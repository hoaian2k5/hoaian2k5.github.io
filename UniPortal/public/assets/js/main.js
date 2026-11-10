/**
 * UniPortal – Login Page JavaScript
 * Handles: password show/hide toggle, submit button loading state.
 * No dependencies. Runs after DOMContentLoaded.
 */

document.addEventListener('DOMContentLoaded', function () {

    // ── Password visibility toggle ──────────────────────────────
    const toggleBtn  = document.getElementById('togglePassword');
    const pwdField   = document.getElementById('password');
    const eyeImg     = document.getElementById('eyeIcon');

    // Two eye-state SVG paths (inline so no extra network request needed)
    const EYE_OPEN   = 'assets/img/icon-eye.svg';   // relative to public/
    const EYE_CLOSED = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="15.75" height="12.25" viewBox="0 0 16 16" fill="none"><path d="M2 2L14 14M6.5 6.68A3 3 0 0 0 9.32 9.5M4.15 4.25C2.8 5.17 1.74 6.45 1 8c1.5 3.33 4.5 5.5 7 5.5 1.26 0 2.47-.4 3.5-1.08M6.76 2.68A7.2 7.2 0 0 1 8 2.5c2.5 0 5.5 2.17 7 5.5a13 13 0 0 1-1.67 2.58" stroke="%2364748b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    if (toggleBtn && pwdField && eyeImg) {
        toggleBtn.addEventListener('click', function () {
            const isHidden = pwdField.type === 'password';
            pwdField.type  = isHidden ? 'text' : 'password';
            eyeImg.src     = isHidden ? EYE_CLOSED : EYE_OPEN;
            toggleBtn.setAttribute('aria-label',
                isHidden ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'
            );
        });
    }

    // ── Submit button loading state ─────────────────────────────
    const form      = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            // Basic client-side guard: don't submit if fields are empty
            const username = document.getElementById('username')?.value.trim();
            const password = pwdField?.value;

            if (!username || !password) {
                return; // let PHP handle the error message
            }

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML =
                '<span style="display:inline-block;width:14px;height:14px;'
              + 'border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;'
              + 'border-radius:50%;animation:spin .7s linear infinite;"></span>'
              + '<span>Đang đăng nhập…</span>';
        });
    }

    // ── Spinner keyframes (injected once) ──────────────────────
    if (!document.getElementById('spinnerStyle')) {
        const style = document.createElement('style');
        style.id = 'spinnerStyle';
        style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
        document.head.appendChild(style);
    }

    // ── Auto-focus first empty field ───────────────────────────
    const usernameField = document.getElementById('username');
    if (usernameField && usernameField.value === '') {
        usernameField.focus();
    } else if (pwdField && pwdField.value === '') {
        pwdField?.focus();
    }

});
