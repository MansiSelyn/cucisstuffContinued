<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Kijelentkezés kezelése (ugyanúgy, mint main.php-ban)
if (isset($_POST['logout'])) {
    $_SESSION = array();
    session_destroy();
    header("Location: index.php");
    exit();
}

// Adatbázis kapcsolat
require_once 'config.php';
$servername = DB_HOST;
$username   = DB_USER;
$password   = DB_PASS;
$dbname     = DB_NAME;

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $userId = $_SESSION['user_id'];

    // Admin ellenőrzés (ugyanaz, mint main.php-ban)
    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$userId]);
    $isAdmin = $adminCheck->fetchColumn() > 0;

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

    // =============================================
    // TERMÉK MÓDOSÍTÁS KEZELÉSE (POST)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
        $itemId  = $_POST['item_id'] ?? '';
        $title   = trim($_POST['edit_title'] ?? '');
        $desc    = trim($_POST['edit_description'] ?? '');
        $price   = trim($_POST['edit_price'] ?? '');

        // Jogosultság ellenőrzés: csak a tulajdonos módosíthatja
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

    // Üzenet a sikeres módosításról (opcionális)
    $editSuccess = isset($_GET['edit']) && $_GET['edit'] === 'success';

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
  /* ========== GLOBÁLIS RESET – EZ HIÁNYZOTT ========== */
  *,
  *::before,
  *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
  }

  /* ========== ALAP STÍLUSOK (account.php eredeti) ========== */
  body {
    min-height: 100vh;
    margin: 0;
    padding: 0;                 /* eltávolítottuk a paddingot, mert a top-bar fixed */
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--body-bg);
    color: var(--text-primary);
    transition: background 0.3s, color 0.3s;
  }
  .container {
    max-width: 1100px;
    margin: 70px auto 0 auto;  /* hely a fixed top-bar alatt */
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    padding: 0 1rem;
  }
  /* ========== TOP BAR ÉS FIÓKMENÜ (átvéve main.php-ből) ========== */
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
  .top-bar-left {
    display: flex;
    gap: 0.5rem;
    pointer-events: auto;
  }
  .top-bar-right {
    display: flex;
    gap: 0.5rem;
    pointer-events: auto;
  }
  /* Vissza gomb */
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
    color: #000;
  }
  /* Admin gomb */
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
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    white-space: nowrap;
    text-decoration: none;
  }
  .admin-btn:hover {
    background: rgba(255, 215, 0, 0.25);
    border-color: #ffd700;
    box-shadow: 0 0 16px rgba(255, 215, 0, 0.35);
    transform: translateY(-1px);
    color: #ffd700;
  }
  /* ── ACCOUNT DROPDOWN (account.php) ── */
  .account-menu {
    position: relative;
    display: inline-block;
  }
  /* A gomb */
  .account-menu-btn {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    border: 1px solid var(--orange-glow);
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(10px);
    color: var(--orange-bright);
    font-size: 0.9rem;
    font-family: inherit;
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    transition: background 0.2s, border-color 0.2s;
  }
  .account-menu-btn:hover {
    background: rgba(255, 140, 0, 0.1);
    border-color: var(--orange-bright);
  }
  /* A panel – alapból rejtve, .show osztállyal jelenik meg */
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
  /* A tényleges vizuális doboz */
  .account-dropdown-panel {
    border-radius: 14px;
    padding: 0.5rem;
    overflow: hidden;
  }
  /* Felhasználónév sor */
  .dropdown-username {
    font-size: 0.85rem;
    font-weight: 700;
    padding: 0.6rem 0.8rem 0.5rem;
    word-break: break-all;
    user-select: none;
  }
  /* Elválasztó */
  .dropdown-divider {
    height: 1px;
    margin: 0.3rem 0.4rem;
  }
  /* Minden kattintható sor egységesen */
  .dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.65rem 0.8rem;
    border-radius: 8px;
    font-size: 0.88rem;
    font-family: inherit;
    text-decoration: none;
    background: transparent;
    border: none;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s, color 0.15s;
    box-sizing: border-box;
    text-align: left;
  }
  /* Kijelentkezés form wrapper */
  .logout-form {
    width: 100%;
    margin: 0;
    padding: 0;
  }
  .logout-form-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.65rem 0.8rem;
    border-radius: 8px;
    font-size: 0.88rem;
    font-family: inherit;
    background: transparent;
    border: none;
    cursor: pointer;
    user-select: none;
    transition: background 0.15s, color 0.15s;
    box-sizing: border-box;
    text-align: left;
  }
  /* Témaváltó sor */
  .dropdown-theme-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.6rem 0.8rem;
    font-size: 0.85rem;
    user-select: none;
  }
  .dropdown-theme-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
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
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.15);
    transition: background 0.3s, border-color 0.3s;
    cursor: pointer;
  }
  .theme-switch input:checked + .theme-switch-track {
    background: rgba(176,203,31,0.25);
    border-color: #B0CB1F;
  }
  .theme-switch-thumb {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    transition: transform 0.3s, background 0.3s;
    pointer-events: none;
  }
  .theme-switch input:checked ~ .theme-switch-thumb {
    transform: translateX(18px);
    background: #B0CB1F;
  }

  /* ========== LIGHT MÓD FELÜLÍRÁSOK (világos háttér a dropdownhoz) ========== */
  body[data-theme="light"] .account-menu-btn {
    background: rgba(240, 252, 200, 0.85);
    border-color: rgba(122, 146, 0, 0.5);
    color: #7a9200;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
  }
  body[data-theme="light"] .account-menu-btn:hover {
    background: rgba(210, 240, 100, 0.95);
    border-color: #B0CB1F;
    color: #4a6000;
  }
  body[data-theme="light"] .account-dropdown .account-dropdown-panel {
    background: rgba(248, 252, 235, 0.98);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(122, 146, 0, 0.3);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.08), 0 0 30px rgba(176, 203, 31, 0.1);
  }
  body[data-theme="light"] .dropdown-username {
    color: #1a1f00;
  }
  body[data-theme="light"] .dropdown-item,
  body[data-theme="light"] .logout-form-btn {
    color: #2a3a00;
  }
  body[data-theme="light"] .dropdown-item:hover,
  body[data-theme="light"] .logout-form-btn:hover {
    background: rgba(176, 203, 31, 0.18);
    color: #4a6000;
  }
  body[data-theme="light"] .dropdown-divider {
    background: linear-gradient(90deg, transparent, #B0CB1F, transparent);
  }
  body[data-theme="light"] .dropdown-theme-row {
    color: #1a1f00;
  }

  /* ========== AZ EREDETI ACCOUNT.PHP STÍLUSOK (info-grid, items-section, modálok) ========== */
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
    user-select: none;
  }
  .info-card label {
    font-size: 0.8rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: block;
    margin-bottom: 0.3rem;
    user-select: none;
  }
  .info-card .val {
    font-size: 1.1rem;
    font-weight: 500;
    color: var(--text-primary);
    word-break: break-all;
    user-select: none;
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
    user-select: none;
  }
  .edit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 20px var(--orange-glow);
  }
  .items-section {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 1.2rem;
    max-height: 65vh;
    overflow-y: auto;
    backdrop-filter: blur(10px);
  }
  .items-section h2 {
    color: var(--orange-bright);
    margin: 0 0 1rem 0;
    font-size: 1.3rem;
    user-select: none;
  }
  .items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
  }
  .mini-card {
    background: rgba(0,0,0,0.2);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    overflow: hidden;
    transition: 0.3s;
    cursor: pointer;
    user-select: none;
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
    user-select: none;
  }
  .mini-card .price {
    margin: 0.3rem 0 0;
    font-size: 0.85rem;
    color: var(--text-primary);
    opacity: 0.8;
    user-select: none;
  }

  /* ========== Fiók módosító modal (eredeti) ========== */
  .modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
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
    max-width: 420px;
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
  .form-group input {
    width: 100%;
    padding: 0.75rem;
    background: var(--input-bg);
    border: 1px solid var(--glass-border);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 0.95rem;
    box-sizing: border-box;
  }
  .form-group input:focus {
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

  /* ========== EDIT MODAL (termék szerkesztő) – account.php saját ========== */
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
      z-index: 6000;
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
      backdrop-filter: blur(24px);
      border-radius: 32px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 140, 0, 0.2);
      transform: translateY(30px) scale(0.96);
      transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 0.25s ease;
      opacity: 0;
      overflow: hidden;
  }
  .edit-modal.show .edit-modal-content {
      transform: translateY(0) scale(1);
      opacity: 1;
  }
  .edit-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1.25rem 1.8rem;
      background: rgba(255, 140, 0, 0.08);
      border-bottom: 1px solid rgba(255, 140, 0, 0.2);
  }
  .edit-modal-title {
      font-size: 1.3rem;
      font-weight: 600;
      color: var(--orange-bright);
      letter-spacing: -0.3px;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.6rem;
  }
  .edit-modal-title::before {
      content: "✏️";
      font-size: 1.2rem;
      filter: drop-shadow(0 0 4px rgba(255,140,0,0.4));
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
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s;
  }
  .edit-modal-close:hover {
      background: rgba(255, 140, 0, 0.25);
      transform: scale(1.05);
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
      letter-spacing: 0.06em;
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
      transition: all 0.2s;
      outline: none;
  }
  .edit-form-textarea {
      resize: none;
      overflow-y: auto;
      min-height: 120px;
      max-height: 300px;
  }
  .edit-form-input:focus,
  .edit-form-textarea:focus {
      border-color: var(--orange-bright);
      background: rgba(0, 0, 0, 0.7);
      box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.15);
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
      right: 1.2rem;
      color: var(--orange-bright);
      font-weight: 600;
      font-size: 0.9rem;
      pointer-events: none;
      background: transparent;
      backdrop-filter: blur(4px);
  }
  .edit-modal-actions {
      display: flex;
      gap: 1rem;
      margin-top: 2rem;
  }
  .btn-edit-cancel,
  .btn-edit-save {
      flex: 1;
      padding: 0.9rem 1rem;
      border-radius: 40px;
      font-weight: 700;
      font-size: 0.9rem;
      letter-spacing: 0.5px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      border: none;
      font-family: inherit;
  }
  .btn-edit-cancel {
      background: rgba(255, 255, 255, 0.05);
      color: var(--text-muted);
      border: 1px solid rgba(255, 140, 0, 0.2);
  }
  .btn-edit-cancel:hover {
      background: rgba(255, 140, 0, 0.1);
      color: var(--orange-bright);
      border-color: var(--orange-bright);
  }
  .btn-edit-save {
      background: linear-gradient(105deg, #ff9a1f, #ff5500);
      color: #0a0500;
      box-shadow: 0 4px 15px rgba(255, 140, 0, 0.3);
  }
  .btn-edit-save:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(255, 140, 0, 0.4);
  }
  .edit-success-banner {
      margin: 0 1.8rem 1rem 1.8rem;
      background: rgba(0, 200, 100, 0.12);
      border: 1px solid #00c851;
      border-radius: 40px;
      padding: 0.6rem 1rem;
      text-align: center;
      color: #5dffa0;
      font-size: 0.85rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
  }
  #editItemForm {
      background: none !important;
      border: none !important;
      box-shadow: none !important;
  }
  /* ========== PRODUCT MODAL (account.php saját) ========== */
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
  /* Light mode overrides – product modal (account.php) */
  body[data-theme="light"] .product-modal-overlay {
      background: rgba(220, 230, 180, 0.98);
  }
  body[data-theme="light"] .product-modal-card {
      background: rgba(240, 248, 210, 0.98);
  }
  body[data-theme="light"] .product-gallery {
      background: rgba(240, 248, 210, 0.8);
  }
  body[data-theme="light"] .product-main-image-container {
      background: rgba(255, 255, 255, 0.6);
      border: 1px solid rgba(140, 170, 10, 0.25);
  }
  body[data-theme="light"] .product-details {
      background: rgba(240, 248, 210, 0.95);
      border-color: rgba(140, 170, 10, 0.2);
  }
  body[data-theme="light"] .product-title,
  body[data-theme="light"] .product-price {
      color: #7a9200;
  }
  body[data-theme="light"] .product-seller {
      color: rgba(26, 31, 0, 0.7);
  }
  body[data-theme="light"] .product-seller strong {
      color: #1a1f00;
  }
  body[data-theme="light"] .product-date {
      color: rgba(26, 31, 0, 0.5);
  }
  body[data-theme="light"] .product-description {
      background: rgba(255, 255, 255, 0.8);
      color: #1a1f00;
      border-color: rgba(140, 170, 10, 0.2);
  }
  body[data-theme="light"] .product-buy-btn {
      background: linear-gradient(135deg, #B0CB1F, #8aA000);
      color: #1a1f00;
  }
  body[data-theme="light"] .product-thumbnail.active {
      border-color: #B0CB1F;
      box-shadow: 0 0 15px rgba(176, 203, 31, 0.3);
  }
  body[data-theme="light"] .product-thumbnail:hover {
      border-color: #B0CB1F;
  }
  body[data-theme="light"] .gallery-nav {
      background: rgba(240, 248, 210, 0.9);
      border-color: #B0CB1F;
      color: #7a9200;
  }
  body[data-theme="light"] .gallery-nav:hover {
      background: #B0CB1F;
      color: #1a1f00;
  }
  body[data-theme="light"] .product-modal-close {
      background: rgba(176, 203, 31, 0.2);
      border-color: #B0CB1F;
      color: #7a9200;
  }
  body[data-theme="light"] .product-modal-close:hover {
      background: #B0CB1F;
      color: #1a1f00;
  }
  body[data-theme="light"] .product-menu-button {
      background: rgba(240, 248, 210, 0.9);
      border-color: #B0CB1F;
      color: #7a9200;
  }
  body[data-theme="light"] .product-menu-button:hover {
      background: #B0CB1F;
      color: #1a1f00;
  }
  body[data-theme="light"] .product-menu-content {
      background: rgba(244, 252, 220, 0.98);
      border-color: rgba(140, 170, 10, 0.3);
  }
  body[data-theme="light"] .product-menu-item {
      color: #1a1f00;
  }
  body[data-theme="light"] .product-menu-item:hover {
      background: rgba(176, 203, 31, 0.2);
      color: #7a9200;
  }
  /* Light mode – fiók módosító modal */
  body[data-theme="light"] .modal-card {
      background: rgba(248, 252, 230, 0.98);
      border: 1px solid rgba(140, 170, 10, 0.35);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1), 0 0 40px rgba(176, 203, 31, 0.15);
      color: #1a1f00;
  }
  body[data-theme="light"] .modal-title {
      color: #7a9200;
  }
  body[data-theme="light"] .modal-close {
      color: #7a9200;
  }
  body[data-theme="light"] .modal-close:hover {
      color: #1a1f00;
      background: rgba(176, 203, 31, 0.2);
  }
  body[data-theme="light"] .form-group label {
      color: #6a7a20;
  }
  body[data-theme="light"] .form-group input {
      background: rgba(245, 252, 215, 0.95);
      border-color: rgba(140, 170, 10, 0.3);
      color: #1a1f00;
  }
  body[data-theme="light"] .form-group input:focus {
      border-color: #B0CB1F;
      box-shadow: 0 0 0 3px rgba(176, 203, 31, 0.18);
  }
  body[data-theme="light"] .submit-btn {
      background: linear-gradient(135deg, #B0CB1F, #8aA000);
      color: #1a1f00;
  }
  /* Light mode – edit item modal (account.php) */
  body[data-theme="light"] .edit-modal-content {
      background: #f8fce6;
      border: 2px solid #B0CB1F;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1), 0 0 40px rgba(176, 203, 31, 0.2);
      color: #1a1f00;
  }
  body[data-theme="light"] .edit-modal-header {
      background: rgba(176, 203, 31, 0.12);
      border-bottom-color: rgba(140, 170, 10, 0.25);
  }
  body[data-theme="light"] .edit-modal-title {
      color: #7a9200;
  }
  body[data-theme="light"] .edit-modal-close {
      color: #7a9200;
  }
  body[data-theme="light"] .edit-modal-close:hover {
      background: rgba(176, 203, 31, 0.25);
  }
  body[data-theme="light"] .edit-form-input,
  body[data-theme="light"] .edit-form-textarea {
      background: rgba(245, 252, 215, 0.95);
      border-color: rgba(140, 170, 10, 0.3);
      color: #1a1f00;
  }
  body[data-theme="light"] .edit-form-input:focus,
  body[data-theme="light"] .edit-form-textarea:focus {
      border-color: #B0CB1F;
      background: rgba(242, 252, 200, 1);
      box-shadow: 0 0 0 3px rgba(176, 203, 31, 0.18);
  }
  body[data-theme="light"] .edit-price-suffix {
      color: #7a9200;
  }
  body[data-theme="light"] .btn-edit-cancel {
      background: rgba(240, 252, 200, 0.7);
      border: 1px solid rgba(140, 170, 10, 0.4);
      color: #6a7a20;
  }
  body[data-theme="light"] .btn-edit-cancel:hover {
      background: rgba(176, 203, 31, 0.2);
      border-color: #B0CB1F;
      color: #4a6000;
  }
  body[data-theme="light"] .btn-edit-save {
      background: linear-gradient(105deg, #B0CB1F, #8aA000);
      color: #1a1f00;
  }
  /* Light mode – lightbox */
  body[data-theme="light"] .lightbox-overlay {
      background: rgba(240, 245, 220, 0.97);
  }
  body[data-theme="light"] .lightbox-image {
      border-color: #B0CB1F;
  }
  body[data-theme="light"] .lightbox-close {
      background: rgba(240, 245, 220, 0.9);
      border-color: #B0CB1F;
      color: #7a9200;
  }
  body[data-theme="light"] .lightbox-close:hover {
      background: #B0CB1F;
      color: #1a1f00;
  }
  .product-modal-card {
      width: 100vw;
      height: 100vh;
      max-width: none;
      max-height: none;
      background: rgba(5, 5, 5, 0.99);
      position: relative;
      display: grid;
      grid-template-columns: 1.5fr 1fr;
      gap: 1.5rem;
      /* top padding a fix header helye miatt */
      padding: 4rem 2rem 2rem 2rem;
      transform: scale(0.98);
      transition: transform 0.3s ease;
      box-shadow: none;
      border-radius: 0;
      border: none;
      overflow: hidden;
      box-sizing: border-box;
  }
  .product-modal-overlay.active .product-modal-card {
      transform: scale(1);
  }
  .product-modal-header {
      position: absolute;
      top: 1rem;
      right: 1rem;
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
  /* Gallery: kép felül, thumbs alul – sosem görgethető */
  .product-gallery {
      display: flex;
      flex-direction: column;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 20px;
      padding: 1rem;
      overflow: hidden;
      min-height: 0;
  }
  /* Képkonténer: maradék helyet foglalja el */
  .product-main-image-container {
      position: relative;
      width: 100%;
      flex: 1 1 0;
      border-radius: 16px;
      overflow: hidden;
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
      border: 1px solid var(--glass-border);
      border-radius: 16px;
      display: block;
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
  .gallery-nav.prev { left: 12px; }
  .gallery-nav.next { right: 12px; }
  .gallery-nav.hidden { display: none; }
  /* Thumbnails: fix magasság, KÉP ALATT */
  .product-thumbnails {
      display: flex;
      gap: 0.6rem;
      overflow-x: auto;
      padding: 0.5rem 0 0 0;
      flex-shrink: 0;
      height: 72px;
      align-items: center;
  }
  .product-thumbnails::-webkit-scrollbar { height: 3px; }
  .product-thumbnails::-webkit-scrollbar-track { background: transparent; }
  .product-thumbnails::-webkit-scrollbar-thumb {
      background: rgba(255, 140, 0, 0.3);
      border-radius: 2px;
  }
  .product-thumbnail {
      width: 60px;
      height: 60px;
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
      box-shadow: 0 0 12px var(--orange-glow);
  }
  .product-thumbnail img {
      width: 100%;
      height: 100%;
      object-fit: cover;
  }
  /* Details panel: fix magasság, belső görgetés, buy btn alul rögzítve */
  .product-details {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      padding: 1.25rem;
      background: rgba(10, 10, 10, 0.7);
      border-radius: 20px;
      border: 1px solid var(--glass-border);
      overflow: hidden;
      min-height: 0;
  }
  /* Görgethető belső rész (cím, ár, eladó, leírás) */
  .product-details-inner {
      flex: 1 1 0;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
      min-height: 0;
      padding-right: 0.2rem;
  }
  .product-title {
      font-size: 1.5rem;
      color: var(--orange-bright);
      margin: 0;
      word-break: break-word;
      line-height: 1.2;
      font-weight: bold;
  }
  .product-price {
      font-size: 1.7rem;
      font-weight: bold;
      color: var(--orange-bright);
      text-shadow: 0 0 20px var(--orange-glow);
  }
  .product-seller {
      font-size: 0.88rem;
      color: rgba(255, 255, 255, 0.7);
      cursor: pointer;
  }
  .product-seller strong {
      color: var(--orange-bright);
      font-size: 0.95rem;
  }
  .product-date {
      font-size: 0.78rem;
      color: rgba(255, 255, 255, 0.4);
  }
  .product-description {
      font-size: 0.88rem;
      line-height: 1.6;
      color: rgba(255, 255, 255, 0.9);
      background: rgba(0, 0, 0, 0.4);
      border-radius: 12px;
      padding: 0.85rem;
      border: 1px solid var(--glass-border);
      white-space: pre-wrap;
      user-select: text;
      overflow-y: auto;
      flex: 1 1 60px;
      min-height: 60px;
  }
  /* Buy gomb: nem görgethető, alul fix */
  .product-buy-btn {
      background: linear-gradient(135deg, #00c851, #007e33);
      border: none;
      border-radius: 12px;
      padding: 0.75rem 1rem;
      color: white;
      font-size: 0.95rem;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      user-select: none;
      flex-shrink: 0;
      width: 100%;
  }
  .product-buy-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 200, 0, 0.4);
  }
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
  @media (max-width: 900px) {
      .product-modal-card {
          grid-template-columns: 1fr;
          gap: 1rem;
          padding: 3.5rem 1rem 1rem 1rem;
          width: 100vw;
          height: 100vh;
      }
      .product-gallery {
          height: 45%;
          min-height: 0;
          flex-shrink: 0;
      }
      .product-details {
          flex: 1 1 0;
          min-height: 0;
      }
      .product-title { font-size: 1.3rem; }
      .product-price { font-size: 1.5rem; }
      .product-thumbnail { width: 52px; height: 52px; }
  }
  @media (max-width: 600px) {
      .product-modal-card {
          padding: 3rem 0.75rem 0.75rem 0.75rem;
          width: 100vw;
          height: 100vh;
      }
      .product-gallery { height: 40%; }
      .product-details { padding: 0.75rem; }
      .product-title { font-size: 1.1rem; }
      .product-price { font-size: 1.3rem; }
      .product-description { font-size: 0.82rem; padding: 0.65rem; }
      .product-modal-header { top: 0.5rem; right: 0.5rem; }
      .product-modal-close,
      .product-menu-button { width: 34px; height: 34px; font-size: 1.1rem; }
      .product-thumbnail { width: 46px; height: 46px; }
      .product-buy-btn { padding: 0.65rem 0.75rem; font-size: 0.88rem; }
      .gallery-nav { width: 32px; height: 32px; font-size: 1rem; }
  }
  .unselectable {
      user-select: none;
      -webkit-user-select: none;
  }
</style>
</head>
<body data-theme="dark">
<!-- ========== FELSŐ SÁV (TOP BAR) ========== -->
<div class="top-bar">
    <div class="top-bar-left">
        <a href="main.php" class="back-btn unselectable">← Vissza</a>
        <?php if ($isAdmin): ?>
            <a href="admin.php" class="admin-btn unselectable">
                <span class="shield-icon">🛡️</span>
                <span class="button-text">Admin</span>
            </a>
        <?php endif; ?>
    </div>
    <div class="top-bar-right">
        <div class="account-menu">
            <button type="button" class="account-menu-btn unselectable" id="accountMenuBtn">
                <span>⚙️</span>
                <span class="button-text">FIÓK</span>
            </button>
            <div class="account-dropdown" id="accountDropdown">
                <div class="account-dropdown-panel">
                    <div class="dropdown-username unselectable">
                        <?php echo htmlspecialchars($user['username']); ?>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="account.php" class="dropdown-item unselectable">👤 Fiókom</a>
                    <div class="dropdown-divider"></div>
                    <div class="dropdown-theme-row">
                        <span class="dropdown-theme-label unselectable">☀️ Világos mód</span>
                        <label class="theme-switch">
                            <input type="checkbox" id="themeSwitchMain">
                            <span class="theme-switch-track"></span>
                            <span class="theme-switch-thumb"></span>
                        </label>
                    </div>
                    <div class="dropdown-divider"></div>
                    <form method="post" class="logout-form">
                        <button type="submit" name="logout" class="logout-form-btn dropdown-item logout unselectable">
                            🚪 Kijelentkezés
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== FŐ TARTALOM (account.php eredeti) ========== -->
<div class="container">
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

<!-- Fiók módosító modal (eredeti) -->
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

<!-- Termék szerkesztő modal (account.php saját) -->
<div class="edit-modal" id="editItemModal">
    <div class="edit-modal-content">
        <div class="edit-modal-header">
            <div class="edit-modal-title unselectable">Hirdetés szerkesztése</div>
            <button class="edit-modal-close unselectable" onclick="closeEditItemModal()">✕</button>
        </div>
        <?php if ($editSuccess): ?>
            <div class="edit-success-banner unselectable">
                ✓ Módosítás sikeresen mentve!
            </div>
        <?php endif; ?>
        <div class="edit-modal-body">
            <form method="post" id="editItemForm">
                <input type="hidden" name="item_id" id="editItemId">
                <input type="hidden" name="edit_item" value="1">
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable"><i>📌</i> Cím</label>
                    <input class="edit-form-input" type="text" id="edit_title" name="edit_title" maxlength="255" autocomplete="off" required>
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable"><i>📄</i> Leírás</label>
                    <textarea class="edit-form-textarea" id="edit_description" name="edit_description" rows="5" required></textarea>
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label unselectable"><i>💰</i> Ár</label>
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

<!-- Termék modal (részletek megjelenítése) -->
<div class="product-modal-overlay" id="productModal">
    <div class="product-modal-card">
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
            <div class="product-main-image-container">
                <img src="" alt="Termék képe" class="product-main-image" id="productMainImage" style="display: none;">
                <div class="product-no-image-placeholder unselectable" id="productNoImagePlaceholder" style="display: none;">📷 Nincs kép</div>
                <button class="gallery-nav prev unselectable" id="galleryPrev">❮</button>
                <button class="gallery-nav next unselectable" id="galleryNext">❯</button>
            </div>
            <div class="product-thumbnails" id="productThumbnails"></div>
        </div>
        <div class="product-details">
            <div class="product-details-inner">
                <h2 class="product-title unselectable" id="productTitle"></h2>
                <div class="product-price unselectable" id="productPrice"></div>
                <div class="product-seller unselectable" id="productSeller"></div>
                <div class="product-date unselectable" id="productDate"></div>
                <div class="product-description selectable" id="productDescription"></div>
            </div>
            <button class="product-buy-btn unselectable" id="productBuyBtn">🛒 Vásárlás</button>
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
// Téma betöltése localStorage-ból (az account.php-ban is)
const themeLink = document.getElementById('themeStylesheet');
const savedTheme = localStorage.getItem('theme') || 'dark';
themeLink.href = savedTheme === 'light' ? 'theme-light.css' : 'theme-dark.css';
document.body.setAttribute('data-theme', savedTheme);

// Témaváltó kapcsoló kezelése (a fiókmenüben lévő checkbox)
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

// ==================== FIÓK DROPDOWN KATTINTÁSRA (nem hover) ====================
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
    // Ha a felhasználó a dropdownon belülre kattint, ne zárjuk be
    accountDropdown.addEventListener('click', (e) => e.stopPropagation());
    // Kattintás máshova -> bezár
    document.addEventListener('click', closeDropdown);
}

// Fiók módosítás modal (eredeti script)
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
      setTimeout(() => { window.location.reload(); }, 800);
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

// ===================== TERMÉK MODAL ÉS SZERKESZTÉS (account.php saját) =====================
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

// Edit modal elemek
const editItemModal = document.getElementById('editItemModal');
const editItemId = document.getElementById('editItemId');
const editTitle = document.getElementById('edit_title');
const editDesc = document.getElementById('edit_description');
const editPrice = document.getElementById('edit_price');

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
    document.body.style.overflow = '';
}
editItemModal.addEventListener('click', function(e) {
    if (e.target === editItemModal) closeEditItemModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && editItemModal.classList.contains('show')) closeEditItemModal();
});

function setMainImage(index) {
    if (index >= 0 && index < currentProductImages.length && currentProductImages[index]) {
        productMainImage.style.display = 'block';
        productNoImagePlaceholder.style.display = 'none';
        productMainImage.src = currentProductImages[index];
        currentImageIndex = index;
        productMainImage.onload = function() { adjustImageContainerHeight(); };
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
        imageContainer.style.height = 'auto';
        const maxHeight = gallery.clientHeight - (document.querySelector('.product-thumbnails')?.offsetHeight || 80) - 20;
        if (imageContainer.clientHeight > maxHeight) {
            imageContainer.style.height = maxHeight + 'px';
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
            if (isOwner) {
                editBtn.style.display = 'block';
                editBtn.onclick = () => {
                    closeProductModal();
                    openEditItemModal(item.id, item.title, item.description, item.price);
                };
                deleteBtn.style.display = 'block';
                deleteBtn.onclick = () => {
                    if (confirm('Biztosan törlöd ezt a terméket?')) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `<input type="hidden" name="item_id" value="${item.id}"><input type="hidden" name="delete_item" value="1">`;
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
productModal.addEventListener('click', (e) => { if (e.target === productModal) closeProductModal(); });
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
lightboxOverlay.addEventListener('click', (e) => { if (e.target === lightboxOverlay) closeLightbox(); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightboxOverlay.classList.contains('active')) closeLightbox();
});
window.addEventListener('resize', () => {
    if (productModal.classList.contains('active')) adjustImageContainerHeight();
});
document.getElementById('productBuyBtn').addEventListener('click', () => alert('Vásárlás funkció még nem elérhető!'));

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Kattintás a termékekre
document.querySelectorAll('.mini-card').forEach(card => {
    card.addEventListener('click', function(e) {
        const itemId = this.dataset.itemId;
        if (itemId) fetchItemDetails(itemId);
    });
});
</script>
</body>
</html>