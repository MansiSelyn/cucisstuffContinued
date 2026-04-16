<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

// Adatbázis kapcsolat (ugyanaz, mint main.php-ban)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "cucidb";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $userId = $_SESSION['user_id'];

    // Felhasználó adatainak lekérése
    $stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Felhasználó termékeinek lekérése
    $itemStmt = $conn->prepare("
        SELECT i.id, i.title, i.price, 
        (SELECT image_path FROM item_images WHERE item_id = i.id AND is_primary = 1 LIMIT 1) as primary_image
        FROM items i WHERE i.user_id = ? ORDER BY i.created_at DESC
    ");
    $itemStmt->execute([$userId]);
    $userItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Adatbázis hiba: " . $e->getMessage());
}

// AJAX feldolgozás (ha POST kérés érkezik)
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
                    $_SESSION['username'] = $newUsername; // Session frissítése
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
<link rel="stylesheet" href="styles.css">
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
  .header h1 { margin: 0; color: var(--orange-bright); font-size: 1.6rem; }
  .back-btn { padding: 0.5rem 1rem; background: var(--orange-subtle); border: 1px solid var(--orange-bright); border-radius: 8px; color: var(--orange-bright); text-decoration: none; font-weight: 600; transition: 0.3s; }
  .back-btn:hover { background: var(--orange-bright); color: #000; }
  
  .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
  .info-card { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 12px; padding: 1.2rem; backdrop-filter: blur(10px); }
  .info-card label { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; }
  .info-card .val { font-size: 1.1rem; font-weight: 500; color: var(--text-primary); word-break: break-all; }
  
  .edit-btn { padding: 0.7rem 1.2rem; background: linear-gradient(135deg, var(--orange-bright), var(--orange-mid)); color: #000; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.3s; margin-top: auto; }
  .edit-btn:hover { transform: translateY(-2px); box-shadow: 0 0 20px var(--orange-glow); }

  .items-section { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: 16px; padding: 1.2rem; max-height: 65vh; overflow-y: auto; backdrop-filter: blur(10px); }
  .items-section h2 { color: var(--orange-bright); margin: 0 0 1rem 0; font-size: 1.3rem; }
  .items-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
  .mini-card { background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); border-radius: 12px; overflow: hidden; transition: 0.3s; }
  .mini-card:hover { border-color: var(--orange-bright); transform: translateY(-3px); }
  .mini-card img { width: 100%; height: 140px; object-fit: cover; background: var(--placeholder-bg); }
  .mini-card .info { padding: 0.7rem; }
  .mini-card .title { margin: 0; font-size: 0.9rem; color: var(--orange-bright); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .mini-card .price { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--text-primary); opacity: 0.8; }

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
</style>
</head>
<body data-theme="dark">
<div class="container">
  <div class="header">
    <h1>Fiókom</h1>
    <a href="main.php" class="back-btn">← Vissza</a>
  </div>

  <div class="info-grid">
    <div class="info-card">
      <label>Felhasználónév</label>
      <div class="val"><?= htmlspecialchars($user['username']) ?></div>
    </div>
    <div class="info-card">
      <label>E-mail cím</label>
      <div class="val"><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div class="info-card" style="display: flex; flex-direction: column; justify-content: center;">
      <button class="edit-btn" onclick="openModal()">✏️ Fiók módosítása</button>
    </div>
  </div>

  <div class="items-section">
    <h2>Hirdetéseim (<?= count($userItems) ?>)</h2>
    <?php if (empty($userItems)): ?>
      <p style="text-align:center; opacity:0.6; padding: 2rem 0;">Még nem adtál fel hirdetést.</p>
    <?php else: ?>
      <div class="items-grid">
        <?php foreach ($userItems as $item): ?>
          <div class="mini-card">
            <?php if ($item['primary_image']): ?>
              <img src="<?= htmlspecialchars($item['primary_image']) ?>" alt="Kép">
            <?php else: ?>
              <div style="height:140px; background:var(--placeholder-bg); display:flex; align-items:center; justify-content:center; color:var(--orange-bright); font-size:2rem;">📷</div>
            <?php endif; ?>
            <div class="info">
              <p class="title"><?= htmlspecialchars($item['title']) ?></p>
              <p class="price"><?= number_format($item['price'], 0, ',', ' ') ?> Ft</p>
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
    <button class="modal-close" onclick="closeModal()">✕</button>
    <h3 class="modal-title">Adatok módosítása</h3>
    <div id="modalStatus" class="status-msg"></div>
    <form id="editForm" method="POST">
      <div class="form-group">
        <label for="username">Felhasználónév</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
      </div>
      <div class="form-group">
        <label for="email">E-mail cím</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
      </div>
      <div class="form-group">
        <label for="password">Új jelszó <span style="opacity:0.6">(hagyd üresen, ha nem változtatod)</span></label>
        <input type="password" id="password" name="password" placeholder="••••••">
      </div>
      <input type="hidden" name="update_account" value="1">
      <button type="submit" class="submit-btn" id="submitBtn">Mentés</button>
    </form>
  </div>
</div>

<script>
// Téma betöltése localStorage-ból
const themeLink = document.getElementById('themeStylesheet');
const savedTheme = localStorage.getItem('theme') || 'dark';
themeLink.href = savedTheme === 'light' ? 'theme-light.css' : 'theme-dark.css';
document.body.setAttribute('data-theme', savedTheme);

// Modal kezelés
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

// AJAX validáció és mentés
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
        window.location.reload(); // Frissítés a session és a megjelenített adatok miatt
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
</script>
</body>
</html>