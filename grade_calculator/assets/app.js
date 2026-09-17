// ============ DARK MODE TOGGLE ============
const themeBtn = document.getElementById('theme-toggle');
if(themeBtn) {
  themeBtn.addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme');
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    // Save to server
    fetch('api.php?a=save_theme&t=' + next).catch(()=>{});
  });
}
// Load saved theme
const savedTheme = localStorage.getItem('theme');
if(savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);

// ============ ATTENDANCE QUICK-MARK ============
window.markAll = function(status) {
  document.querySelectorAll('input[type=radio][value="' + status + '"]').forEach(r => r.checked = true);
};

// ============ PWA INSTALL ============
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  const btn = document.createElement('button');
  btn.textContent = '📲 Install App';
  btn.className = 'btn';
  btn.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999';
  btn.onclick = async () => {
    deferredPrompt.prompt();
    await deferredPrompt.userChoice;
    btn.remove();
  };
  document.body.appendChild(btn);
});

// ============ TOAST NOTIFICATIONS ============
window.toast = function(msg, type = 'info') {
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
};