<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ApprovedCommercialChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_order_activate_and_deactivate_property_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.property-types.store'), [
            'name' => 'Estudio', 'sort_order' => 2, 'is_active' => 1,
        ])->assertRedirect();
        $type = PropertyType::where('name', 'Estudio')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.property-types.update', $type), [
            'name' => 'Estudio renovado', 'sort_order' => 1, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertDatabaseHas('property_types', ['name' => 'Estudio renovado', 'sort_order' => 1]);

        $this->actingAs($admin)->patch(route('admin.property-types.toggle-active', $type->fresh()))->assertRedirect();
        $this->assertDatabaseHas('property_types', ['id' => $type->id, 'is_active' => false]);
        $this->actingAs($admin)->get(route('admin.property-types.index'))->assertSee('Estudio renovado')->assertSee('Inactivo');
    }

    public function test_inactive_type_cannot_be_assigned_to_new_property_but_existing_assignment_survives(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $type = PropertyType::where('name', 'Casa')->firstOrFail();
        $existing = Property::factory()->create(['created_by' => $admin->id, 'property_type' => 'Casa', 'property_type_id' => $type->id]);
        $type->update(['is_active' => false]);

        $this->actingAs($admin)->post(route('admin.properties.store'), $this->payload(['property_type' => 'Casa']))->assertSessionHasErrors('property_type');
        $this->assertDatabaseHas('properties', ['id' => $existing->id, 'property_type' => 'Casa', 'property_type_id' => $type->id]);

        $this->actingAs($admin)->put(route('admin.properties.update', $existing), $this->payload(['property_type' => 'Casa']))->assertRedirect();
        $this->assertDatabaseHas('properties', ['id' => $existing->id, 'property_type' => 'Casa', 'property_type_id' => $type->id]);
    }

    #[DataProvider('legacyBathroomCases')]
    public function test_legacy_bathroom_breakdown_is_compatible(?float $legacy, ?array $expected): void
    {
        $this->assertSame($expected, Property::bathroomBreakdownFromLegacy($legacy));
    }

    public static function legacyBathroomCases(): array
    {
        return [
            'one' => [1.0, ['full_bathrooms' => 1, 'half_bathrooms' => 0]],
            'one and half' => [1.5, ['full_bathrooms' => 1, 'half_bathrooms' => 1]],
            'two' => [2.0, ['full_bathrooms' => 2, 'half_bathrooms' => 0]],
            'two and half' => [2.5, ['full_bathrooms' => 2, 'half_bathrooms' => 1]],
            'three and half' => [3.5, ['full_bathrooms' => 3, 'half_bathrooms' => 1]],
            'null' => [null, null],
            'unexpected decimal' => [2.7, null],
        ];
    }

    public function test_commercial_catalog_accepts_consultorio_and_bodega_and_public_filters_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['Consultorio', 'Bodega'] as $type) {
            $this->actingAs($admin)->post(route('admin.properties.store'), $this->payload(['property_type' => $type, 'title' => $type]))->assertRedirect();
        }

        $this->assertDatabaseHas('properties', ['property_type' => 'Consultorio']);
        $this->get('/propiedades?property_type=Consultorio')->assertSee('Consultorio');
        $this->get('/propiedades?property_type=Bodega')->assertSee('Bodega');
    }

    public function test_admin_calculates_bathroom_legacy_value_and_keeps_breakdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = $this->payload(['full_bathrooms' => 2, 'half_bathrooms' => 2]);

        $this->actingAs($admin)->post(route('admin.properties.store'), $data)->assertRedirect();
        $property = Property::latest('id')->firstOrFail();
        $this->assertSame(2, $property->full_bathrooms);
        $this->assertSame(2, $property->half_bathrooms);
        $this->assertSame('3.0', (string) $property->bathrooms);
        $this->get(route('properties.show', $property))->assertSee('2 completos + 2 medios');

        $this->actingAs($admin)->put(route('admin.properties.update', $property), $this->payload([
            'title' => $property->title,
            'full_bathrooms' => 1,
            'half_bathrooms' => 1,
        ]))->assertRedirect();
        $this->assertSame('1.5', (string) $property->fresh()->bathrooms);
    }

    public function test_public_home_separates_featured_and_normal_properties_without_valuation_origin(): void
    {
        $featured = Property::factory()->create(['title' => 'Única destacada', 'is_featured' => true]);
        $normal = Property::factory()->create(['title' => 'Única normal', 'is_featured' => false]);
        Property::factory()->create(['title' => 'No debe salir', 'origin' => Property::ORIGIN_VALUATION, 'is_featured' => false]);

        $this->get('/')->assertSeeInOrder(['Propiedades destacadas', $featured->title, 'Propiedades normales', $normal->title])->assertDontSee('No debe salir');
    }

    public function test_valuation_lead_derives_property_and_valuation_ids_from_uuid(): void
    {
        $property = Property::factory()->create(['origin' => Property::ORIGIN_VALUATION]);
        $valuation = $property->valuations()->create(['uuid' => fake()->uuid(), 'source' => 'public', 'status' => 'completed']);

        $this->post(route('contact-requests.store'), [
            'valuation_uuid' => $valuation->uuid,
            'property_id' => Property::factory()->create()->id,
            'name' => 'Cliente de valuación',
            'phone' => '5512345678',
            'email' => 'cliente@example.com',
            'contact_form_token' => ContactRequest::issueFormToken(time() - 4),
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_requests', ['valuation_id' => $valuation->id, 'property_id' => $property->id]);
    }

    public function test_invalid_valuation_uuid_is_rejected(): void
    {
        $this->post(route('contact-requests.store'), [
            'valuation_uuid' => fake()->uuid(),
            'name' => 'Cliente',
            'phone' => '5512345678',
            'contact_form_token' => ContactRequest::issueFormToken(time() - 4),
        ])->assertSessionHasErrors('valuation_uuid');
    }

    public function test_public_location_search_keeps_historical_city_and_new_municipality(): void
    {
        Property::factory()->create(['state' => 'Estado histórico', 'city' => 'Ciudad antigua', 'municipality' => null, 'neighborhood' => 'Colonia Antigua']);
        Property::factory()->create(['state' => 'Estado histórico', 'city' => 'Ciudad nueva', 'municipality' => 'Municipio nuevo', 'neighborhood' => 'Colonia Nueva']);

        $this->getJson('/valuador/locations/municipalities?state=Estado%20histórico')->assertJsonFragment(['Ciudad antigua'])->assertJsonFragment(['Municipio nuevo']);
        $this->getJson('/valuador/locations/settlements?state=Estado%20histórico&municipality=Ciudad%20antigua&q=Antigua')->assertJsonFragment(['name' => 'Colonia Antigua']);
        $this->get('/propiedades?state=Estado%20histórico&municipality=Municipio%20nuevo')->assertSee('Colonia Nueva')->assertDontSee('Colonia Antigua');
    }

    public function test_admin_marks_valuation_leads_and_links_the_valuation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create(['origin' => Property::ORIGIN_VALUATION]);
        $valuation = $property->valuations()->create(['uuid' => fake()->uuid(), 'source' => 'public', 'status' => 'completed']);
        $request = ContactRequest::create(['valuation_id' => $valuation->id, 'property_id' => $property->id, 'name' => 'Lead', 'phone' => '5512345678', 'message' => 'Lead de prueba']);

        $this->actingAs($admin)->get(route('admin.contact-requests.show', $request))->assertSee('Lead de valuación')->assertSee(route('admin.valuations.show', $valuation), false);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Propiedad comercial',
            'description' => 'Descripción comercial.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 1000000,
            'currency' => 'MXN',
            'neighborhood' => 'Centro',
            'city' => 'Cuernavaca',
            'state' => 'Morelos',
            'status' => 'published',
        ], $overrides);
    }
}
