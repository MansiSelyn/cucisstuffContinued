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

$uploadSuccess = false;
$uploadError = '';

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

    // Handle new item upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_item'])) {
        $title       = trim($_POST['item_title'] ?? '');
        $description = trim($_POST['item_description'] ?? '');
        $price       = trim($_POST['item_price'] ?? '');

        // Check for uploaded files
        if (!isset($_FILES['item_images']) || empty($_FILES['item_images']['name'][0])) {
            $uploadError = 'Legalább egy képet fel kell tölteni!';
        } elseif ($title === '' || $description === '' || $price === '') {
            $uploadError = 'Minden mező kitöltése kötelező!';
        } elseif (!is_numeric($price) || floatval($price) < 0) {
            $uploadError = 'Az ár csak pozitív szám lehet!';
        } else {
            // Validate images
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            $files = $_FILES['item_images'];
            $uploadValid = true;

            // Check each file
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $uploadError = 'Hiba történt a képfeltöltés során!';
                    $uploadValid = false;
                    break;
                }

                if (!in_array($files['type'][$i], $allowedTypes)) {
                    $uploadError = 'Csak JPEG, PNG, GIF és WebP formátumú képek tölthetők fel!';
                    $uploadValid = false;
                    break;
                }

                if ($files['size'][$i] > $maxFileSize) {
                    $uploadError = 'Egy kép maximális mérete 5MB lehet!';
                    $uploadValid = false;
                    break;
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
                            // Save to database using the table structure you provided
                            $imageInsert = $conn->prepare("
                                INSERT INTO item_images (item_id, image_path, image_filename, is_primary, sort_order)
                                VALUES (:item_id, :image_path, :image_filename, :is_primary, :sort_order)
                            ");

                            $imageInsert->execute([
                                ':item_id' => $newId,
                                ':image_path' => $filepath,
                                ':image_filename' => $filename,
                                ':is_primary' => ($i === 0) ? 1 : 0, // First image is primary
                                ':sort_order' => $sortOrder
                            ]);

                            $sortOrder++;
                        } else {
                            throw new Exception('Hiba történt a kép mentése során: ' . $files['name'][$i]);
                        }
                    }

                    $conn->commit();
                    $uploadSuccess = true;

                    // Clear POST data to prevent re-submission
                    $_POST = array();
                } catch (Exception $e) {
                    $conn->rollBack();
                    $uploadError = 'Hiba történt a hirdetés mentése során: ' . $e->getMessage();
                }
            }
        }
    }

    // Handle item deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
        $itemId = $_POST['item_id'] ?? '';

        if ($itemId && $isAdmin) {
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

                // Delete from database (item_images will be deleted automatically by foreign key constraint)
                $deleteStmt = $conn->prepare("DELETE FROM items WHERE id = ?");
                $deleteStmt->execute([$itemId]);

                // Redirect to refresh the page
                header("Location: index.php?page=" . $page);
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
    <style>
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

        /* Top bar */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.5rem 0 0;
            pointer-events: none;
        }

        /* Admin button */
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

        /* Upload button */
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

        /* Main content */
        .main-content {
            width: 100%;
            max-width: 2000px;
            margin: 0 auto;
            padding: 4.5rem 1rem 5rem 1rem;
            position: relative;
            z-index: 1;
        }

        .items-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 1.5rem;
            width: 100%;
        }

        .item-card {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-deep), 0 0 30px rgba(255, 140, 0, 0.1);
            display: flex;
            flex-direction: column;
            height: 100%;
            user-select: none;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .item-card:hover {
            transform: translateY(-5px);
            border-color: var(--orange-bright);
            box-shadow: var(--shadow-deep), 0 0 40px rgba(255, 140, 0, 0.2);
        }

        .item-card * {
            user-select: none;
        }

        .item-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 1rem;
            border: 1px solid var(--glass-border);
        }

        .item-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--orange-bright);
            margin-bottom: 0.5rem;
            word-wrap: break-word;
        }

        .item-price {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--orange-bright);
            margin-bottom: 0.5rem;
            text-shadow: 0 0 10px var(--orange-glow);
        }

        .item-seller {
            font-size: 0.8rem;
            color: var(--text-primary);
            opacity: 0.6;
            margin-bottom: 0.5rem;
        }

        .item-date {
            font-size: 0.7rem;
            color: var(--text-primary);
            opacity: 0.4;
        }

        .image-count-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            border: 1px solid var(--orange-glow);
            color: var(--orange-bright);
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
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 100px;
        }

        .report-form-textarea:focus {
            outline: none;
            border-color: var(--orange-bright);
        }

        .report-submit-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--orange-bright), #ff5500);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .report-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 140, 0, 0.4);
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

        /* Logout / account styles */
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

        /* Image upload styles */
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

        /* Price input with Ft suffix */
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

        /* Validation error inline */
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

        /* Server-side error banner */
        .error-banner {
            background: rgba(255, 60, 60, 0.1);
            border: 1px solid rgba(255, 60, 60, 0.3);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #ff8080;
            font-size: 0.87rem;
            margin-bottom: 1.3rem;
        }

        /* Success banner */
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
           PRODUCT MODAL - JAVÍTVA
        ===================== */
        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 4000;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .product-modal-card {
            width: 100%;
            max-width: 1200px;
            max-height: 90vh;
            background: rgba(10, 10, 10, 0.98);
            border: 1px solid var(--orange-bright);
            border-radius: 24px;
            overflow-y: auto;
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            padding: 2rem;
            transform: scale(0.95);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.8), 0 0 40px rgba(255, 140, 0, 0.2);
        }

        .product-modal-overlay.active .product-modal-card {
            transform: scale(1);
        }

        /* Fejléc konténer a jobb felső gombokhoz */
        .product-modal-header {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
            z-index: 10;
        }

        .product-modal-close {
            position: relative;
            top: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid var(--orange-glow);
            color: var(--orange-bright);
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            margin: 0;
        }

        .product-modal-close:hover {
            background: var(--orange-bright);
            color: black;
        }

        /* Product menu - finomhangolva */
        .product-menu {
            position: relative;
            z-index: 10;
        }

        .product-menu-button {
            width: 40px;
            height: 40px;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid var(--orange-glow);
            border-radius: 50%;
            color: var(--orange-bright);
            font-size: 1.8rem;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin: 0;
        }

        .product-menu-button:hover {
            background: var(--orange-bright);
            color: black;
            transform: scale(1.1);
        }

        .product-menu-content {
            position: absolute;
            top: 45px;
            right: 0;
            min-width: 150px;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid var(--orange-bright);
            border-radius: 8px;
            padding: 0.5rem;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
            display: none;
            z-index: 30;
        }

        .product-menu-content.show {
            display: block;
        }

        .product-menu-item {
            width: 100%;
            padding: 0.5rem 1rem;
            background: transparent;
            border: none;
            color: white;
            text-align: left;
            font-size: 0.9rem;
            cursor: pointer;
            border-radius: 4px;
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

        /* Galéria stílusok */
        .product-gallery {
            position: relative;
        }

        .product-main-image-container {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--glass-border);
            margin-bottom: 1rem;
        }

        .product-main-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: 1px solid var(--orange-bright);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            z-index: 5;
        }

        .gallery-nav:hover {
            background: var(--orange-bright);
            color: black;
        }

        .gallery-nav.prev {
            left: 10px;
        }

        .gallery-nav.next {
            right: 10px;
        }

        .gallery-nav.hidden {
            display: none;
        }

        .product-thumbnails {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
        }

        .product-thumbnail {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .product-thumbnail:hover {
            border-color: var(--orange-bright);
            transform: translateY(-2px);
        }

        .product-thumbnail.active {
            border-color: var(--orange-bright);
            box-shadow: 0 0 15px var(--orange-glow);
        }

        .product-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Termék adatok */
        .product-details {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .product-details-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .product-title {
            font-size: 2rem;
            color: var(--orange-bright);
            margin: 0;
            word-break: break-word;
            line-height: 1.2;
        }

        .product-price {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--orange-bright);
            text-shadow: 0 0 20px var(--orange-glow);
        }

        .product-seller {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .product-seller strong {
            color: var(--orange-bright);
        }

        .product-date {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.4);
        }

        .product-description {
            font-size: 1rem;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.9);
            background: rgba(0, 0, 0, 0.3);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--glass-border);
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        .product-buy-btn {
            background: linear-gradient(135deg, #00c851, #007e33);
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            color: white;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .product-buy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 200, 0, 0.4);
        }

        /* =====================
           RESPONSIVE
        ===================== */
        @media (max-width: 1800px) {
            .items-grid {
                grid-template-columns: repeat(7, 1fr);
            }
        }

        @media (max-width: 1600px) {
            .items-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        @media (max-width: 1400px) {
            .items-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .items-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 1000px) {
            .items-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 800px) {
            .items-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 600px) {
            .items-grid {
                grid-template-columns: 1fr;
            }

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
                grid-template-columns: 1fr;
                padding: 1rem;
                gap: 1rem;
            }

            .product-title {
                font-size: 1.5rem;
            }

            .product-price {
                font-size: 2rem;
            }

            /* Mobil: csak az ikon jelenjen meg a gombokon */
            .upload-btn .button-text,
            .admin-btn .button-text {
                display: none;
            }

            .product-modal-header {
                top: 0.5rem;
                right: 0.5rem;
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
    </style>
</head>

<body>
    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

    <!-- Top bar: admin button (if admin) + upload button + account menu -->
    <div class="top-bar">
        <?php if ($isAdmin): ?>
            <a href="admin.php" class="admin-btn unselectable" id="adminBtn">
                <span class="shield-icon">🛡️</span>
                <span class="button-text">Admin</span>
            </a>
        <?php endif; ?>

        <button class="upload-btn unselectable" id="openModalBtn" type="button">
            <span class="plus-icon">＋</span>
            <span class="button-text">Hirdetés feladása</span>
        </button>

        <details class="account-menu">
            <summary class="account-summary unselectable">
                <span>⚙️</span> FIÓK
            </summary>
            <div class="account-dropdown">
                <div class="user-info unselectable">
                    <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
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

    <!-- Upload Modal -->
    <div class="modal-overlay" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-card">
            <button class="modal-close" id="closeModalBtn" type="button" aria-label="Bezárás">✕</button>

            <div class="modal-title" id="modalTitle">Új hirdetés</div>
            <div class="modal-subtitle">Tölts fel legalább 1 képet a termékről</div>

            <?php if ($uploadSuccess): ?>
                <div class="success-banner">
                    <span>✓</span> A hirdetés sikeresen fel lett adva!
                </div>
            <?php endif; ?>

            <?php if ($uploadError): ?>
                <div class="error-banner"><?php echo htmlspecialchars($uploadError); ?></div>
            <?php endif; ?>

            <form method="post" id="uploadForm" enctype="multipart/form-data" novalidate>
                <div class="image-upload-container">
                    <label for="item_images" class="image-upload-label">
                        <span class="image-upload-icon">📸</span>
                        <span class="image-upload-hint">
                            Kattints ide a képek kiválasztásához<br>
                            <small>Támogatott formátumok: JPEG, PNG, GIF, WebP (max. 5MB/kép)</small>
                        </span>
                    </label>
                    <input type="file" id="item_images" name="item_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <div class="image-preview-container" id="imagePreview"></div>
                    <div class="field-error" id="images-error" style="margin-top: 0.5rem;">Legalább egy képet fel kell tölteni!</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="item_title">
                        Cím <span class="required-star">*</span>
                    </label>
                    <input
                        class="form-input"
                        type="text"
                        id="item_title"
                        name="item_title"
                        placeholder="pl. iPhone 14 Pro 256GB"
                        maxlength="255"
                        value="<?php echo isset($_POST['item_title']) && $uploadError ? htmlspecialchars($_POST['item_title']) : ''; ?>"
                        autocomplete="off">
                    <div class="field-error" id="title-error">Kérjük, add meg a hirdetés címét!</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="item_description">
                        Leírás <span class="required-star">*</span>
                    </label>
                    <textarea
                        class="form-textarea"
                        id="item_description"
                        name="item_description"
                        placeholder="Írd le a termék állapotát, jellemzőit..."><?php echo isset($_POST['item_description']) && $uploadError ? htmlspecialchars($_POST['item_description']) : ''; ?></textarea>
                    <div class="field-error" id="desc-error">Kérjük, adj meg egy leírást!</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="item_price">
                        Ár <span class="required-star">*</span>
                    </label>
                    <div class="price-wrapper">
                        <input
                            class="form-input"
                            type="number"
                            id="item_price"
                            name="item_price"
                            placeholder="0"
                            min="0"
                            step="1"
                            value="<?php echo isset($_POST['item_price']) && $uploadError ? htmlspecialchars($_POST['item_price']) : ''; ?>">
                        <span class="price-suffix">Ft</span>
                    </div>
                    <div class="field-error" id="price-error">Kérjük, adj meg egy érvényes árat!</div>
                </div>

                <button type="submit" name="upload_item" class="submit-btn unselectable">
                    Hirdetés feladása
                </button>
            </form>
        </div>
    </div>

    <!-- Report Modal -->
    <div class="report-modal" id="reportModal">
        <div class="report-modal-content">
            <div class="report-modal-header">
                <h3 class="report-modal-title">Hirdetés bejelentése</h3>
                <button class="report-modal-close" onclick="closeReportModal()">✕</button>
            </div>
            <form method="post" id="reportForm">
                <input type="hidden" name="item_id" id="reportItemId">
                <input type="hidden" name="report_item" value="1">
                <div class="report-form-group">
                    <label class="report-form-label">Bejelentés oka:</label>
                    <textarea
                        name="report_reason"
                        class="report-form-textarea"
                        required
                        placeholder="Kérjük, részletezd a problémát..."></textarea>
                </div>
                <button type="submit" class="report-submit-btn">Bejelentés küldése</button>
            </form>
        </div>
    </div>

    <!-- Product Detail Modal - JAVÍTVA -->
    <div class="product-modal-overlay" id="productModal">
        <div class="product-modal-card">
            <!-- Új header konténer a jobb felső gomboknak -->
            <div class="product-modal-header">
                <!-- Hárompontos menü a modálban -->
                <div class="product-menu" id="productMenuContainer" style="display: none;">
                    <div class="product-menu-button" onclick="toggleProductMenu(this)">⋮</div>
                    <div class="product-menu-content" id="productMenuContent">
                        <button class="product-menu-item" id="productReportBtn">⚠️ Bejelentés</button>
                        <button class="product-menu-item delete" id="productDeleteBtn" style="display: none;">🗑️ Törlés</button>
                    </div>
                </div>
                
                <!-- Bezáró gomb -->
                <button class="product-modal-close" id="closeProductModalBtn">✕</button>
            </div>
            
            <!-- Képgaléria -->
            <div class="product-gallery">
                <div class="product-main-image-container">
                    <img src="" alt="Termék képe" class="product-main-image" id="productMainImage">
                    <button class="gallery-nav prev" id="galleryPrev">❮</button>
                    <button class="gallery-nav next" id="galleryNext">❯</button>
                </div>
                <div class="product-thumbnails" id="productThumbnails"></div>
            </div>

            <!-- Termék adatok -->
            <div class="product-details">
                <div class="product-details-header">
                    <h2 class="product-title" id="productTitle"></h2>
                    <!-- A menü ELTÁVOLÍTVA innen, mert feljebb került a header-be -->
                </div>

                <div class="product-price" id="productPrice"></div>
                <div class="product-seller" id="productSeller"></div>
                <div class="product-date" id="productDate"></div>
                <div class="product-description" id="productDescription"></div>

                <!-- Vásárlás gomb -->
                <button class="product-buy-btn" id="productBuyBtn">
                    🛒 Vásárlás
                </button>
            </div>
        </div>
    </div>

    <div class="main-content">
        <?php if (!empty($items)): ?>
            <div class="items-grid">
                <?php foreach ($items as $item):
                    // Get the primary image for this item
                    $imageStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? AND is_primary = 1 LIMIT 1");
                    $imageStmt->execute([$item['id']]);
                    $primaryImage = $imageStmt->fetch(PDO::FETCH_ASSOC);

                    // Get total image count for this item
                    $countStmt = $conn->prepare("SELECT COUNT(*) as image_count FROM item_images WHERE item_id = ?");
                    $countStmt->execute([$item['id']]);
                    $imageCount = $countStmt->fetch(PDO::FETCH_ASSOC)['image_count'];
                    
                    // Get all images for this item (for the modal)
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
                        
                        <!-- Hárompontos menü -->
                        <?php
                        // Csak akkor jelenítjük meg a menüt, ha nem a saját termékünk, vagy ha admin vagyunk
                        $showMenu = ($item['user_id'] != $_SESSION['user_id'] || $isAdmin);
                        if ($showMenu):
                        ?>
                            <div class="card-menu">
                                <div class="card-menu-button" onclick="toggleMenu(this); event.stopPropagation();">⋮</div>
                                <div class="card-menu-content">
                                    <?php if ($item['user_id'] != $_SESSION['user_id'] || $isAdmin): ?>
                                        <!-- Bejelentés opció mindenkinek, aki nem a tulajdonos, vagy admin -->
                                        <button class="card-menu-item" onclick="openReportModal('<?php echo $item['id']; ?>'); event.stopPropagation();">
                                            ⚠️ Bejelentés
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($isAdmin): ?>
                                        <!-- Törlés opció csak adminoknak -->
                                        <form method="post" style="margin:0; padding:0;" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a hirdetést?');" onclick="event.stopPropagation();">
                                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                            <input type="hidden" name="delete_item" value="1">
                                            <button type="submit" class="card-menu-item delete">🗑️ Törlés</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($primaryImage): ?>
                            <img src="<?php echo htmlspecialchars($primaryImage['image_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" class="item-image">
                        <?php else: ?>
                            <div class="item-image" style="background: rgba(255,140,0,0.1); display: flex; align-items: center; justify-content: center;">
                                <span style="color: var(--orange-bright); opacity: 0.5;">📷 Nincs kép</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($imageCount > 1): ?>
                            <div class="image-count-badge">+<?php echo $imageCount - 1; ?> kép</div>
                        <?php endif; ?>

                        <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="item-price"><?php echo number_format($item['price'], 0, ',', ' '); ?> Ft</div>
                        <div class="item-seller">Eladó: <?php echo htmlspecialchars($item['seller_name']); ?></div>
                        <div class="item-date"><?php echo date('Y-m-d', strtotime($item['created_at'])); ?></div>
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

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        // Close on Escape
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

            // Validate file types and sizes
            const validFiles = files.filter(file => {
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                const maxSize = 5 * 1024 * 1024; // 5MB

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

                reader.onload = function(e) {
                    previewItem.innerHTML = `
                        <img src="${e.target.result}" alt="Preview">
                        <div class="image-preview-remove" data-index="${index}">×</div>
                        ${index === 0 ? '<div class="primary-badge">Főkép</div>' : ''}
                    `;
                };

                reader.readAsDataURL(file);
                previewContainer.appendChild(previewItem);
            });

            // Add remove functionality
            document.querySelectorAll('.image-preview-remove').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    selectedFiles.splice(index, 1);

                    // Update file input
                    const dt = new DataTransfer();
                    selectedFiles.forEach(file => dt.items.add(file));
                    imageInput.files = dt.files;

                    updatePreview();
                });
            });

            // Validate images count
            validateImages();
        }

        // Client-side validation
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

        function validateImages() {
            const isValid = selectedFiles.length > 0;
            if (!isValid) {
                imagesError.style.display = 'block';
                document.querySelector('.image-upload-container').style.borderColor = '#ff4d4d';
            } else {
                imagesError.style.display = 'none';
                document.querySelector('.image-upload-container').style.borderColor = 'rgba(255, 140, 0, 0.3)';
            }
            return isValid;
        }

        form.addEventListener('submit', (e) => {
            let valid = true;
            valid = validateImages() && valid;
            valid = validateField(titleInput, 'title-error', titleInput.value.trim() !== '') && valid;
            valid = validateField(descInput, 'desc-error', descInput.value.trim() !== '') && valid;
            valid = validateField(priceInput, 'price-error', priceInput.value !== '' && parseFloat(priceInput.value) >= 0) && valid;

            if (!valid) e.preventDefault();
        });

        // Live clear on input
        [titleInput, descInput, priceInput].forEach(el => {
            el.addEventListener('input', () => el.classList.remove('invalid'));
        });

        // Auto-open modal on server error or success (so user sees feedback)
        <?php if ($uploadError || $uploadSuccess): ?>
            openModal();
        <?php endif; ?>

        // Card menu functionality
        function toggleMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');

            // Close other menus
            document.querySelectorAll('.card-menu-content').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
        }

        // Close menus when clicking outside
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

        // Close report modal on backdrop click
        reportModal.addEventListener('click', function(e) {
            if (e.target === reportModal) {
                closeReportModal();
            }
        });

        // Close report modal on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && reportModal.classList.contains('show')) {
                closeReportModal();
            }
        });

        // Product modal functionality
        const productModal = document.getElementById('productModal');
        const closeProductModalBtn = document.getElementById('closeProductModalBtn');
        
        // Current product data
        let currentProductImages = [];
        let currentImageIndex = 0;
        let currentProductId = null;
        let currentProductUserId = null;

        // Add click event to all item cards
        document.querySelectorAll('.item-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't open modal if clicking on menu or report modal
                if (e.target.closest('.card-menu') || e.target.closest('.report-modal')) {
                    return;
                }
                
                // Get product data from data attributes
                const productId = this.dataset.itemId;
                const title = this.dataset.itemTitle;
                const price = this.dataset.itemPrice;
                const seller = this.dataset.itemSeller;
                const date = this.dataset.itemDate;
                const description = this.dataset.itemDescription;
                const images = JSON.parse(this.dataset.itemImages || '[]');
                const userId = this.dataset.itemUserId;
                
                // Store current product data
                currentProductId = productId;
                currentProductUserId = userId;
                currentProductImages = images;
                currentImageIndex = 0;
                
                // Set product details in modal
                document.getElementById('productTitle').textContent = title;
                document.getElementById('productPrice').textContent = price;
                document.getElementById('productSeller').innerHTML = `Eladó: <strong>${seller}</strong>`;
                document.getElementById('productDate').textContent = date;
                document.getElementById('productDescription').textContent = description;
                
                // Set main image
                const mainImage = document.getElementById('productMainImage');
                if (images.length > 0) {
                    mainImage.src = images[0];
                } else {
                    mainImage.src = 'data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22200%22%20height%3D%22200%22%20viewBox%3D%220%200%20200%20200%22%3E%3Crect%20width%3D%22200%22%20height%3D%22200%22%20fill%3D%22%23333%22%2F%3E%3Ctext%20x%3D%2250%22%20y%3D%22115%22%20font-family%3D%22Arial%22%20font-size%3D%2220%22%20fill%3D%22%23ff8c00%22%3E📷 Nincs kép%3C%2Ftext%3E%3C%2Fsvg%3E';
                }
                
                // Setup thumbnails
                const thumbnailsContainer = document.getElementById('productThumbnails');
                thumbnailsContainer.innerHTML = '';
                
                images.forEach((img, index) => {
                    const thumbnail = document.createElement('div');
                    thumbnail.className = `product-thumbnail ${index === 0 ? 'active' : ''}`;
                    thumbnail.innerHTML = `<img src="${img}" alt="Thumbnail ${index + 1}">`;
                    thumbnail.addEventListener('click', () => {
                        setMainImage(index);
                    });
                    thumbnailsContainer.appendChild(thumbnail);
                });
                
                // Show/hide navigation based on number of images
                const prevBtn = document.getElementById('galleryPrev');
                const nextBtn = document.getElementById('galleryNext');
                
                if (images.length <= 1) {
                    prevBtn.classList.add('hidden');
                    nextBtn.classList.add('hidden');
                } else {
                    prevBtn.classList.remove('hidden');
                    nextBtn.classList.remove('hidden');
                }
                
                // Setup menu based on user permissions
                <?php if (isset($_SESSION['user_id'])): ?>
                    const isOwner = (parseInt(userId) === <?php echo $_SESSION['user_id']; ?>);
                    const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
                    
                    const menuContainer = document.getElementById('productMenuContainer');
                    const reportBtn = document.getElementById('productReportBtn');
                    const deleteBtn = document.getElementById('productDeleteBtn');
                    
                    // Show menu if not owner or is admin
                    if (!isOwner || isAdmin) {
                        menuContainer.style.display = 'block';
                        
                        // Configure report button
                        reportBtn.onclick = () => {
                            closeProductModal();
                            openReportModal(productId);
                        };
                        
                        // Configure delete button (admin only)
                        if (isAdmin) {
                            deleteBtn.style.display = 'block';
                            deleteBtn.onclick = () => {
                                if (confirm('Biztosan törölni szeretnéd ezt a hirdetést?')) {
                                    // Create and submit delete form
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
                    } else {
                        menuContainer.style.display = 'none';
                    }
                <?php endif; ?>
                
                // Open modal
                openProductModal();
            });
        });

        function openProductModal() {
            productModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProductModal() {
            productModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function setMainImage(index) {
            if (index >= 0 && index < currentProductImages.length) {
                currentImageIndex = index;
                document.getElementById('productMainImage').src = currentProductImages[index];
                
                // Update active thumbnail
                document.querySelectorAll('.product-thumbnail').forEach((thumb, i) => {
                    if (i === index) {
                        thumb.classList.add('active');
                    } else {
                        thumb.classList.remove('active');
                    }
                });
            }
        }

        // Gallery navigation
        document.getElementById('galleryPrev').addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex - 1;
            if (newIndex >= 0) {
                setMainImage(newIndex);
            } else {
                setMainImage(currentProductImages.length - 1); // Loop to last
            }
        });

        document.getElementById('galleryNext').addEventListener('click', (e) => {
            e.stopPropagation();
            const newIndex = currentImageIndex + 1;
            if (newIndex < currentProductImages.length) {
                setMainImage(newIndex);
            } else {
                setMainImage(0); // Loop to first
            }
        });

        // Close modal events
        closeProductModalBtn.addEventListener('click', closeProductModal);

        productModal.addEventListener('click', (e) => {
            if (e.target === productModal) {
                closeProductModal();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && productModal.classList.contains('active')) {
                closeProductModal();
            }
        });

        // Vásárlás gomb (még nem csinál semmit)
        document.getElementById('productBuyBtn').addEventListener('click', () => {
            alert('Vásárlás funkció még nem elérhető!');
        });

        // Toggle menu in product modal
        function toggleProductMenu(button) {
            const menu = button.nextElementSibling;
            menu.classList.toggle('show');
            
            // Close other menus
            document.querySelectorAll('.product-menu-content').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
        }
    </script>
</body>

</html>