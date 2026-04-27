<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Kijelentkezés kezelése
if (isset($_POST['logout'])) {
    $_SESSION = array();
    session_destroy();
    header("Location: index.php");
    exit();
}

// Adatbázis kapcsolat
require_once 'config.php';
$servername = DB_HOST;
$usernameDB = DB_USER;
$passwordDB = DB_PASS;
$dbname     = DB_NAME;

// --- KÉP ÁTMÉRETEZŐ FÜGGVÉNY (main.php-ból) ---
function resizeImage($source, $destination, $maxDim = 1024)
{
    $info = getimagesize($source);
    if (!$info) return false;

    $mime = $info['mime'];
    $srcWidth = $info[0];
    $srcHeight = $info[1];

    if ($srcWidth <= $maxDim && $srcHeight <= $maxDim) {
        return copy($source, $destination);
    }

    $ratio = $srcWidth / $srcHeight;
    if ($srcWidth > $srcHeight) {
        $newWidth = $maxDim;
        $newHeight = (int) round($maxDim / $ratio);
    } else {
        $newHeight = $maxDim;
        $newWidth = (int) round($maxDim * $ratio);
    }

    switch ($mime) {
        case 'image/jpeg':
            $srcImg = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $srcImg = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $srcImg = imagecreatefromgif($source);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) $srcImg = imagecreatefromwebp($source);
            else return copy($source, $destination);
            break;
        default:
            return false;
    }
    if (!$srcImg) return false;

    $dstImg = imagecreatetruecolor($newWidth, $newHeight);
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
        imagefilledrectangle($dstImg, 0, 0, $newWidth, $newHeight, $transparent);
    } elseif ($mime == 'image/gif') {
        $transparentIndex = imagecolortransparent($srcImg);
        if ($transparentIndex >= 0) {
            $transparentColor = imagecolorsforindex($srcImg, $transparentIndex);
            $transparentIndex = imagecolorallocate($dstImg, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue']);
            imagefill($dstImg, 0, 0, $transparentIndex);
            imagecolortransparent($dstImg, $transparentIndex);
        }
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
    $success = false;
    switch ($mime) {
        case 'image/jpeg':
            $success = imagejpeg($dstImg, $destination, 85);
            break;
        case 'image/png':
            $success = imagepng($dstImg, $destination, 8);
            break;
        case 'image/gif':
            $success = imagegif($dstImg, $destination);
            break;
        case 'image/webp':
            if (function_exists('imagewebp')) $success = imagewebp($dstImg, $destination, 85);
            break;
    }
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    return $success;
}
// --- vége resizeImage ---

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $usernameDB, $passwordDB);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $userId = $_SESSION['user_id'];

    // profile_picture mező ellenőrzése és létrehozása, ha hiányzik
    $checkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($checkCol->rowCount() == 0) {
        $conn->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL");
    }

    // Admin ellenőrzés
    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$userId]);
    $isAdmin = $adminCheck->fetchColumn() > 0;

    // =============================================
    // AJAX: GET ITEM DETAILS (JSON) - a product modalhoz
    // =============================================
    if (isset($_GET['get_item']) && !empty($_GET['get_item'])) {
        header('Content-Type: application/json');
        $itemId = $_GET['get_item'];
        // MÓDOSÍTÁS: i.sold mező hozzáadva
        $stmt = $conn->prepare("
            SELECT i.id, i.title, i.description, i.price, i.created_at, i.sold, u.username as seller_name, i.user_id
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
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        $item['images'] = $images;
        echo json_encode($item);
        exit;
    }

    // =============================================
    // AJAX: GET SELLER PROFILE (JSON) - a seller popuphoz
    // =============================================
    if (isset($_GET['get_seller']) && !empty($_GET['get_seller'])) {
        header('Content-Type: application/json');
        $sellerId = (int)$_GET['get_seller'];
        $sellerStmt = $conn->prepare("
            SELECT u.id, u.username, u.profile_picture, u.created_at,
                   COUNT(DISTINCT i.id) AS item_count,
                   (SELECT COUNT(*) FROM admins WHERE user_id = u.id) AS is_admin
            FROM users u
            LEFT JOIN items i ON i.user_id = u.id
            WHERE u.id = ?
            GROUP BY u.id, u.username, u.profile_picture, u.created_at
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

    // =============================================
    // PROFILKÉP FELTÖLTÉS (AJAX)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_picture'])) {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => ''];

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $response['message'] = 'Nem sikerült feltölteni a képet.';
            echo json_encode($response);
            exit;
        }

        $file = $_FILES['profile_image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            $response['message'] = 'Csak JPEG, PNG, GIF vagy WebP kép tölthető fel.';
            echo json_encode($response);
            exit;
        }

        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            $response['message'] = 'A kép mérete nem haladhatja meg az 5 MB-ot.';
            echo json_encode($response);
            exit;
        }

        // Könyvtár létrehozása
        $uploadDir = 'uploads/profile/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFilename = 'user_' . $userId . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $newFilename;

        // Átméretezés
        if (!resizeImage($file['tmp_name'], $destination, 1024)) {
            $response['message'] = 'Hiba történt a kép feldolgozása közben.';
            echo json_encode($response);
            exit;
        }

        // Régi kép törlése ha van
        $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $oldPic = $stmt->fetchColumn();
        if ($oldPic && file_exists($oldPic)) {
            unlink($oldPic);
        }

        // Adatbázis frissítése
        $update = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
        $update->execute([$destination, $userId]);

        $response['success'] = true;
        $response['message'] = 'Profilkép sikeresen frissítve!';
        $response['new_image'] = $destination;
        echo json_encode($response);
        exit;
    }

    // =============================================
    // FELHASZNÁLÓNÉV MÓDOSÍTÁS (AJAX)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_username'])) {
        header('Content-Type: application/json');
        $newUsername = trim($_POST['username'] ?? '');
        $response = ['success' => false, 'message' => ''];
        if (empty($newUsername)) {
            $response['message'] = 'A felhasználónév nem lehet üres.';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check->execute([$newUsername, $userId]);
            if ($check->fetchColumn()) {
                $response['message'] = 'Ez a felhasználónév már foglalt.';
            } else {
                $update = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $update->execute([$newUsername, $userId]);
                $_SESSION['username'] = $newUsername;
                $response['success'] = true;
                $response['message'] = 'Felhasználónév sikeresen módosítva.';
            }
        }
        echo json_encode($response);
        exit;
    }

    // =============================================
    // EMAIL MÓDOSÍTÁS (AJAX)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email'])) {
        header('Content-Type: application/json');
        $newEmail = trim($_POST['email'] ?? '');
        $response = ['success' => false, 'message' => ''];
        if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Érvénytelen e-mail cím.';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$newEmail, $userId]);
            if ($check->fetchColumn()) {
                $response['message'] = 'Ez az e-mail cím már regisztrálva van.';
            } else {
                $update = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                $update->execute([$newEmail, $userId]);
                $response['success'] = true;
                $response['message'] = 'E-mail cím sikeresen módosítva.';
            }
        }
        echo json_encode($response);
        exit;
    }

    // =============================================
    // JELSZÓ MÓDOSÍTÁS (AJAX)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
        header('Content-Type: application/json');
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $response = ['success' => false, 'message' => ''];

        if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
            $response['message'] = 'Minden mező kitöltése kötelező.';
        } elseif ($newPassword !== $confirmPassword) {
            $response['message'] = 'Az új jelszavak nem egyeznek.';
        } elseif (strlen($newPassword) < 6) {
            $response['message'] = 'A jelszónak legalább 6 karakter hosszúnak kell lennie.';
        } else {
            // Ellenőrizzük a régi jelszót
            $userStmt = $conn->prepare("SELECT passwords.password_hash FROM users JOIN passwords ON users.password_id = passwords.id WHERE users.id = ?");
            $userStmt->execute([$userId]);
            $hash = $userStmt->fetchColumn();
            if (!password_verify($oldPassword, $hash)) {
                $response['message'] = 'A megadott régi jelszó helytelen.';
            } else {
                // Új jelszó hash
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $insPwd = $conn->prepare("INSERT INTO passwords (password_hash) VALUES (?)");
                $insPwd->execute([$newHash]);
                $newPasswordId = $conn->lastInsertId();
                $update = $conn->prepare("UPDATE users SET password_id = ? WHERE id = ?");
                $update->execute([$newPasswordId, $userId]);
                $response['success'] = true;
                $response['message'] = 'Jelszó sikeresen módosítva.';
            }
        }
        echo json_encode($response);
        exit;
    }

    // ------------------------------------------------------------
    // TERMÉK MÓDOSÍTÁS / TÖRLÉS (POST)
    // ------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
        $itemId  = $_POST['item_id'] ?? '';
        $title   = trim($_POST['edit_title'] ?? '');
        $desc    = trim($_POST['edit_description'] ?? '');
        $price   = trim($_POST['edit_price'] ?? '');

        $ownerCheck = $conn->prepare("SELECT user_id FROM items WHERE id = ?");
        $ownerCheck->execute([$itemId]);
        $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);

        if ($itemId && $ownerRow && $ownerRow['user_id'] == $userId && $title !== '' && $desc !== '' && is_numeric($price) && floatval($price) >= 0) {
            try {
                $upd = $conn->prepare("UPDATE items SET title=:title, description=:desc, price=:price WHERE id=:id");
                $upd->execute([':title' => $title, ':desc' => $desc, ':price' => floatval($price), ':id' => $itemId]);
                header("Location: account.php?edit=success");
                exit();
            } catch (Exception $e) {
                $error = "Hiba a módosítás során: " . $e->getMessage();
            }
        } else {
            $error = "Érvénytelen adatok vagy nincs jogosultság!";
        }
    }

    // Termék törlése
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
        $itemId = $_POST['item_id'] ?? '';
        $ownerCheck = $conn->prepare("SELECT user_id FROM items WHERE id = ?");
        $ownerCheck->execute([$itemId]);
        $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
        if ($itemId && $ownerRow && $ownerRow['user_id'] == $userId) {
            // Töröljük a képeket is
            $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ?");
            $imgStmt->execute([$itemId]);
            while ($imgPath = $imgStmt->fetchColumn()) {
                if (file_exists($imgPath)) unlink($imgPath);
            }
            $conn->prepare("DELETE FROM item_images WHERE item_id = ?")->execute([$itemId]);
            $conn->prepare("DELETE FROM items WHERE id = ?")->execute([$itemId]);
            // Könyvtár törlése
            $dir = 'uploads/' . $itemId . '/';
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '*'));
                rmdir($dir);
            }
            header("Location: account.php?deleted=1");
            exit();
        }
    }

    // Termék jelentés (report)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_item'])) {
        $itemId = $_POST['item_id'] ?? '';
        $reason = trim($_POST['report_reason'] ?? '');
        if ($itemId && $reason) {
            try {
                $ins = $conn->prepare("INSERT INTO reports (item_id, user_id, reason) VALUES (?, ?, ?)");
                $ins->execute([$itemId, $userId, $reason]);
                $reportSuccess = true;
            } catch (Exception $e) {
                $reportError = 'Hiba a bejelentés során.';
            }
        }
    }

    // Felhasználó adatainak lekérése
    $stmt = $conn->prepare("SELECT username, email, profile_picture FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $profilePic = $user['profile_picture'] ?? null;

    // Felhasználó termékeinek lekérése
    $itemStmt = $conn->prepare("
        SELECT i.id, i.title, i.description, i.price, i.created_at, i.user_id, u.username as seller_name
        FROM items i 
        JOIN users u ON i.user_id = u.id
        WHERE i.user_id = ? 
        ORDER BY i.created_at DESC
    ");
    $itemStmt->execute([$userId]);
    $userItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // =============================================
    // FELHASZNÁLÓ RENDELÉSEINEK LEKÉRÉSE
    // =============================================
    $userOrders = [];
    try {
        $ordersStmt = $conn->prepare("
            SELECT o.*, i.title AS item_title, i.price AS item_price, 
                   u.username AS seller_name, u.id AS seller_id,
                   (SELECT image_path FROM item_images WHERE item_id = o.item_id AND is_primary = 1 LIMIT 1) as item_image
            FROM orders o
            JOIN items i ON o.item_id = i.id
            JOIN users u ON o.seller_id = u.id
            WHERE o.buyer_id = ?
            ORDER BY o.created_at DESC
        ");
        $ordersStmt->execute([$userId]);
        $userOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $userOrders = [];
    }

    $editSuccess = isset($_GET['edit']) && $_GET['edit'] === 'success';
    $deleteSuccess = isset($_GET['deleted']) && $_GET['deleted'] == '1';
    $reportSuccess = isset($reportSuccess) && $reportSuccess;
    $reportError = $reportError ?? '';
} catch (PDOException $e) {
    die("Adatbázis hiba: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiókom</title>
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        /* ========== GLOBÁLIS RESET ========== */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--body-bg);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
        }

        .container {
            max-width: 1100px;
            margin: 70px auto 0 auto;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            padding: 0 1rem;
        }

        /* top-bar */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            pointer-events: auto;
        }

        .top-bar-left,
        .top-bar-right {
            display: flex;
            gap: 0.5rem;
            pointer-events: auto;
        }

        .back-btn {
            padding: 0.5rem 1rem;
            background: var(--orange-subtle);
            border: 1px solid var(--orange-bright);
            border-radius: 8px;
            color: var(--orange-bright);
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .back-btn:hover {
            background: var(--orange-bright);
            color: #000 !important;
            box-shadow: 0 0 15px var(--orange-glow);
        }

        body[data-theme="light"] .back-btn:hover {
            background: var(--orange-bright);
            color: #1a1f00 !important;
        }

        .admin-btn {
            padding: 0.5rem 1.1rem;
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 50px;
            background: rgba(255, 215, 0, 0.12);
            backdrop-filter: blur(10px);
            color: #ffd700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            white-space: nowrap;
        }

        .admin-btn:hover {
            background: rgba(255, 215, 0, 0.25);
            border-color: #ffd700;
            transform: translateY(-1px);
        }

        /* Account dropdown */
        .account-menu {
            position: relative;
            display: inline-block;
        }

        .account-menu-btn {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid var(--orange-glow);
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            color: var(--orange-bright);
            font-size: 0.9rem;
            font-family: inherit;
            white-space: nowrap;
            cursor: pointer;
        }

        .account-menu-btn:hover {
            background: rgba(255, 140, 0, 0.1);
            border-color: var(--orange-bright);
        }

        .account-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            padding-top: 0.4rem;
            width: 230px;
            z-index: 1001;
            opacity: 0;
            pointer-events: none;
            transform: translateY(-4px);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .account-dropdown.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }

        .account-dropdown-panel {
            border-radius: 14px;
            padding: 0.5rem;
            overflow: hidden;
            background: rgba(8, 8, 8, 0.95);
            backdrop-filter: blur(16px);
            border: 1px solid var(--glass-border);
        }

        .dropdown-username {
            font-size: 0.85rem;
            font-weight: 700;
            padding: 0.6rem 0.8rem 0.5rem;
        }

        .dropdown-divider {
            height: 1px;
            margin: 0.3rem 0.4rem;
            background: linear-gradient(90deg, transparent, var(--orange-bright), transparent);
        }

        .dropdown-item,
        .logout-form-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.65rem 0.8rem;
            border-radius: 8px;
            font-size: 0.88rem;
            text-decoration: none;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            text-align: left;
        }

        .logout-form {
            width: 100%;
            margin: 0;
            padding: 0;
        }

        .dropdown-theme-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 0.8rem;
            font-size: 0.85rem;
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

        .theme-switch input:checked+.theme-switch-track {
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

        .theme-switch input:checked~.theme-switch-thumb {
            transform: translateX(18px);
            background: #B0CB1F;
        }

        /* light mode overrides */
        body[data-theme="light"] .account-dropdown-panel {
            background: rgba(248, 252, 235, 0.98);
            border: 1px solid rgba(122, 146, 0, 0.3);
        }

        body[data-theme="light"] .account-menu-btn {
            background: rgba(240, 252, 200, 0.85);
            border-color: rgba(122, 146, 0, 0.5);
            color: #7a9200;
        }

        body[data-theme="light"] .account-menu-btn:hover {
            background: rgba(210, 240, 100, 0.95);
            border-color: #B0CB1F;
        }

        /* info-grid, items-section stb. */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .info-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.2rem;
            backdrop-filter: blur(10px);
        }

        .info-card label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            display: block;
            margin-bottom: 0.3rem;
        }

        .info-card .val {
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-all;
        }

        .edit-btn {
            padding: 0.7rem 1.2rem;
            background: linear-gradient(135deg, var(--orange-bright), var(--orange-mid));
            color: #000;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            margin-top: auto;
        }

        .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px var(--orange-glow);
        }

        .items-section,
        .orders-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.2rem;
            backdrop-filter: blur(10px);
        }

        .items-section h2,
        .orders-section h2 {
            color: var(--orange-bright);
            margin: 0 0 1rem 0;
            font-size: 1.3rem;
        }

        /* Toggle gombok konténere */
        .toggle-buttons-row {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin: 0 0 0.5rem 0;
            flex-wrap: wrap;
        }

        .section-toggle-btn {
            padding: 0.55rem 1.3rem;
            background: transparent;
            border: 1px solid var(--glass-border);
            border-radius: 25px;
            color: var(--orange-bright);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.25s ease;
            font-weight: 600;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            backdrop-filter: blur(8px);
        }

        .section-toggle-btn:hover {
            background: var(--orange-subtle);
            border-color: var(--orange-bright);
            transform: translateY(-1px);
            box-shadow: 0 0 12px var(--orange-glow);
        }

        .section-toggle-btn.active {
            background: var(--orange-bright);
            color: #000;
            border-color: var(--orange-bright);
            box-shadow: 0 0 18px var(--orange-glow);
        }

        @media (max-width: 500px) {
            .toggle-buttons-row {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .section-toggle-btn {
                justify-content: center;
            }
        }

        .items-grid,
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            transition: opacity 0.3s ease, max-height 0.3s ease;
        }

        .mini-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            overflow: hidden;
            transition: 0.3s;
            cursor: pointer;
        }

        .mini-card:hover {
            border-color: var(--orange-bright);
            transform: translateY(-3px);
        }

        .mini-card img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            background: var(--placeholder-bg);
            pointer-events: none;
        }

        .mini-card .info {
            padding: 0.7rem;
        }

        .mini-card .title {
            margin: 0;
            font-size: 0.9rem;
            color: var(--orange-bright);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mini-card .price {
            margin: 0.3rem 0 0;
            font-size: 0.85rem;
            color: var(--text-primary);
            opacity: 0.8;
        }

        /* ---------- RENDELÉSEK STÍLUSAI ---------- */
        .order-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            padding: 1rem;
            transition: 0.3s;
            overflow: hidden;
        }

        .order-card:hover {
            border-color: var(--orange-bright);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .order-card .order-header {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 0.8rem;
        }

        .order-card .order-image {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
            background: var(--placeholder-bg);
            border: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .order-card .order-image-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: var(--placeholder-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--orange-bright);
            border: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .order-card .order-info {
            flex: 1;
            min-width: 0;
        }

        .order-card .order-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--orange-bright);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .order-card .order-price {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        .order-card .order-meta {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
        }

        .order-card .order-meta strong {
            color: var(--orange-bright);
            cursor: pointer;
            transition: color 0.18s;
        }

        .order-card .order-meta strong:hover {
            text-decoration: underline;
            color: #fff;
        }

        .order-card .order-details {
            border-top: 1px solid var(--glass-border);
            padding-top: 0.7rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.3rem;
        }

        .order-card .order-details span {
            display: block;
        }

        .order-card .order-details .detail-label {
            font-weight: 600;
            color: var(--orange-bright);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-pending {
            background: rgba(255, 165, 0, 0.2);
            color: #ffa500;
            border: 1px solid rgba(255, 165, 0, 0.4);
        }

        .status-completed {
            background: rgba(0, 200, 100, 0.2);
            color: #00c864;
            border: 1px solid rgba(0, 200, 100, 0.4);
        }

        .status-cancelled {
            background: rgba(255, 50, 50, 0.2);
            color: #ff5050;
            border: 1px solid rgba(255, 50, 50, 0.4);
        }

        /* ---------- ÚJ MODÁLOK STÍLUSAI ---------- */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 2rem;
            width: 90%;
            max-width: 450px;
            box-shadow: var(--shadow-deep);
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.4rem;
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--orange-bright);
        }

        .modal-title {
            color: var(--orange-bright);
            margin: 0 0 1.5rem 0;
            font-size: 1.4rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--orange-bright);
            box-shadow: 0 0 0 3px var(--orange-subtle);
        }

        .submit-btn {
            width: 100%;
            padding: 0.8rem;
            background: var(--orange-bright);
            color: #000;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: 0.3s;
        }

        .submit-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .status-msg {
            padding: 0.75rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            display: none;
        }

        .status-msg.error {
            background: rgba(255, 50, 50, 0.15);
            border: 1px solid #ff4d4d;
            color: #ff8080;
        }

        .status-msg.success {
            background: rgba(0, 200, 100, 0.15);
            border: 1px solid #00c851;
            color: #5dffa0;
        }

        /* Profilkép megjelenítés */
        .profile-pic-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--orange-bright);
            background: var(--placeholder-bg);
        }

        .no-profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--placeholder-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--orange-bright);
            border: 2px solid var(--orange-bright);
        }

        /* light theme */
        body[data-theme="light"] .modal-card {
            background: rgba(248, 252, 230, 0.98);
            border: 1px solid rgba(140, 170, 10, 0.35);
        }

        body[data-theme="light"] .modal-title {
            color: #7a9200;
        }

        body[data-theme="light"] .form-group input,
        body[data-theme="light"] .form-group textarea {
            background: rgba(245, 252, 215, 0.95);
            border-color: rgba(140, 170, 10, 0.3);
            color: #1a1f00;
        }

        body[data-theme="light"] .submit-btn {
            background: linear-gradient(135deg, #B0CB1F, #8aA000);
            color: #1a1f00;
        }

        /* Edit modal (termék szerkesztő) */
        .edit-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(12px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 6500;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .edit-modal.show {
            display: flex;
            opacity: 1;
        }

        .edit-modal-content {
            width: 100%;
            max-width: 560px;
            background: var(--glass-bg);
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: translateY(30px) scale(0.96);
            transition: transform 0.3s ease;
            overflow: hidden;
        }

        .edit-modal.show .edit-modal-content {
            transform: translateY(0) scale(1);
        }

        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            padding: 1.25rem 1.8rem;
            background: rgba(255, 140, 0, 0.08);
            border-bottom: 1px solid rgba(255, 140, 0, 0.2);
        }

        .edit-modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--orange-bright);
        }

        .edit-modal-close {
            background: rgba(255, 255, 255, 0.08);
            border: none;
            border-radius: 40px;
            width: 36px;
            height: 36px;
            font-size: 1.2rem;
            color: var(--orange-bright);
            cursor: pointer;
        }

        .edit-modal-body {
            padding: 1.8rem 1.8rem 2rem;
        }

        .edit-form-group {
            margin-bottom: 1.5rem;
        }

        .edit-form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--orange-bright);
            margin-bottom: 0.6rem;
        }

        .edit-form-input,
        .edit-form-textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 140, 0, 0.25);
            border-radius: 20px;
            padding: 0.85rem 1.2rem;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 0.95rem;
        }

        .edit-price-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .edit-price-suffix {
            position: absolute;
            right: 1.2rem;
            color: var(--orange-bright);
            font-weight: 600;
        }

        .edit-modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-edit-cancel,
        .btn-edit-save {
            flex: 1;
            padding: 0.9rem;
            border-radius: 40px;
            font-weight: 700;
            text-align: center;
            cursor: pointer;
            border: none;
        }

        .btn-edit-cancel {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            border: 1px solid rgba(255, 140, 0, 0.2);
        }

        .btn-edit-save {
            background: linear-gradient(105deg, #ff9a1f, #ff5500);
            color: #0a0500;
        }

        /* ========== TERMÉKMODÁL TELJES STÍLUSOK ========== */
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
            border: 1px solid var(--orange-bright);
            color: var(--orange-bright);
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
            background: var(--orange-bright);
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
            border: 1px solid var(--orange-bright);
            border-radius: 50%;
            color: var(--orange-bright);
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
            background: var(--orange-bright);
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
            border: 1px solid var(--orange-bright);
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
            color: var(--orange-bright);
        }

        .product-menu-item.delete:hover {
            background: rgba(255, 0, 0, 0.2);
            color: #ff0000;
        }

        /* Light mode product menu */
        body[data-theme="light"] .product-menu-content {
            background: rgba(248, 252, 230, 0.98) !important;
            border: 1px solid #B0CB1F !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1), 0 0 20px rgba(176, 203, 31, 0.3) !important;
        }

        body[data-theme="light"] .product-menu-item {
            color: #1a1f00 !important;
        }

        body[data-theme="light"] .product-menu-item:hover {
            background: rgba(176, 203, 31, 0.2) !important;
            color: #7a9200 !important;
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
            border: 1px solid var(--glass-border);
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
            border: 2px solid var(--orange-bright);
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
            background: var(--orange-bright);
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
            border-color: var(--orange-bright);
            transform: translateY(-2px);
        }

        .product-thumbnail.active {
            border-color: var(--orange-bright);
            box-shadow: 0 0 20px var(--orange-glow);
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
            border: 1px solid var(--glass-border);
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
            color: var(--orange-bright);
            margin: 0;
            word-break: break-word;
            line-height: 1.2;
            font-weight: bold;
        }

        .product-price {
            font-size: 3rem;
            font-weight: bold;
            color: var(--orange-bright);
            text-shadow: 0 0 30px var(--orange-glow);
        }

        .product-seller {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
        }

        .product-seller strong {
            color: var(--orange-bright);
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
            border: 1px solid var(--glass-border);
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

        /* ========== ELKELT GOMB – TELJESEN SZÜRKE ========== */
        .product-buy-btn.sold {
            background: #666 !important;
            color: #ddd !important;
            cursor: not-allowed !important;
            border: 1px solid #888 !important;
            box-shadow: none !important;
            pointer-events: none;
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
            border: 2px solid var(--orange-bright);
            border-radius: 8px;
        }

        .lightbox-close {
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid var(--orange-bright);
            color: var(--orange-bright);
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
            background: var(--orange-bright);
            color: black;
            transform: scale(1.1);
        }

        /* ========== SELLER POPUP STÍLUSOK ========== */
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
            border-bottom: 1px solid var(--glass-border);
        }

        .seller-popup-close {
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--glass-border);
            color: var(--orange-bright);
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
            background: var(--orange-bright);
            color: #000;
        }

        .seller-popup-topbar-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--orange-bright);
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
            background: linear-gradient(135deg, var(--orange-bright), #ff5500);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            font-weight: 700;
            color: #000;
            margin: 0 auto 1.2rem;
            box-shadow: 0 0 40px rgba(255, 140, 0, 0.3);
            overflow: hidden;
        }

        .seller-popup-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .seller-popup-name {
            text-align: center;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--orange-bright);
            margin-bottom: 0.35rem;
        }

        .seller-popup-meta {
            text-align: center;
            font-size: 0.88rem;
            margin-bottom: 2rem;
            color: rgba(255, 255, 255, 0.4);
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
            color: var(--orange-bright);
        }

        .seller-stat-label {
            font-size: 0.78rem;
            margin-top: 3px;
            color: rgba(255, 255, 255, 0.4);
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
            border-color: var(--orange-bright);
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
            color: var(--orange-bright);
            font-weight: 600;
            margin-top: 3px;
        }

        .seller-popup-msg-btn {
            width: 100%;
            padding: 1.1rem;
            background: linear-gradient(135deg, var(--orange-bright), #ff5500);
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

        /* Light mode seller popup */
        body[data-theme="light"] .seller-popup-avatar {
            background: linear-gradient(135deg, #B0CB1F, #8aA000) !important;
            box-shadow: 0 0 40px rgba(176, 203, 31, 0.3) !important;
        }

        body[data-theme="light"] .seller-popup-name {
            color: #7a9200 !important;
        }

        body[data-theme="light"] .seller-popup-meta,
        body[data-theme="light"] .seller-stat-label,
        body[data-theme="light"] .seller-popup-items-title,
        body[data-theme="light"] .seller-item-title {
            color: #2a3a00 !important;
            opacity: 1;
        }

        body[data-theme="light"] .seller-item-price {
            color: #7a9200 !important;
        }

        body[data-theme="light"] .seller-stat-value {
            color: #7a9200 !important;
        }

        body[data-theme="light"] .seller-popup-topbar-title {
            color: #7a9200 !important;
        }

        body[data-theme="light"] .seller-popup-close {
            background: rgba(176, 203, 31, 0.2);
            border-color: #B0CB1F;
            color: #7a9200;
        }

        body[data-theme="light"] .seller-popup-close:hover {
            background: #B0CB1F;
            color: #1a1f00;
        }

        .unselectable {
            user-select: none;
        }

        /* ========== DELETE CONFIRM MODAL ========== */
        .delete-confirm-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 5000;
        }

        .delete-confirm-modal.show {
            display: flex;
        }

        .delete-confirm-modal-content {
            background: rgba(20, 10, 10, 0.95);
            border: 2px solid #ff4444;
            border-radius: 20px;
            padding: 2rem;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(255, 0, 0, 0.3);
            position: relative;
        }

        .delete-confirm-modal-header {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }

        .delete-confirm-modal-icon {
            font-size: 2rem;
        }

        .delete-confirm-modal-title {
            font-size: 1.3rem;
            color: #ff4444;
            margin: 0;
            flex: 1;
        }

        .delete-confirm-modal-close {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.2rem;
        }

        .delete-confirm-modal-close:hover {
            color: #ff4444;
        }

        .delete-confirm-modal-body {
            margin-bottom: 1.5rem;
        }

        .delete-confirm-modal-text {
            font-size: 1rem;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .delete-confirm-modal-warning {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.5);
        }

        .delete-confirm-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .delete-confirm-cancel-btn {
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: transparent;
            color: #ffffff;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .delete-confirm-cancel-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: #ffffff;
        }

        .delete-confirm-delete-btn {
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            border: none;
            background: #ff4444;
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .delete-confirm-delete-btn:hover {
            background: #ff6666;
            box-shadow: 0 4px 15px rgba(255, 0, 0, 0.4);
        }

        /* Light mode overrides for delete confirm modal */
        body[data-theme="light"] .delete-confirm-modal {
            background: rgba(220, 230, 180, 0.85) !important;
        }

        body[data-theme="light"] .delete-confirm-modal-content {
            background: rgba(255, 245, 240, 0.98) !important;
            border: 2px solid #d32f2f !important;
            box-shadow: 0 20px 60px rgba(200, 0, 0, 0.2) !important;
        }

        body[data-theme="light"] .delete-confirm-modal-title {
            color: #d32f2f !important;
        }

        body[data-theme="light"] .delete-confirm-modal-text {
            color: #1a1f00 !important;
        }

        body[data-theme="light"] .delete-confirm-modal-warning {
            color: rgba(26, 31, 0, 0.5) !important;
        }

        body[data-theme="light"] .delete-confirm-modal-close {
            color: rgba(26, 31, 0, 0.5) !important;
        }

        body[data-theme="light"] .delete-confirm-modal-close:hover {
            color: #d32f2f !important;
        }

        body[data-theme="light"] .delete-confirm-cancel-btn {
            border-color: rgba(26, 31, 0, 0.3) !important;
            color: #1a1f00 !important;
        }

        body[data-theme="light"] .delete-confirm-cancel-btn:hover {
            background: rgba(0, 0, 0, 0.05) !important;
        }

        body[data-theme="light"] .delete-confirm-delete-btn {
            background: #d32f2f !important;
        }

        body[data-theme="light"] .delete-confirm-delete-btn:hover {
            background: #ff4444 !important;
            box-shadow: 0 4px 15px rgba(200, 0, 0, 0.3) !important;
        }

        @media (max-width: 900px) {
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

        /* ---- SCROLLBAR STYLING ---- */
        .product-modal-card ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .product-modal-card ::-webkit-scrollbar-track {
            background: var(--scrollbar-track, #0a0a0a);
            border-radius: 4px;
        }

        .product-modal-card ::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb, rgba(255, 120, 0, 0.3));
            border-radius: 4px;
        }

        .product-modal-card ::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover, rgba(255, 140, 0, 0.5));
        }
    </style>
</head>

<body data-theme="dark">
    <div class="top-bar">
        <div class="top-bar-left">
            <a href="main.php" class="back-btn unselectable">← Vissza</a>
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="admin-btn unselectable"><span class="shield-icon">🛡️</span><span class="button-text">Admin</span></a>
            <?php endif; ?>
        </div>
        <div class="top-bar-right">
            <div class="account-menu">
                <button type="button" class="account-menu-btn unselectable" id="accountMenuBtn"><span>⚙️</span><span class="button-text">FIÓK</span></button>
                <div class="account-dropdown" id="accountDropdown">
                    <div class="account-dropdown-panel">
                        <div class="dropdown-username unselectable"><?php echo htmlspecialchars($user['username']); ?></div>
                        <div class="dropdown-divider"></div>
                        <a href="account.php" class="dropdown-item unselectable">👤 Fiókom</a>
                        <div class="dropdown-divider"></div>
                        <div class="dropdown-theme-row">
                            <span class="dropdown-theme-label unselectable">☀️ Világos mód</span>
                            <label class="theme-switch"><input type="checkbox" id="themeSwitchMain"><span class="theme-switch-track"></span><span class="theme-switch-thumb"></span></label>
                        </div>
                        <div class="dropdown-divider"></div>
                        <form method="post" class="logout-form"><button type="submit" name="logout" class="logout-form-btn dropdown-item unselectable">🚪 Kijelentkezés</button></form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="info-grid">
            <div class="info-card">
                <label class="unselectable">Felhasználónév</label>
                <div class="val unselectable" id="displayUsername"><?= htmlspecialchars($user['username']) ?></div>
            </div>
            <div class="info-card">
                <label class="unselectable">E-mail cím</label>
                <div class="val unselectable" id="displayEmail"><?= htmlspecialchars($user['email']) ?></div>
            </div>
            <div class="info-card" style="display: flex; flex-direction: column; justify-content: center;">
                <button class="edit-btn unselectable" id="openAccountSettingsBtn">⚙️ Fiók módosítása</button>
            </div>
        </div>

        <!-- Profilkép megjelenítés -->
        <div class="info-card" style="display: flex; flex-direction: column; align-items: center; text-align: center;">
            <label class="unselectable">Profilkép</label>
            <div class="profile-pic-container">
                <?php if ($profilePic && file_exists($profilePic)): ?>
                    <img src="<?= htmlspecialchars($profilePic) ?>" class="profile-pic unselectable" id="profileImgPreview" alt="Profilkép">
                <?php else: ?>
                    <div class="no-profile-pic unselectable" id="profileImgPreview">📷</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== TOGGLE GOMBOK SORA ========== -->
        <div class="toggle-buttons-row">
            <button class="section-toggle-btn unselectable" id="toggleItemsMainBtn" onclick="toggleItemsSection()">
                📦 Hirdetéseim (<?= count($userItems) ?>)
            </button>
            <button class="section-toggle-btn unselectable" id="toggleOrdersMainBtn" onclick="toggleOrdersSection()">
                🛒 Rendeléseim (<?= count($userOrders) ?>)
            </button>
        </div>

        <!-- ========== HIRDETÉSEIM SZEKCIÓ ========== -->
        <div class="items-section" id="itemsSection" style="display: none;">
            <h2 class="unselectable">📦 Hirdetéseim</h2>
            <div class="items-grid" id="itemsGrid">
                <?php if (empty($userItems)): ?>
                    <p class="unselectable" style="text-align:center; opacity:0.6; padding: 2rem 0; grid-column: 1 / -1;">Még nem adtál fel hirdetést.</p>
                <?php else: ?>
                    <?php foreach ($userItems as $item): ?>
                        <div class="mini-card" data-item-id="<?= htmlspecialchars($item['id']) ?>">
                            <?php
                            $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? AND is_primary = 1 LIMIT 1");
                            $imgStmt->execute([$item['id']]);
                            $primaryImage = $imgStmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <?php if ($primaryImage): ?>
                                <img src="<?= htmlspecialchars($primaryImage['image_path']) ?>" alt="Kép" class="unselectable">
                            <?php else: ?>
                                <div style="height:140px; background:var(--placeholder-bg); display:flex; align-items:center; justify-content:center; color:var(--orange-bright); font-size:2rem;" class="unselectable">📷</div>
                            <?php endif; ?>
                            <div class="info">
                                <p class="title unselectable"><?= htmlspecialchars($item['title']) ?></p>
                                <p class="price unselectable"><?= number_format($item['price'], 0, ',', ' ') ?> Ft</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== RENDELÉSEIM SZEKCIÓ ========== -->
        <div class="orders-section" id="ordersSection" style="display: none;">
            <h2 class="unselectable">🛒 Rendeléseim</h2>
            <div class="orders-grid" id="ordersGrid">
                <?php if (empty($userOrders)): ?>
                    <p class="unselectable" style="text-align:center; opacity:0.6; padding: 2rem 0; grid-column: 1 / -1;">Még nincsenek rendeléseid.</p>
                <?php else: ?>
                    <?php foreach ($userOrders as $order):
                        $statusClass = 'status-pending';
                        $statusText = 'Függőben';
                        if ($order['status'] === 'completed') {
                            $statusClass = 'status-completed';
                            $statusText = 'Teljesítve';
                        } elseif ($order['status'] === 'cancelled') {
                            $statusClass = 'status-cancelled';
                            $statusText = 'Törölve';
                        }

                        $paymentLabels = [
                            'cod'      => 'Utánvétel',
                            'transfer' => 'Banki átutalás',
                            'pickup'   => 'Személyes átvétel'
                        ];
                        $paymentText = $paymentLabels[$order['payment_method']] ?? $order['payment_method'];
                    ?>
                        <div class="order-card">
                            <div class="order-header">
                                <?php if (!empty($order['item_image'])): ?>
                                    <img src="<?= htmlspecialchars($order['item_image']) ?>" alt="Termék" class="order-image">
                                <?php else: ?>
                                    <div class="order-image-placeholder">📷</div>
                                <?php endif; ?>
                                <div class="order-info">
                                    <div class="order-title" data-item-id="<?= htmlspecialchars($order['item_id']) ?>">
                                        <?= htmlspecialchars($order['item_title']) ?>
                                    </div>
                                    <div class="order-price"><?= number_format($order['item_price'], 0, ',', ' ') ?> Ft</div>
                                    <div class="order-meta">
                                        Eladó: <strong onclick="event.stopPropagation(); openSellerPopup(<?= $order['seller_id'] ?>);"><?= htmlspecialchars($order['seller_name']) ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="order-details">
                                <div>
                                    <span class="detail-label">Rendelés ID</span>
                                    <span><?= htmlspecialchars($order['id']) ?></span>
                                </div>
                                <div>
                                    <span class="detail-label">Státusz</span>
                                    <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                                </div>
                                <div>
                                    <span class="detail-label">Fizetési mód</span>
                                    <span><?= $paymentText ?></span>
                                </div>
                                <div>
                                    <span class="detail-label">Dátum</span>
                                    <span><?= date('Y-m-d H:i', strtotime($order['created_at'])) ?></span>
                                </div>
                                <div style="grid-column: 1 / -1;">
                                    <span class="detail-label">Szállítási cím</span>
                                    <span><?= htmlspecialchars($order['shipping_zip'] . ' ' . $order['shipping_city'] . ', ' . $order['shipping_address']) ?></span>
                                </div>
                                <?php if (!empty($order['notes'])): ?>
                                    <div style="grid-column: 1 / -1;">
                                        <span class="detail-label">Megjegyzés</span>
                                        <span><?= htmlspecialchars($order['notes']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========== ÚJ MODÁLOK ========== -->
    <!-- 1. FŐ MODÁL a 4 opcióval -->
    <div class="modal-overlay" id="accountSettingsModal">
        <div class="modal-card" style="max-width: 400px;">
            <button class="modal-close unselectable" onclick="closeAccountSettingsModal()">✕</button>
            <h3 class="modal-title unselectable">Fiók beállítások</h3>
            <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                <button class="submit-btn" id="changeProfilePicBtn">🖼️ Profilkép módosítás</button>
                <button class="submit-btn" id="changeUsernameBtn">✏️ Felhasználónév módosítás</button>
                <button class="submit-btn" id="changeEmailBtn">📧 E-mail cím módosítás</button>
                <button class="submit-btn" id="changePasswordBtn">🔒 Jelszó módosítás</button>
            </div>
        </div>
    </div>

    <!-- 2. PROFILKÉP FELTÖLTÉS MODÁL -->
    <div class="modal-overlay" id="profilePicModal">
        <div class="modal-card">
            <button class="modal-close unselectable" onclick="closeProfilePicModal()">✕</button>
            <h3 class="modal-title unselectable">Profilkép módosítása</h3>
            <form id="profilePicForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Válassz képet</label>
                    <input type="file" name="profile_image" id="profileImageInput" accept="image/jpeg,image/png,image/gif,image/webp" required>
                </div>
                <div id="profilePicStatus" class="status-msg"></div>
                <button type="submit" class="submit-btn" id="uploadProfilePicBtn">Feltöltés</button>
            </form>
        </div>
    </div>

    <!-- 3. FELHASZNÁLÓNÉV MÓDOSÍTÁS MODÁL -->
    <div class="modal-overlay" id="usernameModal">
        <div class="modal-card">
            <button class="modal-close unselectable" onclick="closeUsernameModal()">✕</button>
            <h3 class="modal-title unselectable">Felhasználónév módosítása</h3>
            <form id="usernameForm">
                <div class="form-group">
                    <label>Új felhasználónév</label>
                    <input type="text" name="username" id="newUsername" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                <div id="usernameStatus" class="status-msg"></div>
                <button type="submit" class="submit-btn">Mentés</button>
            </form>
        </div>
    </div>

    <!-- 4. EMAIL MÓDOSÍTÁS MODÁL -->
    <div class="modal-overlay" id="emailModal">
        <div class="modal-card">
            <button class="modal-close unselectable" onclick="closeEmailModal()">✕</button>
            <h3 class="modal-title unselectable">E-mail cím módosítása</h3>
            <form id="emailForm">
                <div class="form-group">
                    <label>Új e-mail cím</label>
                    <input type="email" name="email" id="newEmail" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div id="emailStatus" class="status-msg"></div>
                <button type="submit" class="submit-btn">Mentés</button>
            </form>
        </div>
    </div>

    <!-- 5. JELSZÓ MÓDOSÍTÁS MODÁL -->
    <div class="modal-overlay" id="passwordModal">
        <div class="modal-card">
            <button class="modal-close unselectable" onclick="closePasswordModal()">✕</button>
            <h3 class="modal-title unselectable">Jelszó módosítása</h3>
            <form id="passwordForm">
                <div class="form-group">
                    <label>Régi jelszó</label>
                    <input type="password" name="old_password" id="oldPassword" required>
                </div>
                <div class="form-group">
                    <label>Új jelszó (legalább 6 karakter)</label>
                    <input type="password" name="new_password" id="newPassword" required>
                </div>
                <div class="form-group">
                    <label>Új jelszó megerősítése</label>
                    <input type="password" name="confirm_password" id="confirmPassword" required>
                </div>
                <div id="passwordStatus" class="status-msg"></div>
                <button type="submit" class="submit-btn">Jelszó megváltoztatása</button>
            </form>
        </div>
    </div>

    <!-- Termék szerkesztő modal -->
    <div class="edit-modal" id="editItemModal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <div class="edit-modal-title unselectable">Hirdetés szerkesztése</div>
                <button class="edit-modal-close unselectable" onclick="closeEditItemModal()">✕</button>
            </div>
            <?php if ($editSuccess): ?>
                <div class="edit-success-banner">✓ Módosítás sikeresen mentve!</div>
            <?php endif; ?>
            <div class="edit-modal-body">
                <form method="post" id="editItemForm">
                    <input type="hidden" name="item_id" id="editItemId">
                    <input type="hidden" name="edit_item" value="1">
                    <div class="edit-form-group">
                        <label class="edit-form-label unselectable">📌 Cím</label>
                        <input class="edit-form-input" type="text" id="edit_title" name="edit_title" required>
                    </div>
                    <div class="edit-form-group">
                        <label class="edit-form-label unselectable">📄 Leírás</label>
                        <textarea class="edit-form-textarea" id="edit_description" name="edit_description" rows="5" required></textarea>
                    </div>
                    <div class="edit-form-group">
                        <label class="edit-form-label unselectable">💰 Ár</label>
                        <div class="edit-price-wrapper">
                            <input class="edit-form-input" type="number" id="edit_price" name="edit_price" min="0" step="1" required>
                            <span class="edit-price-suffix unselectable">Ft</span>
                        </div>
                    </div>
                    <div class="edit-modal-actions">
                        <button type="button" class="btn-edit-cancel unselectable" onclick="closeEditItemModal()">Mégse</button>
                        <button type="submit" class="btn-edit-save unselectable">Mentés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Termék modal (részletek) -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <div class="product-modal-header">
                <div class="product-menu" id="productMenuContainer" style="display: none;">
                    <div class="product-menu-button unselectable" onclick="toggleProductMenu(this)">⋮</div>
                    <div class="product-menu-content" id="productMenuContent">
                        <button class="product-menu-item unselectable" id="productReportBtn" style="display:none;">⚠️ Bejelentés</button>
                        <button class="product-menu-item unselectable" id="productEditBtn" style="display:none;">✏️ Módosítás</button>
                        <button class="product-menu-item delete unselectable" id="productDeleteBtn" style="display:none;">🗑️ Törlés</button>
                    </div>
                </div>
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
                <div class="product-description selectable" id="productDescription"></div>
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

    <!-- Seller Profile Popup -->
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

    <!-- Report Modal (termék bejelentés) -->
    <div class="modal-overlay" id="reportItemModal">
        <div class="modal-card" style="max-width: 500px;">
            <button class="modal-close unselectable" onclick="closeReportModal()">✕</button>
            <h3 class="modal-title unselectable">⚠️ Termék bejelentése</h3>
            <form method="post" id="reportForm">
                <input type="hidden" name="item_id" id="reportItemId">
                <input type="hidden" name="report_item" value="1">
                <div class="form-group">
                    <label>Indoklás</label>
                    <textarea name="report_reason" rows="4" required placeholder="Kérjük, részletezd a problémát..."></textarea>
                </div>
                <button type="submit" class="submit-btn">Bejelentés küldése</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirm Modal -->
    <div class="delete-confirm-modal" id="deleteConfirmModal">
        <div class="delete-confirm-modal-content">
            <div class="delete-confirm-modal-header">
                <span class="delete-confirm-modal-icon">⚠️</span>
                <h3 class="delete-confirm-modal-title unselectable">Hirdetés törlése</h3>
                <button class="delete-confirm-modal-close unselectable" onclick="closeDeleteConfirmModal()">✕</button>
            </div>
            <div class="delete-confirm-modal-body">
                <p class="delete-confirm-modal-text unselectable">Biztosan törölni szeretnéd ezt a hirdetést?</p>
                <p class="delete-confirm-modal-warning unselectable">A törlés végleges, nem vonható vissza.</p>
            </div>
            <div class="delete-confirm-modal-actions">
                <button class="delete-confirm-cancel-btn unselectable" onclick="closeDeleteConfirmModal()">Mégse</button>
                <button class="delete-confirm-delete-btn unselectable" id="confirmDeleteBtn">Törlés</button>
            </div>
        </div>
    </div>

    <script>
        // Téma kezelés
        const themeLink = document.getElementById('themeStylesheet');
        const savedTheme = localStorage.getItem('theme') || 'dark';
        themeLink.href = savedTheme === 'light' ? 'theme-light.css' : 'theme-dark.css';
        document.body.setAttribute('data-theme', savedTheme);
        const themeSwitch = document.getElementById('themeSwitchMain');
        if (themeSwitch) {
            themeSwitch.checked = (savedTheme === 'light');
            themeSwitch.addEventListener('change', function() {
                const newTheme = this.checked ? 'light' : 'dark';
                themeLink.href = newTheme === 'light' ? 'theme-light.css' : 'theme-dark.css';
                localStorage.setItem('theme', newTheme);
                document.body.setAttribute('data-theme', newTheme);
            });
        }

        // Account dropdown
        const accountMenuBtn = document.getElementById('accountMenuBtn');
        const accountDropdown = document.getElementById('accountDropdown');

        function closeDropdown() {
            accountDropdown.classList.remove('show');
        }

        function toggleDropdown(e) {
            e.stopPropagation();
            accountDropdown.classList.toggle('show');
        }
        if (accountMenuBtn && accountDropdown) {
            accountMenuBtn.addEventListener('click', toggleDropdown);
            accountDropdown.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', closeDropdown);
        }

        // ---- TOGGLE FUNKCIÓK ----
        function toggleItemsSection() {
            const section = document.getElementById('itemsSection');
            const btn = document.getElementById('toggleItemsMainBtn');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                btn.classList.add('active');
            } else {
                section.style.display = 'none';
                btn.classList.remove('active');
            }
        }

        function toggleOrdersSection() {
            const section = document.getElementById('ordersSection');
            const btn = document.getElementById('toggleOrdersMainBtn');
            if (section.style.display === 'none' || section.style.display === '') {
                section.style.display = 'block';
                btn.classList.add('active');
            } else {
                section.style.display = 'none';
                btn.classList.remove('active');
            }
        }

        // ---- ÚJ MODÁLOK KEZELÉSE ----
        const accountSettingsModal = document.getElementById('accountSettingsModal');
        const profilePicModal = document.getElementById('profilePicModal');
        const usernameModal = document.getElementById('usernameModal');
        const emailModal = document.getElementById('emailModal');
        const passwordModal = document.getElementById('passwordModal');

        function openAccountSettingsModal() {
            accountSettingsModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAccountSettingsModal() {
            accountSettingsModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeProfilePicModal() {
            profilePicModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeUsernameModal() {
            usernameModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeEmailModal() {
            emailModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closePasswordModal() {
            passwordModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('openAccountSettingsBtn').addEventListener('click', openAccountSettingsModal);
        document.getElementById('changeProfilePicBtn').addEventListener('click', () => {
            closeAccountSettingsModal();
            profilePicModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        document.getElementById('changeUsernameBtn').addEventListener('click', () => {
            closeAccountSettingsModal();
            usernameModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        document.getElementById('changeEmailBtn').addEventListener('click', () => {
            closeAccountSettingsModal();
            emailModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        document.getElementById('changePasswordBtn').addEventListener('click', () => {
            closeAccountSettingsModal();
            passwordModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        // Modálok bezárása background kattintásra
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(m => {
                    m.classList.remove('active');
                    document.body.style.overflow = '';
                });
                if (productModal.classList.contains('active')) closeProductModal();
                if (sellerOverlay.classList.contains('active')) closeSellerPopup();
                if (editItemModal.classList.contains('show')) closeEditItemModal();
                if (document.getElementById('deleteConfirmModal').classList.contains('show')) closeDeleteConfirmModal();
            }
        });

        // --- Profilkép feltöltés AJAX ---
        const profilePicForm = document.getElementById('profilePicForm');
        const profilePicStatus = document.getElementById('profilePicStatus');
        const profileImageInput = document.getElementById('profileImageInput');
        const profileImgPreview = document.getElementById('profileImgPreview');

        profilePicForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(profilePicForm);
            formData.append('upload_profile_picture', '1');
            profilePicStatus.style.display = 'block';
            profilePicStatus.className = 'status-msg';
            profilePicStatus.textContent = 'Feltöltés...';
            try {
                const res = await fetch('account.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    profilePicStatus.classList.add('success');
                    profilePicStatus.textContent = data.message;
                    if (data.new_image) {
                        if (profileImgPreview.tagName === 'IMG') {
                            profileImgPreview.src = data.new_image + '?t=' + Date.now();
                        } else {
                            const newImg = document.createElement('img');
                            newImg.src = data.new_image + '?t=' + Date.now();
                            newImg.className = 'profile-pic';
                            newImg.id = 'profileImgPreview';
                            profileImgPreview.parentNode.replaceChild(newImg, profileImgPreview);
                        }
                    }
                    setTimeout(() => {
                        closeProfilePicModal();
                    }, 1500);
                } else {
                    profilePicStatus.classList.add('error');
                    profilePicStatus.textContent = data.message;
                }
            } catch (err) {
                profilePicStatus.classList.add('error');
                profilePicStatus.textContent = 'Hálózati hiba.';
            }
        });

        // --- Felhasználónév módosítás AJAX ---
        const usernameForm = document.getElementById('usernameForm');
        const usernameStatus = document.getElementById('usernameStatus');
        usernameForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(usernameForm);
            formData.append('update_username', '1');
            usernameStatus.style.display = 'block';
            usernameStatus.className = 'status-msg';
            usernameStatus.textContent = 'Feldolgozás...';
            try {
                const res = await fetch('account.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    usernameStatus.classList.add('success');
                    usernameStatus.textContent = data.message;
                    document.getElementById('displayUsername').textContent = document.getElementById('newUsername').value;
                    document.querySelector('.dropdown-username').textContent = document.getElementById('newUsername').value;
                    setTimeout(() => {
                        closeUsernameModal();
                    }, 1500);
                } else {
                    usernameStatus.classList.add('error');
                    usernameStatus.textContent = data.message;
                }
            } catch (err) {
                usernameStatus.classList.add('error');
                usernameStatus.textContent = 'Hálózati hiba.';
            }
        });

        // --- E-mail módosítás AJAX ---
        const emailForm = document.getElementById('emailForm');
        const emailStatus = document.getElementById('emailStatus');
        emailForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(emailForm);
            formData.append('update_email', '1');
            emailStatus.style.display = 'block';
            emailStatus.className = 'status-msg';
            emailStatus.textContent = 'Feldolgozás...';
            try {
                const res = await fetch('account.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    emailStatus.classList.add('success');
                    emailStatus.textContent = data.message;
                    document.getElementById('displayEmail').textContent = document.getElementById('newEmail').value;
                    setTimeout(() => {
                        closeEmailModal();
                    }, 1500);
                } else {
                    emailStatus.classList.add('error');
                    emailStatus.textContent = data.message;
                }
            } catch (err) {
                emailStatus.classList.add('error');
                emailStatus.textContent = 'Hálózati hiba.';
            }
        });

        // --- Jelszó módosítás AJAX ---
        const passwordForm = document.getElementById('passwordForm');
        const passwordStatus = document.getElementById('passwordStatus');
        passwordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(passwordForm);
            formData.append('update_password', '1');
            passwordStatus.style.display = 'block';
            passwordStatus.className = 'status-msg';
            passwordStatus.textContent = 'Feldolgozás...';
            try {
                const res = await fetch('account.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    passwordStatus.classList.add('success');
                    passwordStatus.textContent = data.message;
                    document.getElementById('oldPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                    setTimeout(() => {
                        closePasswordModal();
                    }, 1500);
                } else {
                    passwordStatus.classList.add('error');
                    passwordStatus.textContent = data.message;
                }
            } catch (err) {
                passwordStatus.classList.add('error');
                passwordStatus.textContent = 'Hálózati hiba.';
            }
        });

        // --- Termék modal, szerkesztés, törlés ---
        let currentProductImages = [];
        let currentImageIndex = 0;
        let currentProductId = null;
        let currentProductUserId = null;

        const productModal = document.getElementById('productModal');
        const closeProductModalBtn = document.getElementById('closeProductModalBtn');
        const productMainImage = document.getElementById('productMainImage');
        const productNoImagePlaceholder = document.getElementById('productNoImagePlaceholder');
        const lightboxOverlay = document.getElementById('lightboxOverlay');
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxClose = document.getElementById('lightboxClose');
        const editItemModal = document.getElementById('editItemModal');
        const editItemId = document.getElementById('editItemId');
        const editTitle = document.getElementById('edit_title');
        const editDesc = document.getElementById('edit_description');
        const editPrice = document.getElementById('edit_price');
        const reportItemModal = document.getElementById('reportItemModal');
        const reportItemId = document.getElementById('reportItemId');

        function setMainImage(index) {
            if (index >= 0 && index < currentProductImages.length && currentProductImages[index]) {
                productMainImage.style.display = 'block';
                productNoImagePlaceholder.style.display = 'none';
                productMainImage.src = currentProductImages[index];
                currentImageIndex = index;
                document.querySelectorAll('.product-thumbnail').forEach((thumb, i) => thumb.classList.toggle('active', i === index));
            } else {
                productMainImage.style.display = 'none';
                productNoImagePlaceholder.style.display = 'block';
            }
        }

        function openProductModal() {
            productModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProductModal() {
            if (lightboxOverlay.classList.contains('active')) lightboxOverlay.classList.remove('active');
            productModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function closeLightbox() {
            lightboxOverlay.classList.remove('active');
        }

        function openEditItemModal(itemId, title, description, price) {
            editItemId.value = itemId;
            editTitle.value = title;
            editDesc.value = description;
            editPrice.value = parseFloat(price) || price;
            editItemModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeEditItemModal() {
            editItemModal.classList.remove('show');
            document.body.style.overflow = productModal.classList.contains('active') ? 'hidden' : '';
        }

        function openReportModal(itemId) {
            reportItemId.value = itemId;
            reportItemModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeReportModal() {
            reportItemModal.classList.remove('active');
            document.body.style.overflow = productModal.classList.contains('active') ? 'hidden' : '';
        }

        editItemModal.addEventListener('click', function(e) {
            if (e.target === editItemModal) closeEditItemModal();
        });

        reportItemModal.addEventListener('click', function(e) {
            if (e.target === reportItemModal) closeReportModal();
        });

        // ========== DELETE CONFIRM MODAL ==========
        let pendingDeleteItemId = null;
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

        function openDeleteConfirmModal(itemId) {
            pendingDeleteItemId = itemId;
            deleteConfirmModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteConfirmModal() {
            deleteConfirmModal.classList.remove('show');
            document.body.style.overflow = '';
            pendingDeleteItemId = null;
        }

        confirmDeleteBtn.addEventListener('click', function() {
            if (pendingDeleteItemId) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="item_id" value="${pendingDeleteItemId}">
                    <input type="hidden" name="delete_item" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });

        deleteConfirmModal.addEventListener('click', function(e) {
            if (e.target === deleteConfirmModal) closeDeleteConfirmModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && deleteConfirmModal.classList.contains('show')) {
                closeDeleteConfirmModal();
            }
        });

        // ========== fetchItemDetails MÓDOSÍTÁSOKKAL ==========
        function fetchItemDetails(itemId) {
            fetch(`?get_item=${itemId}`).then(r => r.json()).then(item => {
                if (item.error) return;
                currentProductId = item.id;
                currentProductUserId = item.user_id;
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
                        thumbnail.innerHTML = `<img src="${img}" alt="Thumbnail">`;
                        thumbnail.addEventListener('click', (e) => {
                            e.stopPropagation();
                            setMainImage(index);
                        });
                        thumbnailsContainer.appendChild(thumbnail);
                    });
                    setMainImage(0);
                } else setMainImage(-1);
                const prevBtn = document.getElementById('galleryPrev');
                const nextBtn = document.getElementById('galleryNext');
                prevBtn.classList.toggle('hidden', !item.images || item.images.length <= 1);
                nextBtn.classList.toggle('hidden', !item.images || item.images.length <= 1);

                const menuContainer = document.getElementById('productMenuContainer');
                const reportBtn = document.getElementById('productReportBtn');
                const deleteBtn = document.getElementById('productDeleteBtn');
                const editBtn = document.getElementById('productEditBtn');
                const buyBtn = document.getElementById('productBuyBtn');
                const isOwner = (parseInt(item.user_id) === <?php echo (int)$_SESSION['user_id']; ?>);

                reportBtn.style.display = 'none';
                editBtn.style.display = 'none';
                deleteBtn.style.display = 'none';
                buyBtn.style.display = 'none';

                let hasVisibleMenu = false;

                if (isOwner) {
                    editBtn.style.display = 'block';
                    deleteBtn.style.display = 'block';
                    hasVisibleMenu = true;
                    buyBtn.style.display = 'none';
                } else {
                    reportBtn.style.display = 'block';
                    hasVisibleMenu = true;
                    buyBtn.style.display = 'flex';

                    // ---- MÓDOSÍTÁS: sold alapján teljesen szürke gomb ----
                    if (item.sold == 1) {
                        buyBtn.textContent = 'Elkelt';
                        buyBtn.classList.add('sold');
                        buyBtn.disabled = true;
                        buyBtn.onclick = null;
                    } else {
                        buyBtn.textContent = '🛒 Vásárlás';
                        buyBtn.classList.remove('sold');
                        buyBtn.disabled = false;
                        buyBtn.onclick = () => {
                            window.location.href = 'vasarlas.php?item_id=' + encodeURIComponent(item.id);
                        };
                    }
                }

                menuContainer.style.display = hasVisibleMenu ? 'block' : 'none';

                editBtn.onclick = () => {
                    openEditItemModal(item.id, item.title, item.description, item.price);
                };
                deleteBtn.onclick = () => {
                    openDeleteConfirmModal(item.id);
                };
                reportBtn.onclick = () => {
                    openReportModal(item.id);
                };

                openProductModal();
            }).catch(err => console.error(err));
        }

        function toggleProductMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');
        }

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

                    const currentUserId = <?php echo (int)$_SESSION['user_id']; ?>;
                    const memberSince = data.created_at ? data.created_at.substring(0, 10) : '—';
                    const adminBadge = parseInt(data.is_admin) ? ' <span class="admin-badge unselectable">Admin</span>' : '';
                    const initial = data.username ? data.username.charAt(0).toUpperCase() : '?';

                    document.querySelector('.seller-popup-topbar-title').textContent = '👤 ' + data.username;

                    let avatarHtml;
                    if (data.profile_picture && data.profile_picture.trim() !== '') {
                        avatarHtml = `<img src="${escapeHtml(data.profile_picture)}" class="seller-popup-avatar-img" alt="${escapeHtml(data.username)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
                    } else {
                        avatarHtml = `<div class="seller-popup-avatar unselectable">${initial}</div>`;
                    }

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
                        <div class="seller-popup-avatar unselectable" style="display: flex; align-items: center; justify-content: center;">
                            ${avatarHtml}
                        </div>
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

        document.getElementById('productSeller').addEventListener('click', function() {
            const sellerId = this.getAttribute('data-seller-id');
            if (sellerId) openSellerPopup(sellerId);
        });

        closeProductModalBtn.addEventListener('click', closeProductModal);
        productModal.addEventListener('click', (e) => {
            if (e.target === productModal) closeProductModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && productModal.classList.contains('active')) closeProductModal();
        });
        document.getElementById('galleryPrev').addEventListener('click', (e) => {
            e.stopPropagation();
            setMainImage(currentImageIndex - 1 >= 0 ? currentImageIndex - 1 : currentProductImages.length - 1);
        });
        document.getElementById('galleryNext').addEventListener('click', (e) => {
            e.stopPropagation();
            setMainImage(currentImageIndex + 1 < currentProductImages.length ? currentImageIndex + 1 : 0);
        });
        productMainImage.addEventListener('click', (e) => {
            if (productMainImage.src && productMainImage.style.display !== 'none') {
                lightboxImage.src = productMainImage.src;
                lightboxOverlay.classList.add('active');
            }
        });
        lightboxClose.addEventListener('click', closeLightbox);
        lightboxOverlay.addEventListener('click', (e) => {
            if (e.target === lightboxOverlay) closeLightbox();
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

        document.querySelectorAll('.mini-card').forEach(card => {
            card.addEventListener('click', function(e) {
                const itemId = this.dataset.itemId;
                if (itemId) fetchItemDetails(itemId);
            });
        });

        document.querySelectorAll('.order-title').forEach(el => {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                const itemId = this.dataset.itemId;
                if (itemId) fetchItemDetails(itemId);
            });
        });
    </script>
</body>

</html>