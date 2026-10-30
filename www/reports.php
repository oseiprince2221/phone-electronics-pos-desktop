
<?php
require_once 'config/database.php';

$pageTitle = 'Reports';
$activePage = 'reports';

$summary = $pdo->query(
    "SELECT
        COUNT(*) AS product_count,
        COALESCE(SUM(quantity), 0) AS total_units,
        COALESCE(SUM(cost_price * quantity), 0) AS cost_value,
        COALESCE(SUM(selling_price * quantity), 0) AS selling_value
     FROM products"
)->fetch();

$categories = $pdo->query(
    "SELECT
        category,
        COUNT(*) AS product_count,
        COALESCE(SUM(quantity), 0) AS total_units,
        COALESCE(SUM(cost_price * quantity), 0) AS cost_value
     FROM products
     GROUP BY category
     ORDER BY category"
)->fetchAll();

require 'includes/header.php';
?>

<section class="stats-grid">
    <div class="stat-card">
        <span>Product Types</span>
        <h2><?= (int) $summary['product_count'] ?></h2>
    </div>

    <div class="stat-card">
        <span>Total Units</span>
        <h2><?= (int) $summary['total_units'] ?></h2>
    </div>

    <div class="stat-card">
        <span>Inventory Cost Value</span>
        <h2><?= money($summary['cost_value']) ?></h2>
    </div>

    <div class="stat-card">
        <span>Potential Selling Value</span>
        <h2><?= money($summary['selling_value']) ?></h2>
    </div>
</section>

<section class="panel">
    <h2>Inventory Report by Category</h2>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Product Types</th>
                    <th>Total Units</th>
                    <th>Stock Cost Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$categories): ?>
                    <tr>
                        <td colspan="4">No products available for reporting.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?= e($category['category']) ?></td>
                        <td><?= (int) $category['product_count'] ?></td>
                        <td><?= (int) $category['total_units'] ?></td>
                        <td><?= money($category['cost_value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="muted">
        Potential selling value is the value of current stock at listed
        selling prices. It does not represent actual sales or net profit.
    </p>
</section>

<?php require 'includes/footer.php'; ?>
