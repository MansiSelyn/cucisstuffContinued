<?php
session_start();

// Adatbázis kapcsolat
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cucidb";

// Ha nincs bejelentkezve, irányítás az index.php-ra
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Admin jogosultság ellenőrzése
    $isAdmin = false;
    if (isset($_SESSION['user_id'])) {
        $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
        $adminCheck->execute([$_SESSION['user_id']]);
        $isAdmin = $adminCheck->fetchColumn() > 0;
    }

    // Ha nem admin, átirányítás a main.php-ra
    if (!$isAdmin) {
        header("Location: main.php");
        exit();
    }

    // --- AJAX kérés termékadatok lekéréséhez (admin modálhoz) ---
    if (isset($_GET['get_item_data']) && !empty($_GET['get_item_data'])) {
        header('Content-Type: application/json');
        $itemId = $_GET['get_item_data'];
        try {
            $stmt = $conn->prepare("SELECT i.*, u.username as seller_name FROM items i JOIN users u ON i.user_id = u.id WHERE i.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                echo json_encode(['error' => 'Termék nem található']);
                exit;
            }
            // Képek lekérése
            $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY sort_order");
            $imgStmt->execute([$itemId]);
            $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $data = [
                'id'          => $item['id'],
                'title'       => $item['title'],
                'price'       => number_format($item['price'], 0, ',', ' ') . ' Ft',
                'seller'      => $item['seller_name'],
                'date'        => date('Y-m-d', strtotime($item['created_at'])),
                'description' => $item['description'],
                'images'      => $images,
                'user_id'     => $item['user_id']
            ];
            echo json_encode($data);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // Aktív nézet kezelése
    $view = isset($_GET['view']) ? $_GET['view'] : 'main';
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $editId = isset($_GET['id']) ? $_GET['id'] : null;

    // Lapozás beállításai
    $itemsPerPage = 25;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $itemsPerPage;

    // Üzenetek
    $message = '';
    $error = '';

    // Műveletek kezelése
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Felhasználó törlése
        if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
            $userId = $_POST['user_id'];
            if ($userId != $_SESSION['user_id']) {
                $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$userId]);
                $message = "Felhasználó sikeresen törölve.";
            } else {
                $error = "Nem törölheted saját magad!";
            }
        }

        // Termék törlése
        if (isset($_POST['delete_item']) && isset($_POST['item_id'])) {
            $itemId = $_POST['item_id'];
            // Képek mappa törlése
            $uploadDir = 'uploads/' . $itemId . '/';
            if (file_exists($uploadDir)) {
                $files = glob($uploadDir . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($uploadDir);
            }
            $deleteStmt = $conn->prepare("DELETE FROM items WHERE id = ?");
            $deleteStmt->execute([$itemId]);
            $message = "Termék sikeresen törölve.";
        }

        // Report törlése
        if (isset($_POST['delete_report']) && isset($_POST['report_id'])) {
            $reportId = $_POST['report_id'];
            $deleteStmt = $conn->prepare("DELETE FROM reports WHERE id = ?");
            $deleteStmt->execute([$reportId]);
            $message = "Report sikeresen törölve.";
        }

        // Termék módosítása
        if (isset($_POST['update_item']) && isset($_POST['item_id'])) {
            $itemId = $_POST['item_id'];
            $title = trim($_POST['item_title'] ?? '');
            $description = trim($_POST['item_description'] ?? '');
            $price = trim($_POST['item_price'] ?? '');

            if ($title === '' || $description === '' || $price === '') {
                $error = 'Minden mező kitöltése kötelező!';
            } elseif (!is_numeric($price) || floatval($price) < 0) {
                $error = 'Az ár csak pozitív szám lehet!';
            } else {
                $updateStmt = $conn->prepare("UPDATE items SET title = ?, description = ?, price = ? WHERE id = ?");
                $updateStmt->execute([$title, $description, floatval($price), $itemId]);
                $message = "Termék sikeresen módosítva.";
                header("Location: admin.php?view=items&page=" . $page);
                exit();
            }
        }

        // Felhasználó módosítása
        if (isset($_POST['update_user']) && isset($_POST['user_id'])) {
            $userId = $_POST['user_id'];
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($username === '' || $email === '') {
                $error = 'Minden mező kitöltése kötelező!';
            } elseif (strpos($email, '@') === false) {
                $error = 'Érvénytelen email cím!';
            } else {
                $checkStmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $checkStmt->execute([$username, $email, $userId]);
                if ($checkStmt->fetchColumn() > 0) {
                    $error = 'A felhasználónév vagy email már foglalt!';
                } else {
                    $updateStmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                    $updateStmt->execute([$username, $email, $userId]);
                    $message = "Felhasználó sikeresen módosítva.";
                    header("Location: admin.php?view=users&page=" . $page);
                    exit();
                }
            }
        }
    }

    // Összes elem számának lekérése a különböző nézetekhez
    if ($view == 'users') {
        $totalStmt = $conn->query("SELECT COUNT(*) FROM users");
    } elseif ($view == 'items') {
        $totalStmt = $conn->query("SELECT COUNT(*) FROM items");
    } elseif ($view == 'reports') {
        // Ellenőrizzük, hogy létezik-e a reports tábla
        try {
            $totalStmt = $conn->query("SELECT COUNT(*) FROM reports");
        } catch (PDOException $e) {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_id CHAR(12) NOT NULL,
                    user_id INT NOT NULL,
                    reason TEXT NOT NULL,
                    status ENUM('pending', 'resolved', 'dismissed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB
            ");
            $totalStmt = $conn->query("SELECT COUNT(*) FROM reports");
        }
    }

    if (isset($totalStmt)) {
        $totalItems = $totalStmt->fetchColumn();
        $totalPages = ceil($totalItems / $itemsPerPage);
    } else {
        $totalPages = 0;
    }

    // Adatok lekérése a különböző nézetekhez
    $items = [];
    $users = [];
    $reports = [];

    if ($view == 'items' && !$editId) {
        $stmt = $conn->prepare("
            SELECT i.*, u.username as seller_name 
            FROM items i 
            JOIN users u ON i.user_id = u.id 
            ORDER BY i.created_at DESC 
            LIMIT :offset, :itemsPerPage
        ");
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view == 'users' && !$editId) {
        $stmt = $conn->prepare("
            SELECT u.*, 
                   (SELECT COUNT(*) FROM admins WHERE user_id = u.id) as is_admin,
                   (SELECT COUNT(*) FROM items WHERE user_id = u.id) as item_count
            FROM users u 
            ORDER BY u.created_at DESC 
            LIMIT :offset, :itemsPerPage
        ");
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view == 'reports') {
        $stmt = $conn->prepare("
            SELECT r.*, i.title as item_title, u.username as reporter_name,
                   i.user_id as item_owner_id, owner.username as item_owner_name
            FROM reports r
            JOIN items i ON r.item_id = i.id
            JOIN users u ON r.user_id = u.id
            JOIN users owner ON i.user_id = owner.id
            ORDER BY r.created_at DESC
            LIMIT :offset, :itemsPerPage
        ");
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Szerkesztendő elem lekérése
    $editItem = null;
    $editUser = null;
    if ($editId) {
        if ($view == 'items') {
            $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
            $stmt->execute([$editId]);
            $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($view == 'users') {
            $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
            $stmt->execute([$editId]);
            $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

} catch(PDOException $e) {
    $error = "Adatbázis hiba: " . $e->getMessage();
    $totalPages = 0;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Cuci's Stuff</title>
    <style>
        /* ============================================
           ALAP STÍLUSOK - DARK MÓD ALAPÉRTELMEZETT
        ============================================ */
        * {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        input, textarea {
            user-select: text;
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
        }
        body {
            background: #0a0a0a;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            position: relative;
            overflow-x: hidden;
            transition: background 0.3s ease;
        }
        .noise {
            position: fixed;
            top: -50%;
            left: -50%;
            right: -50%;
            bottom: -50%;
            width: 200%;
            height: 200%;
            background: transparent url('data:image/svg+xml,%3Csvg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"%3E%3Cfilter id="noise"%3E%3CfeTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3" stitchTiles="stitch"/%3E%3C/filter%3E%3Crect width="100%25" height="100%25" filter="url(%23noise)" opacity="0.08"/%3E%3C/svg%3E') repeat;
            pointer-events: none;
            z-index: 0;
            opacity: 0.4;
        }
        .orb-1, .orb-2 {
            position: fixed;
            width: min(60vw, 600px);
            height: min(60vw, 600px);
            border-radius: 50%;
            filter: blur(min(8vw, 80px));
            pointer-events: none;
            z-index: 0;
            opacity: 0.3;
            transition: background 0.3s ease;
        }
        .orb-1 {
            top: -20vh;
            left: -20vw;
            background: radial-gradient(circle at 30% 30%, #ff8c00, transparent 70%);
            animation: float1 20s infinite ease-in-out;
        }
        .orb-2 {
            bottom: -20vh;
            right: -20vw;
            background: radial-gradient(circle at 70% 70%, #ff5500, transparent 70%);
            animation: float2 25s infinite ease-in-out;
        }
        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(10vw, 10vh) scale(1.1); }
            66% { transform: translate(-5vw, 15vh) scale(0.9); }
        }
        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(-10vw, -10vh) scale(1.2); }
            66% { transform: translate(5vw, -15vh) scale(0.8); }
        }

        /* Admin konténer */
        .admin-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem 1rem 6rem;
            position: relative;
            z-index: 10;
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .admin-title {
            font-size: 2rem;
            color: #ff8c00;
            text-shadow: 0 0 20px rgba(255, 140, 0, 0.5);
            margin: 0;
        }
        .admin-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .admin-btn {
            padding: 0.75rem 1.5rem;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid rgba(255, 140, 0, 0.3);
            border-radius: 50px;
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .admin-btn:hover {
            background: rgba(255, 140, 0, 0.25);
            border-color: #ff8c00;
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(255, 140, 0, 0.3);
        }
        .admin-btn.active {
            background: rgba(255, 140, 0, 0.3);
            border-color: #ff8c00;
            color: #ff8c00;
        }
        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.3);
        }

        /* Admin táblázat */
        .admin-table {
            width: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 140, 0, 0.2);
            border-radius: 16px;
            overflow-x: auto;
            margin-bottom: 2rem;
        }
        .admin-table table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        .admin-table th {
            background: rgba(255, 140, 0, 0.15);
            color: #ff8c00;
            font-weight: 600;
            text-align: left;
            padding: 1rem;
            border-bottom: 2px solid rgba(255, 140, 0, 0.3);
            white-space: nowrap;
        }
        .admin-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 140, 0, 0.1);
            color: #fff;
            white-space: nowrap;
        }
        .admin-table td:last-child {
            white-space: nowrap;
            min-width: 140px;
        }
        .admin-table tr:last-child td {
            border-bottom: none;
        }
        .admin-table tr:hover {
            background: rgba(255, 140, 0, 0.05);
        }

        /* Action gombok */
        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin: 0 0.2rem;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            min-width: 70px;
        }
        .edit-btn {
            background: rgba(0, 123, 255, 0.2);
            color: #66b0ff;
            border: 1px solid rgba(0, 123, 255, 0.3);
        }
        .edit-btn:hover {
            background: rgba(0, 123, 255, 0.3);
            border-color: #0066ff;
            transform: translateY(-2px);
        }
        .delete-btn {
            background: rgba(255, 0, 0, 0.2);
            color: #ff6666;
            border: 1px solid rgba(255, 0, 0, 0.3);
        }
        .delete-btn:hover {
            background: rgba(255, 0, 0, 0.3);
            border-color: #ff0000;
            transform: translateY(-2px);
        }
        .view-btn {
            background: rgba(255, 140, 0, 0.2);
            color: #ff8c00;
            border: 1px solid rgba(255, 140, 0, 0.3);
        }
        .view-btn:hover {
            background: rgba(255, 140, 0, 0.3);
            transform: translateY(-2px);
        }

        /* Üzenetek */
        .message-banner {
            background: rgba(0, 200, 0, 0.1);
            border: 1px solid rgba(0, 200, 0, 0.3);
            color: #66ff66;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .error-banner {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid rgba(255, 0, 0, 0.3);
            color: #ff6666;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        .status-resolved {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        .status-dismissed {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }

        /* Lapozás */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 2rem 0;
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(20px);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            border: 1px solid rgba(255, 140, 0, 0.2);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            z-index: 100;
        }
        .pagination-btn {
            padding: 0.5rem 1.5rem;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid rgba(255, 140, 0, 0.3);
            border-radius: 50px;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            min-width: 100px;
            text-align: center;
        }
        .pagination-btn:hover:not(.disabled) {
            background: rgba(255, 140, 0, 0.2);
            color: #ff8c00;
            transform: translateY(-2px);
        }
        .pagination-btn.disabled {
            opacity: 0.3;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .page-info {
            color: #fff;
            padding: 0.5rem 1rem;
            background: rgba(255, 140, 0, 0.1);
            border-radius: 50px;
            font-weight: 500;
        }

        /* Szerkesztő kártya */
        .edit-card {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 140, 0, 0.2);
            border-radius: 24px;
            padding: 2rem;
            max-width: 600px;
            margin: 0 auto 2rem;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5), 0 0 40px rgba(255, 140, 0, 0.1);
        }
        .edit-card h2 {
            color: #ff8c00;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }
        .edit-card .form-group {
            margin-bottom: 1.5rem;
        }
        .edit-card .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: #fff;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .edit-card .form-input,
        .edit-card .form-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 140, 0, 0.2);
            border-radius: 8px;
            color: #fff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        .edit-card .form-input:focus,
        .edit-card .form-textarea:focus {
            outline: none;
            border-color: #ff8c00;
            background: rgba(255, 140, 0, 0.1);
        }
        .edit-card .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        .edit-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .save-btn {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #ff8c00, #ff5500);
            border: none;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(255, 140, 0, 0.4);
        }
        .cancel-btn {
            padding: 0.75rem 2rem;
            background: transparent;
            border: 1px solid rgba(255, 140, 0, 0.2);
            border-radius: 50px;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .cancel-btn:hover {
            border-color: #ff8c00;
            color: #ff8c00;
        }

        /* Téma váltó gomb */
        .theme-toggle-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
        }
        .theme-toggle-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 140, 0, 0.3);
            color: #ff8c00;
            font-size: 1.4rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        .theme-toggle-btn:hover {
            transform: scale(1.1);
            background: rgba(255, 140, 0, 0.2);
            border-color: #ff8c00;
        }

        /* Főoldal kártya stílusok - dark mód */
        .main-welcome-card {
            text-align: center;
            padding: 3rem;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 24px;
        }
        .main-welcome-card h2 {
            color: #ff8c00;
            margin-bottom: 1rem;
        }
        .main-welcome-card p {
            color: #fff;
            margin-bottom: 2rem;
        }
        .main-feature-card {
            text-align: center;
        }
        .main-feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .main-feature-card h3 {
            color: #ff8c00;
        }
        .main-feature-card p {
            color: rgba(255, 255, 255, 0.6);
        }

        /* ============================================
           TERMÉKMODÁL STÍLUSOK
        ============================================ */
        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 4000;
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
            border: 1px solid #ff8c00;
            color: #ff8c00;
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
            background: #ff8c00;
            color: black;
            transform: scale(1.1);
        }
        .product-menu {
            position: relative;
        }
        .product-menu-button {
            width: 48px;
            height: 48px;
            background: rgba(20, 20, 20, 0.8);
            border: 1px solid #ff8c00;
            border-radius: 50%;
            color: #ff8c00;
            font-size: 2rem;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            backdrop-filter: blur(5px);
        }
        .product-menu-button:hover {
            background: #ff8c00;
            color: black;
            transform: scale(1.1);
        }
        .product-menu-content {
            position: absolute;
            top: 55px;
            right: 0;
            min-width: 180px;
            background: rgba(10, 10, 10, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid #ff8c00;
            border-radius: 12px;
            padding: 0.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), 0 0 30px rgba(255, 140, 0, 0.2);
            display: none;
            z-index: 101;
        }
        .product-menu-content.show {
            display: block;
        }
        .product-menu-item {
            width: 100%;
            padding: 0.75rem 1rem;
            background: transparent;
            border: none;
            color: white;
            text-align: left;
            font-size: 1rem;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        .product-menu-item:hover {
            background: rgba(255, 140, 0, 0.2);
            color: #ff8c00;
        }
        .product-menu-item.delete:hover {
            background: rgba(255, 0, 0, 0.2);
            color: #ff0000;
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
            border: 1px solid rgba(255, 140, 0, 0.2);
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
        .product-no-image-placeholder {
            text-align: center;
            font-size: 1.2rem;
            padding: 2rem;
            user-select: none;
            -webkit-user-select: none;
            color: #ff8c00;
        }
        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: 2px solid #ff8c00;
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
            background: #ff8c00;
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
            border-color: #ff8c00;
            transform: translateY(-2px);
        }
        .product-thumbnail.active {
            border-color: #ff8c00;
            box-shadow: 0 0 20px rgba(255, 140, 0, 0.3);
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
            border: 1px solid rgba(255, 140, 0, 0.2);
            height: 100%;
            overflow-y: auto;
            user-select: none;
        }
        .product-title {
            font-size: 2.5rem;
            color: #ff8c00;
            margin: 0;
            word-break: break-word;
            line-height: 1.2;
            font-weight: bold;
        }
        .product-price {
            font-size: 3rem;
            font-weight: bold;
            color: #ff8c00;
            text-shadow: 0 0 30px rgba(255, 140, 0, 0.3);
        }
        .product-seller {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.7);
        }
        .product-seller strong {
            color: #ff8c00;
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
            border: 1px solid rgba(255, 140, 0, 0.2);
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
            z-index: 5000;
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
            border: 2px solid #ff8c00;
            border-radius: 8px;
        }
        .lightbox-close {
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid #ff8c00;
            color: #ff8c00;
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
            background: #ff8c00;
            color: black;
            transform: scale(1.1);
        }

        /* ============================================
           VILÁGOS MÓD STÍLUSOK
        ============================================ */
        body.light-theme {
            background: #d8e0b0;
        }
        body.light-theme .orb-1 {
            background: radial-gradient(circle at 30% 30%, #B0CB1F, transparent 70%);
        }
        body.light-theme .orb-2 {
            background: radial-gradient(circle at 70% 70%, #8aA000, transparent 70%);
        }
        body.light-theme .admin-title {
            color: #7a9200;
            text-shadow: 0 0 20px rgba(176, 203, 31, 0.45);
        }
        body.light-theme .admin-btn {
            background: rgba(176, 203, 31, 0.15);
            border-color: rgba(140, 170, 10, 0.3);
            color: #1a1f00;
        }
        body.light-theme .admin-btn:hover {
            background: rgba(176, 203, 31, 0.3);
            border-color: #B0CB1F;
            color: #7a9200;
        }
        body.light-theme .admin-btn.active {
            background: rgba(176, 203, 31, 0.35);
            border-color: #B0CB1F;
            color: #7a9200;
        }
        body.light-theme .back-btn {
            background: rgba(0, 0, 0, 0.05);
            border-color: rgba(0, 0, 0, 0.15);
            color: #1a1f00;
        }
        body.light-theme .back-btn:hover {
            background: rgba(0, 0, 0, 0.1);
        }
        body.light-theme .admin-table {
            background: rgba(240, 248, 210, 0.85);
            border-color: rgba(140, 170, 10, 0.25);
        }
        body.light-theme .admin-table th {
            background: rgba(176, 203, 31, 0.2);
            color: #7a9200;
            border-bottom-color: #B0CB1F;
        }
        body.light-theme .admin-table td {
            color: #1a1f00;
            border-bottom-color: rgba(140, 170, 10, 0.2);
        }
        body.light-theme .admin-table a {
            color: #7a9200;
        }
        body.light-theme .admin-table a:hover {
            color: #B0CB1F;
        }
        body.light-theme .message-banner {
            background: rgba(0, 200, 0, 0.15);
            border-color: rgba(0, 200, 0, 0.4);
            color: #2e7d32;
        }
        body.light-theme .error-banner {
            background: rgba(255, 0, 0, 0.1);
            border-color: rgba(255, 0, 0, 0.3);
            color: #c62828;
        }
        body.light-theme .pagination {
            background: rgba(240, 248, 210, 0.95);
            border-color: rgba(140, 170, 10, 0.3);
        }
        body.light-theme .pagination-btn {
            background: rgba(176, 203, 31, 0.15);
            border-color: rgba(140, 170, 10, 0.3);
            color: #1a1f00;
        }
        body.light-theme .pagination-btn:hover:not(.disabled) {
            background: rgba(176, 203, 31, 0.3);
            color: #7a9200;
        }
        body.light-theme .page-info {
            color: #1a1f00;
            background: rgba(176, 203, 31, 0.15);
        }
        body.light-theme .edit-card {
            background: rgba(240, 248, 210, 0.92);
            border-color: rgba(140, 170, 10, 0.3);
        }
        body.light-theme .edit-card h2 {
            color: #7a9200;
        }
        body.light-theme .edit-card .form-label {
            color: #1a1f00;
        }
        body.light-theme .edit-card .form-input,
        body.light-theme .edit-card .form-textarea {
            background: rgba(255, 255, 255, 0.8);
            border-color: rgba(140, 170, 10, 0.3);
            color: #1a1f00;
        }
        body.light-theme .edit-card .form-input:focus,
        body.light-theme .edit-card .form-textarea:focus {
            border-color: #B0CB1F;
            background: rgba(176, 203, 31, 0.1);
        }
        body.light-theme .save-btn {
            background: linear-gradient(135deg, #B0CB1F, #8aA000);
            color: #1a1f00;
        }
        body.light-theme .cancel-btn {
            color: #1a1f00;
            border-color: rgba(140, 170, 10, 0.3);
        }
        body.light-theme .cancel-btn:hover {
            border-color: #B0CB1F;
            color: #7a9200;
        }
        body.light-theme .theme-toggle-btn {
            background: rgba(240, 248, 210, 0.9);
            border-color: #B0CB1F;
            color: #7a9200;
        }
        body.light-theme .theme-toggle-btn:hover {
            background: #B0CB1F;
            color: #1a1f00;
        }
        body.light-theme .status-pending {
            background: rgba(255, 193, 7, 0.25);
            color: #b26500;
        }
        body.light-theme .status-resolved {
            background: rgba(40, 167, 69, 0.2);
            color: #1b5e20;
        }
        body.light-theme .status-dismissed {
            background: rgba(108, 117, 125, 0.2);
            color: #495057;
        }
        body.light-theme .orb-1,
        body.light-theme .orb-2,
        body.light-theme .noise {
            opacity: 0.5;
        }
        body.light-theme .main-welcome-card {
            background: rgba(240, 248, 210, 0.7);
        }
        body.light-theme .main-welcome-card h2 {
            color: #7a9200;
        }
        body.light-theme .main-welcome-card p {
            color: #1a1f00;
        }
        body.light-theme .main-feature-card h3 {
            color: #7a9200;
        }
        body.light-theme .main-feature-card p {
            color: #4a5a00;
        }
        body.light-theme .product-no-image-placeholder {
            color: #7a9200;
        }
        body.light-theme .product-title,
        body.light-theme .product-price {
            color: #7a9200;
            text-shadow: 0 0 30px rgba(176, 203, 31, 0.45);
        }
        body.light-theme .product-seller strong {
            color: #7a9200;
        }
        body.light-theme .product-modal-close,
        body.light-theme .product-menu-button {
            border-color: #7a9200;
            color: #7a9200;
        }
        body.light-theme .product-modal-close:hover,
        body.light-theme .product-menu-button:hover {
            background: #7a9200;
            color: #1a1f00;
        }
        body.light-theme .gallery-nav {
            border-color: #7a9200;
        }
        body.light-theme .gallery-nav:hover {
            background: #7a9200;
        }
        body.light-theme .product-thumbnail.active {
            border-color: #7a9200;
            box-shadow: 0 0 20px rgba(176, 203, 31, 0.3);
        }
        body.light-theme .product-menu-content {
            background: rgba(240, 248, 210, 0.95);
            border-color: #B0CB1F;
        }
        body.light-theme .product-menu-item {
            color: #1a1f00;
        }
        body.light-theme .product-menu-item:hover {
            background: rgba(176, 203, 31, 0.2);
            color: #7a9200;
        }
        body.light-theme .product-menu-item.delete:hover {
            background: rgba(255, 0, 0, 0.2);
            color: #c62828;
        }

        /* ============================================
           LIGHT MÓD - TERMÉKMODÁL KÜLÖNLEGES ÁTALAKÍTÁSA
        ============================================ */
        body.light-theme .product-modal-overlay {
            background: rgba(216, 224, 176, 0.98);
        }
        body.light-theme .product-modal-card {
            background: #f5f7e8;
        }
        body.light-theme .product-gallery {
            background: #eef2da;
            border: 1px solid #B0CB1F;
        }
        body.light-theme .product-main-image-container {
            background: #ffffff;
            border-color: #B0CB1F;
        }
        body.light-theme .product-details {
            background: #ffffff;
            border-color: #B0CB1F;
        }
        body.light-theme .product-description {
            background: #f9fbe7;
            border-color: #B0CB1F;
            color: #1a1f00;
        }
        body.light-theme .product-seller {
            color: #4a5a00;
        }
        body.light-theme .product-date {
            color: #7a8a2a;
        }
        body.light-theme .product-buy-btn {
            background: linear-gradient(135deg, #B0CB1F, #8aA000);
            color: #1a1f00;
        }
        body.light-theme .product-buy-btn:hover {
            box-shadow: 0 10px 30px rgba(176, 203, 31, 0.5);
        }
        body.light-theme .product-thumbnail {
            background: #f0f4e0;
            border-color: #d0dc90;
        }
        body.light-theme .product-thumbnail:hover,
        body.light-theme .product-thumbnail.active {
            border-color: #B0CB1F;
        }

        @media (max-width: 1200px) {
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
        @media (max-width: 600px) {
            .pagination-container {
                padding: 0.5rem 1rem;
            }
            .pagination-btn {
                padding: 0.4rem 1rem;
                font-size: 0.9rem;
            }
            .modal-card {
                padding: 2rem 1.2rem 1.5rem;
            }
            .product-modal-card {
                padding: 0.5rem;
            }
            .product-gallery {
                height: 40vh;
            }
            .product-details {
                padding: 1rem;
            }
            .product-title {
                font-size: 1.5rem;
            }
            .product-price {
                font-size: 2rem;
            }
            .product-description {
                padding: 1rem;
                font-size: 1rem;
            }
            .upload-btn .button-text,
            .admin-btn .button-text {
                display: none;
            }
            .product-modal-header {
                top: 0.5rem;
                right: 0.5rem;
            }
            .lightbox-content {
                flex-direction: column;
                align-items: center;
            }
            .lightbox-image {
                max-width: 95vw;
                max-height: calc(95vh - 70px);
            }
        }
    </style>
</head>

<body>
    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

    <div class="theme-toggle-container">
        <button class="theme-toggle-btn" id="themeToggleBtn" title="Témaváltás">🌙</button>
    </div>

    <div class="admin-container">
        <div class="admin-header">
            <h1 class="admin-title">Admin Panel</h1>
            <div class="admin-buttons">
                <a href="admin.php" class="admin-btn <?php echo $view == 'main' ? 'active' : ''; ?>">
                    <span>🏠</span> Főoldal
                </a>
                <a href="admin.php?view=reports" class="admin-btn <?php echo $view == 'reports' ? 'active' : ''; ?>">
                    <span>⚠️</span> Reportok
                </a>
                <a href="admin.php?view=users" class="admin-btn <?php echo $view == 'users' ? 'active' : ''; ?>">
                    <span>👥</span> Felhasználók
                </a>
                <a href="admin.php?view=items" class="admin-btn <?php echo $view == 'items' ? 'active' : ''; ?>">
                    <span>📦</span> Termékek
                </a>
                <a href="main.php" class="admin-btn back-btn">
                    <span>←</span> Vissza a főoldalra
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message-banner"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-banner"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- ==================== NÉZETEK ==================== -->
        <?php if ($editItem && $view == 'items'): ?>
            <div class="edit-card">
                <h2>Termék szerkesztése</h2>
                <form method="post">
                    <input type="hidden" name="item_id" value="<?php echo $editItem['id']; ?>">
                    <div class="form-group">
                        <label class="form-label">Cím</label>
                        <input type="text" name="item_title" class="form-input" value="<?php echo htmlspecialchars($editItem['title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Leírás</label>
                        <textarea name="item_description" class="form-textarea" required><?php echo htmlspecialchars($editItem['description']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ár (Ft)</label>
                        <input type="number" name="item_price" class="form-input" value="<?php echo $editItem['price']; ?>" min="0" step="1" required>
                    </div>
                    <div class="edit-actions">
                        <button type="submit" name="update_item" class="save-btn">Mentés</button>
                        <a href="admin.php?view=items&page=<?php echo $page; ?>" class="cancel-btn">Mégse</a>
                    </div>
                </form>
            </div>

        <?php elseif ($editUser && $view == 'users'): ?>
            <div class="edit-card">
                <h2>Felhasználó szerkesztése</h2>
                <form method="post">
                    <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
                    <div class="form-group">
                        <label class="form-label">Felhasználónév</label>
                        <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($editUser['username']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($editUser['email']); ?>" required>
                    </div>
                    <div class="edit-actions">
                        <button type="submit" name="update_user" class="save-btn">Mentés</button>
                        <a href="admin.php?view=users&page=<?php echo $page; ?>" class="cancel-btn">Mégse</a>
                    </div>
                </form>
            </div>

        <?php elseif ($view == 'main'): ?>
            <div class="main-welcome-card">
                <h2>Üdvözöljük az Admin Panelben!</h2>
                <p>Válasszon a fenti menüpontok közül:</p>
                <div style="display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap;">
                    <div class="main-feature-card">
                        <div class="main-feature-icon">⚠️</div>
                        <h3>Reportok</h3>
                        <p>Bejelentett hirdetések kezelése</p>
                    </div>
                    <div class="main-feature-card">
                        <div class="main-feature-icon">👥</div>
                        <h3>Felhasználók</h3>
                        <p>Felhasználók listázása és szerkesztése</p>
                    </div>
                    <div class="main-feature-card">
                        <div class="main-feature-icon">📦</div>
                        <h3>Termékek</h3>
                        <p>Hirdetések listázása és szerkesztése</p>
                    </div>
                </div>
            </div>

        <?php elseif ($view == 'reports'): ?>
            <h2 class="reports-title" style="color: #ff8c00; margin-bottom: 1.5rem;">Reportok</h2>
            <?php if (empty($reports)): ?>
                <p style="text-align: center; padding: 2rem; color: rgba(255,255,255,0.6);">Nincsenek megjeleníthető reportok.</p>
            <?php else: ?>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Termék</th>
                                <th>Bejelentő</th>
                                <th>Tulajdonos</th>
                                <th>Indok</th>
                                <th>Státusz</th>
                                <th>Dátum</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo $report['id']; ?></td>
                                    <td>
                                        <button class="view-item-btn" data-item-id="<?php echo $report['item_id']; ?>" style="background: none; border: none; color: #ff8c00; cursor: pointer; text-decoration: underline;">
                                            <?php echo htmlspecialchars($report['item_title']); ?>
                                        </button>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['item_owner_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['reason']); ?></td>
                                    <td><span class="status-badge status-<?php echo $report['status']; ?>"><?php echo $report['status']; ?></span></td>
                                    <td><?php echo date('Y-m-d', strtotime($report['created_at'])); ?></td>
                                    <td>
                                        <a href="admin.php?view=items&id=<?php echo $report['item_id']; ?>" class="action-btn view-btn">Termék</a>
                                        <button type="button" class="action-btn delete-btn" onclick="deleteReport(<?php echo $report['id']; ?>)">Törlés</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($view == 'users'): ?>
            <h2 class="users-title" style="color: #ff8c00; margin-bottom: 1.5rem;">Felhasználók</h2>
            <?php if (empty($users)): ?>
                <p style="text-align: center; padding: 2rem; color: rgba(255,255,255,0.6);">Nincsenek megjeleníthető felhasználók.</p>
            <?php else: ?>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Felhasználónév</th>
                                <th>Email</th>
                                <th>Admin</th>
                                <th>Termékek</th>
                                <th>Regisztráció</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo $user['is_admin'] ? 'Igen' : 'Nem'; ?></td>
                                    <td><?php echo $user['item_count']; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <a href="admin.php?view=users&id=<?php echo $user['id']; ?>&page=<?php echo $page; ?>" class="action-btn edit-btn">Szerkeszt</a>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button type="button" class="action-btn delete-btn" onclick="deleteUser(<?php echo $user['id']; ?>)">Törlés</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($view == 'items'): ?>
            <h2 class="items-title" style="color: #ff8c00; margin-bottom: 1.5rem;">Termékek</h2>
            <?php if (empty($items)): ?>
                <p style="text-align: center; padding: 2rem; color: rgba(255,255,255,0.6);">Nincsenek megjeleníthető termékek.</p>
            <?php else: ?>
                <div class="admin-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cím</th>
                                <th>Eladó</th>
                                <th>Ár</th>
                                <th>Leírás</th>
                                <th>Dátum</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo $item['id']; ?></td>
                                    <td><?php echo htmlspecialchars($item['title']); ?></td>
                                    <td><?php echo htmlspecialchars($item['seller_name']); ?></td>
                                    <td><?php echo number_format($item['price'], 0, ',', ' '); ?> Ft</td>
                                    <td><?php echo htmlspecialchars(substr($item['description'], 0, 50)) . '...'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($item['created_at'])); ?></td>
                                    <td>
                                        <a href="admin.php?view=items&id=<?php echo $item['id']; ?>&page=<?php echo $page; ?>" class="action-btn edit-btn">Szerkeszt</a>
                                        <button type="button" class="action-btn delete-btn" onclick="deleteItem('<?php echo $item['id']; ?>')">Törlés</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Lapozás -->
        <?php if ($totalPages > 1 && $view != 'main' && !$editId): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?view=<?php echo $view; ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Előző</a>
                <?php else: ?>
                    <span class="pagination-btn disabled">Előző</span>
                <?php endif; ?>
                <span class="page-info"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?view=<?php echo $view; ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Következő</a>
                <?php else: ?>
                    <span class="pagination-btn disabled">Következő</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Termékmodál (admin panelben) -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <div class="product-modal-header">
                <div class="product-menu" id="productMenuContainer" style="display: none;">
                    <div class="product-menu-button" onclick="toggleProductMenu(this)">⋮</div>
                    <div class="product-menu-content" id="productMenuContent">
                        <button class="product-menu-item" id="productReportBtn">⚠️ Bejelentés</button>
                        <button class="product-menu-item" id="productEditBtn" style="display: none;">✏️ Módosítás</button>
                        <button class="product-menu-item delete" id="productDeleteBtn" style="display: none;">🗑️ Törlés</button>
                    </div>
                </div>
                <button class="product-modal-close" id="closeProductModalBtn">✕</button>
            </div>
            <div class="product-gallery">
                <div class="product-main-image-container">
                    <img src="" alt="Termék képe" class="product-main-image" id="productMainImage" style="display: none;">
                    <div class="product-no-image-placeholder unselectable" id="productNoImagePlaceholder" style="display: none;">📷 Nincs kép</div>
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
                <button class="product-buy-btn" id="productBuyBtn">🛒 Vásárlás</button>
            </div>
        </div>
    </div>
    <div class="lightbox-overlay" id="lightboxOverlay">
        <div class="lightbox-content">
            <img src="" alt="Nagyított kép" class="lightbox-image" id="lightboxImage">
            <button class="lightbox-close" id="lightboxClose">✕</button>
        </div>
    </div>

    <script>
        // Téma kezelés (admin.php saját)
        (function() {
            const ADMIN_THEME_KEY = 'admin_theme';
            const themeBtn = document.getElementById('themeToggleBtn');
            const body = document.body;
            const ICON_LIGHT = '☀️';
            const ICON_DARK = '🌙';
            function applyTheme(theme) {
                if (theme === 'light') {
                    body.classList.add('light-theme');
                    if (themeBtn) themeBtn.textContent = ICON_DARK;
                } else {
                    body.classList.remove('light-theme');
                    if (themeBtn) themeBtn.textContent = ICON_LIGHT;
                }
                localStorage.setItem(ADMIN_THEME_KEY, theme);
            }
            const savedTheme = localStorage.getItem(ADMIN_THEME_KEY) || 'dark';
            applyTheme(savedTheme);
            if (themeBtn) {
                themeBtn.addEventListener('click', function() {
                    const isLight = body.classList.contains('light-theme');
                    applyTheme(isLight ? 'dark' : 'light');
                });
            }
        })();

        // Törlési függvények
        function deleteUser(userId) {
            if (confirm('Biztosan törlöd ezt a felhasználót? Minden hirdetése és képe véglegesen törlődik!')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete_user=1&user_id=' + userId
                }).then(() => location.reload());
            }
        }
        function deleteItem(itemId) {
            if (confirm('Biztosan törlöd ezt a terméket? A hozzá tartozó képek véglegesen törlődnek!')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete_item=1&item_id=' + itemId
                }).then(() => location.reload());
            }
        }
        function deleteReport(reportId) {
            if (confirm('Biztosan törlöd ezt a reportot?')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete_report=1&report_id=' + reportId
                }).then(() => location.reload());
            }
        }
        setTimeout(() => {
            document.querySelectorAll('.message-banner, .error-banner').forEach(el => {
                el.style.opacity = '0';
                el.style.transition = 'opacity 0.5s ease';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);

        // ==================== TERMÉKMODÁL KEZELÉS ====================
        const productModal = document.getElementById('productModal');
        const closeProductModalBtn = document.getElementById('closeProductModalBtn');
        const productMainImage = document.getElementById('productMainImage');
        const productNoImagePlaceholder = document.getElementById('productNoImagePlaceholder');
        const productTitle = document.getElementById('productTitle');
        const productPrice = document.getElementById('productPrice');
        const productSeller = document.getElementById('productSeller');
        const productDate = document.getElementById('productDate');
        const productDescription = document.getElementById('productDescription');
        const productThumbnails = document.getElementById('productThumbnails');
        const galleryPrev = document.getElementById('galleryPrev');
        const galleryNext = document.getElementById('galleryNext');
        const lightboxOverlay = document.getElementById('lightboxOverlay');
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxClose = document.getElementById('lightboxClose');

        let currentProductImages = [];
        let currentImageIndex = 0;
        let currentProductId = null;
        let currentProductUserId = null;

        function setMainImage(index) {
            if (index >= 0 && index < currentProductImages.length && currentProductImages[index]) {
                productMainImage.style.display = 'block';
                productNoImagePlaceholder.style.display = 'none';
                productMainImage.src = currentProductImages[index];
                currentImageIndex = index;
                productMainImage.onload = () => adjustImageContainerHeight();
                productMainImage.onerror = () => {
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
                    imageContainer.style.height = Math.min(productMainImage.naturalHeight, availableHeight) + 'px';
                } else {
                    imageContainer.style.height = Math.max(300, availableHeight) + 'px';
                }
            }
        }

        function openProductModal() {
            productModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => adjustImageContainerHeight(), 100);
        }

        function closeProductModal() {
            if (lightboxOverlay.classList.contains('active')) closeLightbox();
            productModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeLightbox() {
            lightboxOverlay.classList.remove('active');
        }

        function toggleProductMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');
            document.querySelectorAll('.product-menu-content').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
        }

        // Kattintás a termék nevére a reportokban
        document.querySelectorAll('.view-item-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const itemId = this.dataset.itemId;
                fetch(`admin.php?get_item_data=${itemId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error(data.error);
                            alert(data.error);
                            return;
                        }
                        currentProductId = data.id;
                        currentProductUserId = data.user_id;
                        currentProductImages = data.images;
                        currentImageIndex = 0;

                        productTitle.textContent = data.title;
                        productPrice.textContent = data.price;
                        productSeller.innerHTML = `Eladó: <strong>${data.seller}</strong>`;
                        productDate.textContent = data.date;
                        productDescription.textContent = data.description;

                        productThumbnails.innerHTML = '';
                        if (data.images.length > 0) {
                            data.images.forEach((img, idx) => {
                                const thumb = document.createElement('div');
                                thumb.className = `product-thumbnail ${idx === 0 ? 'active' : ''}`;
                                thumb.innerHTML = `<img src="${img}" alt="Thumbnail ${idx+1}">`;
                                thumb.addEventListener('click', (e) => {
                                    e.stopPropagation();
                                    setMainImage(idx);
                                });
                                productThumbnails.appendChild(thumb);
                            });
                            setMainImage(0);
                        } else {
                            setMainImage(-1);
                        }

                        const hasMultiple = data.images.length > 1;
                        galleryPrev.classList.toggle('hidden', !hasMultiple);
                        galleryNext.classList.toggle('hidden', !hasMultiple);

                        // Menü beállítása - Módosított logika a szerkesztés gombhoz
                        <?php if (isset($_SESSION['user_id'])): ?>
                            const isOwner = (parseInt(data.user_id) === <?php echo $_SESSION['user_id']; ?>);
                            const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
                            const menuContainer = document.getElementById('productMenuContainer');
                            const reportBtn = document.getElementById('productReportBtn');
                            const editBtn = document.getElementById('productEditBtn');
                            const deleteBtn = document.getElementById('productDeleteBtn');
                            
                            if (isAdmin) {
                                // Admin: mutasd a menüt, csak szerkesztés és törlés
                                menuContainer.style.display = 'block';
                                reportBtn.style.display = 'none';
                                editBtn.style.display = 'block';
                                deleteBtn.style.display = 'block';
                                
                                editBtn.onclick = () => {
                                    window.location.href = `admin.php?view=items&id=${currentProductId}`;
                                };
                                deleteBtn.onclick = () => {
                                    if (confirm('Biztosan törölni szeretnéd ezt a hirdetést?')) {
                                        const form = document.createElement('form');
                                        form.method = 'POST';
                                        form.innerHTML = `
                                            <input type="hidden" name="item_id" value="${data.id}">
                                            <input type="hidden" name="delete_item" value="1">
                                        `;
                                        document.body.appendChild(form);
                                        form.submit();
                                    }
                                };
                            } else if (!isOwner) {
                                // Nem admin, nem tulajdonos: csak bejelentés
                                menuContainer.style.display = 'block';
                                reportBtn.style.display = 'block';
                                editBtn.style.display = 'none';
                                deleteBtn.style.display = 'none';
                                reportBtn.onclick = () => {
                                    closeProductModal();
                                    alert('Bejelentés funkció – itt a report modal-t kellene megnyitni.');
                                };
                            } else {
                                // Tulajdonos (nem admin): nincs menü
                                menuContainer.style.display = 'none';
                            }
                        <?php endif; ?>

                        openProductModal();
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Hiba a termékadatok betöltésekor!');
                    });
            });
        });

        // Modál események
        closeProductModalBtn.addEventListener('click', closeProductModal);
        productModal.addEventListener('click', (e) => { if (e.target === productModal) closeProductModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && productModal.classList.contains('active')) closeProductModal(); });
        galleryPrev.addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex - 1;
            setMainImage(newIndex >= 0 ? newIndex : currentProductImages.length - 1);
        });
        galleryNext.addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex + 1;
            setMainImage(newIndex < currentProductImages.length ? newIndex : 0);
        });
        productMainImage.addEventListener('click', () => {
            if (productMainImage.src && productMainImage.style.display !== 'none') {
                lightboxImage.src = productMainImage.src;
                lightboxOverlay.classList.add('active');
            }
        });
        lightboxClose.addEventListener('click', closeLightbox);
        lightboxOverlay.addEventListener('click', (e) => { if (e.target === lightboxOverlay) closeLightbox(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && lightboxOverlay.classList.contains('active')) closeLightbox(); });
        window.addEventListener('resize', () => { if (productModal.classList.contains('active')) adjustImageContainerHeight(); });
        document.getElementById('productBuyBtn').addEventListener('click', () => alert('Vásárlás funkció még nem elérhető!'));
    </script>
</body>
</html>