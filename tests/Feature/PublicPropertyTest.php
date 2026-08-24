<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\ContactRequest;
use App\Models\Property;
use App\Models\PropertyImage;
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
        $this->get('/')
            ->assertOk()
            ->assertSee('Encuentra el espacio ideal')
            ->assertSee('Descubre una selección de propiedades residenciales, comerciales e industriales para vivir, invertir y hacer crecer tu patrimonio, con asesoría profesional y acompañamiento especializado de principio a fin.');
    }

    public function test_home_shows_only_published_featured_properties_and_limits_to_six(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $visible = Property::factory()->count(7)->sequence(
            fn ($sequence) => [
                'created_by' => $admin->id,
                'title' => 'Destacada visible '.$sequence->index,
                'slug' => 'destacada-visible-'.$sequence->index,
                'is_featured' => true,
                'status' => 'published',
                'published_at' => now()->subMinutes($sequence->index),
            ],
        )->create();
        $notFeatured = Property::factory()->create([
            'created_by' => $admin->id,
            'title' => 'No destacada home',
            'slug' => 'no-destacada-home',
            'is_featured' => false,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $draftFeatured = Property::factory()->create([
            'created_by' => $admin->id,
            'title' => 'Borrador destacado home',
            'slug' => 'borrador-destacado-home',
            'is_featured' => true,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('Destacada visible 0')
            ->assertSee('Destacada visible 5')
            ->assertDontSee('Destacada visible 6')
            ->assertDontSee($notFeatured->title)
            ->assertDontSee($draftFeatured->title);
        $this->assertCount(6, $visible->take(6));
    }

    public function test_catalog_loads_and_filters(): void
    {
        $this->seed();
        $this->get('/propiedades?operation_type=sale')->assertOk()->assertSee('resultados encontrados');
    }

    public function test_presale_property_appears_and_can_be_filtered_publicly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'title' => 'Departamento en preventa',
            'slug' => 'departamento-en-preventa',
            'operation_type' => 'presale',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/propiedades?operation_type=presale')
            ->assertOk()
            ->assertSee($property->title)
            ->assertSee('Preventa');

        $this->get(route('properties.show', $property))
            ->assertOk()
            ->assertSee($property->title)
            ->assertSee('Preventa');
    }

    public function test_services_render_five_expected_cards(): void
    {
        $this->get('/servicios')
            ->assertOk()
            ->assertSeeInOrder([
                '01',
                'Compra y venta',
                '02',
                'Rentas',
                '03',
                'Administración',
                '04',
                'Gestión y asesoría',
                '05',
                'Protección patrimonial',
            ]);
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
        $this->actingAs($user)->get('/admin')->assertOk()->assertSee('Panel');
    }

    public function test_public_operation_filters_include_sale_rent_in_sale_and_rent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sale = Property::factory()->create(['created_by' => $admin->id, 'title' => 'Solo venta', 'operation_type' => 'sale', 'status' => 'published', 'published_at' => now()]);
        $rent = Property::factory()->create(['created_by' => $admin->id, 'title' => 'Solo renta', 'operation_type' => 'rent', 'status' => 'published', 'published_at' => now()]);
        $both = Property::factory()->create(['created_by' => $admin->id, 'title' => 'Venta y renta', 'operation_type' => 'sale_rent', 'status' => 'published', 'published_at' => now()]);
        $presale = Property::factory()->create(['created_by' => $admin->id, 'title' => 'Solo preventa', 'operation_type' => 'presale', 'status' => 'published', 'published_at' => now()]);

        $this->get('/propiedades?operation_type=sale')->assertSee($sale->title)->assertSee($both->title)->assertDontSee($rent->title)->assertDontSee($presale->title);
        $this->get('/propiedades?operation_type=rent')->assertSee($rent->title)->assertSee($both->title)->assertDontSee($sale->title)->assertDontSee($presale->title);
        $this->get('/propiedades?operation_type=presale')->assertSee($presale->title)->assertDontSee($sale->title)->assertDontSee($rent->title)->assertDontSee($both->title);
        $this->get('/propiedades')->assertSee($sale->title)->assertSee($rent->title)->assertSee($both->title)->assertSee($presale->title);
    }

    public function test_admin_can_create_edit_and_delete_property(): void
    {
        $this->seed();
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->get(route('admin.properties.create'))
            ->assertOk()
            ->assertSee('Preventa')
            ->assertSee('Venta y renta')
            ->assertDontSee('Slug')
            ->assertDontSee('Latitud')
            ->assertDontSee('Longitud')
            ->assertDontSee('Fecha de publicación')
            ->assertDontSee('Mostrar ubicación exacta en el mapa')
            ->assertSee('x-show="rentOperations.includes(operation)"', false);

        $this->actingAs($admin)->get(route('admin.properties.index'))
            ->assertOk()
            ->assertSee('Preventa')
            ->assertSee('Venta y renta')
            ->assertSee('Despublicar')
            ->assertSee('Eliminar definitivamente');

        $payload = [
            'title' => 'Departamento prueba',
            'description' => 'Descripcion completa de prueba.',
            'operation_type' => 'presale',
            'property_type' => 'Departamento',
            'price' => '2,500,000.25',
            'neighborhood' => 'Del Valle',
            'city' => 'Ciudad de Mexico',
            'state' => 'CDMX',
            'status' => 'draft',
        ];

        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('properties', ['slug' => 'departamento-prueba', 'operation_type' => 'presale', 'currency' => 'MXN', 'price' => 2500000.25]);

        $property = Property::where('slug', 'departamento-prueba')->first();
        $this->actingAs($admin)->put(route('admin.properties.update', $property), array_merge($payload, ['title' => 'Departamento prueba editado', 'operation_type' => 'sale_rent', 'price' => 2500000.25, 'currency' => 'USD']))->assertRedirect();
        $this->assertDatabaseHas('properties', ['title' => 'Departamento prueba editado', 'slug' => 'departamento-prueba', 'operation_type' => 'sale_rent', 'currency' => 'USD']);

        $this->actingAs($admin)->delete(route('admin.properties.destroy', $property))->assertRedirect();
        $this->assertSoftDeleted('properties', ['id' => $property->id]);
    }

    public function test_property_currency_is_limited_to_mxn_or_usd(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = [
            'title' => 'Casa moneda invalida',
            'description' => 'Descripcion completa de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 1000000,
            'currency' => 'pesos',
            'neighborhood' => 'Centro',
            'city' => 'Ciudad de Mexico',
            'state' => 'CDMX',
            'status' => 'draft',
        ];

        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)
            ->assertSessionHasErrors('currency');

        $this->actingAs($admin)->post(route('admin.properties.store'), array_merge($payload, ['currency' => 'usd']))
            ->assertRedirect();

        $this->assertDatabaseHas('properties', ['slug' => 'casa-moneda-invalida', 'currency' => 'USD']);
    }

    public function test_public_price_format_includes_currency_and_rent_period(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'title' => 'Renta con dolares',
            'slug' => 'renta-con-dolares',
            'operation_type' => 'rent',
            'price' => 45000,
            'currency' => 'usd',
            'rent_period' => 'mes',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('properties.show', $property))
            ->assertOk()
            ->assertSee('$45,000 USD / mes');

        $this->get('/propiedades')
            ->assertOk()
            ->assertSee('$45,000 USD / mes');
    }

    public function test_property_slug_is_unique_and_preserved_when_title_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'title' => 'Casa en Cuernavaca',
            'description' => 'Descripcion completa de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 4500000,
            'currency' => 'MXN',
            'neighborhood' => 'Centro',
            'city' => 'Cuernavaca',
            'state' => 'Morelos',
            'status' => 'draft',
        ];

        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('admin.properties.store'), $payload)->assertRedirect();

        $this->assertDatabaseHas('properties', ['title' => 'Casa en Cuernavaca', 'slug' => 'casa-en-cuernavaca']);
        $this->assertDatabaseHas('properties', ['title' => 'Casa en Cuernavaca', 'slug' => 'casa-en-cuernavaca-2']);

        $property = Property::where('slug', 'casa-en-cuernavaca')->first();
        $this->actingAs($admin)->put(route('admin.properties.update', $property), array_merge($payload, ['title' => 'Casa renombrada']))->assertRedirect();

        $this->assertDatabaseHas('properties', ['id' => $property->id, 'title' => 'Casa renombrada', 'slug' => 'casa-en-cuernavaca']);
    }

    public function test_hidden_property_fields_do_not_clear_existing_coordinates_or_publication_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $publishedAt = now()->subDays(5);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'slug' => 'propiedad-con-coordenadas',
            'latitude' => 19.4326000,
            'longitude' => -99.1332000,
            'published_at' => $publishedAt,
            'status' => 'published',
        ]);

        $this->actingAs($admin)->put(route('admin.properties.update', $property), [
            'title' => 'Propiedad con coordenadas editada',
            'description' => $property->description,
            'operation_type' => 'rent',
            'property_type' => $property->property_type,
            'price' => $property->price,
            'currency' => $property->currency,
            'rent_period' => 'mes',
            'neighborhood' => $property->neighborhood,
            'city' => $property->city,
            'state' => $property->state,
            'status' => 'published',
        ])->assertRedirect();

        $property->refresh();
        $this->assertSame('19.4326000', $property->latitude);
        $this->assertSame('-99.1332000', $property->longitude);
        $this->assertSame($publishedAt->toDateTimeString(), $property->published_at->toDateTimeString());
    }

    public function test_property_detail_shows_address_without_map_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'slug' => 'propiedad-sin-mapa',
            'street' => 'Calle Falsa',
            'exterior_number' => '45',
            'neighborhood' => 'Colonia Falsa',
            'city' => 'Ciudad Falsa',
            'state' => 'Estado Falso',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('properties.show', $property))
            ->assertOk()
            ->assertSee('Calle Falsa 45, Colonia Falsa, Ciudad Falsa, Estado Falso')
            ->assertDontSee('Ubicación aproximada')
            ->assertDontSee('Mapa')
            ->assertDontSee('latitud pendiente')
            ->assertDontSee('longitud pendiente')
            ->assertDontSee('API key')
            ->assertDontSee('<iframe', false);
    }

    public function test_property_detail_gallery_uses_large_images_for_main_photo_and_thumb_for_thumbnails(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'slug' => 'propiedad-con-galeria',
            'status' => 'published',
            'published_at' => now(),
        ]);
        PropertyImage::create([
            'property_id' => $property->id,
            'path' => 'properties/1/fachada-large.webp',
            'card_path' => 'properties/1/fachada-card.webp',
            'thumb_path' => 'properties/1/fachada-thumb.webp',
            'alt_text' => 'Fachada principal',
            'position' => 1,
            'is_cover' => true,
        ]);
        PropertyImage::create([
            'property_id' => $property->id,
            'path' => 'properties/1/sala-large.webp',
            'card_path' => 'properties/1/sala-card.webp',
            'thumb_path' => 'properties/1/sala-thumb.webp',
            'alt_text' => 'Sala',
            'position' => 2,
            'is_cover' => false,
        ]);

        $response = $this->get(route('properties.show', $property))
            ->assertOk()
            ->assertSee('src="/storage/properties/1/fachada-large.webp"', false)
            ->assertSee('fachada-large.webp')
            ->assertSee('sala-large.webp')
            ->assertSee('src="/storage/properties/1/fachada-thumb.webp"', false)
            ->assertSee('src="/storage/properties/1/sala-thumb.webp"', false)
            ->assertDontSee('fachada-card.webp')
            ->assertDontSee('sala-card.webp');

        $this->assertSame(3, substr_count($response->getContent(), 'fachada-large.webp'));
        $this->assertSame(1, substr_count($response->getContent(), 'sala-large.webp'));
    }

    public function test_publishing_assigns_publication_date_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create([
            'created_by' => $admin->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($admin)->patch(route('admin.properties.toggle-published', $property))->assertRedirect();
        $firstPublishedAt = $property->refresh()->published_at;
        $this->assertNotNull($firstPublishedAt);

        $this->actingAs($admin)->patch(route('admin.properties.toggle-published', $property))->assertRedirect();
        $this->assertSame($firstPublishedAt->toDateTimeString(), $property->refresh()->published_at->toDateTimeString());

        $this->actingAs($admin)->patch(route('admin.properties.toggle-published', $property))->assertRedirect();
        $this->assertSame($firstPublishedAt->toDateTimeString(), $property->refresh()->published_at->toDateTimeString());
    }

    public function test_admin_can_create_amenity_without_slug(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Amenity::create(['name' => 'Area verde', 'slug' => 'area-verde']);

        $this->actingAs($admin)->get(route('admin.amenities.create'))
            ->assertOk()
            ->assertDontSee('Slug');

        $this->actingAs($admin)->post(route('admin.amenities.store'), [
            'name' => 'Área verde',
        ])->assertRedirect();

        $this->assertDatabaseHas('amenities', ['name' => 'Área verde', 'slug' => 'area-verde-2']);
    }

    public function test_user_roles_are_displayed_in_spanish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Usuario Editor', 'role' => 'editor']);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Administrador')
            ->assertSee('Editor');

        $this->actingAs($admin)->get(route('admin.users.edit', $admin))
            ->assertOk()
            ->assertSee('Administrador')
            ->assertSee('Déjala vacía para conservar la contraseña actual.');
    }

    public function test_contact_request_status_select_uses_current_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $request = ContactRequest::create([
            'name' => 'Cliente Demo',
            'phone' => '5512345678',
            'message' => 'Quiero informacion.',
            'status' => 'contacted',
        ]);

        $this->actingAs($admin)->get(route('admin.contact-requests.show', $request))
            ->assertOk()
            ->assertSee('<option value="contacted" selected>Contactada</option>', false);
    }

    public function test_admin_uploads_are_optimized_to_webp_versions(): void
    {
        Storage::fake('public');
        $this->seed();
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->post(route('admin.properties.store'), [
            'title' => 'Casa con imagen optimizada',
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
