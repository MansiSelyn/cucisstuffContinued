<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}
try {
    $conn = new PDO("mysql:host=localhost;dbname=cucidb", "root", "");
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
            'user_id' => $item['user_id']
        ]);
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
    }
    // Adatok lekérése
    $counts = ['users' => 0, 'items' => 0, 'reports' => 0];
    foreach (['users', 'items'] as $tbl) {
        try {
            $counts[$tbl] = $conn->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        } catch (PDOException $e) {}
    }
    // Report count: termék + üzenet reportok összege
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
    $counts['reports'] = (int)$cItem + (int)$cMsg;
    $totalItems = $counts[$view] ?? 0;
    $totalPages = $perPage ? (int)ceil($totalItems / $perPage) : 0;
    $items = $users = $reports = [];
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
        // Termék reportok
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
    $items = $users = $reports = [];
    $editItem = $editUser = null;
    $counts = ['users' => 0, 'items' => 0, 'reports' => 0];
}
// Segédfüggvény: lapozó link
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

        /* LIGHT MODE — Amber CRT (VILÁGOSABB SZÍNEK) */
        body.light-mode {
            --c-bg: #2a1a00;
            --c-panel: #3a2400;
            --c-border: #6a4500;
            --c-border2: #9a6500;
            --c-green: #ffcc00;
            --c-green-dim: #9a7000;
            --c-green-mid: #e69900;
            --c-amber: #ff8533;
            --c-red: #ff4433;
            --c-text: #f0c080;
            --c-muted: #9a6a45;
            --c-scan: rgba(255, 204, 0, 0.05);
            --c-glow: 0 0 10px rgba(255, 204, 0, 0.5);
            --c-glow-strong: 0 0 25px rgba(255, 204, 0, 0.7);
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

        /* CRT Scanlines */
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

        /* CRT vignette */
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

        /* CRT flicker */
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

        /* Phosphor glow on text */
        .glow {
            text-shadow: var(--c-glow);
        }

        .glow-strong {
            text-shadow: var(--c-glow-strong);
        }

        /* ═══════════ TOP CHROME ═══════════ */
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
            scrollbar-width: none;
        }

        .chrome-nav::-webkit-scrollbar {
            display: none;
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

        /* Theme toggle */
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

        /* ═══════════ MAIN LAYOUT ═══════════ */
        .terminal-body {
            max-width: 1700px;
            margin: 0 auto;
            padding: 20px 16px;
        }

        /* Section header */
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

        /* ═══════════ BANNERS ═══════════ */
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

        /* ═══════════ DATA TABLE ═══════════ */
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

        /* ═══════════ ACTION BUTTONS ═══════════ */
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

        /* ═══════════ EMPTY STATE ═══════════ */
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

        /* ═══════════ EDIT FORM ═══════════ */
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

        /* ═══════════ MAIN DASHBOARD ═══════════ */
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

        /* ═══════════ PAGINATION ═══════════ */
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

        /* ═══════════ PRODUCT MODAL ═══════════ */
        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 4000;
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

        /* Lightbox */
        .lightbox-overlay {
            position: fixed;
            inset: 0;
            z-index: 5000;
            background: rgba(0, 0, 0, 0.97);
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

        /* view-item-btn (report táblában) */
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
        }

        .view-item-btn:hover {
            color: var(--c-green);
        }

        /* Responsive */
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
                    <?php foreach (
                        [
                            ['REPORTOK', 'reports', '⚠', 'Bejelentett hirdetések'],
                            ['FELHASZNÁLÓK', 'users', '◈', 'Regisztrált fiókok'],
                            ['TERMÉKEK', 'items', '◧', 'Aktív hirdetések'],
                        ] as [$label, $key, $icon, $sub]
                    ): ?>
                        <a href="admin.php?view=<?= $key ?>" style="text-decoration:none">
                            <div class="dash-card">
                                <div class="dash-label"><?= $icon ?> <?= $label ?></div>
                                <div class="dash-number"><?= number_format($counts[$key]) ?></div>
                                <div class="dash-sublabel"><?= $sub ?></div>
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
                                            <button class="act act-del" onclick="doDelete('report',<?= $r['id'] ?>,'<?= $r['report_type'] ?>')">TÖRL</button>
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
                                                <button class="act act-del" onclick="doDelete('user',<?= $u['id'] ?>)">TÖRL</button>
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
                                        <td><?= htmlspecialchars($it['title']) ?></td>
                                        <td><?= htmlspecialchars($it['seller_name']) ?></td>
                                        <td class="mono"><?= number_format($it['price'], 0, ',', ' ') ?> FT</td>
                                        <td class="wrap"><?= htmlspecialchars(mb_substr($it['description'], 0, 50)) ?>...</td>
                                        <td class="mono"><?= date('Y-m-d', strtotime($it['created_at'])) ?></td>
                                        <td>
                                            <a href="admin.php?view=items&id=<?= $it['id'] ?>&page=<?= $page ?>" class="act act-edit">EDIT</a>
                                            <button class="act act-del" onclick="doDelete('item','<?= $it['id'] ?>')">TÖRL</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <!-- LAPOZÁS -->
            <?php if ($totalPages > 1 && !in_array($view, ['main']) && !$editId): ?>
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
                <div class="product-description selectable" id="productDescription"></div>
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
    <script>
        // ── TÉMA ──
        (function() {
            const KEY = 'admin_theme',
                body = document.body,
                btn = document.getElementById('themeToggleBtn');

            function apply(t) {
                body.classList.toggle('light-mode', t === 'light');
                localStorage.setItem(KEY, t);
                btn.textContent = t === 'light' ? 'DARK' : 'LIGHT';
            }
            apply(localStorage.getItem(KEY) || 'dark');
            btn.addEventListener('click', () => apply(body.classList.contains('light-mode') ? 'dark' : 'light'));
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
        // ── TÖRLÉSEK ──
        const CONFIRM_MSG = {
            user: 'Biztosan törlöd a felhasználót? Minden adata elvész!',
            item: 'Biztosan törlöd a terméket?',
            report: 'Biztosan törlöd a reportot?'
        };
        const POST_KEY = {
            user: 'delete_user=1&user_id=',
            item: 'delete_item=1&item_id=',
            report: 'delete_report=1&report_id='
        };

        function doDelete(type, id, subtype) {
            if (!confirm(CONFIRM_MSG[type] || 'Biztosan törlöd?')) return;
            let body = POST_KEY[type] + id;
            if (type === 'report' && subtype) body += '&report_type=' + subtype;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body
            }).then(() => location.reload());
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
        document.querySelectorAll('.view-item-btn').forEach(btn => btn.addEventListener('click', function(e) {
            e.preventDefault();
            fetch('admin.php?get_item_data=' + this.dataset.itemId).then(r => r.json()).then(d => {
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
                <?php if (isset($_SESSION['user_id'])): ?>
                    const isOwner = parseInt(d.user_id) === <?= (int)$_SESSION['user_id'] ?>;
                    if (<?= $isAdmin ? 'true' : 'false' ?>) {
                        pm.menuCont.style.display = 'block';
                        pm.reportBtn.style.display = 'none';
                        pm.editBtn.style.display = 'block';
                        pm.delBtn.style.display = 'block';
                        pm.editBtn.onclick = () => location.href = 'admin.php?view=items&id=' + prodId;
                        pm.delBtn.onclick = () => {
                            if (confirm('Biztosan törlöd ezt a terméket?')) {
                                const f = document.createElement('form');
                                f.method = 'POST';
                                f.innerHTML = '<input type="hidden" name="item_id" value="' + d.id + '"><input type="hidden" name="delete_item" value="1">';
                                document.body.appendChild(f);
                                f.submit();
                            }
                        };
                    } else if (!isOwner) {
                        pm.menuCont.style.display = 'block';
                        pm.reportBtn.style.display = 'block';
                        pm.editBtn.style.display = 'none';
                        pm.delBtn.style.display = 'none';
                    } else pm.menuCont.style.display = 'none';
                <?php endif; ?>
                openPM();
            }).catch(() => alert('Betöltési hiba!'));
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
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if (pm.lbOver.classList.contains('active')) pm.lbOver.classList.remove('active');
                else if (pm.modal.classList.contains('active')) closePM();
            }
        });
        window.addEventListener('resize', () => {
            if (pm.modal.classList.contains('active')) adjustH();
        });
        document.getElementById('productBuyBtn').addEventListener('click', () => alert('Vásárlás funkció még nem elérhető!'));
    </script>
</body>

</html>