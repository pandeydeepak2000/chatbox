'use client';

import { useEffect, useState } from 'react';
import { api, fmt } from '@/lib/api';
import { BookOpen, CircleHelp, FilePlus2, PenLine, Search, Upload } from 'lucide-react';
import { Badge, Button, Empty, ErrorBox, Field, Loading, Modal, PageHead, Select, Tabs, statusTone, useAction, useApi } from '@/components/ui';

export default function Knowledge({ ctx }) {
  const [tab, setTab] = useState('documents');
  return (
    <>
      <PageHead title="Knowledge base" subtitle="The assistant answers only from FAQs and documents your team has approved. Anything else is handed to a person." />
      <Tabs value={tab} onChange={setTab} items={[['documents', 'Documents'], ['faqs', 'FAQs'], ['test', 'Test search']]} />
      {tab === 'documents' && <Documents ctx={ctx} />}
      {tab === 'faqs' && <Faqs ctx={ctx} />}
      {tab === 'test' && <TestSearch ctx={ctx} />}
    </>
  );
}

function scopeOptions(ctx) {
  const manageable = ctx.properties.filter((p) => p.can_manage);
  return [
    ...(ctx.perms.is_company_admin ? [{ value: 'company', label: 'Company-wide (all websites)' }] : []),
    ...manageable.map((p) => ({ value: p.id, label: `Website: ${p.site_name}` })),
  ];
}

function Documents({ ctx }) {
  const [status, setStatus] = useState('');
  const [scope, setScope] = useState('');
  const list = useApi('/knowledge', { query: { status, property_id: scope }, poll: 15000 });
  const [modal, setModal] = useState(null);
  const options = scopeOptions(ctx);

  return (
    <>
      <div className="filter-bar">
        <Select value={status} onChange={setStatus} placeholder="All active documents" options={['pending_approval', 'approved', 'processing', 'failed', 'rejected', 'archived'].map((s) => ({ value: s, label: fmt.label(s) }))} />
        <Select value={scope} onChange={setScope} placeholder="All scopes" options={[{ value: 'company', label: 'Company-wide' }, ...ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))]} />
        <span className="grow" />
        {options.length > 0 && <Button onClick={() => setModal({ type: 'manual' })}><PenLine size={15} />Write article</Button>}
        {options.length > 0 && <Button variant="primary" onClick={() => setModal({ type: 'upload' })}><Upload size={15} />Upload file</Button>}
      </div>
      <ErrorBox error={list.error} onRetry={list.reload} />
      {!list.data ? <Loading /> : list.data.data.length === 0 ? <Empty icon={BookOpen} title="No documents yet">Upload TXT, CSV, XLSX, PDF or DOCX files, or write an article. Every item must be approved before the assistant can use it.</Empty> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Title</th><th>Scope</th><th>Type</th><th>Status</th><th>Version</th><th>Updated</th><th /></tr></thead>
            <tbody>
              {list.data.data.map((d) => (
                <tr key={d.id}>
                  <td className="max-260 ellipsis"><span className="cell-title">{d.title}</span>{d.review_note && <div className="cell-sub">Note: {d.review_note}</div>}</td>
                  <td>{d.scope === 'company' ? 'Company-wide' : d.property?.site_name}</td>
                  <td>{d.source_type === 'file' ? 'File' : 'Article'}</td>
                  <td><Badge dot tone={statusTone(d.status)}>{fmt.label(d.status)}</Badge></td>
                  <td>{d.current_version ? `v${d.current_version}` : '—'}</td>
                  <td className="small muted nowrap">{fmt.ago(d.updated_at)}</td>
                  <td><Button size="sm" onClick={() => setModal({ type: 'review', id: d.id })}>{d.status === 'pending_approval' ? 'Review' : 'Open'}</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {modal?.type === 'manual' && <ManualModal options={options} onClose={() => setModal(null)} onDone={() => list.reload(true)} />}
      {modal?.type === 'upload' && <UploadModal options={options} onClose={() => setModal(null)} onDone={() => list.reload(true)} />}
      {modal?.type === 'review' && <ReviewModal id={modal.id} onClose={() => { setModal(null); list.reload(true); }} />}
    </>
  );
}

function ManualModal({ options, onClose, onDone }) {
  const [f, setF] = useState({ title: '', content: '', property_id: options[0]?.value || '' });
  const [run, busy] = useAction();
  return (
    <Modal title="Write a knowledge article" width={720} onClose={onClose} footer={
      <Button variant="primary" disabled={busy || !f.title || !f.content} onClick={() => run(async () => { await api('/knowledge/manual', { method: 'POST', body: f }); onDone(); onClose(); }, 'Saved. It will be used once it is approved.')}>Save for review</Button>
    }>
      <Field label="Title"><input className="input" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
      <Field label="Applies to"><Select value={f.property_id} onChange={(v) => setF({ ...f, property_id: v })} options={options} /></Field>
      <Field label="Content" hint="Plain text. Write the approved policy or procedure exactly as customers may be told."><textarea className="input" rows={12} value={f.content} onChange={(e) => setF({ ...f, content: e.target.value })} /></Field>
    </Modal>
  );
}

function UploadModal({ options, onClose, onDone, documentId }) {
  const [file, setFile] = useState(null);
  const [title, setTitle] = useState('');
  const [scope, setScope] = useState(options?.[0]?.value || '');
  const [run, busy] = useAction();
  return (
    <Modal title={documentId ? 'Upload a new version' : 'Upload a document'} onClose={onClose} footer={
      <Button variant="primary" disabled={busy || !file} onClick={() => run(async () => {
        const form = new FormData();
        form.append('file', file);
        if (documentId) form.append('document_id', documentId);
        else { form.append('property_id', scope); if (title) form.append('title', title); }
        await api('/knowledge/upload', { method: 'POST', form });
        onDone(); onClose();
      }, 'Uploaded. The text is extracted in the background and then waits for approval.')}><FilePlus2 size={15} />Upload</Button>
    }>
      <Field label="File" hint="TXT, CSV, XLSX, PDF or DOCX, up to 10 MB. Scanned PDFs (images) cannot be read.">
        <input className="input" type="file" accept=".txt,.csv,.xlsx,.pdf,.docx" onChange={(e) => setFile(e.target.files[0] || null)} />
      </Field>
      {!documentId && <>
        <Field label="Title (optional)"><input className="input" value={title} onChange={(e) => setTitle(e.target.value)} /></Field>
        <Field label="Applies to"><Select value={scope} onChange={setScope} options={options} /></Field>
      </>}
    </Modal>
  );
}

function ReviewModal({ id, onClose }) {
  const doc = useApi(`/knowledge/${id}`, { poll: 8000 });
  const [text, setText] = useState(null);
  const [newVersion, setNewVersion] = useState(false);
  const [run, busy] = useAction();
  const d = doc.data?.data;
  if (d && text === null && d.review_text != null) setText(d.review_text);

  return (
    <Modal title={d ? d.title : 'Document'} width={860} onClose={onClose} footer={d && (
      <>
        {d.status !== 'archived' && <Button variant="danger-ghost" disabled={busy} onClick={() => window.confirm('Archive this document? The assistant will stop using it.') && run(async () => { await api(`/knowledge/${id}/archive`, { method: 'POST' }); onClose(); }, 'Archived')}>Archive</Button>}
        {d.source_type === 'file' && <Button onClick={() => setNewVersion(true)}>Upload new version</Button>}
        {d.review_text != null && d.status !== 'approved' && <Button disabled={busy} onClick={() => { const note = window.prompt('Reason for rejecting (optional)') ?? null; run(async () => { await api(`/knowledge/${id}/reject`, { method: 'POST', body: { note } }); onClose(); }, 'Rejected'); }}>Reject</Button>}
        {d.review_text != null && <Button variant="primary" disabled={busy} onClick={() => run(async () => { await api(`/knowledge/${id}/approve`, { method: 'POST', body: { content: text } }); onClose(); }, 'Approved. The assistant can now use it.')}>{d.status === 'approved' ? 'Save & re-approve' : 'Approve'}</Button>}
      </>
    )}>
      {!d ? <Loading /> : (
        <>
          <p className="muted small">{d.scope === 'company' ? 'Company-wide' : d.property?.site_name} · <Badge dot tone={statusTone(d.status)}>{fmt.label(d.status)}</Badge> · {d.chunks} searchable section{d.chunks === 1 ? '' : 's'}</p>
          {d.status === 'processing' && <div className="alert">Extracting text… This view refreshes automatically. On cPanel this needs the cron job.</div>}
          {d.versions.filter((v) => v.status === 'failed' && v.error).slice(0, 1).map((v) => <div key={v.id} className="alert alert-err">Version {v.version}: {v.error}</div>)}
          {d.review_text != null ? (
            <Field label={`Text to approve (version ${d.review_version}) — you can correct it before approving`}>
              <textarea className="input mono-text" rows={16} value={text ?? ''} onChange={(e) => setText(e.target.value)} />
            </Field>
          ) : d.status !== 'processing' && <p className="muted">No text is waiting for review.</p>}
          <h3>Versions</h3>
          <ul className="plain small">
            {d.versions.map((v) => <li key={v.id}>Version {v.version} · {v.original_name || 'Written article'} · {fmt.label(v.status)}{v.is_current ? <> <Badge tone="green">Live</Badge></> : ''} · <span className="muted">{fmt.date(v.created_at)}</span></li>)}
          </ul>
        </>
      )}
      {newVersion && <UploadModal documentId={id} onClose={() => setNewVersion(false)} onDone={() => doc.reload(true)} />}
    </Modal>
  );
}

function Faqs({ ctx }) {
  const sites = ctx.properties;
  const [siteId, setSiteId] = useState(ctx.defaultPropertyId || sites[0]?.id || '');
  const faqs = useApi(siteId ? `/properties/${siteId}/faqs` : null, { enabled: !!siteId });
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();
  const site = sites.find((s) => s.id === siteId);
  if (!sites.length) return <Empty title="No websites yet">Add a website first, then create its FAQs.</Empty>;

  return (
    <>
      <div className="filter-bar">
        <Select value={siteId} onChange={setSiteId} options={sites.map((p) => ({ value: p.id, label: p.site_name }))} />
        <span className="grow" />
        {site?.can_manage && <Button variant="primary" onClick={() => setEdit({ question: '', answer: '', category: 'General', is_active: true })}><CircleHelp size={15} />Add FAQ</Button>}
      </div>
      <p className="muted small">When a customer’s question clearly matches an FAQ, the answer is sent instantly at no AI cost.</p>
      {!faqs.data ? <Loading /> : faqs.data.data.length === 0 ? <Empty icon={CircleHelp} title="No FAQs for this website">Add your most common questions to answer customers instantly.</Empty> : (
        <div className="faq-list">
          {faqs.data.data.map((q) => (
            <div key={q.id} className="card faq">
              <div className="row between"><strong>{q.question}</strong>{!q.is_active && <Badge>Inactive</Badge>}</div>
              <div className="pre small">{q.answer}</div>
              {site?.can_manage && <div className="row gap-sm end">
                <Button size="sm" onClick={() => setEdit(q)}>Edit</Button>
                <Button size="sm" variant="danger-ghost" disabled={busy} onClick={() => window.confirm('Delete this FAQ?') && run(async () => { await api(`/faqs/${q.id}`, { method: 'DELETE' }); faqs.reload(true); }, 'FAQ deleted')}>Delete</Button>
              </div>}
            </div>
          ))}
        </div>
      )}
      {edit && (
        <Modal title={edit.id ? 'Edit FAQ' : 'Add FAQ'} width={680} onClose={() => setEdit(null)} footer={
          <Button variant="primary" disabled={busy || !edit.question || !edit.answer} onClick={() => run(async () => {
            const body = { question: edit.question, answer: edit.answer, category: edit.category || 'General', is_active: !!edit.is_active };
            if (edit.id) await api(`/faqs/${edit.id}`, { method: 'PUT', body });
            else await api(`/properties/${siteId}/faqs`, { method: 'POST', body });
            setEdit(null); faqs.reload(true);
          }, 'FAQ saved')}>Save</Button>
        }>
          <Field label="Question"><input className="input" value={edit.question} onChange={(e) => setEdit({ ...edit, question: e.target.value })} /></Field>
          <Field label="Answer"><textarea className="input" rows={6} value={edit.answer} onChange={(e) => setEdit({ ...edit, answer: e.target.value })} /></Field>
          <div className="grid-2">
            <Field label="Category"><input className="input" value={edit.category || ''} onChange={(e) => setEdit({ ...edit, category: e.target.value })} /></Field>
            <Field label="Active"><Select value={edit.is_active ? '1' : '0'} onChange={(v) => setEdit({ ...edit, is_active: v === '1' })} options={[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]} /></Field>
          </div>
        </Modal>
      )}
    </>
  );
}

function TestSearch({ ctx }) {
  const [siteId, setSiteId] = useState(ctx.defaultPropertyId || ctx.properties[0]?.id || '');
  const [q, setQ] = useState('');
  const [res, setRes] = useState(null);
  const [run, busy] = useAction();
  return (
    <div className="card">
      <p className="muted">See which approved passages the assistant would use to answer a question. No AI request is made, so testing is free.</p>
      <div className="row gap">
        <Select value={siteId} onChange={setSiteId} options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />
        <input className="input grow" placeholder="e.g. How do I point my domain to your nameservers?" value={q} onChange={(e) => setQ(e.target.value)} />
        <Button variant="primary" disabled={busy || !q || !siteId} onClick={() => run(async () => setRes((await api('/knowledge/search-test', { method: 'POST', body: { query: q, property_id: siteId } })).data))}><Search size={15} />Search</Button>
      </div>
      {res && (res.length === 0 ? <div className="alert alert-warn">No approved knowledge matches. The assistant would ask one clarifying question or hand this to your team.</div> : res.map((r) => (
        <div key={r.id} className="note"><div className="row between"><strong>{r.title}</strong><Badge tone={r.type === 'faq' ? 'green' : 'blue'}>{r.type === 'faq' ? 'FAQ' : 'Document'} · relevance {r.score}</Badge></div><div className="pre small">{r.content.slice(0, 600)}</div></div>
      )))}
    </div>
  );
}
