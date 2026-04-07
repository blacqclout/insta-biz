<?php
session_start();
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$pdo = new PDO("mysql:host=localhost;dbname=insta-biz;charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure orders has an instagram column
$pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS instagram VARCHAR(255) DEFAULT NULL AFTER phone");

// Ensure users table exists for auth
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    instagram VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Customer auth actions
if (isset($_POST['signup'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $instagram = trim($_POST['instagram'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name && $phone && $instagram && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, phone, instagram, email, password) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $instagram, $email, $hash]);
        $_SESSION['customer_logged_in'] = true;
        $_SESSION['customer_name'] = $name;
        $_SESSION['customer_phone'] = $phone;
        $_SESSION['customer_instagram'] = $instagram;
        echo "<script>alert('Signup successful. Welcome, $name!'); window.location.href=window.location.href;</script>";
        exit;
    } else {
        echo "<script>alert('Please fill in Name, Phone and Password for signup.');</script>";
    }
}

if (isset($_POST['login'])) {
    $instagram = trim($_POST['instagram'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($instagram && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE instagram = ? LIMIT 1");
        $stmt->execute([$instagram]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['customer_logged_in'] = true;
            $_SESSION['customer_name'] = $user['name'];
            $_SESSION['customer_phone'] = $user['phone'];
            $_SESSION['customer_instagram'] = $user['instagram'];
            echo "<script>alert('Login successful. Welcome back, {$user['name']}!'); window.location.href=window.location.href;</script>";
            exit;
        } else {
            echo "<script>alert('Invalid Instagram handle or password.');</script>";
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Auth gate
if (!isset($_SESSION['customer_logged_in']) || !$_SESSION['customer_logged_in']) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Blacq Sign In / Sign Up</title><style>body{background:#0a0a0a;color:#e0e0e0;font-family:Arial;text-align:center;padding:50px;} .box{background:#111;padding:20px;border:1px solid #333;border-radius:16px;display:inline-block;margin:10px;min-width:280px;} input{width:90%;padding:12px;margin:8px 0;border:1px solid #444;background:#222;color:#fff;border-radius:8px;} button{padding:12px 20px;border:none;border-radius:35px;background:#d4af37;color:#000;font-weight:700;cursor:pointer;} a{color:#d4af37;}</style></head><body><h1>Blacq Fashion</h1><p>Sign up or login to continue shopping.</p><div class="box"><h2>Login</h2><form method="post"><input name="instagram" placeholder="Instagram (@username)" required><input type="password" name="password" placeholder="Password" required><button name="login" type="submit">Login</button></form></div><div class="box"><h2>Sign Up</h2><form method="post"><input name="name" placeholder="Name" required><input name="phone" placeholder="Phone (+254...)" required><input name="instagram" placeholder="Instagram (@username)" required><input name="email" placeholder="Email (optional)"><input type="password" name="password" placeholder="Password" required><button name="signup" type="submit">Sign Up</button></form></div><p style="margin-top:16px;">Already have account? just login above.</p></body></html>';
    exit;
}

$gender   = $_GET['gender'] ?? 'all';
$category = $_GET['category'] ?? 'all';
$search   = $_GET['search'] ?? '';

// Fetch products
$sql = "SELECT * FROM products WHERE 1=1";
$params = [];
if ($gender !== 'all') { $sql .= " AND gender = :gender"; $params[':gender'] = $gender; }
if ($category !== 'all') { $sql .= " AND category = :category"; $params[':category'] = $category; }
if ($search) { $sql .= " AND name LIKE :search"; $params[':search'] = "%$search%"; }

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add to cart
if (isset($_POST['add_to_cart'])) {
    $id = (int)$_POST['product_id'];
    $found = false;
    foreach ($_SESSION['cart'] as &$item) {
        if ($item['id'] == $id) { $item['qty']++; $found = true; break; }
    }
    if (!$found) {
        foreach ($products as $p) {
            if ($p['id'] == $id) {
                $_SESSION['cart'][] = ['id' => $p['id'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => 1];
                break;
            }
        }
    }
    echo "<script>alert('Added to cart!');</script>";
}

// Checkout
if (isset($_POST['checkout'])) {
    $total = 0;
    foreach ($_SESSION['cart'] as $item) $total += $item['price'] * $item['qty'];

    $customer_name = $_POST['customer_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $instagram = $_POST['instagram'] ?? '';
    $location = $_POST['location'] ?? '';
    $mpesa_code = $_POST['mpesa_code'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if ($customer_name && $phone && $location && count($_SESSION['cart']) > 0) {
        $stmt = $pdo->prepare("INSERT INTO orders (customer_name, phone, instagram, location, mpesa_code, total_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_name, $phone, $instagram, $location, $mpesa_code, $total, $notes]);
        $order_id = $pdo->lastInsertId();

        foreach ($_SESSION['cart'] as $item) {
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$order_id, $item['id'], $item['qty']]);
        }

        echo "<script>alert('Order #$order_id placed successfully! Customer details sent to admin for confirmation.');</script>";
        $_SESSION['cart'] = [];
    }
}

// Handle individual order
if (isset($_POST['submit_order'])) {
    $product_id = (int)$_POST['product_id'];
    $total = (float)$_POST['total'];
    $customer_name = $_POST['customer_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $instagram = $_POST['instagram'] ?? '';
    $location = $_POST['location'] ?? '';
    $mpesa_code = $_POST['mpesa_code'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if ($customer_name && $phone && $location) {
        // Fetch product name
        $stmt = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $product_name = $product['name'] ?? 'your Blacq purchase';
        
        $stmt = $pdo->prepare("INSERT INTO orders (customer_name, phone, instagram, location, mpesa_code, total_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$customer_name, $phone, $instagram, $location, $mpesa_code, $total, $notes]);
        $order_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$order_id, $product_id, 1]);

        echo "<script>alert('Order #$order_id placed successfully! Customer details sent to admin for confirmation.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacq Fashion</title>
    <style>
        :root { --bg:#0a0a0a; --card:#111; --gold:#d4af37; --text:#e0e0e0; }
        body { font-family:'Segoe UI',sans-serif; background:var(--bg); color:var(--text); margin:0; }
        header { background:linear-gradient(#000,#1a1a1a); padding:80px 20px; text-align:center; }
        h1 { font-size:4rem; color:var(--gold); letter-spacing:8px; font-weight:300; }
        .tagline { color:#aaa; font-size:1.4rem; letter-spacing:4px; }

        .top-bar { padding:15px 30px; background:#111; display:flex; align-items:center; justify-content:center; position:sticky; top:0; z-index:100; gap:30px; }
        .search input { width:500px; padding:14px 24px; border-radius:50px; border:none; background:#222; color:white; font-size:1.05rem; }

        .filters { padding:35px; background:#0d0d0d; text-align:center; }
        .filter-btn { padding:14px 32px; margin:8px; background:transparent; color:#ccc; border:1px solid #444; border-radius:50px; cursor:pointer; }
        .filter-btn.active, .filter-btn:hover { background:var(--gold); color:#000; }

        .product-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(290px,1fr)); gap:40px; padding:60px 30px; max-width:1600px; margin:auto; }
        .product-card { background:var(--card); border-radius:26px; overflow:hidden; position:relative; box-shadow:0 20px 55px rgba(0,0,0,0.85); }
        .product-card:hover { transform:translateY(-12px); }
        .product-card img { width:100%; height:350px; object-fit:cover; }

        .actions { position:absolute; top:18px; right:18px; display:flex; gap:10px; }
        .action-btn { width:44px; height:44px; background:rgba(0,0,0,0.7); border:none; border-radius:50%; color:white; font-size:1.4rem; cursor:pointer; }
        .action-btn:hover { background:var(--gold); color:#000; transform:scale(1.1); }
        .liked { color:#ff4d4d !important; }

        .product-info { padding:28px; text-align:center; }
        .price { font-size:2.2rem; color:var(--gold); font-weight:600; }

        /* Elegant bouncy toggle form */
        .order-section { margin-top:25px; background:#1a1a1a; border-radius:24px; padding:0 28px; max-height:0; overflow:hidden; transition:max-height 0.7s cubic-bezier(0.4,0,0.2,1); }
        .order-section.show { max-height:520px; padding:28px; }
        .order-form input, .order-form textarea { width:100%; padding:16px; margin:12px 0; background:#222; color:white; border:1px solid #555; border-radius:16px; font-size:1.05rem; }
        .order-btn { 
            background:linear-gradient(#d4af37,#ffeb3b); color:#000; border:none; 
            padding:18px; border-radius:50px; font-weight:bold; width:100%; 
            margin-top:20px; cursor:pointer; font-size:1.15rem; 
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55); 
        }
        .order-btn:hover { transform:scale(1.08); box-shadow:0 12px 30px rgba(212,175,55,0.4); }

        #cart-btn { position:fixed; top:25px; right:30px; background:var(--gold); color:#000; padding:14px 26px; border-radius:50px; cursor:pointer; font-weight:bold; z-index:200; }

        /* Elegant AI */
        #ai-assistant { 
            position:fixed; bottom:25px; right:110px; width:72px; height:72px;
            background:var(--gold); color:#000; border-radius:50%; 
            display:flex; align-items:center; justify-content:center; 
            font-size:36px; box-shadow:0 12px 45px rgba(212,175,55,0.6);
            cursor:pointer; z-index:1000; animation: wave 3s infinite;
        }
        @keyframes wave { 0%,100%{transform:rotate(0deg);} 25%{transform:rotate(15deg);} 75%{transform:rotate(-15deg);} }

        #ai-chat { 
            position:fixed; bottom:110px; right:25px; width:380px; height:520px;
            background:#0f0f0f; border-radius:28px; box-shadow:0 25px 80px rgba(0,0,0,0.95);
            display:none; flex-direction:column; overflow:hidden; z-index:999; 
        }
        #ai-header { background:linear-gradient(#1a1a1a,#111); padding:20px; text-align:center; color:var(--gold); font-weight:bold; }
        #ai-messages { flex:1; padding:22px; overflow-y:auto; background:#0a0a0a; }
        .ai-msg { margin:16px 0; padding:14px 20px; border-radius:22px; max-width:85%; line-height:1.5; }
        .ai-user { background:#333; align-self:flex-end; border-bottom-right-radius:6px; }
        .ai-bot { background:#1a1a1a; align-self:flex-start; border-bottom-left-radius:6px; border:1px solid #333; }
        #ai-input { padding:18px; background:#1a1a1a; display:flex; gap:12px; }
        #ai-input input { flex:1; padding:16px 22px; border-radius:30px; border:none; background:#222; color:white; }
        #ai-input button { background:var(--gold); color:#000; border:none; padding:16px 28px; border-radius:30px; font-weight:bold; cursor:pointer; }

        /* Social */
        .social { text-align:center; padding:50px 20px; background:#111; }
        .social a { color:#aaa; font-size:2rem; margin:0 18px; transition:0.4s; }
        .social a:hover { color:var(--gold); transform:scale(1.4); }
    </style>
</head>
<body>

<?php if (!empty($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in']): ?>
    <div style="background:#1a1a1a;color:#d4af37;padding:12px 20px;text-align:center;border-bottom:1px solid #333;">
        Welcome, <?php echo htmlspecialchars($_SESSION['customer_name'] ?? 'Customer'); ?>
        <?php if (!empty($_SESSION['customer_instagram'])): ?>
            (IG: <?php echo htmlspecialchars($_SESSION['customer_instagram']); ?>)
        <?php endif; ?>
        <a href="?logout=1" style="background:#d4af37;color:#000;padding:8px 14px;margin-left:16px;border-radius:20px;text-decoration:none;font-weight:bold;">Logout</a>
    </div>
<?php endif; ?>

<div class="top-bar">
    <h1 style="margin:0;font-size:2.2rem;">BLACQ</h1>
    <div class="search">
        <form method="GET">
            <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
        </form>
    </div>
    <div id="cart-btn" onclick="showCart()">🛒 Cart (<span id="cart-count"><?php echo count($_SESSION['cart']); ?></span>)</div>
</div>

<header>
    <h1>BLACQ</h1>
    <div class="tagline">LUXURY BRACELETS & CLOTHING</div>
</header>

<div class="filters">
    <a href="?gender=all&category=<?php echo htmlspecialchars($category); ?>" class="filter-btn <?php echo $gender==='all'?'active':''; ?>">ALL</a>
    <a href="?gender=male&category=<?php echo htmlspecialchars($category); ?>" class="filter-btn <?php echo $gender==='male'?'active':''; ?>">MALE</a>
    <a href="?gender=female&category=<?php echo htmlspecialchars($category); ?>" class="filter-btn <?php echo $gender==='female'?'active':''; ?>">FEMALE</a>
    <a href="?gender=<?php echo htmlspecialchars($gender); ?>&category=all" class="filter-btn <?php echo $category==='all'?'active':''; ?>">ALL CATEGORIES</a>
    <a href="?gender=<?php echo htmlspecialchars($gender); ?>&category=bracelets" class="filter-btn <?php echo $category==='bracelets'?'active':''; ?>">BRACELETS</a>
    <a href="?gender=<?php echo htmlspecialchars($gender); ?>&category=clothing" class="filter-btn <?php echo $category==='clothing'?'active':''; ?>">CLOTHING</a>
</div>

<div class="product-grid">
    <?php foreach ($products as $p): ?>
        <div class="product-card">
            <div class="actions">
                <button class="action-btn" onclick="toggleLike(this)">♡</button>
                <button class="action-btn" onclick="addToCart(<?php echo $p['id']; ?>)">🛒</button>
            </div>
            <img src="<?php echo htmlspecialchars($p['image_path'] ?? 'https://via.placeholder.com/400x340/111/555?text=BLACQ'); ?>" alt="">
            <div class="product-info">
                <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                <div class="price">KSh <?php echo number_format($p['price'], 0); ?></div>
                <div class="desc"><?php echo htmlspecialchars($p['description'] ?? 'Timeless elegance'); ?></div>
                <div class="stock">Stock: <?php echo $p['stock'] ?? 0; ?> left</div>

                <button onclick="toggleOrderForm(this)" class="order-btn">ORDER NOW</button>

                <div class="order-section">
                    <form method="post" class="order-form">
                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="total" value="<?php echo $p['price']; ?>">
                        <input type="text" name="customer_name" placeholder="Your Name" required>
                        <input type="tel" name="phone" placeholder="Phone (+254...)" required>
                        <input type="text" name="instagram" placeholder="Instagram Handle (@username)">
                        <input type="text" name="location" placeholder="Delivery Location" required>
                        <input type="text" name="mpesa_code" placeholder="M-Pesa Code">
                        <textarea name="notes" placeholder="Notes / Size / Color" rows="2"></textarea>
                        <button type="submit" name="submit_order" class="order-btn">SUBMIT ORDER</button>
                        <p style="text-align:center; margin-top:15px; font-size:14px; color:#888;">
                            After ordering, contact us on Instagram for confirmation: 
                            <a href="https://instagram.com/blacq_clout" target="_blank" style="color:#d4af37; text-decoration:none;">@blacq_clout</a>
                        </p>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Elegant Classy Cart Modal -->
<div id="cart-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:2000;padding:40px;overflow:auto;">
    <div style="background:#111;max-width:650px;margin:auto;border-radius:32px;padding:45px;box-shadow:0 30px 90px rgba(0,0,0,0.9);">
        <h2 style="color:var(--gold); text-align:center;">Your Cart</h2>
        <div id="cart-items" style="margin:30px 0; min-height:140px;"></div>
        <form method="post">
            <input type="text" name="customer_name" class="cart-input" placeholder="Your Name" required>
            <input type="tel" name="phone" class="cart-input" placeholder="Phone (+254...)" required>
            <input type="text" name="instagram" class="cart-input" placeholder="Instagram Handle (@username)">
            <input type="text" name="location" class="cart-input" placeholder="Delivery Location" required>
            <input type="text" name="mpesa_code" class="cart-input" placeholder="M-Pesa Code">
            <textarea name="notes" class="cart-input" placeholder="Notes" rows="3"></textarea>
            <button type="submit" name="checkout" class="bouncy-btn">COMPLETE ORDER</button>
            <p style="text-align:center; margin-top:15px; font-size:14px; color:#888;">
                After ordering, contact us on Instagram for confirmation: 
                <a href="https://instagram.com/blacq_clout" target="_blank" style="color:#d4af37; text-decoration:none;">@blacq_clout</a>
            </p>
        </form>
        <button onclick="document.getElementById('cart-modal').style.display='none'" style="margin-top:15px; background:#333; color:white; width:100%; padding:16px; border-radius:50px;">Close</button>
    </div>
</div>

<!-- Waving Robot AI -->
<div id="ai-assistant" onclick="toggleAI()">🤖</div>
<div id="ai-chat">
    <div id="ai-header">Blacq AI Assistant</div>
    <div id="ai-messages">
        <div class="ai-msg ai-bot">Hi! Ask me about products, sizes, delivery, or stock.</div>
    </div>
    <div id="ai-input">
        <input type="text" id="ai-user-input" placeholder="Ask anything..." onkeypress="if(event.key==='Enter') sendAI()">
        <button onclick="sendAI()">Send</button>
    </div>
</div>

<!-- Social Icons -->
<div class="social">
    <a href="https://instagram.com/blacq_clout" target="_blank" title="Follow us on Instagram">📷</a>
</div>

<script>
function toggleLike(btn) {
    btn.classList.toggle('liked');
    btn.innerHTML = btn.classList.contains('liked') ? '❤️' : '♡';
}

function addToCart(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<input type="hidden" name="add_to_cart" value="1"><input type="hidden" name="product_id" value="${id}">`;
    document.body.appendChild(form);
    form.submit();
}

function showCart() {
    let html = '';
    let total = 0;
    <?php foreach ($_SESSION['cart'] as $item): ?>
        html += `<p><?php echo addslashes($item['name']); ?> × <?php echo $item['qty']; ?> = KSh <?php echo $item['price'] * $item['qty']; ?></p>`;
        total += <?php echo $item['price'] * $item['qty']; ?>;
    <?php endforeach; ?>
    document.getElementById('cart-items').innerHTML = html || '<p style="color:#666;">Your cart is empty</p>';
    document.getElementById('cart-modal').style.display = 'block';
}

function toggleOrderForm(btn) {
    const section = btn.nextElementSibling;
    section.classList.toggle('show');
    btn.textContent = section.classList.contains('show') ? 'CANCEL' : 'ORDER NOW';
}

function toggleAI() {
    const chat = document.getElementById('ai-chat');
    chat.style.display = (chat.style.display === 'flex') ? 'none' : 'flex';
}

function sendAI() {
    const input = document.getElementById('ai-user-input');
    const messages = document.getElementById('ai-messages');
    const question = input.value.trim();
    if (!question) return;

    messages.innerHTML += `<div class="ai-msg ai-user">${question}</div>`;

    let reply = "Thank you! Our team will assist you shortly.";

    const q = question.toLowerCase();
    if (q.includes("size") || q.includes("fit")) reply = "Bracelets are adjustable. Clothing runs true to size.";
    else if (q.includes("delivery") || q.includes("nairobi")) reply = "Nairobi delivery: 1-2 days. Outside: 2-4 days.";
    else if (q.includes("mpesa") || q.includes("pay")) reply = "Pay to 254748171066 via M-Pesa.";

    // Real stock check (simple version)
    if (q.includes("stock")) {
        reply = "Stock is updated live on the shop page. Check the product cards for current availability.";
    }

    messages.innerHTML += `<div class="ai-msg ai-bot">${reply}</div>`;
    messages.scrollTop = messages.scrollHeight;
    input.value = '';
}
</script>

</body>
</html>