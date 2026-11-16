'use client';

import { useState } from 'react';
import { api, fmt } from '@/lib/api';
import { CheckCircle2, ShieldCheck, Star, ThumbsDown, XCircle } from 'lucide-react';
import { Badge, Button, CardHead, Empty, ErrorBox, Loading, PageHead, Stat, useAction, useApi } from '@/components/ui';

/** Admin-only: rate automated answers, flag wrong ones, propose a correction and approve it into knowledge. */
function AnswerCard({ row, onChanged }) {
  const [run, busy] = useAction();
  const r = row.review;
  const [rating, setRating] = useState(r?.rating || 0);
  const [incorrect, setIncorrect] = useState(!!r?.is_incorrect);
  const [correction, setCorrection] = useState(r?.correction_text || '');

  const save = () => run(async () => {
    await api(`/ai/answers/${row.message_id}/review`, { method: 'POST', body: { rating: rating || undefined, is_incorrect: incorrect, correction: correction || undefined } });
    onChanged();
  }, 'Review saved');
  const decide = (verb) => run(async () => {
    await api(`/ai/reviews/${r.id}/${verb}`, { method: 'POST', body: verb === 'approve' ? { correction } : {} });
    onChanged();
  }, verb === 'approve' ? 'Correction approved and added to knowledge' : 'Correction rejected');

  return (
    <section className="card">
      <p className="small muted">{fmt.date(row.created_at)}</p>
      <p><strong>Customer:</strong> {row.question || '—'}</p>
      <p><strong>Answer sent:</strong> {row.answer}</p>
      <div className="row" role="group" aria-label="Rating">
        {[1, 2, 3, 4, 5].map((n) => (
          <button key={n} type="button" className="btn btn-sm" aria-label={`${n} star${n > 1 ? 's' : ''}`} onClick={() => setRating(n)}>
            <Star size={16} fill={n <= rating ? 'currentColor' : 'none'} />
          </button>
        ))}
        <button type="button" className={`btn btn-sm ${incorrect ? 'btn-danger' : ''}`} onClick={() => setIncorrect(!incorrect)}><ThumbsDown size={15} /> Incorrect</button>
        {r?.correction_status && r.correction_status !== 'none' && <Badge tone={r.correction_status === 'approved' ? 'green' : r.correction_status === 'rejected' ? 'red' : 'orange'}>{fmt.label(r.correction_status)}</Badge>}
      </div>
      <label className="field">
        <span className="small">Correct answer (written by you — it is only used after you approve it)</span>
        <textarea className="input" rows={3} maxLength={4000} value={correction} onChange={(e) => setCorrection(e.target.value)} disabled={r?.correction_status === 'approved'} />
      </label>
      <div className="row">
        <Button variant="primary" disabled={busy || (!rating && !incorrect && !correction.trim())} onClick={save}>Save review</Button>
        {r?.correction_status === 'pending' && (
          <>
            <Button disabled={busy} onClick={() => decide('approve')}><CheckCircle2 size={15} /> Approve into knowledge</Button>
            <Button disabled={busy} onClick={() => decide('reject')}><XCircle size={15} /> Reject</Button>
          </>
        )}
      </div>
    </section>
  );
}

export default function AiReview() {
  const answers = useApi('/ai/answers');
  const quality = useApi('/ai/quality');
  const [run, busy] = useAction();
  const [regression, setRegression] = useState(null);
  const q = quality.data?.data;

  if (answers.error) return <ErrorBox error={answers.error} onRetry={answers.reload} />;
  if (!answers.data) return <Loading />;
  const reload = () => { answers.reload(); quality.reload(); };
  const runRegression = () => run(async () => { const r = await api('/ai/regression/run', { method: 'POST', body: {} }); setRegression(r.data); quality.reload(); }, 'Regression check finished');

  return (
    <>
      <PageHead title="Answer review" subtitle="Rate automated answers and fix wrong ones. Nothing becomes trusted knowledge until you approve the exact text." />
      {q && (
        <div className="stats-grid">
          <Stat icon={Star} label="Average rating (30 days)" value={q.average_rating ?? '—'} sub={`${q.reviewed} reviewed`} />
          <Stat icon={ThumbsDown} label="Flagged incorrect" value={q.incorrect_flagged} sub={q.incorrect_rate_of_answered != null ? `${(q.incorrect_rate_of_answered * 100).toFixed(1)}% of answers` : 'no AI answers yet'} tone={q.incorrect_flagged ? 'warn' : undefined} />
          <Stat icon={ShieldCheck} label="Regression cases" value={q.regression_cases} sub={`${q.regression_failing} failing`} tone={q.regression_failing ? 'warn' : undefined} />
        </div>
      )}
      <section className="card">
        <CardHead icon={ShieldCheck} title="Regression check">
          <Button disabled={busy} onClick={runRegression}>Run check</Button>
        </CardHead>
        <p className="muted small">Confirms that every approved correction is still found for its customer question. It makes no AI calls and costs nothing.</p>
        {regression && <p>{regression.passed} of {regression.total} passed{regression.failed ? ` — ${regression.failed} failing` : ''}.</p>}
      </section>
      {answers.data.data.length === 0
        ? <Empty title="No automated answers yet">They appear here once the assistant starts answering customers.</Empty>
        : answers.data.data.map((row) => <AnswerCard key={row.message_id} row={row} onChanged={reload} />)}
    </>
  );
}
