<?php 
session_start();
$status = '';

// Alapértelmezett nézet: bejelentkezés
$mode = 'login';

// Ha már be van jelentkezve, átirányítjuk a főoldalra
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: main.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $mode = 'login';
    } elseif (isset($_POST['register'])) {
        $mode = 'register';
    }

    // BEJELENTKEZÉS KEZELÉSE
    if (isset($_POST['login'])) {
        if (!empty($_POST['felhasznalonev']) && !empty($_POST['jelszo'])) {
            $conn = new mysqli('localhost', 'root', '', 'cucidb');
            if ($conn->connect_error) {
                $status = "Adatbázis hiba";
            } else {
                $stmt = $conn->prepare("SELECT users.id, users.username, passwords.password_hash 
                                       FROM users 
                                       JOIN passwords ON users.password_id = passwords.id 
                                       WHERE users.email = ? OR users.username = ?");
                $stmt->bind_param("ss", $_POST['felhasznalonev'], $_POST['felhasznalonev']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $row = $result->fetch_assoc();
                    if (password_verify($_POST['jelszo'], $row['password_hash'])) {
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['logged_in'] = true;
                        header("Location: main.php");
                        exit();
                    } else {
                        $status = "Hibás jelszó";
                    }
                } else {
                    $status = "Nem létező felhasználó";
                }
                $stmt->close();
                $conn->close();
            }
        } else {
            $status = "Hiányzó felhasználónév/email vagy jelszó";
        }
    }
    
    // REGISZTRÁCIÓ KEZELÉSE
    if (isset($_POST['register'])) {
        if (empty($_POST['felhasznalonev']) || empty($_POST['email']) || empty($_POST['jelszo']) || empty($_POST['jelszo2'])) {
            $status = "Minden mező kitöltése kötelező";
        } elseif ($_POST['jelszo'] !== $_POST['jelszo2']) {
            $status = "A jelszavak nem egyeznek";
        } elseif (strpos($_POST['email'], '@') === false) {
            $status = "Érvénytelen email cím";
        } else {
            $conn = new mysqli('localhost', 'root', '', 'cucidb');
            if ($conn->connect_error) {
                $status = "Adatbázis hiba";
            } else {
                $stmt = $conn->prepare("SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->bind_param("ss", $_POST['email'], $_POST['felhasznalonev']);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if ($row['email'] == $_POST['email']) {
                        $status = "Email már foglalt";
                    } else {
                        $status = "Felhasználónév már foglalt";
                    }
                } else {
                    $hash = password_hash($_POST['jelszo'], PASSWORD_DEFAULT);
                    
                    $stmt = $conn->prepare("INSERT INTO passwords (password_hash) VALUES (?)");
                    $stmt->bind_param("s", $hash);
                    $stmt->execute();
                    $password_id = $stmt->insert_id;
                    
                    $stmt = $conn->prepare("INSERT INTO users (email, username, password_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssi", $_POST['email'], $_POST['felhasznalonev'], $password_id);
                    
                    if ($stmt->execute()) {
                        $mode = 'login';
                        $status = "Sikeres regisztráció";
                    } else {
                        $status = "Regisztrációs hiba";
                    }
                }
                $stmt->close();
                $conn->close();
            }
        }
    }
} 
?>

<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <title>Cuci's Stuff - Bejelentkezés</title>
    <!-- Alap stílusok -->
    <link rel="stylesheet" href="styles.css">
    <!-- Aktív téma — JS felülírja href-jét azonnal, de dark az alapértelmezett FOUC ellen -->
    <link rel="stylesheet" id="themeStylesheet" href="theme-dark.css">
    <script>
        // FOUC (villanás) megelőzése: téma alkalmazása a DOM renderelés előtt
        (function() {
            const saved = localStorage.getItem('theme') || 'dark';
            document.getElementById('themeStylesheet').href =
                saved === 'light' ? 'theme-light.css' : 'theme-dark.css';
        })();
    </script>
</head>

<body>

    <!-- Háttér elemek -->
    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

    <!-- Témaváltó gomb -->
    <button id="themeToggle" title="Témaváltás" type="button">
        <span class="icon-sun">☀️</span>
        <span class="icon-moon">🌙</span>
    </button>

    <?php if (!empty($status)): ?>
        <div class="login-status" id="statusMessage"><?php echo htmlspecialchars($status); ?></div>
    <?php endif; ?>

    <h1>Cuci's Stuff</h1>

    <?php if ($mode === 'login'): ?>
        <!-- BEJELENTKEZÉSI ŰRLAP -->
        <form action="" method="post" id="loginForm">
            <input type="text" name="felhasznalonev" placeholder="Felhasználónév vagy email" 
                   value="<?php echo isset($_POST['felhasznalonev']) ? htmlspecialchars($_POST['felhasznalonev']) : ''; ?>">
            <input type="password" name="jelszo" placeholder="Jelszó">
            <button type="submit" name="login">Bejelentkezés</button>
            <div class="or-separator"><span>VAGY</span></div>
            <button type="button" onclick="register()">Regisztráció</button>
        </form>
    <?php elseif ($mode === 'register'): ?>
        <!-- REGISZTRÁCIÓS ŰRLAP -->
        <form action="" method="post" id="registerForm">
            <input type="text" name="felhasznalonev" placeholder="Felhasználónév" 
                   value="<?php echo isset($_POST['felhasznalonev']) ? htmlspecialchars($_POST['felhasznalonev']) : ''; ?>">
            <input type="email" name="email" placeholder="Email" 
                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            <input type="password" name="jelszo" placeholder="Jelszó">
            <input type="password" name="jelszo2" placeholder="Jelszó megerősítése">
            <button type="submit" name="register">Regisztráció</button>
            <button type="button" onclick="window.location.href=''">Vissza a bejelentkezéshez</button>
        </form>
    <?php endif; ?>

    <script src="js.js"></script>
</body>

</html>