const list = document.querySelector('#list');
const loginPanel = document.querySelector('#login-panel');
const adminPanel = document.querySelector('#admin-panel');
const loginForm = document.querySelector('#login');
const loginResult = document.querySelector('#login-result');
const logoutButton = document.querySelector('#logout');
let csrf = '';

async function readJson(response) {
  try {
    return await response.json();
  } catch {
    return { error: 'Réponse illisible.' };
  }
}

function showLogin(message) {
  adminPanel.hidden = true;
  loginPanel.hidden = false;
  csrf = '';
  if (message) loginResult.textContent = message;
}

function showAdmin() {
  loginPanel.hidden = true;
  adminPanel.hidden = false;
  loginResult.textContent = '';
}

async function load() {
  const response = await fetch('/api/admin.php', { credentials: 'same-origin' });
  const data = await readJson(response);
  if (response.status === 401 || data.login_required) {
    showLogin();
    return;
  }
  if (!response.ok) {
    showLogin(data.error || 'Impossible de charger les réservations.');
    return;
  }
  csrf = data.csrf || '';
  showAdmin();
  const items = data.reservations || [];
  list.replaceChildren();
  if (!items.length) {
    list.textContent = 'Aucune réservation pour le moment.';
    return;
  }
  for (const row of items) {
    const box = document.createElement('article');
    box.className = 'menu-item';
    const title = document.createElement('h3');
    const tableText = row.table_label ? ` · ${row.table_label}` : '';
    title.textContent = `${row.date} à ${row.time} · ${row.guests} personne(s) · ${row.name}${tableText}`;
    const info = document.createElement('p');
    const callHint = row.needs_call ? '\nÀ confirmer de préférence par téléphone.' : '';
    info.textContent = `${row.email} · ${row.phone} · ${row.status}${callHint}\n${row.note || ''}`;
    info.style.whiteSpace = 'pre-line';
    box.append(title, info);
    for (const [status, label] of [['confirmee', 'Confirmer'], ['annulee', 'Annuler'], ['en_attente', 'Remettre en attente']]) {
      const button = document.createElement('button');
      button.className = 'btn small';
      button.textContent = label;
      button.style.margin = '4px';
      button.onclick = async () => {
        const update = await fetch('/api/admin.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: row.id, status, csrf }),
        });
        const result = await readJson(update);
        if (update.status === 401 || result.login_required) {
          showLogin('Veuillez vous reconnecter.');
          return;
        }
        if (!update.ok) {
          alert(result.error || 'Mise à jour impossible.');
          return;
        }
        if (result.csrf) csrf = result.csrf;
        load();
      };
      box.append(button);
    }
    list.append(box);
  }
}

loginForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  loginResult.textContent = 'Connexion…';
  const body = Object.fromEntries(new FormData(loginForm));
  const response = await fetch('/api/login.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await readJson(response);
  if (!response.ok) {
    loginResult.textContent = data.error || 'Connexion refusée.';
    return;
  }
  csrf = data.csrf || '';
  loginForm.reset();
  load();
});

logoutButton.addEventListener('click', async () => {
  await fetch('/api/logout.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf }),
  });
  showLogin('Déconnecté.');
});

load();
