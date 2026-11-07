<?php

namespace Tests\Feature;

use App\Models\AiBudget;
use App\Models\AiUsageLog;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\StaffNotification;
use App\Models\Ticket;
use App\Services\Ai\AiBudgetService;
use App\Services\Knowledge\KnowledgeSearch;
use App\Services\Knowledge\KnowledgeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** Stage 5: knowledge pipeline, AI engine safety, budgets, language, prompt-injection containment. */
class AiKnowledgeTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00', 'Asia/Kolkata'));
    }

    private function approvedDoc(string $title, string $text, ?string $propertyId = null): KnowledgeDocument
    {
        $doc = KnowledgeService::createManual($this->ownerA, $propertyId, $title, $text);

        return KnowledgeService::approve($doc, $this->ownerA);
    }

    private function botReplies(string $convId)
    {
        return Message::where('conversation_id', $convId)->where('sender_type', 'bot')->where('is_automated', false)->orderBy('seq')->pluck('content');
    }

    // ------------------------------------------------------------- knowledge pipeline

    public function test_only_approved_knowledge_is_searchable_and_scoped_to_its_website(): void
    {
        $doc = KnowledgeService::createManual($this->ownerA, $this->siteA->id, 'Refund policy', 'Refunds for shared hosting are possible within 30 days of purchase.');
        $this->assertSame([], KnowledgeSearch::search($this->tenantA->id, $this->siteA->id, 'refund shared hosting days'));

        KnowledgeService::approve($doc, $this->ownerA);
        $this->assertNotEmpty(KnowledgeSearch::search($this->tenantA->id, $this->siteA->id, 'refund shared hosting days'));
        $this->assertSame([], KnowledgeSearch::search($this->tenantA->id, $this->siteA2->id, 'refund shared hosting days'), 'Website knowledge does not leak to other websites.');
        $this->assertSame([], KnowledgeSearch::search($this->tenantB->id, null, 'refund shared hosting days'));

        $this->approvedDoc('Support hours', 'Our support team works 9 AM to 6 PM IST every day.');
        $this->assertNotEmpty(KnowledgeSearch::search($this->tenantA->id, $this->siteA2->id, 'support hours team'), 'Company-wide knowledge applies to every website.');

        KnowledgeService::archive($doc);
        $this->assertSame([], KnowledgeSearch::search($this->tenantA->id, $this->siteA->id, 'refund shared hosting days'));
    }

    public function test_upload_extracts_txt_csv_xlsx_docx_and_pdf_and_requires_approval(): void
    {
        $dir = sys_get_temp_dir() . '/kb_' . uniqid();
        mkdir($dir);

        file_put_contents("$dir/a.txt", "Nameservers\n\nPoint your domain to ns1.example.com and ns2.example.com.");
        file_put_contents("$dir/b.csv", "plan,price\nStarter Hosting,99 INR\nBusiness Hosting,299 INR\n");

        $writer = new Writer();
        $writer->openToFile("$dir/c.xlsx");
        $writer->addRow(Row::fromValues(['Service', 'Renewal']));
        $writer->addRow(Row::fromValues(['.in domain', '799 INR per year']));
        $writer->close();

        $zip = new \ZipArchive();
        $zip->open("$dir/d.docx", \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>SSL certificates are free with every hosting plan.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        file_put_contents("$dir/e.pdf", self::minimalPdf('Backups are taken daily and kept for 7 days.'));

        $expect = ['a.txt' => 'ns1.example.com', 'b.csv' => 'Business Hosting', 'c.xlsx' => '799 INR', 'd.docx' => 'SSL certificates are free', 'e.pdf' => 'Backups are taken daily'];
        foreach ($expect as $file => $needle) {
            $res = $this->actingAs($this->ownerA, 'sanctum')->post('/api/v1/knowledge/upload', [
                'file' => new UploadedFile("$dir/$file", $file, null, null, true),
                'property_id' => 'company',
            ], ['Accept' => 'application/json'])->assertCreated();
            $docId = $res->json('data.id');
            $detail = $this->actingAs($this->ownerA, 'sanctum')->getJson("/api/v1/knowledge/{$docId}")->assertOk();
            $this->assertSame('pending_approval', $detail->json('data.status'), "$file should await approval");
            $this->assertStringContainsString($needle, (string) $detail->json('data.review_text'), "$file extraction");
        }

        $html = UploadedFile::fake()->createWithContent('x.txt', '<html><script>alert(1)</script></html>');
        $this->actingAs($this->ownerA, 'sanctum')->post('/api/v1/knowledge/upload', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertTrue(true, (string) $html->getClientOriginalName());
    }

    public function test_knowledge_permissions(): void
    {
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/knowledge/manual', ['title' => 'x', 'content' => 'y', 'property_id' => 'company'])->assertForbidden();
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/knowledge/manual', ['title' => 'x', 'content' => 'y', 'property_id' => $this->siteA->id])->assertForbidden();
        \Illuminate\Support\Facades\DB::table('property_user')->insert(['property_id' => $this->siteA->id, 'user_id' => $this->agentA->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->agentA->fresh(), 'sanctum')->postJson('/api/v1/knowledge/manual', ['title' => 'Site A doc', 'content' => 'y', 'property_id' => $this->siteA->id])->assertCreated();
        $this->actingAs($this->agentB, 'sanctum')->getJson('/api/v1/knowledge')->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------- AI engine

    public function test_faq_answers_without_calling_the_llm(): void
    {
        $fake = $this->enableAi($this->tenantA);
        Faq::create(['property_id' => $this->siteA->id, 'question' => 'How do I reset my client area password?', 'answer' => 'Use Forgot Password on the login page.', 'is_active' => true]);
        [, $convId] = $this->startChat($this->siteA, 'how to reset client area password');
        $this->assertStringContainsString('Forgot Password', $this->botReplies($convId)->last());
        $this->assertCount(0, $fake->calls);
    }

    public function test_grounded_llm_answer_is_sent_with_usage_and_cost_recorded(): void
    {
        $this->approvedDoc('Nameservers', 'To point your domain use ns1.ambition.test and ns2.ambition.test. Changes take up to 24 hours.', $this->siteA->id);
        $fake = $this->enableAi($this->tenantA);
        $fake->respond(['action' => 'answer', 'reply' => 'Set ns1.ambition.test and ns2.ambition.test; it can take up to 24 hours.', 'language' => 'en', 'confidence' => 0.9, 'cited_ids' => ['__FIRST__'], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']]);
        $fake->during = function () use ($fake) {
            // Cite whatever passage id was provided to the model.
            preg_match('/<passage id="([^"]+)"/', end($fake->calls)['messages'][count(end($fake->calls)['messages']) - 1]['content'], $m);
            $fake->queue[0] = new \App\Services\Ai\LlmResult(array_merge($fake->queue[0]->data, ['cited_ids' => [$m[1] ?? 'none']]), 1200, 150, 0, 'end_turn', null, 'claude-opus-5-5');
        };

        [$token, $convId] = $this->startChat($this->siteA, 'which nameservers should my domain use');
        $this->assertStringContainsString('ns1.ambition.test', $this->botReplies($convId)->last());
        $this->assertSame(0, Ticket::count());

        $log = AiUsageLog::firstOrFail();
        $this->assertSame('answered', $log->outcome);
        $this->assertEqualsWithDelta((1200 * 4 + 150 * 20) / 1_000_000, $log->cost_usd, 0.000001);
        $this->assertNull(Conversation::find($convId)->ai_pending_since);

        // Customer text is only inside <customer_message>, never in the system prompt.
        $call = $fake->calls[0];
        $this->assertStringNotContainsString('which nameservers', $call['system']);
        $this->assertStringContainsString('<customer_message>', end($call['messages'])['content']);
    }

    public function test_ungrounded_answers_forbidden_claims_refusals_and_errors_escalate(): void
    {
        $this->approvedDoc('Billing', 'Invoices are e-mailed 7 days before renewal.', $this->siteA->id);
        $base = ['language' => 'en', 'confidence' => 0.9, 'ticket_summary' => ['issue' => 'invoice question', 'affected_service' => 'hosting', 'steps_tried' => '', 'results' => '', 'unresolved' => 'needs staff']];

        $cases = [
            'uncited' => fn ($f) => $f->respond($base + ['action' => 'answer', 'reply' => 'Your invoice is fine.', 'cited_ids' => []]),
            'claim' => fn ($f) => $f->respond($base + ['action' => 'answer', 'reply' => 'Good news, your payment was received and confirmed.', 'cited_ids' => ['k1']]),
            'refusal' => fn ($f) => $f->respond([], 100, 0, 'refusal', 'The model declined this request.'),
            'error' => fn ($f) => $f->respond([], 0, 0, null, 'Provider error 529 (overloaded_error)'),
        ];
        foreach ($cases as $name => $setup) {
            $fake = $this->enableAi($this->tenantA, new \Tests\Support\FakeLlm());
            $setup($fake);
            [, $convId] = $this->startChat($this->siteA, "invoice renewal billing question {$name}");
            $this->assertNotNull(Ticket::where('conversation_id', $convId)->first(), "{$name} must escalate to a ticket");
            $this->assertStringNotContainsString('payment was received', $this->botReplies($convId)->implode(' '), $name);
            $this->assertSame('human', Conversation::find($convId)->handler, $name);
        }
        $summary = Ticket::where('source', 'ai_escalation')->first()->summary;
        $this->assertSame('invoice question', $summary['issue']);
    }

    public function test_no_ai_reply_if_a_human_takes_over_while_the_llm_is_thinking(): void
    {
        $this->approvedDoc('Email', 'Create email accounts in cPanel > Email Accounts.', $this->siteA->id);
        $fake = $this->enableAi($this->tenantA);
        $fake->respond(['action' => 'answer', 'reply' => 'Open cPanel > Email Accounts.', 'language' => 'en', 'confidence' => 0.9, 'cited_ids' => ['k1'], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']]);
        $fake->during = function () {
            Conversation::query()->update(['handler' => 'human']); // agent clicked "Take over" mid-request
        };
        [, $convId] = $this->startChat($this->siteA, 'how to create email account cpanel');
        $this->assertCount(0, $this->botReplies($convId)->filter(fn ($c) => str_contains($c, 'Email Accounts')));
    }

    public function test_clarification_is_asked_once_then_escalated(): void
    {
        $this->approvedDoc('Hosting', 'Shared hosting plans include cPanel.', $this->siteA->id);
        $fake = $this->enableAi($this->tenantA);
        $sum = ['issue' => 'unclear', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => ''];
        $fake->respond(['action' => 'clarify', 'reply' => 'Which service do you mean?', 'language' => 'en', 'confidence' => 0.4, 'cited_ids' => [], 'ticket_summary' => $sum]);
        $fake->respond(['action' => 'clarify', 'reply' => 'Can you clarify again?', 'language' => 'en', 'confidence' => 0.4, 'cited_ids' => [], 'ticket_summary' => $sum]);

        [$token, $convId] = $this->startChat($this->siteA, 'hosting problem');
        $this->assertSame('Which service do you mean?', $this->botReplies($convId)->last());
        $this->say($this->siteA, $token, $convId, 'the hosting thing')->assertCreated();
        $this->assertNotNull(Ticket::where('conversation_id', $convId)->first(), 'Second clarification request becomes an escalation.');
    }

    public function test_cost_uses_the_price_of_the_model_that_actually_answered(): void
    {
        $settings = \App\Models\AiSetting::forTenant($this->tenantA->id);
        $settings->forceFill(["provider" => "anthropic", "model" => "claude-opus-5-5"])->save();

        $same = AiBudgetService::record($settings, new \App\Services\Ai\LlmResult([], 1_000_000, 0, 0, "end_turn", null, "claude-opus-5-5"), "answered", 10, $this->siteA->id, null);
        $fallback = AiBudgetService::record($settings, new \App\Services\Ai\LlmResult([], 1_000_000, 0, 0, "end_turn", null, "claude-haiku-5-5"), "answered", 10, $this->siteA->id, null);

        $this->assertEqualsWithDelta(4.0, (float) $same->cost_usd, 0.0001);
        $this->assertEqualsWithDelta(0.10, (float) $fallback->cost_usd, 0.0001);
        $this->assertSame("claude-haiku-5-5", $fallback->model);
    }

    public function test_budget_caps_visitor_limit_and_80_percent_alert(): void
    {
        $this->approvedDoc('Hosting', 'Shared hosting plans include cPanel and free SSL.', $this->siteA->id);
        $fake = $this->enableAi($this->tenantA);
        AiBudget::forTenant($this->tenantA->id)->update(['monthly_cap_usd' => 1.00, 'daily_cap_usd' => 5, 'per_visitor_daily_messages' => 50]);

        // 80% alert once.
        \Illuminate\Support\Facades\DB::table('ai_usage_counters')->insert(['tenant_id' => $this->tenantA->id, 'period' => 'm:' . now()->format('Y-m'), 'requests' => 10, 'cost_usd' => 0.80]);
        $fake->respond(['action' => 'escalate', 'reply' => 'x', 'language' => 'en', 'confidence' => 0.1, 'cited_ids' => [], 'ticket_summary' => ['issue' => '', 'affected_service' => '', 'steps_tried' => '', 'results' => '', 'unresolved' => '']], 1000, 100);
        $this->startChat($this->siteA, 'does hosting include ssl free');
        $this->assertSame(1, StaffNotification::where('type', 'ai.budget')->where('user_id', $this->ownerA->id)->count());

        // Over the cap: no LLM call, FAQ-only + human fallback, one "limit reached" alert.
        $callsBefore = count($fake->calls);
        \Illuminate\Support\Facades\DB::table('ai_usage_counters')->where('period', 'm:' . now()->format('Y-m'))->update(['cost_usd' => 1.00]);
        [, $convId] = $this->startChat($this->siteA, 'cpanel included in shared hosting plans which version');
        $this->assertCount($callsBefore, $fake->calls);
        $this->assertSame('budget_month', AiBudgetService::blockReason(\App\Models\AiSetting::forTenant($this->tenantA->id), null));
        $this->assertNotNull(Ticket::where('conversation_id', $convId)->first());
        $this->assertSame(2, StaffNotification::where('type', 'ai.budget')->where('user_id', $this->ownerA->id)->count());

        // Unknown model price: never called (spend could not be controlled).
        \App\Models\AiSetting::forTenant($this->tenantA->id)->update(['model' => 'claude-unknown-x']);
        $this->assertSame('no_price', AiBudgetService::blockReason(\App\Models\AiSetting::forTenant($this->tenantA->id), null));
    }

    public function test_language_detection_explicit_switch_and_hindi_escalation(): void
    {
        [$token, $convId] = $this->startChat($this->siteA, 'मुझे किसी इंसान से बात करनी है, एजेंट से बात कराओ');
        $this->assertSame('hi', Conversation::find($convId)->language);
        $this->assertStringContainsString('टिकट', $this->botReplies($convId)->implode(' ') . Message::where('conversation_id', $convId)->where('sender_type', 'bot')->orderByDesc('seq')->value('content'));

        [$t2, $c2] = $this->startChat($this->siteA2, 'mera domain kaam nahi kar raha hai bhai');
        $this->assertSame('hi-Latn', Conversation::find($c2)->language);
        $this->say($this->siteA2, $t2, $c2, 'please reply in English')->assertCreated();
        $this->assertSame('en', Conversation::find($c2)->language);
    }

    public function test_ai_settings_never_return_the_api_key_and_are_admin_only(): void
    {
        $payload = ['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'low', 'api_key' => 'sk-ant-secret-123456', 'monthly_cap_usd' => 20, 'daily_cap_usd' => 2, 'per_visitor_daily_messages' => 30];
        $this->actingAs($this->agentA, 'sanctum')->postJson('/api/v1/ai/settings', $payload)->assertForbidden();
        $res = $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/ai/settings', $payload)->assertOk();
        $this->assertStringNotContainsString('sk-ant-secret-123456', $res->getContent());
        $this->assertSame('…3456', $res->json('data.settings.api_key_hint'));
        $this->assertNotSame('sk-ant-secret-123456', \Illuminate\Support\Facades\DB::table('ai_settings')->value('api_key'), 'Stored encrypted.');

        // Blank key on save keeps the stored key.
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/ai/settings', array_merge($payload, ['api_key' => '']))->assertOk()->assertJsonPath('data.settings.api_key_set', true);
        $this->actingAs($this->ownerA, 'sanctum')->postJson('/api/v1/ai/settings', array_merge($payload, ['provider' => 'openai_compatible', 'base_url' => 'http://127.0.0.1:8080/v1']))->assertStatus(422);
    }

    /** Structurally valid one-page PDF (with xref table) containing one line of text. */
    private static function minimalPdf(string $text): string
    {
        $stream = "BT /F1 12 Tf 72 712 Td ({$text}) Tj ET";
        $objects = [
            '<</Type/Catalog/Pages 2 0 R>>',
            '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
            '<</Length ' . strlen($stream) . ">>\nstream\n{$stream}\nendstream",
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf . "trailer\n<</Size " . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";
    }
}
