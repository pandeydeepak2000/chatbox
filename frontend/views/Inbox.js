'use client';

import { useEffect, useRef, useState } from 'react';
import { api, fmt, uid } from '@/lib/api';
import { ArrowLeft, ArrowRightLeft, Ban, Bot, FileText, Hand, Lock, MessageSquareText, Paperclip, Play, RotateCcw, Send, Ticket, UserCheck, XCircle } from 'lucide-react';
import { Badge, Button, Empty, ErrorBox, Field, Loading, MessageText, Modal, SearchInput, Select, Tabs, statusTone, useAction, useApi } from '@/components/ui';

const initials = (n) => (n || '?').split(/[\s@]+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

export default function Inbox({ ctx }) {
  const [filter, setFilter] = useState('unassigned');
  const [propertyId, setPropertyId] = useState(ctx.defaultPropertyId);
  const [search, setSearch] = useState('');
  const [activeId, setActiveId] = useState(ctx.params.id || null);
  const list = useApi('/chat/conversations', { query: { filter, property_id: propertyId, search }, poll: 5000 });

  const [prevParamId, setPrevParamId] = useState(ctx.params.id);
  if (ctx.params.id !== prevParamId) { setPrevParamId(ctx.params.id); setActiveId(ctx.params.id || null); }
  const conversations = list.data?.data?.data || [];

  return (
    <div className={`inbox ${activeId ? 'has-active' : ''}`}>
      <section className="inbox-list">
        <div className="inbox-filters">
          <Tabs value={filter} onChange={setFilter} items={[['unassigned', 'Unassigned'], ['mine', 'Mine'], ['all', 'All open'], ['closed', 'Closed']]} />
          <div className="row gap-sm">
            {ctx.properties.length > 1 && (
              <Select value={propertyId} onChange={setPropertyId} placeholder="All websites" options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />
            )}
            <div className="grow"><SearchInput value={search} onChange={setSearch} placeholder="Search name, e-mail or message" /></div>
          </div>
        </div>
        <ErrorBox error={list.error} onRetry={list.reload} />
        {list.loading && !list.data ? <Loading /> : conversations.length === 0 ? <Empty icon={MessageSquareText} title="No conversations here">New chats from your websites appear here automatically.</Empty> : (
          <ul className="conv-list">
            {conversations.map((c) => (
              <li key={c.id}>
                <button className={`conv-item ${activeId === c.id ? 'active' : ''}`} onClick={() => setActiveId(c.id)}>
                  <span className="avatar sm">{initials(c.visitor?.name || c.visitor?.email || 'Visitor')}</span>
                  <span className="conv-main">
                    <span className="row between">
                      <span className="conv-name ellipsis">{c.visitor?.name || c.visitor?.email || 'Website visitor'}</span>
                      <span className="muted small nowrap">{fmt.ago(c.last_message_at)}</span>
                    </span>
                    <span className="conv-preview">{c.latest_message?.visibility === 'internal' ? <><Lock size={12} /> Internal note</> : <span className="ellipsis">{c.latest_message?.content || 'No messages yet'}</span>}</span>
                    <span className="row gap-sm wrap">
                      <Badge tone={statusTone(c.handler)}>{c.handler === 'human' ? 'Team' : 'Assistant'}</Badge>
                      {c.department && <Badge>{c.department.name}</Badge>}
                      {c.unread_count > 0 && <Badge tone="red">{c.unread_count} unread</Badge>}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
      <section className="inbox-chat">
        {activeId ? <Conversation key={activeId} id={activeId} ctx={ctx} onBack={() => setActiveId(null)} onChanged={() => list.reload(true)} /> : <div className="grow" style={{ alignSelf: 'center' }}><Empty icon={MessageSquareText} title="Select a conversation">Choose a chat on the left to read the history and reply.</Empty></div>}
      </section>
    </div>
  );
}

function Conversation({ id, ctx, onBack, onChanged }) {
  const conv = useApi(`/chat/conversations/${id}`, { poll: 3000 });
  const canned = useApi('/chat/canned-responses');
  const [text, setText] = useState('');
  const [mode, setMode] = useState('reply');
  const [modal, setModal] = useState(null);
  const [run, busy] = useAction();
  const endRef = useRef(null);
  const c = conv.data?.data;
  const lastSeq = c?.messages?.length ? c.messages[c.messages.length - 1].seq : 0;

  useEffect(() => { endRef.current?.scrollIntoView({ block: 'end' }); }, [lastSeq]);
  useEffect(() => {
    if (c && c.messages?.some((m) => m.sender_type === 'visitor' && !m.is_read)) api(`/chat/conversations/${id}/read`, { method: 'POST' }).then(onChanged).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSeq]);

  if (conv.error) return <ErrorBox error={conv.error} onRetry={conv.reload} />;
  if (!c) return <Loading />;
  const refresh = () => { conv.reload(true); onChanged(); };
  const departments = ctx.properties.find((p) => p.id === c.property_id)?.departments || [];

  const send = () => run(async () => {
    const body = text.trim();
    if (!body) return;
    if (mode === 'note') await api(`/chat/conversations/${id}/notes`, { method: 'POST', body: { body } });
    else await api(`/chat/conversations/${id}/messages`, { method: 'POST', body: { content: body } });
    setText('');
    refresh();
  });
  const upload = (file) => run(async () => {
    const form = new FormData();
    form.append('file', file);
    form.append('conversation_id', id);
    const up = await api('/chat/upload', { method: 'POST', form });
    await api(`/chat/conversations/${id}/messages`, { method: 'POST', body: { content: `Attached: ${up.file_name}`, attachment_id: up.attachment_id } });
    refresh();
  }, 'Attachment sent');

  const action = (path, body, ok) => run(async () => { await api(path, { method: 'POST', body }); refresh(); }, ok);

  return (
    <div className="chat">
      <div className="chat-head">
        <button className="icon-btn back-btn" onClick={onBack} aria-label="Back to conversations"><ArrowLeft size={18} /></button>
        <span className="avatar">{initials(c.visitor?.name || c.visitor?.email || 'Visitor')}</span>
        <div className="grow">
          <strong>{c.visitor?.name || c.visitor?.email || 'Website visitor'}</strong>
          <div className="meta">{c.visitor?.email || 'No e-mail provided'} · {c.property?.site_name} <Badge dot tone={statusTone(c.status)}>{fmt.label(c.status)}</Badge> <Badge tone={statusTone(c.handler)}>{c.handler === 'human' ? 'Handled by team' : 'Handled by assistant'}</Badge></div>
        </div>
        <div className="row gap-sm wrap">
          {c.handler === 'ai'
            ? <Button size="sm" variant="primary" disabled={busy} onClick={() => action(`/chat/conversations/${id}/takeover`, {}, 'You are now handling this chat. Assistant replies are paused.')}><Hand size={14} />Take over</Button>
            : <Button size="sm" disabled={busy} onClick={() => run(async () => {
              try { await api(`/chat/conversations/${id}/resume-ai`, { method: 'POST' }); } catch (e) {
                if (e.status === 409 && window.confirm(`${e.message}`)) await api(`/chat/conversations/${id}/resume-ai`, { method: 'POST', body: { confirm_open_ticket: true } });
                else throw e;
              }
              refresh();
            }, 'Assistant replies resumed')}><Play size={14} />Resume AI</Button>}
          {c.assigned_agent_id !== ctx.me.user.id && <Button size="sm" onClick={() => action(`/chat/conversations/${id}/assign`, {}, 'Assigned to you')}><UserCheck size={14} />Assign to me</Button>}
          <Button size="sm" onClick={() => setModal('transfer')}><ArrowRightLeft size={14} />Transfer</Button>
          {!c.open_ticket && <Button size="sm" onClick={() => setModal('ticket')}><Ticket size={14} />Create ticket</Button>}
          {c.status === 'closed'
            ? <Button size="sm" onClick={() => action(`/chat/conversations/${id}/status`, { status: 'open' }, 'Conversation reopened')}><RotateCcw size={14} />Reopen</Button>
            : <Button size="sm" onClick={() => action(`/chat/conversations/${id}/status`, { status: 'closed' }, 'Conversation closed')}><XCircle size={14} />Close</Button>}
        </div>
      </div>
      {c.open_ticket && (
        <div className="banner"><Ticket size={15} /> Linked ticket <a href={`#/tickets?id=${c.open_ticket.id}`}><strong>{c.open_ticket.reference}</strong></a> · {fmt.label(c.open_ticket.status)}</div>
      )}
      <div className="chat-body">
        {c.messages.map((m) => <Bubble key={m.id} m={m} onQuote={() => action(`/chat/messages/${m.id}/quotation`, {}, 'Quotation reminders set for +10 min, +30 min and +2 h')} />)}
        <div ref={endRef} />
      </div>
      <div className={`composer ${mode === 'note' ? 'note-mode' : ''}`}>
        <div className="row gap-sm">
          <Tabs value={mode} onChange={setMode} items={[['reply', 'Reply to customer'], ['note', 'Internal note']]} />
          {mode === 'reply' && (canned.data?.data || []).length > 0 && (
            <select className="input input-sm" value="" onChange={(e) => setText((t) => t + e.target.value)} aria-label="Insert saved reply">
              <option value="">Saved replies…</option>
              {canned.data.data.map((r) => <option key={r.id} value={r.content}>{r.shortcut}</option>)}
            </select>
          )}
        </div>
        <textarea className="input" rows={3} value={text} placeholder={mode === 'note' ? 'Write a note for your team. The customer never sees it.' : 'Write your reply…  (Ctrl + Enter to send)'}
          onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(); }} />
        <div className="row between">
          {mode === 'reply' ? (
            <label className="btn btn-default btn-sm file-btn"><Paperclip size={14} />Attach file<input type="file" hidden onChange={(e) => e.target.files[0] && upload(e.target.files[0])} /></label>
          ) : <span />}
          <Button variant="primary" disabled={busy || !text.trim()} onClick={send}>{mode === 'note' ? <><Lock size={14} />Add note</> : <><Send size={14} />Send</>}</Button>
        </div>
      </div>
      <aside className="chat-side">
        <h3>Customer</h3>
        <dl className="kv">
          <dt>Name</dt><dd>{c.visitor?.name || '—'}</dd>
          <dt>E-mail</dt><dd className="break">{c.visitor?.email || '—'}</dd>
          <dt>Phone</dt><dd>{c.visitor?.phone || '—'}</dd>
          <dt>Current page</dt><dd className="break small">{c.visitor?.current_page_url || '—'}</dd>
          <dt>Lead</dt><dd>{c.lead ? <a href={`#/leads?id=${c.lead.id}`}>{c.lead.reference} · {c.lead.status_label}</a> : <span className="muted">Not captured yet</span>}</dd>
          <dt>Account</dt><dd>{c.customer_account_id ? (c.pre_verification ? <Badge tone="orange">PIN account · verify identity</Badge> : <Badge tone="green">Support PIN account</Badge>) : <Badge>Guest</Badge>}</dd>
        </dl>
        <Button size="sm" variant="danger-ghost" onClick={() => window.confirm('Block this visitor from sending messages?') && action(`/chat/visitors/${c.visitor_id}/block`, {}, 'Visitor block setting updated')}><Ban size={14} />Block or unblock</Button>
      </aside>
      {modal === 'transfer' && <TransferModal id={id} departments={departments} onClose={() => setModal(null)} onDone={refresh} />}
      {modal === 'ticket' && <CreateTicketModal conversationId={id} onClose={() => setModal(null)} onDone={(t) => { refresh(); ctx.go('tickets', { id: t.id }); }} />}
    </div>
  );
}

function Bubble({ m, onQuote }) {
  const mine = m.sender_type === 'agent';
  if (m.sender_type === 'system') return <div className="sys-msg">{m.content}</div>;
  return (
    <div className={`bubble-row ${m.sender_type === 'visitor' ? 'left' : 'right'}`}>
      <div className={`bubble ${m.visibility === 'internal' ? 'bubble-note' : m.sender_type === 'bot' ? 'bubble-bot' : mine ? 'bubble-agent' : 'bubble-visitor'}`}>
        {m.visibility === 'internal' && <div className="bubble-label"><Lock size={12} /> Internal note</div>}
        {m.sender_type === 'bot' && <div className="bubble-label"><Bot size={12} /> {m.metadata?.source === 'faq' ? 'FAQ answer' : m.metadata?.source === 'ai' ? 'AI answer' : 'Assistant'}</div>}
        <MessageText text={m.content} />
        {m.attachment_url && /^https?:\/\//.test(m.attachment_url) && (
          m.message_type === 'image'
            ? <a href={m.attachment_url} target="_blank" rel="noopener noreferrer"><img className="attach-img" src={m.attachment_url} alt="Attachment" /></a>
            : <a className="attach-file" href={m.attachment_url} target="_blank" rel="noopener noreferrer"><FileText size={14} /> Download attachment</a>
        )}
        <div className="bubble-meta">
          {m.sender?.name ? `${m.sender.name} · ` : ''}{fmt.date(m.created_at)}
          {mine && m.visibility === 'public' && !m.metadata?.quotation && <button className="link small" onClick={onQuote} title="Remind sales at +10 min, +30 min and +2 h">Mark as quotation</button>}
          {m.metadata?.quotation && <Badge tone="purple">Quotation</Badge>}
        </div>
      </div>
    </div>
  );
}

function TransferModal({ id, departments, onClose, onDone }) {
  const agents = useApi('/agents');
  const [dept, setDept] = useState('');
  const [agent, setAgent] = useState('');
  const [run, busy] = useAction();
  return (
    <Modal title="Transfer conversation" onClose={onClose} footer={
      <Button variant="primary" disabled={busy || (!dept && !agent)} onClick={() => run(async () => {
        await api(`/chat/conversations/${id}/transfer`, { method: 'POST', body: { department_id: dept || undefined, agent_id: agent || undefined } });
        onDone(); onClose();
      }, 'Conversation transferred')}>Transfer</Button>
    }>
      <p className="muted small">Move this chat to another department, a colleague, or both. They are notified immediately.</p>
      <Field label="Department"><Select value={dept} onChange={setDept} placeholder="Keep current department" options={departments.map((d) => ({ value: d.id, label: d.name }))} /></Field>
      <Field label="Team member"><Select value={agent} onChange={setAgent} placeholder="Keep current assignee" options={(agents.data?.data || []).map((a) => ({ value: a.id, label: `${a.name}${a.is_online ? ' (online)' : ''}` }))} /></Field>
    </Modal>
  );
}

export function CreateTicketModal({ conversationId, onClose, onDone }) {
  const [form, setForm] = useState({ subject: '', priority: 'normal', category: 'general' });
  const [key] = useState(uid());
  const [run, busy] = useAction();
  return (
    <Modal title="Create ticket from this conversation" onClose={onClose} footer={
      <Button variant="primary" disabled={busy} onClick={() => run(async () => {
        const r = await api('/tickets', { method: 'POST', body: { conversation_id: conversationId, ...form, idempotency_key: key } });
        onDone(r.data); onClose();
      }, 'Ticket created')}>Create ticket</Button>
    }>
      <Field label="Subject" hint="Leave empty to use the customer’s latest question."><input className="input" value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} /></Field>
      <div className="grid-2">
        <Field label="Priority"><Select value={form.priority} onChange={(v) => setForm({ ...form, priority: v })} options={['low', 'normal', 'high', 'urgent'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
        <Field label="Category"><Select value={form.category} onChange={(v) => setForm({ ...form, category: v })} options={['general', 'technical', 'billing', 'sales', 'domain', 'hosting', 'account', 'other'].map((v) => ({ value: v, label: fmt.label(v) }))} /></Field>
      </div>
    </Modal>
  );
}
