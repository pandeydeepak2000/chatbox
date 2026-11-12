'use client';

import { useState } from 'react';
import { Building2, CheckCircle2, CreditCard, Mail, Plus, Send } from 'lucide-react';
import { api, fmt } from '@/lib/api';
import { Badge, Button, ErrorBox, Field, Loading, Modal, PageHead, Select, Stat, Tabs, Toggle, useAction, useApi } from '@/components/ui';

const LIMIT_KEYS = [['websites', 'Websites'], ['staff', 'Staff'], ['conversations_per_month', 'Chats / month'], ['knowledge_documents', 'Knowledge docs'], ['ai_monthly_usd', 'AI USD / month']];

export default function Platform({ ctx }) {
  const [tab, setTab] = useState('companies');
  const o = useApi('/platform/overview', { enabled: ctx.perms.is_super_admin });
  if (!ctx.perms.is_super_admin) return <p className="muted">Only platform super admins can open this page.</p>;
  return (
    <>
      <PageHead title="Platform administration" subtitle="Manage every company on ChatBox: workspaces, plans, subscriptions and AI prices." />
      <Tabs value={tab} onChange={setTab} items={[['companies', 'Companies'], ['plans', 'Plans'], ['prices', 'AI prices'], ['mail', 'Mail / SMTP']]} />
      <ErrorBox error={o.error} onRetry={o.reload} />
      {!o.data ? <Loading /> : (
        <>
          {tab === 'companies' && <Companies data={o.data.data} reload={() => o.reload(true)} />}
          {tab === 'plans' && <Plans plans={o.data.data.plans} reload={() => o.reload(true)} />}
          {tab === 'prices' && <Prices />}
          {tab === 'mail' && <MailSettings />}
        </>
      )}
    </>
  );
}

function Companies({ data, reload }) {
  const [create, setCreate] = useState(false);
  const [sub, setSub] = useState(null);
  const [run, busy] = useAction();
  return (
    <>
      <div className="stats-grid">
        <Stat icon={Building2} label="Companies" value={data.tenants.length} sub={`${data.tenants.filter((t) => t.status === 'active').length} active`} />
        <Stat icon={CreditCard} label="Paid plans" value={data.tenants.filter((t) => t.plan && Number(t.plan.price_monthly) > 0).length} />
        <Stat icon={CheckCircle2} label="Chats this month" value={data.tenants.reduce((n, t) => n + (t.usage.conversations_per_month || 0), 0)} />
      </div>
      <div className="filter-bar"><span className="grow" /><Button variant="primary" onClick={() => setCreate(true)}><Plus size={15} />Create company</Button></div>
      <p className="muted small">To work inside a company (inbox, AI settings, knowledge), sign in with that company owner’s account.</p>
      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Company</th><th>Plan</th><th>Websites</th><th>Staff</th><th>Chats (month)</th><th>AI (month)</th><th>Status</th><th /></tr></thead>
          <tbody>
            {data.tenants.map((t) => (
              <tr key={t.id}>
                <td><div className="cell-title">{t.name}</div><div className="cell-sub">{t.email} · since {fmt.date(t.created_at)}</div></td>
                <td>{t.plan?.name || '—'} {t.subscription_status && t.subscription_status !== 'active' && <Badge tone="orange">{t.subscription_status}</Badge>}</td>
                <td>{t.usage.websites}</td><td>{t.usage.staff}</td><td>{t.usage.conversations_per_month}</td><td>{fmt.usd(t.usage.ai_monthly_usd)}</td>
                <td><Badge dot tone={t.status === 'active' ? 'green' : 'red'}>{fmt.label(t.status)}</Badge></td>
                <td className="nowrap">
                  <Button size="sm" onClick={() => setSub({ tenant: t, plan_code: t.plan?.code || 'pro', status: t.subscription_status || 'active' })}><CreditCard size={13} />Plan</Button>
                  <Button size="sm" variant={t.status === 'active' ? 'danger-ghost' : 'default'} disabled={busy} onClick={() => window.confirm(`${t.status === 'active' ? 'Suspend' : 'Re-activate'} ${t.name}?`) && run(async () => { await api(`/platform/tenants/${t.id}/status`, { method: 'POST', body: { status: t.status === 'active' ? 'suspended' : 'active' } }); reload(); }, 'Status changed')}>{t.status === 'active' ? 'Suspend' : 'Activate'}</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {sub && (
        <Modal title={`Plan for ${sub.tenant.name}`} onClose={() => setSub(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api(`/platform/tenants/${sub.tenant.id}/subscription`, { method: 'POST', body: { plan_code: sub.plan_code, status: sub.status } }); setSub(null); reload(); }, 'Subscription updated')}>Save</Button>
        }>
          <Field label="Plan"><Select value={sub.plan_code} onChange={(v) => setSub({ ...sub, plan_code: v })} options={data.plans.map((p) => ({ value: p.code, label: `${p.name} (${p.currency} ${p.price_monthly}/month)` }))} /></Field>
          <Field label="Status"><Select value={sub.status} onChange={(v) => setSub({ ...sub, status: v })} options={['trialing', 'active', 'past_due', 'cancelled'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
          <p className="muted small">Online payments are planned for a later phase. For now, plans are assigned here manually.</p>
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

function Prices() {
  const [f, setF] = useState({ provider: 'anthropic', model: '', input_per_million: '', output_per_million: '' });
  const [saved, setSaved] = useState([]);
  const [run, busy] = useAction();
  return (
    <section className="card">
      <p className="muted">USD per 1 million tokens. A model without a price is never called, because its spending could not be controlled. Check your provider’s current price list before entering values.</p>
      <div className="grid-3">
        <Field label="Provider"><Select value={f.provider} onChange={(v) => setF({ ...f, provider: v })} options={[{ value: 'anthropic', label: 'Anthropic' }, { value: 'openai_compatible', label: 'OpenAI-compatible' }]} /></Field>
        <Field label="Model"><input className="input" value={f.model} onChange={(e) => setF({ ...f, model: e.target.value })} /></Field>
        <Field label="Input $/1M"><input className="input" type="number" step="0.0001" value={f.input_per_million} onChange={(e) => setF({ ...f, input_per_million: e.target.value })} /></Field>
        <Field label="Output $/1M"><input className="input" type="number" step="0.0001" value={f.output_per_million} onChange={(e) => setF({ ...f, output_per_million: e.target.value })} /></Field>
      </div>
      <div className="row end"><Button variant="primary" disabled={busy || !f.model} onClick={() => run(async () => { const r = await api('/ai/prices', { method: 'POST', body: f }); setSaved([...saved, r.data]); }, 'Price saved')}>Save price</Button></div>
      {saved.map((p) => <div key={p.id} className="small row gap-sm"><CheckCircle2 size={14} className="tone-green" /> {p.provider} {p.model}: {p.input_per_million} / {p.output_per_million}</div>)}
    </section>
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
