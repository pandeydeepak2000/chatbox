'use client';

import { useEffect, useState } from 'react';
import { api, fmt } from '@/lib/api';
import { AlertTriangle, Bot, CheckCircle2, DollarSign, Gauge, Save, Wallet } from 'lucide-react';
import { Badge, Button, CardHead, ErrorBox, Field, Loading, PageHead, Select, Stat, Toggle, useAction, useApi } from '@/components/ui';

const STATUS_TEXT = {
  disabled: 'AI replies are switched off. FAQs still answer instantly, and other questions go to your team.',
  no_key: 'Add an API key to enable AI replies.',
  no_price: 'This model has no price in the price table, so spending cannot be controlled. Ask the platform administrator to add its price.',
  budget_month: 'The monthly AI budget has been reached. Only FAQs are answered automatically until next month, or until you raise the budget.',
  budget_day: 'Today’s AI budget has been reached. Only FAQs are answered automatically until tomorrow.',
};
const MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

export default function AiSettings() {
  const s = useApi('/ai/settings');
  const usage = useApi('/ai/usage', { poll: 60000 });
  const [form, setForm] = useState(null);
  const [run, busy] = useAction();
  const d = s.data?.data;

  if (d && !form) setForm({ ...d.settings, ...d.budget, api_key: '', instructions: d.settings.instructions || '', base_url: d.settings.base_url || '' });

  if (s.error) return <ErrorBox error={s.error} onRetry={s.reload} />;
  if (!d || !form) return <Loading />;
  const set = (k) => (v) => setForm({ ...form, [k]: v });
  const u = usage.data?.data;
  const save = () => run(async () => {
    const r = await api('/ai/settings', { method: 'POST', body: { ...form, api_key: form.api_key || undefined, base_url: form.base_url || null } });
    s.setData(r);
    setForm({ ...form, api_key: '' });
  }, 'AI settings saved');

  return (
    <>
      <PageHead title="AI settings" subtitle="Choose the AI provider and model, add your own API key, and control how much the assistant may spend." />
      {d.status ? <div className="alert alert-warn"><AlertTriangle size={16} /><span>{STATUS_TEXT[d.status] || d.status}</span></div> : <div className="alert alert-ok"><CheckCircle2 size={16} /><span>AI replies are active and answer only from approved knowledge.</span></div>}

      {u && (
        <div className="stats-grid">
          <Stat icon={Wallet} label="Spent this month" value={fmt.usd(u.month_spent_usd)} sub={`of ${fmt.usd(u.monthly_cap_usd)} monthly budget`} tone={u.month_spent_usd >= u.monthly_cap_usd * 0.8 ? 'warn' : undefined} />
          <Stat icon={DollarSign} label="Spent today" value={fmt.usd(u.today_spent_usd)} sub={`Daily limit ${fmt.usd(u.daily_cap_usd)}`} />
          <Stat icon={Bot} label="AI answers this month" value={u.outcomes?.answered || 0} sub={`${(u.outcomes?.escalate || 0) + (u.outcomes?.error || 0) + (u.outcomes?.refused || 0)} handed to the team or failed`} />
        </div>
      )}

      <div className="grid-2">
        <section className="card">
          <CardHead icon={Bot} title="Assistant" />
          <Toggle checked={form.enabled} onChange={set('enabled')} label="Answer customers with AI (approved knowledge only)" />
          <Field label="Provider"><Select value={form.provider} onChange={set('provider')} options={d.providers} /></Field>
          {form.provider === 'openai_compatible' && (
            <Field label="API base URL" hint="For OpenAI-compatible APIs, e.g. https://api.openai.com/v1"><input className="input" value={form.base_url} onChange={(e) => set('base_url')(e.target.value)} /></Field>
          )}
          <Field label="Model" hint={form.provider === 'anthropic' ? 'claude-opus-5-5 is the default; claude-haiku-5-5 is the lowest-cost option.' : 'Exact model name from your provider.'}>
            <input className="input" list="model-list" value={form.model} onChange={(e) => set('model')(e.target.value)} />
            <datalist id="model-list">{MODELS.map((m) => <option key={m} value={m} />)}</datalist>
          </Field>
          {form.provider === 'anthropic' && (
            <Field label="Effort" hint="Lower effort gives faster, cheaper replies. Low is recommended for support."><Select value={form.effort} onChange={set('effort')} options={['low', 'medium', 'high'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
          )}
          <Field label="API key" hint={d.settings.api_key_set ? `Saved (${d.settings.api_key_hint}). Leave empty to keep it.` : 'Stored encrypted and never shown again after saving.'}>
            <input className="input" type="password" autoComplete="new-password" value={form.api_key} onChange={(e) => set('api_key')(e.target.value)} />
          </Field>
          <Field label="Maximum reply length (tokens)"><input className="input" type="number" min={256} max={8000} value={form.max_output_tokens} onChange={(e) => set('max_output_tokens')(Number(e.target.value))} /></Field>
          <Field label="Guidance for the assistant" hint="Tone of voice, approved policies and anything it must never promise. Customers cannot override these rules.">
            <textarea className="input" rows={5} value={form.instructions} onChange={(e) => set('instructions')(e.target.value)} />
          </Field>
        </section>
        <section className="card">
          <CardHead icon={Gauge} title="Spending limits" />
          <Field label="Monthly budget (USD)"><input className="input" type="number" step="0.01" min={0} value={form.monthly_cap_usd} onChange={(e) => set('monthly_cap_usd')(e.target.value)} /></Field>
          <Field label="Daily budget (USD)"><input className="input" type="number" step="0.01" min={0} value={form.daily_cap_usd} onChange={(e) => set('daily_cap_usd')(e.target.value)} /></Field>
          <Field label="AI replies per customer per day"><input className="input" type="number" min={0} value={form.per_visitor_daily_messages} onChange={(e) => set('per_visitor_daily_messages')(Number(e.target.value))} /></Field>
          <Field label="Send an alert at (% of monthly budget)"><input className="input" type="number" min={10} max={100} value={form.alert_threshold_pct} onChange={(e) => set('alert_threshold_pct')(Number(e.target.value))} /></Field>
          <p className="muted small">When a limit is reached the assistant answers from FAQs only and hands every other question to your team. Company admins are alerted in the dashboard and on Telegram.</p>
          <h3>Prices used for cost tracking (USD per 1M tokens)</h3>
          <table className="table compact">
            <thead><tr><th>Model</th><th>Input</th><th>Output</th></tr></thead>
            <tbody>{d.prices.map((p) => <tr key={`${p.provider}${p.model}`}><td className="mono small">{p.model}</td><td>{p.input_per_million}</td><td>{p.output_per_million}</td></tr>)}</tbody>
          </table>
        </section>
      </div>
      <div className="sticky-actions"><Button variant="primary" disabled={busy} onClick={save}><Save size={15} />Save AI settings</Button></div>

      {u && (
        <section className="card">
          <CardHead icon={Bot} title="Recent AI requests" />
          <div className="table-wrap">
            <table className="table compact">
              <thead><tr><th>Time</th><th>Model</th><th>Tokens in / out</th><th>Cost</th><th>Latency</th><th>Outcome</th></tr></thead>
              <tbody>
                {u.recent.length === 0 && <tr><td colSpan={6} className="muted">No AI requests yet. They appear here once AI replies are switched on.</td></tr>}
                {u.recent.map((r) => (
                  <tr key={r.id}>
                    <td className="small nowrap">{fmt.date(r.created_at)}</td>
                    <td className="mono small">{r.model}</td>
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
    </>
  );
}
