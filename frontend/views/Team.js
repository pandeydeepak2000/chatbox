'use client';

import { useState } from 'react';
import { MessageSquareQuote, Pencil, Plus, Trash2, UserPlus, Users } from 'lucide-react';
import { api } from '@/lib/api';
import { Badge, Button, Empty, ErrorBox, Field, Loading, Modal, PageHead, Select, Tabs, useAction, useApi } from '@/components/ui';

export default function Team({ ctx }) {
  const [tab, setTab] = useState('staff');
  return (
    <>
      <PageHead title="Team & departments" subtitle="Invite your team, control which websites each person can access, and organise chats by department." />
      <Tabs value={tab} onChange={setTab} items={[['staff', 'Staff'], ['departments', 'Departments'], ['canned', 'Saved replies']]} />
      {tab === 'staff' && <Staff ctx={ctx} />}
      {tab === 'departments' && <Departments ctx={ctx} />}
      {tab === 'canned' && <Canned ctx={ctx} />}
    </>
  );
}

function Staff({ ctx }) {
  const agents = useApi('/agents');
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();
  const isAdmin = ctx.perms.is_company_admin;
  if (agents.error) return <ErrorBox error={agents.error} onRetry={agents.reload} />;
  if (!agents.data) return <Loading />;

  return (
    <>
      {isAdmin && <div className="filter-bar"><span className="grow" /><Button variant="primary" onClick={() => setEdit({ name: '', email: '', password: '', property_ids: [], department_ids: [] })}><UserPlus size={15} />Add team member</Button></div>}
      <div className="table-wrap">
        <table className="table">
          <thead><tr><th>Name</th><th>Role</th><th>Websites</th><th>Departments</th><th>Status</th><th /></tr></thead>
          <tbody>
            {agents.data.data.map((a) => (
              <tr key={a.id}>
                <td><div className="row gap"><span className="avatar sm">{(a.name || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()}</span><div><div className="cell-title">{a.name}</div><div className="cell-sub">{a.email}</div></div></div></td>
                <td>{a.role === 'admin' ? <Badge tone="purple">Company admin</Badge> : <Badge>Agent</Badge>}</td>
                <td className="small">{a.role === 'admin' ? 'All websites' : (a.properties?.length ? a.properties.map((p) => p.site_name).join(', ') : 'All websites')}</td>
                <td className="small">{(a.departments || []).map((d) => d.name).join(', ') || '—'}</td>
                <td><Badge dot tone={a.is_online ? 'green' : 'gray'}>{a.is_online ? 'Online' : 'Away'}</Badge></td>
                <td className="nowrap">{isAdmin && <>
                  <Button size="sm" onClick={() => setEdit({ ...a, password: '', property_ids: (a.properties || []).map((p) => p.id), department_ids: (a.departments || []).map((d) => d.id) })}><Pencil size={13} />Edit</Button>
                  {a.id !== ctx.me.user.id && <Button size="sm" variant="danger-ghost" disabled={busy} onClick={() => window.confirm(`Remove ${a.name}?`) && run(async () => { await api(`/agents/${a.id}`, { method: 'DELETE' }); agents.reload(true); }, 'Team member removed')}><Trash2 size={13} />Remove</Button>}
                </>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {edit && <StaffModal value={edit} ctx={ctx} onClose={() => setEdit(null)} onDone={() => agents.reload(true)} />}
    </>
  );
}

function StaffModal({ value, ctx, onClose, onDone }) {
  const [f, setF] = useState(value);
  const [run, busy] = useAction();
  const allDepts = ctx.properties.flatMap((p) => (p.departments || []).map((d) => ({ ...d, site: p.site_name })));
  const toggle = (k, id) => setF({ ...f, [k]: f[k].includes(id) ? f[k].filter((x) => x !== id) : [...f[k], id] });
  const isCompanyAdmin = value.role === 'admin';

  const save = () => run(async () => {
    const body = { name: f.name, email: f.email, department_ids: f.department_ids, property_ids: f.property_ids };
    if (f.password) body.password = f.password;
    if (value.id) {
      await api(`/agents/${value.id}`, { method: 'PUT', body });
      if (!isCompanyAdmin) {
        for (const p of ctx.properties) {
          const role = f.website_roles?.[p.id];
          if (role !== undefined) await api(`/websites/${p.id}/staff`, { method: 'POST', body: { user_id: value.id, role: role || null } });
        }
      }
    } else {
      await api('/agents', { method: 'POST', body: { ...body, password: f.password } });
    }
    onDone(); onClose();
  }, 'Team member saved');

  return (
    <Modal title={value.id ? `Edit ${value.name}` : 'Add team member'} width={680} onClose={onClose} footer={<Button variant="primary" disabled={busy} onClick={save}>Save</Button>}>
      <div className="grid-2">
        <Field label="Name"><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="E-mail"><input className="input" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
      </div>
      <Field label={value.id ? 'New password (leave empty to keep)' : 'Password (min 8 characters)'}><input className="input" type="password" autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></Field>
      {!isCompanyAdmin && (
        <>
          <h3>Website access</h3>
          <p className="muted small">Leave all unticked to give access to every company website. Tick websites to limit this person to them (chats, tickets, leads and knowledge).</p>
          {ctx.properties.map((p) => (
            <div key={p.id} className="row between list-row">
              <label className="check"><input type="checkbox" checked={f.property_ids.includes(p.id)} onChange={() => toggle('property_ids', p.id)} /> {p.site_name}</label>
              {value.id && f.property_ids.includes(p.id) && (
                <Select value={f.website_roles?.[p.id] ?? ''} onChange={(v) => setF({ ...f, website_roles: { ...(f.website_roles || {}), [p.id]: v || 'agent' } })}
                  placeholder="Keep current role" options={[{ value: 'agent', label: 'Agent' }, { value: 'admin', label: 'Website admin (can change settings)' }]} />
              )}
            </div>
          ))}
        </>
      )}
      <h3>Departments</h3>
      {allDepts.length === 0 ? <p className="muted small">No departments yet.</p> : allDepts.map((d) => (
        <label key={d.id} className="check"><input type="checkbox" checked={f.department_ids.includes(d.id)} onChange={() => toggle('department_ids', d.id)} /> {d.name} <span className="muted small">({d.site})</span></label>
      ))}
    </Modal>
  );
}

function Departments({ ctx }) {
  const [siteId, setSiteId] = useState(ctx.defaultPropertyId || ctx.properties[0]?.id || '');
  const list = useApi(siteId ? `/properties/${siteId}/departments` : null, { enabled: !!siteId });
  const [edit, setEdit] = useState(null);
  const [run, busy] = useAction();
  const canManage = ctx.properties.find((p) => p.id === siteId)?.can_manage;
  if (!ctx.properties.length) return <Empty icon={Users} title="Add a website first">Departments belong to a website.</Empty>;
  return (
    <>
      <div className="filter-bar">
        <Select value={siteId} onChange={setSiteId} options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />
        <span className="grow" />
        {canManage && <Button variant="primary" onClick={() => setEdit({ name: '', description: '', is_active: true })}><Plus size={15} />Add department</Button>}
      </div>
      <p className="muted small">Chats in a department whose name contains “Sales” raise a Telegram alert if nobody replies within 30 working minutes.</p>
      {!list.data ? <Loading /> : (
        <ul className="list">
          {list.data.data.map((d) => (
            <li key={d.id} className="list-row">
              <strong>{d.name}</strong><span className="grow muted small">{d.description}</span>
              <Badge dot tone={d.is_active ? 'green' : 'gray'}>{d.is_active ? 'Active' : 'Hidden'}</Badge>
              <span className="small muted">{d.agents_count} staff · {d.conversations_count} chats</span>
              {canManage && <>
                <Button size="sm" onClick={() => setEdit(d)}><Pencil size={13} />Edit</Button>
                <Button size="sm" variant="danger-ghost" disabled={busy} onClick={() => window.confirm(`Delete ${d.name}?`) && run(async () => { await api(`/departments/${d.id}`, { method: 'DELETE' }); list.reload(true); ctx.reloadMe(); }, 'Department deleted')}><Trash2 size={13} />Delete</Button>
              </>}
            </li>
          ))}
        </ul>
      )}
      {edit && (
        <Modal title={edit.id ? 'Edit department' : 'New department'} onClose={() => setEdit(null)} footer={
          <Button variant="primary" disabled={busy || !edit.name} onClick={() => run(async () => {
            const body = { name: edit.name, description: edit.description || null, is_active: !!edit.is_active };
            if (edit.id) await api(`/departments/${edit.id}`, { method: 'PUT', body });
            else await api(`/properties/${siteId}/departments`, { method: 'POST', body });
            setEdit(null); list.reload(true); ctx.reloadMe();
          }, 'Saved')}>Save</Button>
        }>
          <Field label="Name"><input className="input" value={edit.name} onChange={(e) => setEdit({ ...edit, name: e.target.value })} /></Field>
          <Field label="Description"><input className="input" value={edit.description || ''} onChange={(e) => setEdit({ ...edit, description: e.target.value })} /></Field>
          <label className="check"><input type="checkbox" checked={!!edit.is_active} onChange={(e) => setEdit({ ...edit, is_active: e.target.checked })} /> Active (customers can choose it in the chat)</label>
        </Modal>
      )}
    </>
  );
}

function Canned({ ctx }) {
  const [siteId, setSiteId] = useState(ctx.defaultPropertyId || ctx.properties[0]?.id || '');
  const list = useApi('/chat/canned-responses', { query: { property_id: siteId } });
  const [f, setF] = useState({ shortcut: '', content: '' });
  const [run, busy] = useAction();
  const isAdmin = ctx.perms.is_company_admin;
  return (
    <>
      <div className="filter-bar"><Select value={siteId} onChange={setSiteId} options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} /></div>
      {!list.data ? <Loading /> : (
        <ul className="list">
          {list.data.data.length === 0 && <li><Empty icon={MessageSquareQuote} title="No saved replies yet">Save answers you send often and insert them in one click.</Empty></li>}
          {list.data.data.map((c) => (
            <li key={c.id} className="list-row"><span className="ref">{c.shortcut}</span><span className="grow pre small">{c.content}</span>
              {isAdmin && <Button size="sm" variant="danger-ghost" onClick={() => run(async () => { await api(`/canned-responses/${c.id}`, { method: 'DELETE' }); list.reload(true); }, 'Deleted')}>Delete</Button>}
            </li>
          ))}
        </ul>
      )}
      {isAdmin && siteId && (
        <div className="card">
          <div className="grid-2">
            <Field label="Shortcut"><input className="input" value={f.shortcut} placeholder="/pricing" onChange={(e) => setF({ ...f, shortcut: e.target.value })} /></Field>
            <Field label="Reply text"><textarea className="input" rows={2} value={f.content} onChange={(e) => setF({ ...f, content: e.target.value })} /></Field>
          </div>
          <div className="row end"><Button variant="primary" disabled={busy || !f.shortcut || !f.content} onClick={() => run(async () => { await api(`/properties/${siteId}/canned-responses`, { method: 'POST', body: f }); setF({ shortcut: '', content: '' }); list.reload(true); }, 'Saved reply added')}><Plus size={15} />Add saved reply</Button></div>
        </div>
      )}
    </>
  );
}
