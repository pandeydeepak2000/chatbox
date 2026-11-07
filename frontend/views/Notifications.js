'use client';

import { useState } from 'react';
import { Check, ClipboardList, MessageSquareText, Plus, Send, Ticket, Trash2, Pencil } from 'lucide-react';
import { api, fmt } from '@/lib/api';
import { Badge, Button, CardHead, Empty, ErrorBox, Field, Loading, Modal, PageHead, Tabs, Toggle, statusTone, useAction, useApi, useToast } from '@/components/ui';

export default function Notifications({ ctx }) {
  const [tab, setTab] = useState('tasks');
  return (
    <>
      <PageHead title="Notifications & tasks" subtitle="Your follow-up reminders, plus Telegram alerts for your team and their delivery history." />
      <Tabs value={tab} onChange={setTab} items={[['tasks', 'Follow-up tasks'], ...(ctx.perms.is_company_admin ? [['telegram', 'Telegram']] : [])]} />
      {tab === 'tasks' && <Tasks ctx={ctx} />}
      {tab === 'telegram' && <Telegram />}
    </>
  );
}

function Tasks({ ctx }) {
  const t = useApi('/tasks', { poll: 30000 });
  const [run, busy] = useAction();
  if (t.error) return <ErrorBox error={t.error} onRetry={t.reload} />;
  if (!t.data) return <Loading />;
  const { mine, team } = t.data.data;
  const pending = mine.filter((x) => x.status === 'pending');
  const done = mine.filter((x) => x.status !== 'pending');
  return (
    <div className="grid-2">
      <section className="card">
        <CardHead icon={ClipboardList} title="My tasks" />
        <p className="card-sub">Marking a task done does not resolve the customer’s ticket or mark a sale as won.</p>
        {pending.length === 0 && <Empty icon={Check} title="Nothing pending">Quotation reminders and sales alerts assigned to you appear here.</Empty>}
        {pending.map((x) => (
          <div key={x.id} className="task">
            <div className="row between"><Badge tone="orange">{x.type_label}</Badge><span className="muted small">Due {fmt.date(x.due_at)}</span></div>
            <div>{x.title}</div>
            {x.reminders.length > 0 && <div className="small muted">Reminders: {x.reminders.map((r) => `${fmt.date(r.remind_at)}${r.sent ? ' ✓' : r.cancelled ? ' (cancelled)' : ''}`).join(' · ')}</div>}
            <div className="row gap-sm end">
              {x.conversation_id && <Button size="sm" onClick={() => ctx.go('inbox', { id: x.conversation_id })}><MessageSquareText size={13} />Open chat</Button>}
              {x.ticket_id && <Button size="sm" onClick={() => ctx.go('tickets', { id: x.ticket_id })}><Ticket size={13} />Open ticket</Button>}
              <Button size="sm" variant="success" disabled={busy} onClick={() => run(async () => { await api(`/tasks/${x.id}/complete`, { method: 'POST' }); t.reload(true); }, 'Task completed')}><Check size={13} />Mark done</Button>
            </div>
          </div>
        ))}
        {done.length > 0 && <details><summary className="muted small">Completed and cancelled ({done.length})</summary>{done.map((x) => <div key={x.id} className="small muted list-row">{x.title} · {fmt.label(x.status)}</div>)}</details>}
      </section>
      <section className="card">
        <CardHead icon={ClipboardList} title="Team board" />
        <p className="card-sub">A read-only view of your colleagues’ pending follow-ups.</p>
        {team.length === 0 ? <Empty icon={Check} title="No pending team tasks" /> : (
          <ul className="list">{team.map((x) => <li key={x.id} className="list-row"><Badge>{x.type_label}</Badge><span className="grow">{x.owner || 'Unassigned'}</span><span className="small muted">Due {fmt.date(x.due_at)}</span></li>)}</ul>
        )}
      </section>
    </div>
  );
}

function Telegram() {
  const data = useApi('/settings/telegram', { poll: 30000 });
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();
  const toast = useToast();
  if (data.error) return <ErrorBox error={data.error} onRetry={data.reload} />;
  if (!data.data) return <Loading />;
  const { data: channels, events, deliveries } = data.data;

  return (
    <>
      <div className="card">
        <CardHead icon={Send} title="Telegram alerts"><Button variant="primary" onClick={() => setEdit({ name: '', bot_token: '', chat_id: '', events: events.map((e) => e.value), is_enabled: true })}><Plus size={15} />Add destination</Button></CardHead>
        <ol className="muted small" style={{ margin: 0, paddingLeft: 18 }}>
          <li>Create a bot with <strong>@BotFather</strong> in Telegram and copy its token.</li>
          <li>Add the bot to your group or channel.</li>
          <li>Enter the token and chat ID here, then press <strong>Send test</strong>. Tokens are stored encrypted and never shown again.</li>
        </ol>
      </div>
      {channels.length === 0 ? <Empty icon={Send} title="No Telegram destinations yet">Add one to receive new tickets, hand-over requests and sales alerts on your phone.</Empty> : channels.map((c) => (
        <div key={c.id} className="card">
          <div className="row between wrap">
            <div><strong>{c.name}</strong> <span className="muted small">Chat {c.chat_id} · token {c.bot_token_hint}</span></div>
            <div className="row gap-sm">
              <Badge dot tone={c.is_enabled ? 'green' : 'gray'}>{c.is_enabled ? 'Enabled' : 'Paused'}</Badge>
              <Button size="sm" disabled={busy} onClick={() => run(async () => {
                try { const r = await api(`/settings/telegram/${c.id}/test`, { method: 'POST' }); toast(r.message); } catch (e) { toast(e.message, 'err'); }
                data.reload(true);
              })}><Send size={13} />Send test</Button>
              <Button size="sm" onClick={() => setEdit({ ...c, bot_token: '' })}><Pencil size={13} />Edit</Button>
              <Button size="sm" variant="danger-ghost" onClick={() => window.confirm('Delete this destination?') && run(async () => { await api(`/settings/telegram/${c.id}`, { method: 'DELETE' }); data.reload(true); }, 'Destination deleted')}><Trash2 size={13} />Delete</Button>
            </div>
          </div>
          <div className="small muted" style={{ marginTop: 6 }}>Sends: {c.events.map((e) => events.find((x) => x.value === e)?.label || e).join(', ')}</div>
          {c.last_error && <div className="alert alert-err small" style={{ marginTop: 8 }}>Last error: {c.last_error}</div>}
          {c.last_success_at && <div className="small muted">Last delivered {fmt.ago(c.last_success_at)}</div>}
        </div>
      ))}
      <section className="card">
        <CardHead icon={ClipboardList} title="Delivery history" />
        <div className="table-wrap">
          <table className="table compact">
            <thead><tr><th>Time</th><th>Event</th><th>Status</th><th>Attempts</th><th>Error</th></tr></thead>
            <tbody>
              {deliveries.length === 0 && <tr><td colSpan={5} className="muted">No deliveries yet.</td></tr>}
              {deliveries.map((d) => <tr key={d.id}><td className="small nowrap">{fmt.date(d.created_at)}</td><td>{events.find((x) => x.value === d.event)?.label || d.event}</td><td><Badge dot tone={statusTone(d.status)}>{fmt.label(d.status)}</Badge></td><td>{d.attempts}</td><td className="small">{d.last_error || ''}</td></tr>)}
            </tbody>
          </table>
        </div>
        <p className="muted small">Failed messages are retried automatically, up to 5 times, by the scheduled job.</p>
      </section>
      {edit && (
        <Modal title={edit.id ? 'Edit Telegram destination' : 'Add Telegram destination'} onClose={() => setEdit(null)} footer={
          <Button variant="primary" disabled={busy} onClick={() => run(async () => {
            const body = { name: edit.name, chat_id: edit.chat_id, events: edit.events, is_enabled: edit.is_enabled };
            if (edit.bot_token) body.bot_token = edit.bot_token;
            await api(edit.id ? `/settings/telegram/${edit.id}` : '/settings/telegram', { method: 'POST', body });
            setEdit(null); data.reload(true);
          }, 'Saved')}>Save</Button>
        }>
          <Field label="Name"><input className="input" value={edit.name} placeholder="e.g. Aarav — sales alerts" onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
          <Field label="Bot token" hint={edit.id ? 'Leave empty to keep the saved token.' : 'Format: 123456789:AA…'}><input className="input" type="password" autoComplete="off" value={edit.bot_token} onChange={(e) => setEdit({ ...edit, bot_token: e.target.value })} /></Field>
          <Field label="Chat ID" hint="A numeric ID (group IDs start with -100) or @channelname."><input className="input" value={edit.chat_id} onChange={(e) => setEdit({ ...edit, chat_id: e.target.value })} /></Field>
          <Field label="Send these events">
            {events.map((ev) => (
              <label key={ev.value} className="check"><input type="checkbox" checked={edit.events.includes(ev.value)} onChange={() => setEdit({ ...edit, events: edit.events.includes(ev.value) ? edit.events.filter((x) => x !== ev.value) : [...edit.events, ev.value] })} /> {ev.label}</label>
            ))}
          </Field>
          <Toggle checked={edit.is_enabled} onChange={(v) => setEdit({ ...edit, is_enabled: v })} label="Enabled" />
        </Modal>
      )}
    </>
  );
}
