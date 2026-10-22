<?php

namespace Tests\Feature\Api\V1;

use App\Models\Amenity;
use App\Models\Property;
use App\Models\PropertyChangeOutbox;
use App\Models\PropertyRevision;
use App\Models\PropertyType;
use App\Services\PropertyChangeCursor;
use App\Services\PropertyChangePublisher;
use App\Services\PropertyRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PropertyChangesTest extends TestCase
{
    use RefreshDatabase;

    private string $keyDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.test',
            'filesystems.disks.public.url' => 'https://api.test/storage',
        ]);

        $this->keyDirectory = sys_get_temp_dir().'/passport-'.Str::uuid();
        mkdir($this->keyDirectory, 0700, true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        file_put_contents($this->keyDirectory.'/oauth-private.key', $privateKey);
        file_put_contents($this->keyDirectory.'/oauth-public.key', $details['key']);
        chmod($this->keyDirectory.'/oauth-private.key', 0600);
        chmod($this->keyDirectory.'/oauth-public.key', 0600);
        Passport::loadKeysFrom($this->keyDirectory);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyDirectory.'/oauth-private.key');
        @unlink($this->keyDirectory.'/oauth-public.key');
        @rmdir($this->keyDirectory);

        parent::tearDown();
    }

    public function test_public_mutation_creates_sequential_revision_and_pending_outbox(): void
    {
        $property = app(PropertyRevisionService::class)->create(fn () => Property::factory()->create([
            'status' => 'published', 'published_at' => now(), 'price' => '1000000.00',
        ]));

        $this->assertSame(1, PropertyRevision::where('property_id', $property->id)->value('revision_number'));
        $this->assertSame('pending', PropertyChangeOutbox::where('property_id', $property->id)->value('status'));
        $this->assertSame(1, PropertyChangeOutbox::where('property_id', $property->id)->value('feed_sequence'));

        app(PropertyRevisionService::class)->mutate($property, function (Property $locked): void {
            $locked->update(['price' => '1100000.00']);
        });

        $this->assertSame([1, 2], PropertyRevision::where('property_id', $property->id)->orderBy('revision_number')->pluck('revision_number')->all());
        $this->assertSame('property.updated', PropertyRevision::where('property_id', $property->id)->latest('revision_number')->value('event_type'));
    }

    public function test_rollback_removes_business_revision_and_outbox_changes(): void
    {
        try {
            app(PropertyRevisionService::class)->create(function () {
                Property::factory()->create(['status' => 'published', 'published_at' => now()]);
                throw new \RuntimeException('rollback test');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback test', $exception->getMessage());
        }

        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('property_revisions', 0);
        $this->assertDatabaseCount('property_change_outbox', 0);
    }

    public function test_retirement_event_contains_only_public_removal_fields(): void
    {
        $property = app(PropertyRevisionService::class)->create(fn () => Property::factory()->create([
            'status' => 'published', 'published_at' => now(), 'title' => 'No private payload',
        ]));

        app(PropertyRevisionService::class)->mutate($property, function (Property $locked): void {
            $locked->update(['status' => 'archived']);
        });

        $event = PropertyChangeOutbox::where('property_id', $property->id)->latest('revision_number')->firstOrFail();
        $this->assertSame('property.unpublished', $event->event_type);
        $this->assertEqualsCanonicalizing(
            ['external_id', 'revision', 'event_type', 'reason', 'timestamp'],
            array_keys($event->payload),
        );
        $this->assertSame($property->id, $event->payload['external_id']);
        $this->assertSame(2, $event->payload['revision']);
        $this->assertSame('property.unpublished', $event->payload['event_type']);
        $this->assertSame('archived', $event->payload['reason']);
        $this->assertIsString($event->payload['timestamp']);
    }

    public function test_never_published_property_does_not_emit_private_events(): void
    {
        $property = app(PropertyRevisionService::class)->create(fn () => Property::factory()->create([
            'status' => 'draft', 'published_at' => null, 'title' => 'Draft only',
        ]));

        app(PropertyRevisionService::class)->mutate($property, function (Property $locked): void {
            $locked->update(['title' => 'Still private']);
        });

        $this->assertDatabaseCount('property_revisions', 0);
        $this->assertDatabaseCount('property_change_outbox', 0);
    }

    public function test_publisher_exposes_only_a_contiguous_confirmed_prefix(): void
    {
        app(PropertyRevisionService::class)->create(fn () => Property::factory()->create(['status' => 'published', 'published_at' => now()]));
        app(PropertyRevisionService::class)->create(fn () => Property::factory()->create(['status' => 'published', 'published_at' => now()]));

        $this->assertSame(1, app(PropertyChangePublisher::class)->publishAvailable(1));
        $token = $this->issueToken();

        $this->withToken($token)->getJson('/api/v1/property-changes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.safe_sequence', 1)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_change_feed_cursor_is_encrypted_and_expiration_is_explicit(): void
    {
        app(PropertyRevisionService::class)->create(fn () => Property::factory()->create(['status' => 'published', 'published_at' => now()]));
        app(PropertyChangePublisher::class)->publishAvailable();
        $token = $this->issueToken();
        $cursor = app(PropertyChangeCursor::class)->encode(0);

        $this->withToken($token)->getJson('/api/v1/property-changes?after='.rawurlencode($cursor))
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/property-changes?after='.rawurlencode($cursor.'tampered'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'cursor_invalid');

        $expired = Crypt::encryptString(json_encode([
            'version' => 1, 'after' => 0, 'expires_at' => now()->subMinute()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        $this->withToken($token)->getJson('/api/v1/property-changes?after='.rawurlencode($expired))
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'cursor_expired');
    }

    public function test_relationship_changes_emit_specific_events(): void
    {
        $type = PropertyType::query()->firstOrFail();
        $amenity = Amenity::create(['name' => 'Alberca', 'slug' => 'alberca']);
        $property = app(PropertyRevisionService::class)->create(fn () => Property::factory()->create([
            'status' => 'published', 'published_at' => now(), 'property_type' => $type->name, 'property_type_id' => $type->id,
        ]));

        app(PropertyRevisionService::class)->mutate($property, function (Property $locked) use ($amenity): void {
            $locked->amenities()->sync([$amenity->id]);
        });
        app(PropertyRevisionService::class)->updateAmenity($amenity, ['name' => 'Alberca climatizada', 'slug' => 'alberca-climatizada']);
        app(PropertyRevisionService::class)->updatePropertyType($type, ['name' => 'Casa Premium Test', 'slug' => 'casa-premium-test', 'is_active' => true]);

        $this->assertDatabaseHas('property_change_outbox', ['property_id' => $property->id, 'event_type' => 'property.amenities_changed']);
        $this->assertDatabaseHas('property_change_outbox', ['property_id' => $property->id, 'event_type' => 'property.type_changed']);
    }

    private function issueToken(): string
    {
        [$client, $secret] = $this->clientCredentialsClient();

        return $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'scope' => 'properties:read',
        ])->assertOk()->json('access_token');
    }

    /** @return array{0: Client, 1: string} */
    private function clientCredentialsClient(): array
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Local SDG changes test client');

        return [$client, $client->plainSecret];
    }
}
