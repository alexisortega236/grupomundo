<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_loads(): void
    {
        $this->seed();
        $this->get('/')->assertOk()->assertSee('Encuentra el espacio ideal');
    }

    public function test_catalog_loads_and_filters(): void
    {
        $this->seed();
        $this->get('/propiedades?operation_type=sale')->assertOk()->assertSee('resultados encontrados');
    }

    public function test_published_property_can_be_seen(): void
    {
        $this->seed();
        $property = Property::published()->first();
        $this->get(route('properties.show', $property))->assertOk()->assertSee($property->title);
    }

    public function test_draft_property_cannot_be_seen_publicly(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create(['created_by' => $user->id, 'status' => 'draft', 'published_at' => null]);
        $this->get(route('properties.show', $property))->assertNotFound();
    }

    public function test_visitor_can_send_valid_contact_request(): void
    {
        $this->post(route('contact-requests.store'), [
            'name' => 'Cliente Demo',
            'phone' => '5512345678',
            'email' => 'cliente@example.com',
            'message' => 'Quiero informacion.',
            'website' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_requests', ['phone' => '5512345678']);
    }

    public function test_validation_rejects_incomplete_information(): void
    {
        $this->post(route('contact-requests.store'), [])->assertSessionHasErrors(['name', 'phone', 'message']);
    }

    public function test_guest_cannot_enter_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_authorized_user_can_enter_admin_panel(): void
    {
        $user = User::factory()->create(['role' => 'editor']);
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Dashboard');
    }

    public function test_admin_can_create_edit_and_delete_property(): void
    {
        $this->seed();
        $admin = User::where('role', 'admin')->first();
        $payload = [
            'title' => 'Departamento prueba',
            'slug' => 'departamento-prueba',
            'description' => 'Descripcion completa de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Departamento',
            'price' => 2500000,
            'currency' => 'MXN',
            'neighborhood' => 'Del Valle',
            'city' => 'Ciudad de Mexico',
            'state' => 'CDMX',
            'status' => 'draft',
        ];

        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('properties', ['slug' => 'departamento-prueba']);

        $property = Property::where('slug', 'departamento-prueba')->first();
        $this->actingAs($admin)->put(route('admin.properties.update', $property), array_merge($payload, ['title' => 'Departamento prueba editado']))->assertRedirect();
        $this->assertDatabaseHas('properties', ['title' => 'Departamento prueba editado']);

        $this->actingAs($admin)->delete(route('admin.properties.destroy', $property))->assertRedirect();
        $this->assertSoftDeleted('properties', ['id' => $property->id]);
    }

    public function test_admin_uploads_are_optimized_to_webp_versions(): void
    {
        Storage::fake('public');
        $this->seed();
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->post(route('admin.properties.store'), [
            'title' => 'Casa con imagen optimizada',
            'slug' => 'casa-con-imagen-optimizada',
            'description' => 'Descripcion completa de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 3500000,
            'currency' => 'MXN',
            'neighborhood' => 'Del Valle',
            'city' => 'Ciudad de Mexico',
            'state' => 'CDMX',
            'status' => 'published',
            'images' => [UploadedFile::fake()->image('fachada.jpg', 2200, 1400)],
        ])->assertRedirect();

        $image = Property::where('slug', 'casa-con-imagen-optimizada')->first()->images()->first();

        $this->assertNotNull($image);
        $this->assertStringEndsWith('-large.webp', $image->path);
        $this->assertStringEndsWith('-card.webp', $image->card_path);
        $this->assertStringEndsWith('-thumb.webp', $image->thumb_path);
        $this->assertNotNull($image->size_kb);
        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertExists($image->card_path);
        Storage::disk('public')->assertExists($image->thumb_path);
    }
}
