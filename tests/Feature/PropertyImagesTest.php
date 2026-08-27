<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Services\PropertyImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class PropertyImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_property_without_images(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.properties.store'), $this->propertyPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('property_images', 0);
    }

    public function test_admin_can_create_property_with_one_image_and_preserve_original(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.properties.store'), $this->propertyPayload([
            'images' => [UploadedFile::fake()->image('fachada.jpg', 2400, 1600)],
        ]))->assertRedirect();

        $image = Property::latest()->first()->images()->first();
        $this->assertNotNull($image);
        $this->assertTrue($image->is_cover);
        $this->assertNotNull($image->original_path);
        Storage::disk('public')->assertExists($image->original_path);
        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertExists($image->card_path);
        Storage::disk('public')->assertExists($image->thumb_path);
    }

    public function test_admin_can_create_exactly_ten_images_and_first_is_cover(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $images = collect(range(1, 10))->map(fn ($index) => UploadedFile::fake()->image("imagen-{$index}.jpg"))->all();

        $this->actingAs($admin)->post(route('admin.properties.store'), $this->propertyPayload([
            'images' => $images,
        ]))->assertRedirect();

        $property = Property::latest()->first();
        $this->assertCount(10, $property->images);
        $this->assertSame(1, $property->images()->where('is_cover', true)->count());
        $this->assertSame($property->images()->orderBy('position')->first()->id, $property->images()->where('is_cover', true)->first()->id);
    }

    public function test_eleven_images_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $images = collect(range(1, 11))->map(fn ($index) => UploadedFile::fake()->image("imagen-{$index}.jpg"))->all();

        $this->actingAs($admin)->post(route('admin.properties.store'), $this->propertyPayload([
            'images' => $images,
        ]))->assertSessionHasErrors('images');

        $this->assertDatabaseCount('properties', 0);
    }

    public function test_update_allows_seven_existing_plus_three_new_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create(['created_by' => $admin->id]);
        app(PropertyImageService::class)->sync($property, ['images' => $this->images(7)]);

        $this->actingAs($admin)->put(route('admin.properties.update', $property), $this->propertyPayload([
            'images' => $this->images(3),
        ]))->assertRedirect();

        $this->assertCount(10, $property->fresh()->images);
    }

    public function test_update_rejects_seven_existing_plus_four_new_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create(['created_by' => $admin->id]);
        app(PropertyImageService::class)->sync($property, ['images' => $this->images(7)]);

        $this->actingAs($admin)->put(route('admin.properties.update', $property), $this->propertyPayload([
            'images' => $this->images(4),
        ]))->assertSessionHasErrors('images');

        $this->assertCount(7, $property->fresh()->images);
    }

    public function test_generated_dimensions_are_independent_and_do_not_upscale_small_images(): void
    {
        Storage::fake('public');
        $property = Property::factory()->create();
        $service = app(PropertyImageService::class);

        $largeSource = UploadedFile::fake()->image('fachada.jpg', 3000, 2000);
        $service->sync($property, ['images' => [$largeSource]]);
        $largeImage = $property->fresh()->images()->first();
        $manager = new ImageManager(new Driver());

        $this->assertSame([3000, 2000], [$manager->decodePath(Storage::disk('public')->path($largeImage->original_path))->width(), $manager->decodePath(Storage::disk('public')->path($largeImage->original_path))->height()]);
        $this->assertSame([2400, 1600], [$manager->decodePath(Storage::disk('public')->path($largeImage->path))->width(), $manager->decodePath(Storage::disk('public')->path($largeImage->path))->height()]);
        $this->assertSame([1000, 667], [$manager->decodePath(Storage::disk('public')->path($largeImage->card_path))->width(), $manager->decodePath(Storage::disk('public')->path($largeImage->card_path))->height()]);
        $this->assertSame([400, 267], [$manager->decodePath(Storage::disk('public')->path($largeImage->thumb_path))->width(), $manager->decodePath(Storage::disk('public')->path($largeImage->thumb_path))->height()]);

        $smallProperty = Property::factory()->create();
        $service->sync($smallProperty, ['images' => [UploadedFile::fake()->image('small.jpg', 800, 600)]]);
        $smallImage = $smallProperty->fresh()->images()->first();

        $this->assertSame([800, 600], [$manager->decodePath(Storage::disk('public')->path($smallImage->path))->width(), $manager->decodePath(Storage::disk('public')->path($smallImage->path))->height()]);
    }

    public function test_update_allows_deleting_one_owned_image_before_adding_four(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $property = Property::factory()->create(['created_by' => $admin->id]);
        app(PropertyImageService::class)->sync($property, ['images' => $this->images(7)]);
        $deletedImage = $property->fresh()->images()->first();

        $this->actingAs($admin)->put(route('admin.properties.update', $property), $this->propertyPayload([
            'images' => $this->images(4),
            'delete_images' => [$deletedImage->id],
        ]))->assertRedirect();

        $this->assertCount(10, $property->fresh()->images);
        $this->assertDatabaseMissing('property_images', ['id' => $deletedImage->id]);
    }

    private function images(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn ($index) => UploadedFile::fake()->image("nueva-{$index}-".uniqid().'.jpg'))
            ->all();
    }

    private function propertyPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Casa con galería',
            'description' => 'Descripción comercial de prueba.',
            'operation_type' => 'sale',
            'property_type' => 'Casa',
            'price' => 2500000,
            'currency' => 'MXN',
            'neighborhood' => 'Del Valle',
            'city' => 'Ciudad de México',
            'state' => 'CDMX',
            'status' => 'draft',
        ], $overrides);
    }
}
