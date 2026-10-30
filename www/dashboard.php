
<?php
require_once 'config/database.php';

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalUnits = (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM products")->fetchColumn();
$stockValue = (float) $pdo->query("SELECT COALESCE(SUM(cost_price * quantity), 0) FROM products")->fetchColumn();
$lowStockCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= low_stock_level")->fetchColumn();
$recentProducts = $pdo->query("SELECT * FROM products ORDER BY id DESC LIMIT 5")->fetchAll();

require 'includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <span>Total Products</span>
        <h2><?= $totalProducts ?></h2>
        <p>Different products registered</p>
    </div>

    <div class="stat-card">
        <span>Total Units</span>
        <h2><?= $totalUnits ?></h2>
        <p>Items currently in stock</p>
    </div>

    <div class="stat-card">
        <span>Stock Cost Value</span>
        <h2><?= money($stockValue) ?></h2>
        <p>Cost value of current stock</p>
    </div>

    <div class="stat-card warning-card">
        <span>Low-Stock Products</span>
        <h2><?= $lowStockCount ?></h2>
        <p>Products at or below their stock limit</p>
    </div>
</div>

<section class="panel">
    <div class="panel-heading">
        <h2>Recently Added Products</h2>
        <a class="btn btn-primary" href="products.php">Manage Products</a>
    </div>

    <?php if (!$recentProducts): ?>
        <p class="empty-message">
            No products have been added yet.
            <a href="products.php">Add your first product</a>.
        </p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Selling Price</th>
                        <th>Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentProducts as $product): ?>
                        <tr>
                            <td><?= e($product['name']) ?></td>
                            <td><?= e($product['sku']) ?></td>
                            <td><?= e($product['category']) ?></td>
                            <td><?= money($product['selling_price']) ?></td>
                            <td><?= (int) $product['quantity'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Excel Business Records</h2>
    </div>

    <p>
        Click once to generate and download the latest business records
        workbook. Open the downloaded file in Microsoft Excel.
    </p>

    <a class="btn btn-primary" href="export_excel.php">
        Export Business Records to Excel
    </a>
</section>

<?php require 'includes/footer.php'; ?>
