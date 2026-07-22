<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_and_catalog_load(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('Encuentra el espacio ideal');
        $this->get('/propiedades')->assertOk()->assertSee('Propiedades');
    }

    public function test_published_property_can_be_seen_and_draft_cannot(): void
    {
        $this->seed();
        $published = Property::published()->first();
        $draft = Property::where('status', 'draft')->first();
        $this->get(route('properties.show', $published))->assertOk()->assertSee($published->title);
        $this->get(route('properties.show', $draft))->assertNotFound();
    }

    public function test_filters_work(): void
    {
        $this->seed();
        $this->get('/propiedades?operation_type=rent')->assertOk()->assertSee('Renta');
    }

    public function test_visitor_can_send_valid_request(): void
    {
        $this->seed();
        $property = Property::published()->first();
        $this->post(route('contact.requests.store'), ['property_id' => $property->id, 'name' => 'Cliente', 'phone' => '5512345678', 'email' => 'cliente@test.com', 'message' => 'Quiero recibir más información de esta propiedad.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contact_requests', ['name' => 'Cliente', 'property_id' => $property->id]);
    }

    public function test_visitor_cannot_enter_admin_and_authorized_user_can(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $user = User::factory()->create(['role' => 'editor']);
        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
