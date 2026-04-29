/**
 * Casa Monarca v2 - API Client
 */

const BASE = '';

async function apiFetch(path, options = {}) {
  const res = await fetch(BASE + path, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    credentials: 'include',
    ...options,
  });
  if (res.headers.get('content-type')?.includes('application/json')) {
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'Error en la solicitud');
    return data;
  }
  if (!res.ok) throw new Error('Error en la solicitud');
  return res;
}

// AUTH
export const auth = {
  login: (email, password) => apiFetch('/auth/login.php', { method: 'POST', body: JSON.stringify({ email, password }) }),
  logout: () => apiFetch('/auth/logout.php', { method: 'POST' }),
  register: (nombre, email, password) => apiFetch('/auth/register.php', { method: 'POST', body: JSON.stringify({ nombre, email, password }) }),
  session: () => apiFetch('/auth/session.php'),
};

// DOCUMENTOS
export const documentos = {
  list: () => apiFetch('/api/documentos-list.php'),
  create: (data) => apiFetch('/api/documentos-create.php', { method: 'POST', body: JSON.stringify(data) }),
  update: (data) => apiFetch('/api/documentos-update.php', { method: 'POST', body: JSON.stringify(data) }),
  delete: (id) => apiFetch('/api/documentos-delete.php', { method: 'POST', body: JSON.stringify({ id }) }),
  emitir: (id) => apiFetch('/api/documentos-emitir.php', { method: 'POST', body: JSON.stringify({ id }) }),
  revocar: (id, motivo) => apiFetch('/api/documentos-revocar.php', { method: 'POST', body: JSON.stringify({ id, motivo }) }),
};

// USUARIOS
export const usuarios = {
  list: () => apiFetch('/api/usuarios-list.php'),
  cambiarRol: (usuario_id, rol) => apiFetch('/api/usuarios-cambiar-rol.php', { method: 'POST', body: JSON.stringify({ usuario_id, rol }) }),
  desactivar: (usuario_id) => apiFetch('/api/usuarios-desactivar.php', { method: 'POST', body: JSON.stringify({ usuario_id }) }),
  generarClaves: (usuario_id) => apiFetch('/api/usuarios-descargar-claves.php', { method: 'POST', body: JSON.stringify({ usuario_id }) }),
};

// CLAVES
export const claves = {
  info: () => apiFetch('/api/claves-info.php'),
  generarToken: (tipo) => apiFetch('/api/claves-generar-token.php', { method: 'POST', body: JSON.stringify({ tipo: tipo || 'key' }) }),
  descargarUrl: (token, tipo) => `${BASE}/api/claves-descargar.php?token=${encodeURIComponent(token)}&tipo=${tipo || 'key'}`,
};

// BITACORA
export const bitacora = {
  list: (limit = 100, offset = 0) => apiFetch(`/api/bitacora-list.php?limit=${limit}&offset=${offset}`),
};

// CONSULTA PUBLICA
export const consulta = {
  verificar: (folio) => apiFetch(`/api/consulta_qr.php?token=${encodeURIComponent(folio)}`),
};

// TOAST
export function toast(msg, type = 'info') {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  c.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

// FORMATO
export function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' });
}

export function badgeEstado(e) {
  const m = { borrador: 'badge-borrador', emitido: 'badge-emitido', revocado: 'badge-revocado' };
  return `<span class="badge ${m[e] || ''}">${e}</span>`;
}

export function badgeRol(r) {
  return `<span class="badge badge-${r}">${r}</span>`;
}
