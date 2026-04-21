<?php
session_start();

// Kijelentkezés kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "cucidb";

$currentUserId = (int)$_SESSION['user_id'];

function generateMessageId(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < 25; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ================================
    // AJAX – Új üzenetek lekérése (polling) IDŐBÉLYEG ALAPJÁN
    // ================================
    if (isset($_GET['ajax_get_messages']) && isset($_GET['with']) && isset($_GET['last_timestamp'])) {
        header('Content-Type: application/json');
        $partnerId = (int)$_GET['with'];
        $lastTimestamp = $_GET['last_timestamp'];

        // A fogadott üzenetek olvasottá jelölése
        $updateRead = $conn->prepare("
            UPDATE uzenetek SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $updateRead->execute([$partnerId, $currentUserId]);

        // Új üzenetek a megadott időpont után
        $stmt = $conn->prepare("
            SELECT id, sender_id, receiver_id, message, sent_at, is_read
            FROM uzenetek
            WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
              AND sent_at > ?
            ORDER BY sent_at ASC
        ");
        $stmt->execute([$currentUserId, $partnerId, $partnerId, $currentUserId, $lastTimestamp]);
        $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['messages' => $newMessages]);
        exit;
    }

    // ================================
    // AJAX – Üzenet küldése (nem redirect)
    // ================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message_ajax'])) {
        header('Content-Type: application/json');
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');

        $success = false;
        $error   = '';
        $newMsgId = null;
        $newRow   = null;
        if ($receiverId > 0 && $receiverId !== $currentUserId && $message !== '') {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $chk->execute([$receiverId]);
            if ($chk->fetch()) {
                do {
                    $newMsgId = generateMessageId();
                    $idChk = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE id = ?");
                    $idChk->execute([$newMsgId]);
                } while ($idChk->fetchColumn() > 0);

                $ins = $conn->prepare("INSERT INTO uzenetek (id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
                $ins->execute([$newMsgId, $currentUserId, $receiverId, $message]);
                // Visszaadjuk a valós ID-t és sent_at-et, hogy a kliens ne duplikáljon
                $fetchNew = $conn->prepare("SELECT sent_at FROM uzenetek WHERE id = ?");
                $fetchNew->execute([$newMsgId]);
                $newRow = $fetchNew->fetch(PDO::FETCH_ASSOC);
                $success = true;
            } else {
                $error = 'Címzett nem található.';
            }
        } else {
            $error = 'Érvénytelen adatok.';
        }

        echo json_encode([
            'success' => $success,
            'error'   => $error,
            'msg_id'  => $success ? $newMsgId : null,
            'sent_at' => $success ? $newRow['sent_at'] : null,
        ]);
        exit;
    }

    // ================================
    // Hagyományos POST – szerkesztés / törlés / report
    // ================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_message'])) {
        $msgId      = $_POST['message_id'] ?? '';
        $newText    = trim($_POST['new_message'] ?? '');
        if ($msgId && $newText !== '') {
            $editStmt = $conn->prepare("UPDATE uzenetek SET message = ? WHERE id = ? AND sender_id = ?");
            $editStmt->execute([$newText, $msgId, $currentUserId]);
        }
        $with = isset($_GET['with']) ? (int)$_GET['with'] : 0;
        header("Location: uzenetek.php?with=" . $with);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
        $msgId = $_POST['message_id'] ?? '';
        if ($msgId) {
            $delStmt = $conn->prepare("DELETE FROM uzenetek WHERE id = ? AND sender_id = ?");
            $delStmt->execute([$msgId, $currentUserId]);
        }
        $with = isset($_GET['with']) ? (int)$_GET['with'] : 0;
        header("Location: uzenetek.php?with=" . $with);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_message'])) {
        $msgId   = $_POST['message_id'] ?? '';
        $reason  = trim($_POST['report_reason'] ?? '');
        if ($msgId && $reason) {
            $check = $conn->prepare("SELECT receiver_id FROM uzenetek WHERE id = ?");
            $check->execute([$msgId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['receiver_id'] === $currentUserId) {
                $reportStmt = $conn->prepare("
                    INSERT INTO message_reports (message_id, reporter_user_id, reason, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $reportStmt->execute([$msgId, $currentUserId, $reason]);
                $_SESSION['report_success'] = true;
            }
        }
        $with = isset($_GET['with']) ? (int)$_GET['with'] : 0;
        header("Location: uzenetek.php?with=" . $with);
        exit();
    }

    // ================================
    // Normál oldalbetöltés – adatok lekérése
    // ================================
    $withUserId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
    if ($withUserId > 0) {
        $markRead = $conn->prepare("
            UPDATE uzenetek SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $markRead->execute([$withUserId, $currentUserId]);
    }

    $partnersStmt = $conn->prepare("
        SELECT
            u.id,
            u.username,
            u.created_at AS member_since,
            MAX(m.sent_at) AS last_message_at,
            SUM(CASE WHEN m.receiver_id = :me AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM users u
        JOIN uzenetek m ON (
            (m.sender_id = u.id AND m.receiver_id = :me2)
            OR
            (m.receiver_id = u.id AND m.sender_id = :me3)
        )
        WHERE u.id != :me4
        GROUP BY u.id, u.username, u.created_at
        ORDER BY last_message_at DESC
    ");
    $partnersStmt->execute([
        ':me'  => $currentUserId,
        ':me2' => $currentUserId,
        ':me3' => $currentUserId,
        ':me4' => $currentUserId,
    ]);
    $partners = $partnersStmt->fetchAll(PDO::FETCH_ASSOC);

    $messages    = [];
    $withUser    = null;
    if ($withUserId > 0) {
        $userStmt = $conn->prepare("SELECT id, username, created_at FROM users WHERE id = ?");
        $userStmt->execute([$withUserId]);
        $withUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($withUser) {
            $msgStmt = $conn->prepare("
                SELECT id, sender_id, receiver_id, message, sent_at, is_read
                FROM uzenetek
                WHERE (sender_id = :me AND receiver_id = :other)
                   OR (sender_id = :other2 AND receiver_id = :me2)
                ORDER BY sent_at ASC
            ");
            $msgStmt->execute([
                ':me'    => $currentUserId,
                ':other' => $withUserId,
                ':other2'=> $withUserId,
                ':me2'   => $currentUserId,
            ]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $unreadStmt = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE receiver_id = ? AND is_read = 0");
    $unreadStmt->execute([$currentUserId]);
    $totalUnread = (int)$unreadStmt->fetchColumn();

    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$currentUserId]);
    $isAdmin = $adminCheck->fetchColumn() > 0;

    // Seller popup adatok lekérése AJAX kérésre (get_seller)
    if (isset($_GET['get_seller']) && !empty($_GET['get_seller'])) {
        header('Content-Type: application/json');
        $sellerId = (int)$_GET['get_seller'];
        $sellerStmt = $conn->prepare("
            SELECT u.id, u.username, u.created_at,
                   COUNT(DISTINCT i.id) AS item_count,
                   (SELECT COUNT(*) FROM admins WHERE user_id = u.id) AS is_admin
            FROM users u
            LEFT JOIN items i ON i.user_id = u.id
            WHERE u.id = ?
            GROUP BY u.id, u.username, u.created_at
        ");
        $sellerStmt->execute([$sellerId]);
        $seller = $sellerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$seller) {
            echo json_encode(['error' => 'Felhasználó nem található']);
            exit;
        }
        $latestStmt = $conn->prepare("
            SELECT i.id, i.title, i.price,
                   (SELECT image_path FROM item_images WHERE item_id = i.id AND is_primary = 1 LIMIT 1) as thumb
            FROM items i
            WHERE i.user_id = ?
            ORDER BY i.created_at DESC
            LIMIT 4
        ");
        $latestStmt->execute([$sellerId]);
        $seller['latest_items'] = $latestStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($seller);
        exit;
    }

    // ================================
    // AJAX – Get item details (for product modal)
    // ================================
    if (isset($_GET['get_item']) && !empty($_GET['get_item'])) {
        header('Content-Type: application/json');
        $itemId = $_GET['get_item'];
        
        $stmt = $conn->prepare("
            SELECT i.id, i.title, i.description, i.price, i.created_at, u.username as seller_name, i.user_id
            FROM items i
            JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            echo json_encode(['error' => 'Termék nem található']);
            exit;
        }
        
        $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY sort_order");
        $imgStmt->execute([$itemId]);
        $item['images'] = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode($item);
        exit;
    }

} catch (PDOException $e) {
    die("DB hiba: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üzenetek – Valós idejű</title>
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --accent: #ff8c00;
            --accent-glow: rgba(255,140,0,0.3);
            --accent-gradient: linear-gradient(135deg, #ff8c00, #ff5500);
            --bg-glass: rgba(0,0,0,0.7);
            --border-glass: rgba(255,140,0,0.2);
            --text-primary: #ffffff;
            --bg-sidebar: rgba(5,5,5,0.8);
            --bg-chat: rgba(8,8,8,0.95);
            --bg-header: rgba(5,5,5,0.8);
            --bg-input: rgba(255,255,255,0.05);
            --msg-sent: linear-gradient(135deg, rgba(255,140,0,0.85), rgba(200,80,0,0.85));
            --msg-received-bg: rgba(30,30,30,0.85);
            --msg-received-border: rgba(255,140,0,0.15);
            --msg-received-color: #ffffff;
            --partner-time: rgba(255,255,255,0.4);
            --avatar-bg: linear-gradient(135deg, #ff8c00, #ff5500);
            --avatar-color: #000;
        }

        body[data-theme="light"] {
            --accent: #7a9200;
            --accent-glow: rgba(122,146,0,0.3);
            --accent-gradient: linear-gradient(135deg, #B0CB1F, #8aA000);
            --bg-glass: rgba(240,240,235,0.9);
            --border-glass: rgba(122,146,0,0.3);
            --text-primary: #1a1f00;
            --bg-sidebar: rgba(240,240,235,0.9);
            --bg-chat: rgba(250,250,248,0.98);
            --bg-header: rgba(240,240,238,0.9);
            --bg-input: rgba(0,0,0,0.05);
            --msg-sent: linear-gradient(135deg, #B0CB1F, #8aA000);
            --msg-received-bg: rgba(220,220,215,0.85);
            --msg-received-border: rgba(122,146,0,0.3);
            --msg-received-color: #1a1f00;
            --partner-time: rgba(0,0,0,0.4);
            --avatar-bg: linear-gradient(135deg, #B0CB1F, #8aA000);
            --avatar-color: #1a1f00;
            background: #f5f5f0;
        }

        body {
            min-height: 100vh;
            background: #0a0a0a;
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            user-select: none;
        }

        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.6rem 1.2rem;
            background: var(--bg-glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-glass);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 1rem;
            border: 1px solid var(--border-glass);
            border-radius: 50px;
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            color: var(--accent);
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .back-btn:hover {
            background: color-mix(in srgb, var(--accent) 25%, transparent);
            border-color: var(--accent);
        }

        .page-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            flex: 1;
            margin-left: 1rem;
        }

        .account-menu {
            position: relative;
            display: inline-block;
            pointer-events: auto;
        }

        .account-summary {
            list-style: none;
            cursor: pointer;
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-glass);
            border-radius: 50px;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            color: var(--accent);
            font-size: 0.9rem;
            white-space: nowrap;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            user-select: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .account-summary:hover {
            background: rgba(255, 140, 0, 0.1);
            border-color: var(--accent);
        }

        .account-summary::-webkit-details-marker {
            display: none;
        }

        .account-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 250px;
            background: #000;
            backdrop-filter: blur(24px);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            padding: 0.75rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 20px rgba(255,140,0,0.2);
            z-index: 1001;
            animation: dropdownFade 0.2s ease;
        }

        /* Light mode account dropdown */
        body[data-theme="light"] .account-dropdown {
            background: #f0f5e0 !important;
            border-color: rgba(122,146,0,0.4) !important;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1), 0 0 20px rgba(176,203,31,0.2) !important;
        }

        body[data-theme="light"] .account-summary {
            background: rgba(240, 252, 200, 0.85) !important;
            border-color: rgba(122,146,0,0.5) !important;
            color: #7a9200 !important;
        }

        body[data-theme="light"] .account-summary:hover {
            background: rgba(176, 203, 31, 0.2) !important;
            border-color: #B0CB1F !important;
        }

        body[data-theme="light"] .user-info strong {
            color: #4a6000 !important;
        }

        body[data-theme="light"] .dropdown-divider {
            background: linear-gradient(90deg, transparent, #B0CB1F, transparent) !important;
        }

        body[data-theme="light"] .logout-button span {
            color: #2a3a00 !important;
        }

        body[data-theme="light"] .logout-button span:hover {
            background: rgba(176, 203, 31, 0.15) !important;
            color: #7a9200 !important;
        }

        body[data-theme="light"] .theme-toggle-row {
            color: #1a1f00 !important;
        }

        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .user-info {
            color: var(--text-primary);
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
        }

        .user-info strong {
            display: block;
            word-wrap: break-word;
            color: var(--accent);
        }

        .dropdown-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            margin: 0.5rem 0;
        }

        .theme-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .theme-toggle-label {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            opacity: 0.8;
        }

        .theme-switch {
            position: relative;
            width: 42px;
            height: 24px;
            flex-shrink: 0;
        }

        .theme-switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .theme-switch-track {
            position: absolute;
            inset: 0;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: background 0.3s, border-color 0.3s;
            cursor: pointer;
        }

        .theme-switch input:checked + .theme-switch-track {
            background: rgba(176, 203, 31, 0.25);
            border-color: #B0CB1F;
        }

        .theme-switch-thumb {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transition: transform 0.3s, background 0.3s;
            pointer-events: none;
        }

        .theme-switch input:checked ~ .theme-switch-thumb {
            transform: translateX(18px);
            background: #B0CB1F;
        }

        .logout-button {
            width: 100%;
            background: transparent;
            border: none;
            padding: 0;
            color: var(--text-primary);
            cursor: pointer;
        }

        .logout-button span {
            display: block;
            width: 100%;
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .logout-button span:hover {
            background: rgba(255, 140, 0, 0.15);
            color: var(--accent);
            transform: translateX(5px);
        }

        .messages-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            height: calc(100vh - 64px);
            margin-top: 64px;
            overflow: hidden;
        }

        .sidebar {
            border-right: 1px solid var(--border-glass);
            background: var(--bg-sidebar);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
        }

        .sidebar-header {
            padding: 1rem 1.2rem;
            font-size: 0.85rem;
            color: var(--partner-time);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-bottom: 1px solid var(--border-glass);
        }

        .partner-item {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0.9rem 1.2rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,140,0,0.06);
            border-bottom-color: color-mix(in srgb, var(--border-glass) 20%, transparent);
            transition: background 0.18s;
            text-decoration: none;
            color: inherit;
        }

        .partner-item:hover,
        .partner-item.active {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
        }

        .partner-item.active {
            cursor: default;
        }

        .partner-item.active:hover {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
        }

        .partner-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--avatar-bg);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--avatar-color);
            flex-shrink: 0;
            position: relative;
        }

        .partner-info { flex: 1; min-width: 0; }
        .partner-name {
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .partner-time {
            font-size: 0.75rem;
            color: var(--partner-time);
            margin-top: 2px;
        }
        .unread-badge {
            background: var(--accent);
            color: var(--avatar-color);
            border-radius: 50%;
            width: 20px; height: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .no-partners {
            padding: 2rem 1.2rem;
            text-align: center;
            color: var(--partner-time);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .chat-area {
            display: flex;
            flex-direction: column;
            background: var(--bg-chat);
            position: relative;
            min-height: 0;
            overflow: hidden;
        }

        .chat-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-glass);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--bg-header);
        }

        .chat-partner-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--avatar-bg);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            color: var(--avatar-color);
        }

        .chat-partner-name {
            font-weight: 700;
            font-size: 1.05rem;
        }
        
        .chat-partner-name.clickable {
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .chat-partner-name.clickable:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        .chat-partner-member {
            font-size: 0.75rem;
            color: var(--partner-time);
        }

        .messages-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
        }

        .msg-row {
            display: flex;
            align-items: flex-end;
            gap: 6px;
        }
        .msg-row.sent {
            flex-direction: row;
            justify-content: flex-end;
        }
        .msg-row.received {
            flex-direction: row;
            justify-content: flex-start;
        }

        .msg-bubble {
            max-width: 70%;
            padding: 0.65rem 1rem;
            border-radius: 18px;
            font-size: 0.97rem;
            line-height: 1.5;
            word-wrap: break-word;
            position: relative;
        }

        .msg-bubble.sent {
            background: var(--msg-sent);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg-bubble.received {
            background: var(--msg-received-bg);
            border: 1px solid var(--msg-received-border);
            color: var(--msg-received-color);
            border-bottom-left-radius: 4px;
        }

        .msg-time {
            font-size: 0.68rem;
            margin-top: 4px;
            text-align: right;
            opacity: 0.55;
        }

        .msg-menu-btn {
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 1.15rem;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s;
            line-height: 1;
            padding: 4px 4px;
            border-radius: 6px;
            flex-shrink: 0;
            align-self: center;
            position: relative;
        }

        .msg-row:hover .msg-menu-btn {
            opacity: 0.7;
        }

        .msg-menu-btn:hover {
            opacity: 1 !important;
            background: rgba(128,128,128,0.2);
        }

        .msg-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            background: var(--bg-glass);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            min-width: 150px;
            z-index: 20;
            display: none;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .msg-row.sent .msg-dropdown {
            left: auto;
            right: 0;
        }

        .msg-dropdown.show {
            display: flex;
        }

        .msg-dropdown-item {
            padding: 8px 12px;
            background: transparent;
            border: none;
            text-align: left;
            color: var(--text-primary);
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
            font-family: inherit;
        }

        .msg-dropdown-item:hover {
            background: rgba(255,140,0,0.2);
            color: var(--accent);
        }

        .msg-dropdown-item.delete-msg {
            color: #ff6b6b;
        }
        .msg-dropdown-item.delete-msg:hover {
            background: rgba(255,0,0,0.2);
            color: #ff4444;
        }

        .msg-dropdown-item.edit-msg {
            color: var(--accent);
        }
        .msg-dropdown-item.edit-msg:hover {
            background: color-mix(in srgb, var(--accent) 20%, transparent);
        }

        .edit-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 4000;
        }
        .edit-modal.open { display: flex; }
        .edit-modal-content {
            background: var(--bg-glass);
            border: 1px solid var(--accent);
            border-radius: 16px;
            padding: 1.5rem;
            max-width: 460px;
            width: 90%;
        }
        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .edit-modal-title {
            color: var(--accent);
            font-size: 1rem;
            font-weight: 700;
        }
        .edit-modal-close {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.4rem;
            cursor: pointer;
            line-height: 1;
        }
        .edit-textarea {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border-glass);
            border-radius: 10px;
            padding: 0.7rem 1rem;
            color: var(--text-primary);
            font-size: 0.97rem;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            max-height: 240px;
            outline: none;
            margin-bottom: 1rem;
            user-select: text;
        }
        .edit-textarea:focus { border-color: var(--accent); }
        .edit-modal-actions {
            display: flex;
            gap: 0.6rem;
            justify-content: flex-end;
        }
        .edit-cancel-btn {
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            border: 1px solid var(--border-glass);
            background: transparent;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .edit-save-btn {
            padding: 0.5rem 1.4rem;
            border-radius: 40px;
            border: none;
            background: var(--accent-gradient);
            color: var(--avatar-color);
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: inherit;
        }

        .delete-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 4000;
        }
        .delete-modal.open { display: flex; }
        .delete-modal-content {
            background: var(--bg-glass);
            border: 1px solid var(--accent);
            border-radius: 16px;
            padding: 1.5rem;
            max-width: 380px;
            width: 90%;
        }
        .delete-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .delete-modal-title {
            color: var(--accent);
            font-size: 1rem;
            font-weight: 700;
        }
        .delete-modal-close {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.4rem;
            cursor: pointer;
            line-height: 1;
        }
        .delete-modal-text {
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .delete-modal-actions {
            display: flex;
            gap: 0.6rem;
            justify-content: flex-end;
        }
        .delete-cancel-btn {
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            border: 1px solid var(--border-glass);
            background: transparent;
            color: var(--text-primary);
            cursor: pointer;
            font-size: 0.9rem;
            font-family: inherit;
        }
        .delete-confirm-btn {
            padding: 0.5rem 1.4rem;
            border-radius: 40px;
            border: none;
            background: #ff4444;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
            font-family: inherit;
        }

        .chat-input-area {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-glass);
            display: flex;
            gap: 0.8rem;
            align-items: flex-end;
            background: var(--bg-header);
        }

        .msg-textarea {
            flex: 1;
            background: var(--bg-input);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            color: var(--text-primary);
            padding: 0.7rem 1rem;
            font-size: 0.97rem;
            resize: none;
            min-height: 44px;
            max-height: 140px;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
            line-height: 1.45;
            user-select: text;
        }

        .msg-textarea:focus { border-color: var(--accent); }

        .send-btn {
            width: 44px; height: 44px;
            background: var(--accent-gradient);
            border: none;
            border-radius: 50%;
            color: var(--avatar-color);
            font-size: 1.2rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .send-btn:hover { transform: scale(1.08); box-shadow: 0 0 16px var(--accent-glow); }

        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            color: var(--partner-time);
        }
        .empty-chat-icon { font-size: 3.5rem; }
        .empty-chat-text { font-size: 1rem; }

        .report-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 4000;
        }
        .report-modal.show {
            display: flex;
        }
        .report-modal-content {
            background: var(--bg-glass);
            border: 1px solid var(--accent);
            border-radius: 16px;
            padding: 1.5rem;
            max-width: 400px;
            width: 90%;
        }
        .report-form-textarea {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border-glass);
            border-radius: 8px;
            padding: 0.5rem;
            color: var(--text-primary);
            margin-bottom: 1rem;
            resize: vertical;
            font-family: inherit;
        }
        .report-submit-btn {
            background: var(--accent-gradient);
            border: none;
            border-radius: 40px;
            padding: 0.6rem 1rem;
            color: var(--avatar-color);
            font-weight: bold;
            width: 100%;
            cursor: pointer;
        }

        .toast-notification {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%) translateY(120%);
            background: color-mix(in srgb, var(--accent) 15%, var(--bg-glass));
            border: 1px solid var(--accent);
            color: var(--text-primary);
            padding: 0.85rem 1.6rem;
            border-radius: 50px;
            font-size: 0.92rem;
            z-index: 9999;
            box-shadow: 0 4px 24px rgba(0,0,0,0.4), 0 0 20px var(--accent-glow);
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.35s ease;
            opacity: 0;
            white-space: nowrap;
            pointer-events: none;
        }
        .toast-notification.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        /* ===================== SELLER PROFILE POPUP — FULLSCREEN ===================== */
        .seller-popup-overlay {
            position: fixed;
            inset: 0;
            z-index: 6000;
            background: rgba(0, 0, 0, 0.98);
            backdrop-filter: blur(16px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .seller-popup-overlay.active {
            display: flex;
            opacity: 1;
        }

        .seller-popup-card {
            width: 100vw;
            height: 100vh;
            background: rgba(5, 5, 5, 0.99);
            border: none;
            border-radius: 0;
            padding: 0;
            overflow-y: auto;
            position: relative;
            transform: scale(0.98);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .seller-popup-overlay.active .seller-popup-card {
            transform: scale(1);
        }

        .seller-popup-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1.5rem;
            background: rgba(5, 5, 5, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-glass);
        }

        .seller-popup-close {
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--border-glass);
            color: var(--accent);
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

        .seller-popup-close:hover {
            background: var(--accent);
            color: #000;
        }

        .seller-popup-topbar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--accent);
            flex: 1;
        }

        .seller-popup-body {
            flex: 1;
            max-width: 560px;
            width: 100%;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 3rem;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .seller-popup-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #ff5500);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 700;
            color: #000;
            margin: 0 auto 1.2rem;
            box-shadow: 0 0 40px rgba(255, 140, 0, 0.3);
        }

        .seller-popup-name {
            text-align: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 0.35rem;
        }

        .seller-popup-meta {
            text-align: center;
            font-size: 0.88rem;
            color: rgba(255, 255, 255, 0.4);
            margin-bottom: 2rem;
        }

        .seller-popup-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .seller-stat {
            flex: 1;
            background: rgba(255, 140, 0, 0.07);
            border: 1px solid rgba(255, 140, 0, 0.15);
            border-radius: 16px;
            padding: 1.1rem;
            text-align: center;
        }

        .seller-stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--accent);
        }

        .seller-stat-label {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 3px;
        }

        .seller-popup-items-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255, 255, 255, 0.3);
            margin-bottom: 0.9rem;
        }

        .seller-popup-items-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
            margin-bottom: 2rem;
        }

        .seller-item-thumb {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(255, 140, 0, 0.12);
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(0, 0, 0, 0.4);
        }

        .seller-item-thumb:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(255, 140, 0, 0.2);
        }

        .seller-item-thumb img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            display: block;
        }

        .seller-item-thumb-placeholder {
            width: 100%;
            height: 110px;
            background: rgba(255, 140, 0, 0.07);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 140, 0, 0.35);
            font-size: 1.8rem;
        }

        .seller-item-info {
            padding: 0.6rem 0.75rem;
        }

        .seller-item-title {
            font-size: 0.82rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: rgba(255, 255, 255, 0.85);
        }

        .seller-item-price {
            font-size: 0.8rem;
            color: var(--accent);
            font-weight: 600;
            margin-top: 3px;
        }

        .seller-popup-msg-btn {
            width: 100%;
            padding: 1.1rem;
            background: linear-gradient(135deg, var(--accent), #ff5500);
            border: none;
            border-radius: 16px;
            color: #fff;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            text-decoration: none;
            margin-top: auto;
        }

        .seller-popup-msg-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 140, 0, 0.4);
        }

        .seller-popup-loading {
            text-align: center;
            padding: 4rem 2rem;
            color: rgba(255, 255, 255, 0.3);
            font-size: 1rem;
        }

        .admin-badge {
            font-size: 0.7rem;
            background: rgba(255, 215, 0, 0.2);
            color: #ffd700;
            border: 1px solid rgba(255, 215, 0, 0.4);
            border-radius: 50px;
            padding: 1px 8px;
            vertical-align: middle;
        }

        /* Light mode fixes for seller popup */
        body[data-theme="light"] .seller-popup-avatar {
            background: linear-gradient(135deg, #B0CB1F, #8aA000) !important;
            box-shadow: 0 0 40px rgba(176, 203, 31, 0.3) !important;
        }

        body[data-theme="light"] .seller-popup-msg-btn {
            background: linear-gradient(135deg, #B0CB1F, #8aA000) !important;
        }

        body[data-theme="light"] .seller-popup-msg-btn:hover {
            box-shadow: 0 10px 30px rgba(176, 203, 31, 0.4) !important;
        }

        /* ===================== PRODUCT MODAL ===================== */
        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 5000;
            background: rgba(0, 0, 0, 0.98);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 0;
        }

        .product-modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .product-modal-card {
            width: 100vw;
            height: 100vh;
            background: rgba(5, 5, 5, 0.99);
            position: relative;
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 2rem;
            padding: 2rem;
            transform: scale(0.98);
            transition: transform 0.3s ease;
            box-shadow: none;
            overflow: hidden;
        }

        .product-modal-overlay.active .product-modal-card {
            transform: scale(1);
        }

        .product-modal-header {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            display: flex;
            gap: 1rem;
            z-index: 100;
        }

        .product-modal-close {
            background: rgba(20, 20, 20, 0.8);
            border: 1px solid var(--accent);
            color: var(--accent);
            font-size: 1.8rem;
            cursor: pointer;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            backdrop-filter: blur(5px);
        }

        .product-modal-close:hover {
            background: var(--accent);
            color: black;
            transform: scale(1.1);
        }

        .product-gallery {
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 24px;
            padding: 1rem;
            min-height: 0;
        }

        .product-main-image-container {
            position: relative;
            width: 100%;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid var(--border-glass);
            margin-bottom: 1rem;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }

        .product-main-image {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            cursor: pointer;
            transition: opacity 0.2s ease;
        }

        .product-main-image:hover {
            opacity: 0.9;
        }

        .product-no-image-placeholder {
            text-align: center;
            font-size: 1.2rem;
            padding: 2rem;
            user-select: none;
            -webkit-user-select: none;
        }

        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: 2px solid var(--accent);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s ease;
            z-index: 10;
            backdrop-filter: blur(5px);
        }

        .gallery-nav:hover {
            background: var(--accent);
            color: black;
            transform: translateY(-50%) scale(1.1);
        }

        .gallery-nav.prev {
            left: 20px;
        }

        .gallery-nav.next {
            right: 20px;
        }

        .gallery-nav.hidden {
            display: none;
        }

        .product-thumbnails {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding: 0.5rem 0;
            min-height: 100px;
        }

        .product-thumbnail {
            width: 100px;
            height: 100px;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .product-thumbnail:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .product-thumbnail.active {
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .product-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-details {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            padding: 2rem;
            background: rgba(10, 10, 10, 0.8);
            border-radius: 24px;
            border: 1px solid var(--border-glass);
            height: 100%;
            overflow-y: auto;
            user-select: none;
        }

        .product-details-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .product-title {
            font-size: 2.5rem;
            color: var(--accent);
            margin: 0;
            word-break: break-word;
            line-height: 1.2;
            font-weight: bold;
        }

        .product-price {
            font-size: 3rem;
            font-weight: bold;
            color: var(--accent);
            text-shadow: 0 0 30px var(--accent-glow);
        }

        .product-seller {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
        }

        .product-seller strong {
            color: var(--accent);
            font-size: 1.4rem;
        }

        .product-date {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.4);
        }

        .product-description {
            font-size: 1.1rem;
            line-height: 1.8;
            color: rgba(255, 255, 255, 0.9);
            background: rgba(0, 0, 0, 0.5);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--border-glass);
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            user-select: none;
        }

        .product-buy-btn {
            background: linear-gradient(135deg, #00c851, #007e33);
            border: none;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            user-select: none;
        }

        .product-buy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 200, 0, 0.4);
        }

        .lightbox-overlay {
            position: fixed;
            inset: 0;
            z-index: 5500;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .lightbox-overlay.active {
            display: flex;
            opacity: 1;
        }

        .lightbox-content {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            max-width: 95vw;
            max-height: 95vh;
        }

        .lightbox-image {
            max-width: calc(95vw - 70px);
            max-height: 95vh;
            width: auto;
            height: auto;
            object-fit: contain;
            border: 2px solid var(--accent);
            border-radius: 8px;
        }

        .lightbox-close {
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid var(--accent);
            color: var(--accent);
            font-size: 2rem;
            cursor: pointer;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .lightbox-close:hover {
            background: var(--accent);
            color: black;
            transform: scale(1.1);
        }

        @media (max-width: 640px) {
            .messages-layout { grid-template-columns: 1fr; }
            .sidebar { display: <?php echo $withUserId > 0 ? 'none' : 'flex'; ?>; height: calc(100vh - 64px); }
            .chat-area { display: <?php echo $withUserId > 0 ? 'flex' : 'none'; ?>; }
            .msg-bubble { max-width: 85%; }
            .product-modal-card {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 1rem;
                overflow-y: auto;
            }
            .product-gallery {
                height: 50vh;
            }
            .product-title {
                font-size: 2rem;
            }
            .product-price {
                font-size: 2.5rem;
            }
            .product-description {
                max-height: 300px;
            }
        }

        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--accent) 30%, transparent); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--accent) 50%, transparent); }

        .unselectable {
            user-select: none;
            -webkit-user-select: none;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <a href="main.php" class="back-btn unselectable">← Vissza</a>
        <div class="page-title unselectable">
            💬 Üzenetek
            <?php if ($totalUnread > 0): ?>
                <span style="font-size:0.8rem;background:var(--accent);color:var(--avatar-color);border-radius:50px;padding:1px 8px;margin-left:6px;"><?php echo $totalUnread; ?></span>
            <?php endif; ?>
        </div>
        <details class="account-menu">
            <summary class="account-summary unselectable">
                <span>⚙️</span>
                <span class="button-text">FIÓK</span>
            </summary>
            <div class="account-dropdown">
                <div class="user-info unselectable">
                    <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                </div>
                <div class="dropdown-divider"></div>
                <div class="theme-toggle-row">
                    <span class="theme-toggle-label">☀️ Világos mód</span>
                    <label class="theme-switch">
                        <input type="checkbox" id="themeSwitchMsg">
                        <span class="theme-switch-track"></span>
                        <span class="theme-switch-thumb"></span>
                    </label>
                </div>
                <div class="dropdown-divider"></div>
                <form method="post" style="width:100%;margin:0;padding:0;">
                    <button type="submit" name="logout" class="logout-button">
                        <span class="unselectable">Kijelentkezés</span>
                    </button>
                </form>
            </div>
        </details>
    </div>

    <div class="messages-layout">
        <div class="sidebar">
            <div class="sidebar-header">Beszélgetések</div>
            <?php if (empty($partners)): ?>
                <div class="no-partners">
                    Még nincsenek üzeneteid.<br>
                    Kattints egy eladóra a főoldalon az üzenetküldéshez.
                </div>
            <?php else: ?>
                <?php foreach ($partners as $p): ?>
                    <?php if ($withUserId == $p['id']): ?>
                        <div class="partner-item active">
                            <div class="partner-avatar"><?php echo strtoupper(substr($p['username'], 0, 1)); ?></div>
                            <div class="partner-info">
                                <div class="partner-name"><?php echo htmlspecialchars($p['username']); ?></div>
                                <div class="partner-time">
                                    <?php
                                        $ts = strtotime($p['last_message_at']);
                                        $diff = time() - $ts;
                                        if ($diff < 60) echo 'Az imént';
                                        elseif ($diff < 3600) echo round($diff/60) . ' perce';
                                        elseif ($diff < 86400) echo round($diff/3600) . ' órája';
                                        else echo date('Y.m.d', $ts);
                                    ?>
                                </div>
                            </div>
                            <?php if ($p['unread_count'] > 0): ?>
                                <div class="unread-badge"><?php echo $p['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <a href="uzenetek.php?with=<?php echo $p['id']; ?>" class="partner-item">
                            <div class="partner-avatar"><?php echo strtoupper(substr($p['username'], 0, 1)); ?></div>
                            <div class="partner-info">
                                <div class="partner-name"><?php echo htmlspecialchars($p['username']); ?></div>
                                <div class="partner-time">
                                    <?php
                                        $ts = strtotime($p['last_message_at']);
                                        $diff = time() - $ts;
                                        if ($diff < 60) echo 'Az imént';
                                        elseif ($diff < 3600) echo round($diff/60) . ' perce';
                                        elseif ($diff < 86400) echo round($diff/3600) . ' órája';
                                        else echo date('Y.m.d', $ts);
                                    ?>
                                </div>
                            </div>
                            <?php if ($p['unread_count'] > 0): ?>
                                <div class="unread-badge"><?php echo $p['unread_count']; ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="chat-area">
            <?php if ($withUser): ?>
                <div class="chat-header">
                    <div class="chat-partner-avatar">
                        <?php echo strtoupper(substr($withUser['username'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="chat-partner-name clickable" onclick="openSellerPopup(<?php echo $withUserId; ?>)">
                            <?php echo htmlspecialchars($withUser['username']); ?>
                        </div>
                        <div class="chat-partner-member">
                            Tag azóta: <?php echo date('Y.m.d', strtotime($withUser['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <div class="messages-list" id="messagesList">
                    <?php if (empty($messages)): ?>
                        <div style="text-align:center;color:var(--partner-time);margin-top:2rem;font-size:0.9rem;">
                            Még nincs üzenet. Küldj egyet!
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): 
                            $isOwn = ($msg['sender_id'] == $currentUserId);
                        ?>
                            <div class="msg-row <?php echo $isOwn ? 'sent' : 'received'; ?>" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">
                                <?php if (!$isOwn): ?>
                                    <div class="msg-bubble received" data-sent-at="<?php echo $msg['sent_at']; ?>">
                                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                        <div class="msg-time"><?php echo date('H:i', strtotime($msg['sent_at'])); ?></div>
                                    </div>
                                    <div style="position:relative; align-self:center;">
                                        <button class="msg-menu-btn" onclick="toggleMsgMenu(this, event)">⋮</button>
                                        <div class="msg-dropdown">
                                            <button class="msg-dropdown-item report-msg" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">⚠️ Bejelentés</button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="position:relative; align-self:center;">
                                        <button class="msg-menu-btn" onclick="toggleMsgMenu(this, event)">⋮</button>
                                        <div class="msg-dropdown">
                                            <button class="msg-dropdown-item edit-msg"
                                                data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>"
                                                data-msg-text="<?php echo htmlspecialchars($msg['message']); ?>">✏️ Szerkesztés</button>
                                            <button class="msg-dropdown-item delete-msg" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">🗑️ Törlés</button>
                                        </div>
                                    </div>
                                    <div class="msg-bubble sent" data-sent-at="<?php echo $msg['sent_at']; ?>">
                                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                        <div class="msg-time">
                                            <?php echo date('H:i', strtotime($msg['sent_at'])); ?>
                                            <?php if ($msg['is_read']): ?>&nbsp;✓✓<?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-input-area">
                    <input type="hidden" id="receiverId" value="<?php echo $withUserId; ?>">
                    <textarea class="msg-textarea" id="msgInput" placeholder="Írj üzenetet..." rows="1"></textarea>
                    <button type="button" class="send-btn" id="sendBtn">➤</button>
                </div>

            <?php else: ?>
                <div class="empty-chat">
                    <div class="empty-chat-icon">💬</div>
                    <div class="empty-chat-text">Válassz ki egy beszélgetést a bal oldali listából</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="toast-notification" id="toastNotification"></div>

    <div class="report-modal" id="reportMsgModal">
        <div class="report-modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: var(--accent);">Üzenet bejelentése</h3>
                <button class="close-report-modal" style="background: none; border: none; color: var(--text-primary); font-size: 1.5rem; cursor: pointer;">✕</button>
            </div>
            <form method="post" id="reportMsgForm">
                <input type="hidden" name="message_id" id="reportMsgId">
                <input type="hidden" name="report_message" value="1">
                <textarea name="report_reason" class="report-form-textarea" required placeholder="Kérjük, részletezd a problémát..."></textarea>
                <button type="submit" class="report-submit-btn">Bejelentés küldése</button>
            </form>
        </div>
    </div>

    <div class="edit-modal" id="editMsgModal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <span class="edit-modal-title">✏️ Üzenet szerkesztése</span>
                <button class="edit-modal-close" id="editModalClose">✕</button>
            </div>
            <form method="post" id="editMsgForm" action="uzenetek.php?with=<?php echo $withUserId; ?>">
                <input type="hidden" name="message_id" id="editMsgId">
                <input type="hidden" name="edit_message" value="1">
                <textarea class="edit-textarea" name="new_message" id="editMsgTextarea" required></textarea>
                <div class="edit-modal-actions">
                    <button type="button" class="edit-cancel-btn" id="editCancelBtn">Mégse</button>
                    <button type="submit" class="edit-save-btn">Mentés</button>
                </div>
            </form>
        </div>
    </div>

    <div class="delete-modal" id="deleteConfirmModal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <span class="delete-modal-title">🗑️ Üzenet törlése</span>
                <button class="delete-modal-close" id="deleteModalClose">✕</button>
            </div>
            <div class="delete-modal-text">
                Biztosan törölni szeretnéd ezt az üzenetet?<br>
                <small style="opacity:0.7;">A törlés végleges, nem vonható vissza.</small>
            </div>
            <div class="delete-modal-actions">
                <button type="button" class="delete-cancel-btn" id="deleteCancelBtn">Mégse</button>
                <button type="button" class="delete-confirm-btn" id="deleteConfirmBtn">Törlés</button>
            </div>
        </div>
    </div>

    <!-- Seller Profile Popup Overlay -->
    <div class="seller-popup-overlay" id="sellerPopupOverlay">
        <div class="seller-popup-card" id="sellerPopupCard">
            <div class="seller-popup-topbar">
                <button class="seller-popup-close unselectable" id="sellerPopupClose">✕</button>
                <div class="seller-popup-topbar-title unselectable">👤 Eladó profilja</div>
            </div>
            <div class="seller-popup-body" id="sellerPopupContent">
                <div class="seller-popup-loading unselectable">⏳ Betöltés...</div>
            </div>
        </div>
    </div>

    <!-- Product Modal -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <div class="product-modal-header">
                <button class="product-modal-close unselectable" id="closeProductModalBtn">✕</button>
            </div>
            <div class="product-gallery">
                <div class="product-main-image-container">
                    <img src="" alt="Termék képe" class="product-main-image" id="productMainImage" style="display: none;">
                    <div class="product-no-image-placeholder unselectable" id="productNoImagePlaceholder" style="display: none;">📷 Nincs kép</div>
                    <button class="gallery-nav prev unselectable" id="galleryPrev">❮</button>
                    <button class="gallery-nav next unselectable" id="galleryNext">❯</button>
                </div>
                <div class="product-thumbnails" id="productThumbnails"></div>
            </div>
            <div class="product-details">
                <h2 class="product-title unselectable" id="productTitle"></h2>
                <div class="product-price unselectable" id="productPrice"></div>
                <div class="product-seller unselectable" id="productSeller"></div>
                <div class="product-date unselectable" id="productDate"></div>
                <div class="product-description unselectable" id="productDescription"></div>
                <button class="product-buy-btn unselectable" id="productBuyBtn">🛒 Vásárlás</button>
            </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay">
        <div class="lightbox-content">
            <img src="" alt="Nagyított kép" class="lightbox-image" id="lightboxImage">
            <button class="lightbox-close unselectable" id="lightboxClose">✕</button>
        </div>
    </div>

    <script>
        const currentUserId = <?php echo $currentUserId; ?>;
        const partnerId = <?php echo $withUserId ?: 0; ?>;
        let lastTimestamp = '';
        let pollInterval = null;

        const messagesList = document.getElementById('messagesList');
        const msgInput = document.getElementById('msgInput');
        const sendBtn = document.getElementById('sendBtn');
        const toast = document.getElementById('toastNotification');

        // Product modal variables
        let currentProductImages = [];
        let currentImageIndex = 0;
        const productModal = document.getElementById('productModal');
        const productMainImage = document.getElementById('productMainImage');
        const productNoImagePlaceholder = document.getElementById('productNoImagePlaceholder');
        const lightboxOverlay = document.getElementById('lightboxOverlay');
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxClose = document.getElementById('lightboxClose');
        const closeProductModalBtn = document.getElementById('closeProductModalBtn');

        function showToast(msg) {
            toast.textContent = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3500);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function scrollToBottom() {
            if (messagesList) messagesList.scrollTop = messagesList.scrollHeight;
        }

        function getMaxTimestampFromDOM() {
            let maxTs = '';
            document.querySelectorAll('.msg-bubble').forEach(bubble => {
                const ts = bubble.getAttribute('data-sent-at');
                if (ts && ts > maxTs) maxTs = ts;
            });
            return maxTs;
        }

        function appendMessage(msg) {
            const isOwn = (parseInt(msg.sender_id) === currentUserId);
            const msgDiv = document.createElement('div');
            msgDiv.className = `msg-row ${isOwn ? 'sent' : 'received'}`;
            msgDiv.setAttribute('data-msg-id', msg.id);
            
            const timeStr = new Date(msg.sent_at).toLocaleTimeString('hu-HU', {hour: '2-digit', minute:'2-digit'});
            
            if (!isOwn) {
                msgDiv.innerHTML = `
                    <div class="msg-bubble received" data-sent-at="${escapeHtml(msg.sent_at)}">
                        ${escapeHtml(msg.message).replace(/\n/g, '<br>')}
                        <div class="msg-time">${timeStr}</div>
                    </div>
                    <div style="position:relative; align-self:center;">
                        <button class="msg-menu-btn" onclick="toggleMsgMenu(this, event)">⋮</button>
                        <div class="msg-dropdown">
                            <button class="msg-dropdown-item report-msg" data-msg-id="${escapeHtml(msg.id)}">⚠️ Bejelentés</button>
                        </div>
                    </div>
                `;
            } else {
                msgDiv.innerHTML = `
                    <div style="position:relative; align-self:center;">
                        <button class="msg-menu-btn" onclick="toggleMsgMenu(this, event)">⋮</button>
                        <div class="msg-dropdown">
                            <button class="msg-dropdown-item edit-msg" data-msg-id="${escapeHtml(msg.id)}" data-msg-text="${escapeHtml(msg.message)}">✏️ Szerkesztés</button>
                            <button class="msg-dropdown-item delete-msg" data-msg-id="${escapeHtml(msg.id)}">🗑️ Törlés</button>
                        </div>
                    </div>
                    <div class="msg-bubble sent" data-sent-at="${escapeHtml(msg.sent_at)}">
                        ${escapeHtml(msg.message).replace(/\n/g, '<br>')}
                        <div class="msg-time">${timeStr} ${msg.is_read ? '✓✓' : '✓'}</div>
                    </div>
                `;
            }
            messagesList.appendChild(msgDiv);
            scrollToBottom();
        }

        async function pollNewMessages() {
            if (!partnerId) return;
            if (!lastTimestamp) {
                lastTimestamp = getMaxTimestampFromDOM();
                if (!lastTimestamp) {
                    lastTimestamp = '1970-01-01 00:00:00';
                }
            }
            try {
                const response = await fetch(`?ajax_get_messages=1&with=${partnerId}&last_timestamp=${encodeURIComponent(lastTimestamp)}`);
                const data = await response.json();
                if (data.messages && data.messages.length > 0) {
                    for (const msg of data.messages) {
                        if (!document.querySelector(`.msg-row[data-msg-id="${msg.id}"]`)) {
                            appendMessage(msg);
                        }
                        if (msg.sent_at > lastTimestamp) lastTimestamp = msg.sent_at;
                    }
                }
            } catch (err) {
                console.error('Polling hiba:', err);
            }
        }

        let isSending = false;

        async function sendMessage() {
            if (isSending) return;
            const message = msgInput.value.trim();
            if (!message) return;
            const receiver = partnerId;
            if (!receiver) return;

            isSending = true;
            msgInput.disabled = true;
            if (sendBtn) sendBtn.disabled = true;

            // Optimista elem azonnal megjelenik
            const tempId = 'temp_' + Date.now();
            const now = new Date();
            const sentAt = now.toISOString().slice(0, 19).replace('T', ' ');
            const tempMsg = {
                id: tempId,
                sender_id: currentUserId,
                receiver_id: receiver,
                message: message,
                sent_at: sentAt,
                is_read: 0
            };
            appendMessage(tempMsg);
            scrollToBottom();
            msgInput.value = '';
            msgInput.style.height = 'auto';

            const formData = new URLSearchParams();
            formData.append('send_message_ajax', '1');
            formData.append('receiver_id', receiver);
            formData.append('message', message);

            try {
                const response = await fetch('uzenetek.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const data = await response.json();
                if (data.success && data.msg_id) {
                    // Cseréljük a temp elemet a valós ID-ra és sent_at-re
                    const tempRow = document.querySelector(`.msg-row[data-msg-id="${tempId}"]`);
                    if (tempRow) {
                        tempRow.setAttribute('data-msg-id', data.msg_id);
                        const bubble = tempRow.querySelector('.msg-bubble');
                        if (bubble) bubble.setAttribute('data-sent-at', data.sent_at);
                        // edit/delete gombokban is frissítjük az ID-t
                        tempRow.querySelectorAll('[data-msg-id]').forEach(el => {
                            el.setAttribute('data-msg-id', data.msg_id);
                        });
                    }
                    if (data.sent_at > lastTimestamp) lastTimestamp = data.sent_at;
                } else if (!data.success) {
                    // Ha sikertelen, töröljük az optimista elemet
                    const tempRow = document.querySelector(`.msg-row[data-msg-id="${tempId}"]`);
                    if (tempRow) tempRow.remove();
                    showToast(data.error || 'Hiba az üzenet küldésekor.');
                    msgInput.value = message; // visszaállítjuk a szöveget
                }
            } catch (err) {
                const tempRow = document.querySelector(`.msg-row[data-msg-id="${tempId}"]`);
                if (tempRow) tempRow.remove();
                showToast('Hálózati hiba.');
                msgInput.value = message;
                console.error(err);
            } finally {
                isSending = false;
                msgInput.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
                msgInput.focus();
            }
        }

        if (sendBtn) {
            sendBtn.addEventListener('click', sendMessage);
        }
        if (msgInput) {
            msgInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            msgInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 140) + 'px';
            });
        }

        if (partnerId) {
            lastTimestamp = getMaxTimestampFromDOM();
            if (!lastTimestamp) lastTimestamp = '1970-01-01 00:00:00';

            function startPolling() {
                if (pollInterval) clearInterval(pollInterval);
                const interval = document.hidden ? 3000 : 500;
                pollInterval = setInterval(pollNewMessages, interval);
            }
            startPolling();
            document.addEventListener('visibilitychange', startPolling);
        }

        const saved = localStorage.getItem('theme');
        const themeStylesheet = document.getElementById('themeStylesheet');
        const themeSwitch = document.getElementById('themeSwitchMsg');
        function applyTheme(theme) {
            themeStylesheet.href = theme === 'light' ? 'theme-light.css' : 'theme-dark.css';
            document.body.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            if (themeSwitch) themeSwitch.checked = (theme === 'light');
        }
        applyTheme(saved === 'light' ? 'light' : 'dark');
        if (themeSwitch) {
            themeSwitch.addEventListener('change', function() {
                applyTheme(this.checked ? 'light' : 'dark');
            });
        }

        function toggleMsgMenu(btn, event) {
            if (event) event.stopPropagation();
            document.querySelectorAll('.msg-dropdown.show').forEach(dd => {
                if (dd !== btn.nextElementSibling) dd.classList.remove('show');
            });
            btn.nextElementSibling.classList.toggle('show');
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.msg-dropdown.show').forEach(dd => dd.classList.remove('show'));
        });

        const deleteModal = document.getElementById('deleteConfirmModal');
        const deleteCloseBtn = document.getElementById('deleteModalClose');
        const deleteCancelBtn = document.getElementById('deleteCancelBtn');
        const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
        let pendingDeleteMsgId = null;

        function openDeleteModal(msgId) {
            pendingDeleteMsgId = msgId;
            deleteModal.classList.add('open');
        }

        function closeDeleteModal() {
            deleteModal.classList.remove('open');
            pendingDeleteMsgId = null;
        }

        deleteCloseBtn.addEventListener('click', closeDeleteModal);
        deleteCancelBtn.addEventListener('click', closeDeleteModal);
        deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });

        deleteConfirmBtn.addEventListener('click', function() {
            if (pendingDeleteMsgId) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="delete_message" value="1"><input type="hidden" name="message_id" value="${pendingDeleteMsgId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && deleteModal.classList.contains('open')) closeDeleteModal();
        });

        const editModal = document.getElementById('editMsgModal');
        const editMsgId = document.getElementById('editMsgId');
        const editMsgTA = document.getElementById('editMsgTextarea');

        function openEditModal(msgId, msgText) {
            editMsgId.value = msgId;
            editMsgTA.value = msgText;
            editModal.classList.add('open');
            setTimeout(() => { editMsgTA.focus(); editMsgTA.setSelectionRange(editMsgTA.value.length, editMsgTA.value.length); }, 50);
        }
        function closeEditModal() {
            editModal.classList.remove('open');
        }

        document.getElementById('editModalClose').addEventListener('click', closeEditModal);
        document.getElementById('editCancelBtn').addEventListener('click', closeEditModal);
        editModal.addEventListener('click', (e) => { if (e.target === editModal) closeEditModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && editModal.classList.contains('open')) closeEditModal();
        });

        const reportModal = document.getElementById('reportMsgModal');
        const reportMsgId = document.getElementById('reportMsgId');
        const closeReportBtn = reportModal.querySelector('.close-report-modal');

        function closeReportModal() { reportModal.style.display = 'none'; }
        closeReportBtn.addEventListener('click', closeReportModal);
        reportModal.addEventListener('click', (e) => { if (e.target === reportModal) closeReportModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && reportModal.style.display === 'flex') closeReportModal();
        });

        document.addEventListener('click', function(e) {
            if (e.target.closest('.delete-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.delete-msg');
                openDeleteModal(btn.dataset.msgId);
            }
            if (e.target.closest('.edit-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.edit-msg');
                openEditModal(btn.dataset.msgId, btn.dataset.msgText);
            }
            if (e.target.closest('.report-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.report-msg');
                reportMsgId.value = btn.dataset.msgId;
                reportModal.style.display = 'flex';
            }
        });

        <?php if (isset($_SESSION['report_success'])): unset($_SESSION['report_success']); ?>
            showToast('✅ Bejelentésedet rögzítettük. Köszönjük!');
        <?php endif; ?>

        // Seller popup
        const sellerOverlay = document.getElementById('sellerPopupOverlay');
        const sellerContent = document.getElementById('sellerPopupContent');
        const sellerCloseBtn = document.getElementById('sellerPopupClose');

        function openSellerPopup(sellerId) {
            sellerContent.innerHTML = '<div class="seller-popup-loading unselectable">⏳ Betöltés...</div>';
            sellerOverlay.style.display = 'flex';
            sellerOverlay.offsetHeight;
            sellerOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';

            fetch(`?get_seller=${encodeURIComponent(sellerId)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        sellerContent.innerHTML = `<p style="color:red;text-align:center;padding:2rem;" class="unselectable">${escapeHtml(data.error)}</p>`;
                        return;
                    }

                    const currentUserId = <?php echo $currentUserId; ?>;
                    const memberSince = data.created_at ? data.created_at.substring(0, 10) : '—';
                    const adminBadge = parseInt(data.is_admin) ? ' <span class="admin-badge unselectable">Admin</span>' : '';
                    const initial = data.username ? data.username.charAt(0).toUpperCase() : '?';

                    // Update topbar title
                    document.querySelector('.seller-popup-topbar-title').textContent = '👤 ' + data.username;

                    let itemsHtml = '';
                    if (data.latest_items && data.latest_items.length > 0) {
                        itemsHtml = `<div class="seller-popup-items-title unselectable">Legutóbbi hirdetések</div>
                        <div class="seller-popup-items-grid">`;
                        data.latest_items.forEach(item => {
                            const imgHtml = item.thumb ?
                                `<img src="${escapeHtml(item.thumb)}" alt="${escapeHtml(item.title)}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="seller-item-thumb-placeholder unselectable" style="display:none;">📷</div>` :
                                `<div class="seller-item-thumb-placeholder unselectable">📷</div>`;
                            itemsHtml += `
                                <div class="seller-item-thumb" onclick="closeSellerPopup(); fetchItemDetails('${escapeHtml(item.id)}');">
                                    ${imgHtml}
                                    <div class="seller-item-info">
                                        <div class="seller-item-title unselectable">${escapeHtml(item.title)}</div>
                                        <div class="seller-item-price unselectable">${Number(item.price).toLocaleString('hu-HU')} Ft</div>
                                    </div>
                                </div>`;
                        });
                        itemsHtml += '</div>';
                    }

                    const msgBtn = (parseInt(sellerId) !== currentUserId) ?
                        `<a href="uzenetek.php?with=${encodeURIComponent(sellerId)}" class="seller-popup-msg-btn unselectable">💬 Üzenet küldése</a>` :
                        `<div style="text-align:center;color:rgba(255,255,255,0.3);font-size:0.85rem;padding:1rem 0;" class="unselectable">Ez a saját profilod</div>`;

                    sellerContent.innerHTML = `
                        <div class="seller-popup-avatar unselectable">${initial}</div>
                        <div class="seller-popup-name unselectable">${escapeHtml(data.username)}${adminBadge}</div>
                        <div class="seller-popup-meta unselectable">Tag azóta: ${memberSince}</div>
                        <div class="seller-popup-stats">
                            <div class="seller-stat unselectable">
                                <div class="seller-stat-value unselectable">${data.item_count}</div>
                                <div class="seller-stat-label unselectable">Hirdetés</div>
                            </div>
                        </div>
                        ${itemsHtml}
                        ${msgBtn}
                    `;
                })
                .catch(() => {
                    sellerContent.innerHTML = '<p style="color:red;text-align:center;padding:2rem;" class="unselectable">Hálózati hiba történt.</p>';
                });
        }

        function closeSellerPopup() {
            sellerOverlay.classList.remove('active');
            document.body.style.overflow = '';
            setTimeout(() => {
                sellerOverlay.style.display = 'none';
            }, 300);
        }

        sellerCloseBtn.addEventListener('click', closeSellerPopup);
        sellerOverlay.addEventListener('click', e => {
            if (e.target === sellerOverlay) closeSellerPopup();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && sellerOverlay.classList.contains('active')) closeSellerPopup();
        });

        // Product modal functions
        function setMainImage(index) {
            if (index >= 0 && index < currentProductImages.length && currentProductImages[index]) {
                productMainImage.style.display = 'block';
                productNoImagePlaceholder.style.display = 'none';
                productMainImage.src = currentProductImages[index];
                currentImageIndex = index;
                productMainImage.onload = function() {
                    adjustImageContainerHeight();
                };
                productMainImage.onerror = function() {
                    productMainImage.style.display = 'none';
                    productNoImagePlaceholder.style.display = 'block';
                    adjustImageContainerHeight();
                };
                document.querySelectorAll('.product-thumbnail').forEach((thumb, i) => {
                    thumb.classList.toggle('active', i === index);
                });
            } else {
                productMainImage.style.display = 'none';
                productNoImagePlaceholder.style.display = 'block';
                adjustImageContainerHeight();
            }
        }

        function adjustImageContainerHeight() {
            const imageContainer = document.querySelector('.product-main-image-container');
            const gallery = document.querySelector('.product-gallery');
            const thumbnails = document.querySelector('.product-thumbnails');
            if (imageContainer) {
                const galleryPadding = 32;
                const thumbnailsHeight = thumbnails ? thumbnails.offsetHeight : 100;
                const availableHeight = gallery.clientHeight - galleryPadding - thumbnailsHeight - 20;
                if (productMainImage.style.display !== 'none' && productMainImage.complete && productMainImage.naturalHeight > 0) {
                    const imageHeight = Math.min(productMainImage.naturalHeight, availableHeight);
                    imageContainer.style.height = imageHeight + 'px';
                } else {
                    imageContainer.style.height = Math.max(300, availableHeight) + 'px';
                }
            }
        }

        function openProductModal() {
            productModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => {
                adjustImageContainerHeight();
            }, 100);
        }

        function closeProductModal() {
            if (lightboxOverlay.classList.contains('active')) closeLightbox();
            productModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeLightbox() {
            lightboxOverlay.classList.remove('active');
        }

        function fetchItemDetails(itemId) {
            fetch(`?get_item=${itemId}`)
                .then(response => response.json())
                .then(item => {
                    if (item.error) {
                        console.error(item.error);
                        return;
                    }

                    currentProductImages = item.images;
                    currentImageIndex = 0;

                    document.getElementById('productTitle').textContent = item.title;
                    document.getElementById('productPrice').textContent = `${Number(item.price).toLocaleString('hu-HU')} Ft`;
                    document.getElementById('productSeller').innerHTML = `Eladó: <strong>${escapeHtml(item.seller_name)}</strong>`;
                    document.getElementById('productSeller').setAttribute('data-seller-id', item.user_id);
                    document.getElementById('productDate').textContent = item.created_at.substring(0, 10);
                    document.getElementById('productDescription').textContent = item.description;

                    const thumbnailsContainer = document.getElementById('productThumbnails');
                    thumbnailsContainer.innerHTML = '';

                    if (item.images && item.images.length > 0) {
                        item.images.forEach((img, index) => {
                            const thumbnail = document.createElement('div');
                            thumbnail.className = `product-thumbnail ${index === 0 ? 'active' : ''}`;
                            thumbnail.innerHTML = `<img src="${img}" alt="Thumbnail ${index+1}">`;
                            thumbnail.addEventListener('click', (e) => {
                                e.stopPropagation();
                                setMainImage(index);
                            });
                            thumbnailsContainer.appendChild(thumbnail);
                        });
                        setMainImage(0);
                    } else {
                        setMainImage(-1);
                    }

                    const prevBtn = document.getElementById('galleryPrev');
                    const nextBtn = document.getElementById('galleryNext');
                    prevBtn.classList.toggle('hidden', !item.images || item.images.length <= 1);
                    nextBtn.classList.toggle('hidden', !item.images || item.images.length <= 1);

                    openProductModal();
                })
                .catch(err => console.error('Error fetching item details:', err));
        }

        // Product modal event listeners
        closeProductModalBtn.addEventListener('click', closeProductModal);
        productModal.addEventListener('click', (e) => {
            if (e.target === productModal) closeProductModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && productModal.classList.contains('active')) closeProductModal();
        });

        document.getElementById('galleryPrev').addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex - 1;
            setMainImage(newIndex >= 0 ? newIndex : currentProductImages.length - 1);
        });

        document.getElementById('galleryNext').addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex + 1;
            setMainImage(newIndex < currentProductImages.length ? newIndex : 0);
        });

        productMainImage.addEventListener('click', (e) => {
            e.stopPropagation();
            if (productMainImage.src && productMainImage.style.display !== 'none' && !productMainImage.src.includes('svg')) {
                lightboxImage.src = productMainImage.src;
                lightboxOverlay.classList.add('active');
            }
        });

        lightboxClose.addEventListener('click', closeLightbox);
        lightboxOverlay.addEventListener('click', (e) => {
            if (e.target === lightboxOverlay) closeLightbox();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && lightboxOverlay.classList.contains('active')) closeLightbox();
        });

        window.addEventListener('resize', () => {
            if (productModal.classList.contains('active')) adjustImageContainerHeight();
        });

        document.getElementById('productBuyBtn').addEventListener('click', () => {
            alert('Vásárlás funkció még nem elérhető!');
        });

        // Make product seller clickable
        document.getElementById('productSeller').addEventListener('click', function() {
            const sellerId = this.getAttribute('data-seller-id');
            if (sellerId) {
                closeProductModal();
                openSellerPopup(sellerId);
            }
        });

        scrollToBottom();
    </script>
</body>
</html>