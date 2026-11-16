<?php

namespace App\Services\Ai;

use App\Models\AiAnswerReview;
use App\Models\AiRegressionCase;
use App\Models\AiUsageLog;
use App\Models\KnowledgeChunk;
use App\Models\Message;
use App\Models\User;
use App\Services\Audit;
use App\Services\Knowledge\KnowledgeSearch;
use App\Services\Knowledge\KnowledgeService;
use Illuminate\Validation\ValidationException;

/**
 * Admin rating of automated answers and the controlled path from "this answer was wrong" to trusted knowledge.
 *
 * Trust rule: knowledge changes ONLY through text a company administrator typed and explicitly approved.
 * Customer messages and model output are never copied into trusted knowledge automatically.
 */
class AnswerReviewService
{
    /** Automated answer (source = ai) that belongs to this company, or 404-style failure. */
    public static function answerFor(string $tenantId, string $messageId): Message
    {
        $message = Message::where('id', $messageId)->where('sender_type', 'bot')->where('metadata->source', 'ai')
            ->whereHas('conversation.property', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('conversation')->first();
        abort_unless($message, 404, 'Answer not found.');

        return $message;
    }

    public static function review(User $admin, Message $message, ?int $rating, bool $incorrect, ?string $correction): AiAnswerReview
    {
        $question = Message::where('conversation_id', $message->conversation_id)->where('sender_type', 'visitor')
            ->where('seq', '<', $message->seq)->orderByDesc('seq')->value('content');

        $review = AiAnswerReview::firstOrNew(['message_id' => $message->id, 'reviewer_id' => $admin->id]);
        $review->fill([
            'tenant_id' => $admin->tenant_id,
            'property_id' => $message->conversation->property_id,
            'conversation_id' => $message->conversation_id,
            'rating' => $rating,
            'is_incorrect' => $incorrect || ($rating !== null && $rating <= 2),
            'question' => $question ? mb_substr($question, 0, 1000) : null,
            'answer_excerpt' => mb_substr((string) $message->content, 0, 1500),
        ]);
        $correction = trim((string) $correction);
        if ($correction !== '' && $review->correction_status !== 'approved') {
            $review->correction_text = $correction;
            $review->correction_status = 'pending';   // a proposal only: not searchable until approved
        }
        $review->save();
        Audit::record('ai.answer_reviewed', $review, ['rating' => $rating, 'incorrect' => $review->is_incorrect], $admin, $admin->tenant_id);

        return $review;
    }

    /** Approve the admin's own correction text: creates + approves a knowledge document and a regression case. */
    public static function approveCorrection(AiAnswerReview $review, User $admin, ?string $editedText = null): AiAnswerReview
    {
        if ($review->correction_status !== 'pending') {
            throw ValidationException::withMessages(['correction' => ['There is no pending correction to approve.']]);
        }
        $text = trim((string) ($editedText ?: $review->correction_text));
        if ($text === '') {
            throw ValidationException::withMessages(['correction' => ['The correction text is empty.']]);
        }

        $title = 'Correction: ' . mb_substr((string) ($review->question ?: 'customer question'), 0, 120);
        $doc = KnowledgeService::createManual($admin, $review->property_id, $title, ($review->question ? "Q: {$review->question}\nA: " : '') . $text, 'correction');
        KnowledgeService::approve($doc, $admin);

        $review->forceFill([
            'correction_text' => $text, 'correction_status' => 'approved', 'decided_by' => $admin->id,
            'decided_at' => now(), 'knowledge_document_id' => $doc->id,
        ])->save();

        if ($review->question) {
            AiRegressionCase::create([
                'tenant_id' => $review->tenant_id, 'property_id' => $review->property_id, 'review_id' => $review->id,
                'question' => $review->question, 'knowledge_document_id' => $doc->id,
            ]);
        }
        Audit::record('ai.correction_approved', $review, ['document' => $doc->id], $admin, $admin->tenant_id);

        return $review->fresh();
    }

    public static function rejectCorrection(AiAnswerReview $review, User $admin): AiAnswerReview
    {
        if ($review->correction_status !== 'pending') {
            throw ValidationException::withMessages(['correction' => ['There is no pending correction to reject.']]);
        }
        $review->forceFill(['correction_status' => 'rejected', 'decided_by' => $admin->id, 'decided_at' => now()])->save();
        Audit::record('ai.correction_rejected', $review, [], $admin, $admin->tenant_id);

        return $review->fresh();
    }

    /**
     * Retrieval regression: every approved correction must still be found for its question. No AI call, no cost,
     * so it is safe to run from cron (`chatbox:ai-regression`) after any knowledge, matching or prompt change.
     *
     * @return array{total:int, passed:int, failed:int, failures:array<int,array{question:string}>}
     */
    public static function runRegression(string $tenantId): array
    {
        $out = ['total' => 0, 'passed' => 0, 'failed' => 0, 'failures' => []];
        foreach (AiRegressionCase::where('tenant_id', $tenantId)->get() as $case) {
            $hits = KnowledgeSearch::search($tenantId, $case->property_id, $case->question, 5);
            $chunkIds = collect($hits)->where('type', 'knowledge')->map(fn ($h) => (int) substr($h['id'], 1))->all();
            $docs = $chunkIds ? KnowledgeChunk::whereIn('id', $chunkIds)->pluck('document_id')->all() : [];
            $pass = in_array($case->knowledge_document_id, $docs, true);

            $case->forceFill(['last_result' => $pass ? 'pass' : 'fail', 'last_run_at' => now()])->save();
            $out['total']++;
            $out[$pass ? 'passed' : 'failed']++;
            if (! $pass) {
                $out['failures'][] = ['question' => $case->question];
            }
        }

        return $out;
    }

    /** Answer-quality analytics for the last 30 days. */
    public static function quality(string $tenantId): array
    {
        $reviews = AiAnswerReview::where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30));
        $rated = (clone $reviews)->whereNotNull('rating');
        $usage = AiUsageLog::where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('outcome, count(*) as c')->groupBy('outcome')->pluck('c', 'outcome');
        $answered = (int) ($usage['answered'] ?? 0);
        $incorrect = (clone $reviews)->where('is_incorrect', true)->count();

        return [
            'window_days' => 30,
            'reviewed' => (clone $reviews)->count(),
            'average_rating' => $rated->count() ? round((float) (clone $rated)->avg('rating'), 2) : null,
            'rating_breakdown' => (clone $rated)->selectRaw('rating, count(*) as c')->groupBy('rating')->orderBy('rating')->pluck('c', 'rating'),
            'incorrect_flagged' => $incorrect,
            'incorrect_rate_of_answered' => $answered > 0 ? round($incorrect / $answered, 4) : null,
            'corrections' => AiAnswerReview::where('tenant_id', $tenantId)->selectRaw('correction_status, count(*) as c')->groupBy('correction_status')->pluck('c', 'correction_status'),
            'outcomes' => $usage,
            'regression_cases' => AiRegressionCase::where('tenant_id', $tenantId)->count(),
            'regression_failing' => AiRegressionCase::where('tenant_id', $tenantId)->where('last_result', 'fail')->count(),
        ];
    }
}
