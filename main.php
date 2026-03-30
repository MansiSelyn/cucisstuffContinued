<?php
session_start();
if (isset($_POST['logout'])) {
    $_SESSION = array();
    session_destroy();
    header("Location: index.php");
    exit();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cucidb";

// Sessionből hibaüzenet és form adatok betöltése (ha van)
$uploadError = $_SESSION['upload_error'] ?? '';
$formData = $_SESSION['form_data'] ?? [];
// Session adatok törlése olvasás után
unset($_SESSION['upload_error'], $_SESSION['form_data']);

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if current user is admin
    $isAdmin = false;
    if (isset($_SESSION['user_id'])) {
        $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
        $adminCheck->execute([$_SESSION['user_id']]);
        $isAdmin = $adminCheck->fetchColumn() > 0;
    }

    // =============================================
    // SEARCH HANDLER (JSON output)
    // =============================================
    if (isset($_GET['search_query']) && strlen($_GET['search_query']) >= 2) {
        header('Content-Type: application/json');
        $query = '%' . $_GET['search_query'] . '%';
        $stmt = $conn->prepare("
            SELECT 
                i.id, i.title, i.price, u.username as seller_name,
                (SELECT image_path FROM item_images WHERE item_id = i.id AND is_primary = 1 LIMIT 1) as primary_image
            FROM items i
            JOIN users u ON i.user_id = u.id
            WHERE i.title LIKE :q OR i.description LIKE :q
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([':q' => $query]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);
        exit;
    }

    // =============================================
    // GET ITEM DETAILS (JSON output)
    // =============================================
    if (isset($_GET['get_item']) && !empty($_GET['get_item'])) {
        header('Content-Type: application/json');
        $itemId = $_GET['get_item'];

        // Fetch item details
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

        // Fetch all images for the item
        $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY sort_order");
        $imgStmt->execute([$itemId]);
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        $item['images'] = $images;

        echo json_encode($item);
        exit;
    }

    // Handle new item upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_item'])) {
        $title       = trim($_POST['item_title'] ?? '');
        $description = trim($_POST['item_description'] ?? '');
        $price       = trim($_POST['item_price'] ?? '');

        // Check for uploaded files
        if (!isset($_FILES['item_images']) || empty($_FILES['item_images']['name'][0])) {
            $_SESSION['upload_error'] = 'Legalább egy képet fel kell tölteni!';
            $_SESSION['form_data'] = [
                'item_title' => $title,
                'item_description' => $description,
                'item_price' => $price
            ];
            header("Location: main.php");
            exit();
        } elseif ($title === '' || $description === '' || $price === '') {
            $_SESSION['upload_error'] = 'Minden mező kitöltése kötelező!';
            $_SESSION['form_data'] = [
                'item_title' => $title,
                'item_description' => $description,
                'item_price' => $price
            ];
            header("Location: main.php");
            exit();
        } elseif (!is_numeric($price) || floatval($price) < 0) {
            $_SESSION['upload_error'] = 'Az ár csak pozitív szám lehet!';
            $_SESSION['form_data'] = [
                'item_title' => $title,
                'item_description' => $description,
                'item_price' => $price
            ];
            header("Location: main.php");
            exit();
        } else {
            // Validate images
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            $files = $_FILES['item_images'];
            $uploadValid = true;

            // Check each file
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $_SESSION['upload_error'] = 'Hiba történt a képfeltöltés során!';
                    $_SESSION['form_data'] = [
                        'item_title' => $title,
                        'item_description' => $description,
                        'item_price' => $price
                    ];
                    header("Location: main.php");
                    exit();
                }
                if (!in_array($files['type'][$i], $allowedTypes)) {
                    $_SESSION['upload_error'] = 'Csak JPEG, PNG, GIF és WebP formátumú képek tölthetők fel!';
                    $_SESSION['form_data'] = [
                        'item_title' => $title,
                        'item_description' => $description,
                        'item_price' => $price
                    ];
                    header("Location: main.php");
                    exit();
                }
                if ($files['size'][$i] > $maxFileSize) {
                    $_SESSION['upload_error'] = 'Egy kép maximális mérete 5MB lehet!';
                    $_SESSION['form_data'] = [
                        'item_title' => $title,
                        'item_description' => $description,
                        'item_price' => $price
                    ];
                    header("Location: main.php");
                    exit();
                }
            }

            if ($uploadValid) {
                // Generate unique 12-char ID for the item
                do {
                    $newId = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
                    $check = $conn->prepare("SELECT COUNT(*) FROM items WHERE id = ?");
                    $check->execute([$newId]);
                } while ($check->fetchColumn() > 0);

                // Start transaction
                $conn->beginTransaction();
                try {
                    // Insert item
                    $insert = $conn->prepare("
                        INSERT INTO items (id, user_id, title, description, price)
                        VALUES (:id, :user_id, :title, :description, :price)
                    ");
                    $insert->execute([
                        ':id'          => $newId,
                        ':user_id'     => $_SESSION['user_id'],
                        ':title'       => $title,
                        ':description' => $description,
                        ':price'       => floatval($price),
                    ]);

                    // Create directory for images if it doesn't exist
                    $uploadDir = 'uploads/' . $newId . '/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    // Upload each image
                    $sortOrder = 0;
                    for ($i = 0; $i < count($files['name']); $i++) {
                        // Generate unique filename to avoid conflicts
                        $extension = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                        $filename = uniqid() . '_' . $i . '.' . $extension;
                        $filepath = $uploadDir . $filename;

                        // Move uploaded file
                        if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
                            // Save to database
                            $imageInsert = $conn->prepare("
                                INSERT INTO item_images (item_id, image_path, image_filename, is_primary, sort_order)
                                VALUES (:item_id, :image_path, :image_filename, :is_primary, :sort_order)
                            ");
                            $imageInsert->execute([
                                ':item_id' => $newId,
                                ':image_path' => $filepath,
                                ':image_filename' => $filename,
                                ':is_primary' => ($i === 0) ? 1 : 0,
                                ':sort_order' => $sortOrder
                            ]);
                            $sortOrder++;
                        } else {
                            throw new Exception('Hiba történt a kép mentése során: ' . $files['name'][$i]);
                        }
                    }

                    $conn->commit();

                    // ✅ ÁTIRÁNYÍTÁS SIKERES FELTÖLTÉS UTÁN (PRG Pattern)
                    header("Location: main.php?upload=success");
                    exit();
                } catch (Exception $e) {
                    $conn->rollBack();
                    $_SESSION['upload_error'] = 'Hiba történt a hirdetés mentése során: ' . $e->getMessage();
                    $_SESSION['form_data'] = [
                        'item_title' => $title,
                        'item_description' => $description,
                        'item_price' => $price
                    ];
                    header("Location: main.php");
                    exit();
                }
            }
        }
    }

    // Handle item update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
        $itemId  = $_POST['item_id'] ?? '';
        $title   = trim($_POST['edit_title'] ?? '');
        $desc    = trim($_POST['edit_description'] ?? '');
        $price   = trim($_POST['edit_price'] ?? '');

        // Verify ownership or admin
        $ownerCheck = $conn->prepare("SELECT user_id FROM items WHERE id = ?");
        $ownerCheck->execute([$itemId]);
        $ownerRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);

        $canEdit = $itemId && $ownerRow && ($isAdmin || (isset($_SESSION['user_id']) && $ownerRow['user_id'] == $_SESSION['user_id']));

        if ($canEdit && $title !== '' && $desc !== '' && is_numeric($price) && floatval($price) >= 0) {
            try {
                $upd = $conn->prepare("UPDATE items SET title=:title, description=:desc, price=:price WHERE id=:id");
                $upd->execute([':title' => $title, ':desc' => $desc, ':price' => floatval($price), ':id' => $itemId]);
                header("Location: main.php?edit=success");
                exit();
            } catch (Exception $e) {
                $uploadError = 'Hiba a módosítás során: ' . $e->getMessage();
            }
        } else {
            $uploadError = 'Érvénytelen adatok vagy nincs jogosultság!';
        }
    }

    // Handle item deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
        $itemId = $_POST['item_id'] ?? '';

        // Check ownership
        $ownerCheck2 = $conn->prepare("SELECT user_id FROM items WHERE id = ?");
        $ownerCheck2->execute([$itemId]);
        $ownerRow2 = $ownerCheck2->fetch(PDO::FETCH_ASSOC);
        $canDelete = $itemId && $ownerRow2 && ($isAdmin || (isset($_SESSION['user_id']) && $ownerRow2['user_id'] == $_SESSION['user_id']));

        if ($canDelete) {
            try {
                // Get all images for this item to delete files
                $imageStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ?");
                $imageStmt->execute([$itemId]);
                $images = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

                // Delete image files
                foreach ($images as $image) {
                    if (file_exists($image['image_path'])) {
                        unlink($image['image_path']);
                    }
                }

                // Delete the item's directory
                $itemDir = 'uploads/' . $itemId . '/';
                if (is_dir($itemDir)) {
                    rmdir($itemDir);
                }

                // Delete from database
                $deleteStmt = $conn->prepare("DELETE FROM items WHERE id = ?");
                $deleteStmt->execute([$itemId]);

                // Redirect to refresh the page
                if ($isAdmin) {
                    header("Location: main.php");
                } else {
                    header("Location: main.php?deleted=1");
                }
                exit();
            } catch (Exception $e) {
                $uploadError = 'Hiba történt a törlés során: ' . $e->getMessage();
            }
        }
    }

    // Handle item report
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_item'])) {
        $itemId = $_POST['item_id'] ?? '';
        $reason = trim($_POST['report_reason'] ?? '');
        if ($itemId && $reason) {
            try {
                $reportStmt = $conn->prepare("
                    INSERT INTO reports (item_id, user_id, reason, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $reportStmt->execute([$itemId, $_SESSION['user_id'], $reason]);
                $reportSuccess = true;
            } catch (Exception $e) {
                $reportError = 'Hiba történt a bejelentés során: ' . $e->getMessage();
            }
        }
    }

    // Pagination settings
    $itemsPerPage = 24;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $itemsPerPage;

    // Get total items count
    $totalStmt = $conn->query("SELECT COUNT(*) FROM items");
    $totalItems = $totalStmt->fetchColumn();
    $totalPages = ceil($totalItems / $itemsPerPage);

    // Fetch items for current page with RANDOM ordering
    $stmt = $conn->prepare("
        SELECT i.*, u.username as seller_name
        FROM items i
        JOIN users u ON i.user_id = u.id
        ORDER BY RAND()
        LIMIT :offset, :itemsPerPage
    ");
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    $items = [];
    $totalPages = 0;
    $page = 1;
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Főoldal - Termékek</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <style>
        /* ═══════════════════════════════════════════════════════════════════
        MAIN STYLES (dark mode default)
        ═══════════════════════════════════════════════════════════════════ */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --orange-bright: #ff8c00;
            --orange-glow: rgba(255, 140, 0, 0.3);
            --glass-bg: rgba(0, 0, 0, 0.7);
            --glass-border: rgba(255, 140, 0, 0.2);
            --text-primary: #ffffff;
            --shadow-deep: 0 10px 30px rgba(0, 0, 0, 0.5);
            --shadow-orange: 0 0 20px rgba(255, 140, 0, 0.2);
            --placeholder-bg: rgba(255, 140, 0, 0.1);
            --placeholder-text: rgba(255, 140, 0, 0.7);
        }

        body {
            min-height: 100vh;
            width: 100%;
            margin: 0;
            padding: 0;
            background: #0a0a0a;
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            position: relative;
            overflow-x: hidden;
            display: block;
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
            z-index: -1;
            animation: noise 0.2s infinite;
            opacity: 0.4;
        }

        @keyframes noise {

            0%,
            100% {
                transform: translate(0, 0);
            }

            10% {
                transform: translate(-5%, -5%);
            }

            20% {
                transform: translate(-10%, 5%);
            }

            30% {
                transform: translate(5%, -10%);
            }

            40% {
                transform: translate(-5%, 15%);
            }

            50% {
                transform: translate(-10%, 5%);
            }

            60% {
                transform: translate(15%, 0);
            }

            70% {
                transform: translate(0, 10%);
            }

            80% {
                transform: translate(-15%, 0);
            }

            90% {
                transform: translate(10%, 5%);
            }
        }

        .orb-1,
        .orb-2 {
            position: fixed;
            width: min(60vw, 600px);
            height: min(60vw, 600px);
            border-radius: 50%;
            filter: blur(min(8vw, 80px));
            pointer-events: none;
            z-index: -1;
            opacity: 0.3;
        }

        .orb-1 {
            top: -20vh;
            left: -20vw;
            background: radial-gradient(circle at 30% 30%, var(--orange-bright), transparent 70%);
            animation: float1 20s infinite ease-in-out;
        }

        .orb-2 {
            bottom: -20vh;
            right: -20vw;
            background: radial-gradient(circle at 70% 70%, #ff5500, transparent 70%);
            animation: float2 25s infinite ease-in-out;
        }

        @keyframes float1 {

            0%,
            100% {
                transform: translate(0, 0) scale(1);
            }

            33% {
                transform: translate(10vw, 10vh) scale(1.1);
            }

            66% {
                transform: translate(-5vw, 15vh) scale(0.9);
            }
        }

        @keyframes float2 {

            0%,
            100% {
                transform: translate(0, 0) scale(1);
            }

            33% {
                transform: translate(-10vw, -10vh) scale(1.2);
            }

            66% {
                transform: translate(5vw, -15vh) scale(0.8);
            }
        }

        /* Top bar - KÖZÉPRE IGAZÍTOTT VERZIÓ */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            pointer-events: none;
        }

        .top-bar-left {
            display: flex;
            gap: 0.5rem;
            position: absolute;
            left: 1rem;
            pointer-events: auto;
        }

        .top-bar-right {
            display: flex;
            gap: 0.5rem;
            position: absolute;
            right: 1rem;
            pointer-events: auto;
        }

        .admin-btn {
            pointer-events: auto;
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
            user-select: none;
            box-shadow: var(--shadow-deep);
            white-space: nowrap;
            text-decoration: none;
        }

        .admin-btn:hover {
            background: rgba(255, 215, 0, 0.25);
            border-color: #ffd700;
            box-shadow: var(--shadow-deep), 0 0 16px rgba(255, 215, 0, 0.35);
            transform: translateY(-1px);
            color: #ffd700;
        }

        .admin-btn .shield-icon {
            font-size: 1.1rem;
            line-height: 1;
        }

        .upload-btn {
            pointer-events: auto;
            padding: 0.5rem 1.1rem;
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            background: rgba(255, 140, 0, 0.12);
            backdrop-filter: blur(10px);
            color: var(--orange-bright);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            user-select: none;
            box-shadow: var(--shadow-deep);
            white-space: nowrap;
        }

        .upload-btn:hover {
            background: rgba(255, 140, 0, 0.25);
            border-color: var(--orange-bright);
            box-shadow: var(--shadow-deep), 0 0 16px rgba(255, 140, 0, 0.35);
            transform: translateY(-1px);
        }

        .upload-btn .plus-icon {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1;
        }

        .search-container {
            position: relative;
            flex: 0 1 400px;
            max-width: 400px;
            margin: 0 auto;
            pointer-events: auto;
        }

        .search-input {
            width: 100%;
            padding: 0.5rem 1rem;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            color: var(--text-primary);
            font-size: 0.9rem;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-deep);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--orange-bright);
            background: rgba(0, 0, 0, 0.8);
            box-shadow: 0 0 0 2px rgba(255, 140, 0, 0.3);
        }

        .search-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            max-height: 400px;
            overflow-y: auto;
            display: none;
            z-index: 2000;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
        }

        .search-dropdown.show {
            display: block;
        }

        .search-result-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background 0.2s ease;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            user-select: none;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background: rgba(255, 140, 0, 0.15);
        }

        .search-result-image {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--glass-border);
            user-select: none;
            pointer-events: none;
        }

        .search-result-info {
            flex: 1;
            user-select: none;
        }

        .search-result-title {
            font-weight: bold;
            color: var(--orange-bright);
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .search-result-price {
            font-size: 0.8rem;
            color: var(--text-primary);
            opacity: 0.8;
        }

        .search-result-seller {
            font-size: 0.7rem;
            color: var(--text-primary);
            opacity: 0.6;
        }

        /* LIGHT MODE OVERRIDES FOR SEARCH */
        body[data-theme="light"] .search-input {
            background: rgba(245, 252, 215, 0.9);
            border-color: rgba(140, 170, 10, 0.4);
            color: #1a1f00;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        body[data-theme="light"] .search-input:focus {
            background: #ffffff;
            border-color: #B0CB1F;
            box-shadow: 0 0 0 3px rgba(176, 203, 31, 0.3);
        }

        body[data-theme="light"] .search-dropdown {
            background: rgba(248, 252, 230, 0.98);
            border-color: rgba(140, 170, 10, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1), 0 0 20px rgba(176, 203, 31, 0.1);
        }

        body[data-theme="light"] .search-result-item {
            border-bottom-color: rgba(140, 170, 10, 0.15);
        }

        body[data-theme="light"] .search-result-item:hover {
            background: rgba(176, 203, 31, 0.15);
        }

        body[data-theme="light"] .search-result-title {
            color: #7a9200;
        }

        body[data-theme="light"] .search-result-price,
        body[data-theme="light"] .search-result-seller {
            color: #1a1f00;
            opacity: 0.8;
        }

        body[data-theme="light"] .search-result-image {
            background: rgba(176, 203, 31, 0.1);
            border-color: rgba(140, 170, 10, 0.3);
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
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            color: var(--orange-bright);
            font-size: 0.9rem;
            white-space: nowrap;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            user-select: none;
            box-shadow: var(--shadow-deep);
        }

        .account-summary:hover {
            background: rgba(255, 140, 0, 0.1);
            border-color: var(--orange-bright);
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
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 0.75rem;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
            z-index: 1001;
            animation: dropdownFade 0.2s ease;
        }

        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* =====================
        MAIN CONTENT & GRID
        ===================== */
        .main-content {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 3rem 0 4rem 0;
            position: relative;
            z-index: 1;
        }

        .items-grid {
            display: grid;
            gap: 1.2rem;
            width: 100%;
            padding: 1rem;
        }

        @media (orientation: landscape) {
            .items-grid {
                grid-template-columns: repeat(6, 1fr);
                grid-auto-rows: auto;
            }
        }

        @media (orientation: portrait) {
            .items-grid {
                grid-template-columns: repeat(3, 1fr);
                grid-auto-rows: auto;
            }
        }

        @media (min-width: 1600px) and (orientation: landscape) {
            .items-grid {
                grid-template-columns: repeat(8, 1fr);
                gap: 1.3rem;
            }
        }

        @media (max-width: 480px) and (orientation: portrait) {
            .items-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.8rem;
                padding: 0.8rem;
            }
        }

        @media (max-width: 360px) and (orientation: portrait) {
            .items-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.7rem;
                padding: 0.7rem;
            }
        }

        @media (min-width: 768px) and (max-width: 1024px) and (orientation: portrait) {
            .items-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }
        }

        @media (min-width: 768px) and (max-width: 1280px) and (orientation: landscape) {
            .items-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 1rem;
            }
        }

        .item-card {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: clamp(0.8rem, 1.5vw, 1.2rem);
            transition: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
            user-select: none;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .item-card:hover {
            border-color: var(--orange-bright);
            box-shadow: 0 8px 25px rgba(255, 140, 0, 0.25);
            transform: translateY(-4px);
            background: rgba(0, 0, 0, 0.75);
        }

        .item-card * {
            user-select: none;
        }

        .item-image {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 0.8rem;
            border: 1px solid var(--glass-border);
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .item-card:hover .item-image {
            transform: scale(1.02);
        }

        .item-image-placeholder {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 12px;
            margin-bottom: 0.8rem;
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--placeholder-bg);
            flex-shrink: 0;
        }

        .item-image-placeholder .placeholder-text {
            color: var(--placeholder-text);
            font-size: clamp(0.8rem, 1.5vw, 1.2rem);
        }

        .image-count-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(5px);
            padding: 0.3rem 0.7rem;
            border-radius: 20px;
            font-size: clamp(0.6rem, 0.9vw, 0.75rem);
            border: 1px solid var(--orange-glow);
            color: var(--orange-bright);
            font-weight: bold;
            z-index: 2;
        }

        .item-title {
            font-size: clamp(0.75rem, 1.1vw, 1.1rem);
            font-weight: bold;
            color: var(--orange-bright);
            margin-bottom: 0.4rem;
            word-wrap: break-word;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.3;
        }

        .item-price {
            font-size: clamp(0.9rem, 1.3vw, 1.4rem);
            font-weight: bold;
            color: var(--orange-bright);
            margin-bottom: 0.35rem;
            text-shadow: 0 0 10px var(--orange-glow);
        }

        .item-seller {
            font-size: clamp(0.65rem, 0.85vw, 0.85rem);
            color: var(--text-primary);
            opacity: 0.7;
            margin-bottom: 0.3rem;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .item-date {
            font-size: clamp(0.55rem, 0.7vw, 0.7rem);
            color: var(--text-primary);
            opacity: 0.5;
        }

        /* Card Menu Styles */
        .card-menu {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }

        .card-menu-button {
            color: var(--orange-bright);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.5rem;
            transition: all 0.3s ease;
            user-select: none;
            width: auto;
            height: auto;
            background: transparent;
            border: none;
            backdrop-filter: none;
            padding: 0;
            line-height: 1;
        }

        .card-menu-button:hover {
            color: #ffaa33;
            transform: scale(1.1);
        }

        .card-menu-content {
            position: absolute;
            top: 40px;
            right: 0;
            min-width: 150px;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 0.5rem;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
            display: none;
            z-index: 20;
        }

        .card-menu-content.show {
            display: block;
        }

        .card-menu-item {
            width: 100%;
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: var(--text-primary);
            text-align: left;
            font-size: 0.9rem;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .card-menu-item:hover {
            background: rgba(255, 140, 0, 0.2);
            color: var(--orange-bright);
        }

        .card-menu-item.delete {
            color: #ff6b6b;
        }

        .card-menu-item.delete:hover {
            background: rgba(255, 0, 0, 0.2);
            color: #ff0000;
        }

        /* =====================
        EDIT MODAL - TRANSPARENT BACKGROUND, GREEN BUTTON
        ===================== */
        .edit-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.82);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 5500;
        }

        .edit-modal.show {
            display: flex;
        }

        .edit-modal-content {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 20px;
            padding: 2rem 2.2rem 2rem 2.2rem;
            max-width: 520px;
            width: 93%;
            max-height: 92vh;
            overflow-y: auto;
        }

        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .edit-modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #39ff6e;
            letter-spacing: -0.01em;
            text-shadow: 0 0 10px rgba(57, 255, 110, 0.3);
        }

        .edit-modal-close {
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(57, 255, 110, 0.5);
            border-radius: 50%;
            color: rgba(57, 255, 110, 0.8);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.2s;
            line-height: 1;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .edit-modal-close:hover {
            color: #39ff6e;
            background: rgba(57, 255, 110, 0.2);
            border-color: #39ff6e;
            transform: scale(1.05);
        }

        .edit-form-group {
            margin-bottom: 1.2rem;
        }

        .edit-form-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: rgba(57, 255, 110, 0.75);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.4rem;
        }

        .edit-form-input,
        .edit-form-textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid rgba(57, 255, 110, 0.3);
            border-radius: 10px;
            padding: 0.7rem 1rem;
            color: #e8ffe8;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.25s, box-shadow 0.25s;
            outline: none;
            resize: vertical;
        }

        .edit-form-input:focus,
        .edit-form-textarea:focus {
            border-color: #39ff6e;
            box-shadow: 0 0 0 3px rgba(57, 255, 110, 0.15);
            background: rgba(0, 0, 0, 0.85);
        }

        .edit-price-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .edit-price-wrapper .edit-form-input {
            padding-right: 3rem;
        }

        .edit-price-suffix {
            position: absolute;
            right: 1rem;
            color: rgba(57, 255, 110, 0.6);
            font-weight: 600;
            font-size: 0.95rem;
            pointer-events: none;
            user-select: none;
        }

        .edit-submit-btn {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, #1aff6e, #00c851) !important;
            border: none !important;
            border-radius: 12px !important;
            color: #001a08 !important;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            letter-spacing: 0.03em;
            transition: all 0.25s ease;
            margin-top: 0.5rem;
            box-shadow: 0 4px 22px rgba(57, 255, 110, 0.35) !important;
        }

        .edit-submit-btn:hover {
            background: linear-gradient(135deg, #39ff6e, #00e85c) !important;
            box-shadow: 0 6px 30px rgba(57, 255, 110, 0.55) !important;
            transform: translateY(-2px);
        }

        .edit-success-banner {
            background: rgba(57, 255, 110, 0.1);
            border: 1px solid rgba(57, 255, 110, 0.35);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #39ff6e;
            font-size: 0.87rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Report Modal Styles */
        .report-modal {
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
            z-index: 3000;
        }

        .report-modal.show {
            display: flex;
        }

        .report-modal-content {
            background: rgba(10, 10, 10, 0.95);
            border: 1px solid var(--orange-bright);
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .report-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .report-modal-title {
            font-size: 1.3rem;
            color: var(--orange-bright);
        }

        .report-modal-close {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.5rem;
            cursor: pointer;
        }

        .report-modal-close:hover {
            color: var(--orange-bright);
        }

        .report-form-group {
            margin-bottom: 1.5rem;
        }

        .report-form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .report-form-textarea {
            width: 100%;
            background: transparent !important;
            border: none !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 0 !important;
            padding: 0.5rem 0 !important;
            color: var(--text-primary);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 100px;
            outline: none;
        }

        .report-form-textarea:focus {
            border-bottom-color: var(--orange-bright) !important;
            box-shadow: none !important;
        }

        .report-submit-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #1aff6e, #00c851) !important;
            border: none !important;
            border-radius: 12px !important;
            color: #001a08 !important;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 22px rgba(57, 255, 110, 0.35) !important;
        }

        .report-submit-btn:hover {
            background: linear-gradient(135deg, #39ff6e, #00e85c) !important;
            box-shadow: 0 6px 30px rgba(57, 255, 110, 0.55) !important;
            transform: translateY(-2px);
        }

        /* Floating Pagination */
        .floating-pagination {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            z-index: 1000;
            pointer-events: none;
        }

        .pagination-container {
            display: flex;
            gap: 1rem;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow-deep), var(--shadow-orange);
            pointer-events: auto;
        }

        .pagination-btn {
            padding: 0.5rem 1.5rem;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .pagination-btn:hover {
            background: rgba(255, 140, 0, 0.2);
            color: var(--orange-bright);
            transform: translateY(-2px);
        }

        .pagination-btn.disabled {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--glass-border);
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
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
            user-select: none;
        }

        .logout-button span:hover {
            background: rgba(255, 140, 0, 0.15);
            color: var(--orange-bright);
            transform: translateX(5px);
        }

        .user-info {
            color: var(--text-primary);
            font-size: 0.9rem;
            padding: 0.75rem 1rem;
            user-select: none;
        }

        .user-info strong {
            display: block;
            word-wrap: break-word;
            color: var(--orange-bright);
        }

        .dropdown-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--orange-bright), transparent);
            margin: 0.5rem 0;
        }

        .unselectable {
            user-select: none;
            -webkit-user-select: none;
        }

        input,
        textarea {
            user-select: text;
            -webkit-user-select: text;
        }

        .theme-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
            color: var(--text-primary);
            user-select: none;
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

        /* =====================
        UPLOAD MODAL
        ===================== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-card {
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow-y: auto;
            background: rgba(10, 10, 10, 0.92);
            border: 1px solid rgba(255, 140, 0, 0.35);
            border-radius: 24px;
            padding: 2.5rem 2rem 2rem 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.7), 0 0 40px rgba(255, 140, 0, 0.15);
            position: relative;
            transform: translateY(30px) scale(0.97);
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
            opacity: 0;
        }

        .modal-overlay.active .modal-card {
            transform: translateY(0) scale(1);
            opacity: 1;
        }

        .modal-close {
            position: absolute;
            top: 1.1rem;
            right: 1.2rem;
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.4);
            font-size: 1.4rem;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
            user-select: none;
            padding: 0.2rem 0.4rem;
            border-radius: 6px;
        }

        .modal-close:hover {
            color: var(--orange-bright);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--orange-bright);
            margin-bottom: 0.3rem;
            letter-spacing: -0.02em;
        }

        .modal-subtitle {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.4);
            margin-bottom: 1.8rem;
        }

        .form-group {
            margin-bottom: 1.3rem;
        }

        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.45rem;
        }

        .form-label .required-star {
            color: var(--orange-bright);
            margin-left: 0.2rem;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 140, 0, 0.2);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
            outline: none;
            -webkit-appearance: none;
        }

        .form-input:focus,
        .form-textarea:focus {
            border-color: var(--orange-bright);
            background: rgba(255, 140, 0, 0.06);
            box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.12);
        }

        .form-input::placeholder,
        .form-textarea::placeholder {
            color: rgba(255, 255, 255, 0.2);
        }

        .form-textarea {
            resize: vertical;
            min-height: 110px;
        }

        .image-upload-container {
            background: rgba(0, 0, 0, 0.3);
            border: 2px dashed rgba(255, 140, 0, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .image-upload-container:hover {
            border-color: var(--orange-bright);
            background: rgba(255, 140, 0, 0.05);
        }

        .image-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.6);
        }

        .image-upload-icon {
            font-size: 2rem;
            color: var(--orange-bright);
        }

        .image-upload-hint {
            font-size: 0.8rem;
            text-align: center;
        }

        .image-upload-hint small {
            display: block;
            margin-top: 0.3rem;
            opacity: 0.5;
        }

        #item_images {
            display: none;
        }

        .image-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .image-preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid rgba(255, 140, 0, 0.3);
        }

        .image-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-preview-remove {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            background: rgba(0, 0, 0, 0.7);
            border: 1px solid var(--orange-bright);
            border-radius: 50%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s ease;
        }

        .image-preview-remove:hover {
            background: rgba(255, 0, 0, 0.7);
            border-color: red;
        }

        .primary-badge {
            position: absolute;
            bottom: 5px;
            left: 5px;
            background: var(--orange-bright);
            color: black;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: bold;
        }

        .price-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .price-wrapper .form-input {
            padding-right: 3rem;
        }

        .price-suffix {
            position: absolute;
            right: 1rem;
            color: rgba(255, 140, 0, 0.6);
            font-weight: 600;
            font-size: 0.95rem;
            pointer-events: none;
            user-select: none;
        }

        .field-error {
            display: none;
            font-size: 0.76rem;
            color: #ff4d4d;
            margin-top: 0.35rem;
            padding-left: 0.2rem;
        }

        .form-input.invalid,
        .form-textarea.invalid {
            border-color: #ff4d4d;
            box-shadow: 0 0 0 3px rgba(255, 77, 77, 0.12);
        }

        .error-banner {
            background: rgba(255, 60, 60, 0.1);
            border: 1px solid rgba(255, 60, 60, 0.3);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #ff8080;
            font-size: 0.87rem;
            margin-bottom: 1.3rem;
        }

        .success-banner {
            background: rgba(0, 200, 100, 0.1);
            border: 1px solid rgba(0, 200, 100, 0.3);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #5dffa0;
            font-size: 0.87rem;
            margin-bottom: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .submit-btn {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, rgba(255, 140, 0, 0.9), rgba(255, 85, 0, 0.9));
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.03em;
            transition: all 0.25s ease;
            margin-top: 0.5rem;
            box-shadow: 0 4px 20px rgba(255, 140, 0, 0.3);
        }

        .submit-btn:hover {
            background: linear-gradient(135deg, #ff8c00, #ff4400);
            box-shadow: 0 6px 28px rgba(255, 140, 0, 0.5);
            transform: translateY(-2px);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* =====================
        PRODUCT MODAL
        ===================== */
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

        /* =====================
        LIGHTBOX
        ===================== */
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

        /* =====================
        RESPONSIVE
        ===================== */
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

        @media (prefers-reduced-motion: reduce) {

            .noise,
            .orb-1,
            .orb-2,
            .item-card,
            .account-dropdown,
            .pagination-btn,
            .modal-card,
            .modal-overlay,
            .product-modal-card,
            .product-modal-overlay {
                animation: none;
                transition: none;
            }
        }

        /* =====================
        MOBIL TOP BAR RENDEZÉS
        ===================== */
        @media (max-width: 600px) {
            .top-bar {
                flex-wrap: wrap;
                justify-content: space-between;
                padding: 0.5rem;
                gap: 0.5rem;
                position: relative;
            }

            /* Bal és jobb oldali elemek egymás mellett, statikus pozícióban */
            .top-bar-left,
            .top-bar-right {
                position: static;
                width: auto;
            }

            /* Keresőmező az egész sor alatt */
            .search-container {
                order: 3;
                width: 100%;
                max-width: none;
                margin: 0.5rem 0 0;
                flex: none;
            }

            /* Csak az ikonok maradnak a gombokon */
            .upload-btn .button-text,
            .admin-btn .button-text,
            .account-summary .button-text {
                display: none;
            }

            /* Gombok méretének csökkentése, hogy jobban elférjenek */
            .upload-btn,
            .admin-btn,
            .account-summary {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            /* A fiók legördülő menüje ne lógjon ki a képernyőn */
            .account-dropdown {
                right: 0;
                left: auto;
                width: 240px;
            }
        }
    </style>
</head>

<body>
    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

    <!-- Top bar -->
    <div class="top-bar">
        <div class="top-bar-left">
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="admin-btn unselectable" id="adminBtn">
                    <span class="shield-icon">🛡️</span>
                    <span class="button-text">Admin</span>
                </a>
            <?php endif; ?>
        </div>

        <div class="search-container">
            <input type="text" id="searchInput" class="search-input" placeholder="Keresés termékek között..." autocomplete="off">
            <div id="searchResults" class="search-dropdown"></div>
        </div>

        <div class="top-bar-right">
            <button class="upload-btn unselectable" id="openModalBtn" type="button">
                <span class="plus-icon">＋</span>
                <span class="button-text">Hirdetés feladása</span>
            </button>
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
                            <input type="checkbox" id="themeSwitchMain">
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
    </div>

    <!-- Upload Modal -->
    <div class="modal-overlay" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-card">
            <button class="modal-close unselectable" id="closeModalBtn" type="button" aria-label="Bezárás">✕</button>
            <div class="modal-title unselectable" id="modalTitle">Új hirdetés</div>
            <div class="modal-subtitle unselectable">Tölts fel legalább 1 képet a termékről</div>

            <?php if (isset($_GET['upload']) && $_GET['upload'] === 'success'): ?>
                <div class="success-banner unselectable">
                    <span>✓</span> A hirdetés sikeresen fel lett adva!
                </div>
            <?php endif; ?>

            <?php if ($uploadError): ?>
                <div class="error-banner unselectable"><?php echo htmlspecialchars($uploadError); ?></div>
            <?php endif; ?>

            <form method="post" id="uploadForm" enctype="multipart/form-data" novalidate>
                <div class="image-upload-container">
                    <label for="item_images" class="image-upload-label unselectable">
                        <span class="image-upload-icon unselectable">📸</span>
                        <span class="image-upload-hint unselectable">
                            Kattints ide a képek kiválasztásához<br>
                            <small>Támogatott formátumok: JPEG, PNG, GIF, WebP (max. 5MB/kép)</small>
                        </span>
                    </label>
                    <input type="file" id="item_images" name="item_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <div class="image-preview-container" id="imagePreview"></div>
                    <div class="field-error unselectable" id="images-error" style="margin-top: 0.5rem;">Legalább egy képet fel kell tölteni!</div>
                </div>

                <div class="form-group">
                    <label class="form-label unselectable" for="item_title">
                        Cím <span class="required-star unselectable">*</span>
                    </label>
                    <input class="form-input" type="text" id="item_title" name="item_title" placeholder="pl. iPhone 14 Pro 256GB" maxlength="255"
                        value="<?php echo htmlspecialchars($formData['item_title'] ?? ''); ?>" autocomplete="off">
                    <div class="field-error unselectable" id="title-error">Kérjük, add meg a hirdetés címét!</div>
                </div>

                <div class="form-group">
                    <label class="form-label unselectable" for="item_description">
                        Leírás <span class="required-star unselectable">*</span>
                    </label>
                    <textarea class="form-textarea" id="item_description" name="item_description" placeholder="Írd le a termék állapotát, jellemzőit..."><?php echo htmlspecialchars($formData['item_description'] ?? ''); ?></textarea>
                    <div class="field-error unselectable" id="desc-error">Kérjük, adj meg egy leírást!</div>
                </div>

                <div class="form-group">
                    <label class="form-label unselectable" for="item_price">
                        Ár <span class="required-star unselectable">*</span>
                    </label>
                    <div class="price-wrapper">
                        <input class="form-input" type="number" id="item_price" name="item_price" placeholder="0" min="0" step="1"
                            value="<?php echo htmlspecialchars($formData['item_price'] ?? ''); ?>">
                        <span class="price-suffix unselectable">Ft</span>
                    </div>
                    <div class="field-error unselectable" id="price-error">Kérjük, adj meg egy érvényes árat!</div>
                </div>

                <button type="submit" name="upload_item" class="submit-btn unselectable">
                    Hirdetés feladása
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="edit-modal" id="editModal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <h3 class="edit-modal-title unselectable">✏️ Hirdetés módosítása</h3>
                <button class="edit-modal-close unselectable" onclick="closeEditModal()">✕</button>
            </div>
            <?php if (isset($_GET['edit']) && $_GET['edit'] === 'success'): ?>
                <div class="edit-success-banner unselectable"><span>✓</span> Módosítás sikeresen mentve!</div>
            <?php endif; ?>
            <form method="post" id="editForm">
                <input type="hidden" name="item_id" id="editItemId">
                <input type="hidden" name="edit_item" value="1">
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable" for="edit_title">Cím</label>
                    <input class="edit-form-input" type="text" id="edit_title" name="edit_title" maxlength="255" autocomplete="off" required>
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable" for="edit_description">Leírás</label>
                    <textarea class="edit-form-textarea" id="edit_description" name="edit_description" rows="5" required></textarea>
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable" for="edit_price">Ár</label>
                    <div class="edit-price-wrapper">
                        <input class="edit-form-input" type="number" id="edit_price" name="edit_price" min="0" step="1" required>
                        <span class="edit-price-suffix unselectable">Ft</span>
                    </div>
                </div>
                <button type="submit" class="edit-submit-btn unselectable">💾 Módosítások mentése</button>
            </form>
        </div>
    </div>

    <!-- Report Modal -->
    <div class="report-modal" id="reportModal">
        <div class="report-modal-content">
            <div class="report-modal-header">
                <div class="report-modal-title-wrapper">
                    <span class="report-modal-icon">⚠️</span>
                    <h3 class="report-modal-title unselectable">Hirdetés bejelentése</h3>
                </div>
                <button class="report-modal-close unselectable" onclick="closeReportModal()" aria-label="Bezárás">✕</button>
            </div>
            <form method="post" id="reportForm">
                <input type="hidden" name="item_id" id="reportItemId">
                <input type="hidden" name="report_item" value="1">
                <div class="report-form-group">
                    <textarea name="report_reason" class="report-form-textarea" required placeholder="Kérjük, részletezd a problémát..."></textarea>
                </div>
                <button type="submit" class="report-submit-btn unselectable">
                    <span class="btn-icon">📢</span> Bejelentés küldése
                </button>
            </form>
        </div>
    </div>

    <!-- Product Detail Modal -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <div class="product-modal-header">
                <div class="product-menu" id="productMenuContainer" style="display: none;">
                    <div class="product-menu-button unselectable" onclick="toggleProductMenu(this)">⋮</div>
                    <div class="product-menu-content" id="productMenuContent">
                        <button class="product-menu-item unselectable" id="productEditBtn" style="display:none;">✏️ Módosítás</button>
                        <button class="product-menu-item unselectable" id="productReportBtn">⚠️ Bejelentés</button>
                        <button class="product-menu-item delete unselectable" id="productDeleteBtn" style="display: none;">🗑️ Törlés</button>
                    </div>
                </div>
                <button class="product-modal-close unselectable" id="closeProductModalBtn">✕</button>
            </div>

            <div class="product-gallery">
                <div class="product-main-image-container">
                    <img src="" alt="Termék képe" class="product-main-image" id="productMainImage" style="display: none;">
                    <div class="product-no-image-placeholder unselectable" id="productNoImagePlaceholder" style="display: none;">
                        📷 Nincs kép
                    </div>
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
                <button class="product-buy-btn unselectable" id="productBuyBtn">
                    🛒 Vásárlás
                </button>
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

    <div class="main-content">
        <?php if (!empty($items)): ?>
            <div class="items-grid">
                <?php foreach ($items as $item):
                    $imageStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? AND is_primary = 1 LIMIT 1");
                    $imageStmt->execute([$item['id']]);
                    $primaryImage = $imageStmt->fetch(PDO::FETCH_ASSOC);

                    $countStmt = $conn->prepare("SELECT COUNT(*) as image_count FROM item_images WHERE item_id = ?");
                    $countStmt->execute([$item['id']]);
                    $imageCount = $countStmt->fetch(PDO::FETCH_ASSOC)['image_count'];

                    $allImagesStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY sort_order");
                    $allImagesStmt->execute([$item['id']]);
                    $allImages = $allImagesStmt->fetchAll(PDO::FETCH_COLUMN);
                ?>
                    <div class="item-card"
                        data-item-id="<?php echo $item['id']; ?>"
                        data-item-title="<?php echo htmlspecialchars($item['title']); ?>"
                        data-item-price="<?php echo number_format($item['price'], 0, ',', ' '); ?> Ft"
                        data-item-seller="<?php echo htmlspecialchars($item['seller_name']); ?>"
                        data-item-date="<?php echo date('Y-m-d', strtotime($item['created_at'])); ?>"
                        data-item-description="<?php echo htmlspecialchars($item['description']); ?>"
                        data-item-images='<?php echo json_encode($allImages); ?>'
                        data-item-user-id="<?php echo $item['user_id']; ?>">

                        <?php
                        $isOwnerCard = ($item['user_id'] == $_SESSION['user_id']);
                        $showCardMenu = true; // always show menu (report for others, edit/delete for owner/admin)
                        ?>
                        <div class="card-menu">
                            <div class="card-menu-button unselectable" onclick="toggleMenu(this); event.stopPropagation();">⋮</div>
                            <div class="card-menu-content">
                                <?php if (!$isOwnerCard || $isAdmin): ?>
                                    <button class="card-menu-item unselectable" onclick="openReportModal('<?php echo $item['id']; ?>'); event.stopPropagation();">
                                        ⚠️ Bejelentés
                                    </button>
                                <?php endif; ?>
                                <?php if ($isOwnerCard || $isAdmin): ?>
                                    <button class="card-menu-item unselectable"
                                        onclick="openEditModal('<?php echo $item['id']; ?>', <?php echo htmlspecialchars(json_encode($item['title'])); ?>, <?php echo htmlspecialchars(json_encode($item['description'])); ?>, '<?php echo $item['price']; ?>'); event.stopPropagation();">
                                        ✏️ Módosítás
                                    </button>
                                    <form method="post" style="margin:0; padding:0;" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a hirdetést?');" onclick="event.stopPropagation();">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="delete_item" value="1">
                                        <button type="submit" class="card-menu-item delete unselectable">🗑️ Törlés</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($primaryImage): ?>
                            <img src="<?php echo htmlspecialchars($primaryImage['image_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="item-image">
                        <?php else: ?>
                            <div class="item-image-placeholder">
                                <span class="placeholder-text unselectable">📷 Nincs kép</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($imageCount > 1): ?>
                            <div class="image-count-badge unselectable">+<?php echo $imageCount - 1; ?> kép</div>
                        <?php endif; ?>

                        <div class="item-title unselectable"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="item-price unselectable"><?php echo number_format($item['price'], 0, ',', ' '); ?> Ft</div>
                        <div class="item-seller unselectable">Eladó: <?php echo htmlspecialchars($item['seller_name']); ?></div>
                        <div class="item-date unselectable"><?php echo date('Y-m-d', strtotime($item['created_at'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="floating-pagination">
                    <div class="pagination-container">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn unselectable">Előző</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled unselectable">Előző</span>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn unselectable">Következő</a>
                        <?php else: ?>
                            <span class="pagination-btn disabled unselectable">Következő</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Current product data
        let currentProductImages = [];
        let currentImageIndex = 0;
        let currentProductId = null;
        let currentProductUserId = null;

        // Upload modal functionality
        const modal = document.getElementById('uploadModal');
        const openBtn = document.getElementById('openModalBtn');
        const closeBtn = document.getElementById('closeModalBtn');

        function openModal() {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
        });

        // Image preview functionality
        const imageInput = document.getElementById('item_images');
        const previewContainer = document.getElementById('imagePreview');
        const imagesError = document.getElementById('images-error');
        let selectedFiles = [];

        imageInput.addEventListener('change', function(e) {
            const files = Array.from(e.target.files);
            const validFiles = files.filter(file => {
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                const maxSize = 5 * 1024 * 1024;
                if (!validTypes.includes(file.type)) {
                    alert(`A ${file.name} fájl formátuma nem támogatott!`);
                    return false;
                }
                if (file.size > maxSize) {
                    alert(`A ${file.name} fájl mérete nagyobb, mint 5MB!`);
                    return false;
                }
                return true;
            });
            selectedFiles = validFiles;
            updatePreview();
        });

        function updatePreview() {
            previewContainer.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                const previewItem = document.createElement('div');
                previewItem.className = 'image-preview-item';
                previewItem.setAttribute('data-index', index);
                reader.onload = function(e) {
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview">
                        <div class="image-preview-remove" data-index="${index}">×</div>
                        ${index === 0 ? '<div class="primary-badge unselectable">Főkép</div>' : ''}
                    `;
                    const removeBtn = previewItem.querySelector('.image-preview-remove');
                    if (removeBtn) {
                        removeBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const idx = parseInt(this.dataset.index);
                            removeImageAtIndex(idx);
                        });
                    }
                };
                reader.readAsDataURL(file);
                previewContainer.appendChild(previewItem);
            });
            validateImages();
        }

        function removeImageAtIndex(indexToRemove) {
            selectedFiles.splice(indexToRemove, 1);
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            imageInput.files = dt.files;
            updatePreview();
        }

        function validateImages() {
            const isValid = selectedFiles.length > 0;
            const uploadContainer = document.querySelector('.image-upload-container');
            if (!isValid) {
                imagesError.style.display = 'block';
                if (uploadContainer) uploadContainer.style.borderColor = '#ff4d4d';
            } else {
                imagesError.style.display = 'none';
                if (uploadContainer) uploadContainer.style.borderColor = 'rgba(255, 140, 0, 0.3)';
            }
            return isValid;
        }

        const form = document.getElementById('uploadForm');
        const titleInput = document.getElementById('item_title');
        const descInput = document.getElementById('item_description');
        const priceInput = document.getElementById('item_price');

        function validateField(input, errorId, condition) {
            const errEl = document.getElementById(errorId);
            if (!condition) {
                input.classList.add('invalid');
                errEl.style.display = 'block';
                return false;
            }
            input.classList.remove('invalid');
            errEl.style.display = 'none';
            return true;
        }

        form.addEventListener('submit', (e) => {
            let valid = true;
            valid = validateImages() && valid;
            valid = validateField(titleInput, 'title-error', titleInput.value.trim() !== '') && valid;
            valid = validateField(descInput, 'desc-error', descInput.value.trim() !== '') && valid;
            valid = validateField(priceInput, 'price-error', priceInput.value !== '' && parseFloat(priceInput.value) >= 0) && valid;
            if (!valid) e.preventDefault();
        });

        [titleInput, descInput, priceInput].forEach(el => {
            el.addEventListener('input', () => el.classList.remove('invalid'));
        });

        <?php if (isset($_GET['upload']) && $_GET['upload'] === 'success' || $uploadError): ?>
            openModal();
        <?php endif; ?>

        // Card menu functionality
        function toggleMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');
            document.querySelectorAll('.card-menu-content').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.card-menu')) {
                document.querySelectorAll('.card-menu-content').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });

        // Report modal functionality
        const reportModal = document.getElementById('reportModal');
        const reportItemId = document.getElementById('reportItemId');

        function openReportModal(itemId) {
            reportItemId.value = itemId;
            reportModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeReportModal() {
            reportModal.classList.remove('show');
            document.body.style.overflow = '';
        }

        reportModal.addEventListener('click', function(e) {
            if (e.target === reportModal) closeReportModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && reportModal.classList.contains('show')) closeReportModal();
        });

        // Product modal functionality
        const productModal = document.getElementById('productModal');
        const closeProductModalBtn = document.getElementById('closeProductModalBtn');
        const productMainImage = document.getElementById('productMainImage');
        const productNoImagePlaceholder = document.getElementById('productNoImagePlaceholder');
        const lightboxOverlay = document.getElementById('lightboxOverlay');
        const lightboxImage = document.getElementById('lightboxImage');
        const lightboxClose = document.getElementById('lightboxClose');

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

        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.card-menu') || e.target.closest('.report-modal')) return;
                const productId = this.dataset.itemId;
                const title = this.dataset.itemTitle;
                const price = this.dataset.itemPrice;
                const seller = this.dataset.itemSeller;
                const date = this.dataset.itemDate;
                const description = this.dataset.itemDescription;
                const images = JSON.parse(this.dataset.itemImages || '[]');
                const userId = this.dataset.itemUserId;

                currentProductId = productId;
                currentProductUserId = userId;
                currentProductImages = images;
                currentImageIndex = 0;

                document.getElementById('productTitle').textContent = title;
                document.getElementById('productPrice').textContent = price;
                document.getElementById('productSeller').innerHTML = `Eladó: <strong>${seller}</strong>`;
                document.getElementById('productDate').textContent = date;
                document.getElementById('productDescription').textContent = description;

                const thumbnailsContainer = document.getElementById('productThumbnails');
                thumbnailsContainer.innerHTML = '';

                if (images.length > 0) {
                    images.forEach((img, index) => {
                        const thumbnail = document.createElement('div');
                        thumbnail.className = `product-thumbnail ${index === 0 ? 'active' : ''}`;
                        thumbnail.innerHTML = `<img src="${img}" alt="Thumbnail ${index + 1}">`;
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
                prevBtn.classList.toggle('hidden', images.length <= 1);
                nextBtn.classList.toggle('hidden', images.length <= 1);

                <?php if (isset($_SESSION['user_id'])): ?>
                    const isOwner = (parseInt(userId) === <?php echo $_SESSION['user_id']; ?>);
                    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
                    const menuContainer = document.getElementById('productMenuContainer');
                    const reportBtn = document.getElementById('productReportBtn');
                    const deleteBtn = document.getElementById('productDeleteBtn');
                    const editBtn = document.getElementById('productEditBtn');

                    // Always show menu
                    menuContainer.style.display = 'block';

                    // Report: visible for non-owners (or admins can report too)
                    if (!isOwner || isAdmin) {
                        reportBtn.style.display = 'block';
                        reportBtn.onclick = () => {
                            closeProductModal();
                            openReportModal(productId);
                        };
                    } else {
                        reportBtn.style.display = 'none';
                    }

                    // Edit: visible for owner or admin
                    if (isOwner || isAdmin) {
                        editBtn.style.display = 'block';
                        editBtn.onclick = () => {
                            closeProductModal();
                            openEditModal(
                                productId,
                                document.getElementById('productTitle').textContent,
                                document.getElementById('productDescription').textContent,
                                document.getElementById('productPrice').textContent.replace(/[^0-9]/g, '')
                            );
                        };
                    } else {
                        editBtn.style.display = 'none';
                    }

                    // Delete: visible for owner or admin
                    if (isOwner || isAdmin) {
                        deleteBtn.style.display = 'block';
                        deleteBtn.onclick = () => {
                            if (confirm('Biztosan törölni szeretnéd ezt a hirdetést?')) {
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = `
                                <input type="hidden" name="item_id" value="${productId}">
                                <input type="hidden" name="delete_item" value="1">
                            `;
                                document.body.appendChild(form);
                                form.submit();
                            }
                        };
                    } else {
                        deleteBtn.style.display = 'none';
                    }
                <?php endif; ?>

                openProductModal();
            });
        });

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

        closeProductModalBtn.addEventListener('click', closeProductModal);

        productModal.addEventListener('click', (e) => {
            if (e.target === productModal) closeProductModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && productModal.classList.contains('active')) closeProductModal();
        });

        document.getElementById('productBuyBtn').addEventListener('click', () => {
            alert('Vásárlás funkció még nem elérhető!');
        });

        function toggleProductMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');
            document.querySelectorAll('.product-menu-content').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
        }

        productMainImage.addEventListener('click', (e) => {
            e.stopPropagation();
            if (productMainImage.src && productMainImage.style.display !== 'none' && !productMainImage.src.includes('svg')) {
                lightboxImage.src = productMainImage.src;
                lightboxOverlay.classList.add('active');
            }
        });

        function closeLightbox() {
            lightboxOverlay.classList.remove('active');
        }

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

        // ── TÉMAVÁLTÓ ──
        (function() {
            const checkbox = document.getElementById('themeSwitchMain');
            const themeLink = document.getElementById('themeStylesheet');

            function applyTheme(theme) {
                themeLink.href = theme === 'light' ? 'theme-light.css' : 'theme-dark.css';
                localStorage.setItem('theme', theme);
                checkbox.checked = (theme === 'light');
                document.documentElement.setAttribute('data-theme', theme);
                document.body.setAttribute('data-theme', theme);

                const placeholder = document.getElementById('productNoImagePlaceholder');
                if (placeholder) {
                    placeholder.style.color = theme === 'light' ? '#7a9200' : '#ff8c00';
                }

                if (productModal && productModal.classList.contains('active') && currentProductImages && currentProductImages.length > 0) {
                    if (productMainImage && currentProductImages[currentImageIndex]) {
                        productMainImage.src = currentProductImages[currentImageIndex];
                    }
                    const thumbnailsContainer = document.getElementById('productThumbnails');
                    if (thumbnailsContainer) {
                        const thumbnails = thumbnailsContainer.querySelectorAll('.product-thumbnail');
                        currentProductImages.forEach((img, index) => {
                            if (thumbnails[index]) {
                                const thumbnailImg = thumbnails[index].querySelector('img');
                                if (thumbnailImg) thumbnailImg.src = img;
                            }
                        });
                    }
                }
            }

            const saved = localStorage.getItem('theme') || 'dark';
            applyTheme(saved);

            checkbox.addEventListener('change', function() {
                applyTheme(this.checked ? 'light' : 'dark');
            });
        })();

        // =====================
        // KERESÉS FUNKCIÓ (AJAX)
        // =====================
        const searchInput = document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        let searchTimeout;

        function performSearch() {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                searchResults.classList.remove('show');
                return;
            }

            fetch(`?search_query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        searchResults.classList.remove('show');
                        return;
                    }

                    const isLightMode = document.body.getAttribute('data-theme') === 'light';
                    const placeholderColor = isLightMode ? '#7a9200' : '#ff8c00';
                    const placeholderSvg = `data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22${encodeURIComponent(placeholderColor)}%22%3E%3Cpath%20d%3D%22M4%204h16v2H4V4zm2%204h12v2H6V8zm14-4v16H4V4h16z%22%2F%3E%3C%2Fsvg%3E`;

                    searchResults.innerHTML = data.map(item => `
                        <div class="search-result-item" data-item-id="${item.id}">
                            <img src="${item.primary_image || ''}" class="search-result-image" onerror="this.src='${placeholderSvg}'">
                            <div class="search-result-info">
                                <div class="search-result-title">${escapeHtml(item.title)}</div>
                                <div class="search-result-price">${Number(item.price).toLocaleString('hu-HU')} Ft</div>
                                <div class="search-result-seller">${escapeHtml(item.seller_name)}</div>
                            </div>
                        </div>
                    `).join('');

                    searchResults.classList.add('show');

                    document.querySelectorAll('.search-result-item').forEach(el => {
                        el.addEventListener('click', () => {
                            const itemId = el.dataset.itemId;
                            fetchItemDetails(itemId);
                        });
                    });
                })
                .catch(err => console.error('Search error:', err));
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 300);
        });

        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('show');
            }
        });

        function fetchItemDetails(itemId) {
            fetch(`?get_item=${itemId}`)
                .then(response => response.json())
                .then(item => {
                    if (item.error) {
                        console.error(item.error);
                        return;
                    }

                    currentProductId = item.id;
                    currentProductUserId = item.user_id;
                    currentProductImages = item.images;
                    currentImageIndex = 0;

                    document.getElementById('productTitle').textContent = item.title;
                    document.getElementById('productPrice').textContent = `${Number(item.price).toLocaleString('hu-HU')} Ft`;
                    document.getElementById('productSeller').innerHTML = `Eladó: <strong>${escapeHtml(item.seller_name)}</strong>`;
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

                    const menuContainer = document.getElementById('productMenuContainer');
                    const reportBtn = document.getElementById('productReportBtn');
                    const deleteBtn = document.getElementById('productDeleteBtn');
                    const editBtn = document.getElementById('productEditBtn');
                    const isOwner = (parseInt(item.user_id) === <?php echo $_SESSION['user_id']; ?>);
                    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;

                    menuContainer.style.display = 'block';

                    if (!isOwner || isAdmin) {
                        reportBtn.style.display = 'block';
                        reportBtn.onclick = () => {
                            closeProductModal();
                            openReportModal(item.id);
                        };
                    } else {
                        reportBtn.style.display = 'none';
                    }

                    if (isOwner || isAdmin) {
                        editBtn.style.display = 'block';
                        editBtn.onclick = () => {
                            closeProductModal();
                            openEditModal(item.id, item.title, item.description, item.price);
                        };
                        deleteBtn.style.display = 'block';
                        deleteBtn.onclick = () => {
                            if (confirm('Biztosan törlöd ezt a terméket?')) {
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = `
                                    <input type="hidden" name="item_id" value="${item.id}">
                                    <input type="hidden" name="delete_item" value="1">
                                `;
                                document.body.appendChild(form);
                                form.submit();
                            }
                        };
                    } else {
                        editBtn.style.display = 'none';
                        deleteBtn.style.display = 'none';
                    }

                    openProductModal();
                })
                .catch(err => console.error('Error fetching item details:', err));
        }

        // =====================
        // EDIT MODAL
        // =====================
        const editModal = document.getElementById('editModal');

        function openEditModal(itemId, title, description, price) {
            document.getElementById('editItemId').value = itemId;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_price').value = parseFloat(price) || price;
            editModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            editModal.classList.remove('show');
            document.body.style.overflow = '';
        }

        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) closeEditModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && editModal.classList.contains('show')) closeEditModal();
        });

        <?php if (isset($_GET['edit']) && $_GET['edit'] === 'success'): ?>
            // Show edit modal briefly on success so user sees the banner
            // (optional — remove if not desired)
        <?php endif; ?>

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
    </script>
</body>

</html>