<?php
$config = require __DIR__ . '/config.php';

$host = $config['db_host'];
$db   = $config['db_name'];
$user = $config['db_user'];
$pass = $config['db_password'];
$port = $config['db_port'];
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Retry loop for Docker startup synchronization
    $retries = 5;
    while ($retries > 0) {
        error_log("Connection failed. Retrying in 3 seconds...");
        sleep(3);
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            break;
        } catch (\PDOException $ex) {
            $retries--;
            if ($retries === 0) {
                throw new \PDOException($ex->getMessage(), (int)$ex->getCode());
            }
        }
    }
}

// 1. Create users table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 2. Create links table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(255) UNIQUE NOT NULL,
        url TEXT NOT NULL,
        title VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 3. Alter links to add user_id column if not present
$stmt = $pdo->prepare("
    SELECT COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'links' AND COLUMN_NAME = 'user_id'
");
$stmt->execute([$db]);
$columnExists = $stmt->fetch();

if (!$columnExists) {
    try {
        $pdo->exec("
            ALTER TABLE links 
            ADD COLUMN user_id INT NULL,
            ADD CONSTRAINT fk_links_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
        ");
    } catch (\Exception $e) {
        error_log("Failed to add user_id to links: " . $e->getMessage());
    }
}

// 4. Create clicks log table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS clicks_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id INT NOT NULL,
        click_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip VARCHAR(45),
        browser VARCHAR(255),
        os VARCHAR(255),
        referrer VARCHAR(255),
        country VARCHAR(100),
        FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 5. Seed default admin user
$stmt = $pdo->query("SELECT id FROM users LIMIT 1");
$userExists = $stmt->fetch();

if (!$userExists) {
    $adminUser = $config['admin_username'];
    $adminPass = $config['admin_password'];
    $hashedPass = password_hash($adminPass, PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
    $stmt->execute([$adminUser, $hashedPass]);
}

return $pdo;
