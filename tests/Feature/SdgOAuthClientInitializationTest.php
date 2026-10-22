<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class SdgOAuthClientInitializationTest extends TestCase
{
    use RefreshDatabase;

    private string $clientId = '11111111-1111-4111-8111-111111111111';

    private string $clientSecret = 'sdg-test-secret-0123456789-abcdefghijklmnopqrstuvwxyz';

    private string $keyDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integration.sdg_oauth.client_id' => $this->clientId,
            'integration.sdg_oauth.client_secret' => $this->clientSecret,
            'app.url' => 'https://api.test',
            'filesystems.disks.public.url' => 'https://api.test/storage',
        ]);

        $this->keyDirectory = sys_get_temp_dir().'/passport-sdg-'.Str::uuid();
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

    public function test_first_initialization_creates_a_hashed_client_with_the_fixed_uuid(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);

        $client = Client::query()->findOrFail($this->clientId);

        $this->assertSame(['client_credentials'], $client->grant_types);
        $this->assertFalse($client->revoked);
        $this->assertTrue(Hash::check($this->clientSecret, $client->secret));
        $this->assertNotSame($this->clientSecret, $client->secret);
    }

    public function test_second_initialization_is_idempotent(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);

        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function test_reinitialization_recreates_the_same_client_after_database_rebuild(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);
        Client::query()->delete();

        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);

        $this->assertDatabaseHas('oauth_clients', ['id' => $this->clientId]);
    }

    public function test_missing_credentials_skip_without_creating_a_client(): void
    {
        config([
            'integration.sdg_oauth.client_id' => null,
            'integration.sdg_oauth.client_secret' => null,
        ]);

        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_existing_client_with_a_different_secret_fails_without_rotating_it(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);
        $storedSecret = Client::query()->findOrFail($this->clientId)->secret;

        config(['integration.sdg_oauth.client_secret' => 'a-different-secret-that-is-long-enough-0123456789']);

        $this->artisan('passport:initialize-sdg-client')->assertExitCode(1);

        $this->assertTrue(Hash::check($this->clientSecret, $storedSecret));
        $this->assertDatabaseCount('oauth_clients', 1);
    }

    public function test_initialized_client_can_issue_a_scoped_token_and_read_the_api(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);
        Property::factory()->create(['status' => 'published', 'published_at' => now()]);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'properties:read',
        ])->assertOk()->json('access_token');

        $this->withToken($token)->getJson('/api/v1/properties')->assertOk();
    }

    public function test_initialized_client_without_the_required_scope_is_denied_by_the_api(): void
    {
        $this->artisan('passport:initialize-sdg-client')->assertExitCode(0);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => '',
        ])->assertOk()->json('access_token');

        $this->withToken($token)->getJson('/api/v1/properties')->assertForbidden();
    }
}
