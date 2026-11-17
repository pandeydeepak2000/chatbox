'use client';

import { useState } from 'react';
import { api, fmt } from '@/lib/api';
import { AlertTriangle, Bot, CheckCircle2, DollarSign, Gauge, KeyRound, Plug, Plus, Save, Search, Trash2, Wallet, XCircle } from 'lucide-react';
import { Badge, Button, CardHead, ErrorBox, Field, Loading, Modal, PageHead, Select, Stat, Toggle, useAction, useApi } from '@/components/ui';

/** Why AI replies are not running right now (set by the server, never guessed here). */
const STATUS_TEXT = {
  disabled: 'AI replies are switched off. FAQs still answer instantly, and other questions go to your team.',
  no_key: 'Add an API key for the active provider to enable AI replies.',
  no_price: 'The active model has no confirmed price, so spending cannot be controlled. Enter its input/output price below (USD per 1M tokens).',
  provider_disabled: 'The active provider is disabled. Enable it or pick another provider.',
  provider_not_approved: 'This custom provider is waiting for platform approval, so it cannot be used yet.',
  model_disabled: 'The active model is disabled. Enable it or choose another default model.',
  budget_month: 'The monthly AI budget has been reached. Only FAQs are answered automatically until next month, or until you raise the budget.',
  budget_day: 'Today’s AI budget has been reached. Only FAQs are answered automatically until tomorrow.',
};

const CONNECTION = {
  ok: ['green', 'Connected'], untested: ['gray', 'Not tested'], no_key: ['gray', 'No API key'], invalid_credentials: ['red', 'Invalid API key'],
  rate_limited: ['orange', 'Rate limited'], unavailable: ['orange', 'Provider unavailable'], unreachable: ['red', 'Unreachable'],
  blocked: ['red', 'Address rejected'], not_found: ['orange', 'Not found'], no_catalog: ['gray', 'No model list'], error: ['red', 'Error'],
};
const MODEL_TEST = {
  available: ['green', 'Available'], untested: ['gray', 'Not tested'], unavailable: ['red', 'Unavailable'], auth_failed: ['red', 'Key rejected'],
  rate_limited: ['orange', 'Rate limited'], error: ['red', 'Test failed'],
};
const CATALOG = { listed: ['green', 'Listed by provider'], unlisted: ['orange', 'Not in provider list'], unknown: ['gray', 'Not checked'] };
const APPROVAL = { approved: ['green', 'Approved'], pending: ['orange', 'Awaiting platform approval'], rejected: ['red', 'Rejected'] };

const Chip = ({ map, value }) => { const [tone, label] = map[value] || ['gray', fmt.label(value)]; return <Badge tone={tone}>{label}</Badge>; };

export default function AiSettings() {
  const s = useApi('/ai/settings');
  const pv = useApi('/ai/providers');
  const usage = useApi('/ai/usage', { poll: 60000 });
  const [form, setForm] = useState(null);
  const [sel, setSel] = useState(null);          // provider slug being configured
  const [keyInput, setKeyInput] = useState('');
  const [modal, setModal] = useState(null);      // 'model' | 'custom' | { edit: model }
  const [run, busy] = useAction();
  const d = s.data?.data;
  const pd = pv.data?.data;

  if (d && !form) setForm({ ...d.settings, ...d.budget, instructions: d.settings.instructions || '', service_scope: d.settings.service_scope || '' });
  if (s.error) return <ErrorBox error={s.error} onRetry={s.reload} />;
  if (pv.error) return <ErrorBox error={pv.error} onRetry={pv.reload} />;
  if (!d || !form || !pd) return <Loading />;

  const providers = pd.providers;
  const active = providers.find((p) => p.id && p.id === pd.active.provider_id);
  const customRows = providers.filter((p) => p.type === 'custom');
  // "Custom Provider" is always an option. With none added yet it is a placeholder that offers to add one.
  const PLACEHOLDER = { slug: '__custom__', type: 'custom', label: 'Custom Provider', models: [], api_key_set: false, enabled: true, isPlaceholder: true };
  const current = sel === '__custom__' ? (customRows[0] || PLACEHOLDER) : (providers.find((p) => p.slug === sel) || active || providers[0]);
  const activeModel = (active?.models || []).find((m) => m.id === pd.active.model_id);
  const set = (k) => (v) => setForm({ ...form, [k]: v });
  const u = usage.data?.data;
  const refresh = () => { pv.reload(); s.reload(); };
  // Run an action and always refresh: a failed test still changes the stored status the page shows.
  const act = (fn, ok) => run(async () => { try { return await fn(); } finally { refresh(); } }, ok);
  const post = (path, body = {}) => api(path, { method: 'POST', body });

  const saveSettings = () => act(async () => {
    // The ACTIVE provider/model/address are sent back unchanged: this form never switches provider by accident.
    await api('/ai/settings', { method: 'POST', body: {
      enabled: form.enabled, provider: d.settings.provider, model: d.settings.model, base_url: d.settings.base_url || null, effort: form.effort,
      max_output_tokens: form.max_output_tokens, instructions: form.instructions, service_scope: form.service_scope,
      monthly_cap_usd: form.monthly_cap_usd, daily_cap_usd: form.daily_cap_usd, per_visitor_daily_messages: form.per_visitor_daily_messages, alert_threshold_pct: form.alert_threshold_pct,
    } });
    setForm(null);
  }, 'AI settings saved');

  const providerOptions = [
    ...providers.filter((p) => p.type !== 'custom').map((p) => ({ value: p.slug, label: `${p.label} — ${p.subtitle}` })),
    ...(customRows.length
      ? customRows.map((p) => ({ value: p.slug, label: `${p.name} — Custom provider` }))
      : [{ value: '__custom__', label: 'Custom Provider — Administrator-approved OpenAI-compatible API' }]),
  ];
  const isAnthropicActive = d.settings.provider === 'anthropic';

  return (
    <>
      <PageHead title="AI settings" subtitle="Choose the AI provider and model, keep your own API key, and control what the assistant may spend. The assistant only answers about your own services.">
        <Button variant="primary" disabled={busy} onClick={saveSettings}><Save size={15} />Save settings</Button>
      </PageHead>

      {/* Four distinct states, never merged into one: a good connection does not mean the model works or that AI is on. */}
      <div className="stats-grid">
        <Stat icon={Plug} label="API connection" value={active ? <Chip map={CONNECTION} value={active.connection_status} /> : <Badge>No provider selected</Badge>} sub={active ? active.label : 'Pick a provider and a model'} />
        <Stat icon={Bot} label="Selected model" value={activeModel ? <Chip map={MODEL_TEST} value={activeModel.test_status} /> : <Badge>None</Badge>} sub={activeModel ? activeModel.display_name : '—'} />
        <Stat icon={CheckCircle2} label="AI replies" value={<Badge tone={form.enabled ? (d.status ? 'orange' : 'green') : 'gray'}>{form.enabled ? (d.status ? 'Enabled, paused' : 'Enabled') : 'Disabled'}</Badge>} sub={d.status ? 'See the notice below' : 'Your decision: never automatic'} />
        <Stat icon={Wallet} label="Spent this month" value={u ? fmt.usd(u.month_spent_usd) : '—'} sub={u ? `of ${fmt.usd(u.monthly_cap_usd)} monthly budget` : ''} tone={u && u.month_spent_usd >= u.monthly_cap_usd * 0.8 ? 'warn' : undefined} />
      </div>
      {d.status
        ? <div className="alert alert-warn"><AlertTriangle size={16} /><span>{STATUS_TEXT[d.status] || d.status}</span></div>
        : <div className="alert alert-ok"><CheckCircle2 size={16} /><span>AI replies are active. They use approved FAQs and knowledge only and stay within your services.</span></div>}

      <div className="grid-2">
        <section className="card">
          <CardHead icon={Bot} title="Assistant" />
          <Toggle checked={form.enabled} onChange={set('enabled')} label="Answer customers with AI (approved knowledge only)" />
          <p className="muted small">A successful connection or test never switches this on. Turn it on yourself, then press Save settings.</p>

          <Field label="Provider">
            <Select value={current.slug} onChange={(v) => { setSel(v); setKeyInput(''); }} options={providerOptions} aria-label="Provider" />
          </Field>
          {current.isPlaceholder && (
            <div className="alert alert-warn" style={{ alignItems: 'center' }}>
              <AlertTriangle size={16} />
              <span>No custom provider has been added yet. A custom provider must be an https OpenAI-compatible API and is approved by the platform before it can be used.</span>
              <Button size="sm" onClick={() => setModal('custom')}><Plus size={14} />Add custom provider</Button>
            </div>
          )}
          {current.type === 'custom' && !current.isPlaceholder && (
            <p className="small muted">
              {current.base_url} · <Chip map={APPROVAL} value={current.approval_status} />{current.approval_note ? ` ${current.approval_note}` : ''}
            </p>
          )}

          {!current.isPlaceholder && <Field label="API key" hint={current.api_key_set ? `Saved (${current.api_key_hint}). It is stored encrypted and is never shown again.` : 'Stored encrypted and never shown again after saving. Each provider has its own key.'}>
            <div className="row">
              <input className="input" type="password" autoComplete="new-password" placeholder={current.api_key_set ? 'Enter a new key to replace it' : 'Paste the API key'} value={keyInput} onChange={(e) => setKeyInput(e.target.value)} aria-label="API key" />
              <Button disabled={busy || keyInput.trim().length < 8} onClick={() => act(async () => { await post(`/ai/providers/${current.slug}`, { api_key: keyInput.trim() }); setKeyInput(''); }, 'API key saved')}><KeyRound size={15} />Save key</Button>
            </div>
          </Field>}
          {!current.isPlaceholder && <><div className="row">
            <Button disabled={busy || !current.api_key_set} onClick={() => act(() => post(`/ai/providers/${current.slug}/test`), 'Connected')}><Plug size={15} />Test connection</Button>
            <Chip map={CONNECTION} value={current.connection_status} />
            <span className="small muted">{current.connection_message}</span>
          </div>
          <div className="row">
            <Toggle checked={current.enabled} onChange={(v) => act(() => post(`/ai/providers/${current.slug}`, { enabled: v }))} label="Provider enabled" />
            {current.api_key_set && <Button size="sm" disabled={busy} onClick={() => { if (window.confirm(`Remove the saved key for ${current.label}? AI replies using it will stop.`)) act(() => api(`/ai/providers/${current.slug}`, { method: 'DELETE' }), 'Removed'); }}><Trash2 size={14} />Remove key / provider</Button>}
          </div></>}

          <Field label="Services we support" hint="One per line. The assistant answers about these (and your approved knowledge) only; anything unrelated is politely redirected without a ticket.">
            <textarea className="input" rows={4} value={form.service_scope} placeholder={'Shared hosting\nDomain registration\nVPS\nSSL certificates'} onChange={(e) => set('service_scope')(e.target.value)} aria-label="Services we support" />
          </Field>
          <Field label="Guidance for the assistant" hint="Tone of voice, approved policies and anything it must never promise. Customers cannot override these rules.">
            <textarea className="input" rows={4} value={form.instructions} onChange={(e) => set('instructions')(e.target.value)} />
          </Field>
          {isAnthropicActive && (
            <Field label="Effort" hint="Lower effort gives faster, cheaper replies. Low is recommended for support."><Select value={form.effort} onChange={set('effort')} options={['low', 'medium', 'high'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
          )}
          <Field label="Maximum reply length (tokens)"><input className="input" type="number" min={256} max={8000} value={form.max_output_tokens} onChange={(e) => set('max_output_tokens')(Number(e.target.value))} /></Field>
        </section>

        <section className="card">
          <CardHead icon={Gauge} title="Spending limits" />
          <Field label="Monthly budget (USD)"><input className="input" type="number" step="0.01" min={0} value={form.monthly_cap_usd} onChange={(e) => set('monthly_cap_usd')(e.target.value)} /></Field>
          <Field label="Daily budget (USD)"><input className="input" type="number" step="0.01" min={0} value={form.daily_cap_usd} onChange={(e) => set('daily_cap_usd')(e.target.value)} /></Field>
          <Field label="AI replies per customer per day"><input className="input" type="number" min={0} value={form.per_visitor_daily_messages} onChange={(e) => set('per_visitor_daily_messages')(Number(e.target.value))} /></Field>
          <Field label="Send an alert at (% of monthly budget)"><input className="input" type="number" min={10} max={100} value={form.alert_threshold_pct} onChange={(e) => set('alert_threshold_pct')(Number(e.target.value))} /></Field>
          <p className="muted small">When a limit is reached the assistant answers from FAQs only and hands every service question to your team. Company admins are alerted in the dashboard and on Telegram.</p>
          {u && <p className="small"><DollarSign size={13} /> Today: {fmt.usd(u.today_spent_usd)} of {fmt.usd(u.daily_cap_usd)} · AI answers this month: {u.outcomes?.answered || 0}</p>}
        </section>
      </div>

      {!current.isPlaceholder && <section className="card">
        <CardHead icon={Bot} title={`Models — ${current.label}`}>
          <div className="row">
            <Button size="sm" disabled={busy} onClick={() => setModal('model')}><Plus size={14} />Add model</Button>
            <Button size="sm" disabled={busy} onClick={() => setModal('custom')}><Plus size={14} />Add custom provider</Button>
          </div>
        </CardHead>
        <p className="muted small">Models come from the provider itself. A saved model is not assumed to work: use Test to check it with your key.</p>
        <div className="table-wrap">
          <table className="table compact">
            <thead><tr><th>Model</th><th>Status</th><th>Price per 1M tokens</th><th /></tr></thead>
            <tbody>
              {current.models.length === 0 && <tr><td colSpan={4} className="muted">No model added for this provider yet. Press “Add model”.</td></tr>}
              {current.models.map((m) => (
                <tr key={m.id}>
                  <td>
                    <strong>{m.display_name}</strong> {m.is_active && <Badge tone="blue">Active</Badge>} {!m.enabled && <Badge>Disabled</Badge>}
                    <div className="mono small muted">{m.model_id}</div>
                  </td>
                  <td>
                    <Chip map={MODEL_TEST} value={m.test_status} /> <Chip map={CATALOG} value={m.catalog_status} />
                    {m.test_message && <div className="small muted">{m.test_message}</div>}
                  </td>
                  <td>{m.price_set ? `in $${m.input_per_million} / out $${m.output_per_million}` : <Badge tone="orange">Price needed</Badge>}</td>
                  <td className="nowrap">
                    <Button size="sm" disabled={busy || !current.api_key_set} onClick={() => act(() => post(`/ai/models/${m.id}/test`), 'Model is available')}>Test</Button>{' '}
                    <Button size="sm" disabled={busy || m.is_active || !m.enabled} onClick={() => act(() => post(`/ai/models/${m.id}/default`), 'Default model set')}>Set default</Button>{' '}
                    <Button size="sm" disabled={busy} onClick={() => setModal({ edit: m })}>Edit</Button>{' '}
                    <Button size="sm" disabled={busy} onClick={() => act(() => post(`/ai/models/${m.id}`, { enabled: !m.enabled }))}>{m.enabled ? 'Disable' : 'Enable'}</Button>{' '}
                    <Button size="sm" disabled={busy || m.is_active} onClick={() => { if (window.confirm(`Delete ${m.display_name}?`)) act(() => api(`/ai/models/${m.id}`, { method: 'DELETE' }), 'Model deleted'); }}><XCircle size={13} /></Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>}

      {u && (
        <section className="card">
          <CardHead icon={Bot} title="Recent AI requests" />
          <div className="table-wrap">
            <table className="table compact">
              <thead><tr><th>Time</th><th>Provider / model</th><th>Tokens in / out</th><th>Cost</th><th>Latency</th><th>Outcome</th></tr></thead>
              <tbody>
                {u.recent.length === 0 && <tr><td colSpan={6} className="muted">No AI requests yet. They appear here once AI replies are switched on.</td></tr>}
                {u.recent.map((r) => (
                  <tr key={r.id}>
                    <td className="small nowrap">{fmt.date(r.created_at)}</td>
                    <td className="mono small">{r.provider} · {r.model}</td>
                    <td>{r.input_tokens} / {r.output_tokens}</td>
                    <td>{fmt.usd(r.cost_usd)}</td>
                    <td>{(r.latency_ms / 1000).toFixed(1)} s</td>
                    <td><Badge tone={r.outcome === 'answered' ? 'green' : r.outcome === 'error' ? 'red' : 'orange'}>{fmt.label(r.outcome)}</Badge>{r.error && <div className="small muted">{r.error}</div>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {modal === 'model' && <ModelModal provider={current} onClose={() => setModal(null)} onDone={() => { setModal(null); refresh(); }} />}
      {modal && modal.edit && <ModelModal provider={current} model={modal.edit} onClose={() => setModal(null)} onDone={() => { setModal(null); refresh(); }} />}
      {modal === 'custom' && <CustomProviderModal onClose={() => setModal(null)} onDone={(slug) => { setModal(null); setSel(slug); refresh(); }} />}
    </>
  );
}

/** Add a model (live search of the provider's list, or an exact manual ID) or edit its name/price. */
function ModelModal({ provider, model, onClose, onDone }) {
  const editing = !!model;
  const [q, setQ] = useState('');
  const [found, setFound] = useState(null);       // null = not searched
  const [note, setNote] = useState('');
  const [f, setF] = useState({
    model_id: model?.model_id || '', display_name: model?.display_name || '',
    input_per_million: model?.input_per_million ?? '', output_per_million: model?.output_per_million ?? '', test: true, make_default: false,
  });
  const [run, busy] = useAction();

  const search = async () => {
    setNote('');
    try {
      const r = await api(`/ai/providers/${provider.slug}/discover`, { method: 'POST', body: { q } });
      setFound(r.data.models);
      if (!r.data.models.length) setNote('The provider returned no matching model. You can still type an exact model ID below.');
    } catch (e) {
      setFound([]);
      setNote(`${e.message} You can still type an exact model ID below.`);
    }
  };
  const priced = f.input_per_million !== '' && f.output_per_million !== '';
  const save = () => run(async () => {
    if (editing) {
      const body = { display_name: f.display_name || undefined };
      if (priced) { body.input_per_million = Number(f.input_per_million); body.output_per_million = Number(f.output_per_million); }
      await api(`/ai/models/${model.id}`, { method: 'POST', body });
    } else {
      await api(`/ai/providers/${provider.slug}/models`, { method: 'POST', body: {
        model_id: f.model_id.trim(), display_name: f.display_name || undefined, test: f.test, make_default: f.make_default,
        ...(priced ? { input_per_million: Number(f.input_per_million), output_per_million: Number(f.output_per_million) } : {}),
      } });
    }
    onDone();
  }, editing ? 'Model updated' : 'Model added');

  return (
    <Modal title={editing ? `Edit ${model.display_name}` : `Add model — ${provider.label}`} onClose={onClose} width={620}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" disabled={busy || (!editing && !f.model_id.trim())} onClick={save}>{editing ? 'Save' : 'Save model'}</Button></>}>
      {!editing && (
        <>
          {provider.api_key_set ? (
            <div className="row">
              <input className="input" placeholder="Search the provider's models…" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} aria-label="Search models" />
              <Button disabled={busy} onClick={search}><Search size={14} />Search</Button>
            </div>
          ) : <p className="muted small">Save an API key for this provider to search its model list. You can always type an exact model ID.</p>}
          {note && <p className="small muted">{note}</p>}
          {found && found.length > 0 && (
            <div className="table-wrap" style={{ maxHeight: 200, overflow: 'auto' }}>
              <table className="table compact"><tbody>
                {found.map((m) => (
                  <tr key={m.id} style={{ cursor: 'pointer' }} onClick={() => setF({ ...f, model_id: m.id, display_name: f.display_name || m.name })}>
                    <td className="mono small">{m.id}</td><td className="small muted">{m.name !== m.id ? m.name : ''} {m.lifecycle === 'deprecated' && <Badge tone="orange">deprecated</Badge>}</td>
                    <td>{m.added ? <Badge>Added</Badge> : <Button size="sm">Select</Button>}</td>
                  </tr>
                ))}
              </tbody></table>
            </div>
          )}
          <Field label="Exact model ID" hint="Pick from the list above or type it exactly as the provider documents it."><input className="input" value={f.model_id} onChange={(e) => setF({ ...f, model_id: e.target.value })} aria-label="Model ID" /></Field>
        </>
      )}
      <Field label="Display name" hint="A friendly name only your team sees."><input className="input" value={f.display_name} onChange={(e) => setF({ ...f, display_name: e.target.value })} aria-label="Display name" /></Field>
      <div className="grid-2">
        <Field label="Input price (USD per 1M tokens)"><input className="input" type="number" min={0} step="0.0001" value={f.input_per_million} onChange={(e) => setF({ ...f, input_per_million: e.target.value })} aria-label="Input price" /></Field>
        <Field label="Output price (USD per 1M tokens)"><input className="input" type="number" min={0} step="0.0001" value={f.output_per_million} onChange={(e) => setF({ ...f, output_per_million: e.target.value })} aria-label="Output price" /></Field>
      </div>
      <p className="small muted">Check these on your provider’s pricing page. ChatBox never guesses a price: without both values AI cannot be switched on for this model, because its spend could not be limited.</p>
      {!editing && (
        <>
          <Toggle checked={f.test} onChange={(v) => setF({ ...f, test: v })} label="Test the model after saving (one small request)" />
          <Toggle checked={f.make_default} onChange={(v) => setF({ ...f, make_default: v })} label="Set as the default model" />
        </>
      )}
    </Modal>
  );
}

/** Add a custom OpenAI-compatible provider. It must be https, public and approved by the platform before use. */
function CustomProviderModal({ onClose, onDone }) {
  const [f, setF] = useState({ name: '', base_url: '', api_key: '', models_path: '/models', model_id: '', timeout_seconds: 25, json_mode: false, enabled: true });
  const [err, setErr] = useState('');
  const [result, setResult] = useState(null);
  const [run, busy] = useAction();

  const save = () => run(async () => {
    setErr('');
    try {
      const r = await api('/ai/providers/custom', { method: 'POST', body: { ...f, protocol: 'openai', models_path: f.models_path || undefined, model_id: f.model_id || undefined } });
      setResult(r.data);
    } catch (e) {
      setErr(e.message);
    }
  });

  if (result) {
    return (
      <Modal title="Custom provider added" onClose={() => onDone(result.slug)} footer={<Button variant="primary" onClick={() => onDone(result.slug)}>Done</Button>}>
        {result.approval_status === 'approved'
          ? <div className="alert alert-ok"><CheckCircle2 size={16} /><span>{result.name} is approved and ready. Add or test a model, then choose it as default.</span></div>
          : <div className="alert alert-warn"><AlertTriangle size={16} /><span>{result.name} is waiting for platform approval. It cannot be tested or used until the platform administrator approves its address. Your key is stored encrypted and is never shown to anyone.</span></div>}
      </Modal>
    );
  }
  return (
    <Modal title="Add custom provider" onClose={onClose} width={620}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" disabled={busy || !f.name || !f.base_url || f.api_key.length < 8} onClick={save}>Add provider</Button></>}>
      <p className="small muted">For an OpenAI-compatible chat completions API. The address must be https on port 443, a public host name, and is approved by the platform before use.</p>
      {err && <div className="alert alert-warn"><AlertTriangle size={16} /><span>{err}</span></div>}
      <div className="grid-2">
        <Field label="Provider name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} aria-label="Provider name" /></Field>
        <Field label="Protocol"><Select value="openai" onChange={() => {}} options={[{ value: 'openai', label: 'OpenAI-compatible (chat completions)' }]} aria-label="Protocol" /></Field>
      </div>
      <Field label="API base URL" hint="e.g. https://api.example.com/v1 (requests go to …/chat/completions)"><input className="input" value={f.base_url} onChange={(e) => setF({ ...f, base_url: e.target.value })} aria-label="API base URL" /></Field>
      <Field label="API key" hint="Stored encrypted and never shown again."><input className="input" type="password" autoComplete="new-password" value={f.api_key} onChange={(e) => setF({ ...f, api_key: e.target.value })} aria-label="Custom provider API key" /></Field>
      <div className="grid-2">
        <Field label="Model discovery path (optional)" hint="Leave empty if the provider has no model list."><input className="input" value={f.models_path} onChange={(e) => setF({ ...f, models_path: e.target.value })} /></Field>
        <Field label="First model ID (optional)"><input className="input" value={f.model_id} onChange={(e) => setF({ ...f, model_id: e.target.value })} aria-label="Manual model ID" /></Field>
        <Field label="Connection timeout (seconds)"><input className="input" type="number" min={5} max={60} value={f.timeout_seconds} onChange={(e) => setF({ ...f, timeout_seconds: Number(e.target.value) })} /></Field>
      </div>
      <Toggle checked={f.json_mode} onChange={(v) => setF({ ...f, json_mode: v })} label="This API accepts response_format (JSON mode)" />
      <Toggle checked={f.enabled} onChange={(v) => setF({ ...f, enabled: v })} label="Enabled" />
    </Modal>
  );
}
