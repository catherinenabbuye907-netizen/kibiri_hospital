const loginForm = document.getElementById('staffLoginForm');

if (loginForm) {
  loginForm.addEventListener('submit', () => {
    const submitButton = loginForm.querySelector('button[type="submit"]');

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Signing in...';
    }
  });
}
