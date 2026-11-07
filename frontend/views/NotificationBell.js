'use client';

import { useState } from 'react';
import { Bell, BellOff } from 'lucide-react';
import { api, fmt } from '@/lib/api';
import { useApi } from '@/components/ui';
import { navigate } from './App';

/** Dashboard notifications (polled). Reading one never resolves the underlying ticket/task. */
export default function NotificationBell() {
  const [open, setOpen] = useState(false);
  const { data, reload } = useApi('/notifications', { poll: 30000 });
  const unread = data?.unread || 0;
  const items = data?.data || [];

  const openItem = async (n) => {
    if (!n.read_at) await api(`/notifications/${n.id}/read`, { method: 'POST' }).catch(() => {});
    setOpen(false);
    reload(true);
    const d = n.data || {};
    if (d.ticket_id) navigate('tickets', { id: d.ticket_id });
    else if (d.conversation_id) navigate('inbox', { id: d.conversation_id });
    else if (d.task_id || d.task_ids) navigate('notifications');
  };

  return (
    <div className="bell">
      <button className="icon-btn" onClick={() => setOpen(!open)} aria-label={`Notifications (${unread} unread)`} aria-expanded={open}>
        <Bell size={19} />{unread > 0 && <span className="bell-count">{unread > 99 ? '99+' : unread}</span>}
      </button>
      {open && (
        <div className="bell-panel">
          <div className="bell-head">
            <strong>Notifications</strong>
            {unread > 0 && <button className="link small" onClick={async () => { await api('/notifications/read-all', { method: 'POST' }); reload(true); }}>Mark all as read</button>}
          </div>
          <div className="bell-list">
            {items.length === 0 && (
              <div className="empty" style={{ padding: 28 }}>
                <div className="empty-icon"><BellOff size={20} /></div>
                <div className="empty-title">You are all caught up</div>
                <div className="empty-text">New tickets, hand-overs and reminders will appear here.</div>
              </div>
            )}
            {items.map((n) => (
              <button key={n.id} className={`bell-item ${n.read_at ? '' : 'unread'}`} onClick={() => openItem(n)}>
                <div className="bell-title">{n.title}</div>
                {n.body && <div className="bell-body">{n.body}</div>}
                <div className="bell-time">{fmt.ago(n.created_at)}</div>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
