<?php
session_start();
require_once 'config/database.php';

$pageTitle = 'Sales & Checkout';
$activePage = 'sales';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$error = '';
$success = '';
$receipt = null;
$receiptItems = [];

function sales_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sales_money($amount): string
{
    return 'GH₵ ' . number_format((float) $amount, 2);
}

function cart_total(array $cart): float
{
    $total = 0;

    foreach ($cart as $item) {
        $total += (float) $item['price'] * (int) $item['quantity'];
    }

    return round($total, 2);
}

function logSalesActivity(
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $productId = filter_var(
            $_POST['product_id'] ?? '',
            FILTER_VALIDATE_INT
        );
        $quantity = filter_var(
            $_POST['quantity'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$productId || !$quantity || $quantity < 1) {
            $error = 'Select a product and enter a quantity of at least 1.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, name, sku, selling_price, quantity
                 FROM products WHERE id = ?'
            );
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                $error = 'Product not found.';
            } elseif ((int) $product['quantity'] < 1) {
                $error = 'This product is out of stock.';
            } else {
                $currentQuantity = (int) (
                    $_SESSION['cart'][$productId]['quantity'] ?? 0
                );
                $newQuantity = $currentQuantity + $quantity;

                if ($newQuantity > (int) $product['quantity']) {
                    $error = 'The requested quantity exceeds available stock.';
                } else {
                    $_SESSION['cart'][$productId] = [
                        'id' => (int) $product['id'],
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'price' => (float) $product['selling_price'],
                        'quantity' => $newQuantity
                    ];

                    header('Location: sales.php?added=1');
                    exit;
                }
            }
        }
    }

    if ($action === 'remove') {
        $productId = filter_var(
            $_POST['product_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if ($productId && isset($_SESSION['cart'][$productId])) {
            unset($_SESSION['cart'][$productId]);
        }

        header('Location: sales.php');
        exit;
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        header('Location: sales.php');
        exit;
    }

    if ($action === 'checkout') {
        $cart = $_SESSION['cart'];
        $amountPaid = $_POST['amount_paid'] ?? '';
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');

        if (!$cart) {
            $error = 'Your cart is empty.';
        } elseif ($customerName === '' || $customerPhone === '') {
            $error = 'Enter the customer name and phone number.';
        } elseif (
            strlen($customerName) > 150 ||
            strlen($customerPhone) > 40
        ) {
            $error = 'The customer name or phone number is too long.';
        } elseif (!is_numeric($amountPaid) || (float) $amountPaid < 0) {
            $error = 'Enter a valid amount paid. The amount cannot be negative.';
        } else {
            try {
                $pdo->beginTransaction();

                $lockedProducts = [];
                $subtotal = 0;

                foreach ($cart as $productId => $item) {
                    $stmt = $pdo->prepare(
                        'SELECT id, name, sku, selling_price, quantity
                         FROM products WHERE id = ?'
                    );
                    $stmt->execute([(int) $productId]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        throw new RuntimeException(
                            'A product in your cart no longer exists.'
                        );
                    }

                    if ((int) $product['quantity'] < (int) $item['quantity']) {
                        throw new RuntimeException(
                            'Insufficient stock for ' . $product['name'] .
                            '. Please update your cart.'
                        );
                    }

                    $price = (float) $product['selling_price'];
                    $quantity = (int) $item['quantity'];
                    $lineTotal = round($price * $quantity, 2);

                    $lockedProducts[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'price' => $price,
                        'line_total' => $lineTotal
                    ];

                    $subtotal += $lineTotal;
                }

                $subtotal = round($subtotal, 2);
                $amountPaid = round((float) $amountPaid, 2);

                $change = round(max(0, $amountPaid - $subtotal), 2);
                $balanceDue = round(max(0, $subtotal - $amountPaid), 2);

                $receiptNo = 'POS-' . date('Ymd-His') . '-' .
                    strtoupper(bin2hex(random_bytes(2)));

                $stmt = $pdo->prepare(
                    'INSERT INTO sales
                     (receipt_no, subtotal, total, amount_paid,
                      change_amount, payment_method)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $receiptNo,
                    $subtotal,
                    $subtotal,
                    $amountPaid,
                    $change,
                    'Cash'
                ]);

                $saleId = (int) $pdo->lastInsertId();

                $insertCustomer = $pdo->prepare(
                    'INSERT INTO sale_customer_details
                     (sale_id, customer_name, customer_phone, balance_due)
                     VALUES (?, ?, ?, ?)'
                );

                $insertCustomer->execute([
                    $saleId,
                    $customerName,
                    $customerPhone,
                    $balanceDue
                ]);

                $insertItem = $pdo->prepare(
                    'INSERT INTO sale_items
                     (sale_id, product_id, product_name, sku,
                      quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                $updateStock = $pdo->prepare(
                    'UPDATE products
                     SET quantity = quantity - ?
                     WHERE id = ? AND quantity >= ?'
                );

                $insertStockMovement = $pdo->prepare(
                    "INSERT INTO stock_movements
                     (product_id, product_name, movement_type, quantity,
                      quantity_before, quantity_after, reference,
                      staff_name, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                foreach ($lockedProducts as $entry) {
                    $product = $entry['product'];
                    $quantity = $entry['quantity'];

                    $insertItem->execute([
                        $saleId,
                        $product['id'],
                        $product['name'],
                        $product['sku'],
                        $quantity,
                        $entry['price'],
                        $entry['line_total']
                    ]);

                    $updateStock->execute([
                        $quantity,
                        $product['id'],
                        $quantity
                    ]);

                    if ($updateStock->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Stock changed during checkout. Please try again.'
                        );
                    }

                    $quantityBefore = (int) $product['quantity'];
                    $quantityAfter = $quantityBefore - $quantity;

                    $insertStockMovement->execute([
                        (int) $product['id'],
                        $product['name'],
                        'SALE',
                        $quantity,
                        $quantityBefore,
                        $quantityAfter,
                        $receiptNo,
                        'Administrator',
                        'Stock reduced by completed sale'
                    ]);
                }

                logSalesActivity(
                    $pdo,
                    'SALE_COMPLETED',
                    "Completed sale {$receiptNo}; total GH₵ " .
                        number_format($subtotal, 2, '.', ''),
                    $receiptNo
                );

                $pdo->commit();

                $_SESSION['cart'] = [];

                header(
                    'Location: sales.php?receipt=' . urlencode($receiptNo)
                );
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('POS checkout error: ' . $e->getMessage());

                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Checkout failed. No sale was completed. Check your database tables and try again.';
            }
        }
    }
}

if (isset($_GET['receipt'])) {
    $stmt = $pdo->prepare(
        'SELECT sales.*,
                details.customer_name,
                details.customer_phone,
                details.balance_due
         FROM sales
         LEFT JOIN sale_customer_details AS details
            ON details.sale_id = sales.id
         WHERE sales.receipt_no = ?'
    );
    $stmt->execute([$_GET['receipt']]);
    $receipt = $stmt->fetch();

    if ($receipt) {
        $stmt = $pdo->prepare(
            'SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id'
        );
        $stmt->execute([$receipt['id']]);
        $receiptItems = $stmt->fetchAll();
    } else {
        $error = 'Receipt not found.';
    }
}

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, name, sku, brand, selling_price, quantity
         FROM products
         WHERE (name LIKE ? OR sku LIKE ? OR brand LIKE ?)
           AND quantity > 0
         ORDER BY name LIMIT 100'
    );

    $term = '%' . $search . '%';
    $stmt->execute([$term, $term, $term]);
    $availableProducts = $stmt->fetchAll();
} else {
    $availableProducts = $pdo->query(
        'SELECT id, name, sku, brand, selling_price, quantity
         FROM products
         WHERE quantity > 0
         ORDER BY name LIMIT 100'
    )->fetchAll();
}

$cart = $_SESSION['cart'];
$total = cart_total($cart);

require 'includes/header.php';
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= sales_e($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">Product added to cart.</div>
<?php endif; ?>

<?php if ($receipt): ?>
    <section class="panel receipt">
        <div class="receipt-heading">
            <h2>Sales Receipt</h2>
            <p>Mr. Black Phones and Electronics</p>
            <p>Receipt: <?= sales_e($receipt['receipt_no']) ?></p>
            <p>Date: <?= sales_e($receipt['sold_at']) ?></p>
        </div>

        <div class="receipt-customer">
            <p>
                <span>Customer</span>
                <strong><?= sales_e($receipt['customer_name'] ?? '') ?></strong>
            </p>
            <p>
                <span>Phone</span>
                <strong><?= sales_e($receipt['customer_phone'] ?? '') ?></strong>
            </p>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($receiptItems as $item): ?>
                        <tr>
                            <td>
                                <?= sales_e($item['product_name']) ?>
                                <br>
                                <small><?= sales_e($item['sku']) ?></small>
                            </td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td><?= sales_money($item['unit_price']) ?></td>
                            <td><?= sales_money($item['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="receipt-totals">
            <p>
                Total:
                <strong><?= sales_money($receipt['total']) ?></strong>
            </p>
            <p>
                Amount paid: <?= sales_money($receipt['amount_paid']) ?>
            </p>

            <?php if ((float) ($receipt['balance_due'] ?? 0) > 0): ?>
                <p class="receipt-balance">
                    Balance due:
                    <strong><?= sales_money($receipt['balance_due']) ?></strong>
                </p>
            <?php else: ?>
                <p>
                    Change:
                    <strong><?= sales_money($receipt['change_amount']) ?></strong>
                </p>
            <?php endif; ?>

            <p>
                Payment method: <?= sales_e($receipt['payment_method']) ?>
            </p>
        </div>

        <p class="receipt-thanks">Thank you for shopping with us!</p>

        <button class="btn btn-primary no-print"
                onclick="window.print()">Print Receipt</button>
        <a class="btn btn-secondary no-print" href="sales.php">New Sale</a>
    </section>

<?php else: ?>

    <section class="panel">
        <h2>Find Products</h2>

        <form method="GET" action="sales.php" class="search-form">
            <input type="search" name="search"
                   value="<?= sales_e($search) ?>"
                   placeholder="Search product name, SKU or brand...">
            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-secondary" href="sales.php">Clear</a>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Price</th>
                        <th>Available</th>
                        <th>Add to Cart</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$availableProducts): ?>
                        <tr>
                            <td colspan="5">No available products found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($availableProducts as $product): ?>
                        <tr>
                            <td>
                                <?= sales_e($product['name']) ?>
                                <br>
                                <small><?= sales_e($product['brand'] ?? '') ?></small>
                            </td>
                            <td><?= sales_e($product['sku']) ?></td>
                            <td><?= sales_money($product['selling_price']) ?></td>
                            <td><?= (int) $product['quantity'] ?></td>
                            <td>
                                <form method="POST" action="sales.php"
                                      class="add-cart-form">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id"
                                           value="<?= (int) $product['id'] ?>">
                                    <input type="number" name="quantity"
                                           min="1"
                                           max="<?= (int) $product['quantity'] ?>"
                                           value="1" required>
                                    <button class="btn btn-primary btn-small"
                                            type="submit">Add</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <h2>Shopping Cart</h2>

            <?php if ($cart): ?>
                <form method="POST" action="sales.php" class="inline-form">
                    <input type="hidden" name="action" value="clear">
                    <button class="btn btn-secondary" type="submit">
                        Clear Cart
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Line Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$cart): ?>
                        <tr>
                            <td colspan="6">Your cart is empty.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($cart as $item): ?>
                        <tr>
                            <td><?= sales_e($item['name']) ?></td>
                            <td><?= sales_e($item['sku']) ?></td>
                            <td><?= sales_money($item['price']) ?></td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td>
                                <?= sales_money(
                                    $item['price'] * $item['quantity']
                                ) ?>
                            </td>
                            <td>
                                <form method="POST" action="sales.php"
                                      class="inline-form">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id"
                                           value="<?= (int) $item['id'] ?>">
                                    <button class="btn btn-danger btn-small"
                                            type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="checkout-total">
            <span>Total to Pay</span>
            <strong><?= sales_money($total) ?></strong>
        </div>

        <?php if ($cart): ?>
            <form method="POST" action="sales.php" class="payment-form">
                <input type="hidden" name="action" value="checkout">

                <div class="form-group">
                    <label for="customer_name">Customer Name</label>
                    <input type="text" id="customer_name" name="customer_name"
                           maxlength="150" autocomplete="name" required>
                </div>

                <div class="form-group">
                    <label for="customer_phone">Customer Phone Number</label>
                    <input type="tel" id="customer_phone" name="customer_phone"
                           maxlength="40" autocomplete="tel" required>
                </div>

                <div class="form-group">
                    <label for="amount_paid">Amount Paid Now (GH₵)</label>
                    <input type="number" id="amount_paid" name="amount_paid"
                           min="0"
                           step="0.01"
                           value="<?= number_format($total, 2, '.', '') ?>"
                           required>
                </div>

                <p class="payment-help">
                    Any amount still unpaid will be recorded as a balance due
                    on the customer's receipt.
                </p>

                <button class="btn btn-primary" type="submit">
                    Complete Sale
                </button>
            </form>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>
