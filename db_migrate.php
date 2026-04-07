<?php
// Database migration to add instagram column
$pdo = new PDO("mysql:host=localhost;dbname=insta-biz;charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    // Check if instagram column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'instagram'");
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $pdo->exec("ALTER TABLE orders ADD COLUMN instagram VARCHAR(255) DEFAULT NULL AFTER phone");
        echo "✅ Success! Instagram column added to orders table.";
    } else {
        echo "✅ Instagram column already exists!";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
