
<?php
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('money')) {
    function money($amount): string
    {
        return 'GH₵ ' . number_format((float) $amount, 2);
    }
}

$pageTitle = $pageTitle ?? 'Mr. Black Phones and Electronics';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | Mr. Black Phones and Electronics</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <div class="brand-icon">P</div>

        <div>
            <strong>Mr. Black Phones and Electronics</strong>
            <small>Local Store Management</small>
        </div>
    </div>

    <button
        type="button"
        class="btn btn-primary"
        onclick="window.location.reload();"
        title="Refresh this page"
    >
        ↻ Refresh
    </button>
</header>

<div class="layout">
    <aside class="sidebar">
        <p class="menu-heading">MAIN MENU</p>

        <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>"
           href="dashboard.php">Dashboard</a>

        <a class="<?= $activePage === 'products' ? 'active' : '' ?>"
           href="products.php">Products</a>

        <a class="<?= $activePage === 'sales' ? 'active' : '' ?>"
           href="sales.php">Sales / Checkout</a>

        <a class="<?= $activePage === 'inventory' ? 'active' : '' ?>"
           href="inventory.php">Inventory</a>

        <a class="<?= $activePage === 'reports' ? 'active' : '' ?>"
           href="reports.php">Reports</a>
    </aside>

    <main class="main-content">
        <div class="page-heading">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <p>Manage your phone and electronics shop.</p>
            </div>
        </div>
