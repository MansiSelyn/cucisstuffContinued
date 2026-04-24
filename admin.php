<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}
require_once 'config.php';
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$_SESSION['user_id']]);
    if (!$adminCheck->fetchColumn()) {
        header("Location: main.php");
        exit();
    }
    $isAdmin = true;
    // AJAX: termékadatok
    if (!empty($_GET['get_item_data'])) {
        header('Content-Type: application/json');
        $s = $conn->prepare("SELECT i.*, u.username AS seller_name FROM items i JOIN users u ON i.user_id=u.id WHERE i.id=?");
        $s->execute([$_GET['get_item_data']]);
        $item = $s->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            echo json_encode(['error' => 'Termék nem található']);
            exit;
        }
        $imgs = $conn->prepare("SELECT image_path FROM item_images WHERE item_id=? ORDER BY sort_order");
        $imgs->execute([$item['id']]);
        echo json_encode([
            'id' => $item['id'],
            'title' => $item['title'],
            'price' => number_format($item['price'], 0, ',', ' ') . ' Ft',
            'seller' => $item['seller_name'],
            'date' => date('Y-m-d', strtotime($item['created_at'])),
            'description' => $item['description'],
            'images' => $imgs->fetchAll(PDO::FETCH_COLUMN),
            'user_id' => $item['user_id'],
            'sold' => (bool)$item['sold']
        ]);
        exit;
    }

    // AJAX: felhasználói profil adatok
    if (isset($_GET['get_user_profile']) && !empty($_GET['get_user_profile'])) {
        header('Content-Type: application/json');
        $profileId = (int)$_GET['get_user_profile'];
        $userStmt = $conn->prepare("SELECT id, username, email, created_at, profile_picture FROM users WHERE id = ?");
        $userStmt->execute([$profileId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['error' => 'Felhasználó nem található']);
            exit;
        }
        $itemsStmt = $conn->prepare("SELECT id, title, price, sold, (SELECT image_path FROM item_images WHERE item_id = items.id AND is_primary = 1 LIMIT 1) as thumb FROM items WHERE user_id = ? ORDER BY created_at DESC");
        $itemsStmt->execute([$profileId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $user['items'] = $items;
        $user['item_count'] = count($items);
        echo json_encode($user);
        exit;
    }

    $view   = $_GET['view']   ?? 'main';
    $editId = $_GET['id']     ?? null;
    $page   = max(1, intval($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;
    $message = $error = '';
    // POST műveletek
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete_user'], $_POST['user_id'])) {
            if ($_POST['user_id'] != $_SESSION['user_id']) {
                $conn->prepare("DELETE FROM users WHERE id=?")->execute([$_POST['user_id']]);
                $message = "Felhasználó törölve.";
            } else {
                $error = "Saját magad nem törölheted!";
            }
        }
        if (isset($_POST['delete_item'], $_POST['item_id'])) {
            $dir = 'uploads/' . $_POST['item_id'] . '/';
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '*'));
                rmdir($dir);
            }
            $conn->prepare("DELETE FROM items WHERE id=?")->execute([$_POST['item_id']]);
            $message = "Termék törölve.";
        }
        if (isset($_POST['delete_report'], $_POST['report_id'])) {
            $repType = $_POST['report_type'] ?? 'item';
            if ($repType === 'message') {
                $conn->prepare("DELETE FROM message_reports WHERE id=?")->execute([$_POST['report_id']]);
            } else {
                $conn->prepare("DELETE FROM reports WHERE id=?")->execute([$_POST['report_id']]);
            }
            $message = "Report törölve.";
        }
        if (isset($_POST['update_item'], $_POST['item_id'])) {
            $t = trim($_POST['item_title'] ?? '');
            $d = trim($_POST['item_description'] ?? '');
            $p = trim($_POST['item_price'] ?? '');
            if (!$t || !$d || !$p) {
                $error = 'Minden mező kötelező!';
            } elseif (!is_numeric($p) || $p < 0) {
                $error = 'Az ár csak pozitív szám lehet!';
            } else {
                $conn->prepare("UPDATE items SET title=?,description=?,price=? WHERE id=?")->execute([$t, $d, (float)$p, $_POST['item_id']]);
                $message = "Termék módosítva.";
                header("Location: admin.php?view=items&page=$page");
                exit();
            }
        }
        if (isset($_POST['update_user'], $_POST['user_id'])) {
            $u = trim($_POST['username'] ?? '');
            $e = trim($_POST['email'] ?? '');
            if (!$u || !$e) {
                $error = 'Minden mező kötelező!';
            } elseif (!str_contains($e, '@')) {
                $error = 'Érvénytelen email!';
            } else {
                $chk = $conn->prepare("SELECT id FROM users WHERE (username=? OR email=?) AND id!=?");
                $chk->execute([$u, $e, $_POST['user_id']]);
                if ($chk->fetchColumn()) {
                    $error = 'Felhasználónév vagy email foglalt!';
                } else {
                    $conn->prepare("UPDATE users SET username=?,email=? WHERE id=?")->execute([$u, $e, $_POST['user_id']]);
                    $message = "Felhasználó módosítva.";
                    header("Location: admin.php?view=users&page=$page");
                    exit();
                }
            }
        }
        // Rendelés törlése
        if (isset($_POST['delete_order'], $_POST['order_id'])) {
            $conn->prepare("DELETE FROM orders WHERE id=?")->execute([$_POST['order_id']]);
            $message = "Rendelés törölve.";
        }
        // ---- VIZSGAPURGE ----
        if (isset($_POST['purge_confirm'])) {
            $keeperNames = ['gabi', 'martin', 'cuci', 'admin'];
            $placeholders = implode(',', array_fill(0, count($keeperNames), '?'));
            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) NOT IN ($placeholders)");
                $stmt->execute($keeperNames);
                $userIdsToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $itemsToDelete = [];
                $imagePaths = [];
                if (!empty($userIdsToDelete)) {
                    $inUsers = implode(',', array_fill(0, count($userIdsToDelete), '?'));
                    $itemStmt = $conn->prepare("SELECT id FROM items WHERE user_id IN ($inUsers)");
                    $itemStmt->execute($userIdsToDelete);
                    $itemIds = $itemStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($itemIds)) {
                        $inItems = implode(',', array_fill(0, count($itemIds), '?'));
                        $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id IN ($inItems)");
                        $imgStmt->execute($itemIds);
                        $imagePaths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                    $delUserStmt = $conn->prepare("DELETE FROM users WHERE id IN ($inUsers)");
                    $delUserStmt->execute($userIdsToDelete);
                }
                $conn->commit();
                foreach ($imagePaths as $path) {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
                if (!empty($itemIds)) {
                    foreach ($itemIds as $itemId) {
                        $dir = 'uploads/' . $itemId . '/';
                        if (is_dir($dir)) {
                            array_map('unlink', glob($dir . '*'));
                            rmdir($dir);
                        }
                    }
                }
                $message = "VIZSGAPURGE végrehajtva. Törölt felhasználók: " . count($userIdsToDelete) . ", törölt hirdetések: " . count($itemIds ?? []);
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Hiba a VIZSGAPURGE során: " . $e->getMessage();
            }
        }
    }
    // Adatok lekérése
    $counts = ['users' => 0, 'items' => 0, 'reports' => 0, 'orders' => 0];
    foreach (['users', 'items'] as $tbl) {
        try {
            $counts[$tbl] = $conn->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        } catch (PDOException $e) {
        }
    }
    try {
        $cItem = $conn->query("SELECT COUNT(*) FROM reports")->fetchColumn();
    } catch (PDOException $e) {
        $cItem = 0;
        $conn->exec("CREATE TABLE IF NOT EXISTS reports (id INT AUTO_INCREMENT PRIMARY KEY, item_id CHAR(12) NOT NULL, user_id INT NOT NULL, reason TEXT NOT NULL, status ENUM('pending','resolved','dismissed') DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }
    try {
        $cMsg = $conn->query("SELECT COUNT(*) FROM message_reports")->fetchColumn();
    } catch (PDOException $e) {
        $cMsg = 0;
        $conn->exec("CREATE TABLE IF NOT EXISTS message_reports (id INT AUTO_INCREMENT PRIMARY KEY, message_id CHAR(25) NOT NULL, reporter_user_id INT NOT NULL, reason TEXT NOT NULL, status ENUM('pending','resolved','dismissed') DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (message_id) REFERENCES uzenetek(id) ON DELETE CASCADE, FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }
    try {
        $counts['orders'] = $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    } catch (PDOException $e) {
        $counts['orders'] = 0;
        $conn->exec("CREATE TABLE IF NOT EXISTS orders (
            id CHAR(12) PRIMARY KEY,
            buyer_id INT NOT NULL,
            seller_id INT NOT NULL,
            item_id CHAR(12) NOT NULL,
            status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
            shipping_name VARCHAR(255) NOT NULL,
            shipping_email VARCHAR(255) NOT NULL,
            shipping_phone VARCHAR(50) NOT NULL,
            shipping_zip VARCHAR(20) NOT NULL,
            shipping_city VARCHAR(100) NOT NULL,
            shipping_address VARCHAR(255) NOT NULL,
            payment_method ENUM('cod', 'transfer', 'pickup') NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }
    $counts['reports'] = (int)$cItem + (int)$cMsg;
    $totalItems = $counts[$view] ?? 0;
    $totalPages = $perPage ? (int)ceil($totalItems / $perPage) : 0;
    $items = $users = $reports = $conversations = $messages = $orders = [];
    $selectedUser1 = $selectedUser2 = 0;
    $user1Name = $user2Name = '';
    if ($view === 'items' && !$editId) {
        $s = $conn->prepare("SELECT i.*,u.username AS seller_name FROM items i JOIN users u ON i.user_id=u.id ORDER BY i.created_at DESC LIMIT :o,:l");
        $s->bindValue(':o', $offset, PDO::PARAM_INT);
        $s->bindValue(':l', $perPage, PDO::PARAM_INT);
        $s->execute();
        $items = $s->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'users' && !$editId) {
        $s = $conn->prepare("SELECT u.*,(SELECT COUNT(*) FROM admins WHERE user_id=u.id) AS is_admin,(SELECT COUNT(*) FROM items WHERE user_id=u.id) AS item_count FROM users u ORDER BY u.created_at DESC LIMIT :o,:l");
        $s->bindValue(':o', $offset, PDO::PARAM_INT);
        $s->bindValue(':l', $perPage, PDO::PARAM_INT);
        $s->execute();
        $users = $s->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'reports') {
        $s = $conn->prepare("
            SELECT r.id, 'item' AS report_type,
                   r.item_id AS ref_id,
                   i.title AS ref_title,
                   u.username AS reporter_name,
                   owner.username AS target_name,
                   r.reason, r.status, r.created_at
            FROM reports r
            JOIN items i ON r.item_id = i.id
            JOIN users u ON r.user_id = u.id
            JOIN users owner ON i.user_id = owner.id
            UNION ALL
            SELECT mr.id, 'message' AS report_type,
                   mr.message_id AS ref_id,
                   CONCAT('Üzenet: ', LEFT(uz.message, 40)) AS ref_title,
                   reporter.username AS reporter_name,
                   sender.username AS target_name,
                   mr.reason, mr.status, mr.created_at
            FROM message_reports mr
            JOIN uzenetek uz ON mr.message_id = uz.id
            JOIN users reporter ON mr.reporter_user_id = reporter.id
            JOIN users sender ON uz.sender_id = sender.id
            ORDER BY created_at DESC
            LIMIT :o,:l
        ");
        $s->bindValue(':o', $offset, PDO::PARAM_INT);
        $s->bindValue(':l', $perPage, PDO::PARAM_INT);
        $s->execute();
        $reports = $s->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'conversations') {
        $convStmt = $conn->prepare("
            SELECT 
                LEAST(u1.id, u2.id) AS user1_id,
                GREATEST(u1.id, u2.id) AS user2_id,
                u1.username AS user1_name,
                u2.username AS user2_name,
                MAX(m.sent_at) AS last_message_at,
                (SELECT message FROM uzenetek WHERE 
                    (sender_id = user1_id AND receiver_id = user2_id) OR 
                    (sender_id = user2_id AND receiver_id = user1_id) 
                    ORDER BY sent_at DESC LIMIT 1) AS last_message
            FROM uzenetek m
            JOIN users u1 ON (u1.id = m.sender_id OR u1.id = m.receiver_id)
            JOIN users u2 ON (u2.id = m.sender_id OR u2.id = m.receiver_id)
            WHERE u1.id < u2.id
            GROUP BY user1_id, user2_id, user1_name, user2_name
            ORDER BY last_message_at DESC
        ");
        $convStmt->execute();
        $conversations = $convStmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedUser1 = isset($_GET['user1']) ? (int)$_GET['user1'] : 0;
        $selectedUser2 = isset($_GET['user2']) ? (int)$_GET['user2'] : 0;
        if ($selectedUser1 > 0 && $selectedUser2 > 0) {
            $msgStmt = $conn->prepare("
                SELECT m.*, s.username AS sender_name, r.username AS receiver_name
                FROM uzenetek m
                JOIN users s ON m.sender_id = s.id
                JOIN users r ON m.receiver_id = r.id
                WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.sent_at ASC
            ");
            $msgStmt->execute([$selectedUser1, $selectedUser2, $selectedUser2, $selectedUser1]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($conversations as $c) {
                if ($c['user1_id'] == $selectedUser1 && $c['user2_id'] == $selectedUser2) {
                    $user1Name = $c['user1_name'];
                    $user2Name = $c['user2_name'];
                    break;
                }
            }
        }
    } elseif ($view === 'orders') {
        $totalPages = (int)ceil($counts['orders'] / $perPage);
        // COLLATE használata a karakterkészlet- és kolláció-ütközés elkerülésére
        $oStmt = $conn->prepare("
            SELECT o.*, i.title AS item_title, i.price AS item_price,
                   buyer.username AS buyer_name, seller.username AS seller_name
            FROM orders o
            JOIN items i ON o.item_id COLLATE utf8mb4_unicode_ci = i.id COLLATE utf8mb4_unicode_ci
            JOIN users buyer ON o.buyer_id = buyer.id
            JOIN users seller ON o.seller_id = seller.id
            ORDER BY o.created_at DESC
            LIMIT :o, :l
        ");
        $oStmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $oStmt->bindValue(':l', $perPage, PDO::PARAM_INT);
        $oStmt->execute();
        $orders = $oStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $editItem = $editUser = null;
    if ($editId) {
        if ($view === 'items') {
            $s = $conn->prepare("SELECT * FROM items WHERE id=?");
            $s->execute([$editId]);
            $editItem = $s->fetch(PDO::FETCH_ASSOC);
        } elseif ($view === 'users') {
            $s = $conn->prepare("SELECT id,username,email FROM users WHERE id=?");
            $s->execute([$editId]);
            $editUser = $s->fetch(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $error = "DB HIBA: " . $e->getMessage();
    $totalPages = 0;
    $view = 'main';
    $items = $users = $reports = $conversations = $messages = $orders = [];
    $editItem = $editUser = null;
    $counts = ['users' => 0, 'items' => 0, 'reports' => 0, 'orders' => 0];
}
function pgLink($v, $p)
{
    return "admin.php?view=$v&page=$p";
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo.png">
    <title>ADMIN TERMINAL // CUCI-SYS</title>
    <style>
        /* ═══════════════════════════════════════════════
        MILITARY COMPUTER TERMINAL — ADMIN INTERFACE
        SYSTEM: CUCI-SYS v2.1 // CLASSIFIED ACCESS
        ═══════════════════════════════════════════════ */
        @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=VT323&display=swap');

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --c-bg: #050a03;
            --c-panel: #071005;
            --c-border: #1a3a10;
            --c-border2: #2a5a1a;
            --c-green: #39ff14;
            --c-green-dim: #1a7a08;
            --c-green-mid: #22cc08;
            --c-amber: #ffb300;
            --c-red: #ff2200;
            --c-text: #b0d8a0;
            --c-muted: #4a7040;
            --c-scan: rgba(57, 255, 20, 0.03);
            --c-glow: 0 0 8px rgba(57, 255, 20, 0.4);
            --c-glow-strong: 0 0 20px rgba(57, 255, 20, 0.6);
            --font-mono: 'Share Tech Mono', 'Courier New', monospace;
            --font-vt: 'VT323', 'Courier New', monospace;
        }

        html.light-mode {
            --c-bg: #2a1a00;
            --c-panel: #3a2400;
            --c-border: #6a4500;
            --c-border2: #9a6500;
            --c-green: #ff8c00;
            --c-green-dim: #9a7000;
            --c-green-mid: #e69900;
            --c-amber: #ff8533;
            --c-red: #ff4433;
            --c-text: #f0c080;
            --c-muted: #9a6a45;
            --c-scan: rgba(255, 204, 0, 0.05);
            --c-glow: 0 0 10px rgba(255, 140, 0, 0.5);
            --c-glow-strong: 0 0 25px rgba(255, 140, 0, 0.7);
        }

        html {
            height: 100%;
        }

        body {
            min-height: 100vh;
            background: var(--c-bg);
            color: var(--c-text);
            font-family: var(--font-mono);
            font-size: 0.88rem;
            line-height: 1.5;
            overflow-x: hidden;
            user-select: none;
            -webkit-user-select: none;
            position: relative;
        }

        input,
        textarea,
        .selectable {
            user-select: text;
            -webkit-user-select: text;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: repeating-linear-gradient(0deg,
                    transparent,
                    transparent 2px,
                    var(--c-scan) 2px,
                    var(--c-scan) 4px);
            pointer-events: none;
            z-index: 9998;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: radial-gradient(ellipse at center,
                    transparent 55%,
                    rgba(0, 0, 0, 0.7) 100%);
            pointer-events: none;
            z-index: 9997;
        }

        @keyframes flicker {

            0%,
            100% {
                opacity: 1;
            }

            97% {
                opacity: 1;
            }

            98% {
                opacity: 0.94;
            }

            99% {
                opacity: 1;
            }
        }

        .crt-wrap {
            animation: flicker 8s infinite;
            min-height: 100vh;
            padding: 0 0 80px;
        }

        .glow {
            text-shadow: var(--c-glow);
        }

        .glow-strong {
            text-shadow: var(--c-glow-strong);
        }

        .terminal-chrome {
            background: var(--c-panel);
            border-bottom: 2px solid var(--c-border2);
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 0 var(--c-border), 0 4px 20px rgba(0, 0, 0, 0.8);
        }

        .chrome-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 16px;
            border-bottom: 1px solid var(--c-border);
            font-family: var(--font-vt);
            font-size: 1.1rem;
        }

        .chrome-top-left {
            color: var(--c-green);
            letter-spacing: 2px;
        }

        .chrome-top-left span {
            color: var(--c-muted);
        }

        .chrome-top-right {
            display: flex;
            gap: 16px;
            align-items: center;
            color: var(--c-muted);
            font-size: 0.95rem;
        }

        .chrome-top-right .live-clock {
            color: var(--c-green);
            letter-spacing: 1px;
        }

        .chrome-nav {
            display: flex;
            align-items: center;
            gap: 0;
            padding: 0 8px;
            overflow-x: auto;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: transparent;
            border: none;
            border-right: 1px solid var(--c-border);
            color: var(--c-muted);
            font-family: var(--font-mono);
            font-size: 0.8rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
            position: relative;
        }

        .nav-btn:first-child {
            border-left: 1px solid var(--c-border);
        }

        .nav-btn:hover {
            color: var(--c-green);
            background: rgba(57, 255, 20, 0.04);
        }

        .nav-btn.active {
            color: var(--c-green);
            background: rgba(57, 255, 20, 0.07);
            box-shadow: inset 0 -2px 0 var(--c-green);
        }

        .nav-btn .nav-badge {
            background: var(--c-green-dim);
            color: var(--c-bg);
            font-size: 0.65rem;
            padding: 1px 5px;
            border-radius: 2px;
        }

        .nav-btn.active .nav-badge {
            background: var(--c-green);
        }

        .nav-btn.nav-back {
            margin-left: auto;
            color: var(--c-muted);
        }

        .nav-btn.nav-back:hover {
            color: var(--c-amber);
        }

        .nav-btn.purge-btn {
            color: #ff3333 !important;
            border-color: #ff3333 !important;
        }

        .nav-btn.purge-btn:hover {
            background: rgba(255, 51, 51, 0.2) !important;
            color: #ff6666 !important;
            box-shadow: 0 0 12px rgba(255, 0, 0, 0.5) !important;
        }

        html.light-mode .nav-btn.purge-btn {
            color: #ff0000 !important;
            border-color: #ff0000 !important;
        }

        html.light-mode .nav-btn.purge-btn:hover {
            background: rgba(255, 0, 0, 0.15) !important;
            color: #ff4444 !important;
        }

        .theme-btn {
            position: fixed;
            top: 10px;
            right: 12px;
            z-index: 9999;
            background: var(--c-panel);
            border: 1px solid var(--c-border2);
            color: var(--c-green);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            letter-spacing: 1px;
            padding: 4px 8px;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.2s;
        }

        .theme-btn:hover {
            background: var(--c-border);
            border-color: var(--c-green);
            box-shadow: var(--c-glow);
        }

        .terminal-body {
            max-width: 1700px;
            margin: 0 auto;
            padding: 20px 16px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--c-border);
        }

        .section-header h2 {
            font-family: var(--font-vt);
            font-size: 1.6rem;
            color: var(--c-green);
            letter-spacing: 3px;
            text-transform: uppercase;
            text-shadow: var(--c-glow);
        }

        .section-header::before {
            content: '//';
            color: var(--c-green-dim);
            font-family: var(--font-vt);
            font-size: 1.4rem;
        }

        .record-count {
            margin-left: auto;
            font-size: 0.75rem;
            color: var(--c-muted);
            letter-spacing: 1px;
        }

        .banner {
            padding: 10px 16px;
            margin-bottom: 14px;
            border-left: 3px solid;
            font-family: var(--font-mono);
            font-size: 0.82rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            animation: bannerFade 5s forwards;
        }

        @keyframes bannerFade {

            0%,
            80% {
                opacity: 1
            }

            100% {
                opacity: 0
            }
        }

        .banner-ok {
            border-color: var(--c-green);
            background: rgba(57, 255, 20, 0.05);
            color: var(--c-green);
        }

        .banner-err {
            border-color: var(--c-red);
            background: rgba(255, 34, 0, 0.07);
            color: var(--c-red);
        }

        .banner::before {
            margin-right: 10px;
        }

        .banner-ok::before {
            content: '[OK]';
        }

        .banner-err::before {
            content: '[ERR]';
        }

        .data-panel {
            border: 1px solid var(--c-border2);
            background: var(--c-panel);
            overflow-x: auto;
            margin-bottom: 16px;
            position: relative;
        }

        .data-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--c-green-dim), transparent);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .data-table th {
            background: rgba(57, 255, 20, 0.05);
            color: var(--c-green-mid);
            font-family: var(--font-mono);
            font-size: 0.72rem;
            font-weight: normal;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid var(--c-border2);
            white-space: nowrap;
        }

        .data-table th::before {
            content: '> ';
            color: var(--c-green-dim);
        }

        .data-table td {
            padding: 9px 14px;
            border-bottom: 1px solid var(--c-border);
            color: var(--c-text);
            font-size: 0.83rem;
            white-space: nowrap;
            vertical-align: middle;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover td {
            background: rgba(57, 255, 20, 0.03);
            color: var(--c-green);
        }

        .data-table td.wrap {
            white-space: normal;
            max-width: 260px;
            word-break: break-word;
        }

        .data-table td.mono {
            font-family: var(--font-mono);
            font-size: 0.75rem;
            color: var(--c-muted);
        }

        .act {
            display: inline-block;
            padding: 3px 10px;
            font-family: var(--font-mono);
            font-size: 0.72rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid;
            background: transparent;
            transition: all 0.15s;
            margin: 1px 2px;
        }

        .act-edit {
            color: #5599ff;
            border-color: #2244aa;
        }

        .act-edit:hover {
            background: rgba(85, 153, 255, 0.1);
            border-color: #5599ff;
            box-shadow: 0 0 8px rgba(85, 153, 255, 0.3);
        }

        .act-del {
            color: var(--c-red);
            border-color: #660000;
        }

        .act-del:hover {
            background: rgba(255, 34, 0, 0.1);
            border-color: var(--c-red);
            box-shadow: 0 0 8px rgba(255, 34, 0, 0.3);
        }

        .act-view {
            color: var(--c-green-mid);
            border-color: var(--c-border2);
        }

        .act-view:hover {
            background: rgba(57, 255, 20, 0.07);
            border-color: var(--c-green-mid);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--c-muted);
            font-family: var(--font-vt);
            font-size: 1.2rem;
            letter-spacing: 2px;
            border: 1px dashed var(--c-border);
        }

        .empty-state::before {
            content: '[ NO DATA ]';
            display: block;
            font-size: 1.8rem;
            color: var(--c-green-dim);
            margin-bottom: 8px;
        }

        .edit-terminal {
            max-width: 640px;
            margin: 0 auto 20px;
            border: 1px solid var(--c-border2);
            background: var(--c-panel);
            padding: 0;
        }

        .edit-terminal-header {
            background: rgba(57, 255, 20, 0.06);
            border-bottom: 1px solid var(--c-border2);
            padding: 10px 16px;
            font-family: var(--font-vt);
            font-size: 1.3rem;
            letter-spacing: 3px;
            color: var(--c-green);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-terminal-header::before {
            content: '▶';
            font-size: 1rem;
            color: var(--c-green-dim);
        }

        .edit-terminal-body {
            padding: 20px 20px 16px;
        }

        .field-row {
            margin-bottom: 16px;
        }

        .field-label {
            display: block;
            font-size: 0.7rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--c-muted);
            margin-bottom: 5px;
        }

        .field-label::before {
            content: ':: ';
            color: var(--c-green-dim);
        }

        .field-input,
        .field-textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid var(--c-border2);
            color: var(--c-green);
            font-family: var(--font-mono);
            font-size: 0.88rem;
            padding: 8px 12px;
            outline: none;
            transition: all 0.15s;
        }

        .field-input:focus,
        .field-textarea:focus {
            border-color: var(--c-green);
            box-shadow: var(--c-glow), inset 0 0 10px rgba(57, 255, 20, 0.04);
        }

        .field-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .field-input::placeholder,
        .field-textarea::placeholder {
            color: var(--c-green-dim);
        }

        .edit-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
            align-items: center;
        }

        .btn-save {
            padding: 8px 24px;
            background: rgba(57, 255, 20, 0.1);
            border: 1px solid var(--c-green-mid);
            color: var(--c-green);
            font-family: var(--font-mono);
            font-size: 0.78rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-save:hover {
            background: rgba(57, 255, 20, 0.2);
            box-shadow: var(--c-glow-strong);
        }

        .btn-cancel {
            padding: 8px 20px;
            background: transparent;
            border: 1px solid var(--c-border2);
            color: var(--c-muted);
            font-family: var(--font-mono);
            font-size: 0.78rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-cancel:hover {
            border-color: var(--c-amber);
            color: var(--c-amber);
        }

        .dash-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .dash-card {
            border: 1px solid var(--c-border2);
            background: var(--c-panel);
            padding: 20px;
            position: relative;
            overflow: hidden;
            cursor: default;
            transition: border-color 0.2s;
        }

        .dash-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--c-green-dim), transparent);
        }

        .dash-card:hover {
            border-color: var(--c-green-mid);
        }

        .dash-card:hover .dash-number {
            text-shadow: var(--c-glow-strong);
        }

        .dash-label {
            font-size: 0.68rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--c-muted);
            margin-bottom: 8px;
        }

        .dash-label::before {
            content: '■ ';
            font-size: 0.5rem;
        }

        .dash-number {
            font-family: var(--font-vt);
            font-size: 3.2rem;
            color: var(--c-green);
            line-height: 1;
            text-shadow: var(--c-glow);
            letter-spacing: 4px;
        }

        .dash-sublabel {
            font-size: 0.7rem;
            color: var(--c-green-dim);
            margin-top: 6px;
            letter-spacing: 1px;
        }

        .sys-info {
            border: 1px solid var(--c-border);
            background: var(--c-panel);
            padding: 16px 20px;
            font-family: var(--font-mono);
            font-size: 0.78rem;
            color: var(--c-muted);
            line-height: 2;
        }

        .sys-info p::before {
            content: '> ';
            color: var(--c-green-dim);
        }

        .sys-info strong {
            color: var(--c-green-mid);
        }

        .pagination {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: var(--c-panel);
            border-top: 1px solid var(--c-border2);
            z-index: 200;
        }

        .pg-btn {
            padding: 5px 20px;
            background: transparent;
            border: 1px solid var(--c-border2);
            color: var(--c-text);
            font-family: var(--font-mono);
            font-size: 0.75rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-decoration: none;
            transition: all 0.15s;
        }

        .pg-btn:hover:not(.disabled) {
            border-color: var(--c-green);
            color: var(--c-green);
            box-shadow: var(--c-glow);
        }

        .pg-btn.disabled {
            opacity: 0.25;
            pointer-events: none;
        }

        .pg-info {
            font-family: var(--font-vt);
            font-size: 1.2rem;
            color: var(--c-green);
            min-width: 80px;
            text-align: center;
            letter-spacing: 2px;
        }

        /* MEGERŐSÍTŐ MODAL (TÖRLÉSHEZ) */
        .confirm-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .confirm-modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .confirm-modal-card {
            width: 100%;
            max-width: 450px;
            background: var(--c-panel);
            border: 2px solid var(--c-red);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 0 40px rgba(255, 0, 0, 0.4), 0 10px 30px rgba(0, 0, 0, 0.7);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.25s;
            opacity: 0;
            text-align: center;
        }

        .confirm-modal-overlay.active .confirm-modal-card {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .confirm-modal-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .confirm-modal-title {
            font-family: var(--font-vt);
            font-size: 1.8rem;
            color: var(--c-red);
            text-shadow: 0 0 10px #ff0000;
            letter-spacing: 2px;
            margin-bottom: 1rem;
        }

        .confirm-modal-text {
            color: var(--c-text);
            margin-bottom: 1.8rem;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .confirm-modal-text strong {
            color: var(--c-red);
        }

        .confirm-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .confirm-btn-yes {
            padding: 0.65rem 2rem;
            background: var(--c-red);
            border: none;
            border-radius: 40px;
            color: #000;
            font-family: var(--font-mono);
            font-weight: bold;
            font-size: 0.85rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 0 15px #ff0000;
        }

        .confirm-btn-yes:hover {
            background: #ff5555;
            box-shadow: 0 0 25px #ff4444;
            transform: scale(1.02);
        }

        .confirm-btn-no {
            padding: 0.65rem 2rem;
            background: var(--c-green-dim);
            border: none;
            border-radius: 40px;
            color: #000;
            font-family: var(--font-mono);
            font-weight: bold;
            font-size: 0.85rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 0 15px var(--c-green);
        }

        .confirm-btn-no:hover {
            background: var(--c-green-mid);
            box-shadow: 0 0 25px var(--c-green);
            transform: scale(1.02);
        }

        .purge-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .purge-modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .purge-modal-card {
            width: 100%;
            max-width: 500px;
            background: #1a0505;
            border: 2px solid #ff3333;
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 0 40px rgba(255, 0, 0, 0.4), 0 10px 30px rgba(0, 0, 0, 0.7);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.25s;
            opacity: 0;
            text-align: center;
        }

        .purge-modal-overlay.active .purge-modal-card {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .purge-modal-icon {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        .purge-modal-title {
            font-family: var(--font-vt);
            font-size: 2rem;
            color: #ff3333;
            text-shadow: 0 0 15px #ff0000;
            letter-spacing: 4px;
            margin-bottom: 1rem;
        }

        .purge-modal-text {
            color: #f0c0c0;
            margin-bottom: 1.8rem;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .purge-modal-text strong {
            color: #ff8888;
        }

        .purge-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .purge-btn-confirm {
            padding: 0.75rem 2rem;
            background: #ff3333;
            border: none;
            border-radius: 40px;
            color: #000;
            font-family: var(--font-mono);
            font-weight: bold;
            font-size: 0.9rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 0 15px #ff0000;
        }

        .purge-btn-confirm:hover {
            background: #ff5555;
            box-shadow: 0 0 25px #ff4444;
            transform: scale(1.02);
        }

        .purge-btn-cancel {
            padding: 0.75rem 2rem;
            background: #00aa3a;
            border: none;
            border-radius: 40px;
            color: #000;
            font-family: var(--font-mono);
            font-weight: bold;
            font-size: 0.9rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 0 15px #00ff66;
        }

        .purge-btn-cancel:hover {
            background: #33cc66;
            box-shadow: 0 0 25px #33ff77;
            transform: scale(1.02);
        }

        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 6000;
            background: rgba(0, 5, 0, 0.97);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .product-modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .product-modal-card {
            width: 100vw;
            height: 100vh;
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 0;
            background: #030a02;
            border: 1px solid var(--c-border2);
            overflow: hidden;
            position: relative;
        }

        .product-modal-card * {
            user-select: none;
            -webkit-user-select: none;
        }

        .product-modal-header {
            position: absolute;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
            z-index: 100;
        }

        .pm-btn {
            width: 36px;
            height: 36px;
            background: rgba(0, 0, 0, 0.8);
            border: 1px solid var(--c-green-mid);
            color: var(--c-green);
            font-family: var(--font-mono);
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }

        .pm-btn:hover {
            background: var(--c-green-mid);
            color: var(--c-bg);
        }

        .product-menu {
            position: relative;
        }

        .product-menu-content {
            position: absolute;
            top: 42px;
            right: 0;
            min-width: 160px;
            background: rgba(3, 10, 2, 0.98);
            border: 1px solid var(--c-green-mid);
            padding: 4px;
            display: none;
            z-index: 101;
        }

        .product-menu-content.show {
            display: block;
        }

        .product-menu-item {
            width: 100%;
            padding: 7px 12px;
            background: transparent;
            border: none;
            color: var(--c-text);
            font-family: var(--font-mono);
            font-size: 0.78rem;
            letter-spacing: 1px;
            text-align: left;
            cursor: pointer;
            transition: all 0.15s;
            text-transform: uppercase;
        }

        .product-menu-item:hover {
            background: rgba(57, 255, 20, 0.07);
            color: var(--c-green);
        }

        .product-menu-item.delete:hover {
            background: rgba(255, 34, 0, 0.1);
            color: var(--c-red);
        }

        .product-gallery {
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--c-border2);
            background: #020802;
            padding: 16px;
            min-height: 0;
        }

        .product-main-image-container {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--c-border);
            background: rgba(0, 0, 0, 0.4);
            overflow: hidden;
            margin-bottom: 12px;
            min-height: 200px;
        }

        .product-main-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            cursor: zoom-in;
        }

        .product-no-image-placeholder {
            color: var(--c-green-dim);
            font-family: var(--font-vt);
            font-size: 1.4rem;
            letter-spacing: 3px;
        }

        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: var(--c-green);
            border: 1px solid var(--c-green-mid);
            width: 36px;
            height: 36px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.15s;
            z-index: 10;
        }

        .gallery-nav:hover {
            background: var(--c-green-mid);
            color: var(--c-bg);
        }

        .gallery-nav.prev {
            left: 8px;
        }

        .gallery-nav.next {
            right: 8px;
        }

        .gallery-nav.hidden {
            display: none;
        }

        .product-thumbnails {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 4px 0;
            min-height: 72px;
        }

        .product-thumbnail {
            width: 72px;
            height: 72px;
            flex-shrink: 0;
            border: 1px solid var(--c-border2);
            cursor: pointer;
            transition: all 0.15s;
            overflow: hidden;
        }

        .product-thumbnail:hover,
        .product-thumbnail.active {
            border-color: var(--c-green);
            box-shadow: var(--c-glow);
        }

        .product-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-details {
            display: flex;
            flex-direction: column;
            gap: 16px;
            padding: 20px;
            overflow-y: auto;
            background: #020802;
        }

        .product-title {
            font-family: var(--font-vt);
            font-size: 2rem;
            color: var(--c-green);
            letter-spacing: 2px;
            text-shadow: var(--c-glow);
            word-break: break-word;
        }

        .product-price {
            font-family: var(--font-vt);
            font-size: 2.6rem;
            color: var(--c-green);
            text-shadow: var(--c-glow-strong);
            letter-spacing: 3px;
        }

        .product-seller {
            font-size: 0.85rem;
            color: var(--c-muted);
        }

        .product-seller strong {
            color: var(--c-green-mid);
        }

        .product-date {
            font-size: 0.75rem;
            color: var(--c-green-dim);
            letter-spacing: 1px;
        }

        .product-description {
            font-size: 0.85rem;
            line-height: 1.8;
            color: var(--c-text);
            background: rgba(0, 0, 0, 0.4);
            padding: 14px;
            border: 1px solid var(--c-border);
            max-height: 260px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .product-buy-btn {
            background: rgba(0, 180, 60, 0.12);
            border: 1px solid #00aa3a;
            color: #00ff66;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            padding: 14px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: auto;
        }

        .product-buy-btn:hover {
            background: rgba(0, 180, 60, 0.2);
            box-shadow: 0 0 20px rgba(0, 180, 60, 0.3);
        }

        .product-buy-btn.sold {
            background: #555 !important;
            border-color: #777 !important;
            color: #aaa !important;
            cursor: not-allowed;
        }

        .lightbox-overlay {
            position: fixed;
            inset: 0;
            z-index: 7000;
            background: rgba(0, 0, 0, 0.98);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .lightbox-overlay.active {
            display: flex;
            opacity: 1;
        }

        .lightbox-content {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            max-width: 95vw;
            max-height: 95vh;
        }

        .lightbox-image {
            max-width: calc(95vw - 60px);
            max-height: 95vh;
            object-fit: contain;
            border: 1px solid var(--c-green-mid);
        }

        .lightbox-close {
            background: rgba(0, 0, 0, 0.9);
            border: 1px solid var(--c-green-mid);
            color: var(--c-green);
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.15s;
        }

        .lightbox-close:hover {
            background: var(--c-green-mid);
            color: var(--c-bg);
        }

        .view-item-btn {
            background: none;
            border: none;
            color: var(--c-green-mid);
            cursor: pointer;
            font-family: var(--font-mono);
            font-size: 0.83rem;
            text-decoration: underline;
            letter-spacing: 0.5px;
            transition: color 0.15s;
            padding: 0;
            text-align: left;
        }

        .view-item-btn:hover {
            color: var(--c-green);
        }

        .conversations-container {
            display: flex;
            gap: 1rem;
            height: calc(100vh - 220px);
            min-height: 500px;
        }

        .conversation-sidebar {
            width: 300px;
            background: var(--c-panel);
            border: 1px solid var(--c-border2);
            overflow-y: auto;
        }

        .conversation-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--c-border);
            text-decoration: none;
            color: var(--c-text);
            transition: background 0.15s;
        }

        .conversation-item:hover,
        .conversation-item.active {
            background: rgba(57, 255, 20, 0.07);
            color: var(--c-green);
        }

        .conversation-item.active {
            cursor: default;
            pointer-events: none;
        }

        .conv-avatars {
            display: flex;
            gap: 0.2rem;
        }

        .conv-avatars span {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--c-border2);
            color: var(--c-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            border: 1px solid var(--c-green-dim);
        }

        .conv-info {
            flex: 1;
            min-width: 0;
        }

        .conv-names {
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conv-lastmsg {
            font-size: 0.75rem;
            color: var(--c-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conv-time {
            font-size: 0.65rem;
            color: var(--c-green-dim);
            margin-top: 2px;
        }

        .conversation-chat {
            flex: 1;
            background: var(--c-panel);
            border: 1px solid var(--c-border2);
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            padding: 0.8rem 1.2rem;
            border-bottom: 1px solid var(--c-border2);
            font-family: var(--font-vt);
            font-size: 1.2rem;
            color: var(--c-green);
            background: rgba(57, 255, 20, 0.03);
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .message-row {
            display: flex;
        }

        .message-row.left {
            justify-content: flex-start;
        }

        .message-row.right {
            justify-content: flex-end;
        }

        .message-bubble {
            max-width: 70%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--c-border);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
        }

        .message-sender {
            font-size: 0.7rem;
            color: var(--c-green-mid);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .message-text {
            font-size: 0.85rem;
            color: var(--c-text);
            word-break: break-word;
        }

        .message-time {
            font-size: 0.6rem;
            color: var(--c-muted);
            text-align: right;
            margin-top: 0.3rem;
        }

        .chat-placeholder {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--c-muted);
            font-family: var(--font-vt);
            font-size: 1.2rem;
            letter-spacing: 2px;
        }

        /* Order details row */
        .order-details-row td {
            padding: 16px !important;
            background: rgba(0, 0, 0, 0.3) !important;
            white-space: normal !important;
        }

        .order-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            font-size: 0.85rem;
            line-height: 1.6;
        }

        .order-details-grid strong {
            color: var(--c-green-mid);
        }

        /* User popup overlay */
        .user-popup-overlay {
            position: fixed;
            inset: 0;
            z-index: 5000;
            background: rgba(0, 0, 0, 0.98);
            backdrop-filter: blur(16px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .user-popup-overlay.active {
            display: flex;
            opacity: 1;
        }

        .user-popup-card {
            width: 100vw;
            height: 100vh;
            background: rgba(5, 5, 5, 0.99);
            border: none;
            border-radius: 0;
            padding: 0;
            overflow: hidden;
            position: relative;
            transform: scale(0.98);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .user-popup-overlay.active .user-popup-card {
            transform: scale(1);
        }

        .user-popup-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            background: rgba(5, 5, 5, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--c-border2);
            flex-shrink: 0;
        }

        .user-popup-close {
            background: rgba(57, 255, 20, 0.1);
            border: 1px solid var(--c-border2);
            color: var(--c-green);
            width: 42px;
            height: 42px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .user-popup-close:hover {
            background: var(--c-green);
            color: #000;
        }

        .user-popup-topbar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--c-green);
        }

        .user-popup-body {
            flex: 1;
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 3rem;
            display: flex;
            flex-direction: column;
            gap: 0;
            overflow-y: auto;
        }

        .user-popup-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--c-green), #0a4a00);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 700;
            color: #000;
            margin: 0 auto 1.2rem;
            box-shadow: 0 0 40px rgba(57, 255, 20, 0.3);
            overflow: hidden;
            flex-shrink: 0;
        }

        .user-popup-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-popup-name {
            text-align: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--c-green);
            margin-bottom: 0.35rem;
        }

        .user-popup-meta {
            text-align: center;
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.4);
            margin-bottom: 2rem;
        }

        .user-popup-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .user-stat {
            flex: 1;
            background: rgba(57, 255, 20, 0.07);
            border: 1px solid rgba(57, 255, 20, 0.15);
            border-radius: 16px;
            padding: 1.1rem;
            text-align: center;
        }

        .user-stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--c-green);
        }

        .user-stat-label {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 3px;
        }

        .user-popup-items-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255, 255, 255, 0.3);
            margin-bottom: 0.9rem;
        }

        .user-popup-items-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
            margin-bottom: 2rem;
            max-height: 50vh;
            overflow-y: auto;
        }

        .user-item-thumb {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(57, 255, 20, 0.12);
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(0, 0, 0, 0.4);
        }

        .user-item-thumb:hover {
            border-color: var(--c-green);
            box-shadow: 0 8px 24px rgba(57, 255, 20, 0.2);
        }

        .user-item-thumb.sold {
            opacity: 0.5;
            border-color: rgba(255, 50, 50, 0.5);
        }

        .user-item-thumb.sold:hover {
            border-color: var(--c-red);
            box-shadow: 0 8px 24px rgba(255, 0, 0, 0.2);
        }

        .user-item-thumb img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            display: block;
        }

        .user-item-thumb-placeholder {
            width: 100%;
            height: 110px;
            background: rgba(57, 255, 20, 0.07);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(57, 255, 20, 0.35);
            font-size: 1.8rem;
        }

        .user-item-info {
            padding: 0.6rem 0.75rem;
        }

        .user-item-title {
            font-size: 0.82rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: rgba(255, 255, 255, 0.85);
        }

        .user-item-price {
            font-size: 0.8rem;
            color: var(--c-green);
            font-weight: 600;
            margin-top: 3px;
        }

        .user-item-sold-badge {
            font-size: 0.7rem;
            color: var(--c-red);
            text-transform: uppercase;
            margin-top: 2px;
        }

        @media (max-width: 1100px) {
            .product-modal-card {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }

            .product-gallery {
                height: 45vh;
                border-right: none;
                border-bottom: 1px solid var(--c-border2);
            }
        }

        @media (max-width: 800px) {
            .conversations-container {
                flex-direction: column;
                height: auto;
            }

            .conversation-sidebar {
                width: 100%;
                max-height: 300px;
            }
        }

        @media (max-width: 600px) {
            .nav-btn .nav-label {
                display: none;
            }

            .product-title {
                font-size: 1.5rem;
            }

            .product-price {
                font-size: 2rem;
            }

            .chrome-top-right .sys-time-full {
                display: none;
            }
        }

        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--c-panel);
            border: 1px solid var(--c-border);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--c-green-dim);
            border: 1px solid var(--c-border2);
            border-radius: 0;
            box-shadow: inset 0 0 6px rgba(0, 0, 0, 0.5);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--c-green-mid);
        }

        ::-webkit-scrollbar-corner {
            background: var(--c-panel);
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: var(--c-green-dim) var(--c-panel);
        }

        html.light-mode * {
            scrollbar-color: var(--c-green-dim) var(--c-panel);
        }

        html.light-mode ::-webkit-scrollbar-thumb {
            background: var(--c-green-dim);
            border-color: var(--c-border2);
        }

        html.light-mode ::-webkit-scrollbar-thumb:hover {
            background: var(--c-green-mid);
        }

        /* Light mode fix: user popup narancs színek */
        html.light-mode .user-popup-avatar {
            background: linear-gradient(135deg, #ff8c00, #cc4400) !important;
            box-shadow: 0 0 40px rgba(255, 140, 0, 0.4) !important;
        }

        html.light-mode .user-popup-name,
        html.light-mode .user-popup-topbar-title,
        html.light-mode .user-stat-value,
        html.light-mode .user-item-price {
            color: #ff8c00 !important;
        }

        html.light-mode .user-item-thumb {
            border-color: rgba(255, 140, 0, 0.2) !important;
        }

        html.light-mode .user-item-thumb:hover {
            border-color: #ff8c00 !important;
            box-shadow: 0 8px 24px rgba(255, 140, 0, 0.3) !important;
        }

        html.light-mode .user-popup-close {
            background: rgba(255, 170, 51, 0.2) !important;
            border-color: rgba(255, 170, 51, 0.5) !important;
            color: #ffaa33 !important;
        }

        html.light-mode .user-popup-close:hover {
            background: #ffaa33 !important;
            color: #000 !important;
        }

        html.light-mode .user-stat {
            background: rgba(255, 140, 0, 0.08) !important;
            border-color: rgba(255, 140, 0, 0.2) !important;
        }

        html.light-mode .user-item-thumb-placeholder {
            background: rgba(255, 140, 0, 0.1) !important;
            color: rgba(255, 140, 0, 0.4) !important;
        }
    </style>
</head>

<body>
    <div class="crt-wrap">
        <button class="theme-btn" id="themeToggleBtn">MODE</button>
        <!-- CHROME -->
        <div class="terminal-chrome">
            <div class="chrome-top">
                <div class="chrome-top-left">CUCI-SYS <span>// ADMIN TERMINAL // SECURITY LEVEL: A1</span></div>
                <div class="chrome-top-right">
                    <span>OP: <strong style="color:var(--c-green)"><?php echo htmlspecialchars($_SESSION['username'] ?? 'UNKNOWN'); ?></strong></span>
                    <span class="live-clock" id="liveClock">--:--:--</span>
                </div>
            </div>
            <nav class="chrome-nav">
                <a href="admin.php" class="nav-btn <?= $view === 'main' ? 'active' : '' ?>">
                    <span>■</span><span class="nav-label">FŐOLDAL</span>
                </a>
                <a href="admin.php?view=reports" class="nav-btn <?= $view === 'reports' ? 'active' : '' ?>">
                    <span>⚠</span><span class="nav-label">REPORTOK</span>
                    <?php if ($counts['reports'] > 0): ?><span class="nav-badge"><?= $counts['reports'] ?></span><?php endif; ?>
                </a>
                <a href="admin.php?view=users" class="nav-btn <?= $view === 'users' ? 'active' : '' ?>">
                    <span>◈</span><span class="nav-label">FELHASZNÁLÓK</span>
                    <span class="nav-badge"><?= $counts['users'] ?></span>
                </a>
                <a href="admin.php?view=items" class="nav-btn <?= $view === 'items' ? 'active' : '' ?>">
                    <span>◧</span><span class="nav-label">TERMÉKEK</span>
                    <span class="nav-badge"><?= $counts['items'] ?></span>
                </a>
                <a href="admin.php?view=conversations" class="nav-btn <?= $view === 'conversations' ? 'active' : '' ?>">
                    <span class="nav-label">BESZÉLGETÉSEK</span>
                </a>
                <a href="admin.php?view=orders" class="nav-btn <?= $view === 'orders' ? 'active' : '' ?>">
                    <span class="nav-label">RENDELÉSEK</span>
                </a>
                <button class="nav-btn purge-btn" id="purgeBtn">⚠ VIZSGAPURGE</button>
                <a href="main.php" class="nav-btn nav-back">← KILÉPÉS</a>
            </nav>
        </div>
        <!-- BODY -->
        <div class="terminal-body">
            <?php if ($message): ?>
                <div class="banner banner-ok"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="banner banner-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <!-- ════════════ EDIT ITEM ════════════ -->
            <?php if ($editItem && $view === 'items'): ?>
                <div class="edit-terminal">
                    <div class="edit-terminal-header">TERMÉK SZERKESZTÉSE // ID: <?= htmlspecialchars($editItem['id']) ?></div>
                    <div class="edit-terminal-body">
                        <form method="post">
                            <input type="hidden" name="item_id" value="<?= $editItem['id'] ?>">
                            <div class="field-row">
                                <label class="field-label">Cím</label>
                                <input type="text" name="item_title" class="field-input" value="<?= htmlspecialchars($editItem['title']) ?>" required>
                            </div>
                            <div class="field-row">
                                <label class="field-label">Leírás</label>
                                <textarea name="item_description" class="field-textarea" required><?= htmlspecialchars($editItem['description']) ?></textarea>
                            </div>
                            <div class="field-row">
                                <label class="field-label">Ár (Ft)</label>
                                <input type="number" name="item_price" class="field-input" value="<?= $editItem['price'] ?>" min="0" step="1" required>
                            </div>
                            <div class="edit-actions">
                                <button type="submit" name="update_item" class="btn-save">[ MENTÉS ]</button>
                                <a href="admin.php?view=items&page=<?= $page ?>" class="btn-cancel">[ MÉGSE ]</a>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- ════════════ EDIT USER ════════════ -->
            <?php elseif ($editUser && $view === 'users'): ?>
                <div class="edit-terminal">
                    <div class="edit-terminal-header">FELHASZNÁLÓ SZERKESZTÉSE // ID: <?= htmlspecialchars($editUser['id']) ?></div>
                    <div class="edit-terminal-body">
                        <form method="post">
                            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                            <div class="field-row">
                                <label class="field-label">Felhasználónév</label>
                                <input type="text" name="username" class="field-input" value="<?= htmlspecialchars($editUser['username']) ?>" required>
                            </div>
                            <div class="field-row">
                                <label class="field-label">Email</label>
                                <input type="email" name="email" class="field-input" value="<?= htmlspecialchars($editUser['email']) ?>" required>
                            </div>
                            <div class="edit-actions">
                                <button type="submit" name="update_user" class="btn-save">[ MENTÉS ]</button>
                                <a href="admin.php?view=users&page=<?= $page ?>" class="btn-cancel">[ MÉGSE ]</a>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- ════════════ DASHBOARD ════════════ -->
            <?php elseif ($view === 'main'): ?>
                <div class="dash-grid">
                    <?php foreach (['REPORTOK' => 'reports', 'FELHASZNÁLÓK' => 'users', 'TERMÉKEK' => 'items', 'RENDELÉSEK' => 'orders'] as $label => $key): ?>
                        <a href="admin.php?view=<?= $key ?>" style="text-decoration:none">
                            <div class="dash-card">
                                <div class="dash-label"><?= match ($key) {
                                                            'reports' => '⚠',
                                                            'users' => '◈',
                                                            'items' => '◧',
                                                            'orders' => '📦'
                                                        } ?> <?= $label ?></div>
                                <div class="dash-number"><?= number_format($counts[$key]) ?></div>
                                <div class="dash-sublabel"><?= match ($key) {
                                                                'reports' => 'Bejelentett hirdetések',
                                                                'users' => 'Regisztrált fiókok',
                                                                'items' => 'Aktív hirdetések',
                                                                'orders' => 'Megrendelések'
                                                            } ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="sys-info">
                    <p>SYSTEM STATUS: <strong>ONLINE</strong></p>
                    <p>DATABASE: <strong>CUCIDB</strong> // HOST: <strong>LOCALHOST</strong></p>
                    <p>SESSION: <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong> // ADMIN ACCESS: <strong>GRANTED</strong></p>
                    <p>TIMESTAMP: <strong><?= date('Y-m-d H:i:s') ?></strong></p>
                </div>
                <!-- ════════════ REPORTS ════════════ -->
            <?php elseif ($view === 'reports'): ?>
                <div class="section-header">
                    <h2>REPORTOK</h2>
                    <span class="record-count">TOTAL: <?= $counts['reports'] ?> RECORD</span>
                </div>
                <?php if (empty($reports)): ?>
                    <div class="empty-state">NINCS ADAT</div>
                <?php else: ?>
                    <div class="data-panel">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>TÍPUS</th>
                                    <th>TÁRGY</th>
                                    <th>BEJELENTŐ</th>
                                    <th>ÉRINTETT</th>
                                    <th>INDOK</th>
                                    <th>DÁTUM</th>
                                    <th>OPS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $r): ?>
                                    <tr>
                                        <td class="mono"><?= $r['id'] ?></td>
                                        <td>
                                            <?php if ($r['report_type'] === 'item'): ?>
                                                <span style="color:var(--c-amber);letter-spacing:1px;">◧ TERMÉK</span>
                                            <?php else: ?>
                                                <span style="color:var(--c-green-mid);letter-spacing:1px;">✉ ÜZENET</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['report_type'] === 'item'): ?>
                                                <button class="view-item-btn" data-item-id="<?= htmlspecialchars($r['ref_id']) ?>"><?= htmlspecialchars($r['ref_title']) ?></button>
                                            <?php else: ?>
                                                <span style="color:var(--c-text);font-size:0.8rem;"><?= htmlspecialchars($r['ref_title']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['reporter_name']) ?></td>
                                        <td><?= htmlspecialchars($r['target_name']) ?></td>
                                        <td class="wrap"><?= htmlspecialchars($r['reason']) ?></td>
                                        <td class="mono"><?= date('Y-m-d', strtotime($r['created_at'])) ?></td>
                                        <td>
                                            <?php if ($r['report_type'] === 'item'): ?>
                                                <a href="admin.php?view=items&id=<?= $r['ref_id'] ?>" class="act act-view">TERMÉK</a>
                                            <?php endif; ?>
                                            <button class="act act-del" onclick="confirmThenPost('delete_report', { report_id: <?= $r['id'] ?>, report_type: '<?= $r['report_type'] ?>' })">TÖRL</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <!-- ════════════ USERS ════════════ -->
            <?php elseif ($view === 'users'): ?>
                <div class="section-header">
                    <h2>FELHASZNÁLÓK</h2>
                    <span class="record-count">TOTAL: <?= $counts['users'] ?> RECORD</span>
                </div>
                <?php if (empty($users)): ?>
                    <div class="empty-state">NINCS ADAT</div>
                <?php else: ?>
                    <div class="data-panel">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>USERNAME</th>
                                    <th>EMAIL</th>
                                    <th>ADMIN</th>
                                    <th>ITEMS</th>
                                    <th>REGDÁTUM</th>
                                    <th>OPS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td class="mono"><?= $u['id'] ?></td>
                                        <td><?= htmlspecialchars($u['username']) ?></td>
                                        <td class="mono"><?= htmlspecialchars($u['email']) ?></td>
                                        <td><?= $u['is_admin'] ? '<span style="color:var(--c-amber)">■ ADMIN</span>' : '<span style="color:var(--c-muted)">○ USER</span>' ?></td>
                                        <td><?= $u['item_count'] ?></td>
                                        <td class="mono"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                                        <td>
                                            <a href="admin.php?view=users&id=<?= $u['id'] ?>&page=<?= $page ?>" class="act act-edit">EDIT</a>
                                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                <button class="act act-del" onclick="confirmThenPost('delete_user', { user_id: <?= $u['id'] ?> })">TÖRL</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <!-- ════════════ ITEMS ════════════ -->
            <?php elseif ($view === 'items'): ?>
                <div class="section-header">
                    <h2>TERMÉKEK</h2>
                    <span class="record-count">TOTAL: <?= $counts['items'] ?> RECORD</span>
                </div>
                <?php if (empty($items)): ?>
                    <div class="empty-state">NINCS ADAT</div>
                <?php else: ?>
                    <div class="data-panel">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>CÍM</th>
                                    <th>ELADÓ</th>
                                    <th>ÁR</th>
                                    <th>LEÍRÁS</th>
                                    <th>DÁTUM</th>
                                    <th>OPS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr>
                                        <td class="mono"><?= $it['id'] ?></td>
                                        <td>
                                            <button class="view-item-btn" data-item-id="<?= $it['id'] ?>">
                                                <?= htmlspecialchars($it['title']) ?>
                                            </button>
                                        </td>
                                        <td><?= htmlspecialchars($it['seller_name']) ?></td>
                                        <td class="mono"><?= number_format($it['price'], 0, ',', ' ') ?> FT</td>
                                        <td class="wrap"><?= htmlspecialchars(mb_substr($it['description'], 0, 50)) ?>...</td>
                                        <td class="mono"><?= date('Y-m-d', strtotime($it['created_at'])) ?></td>
                                        <td>
                                            <a href="admin.php?view=items&id=<?= $it['id'] ?>&page=<?= $page ?>" class="act act-edit">EDIT</a>
                                            <button class="act act-del" onclick="confirmThenPost('delete_item', { item_id: '<?= $it['id'] ?>' })">TÖRL</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <!-- ════════════ CONVERSATIONS ════════════ -->
            <?php elseif ($view === 'conversations'): ?>
                <div class="section-header">
                    <h2>BESZÉLGETÉSEK</h2>
                    <span class="record-count">TOTAL: <?= count($conversations) ?> CONVERSATION(S)</span>
                </div>
                <div class="conversations-container">
                    <div class="conversation-sidebar" id="conversationSidebar">
                        <?php foreach ($conversations as $conv): ?>
                            <?php $isActive = ($selectedUser1 == $conv['user1_id'] && $selectedUser2 == $conv['user2_id']); ?>
                            <?php if ($isActive): ?>
                                <div class="conversation-item active">
                                    <div class="conv-avatars">
                                        <span><?= strtoupper(substr($conv['user1_name'], 0, 1)) ?></span>
                                        <span><?= strtoupper(substr($conv['user2_name'], 0, 1)) ?></span>
                                    </div>
                                    <div class="conv-info">
                                        <div class="conv-names"><?= htmlspecialchars($conv['user1_name']) ?> ↔ <?= htmlspecialchars($conv['user2_name']) ?></div>
                                        <div class="conv-lastmsg"><?= htmlspecialchars(mb_substr($conv['last_message'] ?? '', 0, 30)) ?></div>
                                        <div class="conv-time"><?= date('Y-m-d H:i', strtotime($conv['last_message_at'])) ?></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="admin.php?view=conversations&user1=<?= $conv['user1_id'] ?>&user2=<?= $conv['user2_id'] ?>"
                                    class="conversation-item">
                                    <div class="conv-avatars">
                                        <span><?= strtoupper(substr($conv['user1_name'], 0, 1)) ?></span>
                                        <span><?= strtoupper(substr($conv['user2_name'], 0, 1)) ?></span>
                                    </div>
                                    <div class="conv-info">
                                        <div class="conv-names"><?= htmlspecialchars($conv['user1_name']) ?> ↔ <?= htmlspecialchars($conv['user2_name']) ?></div>
                                        <div class="conv-lastmsg"><?= htmlspecialchars(mb_substr($conv['last_message'] ?? '', 0, 30)) ?></div>
                                        <div class="conv-time"><?= date('Y-m-d H:i', strtotime($conv['last_message_at'])) ?></div>
                                    </div>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="conversation-chat">
                        <?php if ($selectedUser1 && $selectedUser2): ?>
                            <div class="chat-header">
                                <span><?= htmlspecialchars($user1Name) ?> ↔ <?= htmlspecialchars($user2Name) ?></span>
                            </div>
                            <div class="chat-messages">
                                <?php foreach ($messages as $msg): ?>
                                    <div class="message-row <?= $msg['sender_id'] == $selectedUser1 ? 'left' : 'right' ?>">
                                        <div class="message-bubble">
                                            <div class="message-sender"><?= htmlspecialchars($msg['sender_name']) ?></div>
                                            <div class="message-text"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                                            <div class="message-time"><?= date('H:i', strtotime($msg['sent_at'])) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="chat-placeholder">[ VÁLASSZ KI EGY BESZÉLGETÉST A BAL OLDALI LISTÁBÓL ]</div>
                        <?php endif; ?>
                    </div>
                </div>
            <!-- ════════════ ORDERS ════════════ -->
            <?php elseif ($view === 'orders'): ?>
                <div class="section-header">
                    <h2>RENDELÉSEK</h2>
                    <span class="record-count">TOTAL: <?= $counts['orders'] ?? 0 ?> RECORD</span>
                </div>
                <?php if (empty($orders)): ?>
                    <div class="empty-state">NINCS ADAT</div>
                <?php else: ?>
                    <div class="data-panel">
                        <table class="data-table" style="min-width: 1000px;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>TERMÉK</th>
                                    <th>VEVŐ</th>
                                    <th>ELADÓ</th>
                                    <th>ÖSSZEG</th>
                                    <th>ÁLLAPOT</th>
                                    <th>FIZETÉS</th>
                                    <th>DÁTUM</th>
                                    <th>MŰVELETEK</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td class="mono"><?= htmlspecialchars($o['id']) ?></td>
                                        <td>
                                            <a href="#" onclick="fetchItemDetailsAndOpen('<?= htmlspecialchars($o['item_id']) ?>'); return false;" style="color: var(--c-green-mid); text-decoration: underline;">
                                                <?= htmlspecialchars($o['item_title']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="#" onclick="openUserProfile(<?= $o['buyer_id'] ?>); return false;" style="color: var(--c-green-mid); text-decoration: underline;">
                                                <?= htmlspecialchars($o['buyer_name']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="#" onclick="openUserProfile(<?= $o['seller_id'] ?>); return false;" style="color: var(--c-green-mid); text-decoration: underline;">
                                                <?= htmlspecialchars($o['seller_name']) ?>
                                            </a>
                                        </td>
                                        <td class="mono"><?= number_format($o['item_price'], 0, ',', ' ') ?> FT</td>
                                        <td><?= htmlspecialchars($o['status']) ?></td>
                                        <td><?= htmlspecialchars($o['payment_method']) ?></td>
                                        <td class="mono"><?= date('Y-m-d', strtotime($o['created_at'])) ?></td>
                                        <td>
                                            <button class="act act-view" onclick="toggleOrderDetails('<?= htmlspecialchars($o['id']) ?>')">INFO</button>
                                            <button class="act act-del" onclick="confirmThenPost('delete_order', { order_id: '<?= htmlspecialchars($o['id']) ?>' })">TÖRL</button>
                                        </td>
                                    </tr>
                                    <tr class="order-details-row" id="details-<?= htmlspecialchars($o['id']) ?>" style="display: none;">
                                        <td colspan="9">
                                            <div class="order-details-grid">
                                                <div>
                                                    <strong>Címzett:</strong> <?= htmlspecialchars($o['shipping_name']) ?><br>
                                                    <strong>Email:</strong> <?= htmlspecialchars($o['shipping_email']) ?><br>
                                                    <strong>Telefon:</strong> <?= htmlspecialchars($o['shipping_phone']) ?>
                                                </div>
                                                <div>
                                                    <strong>Cím:</strong> <?= htmlspecialchars($o['shipping_zip']) ?> <?= htmlspecialchars($o['shipping_city']) ?>, <?= htmlspecialchars($o['shipping_address']) ?><br>
                                                    <strong>Fizetési mód:</strong> <?= htmlspecialchars($o['payment_method']) ?><br>
                                                    <strong>Megjegyzés:</strong> <?= nl2br(htmlspecialchars($o['notes'] ?? '-')) ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <!-- LAPOZÁS -->
            <?php if ($totalPages > 1 && !in_array($view, ['main', 'conversations']) && !$editId): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= pgLink($view, $page - 1) ?>" class="pg-btn">◄ ELŐZŐ</a>
                    <?php else: ?>
                        <span class="pg-btn disabled">◄ ELŐZŐ</span>
                    <?php endif; ?>
                    <span class="pg-info"><?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= pgLink($view, $page + 1) ?>" class="pg-btn">KÖVETKEZŐ ►</a>
                    <?php else: ?>
                        <span class="pg-btn disabled">KÖVETKEZŐ ►</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div><!-- /terminal-body -->
    </div><!-- /crt-wrap -->

    <!-- TÖRLÉSI MEGERŐSÍTŐ MODAL -->
    <div class="confirm-modal-overlay" id="confirmModal">
        <div class="confirm-modal-card">
            <div class="confirm-modal-icon">⚠️</div>
            <div class="confirm-modal-title">MEGERŐSÍTÉS</div>
            <div class="confirm-modal-text" id="confirmModalText">Biztosan törlöd?</div>
            <div class="confirm-modal-actions">
                <button class="confirm-btn-no" id="confirmNoBtn">MÉGSE</button>
                <button class="confirm-btn-yes" id="confirmYesBtn">TÖRLÉS</button>
            </div>
        </div>
    </div>

    <!-- PURGE MODAL -->
    <div class="purge-modal-overlay" id="purgeModal">
        <div class="purge-modal-card">
            <div class="purge-modal-icon">⚠️⚠️⚠️</div>
            <div class="purge-modal-title">VIZSGAPURGE</div>
            <div class="purge-modal-text">
                <strong>FIGYELEM!</strong> Ez a művelet <strong>véglegesen törli</strong> az összes felhasználót,<br>
                akik <strong>nem</strong> a következők: <strong>gabi, martin, cuci, admin</strong>.<br>
                Törlődik továbbá az összes olyan hirdetés és a hozzájuk tartozó kép is,<br>
                amit nem ezek a felhasználók töltöttek fel.<br><br>
                Biztosan folytatod?
            </div>
            <div class="purge-modal-actions">
                <button class="purge-btn-cancel" id="purgeCancelBtn">Mégse</button>
                <form method="post" id="purgeForm" style="margin:0;">
                    <input type="hidden" name="purge_confirm" value="1">
                    <button type="submit" class="purge-btn-confirm">VÉGREHAJT</button>
                </form>
            </div>
        </div>
    </div>

    <!-- TERMÉKMODÁL -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <div class="product-modal-header">
                <div class="product-menu" id="productMenuContainer" style="display:none">
                    <button class="pm-btn" onclick="toggleProductMenu(this)">⋮</button>
                    <div class="product-menu-content" id="productMenuContent">
                        <button class="product-menu-item" id="productReportBtn">⚠ BEJELENTÉS</button>
                        <button class="product-menu-item" id="productEditBtn" style="display:none">✎ MÓDOSÍTÁS</button>
                        <button class="product-menu-item delete" id="productDeleteBtn" style="display:none">✕ TÖRLÉS</button>
                    </div>
                </div>
                <button class="pm-btn" id="closeProductModalBtn">✕</button>
            </div>
            <div class="product-gallery">
                <div class="product-main-image-container">
                    <img src="" alt="" class="product-main-image" id="productMainImage" style="display:none">
                    <div class="product-no-image-placeholder" id="productNoImagePlaceholder" style="display:none">[ NO IMAGE ]</div>
                    <button class="gallery-nav prev" id="galleryPrev">❮</button>
                    <button class="gallery-nav next" id="galleryNext">❯</button>
                </div>
                <div class="product-thumbnails" id="productThumbnails"></div>
            </div>
            <div class="product-details">
                <h2 class="product-title" id="productTitle"></h2>
                <div class="product-price" id="productPrice"></div>
                <div class="product-seller" id="productSeller"></div>
                <div class="product-date" id="productDate"></div>
                <div class="product-description" id="productDescription"></div>
                <button class="product-buy-btn" id="productBuyBtn">[ VÁSÁRLÁS ]</button>
            </div>
        </div>
    </div>
    <div class="lightbox-overlay" id="lightboxOverlay">
        <div class="lightbox-content">
            <img src="" alt="" class="lightbox-image" id="lightboxImage">
            <button class="lightbox-close" id="lightboxClose">✕</button>
        </div>
    </div>

    <!-- USER PROFILE POPUP -->
    <div class="user-popup-overlay" id="userProfilePopup">
        <div class="user-popup-card">
            <div class="user-popup-topbar">
                <div class="user-popup-topbar-title">👤 Felhasználói profil</div>
                <button class="user-popup-close" id="userPopupClose">✕</button>
            </div>
            <div class="user-popup-body" id="userPopupContent">
                <div style="text-align: center; padding: 4rem 2rem; color: rgba(255,255,255,0.3);">⏳ Betöltés...</div>
            </div>
        </div>
    </div>

    <script>
        // ── TÉMA ──
        (function() {
            const KEY = 'admin_theme',
                body = document.body,
                btn = document.getElementById('themeToggleBtn');

            function apply(t) {
                document.documentElement.classList.toggle('light-mode', t === 'light');
                localStorage.setItem(KEY, t);
                btn.textContent = t === 'light' ? 'DARK' : 'LIGHT';
            }
            apply(localStorage.getItem(KEY) || 'dark');
            btn.addEventListener('click', () => apply(document.documentElement.classList.contains('light-mode') ? 'dark' : 'light'));
        })();
        // ── ÓRA ──
        (function clock() {
            const el = document.getElementById('liveClock');
            if (el) {
                const n = new Date();
                el.textContent = [n.getHours(), n.getMinutes(), n.getSeconds()].map(x => String(x).padStart(2, '0')).join(':');
            }
            setTimeout(clock, 1000);
        })();
        // ── BANNEREK ELTÜNTETÉSE ──
        setTimeout(() => document.querySelectorAll('.banner').forEach(el => {
            el.style.opacity = '0';
            el.style.transition = 'opacity .5s';
            setTimeout(() => el.remove(), 500);
        }), 5000);

        // ── MEGERŐSÍTŐ MODAL ──
        const confirmModal = document.getElementById('confirmModal');
        const confirmModalText = document.getElementById('confirmModalText');
        const confirmYesBtn = document.getElementById('confirmYesBtn');
        const confirmNoBtn = document.getElementById('confirmNoBtn');
        let pendingConfirmAction = null;

        function openConfirmModal(message, action) {
            confirmModalText.textContent = message || 'Biztosan törlöd?';
            pendingConfirmAction = action;
            confirmModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeConfirmModal() {
            confirmModal.classList.remove('active');
            document.body.style.overflow = '';
            pendingConfirmAction = null;
        }

        confirmYesBtn.addEventListener('click', () => {
            if (pendingConfirmAction) {
                pendingConfirmAction();
            }
            closeConfirmModal();
        });
        confirmNoBtn.addEventListener('click', closeConfirmModal);
        confirmModal.addEventListener('click', (e) => {
            if (e.target === confirmModal) closeConfirmModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && confirmModal.classList.contains('active')) closeConfirmModal();
        });

        // Általános törlés megerősítéssel
        function confirmThenPost(type, data) {
            const messages = {
                'delete_user': 'Biztosan törlöd ezt a felhasználót? Minden adata elvész!',
                'delete_item': 'Biztosan törlöd ezt a terméket?',
                'delete_report': 'Biztosan törlöd ezt a reportot?',
                'delete_order': 'Biztosan törlöd ezt a rendelést?'
            };
            openConfirmModal(messages[type] || 'Biztosan törlöd?', () => {
                let body = '';
                if (type === 'delete_user') body = 'delete_user=1&user_id=' + data.user_id;
                else if (type === 'delete_item') body = 'delete_item=1&item_id=' + data.item_id;
                else if (type === 'delete_report') body = 'delete_report=1&report_id=' + data.report_id + '&report_type=' + (data.report_type || 'item');
                else if (type === 'delete_order') body = 'delete_order=1&order_id=' + data.order_id;
                fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(() => location.reload());
            });
        }

        // Order details toggle
        function toggleOrderDetails(orderId) {
            const row = document.getElementById('details-' + orderId);
            if (row) {
                row.style.display = row.style.display === 'none' ? '' : 'none';
            }
        }

        // ── PURGE MODAL ──
        const purgeModal = document.getElementById('purgeModal');
        const purgeBtn = document.getElementById('purgeBtn');
        const purgeCancelBtn = document.getElementById('purgeCancelBtn');

        function openPurgeModal() {
            purgeModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePurgeModal() {
            purgeModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        purgeBtn.addEventListener('click', openPurgeModal);
        purgeCancelBtn.addEventListener('click', closePurgeModal);
        purgeModal.addEventListener('click', (e) => {
            if (e.target === purgeModal) closePurgeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (purgeModal.classList.contains('active')) closePurgeModal();
                else if (confirmModal.classList.contains('active')) closeConfirmModal();
                else if (pm.lbOver.classList.contains('active')) pm.lbOver.classList.remove('active');
                else if (pm.modal.classList.contains('active')) closePM();
                else if (userPopup.classList.contains('active')) closeUserProfile();
            }
        });

        // ── USER PROFILE POPUP ──
        const userPopup = document.getElementById('userProfilePopup');
        const userPopupContent = document.getElementById('userPopupContent');
        const userPopupClose = document.getElementById('userPopupClose');

        function openUserProfile(userId) {
            userPopupContent.innerHTML = '<div style="text-align: center; padding: 4rem 2rem; color: rgba(255,255,255,0.3);">⏳ Betöltés...</div>';
            userPopup.style.display = 'flex';
            userPopup.offsetHeight;
            userPopup.classList.add('active');
            document.body.style.overflow = 'hidden';

            fetch('?get_user_profile=' + userId)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        userPopupContent.innerHTML = '<p style="color:red;text-align:center;padding:2rem;">' + escapeHtml(data.error) + '</p>';
                        return;
                    }
                    const memberSince = data.created_at ? data.created_at.substring(0, 10) : '—';
                    const initial = data.username ? data.username.charAt(0).toUpperCase() : '?';

                    let avatarHtml;
                    if (data.profile_picture && data.profile_picture.trim() !== '') {
                        avatarHtml = `<img src="${escapeHtml(data.profile_picture)}" alt="${escapeHtml(data.username)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
                    } else {
                        avatarHtml = initial;
                    }

                    let itemsHtml = '';
                    if (data.items && data.items.length > 0) {
                        itemsHtml = `<div class="user-popup-items-title">Termékek (${data.item_count})</div>
                        <div class="user-popup-items-grid">`;
                        data.items.forEach(item => {
                            const soldClass = item.sold == 1 ? ' sold' : '';
                            const soldBadge = item.sold == 1 ? '<div class="user-item-sold-badge">[ELKELT]</div>' : '';
                            const imgHtml = item.thumb ?
                                `<img src="${escapeHtml(item.thumb)}" alt="${escapeHtml(item.title)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="user-item-thumb-placeholder" style="display:none;">📷</div>` :
                                `<div class="user-item-thumb-placeholder" style="display: flex;">📷</div>`;
                            itemsHtml += `
                                <div class="user-item-thumb${soldClass}" onclick="fetchItemDetailsAndOpen('${escapeHtml(item.id)}');">
                                    ${imgHtml}
                                    ${soldBadge}
                                    <div class="user-item-info">
                                        <div class="user-item-title">${escapeHtml(item.title)}</div>
                                        <div class="user-item-price">${Number(item.price).toLocaleString('hu-HU')} Ft</div>
                                    </div>
                                </div>`;
                        });
                        itemsHtml += '</div>';
                    } else {
                        itemsHtml = '<div style="text-align:center;color:rgba(255,255,255,0.3);padding:2rem;">Nincsenek termékek.</div>';
                    }

                    userPopupContent.innerHTML = `
                        <div class="user-popup-avatar" style="display: flex; align-items: center; justify-content: center;">
                            ${avatarHtml}
                        </div>
                        <div class="user-popup-name">${escapeHtml(data.username)}</div>
                        <div class="user-popup-meta">
                            Email: ${escapeHtml(data.email)}<br>
                            Regisztráció: ${memberSince} &nbsp; | &nbsp; ID: ${data.id}
                        </div>
                        <div class="user-popup-stats">
                            <div class="user-stat">
                                <div class="user-stat-value">${data.item_count}</div>
                                <div class="user-stat-label">Hirdetés</div>
                            </div>
                        </div>
                        ${itemsHtml}
                    `;
                })
                .catch(() => {
                    userPopupContent.innerHTML = '<p style="color:red;text-align:center;padding:2rem;">Hálózati hiba.</p>';
                });
        }

        function closeUserProfile() {
            userPopup.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(() => {
                userPopup.style.display = 'none';
            }, 300);
        }

        userPopupClose.addEventListener('click', closeUserProfile);
        userPopup.addEventListener('click', e => {
            if (e.target === userPopup) closeUserProfile();
        });

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // ── TERMÉKMODÁL ──
        const pm = {
            modal: document.getElementById('productModal'),
            closeBtn: document.getElementById('closeProductModalBtn'),
            mainImg: document.getElementById('productMainImage'),
            noImg: document.getElementById('productNoImagePlaceholder'),
            title: document.getElementById('productTitle'),
            price: document.getElementById('productPrice'),
            seller: document.getElementById('productSeller'),
            date: document.getElementById('productDate'),
            desc: document.getElementById('productDescription'),
            thumbs: document.getElementById('productThumbnails'),
            prev: document.getElementById('galleryPrev'),
            next: document.getElementById('galleryNext'),
            lbOver: document.getElementById('lightboxOverlay'),
            lbImg: document.getElementById('lightboxImage'),
            lbClose: document.getElementById('lightboxClose'),
            menuCont: document.getElementById('productMenuContainer'),
            reportBtn: document.getElementById('productReportBtn'),
            editBtn: document.getElementById('productEditBtn'),
            delBtn: document.getElementById('productDeleteBtn'),
            buyBtn: document.getElementById('productBuyBtn'),
        };
        let imgs = [],
            imgIdx = 0,
            prodId = null,
            prodUid = null;

        function setImg(i) {
            if (i >= 0 && i < imgs.length) {
                pm.mainImg.style.display = 'block';
                pm.noImg.style.display = 'none';
                pm.mainImg.src = imgs[i];
                imgIdx = i;
                pm.mainImg.onload = adjustH;
                pm.mainImg.onerror = () => {
                    pm.mainImg.style.display = 'none';
                    pm.noImg.style.display = 'block';
                };
                document.querySelectorAll('.product-thumbnail').forEach((t, j) => t.classList.toggle('active', j === i));
            } else {
                pm.mainImg.style.display = 'none';
                pm.noImg.style.display = 'block';
            }
        }

        function adjustH() {
            const c = document.querySelector('.product-main-image-container'),
                g = document.querySelector('.product-gallery'),
                tb = document.querySelector('.product-thumbnails');
            if (c && g) {
                const avail = g.clientHeight - 32 - (tb ? tb.offsetHeight : 72) - 16;
                c.style.height = Math.max(200, Math.min(pm.mainImg.naturalHeight || avail, avail)) + 'px';
            }
        }

        function openPM() {
            pm.modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(adjustH, 100);
        }

        function closePM() {
            if (pm.lbOver.classList.contains('active')) pm.lbOver.classList.remove('active');
            pm.modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Fetch item details and open modal (user popup stays open)
        function fetchItemDetailsAndOpen(itemId) {
            fetch('admin.php?get_item_data=' + itemId)
                .then(r => r.json())
                .then(d => {
                    if (d.error) {
                        alert(d.error);
                        return;
                    }
                    prodId = d.id;
                    prodUid = d.user_id;
                    imgs = d.images;
                    imgIdx = 0;
                    pm.title.textContent = d.title;
                    pm.price.textContent = d.price;
                    pm.seller.innerHTML = 'Eladó: <strong>' + d.seller + '</strong>';
                    pm.date.textContent = d.date;
                    pm.desc.textContent = d.description;
                    pm.thumbs.innerHTML = '';
                    if (imgs.length) {
                        imgs.forEach((src, i) => {
                            const t = document.createElement('div');
                            t.className = 'product-thumbnail' + (i === 0 ? ' active' : '');
                            t.innerHTML = '<img src="' + src + '" alt="">';
                            t.onclick = e => {
                                e.stopPropagation();
                                setImg(i);
                            };
                            pm.thumbs.appendChild(t);
                        });
                        setImg(0);
                    } else setImg(-1);
                    pm.prev.classList.toggle('hidden', imgs.length < 2);
                    pm.next.classList.toggle('hidden', imgs.length < 2);

                    if (d.sold) {
                        pm.buyBtn.textContent = '[ ELKELT ]';
                        pm.buyBtn.classList.add('sold');
                        pm.buyBtn.disabled = true;
                    } else {
                        pm.buyBtn.textContent = '[ VÁSÁRLÁS ]';
                        pm.buyBtn.classList.remove('sold');
                        pm.buyBtn.disabled = false;
                    }

                    // Menu
                    <?php if (isset($_SESSION['user_id'])): ?>
                        const isOwner = parseInt(d.user_id) === <?= (int)$_SESSION['user_id'] ?>;
                        if (<?= $isAdmin ? 'true' : 'false' ?>) {
                            pm.menuCont.style.display = 'block';
                            pm.reportBtn.style.display = 'none';
                            pm.editBtn.style.display = 'block';
                            pm.delBtn.style.display = 'block';
                            pm.editBtn.onclick = () => location.href = 'admin.php?view=items&id=' + prodId;
                            pm.delBtn.onclick = () => {
                                confirmThenPost('delete_item', { item_id: prodId });
                            };
                        } else if (!isOwner) {
                            pm.menuCont.style.display = 'block';
                            pm.reportBtn.style.display = 'block';
                            pm.editBtn.style.display = 'none';
                            pm.delBtn.style.display = 'none';
                        } else pm.menuCont.style.display = 'none';
                    <?php endif; ?>
                    openPM();
                })
                .catch(() => alert('Betöltési hiba!'));
        }

        document.querySelectorAll('.view-item-btn').forEach(btn => btn.addEventListener('click', function(e) {
            e.preventDefault();
            fetchItemDetailsAndOpen(this.dataset.itemId);
        }));

        function toggleProductMenu(btn) {
            const m = btn.nextElementSibling;
            m.classList.toggle('show');
            document.querySelectorAll('.product-menu-content').forEach(x => {
                if (x !== m) x.classList.remove('show');
            });
        }
        pm.closeBtn.addEventListener('click', closePM);
        pm.modal.addEventListener('click', e => {
            if (e.target === pm.modal) closePM();
        });
        pm.prev.addEventListener('click', e => {
            e.stopPropagation();
            setImg(imgIdx - 1 >= 0 ? imgIdx - 1 : imgs.length - 1);
        });
        pm.next.addEventListener('click', e => {
            e.stopPropagation();
            setImg(imgIdx + 1 < imgs.length ? imgIdx + 1 : 0);
        });
        pm.mainImg.addEventListener('click', () => {
            if (pm.mainImg.src && pm.mainImg.style.display !== 'none') {
                pm.lbImg.src = pm.mainImg.src;
                pm.lbOver.classList.add('active');
            }
        });
        pm.lbClose.addEventListener('click', () => pm.lbOver.classList.remove('active'));
        pm.lbOver.addEventListener('click', e => {
            if (e.target === pm.lbOver) pm.lbOver.classList.remove('active');
        });
        window.addEventListener('resize', () => {
            if (pm.modal.classList.contains('active')) adjustH();
        });
        pm.buyBtn.addEventListener('click', () => {
            if (!pm.buyBtn.classList.contains('sold')) {
                alert('Vásárlás funkció admin felületről nem elérhető.');
            }
        });

        // ========== SCROLL MEGŐRZÉSE A SIDEBARBAN ==========
        (function() {
            const sidebar = document.getElementById('conversationSidebar');
            if (!sidebar) return;

            const savedScroll = sessionStorage.getItem('convSidebarScroll');
            if (savedScroll !== null) {
                sidebar.scrollTop = parseInt(savedScroll, 10);
                sessionStorage.removeItem('convSidebarScroll');
            }

            sidebar.addEventListener('click', function(e) {
                const link = e.target.closest('.conversation-item');
                if (link && link.tagName === 'A') {
                    sessionStorage.setItem('convSidebarScroll', sidebar.scrollTop);
                }
            });
        })();
    </script>
</body>

</html>