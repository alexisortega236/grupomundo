<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_property_request_generates_compatible_message(): void
    {
        $property = $this->commercialProperty();

        $this->post(route('contact-requests.store'), $this->payload($property, [
            'name' => 'Ana',
            'phone' => '+52 (55) 1234-5678',
        ]))->assertRedirect();

        $this->assertDatabaseHas('contact_requests', [
            'property_id' => $property->id,
            'name' => 'Ana',
            'phone' => '+52 (55) 1234-5678',
            'email' => null,
            'message' => 'Solicitud de contacto desde la propiedad: '.$property->title,
        ]);
    }

    public function test_honeypot_and_fast_submission_do_not_persist(): void
    {
        $property = $this->commercialProperty();

        $this->post(route('contact-requests.store'), $this->payload($property, ['website' => 'bot']))
            ->assertRedirect();
        $this->assertDatabaseCount('contact_requests', 0);

        $this->post(route('contact-requests.store'), $this->payload($property, [
            'contact_form_token' => ContactRequest::issueFormToken(),
        ]))->assertRedirect();
        $this->assertDatabaseCount('contact_requests', 0);
    }

    public function test_turnstile_is_mocked_and_must_succeed_when_configured(): void
    {
        config(['services.turnstile.secret_key' => 'test-secret']);
        $property = $this->commercialProperty();
        Http::fake(['https://challenges.cloudflare.com/*' => function ($request) {
            return Http::response(['success' => $request->data()['response'] === 'valid-token']);
        }]);

        $this->post(route('contact-requests.store'), $this->payload($property, [
            'cf-turnstile-response' => 'invalid-token',
        ]))->assertRedirect();
        $this->assertDatabaseCount('contact_requests', 0);

        $validResponse = $this->post(route('contact-requests.store'), $this->payload($property, [
            'cf-turnstile-response' => 'valid-token',
        ]));
        $validResponse->assertRedirect();
        $this->assertDatabaseCount('contact_requests', 1);
    }

    public function test_contact_endpoint_is_limited_to_five_requests_per_minute_per_ip(): void
    {
        $property = $this->commercialProperty();

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
                ->post(route('contact-requests.store'), $this->payload($property, ['name' => 'Cliente '.$i]))
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->post(route('contact-requests.store'), $this->payload($property))
            ->assertTooManyRequests();
        $this->assertDatabaseCount('contact_requests', 5);
    }

    public function test_valuation_origin_cannot_be_contact_request_property(): void
    {
        $property = $this->commercialProperty(['origin' => Property::ORIGIN_VALUATION]);

        $this->post(route('contact-requests.store'), $this->payload($property))
            ->assertSessionHasErrors('property_id');
        $this->assertDatabaseCount('contact_requests', 0);
    }

    public function test_admin_still_displays_historical_email_and_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $request = ContactRequest::create([
            'name' => 'Cliente histórico',
            'phone' => '5512345678',
            'email' => 'historico@example.com',
            'message' => 'Mensaje histórico',
        ]);

        $this->actingAs($admin)->get(route('admin.contact-requests.show', $request))
            ->assertOk()
            ->assertSee('historico@example.com')
            ->assertSee('Mensaje histórico');
    }

    private function commercialProperty(array $attributes = []): Property
    {
        $user = User::factory()->create(['role' => 'admin']);

        return Property::factory()->create(array_merge([
            'created_by' => $user->id,
            'title' => 'Casa de prueba',
            'status' => 'published',
            'published_at' => now(),
        ], $attributes));
    }

    private function payload(Property $property, array $overrides = []): array
    {
        return array_merge([
            'property_id' => $property->id,
            'name' => 'Cliente Demo',
            'phone' => '5512345678',
            'contact_form_token' => ContactRequest::issueFormToken(time() - 4),
        ], $overrides);
    }
}
