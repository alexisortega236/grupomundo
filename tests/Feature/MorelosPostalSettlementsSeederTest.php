<?php

namespace Tests\Feature;

use App\Models\PostalSettlement;
use Database\Seeders\MorelosPostalSettlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MorelosPostalSettlementsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_loads_the_morelos_snapshot(): void
    {
        $this->seed(MorelosPostalSettlementsSeeder::class);

        $this->assertSame(1877, PostalSettlement::count());
        $this->assertTrue(
            PostalSettlement::where('state', 'Morelos')
                ->where('municipality', 'Cuautla')
                ->exists()
        );

        $settlement = PostalSettlement::where('settlement', 'Año de Juárez')
            ->where('postal_code', '62748')
            ->first();

        $this->assertNotNull($settlement);
        $this->assertSame('Morelos', $settlement->state);
        $this->assertSame('Cuautla', $settlement->municipality);
        $this->assertSame('sepomex', $settlement->source);
    }

    public function test_seeder_is_idempotent_and_does_not_create_duplicates(): void
    {
        $this->seed(MorelosPostalSettlementsSeeder::class);
        $this->seed(MorelosPostalSettlementsSeeder::class);

        $this->assertSame(1877, PostalSettlement::count());
        $this->assertSame(
            1877,
            PostalSettlement::query()
                ->select(['source', 'state_code', 'municipality_code', 'settlement', 'postal_code'])
                ->groupBy('source', 'state_code', 'municipality_code', 'settlement', 'postal_code')
                ->get()
                ->count()
        );
    }
}
