<?php
session_start();

// =============================================
// BEJELENTKEZÉS ELLENŐRZÉS
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

require_once 'config.php';

$userId = (int)$_SESSION['user_id'];
$message = '';
$error = '';

// =============================================
// ADATBÁZIS KAPCSOLAT
// =============================================
try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // --- RENDELÉSEK TÁBLA LÉTREHOZÁSA (ha még nem létezik) ---
    $conn->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id CHAR(12) PRIMARY KEY,
            buyer_id INT NOT NULL,
            seller_id INT NOT NULL,
            item_id CHAR(12) NOT NULL,
            status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
            shipping_name VARCHAR(255) NOT NULL,
            shipping_email VARCHAR(255) NOT NULL,
            shipping_phone VARCHAR(50) NOT NULL,
            shipping_zip VARCHAR(20) NOT NULL,
            shipping_city VARCHAR(100) NOT NULL,
            shipping_address VARCHAR(255) NOT NULL,
            payment_method ENUM('cod', 'transfer', 'pickup') NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");

    // Sold oszlop hozzáadása az items táblához, ha még nincs
    $colCheck = $conn->query("SHOW COLUMNS FROM items LIKE 'sold'");
    if ($colCheck->rowCount() === 0) {
        $conn->exec("ALTER TABLE items ADD COLUMN sold BOOLEAN DEFAULT FALSE");
    }

    // =============================================
    // TERMÉK ADATAINAK LEKÉRÉSE
    // =============================================
    $itemId = $_GET['item_id'] ?? '';
    $item = null;

    if (!empty($itemId)) {
        $stmt = $conn->prepare("
            SELECT i.*, u.username AS seller_name, u.email AS seller_email
            FROM items i
            JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if (!$item) {
            $error = 'A termék nem található.';
        } elseif ($item['sold']) {
            $error = 'Ezt a terméket már megvásárolták.';
        } elseif ($item['user_id'] == $userId) {
            $error = 'A saját termékedet nem vásárolhatod meg.';
        }
    } else {
        $error = 'Nincs termék kiválasztva.';
    }

    // =============================================
    // VÁSÁRLÁS FELDOLGOZÁSA (POST)
    // =============================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order']) && $item && !$item['sold']) {
        $shippingName    = trim($_POST['shipping_name'] ?? '');
        $shippingEmail   = trim($_POST['shipping_email'] ?? '');
        $shippingPhone   = trim($_POST['shipping_phone'] ?? '');
        $shippingZip     = trim($_POST['shipping_zip'] ?? '');
        $shippingCity    = trim($_POST['shipping_city'] ?? '');
        $shippingAddress = trim($_POST['shipping_address'] ?? '');
        $paymentMethod   = $_POST['payment_method'] ?? '';
        $notes           = trim($_POST['notes'] ?? '');

        // Validálás
        $errors = [];
        if (empty($shippingName)) $errors[] = 'A név megadása kötelező.';
        if (empty($shippingEmail) || !filter_var($shippingEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Érvénytelen e-mail cím.';
        if (empty($shippingPhone)) $errors[] = 'A telefonszám megadása kötelező.';
        if (empty($shippingZip)) $errors[] = 'Az irányítószám megadása kötelező.';
        if (empty($shippingCity)) $errors[] = 'A város megadása kötelező.';
        if (empty($shippingAddress)) $errors[] = 'A cím megadása kötelező.';
        if (!in_array($paymentMethod, ['cod', 'transfer', 'pickup'])) $errors[] = 'Érvénytelen fizetési mód.';

        if (empty($errors)) {
            // Rendelés ID generálása
            do {
                $orderId = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
                $checkId = $conn->prepare("SELECT COUNT(*) FROM orders WHERE id = ?");
                $checkId->execute([$orderId]);
            } while ($checkId->fetchColumn() > 0);

            // Üzenet ID generálása az értesítéshez
            do {
                $msgId = '';
                $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                for ($i = 0; $i < 25; $i++) {
                    $msgId .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $msgCheck = $conn->prepare("SELECT COUNT(*) FROM uzenetek WHERE id = ?");
                $msgCheck->execute([$msgId]);
            } while ($msgCheck->fetchColumn() > 0);

            try {
                $conn->beginTransaction();

                // Rendelés beszúrása
                $insertOrder = $conn->prepare("
                    INSERT INTO orders (id, buyer_id, seller_id, item_id, status,
                        shipping_name, shipping_email, shipping_phone,
                        shipping_zip, shipping_city, shipping_address,
                        payment_method, notes)
                    VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertOrder->execute([
                    $orderId,
                    $userId,
                    $item['user_id'],
                    $itemId,
                    $shippingName,
                    $shippingEmail,
                    $shippingPhone,
                    $shippingZip,
                    $shippingCity,
                    $shippingAddress,
                    $paymentMethod,
                    $notes
                ]);

                // Termék eladottnak jelölése
                $conn->prepare("UPDATE items SET sold = TRUE WHERE id = ?")->execute([$itemId]);

                // Értesítő üzenet küldése az eladónak
                $paymentLabels = [
                    'cod'      => 'Utánvétel',
                    'transfer' => 'Banki átutalás',
                    'pickup'   => 'Személyes átvétel'
                ];
                $messageText = "📦 Új rendelés érkezett!\n\n"
                    . "Termék: " . $item['title'] . "\n"
                    . "Ár: " . number_format($item['price'], 0, ',', ' ') . " Ft\n"
                    . "Vevő: " . $shippingName . "\n"
                    . "Email: " . $shippingEmail . "\n"
                    . "Telefon: " . $shippingPhone . "\n"
                    . "Cím: " . $shippingZip . " " . $shippingCity . ", " . $shippingAddress . "\n"
                    . "Fizetési mód: " . ($paymentLabels[$paymentMethod] ?? $paymentMethod) . "\n"
                    . "Megjegyzés: " . ($notes ?: '-') . "\n\n"
                    . "Rendelés azonosító: " . $orderId;

                $insertMsg = $conn->prepare("
                    INSERT INTO uzenetek (id, sender_id, receiver_id, message)
                    VALUES (?, ?, ?, ?)
                ");
                $insertMsg->execute([$msgId, $userId, $item['user_id'], $messageText]);

                $conn->commit();

                // Sikeres vásárlás után átirányítás
                header("Location: vasarlas.php?item_id=" . urlencode($itemId) . "&success=1");
                exit();
            } catch (Exception $e) {
                $conn->rollBack();
                $error = 'Hiba történt a rendelés során: ' . $e->getMessage();
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }

    $success = isset($_GET['success']) && $_GET['success'] == '1';

    // Felhasználó adatok (előtöltéshez)
    $userStmt = $conn->prepare("SELECT username, email, profile_picture FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $currentUser = $userStmt->fetch();

    // Admin ellenőrzés
    $adminCheck = $conn->prepare("SELECT COUNT(*) FROM admins WHERE user_id = ?");
    $adminCheck->execute([$userId]);
    $isAdmin = $adminCheck->fetchColumn() > 0;
} catch (PDOException $e) {
    die("Adatbázis hiba: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vásárlás – Cuci's Stuff</title>
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        /* ========== ALAP STÍLUSOK ========== */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --orange-bright: #ff9a1f;
            --orange-mid: #e07800;
            --orange-glow: rgba(255, 140, 0, 0.55);
            --orange-subtle: rgba(255, 140, 0, 0.12);
            --glass-bg: rgba(6, 6, 6, 0.78);
            --glass-border: rgba(255, 140, 0, 0.18);
            --text-primary: #f5f0e8;
            --text-muted: #8a7a65;
            --input-bg: rgba(20, 16, 10, 0.92);
            --shadow-orange: 0 0 40px rgba(255, 120, 0, 0.22);
            --shadow-deep: 0 30px 80px rgba(0, 0, 0, 0.9);
            --body-bg: #000;
        }

        body[data-theme="light"] {
            --orange-bright: #7a9200;
            --orange-mid: #B0CB1F;
            --orange-glow: rgba(176, 203, 31, 0.45);
            --orange-subtle: rgba(176, 203, 31, 0.10);
            --glass-bg: rgba(248, 252, 230, 0.90);
            --glass-border: rgba(140, 170, 10, 0.30);
            --text-primary: #1a1f00;
            --text-muted: #6a7a20;
            --input-bg: rgba(245, 252, 215, 0.95);
            --shadow-orange: 0 0 40px rgba(176, 203, 31, 0.15);
            --shadow-deep: 0 30px 80px rgba(0, 0, 0, 0.10);
            --body-bg: #d8e0b0;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--body-bg);
            color: var(--text-primary);
            transition: background 0.4s, color 0.4s;
        }

        /* Háttér díszítés */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(ellipse 80% 60% at 18% 12%, rgba(255, 100, 0, 0.15) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 85% 80%, rgba(180, 60, 0, 0.1) 0%, transparent 50%),
                radial-gradient(ellipse 100% 100% at 50% 50%, #0d0d0d 0%, #000 100%);
        }

        body[data-theme="light"]::before {
            background:
                radial-gradient(ellipse 80% 60% at 18% 12%, rgba(200, 230, 60, 0.25) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 85% 80%, rgba(160, 200, 20, 0.15) 0%, transparent 50%),
                radial-gradient(ellipse 100% 100% at 50% 50%, #d8e0b0 0%, #c8d0a0 100%);
        }

        .unselectable {
            user-select: none;
            -webkit-user-select: none;
        }

        /* ========== TOP BAR ========== */
        .top-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 1.2rem;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            pointer-events: auto;
        }

        .top-bar-left {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .back-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--orange-glow);
            border-radius: 50px;
            background: rgba(255, 140, 0, 0.12);
            color: var(--orange-bright);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 140, 0, 0.25);
            border-color: var(--orange-bright);
            color: #fff;
        }

        .page-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--orange-bright);
            margin-left: 1rem;
        }

        /* ========== FŐ TARTALOM ========== */
        .main-container {
            position: relative;
            z-index: 1;
            max-width: 800px;
            margin: 90px auto 40px;
            padding: 0 1.5rem;
        }

        /* ========== TERMÉK ÖSSZEFOGLALÓ KÁRTYA ========== */
        .product-summary {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1.5rem;
            align-items: center;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
        }

        .product-image {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .product-image-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            background: rgba(255, 140, 0, 0.1);
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: var(--orange-bright);
            flex-shrink: 0;
        }

        .product-info {
            flex: 1;
        }

        .product-info h2 {
            font-size: 1.3rem;
            color: var(--orange-bright);
            margin-bottom: 0.5rem;
        }

        .product-info .price {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--orange-bright);
            text-shadow: 0 0 15px var(--orange-glow);
            margin-bottom: 0.5rem;
        }

        .product-info .seller {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* ========== ŰRLAP KÁRTYA ========== */
        .form-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-deep), var(--shadow-orange);
        }

        .form-card h3 {
            font-size: 1.2rem;
            color: var(--orange-bright);
            margin-bottom: 1.5rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--glass-border);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--orange-bright);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem 1rem;
            background: var(--input-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.25s ease;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--orange-bright);
            box-shadow: 0 0 0 3px var(--orange-subtle);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.2);
        }

        .form-group select {
            cursor: pointer;
        }

        .form-group select option {
            background: #1a1a1a;
            color: #fff;
        }

        .payment-methods {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .payment-option {
            flex: 1;
            min-width: 150px;
        }

        .payment-option input {
            display: none;
        }

        .payment-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            padding: 1rem;
            background: var(--input-bg);
            border: 2px solid var(--glass-border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .payment-option .payment-icon {
            font-size: 1.8rem;
        }

        .payment-option input:checked+label {
            border-color: var(--orange-bright);
            background: var(--orange-subtle);
            color: var(--orange-bright);
            box-shadow: 0 0 20px var(--orange-glow);
        }

        .payment-option label:hover {
            border-color: var(--orange-bright);
            background: rgba(255, 140, 0, 0.05);
        }

        /* ========== GOMBOK ========== */
        .submit-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--orange-bright), var(--orange-mid));
            color: #000;
            border: none;
            border-radius: 14px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            letter-spacing: 0.03em;
            transition: all 0.3s ease;
            margin-top: 1.5rem;
            box-shadow: 0 4px 20px rgba(255, 140, 0, 0.3);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 140, 0, 0.5);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* ========== ÜZENETEK ========== */
        .message-banner {
            padding: 1rem 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            text-align: center;
        }

        .message-banner.error {
            background: rgba(255, 50, 50, 0.15);
            border: 1px solid #ff4d4d;
            color: #ff8080;
        }

        .message-banner.success {
            background: rgba(0, 200, 100, 0.15);
            border: 1px solid #00c851;
            color: #5dffa0;
        }

        /* ========== SIKEROLDAL ========== */
        .success-container {
            text-align: center;
            padding: 3rem 2rem;
        }

        .success-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .success-title {
            font-size: 1.8rem;
            color: var(--orange-bright);
            margin-bottom: 0.8rem;
        }

        .success-text {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .success-btn {
            display: inline-block;
            padding: 0.8rem 2rem;
            background: var(--orange-bright);
            color: #000;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s ease;
        }

        .success-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 140, 0, 0.4);
        }

        /* ========== RESZPONZÍV ========== */
        @media (max-width: 768px) {
            .product-summary {
                flex-direction: column;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .payment-methods {
                flex-direction: column;
            }

            .page-title {
                display: none;
            }
        }

        /* ========== SCROLLBAR ========== */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #0a0a0a;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 140, 0, 0.3);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 140, 0, 0.5);
        }
    </style>
</head>

<body data-theme="dark">

    <!-- ========== FELSŐ SÁV ========== -->
    <div class="top-bar">
        <div class="top-bar-left">
            <a href="main.php" class="back-btn unselectable">← Vissza a főoldalra</a>
            <span class="page-title unselectable">🛒 Vásárlás</span>
        </div>
    </div>

    <!-- ========== FŐ TARTALOM ========== -->
    <div class="main-container">

        <?php if (!empty($error)): ?>
            <div class="message-banner error unselectable"><?= $error ?></div>
            <div style="text-align:center;margin-top:1rem;">
                <a href="main.php" class="back-btn unselectable" style="display:inline-block;">← Vissza a főoldalra</a>
            </div>

        <?php elseif ($success): ?>
            <div class="form-card">
                <div class="success-container">
                    <div class="success-icon">✅</div>
                    <h2 class="success-title unselectable">Sikeres rendelés!</h2>
                    <p class="success-text unselectable">
                        A rendelésedet rögzítettük. Az eladót értesítettük a vásárlásról.<br>
                        Hamarosan felveszi veled a kapcsolatot a megadott e-mail címen vagy telefonszámon.
                    </p>
                    <a href="main.php" class="success-btn unselectable">Vissza a főoldalra</a>
                </div>
            </div>

        <?php elseif ($item): ?>
            <!-- Termék összefoglaló -->
            <div class="product-summary">
                <?php
                $imgStmt = $conn->prepare("SELECT image_path FROM item_images WHERE item_id = ? AND is_primary = 1 LIMIT 1");
                $imgStmt->execute([$itemId]);
                $primaryImage = $imgStmt->fetchColumn();
                ?>
                <?php if ($primaryImage): ?>
                    <img src="<?= htmlspecialchars($primaryImage) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="product-image">
                <?php else: ?>
                    <div class="product-image-placeholder unselectable">📷</div>
                <?php endif; ?>
                <div class="product-info">
                    <h2 class="unselectable"><?= htmlspecialchars($item['title']) ?></h2>
                    <div class="price unselectable"><?= number_format($item['price'], 0, ',', ' ') ?> Ft</div>
                    <div class="seller unselectable">Eladó: <?= htmlspecialchars($item['seller_name']) ?></div>
                </div>
            </div>

            <!-- Szállítási adatok űrlap -->
            <div class="form-card">
                <h3 class="unselectable">📦 Szállítási adatok</h3>
                <form method="post" id="orderForm" novalidate>
                    <input type="hidden" name="confirm_order" value="1">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="shipping_name">Teljes név *</label>
                            <input type="text" id="shipping_name" name="shipping_name"
                                placeholder="Add meg a neved"
                                value="<?= htmlspecialchars($_POST['shipping_name'] ?? $currentUser['username'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shipping_email">E-mail cím *</label>
                            <input type="email" id="shipping_email" name="shipping_email"
                                placeholder="pelda@email.hu"
                                value="<?= htmlspecialchars($_POST['shipping_email'] ?? $currentUser['email'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shipping_phone">Telefonszám *</label>
                            <input type="tel" id="shipping_phone" name="shipping_phone"
                                placeholder="+36 30 123 4567"
                                value="<?= htmlspecialchars($_POST['shipping_phone'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shipping_zip">Irányítószám *</label>
                            <input type="text" id="shipping_zip" name="shipping_zip"
                                placeholder="Pl. 1234"
                                value="<?= htmlspecialchars($_POST['shipping_zip'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="shipping_city">Város *</label>
                            <input type="text" id="shipping_city" name="shipping_city"
                                placeholder="Pl. Budapest"
                                value="<?= htmlspecialchars($_POST['shipping_city'] ?? '') ?>" required>
                        </div>
                        <div class="form-group full-width">
                            <label for="shipping_address">Utca, házszám *</label>
                            <input type="text" id="shipping_address" name="shipping_address"
                                placeholder="Pl. Példa utca 12."
                                value="<?= htmlspecialchars($_POST['shipping_address'] ?? '') ?>" required>
                        </div>
                        <div class="form-group full-width">
                            <label class="unselectable">Fizetési mód *</label>
                            <div class="payment-methods">
                                <div class="payment-option">
                                    <input type="radio" id="pay_cod" name="payment_method" value="cod"
                                        <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'cod') ? 'checked' : '' ?> required>
                                    <label for="pay_cod">
                                        <span class="payment-icon">🏠</span>
                                        Utánvétel
                                    </label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" id="pay_transfer" name="payment_method" value="transfer"
                                        <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'transfer') ? 'checked' : '' ?>>
                                    <label for="pay_transfer">
                                        <span class="payment-icon">🏦</span>
                                        Banki átutalás
                                    </label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" id="pay_pickup" name="payment_method" value="pickup"
                                        <?= (isset($_POST['payment_method']) && $_POST['payment_method'] === 'pickup') ? 'checked' : '' ?>>
                                    <label for="pay_pickup">
                                        <span class="payment-icon">🤝</span>
                                        Személyes átvétel
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label for="notes">Megjegyzés (opcionális)</label>
                            <textarea id="notes" name="notes" rows="3"
                                placeholder="Pl. kaputelefon kód, emelet, ajtó..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn unselectable">
                        💳 Vásárlás véglegesítése – <?= $item ? number_format($item['price'], 0, ',', ' ') . ' Ft' : '' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- ========== TÉMA KEZELÉS ========== -->
    <script>
        (function() {
            const themeLink = document.getElementById('themeStylesheet');
            const saved = localStorage.getItem('theme') || 'dark';
            themeLink.href = saved === 'light' ? 'theme-light.css' : 'theme-dark.css';
            document.body.setAttribute('data-theme', saved);
        })();
    </script>

</body>

</html>