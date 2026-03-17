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
            // Ne lehessen saját magát törölni
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
                
                // Visszatérés a listához
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
                // Ellenőrizzük, hogy más nem használja-e már a felhasználónevet vagy emailt
                $checkStmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $checkStmt->execute([$username, $email, $userId]);
                if ($checkStmt->fetchColumn() > 0) {
                    $error = 'A felhasználónév vagy email már foglalt!';
                } else {
                    $updateStmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                    $updateStmt->execute([$username, $email, $userId]);
                    $message = "Felhasználó sikeresen módosítva.";
                    
                    // Visszatérés a listához
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
            // Ha nem létezik a tábla, létrehozzuk
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
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Global select none */
        * {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        /* Engedélyezzük a kijelölést input mezőkben */
        input, textarea {
            user-select: text;
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
        }

        /* Admin specifikus stílusok */
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
            color: var(--orange-bright);
            text-shadow: 0 0 20px var(--orange-glow);
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
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            color: var(--text-primary);
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
            border-color: var(--orange-bright);
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(255, 140, 0, 0.3);
        }

        .admin-btn.active {
            background: rgba(255, 140, 0, 0.3);
            border-color: var(--orange-bright);
            color: var(--orange-bright);
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
            border: 1px solid var(--glass-border);
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
            color: var(--orange-bright);
            font-weight: 600;
            text-align: left;
            padding: 1rem;
            border-bottom: 2px solid var(--orange-glow);
            white-space: nowrap;
        }

        .admin-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--glass-border);
            color: var(--text-primary);
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

        /* Action buttons */
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
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.2);
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
            box-shadow: 0 4px 12px rgba(255, 0, 0, 0.2);
        }

        .view-btn {
            background: rgba(255, 140, 0, 0.2);
            color: var(--orange-bright);
            border: 1px solid var(--orange-glow);
        }

        .view-btn:hover {
            background: rgba(255, 140, 0, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 140, 0, 0.2);
        }

        /* Message banners */
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

        /* Pagination */
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
            border: 1px solid var(--glass-border);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            z-index: 100;
        }

        .pagination-btn {
            padding: 0.5rem 1.5rem;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            min-width: 100px;
            text-align: center;
        }

        .pagination-btn:hover:not(.disabled) {
            background: rgba(255, 140, 0, 0.2);
            color: var(--orange-bright);
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(255, 140, 0, 0.3);
        }

        .pagination-btn.disabled {
            opacity: 0.3;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .page-info {
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            background: rgba(255, 140, 0, 0.1);
            border-radius: 50px;
            font-weight: 500;
        }

        /* Edit card */
        .edit-card {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2rem;
            max-width: 600px;
            margin: 0 auto 2rem;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
        }

        .edit-card h2 {
            color: var(--orange-bright);
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }

        .edit-card .form-group {
            margin-bottom: 1.5rem;
        }

        .edit-card .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .edit-card .form-input,
        .edit-card .form-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .edit-card .form-input:focus,
        .edit-card .form-textarea:focus {
            outline: none;
            border-color: var(--orange-bright);
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
            background: linear-gradient(135deg, var(--orange-bright), #ff5500);
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
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .cancel-btn:hover {
            border-color: var(--orange-bright);
            color: var(--orange-bright);
        }
    </style>
</head>

<body>
    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

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

        <!-- SZERKESZTŐ NÉZET -->
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

        <!-- FŐOLDAL NÉZET -->
        <?php elseif ($view == 'main'): ?>
            <div style="text-align: center; padding: 3rem; background: rgba(0,0,0,0.3); border-radius: 24px;">
                <h2 style="color: var(--orange-bright); margin-bottom: 1rem;">Üdvözöljük az Admin Panelben!</h2>
                <p style="color: var(--text-primary); margin-bottom: 2rem;">Válasszon a fenti menüpontok közül:</p>
                <div style="display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap;">
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
                        <h3>Reportok</h3>
                        <p style="color: var(--text-muted);">Bejelentett hirdetések kezelése</p>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">👥</div>
                        <h3>Felhasználók</h3>
                        <p style="color: var(--text-muted);">Felhasználók listázása és szerkesztése</p>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">📦</div>
                        <h3>Termékek</h3>
                        <p style="color: var(--text-muted);">Hirdetések listázása és szerkesztése</p>
                    </div>
                </div>
            </div>

        <!-- REPORT NÉZET -->
        <?php elseif ($view == 'reports'): ?>
            <h2 style="color: var(--orange-bright); margin-bottom: 1.5rem;">Reportok</h2>
            
            <?php if (empty($reports)): ?>
                <p style="text-align: center; padding: 2rem; color: var(--text-muted);">Nincsenek megjeleníthető reportok.</p>
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
                                        <a href="item.php?id=<?php echo $report['item_id']; ?>" target="_blank" style="color: var(--orange-bright);">
                                            <?php echo htmlspecialchars($report['item_title']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['item_owner_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['reason']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $report['status']; ?>">
                                            <?php echo $report['status']; ?>
                                        </span>
                                    </td>
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

        <!-- FELHASZNÁLÓ NÉZET -->
        <?php elseif ($view == 'users'): ?>
            <h2 style="color: var(--orange-bright); margin-bottom: 1.5rem;">Felhasználók</h2>
            
            <?php if (empty($users)): ?>
                <p style="text-align: center; padding: 2rem; color: var(--text-muted);">Nincsenek megjeleníthető felhasználók.</p>
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

        <!-- TERMÉK NÉZET -->
        <?php elseif ($view == 'items'): ?>
            <h2 style="color: var(--orange-bright); margin-bottom: 1.5rem;">Termékek</h2>
            
            <?php if (empty($items)): ?>
                <p style="text-align: center; padding: 2rem; color: var(--text-muted);">Nincsenek megjeleníthető termékek.</p>
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

        <!-- LAPOZÁS -->
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

    <script>
        // Törlési függvények
        function deleteUser(userId) {
            if (confirm('Biztosan törlöd ezt a felhasználót? Minden hirdetése és képe véglegesen törlődik!')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_user=1&user_id=' + userId
                }).then(() => location.reload());
            }
        }

        function deleteItem(itemId) {
            if (confirm('Biztosan törlöd ezt a terméket? A hozzá tartozó képek véglegesen törlődnek!')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_item=1&item_id=' + itemId
                }).then(() => location.reload());
            }
        }

        function deleteReport(reportId) {
            if (confirm('Biztosan törlöd ezt a reportot?')) {
                fetch('admin.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_report=1&report_id=' + reportId
                }).then(() => location.reload());
            }
        }

        // Status üzenet automatikus eltüntetése
        setTimeout(() => {
            document.querySelectorAll('.message-banner, .error-banner').forEach(el => {
                el.style.opacity = '0';
                el.style.transition = 'opacity 0.5s ease';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>