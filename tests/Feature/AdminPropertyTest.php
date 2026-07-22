<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_and_delete_property(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = $this->payload();
        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)->assertRedirect();
        $property = Property::where('title', $payload['title'])->firstOrFail();
        $this->actingAs($admin)->put(route('admin.properties.update', $property), array_merge($payload, ['title' => 'Casa actualizada', 'slug' => $property->slug]))->assertRedirect();
        $this->assertDatabaseHas('properties', ['title' => 'Casa actualizada']);
        $this->actingAs($admin)->delete(route('admin.properties.destroy', $property))->assertRedirect();
        $this->assertSoftDeleted($property);
    }

    public function test_validation_rejects_incomplete_information(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.properties.store'), [])->assertSessionHasErrors(['title', 'description', 'operation_type', 'property_type', 'price']);
    }

    private function payload(): array
    {
        return [
            'title' => 'Casa de prueba',
            'slug' => 'casa-de-prueba',
            'description' => 'Descripción completa de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 4500000,
            'currency' => 'MXN',
            'neighborhood' => 'Del Valle',
            'city' => 'Benito Juárez',
            'state' => 'Ciudad de México',
            'status' => 'published',
        ];
    }
}
