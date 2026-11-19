<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Reference data: every environment.
            RolesAndPermissionsSeeder::class,
            ProcurementTypeSeeder::class,
            OrganisationSeeder::class,
            QueryRouteSeeder::class, // OVERNIGHT-2 (D1): idempotent
        ]);

        // Example user (factory password "password"): local only, never in
        // staging or production. Real accounts come from app:user-create.
        if (app()->isLocal()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
