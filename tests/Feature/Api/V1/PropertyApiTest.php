<?php

namespace Tests\Feature\Api\V1;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\TestCase;

class PropertyApiTest extends TestCase
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
        Storage::forgetDisk('public');

        $this->keyDirectory = sys_get_temp_dir().'/passport-'.Str::uuid();
        mkdir($this->keyDirectory, 0700, true);

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
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

    public function test_client_credentials_token_can_read_published_commercial_property(): void
    {
        $property = Property::factory()->create([
            'title' => 'API visible',
            'origin' => Property::ORIGIN_COMMERCIAL,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $this->attachImage($property);
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', $property->id)
            ->assertJsonPath('data.0.title', 'API visible')
            ->assertJsonPath('data.0.images.0.url', 'https://api.test/storage/properties/api-card.webp');
    }

    public function test_missing_invalid_and_insufficient_tokens_are_rejected(): void
    {
        $this->getJson('/api/v1/properties')->assertUnauthorized();
        $this->withToken('not-a-valid-token')->getJson('/api/v1/properties')->assertUnauthorized();

        $token = $this->issueToken([]);
        $this->withToken($token)->getJson('/api/v1/properties')->assertForbidden();
    }

    public function test_revoked_client_cannot_issue_another_token(): void
    {
        [$client, $secret] = $this->clientCredentialsClient();
        $client->update(['revoked' => true]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'scope' => 'properties:read',
        ])->assertUnauthorized();
    }

    public function test_revoked_client_loses_access_with_a_previously_issued_token(): void
    {
        Property::factory()->create(['status' => 'published', 'published_at' => now()]);
        [$client, $token] = $this->issueTokenForClient(['properties:read']);

        $client->update(['revoked' => true]);

        $this->withToken($token)->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_individually_revoked_token_is_rejected(): void
    {
        Property::factory()->create(['status' => 'published', 'published_at' => now()]);
        [$client, $token] = $this->issueTokenForClient(['properties:read']);
        $accessToken = Token::query()->where('client_id', $client->getKey())->latest('created_at')->first();

        $this->assertNotNull($accessToken);
        $accessToken->revoke();

        $this->withToken($token)->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        Property::factory()->create(['status' => 'published', 'published_at' => now()]);
        Passport::clientCredentialsTokensExpireIn(now()->subMinute());

        try {
            $token = $this->issueToken(['properties:read']);
            $this->withToken($token)->getJson('/api/v1/properties')->assertUnauthorized();
        } finally {
            Passport::clientCredentialsTokensExpireIn(now()->addMinutes(15));
        }
    }

    public function test_only_published_commercial_properties_are_visible(): void
    {
        $visible = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL, 'status' => 'published', 'published_at' => now()]);
        $draft = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL, 'status' => 'draft', 'published_at' => null]);
        $archived = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL, 'status' => 'archived', 'published_at' => now()]);
        $valuation = Property::factory()->create(['origin' => Property::ORIGIN_VALUATION, 'status' => 'published', 'published_at' => now()]);
        $deleted = Property::factory()->create(['origin' => Property::ORIGIN_COMMERCIAL, 'status' => 'published', 'published_at' => now()]);
        $deleted->delete();
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', $visible->id)
            ->assertJsonMissing(['external_id' => $draft->id])
            ->assertJsonMissing(['external_id' => $archived->id])
            ->assertJsonMissing(['external_id' => $valuation->id])
            ->assertJsonMissing(['external_id' => $deleted->id]);
    }

    public function test_non_published_detail_returns_generic_not_found(): void
    {
        $property = Property::factory()->create(['status' => 'draft', 'published_at' => null]);
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties/'.$property->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonMissing(['message' => $property->title]);
    }

    public function test_location_is_redacted_when_exact_location_is_disabled(): void
    {
        $property = Property::factory()->create([
            'status' => 'published', 'published_at' => now(), 'show_exact_location' => false,
            'street' => 'Calle privada', 'exterior_number' => '123', 'interior_number' => '4B',
            'latitude' => '19.4326000', 'longitude' => '-99.1332000',
        ]);
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties/'.$property->id)
            ->assertOk()
            ->assertJsonPath('data.location.show_exact_location', false)
            ->assertJsonPath('data.location.street', null)
            ->assertJsonPath('data.location.exterior_number', null)
            ->assertJsonPath('data.location.interior_number', null)
            ->assertJsonPath('data.location.latitude', null)
            ->assertJsonPath('data.location.longitude', null)
            ->assertJsonPath('data.location.neighborhood', $property->neighborhood);
    }

    public function test_location_is_returned_when_exact_location_is_enabled(): void
    {
        $property = Property::factory()->create([
            'status' => 'published', 'published_at' => now(), 'show_exact_location' => true,
            'street' => 'Calle pública', 'exterior_number' => '456', 'interior_number' => '2A',
            'latitude' => '19.4326000', 'longitude' => '-99.1332000',
        ]);
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.0.location.show_exact_location', true)
            ->assertJsonPath('data.0.location.street', 'Calle pública')
            ->assertJsonPath('data.0.location.latitude', '19.4326000')
            ->assertJsonPath('data.0.location.longitude', '-99.1332000');
    }

    public function test_cursor_pagination_is_id_ordered_and_bounded(): void
    {
        $properties = Property::factory()->count(3)->create(['status' => 'published', 'published_at' => now()])->sortBy('id')->values();
        $token = $this->issueToken(['properties:read']);

        $first = $this->withToken($token)->getJson('/api/v1/properties?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination_consistency', 'live_catalog');

        $this->assertSame($properties[0]->id, $first->json('data.0.external_id'));
        $this->assertSame($properties[1]->id, $first->json('data.1.external_id'));
        $next = $first->json('links.next');
        $this->assertNotEmpty($next);

        $second = $this->withToken($token)->getJson(parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($properties[2]->id, $second->json('data.0.external_id'));
    }

    public function test_query_parameters_are_strictly_validated(): void
    {
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_invalid_cursors_return_unprocessable_entity(): void
    {
        $token = $this->issueToken(['properties:read']);
        $manipulated = rtrim(strtr(base64_encode(json_encode([
            'id' => ['unexpected' => true], '_pointsToNextItems' => true,
        ])), '+/', '-_'), '=');

        $this->withToken($token)->getJson('/api/v1/properties?cursor=not-a-cursor')
            ->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
        $this->withToken($token)->getJson('/api/v1/properties?cursor=')
            ->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
        $this->withToken($token)->getJson('/api/v1/properties?cursor='.$manipulated)
            ->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
    }

    public function test_invalid_images_are_omitted_without_failing_the_property_response(): void
    {
        $property = Property::factory()->create(['status' => 'published', 'published_at' => now()]);
        $property->images()->create(['path' => '', 'card_path' => null, 'position' => 1, 'is_cover' => true]);
        $property->images()->create([
            'path' => 'properties/valid.webp', 'card_path' => 'properties/valid-card.webp',
            'position' => 2, 'is_cover' => false,
        ]);
        $token = $this->issueToken(['properties:read']);

        $this->withToken($token)->getJson('/api/v1/properties/'.$property->id)
            ->assertOk()->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.images.0.url', 'https://api.test/storage/properties/valid-card.webp')
            ->assertJsonPath('data.images.0.is_cover', false);
    }

    public function test_rate_limit_is_applied_by_oauth_client(): void
    {
        $token = $this->issueToken(['properties:read']);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->withToken($token)->getJson('/api/v1/properties')->assertOk();
        }

        $this->withToken($token)->getJson('/api/v1/properties')->assertTooManyRequests();
    }

    private function issueToken(array $scopes): string
    {
        [$client, $secret] = $this->clientCredentialsClient();

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'scope' => implode(' ', $scopes),
        ])->assertOk();

        return $response->json('access_token');
    }

    /** @return array{0: Client, 1: string} */
    private function issueTokenForClient(array $scopes): array
    {
        [$client, $secret] = $this->clientCredentialsClient();

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'scope' => implode(' ', $scopes),
        ])->assertOk()->json('access_token');

        return [$client, $token];
    }

    /** @return array{0: Client, 1: string} */
    private function clientCredentialsClient(): array
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Local SDG test client');

        return [$client, $client->plainSecret];
    }

    private function attachImage(Property $property): void
    {
        $property->images()->create([
            'path' => 'properties/api-large.webp',
            'card_path' => 'properties/api-card.webp',
            'thumb_path' => 'properties/api-thumb.webp',
            'original_path' => 'properties/api-original.jpg',
            'position' => 1,
            'is_cover' => true,
            'alt_text' => 'Imagen API',
            'width' => 1000,
            'height' => 750,
        ]);
    }
}
