'use client';

import { Fragment, useEffect, useState } from 'react';
import { api, fmt } from '@/lib/api';
import { Bot, FileText, History, Lock, MessageSquareText, Send, Sparkles, Ticket, UserRound } from 'lucide-react';
import { BackLink, Badge, Button, Empty, ErrorBox, Loading, MessageText, PageHead, Pager, SearchInput, Select, Tabs, statusTone, useAction, useApi } from '@/components/ui';

const STATUSES = [['open', 'Open'], ['pending_team', 'Waiting for team'], ['pending_customer', 'Waiting for customer'], ['resolved', 'Resolved'], ['closed', 'Closed']];
const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const CATEGORIES = ['general', 'technical', 'billing', 'sales', 'domain', 'hosting', 'account', 'other'];
const SOURCES = { ai_escalation: 'Raised by the assistant', customer_request: 'Customer asked for a person', staff: 'Created by staff', restricted_action: 'Needs staff action', after_hours: 'After-hours request' };

export default function Tickets({ ctx }) {
  const [tab, setTab] = useState('unresolved');
  const [filters, setFilters] = useState({ property_id: ctx.defaultPropertyId, priority: '', search: '' });
  const [page, setPage] = useState(1);
  const [openId, setOpenId] = useState(ctx.params.id || null);
  const query = { ...filters, page, status: ['unresolved', 'resolved', 'closed'].includes(tab) ? tab : '', assigned_to: tab === 'mine' ? 'me' : tab === 'unassigned' ? 'unassigned' : '' };
  if (tab === 'mine' || tab === 'unassigned') query.status = 'unresolved';
  const list = useApi('/tickets', { query, poll: 15000 });

  const [prevParamId, setPrevParamId] = useState(ctx.params.id);
  if (ctx.params.id !== prevParamId) { setPrevParamId(ctx.params.id); setOpenId(ctx.params.id || null); }

  if (openId) return <TicketDetail id={openId} ctx={ctx} onBack={() => { setOpenId(null); ctx.go('tickets'); list.reload(true); }} />;
  const counts = list.data?.counts || {};

  return (
    <>
      <PageHead title="Support tickets" subtitle="Created automatically when the assistant cannot answer reliably or a customer asks for a person, and manually by your team." />
      <Tabs value={tab} onChange={(v) => { setTab(v); setPage(1); }} items={[
        ['unresolved', 'Unresolved', counts.unresolved], ['mine', 'Assigned to me', counts.mine], ['unassigned', 'Unassigned', counts.unassigned], ['resolved', 'Resolved'], ['closed', 'Closed'],
      ]} />
      <div className="filter-bar">
        <SearchInput value={filters.search} onChange={(v) => setFilters({ ...filters, search: v })} placeholder="Search by reference or subject" />
        {ctx.properties.length > 1 && <Select value={filters.property_id} onChange={(v) => setFilters({ ...filters, property_id: v })} placeholder="All websites" options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />}
        <Select value={filters.priority} onChange={(v) => setFilters({ ...filters, priority: v })} placeholder="Any priority" options={PRIORITIES.map((p) => ({ value: p, label: fmt.label(p) }))} />
      </div>
      <ErrorBox error={list.error} onRetry={list.reload} />
      {list.loading && !list.data ? <Loading /> : (list.data?.data || []).length === 0 ? <Empty icon={Ticket} title="No tickets here">Tickets that match this view will appear here.</Empty> : (
        <div className="table-wrap">
          <table className="table table-stack">
            <thead><tr><th>Reference</th><th>Subject</th><th>Website</th><th>Customer</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Updated</th></tr></thead>
            <tbody>
              {list.data.data.map((t) => (
                <tr key={t.id} className="clickable" onClick={() => setOpenId(t.id)}>
                  <td data-label="Ref" className="nowrap"><span className="ref">{t.reference}</span></td>
                  <td data-label="Subject" className="ellipsis max-260 stack-title cell-title">{t.subject}</td>
                  <td data-label="Website">{t.property?.site_name}</td>
                  <td data-label="Customer" className="max-180"><div className="ellipsis">{t.customer_name || t.customer_email || '—'}</div>{t.customer_email && !t.customer_verified && <Badge tone="orange">Unverified e-mail</Badge>}</td>
                  <td data-label="Priority"><Badge tone={statusTone(t.priority)}>{fmt.label(t.priority)}</Badge></td>
                  <td data-label="Status"><Badge dot tone={statusTone(t.status)}>{t.status_label}</Badge></td>
                  <td data-label="Assigned">{t.assigned_to?.name || <span className="muted">Unassigned</span>}</td>
                  <td data-label="Updated" className="nowrap muted small">{fmt.ago(t.updated_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
    </>
  );
}

function TicketDetail({ id, ctx, onBack }) {
  const t = useApi(`/tickets/${id}`, { poll: 10000 });
  const agents = useApi('/agents');
  const [text, setText] = useState('');
  const [mode, setMode] = useState('reply');
  const [run, busy] = useAction();
  const d = t.data?.data;
  if (t.error) return <><BackLink onClick={onBack}>All tickets</BackLink><ErrorBox error={t.error} /></>;
  if (!d) return <Loading />;

  const update = (body, ok) => run(async () => { await api(`/tickets/${id}/update`, { method: 'POST', body }); t.reload(true); }, ok);
  const send = () => run(async () => {
    if (!text.trim()) return;
    if (mode === 'note') await api(`/tickets/${id}/notes`, { method: 'POST', body: { body: text } });
    else await api(`/tickets/${id}/reply`, { method: 'POST', body: { content: text } });
    setText('');
    t.reload(true);
  }, mode === 'note' ? 'Internal note added' : 'Reply sent to the customer');

  return (
    <>
      <div className="page-head">
        <div>
          <BackLink onClick={onBack}>All tickets</BackLink>
          <h1 className="ticket-title"><span className="ref">{d.reference}</span>{d.subject}</h1>
          <div className="ticket-meta">
            <Badge dot tone={statusTone(d.status)}>{STATUSES.find(([v]) => v === d.status)?.[1] || fmt.label(d.status)}</Badge>
            <Badge tone={statusTone(d.priority)}>{fmt.label(d.priority)} priority</Badge>
            <span>{d.property?.site_name}</span>·<span>Opened {fmt.date(d.created_at)}</span>·<span>{SOURCES[d.source] || fmt.label(d.source)}</span>
          </div>
        </div>
      </div>
      <div className="ticket-layout">
        <section className="card">
          {d.summary && Object.values(d.summary).some(Boolean) && (
            <div className="summary">
              <h3><Sparkles size={14} /> Summary</h3>
              <dl className="kv">
                {[['issue', 'Issue'], ['affected_service', 'Affected service'], ['steps_tried', 'Steps tried'], ['results', 'Context'], ['unresolved', 'Still open']].map(([k, l]) => d.summary[k] ? <Fragment key={k}><dt>{l}</dt><dd>{d.summary[k]}</dd></Fragment> : null)}
              </dl>
            </div>
          )}
          <h3>Conversation history</h3>
          <div className="thread">
            {d.messages.length === 0 && <Empty icon={MessageSquareText} title="No messages yet" />}
            {d.messages.map((m) => (
              <div key={m.id} className={`thread-item ${m.visibility === 'internal' ? 'thread-note' : ''} thread-${m.sender_type}`}>
                <div className="thread-meta">
                  <strong>{m.visibility === 'internal' ? <Lock size={13} /> : m.sender_type === 'bot' ? <Bot size={13} /> : m.sender_type === 'visitor' ? <UserRound size={13} /> : null}{m.sender_type === 'visitor' ? (d.visitor?.name || 'Customer') : m.sender_type === 'bot' ? 'Assistant' : m.sender_type === 'system' ? 'System' : m.sender_name || 'Staff'}{m.visibility === 'internal' ? <Badge tone="orange">Internal note</Badge> : null}</strong>
                  <span className="muted small">{fmt.date(m.created_at)}</span>
                </div>
                <MessageText text={m.content} />
                {m.attachment_url && /^https?:\/\//.test(m.attachment_url) && <a href={m.attachment_url} target="_blank" rel="noopener noreferrer" className="attach-file"><FileText size={14} /> Attachment</a>}
              </div>
            ))}
          </div>
          {d.conversation_id && (
            <div className={`composer ${mode === 'note' ? 'note-mode' : ''}`}>
              <Tabs value={mode} onChange={setMode} items={[['reply', 'Reply to customer'], ['note', 'Internal note']]} />
              <textarea className="input" rows={4} value={text} onChange={(e) => setText(e.target.value)} placeholder={mode === 'note' ? 'Only your team can see this note.' : 'The customer sees this in the chat window and under Support Tickets.'} />
              <div className="row end"><Button variant="primary" disabled={busy || !text.trim()} onClick={send}>{mode === 'note' ? <><Lock size={14} />Add note</> : <><Send size={14} />Send reply</>}</Button></div>
            </div>
          )}
        </section>
        <aside className="card ticket-side">
          <h3>Details</h3>
          <label className="field"><span className="field-label">Status</span>
            <Select value={d.status} onChange={(v) => update({ status: v }, 'Status updated')} options={STATUSES.map(([v, l]) => ({ value: v, label: l }))} />
          </label>
          <label className="field"><span className="field-label">Priority</span>
            <Select value={d.priority} onChange={(v) => update({ priority: v }, 'Priority updated')} options={PRIORITIES.map((v) => ({ value: v, label: fmt.label(v) }))} />
          </label>
          <label className="field"><span className="field-label">Category</span>
            <Select value={d.category} onChange={(v) => update({ category: v }, 'Category updated')} options={CATEGORIES.map((v) => ({ value: v, label: fmt.label(v) }))} />
          </label>
          <label className="field"><span className="field-label">Assigned to</span>
            <Select value={d.assigned_to?.id || ''} onChange={(v) => update({ assigned_to: v || null }, 'Assignment updated')} placeholder="Unassigned" options={(agents.data?.data || []).map((a) => ({ value: a.id, label: a.name }))} />
          </label>
          {d.conversation_id && <Button size="sm" className="btn-block" onClick={() => ctx.go('inbox', { id: d.conversation_id })}><MessageSquareText size={14} />Open in Live Inbox</Button>}
          <h3>Customer</h3>
          <dl className="kv">
            <dt>Name</dt><dd>{d.visitor?.name || '—'}</dd>
            <dt>E-mail</dt><dd className="break">{d.customer?.email || d.visitor?.email || '—'}</dd>
            <dt>Ownership</dt><dd>{d.customer ? (d.customer.email_verified ? <Badge tone="green">E-mail verified</Badge> : <Badge tone="orange">Support PIN · e-mail not verified</Badge>) : <Badge>Guest chat</Badge>}</dd>
          </dl>
          {d.conversation?.pre_verification && <div className="alert alert-warn small">Part of this history was created before the e-mail owner verified the account. Check identity before sharing account details.</div>}
          <h3>Owners</h3>
          {d.assignments.length === 0 ? <p className="muted small">Not assigned yet. The first person to reply becomes the owner.</p> : (
            <ul className="timeline">
              {d.assignments.map((a, i) => <li key={i} className={a.current ? 'current' : ''}><strong>{a.user}</strong>{a.current && <> <Badge tone="blue">Current</Badge></>}<div className="muted small">{fmt.date(a.assigned_at)}</div></li>)}
            </ul>
          )}
          <h3><History size={13} style={{ verticalAlign: '-2px' }} /> Activity</h3>
          <ul className="timeline">
            {d.events.map((e, i) => <li key={i}>{fmt.label(e.type)}{e.data?.to ? ` → ${fmt.label(String(e.data.to_name || e.data.to))}` : ''}<div className="muted small">{fmt.date(e.created_at)}</div></li>)}
          </ul>
        </aside>
      </div>
    </>
  );
}
