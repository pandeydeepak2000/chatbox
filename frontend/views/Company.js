'use client';

import { useEffect, useState } from 'react';
import { Building2, CreditCard, Save, UserRound } from 'lucide-react';
import { api } from '@/lib/api';
import { Button, CardHead, ErrorBox, Field, Loading, PageHead, Select, useAction, useApi } from '@/components/ui';

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']];

export default function Company({ ctx }) {
  const c = useApi('/company');
  const agents = useApi('/agents');
  const [f, setF] = useState(null);
  const [profile, setProfile] = useState({ name: ctx.me.user.name, email: ctx.me.user.email, password: '', current_password: '' });
  const [run, busy] = useAction();
  const d = c.data?.data;

  if (d && !f) setF({ name: d.name, slug: d.slug || '', timezone: d.timezone, ticket_prefix: d.ticket_prefix, working_hours: d.working_hours, sales_followup_user_id: d.sales_followup_user_id || '', session_timeout_minutes: d.session_timeout_minutes ?? 30 });
  if (ctx.perms.is_super_admin && !ctx.me.user.tenant_id) return <><PageHead title="Company settings" /><p className="muted">Platform super admins are not part of a company. Use the Super Admin page.</p><ProfileCard profile={profile} setProfile={setProfile} run={run} busy={busy} /></>;
  if (c.error) return <ErrorBox error={c.error} onRetry={c.reload} />;
  if (!d || !f) return <Loading />;
  const wh = f.working_hours;
  const limits = d.subscription?.limits || {};

  return (
    <>
      <PageHead title="Company settings" subtitle="Your company details, working hours, ticket references and plan." />
      <div className="grid-2">
        <fieldset className="card plain-fieldset" disabled={!d.can_edit}>
          <CardHead icon={Building2} title="Company" />
          <Field label="Company name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <Field label="Company address" hint={`Your team signs in at ${typeof window !== 'undefined' ? window.location.origin : ''}/${f.slug || 'your-company'} (also /${f.slug || 'your-company'}/inbox, /tickets …). 3–40 lowercase letters, numbers or hyphens. Changing it changes that link; /app keeps working.`}>
            <input className="input" value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })} aria-label="Company address" />
          </Field>
          <div className="grid-2">
            <Field label="Ticket prefix" hint="For example AMB gives references like AMB-000123."><input className="input" value={f.ticket_prefix} onChange={(e) => setF({ ...f, ticket_prefix: e.target.value.toUpperCase() })} /></Field>
            <Field label="Timezone"><input className="input" value={f.timezone} onChange={(e) => setF({ ...f, timezone: e.target.value, working_hours: { ...wh, timezone: e.target.value } })} /></Field>
          </div>
          <h3>Working hours</h3>
          <p className="muted small">Used for live hand-over, after-hours replies and the 30-working-minute sales alert. Outside these hours the assistant never promises an immediate human reply.</p>
          <div className="grid-2">
            <Field label="Opens"><input className="input" type="time" value={wh.start} onChange={(e) => setF({ ...f, working_hours: { ...wh, start: e.target.value } })} /></Field>
            <Field label="Closes"><input className="input" type="time" value={wh.end} onChange={(e) => setF({ ...f, working_hours: { ...wh, end: e.target.value } })} /></Field>
          </div>
          <div className="row gap-sm wrap">
            {DAYS.map(([n, l]) => (
              <label key={n} className="chip"><input type="checkbox" checked={wh.days.includes(n)} onChange={() => setF({ ...f, working_hours: { ...wh, days: wh.days.includes(n) ? wh.days.filter((x) => x !== n) : [...wh.days, n].sort() } })} /> {l}</label>
            ))}
          </div>
          <div className="grid-2">
            <Field label="Sales follow-up owner" hint="Receives quotation reminders (+10 min, +30 min, +2 h) and new sales enquiry alerts.">
              <Select value={f.sales_followup_user_id} onChange={(v) => setF({ ...f, sales_followup_user_id: v })} placeholder="Not set" options={(agents.data?.data || []).map((a) => ({ value: a.id, label: a.name }))} />
            </Field>
            <Field label="Chat session timeout (minutes)" hint="Idle customer PIN sessions expire after this time (0 = never).">
              <input className="input" type="number" min={0} max={480} value={f.session_timeout_minutes} onChange={(e) => setF({ ...f, session_timeout_minutes: Number(e.target.value) || 0 })} />
            </Field>
          </div>
          {d.can_edit && <div className="row end"><Button variant="primary" disabled={busy} onClick={() => run(async () => {
            const r = await api('/company', { method: 'POST', body: { ...f, sales_followup_user_id: f.sales_followup_user_id || null, working_hours: { start: wh.start, end: wh.end, days: wh.days } } });
            c.setData(r);
            // The company address is part of the page URL: after changing it, continue at the new address (same session).
            const next = r.data?.slug;
            if (next && d.slug && next !== d.slug && window.location.pathname.startsWith(`/${d.slug}`)) {
              window.location.replace(`/${next}/company`);
            }
          }, 'Company settings saved')}><Save size={15} />Save changes</Button></div>}
        </fieldset>
        <div>
          <section className="card">
            <CardHead icon={CreditCard} title="Plan & usage" />
            <p><strong>{d.subscription?.plan?.name || 'No plan (unlimited)'}</strong> {d.subscription?.status && <span className="muted">· {d.subscription.status.charAt(0).toUpperCase() + d.subscription.status.slice(1).replace('_', ' ')}</span>}</p>
            <table className="table compact">
              <tbody>
                {[['websites', 'Websites'], ['staff', 'Staff'], ['conversations_per_month', 'Chats this month'], ['knowledge_documents', 'Knowledge documents'], ['ai_monthly_usd', 'AI spend this month (USD)']].map(([k, l]) => (
                  <tr key={k}><td>{l}</td><td>{d.usage[k]}</td><td className="muted">{limits[k] == null ? 'Unlimited' : `of ${limits[k]}`}</td></tr>
                ))}
              </tbody>
            </table>
          </section>
          <ProfileCard profile={profile} setProfile={setProfile} run={run} busy={busy} />
        </div>
      </div>
    </>
  );
}

function ProfileCard({ profile, setProfile, run, busy }) {
  return (
    <section className="card">
      <CardHead icon={UserRound} title="My profile" />
      <Field label="Name"><input className="input" value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} /></Field>
      <Field label="E-mail"><input className="input" type="email" value={profile.email} onChange={(e) => setProfile({ ...profile, email: e.target.value })} /></Field>
      <Field label="New password" hint="Leave empty to keep your current password. Other devices are signed out when you change it."><input className="input" type="password" autoComplete="new-password" value={profile.password} onChange={(e) => setProfile({ ...profile, password: e.target.value })} /></Field>
      <Field label="Current password" hint="Required to change your e-mail or password."><input className="input" type="password" autoComplete="current-password" value={profile.current_password} onChange={(e) => setProfile({ ...profile, current_password: e.target.value })} /></Field>
      <div className="row end"><Button disabled={busy} onClick={() => run(async () => { await api('/auth/profile', { method: 'PUT', body: { ...profile, password: profile.password || undefined, current_password: profile.current_password || undefined } }); setProfile({ ...profile, password: '', current_password: '' }); }, 'Profile saved')}><Save size={15} />Save profile</Button></div>
    </section>
  );
}
