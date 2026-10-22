<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use RuntimeException;

class InitializeSdgOAuthClient extends Command
{
    protected $signature = 'passport:initialize-sdg-client';

    protected $description = 'Initializes the stable SDG Client Credentials client when configured.';

    private const CLIENT_NAME = 'SDG Client Credentials';

    private const REQUIRED_SCOPE = 'properties:read';

    public function handle(): int
    {
        $clientId = config('integration.sdg_oauth.client_id');
        $clientSecret = config('integration.sdg_oauth.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            $this->line('SDG OAuth client initialization skipped: credentials are not configured.');

            return self::SUCCESS;
        }

        if (! Str::isUuid($clientId)) {
            $this->error('SDG OAuth client initialization failed: SDG_OAUTH_CLIENT_ID must be a UUID.');

            return self::FAILURE;
        }

        if (strlen($clientSecret) < 32) {
            $this->error('SDG OAuth client initialization failed: SDG_OAUTH_CLIENT_SECRET is too short.');

            return self::FAILURE;
        }

        if (! Passport::hasScope(self::REQUIRED_SCOPE)) {
            $this->error('SDG OAuth client initialization failed: the required API scope is not configured.');

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($clientId, $clientSecret): int {
                $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();

                if ($client === null) {
                    $sameName = Client::query()
                        ->where('name', self::CLIENT_NAME)
                        ->exists();

                    if ($sameName) {
                        throw new RuntimeException('An SDG OAuth client with a different ID already exists.');
                    }

                    $client = new Client();
                    $client->forceFill([
                        'id' => $clientId,
                        'name' => self::CLIENT_NAME,
                        'secret' => $clientSecret,
                        'provider' => null,
                        'redirect_uris' => [],
                        'grant_types' => ['client_credentials'],
                        'revoked' => false,
                    ]);
                    $client->save();

                    $this->info('SDG OAuth Client Credentials client initialized.');

                    return self::SUCCESS;
                }

                $valid = $client->name === self::CLIENT_NAME
                    && ! $client->revoked
                    && $client->hasGrantType('client_credentials')
                    && $client->confidential()
                    && Hash::check($clientSecret, (string) $client->secret);

                if (! $valid) {
                    throw new RuntimeException('The existing SDG OAuth client configuration is invalid.');
                }

                $this->line('SDG OAuth Client Credentials client already initialized.');

                return self::SUCCESS;
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
