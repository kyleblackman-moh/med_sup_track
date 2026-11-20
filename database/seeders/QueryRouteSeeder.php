<?php

namespace Database\Seeders;

use App\Enums\QueryType;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OVERNIGHT-2 (D1): the standing query routes, as data. Idempotent: each row
 * is matched on (from_role, to_role) and updated in place, so running it again
 * changes nothing; a row an administrator switched off stays off.
 *
 * A reply travels by the reverse of the route its query came by; on a via-CMO
 * route the CMO forwards each message both ways. Role names here are data
 * (the query_routes table is read by role), never permission checks.
 */
class QueryRouteSeeder extends Seeder
{
    /** @return list<array{from: Role, to: Role, via_cmo: bool, type: QueryType, description: string}> */
    public static function routes(): array
    {
        return [
            ['from' => Role::Technocrat, 'to' => Role::VendorApplicant, 'via_cmo' => false, 'type' => QueryType::TECHNICAL,
                'description' => 'Technocrat to the vendor, direct. The CMO is told and can read it.'],
            ['from' => Role::LegalOfficer, 'to' => Role::Technocrat, 'via_cmo' => true, 'type' => QueryType::LEGAL,
                'description' => 'Legal to the Technocrats, via the CMO.'],
            ['from' => Role::PsOffice, 'to' => Role::Technocrat, 'via_cmo' => true, 'type' => QueryType::PS_TECHNICAL,
                'description' => 'PS to the Technocrats (technical), via the CMO.'],
            ['from' => Role::PsOffice, 'to' => Role::LegalOfficer, 'via_cmo' => true, 'type' => QueryType::PS_LEGAL,
                'description' => 'PS to Legal (legal-related), via the CMO.'],
            ['from' => Role::Technocrat, 'to' => Role::Cmo, 'via_cmo' => false, 'type' => QueryType::TECHNICAL,
                'description' => 'Technocrat to the CMO, resolved internally.'],
            ['from' => Role::Cmo, 'to' => Role::LegalOfficer, 'via_cmo' => false, 'type' => QueryType::LEGAL,
                'description' => 'The CMO forwards a query to Legal.'],
            ['from' => Role::Cmo, 'to' => Role::Technocrat, 'via_cmo' => false, 'type' => QueryType::TECHNICAL,
                'description' => 'Rejections go to the CMO team, which sends them on to the Technocrats.'],
        ];
    }

    public function run(): void
    {
        $now = CarbonImmutable::now('UTC');

        foreach (self::routes() as $route) {
            $key = ['from_role' => $route['from']->value, 'to_role' => $route['to']->value];
            $values = ['via_cmo' => $route['via_cmo'], 'query_type' => $route['type']->value, 'description' => $route['description'], 'updated_at' => $now];

            if (DB::table('query_routes')->where($key)->exists()) {
                // "active" is left as it is, so a route switched off stays off.
                DB::table('query_routes')->where($key)->update($values);
            } else {
                DB::table('query_routes')->insert([...$key, ...$values, 'active' => true, 'created_at' => $now]);
            }
        }
    }
}
