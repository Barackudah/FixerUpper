"""Desktop product creator for the local FixerUpper XAMPP project.

This module contains a complete Tkinter administration window for adding new
storefront products to the FixerUpper PHP/MySQL site. The web application already
has an inventory page for editing stock on existing products; this desktop tool
fills the missing workflow: creating a new product record, attaching its initial
inventory, registering its main image, and storing the specification rows used by
the "More Info" modal on the storefront.

The implementation intentionally uses only the Python standard library. Tkinter
provides the desktop interface, and database access is delegated to the XAMPP
``mysql.exe`` command-line client through ``stdin``. That keeps the tool easy to
run on a clean Windows/XAMPP machine without installing PyMySQL, mysql-connector,
PySide, or any other package from pip.

Database writes are grouped into one MySQL transaction:

1. Insert the base product into ``products``.
2. Insert the initial stock/location/supplier row into ``product_inventory``.
3. Insert the first gallery image into ``product_images``.
4. Insert optional modal details into ``product_specs``.

If any statement fails, MySQL rolls the transaction back and the site is not left
with a partially-created storefront product.
"""

from __future__ import annotations

import argparse
import csv
import os
import re
import shutil
import subprocess
import sys
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Iterable

import tkinter as tk
from tkinter import filedialog, messagebox, ttk


PROJECT_ROOT = Path(__file__).resolve().parents[1]
ASSETS_IMAGES_DIR = PROJECT_ROOT / "assets" / "images"
BASE_DPI = 96.0
PRODUCTS_PANEL_WIDTH_SCALE = 1.04
PRODUCT_TABLE_COLUMNS = ("id", "slug", "name", "price", "stock", "location")
PRODUCT_NUMERIC_COLUMNS = {"id", "price", "stock"}

# Connection defaults match the local XAMPP setup used by config.php.
DEFAULT_MYSQL_EXE = Path(r"C:\xampp\mysql\bin\mysql.exe")
MYSQL_EXE = Path(os.environ.get("FIXERUPPER_MYSQL_EXE", str(DEFAULT_MYSQL_EXE)))
MYSQL_DATABASE = os.environ.get("FIXERUPPER_DB_NAME", "fixerupper")
MYSQL_USER = os.environ.get("FIXERUPPER_DB_USER", "root")
MYSQL_PASSWORD = os.environ.get("FIXERUPPER_DB_PASSWORD", "")

IMAGE_EXTENSIONS = {".png", ".jpg", ".jpeg", ".gif", ".webp"}
# New products usually need these modal rows, so the form starts with them.
DEFAULT_SPECS = [
    "Operating System",
    "Processor",
    "Graphics",
    "Memory",
    "Storage",
    "Cooling",
    "Case",
]

CYRILLIC_TRANSLITERATION = str.maketrans({
    "\u0430": "a",
    "\u0431": "b",
    "\u0432": "v",
    "\u0433": "g",
    "\u0434": "d",
    "\u0435": "e",
    "\u0451": "e",
    "\u0436": "zh",
    "\u0437": "z",
    "\u0438": "i",
    "\u0439": "y",
    "\u043a": "k",
    "\u043b": "l",
    "\u043c": "m",
    "\u043d": "n",
    "\u043e": "o",
    "\u043f": "p",
    "\u0440": "r",
    "\u0441": "s",
    "\u0442": "t",
    "\u0443": "u",
    "\u0444": "f",
    "\u0445": "h",
    "\u0446": "ts",
    "\u0447": "ch",
    "\u0448": "sh",
    "\u0449": "sch",
    "\u044a": "",
    "\u044b": "y",
    "\u044c": "",
    "\u044d": "e",
    "\u044e": "yu",
    "\u044f": "ya",
})

COLORS = {
    "bg": "#171717",
    "canvas": "#222222",
    "panel": "#242424",
    "panel_alt": "#2d2d2d",
    "card": "#303030",
    "card_dark": "#1f1f1f",
    "field": "#3a3a3a",
    "field_dark": "#242424",
    "scrollbar_thumb": "#2a2a2a",
    "heading_hover": "#202020",
    "line_dark": "#171717",
    "line": "#565656",
    "line_bright": "#7a7a7a",
    "text": "#ffffff",
    "muted": "#a8a8a8",
    "muted_dark": "#7a7a7a",
    "accent": "#7eff00",
    "accent_soft": "#95ff38",
    "accent_deep": "#274400",
    "danger": "#ff8d8d",
    "warning": "#ffae42",
}


class DatabaseError(RuntimeError):
    """Raised when mysql.exe cannot complete a database request."""


class ValidationError(ValueError):
    """Raised when the form cannot be saved yet."""


def enable_high_dpi_rendering() -> None:
    """Ask Windows to render Tkinter at native monitor DPI instead of bitmap scaling."""

    if sys.platform != "win32":
        return

    try:
        import ctypes

        try:
            ctypes.windll.shcore.SetProcessDpiAwareness(2)
        except (AttributeError, OSError):
            ctypes.windll.user32.SetProcessDPIAware()
    except Exception:
        return


def display_scale_for(root: tk.Tk) -> float:
    """Return the multiplier between classic Tk pixels and native monitor pixels."""

    try:
        return max(1.0, root.winfo_fpixels("1i") / BASE_DPI)
    except tk.TclError:
        return 1.0


@dataclass
class ProductRow:
    """Small read model used by the left-hand "Existing products" table."""

    product_id: str
    slug: str
    name: str
    price: str
    stock: str
    location: str


@dataclass
class ProductPayload:
    """Validated form data ready to be written to the FixerUpper schema."""

    slug: str
    name: str
    short_description: str
    price: Decimal
    main_image: str
    is_active: bool
    stock_quantity: int
    location: str
    supplier: str
    specs: list[tuple[str, str]]


@dataclass
class ProductDetails:
    """Full product data loaded from the database for editing."""

    product_id: int
    payload: ProductPayload


@dataclass
class SpecControls:
    """Tkinter widgets and variables for one editable specification row."""

    frame: ttk.Frame
    label_var: tk.StringVar
    value_var: tk.StringVar


def sql_quote(value: str) -> str:
    """Return a MySQL single-quoted string literal for the local CLI client.

    The tool feeds SQL to ``mysql.exe`` through standard input instead of using a
    DB-API driver with prepared statements. Escaping is therefore centralized in
    this helper so every dynamic string follows the same MySQL literal rules.
    """

    replacements = {
        "\0": r"\0",
        "\b": r"\b",
        "\n": r"\n",
        "\r": r"\r",
        "\t": r"\t",
        "\x1a": r"\Z",
        "\\": r"\\",
        "'": r"\'",
        '"': r"\"",
    }
    escaped = "".join(replacements.get(char, char) for char in str(value))
    return f"'{escaped}'"


def decode_mysql_hex(value: str) -> str:
    """Decode a MySQL HEX(text) value back into UTF-8 text."""

    if not value:
        return ""

    try:
        return bytes.fromhex(value).decode("utf-8", errors="replace")
    except ValueError:
        return value


def slugify(value: str) -> str:
    """Convert a product name into the URL-safe slug format used by the site."""

    slug = value.strip().lower().translate(CYRILLIC_TRANSLITERATION)
    slug = re.sub(r"[^a-z0-9]+", "-", slug)
    slug = re.sub(r"-{2,}", "-", slug).strip("-")
    return slug[:50]


def clean_one_line(value: str) -> str:
    """Normalize a short text field that should not contain line breaks."""

    return re.sub(r"\s+", " ", value.strip())


def path_to_site_relative(path: Path) -> str:
    """Convert an absolute project path into the forward-slash web path."""

    return path.resolve().relative_to(PROJECT_ROOT.resolve()).as_posix()


def is_inside_project(path: Path) -> bool:
    """Return True when a selected image already lives inside this project."""

    try:
        path.resolve().relative_to(PROJECT_ROOT.resolve())
        return True
    except ValueError:
        return False


class DatabaseClient:
    """Thin wrapper around XAMPP's mysql.exe command-line client.

    A normal desktop application would often use a DB-API package such as
    ``mysql-connector-python`` or ``PyMySQL``. This project avoids that dependency
    so the tool can be launched immediately after cloning the repository on the
    same Windows/XAMPP environment as the PHP site.
    """

    def __init__(
        self,
        mysql_exe: Path = MYSQL_EXE,
        database: str = MYSQL_DATABASE,
        user: str = MYSQL_USER,
        password: str = MYSQL_PASSWORD,
    ) -> None:
        self.mysql_exe = mysql_exe
        self.database = database
        self.user = user
        self.password = password

    def _args(self) -> list[str]:
        """Build the mysql.exe command arguments used for every request."""

        if not self.mysql_exe.exists():
            raise DatabaseError(f"mysql.exe was not found: {self.mysql_exe}")

        args = [
            str(self.mysql_exe),
            "--batch",
            "--raw",
            "--skip-column-names",
            "--default-character-set=utf8mb4",
            f"--user={self.user}",
        ]

        if self.password:
            args.append(f"--password={self.password}")

        args.append(f"--database={self.database}")
        return args

    def run(self, sql: str) -> str:
        """Execute SQL and return stdout, raising DatabaseError on failure.

        SQL is passed through ``stdin`` rather than shell interpolation. This
        keeps command execution predictable on Windows and avoids quoting issues
        with paths, product names, or long specification text.
        """

        creationflags = subprocess.CREATE_NO_WINDOW if hasattr(subprocess, "CREATE_NO_WINDOW") else 0
        process = subprocess.run(
            self._args(),
            input=sql,
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=True,
            cwd=PROJECT_ROOT,
            creationflags=creationflags,
            check=False,
        )

        if process.returncode != 0:
            details = process.stderr.strip() or process.stdout.strip() or "unknown mysql error"
            raise DatabaseError(details)

        return process.stdout

    def list_products(self) -> list[ProductRow]:
        """Load active storefront products for the desktop app sidebar."""

        output = self.run(
            """
            SET NAMES utf8mb4;
            SELECT
                p.id,
                p.slug,
                REPLACE(REPLACE(REPLACE(p.name, CHAR(9), ' '), CHAR(10), ' '), CHAR(13), ' ') AS name,
                CAST(p.price AS CHAR),
                CAST(COALESCE(i.stock_quantity, 0) AS CHAR),
                REPLACE(REPLACE(REPLACE(COALESCE(NULLIF(i.location, ''), 'Unassigned'), CHAR(9), ' '), CHAR(10), ' '), CHAR(13), ' ') AS location
            FROM products p
            LEFT JOIN product_inventory i ON i.product_id = p.id
            WHERE p.is_active = 1
            ORDER BY p.id;
            """
        )
        rows: list[ProductRow] = []
        reader = csv.reader((line for line in output.splitlines() if line.strip()), delimiter="\t")

        for columns in reader:
            if len(columns) < 6:
                continue

            rows.append(
                ProductRow(
                    product_id=columns[0],
                    slug=columns[1],
                    name=columns[2],
                    price=columns[3],
                    stock=columns[4],
                    location=columns[5],
                )
            )

        return rows

    def get_product(self, product_id: int) -> ProductDetails:
        """Load one product and its related inventory/spec rows for editing."""

        product_id = int(product_id)
        output = self.run(
            f"""
            SET NAMES utf8mb4;
            SELECT
                p.id,
                HEX(p.slug),
                HEX(p.name),
                HEX(p.short_description),
                CAST(p.price AS CHAR),
                HEX(p.main_image),
                CAST(p.is_active AS CHAR),
                CAST(COALESCE(i.stock_quantity, 0) AS CHAR),
                HEX(COALESCE(i.location, '')),
                HEX(COALESCE(i.supplier, ''))
            FROM products p
            LEFT JOIN product_inventory i ON i.product_id = p.id
            WHERE p.id = {product_id}
            LIMIT 1;
            """
        )
        rows = list(csv.reader((line for line in output.splitlines() if line.strip()), delimiter="\t"))

        if not rows or len(rows[0]) < 10:
            raise DatabaseError(f"Product #{product_id} was not found.")

        row = rows[0]
        specs_output = self.run(
            f"""
            SET NAMES utf8mb4;
            SELECT HEX(label), HEX(value)
            FROM product_specs
            WHERE product_id = {product_id}
            ORDER BY sort_order, id;
            """
        )
        specs = [
            (decode_mysql_hex(columns[0]), decode_mysql_hex(columns[1]))
            for columns in csv.reader((line for line in specs_output.splitlines() if line.strip()), delimiter="\t")
            if len(columns) >= 2
        ]

        payload = ProductPayload(
            slug=decode_mysql_hex(row[1]),
            name=decode_mysql_hex(row[2]),
            short_description=decode_mysql_hex(row[3]),
            price=Decimal(row[4]).quantize(Decimal("0.01")),
            main_image=decode_mysql_hex(row[5]),
            is_active=row[6] == "1",
            stock_quantity=int(row[7] or "0"),
            location=decode_mysql_hex(row[8]),
            supplier=decode_mysql_hex(row[9]),
            specs=specs,
        )
        return ProductDetails(product_id=product_id, payload=payload)

    def create_product(self, payload: ProductPayload) -> int:
        """Insert a new product and all related records in one transaction.

        The PHP storefront expects several linked tables to be present for a rich
        product card: ``products`` for the core listing, ``product_inventory`` for
        saleable stock, ``product_images`` for the modal gallery, and
        ``product_specs`` for the text details. Creating all of them together
        prevents a half-configured product from appearing on the site.
        """

        spec_sql = []

        for sort_order, (label, value) in enumerate(payload.specs, start=1):
            spec_sql.append(
                "INSERT INTO product_specs (product_id, label, value, sort_order) "
                f"VALUES (@fixerupper_product_id, {sql_quote(label)}, {sql_quote(value)}, {sort_order});"
            )

        sql = f"""
        SET NAMES utf8mb4;
        START TRANSACTION;

        INSERT INTO products (slug, name, short_description, price, main_image, is_active)
        VALUES (
            {sql_quote(payload.slug)},
            {sql_quote(payload.name)},
            {sql_quote(payload.short_description)},
            {payload.price},
            {sql_quote(payload.main_image)},
            {1 if payload.is_active else 0}
        );

        SET @fixerupper_product_id = LAST_INSERT_ID();

        INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
        VALUES (
            @fixerupper_product_id,
            {payload.stock_quantity},
            {sql_quote(payload.location)},
            {sql_quote(payload.supplier)}
        );

        INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
        VALUES (@fixerupper_product_id, {sql_quote(payload.main_image)}, {sql_quote(payload.name)}, 1);

        {"".join(spec_sql)}

        COMMIT;
        SELECT @fixerupper_product_id;
        """
        output = self.run(sql)
        product_id = output.strip().splitlines()[-1] if output.strip() else ""

        if not product_id.isdigit():
            raise DatabaseError("Product was saved, but mysql.exe did not return the new id.")

        return int(product_id)

    def update_product(self, product_id: int, payload: ProductPayload) -> None:
        """Update an existing product and replace its modal specification rows."""

        product_id = int(product_id)
        spec_sql = []

        for sort_order, (label, value) in enumerate(payload.specs, start=1):
            spec_sql.append(
                "INSERT INTO product_specs (product_id, label, value, sort_order) "
                f"VALUES (@fixerupper_product_id, {sql_quote(label)}, {sql_quote(value)}, {sort_order});"
            )

        sql = f"""
        SET NAMES utf8mb4;
        START TRANSACTION;

        SET @fixerupper_product_id = {product_id};

        UPDATE products
        SET
            slug = {sql_quote(payload.slug)},
            name = {sql_quote(payload.name)},
            short_description = {sql_quote(payload.short_description)},
            price = {payload.price},
            main_image = {sql_quote(payload.main_image)},
            is_active = {1 if payload.is_active else 0}
        WHERE id = @fixerupper_product_id;

        INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
        VALUES (
            @fixerupper_product_id,
            {payload.stock_quantity},
            {sql_quote(payload.location)},
            {sql_quote(payload.supplier)}
        )
        ON DUPLICATE KEY UPDATE
            stock_quantity = VALUES(stock_quantity),
            location = VALUES(location),
            supplier = VALUES(supplier);

        INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
        VALUES (@fixerupper_product_id, {sql_quote(payload.main_image)}, {sql_quote(payload.name)}, 1)
        ON DUPLICATE KEY UPDATE
            image_path = VALUES(image_path),
            alt_text = VALUES(alt_text);

        DELETE FROM product_specs WHERE product_id = @fixerupper_product_id;
        {"".join(spec_sql)}

        COMMIT;
        """
        self.run(sql)

    def soft_delete_product(self, product_id: int) -> None:
        """Hide a product from the storefront without physically deleting rows."""

        product_id = int(product_id)
        self.run(
            f"""
            SET NAMES utf8mb4;
            START TRANSACTION;

            UPDATE products
            SET is_active = 0
            WHERE id = {product_id};

            UPDATE product_inventory
            SET stock_quantity = 0
            WHERE product_id = {product_id};

            COMMIT;
            """
        )


class InventoryDesktopApp(tk.Tk):
    """Main Tkinter window for the FixerUpper product creation workflow."""

    def __init__(self, db: DatabaseClient) -> None:
        """Create the desktop UI and load the current product list."""

        enable_high_dpi_rendering()
        super().__init__()
        self.display_scale = display_scale_for(self)
        self.db = db
        self.products: list[ProductRow] = []
        self.existing_slugs: set[str] = set()
        self.spec_rows: list[SpecControls] = []
        self.image_source_path: Path | None = None
        self.product_sort_column: str | None = None
        self.product_sort_reverse = False
        self.editing_product_id: int | None = None
        self.editing_product_slug = ""

        self.title("FIXERUPPER Inventory Desktop")
        self.geometry(f"{self._px(1240)}x{self._px(780)}")
        self.minsize(self._px(1100), self._px(700))
        self.configure(bg=COLORS["bg"])

        self._configure_style()
        self._build_layout()
        self._reset_specs()
        self.after(150, self.load_products)

    def _px(self, value: int | float) -> int:
        """Scale a classic Tk pixel value to the current native monitor DPI."""

        if value == 0:
            return 0

        scaled = round(value * self.display_scale)
        return max(1, scaled) if value > 0 else min(-1, scaled)

    def _pad(self, values: int | tuple[int, ...]) -> int | tuple[int, ...]:
        """Scale Tk padding values while preserving tuple shape."""

        if isinstance(values, tuple):
            return tuple(self._px(value) for value in values)

        return self._px(values)

    def _configure_style(self) -> None:
        """Apply a dark FIXERUPPER-inspired theme to standard ttk widgets."""

        style = ttk.Style(self)
        style.theme_use("clam")
        default_font = ("Montserrat", 9)
        heading_font = ("Teko", 24, "bold")

        style.configure(".", font=default_font, background=COLORS["bg"], foreground=COLORS["text"])
        style.configure("TFrame", background=COLORS["bg"])
        style.configure("Panel.TFrame", background=COLORS["panel"])
        style.configure("Section.TFrame", background=COLORS["panel_alt"])
        style.configure("Preview.TFrame", background=COLORS["card"])
        style.configure("Actions.TFrame", background=COLORS["bg"])
        style.configure("TLabel", background=COLORS["bg"], foreground=COLORS["text"])
        style.configure("Muted.TLabel", background=COLORS["bg"], foreground=COLORS["muted"])
        style.configure("Panel.TLabel", background=COLORS["panel"], foreground=COLORS["text"])
        style.configure("PanelMuted.TLabel", background=COLORS["panel"], foreground=COLORS["muted"])
        style.configure("Section.TLabel", background=COLORS["panel_alt"], foreground=COLORS["text"])
        style.configure("SectionMuted.TLabel", background=COLORS["panel_alt"], foreground=COLORS["muted"])
        style.configure("Preview.TLabel", background=COLORS["card"], foreground=COLORS["text"])
        style.configure("PreviewMuted.TLabel", background=COLORS["card"], foreground=COLORS["muted"])
        style.configure("PreviewAccent.TLabel", background=COLORS["card"], foreground=COLORS["accent"])
        style.configure("Title.TLabel", font=heading_font, background=COLORS["bg"], foreground=COLORS["accent"])
        style.configure(
            "Header.TLabel",
            font=("Montserrat", 10, "bold"),
            background=COLORS["panel_alt"],
            foreground=COLORS["accent"],
        )
        style.configure(
            "SidebarHeader.TLabel",
            font=("Montserrat", 10, "bold"),
            background=COLORS["panel"],
            foreground=COLORS["accent"],
        )
        style.configure(
            "TEntry",
            fieldbackground=COLORS["field"],
            foreground=COLORS["text"],
            bordercolor=COLORS["line"],
            insertcolor=COLORS["text"],
            lightcolor=COLORS["line"],
            darkcolor=COLORS["line"],
        )
        style.configure(
            "TSpinbox",
            fieldbackground=COLORS["field"],
            foreground=COLORS["text"],
            bordercolor=COLORS["line"],
            arrowsize=self._px(12),
        )
        style.configure(
            "TButton",
            background=COLORS["field_dark"],
            foreground=COLORS["text"],
            bordercolor=COLORS["line_bright"],
            focusthickness=self._px(1),
            focuscolor=COLORS["accent"],
            padding=self._pad((10, 6)),
        )
        style.map(
            "TButton",
            background=[("active", COLORS["card"]), ("pressed", COLORS["panel"])],
            foreground=[("active", COLORS["accent"])],
            bordercolor=[("active", COLORS["accent"])],
        )
        style.configure("TCheckbutton", background=COLORS["panel_alt"], foreground=COLORS["text"])
        style.map(
            "TCheckbutton",
            background=[("active", COLORS["panel_alt"])],
            foreground=[("active", COLORS["accent"])],
        )
        style.configure(
            "Accent.TButton",
            background=COLORS["accent_deep"],
            foreground=COLORS["accent"],
            bordercolor=COLORS["accent"],
            padding=self._pad((14, 8)),
        )
        style.map(
            "Accent.TButton",
            background=[("active", "#345d00"), ("pressed", COLORS["accent_deep"])],
            foreground=[("active", COLORS["text"])],
            bordercolor=[("active", COLORS["accent"])],
        )
        style.configure(
            "Treeview",
            background=COLORS["card_dark"],
            fieldbackground=COLORS["card_dark"],
            foreground=COLORS["text"],
            bordercolor=COLORS["line"],
            borderwidth=0,
            relief="flat",
            lightcolor=COLORS["line"],
            darkcolor=COLORS["line"],
            rowheight=self._px(29),
        )
        style.configure(
            "Treeview.Heading",
            background=COLORS["field_dark"],
            foreground=COLORS["accent"],
            bordercolor=COLORS["line"],
            borderwidth=self._px(1),
            lightcolor=COLORS["line"],
            darkcolor=COLORS["line"],
            relief="solid",
            font=("Montserrat", 8, "bold"),
        )
        style.map(
            "Treeview.Heading",
            background=[("active", COLORS["heading_hover"]), ("pressed", COLORS["line_dark"])],
            foreground=[("active", COLORS["accent"]), ("pressed", COLORS["accent_soft"])],
            bordercolor=[("active", COLORS["line"]), ("pressed", COLORS["line"])],
            lightcolor=[("active", COLORS["line"]), ("pressed", COLORS["line"])],
            darkcolor=[("active", COLORS["line"]), ("pressed", COLORS["line"])],
        )
        style.map("Treeview", background=[("selected", "#3d5f23")], foreground=[("selected", COLORS["text"])])
        style.configure(
            "Fixer.Vertical.TScrollbar",
            background=COLORS["scrollbar_thumb"],
            troughcolor=COLORS["canvas"],
            bordercolor=COLORS["line_dark"],
            arrowcolor=COLORS["accent"],
            darkcolor=COLORS["scrollbar_thumb"],
            lightcolor=COLORS["scrollbar_thumb"],
            relief="flat",
            arrowsize=self._px(12),
            width=self._px(14),
        )
        style.map(
            "Fixer.Vertical.TScrollbar",
            background=[("active", COLORS["card"]), ("pressed", COLORS["panel_alt"])],
            arrowcolor=[("active", COLORS["accent_soft"])],
        )

    def _build_layout(self) -> None:
        """Create the high-level two-column application layout."""

        self.columnconfigure(1, weight=1)
        self.rowconfigure(1, weight=1)

        header = tk.Frame(self, bg=COLORS["bg"], highlightthickness=0)
        header.grid(row=0, column=0, columnspan=2, sticky="ew")
        header.columnconfigure(1, weight=1)

        brand_block = tk.Frame(header, bg=COLORS["bg"])
        brand_block.grid(row=0, column=0, sticky="w", padx=self._pad((20, 0)), pady=self._pad((14, 10)))
        tk.Label(
            brand_block,
            text="FIXERUPPER",
            bg=COLORS["bg"],
            fg=COLORS["accent"],
            font=("Teko", 28, "bold"),
        ).grid(row=0, column=0, sticky="w")
        tk.Label(
            brand_block,
            text="DESKTOP PRODUCT CREATOR",
            bg=COLORS["bg"],
            fg=COLORS["muted"],
            font=("Montserrat", 8, "bold"),
        ).grid(row=1, column=0, sticky="w")

        tk.Label(
            header,
            text="ADMIN MODE",
            bg=COLORS["field_dark"],
            fg=COLORS["accent_soft"],
            font=("Montserrat", 9, "bold"),
            padx=self._px(14),
            pady=self._px(7),
        ).grid(row=0, column=2, sticky="e", padx=self._pad((8, 20)), pady=self._pad((16, 10)))

        self._build_products_panel()
        self._build_form_panel()

        self.status_var = tk.StringVar(value="Ready.")
        self.status_label = ttk.Label(self, textvariable=self.status_var, style="Muted.TLabel", padding=self._pad((18, 6, 18, 10)))
        self.status_label.grid(row=2, column=0, columnspan=2, sticky="ew")

    def _build_products_panel(self) -> None:
        """Build the left sidebar that previews active storefront products."""

        panel = ttk.Frame(self, style="Panel.TFrame", padding=self._px(12))
        panel.grid(row=1, column=0, sticky="nsw", padx=self._pad((20, 8)), pady=self._pad((16, 12)))
        panel.rowconfigure(3, weight=1)
        panel.columnconfigure(0, weight=1)

        self.product_count_var = tk.StringVar(value="0 active products")
        ttk.Label(panel, text="STOREFRONT PRODUCTS", style="SidebarHeader.TLabel").grid(row=0, column=0, sticky="w")
        ttk.Label(panel, textvariable=self.product_count_var, style="PanelMuted.TLabel").grid(row=1, column=0, sticky="w", pady=self._pad((3, 8)))
        ttk.Button(panel, text="Refresh database", command=self.load_products).grid(row=2, column=0, sticky="ew", pady=self._pad((0, 10)))

        columns = PRODUCT_TABLE_COLUMNS
        products_table_frame = tk.Frame(
            panel,
            bg=COLORS["card_dark"],
            highlightthickness=self._px(1),
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["line"],
        )
        products_table_frame.grid(row=3, column=0, sticky="nsew")
        products_table_frame.rowconfigure(0, weight=1)
        products_table_frame.columnconfigure(0, weight=1)

        self.products_tree = ttk.Treeview(products_table_frame, columns=columns, show="headings", height=20)
        headings = {
            "id": ("ID", 46),
            "slug": ("Slug", 92),
            "name": ("Name", 190),
            "price": ("Price", 68),
            "stock": ("Stock", 58),
            "location": ("Location", 116),
        }

        for column, (label, width) in headings.items():
            self.products_tree.heading(column, text=label, command=lambda selected=column: self.sort_products_by(selected))
            anchor = "center" if column in {"id", "price", "stock"} else "w"
            scaled_width = self._px(width * PRODUCTS_PANEL_WIDTH_SCALE)
            self.products_tree.column(column, width=scaled_width, minwidth=scaled_width, anchor=anchor, stretch=False)

        self.products_scrollbar = ttk.Scrollbar(panel, orient="vertical", command=self.products_tree.yview, style="Fixer.Vertical.TScrollbar")
        self.products_tree.configure(yscrollcommand=self._sync_products_scrollbar)
        self.products_tree.bind("<Button-1>", self._block_products_column_resize)
        self.products_tree.bind("<B1-Motion>", self._block_products_column_resize)
        self.products_tree.bind("<Double-1>", self._block_products_column_resize)
        self.products_tree.bind("<Button-3>", self._show_products_context_menu)
        self.products_tree.grid(row=0, column=0, sticky="nsew")
        self.products_scrollbar.grid(row=3, column=1, sticky="ns", padx=self._pad((8, 0)))
        self.products_scrollbar.grid_remove()

        self.products_context_menu = tk.Menu(
            self,
            tearoff=False,
            bg=COLORS["field_dark"],
            fg=COLORS["text"],
            activebackground=COLORS["heading_hover"],
            activeforeground=COLORS["accent"],
            borderwidth=self._px(1),
            relief="solid",
        )
        self.products_context_menu.add_command(label="EDIT", command=self.edit_selected_product)
        self.products_context_menu.add_command(label="DELETE", command=self.delete_selected_product)

        ttk.Label(
            panel,
            text="New products are written to products, product_inventory, product_images and product_specs.",
            style="PanelMuted.TLabel",
            wraplength=self._px(305 * PRODUCTS_PANEL_WIDTH_SCALE),
        ).grid(row=4, column=0, columnspan=2, sticky="ew", pady=self._pad((10, 0)))

    def _build_form_panel(self) -> None:
        """Build the scrollable product creation form on the right."""

        wrapper = ttk.Frame(self, style="TFrame")
        wrapper.grid(row=1, column=1, sticky="nsew", padx=self._pad((8, 20)), pady=self._pad((16, 12)))
        wrapper.rowconfigure(0, weight=1)
        wrapper.columnconfigure(0, weight=1)

        self.form_canvas = tk.Canvas(
            wrapper,
            bg=COLORS["canvas"],
            highlightthickness=0,
            borderwidth=0,
        )
        form_scroll = ttk.Scrollbar(wrapper, orient="vertical", command=self.form_canvas.yview, style="Fixer.Vertical.TScrollbar")
        self.form_canvas.configure(yscrollcommand=form_scroll.set)
        self.form_canvas.grid(row=0, column=0, sticky="nsew")
        form_scroll.grid(row=0, column=1, sticky="ns")

        self.form_frame = ttk.Frame(self.form_canvas, style="TFrame", padding=self._pad((0, 0, 8, 0)))
        self.form_window_id = self.form_canvas.create_window((0, 0), window=self.form_frame, anchor="nw")
        self.form_frame.bind("<Configure>", self._sync_form_scrollregion)
        self.form_canvas.bind("<Configure>", self._sync_form_width)

        self.name_var = tk.StringVar()
        self.slug_var = tk.StringVar()
        self.price_var = tk.StringVar()
        self.image_var = tk.StringVar(value="assets/images/pc_noimage.png")
        self.active_var = tk.BooleanVar(value=True)
        self.stock_var = tk.StringVar(value="1")
        self.location_var = tk.StringVar(value="Main workshop")
        self.supplier_var = tk.StringVar(value="FixerUpper Build Team")

        product_section = self._section("PRODUCT")
        self._field(product_section, 0, "Name", self.name_var)
        self._field(product_section, 1, "Slug", self.slug_var, button_text="Generate", button_command=self.fill_slug_from_name)
        self._price_field(product_section, 2, "Price", self.price_var)
        self.description_text = self._text_field(product_section, 3, "Short description", height=4)
        self._field(product_section, 4, "Main image", self.image_var, button_text="Browse", button_command=self.browse_image)
        ttk.Button(product_section, text="Use placeholder", command=self.use_placeholder_image).grid(row=6, column=1, sticky="w", pady=self._pad((0, 8)))
        self._active_storefront_check(product_section, 7)

        inventory_section = self._section("INVENTORY")
        self._match_form_columns(product_section, inventory_section)
        self._stock_field(inventory_section, 0, "Stock quantity", self.stock_var)
        self._field(inventory_section, 1, "Location", self.location_var)
        self._field(inventory_section, 2, "Supplier", self.supplier_var)

        specs_section = self._section("MODAL SPECS")
        specs_section.columnconfigure(1, weight=1)
        self.specs_container = ttk.Frame(specs_section, style="Section.TFrame")
        self.specs_container.columnconfigure(0, weight=1)
        self.specs_container.grid(row=0, column=0, columnspan=3, sticky="ew")
        ttk.Button(specs_section, text="Add spec row", command=lambda: self.add_spec_row("", "")).grid(
            row=1,
            column=0,
            sticky="w",
            pady=self._pad((10, 0)),
        )

        actions = ttk.Frame(self.form_frame, style="Actions.TFrame", padding=self._pad((0, 10, 0, 0)))
        actions.pack(fill="x")
        actions.columnconfigure(0, weight=1)
        ttk.Button(actions, text="Clear form", command=self.clear_form).grid(row=0, column=0, sticky="w")
        self.save_button = ttk.Button(actions, text="SAVE PRODUCT", style="Accent.TButton", command=self.save_product)
        self.save_button.grid(row=0, column=1, sticky="e")

    def _sync_form_scrollregion(self, _event: tk.Event) -> None:
        """Keep the canvas scroll area aligned with the dynamic form height."""

        self.form_canvas.configure(scrollregion=self.form_canvas.bbox("all"))

    def _sync_form_width(self, event: tk.Event) -> None:
        """Stretch the embedded form frame to the visible canvas width."""

        self.form_canvas.itemconfigure(self.form_window_id, width=event.width)

    def _block_products_column_resize(self, event: tk.Event) -> str | None:
        """Prevent manual resizing of product table heading separators."""

        if self.products_tree.identify_region(event.x, event.y) == "separator":
            return "break"

        return None

    def _show_products_context_menu(self, event: tk.Event) -> str | None:
        """Open the product row context menu on right-click."""

        row_id = self.products_tree.identify_row(event.y)

        if not row_id:
            return None

        self.products_tree.selection_set(row_id)
        self.products_tree.focus(row_id)
        self.products_context_menu.tk_popup(event.x_root, event.y_root)
        return "break"

    def _selected_product_id(self) -> int | None:
        """Return the product id from the selected product table row."""

        selection = self.products_tree.selection()

        if not selection:
            return None

        values = self.products_tree.item(selection[0], "values")

        if not values:
            return None

        try:
            return int(values[0])
        except (TypeError, ValueError):
            return None

    def _sync_products_scrollbar(self, first: str, last: str) -> None:
        """Show the product scrollbar only when the tree has hidden rows."""

        self.products_scrollbar.set(first, last)

        if self._products_need_scrollbar():
            self.products_scrollbar.grid()
        else:
            self.products_scrollbar.grid_remove()

    def _refresh_products_scrollbar(self) -> None:
        """Re-check product scrollbar visibility after rows are inserted."""

        self.products_tree.update_idletasks()
        first, last = self.products_tree.yview()
        self._sync_products_scrollbar(str(first), str(last))

    def _products_need_scrollbar(self) -> bool:
        """Return True when the product table contains more rows than it displays."""

        visible_rows = int(self.products_tree.cget("height"))
        return len(self.products_tree.get_children()) > visible_rows

    def _section(self, title: str) -> ttk.Frame:
        """Create a labelled form section with consistent padding."""

        frame = ttk.Frame(self.form_frame, style="Section.TFrame", padding=self._px(14))
        frame.pack(fill="x", pady=self._pad((0, 12)))
        frame.columnconfigure(1, weight=1, uniform="spec-fields")
        ttk.Label(frame, text=title, style="Header.TLabel").grid(row=0, column=0, columnspan=3, sticky="w", pady=self._pad((0, 10)))
        return frame

    def _match_form_columns(self, source: ttk.Frame, target: ttk.Frame) -> None:
        """Give one section the same label/action column widths as another."""

        self.update_idletasks()
        last_row = max(1, source.grid_size()[1] - 1)
        _x, _y, label_width, _height = source.grid_bbox(0, 1, 0, last_row)
        _x, _y, action_width, _height = source.grid_bbox(2, 1, 2, last_row)
        target.columnconfigure(0, minsize=label_width)
        target.columnconfigure(2, minsize=action_width)

    def _field(
        self,
        parent: ttk.Frame,
        row: int,
        label: str,
        variable: tk.StringVar,
        button_text: str | None = None,
        button_command: object | None = None,
        spin: bool = False,
    ) -> None:
        """Create one labelled entry/spinbox row with an optional action button."""

        real_row = row + 1
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(
            row=real_row,
            column=0,
            sticky="w",
            padx=self._pad((0, 12)),
            pady=self._px(5),
        )

        capsule, _field = self._capsule_entry(parent, variable, width=12 if spin else None)
        capsule.grid(row=real_row, column=1, sticky="w" if spin else "ew", pady=self._px(5))

        if button_text and button_command:
            ttk.Button(parent, text=button_text, command=button_command).grid(
                row=real_row,
                column=2,
                sticky="ew",
                padx=self._pad((8, 0)),
                pady=self._px(5),
            )

    def _active_storefront_check(self, parent: ttk.Frame, row: int) -> None:
        """Create a larger custom checkbox for the storefront active flag."""

        check_frame = tk.Frame(parent, bg=COLORS["panel_alt"], cursor="hand2")
        check_frame.grid(row=row, column=1, sticky="w", pady=self._pad((0, 4)))

        box_size = self._px(14)
        box = tk.Canvas(
            check_frame,
            width=box_size,
            height=box_size,
            bg=COLORS["panel_alt"],
            highlightthickness=0,
            bd=0,
            cursor="hand2",
        )
        box.grid(row=0, column=0, sticky="w", padx=self._pad((0, 7)))

        label = tk.Label(
            check_frame,
            text="Active on storefront",
            bg=COLORS["panel_alt"],
            fg=COLORS["text"],
            font=("Montserrat", 9),
            cursor="hand2",
        )
        label.grid(row=0, column=1, sticky="w")

        def draw_check(*_args: object) -> None:
            box.delete("all")
            box.create_rectangle(
                self._px(1),
                self._px(1),
                box_size - self._px(1),
                box_size - self._px(1),
                outline=COLORS["line_bright"],
                fill=COLORS["field_dark"] if self.active_var.get() else COLORS["panel_alt"],
                width=self._px(1),
            )

            if self.active_var.get():
                box.create_line(
                    self._px(3),
                    self._px(7),
                    self._px(6),
                    self._px(10),
                    self._px(11),
                    self._px(4),
                    fill=COLORS["text"],
                    width=self._px(2),
                    capstyle="round",
                    joinstyle="round",
                )

        def toggle_check(_event: tk.Event | None = None) -> None:
            self.active_var.set(not self.active_var.get())

        for widget in (check_frame, box, label):
            widget.bind("<Button-1>", toggle_check)
            widget.bind("<Enter>", lambda _event: label.configure(fg=COLORS["accent_soft"]))
            widget.bind("<Leave>", lambda _event: label.configure(fg=COLORS["text"]))

        self.active_var.trace_add("write", draw_check)
        draw_check()

    def _capsule_entry(self, parent: tk.Widget, variable: tk.StringVar, width: int | None = None) -> tuple[tk.Frame, tk.Entry]:
        """Create the dark bordered input capsule used throughout the form."""

        capsule = tk.Frame(
            parent,
            bg=COLORS["field"],
            highlightthickness=self._px(1),
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["accent"],
        )
        capsule.columnconfigure(0, weight=0 if width else 1)

        entry = tk.Entry(
            capsule,
            textvariable=variable,
            width=width or 20,
            bg=COLORS["field"],
            fg=COLORS["text"],
            insertbackground=COLORS["text"],
            relief="flat",
            bd=0,
            font=("Montserrat", 10),
        )
        entry.grid(row=0, column=0, sticky="ew", ipady=self._px(4), padx=self._px(9), pady=self._px(1))

        return capsule, entry

    def _price_field(self, parent: ttk.Frame, row: int, label: str, variable: tk.StringVar) -> None:
        """Create a centered compact price capsule with an internal pound marker."""

        real_row = row + 1
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(
            row=real_row,
            column=0,
            sticky="w",
            padx=self._pad((0, 12)),
            pady=self._px(5),
        )

        holder = tk.Frame(parent, bg=COLORS["panel_alt"])
        holder.grid(row=real_row, column=1, sticky="ew", pady=self._px(5))
        holder.configure(height=self._px(36))
        holder.grid_propagate(False)

        capsule = tk.Frame(
            holder,
            bg=COLORS["field"],
            highlightthickness=self._px(1),
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["accent"],
        )
        capsule.columnconfigure(1, weight=1)
        capsule.rowconfigure(0, weight=1)
        capsule.grid_propagate(False)

        tk.Label(
            capsule,
            text="\u00a3",
            bg=COLORS["field"],
            fg=COLORS["text"],
            font=("Montserrat", 10, "bold"),
        ).grid(row=0, column=0, sticky="ns", padx=self._pad((9, 4)), pady=self._px(1))

        price_validate = (self.register(self._validate_price_input), "%P")
        tk.Entry(
            capsule,
            textvariable=variable,
            width=8,
            validate="key",
            validatecommand=price_validate,
            bg=COLORS["field"],
            fg=COLORS["text"],
            insertbackground=COLORS["text"],
            relief="flat",
            bd=0,
            font=("Montserrat", 10),
        ).grid(row=0, column=1, sticky="nsew", ipady=self._px(3), padx=self._pad((0, 9)), pady=self._px(1))

        def layout_price_control(event: tk.Event) -> None:
            holder_width = max(1, event.width)
            capsule_width = min(self._px(112), holder_width)
            capsule_x = max(0, (holder_width - capsule_width) // 2)
            capsule.place(x=capsule_x, rely=0.5, anchor="w", width=capsule_width, height=self._px(32))

        holder.bind("<Configure>", layout_price_control)
        return

    def _stock_field(self, parent: ttk.Frame, row: int, label: str, variable: tk.StringVar) -> None:
        """Create a centered stock quantity capsule with built-in step controls."""

        real_row = row + 1
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(
            row=real_row,
            column=0,
            sticky="w",
            padx=self._pad((0, 12)),
            pady=self._px(5),
        )

        holder = tk.Frame(parent, bg=COLORS["panel_alt"])
        holder.grid(row=real_row, column=1, sticky="ew", pady=self._px(5))
        holder.configure(height=self._px(36))
        holder.grid_propagate(False)

        capsule = tk.Frame(
            holder,
            bg=COLORS["field"],
            highlightthickness=self._px(1),
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["accent"],
        )
        capsule.columnconfigure(1, weight=1)
        capsule.rowconfigure(0, weight=1)
        capsule.grid_propagate(False)

        tk.Label(
            capsule,
            text="\u00a3",
            bg=COLORS["field"],
            fg=COLORS["field"],
            font=("Montserrat", 10, "bold"),
        ).grid(row=0, column=0, sticky="ns", padx=self._pad((9, 4)), pady=self._px(1))

        quantity_validate = (self.register(self._validate_quantity_input), "%P")
        entry = tk.Entry(
            capsule,
            textvariable=variable,
            width=8,
            justify="center",
            validate="key",
            validatecommand=quantity_validate,
            bg=COLORS["field"],
            fg=COLORS["text"],
            insertbackground=COLORS["text"],
            relief="flat",
            bd=0,
            font=("Montserrat", 10),
        )
        entry.grid(row=0, column=1, sticky="nsew", ipady=self._px(3), padx=self._pad((0, 2)), pady=self._px(1))

        stepper = tk.Frame(capsule, bg=COLORS["field"])
        stepper.grid(row=0, column=2, sticky="ns", padx=self._pad((0, 6)), pady=self._px(2))
        stepper.rowconfigure(0, weight=1)
        stepper.rowconfigure(1, weight=1)

        for arrow_row, (symbol, delta) in enumerate((("\u25b2", 1), ("\u25bc", -1))):
            arrow = tk.Label(
                stepper,
                text=symbol,
                bg=COLORS["field"],
                fg=COLORS["muted"],
                cursor="hand2",
                font=("Segoe UI Symbol", 6, "bold"),
                width=2,
            )
            arrow.grid(row=arrow_row, column=0, sticky="nsew")
            arrow.bind("<Button-1>", lambda _event, change=delta: self._step_stock_quantity(change))
            arrow.bind("<Enter>", lambda _event, widget=arrow: widget.configure(fg=COLORS["accent_soft"]))
            arrow.bind("<Leave>", lambda _event, widget=arrow: widget.configure(fg=COLORS["muted"]))

        def layout_stock_control(event: tk.Event) -> None:
            holder_width = max(1, event.width)
            capsule_width = min(self._px(112), holder_width)
            capsule_x = max(0, (holder_width - capsule_width) // 2)
            capsule.place(x=capsule_x, rely=0.5, anchor="w", width=capsule_width, height=self._px(32))

        holder.bind("<Configure>", layout_stock_control)

    def _step_stock_quantity(self, delta: int) -> None:
        """Increment or decrement the stock quantity without allowing negatives."""

        try:
            quantity = int(self.stock_var.get().strip())
        except ValueError:
            quantity = 0

        quantity = min(999999, max(0, quantity + delta))
        self.stock_var.set(str(quantity))

    @staticmethod
    def _validate_price_input(proposed: str) -> bool:
        """Allow only a decimal money value while the user types."""

        return bool(re.fullmatch(r"\d{0,9}(\.\d{0,2})?", proposed))

    @staticmethod
    def _validate_quantity_input(proposed: str) -> bool:
        """Allow only a non-negative whole stock quantity while the user types."""

        return proposed == "" or (proposed.isdigit() and int(proposed) <= 999999)

    def _text_field(self, parent: ttk.Frame, row: int, label: str, height: int) -> tk.Text:
        """Create the multiline description field styled like the dark form."""

        real_row = row + 1
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(
            row=real_row,
            column=0,
            sticky="nw",
            padx=self._pad((0, 12)),
            pady=self._px(5),
        )
        capsule = tk.Frame(
            parent,
            bg=COLORS["field"],
            highlightthickness=self._px(1),
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["accent"],
        )
        capsule.grid(row=real_row, column=1, columnspan=2, sticky="ew", pady=self._px(5))
        capsule.columnconfigure(0, weight=1)

        field = tk.Text(
            capsule,
            height=height,
            wrap="word",
            bg=COLORS["field"],
            fg=COLORS["text"],
            insertbackground=COLORS["text"],
            relief="flat",
            bd=0,
            highlightthickness=0,
            font=("Montserrat", 9),
        )
        field.grid(row=0, column=0, sticky="ew", padx=self._px(9), pady=self._px(6))
        return field

    def _reset_specs(self) -> None:
        """Restore the default modal specification rows."""

        for row in list(self.spec_rows):
            row.frame.destroy()

        self.spec_rows.clear()

        for label in DEFAULT_SPECS:
            self.add_spec_row(label, "")

    def add_spec_row(self, label: str, value: str) -> None:
        """Add one editable specification row to the modal details section."""

        index = len(self.spec_rows)
        frame = ttk.Frame(self.specs_container, style="Section.TFrame")
        frame.grid(row=index, column=0, sticky="ew", pady=self._px(3))
        frame.columnconfigure(0, weight=1, uniform="spec-fields")
        frame.columnconfigure(1, weight=1)
        frame.columnconfigure(2, weight=0)

        label_var = tk.StringVar(value=label)
        value_var = tk.StringVar(value=value)

        label_capsule, _label_entry = self._capsule_entry(frame, label_var)
        label_capsule.grid(row=0, column=0, sticky="ew", padx=self._pad((0, 8)))
        value_capsule, _value_entry = self._capsule_entry(frame, value_var)
        value_capsule.grid(row=0, column=1, sticky="ew", padx=self._pad((0, 8)))
        ttk.Button(frame, text="Remove", command=lambda selected=frame: self.remove_spec_row(selected)).grid(
            row=0,
            column=2,
            sticky="ew",
        )

        self.spec_rows.append(SpecControls(frame=frame, label_var=label_var, value_var=value_var))

    def remove_spec_row(self, frame: ttk.Frame) -> None:
        """Remove a specification row and close the visual gap in the grid."""

        self.spec_rows = [row for row in self.spec_rows if row.frame is not frame]
        frame.destroy()
        self._reflow_spec_rows()

    def _reflow_spec_rows(self) -> None:
        """Re-number specification row positions after one row is deleted."""

        for index, row in enumerate(self.spec_rows):
            row.frame.grid_configure(row=index)

    def edit_selected_product(self) -> None:
        """Load the selected product into the form for editing."""

        product_id = self._selected_product_id()

        if product_id is None:
            self.set_status("Select a product to edit.", danger=True)
            return

        try:
            product = self.db.get_product(product_id)
        except DatabaseError as error:
            self.set_status(f"Edit failed: {error}", danger=True)
            messagebox.showerror("Edit failed", str(error))
            return

        self._load_product_for_edit(product)
        self.set_status(f"Editing product #{product.product_id}: {product.payload.name}")

    def delete_selected_product(self) -> None:
        """Soft-delete the selected product after confirmation."""

        product_id = self._selected_product_id()

        if product_id is None:
            self.set_status("Select a product to delete.", danger=True)
            return

        selection = self.products_tree.selection()
        product_name = self.products_tree.set(selection[0], "name") if selection else f"#{product_id}"

        if not messagebox.askyesno("Delete product", f"Delete '{product_name}' from the storefront?"):
            return

        try:
            self.db.soft_delete_product(product_id)
        except DatabaseError as error:
            self.set_status(f"Delete failed: {error}", danger=True)
            messagebox.showerror("Delete failed", str(error))
            return

        if self.editing_product_id == product_id:
            self.clear_form(show_status=False)

        self.load_products()
        self.set_status(f"Deleted product #{product_id}: {product_name}")

    def _load_product_for_edit(self, product: ProductDetails) -> None:
        """Populate the form with an existing product and enter edit mode."""

        payload = product.payload
        self.editing_product_id = product.product_id
        self.editing_product_slug = payload.slug

        self.name_var.set(payload.name)
        self.slug_var.set(payload.slug)
        self.price_var.set(f"{payload.price:.2f}")
        self.image_var.set(payload.main_image or "assets/images/pc_noimage.png")
        self.active_var.set(payload.is_active)
        self.stock_var.set(str(payload.stock_quantity))
        self.location_var.set(payload.location or "Unassigned")
        self.supplier_var.set(payload.supplier)
        self.description_text.delete("1.0", "end")
        self.description_text.insert("1.0", payload.short_description)
        self.image_source_path = None
        self._set_spec_rows(payload.specs)
        self.save_button.configure(text="UPDATE PRODUCT")

    def _set_spec_rows(self, specs: list[tuple[str, str]]) -> None:
        """Replace modal specification controls with loaded rows."""

        for row in list(self.spec_rows):
            row.frame.destroy()

        self.spec_rows.clear()

        if not specs:
            self.add_spec_row("", "")
            return

        for label, value in specs:
            self.add_spec_row(label, value)

    def sort_products_by(self, column: str) -> None:
        """Sort the storefront product table by the selected heading."""

        if column not in PRODUCT_TABLE_COLUMNS:
            return

        if self.product_sort_column == column:
            self.product_sort_reverse = not self.product_sort_reverse
        else:
            self.product_sort_column = column
            self.product_sort_reverse = False

        sorted_rows = sorted(
            self.products_tree.get_children(),
            key=lambda item_id: self._product_sort_key(column, self.products_tree.set(item_id, column)),
            reverse=self.product_sort_reverse,
        )

        for index, item_id in enumerate(sorted_rows):
            self.products_tree.move(item_id, "", index)

        direction = "descending" if self.product_sort_reverse else "ascending"
        self.set_status(f"Sorted products by {column.upper()} {direction}.")

    def _product_sort_key(self, column: str, value: str) -> Decimal | str:
        """Return a numeric or text key for product table sorting."""

        if column in PRODUCT_NUMERIC_COLUMNS:
            return self._numeric_product_sort_key(value)

        return value.casefold()

    @staticmethod
    def _numeric_product_sort_key(value: str) -> Decimal:
        """Parse product table numbers for heading sorting."""

        try:
            return Decimal(value.replace(",", "").strip())
        except InvalidOperation:
            return Decimal("0")

    def load_products(self) -> None:
        """Refresh the sidebar and cache slugs for duplicate validation."""

        try:
            self.products = self.db.list_products()
        except DatabaseError as error:
            self.set_status(f"Database error: {error}", danger=True)
            messagebox.showerror("Database error", str(error))
            return

        self.existing_slugs = {product.slug for product in self.products}
        self.product_count_var.set(f"{len(self.products)} active products")
        self.products_tree.delete(*self.products_tree.get_children())

        for product in self.products:
            self.products_tree.insert(
                "",
                "end",
                values=(product.product_id, product.slug, product.name, product.price, product.stock, product.location),
            )

        self.after_idle(self._refresh_products_scrollbar)
        self.set_status(f"Loaded {len(self.products)} active products.")

    def fill_slug_from_name(self) -> None:
        """Generate the product slug from the current product name field."""

        if not clean_one_line(self.name_var.get()):
            self.set_status("Enter a product name before generating the slug.", danger=True)
            return

        slug = slugify(self.name_var.get())

        if not slug:
            self.set_status("Unable to generate a slug from this product name.", danger=True)
            return

        self.slug_var.set(slug)
        self.set_status("Slug generated from product name.")

    def browse_image(self) -> None:
        """Ask the user for an image and prepare its future storefront path.

        Images already inside the repository are referenced directly. Images from
        outside the project are copied into ``assets/images`` during save so the
        PHP storefront can serve them normally.
        """

        file_path = filedialog.askopenfilename(
            title="Choose product image",
            filetypes=[
                ("Images", "*.png *.jpg *.jpeg *.gif *.webp"),
                ("All files", "*.*"),
            ],
        )

        if not file_path:
            return

        source = Path(file_path)
        if source.suffix.lower() not in IMAGE_EXTENSIONS:
            messagebox.showwarning("Unsupported image", "Choose a PNG, JPG, GIF or WEBP image.")
            return

        self.image_source_path = source
        if is_inside_project(source):
            self.image_var.set(path_to_site_relative(source))
        else:
            target_name = self._image_target_name(source)
            self.image_var.set(f"assets/images/{target_name}")

        self.set_status("Image selected.")

    def _image_target_name(self, source: Path) -> str:
        """Create the default asset filename for an external selected image."""

        slug = slugify(self.slug_var.get() or self.name_var.get()) or "product"
        suffix = source.suffix.lower()
        return f"{slug}{suffix}"

    def use_placeholder_image(self) -> None:
        """Use the existing no-image asset instead of copying a new file."""

        self.image_source_path = None
        self.image_var.set("assets/images/pc_noimage.png")
        self.set_status("Placeholder image selected.")

    def clear_form(self, show_status: bool = True) -> None:
        """Reset all product fields back to sensible defaults."""

        self.name_var.set("")
        self.slug_var.set("")
        self.price_var.set("")
        self.image_var.set("assets/images/pc_noimage.png")
        self.active_var.set(True)
        self.stock_var.set("1")
        self.location_var.set("Main workshop")
        self.supplier_var.set("FixerUpper Build Team")
        self.description_text.delete("1.0", "end")
        self.image_source_path = None
        self.editing_product_id = None
        self.editing_product_slug = ""
        self.save_button.configure(text="SAVE PRODUCT")
        self._reset_specs()

        if show_status:
            self.set_status("Form cleared.")

    def save_product(self) -> None:
        """Validate the form, copy the image if needed, and save the product."""

        try:
            payload = self.collect_payload()
        except ValidationError as error:
            self.set_status(str(error), danger=True)
            messagebox.showwarning("Check the form", str(error))
            return

        editing_product_id = self.editing_product_id
        action_label = "Update" if editing_product_id is not None else "Create"

        if not messagebox.askyesno(f"{action_label} product", f"{action_label} '{payload.name}' on the storefront?"):
            return

        self.save_button.state(["disabled"])
        self.set_status("Saving product...")
        self.update_idletasks()

        try:
            payload.main_image = self.prepare_image_path(payload.slug, payload.main_image)
            if editing_product_id is None:
                product_id = self.db.create_product(payload)
            else:
                self.db.update_product(editing_product_id, payload)
                product_id = editing_product_id
        except (DatabaseError, OSError) as error:
            self.set_status(f"Save failed: {error}", danger=True)
            messagebox.showerror("Save failed", str(error))
            return
        finally:
            self.save_button.state(["!disabled"])

        verb = "updated" if editing_product_id is not None else "added"
        self.set_status(f"Saved product #{product_id}: {payload.name}")
        messagebox.showinfo("Product saved", f"Product #{product_id} was {verb}.")
        self.clear_form(show_status=False)
        self.load_products()
        self.set_status(f"Saved product #{product_id}: {payload.name}")

    def collect_payload(self) -> ProductPayload:
        """Validate raw Tkinter field values and build a ProductPayload.

        The validation mirrors the current MySQL column limits from
        ``database/fixerupper.sql``. Catching length, numeric, and duplicate-slug
        issues before running SQL gives the user a clear desktop dialog instead
        of a raw MySQL error.
        """

        name = clean_one_line(self.name_var.get())
        slug = slugify(self.slug_var.get())
        short_description = self.description_text.get("1.0", "end").strip()
        price_text = re.sub(r"[^0-9.]", "", self.price_var.get())
        image = self.image_var.get().strip().replace("\\", "/")
        location = clean_one_line(self.location_var.get()) or "Unassigned"
        supplier = clean_one_line(self.supplier_var.get())

        if not name:
            raise ValidationError("Product name is required.")

        if len(name) > 100:
            raise ValidationError("Product name must be 100 characters or fewer.")

        if not slug:
            raise ValidationError("Slug is required. Use 'Generate' if you want it created from the product name.")

        if len(slug) > 50:
            raise ValidationError("Slug must be 50 characters or fewer.")

        if slug in self.existing_slugs and slug != self.editing_product_slug:
            raise ValidationError(f"The slug '{slug}' already exists.")

        if not short_description:
            raise ValidationError("Short description is required.")

        try:
            price = Decimal(price_text).quantize(Decimal("0.01"))
        except (InvalidOperation, ValueError):
            raise ValidationError("Price must be a valid number.") from None

        if price <= 0:
            raise ValidationError("Price must be greater than zero.")

        try:
            stock_quantity = int(self.stock_var.get())
        except ValueError:
            raise ValidationError("Stock quantity must be a whole number.") from None

        if stock_quantity < 0:
            raise ValidationError("Stock quantity cannot be negative.")

        if len(location) > 80:
            raise ValidationError("Location must be 80 characters or fewer.")

        if len(supplier) > 120:
            raise ValidationError("Supplier must be 120 characters or fewer.")

        if not image:
            image = "assets/images/pc_noimage.png"

        if len(image) > 255:
            raise ValidationError("Image path must be 255 characters or fewer.")

        specs = self.collect_specs()

        return ProductPayload(
            slug=slug,
            name=name,
            short_description=short_description,
            price=price,
            main_image=image,
            is_active=bool(self.active_var.get()),
            stock_quantity=stock_quantity,
            location=location,
            supplier=supplier,
            specs=specs,
        )

    def collect_specs(self) -> list[tuple[str, str]]:
        """Return non-empty modal specification rows in display order."""

        specs: list[tuple[str, str]] = []

        for row in self.spec_rows:
            label = clean_one_line(row.label_var.get())
            value = row.value_var.get().strip()

            if not label and not value:
                continue

            if not label or not value:
                raise ValidationError("Every spec row must have both a label and a value.")

            if len(label) > 80:
                raise ValidationError("Spec labels must be 80 characters or fewer.")

            specs.append((label, value))

        return specs

    def prepare_image_path(self, slug: str, current_image: str) -> str:
        """Copy an external image into assets/images and return its web path.

        When the user chooses a file outside the project, the desktop tool stores
        a copy next to the existing product images. If a matching filename already
        exists, a numeric suffix is appended so existing assets are not replaced.
        """

        if not self.image_source_path:
            return current_image or "assets/images/pc_noimage.png"

        source = self.image_source_path

        if not source.exists():
            raise OSError(f"Selected image no longer exists: {source}")

        if is_inside_project(source):
            return path_to_site_relative(source)

        ASSETS_IMAGES_DIR.mkdir(parents=True, exist_ok=True)
        suffix = source.suffix.lower()
        base_name = slugify(slug or source.stem) or "product"
        destination = ASSETS_IMAGES_DIR / f"{base_name}{suffix}"
        counter = 2

        while destination.exists():
            destination = ASSETS_IMAGES_DIR / f"{base_name}-{counter}{suffix}"
            counter += 1

        shutil.copy2(source, destination)
        return path_to_site_relative(destination)

    def set_status(self, message: str, danger: bool = False) -> None:
        """Show a short status-bar message at the bottom of the window."""

        self.status_var.set(message)
        color = COLORS["danger"] if danger else COLORS["accent_soft"]
        self.status_label.configure(foreground=color)

        if danger:
            self.bell()


def run_check(db: DatabaseClient) -> int:
    """CLI health check used before committing or troubleshooting the app."""

    try:
        products = db.list_products()
    except DatabaseError as error:
        print(f"Database check failed: {error}", file=sys.stderr)
        return 1

    print(f"Database check ok: {len(products)} active products")
    for product in products:
        print(f"{product.product_id}\t{product.slug}\t{product.stock}\t{product.name}")

    return 0


def main(argv: Iterable[str] | None = None) -> int:
    """Parse command-line arguments and start either check mode or the GUI."""

    parser = argparse.ArgumentParser(description="FixerUpper desktop inventory product creator")
    parser.add_argument("--check-db", action="store_true", help="Check the MySQL connection and print active products")
    args = parser.parse_args(list(argv) if argv is not None else None)

    db = DatabaseClient()

    if args.check_db:
        return run_check(db)

    app = InventoryDesktopApp(db)
    app.mainloop()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
