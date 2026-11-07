'use client';

import { useEffect, useState } from 'react';
import { api, download, fmt } from '@/lib/api';
import { Download, FileSpreadsheet, Lock, MessageSquareText, Target } from 'lucide-react';
import { Badge, Button, Empty, ErrorBox, Field, Loading, Modal, PageHead, Pager, SearchInput, Select, statusTone, useAction, useApi } from '@/components/ui';

export default function Leads({ ctx }) {
  const meta = useApi('/leads/meta');
  const [f, setF] = useState({ search: '', property_id: ctx.defaultPropertyId, status: '', service: '', assigned_to: '', date_from: '', date_to: '' });
  const [page, setPage] = useState(1);
  const [scope, setScope] = useState('filtered');
  const [openId, setOpenId] = useState(ctx.params.id || null);
  const list = useApi('/leads', { query: { ...f, page, per_page: 25 } });
  const [run, busy] = useAction();
  const m = meta.data?.data;

  const [prevParamId, setPrevParamId] = useState(ctx.params.id);
  if (ctx.params.id !== prevParamId) { setPrevParamId(ctx.params.id); setOpenId(ctx.params.id || null); }
  const set = (k) => (v) => { setF({ ...f, [k]: v }); setPage(1); };
  const quick = (id, body) => run(async () => { await api(`/leads/${id}/update`, { method: 'POST', body }); list.reload(true); }, 'Lead updated');
  const exportAs = (format) => run(() => download('/leads/export', { ...(scope === 'all' ? {} : f), format, scope }, `leads_${new Date().toISOString().slice(0, 10)}.${format}`), 'Download started');

  return (
    <>
      <PageHead title="Leads" subtitle="Potential customers captured automatically from your chats. Everyone sees only the websites they have access to.">
        <select className="input input-sm" value={scope} onChange={(e) => setScope(e.target.value)} aria-label="Export scope">
          <option value="filtered">Export: current filters</option>
          <option value="all">Export: all leads</option>
        </select>
        <Button disabled={busy} onClick={() => exportAs('csv')}><Download size={15} />CSV</Button>
        <Button variant="primary" disabled={busy} onClick={() => exportAs('xlsx')}><FileSpreadsheet size={15} />Export to Excel</Button>
      </PageHead>
      <div className="filter-bar">
        <SearchInput value={f.search} onChange={set('search')} placeholder="Search name, e-mail or phone" />
        {m && <Select value={f.property_id} onChange={set('property_id')} placeholder="All websites" options={m.websites.map((w) => ({ value: w.id, label: w.site_name }))} />}
        {m && <Select value={f.status} onChange={set('status')} placeholder="All statuses" options={m.statuses} />}
        {m && <Select value={f.service} onChange={set('service')} placeholder="All services" options={m.services} />}
        {m && <Select value={f.assigned_to} onChange={set('assigned_to')} placeholder="Any staff" options={[{ value: 'me', label: 'Assigned to me' }, { value: 'unassigned', label: 'Unassigned' }, ...m.staff.map((s) => ({ value: s.id, label: s.name }))]} />}
        <label className="inline-field">From <input className="input" type="date" value={f.date_from} onChange={(e) => set('date_from')(e.target.value)} /></label>
        <label className="inline-field">To <input className="input" type="date" value={f.date_to} onChange={(e) => set('date_to')(e.target.value)} /></label>
      </div>
      {list.data && <div className="result-count">{list.data.meta.total} lead{list.data.meta.total === 1 ? '' : 's'}</div>}
      <ErrorBox error={list.error} onRetry={list.reload} />
      {list.loading && !list.data ? <Loading /> : (list.data?.data || []).length === 0 ? <Empty icon={Target} title="No leads match these filters">Try clearing a filter, or wait for new chats to come in.</Empty> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Lead</th><th>Customer</th><th>Website</th><th>Service</th><th>Inquiry</th><th>Status</th><th>Assigned</th><th>Created</th><th>Last interaction</th></tr></thead>
            <tbody>
              {list.data.data.map((l) => (
                <tr key={l.id}>
                  <td className="nowrap"><button className="link ref" onClick={() => setOpenId(l.id)}>{l.reference}</button></td>
                  <td className="max-220 break"><div className="cell-title">{l.name || 'Name not provided'}</div><div className="cell-sub">{l.email || 'No e-mail'}{l.phone ? ` · ${l.phone}` : ''}</div></td>
                  <td>{l.property?.site_name}{l.website && <div className="muted small">{l.website}</div>}</td>
                  <td>{l.interested_service_label || '—'}</td>
                  <td className="ellipsis max-220" title={l.inquiry_summary || ''}>{l.inquiry_summary || '—'}</td>
                  <td>{m && <select className={`input input-sm tone-${statusTone(l.status)}`} value={l.status} onChange={(e) => quick(l.id, { status: e.target.value })}>{m.statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</select>}</td>
                  <td>{m && <select className="input input-sm" value={l.assigned_to?.id || ''} onChange={(e) => quick(l.id, { assigned_to: e.target.value || null })}><option value="">Unassigned</option>{m.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>}</td>
                  <td className="nowrap small muted">{fmt.date(l.created_at)}</td>
                  <td className="nowrap small muted">{fmt.ago(l.last_interaction_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
      {openId && m && <LeadModal id={openId} meta={m} ctx={ctx} onClose={() => { setOpenId(null); list.reload(true); }} />}
    </>
  );
}

function LeadModal({ id, meta, ctx, onClose }) {
  const lead = useApi(`/leads/${id}`);
  const [form, setForm] = useState(null);
  const [note, setNote] = useState('');
  const [run, busy] = useAction();
  const l = lead.data?.data;
  if (l && !form) setForm({ name: l.name || '', email: l.email || '', phone: l.phone || '', website: l.website || '', interested_service: l.interested_service || '', status: l.status, assigned_to: l.assigned_to?.id || '', inquiry_summary: l.inquiry_summary || '' });
  const nullIfEmpty = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));

  return (
    <Modal title={l ? `${l.reference} · ${l.name || 'Lead'}` : 'Lead'} onClose={onClose} width={820} footer={l && (
      <>
        {meta.can_delete && <Button variant="danger-ghost" disabled={busy} onClick={() => window.confirm(`Delete ${l.reference}? Chats with this customer are kept.`) && run(async () => { await api(`/leads/${id}`, { method: 'DELETE' }); onClose(); }, 'Lead deleted')}>Delete lead</Button>}
        <Button variant="primary" disabled={busy || !form} onClick={() => run(async () => { await api(`/leads/${id}/update`, { method: 'POST', body: { ...nullIfEmpty(form), status: form.status } }); lead.reload(true); }, 'Lead saved')}>Save changes</Button>
      </>
    )}>
      {!l || !form ? <Loading /> : (
        <>
          <p className="muted small">{l.property?.site_name} · Created {fmt.date(l.created_at)} · Source: {fmt.label(l.source)}</p>
          <div className="grid-2">
            <Field label="Customer name"><input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="E-mail"><input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label="Phone"><input className="input" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Field label="Customer website"><input className="input" value={form.website} onChange={(e) => setForm({ ...form, website: e.target.value })} /></Field>
            <Field label="Interested service"><Select value={form.interested_service} onChange={(v) => setForm({ ...form, interested_service: v })} placeholder="Not set" options={meta.services} /></Field>
            <Field label="Status"><Select value={form.status} onChange={(v) => setForm({ ...form, status: v })} options={meta.statuses} /></Field>
            <Field label="Assigned staff"><Select value={form.assigned_to} onChange={(v) => setForm({ ...form, assigned_to: v })} placeholder="Unassigned" options={meta.staff.map((s) => ({ value: s.id, label: s.name }))} /></Field>
          </div>
          <Field label="Inquiry summary"><textarea className="input" rows={3} value={form.inquiry_summary} onChange={(e) => setForm({ ...form, inquiry_summary: e.target.value })} /></Field>
          <div className="grid-2">
            <div>
              <h3>Linked conversations</h3>
              {l.conversations.length === 0 ? <p className="muted small">No conversations linked yet.</p> : l.conversations.map((c) => (
                <div key={c.id} className="row between list-row">
                  <span>{fmt.date(c.created_at)} <Badge dot tone={statusTone(c.status)}>{fmt.label(c.status)}</Badge></span>
                  <Button size="sm" onClick={() => { onClose(); ctx.go('inbox', { id: c.id }); }}><MessageSquareText size={14} />Open chat</Button>
                </div>
              ))}
            </div>
            <div>
              <h3><Lock size={12} style={{ verticalAlign: '-1px' }} /> Internal notes</h3>
              <div className="row gap-sm">
                <textarea className="input" rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Add a note for your team…" />
                <Button disabled={busy || !note.trim()} onClick={() => run(async () => { await api(`/leads/${id}/notes`, { method: 'POST', body: { body: note } }); setNote(''); lead.reload(true); }, 'Note added')}>Add</Button>
              </div>
              {l.notes.map((n) => <div key={n.id} className="note"><div className="pre">{n.body}</div><div className="muted small">{n.author} · {fmt.date(n.created_at)}</div></div>)}
            </div>
          </div>
        </>
      )}
    </Modal>
  );
}
