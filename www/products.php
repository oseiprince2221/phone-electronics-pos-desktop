
<?php
require_once 'config/database.php';

function logProductActivity(
    PDO $pdo,
    string $activityType,
    string $description,
    ?string $reference = null
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO activity_log
         (activity_type, description, reference, staff_name)
         VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
        $activityType,
        $description,
        $reference,
        'Administrator'
    ]);
}

$pageTitle = 'Product Management';
$activePage = 'products';

$error = '';
$search = trim($_GET['search'] ?? '');

/* ADD PRODUCT */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $sku = strtoupper(trim($_POST['sku'] ?? ''));
        $category = trim($_POST['category'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $costPrice = $_POST['cost_price'] ?? '';
        $sellingPrice = $_POST['selling_price'] ?? '';
        $quantity = $_POST['quantity'] ?? '';
        $lowStock = $_POST['low_stock_level'] ?? '5';

        $validNumbers =
            is_numeric($costPrice) &&
            is_numeric($sellingPrice) &&
            is_numeric($quantity) &&
            is_numeric($lowStock);

        if ($name === '' || $sku === '' || $category === '') {
            $error = 'Product name, SKU and category are required.';
        } elseif (!$validNumbers) {
            $error = 'Enter valid numbers for price and stock fields.';
        } elseif (
            (float) $costPrice < 0 ||
            (float) $sellingPrice < 0 ||
            (float) $quantity < 0 ||
            (float) $lowStock < 0 ||
            (float) $quantity != (int) $quantity ||
            (float) $lowStock != (int) $lowStock
        ) {
            $error = 'Prices and stock values must be non-negative. Quantity and low-stock level must be whole numbers.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "INSERT INTO products
                    (name, sku, category, brand, cost_price,
                     selling_price, quantity, low_stock_level)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $name,
                    $sku,
                    $category,
                    $brand !== '' ? $brand : null,
                    $costPrice,
                    $sellingPrice,
                    (int) $quantity,
                    (int) $lowStock
                ]);

                $productId = (int) $pdo->lastInsertId();

                logProductActivity(
                    $pdo,
                    'PRODUCT_ADDED',
                    "Added product: {$name} (SKU: {$sku})",
                    (string) $productId
                );

                if ((int) $quantity > 0) {
                    $stockStmt = $pdo->prepare(
                        "INSERT INTO stock_movements
                         (product_id, product_name, movement_type, quantity,
                          quantity_before, quantity_after, reference,
                          staff_name, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stockStmt->execute([
                        $productId,
                        $name,
                        'OPENING_STOCK',
                        (int) $quantity,
                        0,
                        (int) $quantity,
                        'PRODUCT-' . $productId,
                        'Administrator',
                        'Initial stock when product was created'
                    ]);
                }

                $pdo->commit();

                header('Location: products.php?success=added');
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    $error = 'That SKU already exists. Use a different SKU.';
                } else {
                    $error = 'Unable to save the product. Check your database settings.';
                }
            }
        }
    }

    /* DELETE PRODUCT */
    if ($action === 'delete') {
        $id = filter_var(
            $_POST['product_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if ($id !== false && $id !== null && $id > 0) {
            try {
                $pdo->beginTransaction();

                $findStmt = $pdo->prepare(
                    "SELECT id, name, sku, quantity
                     FROM products WHERE id = ?"
                );

                $findStmt->execute([$id]);
                $product = $findStmt->fetch();

                if (!$product) {
                    $pdo->rollBack();
                    $error = 'Product not found.';
                } else {
                    logProductActivity(
                        $pdo,
                        'PRODUCT_DELETED',
                        "Deleted product: {$product['name']} (SKU: {$product['sku']})",
                        (string) $product['id']
                    );

                    $deleteStmt = $pdo->prepare(
                        "DELETE FROM products WHERE id = ?"
                    );

                    $deleteStmt->execute([$id]);

                    $pdo->commit();

                    header('Location: products.php?success=deleted');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Unable to delete this product. It may already be linked to a sale.';
            }
        } else {
            $error = 'Invalid product selected.';
        }
    }
}

/* SEARCH AND DISPLAY PRODUCTS */
if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM products
         WHERE name LIKE ?
            OR sku LIKE ?
            OR brand LIKE ?
         ORDER BY id DESC"
    );

    $term = '%' . $search . '%';

    $stmt->execute([$term, $term, $term]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->query(
        "SELECT * FROM products ORDER BY id DESC"
    );

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require 'includes/header.php';
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if (($_GET['success'] ?? '') === 'added'): ?>
    <div class="alert alert-success">Product added successfully.</div>
<?php elseif (($_GET['success'] ?? '') === 'deleted'): ?>
    <div class="alert alert-success">Product deleted successfully.</div>
<?php endif; ?>

<section class="panel">
    <h2>Add New Product</h2>

    <form method="POST" action="products.php" class="product-form">
        <input type="hidden" name="action" value="add">

        <div class="form-grid">
            <div class="form-group">
                <label for="name">Product Name *</label>
                <input id="name" name="name" type="text"
                       maxlength="150" required>
            </div>

            <div class="form-group">
                <label for="sku">SKU / Product Code *</label>
                <input id="sku" name="sku" type="text"
                       maxlength="80" required>
            </div>

            <div class="form-group">
                <label for="category">Category *</label>
                <select id="category" name="category" required>
                    <option value="">Select category</option>
                    <option value="Phones">Phones</option>
                    <option value="Accessories">Accessories</option>
                    <option value="Laptops">Laptops</option>
                    <option value="Audio">Audio</option>
                    <option value="Electronics">Electronics</option>
                    <option value="Repairs">Repairs</option>
                </select>
            </div>

            <div class="form-group">
                <label for="brand">Brand</label>
                <input id="brand" name="brand" type="text"
                       maxlength="100">
            </div>

            <div class="form-group">
                <label for="cost_price">Cost Price (GH₵) *</label>
                <input id="cost_price" name="cost_price"
                       type="number" min="0" step="0.01"
                       value="0.00" required>
            </div>

            <div class="form-group">
                <label for="selling_price">Selling Price (GH₵) *</label>
                <input id="selling_price" name="selling_price"
                       type="number" min="0" step="0.01"
                       value="0.00" required>
            </div>

            <div class="form-group">
                <label for="quantity">Opening Stock *</label>
                <input id="quantity" name="quantity"
                       type="number" min="0" step="1"
                       value="0" required>
            </div>

            <div class="form-group">
                <label for="low_stock_level">Low-Stock Alert Level *</label>
                <input id="low_stock_level" name="low_stock_level"
                       type="number" min="0" step="1"
                       value="5" required>
            </div>
        </div>

        <button class="btn btn-primary" type="submit">
            Save Product
        </button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Product List</h2>
        <span><?= count($products) ?> result(s)</span>
    </div>

    <form method="GET" action="products.php" class="search-form">
        <input type="search" name="search"
               placeholder="Search name, SKU or brand..."
               value="<?= e($search) ?>">

        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-secondary" href="products.php">Clear</a>
    </form>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>Cost</th>
                    <th>Selling Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="9" class="empty-message">
                            No products found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= e($product['name']) ?></td>
                        <td><?= e($product['sku']) ?></td>
                        <td><?= e($product['category']) ?></td>
                        <td><?= e($product['brand'] ?? '') ?></td>
                        <td><?= money($product['cost_price']) ?></td>
                        <td><?= money($product['selling_price']) ?></td>
                        <td><?= (int) $product['quantity'] ?></td>

                        <td>
                            <?php if ((int) $product['quantity'] === 0): ?>
                                <span class="badge badge-danger">Out of stock</span>
                            <?php elseif ((int) $product['quantity'] <= (int) $product['low_stock_level']): ?>
                                <span class="badge badge-warning">Low stock</span>
                            <?php else: ?>
                                <span class="badge badge-success">In stock</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <form method="POST" action="products.php"
                                  class="inline-form delete-form">
                                <input type="hidden" name="action" value="delete">

                                <input type="hidden" name="product_id"
                                       value="<?= (int) $product['id'] ?>">

                                <button type="submit"
                                        class="btn btn-danger btn-small">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require 'includes/footer.php'; ?>
