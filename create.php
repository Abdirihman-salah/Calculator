<?php
/**
 * Create Sale - CARTAN ERP
 * Electronics & Mobile Repair Shop
 */

// Include configuration and authentication
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
requireAuth();

// Check permission
if (!hasPermission('sales')) {
    header('Location: ' . BASE_URL . '/modules/dashboard/');
    exit;
}

// Initialize variables
$errors = [];
$success = false;
$invoiceNo = '';
$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;
$taxAmount = 0;
$totalAmount = 0;

// Get database connection
try {
    $pdo = getDB();
    
    // Generate invoice number
    $lastInvoice = $pdo->query("SELECT invoice_no FROM sales ORDER BY id DESC LIMIT 1")->fetch();
    if ($lastInvoice) {
        $lastNumber = intval(substr($lastInvoice['invoice_no'], 3));
        $invoiceNo = DEFAULT_INVOICE_PREFIX . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $invoiceNo = DEFAULT_INVOICE_PREFIX . '1001';
    }
    
    // Get products for dropdown
    $products = $pdo->query("
        SELECT id, sku, name, selling_price, stock_quantity, tax_rate 
        FROM products 
        WHERE is_active = 1 AND stock_quantity > 0 
        ORDER BY name
    ")->fetchAll();
    
    // Get services for dropdown
    $services = $pdo->query("
        SELECT id, service_code, name, base_price, tax_rate 
        FROM services 
        WHERE is_active = 1 
        ORDER BY name
    ")->fetchAll();
    
    // Get customers for dropdown
    $customers = $pdo->query("
        SELECT id, customer_code, full_name, phone, discount_percent 
        FROM customers 
        WHERE is_active = 1 
        ORDER BY full_name
    ")->fetchAll();
    
    // Get payment methods
    $paymentMethods = $pdo->query("SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
    
} catch (Exception $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_product') {
        $productId = $_POST['product_id'] ?? 0;
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if ($productId && $quantity > 0) {
            $stmt = $pdo->prepare("SELECT id, sku, name, selling_price, stock_quantity, tax_rate FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if ($product) {
                if ($quantity > $product['stock_quantity']) {
                    $errors[] = "Insufficient stock. Available: {$product['stock_quantity']}";
                } else {
                    // Check if product already in cart
                    $found = false;
                    foreach ($cart as &$item) {
                        if ($item['type'] === 'product' && $item['id'] == $productId) {
                            $item['quantity'] += $quantity;
                            $item['total'] = $item['quantity'] * $item['price'];
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        $cart[] = [
                            'type' => 'product',
                            'id' => $product['id'],
                            'sku' => $product['sku'],
                            'name' => $product['name'],
                            'price' => $product['selling_price'],
                            'quantity' => $quantity,
                            'tax_rate' => $product['tax_rate'],
                            'total' => $product['selling_price'] * $quantity
                        ];
                    }
                    
                    $_SESSION['cart'] = $cart;
                }
            }
        }
    }
    elseif ($action === 'add_service') {
        $serviceId = $_POST['service_id'] ?? 0;
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if ($serviceId && $quantity > 0) {
            $stmt = $pdo->prepare("SELECT id, service_code, name, base_price, tax_rate FROM services WHERE id = ?");
            $stmt->execute([$serviceId]);
            $service = $stmt->fetch();
            
            if ($service) {
                $cart[] = [
                    'type' => 'service',
                    'id' => $service['id'],
                    'sku' => $service['service_code'],
                    'name' => $service['name'],
                    'price' => $service['base_price'],
                    'quantity' => $quantity,
                    'tax_rate' => $service['tax_rate'],
                    'total' => $service['base_price'] * $quantity
                ];
                
                $_SESSION['cart'] = $cart;
            }
        }
    }
    elseif ($action === 'remove_item') {
        $index = $_POST['index'] ?? -1;
        if (isset($cart[$index])) {
            array_splice($cart, $index, 1);
            $_SESSION['cart'] = $cart;
        }
    }
    elseif ($action === 'update_quantity') {
        $index = $_POST['index'] ?? -1;
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if (isset($cart[$index]) && $quantity > 0) {
            if ($cart[$index]['type'] === 'product') {
                $stmt = $pdo->prepare("SELECT stock_quantity FROM products WHERE id = ?");
                $stmt->execute([$cart[$index]['id']]);
                $product = $stmt->fetch();
                
                if ($quantity > $product['stock_quantity']) {
                    $errors[] = "Insufficient stock. Available: {$product['stock_quantity']}";
                } else {
                    $cart[$index]['quantity'] = $quantity;
                    $cart[$index]['total'] = $cart[$index]['price'] * $quantity;
                    $_SESSION['cart'] = $cart;
                }
            } else {
                $cart[$index]['quantity'] = $quantity;
                $cart[$index]['total'] = $cart[$index]['price'] * $quantity;
                $_SESSION['cart'] = $cart;
            }
        }
    }
    elseif ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
        $cart = [];
    }
    elseif ($action === 'create_sale') {
        // CSRF protection
        if (!verify_csrf()) {
            $errors[] = "CSRF token validation failed";
        } else {
            $customerId = $_POST['customer_id'] ?? 0;
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerPhone = trim($_POST['customer_phone'] ?? '');
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');
            $discountType = $_POST['discount_type'] ?? 'none';
            $discountValue = floatval($_POST['discount_value'] ?? 0);
            
            // Validation
            if (empty($cart)) {
                $errors[] = "Cart is empty. Add items to create a sale.";
            }
            
            if (empty($paymentMethod)) {
                $errors[] = "Payment method is required";
            }
            
            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;
            
            foreach ($cart as $item) {
                $subtotal += $item['total'];
                $taxAmount += ($item['total'] * $item['tax_rate'] / 100);
            }
            
            // Calculate discount
            $discountAmount = 0;
            if ($discountType === 'percentage' && $discountValue > 0) {
                $discountAmount = ($subtotal * $discountValue / 100);
            } elseif ($discountType === 'fixed' && $discountValue > 0) {
                $discountAmount = $discountValue;
            }
            
            $totalAmount = $subtotal + $taxAmount - $discountAmount;
            
            // If no errors, create sale
            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    
                    // Get customer info if selected
                    if ($customerId > 0) {
                        $stmt = $pdo->prepare("SELECT full_name, phone FROM customers WHERE id = ?");
                        $stmt->execute([$customerId]);
                        $customer = $stmt->fetch();
                        if ($customer) {
                            $customerName = $customer['full_name'];
                            $customerPhone = $customer['phone'];
                        }
                    }
                    
                    // Create sale record
                    $stmt = $pdo->prepare("
                        INSERT INTO sales (
                            invoice_no, customer_id, customer_name, customer_phone,
                            user_id, subtotal, discount_type, discount_value, discount_amount,
                            tax_amount, total_amount, payment_method, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $invoiceNo,
                        $customerId > 0 ? $customerId : null,
                        $customerName,
                        $customerPhone,
                        $_SESSION['user_id'],
                        $subtotal,
                        $discountType,
                        $discountValue,
                        $discountAmount,
                        $taxAmount,
                        $totalAmount,
                        $paymentMethod,
                        $notes
                    ]);
                    
                    $saleId = $pdo->lastInsertId();
                    
                    // Add sale items and update stock
                    foreach ($cart as $item) {
                        if ($item['type'] === 'product') {
                            // Get product cost for profit calculation
                            $stmt = $pdo->prepare("SELECT cost_price FROM products WHERE id = ?");
                            $stmt->execute([$item['id']]);
                            $product = $stmt->fetch();
                            $costPrice = $product['cost_price'];
                            $profit = ($item['price'] - $costPrice) * $item['quantity'];
                            
                            // Add sale item
                            $stmt = $pdo->prepare("
                                INSERT INTO sale_items (
                                    sale_id, product_id, quantity, unit_price, total_price,
                                    cost_price, profit, product_name, product_sku
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $saleId,
                                $item['id'],
                                $item['quantity'],
                                $item['price'],
                                $item['total'],
                                $costPrice,
                                $profit,
                                $item['name'],
                                $item['sku']
                            ]);
                            
                            // Update product stock
                            $stmt = $pdo->prepare("
                                UPDATE products 
                                SET stock_quantity = stock_quantity - ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $stmt->execute([$item['quantity'], $item['id']]);
                            
                            // Record stock movement
                            $stmt = $pdo->prepare("
                                INSERT INTO stock_movements (
                                    product_id, movement_type, quantity_change,
                                    previous_quantity, new_quantity,
                                    reference_type, reference_id, reference_no,
                                    reason, user_id
                                ) VALUES (?, ?, ?, 
                                    (SELECT stock_quantity + ? FROM products WHERE id = ?),
                                    (SELECT stock_quantity FROM products WHERE id = ?),
                                    ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $item['id'],
                                'sale',
                                -$item['quantity'],
                                $item['quantity'],
                                $item['id'],
                                $item['id'],
                                'sale',
                                $saleId,
                                $invoiceNo,
                                'Sale: ' . $invoiceNo,
                                $_SESSION['user_id']
                            ]);
                            
                        } elseif ($item['type'] === 'service') {
                            // Add service item
                            $stmt = $pdo->prepare("
                                INSERT INTO service_items (
                                    sale_id, service_id, quantity, unit_price, total_price,
                                    status
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $saleId,
                                $item['id'],
                                $item['quantity'],
                                $item['price'],
                                $item['total'],
                                'pending'
                            ]);
                        }
                    }
                    
                    // Update customer stats if registered customer
                    if ($customerId > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE customers 
                            SET total_spent = total_spent + ?,
                                last_visit = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$totalAmount, $customerId]);
                    }
                    
                    $pdo->commit();
                    
                    // Log activity
                    logActivity($_SESSION['user_id'], 'create_sale', 
                        "Created sale: {$invoiceNo} for {$totalAmount}");
                    
                    // Clear cart
                    $_SESSION['cart'] = [];
                    
                    $success = true;
                    $successData = [
                        'invoice_no' => $invoiceNo,
                        'total_amount' => $totalAmount,
                        'sale_id' => $saleId
                    ];
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "Failed to create sale: " . $e->getMessage();
                }
            }
        }
    }
}

// Calculate cart totals
foreach ($cart as $item) {
    $subtotal += $item['total'];
    $taxAmount += ($item['total'] * $item['tax_rate'] / 100);
}
$totalAmount = $subtotal + $taxAmount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Sale - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3a0ca3;
            --success: #06d6a0;
            --warning: #ffd166;
            --danger: #ef476f;
        }
        
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sale-card {
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: none;
            margin-bottom: 20px;
        }
        
        .sale-header {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px;
        }
        
        .cart-item {
            border-bottom: 1px solid #e9ecef;
            padding: 15px 0;
            transition: background 0.2s;
        }
        
        .cart-item:hover {
            background: #f8f9fa;
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .quantity-input {
            width: 70px;
            text-align: center;
        }
        
        .btn-success {
            background: linear-gradient(45deg, var(--success), #04a777);
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(6, 214, 160, 0.3);
        }
        
        .total-box {
            background: linear-gradient(45deg, #4361ee, #3a0ca3);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .invoice-badge {
            background: white;
            color: var(--primary);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1.2rem;
            border: 2px dashed var(--primary);
        }
        
        .product-search {
            position: relative;
        }
        
        .product-search .select2 {
            width: 100% !important;
        }
        
        .stock-badge {
            font-size: 0.8rem;
            padding: 3px 8px;
        }
        
        .cart-actions {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 15px;
            border-top: 2px solid #e9ecef;
            box-shadow: 0 -5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include __DIR__ . '/../../layout/header.php'; ?>
    <?php include __DIR__ . '/../../layout/sidebar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-10 offset-lg-2">
                <div class="container py-4">
                    <!-- Page Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 mb-2"><i class="bi bi-cart-plus"></i> Create New Sale</h1>
                            <p class="text-muted">Create invoices for products and services</p>
                        </div>
                        <div class="invoice-badge">
                            <i class="bi bi-receipt"></i> Invoice: <?php echo $invoiceNo; ?>
                        </div>
                    </div>

                    <!-- Success Modal -->
                    <?php if ($success): ?>
                    <div class="modal fade show" id="successModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header bg-success text-white">
                                    <h5 class="modal-title"><i class="bi bi-check-circle"></i> Sale Created Successfully!</h5>
                                </div>
                                <div class="modal-body text-center">
                                    <div class="display-1 text-success mb-3">
                                        <i class="bi bi-check2-circle"></i>
                                    </div>
                                    <h4>Invoice: <?php echo $invoiceNo; ?></h4>
                                    <h3 class="text-primary my-3">$<?php echo number_format($totalAmount, 2); ?></h3>
                                    <p class="text-muted">Sale has been recorded successfully.</p>
                                </div>
                                <div class="modal-footer justify-content-center">
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/invoice.php?id=<?php echo $successData['sale_id']; ?>" 
                                       class="btn btn-primary" target="_blank">
                                        <i class="bi bi-printer"></i> Print Invoice
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/create.php" 
                                       class="btn btn-success">
                                        <i class="bi bi-plus-circle"></i> New Sale
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/modules/sales/list.php" 
                                       class="btn btn-outline-secondary">
                                        <i class="bi bi-list"></i> Sales List
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-backdrop fade show"></div>
                    <?php endif; ?>

                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <h5><i class="bi bi-exclamation-triangle"></i> Please fix the following errors:</h5>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Left Column - Products & Services -->
                        <div class="col-lg-8">
                            <!-- Products Section -->
                            <div class="sale-card card mb-4">
                                <div class="sale-header card-header">
                                    <h4 class="mb-0"><i class="bi bi-box"></i> Add Products</h4>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row g-3">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="add_product">
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Select Product</label>
                                            <select class="form-select" name="product_id" required>
                                                <option value="">-- Choose Product --</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['id']; ?>" 
                                                        data-price="<?php echo $product['selling_price']; ?>"
                                                        data-stock="<?php echo $product['stock_quantity']; ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?> 
                                                    (SKU: <?php echo $product['sku']; ?>)
                                                    - $<?php echo number_format($product['selling_price'], 2); ?>
                                                    <small class="text-muted">(Stock: <?php echo $product['stock_quantity']; ?>)</small>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label class="form-label">Quantity</label>
                                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                                        </div>
                                        
                                        <div class="col-md-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-cart-plus"></i> Add to Cart
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Services Section -->
                            <div class="sale-card card mb-4">
                                <div class="sale-header card-header">
                                    <h4 class="mb-0"><i class="bi bi-tools"></i> Add Services</h4>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row g-3">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="add_service">
                                        
                                        <div class="col-md-6">
                                            <label class="form-label">Select Service</label>
                                            <select class="form-select" name="service_id" required>
                                                <option value="">-- Choose Service --</option>
                                                <?php foreach ($services as $service): ?>
                                                <option value="<?php echo $service['id']; ?>">
                                                    <?php echo htmlspecialchars($service['name']); ?> 
                                                    (Code: <?php echo $service['service_code']; ?>)
                                                    - $<?php echo number_format($service['base_price'], 2); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label class="form-label">Quantity</label>
                                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                                        </div>
                                        
                                        <div class="col-md-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="bi bi-tools"></i> Add Service
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Cart Items -->
                            <div class="sale-card card">
                                <div class="sale-header card-header d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0"><i class="bi bi-cart"></i> Shopping Cart</h4>
                                    <?php if (!empty($cart)): ?>
                                    <form method="POST">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="clear_cart">
                                        <button type="submit" class="btn btn-sm btn-outline-light" 
                                                onclick="return confirm('Clear all items from cart?')">
                                            <i class="bi bi-trash"></i> Clear Cart
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="card-body">
                                    <?php if (empty($cart)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-cart display-1 text-muted"></i>
                                        <h4 class="mt-3">Your cart is empty</h4>
                                        <p class="text-muted">Add products or services to start a sale</p>
                                    </div>
                                    <?php else: ?>
                                    
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Price</th>
                                                    <th>Qty</th>
                                                    <th>Tax</th>
                                                    <th>Total</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cart as $index => $item): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo $item['type'] === 'product' ? 'Product' : 'Service'; ?>
                                                            | <?php echo $item['sku']; ?>
                                                        </div>
                                                    </td>
                                                    <td>$<?php echo number_format($item['price'], 2); ?></td>
                                                    <td>
                                                        <form method="POST" class="d-inline">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="update_quantity">
                                                            <input type="hidden" name="index" value="<?php echo $index; ?>">
                                                            <input type="number" name="quantity" 
                                                                   value="<?php echo $item['quantity']; ?>" 
                                                                   min="1" class="form-control form-control-sm quantity-input"
                                                                   onchange="this.form.submit()">
                                                        </form>
                                                    </td>
                                                    <td><?php echo $item['tax_rate']; ?>%</td>
                                                    <td><strong>$<?php echo number_format($item['total'], 2); ?></strong></td>
                                                    <td>
                                                        <form method="POST" class="d-inline">
                                                            <?php csrf_field(); ?>
                                                            <input type="hidden" name="action" value="remove_item">
                                                            <input type="hidden" name="index" value="<?php echo $index; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="return confirm('Remove this item?')">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Cart Summary & Checkout -->
                        <div class="col-lg-4">
                            <!-- Cart Summary -->
                            <div class="sale-card card mb-4">
                                <div class="sale-header card-header">
                                    <h4 class="mb-0"><i class="bi bi-receipt"></i> Order Summary</h4>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Subtotal:</span>
                                            <span>$<?php echo number_format($subtotal, 2); ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Tax:</span>
                                            <span>$<?php echo number_format($taxAmount, 2); ?></span>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between h4 mb-0">
                                            <strong>Total:</strong>
                                            <strong class="text-primary">$<?php echo number_format($totalAmount, 2); ?></strong>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <div class="mb-3">
                                            <i class="bi bi-cart-check display-4 text-success"></i>
                                        </div>
                                        <p class="text-muted">
                                            <?php echo count($cart); ?> item(s) in cart
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Checkout Form -->
                            <div class="sale-card card">
                                <div class="sale-header card-header">
                                    <h4 class="mb-0"><i class="bi bi-credit-card"></i> Checkout</h4>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="checkoutForm">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="create_sale">
                                        
                                        <!-- Customer Selection -->
                                        <div class="mb-3">
                                            <label class="form-label">Customer</label>
                                            <select class="form-select" name="customer_id" id="customerSelect">
                                                <option value="0">Walk-in Customer</option>
                                                <?php foreach ($customers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>">
                                                    <?php echo htmlspecialchars($customer['full_name']); ?> 
                                                    (<?php echo $customer['phone']; ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Select or leave as "Walk-in Customer"</small>
                                        </div>
                                        
                                        <!-- Customer Details (for new customers) -->
                                        <div id="newCustomerFields" style="display: none;">
                                            <div class="mb-3">
                                                <label class="form-label">Customer Name</label>
                                                <input type="text" class="form-control" name="customer_name" 
                                                       placeholder="Enter customer name">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number</label>
                                                <input type="text" class="form-control" name="customer_phone" 
                                                       placeholder="Enter phone number">
                                            </div>
                                        </div>
                                        
                                        <!-- Discount -->
                                        <div class="mb-3">
                                            <label class="form-label">Discount</label>
                                            <div class="row">
                                                <div class="col-6">
                                                    <select class="form-select" name="discount_type" id="discountType">
                                                        <option value="none">No Discount</option>
                                                        <option value="percentage">Percentage %</option>
                                                        <option value="fixed">Fixed Amount</option>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <input type="number" class="form-control" name="discount_value" 
                                                           id="discountValue" value="0" min="0" step="0.01" 
                                                           placeholder="Amount" disabled>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Payment Method -->
                                        <div class="mb-3">
                                            <label class="form-label">Payment Method</label>
                                            <select class="form-select" name="payment_method" required>
                                                <?php foreach ($paymentMethods as $method): ?>
                                                <option value="<?php echo $method['code']; ?>">
                                                    <?php echo htmlspecialchars($method['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Notes -->
                                        <div class="mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" name="notes" rows="3" 
                                                      placeholder="Additional notes..."></textarea>
                                        </div>
                                        
                                        <!-- Checkout Button -->
                                        <button type="submit" class="btn btn-success w-100 btn-lg" 
                                                id="checkoutBtn" <?php echo empty($cart) ? 'disabled' : ''; ?>>
                                            <i class="bi bi-check-circle"></i> Complete Sale
                                        </button>
                                        
                                        <div class="text-center mt-3">
                                            <small class="text-muted">
                                                <i class="bi bi-shield-check"></i> Secure Transaction
                                            </small>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('#customerSelect').select2({
                placeholder: 'Select customer or walk-in',
                width: '100%'
            });
            
            // Show/hide customer fields based on selection
            $('#customerSelect').on('change', function() {
                if ($(this).val() === '0') {
                    $('#newCustomerFields').slideDown();
                    $('input[name="customer_name"], input[name="customer_phone"]').prop('required', true);
                } else {
                    $('#newCustomerFields').slideUp();
                    $('input[name="customer_name"], input[name="customer_phone"]').prop('required', false);
                }
            });
            
            // Enable/disable discount value based on type
            $('#discountType').on('change', function() {
                const discountValue = $('#discountValue');
                if ($(this).val() === 'none') {
                    discountValue.prop('disabled', true).val(0);
                } else {
                    discountValue.prop('disabled', false).focus();
                }
            });
            
            // Update checkout button based on cart
            function updateCheckoutButton() {
                const cartEmpty = <?php echo empty($cart) ? 'true' : 'false'; ?>;
                $('#checkoutBtn').prop('disabled', cartEmpty);
                
                if (cartEmpty) {
                    $('#checkoutBtn').html('<i class="bi bi-x-circle"></i> Cart is Empty');
                } else {
                    $('#checkoutBtn').html('<i class="bi bi-check-circle"></i> Complete Sale');
                }
            }
            
            // Auto-calculate on discount change
            $('#discountValue').on('input', function() {
                const discountType = $('#discountType').val();
                const discountValue = parseFloat($(this).val()) || 0;
                const subtotal = <?php echo $subtotal; ?>;
                
                if (discountType === 'percentage' && discountValue > 100) {
                    $(this).val(100);
                    alert('Discount percentage cannot exceed 100%');
                }
            });
            
            // Form validation
            $('#checkoutForm').on('submit', function(e) {
                const cartEmpty = <?php echo empty($cart) ? 'true' : 'false'; ?>;
                
                if (cartEmpty) {
                    e.preventDefault();
                    alert('Please add items to cart before checkout.');
                    return false;
                }
                
                const discountType = $('#discountType').val();
                const discountValue = parseFloat($('#discountValue').val()) || 0;
                
                if (discountType === 'percentage' && discountValue > 100) {
                    e.preventDefault();
                    alert('Discount percentage cannot exceed 100%');
                    return false;
                }
                
                if (!confirm('Finalize this sale?\n\nTotal: $<?php echo number_format($totalAmount, 2); ?>')) {
                    e.preventDefault();
                    return false;
                }
                
                // Disable button to prevent double submission
                $('#checkoutBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Processing...');
            });
            
            // Auto-focus product search
            $('select[name="product_id"]').focus();
            
            // Initialize
            updateCheckoutButton();
        });
    </script>
</body>
</html>
