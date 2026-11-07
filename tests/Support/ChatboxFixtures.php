<?php

namespace Tests\Support;

use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResult;
use Illuminate\Support\Facades\Hash;

/** Shared fixtures: two companies, two websites for company A, staff with different scopes. */
trait ChatboxFixtures
{
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $ownerA;
    protected User $agentA;
    protected User $agentB;
    protected Property $siteA;
    protected Property $siteA2;
    protected Property $siteB;

    protected function seedCompanies(): void
    {
        $this->tenantA = Tenant::create(['name' => 'AmbitionHost', 'email' => 'a@a.test', 'plan' => 'pro', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Other Co', 'email' => 'b@b.test', 'plan' => 'pro', 'status' => 'active']);
        $this->tenantA->forceFill(['ticket_prefix' => 'AMB'])->save();

        $this->ownerA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Aarav', 'email' => 'owner@a.test', 'password' => Hash::make('owner-pass-1'), 'role' => 'admin']);
        $this->tenantA->forceFill(['owner_user_id' => $this->ownerA->id])->save();
        $this->agentA = User::create(['tenant_id' => $this->tenantA->id, 'name' => 'Chandrika', 'email' => 'agent@a.test', 'password' => Hash::make('agent-pass-1'), 'role' => 'agent']);
        $this->agentB = User::create(['tenant_id' => $this->tenantB->id, 'name' => 'Bob', 'email' => 'agent@b.test', 'password' => Hash::make('agent-pass-1'), 'role' => 'agent']);

        $this->siteA = Property::create(['tenant_id' => $this->tenantA->id, 'site_name' => 'Ambition Hosting', 'site_url' => 'https://host.a.test', 'allowed_domains' => ['host.a.test'], 'is_active' => true, 'identity_mode' => 'pin_optional']);
        $this->siteA2 = Property::create(['tenant_id' => $this->tenantA->id, 'site_name' => 'Ambition Domains', 'site_url' => 'https://dom.a.test', 'allowed_domains' => ['dom.a.test'], 'is_active' => true, 'identity_mode' => 'pin_optional']);
        $this->siteB = Property::create(['tenant_id' => $this->tenantB->id, 'site_name' => 'Other Site', 'site_url' => 'https://b.test', 'allowed_domains' => ['b.test'], 'is_active' => true, 'identity_mode' => 'pin_optional']);

        // Default: team offline, so escalations create tickets (not live hand-over).
        User::query()->update(['is_online' => false]);
    }

    protected function origin(Property $p): array
    {
        return ['Origin' => 'https://' . parse_url($p->site_url, PHP_URL_HOST)];
    }

    /** Widget init + start chat; returns [visitorToken, conversationId, response]. */
    protected function startChat(Property $p, string $message, array $headers = [], ?string $token = null): array
    {
        $init = $this->withHeaders($this->origin($p) + $headers)->postJson('/api/v1/widget/init', array_filter(['property_key' => $p->public_key, 'visitor_token' => $token]))->assertOk();
        $token = $init->json('data.visitor.token');
        $start = $this->withHeaders($this->origin($p) + $headers)->postJson('/api/v1/widget/start', [
            'property_key' => $p->public_key, 'visitor_token' => $token, 'initial_message' => $message,
        ]);

        return [$token, $start->json('data.conversation.id'), $start];
    }

    protected function say(Property $p, string $token, string $conversationId, string $text, array $headers = [])
    {
        return $this->withHeaders($this->origin($p) + $headers)->postJson('/api/v1/widget/messages', [
            'property_key' => $p->public_key, 'visitor_token' => $token, 'conversation_id' => $conversationId, 'content' => $text,
        ]);
    }

    /** Last e-mail body sent through the array mailer. */
    protected function lastMailBody(): ?string
    {
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $last = $messages->last();

        return $last ? $last->getOriginalMessage()->getTextBody() : null;
    }

    protected function lastMailSubject(): ?string
    {
        $last = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last();

        return $last ? $last->getOriginalMessage()->getSubject() : null;
    }

    protected function mailCount(): int
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
    }

    protected function enableAi(Tenant $tenant, ?FakeLlm $fake = null): FakeLlm
    {
        AiSetting::forTenant($tenant->id)->update(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-test-key']);
        AiModelPrice::updateOrCreate(['provider' => 'anthropic', 'model' => 'claude-opus-5-5'], ['input_per_million' => 4, 'output_per_million' => 20]);
        $fake ??= new FakeLlm();
        app()->instance(LlmClient::class, $fake);

        return $fake;
    }
}

/** Test double for the LLM: returns queued responses and records every call. */
class FakeLlm implements LlmClient
{
    public array $calls = [];
    public array $queue = [];
    /** @var callable|null runs during the call (e.g. simulate a human taking over meanwhile) */
    public $during = null;

    public function respond(array $data, int $in = 1200, int $out = 150, ?string $stop = 'end_turn', ?string $error = null): self
    {
        $this->queue[] = new LlmResult($error ? null : $data, $in, $out, 0, $stop, $error, 'claude-opus-5-5');

        return $this;
    }

    public function complete(AiSetting $settings, string $system, array $messages, array $schema): LlmResult
    {
        $this->calls[] = compact('system', 'messages', 'schema');
        if ($this->during) {
            ($this->during)();
        }

        return array_shift($this->queue) ?? LlmResult::failed('no fake response queued');
    }
}
