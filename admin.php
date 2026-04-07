<?php
session_start();
$admin_pass = 'blacq123'; // CHANGE THIS TO YOUR OWN PASSWORD

if (!isset($_SESSION['admin_logged_in'])) {
    if (isset($_POST['password']) && $_POST['password'] === $admin_pass) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        echo '<!DOCTYPE html><html><head><title>Blacq Admin Login</title></head><body style="background:#0a0a0a;color:#e0e0e0;text-align:center;padding:150px;font-family:Arial;">
        <h2 style="color:#d4af37;">Blacq Fashion Admin</h2>
        <form method="post"><input type="password" name="password" placeholder="Password" style="padding:14px;width:340px;margin:20px;" required><br>
        <button type="submit" style="padding:14px 50px;background:#d4af37;color:#000;border:none;border-radius:50px;cursor:pointer;">Login</button></form></body></html>';
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=insta-biz;charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Add product
if (isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $gender = $_POST['gender'];
    $category = $_POST['category'];
    $price = $_POST['price'];
    $description = $_POST['description'];
    $stock = $_POST['stock'];

    $image_path = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $dir = 'img/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $image_path = $dir . time() . '_' . basename($_FILES['image']['name']);
        move_uploaded_file($_FILES['image']['tmp_name'], $image_path);
    }

    $stmt = $pdo->prepare("INSERT INTO products (name, gender, category, price, description, image_path, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $gender, $category, $price, $description, $image_path, $stock]);
}

// Delete product
if (isset($_POST['delete_product'])) {
    $id = $_POST['product_id'];
    // Get image path
    $stmt = $pdo->prepare("SELECT image_path FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['image_path'] && file_exists($row['image_path'])) {
        unlink($row['image_path']);
    }
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
}

// Update status + stock + AUTO WhatsApp to CUSTOMER when Delivered
if (isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['status'];

    if ($new_status === 'Confirmed') {
        // Check if stock is sufficient
        $stmt = $pdo->prepare("SELECT oi.quantity, p.stock FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $can_confirm = true;
        foreach ($items as $item) {
            if ($item['stock'] < $item['quantity']) {
                $can_confirm = false;
                break;
            }
        }
        if ($can_confirm) {
            $stmt = $pdo->prepare("UPDATE products p JOIN order_items oi ON p.id = oi.product_id SET p.stock = p.stock - oi.quantity WHERE oi.order_id = ?");
            $stmt->execute([$order_id]);
        } else {
            // Optionally, show error, but for now, skip stock update
            echo "<script>alert('Insufficient stock for some items in order #$order_id');</script>";
        }
    }

    if ($new_status === 'Delivered') {
        // Open Instagram page for admin to send delivery notification
        $ig_url = "https://instagram.com/blacq_clout";
        echo "<script>window.open('" . $ig_url . "', '_blank');</script>";
    }

    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $order_id]);
}

// Quick Confirm All Pending
if (isset($_POST['confirm_all'])) {
    $stmt = $pdo->prepare("UPDATE orders SET status = 'Confirmed' WHERE status = 'Pending'");
    $stmt->execute();
}

// Update stock
if (isset($_POST['update_stock'])) {
    $product_id = $_POST['product_id'];
    $new_stock = (int)$_POST['new_stock'];
    $stmt = $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?");
    $stmt->execute([$new_stock, $product_id]);
}

// Sales Summary, Low Stock, Best Sellers, Orders (unchanged)
$today = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM orders WHERE DATE(order_date) = CURDATE()")->fetch();
$week = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch();
$allTime = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount),0) as total FROM orders")->fetch();

$bestSellers = $pdo->query("SELECT p.name, SUM(oi.quantity) as sold FROM order_items oi JOIN products p ON oi.product_id = p.id GROUP BY p.id ORDER BY sold DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$lowStock = $pdo->query("SELECT * FROM products WHERE stock <= 5 ORDER BY stock ASC")->fetchAll(PDO::FETCH_ASSOC);

$orders = $pdo->query("SELECT o.*, GROUP_CONCAT(p.name SEPARATOR ', ') as products FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id LEFT JOIN products p ON oi.product_id = p.id GROUP BY o.id ORDER BY o.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$allProducts = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacq Admin Panel</title>
    <style>
        body { background:#0a0a0a; color:#e0e0e0; font-family:Arial; padding:30px; }
        h1, h2 { text-align:center; color:#d4af37; letter-spacing:3px; }
        .logout { float:right; color:#d4af37; text-decoration:none; }
        form { max-width:620px; margin:40px auto; background:#111; padding:35px; border-radius:20px; }
        input, select, textarea { width:100%; padding:14px; margin:12px 0; background:#222; color:white; border:1px solid #444; border-radius:12px; }
        button { background:#d4af37; color:#000; padding:14px; border:none; border-radius:50px; font-weight:bold; cursor:pointer; width:100%; margin-top:15px; }
        .delete-btn { background:#ff4444; }
        table { width:100%; border-collapse:collapse; margin:30px 0; background:#111; border-radius:16px; overflow:hidden; }
        th, td { padding:16px; text-align:left; border-bottom:1px solid #333; }
        th { background:#1a1a1a; color:#d4af37; }
        .summary { display:flex; gap:20px; flex-wrap:wrap; margin:30px 0; }
        .summary-card { background:#111; padding:25px; border-radius:16px; flex:1; min-width:280px; text-align:center; }
        .low-stock { color:#ff5722; font-weight:bold; }
    </style>
</head>
<body>

<a href="admin.php?logout=1" class="logout">Logout</a>
<h1>Blacq Fashion Admin</h1>

<h2>Add New Product</h2>
<form method="post" enctype="multipart/form-data">
    <input type="text" name="name" placeholder="Product Name" required>
    <select name="gender" required>
        <option value="">Select Gender</option>
        <option value="male">Male</option>
        <option value="female">Female</option>
    </select>
    <select name="category" required>
        <option value="">Select Category</option>
        <option value="bracelets">Bracelets</option>
        <option value="clothing">Clothing</option>
    </select>
    <input type="number" name="price" placeholder="Price (KSh)" step="0.01" required>
    <textarea name="description" placeholder="Short Description" rows="3"></textarea>
    <input type="file" name="image" accept="image/*" required>
    <input type="number" name="stock" placeholder="Stock Quantity" value="10" required>
    <button type="submit" name="add_product">Add Product</button>
</form>

<div class="summary">
    <div class="summary-card">
        <h3>Today</h3>
        <p>Orders: <?php echo $today['count']; ?></p>
        <p>KSh <?php echo number_format($today['total'], 0); ?></p>
    </div>
    <div class="summary-card">
        <h3>This Week</h3>
        <p>Orders: <?php echo $week['count']; ?></p>
        <p>KSh <?php echo number_format($week['total'], 0); ?></p>
    </div>
    <div class="summary-card">
        <h3>All Time</h3>
        <p>Orders: <?php echo $allTime['count']; ?></p>
        <p>KSh <?php echo number_format($allTime['total'], 0); ?></p>
    </div>
</div>

<h2>Low Stock Alert (≤ 5)</h2>
<?php if (count($lowStock) > 0): ?>
    <ul class="low-stock">
        <?php foreach ($lowStock as $p): ?>
            <li><?php echo htmlspecialchars($p['name']); ?> — only <?php echo $p['stock']; ?> left</li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p style="color:#4caf50;">All products have good stock levels.</p>
<?php endif; ?>

<h2>Best Sellers</h2>
<ul>
    <?php foreach ($bestSellers as $b): ?>
        <li><?php echo htmlspecialchars($b['name']); ?> — <?php echo $b['sold']; ?> sold</li>
    <?php endforeach; ?>
</ul>

<h2>All Products</h2>
<table>
    <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Price</th>
        <th>Stock</th>
        <th>Add Stock</th>
        <th>Action</th>
    </tr>
    <?php foreach ($allProducts as $p): ?>
    <tr>
        <td><?php echo $p['id']; ?></td>
        <td><?php echo htmlspecialchars($p['name']); ?></td>
        <td>KSh <?php echo number_format($p['price'], 0); ?></td>
        <td><?php echo $p['stock']; ?></td>
        <td>
            <form method="post" style="display:flex; gap:5px;">
                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                <input type="number" name="new_stock" value="<?php echo $p['stock']; ?>" min="0" style="width:60px; padding:5px;">
                <button type="submit" name="update_stock" style="padding:5px 10px;">Update</button>
            </form>
        </td>
        <td>
            <form method="post" onsubmit="return confirm('Delete this product?')">
                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                <button type="submit" name="delete_product" class="delete-btn">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<h2>Orders Management</h2>
<form method="post" style="margin-bottom:20px;">
    <button type="submit" name="confirm_all" style="background:#4caf50;">Quick Confirm All Pending Orders</button>
</form>

<table>
    <tr>
        <th>Order ID</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Phone</th>
        <th>Instagram</th>
        <th>Location</th>
        <th>Products</th>
        <th>Total</th>
        <th>M-Pesa</th>
        <th>Status</th>
        <th>Action</th>
    </tr>
    <?php foreach ($orders as $o): ?>
    <tr>
        <td>#<?php echo $o['id']; ?></td>
        <td><?php echo $o['order_date']; ?></td>
        <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
        <td><?php echo htmlspecialchars($o['phone']); ?></td>
        <td><?php echo htmlspecialchars($o['instagram'] ?? '—'); ?></td>
        <td><?php echo htmlspecialchars($o['location']); ?></td>
        <td><?php echo htmlspecialchars($o['products'] ?? '—'); ?></td>
        <td>KSh <?php echo number_format($o['total_amount'], 0); ?></td>
        <td><?php echo htmlspecialchars($o['mpesa_code'] ?? '—'); ?></td>
        <td><?php echo $o['status']; ?></td>
        <td>
            <form method="post">
                <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                <select name="status">
                    <option value="Pending" <?php echo $o['status']=='Pending'?'selected':''; ?>>Pending</option>
                    <option value="Confirmed" <?php echo $o['status']=='Confirmed'?'selected':''; ?>>Confirmed</option>
                    <option value="Delivered" <?php echo $o['status']=='Delivered'?'selected':''; ?>>Delivered</option>
                </select>
                <button type="submit" name="update_status">Update</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>