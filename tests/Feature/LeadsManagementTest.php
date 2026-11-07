<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeadsManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $ownerA;
    private User $agentA;
    private User $agentB;
    private Property $siteA1;
    private Property $siteA2;
    private Property $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Company A', 'email' => 'a@a.test', 'plan' => 'pro', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Company B', 'email' => 'b@b.test', 'plan' => 'pro', 'status' => 'active']);
        $this->ownerA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Owner A', 'email' => 'owner@a.test', 'password' => Hash::make('x'), 'role' => 'admin']);
        $this->agentA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Agent A', 'email' => 'agent@a.test', 'password' => Hash::make('x'), 'role' => 'agent']);
        $this->agentB = User::create(['tenant_id' => $this->tenantB->id, 'name' => 'Agent B', 'email' => 'agent@b.test', 'password' => Hash::make('x'), 'role' => 'agent']);

        $this->siteA1 = Property::create(['tenant_id' => $this->tenantA->id, 'site_name' => 'A Hosting', 'site_url' => 'https://a1.test', 'allowed_domains' => ['a1.test'], 'is_active' => true]);
        $this->siteA2 = Property::create(['tenant_id' => $this->tenantA->id, 'site_name' => 'A Domains', 'site_url' => 'https://a2.test', 'allowed_domains' => ['a2.test'], 'is_active' => true]);
        $this->siteB = Property::create(['tenant_id' => $this->tenantB->id, 'site_name' => 'B Site', 'site_url' => 'https://b.test', 'allowed_domains' => ['b.test'], 'is_active' => true]);
    }

    private function widgetInit(Property $p, array $data = []): string
    {
        $host = parse_url($p->site_url, PHP_URL_HOST);

        return $this->withHeaders(['Origin' => "https://{$host}"])
            ->postJson('/api/v1/widget/init', array_merge(['property_key' => $p->public_key], $data))
            ->assertOk()
            ->json('data.visitor.token');
    }

    private function startChat(Property $p, string $token, string $message): string
    {
        return $this->postJson('/api/v1/widget/start', [
            'property_key' => $p->public_key, 'visitor_token' => $token, 'initial_message' => $message,
        ])->assertCreated()->json('data.conversation.id');
    }

    private function makeLead(Property $p, array $attrs = []): Lead
    {
        return Lead::create(array_merge(['tenant_id' => $p->tenant_id, 'property_id' => $p->id, 'status' => 'new', 'source' => 'manual'], $attrs));
    }

    // ------------------------------------------------------------- automatic capture

    public function test_chat_creates_exactly_one_lead_per_visitor_and_keeps_it_updated(): void
    {
        $token = $this->widgetInit($this->siteA1, ['name' => 'Rahul Sharma', 'email' => 'Rahul@Example.com']);
        $this->assertSame(1, Lead::count());

        $convId = $this->startChat($this->siteA1, $token, 'I want to buy VPS hosting for my site rahulstore.in');
        foreach (['second message', 'third message'] as $text) {
            $this->postJson('/api/v1/widget/messages', [
                'property_key' => $this->siteA1->public_key, 'visitor_token' => $token, 'conversation_id' => $convId, 'content' => $text,
            ])->assertCreated();
        }
        $this->widgetInit($this->siteA1, ['visitor_token' => $token, 'name' => 'Rahul Sharma']);

        $this->assertSame(1, Lead::count(), 'Messages and repeat visits must not create duplicate leads.');
        $lead = Lead::first();
        $this->assertSame('Rahul Sharma', $lead->name);
        $this->assertSame('rahul@example.com', $lead->email);
        $this->assertSame('vps', $lead->interested_service);
        $this->assertSame('rahulstore.in', $lead->website);
        $this->assertSame('I want to buy VPS hosting for my site rahulstore.in', $lead->inquiry_summary, 'First inquiry is kept, later messages do not overwrite it.');
        $this->assertSame($this->siteA1->id, $lead->property_id);
        $this->assertSame('L-000001', $lead->reference);
        $this->assertTrue($lead->conversations()->where('conversations.id', $convId)->exists());
    }

    public function test_anonymous_page_view_is_not_a_lead_but_a_chat_with_only_a_question_is(): void
    {
        $token = $this->widgetInit($this->siteA1);
        $this->assertSame(0, Lead::count());

        $this->startChat($this->siteA1, $token, 'Do you sell domains? my mail is someone@mail.test');
        $lead = Lead::firstOrFail();
        $this->assertNull($lead->name);
        $this->assertSame('someone@mail.test', $lead->email);
        $this->assertSame('domain', $lead->interested_service);
        $this->assertNull($lead->website, 'An e-mail domain must not be mistaken for the customer website.');
    }

    public function test_visitors_sharing_a_name_or_email_are_never_merged(): void
    {
        $this->widgetInit($this->siteA1, ['name' => 'Amit', 'email' => 'same@x.test']);
        $this->widgetInit($this->siteA1, ['name' => 'Amit', 'email' => 'same@x.test']);

        $this->assertSame(2, Lead::count());
        $this->assertSame([1, 2], Lead::orderBy('lead_number')->pluck('lead_number')->all());
    }

    public function test_capture_fills_only_empty_fields_and_never_overwrites_staff_edits(): void
    {
        $token = $this->widgetInit($this->siteA1, ['name' => 'Original']);
        $lead = Lead::firstOrFail();

        $this->actingAs($this->ownerA, 'sanctum')->patchJson("/api/v1/leads/{$lead->id}", ['name' => 'Corrected By Staff', 'status' => 'contacted'])->assertOk();
        $this->widgetInit($this->siteA1, ['visitor_token' => $token, 'name' => 'Visitor Typed Again', 'phone' => '+91 98765 43210']);

        $lead->refresh();
        $this->assertSame('Corrected By Staff', $lead->name);
        $this->assertSame('contacted', $lead->status);
        $this->assertSame('+91 98765 43210', $lead->phone, 'Empty fields are still filled.');
    }

    // ------------------------------------------------------------- permissions

    public function test_company_isolation_for_list_detail_update_notes_and_export(): void
    {
        $leadA = $this->makeLead($this->siteA1, ['name' => 'Secret Customer A', 'email' => 'secret@a.test']);
        $this->makeLead($this->siteB, ['name' => 'Customer B']);

        $list = $this->actingAs($this->agentB, 'sanctum')->getJson('/api/v1/leads')->assertOk();
        $this->assertSame(['Customer B'], collect($list->json('data'))->pluck('name')->all());

        $this->actingAs($this->agentB, 'sanctum')->getJson("/api/v1/leads/{$leadA->id}")->assertNotFound();
        $this->actingAs($this->agentB, 'sanctum')->patchJson("/api/v1/leads/{$leadA->id}", ['status' => 'closed'])->assertNotFound();
        $this->actingAs($this->agentB, 'sanctum')->postJson("/api/v1/leads/{$leadA->id}/notes", ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->agentB, 'sanctum')->getJson("/api/v1/leads?property_id={$this->siteA1->id}")->assertOk()->assertJsonCount(0, 'data');

        $csv = $this->actingAs($this->agentB, 'sanctum')->get('/api/v1/leads/export?format=csv&scope=all')->streamedContent();
        $this->assertStringNotContainsString('Secret Customer A', $csv);
        $this->assertStringContainsString('Customer B', $csv);
        $this->assertSame('new', $leadA->fresh()->status);
    }

    public function test_website_specific_staff_only_see_their_websites_and_owner_sees_all(): void
    {
        $this->makeLead($this->siteA1, ['name' => 'Hosting Lead']);
        $domainLead = $this->makeLead($this->siteA2, ['name' => 'Domain Lead']);
        $hostingLead = Lead::where('name', 'Hosting Lead')->first();

        $this->agentA->setWebsiteAccess([$this->siteA2->id]);

        $agentList = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/leads')->assertOk();
        $this->assertSame(['Domain Lead'], collect($agentList->json('data'))->pluck('name')->all());
        $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/leads/{$hostingLead->id}")->assertNotFound();
        $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/leads/{$domainLead->id}")->assertOk();
        $csv = $this->actingAs($this->agentA, 'sanctum')->get('/api/v1/leads/export?format=csv&scope=all')->streamedContent();
        $this->assertStringNotContainsString('Hosting Lead', $csv);
        $meta = $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/leads/meta')->assertOk();
        $this->assertSame(['A Domains'], collect($meta->json('data.websites'))->pluck('site_name')->all());

        $ownerList = $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/leads')->assertOk();
        $this->assertEqualsCanonicalizing(['Hosting Lead', 'Domain Lead'], collect($ownerList->json('data'))->pluck('name')->all());

        // The same restriction applies to the chat inbox.
        $v = Visitor::create(['property_id' => $this->siteA1->id, 'visitor_token' => 'vtok_inbox001']);
        $conv = Conversation::create(['property_id' => $this->siteA1->id, 'visitor_id' => $v->id, 'status' => 'open']);
        $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/chat/conversations/{$conv->id}")->assertNotFound();
    }

    public function test_assignment_requires_staff_of_same_company_with_access_to_the_website(): void
    {
        $lead = $this->makeLead($this->siteA1);
        $this->agentA->setWebsiteAccess([$this->siteA2->id]);

        $this->actingAs($this->ownerA, 'sanctum')->patchJson("/api/v1/leads/{$lead->id}", ['assigned_to' => $this->agentB->id])->assertStatus(422);
        $this->actingAs($this->ownerA, 'sanctum')->patchJson("/api/v1/leads/{$lead->id}", ['assigned_to' => $this->agentA->id])->assertStatus(422);

        $this->agentA->setWebsiteAccess([]);
        $res = $this->actingAs($this->ownerA, 'sanctum')->patchJson("/api/v1/leads/{$lead->id}", ['assigned_to' => $this->agentA->id, 'status' => 'follow_up'])->assertOk();
        $this->assertSame('Agent A', $res->json('data.assigned_to.name'));
        $this->assertSame('Follow-up', $res->json('data.status_label'));
        $this->assertTrue(AuditLog::where('action', 'lead.updated')->where('subject_id', $lead->id)->exists());
    }

    public function test_agent_website_assignment_rejects_other_company_websites(): void
    {
        $this->actingAs($this->ownerA, 'sanctum')->putJson("/api/v1/agents/{$this->agentA->id}", ['property_ids' => [$this->siteB->id]])->assertStatus(422);
        $this->actingAs($this->ownerA, 'sanctum')->putJson("/api/v1/agents/{$this->agentA->id}", ['property_ids' => [$this->siteA2->id]])->assertOk();
        $this->assertSame([$this->siteA2->id], $this->agentA->fresh()->restrictedPropertyIds());
    }

    // ------------------------------------------------------------- filters

    public function test_filters_search_status_service_assignee_website_and_dates(): void
    {
        $this->makeLead($this->siteA1, ['name' => 'Priya Verma', 'email' => 'priya@x.test', 'phone' => '9811100000', 'status' => 'interested', 'interested_service' => 'hosting', 'assigned_to' => $this->agentA->id]);
        $this->makeLead($this->siteA2, ['name' => 'Karan', 'email' => 'karan@y.test', 'status' => 'new', 'interested_service' => 'domain']);
        $old = $this->makeLead($this->siteA1, ['name' => 'Old Lead', 'status' => 'closed']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $names = fn (string $qs) => collect($this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/leads?' . $qs)->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Priya Verma'], $names('search=98111'));
        $this->assertSame(['Karan'], $names('search=karan@'));
        $this->assertSame(['Priya Verma'], $names('status=interested'));
        $this->assertSame(['Karan'], $names('service=domain'));
        $this->assertSame(['Priya Verma'], $names('assigned_to=me'));
        $this->assertEqualsCanonicalizing(['Karan', 'Old Lead'], $names('assigned_to=unassigned'));
        $this->assertSame(['Karan'], $names('property_id=' . $this->siteA2->id));
        $this->assertSame(['Old Lead'], $names('date_to=' . now('Asia/Kolkata')->subDays(30)->format('Y-m-d')));
        $this->assertEqualsCanonicalizing(['Priya Verma', 'Karan'], $names('date_from=' . now('Asia/Kolkata')->subDay()->format('Y-m-d')));
        $this->assertSame([], $names('search=' . urlencode('%')));

        $this->actingAs($this->agentA, 'sanctum')->getJson('/api/v1/leads?status=bogus')->assertStatus(422);
    }

    // ------------------------------------------------------------- notes / delete / detail

    public function test_notes_detail_links_and_admin_only_delete_keeps_chats(): void
    {
        $token = $this->widgetInit($this->siteA1, ['name' => 'Neha']);
        $convId = $this->startChat($this->siteA1, $token, 'Need SSL');
        $lead = Lead::firstOrFail();

        $this->actingAs($this->agentA, 'sanctum')->postJson("/api/v1/leads/{$lead->id}/notes", ['body' => 'Called, will buy next week'])->assertCreated();
        $detail = $this->actingAs($this->agentA, 'sanctum')->getJson("/api/v1/leads/{$lead->id}")->assertOk();
        $this->assertSame('Called, will buy next week', $detail->json('data.notes.0.body'));
        $this->assertSame('Agent A', $detail->json('data.notes.0.author'));
        $this->assertSame($convId, $detail->json('data.conversations.0.id'));

        $this->actingAs($this->agentA, 'sanctum')->deleteJson("/api/v1/leads/{$lead->id}")->assertStatus(403);
        $this->actingAs($this->ownerA, 'sanctum')->deleteJson("/api/v1/leads/{$lead->id}")->assertOk();
        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        $this->assertDatabaseHas('conversations', ['id' => $convId]);
        $this->assertTrue(AuditLog::where('action', 'lead.deleted')->exists());
    }

    // ------------------------------------------------------------- export

    public function test_csv_export_is_filtered_neutralises_formulas_and_is_audited(): void
    {
        $this->makeLead($this->siteA1, ['name' => '=HYPERLINK("http://evil.test","click")', 'phone' => '+919800000000', 'status' => 'new', 'inquiry_summary' => '@SUM(A1)']);
        $this->makeLead($this->siteA1, ['name' => 'Closed Person', 'status' => 'closed']);

        $res = $this->actingAs($this->ownerA, 'sanctum')->get('/api/v1/leads/export?format=csv&status=new');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF\"Lead ID\",\"Customer Name\",\"Email Address\"", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+919800000000", $csv);
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertStringNotContainsString('Closed Person', $csv);

        $all = $this->actingAs($this->ownerA, 'sanctum')->get('/api/v1/leads/export?format=csv&scope=all&status=new')->streamedContent();
        $this->assertStringContainsString('Closed Person', $all);

        $log = AuditLog::where('action', 'leads.exported')->orderBy('id')->first();
        $this->assertSame('csv', $log->meta['format']);
        $this->assertSame(1, $log->meta['rows']);
        $this->assertSame('new', $log->meta['filters']['status']);
    }

    public function test_xlsx_export_is_a_valid_workbook_without_live_formulas(): void
    {
        $this->makeLead($this->siteA1, ['name' => '=HYPERLINK("http://evil.test","x")', 'email' => 'ok@a.test']);
        $this->makeLead($this->siteB, ['name' => 'Other Company']);

        $res = $this->actingAs($this->ownerA, 'sanctum')->get('/api/v1/leads/export?format=xlsx');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $bytes = $res->streamedContent();
        $this->assertStringStartsWith('PK', $bytes, 'XLSX must be a zip container.');

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($sheet);
        $this->assertStringNotContainsString('<f>', $sheet, 'No formula cells may be written.');
        $this->assertStringContainsString('ok@a.test', $sheet);
        $this->assertStringContainsString("'=HYPERLINK", html_entity_decode($sheet, ENT_QUOTES | ENT_XML1));
        $this->assertStringNotContainsString('Other Company', $sheet);
    }

    // ------------------------------------------------------------- migration backfill

    public function test_migration_copies_existing_crm_visitors_into_leads_without_touching_them(): void
    {
        $withData = Visitor::create(['property_id' => $this->siteA1->id, 'visitor_token' => 'vtok_legacy01', 'name' => 'Legacy Lead', 'email' => 'legacy@x.test',
            'metadata' => ['lead_status' => 'qualified', 'lead_notes' => 'VIP customer', 'initial_inquiry' => 'Need &amp; want hosting']]);
        Visitor::create(['property_id' => $this->siteA1->id, 'visitor_token' => 'vtok_legacy02']); // anonymous
        $conv = Conversation::create(['property_id' => $this->siteA1->id, 'visitor_id' => $withData->id, 'status' => 'closed']);

        $migration = require database_path('migrations/2026_11_05_110000_create_leads_tables.php');
        $migration->down();
        $migration->up();

        $this->assertSame(1, DB::table('leads')->count());
        $lead = Lead::firstOrFail();
        $this->assertSame('Legacy Lead', $lead->name);
        $this->assertSame('interested', $lead->status, 'Old "qualified" maps to "interested".');
        $this->assertSame('Need & want hosting', $lead->inquiry_summary);
        $this->assertStringContainsString('VIP customer', $lead->notes()->first()->body);
        $this->assertTrue($lead->conversations()->where('conversations.id', $conv->id)->exists());
        $this->assertSame(1, (int) DB::table('tenants')->where('id', $this->tenantA->id)->value('lead_counter'));
        $this->assertSame('qualified', $withData->fresh()->metadata['lead_status'], 'Visitor rows are not modified.');

        // New leads continue the numbering.
        $this->assertSame(2, $this->makeLead($this->siteA1)->lead_number);
    }
}
