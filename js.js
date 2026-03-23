// ── TÉMA KEZELÉS ──

/**
 * Téma betöltése és alkalmazása
 * @param {string} theme - 'dark' vagy 'light'
 */
function applyTheme(theme) {
    const link = document.getElementById('themeStylesheet');
    link.href = theme === 'light' ? 'theme-light.css' : 'theme-dark.css';
    localStorage.setItem('theme', theme);
    document.body.setAttribute('data-theme', theme);  // EZ A SOR A LÉNYEG
}

/**
 * Mentett téma betöltése oldal betöltésekor
 */
(function initTheme() {
    const saved = localStorage.getItem('theme') || 'dark';
    applyTheme(saved);
})();

/**
 * Témaváltó gomb eseménykezelője
 */
document.getElementById('themeToggle').addEventListener('click', function () {
    const current = localStorage.getItem('theme') || 'dark';
    applyTheme(current === 'dark' ? 'light' : 'dark');
});


// ── STATUS ÜZENET ──

const status = document.getElementById("statusMessage");
if (status) {
    setTimeout(() => {
        status.style.opacity = "0";
        status.style.transition = "opacity 0.5s ease";
        setTimeout(() => {
            status.remove();
        }, 500);
    }, 3000);
}


// ── REGISZTRÁCIÓS ŰRLAP ──

/**
 * Regisztrációs űrlap megjelenítése
 */
function register() {
    const loginForm = document.getElementById("loginForm");
    if (loginForm) {
        loginForm.remove();
    }

    let registerForm = document.createElement("form");
    registerForm.id = "registerForm";
    registerForm.method = "post";
    registerForm.action = "";
    registerForm.innerHTML = `
        <input type="text" name="felhasznalonev" placeholder="Felhasználónév" required>
        <input type="email" name="email" placeholder="Email cím" required>
        <input type="password" name="jelszo" placeholder="Jelszó" required>
        <input type="password" name="jelszo2" placeholder="Jelszó újra" required>
        <button type="submit" name="register">Regisztráció</button>
        <button type="button" onclick="loginBack()">Vissza a bejelentkezéshez</button>
    `;

    const h1 = document.querySelector('h1');
    h1.insertAdjacentElement('afterend', registerForm);
}

/**
 * Visszatérés a bejelentkezési űrlaphoz
 */
function loginBack() {
    const registerForm = document.getElementById("registerForm");
    if (registerForm) {
        registerForm.remove();
    }

    let loginForm = document.createElement("form");
    loginForm.id = "loginForm";
    loginForm.method = "post";
    loginForm.action = "";
    loginForm.innerHTML = `
        <input type="text" name="felhasznalonev" placeholder="Felhasználónév">
        <input type="password" name="jelszo" placeholder="Jelszó">
        <button type="submit" name="login">Bejelentkezés</button>
        <div class="or-separator"><span>VAGY</span></div>
        <button type="button" onclick="register()">Regisztráció</button>
    `;

    const h1 = document.querySelector('h1');
    h1.insertAdjacentElement('afterend', loginForm);
}