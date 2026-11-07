document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const mode = urlParams.get('mode') || 'signup';

    const headerLogin = document.getElementById('headerLogin');
    const headerSignup = document.getElementById('headerSignup');
    const pageLoginForm = document.getElementById('pageLoginForm');
    const pageSignupForm = document.getElementById('pageSignupForm');
    const tabUnderline = document.getElementById('tabUnderline');

    const switchToLogin = document.getElementById('switchToLogin');
    const switchToSignup = document.getElementById('switchToSignup');

    function setMode(selectedMode) {
        if (selectedMode === 'login') {
            if (headerLogin) headerLogin.classList.add('active');
            if (headerSignup) headerSignup.classList.remove('active');
            if (pageLoginForm) pageLoginForm.classList.add('active');
            if (pageSignupForm) pageSignupForm.classList.remove('active');

            // Position blue underline under LOG IN
            if (tabUnderline && headerLogin) {
                tabUnderline.style.left = headerLogin.offsetLeft + 'px';
                tabUnderline.style.width = headerLogin.offsetWidth + 'px';
            }
        } else {
            if (headerSignup) headerSignup.classList.add('active');
            if (headerLogin) headerLogin.classList.remove('active');
            if (pageSignupForm) pageSignupForm.classList.add('active');
            if (pageLoginForm) pageLoginForm.classList.remove('active');

            // Position blue underline under SIGN UP
            if (tabUnderline && headerSignup) {
                tabUnderline.style.left = headerSignup.offsetLeft + 'px';
                tabUnderline.style.width = headerSignup.offsetWidth + 'px';
            }
        }
    }

    setMode(mode);

    // Event listeners for switching tabs
    if (headerLogin) headerLogin.addEventListener('click', () => setMode('login'));
    if (headerSignup) headerSignup.addEventListener('click', () => setMode('signup'));
    if (switchToLogin) switchToLogin.addEventListener('click', () => setMode('login'));
    if (switchToSignup) switchToSignup.addEventListener('click', () => setMode('signup'));
});