'use client';

import { useEffect, useState } from 'react';
import { Copy, ExternalLink, Globe, KeyRound, Plus, RefreshCw, Save, Send } from 'lucide-react';
import { api } from '@/lib/api';
import { Badge, Button, Empty, ErrorBox, Field, Loading, Modal, PageHead, Select, Tabs, Toggle, useAction, useApi, useToast } from '@/components/ui';

const IDENTITY = [
  { value: 'pin_optional', label: 'Ask for e-mail & Support PIN, allow quick guest chats (recommended)' },
  { value: 'pin_required', label: 'Always require e-mail & Support PIN' },
  { value: 'guest_only', label: 'Guest chat only (no customer accounts)' },
];

export default function Websites({ ctx }) {
  const list = useApi('/properties');
  const [chosenId, setOpenId] = useState(ctx.defaultPropertyId || null);
  const [adding, setAdding] = useState(false);
  const sites = list.data?.data || [];
  const openId = chosenId || (sites.length === 1 ? sites[0].id : null);
  const current = sites.find((s) => s.id === openId);

  return (
    <>
      <PageHead title="Websites & widget" subtitle="Each website has its own chat widget, admin sign-in address, customers and settings.">
        {ctx.perms.is_company_admin && <Button variant="primary" onClick={() => setAdding(true)}><Plus size={15} />Add website</Button>}
      </PageHead>
      <ErrorBox error={list.error} onRetry={list.reload} />
      {!list.data ? <Loading /> : sites.length === 0 ? <Empty icon={Globe} title="No websites yet">Add your first website to get its chat widget code.</Empty> : (
        <div className="site-layout">
          <ul className="site-list">
            {sites.map((s) => (
              <li key={s.id}><button className={`site-item ${openId === s.id ? 'active' : ''}`} onClick={() => setOpenId(s.id)}>
                <span className="site-icon"><Globe size={16} /></span>
                <span className="grow"><span className="cell-title" style={{ display: 'block' }}>{s.site_name}</span><span className="cell-sub ellipsis" style={{ display: 'block' }}>{s.site_url}</span>
                {!s.is_active && <Badge tone="red">Disabled</Badge>}</span>
              </button></li>
            ))}
          </ul>
          <div>{current ? <WebsiteEditor key={current.id} site={current} ctx={ctx} onSaved={() => { list.reload(true); ctx.reloadMe(); }} /> : <Empty icon={Globe} title="Select a website">Choose a website on the left to edit its settings.</Empty>}</div>
        </div>
      )}
      {adding && <AddWebsite onClose={() => setAdding(false)} onDone={(id) => { list.reload(true); ctx.reloadMe(); setOpenId(id); }} />}
    </>
  );
}

function AddWebsite({ onClose, onDone }) {
  const [f, setF] = useState({ site_name: '', site_url: 'https://' });
  const [run, busy] = useAction();
  return (
    <Modal title="Add a website" onClose={onClose} footer={
      <Button variant="primary" disabled={busy} onClick={() => run(async () => { const r = await api('/properties', { method: 'POST', body: f }); onDone(r.data.id); onClose(); }, 'Website added')}>Add website</Button>
    }>
      <Field label="Website name"><input className="input" value={f.site_name} onChange={(e) => setF({ ...f, site_name: e.target.value })} /></Field>
      <Field label="Website URL" hint="Its domain is added to the widget whitelist automatically."><input className="input" value={f.site_url} onChange={(e) => setF({ ...f, site_url: e.target.value })} /></Field>
    </Modal>
  );
}

function WebsiteEditor({ site, ctx, onSaved }) {
  const [tab, setTab] = useState('settings');
  const canManage = ctx.properties.find((p) => p.id === site.id)?.can_manage;
  return (
    <section className="card">
      <div className="row between wrap" style={{ marginBottom: 6 }}><h2 style={{ margin: 0 }}>{site.site_name}</h2>{site.slug && <a className="card-link" href={`/${site.slug}/admin`} target="_blank" rel="noopener noreferrer">Open admin sign-in <ExternalLink size={13} /></a>}</div>
      {!canManage && <div className="alert">You can view this website but only its administrators can change settings.</div>}
      <Tabs value={tab} onChange={setTab} items={[['settings', 'General'], ['widget', 'Chat widget'], ['embed', 'Install code'], ['webhook', 'Webhook']]} />
      {tab === 'settings' && <SettingsTab site={site} disabled={!canManage} isAdmin={ctx.perms.is_company_admin} onSaved={onSaved} />}
      {tab === 'widget' && <WidgetTab site={site} disabled={!canManage} onSaved={onSaved} />}
      {tab === 'embed' && <EmbedTab site={site} isAdmin={ctx.perms.is_company_admin} onSaved={onSaved} />}
      {tab === 'webhook' && <WebhookTab site={site} disabled={!ctx.perms.is_company_admin} onSaved={onSaved} />}
    </section>
  );
}

function SettingsTab({ site, disabled, isAdmin, onSaved }) {
  const oh = site.widget_config?.operating_hours || {};
  const [f, setF] = useState({
    site_name: site.site_name, site_url: site.site_url, slug: site.slug || '', identity_mode: site.identity_mode || 'guest_only',
    allowed_domains: (site.allowed_domains || []).join(', '), brand_name: site.widget_config?.brand_name || '', is_active: site.is_active,
    hours_enabled: !!oh.enabled, start: oh.start || '09:00', end: oh.end || '18:00', timezone: oh.timezone || 'Asia/Kolkata', offline_notice: oh.offline_notice || '',
  });
  const [run, busy] = useAction();
  const set = (k) => (v) => setF({ ...f, [k]: v });
  const save = () => run(async () => {
    const body = {
      site_name: f.site_name, site_url: f.site_url, slug: f.slug, identity_mode: f.identity_mode, brand_name: f.brand_name || null,
      allowed_domains: f.allowed_domains.split(/[\s,]+/).map((d) => d.trim()).filter(Boolean),
      operating_hours: { enabled: f.hours_enabled, start: f.start, end: f.end, timezone: f.timezone, offline_notice: f.offline_notice || null },
    };
    if (isAdmin) body.is_active = f.is_active;
    await api(`/websites/${site.id}/settings`, { method: 'POST', body });
    onSaved();
  }, 'Website settings saved');

  return (
    <fieldset disabled={disabled} className="plain-fieldset">
      <div className="grid-2">
        <Field label="Website name"><input className="input" value={f.site_name} onChange={(e) => set('site_name')(e.target.value)} /></Field>
        <Field label="Website URL"><input className="input" value={f.site_url} onChange={(e) => set('site_url')(e.target.value)} /></Field>
        <Field label="Admin sign-in address" hint={`Staff of this website sign in at ${typeof window !== 'undefined' ? window.location.origin : ''}/${f.slug || 'address'}/admin`}><input className="input" value={f.slug} onChange={(e) => set('slug')(e.target.value.toLowerCase())} /></Field>
        <Field label="Brand name shown in the chat"><input className="input" value={f.brand_name} placeholder={site.site_name} onChange={(e) => set('brand_name')(e.target.value)} /></Field>
      </div>
      <Field label="Customer sign-in" hint="Customers type their e-mail in the chat, then create or enter a 6-digit Support PIN. A forgotten PIN is reset with an e-mailed code or by an admin."><Select value={f.identity_mode} onChange={set('identity_mode')} options={IDENTITY} /></Field>
      <Field label="Allowed domains" hint="The widget only loads on these domains (subdomains included). Separate with commas."><input className="input" value={f.allowed_domains} onChange={(e) => set('allowed_domains')(e.target.value)} /></Field>
      <h3>Working hours for this website</h3>
      <Toggle checked={f.hours_enabled} onChange={set('hours_enabled')} label="Override the company working hours" />
      {f.hours_enabled && (
        <div className="grid-3">
          <Field label="Start"><input className="input" type="time" value={f.start} onChange={(e) => set('start')(e.target.value)} /></Field>
          <Field label="End"><input className="input" type="time" value={f.end} onChange={(e) => set('end')(e.target.value)} /></Field>
          <Field label="Timezone"><input className="input" value={f.timezone} onChange={(e) => set('timezone')(e.target.value)} /></Field>
          <Field label="Offline message" wide><input className="input" value={f.offline_notice} onChange={(e) => set('offline_notice')(e.target.value)} /></Field>
        </div>
      )}
      {isAdmin && <Toggle checked={f.is_active} onChange={set('is_active')} label="Website active (widget enabled)" />}
      <div className="row end"><Button variant="primary" disabled={busy || disabled} onClick={save}><Save size={15} />Save settings</Button></div>
    </fieldset>
  );
}

function WidgetTab({ site, disabled, onSaved }) {
  const c = site.widget_config || {};
  const [f, setF] = useState({
    primary_color: c.primary_color || '#2563eb', welcome_message: c.welcome_message || '', position: c.position || 'right',
    attachments_enabled: c.attachments_enabled !== false, csat_enabled: c.csat_enabled !== false,
  });
  const [run, busy] = useAction();
  const set = (k) => (v) => setF({ ...f, [k]: v });
  return (
    <fieldset disabled={disabled} className="plain-fieldset">
      <div className="grid-2">
        <Field label="Brand colour"><input className="input color" type="color" value={f.primary_color} onChange={(e) => set('primary_color')(e.target.value)} /></Field>
        <Field label="Position"><Select value={f.position} onChange={set('position')} options={[{ value: 'right', label: 'Bottom right' }, { value: 'left', label: 'Bottom left' }]} /></Field>
      </div>
      <Field label="Welcome message"><textarea className="input" rows={3} value={f.welcome_message} onChange={(e) => set('welcome_message')(e.target.value)} /></Field>
      <Toggle checked={f.attachments_enabled} onChange={set('attachments_enabled')} label="Customers can send screenshots and files" />
      <Toggle checked={f.csat_enabled} onChange={set('csat_enabled')} label="Ask for a rating when a chat is closed" />
      <div className="widget-preview" style={{ '--brand': f.primary_color }}>
        <div className="wp-head">{site.widget_config?.brand_name || site.site_name} Support</div>
        <div className="wp-body">{f.welcome_message || 'Hello! How can we help you today?'}</div>
      </div>
      <div className="row end"><Button variant="primary" disabled={busy || disabled} onClick={() => run(async () => { await api(`/properties/${site.id}`, { method: 'PUT', body: { widget_config: f } }); onSaved(); }, 'Widget saved')}><Save size={15} />Save widget</Button></div>
    </fieldset>
  );
}

function EmbedTab({ site, isAdmin, onSaved }) {
  const toast = useToast();
  const [run, busy] = useAction();
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const code = `<script src="${origin}/widget.js" data-property-key="${site.public_key}" async></script>`;
  const copy = (t) => navigator.clipboard?.writeText(t).then(() => toast('Copied'));
  return (
    <>
      <p>Paste this code just before <code>&lt;/body&gt;</code> on every page of <strong>{site.site_url}</strong>. The chat button appears automatically.</p>
      <pre className="code">{code}</pre>
      <Button onClick={() => copy(code)}><Copy size={15} />Copy code</Button>
      <dl className="kv" style={{ marginTop: 16 }}>
        <dt>Public widget key</dt><dd className="mono break">{site.public_key}</dd>
        {isAdmin && site.secret_key && <><dt>Secret key</dt><dd className="mono break">{site.secret_key} <span className="muted small">(server-side only. Never put it in a web page.)</span></dd></>}
      </dl>
      {isAdmin && <Button variant="danger-ghost" disabled={busy} onClick={() => window.confirm('Regenerate keys? The embed code on your website stops working until you update it.') && run(async () => { await api(`/properties/${site.id}/regenerate-keys`, { method: 'POST' }); onSaved(); }, 'Keys regenerated. Update the install code on your website.')}><RefreshCw size={15} />Regenerate keys</Button>}
    </>
  );
}

function WebhookTab({ site, disabled, onSaved }) {
  const c = site.widget_config || {};
  const [url, setUrl] = useState(c.webhook_url || '');
  const [secret, setSecret] = useState('');
  const [run, busy] = useAction();
  const toast = useToast();
  return (
    <fieldset disabled={disabled} className="plain-fieldset">
      <p className="muted">Send new messages, ratings and closed chats to your CRM or Zapier as signed JSON. Private and internal network addresses are not allowed.</p>
      <Field label="Webhook URL"><input className="input" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://hooks.example.com/…" /></Field>
      <Field label="Signing secret" hint={c.webhook_secret_set ? 'A secret is saved. Leave empty to keep it.' : 'Optional. Used for the X-ChatBox-Signature header.'}><input className="input" type="password" autoComplete="new-password" value={secret} onChange={(e) => setSecret(e.target.value)} /></Field>
      <div className="row gap end">
        <Button disabled={busy || !url} onClick={() => run(async () => {
          try {
            const r = await api(`/properties/${site.id}/test-webhook`, { method: 'POST', body: { webhook_url: url, webhook_secret: secret || undefined } });
            toast(r.message);
          } catch (e) { toast(e.message, 'err'); }
        })}><Send size={15} />Send test</Button>
        <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api(`/properties/${site.id}`, { method: 'PUT', body: { widget_config: { webhook_url: url || null, webhook_secret: secret } } }); setSecret(''); onSaved(); }, 'Webhook saved')}><Save size={15} />Save webhook</Button>
      </div>
    </fieldset>
  );
}
