const SESSION_KEY = 'netlab-guardian-session-v1';

try {
  const session = JSON.parse(sessionStorage.getItem(SESSION_KEY));
  if (session && ['admin','viewer'].includes(session.role)) window.location.replace('index.html');
} catch {}

const form = document.querySelector('#siteLoginForm');
const error = document.querySelector('#siteLoginError');

form.addEventListener('submit', event => {
  event.preventDefault();
  const username = form.elements.username.value.trim();
  const password = form.elements.password.value;
  const account = username === 'admin' && password === 'admin'
    ? {username:'admin',role:'admin'}
    : username === 'nickel' && password === 'supergeheim'
      ? {username:'nickel',role:'viewer'}
      : null;

  if (!account) {
    error.textContent = 'Benutzername oder Passwort ist falsch.';
    form.elements.password.value = '';
    form.elements.password.focus();
    return;
  }

  try {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(account));
    window.location.replace('index.html');
  } catch {
    error.textContent = 'Die Anmeldung konnte nicht gespeichert werden. Bitte Browserspeicher erlauben.';
  }
});
