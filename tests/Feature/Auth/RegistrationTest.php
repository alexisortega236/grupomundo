<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_publicly_available(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_public_registration_cannot_create_users(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_docker_entrypoint_does_not_enable_demo_seeders_by_default(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('${RUN_SEEDERS:-false}', $entrypoint);
        $this->assertStringContainsString('APP_KEY must be configured', $entrypoint);
    }
}
