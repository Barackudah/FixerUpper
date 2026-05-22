<?php
// Report MySQL problems as exceptions so database failures stop the request clearly.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Open the local FixerUpper database connection used by the storefront and cart endpoints.
$conn = new mysqli('localhost', 'root', '', 'fixerupper');

// Use utf8mb4 so product names, symbols and future multilingual text are stored safely.
$conn->set_charset('utf8mb4');
