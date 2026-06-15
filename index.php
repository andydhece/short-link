<?php
session_start();

$config = require __DIR__ . '/config.php';
$pdo    = require_once __DIR__ . '/db.php';

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Clean up relative path if app runs in a subdirectory
$scriptName = $_SERVER['SCRIPT_NAME'];
$basePath = str_replace('/index.php', '', $scriptName);
if ($basePath !== '' && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = '/' . ltrim($path, '/');

// Routing helper functions
function requireAuth() {
    if (!isset($_SESSION['userId'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

function requireAdmin() {
    if (!isset($_SESSION['userId']) || $_SESSION['role'] !== 'admin') {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

// 1. HTML Views (GET only)
if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SESSION['userId'])) {
        header('Location: ' . $config['base_url'] . '/dashboard');
        exit;
    }
    readfile(__DIR__ . '/views/login.html');
    exit;
}

// /register route dinonaktifkan — akun dibuat oleh admin
if ($path === '/register' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(404);
    readfile(__DIR__ . '/views/404.html');
    exit;
}

if ($path === '/dashboard') {
    if (!isset($_SESSION['userId'])) {
        header('Location: ' . $config['base_url'] . '/');
        exit;
    }
    readfile(__DIR__ . '/views/dashboard.html');
    exit;
}

if ($path === '/logout') {
    session_destroy();
    header('Location: ' . $config['base_url'] . '/');
    exit;
}

// 2. Auth APIs
if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim(strtolower($input['username'])) : '';
    $password = isset($input['password']) ? $input['password'] : '';

    if (empty($username) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Username dan password wajib diisi.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['userId'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        echo json_encode(['success' => true]);
        exit;
    }

    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Username atau password salah.']);
    exit;
}

// POST /register dinonaktifkan — akun dibuat oleh admin melalui panel
if ($path === '/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Pendaftaran mandiri dinonaktifkan. Hubungi admin.']);
    exit;
}

if ($path === '/api/session') {
    header('Content-Type: application/json');
    if (isset($_SESSION['userId'])) {
        echo json_encode([
            'loggedIn' => true,
            'username' => $_SESSION['username'],
            'role'     => $_SESSION['role'],
            'id'       => $_SESSION['userId']
        ]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
    exit;
}

// 3. Links APIs
if ($path === '/api/links') {
    requireAuth();
    header('Content-Type: application/json');

    if ($_SESSION['role'] === 'admin') {
        $stmt = $pdo->query("
            SELECT l.*, COUNT(c.id) as clicks, u.username as creator
            FROM links l 
            LEFT JOIN clicks_log c ON l.id = c.link_id 
            LEFT JOIN users u ON l.user_id = u.id
            GROUP BY l.id 
            ORDER BY l.created_at DESC
        ");
        $links = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT l.*, COUNT(c.id) as clicks, u.username as creator
            FROM links l 
            LEFT JOIN clicks_log c ON l.id = c.link_id 
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.user_id = ?
            GROUP BY l.id 
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([$_SESSION['userId']]);
        $links = $stmt->fetchAll();
    }

    echo json_encode(['links' => $links]);
    exit;
}

if ($path === '/api/shorten' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $url = isset($input['url']) ? trim($input['url']) : '';
    $keyword = isset($input['keyword']) ? trim(strtolower($input['keyword'])) : '';

    if (empty($url)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'URL wajib diisi.']);
        exit;
    }

    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'http://' . $url;
    }

    if (!empty($keyword)) {
        $keyword = preg_replace('/[^a-z0-9\-]/', '', $keyword);
        if (empty($keyword)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['error' => 'Format keyword tidak valid.']);
            exit;
        }
    } else {
        $charset = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $isUnique = false;
        while (!$isUnique) {
            $keyword = '';
            for ($i = 0; $i < 5; $i++) {
                $keyword .= $charset[rand(0, strlen($charset) - 1)];
            }
            $stmt = $pdo->prepare("SELECT id FROM links WHERE slug = ?");
            $stmt->execute([$keyword]);
            if (!$stmt->fetch()) {
                $isUnique = true;
            }
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM links WHERE slug = ?");
    $stmt->execute([$keyword]);
    if ($stmt->fetch()) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Keyword ini sudah digunakan.']);
        exit;
    }

    $title = $url;
    try {
        $opts = [
            'http' => [
                'timeout' => 2,
                'header'  => "User-Agent: ShortLinkBot/1.0\r\n"
            ]
        ];
        $context = stream_context_create($opts);
        $html = @file_get_contents($url, false, $context);
        if ($html !== false) {
            if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
                $title = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
            }
        }
    } catch (\Exception $e) {
        // ignore title fetch errors
    }

    $stmt = $pdo->prepare("INSERT INTO links (slug, url, title, user_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$keyword, $url, $title, $_SESSION['userId']]);

    echo json_encode([
        'success' => true,
        'shorturl' => $config['base_url'] . '/' . $keyword,
        'slug' => $keyword
    ]);
    exit;
}

if (preg_match('#^/api/links/(\d+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAuth();
    header('Content-Type: application/json');
    $linkId = $matches[1];

    if ($_SESSION['role'] === 'admin') {
        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
        $stmt->execute([$linkId]);
        echo json_encode(['success' => true]);
        exit;
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM links WHERE id = ?");
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();
        if (!$link) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Link tidak ditemukan.']);
            exit;
        }
        if ($link['user_id'] != $_SESSION['userId']) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Akses ditolak.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
        $stmt->execute([$linkId]);
        echo json_encode(['success' => true]);
        exit;
    }
}

if (preg_match('#^/api/stats/(\d+)$#', $path, $matches)) {
    requireAuth();
    header('Content-Type: application/json');
    $linkId = $matches[1];

    if ($_SESSION['role'] !== 'admin') {
        $stmt = $pdo->prepare("SELECT user_id FROM links WHERE id = ?");
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();
        if (!$link) {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(['error' => 'Link tidak ditemukan.']);
            exit;
        }
        if ($link['user_id'] != $_SESSION['userId']) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Akses ditolak.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT DATE(click_time) as date, COUNT(*) as count 
        FROM clicks_log 
        WHERE link_id = ? 
        GROUP BY DATE(click_time) 
        ORDER BY date ASC 
        LIMIT 14
    ");
    $stmt->execute([$linkId]);
    $daily = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT referrer, COUNT(*) as count 
        FROM clicks_log 
        WHERE link_id = ? 
        GROUP BY referrer 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmt->execute([$linkId]);
    $referrers = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT browser, COUNT(*) as count 
        FROM clicks_log 
        WHERE link_id = ? 
        GROUP BY browser 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmt->execute([$linkId]);
    $browsers = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT os, COUNT(*) as count 
        FROM clicks_log 
        WHERE link_id = ? 
        GROUP BY os 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmt->execute([$linkId]);
    $os = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT country, COUNT(*) as count 
        FROM clicks_log 
        WHERE link_id = ? 
        GROUP BY country 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stmt->execute([$linkId]);
    $countries = $stmt->fetchAll();

    echo json_encode([
        'daily'     => $daily,
        'referrers' => $referrers,
        'browsers'  => $browsers,
        'os'        => $os,
        'countries' => $countries
    ]);
    exit;
}

// 4. Users Admin APIs
if ($path === '/api/users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();
    header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY created_at DESC");
    echo json_encode(['users' => $stmt->fetchAll()]);
    exit;
}

if ($path === '/api/users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $username = isset($input['username']) ? trim(strtolower($input['username'])) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    $role = isset($input['role']) ? trim($input['role']) : 'user';

    if (empty($username) || empty($password) || empty($role)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Semua field wajib diisi.']);
        exit;
    }

    $cleanUsername = preg_replace('/[^a-z0-9\_]/', '', $username);
    if (empty($cleanUsername) || strlen($cleanUsername) < 3) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Username minimal 3 karakter alfanumerik/underscore.']);
        exit;
    }

    if (strlen($password) < 6) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Password minimal 6 karakter.']);
        exit;
    }

    if (!in_array($role, ['admin', 'user'])) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Role tidak valid.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$cleanUsername]);
    if ($stmt->fetch()) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Username sudah digunakan.']);
        exit;
    }

    $hashedPass = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->execute([$cleanUsername, $hashedPass, $role]);

    echo json_encode(['success' => true]);
    exit;
}

if (preg_match('#^/api/users/(\d+)/role$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireAdmin();
    header('Content-Type: application/json');
    $targetUserId = $matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $role = isset($input['role']) ? trim($input['role']) : '';

    if (!in_array($role, ['admin', 'user'])) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Role tidak valid.']);
        exit;
    }

    if ($targetUserId == $_SESSION['userId']) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Anda tidak dapat mengubah role Anda sendiri.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->execute([$role, $targetUserId]);
    echo json_encode(['success' => true]);
    exit;
}

if (preg_match('#^/api/users/(\d+)$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAdmin();
    header('Content-Type: application/json');
    $targetUserId = $matches[1];

    if ($targetUserId == $_SESSION['userId']) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Anda tidak dapat menghapus akun Anda sendiri.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    echo json_encode(['success' => true]);
    exit;
}

// 5. Shortlink Redirection Routing
if (preg_match('#^/([a-zA-Z0-9\-]+)$#', $path, $matches)) {
    $slug = $matches[1];

    if (in_array($slug, ['favicon.ico', 'robots.txt'])) {
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM links WHERE slug = ?");
    $stmt->execute([$slug]);
    $link = $stmt->fetch();

    if (!$link) {
        header('HTTP/1.1 404 Not Found');
        readfile(__DIR__ . '/views/404.html');
        exit;
    }

    // Fast analytics logging
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : ($_SERVER['REMOTE_ADDR'] ?: '');
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $rawReferrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

    $referrer = 'Direct / Email';
    if (!empty($rawReferrer)) {
        $parsedUrl = parse_url($rawReferrer);
        $referrer = isset($parsedUrl['host']) ? $parsedUrl['host'] : $rawReferrer;
    }

    // Simple user agent parser
    $browser = 'Unknown';
    $os = 'Unknown';

    if (preg_match('/msie/i', $userAgent) && !preg_match('/opera/i', $userAgent)) {
        $browser = 'MSIE';
    } elseif (preg_match('/firefox/i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/chrome/i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/safari/i', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/opera/i', $userAgent)) {
        $browser = 'Opera';
    } elseif (preg_match('/netscape/i', $userAgent)) {
        $browser = 'Netscape';
    }

    if (preg_match('/windows|win32/i', $userAgent)) {
        $os = 'Windows';
    } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
        $os = 'macOS';
    } elseif (preg_match('/android/i', $userAgent)) {
        $os = 'Android';
    } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
        $os = 'iOS';
    } elseif (preg_match('/linux/i', $userAgent)) {
        $os = 'Linux';
    }

    $country = 'Unknown';

    try {
        $logStmt = $pdo->prepare("
            INSERT INTO clicks_log (link_id, ip, browser, os, referrer, country) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $logStmt->execute([$link['id'], $ip, $browser, $os, $referrer, $country]);
    } catch (\Exception $e) {
        error_log("Failed logging click: " . $e->getMessage());
    }

    header('HTTP/1.1 302 Found');
    header('Location: ' . $link['url']);
    exit;
}

// 6. Default 404 Route
header('HTTP/1.1 404 Not Found');
readfile(__DIR__ . '/views/404.html');
exit;
