'use client';

import { useState } from 'react';
import { KeyRound, LockOpen, ShieldCheck, UserRound } from 'lucide-react';
import { api, fmt } from '@/lib/api';
import { Badge, Button, Empty, ErrorBox, Field, Loading, Modal, PageHead, Pager, SearchInput, Select, useAction, useApi } from '@/components/ui';

/**
 * Website customers who use a Support PIN. Customers normally reset a forgotten PIN themselves
 * ("Forgot PIN?" sends a one-time code by e-mail); administrators can set a new PIN here when that
 * is not possible. Nobody can see a customer's current PIN.
 */
export default function Customers({ ctx }) {
  const [f, setF] = useState({ property_id: ctx.defaultPropertyId, search: '', status: '' });
  const [page, setPage] = useState(1);
  const [resetFor, setResetFor] = useState(null);
  const list = useApi('/customers', { query: { ...f, page } });
  const [run, busy] = useAction();
  const set = (k) => (v) => { setF({ ...f, [k]: v }); setPage(1); };
  const rows = list.data?.data || [];

  return (
    <>
      <PageHead title="Customers" subtitle="People who saved their chats with an e-mail and 6-digit Support PIN. Each website has its own customers." />
      <div className="alert"><ShieldCheck size={16} /><span>Customers can reset a forgotten PIN themselves with <strong>Forgot PIN?</strong> in the chat (a one-time code is e-mailed). Use <strong>Reset PIN</strong> only after you have confirmed who you are speaking to.</span></div>
      <div className="filter-bar">
        <SearchInput value={f.search} onChange={set('search')} placeholder="Search by e-mail" />
        {ctx.properties.length > 1 && <Select value={f.property_id} onChange={set('property_id')} placeholder="All websites" options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />}
        <Select value={f.status} onChange={set('status')} placeholder="All customers" options={[{ value: 'locked', label: 'Locked (too many wrong PINs)' }, { value: 'verified', label: 'E-mail verified' }, { value: 'unverified', label: 'E-mail not verified' }]} />
      </div>
      {list.data && <div className="result-count">{list.data.meta.total} customer{list.data.meta.total === 1 ? '' : 's'}</div>}
      <ErrorBox error={list.error} onRetry={list.reload} />
      {list.loading && !list.data ? <Loading /> : rows.length === 0 ? (
        <Empty icon={UserRound} title="No customers yet">When a visitor saves their chat with an e-mail and Support PIN, they appear here.</Empty>
      ) : (
        <div className="table-wrap">
          <table className="table table-stack">
            <thead><tr><th>Customer</th><th>Website</th><th>Status</th><th>Chats</th><th>Tickets</th><th>Last sign-in</th><th>Joined</th><th /></tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.id}>
                  <td data-label="Customer" className="stack-title"><span className="cell-title break">{c.email}</span></td>
                  <td data-label="Website">{c.property?.site_name}</td>
                  <td data-label="Status">
                    {c.locked ? <Badge dot tone="red">Locked</Badge> : c.email_verified ? <Badge dot tone="green">Verified</Badge> : <Badge dot tone="orange">Not verified</Badge>}
                  </td>
                  <td data-label="Chats">{c.conversations}</td>
                  <td data-label="Tickets">{c.tickets}</td>
                  <td data-label="Last sign-in" className="muted small nowrap">{fmt.ago(c.last_login_at)}</td>
                  <td data-label="Joined" className="muted small nowrap">{fmt.date(c.created_at)}</td>
                  <td className="nowrap">
                    {c.can_manage && (
                      <div className="row gap-sm">
                        {c.locked && <Button size="sm" disabled={busy} onClick={() => run(async () => { await api(`/customers/${c.id}/unlock`, { method: 'POST' }); list.reload(true); }, 'Account unlocked')}><LockOpen size={14} />Unlock</Button>}
                        <Button size="sm" onClick={() => setResetFor(c)}><KeyRound size={14} />Reset PIN</Button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pager meta={list.data?.meta} page={page} onPage={setPage} />
      {resetFor && <ResetPinModal customer={resetFor} onClose={() => setResetFor(null)} onDone={() => list.reload(true)} />}
    </>
  );
}

function ResetPinModal({ customer, onClose, onDone }) {
  const [pin, setPin] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [run, busy] = useAction();
  const valid = /^[0-9]{6}$/.test(pin);
  return (
    <Modal title="Reset Support PIN" onClose={onClose} footer={
      <>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" disabled={busy || !valid || !confirmed} onClick={() => run(async () => {
          await api(`/customers/${customer.id}/reset-pin`, { method: 'POST', body: { pin } });
          onDone(); onClose();
        }, 'New PIN saved. The customer was signed out everywhere and notified by e-mail.')}>Save new PIN</Button>
      </>
    }>
      <p>Set a new 6-digit PIN for <strong>{customer.email}</strong> on {customer.property?.site_name}.</p>
      <Field label="New PIN" hint="6 digits, numbers only. Avoid easy PINs like 123456 or 111111.">
        <input className="input mono-text" inputMode="numeric" maxLength={6} autoComplete="off" value={pin} onChange={(e) => setPin(e.target.value.replace(/[^0-9]/g, ''))} placeholder="e.g. 730581" />
      </Field>
      <label className="check"><input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} /> I have confirmed this person owns this e-mail address.</label>
      <ul className="muted small" style={{ paddingLeft: 18, marginTop: 12 }}>
        <li>The customer is signed out on every device.</li>
        <li>A security e-mail tells them their PIN was changed.</li>
        <li>Share the new PIN only with the verified customer, and ask them to keep it private.</li>
      </ul>
    </Modal>
  );
}
