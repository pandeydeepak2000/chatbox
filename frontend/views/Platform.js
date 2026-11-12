'use client';

import { useState } from 'react';
import {
  Activity, Bot, Building2, CheckCircle2, CreditCard, Globe, KeyRound, Mail, MessageSquareText,
  Pencil, Plus, RefreshCw, Search, Send, Server, ShieldCheck, Target, Ticket, Trash2, Users,
} from 'lucide-react';
import { api, fmt } from '@/lib/api';
import { Badge, Button, ErrorBox, Field, Loading, Modal, PageHead, Select, Stat, Tabs, Toggle, useAction, useApi } from '@/components/ui';
import { navigate } from './App';

const LIMIT_KEYS = [['websites', 'Websites'], ['staff', 'Staff'], ['conversations_per_month', 'Chats / month'], ['knowledge_documents', 'Knowledge docs'], ['ai_monthly_usd', 'AI USD / month']];

const SECTION_META = {
  platform: ['Platform overview', 'Monitor all companies, websites, conversations, AI spend and system status.'],
  companies: ['Companies', 'Create workspaces, update company e-mails (Super Admin only), plans and account status.'],
  plans: ['Plans & billing', 'Configure subscription tiers, usage limits and public availability.'],
  users: ['Users & staff', 'Manage every staff and admin login across the entire platform.'],
  prices: ['AI pricing', 'Manage per-million token prices. Models without a price are never called.'],
  mail: ['Mail / SMTP settings', 'Configure outbound SMTP for Forgot Password, customer PIN resets and transcripts.'],
  audit: ['Audit & security logs', 'Track logins, password resets, company updates and configuration changes.'],
  system: ['System health', 'Check database, mail, queue status and clear application caches.'],
};

export default function Platform({ ctx, activeSection = 'platform' }) {
  const [localTab, setLocalTab] = useState('platform');
  const section = activeSection && SECTION_META[activeSection] ? activeSection : localTab;
  const o = useApi('/platform/overview', { enabled: ctx.perms.is_super_admin });

  if (!ctx.perms.is_super_admin) return <p className="muted">Only platform super admins can open this page.</p>;

  const [title, subtitle] = SECTION_META[section] || SECTION_META.platform;
  const showInlineTabs = !activeSection || activeSection === 'platform' && ctx.me?.user?.tenant_id;

  return (
    <>
      <PageHead title={title} subtitle={subtitle} />
      {showInlineTabs && (
        <Tabs
          value={section}
          onChange={setLocalTab}
          items={[
            ['platform', 'Overview'], ['companies', 'Companies'], ['plans', 'Plans'], ['users', 'Users'],
            ['prices', 'AI prices'], ['mail', 'Mail / SMTP'], ['audit', 'Audit logs'], ['system', 'System'],
          ]}
        />
      )}
      <ErrorBox error={o.error} onRetry={o.reload} />
      {!o.data ? <Loading /> : (
        <>
          {section === 'platform' && <PlatformOverview data={o.data.data} />}
          {section === 'companies' && <Companies data={o.data.data} reload={() => o.reload(true)} />}
          {section === 'plans' && <Plans plans={o.data.data.plans} reload={() => o.reload(true)} />}
          {section === 'users' && <PlatformUsers tenants={o.data.data.tenants} meId={ctx.me?.user?.id} />}
          {section === 'prices' && <Prices />}
          {section === 'mail' && <MailSettings />}
          {section === 'audit' && <AuditLogs />}
          {section === 'system' && <SystemHealth />}
        </>
      )}
    </>
  );
}

function PlatformOverview({ data }) {
  const s = data.summary || {};
  return (
    <div className="stack">
      <div className="stats-grid">
        <Stat icon={Building2} label="Companies" value={s.total_companies ?? data.tenants.length} sub={`${s.active_companies ?? 0} active · ${s.suspended_companies ?? 0} suspended`} />
        <Stat icon={Globe} label="Websites & staff" value={`${s.total_websites ?? 0} / ${s.total_staff ?? 0}`} sub="Total websites / staff accounts" />
        <Stat icon={MessageSquareText} label="Conversations" value={s.total_conversations ?? 0} sub={`${s.open_conversations ?? 0} active right now`} />
        <Stat icon={Ticket} label="Support tickets" value={s.total_tickets ?? 0} sub={`${s.open_tickets ?? 0} open tickets`} />
        <Stat icon={Target} label="Captured leads" value={s.total_leads ?? 0} sub="Across all workspaces" />
        <Stat icon={Bot} label="AI spend (month)" value={fmt.usd(s.ai_monthly_usd ?? 0)} sub={s.mail_enabled ? `SMTP active (${s.mail_host})` : 'SMTP not configured'} />
      </div>

      <section className="card">
        <div className="row wrap gap-sm" style={{ justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <h3 style={{ margin: 0 }}>Quick actions</h3>
            <p className="muted small" style={{ margin: '4px 0 0' }}>Jump directly to common platform administration tasks.</p>
          </div>
          <div className="row wrap gap-sm">
            <Button variant="primary" onClick={() => navigate('companies')}><Building2 size={14} />Manage companies</Button>
            <Button onClick={() => navigate('users')}><Users size={14} />Manage users</Button>
            <Button onClick={() => navigate('mail')}><Mail size={14} />SMTP settings</Button>
            <Button onClick={() => navigate('audit')}><ShieldCheck size={14} />Audit logs</Button>
            <Button onClick={() => navigate('system')}><Server size={14} />System health</Button>
          </div>
        </div>
      </section>

      <section className="card">
        <h3 style={{ marginTop: 0 }}>Recent companies</h3>
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Company</th><th>Plan</th><th>Websites</th><th>Staff</th><th>Chats (month)</th><th>AI (month)</th><th>Status</th></tr></thead>
            <tbody>
              {data.tenants.slice(0, 8).map((t) => (
                <tr key={t.id}>
                  <td><div className="cell-title">{t.name}</div><div className="cell-sub">{t.email}</div></td>
                  <td>{t.plan?.name || '—'}</td>
                  <td>{t.usage.websites}</td>
                  <td>{t.usage.staff}</td>
                  <td>{t.usage.conversations_per_month}</td>
                  <td>{fmt.usd(t.usage.ai_monthly_usd)}</td>
                  <td><Badge dot tone={t.status === 'active' ? 'green' : 'red'}>{fmt.label(t.status)}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function Companies({ data, reload }) {
  const [create, setCreate] = useState(false);
  const [editTenant, setEditTenant] = useState(null);
  const [sub, setSub] = useState(null);
  const [q, setQ] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [run, busy] = useAction();

  const filtered = data.tenants.filter((t) => {
    if (statusFilter && t.status !== statusFilter) return false;
    if (!q.trim()) return true;
    const s = q.trim().toLowerCase();
    return t.name.toLowerCase().includes(s) || t.email.toLowerCase().includes(s);
  });

  return (
    <>
      <div className="filter-bar">
        <div style={{ position: 'relative', minWidth: 220 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: 11, opacity: 0.5 }} />
          <input className="input" style={{ paddingLeft: 30 }} placeholder="Search company or e-mail…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Select value={statusFilter} onChange={setStatusFilter} options={[{ value: '', label: 'All statuses' }, { value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }]} />
        <span className="grow" />
        <Button variant="primary" onClick={() => setCreate(true)}><Plus size={15} />Create company</Button>
      </div>
      <div className="alert" style={{ marginBottom: 12 }}>
        <ShieldCheck size={16} />
        <span>Only a <strong>Super Admin</strong> can change a company’s official e-mail address. Company admins cannot modify it from workspace settings.</span>
      </div>
      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Company</th><th>Plan</th><th>Websites</th><th>Staff</th><th>Chats (month)</th><th>AI (month)</th><th>Status</th><th /></tr></thead>
          <tbody>
            {filtered.map((t) => (
              <tr key={t.id}>
                <td><div className="cell-title">{t.name}</div><div className="cell-sub">{t.email} · since {fmt.date(t.created_at)}</div></td>
                <td>{t.plan?.name || '—'} {t.subscription_status && t.subscription_status !== 'active' && <Badge tone="orange">{t.subscription_status}</Badge>}</td>
                <td>{t.usage.websites}</td><td>{t.usage.staff}</td><td>{t.usage.conversations_per_month}</td><td>{fmt.usd(t.usage.ai_monthly_usd)}</td>
                <td><Badge dot tone={t.status === 'active' ? 'green' : 'red'}>{fmt.label(t.status)}</Badge></td>
                <td className="nowrap">
                  <Button size="sm" onClick={() => setEditTenant({ id: t.id, name: t.name, email: t.email, update_owner_email: true })}><Pencil size={13} />Edit</Button>
                  <Button size="sm" onClick={() => setSub({ tenant: t, plan_code: t.plan?.code || 'pro', status: t.subscription_status || 'active' })}><CreditCard size={13} />Plan</Button>
                  <Button size="sm" variant={t.status === 'active' ? 'danger-ghost' : 'default'} disabled={busy} onClick={() => window.confirm(`${t.status === 'active' ? 'Suspend' : 'Re-activate'} ${t.name}?`) && run(async () => { await api(`/platform/tenants/${t.id}/status`, { method: 'POST', body: { status: t.status === 'active' ? 'suspended' : 'active' } }); reload(); }, 'Status changed')}>{t.status === 'active' ? 'Suspend' : 'Activate'}</Button>
                </td>
              </tr>
            ))}
            {!filtered.length && <tr><td colSpan={8} className="muted" style={{ textAlign: 'center', padding: 24 }}>No matching companies found.</td></tr>}
          </tbody>
        </table>
      </div>

      {editTenant && (
        <Modal title="Edit company details" onClose={() => setEditTenant(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => {
            await api(`/platform/tenants/${editTenant.id}`, {
              method: 'POST',
              body: { name: editTenant.name, email: editTenant.email, update_owner_email: editTenant.update_owner_email },
            });
            setEditTenant(null);
            reload();
          }, 'Company updated')}>Save changes</Button>
        }>
          <Field label="Company name"><input className="input" value={editTenant.name} onChange={(e) => setEditTenant({ ...editTenant, name: e.target.value })} /></Field>
          <Field label="Company e-mail (Super Admin only)"><input className="input" type="email" value={editTenant.email} onChange={(e) => setEditTenant({ ...editTenant, email: e.target.value })} /></Field>
          <Toggle checked={editTenant.update_owner_email} onChange={(v) => setEditTenant({ ...editTenant, update_owner_email: v })} label="Also update matching company owner login e-mail" />
        </Modal>
      )}

      {sub && (
        <Modal title={`Plan for ${sub.tenant.name}`} onClose={() => setSub(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api(`/platform/tenants/${sub.tenant.id}/subscription`, { method: 'POST', body: { plan_code: sub.plan_code, status: sub.status } }); setSub(null); reload(); }, 'Subscription updated')}>Save</Button>
        }>
          <Field label="Plan"><Select value={sub.plan_code} onChange={(v) => setSub({ ...sub, plan_code: v })} options={data.plans.map((p) => ({ value: p.code, label: `${p.name} (${p.currency} ${p.price_monthly}/month)` }))} /></Field>
          <Field label="Status"><Select value={sub.status} onChange={(v) => setSub({ ...sub, status: v })} options={['trialing', 'active', 'past_due', 'cancelled'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
        </Modal>
      )}
      {create && <CreateCompany plans={data.plans} onClose={() => setCreate(false)} onDone={reload} />}
    </>
  );
}

function CreateCompany({ plans, onClose, onDone }) {
  const [f, setF] = useState({ company_name: '', admin_name: '', admin_email: '', admin_password: '', website_name: '', website_url: 'https://', plan: 'pro' });
  const [run, busy] = useAction();
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <Modal title="Create a company" width={640} onClose={onClose} footer={
      <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api('/platform/tenants', { method: 'POST', body: f }); onDone(); onClose(); }, 'Company created')}>Create</Button>
    }>
      <div className="grid-2">
        <Field label="Company name"><input className="input" value={f.company_name} onChange={set('company_name')} /></Field>
        <Field label="Plan"><Select value={f.plan} onChange={(v) => setF({ ...f, plan: v })} options={plans.filter((p) => ['free', 'pro', 'enterprise'].includes(p.code)).map((p) => ({ value: p.code, label: p.name }))} /></Field>
        <Field label="Owner name"><input className="input" value={f.admin_name} onChange={set('admin_name')} /></Field>
        <Field label="Owner e-mail"><input className="input" type="email" value={f.admin_email} onChange={set('admin_email')} /></Field>
        <Field label="Initial password (at least 8 characters)"><input className="input" type="password" autoComplete="new-password" value={f.admin_password} onChange={set('admin_password')} /></Field>
        <Field label="First website name"><input className="input" value={f.website_name} onChange={set('website_name')} /></Field>
      </div>
      <Field label="First website URL"><input className="input" value={f.website_url} onChange={set('website_url')} /></Field>
    </Modal>
  );
}

function Plans({ plans, reload }) {
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();
  return (
    <>
      <div className="filter-bar"><span className="grow" /><Button variant="primary" onClick={() => setEdit({ code: '', name: '', price_monthly: 0, currency: 'INR', is_active: true, is_public: true, limits: {} })}><Plus size={15} />New plan</Button></div>
      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Plan</th><th>Price</th>{LIMIT_KEYS.map(([, l]) => <th key={l}>{l}</th>)}<th>Companies</th><th /></tr></thead>
          <tbody>
            {plans.map((p) => (
              <tr key={p.code}>
                <td><strong>{p.name}</strong> <span className="mono small muted">{p.code}</span>{!p.is_active && <> <Badge>Inactive</Badge></>}{!p.is_public && <> <Badge tone="purple">Private</Badge></>}</td>
                <td>{p.currency} {p.price_monthly}</td>
                {LIMIT_KEYS.map(([k]) => <td key={k}>{p.limits[k] ?? '∞'}</td>)}
                <td>{p.subscribers}</td>
                <td><Button size="sm" onClick={() => setEdit({ ...p })}>Edit</Button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {edit && (
        <Modal title={edit.subscribers !== undefined ? `Edit ${edit.name}` : 'New plan'} width={640} onClose={() => setEdit(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => {
            const body = { name: edit.name, price_monthly: Number(edit.price_monthly), currency: edit.currency, is_active: edit.is_active, is_public: edit.is_public, limits: Object.fromEntries(LIMIT_KEYS.map(([k]) => [k, edit.limits[k] === '' || edit.limits[k] == null ? null : Number(edit.limits[k])])) };
            if (edit.subscribers !== undefined) await api(`/platform/plans/${edit.code}`, { method: 'POST', body });
            else await api('/platform/plans', { method: 'POST', body: { ...body, code: edit.code } });
            setEdit(null); reload();
          }, 'Plan saved')}>Save</Button>
        }>
          <div className="grid-2">
            {edit.subscribers === undefined && <Field label="Code"><input className="input" value={edit.code} onChange={(e) => setEdit({ ...edit, code: e.target.value.toLowerCase() })} /></Field>}
            <Field label="Name"><input className="input" value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
            <Field label="Monthly price"><input className="input" type="number" value={edit.price_monthly} onChange={(e) => setEdit({ ...edit, price_monthly: e.target.value })} /></Field>
            <Field label="Currency"><input className="input" value={edit.currency} maxLength={3} onChange={(e) => setEdit({ ...edit, currency: e.target.value.toUpperCase() })} /></Field>
          </div>
          <h3>Limits (empty = unlimited)</h3>
          <div className="grid-3">
            {LIMIT_KEYS.map(([k, l]) => <Field key={k} label={l}><input className="input" type="number" min={0} value={edit.limits[k] ?? ''} onChange={(e) => setEdit({ ...edit, limits: { ...edit.limits, [k]: e.target.value } })} /></Field>)}
          </div>
          <Toggle checked={edit.is_active} onChange={(v) => setEdit({ ...edit, is_active: v })} label="Active" />
          <Toggle checked={edit.is_public} onChange={(v) => setEdit({ ...edit, is_public: v })} label="Public (offered to new companies)" />
        </Modal>
      )}
    </>
  );
}

function PlatformUsers({ tenants, meId }) {
  const u = useApi('/platform/users');
  const [q, setQ] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();

  if (u.error) return <ErrorBox error={u.error} onRetry={u.reload} />;
  if (!u.data) return <Loading />;

  const list = (u.data.data || []).filter((user) => {
    if (roleFilter && user.role !== roleFilter) return false;
    if (!q.trim()) return true;
    const s = q.trim().toLowerCase();
    return (user.name || '').toLowerCase().includes(s)
      || (user.email || '').toLowerCase().includes(s)
      || (user.tenant?.name || '').toLowerCase().includes(s);
  });

  return (
    <>
      <div className="filter-bar">
        <div style={{ position: 'relative', minWidth: 240 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: 11, opacity: 0.5 }} />
          <input className="input" style={{ paddingLeft: 30 }} placeholder="Search name, e-mail or company…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Select
          value={roleFilter}
          onChange={setRoleFilter}
          options={[
            { value: '', label: 'All roles' },
            { value: 'super_admin', label: 'Super admin' },
            { value: 'admin', label: 'Company admin' },
            { value: 'agent', label: 'Support agent' },
          ]}
        />
        <span className="grow" />
        <Button onClick={() => u.reload(true)}><RefreshCw size={14} />Refresh</Button>
      </div>

      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>User</th><th>Company</th><th>Role</th><th>Status</th><th>Created</th><th /></tr></thead>
          <tbody>
            {list.map((user) => (
              <tr key={user.id}>
                <td><div className="cell-title">{user.name}</div><div className="cell-sub">{user.email}</div></td>
                <td>{user.tenant?.name || <span className="muted">Platform</span>}</td>
                <td><Badge tone={user.role === 'super_admin' ? 'purple' : user.role === 'admin' ? 'blue' : 'gray'}>{fmt.label(user.role)}</Badge></td>
                <td><Badge dot tone={user.is_active ? 'green' : 'red'}>{user.is_active ? 'Active' : 'Disabled'}</Badge></td>
                <td className="small muted">{fmt.date(user.created_at)}</td>
                <td className="nowrap">
                  <Button
                    size="sm"
                    onClick={() => setEdit({
                      id: user.id,
                      name: user.name,
                      email: user.email,
                      role: user.role,
                      tenant_id: user.tenant_id || '',
                      is_active: !!user.is_active,
                      password: '',
                    })}
                  >
                    <KeyRound size={13} />Edit / Credentials
                  </Button>
                  {user.id !== meId && (
                    <Button
                      size="sm"
                      variant="danger-ghost"
                      disabled={busy}
                      onClick={() => window.confirm(`Delete user ${user.email}?`) && run(async () => {
                        await api(`/platform/users/${user.id}`, { method: 'DELETE' });
                        u.reload(true);
                      }, 'User deleted')}
                    >
                      <Trash2 size={13} />
                    </Button>
                  )}
                </td>
              </tr>
            ))}
            {!list.length && <tr><td colSpan={6} className="muted" style={{ textAlign: 'center', padding: 24 }}>No matching users found.</td></tr>}
          </tbody>
        </table>
      </div>

      {edit && (
        <Modal title={`Edit ${edit.email}`} width={560} onClose={() => setEdit(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => {
            await api(`/platform/users/${edit.id}/update`, {
              method: 'POST',
              body: {
                name: edit.name,
                email: edit.email,
                role: edit.role,
                tenant_id: edit.role === 'super_admin' ? null : (edit.tenant_id || null),
                is_active: edit.is_active,
                password: edit.password || undefined,
              },
            });
            setEdit(null);
            u.reload(true);
          }, 'User updated')}>Save changes</Button>
        }>
          <div className="grid-2">
            <Field label="Full name"><input className="input" value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
            <Field label="Login e-mail"><input className="input" type="email" value={edit.email} onChange={(e) => setEdit({ ...edit, email: e.target.value })} /></Field>
            <Field label="Role">
              <Select
                value={edit.role}
                onChange={(v) => setEdit({ ...edit, role: v, tenant_id: v === 'super_admin' ? '' : edit.tenant_id })}
                options={[{ value: 'super_admin', label: 'Super admin' }, { value: 'admin', label: 'Company admin' }, { value: 'agent', label: 'Support agent' }]}
              />
            </Field>
            {edit.role !== 'super_admin' && (
              <Field label="Company">
                <Select
                  value={edit.tenant_id}
                  onChange={(v) => setEdit({ ...edit, tenant_id: v })}
                  options={[{ value: '', label: 'Select company…' }, ...tenants.map((t) => ({ value: t.id, label: t.name }))]}
                />
              </Field>
            )}
          </div>
          <Field label="New password (leave blank to keep current)" hint="Changing password signs the user out of all active sessions.">
            <input className="input" type="password" autoComplete="new-password" placeholder="At least 8 characters" value={edit.password} onChange={(e) => setEdit({ ...edit, password: e.target.value })} />
          </Field>
          {edit.id !== meId && <Toggle checked={edit.is_active} onChange={(v) => setEdit({ ...edit, is_active: v })} label="Account active" />}
        </Modal>
      )}
    </>
  );
}

function Prices() {
  const list = useApi('/platform/ai-prices');
  const [f, setF] = useState({ provider: 'anthropic', model: '', input_per_million: '', output_per_million: '' });
  const [run, busy] = useAction();

  return (
    <div className="stack">
      <section className="card">
        <h3 style={{ marginTop: 0 }}>Add or update model price</h3>
        <p className="muted small">USD per 1 million tokens. A model without a price is never called, because its spending could not be controlled.</p>
        <div className="grid-3">
          <Field label="Provider"><Select value={f.provider} onChange={(v) => setF({ ...f, provider: v })} options={[{ value: 'anthropic', label: 'Anthropic' }, { value: 'openai_compatible', label: 'OpenAI-compatible' }]} /></Field>
          <Field label="Model"><input className="input" placeholder="e.g. claude-3-5-haiku-latest" value={f.model} onChange={(e) => setF({ ...f, model: e.target.value })} /></Field>
          <Field label="Input $/1M"><input className="input" type="number" step="0.0001" value={f.input_per_million} onChange={(e) => setF({ ...f, input_per_million: e.target.value })} /></Field>
          <Field label="Output $/1M"><input className="input" type="number" step="0.0001" value={f.output_per_million} onChange={(e) => setF({ ...f, output_per_million: e.target.value })} /></Field>
        </div>
        <div className="row end">
          <Button variant="primary" disabled={busy || !f.model} onClick={() => run(async () => {
            await api('/ai/prices', { method: 'POST', body: f });
            setF({ ...f, model: '', input_per_million: '', output_per_million: '' });
            list.reload(true);
          }, 'Price saved')}>Save price</Button>
        </div>
      </section>

      <section className="card">
        <h3 style={{ marginTop: 0 }}>Configured AI model prices</h3>
        <ErrorBox error={list.error} onRetry={list.reload} />
        {!list.data ? <Loading /> : (
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>Provider</th><th>Model</th><th>Input $/1M</th><th>Output $/1M</th><th>Updated</th><th /></tr></thead>
              <tbody>
                {(list.data.data || []).map((p) => (
                  <tr key={p.id}>
                    <td><Badge tone="blue">{p.provider}</Badge></td>
                    <td className="mono small">{p.model}</td>
                    <td>${Number(p.input_per_million).toFixed(4)}</td>
                    <td>${Number(p.output_per_million).toFixed(4)}</td>
                    <td className="small muted">{fmt.date(p.updated_at)}</td>
                    <td><Button size="sm" onClick={() => setF({ provider: p.provider, model: p.model, input_per_million: String(p.input_per_million), output_per_million: String(p.output_per_million) })}>Edit</Button></td>
                  </tr>
                ))}
                {!(list.data.data || []).length && <tr><td colSpan={6} className="muted" style={{ textAlign: 'center', padding: 20 }}>No AI model prices configured yet.</td></tr>}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

function MailSettings() {
  const s = useApi('/platform/mail-settings');
  const [draft, setDraft] = useState(null);
  const [testEmail, setTestEmail] = useState('');
  const [testMsg, setTestMsg] = useState(null);
  const [run, busy] = useAction();

  const f = draft ?? (s.data?.data ? { ...s.data.data, password: '', clear_password: false } : null);
  const setF = (next) => setDraft(next);

  if (s.error) return <ErrorBox error={s.error} onRetry={s.reload} />;
  if (!f) return <Loading />;

  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const save = () => run(async () => {
    const r = await api('/platform/mail-settings', { method: 'POST', body: f });
    setF({ ...r.data, password: '', clear_password: false });
  }, 'Mail settings saved');

  const sendTest = async () => {
    setTestMsg(null);
    await run(async () => {
      try {
        const r = await api('/platform/mail-settings/test', {
          method: 'POST',
          body: { ...f, to_email: testEmail },
        });
        setTestMsg({ ok: true, text: r.message });
      } catch (err) {
        setTestMsg({ ok: false, text: err.message });
        throw err;
      }
    });
  };

  return (
    <div className="stack">
      <section className="card">
        <div className="row gap-sm" style={{ marginBottom: 8 }}>
          <Mail size={18} />
          <h3 style={{ margin: 0 }}>Outbound SMTP settings</h3>
        </div>
        <p className="muted small">
          Used for staff <strong>Forgot password</strong> links, customer PIN resets and chat transcripts. Passwords are encrypted at rest and never shown again after saving.
        </p>
        <Toggle
          checked={!!f.enabled}
          onChange={(v) => setF({ ...f, enabled: v })}
          label="Use these SMTP settings for all outgoing e-mails"
        />
        <div className="grid-2" style={{ marginTop: 12 }}>
          <Field label="SMTP host"><input className="input" placeholder="mail.yourdomain.com" value={f.host} onChange={set('host')} /></Field>
          <Field label="SMTP port"><input className="input" type="number" min={1} max={65535} value={f.port} onChange={(e) => setF({ ...f, port: Number(e.target.value) || 587 })} /></Field>
          <Field label="Encryption">
            <Select
              value={f.encryption}
              onChange={(v) => setF({ ...f, encryption: v, port: v === 'ssl' && f.port === 587 ? 465 : v === 'tls' && f.port === 465 ? 587 : f.port })}
              options={[
                { value: 'ssl', label: 'SSL / TLS (port 465)' },
                { value: 'tls', label: 'STARTTLS (port 587)' },
                { value: 'none', label: 'None (plain)' },
              ]}
            />
          </Field>
          <Field label="SMTP username"><input className="input" placeholder="support@yourdomain.com" autoComplete="off" value={f.username} onChange={set('username')} /></Field>
          <Field
            label="SMTP password"
            hint={f.has_password ? 'A password is saved. Leave blank to keep the existing password.' : 'Enter your e-mail account password.'}
          >
            <input
              className="input"
              type="password"
              autoComplete="new-password"
              placeholder={f.has_password ? '•••••••• (saved)' : 'Enter SMTP password'}
              value={f.password}
              onChange={(e) => setF({ ...f, password: e.target.value, clear_password: false })}
            />
          </Field>
          <Field label="From e-mail"><input className="input" type="email" placeholder="support@yourdomain.com" value={f.from_address} onChange={set('from_address')} /></Field>
          <Field label="From name"><input className="input" placeholder="SaluteHost Support" value={f.from_name} onChange={set('from_name')} /></Field>
        </div>
        <div className="row end" style={{ marginTop: 12 }}>
          <Button variant="primary" disabled={busy} onClick={save}>Save mail settings</Button>
        </div>
      </section>

      <section className="card">
        <h3 style={{ marginTop: 0 }}>Send a test e-mail</h3>
        <p className="muted small">Verify your SMTP credentials right away before using Forgot Password.</p>
        <div className="row gap-sm" style={{ alignItems: 'flex-end' }}>
          <div style={{ flex: 1 }}>
            <Field label="Recipient e-mail">
              <input className="input" type="email" placeholder="you@example.com" value={testEmail} onChange={(e) => setTestEmail(e.target.value)} />
            </Field>
          </div>
          <Button disabled={busy || !testEmail} onClick={sendTest}><Send size={14} />Send test e-mail</Button>
        </div>
        {testMsg && (
          <div className={`alert ${testMsg.ok ? 'alert-ok' : 'alert-err'}`} style={{ marginTop: 12 }}>
            {testMsg.text}
          </div>
        )}
      </section>
    </div>
  );
}

function AuditLogs() {
  const [q, setQ] = useState('');
  const logs = useApi('/platform/audit-logs', { query: q ? { q } : undefined });

  return (
    <>
      <div className="filter-bar">
        <div style={{ position: 'relative', minWidth: 260 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: 11, opacity: 0.5 }} />
          <input className="input" style={{ paddingLeft: 30 }} placeholder="Filter by action or IP…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <span className="grow" />
        <Button onClick={() => logs.reload(true)}><RefreshCw size={14} />Refresh</Button>
      </div>
      <ErrorBox error={logs.error} onRetry={logs.reload} />
      {!logs.data ? <Loading /> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Company</th><th>IP address</th></tr></thead>
            <tbody>
              {(logs.data.data || []).map((row) => (
                <tr key={row.id}>
                  <td className="small muted nowrap">{fmt.date(row.created_at)}</td>
                  <td><Badge tone={row.action.includes('failed') ? 'red' : row.action.startsWith('platform.') ? 'purple' : 'blue'}>{row.action}</Badge></td>
                  <td>
                    <div className="cell-title">{row.actor_name || fmt.label(row.actor_type)}</div>
                    {row.actor_email && <div className="cell-sub">{row.actor_email}</div>}
                  </td>
                  <td>{row.tenant_name || <span className="muted">Platform</span>}</td>
                  <td className="mono small">{row.ip_address || '—'}</td>
                </tr>
              ))}
              {!(logs.data.data || []).length && <tr><td colSpan={5} className="muted" style={{ textAlign: 'center', padding: 24 }}>No audit logs recorded yet.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

function SystemHealth() {
  const h = useApi('/platform/system-health');
  const [run, busy] = useAction();

  if (h.error) return <ErrorBox error={h.error} onRetry={h.reload} />;
  if (!h.data) return <Loading />;
  const d = h.data.data;

  return (
    <div className="stack">
      <div className="stats-grid">
        <Stat icon={Activity} label="Database" value={d.db_ok ? 'Connected' : 'Error'} sub={`Driver: ${d.db_driver}`} />
        <Stat icon={Mail} label="Outbound mail" value={d.mail_enabled ? 'Enabled' : 'Disabled'} sub={d.mail_host || 'Configure in Mail / SMTP'} />
        <Stat icon={Server} label="Queue jobs" value={d.pending_jobs} sub={`${d.failed_jobs} failed jobs · ${d.queue_connection}`} />
        <Stat icon={CheckCircle2} label="Runtime" value={`PHP ${d.php_version}`} sub={`Laravel ${d.laravel_version} · ${d.app_env}`} />
      </div>

      <section className="card">
        <h3 style={{ marginTop: 0 }}>Server & cache controls</h3>
        <div className="grid-2 small" style={{ marginBottom: 16 }}>
          <div><strong>Cache driver:</strong> <span className="mono">{d.cache_driver}</span></div>
          <div><strong>From e-mail:</strong> <span className="mono">{d.mail_from || '—'}</span></div>
          <div><strong>Debug mode:</strong> {d.app_debug ? <Badge tone="orange">Enabled</Badge> : <Badge tone="green">Off (secure)</Badge>}</div>
          <div><strong>Server time (IST):</strong> {fmt.date(d.server_time)}</div>
        </div>
        <div className="row gap-sm">
          <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api('/platform/flush-cache', { method: 'POST' }); h.reload(true); }, 'Application cache flushed')}>
            <RefreshCw size={14} />Flush application cache
          </Button>
          <Button onClick={() => h.reload(true)}>Refresh status</Button>
        </div>
      </section>
    </div>
  );
}
