<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\OrganisationType;
use App\Models\Organisation;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an active VENDOR organisation (vendors are data). The audit row
 * organisation.created carries only the new id and type: no name text.
 */
class CreateOrganisation extends Command
{
    protected $signature = 'app:organisation-create
        {name : Registered name (unique)}
        {--short-name= : Optional short name, up to 50 characters}';

    protected $description = 'Create an active vendor organisation';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $shortName = $this->option('short-name') !== null ? trim((string) $this->option('short-name')) : null;

        $validator = Validator::make(['name' => $name, 'short_name' => $shortName], [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (Organisation::where('name', $name)->exists()) {
            $this->error('An organisation with that name already exists. Nothing was changed.');

            return self::FAILURE;
        }

        $organisation = DB::transaction(function () use ($name, $shortName) {
            $organisation = Organisation::create([
                'name' => $name,
                'short_name' => $shortName !== '' ? $shortName : null,
                'type' => OrganisationType::Vendor,
                'active' => true,
            ]);

            Audit::record(
                AuditAction::OrganisationCreated,
                ['type' => 'organisation', 'id' => $organisation->id],
                null,
                ['organisation_id' => $organisation->id, 'type' => OrganisationType::Vendor->value],
                Audit::ACTOR_SYSTEM,
            );

            return $organisation;
        });

        $this->info("Created vendor organisation {$organisation->id}.");

        return self::SUCCESS;
    }
}
