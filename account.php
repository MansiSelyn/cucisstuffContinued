<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Adatbázis kapcsolat
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cucidb";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $userId = $_SESSION['user_id'];

    // =============================================
    // AJAX: GET ITEM DETAILS (JSON) - a product modalhoz
    // =============================================
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

        // Képek lekérése
        $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? ORDER BY sort_order");
        $imgStmt->execute([$itemId]);
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        $item['images'] = $images;

        echo json_encode($item);
        exit;
    }

    // =============================================
    // AJAX: GET SELLER PROFILE (JSON) - opcionális
    // =============================================
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

        // Legutóbbi termékek (max 4)
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

    // Felhasználó adatainak lekérése
    $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Felhasználó termékeinek lekérése (a főoldali modalhoz szükséges összes adat)
    $itemStmt = $conn->prepare("
        SELECT i.id, i.title, i.description, i.price, i.created_at, i.user_id, u.username as seller_name
        FROM items i 
        JOIN users u ON i.user_id = u.id
        WHERE i.user_id = ? 
        ORDER BY i.created_at DESC
    ");
    $itemStmt->execute([$userId]);
    $userItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Adatbázis hiba: " . $e->getMessage());
}

// AJAX feldolgozás (fiók módosítás)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    header('Content-Type: application/json');
    
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail    = trim($_POST['email'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');
    $response = ['success' => false, 'message' => ''];

    if (empty($newUsername) || empty($newEmail)) {
        $response['message'] = 'A felhasználónév és e-mail mezők kitöltése kötelező!';
    } else {
        // Felhasználónév ellenőrzés
        $checkUser = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkUser->execute([$newUsername, $userId]);
        if ($checkUser->fetchColumn()) {
            $response['message'] = 'Ez a felhasználónév már foglalt!';
        } else {
            // E-mail ellenőrzés
            $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$newEmail, $userId]);
            if ($checkEmail->fetchColumn()) {
                $response['message'] = 'Ez az e-mail cím már regisztrálva van!';
            } else {
                // Frissítés
                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        $response['message'] = 'A jelszónak legalább 6 karakternek kell lennie!';
                    } else {
                        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET username=?, email=?, password=? WHERE id=?");
                        $upd->execute([$newUsername, $newEmail, $hashed, $userId]);
                    }
                } else {
                    $upd = $conn->prepare("UPDATE users SET username=?, email=? WHERE id=?");
                    $upd->execute([$newUsername, $newEmail, $userId]);
                }

                if (empty($response['message'])) {
                    $response['success'] = true;
                    $response['message'] = 'Fiók sikeresen frissítve!';
                    $_SESSION['username'] = $newUsername;
                }
            }
        }
    }
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fiókom</title>
<link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
<style>
  /* Alap stílusok a téma változókkal */
  body {
    min-height: 100vh; margin: 0; padding: 2rem; box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--body-bg); color: var(--text-primary);
    transition: background 0.3s, color 0.3s;
  }
  .container { max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.5rem; }
  .header { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 16px; backdrop-filter: blur(10px); }
  .header h1 { margin: 0; color: var(--orange-bright); font-size: 1.6rem; user-select: none; }
  .back-btn { padding: 0.5rem 1rem; background: var(--orange-subtle); border: 1px solid var(--orange-bright); border-radius: 8px; color: var(--orange-bright); text-decoration: none; font-weight: 600; transition: 0.3s; user-select: none; }
  .back-btn:hover { background: var(--orange-bright); color: #000; }
  
  .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
  .info-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 12px; padding: 1.2rem; backdrop-filter: blur(10px); user-select: none; }
  .info-card label { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; user-select: none; }
  .info-card .val { font-size: 1.1rem; font-weight: 500; color: var(--text-primary); word-break: break-all; user-select: none; }
  
  .edit-btn { padding: 0.7rem 1.2rem; background: linear-gradient(135deg, var(--orange-bright), var(--orange-mid)); color: #000; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; margin-top: auto; user-select: none; }
  .edit-btn:hover { transform: translateY(-2px); box-shadow: 0 0 20px var(--orange-glow); }

  .items-section { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 16px; padding: 1.2rem; max-height: 65vh; overflow-y: auto; backdrop-filter: blur(10px); }
  .items-section h2 { color: var(--orange-bright); margin: 0 0 1rem 0; font-size: 1.3rem; user-select: none; }
  .items-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
  .mini-card { background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); border-radius: 12px; overflow: hidden; transition: 0.3s; cursor: pointer; user-select: none; }
  .mini-card:hover { border-color: var(--orange-bright); transform: translateY(-3px); }
  .mini-card img { width: 100%; height: 140px; object-fit: cover; background: var(--placeholder-bg); pointer-events: none; }
  .mini-card .info { padding: 0.7rem; }
  .mini-card .title { margin: 0; font-size: 0.9rem; color: var(--orange-bright); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; user-select: none; }
  .mini-card .price { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--text-primary); opacity: 0.8; user-select: none; }

  /* Modal */
  .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 2000; }
  .modal-overlay.active { display: flex; }
  .modal-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 20px; padding: 2rem; width: 90%; max-width: 420px; box-shadow: var(--shadow-deep); position: relative; }
  .modal-close { position: absolute; top: 1rem; right: 1rem; background: transparent; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer; }
  .modal-close:hover { color: var(--orange-bright); }
  .modal-title { color: var(--orange-bright); margin: 0 0 1.5rem 0; font-size: 1.4rem; }
  .form-group { margin-bottom: 1rem; }
  .form-group label { display: block; margin-bottom: 0.4rem; font-size: 0.85rem; color: var(--text-muted); }
  .form-group input { width: 100%; padding: 0.75rem; background: var(--input-bg); border: 1px solid var(--glass-border); border-radius: 10px; color: var(--text-primary); font-size: 0.95rem; box-sizing: border-box; }
  .form-group input:focus { outline: none; border-color: var(--orange-bright); box-shadow: 0 0 0 3px var(--orange-subtle); }
  .submit-btn { width: 100%; padding: 0.8rem; background: var(--orange-bright); color: #000; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; margin-top: 0.5rem; transition: 0.3s; }
  .submit-btn:hover { opacity: 0.9; }
  .status-msg { padding: 0.75rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.9rem; display: none; }
  .status-msg.error { background: rgba(255, 50, 50, 0.15); border: 1px solid #ff4d4d; color: #ff8080; }
  .status-msg.success { background: rgba(0, 200, 100, 0.15); border: 1px solid #00c851; color: #5dffa0; }

  /* =====================
     PRODUCT MODAL - TELJES KÉPERNYŐS KÁRTYA, KÉP ARÁNYOS MÉRETEZÉSSEL
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
      opacity: 0;
      transition: opacity 0.3s ease;
      padding: 1rem;
  }
  .product-modal-overlay.active {
      display: flex;
      opacity: 1;
  }
  
  /* Kártya: fix méret, a képernyő 90%-a, nem a kép méretéhez igazodik */
  .product-modal-card {
      width: 90vw;
      height: 90vh;
      max-width: none;
      max-height: none;
      background: rgba(5, 5, 5, 0.98);
      position: relative;
      display: grid;
      grid-template-columns: 1.5fr 1fr;
      gap: 1.5rem;
      padding: 1.5rem;
      transform: scale(0.98);
      transition: transform 0.3s ease;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 40px rgba(255, 140, 0, 0.15);
      border-radius: 24px;
      border: 1px solid var(--glass-border);
      overflow: hidden;
  }
  .product-modal-overlay.active .product-modal-card {
      transform: scale(1);
  }
  
  /* Fejléc (menu + close) - jobb felső sarokban */
  .product-modal-header {
      position: absolute;
      top: 1rem;
      right: 1rem;
      bottom: auto;
      left: auto;
      display: flex;
      gap: 0.75rem;
      z-index: 100;
  }
  
  .product-modal-close {
      background: rgba(20, 20, 20, 0.9);
      border: 1px solid var(--orange-bright);
      color: var(--orange-bright);
      font-size: 1.4rem;
      cursor: pointer;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: all 0.2s ease;
      backdrop-filter: blur(5px);
      user-select: none;
  }
  .product-modal-close:hover {
      background: var(--orange-bright);
      color: black;
      transform: scale(1.05);
  }
  .product-menu {
      position: relative;
  }
  .product-menu-button {
      width: 40px;
      height: 40px;
      background: rgba(20, 20, 20, 0.9);
      border: 1px solid var(--orange-bright);
      border-radius: 50%;
      color: var(--orange-bright);
      font-size: 1.6rem;
      line-height: 1;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      backdrop-filter: blur(5px);
      user-select: none;
  }
  .product-menu-button:hover {
      background: var(--orange-bright);
      color: black;
      transform: scale(1.05);
  }
  .product-menu-content {
      position: absolute;
      top: 48px;
      right: 0;
      min-width: 160px;
      background: rgba(10, 10, 10, 0.98);
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
      padding: 0.6rem 1rem;
      background: transparent;
      border: none;
      color: white;
      text-align: left;
      font-size: 0.85rem;
      cursor: pointer;
      border-radius: 6px;
      transition: all 0.2s ease;
      user-select: none;
  }
  .product-menu-item:hover {
      background: rgba(255, 140, 0, 0.2);
      color: var(--orange-bright);
  }
  .product-menu-item.delete:hover {
      background: rgba(255, 0, 0, 0.2);
      color: #ff0000;
  }
  
  /* Galéria konténer - teljes magasság kitöltése */
  .product-gallery {
      position: relative;
      height: 100%;
      display: flex;
      flex-direction: column;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 20px;
      padding: 1rem;
      min-height: 0;
  }
  .product-main-image-container {
      position: relative;
      width: 100%;
      flex: 1;
      border-radius: 16px;
      overflow: hidden;
      border: 1px solid var(--glass-border);
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 0;
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
      font-size: 1rem;
      padding: 1.5rem;
      user-select: none;
      color: var(--orange-bright);
      opacity: 0.6;
  }
  .gallery-nav {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(0, 0, 0, 0.7);
      color: white;
      border: 2px solid var(--orange-bright);
      width: 40px;
      height: 40px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      transition: all 0.2s ease;
      z-index: 10;
      backdrop-filter: blur(5px);
      user-select: none;
  }
  .gallery-nav:hover {
      background: var(--orange-bright);
      color: black;
      transform: translateY(-50%) scale(1.1);
  }
  .gallery-nav.prev {
      left: 12px;
  }
  .gallery-nav.next {
      right: 12px;
  }
  .gallery-nav.hidden {
      display: none;
  }
  
  /* Thumbnails - felül, görgethető */
  .product-thumbnails {
      display: flex;
      gap: 0.75rem;
      overflow-x: auto;
      padding: 0.5rem 0;
      min-height: 80px;
      margin-bottom: 1rem;
      flex-shrink: 0;
  }
  .product-thumbnail {
      width: 80px;
      height: 80px;
      border-radius: 10px;
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
  
  /* Termékadatok oszlop */
  .product-details {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      padding: 1rem;
      background: rgba(10, 10, 10, 0.7);
      border-radius: 20px;
      border: 1px solid var(--glass-border);
      height: 100%;
      overflow-y: auto;
      user-select: none;
  }
  .product-title {
      font-size: 1.8rem;
      color: var(--orange-bright);
      margin: 0;
      word-break: break-word;
      line-height: 1.2;
      font-weight: bold;
  }
  .product-price {
      font-size: 2rem;
      font-weight: bold;
      color: var(--orange-bright);
      text-shadow: 0 0 20px var(--orange-glow);
  }
  .product-seller {
      font-size: 0.95rem;
      color: rgba(255, 255, 255, 0.7);
      cursor: pointer;
  }
  .product-seller strong {
      color: var(--orange-bright);
      font-size: 1.1rem;
  }
  .product-date {
      font-size: 0.85rem;
      color: rgba(255, 255, 255, 0.4);
  }
  .product-description {
      font-size: 0.95rem;
      line-height: 1.6;
      color: rgba(255, 255, 255, 0.9);
      background: rgba(0, 0, 0, 0.4);
      border-radius: 14px;
      padding: 1rem;
      border: 1px solid var(--glass-border);
      max-height: 250px;
      overflow-y: auto;
      white-space: pre-wrap;
      user-select: text;
  }
  .product-buy-btn {
      background: linear-gradient(135deg, #00c851, #007e33);
      border: none;
      border-radius: 14px;
      padding: 1rem 1.5rem;
      color: white;
      font-size: 1.2rem;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      user-select: none;
      margin-top: auto;
  }
  .product-buy-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 200, 0, 0.4);
  }
  
  /* Lightbox */
  .lightbox-overlay {
      position: fixed;
      inset: 0;
      z-index: 5000;
      background: rgba(0, 0, 0, 0.96);
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
      font-size: 1.8rem;
      cursor: pointer;
      width: 45px;
      height: 45px;
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
      transform: scale(1.05);
  }
  
  /* Reszponzív viselkedés */
  @media (max-width: 900px) {
      .product-modal-card {
          grid-template-columns: 1fr;
          gap: 1rem;
          padding: 1rem;
          width: 95vw;
          height: 95vh;
      }
      .product-gallery {
          height: 45vh;
          min-height: 0;
      }
      .product-title {
          font-size: 1.5rem;
      }
      .product-price {
          font-size: 1.8rem;
      }
      .product-description {
          max-height: 200px;
      }
      .product-thumbnail {
          width: 65px;
          height: 65px;
      }
  }
  @media (max-width: 600px) {
      .product-modal-card {
          padding: 0.75rem;
      }
      .product-gallery {
          height: 40vh;
      }
      .product-details {
          padding: 0.75rem;
      }
      .product-title {
          font-size: 1.2rem;
      }
      .product-price {
          font-size: 1.4rem;
      }
      .product-description {
          padding: 0.75rem;
          font-size: 0.85rem;
      }
      .product-modal-header {
          top: 0.5rem;
          right: 0.5rem;
      }
      .product-modal-close,
      .product-menu-button {
          width: 32px;
          height: 32px;
          font-size: 1.1rem;
      }
      .product-thumbnail {
          width: 55px;
          height: 55px;
      }
      .product-buy-btn {
          padding: 0.75rem 1rem;
          font-size: 1rem;
      }
      .gallery-nav {
          width: 32px;
          height: 32px;
          font-size: 1rem;
      }
      .lightbox-close {
          width: 36px;
          height: 36px;
          font-size: 1.4rem;
      }
  }
  
  .unselectable {
      user-select: none;
      -webkit-user-select: none;
  }
</style>
</head>
<body data-theme="dark">
<div class="container">
  <div class="header">
    <h1 class="unselectable">Fiókom</h1>
    <a href="main.php" class="back-btn unselectable">← Vissza</a>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <label class="unselectable">Felhasználónév</label>
      <div class="val unselectable"><?= htmlspecialchars($user['username']) ?></div>
    </div>
    <div class="info-card">
      <label class="unselectable">E-mail cím</label>
      <div class="val unselectable"><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div class="info-card" style="display: flex; flex-direction: column; justify-content: center;">
      <button class="edit-btn unselectable" onclick="openModal()">✏️ Fiók módosítása</button>
    </div>
  </div>

  <div class="items-section">
    <h2 class="unselectable">Hirdetéseim (<?= count($userItems) ?>)</h2>
    <?php if (empty($userItems)): ?>
      <p class="unselectable" style="text-align:center; opacity:0.6; padding: 2rem 0;">Még nem adtál fel hirdetést.</p>
    <?php else: ?>
      <div class="items-grid">
        <?php foreach ($userItems as $item): ?>
          <div class="mini-card" data-item-id="<?= htmlspecialchars($item['id']) ?>">
            <?php
              // Kép lekérése a termékhez (elsődleges kép)
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
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Módosító Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-card">
    <button class="modal-close unselectable" onclick="closeModal()">✕</button>
    <h3 class="modal-title unselectable">Adatok módosítása</h3>
    <div id="modalStatus" class="status-msg"></div>
    <form id="editForm" method="POST">
      <div class="form-group">
        <label for="username" class="unselectable">Felhasználónév</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
      </div>
      <div class="form-group">
        <label for="email" class="unselectable">E-mail cím</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
      </div>
      <div class="form-group">
        <label for="password" class="unselectable">Új jelszó <span style="opacity:0.6">(hagyd üresen, ha nem változtatod)</span></label>
        <input type="password" id="password" name="password" placeholder="••••••">
      </div>
      <input type="hidden" name="update_account" value="1">
      <button type="submit" class="submit-btn unselectable" id="submitBtn">Mentés</button>
    </form>
  </div>
</div>

<!-- ===================== TERMÉK MODAL ===================== -->
<div class="product-modal-overlay" id="productModal">
    <div class="product-modal-card">
        <!-- Fejléc (menu + close) - jobb felső sarokban -->
        <div class="product-modal-header">
            <div class="product-menu" id="productMenuContainer" style="display: none;">
                <div class="product-menu-button unselectable" onclick="toggleProductMenu(this)">⋮</div>
                <div class="product-menu-content" id="productMenuContent">
                    <button class="product-menu-item unselectable" id="productEditBtn" style="display:none;">✏️ Módosítás</button>
                    <button class="product-menu-item delete unselectable" id="productDeleteBtn" style="display: none;">🗑️ Törlés</button>
                </div>
            </div>
            <button class="product-modal-close unselectable" id="closeProductModalBtn">✕</button>
        </div>

        <div class="product-gallery">
            <div class="product-thumbnails" id="productThumbnails"></div>
            <div class="product-main-image-container">
                <img src="" alt="Termék képe" class="product-main-image" id="productMainImage" style="display: none;">
                <div class="product-no-image-placeholder unselectable" id="productNoImagePlaceholder" style="display: none;">
                    📷 Nincs kép
                </div>
                <button class="gallery-nav prev unselectable" id="galleryPrev">❮</button>
                <button class="gallery-nav next unselectable" id="galleryNext">❯</button>
            </div>
        </div>

        <div class="product-details">
            <h2 class="product-title unselectable" id="productTitle"></h2>
            <div class="product-price unselectable" id="productPrice"></div>
            <div class="product-seller unselectable" id="productSeller"></div>
            <div class="product-date unselectable" id="productDate"></div>
            <div class="product-description selectable" id="productDescription"></div>
            <button class="product-buy-btn unselectable" id="productBuyBtn">
                🛒 Vásárlás
            </button>
        </div>
    </div>
</div>

<!-- Lightbox a nagyított képhez -->
<div class="lightbox-overlay" id="lightboxOverlay">
    <div class="lightbox-content">
        <img src="" alt="Nagyított kép" class="lightbox-image" id="lightboxImage">
        <button class="lightbox-close unselectable" id="lightboxClose">✕</button>
    </div>
</div>

<script>
// Téma betöltése localStorage-ból
const themeLink = document.getElementById('themeStylesheet');
const savedTheme = localStorage.getItem('theme') || 'dark';
themeLink.href = savedTheme === 'light' ? 'theme-light.css' : 'theme-dark.css';
document.body.setAttribute('data-theme', savedTheme);

// Modal kezelés (fiók módosítás)
const modal = document.getElementById('editModal');
const statusBox = document.getElementById('modalStatus');
const form = document.getElementById('editForm');
const submitBtn = document.getElementById('submitBtn');

function openModal() {
  statusBox.style.display = 'none';
  statusBox.className = 'status-msg';
  modal.classList.add('active');
}
function closeModal() {
  modal.classList.remove('active');
}
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// AJAX fiók módosítás
form.addEventListener('submit', function(e) {
  e.preventDefault();
  statusBox.style.display = 'none';
  submitBtn.disabled = true;
  submitBtn.textContent = 'Feldolgozás...';

  const formData = new FormData(form);

  fetch('account.php', {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(res => res.json())
  .then(data => {
    statusBox.textContent = data.message;
    statusBox.style.display = 'block';
    statusBox.classList.add(data.success ? 'success' : 'error');

    if (data.success) {
      setTimeout(() => {
        window.location.reload();
      }, 800);
    }
  })
  .catch(err => {
    statusBox.textContent = 'Váratlan hiba történt.';
    statusBox.style.display = 'block';
    statusBox.classList.add('error');
  })
  .finally(() => {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Mentés';
  });
});

// ===================== TERMÉK MODAL LOGIKA =====================
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
    if (imageContainer && gallery) {
        // A konténer rugalmas marad (flex: 1), de biztosítjuk, hogy ne lógjon ki
        imageContainer.style.height = 'auto';
        const containerRect = imageContainer.getBoundingClientRect();
        const maxHeight = gallery.clientHeight - (document.querySelector('.product-thumbnails')?.offsetHeight || 80) - 20;
        if (containerRect.height > maxHeight) {
            imageContainer.style.height = maxHeight + 'px';
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
            const deleteBtn = document.getElementById('productDeleteBtn');
            const editBtn = document.getElementById('productEditBtn');
            const isOwner = (parseInt(item.user_id) === <?php echo (int)$_SESSION['user_id']; ?>);

            menuContainer.style.display = 'block';

            // Szerkesztés gomb - csak a tulajdonosnak
            if (isOwner) {
                editBtn.style.display = 'block';
                editBtn.onclick = () => {
                    closeProductModal();
                    alert('Saját termék szerkesztése a főoldalon a ⋮ menüből lehetséges.');
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

function toggleProductMenu(button) {
    const menu = button.nextElementSibling;
    menu.classList.toggle('show');
    document.querySelectorAll('.product-menu-content').forEach(m => {
        if (m !== menu) m.classList.remove('show');
    });
}

// Eseménykezelők
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

// Helper: HTML escape
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Kattintás a termékekre a .mini-card-on
document.querySelectorAll('.mini-card').forEach(card => {
    card.addEventListener('click', function(e) {
        const itemId = this.dataset.itemId;
        if (itemId) {
            fetchItemDetails(itemId);
        }
    });
});
</script>
</body>
</html>