'use client';

import { useState } from 'react';
import { BarChart3, Bot, CircleHelp, Clock, DollarSign, Globe, MessageSquareText, Star, Target, Ticket } from 'lucide-react';
import { fmt } from '@/lib/api';
import { CardHead, ErrorBox, Loading, PageHead, Select, Stat, useApi } from '@/components/ui';

export default function Analytics({ ctx }) {
  const [days, setDays] = useState('30');
  const [propertyId, setPropertyId] = useState(ctx.defaultPropertyId);
  const a = useApi('/analytics', { query: { days, property_id: propertyId } });
  const d = a.data?.data;
  const series = d ? Object.entries(d.series || {}) : [];
  const max = Math.max(1, ...series.map(([, n]) => n));

  return (
    <>
      <PageHead title="Analytics & reports" subtitle="How your support is performing. Only websites you have access to are included.">
        <Select value={days} onChange={setDays} options={[['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days']].map(([value, label]) => ({ value, label }))} />
        {ctx.properties.length > 1 && <Select value={propertyId} onChange={setPropertyId} placeholder="All websites" options={ctx.properties.map((p) => ({ value: p.id, label: p.site_name }))} />}
      </PageHead>
      <ErrorBox error={a.error} onRetry={a.reload} />
      {!d ? <Loading /> : (
        <>
          <div className="stats-grid">
            <Stat icon={MessageSquareText} label="Conversations" value={d.conversations} sub={`${d.human_handled} handled by your team`} />
            <Stat icon={Clock} label="Avg. first response" value={d.avg_first_response_seconds != null ? `${Math.round(d.avg_first_response_seconds / 60)} min` : '—'} />
            <Stat icon={Ticket} label="Tickets created / resolved" value={`${d.tickets_created} / ${d.tickets_resolved}`} sub={`${d.tickets_open} open now`} />
            <Stat icon={CircleHelp} label="FAQ answers" value={d.bot_answers.faq} sub="Answered instantly, no AI cost" />
            <Stat icon={Bot} label="AI answers" value={d.bot_answers.ai} sub={`${d.bot_answers.escalations} handed to the team`} />
            <Stat icon={Target} label="New leads" value={d.leads_new} />
            <Stat icon={Star} label="Customer rating" value={d.csat_avg ? `${d.csat_avg} / 5` : '—'} />
            {d.ai_cost_usd !== null && <Stat icon={DollarSign} label="AI cost" value={fmt.usd(d.ai_cost_usd)} />}
          </div>
          <section className="card">
            <CardHead icon={BarChart3} title="Conversations per day" />
            {series.length === 0 ? <p className="muted">No conversations in this period.</p> : (
              <div className="bars" role="img" aria-label="Conversations per day">
                {series.map(([day, n]) => (
                  <div key={day} className="bar-col" title={`${day}: ${n}`}>
                    <div className="bar" style={{ height: `${(n / max) * 100}%` }} />
                    <div className="bar-label">{day.slice(5)}</div>
                  </div>
                ))}
              </div>
            )}
          </section>
          <div className="grid-2">
            <section className="card">
              <CardHead icon={Globe} title="By website" />
              <table className="table compact">
                <thead><tr><th>Website</th><th>Conversations</th><th>Open tickets</th><th>Leads</th></tr></thead>
                <tbody>{d.per_website.map((w) => <tr key={w.site_name}><td>{w.site_name}</td><td>{w.conversations}</td><td>{w.open_tickets}</td><td>{w.leads}</td></tr>)}</tbody>
              </table>
            </section>
            <section className="card">
              <CardHead icon={Target} title="Leads by status" />
              <table className="table compact"><tbody>{Object.entries(d.leads_by_status || {}).map(([s, n]) => <tr key={s}><td>{fmt.label(s)}</td><td>{n}</td></tr>)}</tbody></table>
              <h2 style={{ marginTop: 18 }}>Tickets by source</h2>
              <table className="table compact"><tbody>{Object.entries(d.tickets_by_source || {}).map(([s, n]) => <tr key={s}><td>{fmt.label(s)}</td><td>{n}</td></tr>)}</tbody></table>
            </section>
          </div>
        </>
      )}
    </>
  );
}
