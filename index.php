<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
$status = '';

// Alapértelmezett nézet: bejelentkezés
$mode = 'login';

// Ha már be van jelentkezve, átirányítjuk a főoldalra
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: main.php");
    exit();
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $mode = 'login';
    } elseif (isset($_POST['register'])) {
        $mode = 'register';
    }

    // BEJELENTKEZÉS KEZELÉSE
    if (isset($_POST['login'])) {
        if (!empty($_POST['felhasznalonev']) && !empty($_POST['jelszo'])) {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
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
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        /* ══════════════════════════════════════════
           DARK THEME VARIABLES (alapértelmezett)
           ══════════════════════════════════════════ */
        :root,
        [data-theme="dark"] {
            --orange-bright: #ff9a1f;
            --orange-mid: #e07800;
            --orange-deep: #b35500;
            --orange-glow: rgba(255, 140, 0, 0.55);
            --orange-subtle: rgba(255, 140, 0, 0.12);
            --glass-bg: rgba(6, 6, 6, 0.78);
            --glass-border: rgba(255, 140, 0, 0.18);
            --text-primary: #f5f0e8;
            --input-bg: rgba(20, 16, 10, 0.92);
            --shadow-orange: 0 0 40px rgba(255, 120, 0, 0.22);
            --shadow-deep: 0 30px 80px rgba(0, 0, 0, 0.9);
            --body-bg: #000;
            --scrollbar-track: #0a0a0a;
            --scrollbar-thumb: rgba(255, 120, 0, 0.3);
            --scrollbar-thumb-hover: rgba(255, 140, 0, 0.5);
            --placeholder-color: #5a4c3a;
            --input-focus-bg: rgba(15, 10, 4, 0.95);
            --status-bg-from: rgba(10, 6, 0, 0.98);
            --status-bg-to: rgba(0, 0, 0, 0.92);
            --h1-color: #f5f0e8;
            --h1-shadow: 0 0 10px rgba(255, 140, 0, 0.6), 0 0 30px rgba(255, 80, 0, 0.3), 0 2px 4px rgba(0, 0, 0, 0.8);
            --body-before-bg:
                radial-gradient(ellipse 80% 60% at 18% 12%, #7a3800 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 85% 80%, #3a1800 0%, transparent 50%),
                radial-gradient(ellipse 100% 100% at 50% 50%, #0d0d0d 0%, #000 100%);
            --body-after-bg:
                radial-gradient(ellipse 500px 300px at 15% 10%, rgba(255, 100, 0, 0.18) 0%, transparent 70%),
                radial-gradient(ellipse 400px 400px at 90% 90%, rgba(180, 60, 0, 0.12) 0%, transparent 60%),
                radial-gradient(ellipse 300px 200px at 50% 0%, rgba(255, 140, 0, 0.08) 0%, transparent 60%);
            --orb1-bg: radial-gradient(circle, rgba(255, 140, 0, 0.25) 0%, rgba(255, 80, 0, 0.08) 45%, transparent 70%);
            --orb2-bg: radial-gradient(circle, rgba(180, 60, 0, 0.20) 0%, rgba(100, 30, 0, 0.06) 50%, transparent 70%);
            --status-border: rgba(255, 140, 0, 0.25);
            --status-shadow: 0 1px 0 rgba(255, 255, 255, 0.04) inset, 0 4px 30px rgba(0, 0, 0, 0.8), 0 0 60px rgba(255, 100, 0, 0.06);
            --form-before-bg: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.18), rgba(255, 200, 100, 0.14), transparent);
            --form-after-bg: radial-gradient(ellipse 80% 50% at 50% 0%, rgba(255, 140, 0, 0.07) 0%, transparent 60%);
            --input-border: rgba(255, 140, 0, 0.15);
            --input-shadow: 0 4px 16px rgba(0, 0, 0, 0.6), 0 1px 0 rgba(255, 255, 255, 0.04) inset, 0 -1px 0 rgba(0, 0, 0, 0.5) inset;
            --input-focus-border: rgba(255, 140, 0, 0.5);
            --input-focus-shadow: 0 0 0 3px rgba(255, 120, 0, 0.18), 0 0 20px rgba(255, 100, 0, 0.25), 0 4px 16px rgba(0, 0, 0, 0.6), 0 1px 0 rgba(255, 200, 100, 0.06) inset;
            --input-focus-color: #fff;
            --btn-submit-bg: linear-gradient(180deg, #ffab35 0%, #e07800 50%, #b35500 100%);
            --btn-submit-color: #0a0500;
            --btn-submit-shadow: 0 8px 0 #6b3000, 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 200, 100, 0.3) inset, 0 1px 0 rgba(255, 230, 150, 0.4) inset;
            --btn-submit-hover-bg: linear-gradient(180deg, #ffbe55 0%, #f08800 50%, #c06000 100%);
            --btn-submit-hover-shadow: 0 10px 0 #6b3000, 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 30px rgba(255, 140, 0, 0.3), 0 0 0 1px rgba(255, 200, 100, 0.35) inset;
            --btn-submit-active-shadow: 0 2px 0 #6b3000, 0 8px 20px rgba(0, 0, 0, 0.4);
            --btn-ghost-bg: transparent;
            --btn-ghost-color: #ff9a1f;
            --btn-ghost-border: rgba(255, 140, 0, 0.3);
            --btn-ghost-shadow: 0 2px 12px rgba(0, 0, 0, 0.4);
            --btn-ghost-text-shadow: 0 0 8px rgba(255, 140, 0, 0.4);
            --btn-ghost-hover-bg: rgba(255, 120, 0, 0.08);
            --btn-ghost-hover-border: rgba(255, 140, 0, 0.6);
            --btn-ghost-hover-color: #fff;
            --btn-ghost-hover-shadow: 0 0 20px rgba(255, 100, 0, 0.2), 0 0 0 1px rgba(255, 140, 0, 0.15) inset;
            --btn-ghost-hover-text-shadow: 0 0 12px rgba(255, 160, 0, 0.7);
        }

        /* ══════════════════════════════════════════
           LIGHT THEME VARIABLES
           ══════════════════════════════════════════ */
        [data-theme="light"] {
            --orange-bright: #7a9200;
            --orange-mid: #B0CB1F;
            --orange-deep: #8aA000;
            --orange-glow: rgba(176, 203, 31, 0.45);
            --orange-subtle: rgba(176, 203, 31, 0.10);
            --glass-bg: rgba(248, 252, 230, 0.90);
            --glass-border: rgba(140, 170, 10, 0.30);
            --text-primary: #1a1f00;
            --input-bg: rgba(245, 252, 215, 0.95);
            --shadow-orange: 0 0 40px rgba(176, 203, 31, 0.15);
            --shadow-deep: 0 30px 80px rgba(0, 0, 0, 0.10);
            --body-bg: #d8e0b0;
            --scrollbar-track: #e8f0c0;
            --scrollbar-thumb: rgba(140, 170, 10, 0.35);
            --scrollbar-thumb-hover: rgba(140, 170, 10, 0.6);
            --placeholder-color: #9aaa50;
            --input-focus-bg: rgba(242, 252, 200, 1);
            --status-bg-from: rgba(244, 252, 220, 0.98);
            --status-bg-to: rgba(234, 248, 195, 0.95);
            --h1-color: #7a9200;
            --h1-shadow: 0 0 10px rgba(176, 203, 31, 0.6), 0 0 30px rgba(140, 180, 10, 0.3), 0 2px 4px rgba(0, 0, 0, 0.2);
            --body-before-bg:
                radial-gradient(ellipse 80% 60% at 18% 12%, rgba(200, 230, 60, 0.35) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 85% 80%, rgba(160, 200, 20, 0.20) 0%, transparent 50%),
                radial-gradient(ellipse 100% 100% at 50% 50%, #d8e0b0 0%, #c8d0a0 100%);
            --body-after-bg:
                radial-gradient(ellipse 500px 300px at 15% 10%, rgba(176, 203, 31, 0.12) 0%, transparent 70%),
                radial-gradient(ellipse 400px 400px at 90% 90%, rgba(140, 180, 10, 0.09) 0%, transparent 60%),
                radial-gradient(ellipse 300px 200px at 50% 0%, rgba(200, 220, 50, 0.08) 0%, transparent 60%);
            --orb1-bg: radial-gradient(circle, rgba(176, 203, 31, 0.20) 0%, rgba(140, 180, 10, 0.06) 45%, transparent 70%);
            --orb2-bg: radial-gradient(circle, rgba(160, 200, 20, 0.15) 0%, rgba(100, 140, 5, 0.04) 50%, transparent 70%);
            --status-border: rgba(140, 170, 10, 0.28);
            --status-shadow: 0 1px 0 rgba(255, 255, 255, 0.85) inset, 0 4px 30px rgba(0, 0, 0, 0.05), 0 0 60px rgba(176, 203, 31, 0.05);
            --form-before-bg: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.85), rgba(210, 240, 100, 0.4), transparent);
            --form-after-bg: radial-gradient(ellipse 80% 50% at 50% 0%, rgba(176, 203, 31, 0.07) 0%, transparent 60%);
            --input-border: rgba(140, 170, 10, 0.22);
            --input-shadow: 0 4px 16px rgba(0, 0, 0, 0.05), 0 1px 0 rgba(255, 255, 255, 0.95) inset, 0 -1px 0 rgba(0, 0, 0, 0.03) inset;
            --input-focus-border: rgba(140, 170, 10, 0.55);
            --input-focus-shadow: 0 0 0 3px rgba(176, 203, 31, 0.15), 0 0 20px rgba(176, 203, 31, 0.12), 0 4px 16px rgba(0, 0, 0, 0.05), 0 1px 0 rgba(255, 255, 255, 0.9) inset;
            --input-focus-color: #1a1f00;
            --btn-submit-bg: linear-gradient(180deg, #d4e840 0%, #B0CB1F 50%, #8aA000 100%);
            --btn-submit-color: #1a1f00;
            --btn-submit-shadow: 0 8px 0 #607000, 0 12px 30px rgba(0, 0, 0, 0.14), 0 0 0 1px rgba(220, 255, 80, 0.35) inset, 0 1px 0 rgba(240, 255, 150, 0.5) inset;
            --btn-submit-hover-bg: linear-gradient(180deg, #e0f050 0%, #c4df25 50%, #9ab800 100%);
            --btn-submit-hover-shadow: 0 10px 0 #607000, 0 15px 40px rgba(0, 0, 0, 0.18), 0 0 30px rgba(176, 203, 31, 0.25), 0 0 0 1px rgba(220, 255, 80, 0.4) inset;
            --btn-submit-active-shadow: 0 2px 0 #607000, 0 8px 20px rgba(0, 0, 0, 0.12);
            --btn-ghost-bg: rgba(240, 252, 200, 0.55);
            --btn-ghost-color: #7a9200;
            --btn-ghost-border: rgba(140, 170, 10, 0.32);
            --btn-ghost-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            --btn-ghost-text-shadow: none;
            --btn-ghost-hover-bg: rgba(210, 240, 100, 0.35);
            --btn-ghost-hover-border: rgba(140, 170, 10, 0.55);
            --btn-ghost-hover-color: #507000;
            --btn-ghost-hover-shadow: 0 0 20px rgba(176, 203, 31, 0.14), 0 0 0 1px rgba(176, 203, 31, 0.14) inset;
            --btn-ghost-hover-text-shadow: none;
        }

        /* ══════════════════════════════════════════
           BASE RESET & LAYOUT
           ══════════════════════════════════════════ */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            -webkit-font-smoothing: antialiased;
        }

        body {
            min-height: 100vh;
            font-family: 'Frutiger', 'Nunito', 'Helvetica Neue', sans-serif;
            background: var(--body-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            padding: 20px;
            padding-top: 70px;
            position: relative;
            overflow-x: hidden;
            transition: background 0.4s ease, color 0.4s ease;
        }

        body::before,
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            transition: background 0.4s ease;
        }

        body::before {
            background: var(--body-before-bg);
            z-index: 0;
        }

        body::after {
            background: var(--body-after-bg);
            z-index: 0;
        }

        /* ── NOISE GRAIN ── */
        .noise {
            position: fixed;
            inset: -50%;
            width: 200%;
            height: 200%;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.08'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 1;
            opacity: 0.5;
            mix-blend-mode: overlay;
        }

        /* ── FLOATING ORBS ── */
        .orb-1 {
            position: fixed;
            top: -80px;
            left: -80px;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
            background: var(--orb1-bg);
            animation: orbPulse 8s ease-in-out infinite alternate;
            transition: background 0.4s ease;
        }

        .orb-2 {
            position: fixed;
            bottom: -120px;
            right: -120px;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
            background: var(--orb2-bg);
            animation: orbPulse 11s ease-in-out infinite alternate-reverse;
            transition: background 0.4s ease;
        }

        @keyframes orbPulse {
            from {
                transform: scale(1) translate(0, 0);
                opacity: 0.8;
            }

            to {
                transform: scale(1.15) translate(20px, 15px);
                opacity: 1;
            }
        }

        /* ── THEME TOGGLE BUTTON ── */
        #themeToggle {
            position: fixed;
            top: 14px;
            right: 20px;
            z-index: 1001;
            background: transparent;
            border: 1px solid var(--glass-border);
            color: var(--orange-bright);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 0;
            letter-spacing: 0;
            text-transform: none;
            font-weight: 400;
            transition: background 0.25s, border-color 0.25s, box-shadow 0.25s;
            box-shadow: none;
        }

        #themeToggle:hover {
            background: var(--orange-subtle);
            border-color: var(--orange-bright);
            box-shadow: 0 0 14px var(--orange-glow);
            transform: none;
        }

        #themeToggle::after {
            display: none;
        }

        /* Sun icon: visible in dark mode, hidden in light */
        [data-theme="dark"] .icon-sun {
            display: inline;
        }

        [data-theme="dark"] .icon-moon {
            display: none;
        }

        [data-theme="light"] .icon-sun {
            display: none;
        }

        [data-theme="light"] .icon-moon {
            display: inline;
        }

        /* fallback (before JS runs, :root = dark) */
        .icon-sun {
            display: inline;
        }

        .icon-moon {
            display: none;
        }

        /* ── TOP STATUS BAR ── */
        .login-status {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 14px 0;
            text-align: center;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--orange-bright);
            z-index: 1000;
            background: linear-gradient(180deg, var(--status-bg-from) 0%, var(--status-bg-to) 100%);
            border-bottom: 1px solid var(--status-border);
            box-shadow: var(--status-shadow);
            text-shadow: 0 0 12px var(--orange-glow), 0 0 30px rgba(255, 140, 0, 0.3);
            transition: background 0.4s ease;
        }

        /* ── HEADING ── */
        h1 {
            font-size: 2.6rem;
            font-weight: 300;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: var(--h1-color);
            text-shadow: var(--h1-shadow);
            position: relative;
            z-index: 2;
            margin-bottom: 30px;
            user-select: none;
            animation: titleFadeIn 0.8s ease both;
            transition: color 0.4s ease, text-shadow 0.4s ease;
        }

        h1 span {
            color: var(--orange-bright);
            font-weight: 700;
        }

        @keyframes titleFadeIn {
            from {
                opacity: 0;
                transform: translateY(-12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── GLASSMORPHIC CARD ── */
        form {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 380px;
            padding: 42px 48px 38px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-radius: 24px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow-deep), var(--shadow-orange), 0 0 0 1px rgba(255, 255, 255, 0.03) inset, 0 1px 0 rgba(255, 255, 255, 0.05) inset;
            animation: cardRise 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: 0.1s;
            transition: background 0.4s ease, border-color 0.4s ease, box-shadow 0.4s ease;
        }

        @keyframes cardRise {
            from {
                opacity: 0;
                transform: translateY(24px) scale(0.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 8%;
            width: 84%;
            height: 1px;
            border-radius: 50%;
            pointer-events: none;
            background: var(--form-before-bg);
            transition: background 0.4s ease;
        }

        form::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 24px;
            pointer-events: none;
            background: var(--form-after-bg);
            transition: background 0.4s ease;
        }

        /* ── INPUTS ── */
        input {
            padding: 14px 20px;
            border-radius: 50px;
            outline: none;
            font-size: 0.95rem;
            font-family: inherit;
            background: var(--input-bg);
            color: var(--text-primary);
            border: 1px solid var(--input-border);
            box-shadow: var(--input-shadow);
            transition: all 0.25s ease;
            position: relative;
            z-index: 1;
        }

        input::placeholder {
            font-style: italic;
            font-size: 0.9rem;
            color: var(--placeholder-color);
        }

        input:focus {
            border-color: var(--input-focus-border);
            background: var(--input-focus-bg);
            box-shadow: var(--input-focus-shadow);
            color: var(--input-focus-color);
        }

        /* ── BUTTONS ── */
        button {
            padding: 15px;
            border-radius: 50px;
            border: none;
            font-size: 0.9rem;
            font-family: inherit;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.22s ease;
            position: relative;
            overflow: hidden;
        }

        button::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.12), transparent);
            transition: left 0.45s ease;
            pointer-events: none;
        }

        button:hover::after {
            left: 160%;
        }

        button[type="submit"] {
            background: var(--btn-submit-bg);
            color: var(--btn-submit-color);
            box-shadow: var(--btn-submit-shadow);
        }

        button[type="submit"]:hover {
            background: var(--btn-submit-hover-bg);
            box-shadow: var(--btn-submit-hover-shadow);
            transform: translateY(-2px);
        }

        button[type="submit"]:active {
            box-shadow: var(--btn-submit-active-shadow);
            transform: translateY(6px);
        }

        button[type="button"] {
            background: var(--btn-ghost-bg);
            color: var(--btn-ghost-color);
            border: 1px solid var(--btn-ghost-border);
            box-shadow: var(--btn-ghost-shadow);
            text-shadow: var(--btn-ghost-text-shadow);
        }

        button[type="button"]:hover {
            background: var(--btn-ghost-hover-bg);
            border-color: var(--btn-ghost-hover-border);
            color: var(--btn-ghost-hover-color);
            box-shadow: var(--btn-ghost-hover-shadow);
            text-shadow: var(--btn-ghost-hover-text-shadow);
        }

        /* ── OR SEPARATOR ── */
        .or-separator {
            display: flex;
            align-items: center;
            margin: 6px 0 2px;
            position: relative;
            color: transparent;
        }

        .or-separator::before,
        .or-separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--orange-mid), var(--orange-mid), transparent);
            opacity: 0.3;
        }

        .or-separator span {
            padding: 0 14px;
            font-size: 0.68rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--orange-bright);
            opacity: 0.5;
            position: relative;
            z-index: 1;
        }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
        }

        /* ── SELECTION ── */
        ::selection {
            background: var(--orange-glow);
            color: #fff;
        }

        /* ── UNSELECTABLE ── */
        .unselectable {
            user-select: none;
            -webkit-user-select: none;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 1200px) {
            body {
                padding: 24px;
                padding-top: 80px;
            }

            h1 {
                font-size: 3.2rem;
                margin-bottom: 40px;
                letter-spacing: 8px;
            }

            form {
                max-width: 680px;
                width: 100%;
                padding: 60px 56px 54px;
                gap: 28px;
                border-radius: 36px;
            }

            input {
                padding: 22px 28px;
                font-size: 1.3rem;
                border-radius: 70px;
            }

            input::placeholder {
                font-size: 1.2rem;
            }

            button {
                padding: 24px;
                font-size: 1.3rem;
                letter-spacing: 4px;
                border-radius: 70px;
            }

            .or-separator {
                margin: 18px 0 12px;
            }

            .or-separator span {
                font-size: 0.9rem;
                padding: 0 24px;
            }
        }

        @media (min-width: 1201px) and (max-width: 1600px) {
            body {
                padding-top: 70px;
            }

            form {
                max-width: 520px;
                padding: 48px 50px 44px;
            }

            h1 {
                font-size: 2.8rem;
            }

            input {
                padding: 18px 24px;
                font-size: 1.1rem;
            }

            button {
                padding: 18px;
                font-size: 1.1rem;
            }
        }

        @media (min-width: 1601px) {
            form {
                max-width: 480px;
                padding: 42px 48px 38px;
            }

            h1 {
                font-size: 2.6rem;
            }

            input {
                padding: 14px 20px;
                font-size: 0.95rem;
            }

            button {
                padding: 15px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 400px) {
            body {
                padding: 16px;
                padding-top: 70px;
            }

            h1 {
                font-size: 2.4rem;
                margin-bottom: 30px;
                letter-spacing: 5px;
            }

            form {
                max-width: 100%;
                padding: 36px 24px 32px;
                gap: 20px;
                border-radius: 28px;
            }

            input {
                padding: 18px 22px;
                font-size: 1.1rem;
            }

            button {
                padding: 20px;
                font-size: 1.1rem;
            }
        }
    </style>

    <!-- FOUC megelőzése: téma alkalmazása a DOM renderelés előtt -->
    <script>
        (function() {
            var saved = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>

<body>

    <div class="noise"></div>
    <div class="orb-1"></div>
    <div class="orb-2"></div>

    <button id="themeToggle" title="Témaváltás" type="button">
        <span class="icon-sun">☀️</span>
        <span class="icon-moon">🌙</span>
    </button>

    <?php if (!empty($status)): ?>
        <div class="login-status" id="statusMessage"><?php echo htmlspecialchars($status); ?></div>
    <?php endif; ?>

    <h1>Cuci's <span>Stuff</span></h1>

    <?php if ($mode === 'login'): ?>
        <form action="" method="post" id="loginForm">
            <input type="text" name="felhasznalonev" placeholder="Felhasználónév vagy email"
                value="<?php echo isset($_POST['felhasznalonev']) ? htmlspecialchars($_POST['felhasznalonev']) : ''; ?>">
            <input type="password" name="jelszo" placeholder="Jelszó">
            <button type="submit" name="login">Bejelentkezés</button>
            <div class="or-separator"><span>VAGY</span></div>
            <button type="button" onclick="register()">Regisztráció</button>
        </form>
    <?php elseif ($mode === 'register'): ?>
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

    <script>
        // ── TÉMA KEZELÉS ──

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
        }

        (function initTheme() {
            var saved = localStorage.getItem('theme') || 'dark';
            applyTheme(saved);
        })();

        document.getElementById('themeToggle').addEventListener('click', function() {
            var current = localStorage.getItem('theme') || 'dark';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });

        // ── STATUS ÜZENET ──

        var statusEl = document.getElementById("statusMessage");
        if (statusEl) {
            setTimeout(function() {
                statusEl.style.opacity = "0";
                statusEl.style.transition = "opacity 0.5s ease";
                setTimeout(function() {
                    statusEl.remove();
                }, 500);
            }, 3000);
        }

        // ── REGISZTRÁCIÓS ŰRLAP ──

        function register() {
            var loginForm = document.getElementById("loginForm");
            if (loginForm) loginForm.remove();

            var registerForm = document.createElement("form");
            registerForm.id = "registerForm";
            registerForm.method = "post";
            registerForm.action = "";
            registerForm.innerHTML =
                '<input type="text" name="felhasznalonev" placeholder="Felhasználónév" required>' +
                '<input type="email" name="email" placeholder="Email cím" required>' +
                '<input type="password" name="jelszo" placeholder="Jelszó" required>' +
                '<input type="password" name="jelszo2" placeholder="Jelszó újra" required>' +
                '<button type="submit" name="register">Regisztráció</button>' +
                '<button type="button" onclick="loginBack()">Vissza a bejelentkezéshez</button>';

            document.querySelector('h1').insertAdjacentElement('afterend', registerForm);
        }

        function loginBack() {
            var registerForm = document.getElementById("registerForm");
            if (registerForm) registerForm.remove();

            var loginForm = document.createElement("form");
            loginForm.id = "loginForm";
            loginForm.method = "post";
            loginForm.action = "";
            loginForm.innerHTML =
                '<input type="text" name="felhasznalonev" placeholder="Felhasználónév">' +
                '<input type="password" name="jelszo" placeholder="Jelszó">' +
                '<button type="submit" name="login">Bejelentkezés</button>' +
                '<div class="or-separator"><span>VAGY</span></div>' +
                '<button type="button" onclick="register()">Regisztráció</button>';

            document.querySelector('h1').insertAdjacentElement('afterend', loginForm);
        }
    </script>

</body>

</html>