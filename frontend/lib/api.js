// Small API client for the Laravel backend (same origin in production).
const API = '/api/v1';
const TOKEN_KEY = 'chatbox_staff_token';

export function getToken() {
  try { return localStorage.getItem(TOKEN_KEY); } catch { return null; }
}
export function setToken(t) {
  try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch { /* storage blocked */ }
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message);
    this.status = status;
    this.errors = errors || {};
  }
}

let onUnauthorized = () => {};
export function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

export async function api(path, { method = 'GET', body, form, query } = {}) {
  const url = new URL(API + path, window.location.origin);
  if (query) {
    Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v); });
  }
  const headers = { Accept: 'application/json' };
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  let payload;
  if (form) payload = form;
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }

  let res;
  try {
    res = await fetch(url, { method, headers, body: payload });
  } catch {
    throw new ApiError('Cannot reach the server. Check your connection and try again.', 0);
  }
  if (res.status === 401 && path !== '/auth/login') {
    onUnauthorized();
    throw new ApiError('Your session has expired. Please sign in again.', 401);
  }
  if (res.status === 204) return null;
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.success === false) {
    const first = json.errors ? Object.values(json.errors)[0]?.[0] : null;
    throw new ApiError(first || json.message || `Request failed (${res.status})`, res.status, json.errors);
  }
  return json;
}

/** Authenticated file download (CSV/XLSX exports). */
export async function download(path, query, filename) {
  const url = new URL(API + path, window.location.origin);
  Object.entries(query || {}).forEach(([k, v]) => { if (v) url.searchParams.set(k, v); });
  const res = await fetch(url, { headers: { Authorization: `Bearer ${getToken()}` } });
  if (!res.ok) throw new ApiError('Download failed.', res.status);
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

export const fmt = {
  date(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  },
  ago(iso) {
    if (!iso) return '—';
    const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
  },
  /** Human-readable label for system values: pending_customer → Pending customer. */
  label(s) {
    if (s === null || s === undefined || s === '') return '—';
    const t = String(s).replace(/_/g, ' ').trim();
    return t.charAt(0).toUpperCase() + t.slice(1);
  },
  usd(n) { return `$${Number(n || 0).toFixed(Number(n) < 1 ? 4 : 2)}`; },
};

export function uid() {
  return (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);
}
