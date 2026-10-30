
<?php
require_once 'config/database.php';

$pageTitle = 'Inventory';
$activePage = 'inventory';

$products = $pdo->query(
    "SELECT * FROM products
     ORDER BY
        CASE
            WHEN quantity = 0 THEN 0
            WHEN quantity <= low_stock_level THEN 1
            ELSE 2
        END,
        name ASC"
)->fetchAll();

$totalUnits = (int) $pdo->query(
    "SELECT COALESCE(SUM(quantity), 0) FROM products"
)->fetchColumn();

require 'includes/header.php';
?>

<section class="stats-grid">
    <div class="stat-card">
        <span>Products Listed</span>
        <h2><?= count($products) ?></h2>
    </div>

    <div class="stat-card">
        <span>Total Units in Stock</span>
        <h2><?= $totalUnits ?></h2>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Stock Overview</h2>
        <a class="btn btn-primary" href="products.php">Manage Products</a>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Alert Level</th>
                    <th>Stock Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="6">No inventory recorded yet.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= e($product['name']) ?></td>
                        <td><?= e($product['sku']) ?></td>
                        <td><?= e($product['category']) ?></td>
                        <td><?= (int) $product['quantity'] ?></td>
                        <td><?= (int) $product['low_stock_level'] ?></td>
                        <td>
                            <?php if ((int) $product['quantity'] === 0): ?>
                                <span class="badge badge-danger">Out of stock</span>
                            <?php elseif ((int) $product['quantity'] <= (int) $product['low_stock_level']): ?>
                                <span class="badge badge-warning">Restock soon</span>
                            <?php else: ?>
                                <span class="badge badge-success">Available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'includes/footer.php'; ?>
