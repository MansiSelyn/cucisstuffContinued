// Status üzenet automatikus eltüntetése 3 másodperc után
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

/**
 * Regisztrációs űrlap megjelenítése
 * Eltávolítja a bejelentkezési űrlapot és létrehozza a regisztrációs űrlapot
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
 * Eltávolítja a regisztrációs űrlapot és visszaállítja a bejelentkezési űrlapot
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

// Az afterLogin függvényt eltávolítottuk, mert a szerveroldali átirányítás megoldja