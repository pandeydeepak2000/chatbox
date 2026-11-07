'use client';

import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { AlertCircle, CheckCircle2, ChevronLeft, ChevronRight, Inbox as InboxIcon, Search, X } from 'lucide-react';
import { api } from '@/lib/api';

/* ---------------------------------------------------------------- toasts */
const ToastCtx = createContext(() => {});
export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const push = useCallback((text, kind = 'ok') => {
    const id = Math.random();
    setItems((x) => [...x, { id, text, kind }]);
    setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), kind === 'err' ? 6000 : 3500);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toasts" role="status" aria-live="polite">
        {items.map((t) => <div key={t.id} className={`toast toast-${t.kind}`}>{t.kind === 'err' ? <AlertCircle size={18} /> : <CheckCircle2 size={18} />}<span>{t.text}</span></div>)}
      </div>
    </ToastCtx.Provider>
  );
}
export const useToast = () => useContext(ToastCtx);

/* ---------------------------------------------------------------- data hook */
/** Loads `path` (optionally polling). Pauses polling while the tab is hidden. */
export function useApi(path, { query, poll = 0, enabled = true } = {}) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [loadedKey, setLoadedKey] = useState(null);
  const key = path + JSON.stringify(query || {});
  const keyRef = useRef(key);
  useEffect(() => { keyRef.current = key; }, [key]);
  const loading = loadedKey !== key;

  const load = useCallback(async () => {
    if (!enabled || !path) return;
    try {
      const json = await api(path, { query });
      if (keyRef.current === key) { setData(json); setError(null); }
    } catch (e) {
      if (keyRef.current === key) setError(e);
    } finally {
      if (keyRef.current === key) setLoadedKey(key);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, enabled]);

  // Fetching from the API synchronises with an external system; state updates when it responds.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { load(); }, [load]);
  useEffect(() => {
    if (!poll) return undefined;
    const t = setInterval(() => { if (!document.hidden) load(true); }, poll);
    return () => clearInterval(t);
  }, [poll, load]);

  return { data, error, loading, reload: load, setData };
}

/** Wraps an async action with busy state + toast feedback. */
export function useAction() {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const run = useCallback(async (fn, okText) => {
    setBusy(true);
    try {
      const r = await fn();
      if (okText) toast(okText);
      return r;
    } catch (e) {
      toast(e.message || 'Something went wrong', 'err');
      return undefined;
    } finally {
      setBusy(false);
    }
  }, [toast]);
  return [run, busy];
}

/* ---------------------------------------------------------------- primitives */
export function Button({ variant = 'default', size, className = '', ...p }) {
  return <button type="button" className={`btn btn-${variant} ${size ? `btn-${size}` : ''} ${className}`} {...p} />;
}

export function Field({ label, hint, error, children, wide }) {
  return (
    <label className={`field ${wide ? 'field-wide' : ''}`}>
      {label && <span className="field-label">{label}</span>}
      {children}
      {hint && !error && <span className="field-hint">{hint}</span>}
      {error && <span className="field-error">{error}</span>}
    </label>
  );
}

export function Badge({ tone = 'gray', dot, children }) {
  return <span className={`badge badge-${tone} ${dot ? 'badge-dot' : ''}`}>{children}</span>;
}

export const statusTone = (s) => ({
  open: 'blue', pending_team: 'orange', pending_customer: 'purple', resolved: 'green', closed: 'gray',
  new: 'blue', contacted: 'orange', interested: 'purple', follow_up: 'orange', converted: 'green',
  approved: 'green', pending_approval: 'orange', processing: 'blue', failed: 'red', rejected: 'red', archived: 'gray', draft: 'gray',
  unassigned: 'orange', pending: 'purple', urgent: 'red', high: 'orange', normal: 'blue', low: 'gray',
  sent: 'green', ai: 'purple', human: 'blue',
}[s] || 'gray');

export function Modal({ title, onClose, children, footer, width = 560 }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);
  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: width }} role="dialog" aria-modal="true" aria-label={title}>
        <div className="modal-head">
          <h3>{title}</h3>
          <button className="icon-btn" onClick={onClose} aria-label="Close"><X size={18} /></button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}

export function Empty({ icon: Icon = InboxIcon, title, children }) {
  return (
    <div className="empty">
      <div className="empty-icon"><Icon size={22} /></div>
      <div className="empty-title">{title}</div>
      {children && <div className="empty-text">{children}</div>}
    </div>
  );
}

export function Loading({ text = 'Loading…' }) {
  return <div className="loading"><span className="spinner" /> {text}</div>;
}

export function ErrorBox({ error, onRetry }) {
  if (!error) return null;
  return (
    <div className="alert alert-err">
      <AlertCircle size={16} />
      <span className="grow">{error.message}</span>
      {onRetry && <Button size="sm" onClick={() => onRetry()}>Try again</Button>}
    </div>
  );
}

export function PageHead({ title, subtitle, children }) {
  return (
    <div className="page-head">
      <div>
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
      <div className="page-actions">{children}</div>
    </div>
  );
}

export function Tabs({ value, onChange, items }) {
  return (
    <div className="tabs" role="tablist">
      {items.map(([v, label, count]) => (
        <button key={v} role="tab" aria-selected={value === v} className={`tab ${value === v ? 'active' : ''}`} onClick={() => onChange(v)}>
          {label}{count !== undefined && count !== null ? <span className="tab-count">{count}</span> : null}
        </button>
      ))}
    </div>
  );
}

export function Pager({ meta, page, onPage }) {
  if (!meta || meta.last_page <= 1) return null;
  return (
    <div className="pager">
      <span>Page {meta.current_page} of {meta.last_page}</span>
      <Button size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}><ChevronLeft size={15} /> Previous</Button>
      <Button size="sm" disabled={page >= meta.last_page} onClick={() => onPage(page + 1)}>Next <ChevronRight size={15} /></Button>
    </div>
  );
}

/** Plain-text message bubble content: React escapes everything; keep line breaks. */
export function MessageText({ text }) {
  return <div className="msg-text">{String(text ?? '')}</div>;
}

export function Select({ value, onChange, options, placeholder, ...p }) {
  return (
    <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...p}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  );
}

export function Stat({ label, value, sub, tone, icon: Icon }) {
  return (
    <div className={`stat ${tone ? `stat-${tone}` : ''}`}>
      <div className="stat-top"><div className="stat-label">{label}</div>{Icon && <span className="stat-icon"><Icon size={17} /></span>}</div>
      <div className="stat-value">{value ?? '—'}</div>
      {sub && <div className="stat-sub">{sub}</div>}
    </div>
  );
}

export function Toggle({ checked, onChange, label }) {
  return (
    <label className="toggle">
      <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />
      <span className="toggle-track"><span className="toggle-thumb" /></span>
      {label && <span>{label}</span>}
    </label>
  );
}

/** Search box with a leading icon. */
export function SearchInput({ value, onChange, placeholder }) {
  return (
    <div className="input-icon">
      <Search size={16} />
      <input className="input" type="search" placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}

/** Small back link above a page title. */
export function BackLink({ onClick, children }) {
  return <button type="button" className="back-link" onClick={onClick}><ChevronLeft size={16} />{children}</button>;
}

/** Card heading with optional icon and right-side content. */
export function CardHead({ icon: Icon, title, children }) {
  return (
    <div className="card-head">
      <h2>{Icon && <Icon size={17} className="muted" />}{title}</h2>
      {children}
    </div>
  );
}
