<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Fhir\ReferralBundleFactory;
use App\Models\Destination;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Translate an intake JSON file into a submission Bundle, without sending it.
 *
 * This is how the golden fixtures are regenerated, and the quickest way to see what a change
 * to a builder actually does to the wire format:
 *
 *   php artisan fhir:build-referral tests/fixtures/intake/referral-kalibo.json \
 *       --out=tests/fixtures/fhir/generated-referral-bundle.json
 *   php artisan fhir:validate tests/fixtures/fhir/generated-referral-bundle.json
 */
class FhirBuildReferral extends Command
{
    protected $signature = 'fhir:build-referral
        {file : Intake JSON file}
        {--out= : Write the Bundle here instead of stdout}';

    protected $description = 'Translate an intake JSON payload into a PH eReferral submission Bundle';

    public function handle(ReferralBundleFactory $factory): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("Not found: {$path}");

            return self::FAILURE;
        }

        $intake = json_decode((string) file_get_contents($path), true);

        if (! is_array($intake)) {
            $this->error("Not valid JSON: {$path}");

            return self::FAILURE;
        }

        $destination = Destination::query()
            ->where('hcpn_id', $intake['destination']['hcpn_id'] ?? '')
            ->first();

        if ($destination === null) {
            $this->error("Unknown destination [{$intake['destination']['hcpn_id']}]. Seed it first: php artisan db:seed --class=DestinationSeeder");

            return self::FAILURE;
        }

        // Same shape check the HTTP endpoint applies, minus the request-scoped rules, so a
        // malformed fixture fails here rather than producing a subtly wrong Bundle.
        $validator = Validator::make($intake, [
            'message_id' => ['required', 'uuid'],
            'sent_at' => ['required', 'string'],
            'request_type' => ['required', 'in:referral,telemedicine'],
            'source.record_id' => ['required', 'string'],
            'source.facility_code' => ['required', 'string'],
            'source.practitioner.prc_license' => ['required', 'string'],
            'patient.identifiers' => ['required', 'array', 'min:1'],
            'clinical.reason_text' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $bundle = $factory->build($intake, $destination);

        $json = (string) json_encode(
            $bundle,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $out = $this->option('out');

        if ($out === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents($out, $json.PHP_EOL);
        $this->info("Wrote {$out} — ".count($bundle['entry']).' entries');

        return self::SUCCESS;
    }
}
