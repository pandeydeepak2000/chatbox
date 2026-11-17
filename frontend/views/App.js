'use client';

import { useCallback, useEffect, useState } from 'react';
import {
  BarChart3, Bell, BookOpen, Bot, Building2, ChevronRight, CreditCard, Globe, LayoutDashboard, LifeBuoy, LogOut,
  Mail, Menu, MessageSquareText, Server, ShieldCheck, Sparkles, Target, Ticket, UserRound, Users, Zap,
} from 'lucide-react';
import { api, getToken, setToken, setUnauthorizedHandler } from '@/lib/api';
import { Button, Field, Loading, ToastProvider, useAction } from '@/components/ui';
import Overview from './Overview';
import Inbox from './Inbox';
import Tickets from './Tickets';
import Leads from './Leads';
import Knowledge from './Knowledge';
import AiSettings from './AiSettings';
import AiReview from './AiReview';
import Websites from './Websites';
import Team from './Team';
import Notifications from './Notifications';
import Analytics from './Analytics';
import Company from './Company';
import Platform from './Platform';
import Customers from './Customers';
import NotificationBell from './NotificationBell';

/**
 * Where the dashboard is open. Three addresses serve the same app:
 *   /app[/view]               the classic address (also platform administrators)
 *   /{website}/admin[/view]   website sign-in address
 *   /{company}[/view]         the company's own address, e.g. /salute and /salute/inbox
 * The address is presentation only: what a person may see is decided by the server from the signed-in account.
 */
const SLUG = /^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/;
export function pathContext() {
  if (typeof window === 'undefined') return { mode: 'app', base: '/app', rest: [] };
  const parts = window.location.pathname.split('/').filter(Boolean);
  if (parts[0] === 'app') return { mode: 'app', base: '/app', rest: parts.slice(1) };
  if (parts[1] === 'admin' && SLUG.test(parts[0])) return { mode: 'website', base: `/${parts[0]}/admin`, slug: parts[0], rest: parts.slice(2) };
  if (parts[0] && SLUG.test(parts[0])) return { mode: 'company', base: `/${parts[0]}`, company: parts[0], rest: parts.slice(1) };
  return { mode: 'app', base: '/app', rest: [] };
}

const cleanParams = (entries) => Object.fromEntries(entries.filter(([, v]) => /^[A-Za-z0-9_-]{1,64}$/.test(v)));

function parseHash() {
  const h = window.location.hash.replace(/^#\/?/, '');
  const [view, qs] = h.split('?');
  return { view: /^[a-z]{1,20}$/.test(view) ? view : null, params: cleanParams([...new URLSearchParams(qs || '')]) };
}

/** The view comes from the address path (/salute/inbox); the old #/inbox links are still understood. */
function parseRoute() {
  const c = pathContext();
  const fromPath = c.rest[0] && /^[a-z]{1,20}$/.test(c.rest[0]) ? c.rest[0] : null;
  if (fromPath) return { view: fromPath, params: cleanParams([...new URLSearchParams(window.location.search)]) };
  const h = parseHash();
  if (h.view) return h;
  return { view: 'overview', params: cleanParams([...new URLSearchParams(window.location.search)]) };
}

const buildUrl = (view, params = {}) => {
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString();
  return `${pathContext().base}/${view}${qs ? `?${qs}` : ''}`;
};

/**
 * Every signed-in person works at ONE address: company staff at /{their company}, platform administrators at /app.
 * Opening /app or another company's address after sign-in moves the browser (no reload) to the person's own address.
 * This is about a clear, consistent link only; access is still decided by the server from the account on every request.
 */
const COMPANY_VIEWS = ['overview', 'inbox', 'tickets', 'leads', 'customers', 'knowledge', 'ai', 'aireview', 'websites', 'team', 'notifications', 'analytics', 'company'];
function ownBase(me) {
  const own = me?.user?.tenant?.slug;
  if (me?.user?.tenant_id) return own && SLUG.test(own) ? `/${own}` : null;   // company staff (incl. a super admin who belongs to a company)
  return me?.permissions?.is_super_admin ? '/app' : null;                     // platform administrator
}
export function moveToOwnAddress(me) {
  if (typeof window === 'undefined') return;
  const c = pathContext();
  if (c.mode === 'website') return;                                 // /{website}/admin keeps its website scope
  const base = ownBase(me);
  if (!base || base === c.base) return;
  if (base !== '/app' && me?.permissions?.is_super_admin && c.mode === 'app') return;   // platform pages stay on /app
  // Only views a company address serves are carried over, so a reload never lands on a 404.
  const view = base === '/app' || COMPANY_VIEWS.includes(c.rest[0]) ? c.rest[0] : null;
  window.history.replaceState(null, '', `${base}${view ? `/${view}` : ''}${window.location.search}${window.location.hash}`);
}

/** Native history API (supported by Next.js shallow routing); the app listens for 'chatbox:navigate'. */
export function navigate(view, params = {}) {
  window.history.pushState(null, '', buildUrl(view, params));
  window.dispatchEvent(new Event('chatbox:navigate'));
}

// [view, label, icon, required permission]
const NAV_SECTIONS = [
  ['Support', [
    ['overview', 'Overview', LayoutDashboard],
    ['inbox', 'Live Inbox', MessageSquareText],
    ['tickets', 'Support Tickets', Ticket],
    ['leads', 'Leads', Target],
    ['customers', 'Customers', UserRound],
  ]],
  ['Knowledge & AI', [
    ['knowledge', 'Knowledge Base', BookOpen],
    ['ai', 'AI Settings', Bot, 'admin'],
    ['aireview', 'Answer Review', Sparkles, 'admin'],
  ]],
  ['Workspace', [
    ['websites', 'Websites & Widget', Globe],
    ['team', 'Team & Departments', Users],
    ['notifications', 'Notifications & Tasks', Bell],
    ['analytics', 'Analytics & Reports', BarChart3],
    ['company', 'Company Settings', Building2],
  ]],
  ['Platform', [
    ['platform', 'Super Admin', ShieldCheck, 'super'],
  ]],
];
const ALL_NAV = NAV_SECTIONS.flatMap(([, items]) => items);

const PLATFORM_NAV_SECTIONS = [
  ['Platform', [
    ['platform', 'Overview', LayoutDashboard],
    ['companies', 'Companies', Building2],
    ['plans', 'Plans & Billing', CreditCard],
    ['users', 'Users & Staff', Users],
  ]],
  ['System & Security', [
    ['prices', 'AI Pricing', Bot],
    ['aiproviders', 'Custom AI Providers', Bot],
    ['mail', 'Mail / SMTP', Mail],
    ['audit', 'Audit & Security', ShieldCheck],
    ['system', 'System Health', Server],
  ]],
];
const ALL_PLATFORM_NAV = PLATFORM_NAV_SECTIONS.flatMap(([, items]) => items);

export default function App() {
  return (
    <ToastProvider>
      <Root />
    </ToastProvider>
  );
}

function Root() {
  const [me, setMe] = useState(null);
  const [booting, setBooting] = useState(true);
  const [route, setRoute] = useState(() => (typeof window === 'undefined' ? { view: 'overview', params: {} } : parseRoute()));
  const where = typeof window === 'undefined' ? { mode: 'app' } : pathContext();   // re-read: the address can change after sign-in
  const slug = where.mode === 'website' ? where.slug : null;             // website sign-in address
  const companySlug = where.mode === 'company' ? where.company : null;   // the company's own address

  // Signed in: move to the person's own address first, then show the dashboard there.
  const signedIn = useCallback((data) => {
    moveToOwnAddress(data);
    setRoute(parseRoute());
    setMe(data);
  }, []);

  const loadMe = useCallback(async () => {
    if (!getToken()) { setBooting(false); return; }
    try {
      const r = await api('/auth/me');
      signedIn(r.data);
    } catch { setToken(null); }
    setBooting(false);
  }, [signedIn]);

  useEffect(() => {
    const onNav = () => {
      // Old-style links (#/tickets?id=…) are turned into the real address (/company/tickets?id=…) on the spot.
      if (window.location.hash.startsWith('#/')) {
        const h = parseHash();
        if (h.view) window.history.replaceState(null, '', buildUrl(h.view, h.params));
      }
      setRoute(parseRoute());
    };
    window.addEventListener('hashchange', onNav);
    window.addEventListener('popstate', onNav);
    window.addEventListener('chatbox:navigate', onNav);
    setUnauthorizedHandler(() => { setToken(null); setMe(null); });
    // Initial session check against the API (external system).
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadMe();
    onNav();
    return () => {
      window.removeEventListener('hashchange', onNav);
      window.removeEventListener('popstate', onNav);
      window.removeEventListener('chatbox:navigate', onNav);
    };
  }, [loadMe]);

  const logout = () => { setToken(null); setMe(null); };
  if (booting) return <div className="center-screen"><Loading /></div>;
  if (!me) return <Login slug={slug} companySlug={companySlug} onLogin={(data) => { setToken(data.token); signedIn(data); }} />;
  // A company address only opens for people of that company. The server enforces access either way; this only avoids a confusing screen.
  if (companySlug && me.user?.tenant?.slug !== companySlug) return <WrongCompany me={me} companySlug={companySlug} onLogout={logout} />;
  return <Shell me={me} route={route} slug={slug} reloadMe={loadMe} onLogout={logout} />;
}

function WrongCompany({ me, companySlug, onLogout }) {
  const own = me.user?.tenant?.slug;
  return (
    <div className="center-screen">
      <div className="card" style={{ maxWidth: 460, margin: '10vh auto', textAlign: 'center' }}>
        <h2>This address belongs to another company</h2>
        <p className="muted">You are signed in as {me.user?.email}, which does not belong to <strong>{companySlug}</strong>. Nothing of that company is shown to you.</p>
        <div className="row" style={{ justifyContent: 'center' }}>
          {own ? <a className="btn btn-primary" href={`/${own}`}>Open my company ({own})</a> : <a className="btn btn-primary" href="/app">Open the platform dashboard</a>}
          <button type="button" className="btn" onClick={onLogout}>Sign out</button>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ login */
const LOGIN_POINTS = [
  [MessageSquareText, 'Every conversation in one inbox', 'Live chat from all your websites, with AI and human hand-over.'],
  [Ticket, 'Nothing slips through', 'Unanswered questions become tickets with references, owners and reminders.'],
  [Sparkles, 'Answers from your own knowledge', 'The assistant only uses FAQs and documents your team has approved.'],
];

function Login({ slug, companySlug, onLogin }) {
  const [site, setSite] = useState(null);
  const [company, setCompany] = useState(null);
  const [reset] = useState(() => {
    const p = new URLSearchParams(window.location.search);
    return p.get('reset_token') ? { token: p.get('reset_token'), email: p.get('email') || '' } : null;
  });
  const [mode, setMode] = useState(reset ? 'reset' : 'login');
  const [form, setForm] = useState({ email: reset?.email || '', password: '' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [run, busy] = useAction();

  useEffect(() => {
    if (slug) api(`/public/websites/${slug}`).then((r) => setSite(r.data)).catch(() => setSite({ missing: true }));
  }, [slug]);
  useEffect(() => {
    if (companySlug) api(`/public/tenants/${companySlug}`).then((r) => setCompany(r.data)).catch(() => setCompany({ missing: true }));
  }, [companySlug]);

  const submit = async (e) => {
    e.preventDefault();
    setError(''); setNotice('');
    try {
      if (mode === 'login') {
        const r = await api('/auth/login', { method: 'POST', body: { email: form.email, password: form.password, website_slug: slug || undefined, tenant_slug: companySlug || undefined } });
        onLogin(r.data);
      } else if (mode === 'forgot') {
        const r = await api('/auth/forgot-password', { method: 'POST', body: { email: form.email } });
        setNotice(r.message);
      } else {
        const r = await api('/auth/reset-password', { method: 'POST', body: { email: form.email, token: reset.token, password: form.password } });
        setNotice(r.message);
        window.history.replaceState(null, '', window.location.pathname);
        setMode('login');
      }
    } catch (err) { setError(err.message); }
  };

  const siteKnown = site && !site.missing;
  const companyKnown = company && !company.missing;
  const title = mode === 'forgot' ? 'Reset your password' : mode === 'reset' ? 'Choose a new password' : companyKnown ? `Sign in to ${company.name}` : siteKnown ? `Sign in to ${site.site_name}` : 'Welcome back';
  const subtitle = mode === 'forgot'
    ? 'Enter your work e-mail and we will send you a secure reset link.'
    : mode === 'reset'
      ? 'Use at least 8 characters. You will be signed out on other devices.'
      : companyKnown ? 'Use your staff account for this company.' : siteKnown ? 'Use the staff account that has access to this website.' : 'Sign in to your support workspace to continue.';

  return (
    <div className="login-screen">
      <aside className="login-aside">
        <div className="login-brand"><div className="brand-mark sm"><LifeBuoy size={18} /></div>ChatBox</div>
        <div className="login-pitch">
          <h2>Support that feels personal, at any scale.</h2>
          <p>One workspace for live chat, tickets, leads and an AI assistant that stays within the facts you approve.</p>
          <ul className="login-points">
            {LOGIN_POINTS.map(([Icon, head, text]) => (
              <li key={head}><span className="pt-icon"><Icon size={17} /></span><div><strong>{head}</strong><span>{text}</span></div></li>
            ))}
          </ul>
        </div>
        <div className="login-foot">© {new Date().getFullYear()} ChatBox · Secure staff access</div>
      </aside>
      <main className="login-main">
        <form className="login-card" onSubmit={(e) => run(() => submit(e))}>
          <div className="brand-mark" style={site?.primary_color ? { background: site.primary_color } : undefined}><LifeBuoy size={22} /></div>
          <h1>{title}</h1>
          <p className="muted">{subtitle}</p>
          {site?.missing && <div className="alert alert-err">This website address is not recognised. Please check the link you were given.</div>}
          {error && <div className="alert alert-err">{error}</div>}
          {notice && <div className="alert alert-ok">{notice}</div>}
          <Field label="Work e-mail">
            <input className="input" type="email" required autoComplete="username" placeholder="you@company.com" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>
          {mode !== 'forgot' && (
            <Field label={mode === 'reset' ? 'New password' : 'Password'}>
              <input className="input" type="password" required minLength={mode === 'reset' ? 8 : 1} autoComplete={mode === 'reset' ? 'new-password' : 'current-password'} placeholder="••••••••" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
            </Field>
          )}
          <Button variant="primary" type="submit" disabled={busy} className="btn-block">
            {busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : mode === 'forgot' ? 'Send reset link' : 'Update password'}
          </Button>
          <div className="login-links">
            {mode === 'login'
              ? <button type="button" className="link" onClick={() => { setMode('forgot'); setError(''); }}>Forgot your password?</button>
              : <button type="button" className="link" onClick={() => { setMode('login'); setError(''); }}>Back to sign in</button>}
          </div>
          <div className="login-secure"><ShieldCheck size={14} /> Encrypted connection · Access is limited to your websites</div>
        </form>
      </main>
    </div>
  );
}

/* ------------------------------------------------------------------ shell */
function Shell({ me, route, slug, reloadMe, onLogout }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [online, setOnline] = useState(!!me.user.is_online);
  const perms = me.permissions || {};
  // A platform super admin is not part of any company: company pages (inbox, AI settings, …) do not
  // apply, so only the platform pages are shown. Company owners manage their own workspace.
  const platformOnly = perms.is_super_admin && !me.user.tenant_id;
  const allowed = ([, , , need]) => (platformOnly ? need === 'super' : (!need || (need === 'super' ? perms.is_super_admin : perms.is_company_admin)));
  const ctx = { me, perms, properties: me.properties || [], reloadMe, params: route.params, go: navigate, slug };

  // The website chosen in the admin URL is the default website filter.
  const defaultSite = slug ? (me.properties || []).find((p) => p.slug === slug)?.id : '';
  ctx.defaultPropertyId = defaultSite || '';

  useEffect(() => {
    const beat = setInterval(() => { if (!document.hidden && online) api('/auth/status', { method: 'POST', body: { is_online: true } }).catch(() => {}); }, 120000);
    return () => clearInterval(beat);
  }, [online]);

  const toggleOnline = async () => {
    const next = !online;
    setOnline(next);
    await api('/auth/status', { method: 'POST', body: { is_online: next } }).catch(() => setOnline(!next));
  };
  const logout = async () => {
    await api('/auth/logout', { method: 'POST' }).catch(() => {});
    onLogout();
  };

  const sections = platformOnly ? PLATFORM_NAV_SECTIONS : NAV_SECTIONS;
  const navItems = platformOnly ? ALL_PLATFORM_NAV : ALL_NAV;
  const activeView = platformOnly ? (ALL_PLATFORM_NAV.some(([v]) => v === route.view) ? route.view : 'platform') : route.view;

  const View = platformOnly ? Platform : {
    customers: Customers,
    overview: Overview, inbox: Inbox, tickets: Tickets, leads: Leads, knowledge: Knowledge, ai: AiSettings, aireview: AiReview,
    websites: Websites, team: Team, notifications: Notifications, analytics: Analytics, company: Company, platform: Platform,
  }[route.view] || Overview;

  const siteName = slug ? (me.properties || []).find((p) => p.slug === slug)?.site_name : null;
  const workspaceName = me.user.tenant?.name || 'ChatBox Platform';
  const current = navItems.find(([v]) => v === activeView) || navItems[0];
  const roleLabel = perms.is_super_admin ? 'Super admin' : perms.is_company_owner ? 'Company owner' : perms.is_company_admin ? 'Company admin' : 'Support agent';
  const initials = (me.user.name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

  return (
    <div className={`shell ${menuOpen ? 'menu-open' : ''}`}>
      <aside className="sidebar">
        <div className="side-brand">
          <div className="brand-mark sm"><LifeBuoy size={18} /></div>
          <div className="grow">
            <div className="side-title ellipsis">{workspaceName}</div>
            <div className="side-sub ellipsis">{platformOnly ? 'Platform control center' : (siteName ? `${siteName} admin` : 'Support workspace')}</div>
          </div>
        </div>
        <nav>
          {sections.map(([section, items]) => {
            const visible = platformOnly ? items : items.filter(allowed);
            if (!visible.length) return null;
            return (
              <div key={section}>
                <div className="nav-section">{section}</div>
                {visible.map(([v, label, Icon]) => (
                  <a key={v} href={buildUrl(v)} className={`nav-item ${current[0] === v ? 'active' : ''}`} onClick={(e) => { e.preventDefault(); setMenuOpen(false); navigate(v); }} aria-current={current[0] === v ? 'page' : undefined}>
                    <Icon size={17} />{label}
                  </a>
                ))}
              </div>
            );
          })}
        </nav>
        <div className="side-foot">
          <div className="side-user">
            <div className="avatar">{initials}</div>
            <div className="grow">
              <div className="side-name ellipsis">{me.user.name}</div>
              <div className="side-sub">{roleLabel}</div>
            </div>
          </div>
          <div className="side-actions">
            {!platformOnly && (
              <Button size="sm" onClick={toggleOnline} title={online ? 'Customers see you as available' : 'You will not receive live hand-overs'}>
                <span className={`status-dot ${online ? 'on' : ''}`} />{online ? 'Online' : 'Away'}
              </Button>
            )}
            <Button size="sm" onClick={logout}><LogOut size={14} />Sign out</Button>
          </div>
        </div>
      </aside>
      <div className="main">
        <header className="topbar">
          <button className="icon-btn menu-btn" onClick={() => setMenuOpen(!menuOpen)} aria-label="Open menu"><Menu size={20} /></button>
          <div className="topbar-title">
            <span className="ellipsis">{siteName || workspaceName}</span>
            <ChevronRight size={14} className="topbar-sep" />
            <strong className="ellipsis">{current[1]}</strong>
          </div>
          {perms.is_super_admin && !platformOnly && route.view !== 'platform' && <a className="btn btn-sm btn-ghost" href="/app/platform" onClick={(e) => { e.preventDefault(); window.history.pushState(null, '', '/app/platform'); window.dispatchEvent(new Event('chatbox:navigate')); }}><Zap size={14} />Platform</a>}
          <NotificationBell />
        </header>
        <main className="content">
          <View ctx={ctx} activeSection={activeView} />
        </main>
      </div>
    </div>
  );
}
