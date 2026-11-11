<?php

namespace Database\Seeders;

use App\Enums\OrganisationType;
use App\Models\Organisation;
use Illuminate\Database\Seeder;

/**
 * Seeds the Ministry itself. Vendors are created as data by administrators,
 * never seeded or hard-coded.
 */
class OrganisationSeeder extends Seeder
{
    public function run(): void
    {
        Organisation::firstOrCreate(
            ['name' => 'Ministry of Health'],
            ['type' => OrganisationType::Moh, 'short_name' => 'MOH', 'active' => true],
        );
    }
}
