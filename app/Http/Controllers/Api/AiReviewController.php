<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAnswerReview;
use App\Models\AiSetting;
use App\Models\Message;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AnswerReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company-administrator tools around automated answers: list, rate, correct, analytics, regression, connection test. */
class AiReviewController extends Controller
{
    private function admin(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user->tenant_id && $user->isAdmin(), 403, 'Only company administrators can review automated answers.');

        return $user;
    }

    /** Recent automated answers with the customer question and the admin's own review. */
    public function answers(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $messages = Message::where('sender_type', 'bot')->where('metadata->source', 'ai')
            ->whereHas('conversation.property', fn ($q) => $q->where('tenant_id', $admin->tenant_id))
            ->with('conversation:id,property_id')->orderByDesc('created_at')->limit(50)->get();

        $reviews = AiAnswerReview::where('tenant_id', $admin->tenant_id)->where('reviewer_id', $admin->id)
            ->whereIn('message_id', $messages->pluck('id'))->get()->keyBy('message_id');

        return response()->json(['success' => true, 'data' => $messages->map(function (Message $m) use ($reviews) {
            $r = $reviews->get($m->id);

            return [
                'message_id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'property_id' => $m->conversation?->property_id,
                'answer' => mb_substr((string) $m->content, 0, 1500),
                'question' => Message::where('conversation_id', $m->conversation_id)->where('sender_type', 'visitor')->where('seq', '<', $m->seq)->orderByDesc('seq')->value('content'),
                'created_at' => $m->created_at?->toIso8601String(),
                'review' => $r ? $r->only(['id', 'rating', 'is_incorrect', 'correction_text', 'correction_status']) : null,
            ];
        })->values()]);
    }

    public function review(Request $request, string $messageId): JsonResponse
    {
        $admin = $this->admin($request);
        $v = $request->validate([
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'is_incorrect' => ['nullable', 'boolean'],
            'correction' => ['nullable', 'string', 'max:4000'],
        ]);
        abort_if(($v['rating'] ?? null) === null && empty($v['is_incorrect']) && trim((string) ($v['correction'] ?? '')) === '', 422, 'Give a rating, flag the answer or write a correction.');

        $message = AnswerReviewService::answerFor($admin->tenant_id, $messageId);
        $review = AnswerReviewService::review($admin, $message, $v['rating'] ?? null, (bool) ($v['is_incorrect'] ?? false), $v['correction'] ?? null);

        return response()->json(['success' => true, 'data' => $review->only(['id', 'rating', 'is_incorrect', 'correction_text', 'correction_status'])]);
    }

    public function approveCorrection(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $v = $request->validate(['correction' => ['nullable', 'string', 'max:4000']]);
        $review = AiAnswerReview::where('tenant_id', $admin->tenant_id)->where('id', $id)->firstOrFail();

        $review = AnswerReviewService::approveCorrection($review, $admin, $v['correction'] ?? null);

        return response()->json(['success' => true, 'data' => $review->only(['id', 'correction_status', 'knowledge_document_id'])]);
    }

    public function rejectCorrection(Request $request, string $id): JsonResponse
    {
        $admin = $this->admin($request);
        $review = AiAnswerReview::where('tenant_id', $admin->tenant_id)->where('id', $id)->firstOrFail();

        return response()->json(['success' => true, 'data' => AnswerReviewService::rejectCorrection($review, $admin)->only(['id', 'correction_status'])]);
    }

    public function quality(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AnswerReviewService::quality($this->admin($request)->tenant_id)]);
    }

    public function regression(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AnswerReviewService::runRegression($this->admin($request)->tenant_id)]);
    }

    /** One tiny call with the saved provider/model/key. The key and raw provider errors are never returned. */
    public function testConnection(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $settings = AiSetting::forTenant($admin->tenant_id);
        if ((string) $settings->api_key === '') {
            return response()->json(['success' => false, 'message' => 'Save an API key first.'], 422);
        }

        $started = microtime(true);
        $result = app(AiResponder::class)->ping($settings);
        $ms = (int) round((microtime(true) - $started) * 1000);

        return response()->json([
            'success' => $result->ok(),
            'message' => $result->ok() ? "Connected to {$settings->provider} ({$settings->model}) in {$ms} ms." : 'The provider did not accept the request. Check the key, model name and (for other providers) the base URL.',
            'provider' => $settings->provider,
            'model' => $settings->model,
        ], $result->ok() ? 200 : 422);
    }
}
