<?php
session_start();

// Kijelentkezés kezelése (most már működik)
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

    // ── Send message ──────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');

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
            }
        }
        header("Location: uzenetek.php?with=" . $receiverId);
        exit();
    }

    // ── Edit own message ──────────────────────────────────────────────────────
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

    // ── Delete own message ────────────────────────────────────────────────────
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

    // ── Report a message (only if current user is the receiver) ───────────────
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

    // ── Mark messages as read ─────────────────────────────────────────────────
    $withUserId = isset($_GET['with']) ? (int)$_GET['with'] : 0;
    if ($withUserId > 0) {
        $markRead = $conn->prepare("
            UPDATE uzenetek SET is_read = 1
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $markRead->execute([$withUserId, $currentUserId]);
    }

    // ── Fetch conversation partners ───────────────────────────────────────────
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

    // ── Fetch messages with selected user ────────────────────────────────────
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

    // ── Count total unread ────────────────────────────────────────────────────
    $unreadStmt = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE receiver_id = ? AND is_read = 0");
    $unreadStmt->execute([$currentUserId]);
    $totalUnread = (int)$unreadStmt->fetchColumn();

    // ── Check if current user is admin (for potential extra privileges) ──────
    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$currentUserId]);
    $isAdmin = $adminCheck->fetchColumn() > 0;

} catch (PDOException $e) {
    die("DB hiba: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üzenetek</title>
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        /* Base variables (dark mode defaults) */
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

        body {
            min-height: 100vh;
            background: #0a0a0a;
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            user-select: none;
        }

        /* Light mode overrides */
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

        /* Top Bar */
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

        /* Account menu */
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
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(24px);
            border: 1px solid var(--border-glass);
            border-radius: 16px;
            padding: 0.75rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 20px rgba(255,140,0,0.2);
            z-index: 1001;
            animation: dropdownFade 0.2s ease;
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

        /* Layout */
        .messages-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            height: calc(100vh - 64px);
            margin-top: 64px;
            overflow: hidden;
        }

        /* Sidebar */
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

        /* Chat area */
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

        .chat-partner-member {
            font-size: 0.75rem;
            color: var(--partner-time);
        }

        /* Messages list */
        .messages-list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.7rem;
        }

        /* Message row wrapper */
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

        /* Message bubble */
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

        /* Menu button */
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

        /* Dropdown menu */
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

        /* Edit modal */
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

        /* Delete confirm modal */
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

        /* Input area */
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

        /* Empty state */
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

        /* Mobile */
        @media (max-width: 640px) {
            .messages-layout { grid-template-columns: 1fr; }
            .sidebar { display: <?php echo $withUserId > 0 ? 'none' : 'flex'; ?>; height: calc(100vh - 64px); }
            .chat-area { display: <?php echo $withUserId > 0 ? 'flex' : 'none'; ?>; }
            .msg-bubble { max-width: 85%; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--accent) 30%, transparent); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: color-mix(in srgb, var(--accent) 50%, transparent); }
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
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">Beszélgetések</div>
            <?php if (empty($partners)): ?>
                <div class="no-partners">
                    Még nincsenek üzeneteid.<br>
                    Kattints egy eladóra a főoldalon az üzenetküldéshez.
                </div>
            <?php else: ?>
                <?php foreach ($partners as $p): ?>
                    <a href="uzenetek.php?with=<?php echo $p['id']; ?>"
                       class="partner-item <?php echo ($withUserId == $p['id']) ? 'active' : ''; ?>">
                        <div class="partner-avatar">
                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                        </div>
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
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Chat area -->
        <div class="chat-area">
            <?php if ($withUser): ?>
                <div class="chat-header">
                    <div class="chat-partner-avatar">
                        <?php echo strtoupper(substr($withUser['username'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="chat-partner-name"><?php echo htmlspecialchars($withUser['username']); ?></div>
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
                            <div class="msg-row <?php echo $isOwn ? 'sent' : 'received'; ?>">
                                <?php if (!$isOwn): ?>
                                    <!-- Fogadott üzenet: menü jobb oldalon -->
                                    <div class="msg-bubble received" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">
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
                                    <!-- Küldött üzenet: menü BAL oldalon -->
                                    <div style="position:relative; align-self:center;">
                                        <button class="msg-menu-btn" onclick="toggleMsgMenu(this, event)">⋮</button>
                                        <div class="msg-dropdown">
                                            <button class="msg-dropdown-item edit-msg"
                                                data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>"
                                                data-msg-text="<?php echo htmlspecialchars($msg['message']); ?>">✏️ Szerkesztés</button>
                                            <button class="msg-dropdown-item delete-msg" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">🗑️ Törlés</button>
                                        </div>
                                    </div>
                                    <div class="msg-bubble sent" data-msg-id="<?php echo htmlspecialchars($msg['id']); ?>">
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

                <form class="chat-input-area" method="post">
                    <input type="hidden" name="receiver_id" value="<?php echo $withUserId; ?>">
                    <input type="hidden" name="send_message" value="1">
                    <textarea class="msg-textarea" name="message" id="msgInput"
                        placeholder="Írj üzenetet..." rows="1" required
                        onkeydown="handleEnter(event)"></textarea>
                    <button type="submit" class="send-btn" title="Küldés">➤</button>
                </form>

            <?php else: ?>
                <div class="empty-chat">
                    <div class="empty-chat-icon">💬</div>
                    <div class="empty-chat-text">Válassz ki egy beszélgetést a bal oldali listából</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Report modal -->
    <div class="report-modal" id="reportMsgModal" style="display: none; position:fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); align-items: center; justify-content: center; z-index: 4000;">
        <div class="report-modal-content" style="background: var(--bg-glass); border: 1px solid var(--accent); border-radius: 16px; padding: 1.5rem; max-width: 400px; width: 90%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: var(--accent);">Üzenet bejelentése</h3>
                <button class="close-report-modal" style="background: none; border: none; color: var(--text-primary); font-size: 1.5rem; cursor: pointer;">✕</button>
            </div>
            <form method="post" id="reportMsgForm">
                <input type="hidden" name="message_id" id="reportMsgId">
                <input type="hidden" name="report_message" value="1">
                <textarea name="report_reason" class="report-form-textarea" required placeholder="Kérjük, részletezd a problémát..." style="width:100%; background: var(--bg-input); border: 1px solid var(--border-glass); border-radius: 8px; padding: 0.5rem; color: var(--text-primary); margin-bottom: 1rem;"></textarea>
                <button type="submit" class="report-submit-btn" style="background: var(--accent-gradient); border: none; border-radius: 40px; padding: 0.6rem 1rem; color: var(--avatar-color); font-weight: bold; width:100%; cursor: pointer;">Bejelentés küldése</button>
            </form>
        </div>
    </div>

    <!-- Edit message modal -->
    <div class="edit-modal" id="editMsgModal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <span class="edit-modal-title">✏️ Üzenet szerkesztése</span>
                <button class="edit-modal-close" id="editModalClose">✕</button>
            </div>
            <form method="post" id="editMsgForm">
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

    <!-- Delete confirmation modal -->
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

    <script>
        // Auto-scroll
        const msgList = document.getElementById('messagesList');
        function scrollToBottom() {
            if (msgList) msgList.scrollTop = msgList.scrollHeight;
        }
        scrollToBottom();

        function handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                e.target.closest('form').submit();
            }
        }

        const ta = document.getElementById('msgInput');
        if (ta) {
            ta.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 140) + 'px';
            });
        }

        // Theme
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

        // Message menu
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

        // Delete message - modal
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

        // Eseménykezelők delegálással (dinamikus gombok miatt)
        document.addEventListener('click', function(e) {
            // Törlés gomb
            if (e.target.closest('.delete-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.delete-msg');
                openDeleteModal(btn.dataset.msgId);
            }
            // Szerkesztés gomb
            if (e.target.closest('.edit-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.edit-msg');
                openEditModal(btn.dataset.msgId, btn.dataset.msgText);
            }
            // Bejelentés gomb
            if (e.target.closest('.report-msg')) {
                e.preventDefault();
                e.stopPropagation();
                const btn = e.target.closest('.report-msg');
                reportMsgId.value = btn.dataset.msgId;
                reportModal.style.display = 'flex';
            }
        });

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
            if (e.key === 'Escape' && deleteModal.classList.contains('open')) {
                closeDeleteModal();
            }
        });

        // Edit message modal
        const editModal    = document.getElementById('editMsgModal');
        const editMsgId    = document.getElementById('editMsgId');
        const editMsgTA    = document.getElementById('editMsgTextarea');

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

        // Report message modal
        const reportModal = document.getElementById('reportMsgModal');
        const reportMsgId = document.getElementById('reportMsgId');
        const closeReportBtn = reportModal.querySelector('.close-report-modal');

        function closeReportModal() { reportModal.style.display = 'none'; }
        closeReportBtn.addEventListener('click', closeReportModal);
        reportModal.addEventListener('click', (e) => { if (e.target === reportModal) closeReportModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && reportModal.style.display === 'flex') closeReportModal();
        });

        <?php if (isset($_SESSION['report_success'])): unset($_SESSION['report_success']); ?>
            alert('Bejelentésedet rögzítettük. Köszönjük!');
        <?php endif; ?>
    </script>
</body>
</html>