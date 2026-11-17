<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChatboxFixtures;
use Tests\TestCase;

/** php artisan chatbox:clear-chats <company>: one company starts fresh, nothing else changes. */
class ClearCompanyChatDataTest extends TestCase
{
    use ChatboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCompanies();
        $this->tenantA->forceFill(['slug' => 'ambition'])->save();
    }

    private function countsFor(string $tenantId): array
    {
        $props = Property::where('tenant_id', $tenantId)->pluck('id');
        $convs = Conversation::whereIn('property_id', $props)->pluck('id');

        return [
            'chats' => $convs->count(),
            'messages' => DB::table('messages')->whereIn('conversation_id', $convs)->count(),
            'tickets' => Ticket::where('tenant_id', $tenantId)->count(),
            'leads' => Lead::where('tenant_id', $tenantId)->count(),
            'visitors' => Visitor::whereIn('property_id', $props)->count(),
        ];
    }

    private function fillBothCompanies(): void
    {
        $this->startChat($this->siteA, 'talk to a human please, my invoice is wrong');
        $this->startChat($this->siteA, 'hello, my name is Rahul, rahul@example.com');
        $this->startChat($this->siteB, 'talk to a human please, my domain is down');
        $this->runOutEscalationWindow();
    }

    public function test_only_the_named_company_is_cleared_and_everything_else_is_kept(): void
    {
        $this->fillBothCompanies();
        $before = $this->countsFor($this->tenantA->id);
        $this->assertGreaterThan(0, $before['chats']);
        $this->assertGreaterThan(0, $before['tickets']);
        $otherBefore = $this->countsFor($this->tenantB->id);
        $usersBefore = User::count();
        $propsBefore = Property::count();

        $this->artisan('chatbox:clear-chats', ['company' => 'ambition', '--force' => true])->assertSuccessful();

        $this->assertSame(['chats' => 0, 'messages' => 0, 'tickets' => 0, 'leads' => 0, 'visitors' => 0], $this->countsFor($this->tenantA->id));
        $this->assertSame($otherBefore, $this->countsFor($this->tenantB->id), 'Another company is never touched.');
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($propsBefore, Property::count());
        $this->assertSame(0, (int) $this->tenantA->fresh()->ticket_counter);
        $this->assertNotNull($this->tenantA->fresh(), 'The company itself stays.');

        // The widget works again and numbering starts from 1.
        $this->startChat($this->siteA, 'talk to a human please, my invoice is wrong');
        $this->runOutEscalationWindow();
        $this->assertStringEndsWith('-000001', Ticket::where('tenant_id', $this->tenantA->id)->firstOrFail()->reference);
    }

    public function test_a_wrong_confirmation_or_unknown_company_deletes_nothing(): void
    {
        $this->fillBothCompanies();
        $before = $this->countsFor($this->tenantA->id);

        $this->artisan('chatbox:clear-chats', ['company' => 'ambition'])
            ->expectsQuestion('This cannot be undone. Type the company address "ambition" to delete', 'yes')
            ->assertFailed();
        $this->artisan('chatbox:clear-chats', ['company' => 'no-such-company', '--force' => true])->assertFailed();

        $this->assertSame($before, $this->countsFor($this->tenantA->id));
    }

    public function test_the_company_can_be_named_by_its_exact_name_too(): void
    {
        $this->fillBothCompanies();
        $this->artisan('chatbox:clear-chats', ['company' => 'AmbitionHost'])
            ->expectsQuestion('This cannot be undone. Type the company address "ambition" to delete', 'ambition')
            ->assertSuccessful();
        $this->assertSame(0, $this->countsFor($this->tenantA->id)['chats']);
        $this->assertGreaterThan(0, $this->countsFor($this->tenantB->id)['chats']);
    }
}
