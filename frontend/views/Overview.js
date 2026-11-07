'use client';

import { ArrowRight, Bot, CheckCircle2, ClipboardList, Clock, DollarSign, MessageSquareText, Target, Ticket } from 'lucide-react';
import { fmt } from '@/lib/api';
import { Badge, CardHead, Empty, ErrorBox, Loading, PageHead, Stat, statusTone, useApi } from '@/components/ui';

function greeting() {
  const h = Number(new Date().toLocaleString('en-GB', { hour: '2-digit', hour12: false, timeZone: 'Asia/Kolkata' }));
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Overview({ ctx }) {
  const stats = useApi('/analytics', { query: { days: 7, property_id: ctx.defaultPropertyId }, poll: 60000 });
  const tickets = useApi('/tickets', { query: { status: 'unresolved', per_page: 6 }, poll: 30000 });
  const tasks = useApi('/tasks');
  const d = stats.data?.data;
  const pendingTasks = tasks.data ? tasks.data.data.mine.filter((t) => t.status === 'pending') : [];

  return (
    <>
      <PageHead title={`${greeting()}, ${ctx.me.user.name.split(' ')[0]}`} subtitle="Here is what needs your attention across your websites over the last 7 days." />
      <ErrorBox error={stats.error} onRetry={stats.reload} />
      {!d ? <Loading /> : (
        <div className="stats-grid">
          <Stat icon={MessageSquareText} label="Open conversations" value={d.conversations_open} sub={`${d.conversations} started this week`} />
          <Stat icon={Ticket} label="Unresolved tickets" value={d.tickets_open} tone={d.tickets_open ? 'warn' : undefined} sub={`${d.tickets_resolved} resolved this week`} />
          <Stat icon={Target} label="New leads" value={d.leads_new} sub="Captured this week" />
          <Stat icon={Clock} label="Avg. first response" value={d.avg_first_response_seconds != null ? `${Math.round(d.avg_first_response_seconds / 60)} min` : '—'} sub="Time to first human reply" />
          <Stat icon={Bot} label="Automated answers" value={d.bot_answers.faq + d.bot_answers.ai} sub={`${d.bot_answers.faq} FAQ · ${d.bot_answers.ai} AI · ${d.bot_answers.escalations} handed over`} />
          {d.ai_cost_usd !== null && <Stat icon={DollarSign} label="AI spend" value={fmt.usd(d.ai_cost_usd)} sub="Last 7 days" />}
        </div>
      )}

      <div className="grid-2">
        <section className="card">
          <CardHead icon={Ticket} title="Tickets waiting"><a className="card-link" href="#/tickets">View all <ArrowRight size={14} /></a></CardHead>
          {!tickets.data ? <Loading /> : tickets.data.data.length === 0 ? (
            <Empty icon={CheckCircle2} title="No unresolved tickets">Every customer request has been answered.</Empty>
          ) : (
            <ul className="list">
              {tickets.data.data.map((t) => (
                <li key={t.id}>
                  <a href={`#/tickets?id=${t.id}`} className="list-row">
                    <span className="ref">{t.reference}</span>
                    <span className="grow ellipsis">{t.subject}</span>
                    <Badge tone={statusTone(t.priority)}>{fmt.label(t.priority)}</Badge>
                    <Badge dot tone={statusTone(t.status)}>{t.status_label}</Badge>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </section>
        <section className="card">
          <CardHead icon={ClipboardList} title="My follow-up tasks"><a className="card-link" href="#/notifications">View all <ArrowRight size={14} /></a></CardHead>
          {!tasks.data ? <Loading /> : pendingTasks.length === 0 ? (
            <Empty icon={CheckCircle2} title="No pending follow-ups">Quotation reminders and sales alerts assigned to you will appear here.</Empty>
          ) : (
            <ul className="list">
              {pendingTasks.slice(0, 6).map((t) => (
                <li key={t.id} className="list-row">
                  <Badge tone="orange">{t.type_label}</Badge>
                  <span className="grow ellipsis">{t.title}</span>
                  <span className="muted small nowrap">Due {fmt.date(t.due_at)}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </>
  );
}
