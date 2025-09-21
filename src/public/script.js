const apiBase = '/api';

async function apiFetch(path, options = {}) {
  const res = await fetch(`${apiBase}${path}`, {
    headers: { 'Accept': 'application/json', ...(options.headers || {}) },
    credentials: 'same-origin',
    ...options,
  });
  const ct = res.headers.get('content-type') || '';
  const isJson = ct.includes('application/json');
  const body = isJson ? await res.json() : await res.text();
  if (!res.ok || (isJson && body && body.success === false)) {
    const message = isJson ? (body.error || JSON.stringify(body)) : body;
    throw new Error(message || `HTTP ${res.status}`);
  }
  return isJson ? body : { raw: body };
}

function qs(sel) { return document.querySelector(sel); }
function qsa(sel) { return Array.from(document.querySelectorAll(sel)); }

function setHidden(el, hidden) { el.classList.toggle('hidden', !!hidden); }

function bytesFmt(n) {
  const units = ['B','KB','MB','GB','TB'];
  let i = 0; let v = n;
  while (v >= 1024 && i < units.length-1) { v /= 1024; i++; }
  return `${v.toFixed(v >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
}

async function refreshStatus() {
  const authStatus = qs('#auth-status');
  try {
    const data = await apiFetch('/auth.php?action=status');
    const loggedIn = !!(data && data.authenticated);
    authStatus.textContent = loggedIn ? 'Connecté' : 'Non connecté';
    setHidden(qs('#login-form'), loggedIn);
    setHidden(qs('#logout-btn'), !loggedIn);
    setHidden(qs('#uploader'), !loggedIn);
    setHidden(qs('#files-actions'), !loggedIn);
    await refreshList();
  } catch (e) {
    authStatus.textContent = 'Erreur de statut';
  }
}

async function doLogin(ev) {
  ev.preventDefault();
  const pwd = qs('#password').value;
  try {
    await apiFetch('/auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'login', password: pwd }).toString(),
    });
    qs('#password').value = '';
    await refreshStatus();
  } catch (e) {
    alert('Connexion échouée: ' + e.message);
  }
}

async function doLogout() {
  try {
    await apiFetch('/auth.php?action=logout');
    await refreshStatus();
  } catch (e) {
    alert('Erreur déconnexion: ' + e.message);
  }
}

function uploadWithProgress(formData, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${apiBase}/upload.php`);
    xhr.responseType = 'json';
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300 && xhr.response && xhr.response.success !== false) {
        resolve(xhr.response);
      } else {
        const msg = (xhr.response && (xhr.response.error || JSON.stringify(xhr.response))) || xhr.statusText;
        reject(new Error(msg));
      }
    };
    xhr.onerror = () => reject(new Error('Réseau/serveur indisponible'));
    if (xhr.upload && onProgress) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(e.loaded, e.total);
      };
    }
    xhr.send(formData);
  });
}

async function doUpload(ev) {
  ev.preventDefault();
  const input = qs('#files');
  const files = input.files;
  if (!files || files.length === 0) return;
  const fd = new FormData();
  for (const f of files) fd.append('files[]', f, f.name);

  const bar = qs('#progress-bar');
  const wrap = qs('#progress');
  const text = qs('#progress-text');
  setHidden(wrap, false);
  bar.style.width = '0%';
  text.textContent = '';
  qs('#upload-errors').textContent = '';

  try {
    const res = await uploadWithProgress(fd, (loaded, total) => {
      const pct = total ? Math.round(loaded * 100 / total) : 0;
      bar.style.width = `${pct}%`;
      text.textContent = `${pct}%`;
    });
    setHidden(wrap, true);
    input.value = '';
    await refreshList();
  } catch (e) {
    setHidden(wrap, true);
    qs('#upload-errors').textContent = String(e.message || e);
  }
}

async function refreshList() {
  const listEl = qs('#file-list');
  const errs = qs('#list-errors');
  listEl.innerHTML = '';
  errs.textContent = '';
  try {
    const data = await apiFetch('/list.php');
    const files = data.files || [];
    if (files.length === 0) {
      listEl.innerHTML = '<li>Aucun fichier.</li>';
      return;
    }
    for (const f of files) {
      const li = document.createElement('li');
      const meta = document.createElement('div');
      meta.className = 'file-meta';
      const title = document.createElement('div');
      const link = document.createElement('a');
      link.href = f.url;
      link.textContent = f.name;
      link.target = '_blank';
      title.appendChild(link);
      const sub = document.createElement('small');
      const dt = new Date(f.mtime * 1000).toLocaleString();
      sub.textContent = `${bytesFmt(f.size)} • ${dt}`;
      meta.appendChild(title);
      meta.appendChild(sub);
      li.appendChild(meta);

      const acts = document.createElement('div');
      acts.className = 'file-actions';
      const ren = document.createElement('button');
      ren.className = 'rename';
      ren.title = 'Renommer';
      ren.textContent = '✏️';
      ren.addEventListener('click', async () => {
        // Suggest base name without extension
        const dot = f.name.lastIndexOf('.');
        const ext = dot > 0 ? f.name.slice(dot) : '';
        const base = dot > 0 ? f.name.slice(0, dot) : f.name;
        const input = prompt(`Nouveau nom (sans extension ${ext || ''})`, base);
        if (input == null) return;
        let newBase = input.trim();
        if (!newBase) return;
        // If user included the same extension, strip it
        if (ext && newBase.toLowerCase().endsWith(ext.toLowerCase())) {
          newBase = newBase.slice(0, -ext.length);
        }
        try {
          await apiFetch('/rename.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: f.id, name: newBase }).toString(),
          });
          await refreshList();
        } catch (e) {
          alert('Renommage échoué: ' + e.message);
        }
      });
      const del = document.createElement('button');
      del.className = 'trash';
      del.title = 'Supprimer';
      del.textContent = '🗑';
      del.addEventListener('click', async () => {
        if (!confirm(`Supprimer ${f.name} ?`)) return;
        try {
          await apiFetch('/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: f.id }).toString(),
          });
          await refreshList();
        } catch (e) {
          alert('Suppression échouée: ' + e.message);
        }
      });
      acts.appendChild(ren);
      acts.appendChild(del);
      li.appendChild(acts);
      listEl.appendChild(li);
    }
  } catch (e) {
    errs.textContent = String(e.message || e);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  qs('#login-form').addEventListener('submit', doLogin);
  qs('#logout-btn').addEventListener('click', doLogout);
  qs('#upload-form').addEventListener('submit', doUpload);
  refreshStatus();
});
