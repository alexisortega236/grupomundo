<?php

namespace Tests\Feature;

use App\Enums\ValuationStatus;
use App\Models\Property;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyOriginSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_property_creation_defaults_to_commercial_origin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.properties.store'), [
            'title' => 'Casa comercial',
            'description' => 'Descripción comercial.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 2500000,
            'currency' => 'MXN',
            'neighborhood' => 'Del Valle',
            'city' => 'Ciudad de México',
            'state' => 'CDMX',
            'status' => 'draft',
        ])->assertRedirect();

        $this->assertDatabaseHas('properties', [
            'title' => 'Casa comercial',
            'origin' => Property::ORIGIN_COMMERCIAL,
        ]);
    }

    public function test_valuation_property_is_excluded_from_commercial_inventory_and_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technical = $this->technicalProperty();
        $commercial = Property::factory()->create(['created_by' => $admin->id, 'title' => 'Casa visible']);

        $this->actingAs($admin)->get(route('admin.properties.index'))
            ->assertOk()
            ->assertSee($commercial->title)
            ->assertDontSee($technical->title);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Últimas propiedades')
            ->assertSee($commercial->title)
            ->assertDontSee($technical->title);

        $dashboard = $this->actingAs($admin)->get(route('admin.dashboard'));
        $this->assertMatchesRegularExpression('/Total de propiedades.*?<p[^>]*>1<\/p>/s', $dashboard->getContent());
    }

    public function test_valuation_property_cannot_be_published_featured_or_updated_from_commercial_module(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technical = $this->technicalProperty();

        $this->actingAs($admin)->patch(route('admin.properties.toggle-published', $technical))
            ->assertForbidden();

        $this->actingAs($admin)->get(route('admin.properties.edit', $technical))
            ->assertForbidden();

        $this->actingAs($admin)->put(route('admin.properties.update', $technical), [
            'title' => 'Intento comercial',
            'description' => 'No debe actualizarse.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 1,
            'currency' => 'MXN',
            'neighborhood' => 'Centro',
            'city' => 'Ciudad de México',
            'state' => 'CDMX',
            'status' => 'published',
            'is_featured' => true,
        ])->assertForbidden();

        $this->assertDatabaseHas('properties', [
            'id' => $technical->id,
            'origin' => Property::ORIGIN_VALUATION,
            'status' => 'draft',
            'is_featured' => 0,
        ]);
    }

    public function test_force_delete_is_protected_when_property_has_valuations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technical = $this->technicalProperty();
        $valuation = $technical->valuations()->first();

        $this->actingAs($admin)->delete(route('admin.properties.force-delete', $technical->id))
            ->assertForbidden();

        $this->assertDatabaseHas('properties', ['id' => $technical->id]);
        $this->assertDatabaseHas('valuations', ['id' => $valuation->id]);
    }

    public function test_soft_deleted_property_remains_available_from_valuation(): void
    {
        $technical = $this->technicalProperty();
        $valuation = $technical->valuations()->first();
        $technical->delete();

        $this->assertTrue($valuation->fresh()->property->is($technical));
    }

    public function test_valuation_origin_never_enters_public_queries_even_if_historically_published(): void
    {
        $technical = $this->technicalProperty();
        $technical->update([
            'status' => 'published',
            'published_at' => now(),
            'is_featured' => true,
        ]);

        $this->get('/')->assertOk()->assertDontSee($technical->title);
        $this->get('/propiedades')->assertOk()->assertDontSee($technical->title);
        $this->get(route('properties.show', $technical))->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($technical->slug);
    }

    public function test_commercial_property_with_valuation_keeps_commercial_origin(): void
    {
        $property = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL]);
        $property->valuations()->create([
            'uuid' => (string) str()->uuid(),
            'status' => ValuationStatus::Pending,
        ]);

        $this->assertSame(Property::ORIGIN_COMMERCIAL, $property->fresh()->origin);
        $this->assertTrue(Property::commercial()->whereKey($property)->exists());
        $this->assertFalse(Property::valuationOrigin()->whereKey($property)->exists());
    }

    public function test_property_origin_cannot_change_from_commercial_to_valuation(): void
    {
        $property = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL]);

        $this->expectException(\InvalidArgumentException::class);
        $property->update(['origin' => Property::ORIGIN_VALUATION]);
    }

    public function test_property_origin_cannot_change_from_valuation_to_commercial(): void
    {
        $property = Property::factory()->create(['origin' => Property::ORIGIN_VALUATION]);

        $this->expectException(\InvalidArgumentException::class);
        $property->update(['origin' => Property::ORIGIN_COMMERCIAL]);
    }

    private function technicalProperty(): Property
    {
        $property = Property::factory()->create([
            'title' => 'Valuación técnica de prueba',
            'origin' => Property::ORIGIN_VALUATION,
            'status' => 'draft',
            'price' => 0,
            'is_featured' => false,
        ]);

        $property->valuations()->create([
            'uuid' => (string) str()->uuid(),
            'status' => ValuationStatus::Pending,
        ]);

        return $property;
    }
}
