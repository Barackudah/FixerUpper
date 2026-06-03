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

COLORS = {
    "bg": "#171717",
    "canvas": "#222222",
    "panel": "#242424",
    "panel_alt": "#2d2d2d",
    "card": "#303030",
    "card_dark": "#1f1f1f",
    "field": "#3a3a3a",
    "field_dark": "#242424",
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


def slugify(value: str) -> str:
    """Convert a product name into the URL-safe slug format used by the site."""

    slug = value.strip().lower()
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


class InventoryDesktopApp(tk.Tk):
    """Main Tkinter window for the FixerUpper product creation workflow."""

    def __init__(self, db: DatabaseClient) -> None:
        """Create the desktop UI and load the current product list."""

        super().__init__()
        self.db = db
        self.products: list[ProductRow] = []
        self.existing_slugs: set[str] = set()
        self.spec_rows: list[SpecControls] = []
        self.image_source_path: Path | None = None

        self.title("FIXERUPPER Inventory Desktop")
        self.geometry("1240x780")
        self.minsize(1100, 700)
        self.configure(bg=COLORS["bg"])

        self._configure_style()
        self._build_layout()
        self._reset_specs()
        self.after(150, self.load_products)

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
            arrowsize=12,
        )
        style.configure(
            "TButton",
            background=COLORS["field_dark"],
            foreground=COLORS["text"],
            bordercolor=COLORS["line_bright"],
            focusthickness=1,
            focuscolor=COLORS["accent"],
            padding=(10, 6),
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
            padding=(14, 8),
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
            rowheight=29,
        )
        style.configure(
            "Treeview.Heading",
            background=COLORS["field_dark"],
            foreground=COLORS["accent"],
            bordercolor=COLORS["line"],
            font=("Montserrat", 8, "bold"),
        )
        style.map("Treeview", background=[("selected", "#3d5f23")], foreground=[("selected", COLORS["text"])])
        style.configure("Vertical.TScrollbar", background=COLORS["field_dark"], troughcolor=COLORS["panel"])

    def _build_layout(self) -> None:
        """Create the high-level two-column application layout."""

        self.columnconfigure(1, weight=1)
        self.rowconfigure(1, weight=1)

        header = tk.Frame(self, bg=COLORS["bg"], highlightthickness=0)
        header.grid(row=0, column=0, columnspan=2, sticky="ew")
        header.columnconfigure(1, weight=1)

        brand_block = tk.Frame(header, bg=COLORS["bg"])
        brand_block.grid(row=0, column=0, sticky="w", padx=(20, 0), pady=(14, 10))
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
            padx=14,
            pady=7,
        ).grid(row=0, column=2, sticky="e", padx=(8, 20), pady=(16, 10))

        accent_line = tk.Frame(header, height=2, bg=COLORS["accent"])
        accent_line.grid(row=1, column=0, columnspan=3, sticky="ew")

        self._build_products_panel()
        self._build_form_panel()

        self.status_var = tk.StringVar(value="Ready.")
        self.status_label = ttk.Label(self, textvariable=self.status_var, style="Muted.TLabel", padding=(18, 6, 18, 10))
        self.status_label.grid(row=2, column=0, columnspan=2, sticky="ew")

    def _build_products_panel(self) -> None:
        """Build the left sidebar that previews active storefront products."""

        panel = ttk.Frame(self, style="Panel.TFrame", padding=12)
        panel.grid(row=1, column=0, sticky="nsw", padx=(20, 8), pady=(16, 12))
        panel.rowconfigure(3, weight=1)
        panel.columnconfigure(0, weight=1)

        self.product_count_var = tk.StringVar(value="0 active products")
        ttk.Label(panel, text="STOREFRONT PRODUCTS", style="SidebarHeader.TLabel").grid(row=0, column=0, sticky="w")
        ttk.Label(panel, textvariable=self.product_count_var, style="PanelMuted.TLabel").grid(row=1, column=0, sticky="w", pady=(3, 8))
        ttk.Button(panel, text="Refresh database", command=self.load_products).grid(row=2, column=0, sticky="ew", pady=(0, 10))

        columns = ("id", "slug", "name", "price", "stock", "location")
        self.products_tree = ttk.Treeview(panel, columns=columns, show="headings", height=20)
        headings = {
            "id": ("ID", 46),
            "slug": ("Slug", 92),
            "name": ("Name", 190),
            "price": ("Price", 68),
            "stock": ("Stock", 58),
            "location": ("Location", 116),
        }

        for column, (label, width) in headings.items():
            self.products_tree.heading(column, text=label)
            anchor = "center" if column in {"id", "price", "stock"} else "w"
            self.products_tree.column(column, width=width, minwidth=width, anchor=anchor, stretch=column == "name")

        scrollbar = ttk.Scrollbar(panel, orient="vertical", command=self.products_tree.yview)
        self.products_tree.configure(yscrollcommand=scrollbar.set)
        self.products_tree.grid(row=3, column=0, sticky="nsew")
        scrollbar.grid(row=3, column=1, sticky="ns")

        ttk.Label(
            panel,
            text="New products are written to products, product_inventory, product_images and product_specs.",
            style="PanelMuted.TLabel",
            wraplength=305,
        ).grid(row=4, column=0, columnspan=2, sticky="ew", pady=(10, 0))

    def _build_form_panel(self) -> None:
        """Build the scrollable product creation form on the right."""

        wrapper = ttk.Frame(self, style="TFrame")
        wrapper.grid(row=1, column=1, sticky="nsew", padx=(8, 20), pady=(16, 12))
        wrapper.rowconfigure(0, weight=1)
        wrapper.columnconfigure(0, weight=1)

        self.form_canvas = tk.Canvas(
            wrapper,
            bg=COLORS["canvas"],
            highlightthickness=0,
            borderwidth=0,
        )
        form_scroll = ttk.Scrollbar(wrapper, orient="vertical", command=self.form_canvas.yview)
        self.form_canvas.configure(yscrollcommand=form_scroll.set)
        self.form_canvas.grid(row=0, column=0, sticky="nsew")
        form_scroll.grid(row=0, column=1, sticky="ns")

        self.form_frame = ttk.Frame(self.form_canvas, style="TFrame", padding=(0, 0, 8, 0))
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

        self.preview_title_var = tk.StringVar(value="Product name")
        self.preview_slug_var = tk.StringVar(value="product-slug")
        self.preview_price_var = tk.StringVar(value="GBP 0")
        self.preview_stock_var = tk.StringVar(value="1 in stock")
        self.preview_description_var = tk.StringVar(value="Short product description preview.")
        self.preview_image_var = tk.StringVar(value="assets/images/pc_noimage.png")

        self._build_preview_panel()

        product_section = self._section("PRODUCT")
        self._field(product_section, 0, "Name", self.name_var)
        self._field(product_section, 1, "Slug", self.slug_var, button_text="From name", button_command=self.fill_slug_from_name)
        self._field(product_section, 2, "Price", self.price_var)
        self.description_text = self._text_field(product_section, 3, "Short description", height=4)
        self._field(product_section, 4, "Main image", self.image_var, button_text="Browse", button_command=self.browse_image)
        ttk.Button(product_section, text="Use placeholder", command=self.use_placeholder_image).grid(row=6, column=1, sticky="w", pady=(0, 8))
        ttk.Checkbutton(product_section, text="Active on storefront", variable=self.active_var).grid(row=7, column=1, sticky="w", pady=(0, 4))

        inventory_section = self._section("INVENTORY")
        self._field(inventory_section, 0, "Stock quantity", self.stock_var, spin=True)
        self._field(inventory_section, 1, "Location", self.location_var)
        self._field(inventory_section, 2, "Supplier", self.supplier_var)

        specs_section = self._section("MODAL SPECS")
        specs_section.columnconfigure(1, weight=1)
        self.specs_container = ttk.Frame(specs_section, style="Section.TFrame")
        self.specs_container.grid(row=0, column=0, columnspan=3, sticky="ew")
        ttk.Button(specs_section, text="Add spec row", command=lambda: self.add_spec_row("", "")).grid(
            row=1,
            column=0,
            sticky="w",
            pady=(10, 0),
        )

        actions = ttk.Frame(self.form_frame, style="Actions.TFrame", padding=(0, 10, 0, 0))
        actions.pack(fill="x")
        actions.columnconfigure(0, weight=1)
        ttk.Button(actions, text="Clear form", command=self.clear_form).grid(row=0, column=0, sticky="w")
        self.save_button = ttk.Button(actions, text="SAVE PRODUCT", style="Accent.TButton", command=self.save_product)
        self.save_button.grid(row=0, column=1, sticky="e")
        self._wire_preview_updates()
        self._refresh_preview()

    def _build_preview_panel(self) -> None:
        """Build the storefront-style live preview above the form fields."""

        preview = ttk.Frame(self.form_frame, style="Preview.TFrame", padding=16)
        preview.pack(fill="x", pady=(0, 12))
        preview.columnconfigure(1, weight=1)

        image_stage = tk.Frame(
            preview,
            width=150,
            height=118,
            bg=COLORS["card_dark"],
            highlightthickness=1,
            highlightbackground=COLORS["line"],
        )
        image_stage.grid(row=0, column=0, rowspan=5, sticky="nsw", padx=(0, 16))
        image_stage.grid_propagate(False)
        tk.Label(
            image_stage,
            text="IMAGE\nSTAGE",
            bg=COLORS["card_dark"],
            fg=COLORS["muted_dark"],
            font=("Montserrat", 9, "bold"),
            justify="center",
        ).place(relx=0.5, rely=0.42, anchor="center")
        tk.Label(
            image_stage,
            textvariable=self.preview_image_var,
            bg=COLORS["card_dark"],
            fg=COLORS["muted"],
            font=("Montserrat", 7),
            wraplength=128,
            justify="center",
        ).place(relx=0.5, rely=0.78, anchor="center")

        ttk.Label(preview, text="LIVE STOREFRONT PREVIEW", style="PreviewAccent.TLabel").grid(row=0, column=1, sticky="w")
        tk.Label(
            preview,
            textvariable=self.preview_title_var,
            bg=COLORS["card"],
            fg=COLORS["text"],
            font=("Teko", 22, "bold"),
            anchor="w",
        ).grid(row=1, column=1, sticky="ew", pady=(4, 0))
        ttk.Label(preview, textvariable=self.preview_slug_var, style="PreviewMuted.TLabel").grid(row=2, column=1, sticky="w")
        ttk.Label(
            preview,
            textvariable=self.preview_description_var,
            style="PreviewMuted.TLabel",
            wraplength=650,
        ).grid(row=3, column=1, sticky="ew", pady=(7, 8))

        footer = ttk.Frame(preview, style="Preview.TFrame")
        footer.grid(row=4, column=1, sticky="ew")
        footer.columnconfigure(1, weight=1)
        self.preview_stock_label = tk.Label(
            footer,
            textvariable=self.preview_stock_var,
            bg=COLORS["card_dark"],
            fg=COLORS["accent_soft"],
            font=("Montserrat", 8, "bold"),
            padx=10,
            pady=4,
        )
        self.preview_stock_label.grid(row=0, column=0, sticky="w")
        tk.Label(
            footer,
            textvariable=self.preview_price_var,
            bg=COLORS["card"],
            fg=COLORS["accent"],
            font=("Teko", 20, "bold"),
        ).grid(row=0, column=2, sticky="e")

    def _wire_preview_updates(self) -> None:
        """Refresh the live product preview when form values change."""

        watched_vars = [
            self.name_var,
            self.slug_var,
            self.price_var,
            self.image_var,
            self.stock_var,
        ]

        for variable in watched_vars:
            variable.trace_add("write", lambda *_args: self._refresh_preview())

        self.description_text.bind("<KeyRelease>", lambda _event: self._refresh_preview())
        self.description_text.bind("<<Paste>>", lambda _event: self.after_idle(self._refresh_preview))

    def _refresh_preview(self) -> None:
        """Project the current form state into the storefront preview card."""

        name = clean_one_line(self.name_var.get()) or "Product name"
        slug = slugify(self.slug_var.get() or self.name_var.get()) or "product-slug"
        description = self.description_text.get("1.0", "end").strip() or "Short product description preview."
        price_text = re.sub(r"[^0-9.]", "", self.price_var.get())
        image_path = self.image_var.get().strip().replace("\\", "/") or "assets/images/pc_noimage.png"

        try:
            price = Decimal(price_text).quantize(Decimal("0.01"))
        except (InvalidOperation, ValueError):
            price = Decimal("0.00")

        try:
            stock_quantity = int(self.stock_var.get())
        except ValueError:
            stock_quantity = 0

        if stock_quantity <= 0:
            stock_text = "out of stock"
            stock_color = COLORS["danger"]
        elif stock_quantity <= 3:
            stock_text = f"low stock: {stock_quantity} left"
            stock_color = COLORS["danger"]
        elif stock_quantity <= 9:
            stock_text = f"medium stock: {stock_quantity} left"
            stock_color = COLORS["warning"]
        else:
            stock_text = f"{stock_quantity} in stock"
            stock_color = COLORS["accent_soft"]

        self.preview_title_var.set(name)
        self.preview_slug_var.set(slug)
        self.preview_price_var.set(f"GBP {price:,.0f}")
        self.preview_stock_var.set(stock_text)
        self.preview_description_var.set(clean_one_line(description)[:180])
        self.preview_image_var.set(image_path)
        self.preview_stock_label.configure(fg=stock_color)

    def _sync_form_scrollregion(self, _event: tk.Event) -> None:
        """Keep the canvas scroll area aligned with the dynamic form height."""

        self.form_canvas.configure(scrollregion=self.form_canvas.bbox("all"))

    def _sync_form_width(self, event: tk.Event) -> None:
        """Stretch the embedded form frame to the visible canvas width."""

        self.form_canvas.itemconfigure(self.form_window_id, width=event.width)

    def _section(self, title: str) -> ttk.Frame:
        """Create a labelled form section with consistent padding."""

        frame = ttk.Frame(self.form_frame, style="Section.TFrame", padding=14)
        frame.pack(fill="x", pady=(0, 12))
        frame.columnconfigure(1, weight=1)
        ttk.Label(frame, text=title, style="Header.TLabel").grid(row=0, column=0, columnspan=3, sticky="w", pady=(0, 10))
        return frame

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
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(row=real_row, column=0, sticky="w", padx=(0, 12), pady=5)

        if spin:
            field = ttk.Spinbox(parent, from_=0, to=999999, textvariable=variable, width=12)
        else:
            field = ttk.Entry(parent, textvariable=variable)

        field.grid(row=real_row, column=1, sticky="ew", pady=5)

        if button_text and button_command:
            ttk.Button(parent, text=button_text, command=button_command).grid(row=real_row, column=2, sticky="ew", padx=(8, 0), pady=5)

    def _text_field(self, parent: ttk.Frame, row: int, label: str, height: int) -> tk.Text:
        """Create the multiline description field styled like the dark form."""

        real_row = row + 1
        ttk.Label(parent, text=label.upper(), style="SectionMuted.TLabel").grid(row=real_row, column=0, sticky="nw", padx=(0, 12), pady=5)
        field = tk.Text(
            parent,
            height=height,
            wrap="word",
            bg=COLORS["field"],
            fg=COLORS["text"],
            insertbackground=COLORS["text"],
            relief="solid",
            bd=1,
            highlightthickness=1,
            highlightbackground=COLORS["line"],
            highlightcolor=COLORS["line_bright"],
            font=("Montserrat", 9),
        )
        field.grid(row=real_row, column=1, columnspan=2, sticky="ew", pady=5)
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
        frame.grid(row=index, column=0, sticky="ew", pady=3)
        frame.columnconfigure(1, weight=1)

        label_var = tk.StringVar(value=label)
        value_var = tk.StringVar(value=value)

        ttk.Entry(frame, textvariable=label_var, width=22).grid(row=0, column=0, sticky="ew", padx=(0, 8))
        ttk.Entry(frame, textvariable=value_var).grid(row=0, column=1, sticky="ew")
        ttk.Button(frame, text="Remove", command=lambda selected=frame: self.remove_spec_row(selected)).grid(
            row=0,
            column=2,
            sticky="e",
            padx=(8, 0),
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

        self.set_status(f"Loaded {len(self.products)} active products.")

    def fill_slug_from_name(self) -> None:
        """Generate the product slug from the current product name field."""

        slug = slugify(self.name_var.get())
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
        self._reset_specs()
        self._refresh_preview()

        if show_status:
            self.set_status("Form cleared.")

    def save_product(self) -> None:
        """Validate the form, copy the image if needed, and create the product."""

        try:
            payload = self.collect_payload()
        except ValidationError as error:
            self.set_status(str(error), danger=True)
            messagebox.showwarning("Check the form", str(error))
            return

        if not messagebox.askyesno("Create product", f"Create '{payload.name}' on the storefront?"):
            return

        self.save_button.state(["disabled"])
        self.set_status("Saving product...")
        self.update_idletasks()

        try:
            payload.main_image = self.prepare_image_path(payload.slug, payload.main_image)
            product_id = self.db.create_product(payload)
        except (DatabaseError, OSError) as error:
            self.set_status(f"Save failed: {error}", danger=True)
            messagebox.showerror("Save failed", str(error))
            return
        finally:
            self.save_button.state(["!disabled"])

        self.set_status(f"Saved product #{product_id}: {payload.name}")
        messagebox.showinfo("Product saved", f"Product #{product_id} was added.")
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
            raise ValidationError("Slug is required. Use 'From name' if you want it generated.")

        if len(slug) > 50:
            raise ValidationError("Slug must be 50 characters or fewer.")

        if slug in self.existing_slugs:
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
