<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;

/**
 * Seeds the destination registry.
 *
 * Two rows, and the contrast between them is the point:
 *
 *   - our telemedicine system, which has an endpoint and is the v1 receiver
 *   - a real HCPN facility from the government list, which does not have one yet
 *
 * The second is listed so a sender can see it exists, and refused if they try to send to it.
 * That is the honest behaviour: most facilities in the national directory cannot receive FHIR
 * today, and pretending otherwise would queue referrals that never move.
 */
class DestinationSeeder extends Seeder
{
    public function run(): void
    {
        Destination::query()->updateOrCreate(
            ['hcpn_id' => 'telemedicine-internal'],
            [
                'display_name' => 'Telemedicine Service',
                'city' => 'Internal',
                'nhfr_code' => 'DOH000000000000001',
                'hcpn_code' => null,
                'endpoint_url' => config('fhir.internal.telemedicine_endpoint'),
                'auth_type' => 'bearer',
                'auth_credential_key' => 'telemedicine_token',
                'accepts' => ['referral', 'telemedicine'],
                'active' => true,
            ],
        );

        Destination::query()->updateOrCreate(
            ['hcpn_id' => 'drstmh-kalibo'],
            [
                'display_name' => 'Dr. Rafael S. Tumbokon Memorial Hospital',
                'city' => 'Kalibo, Aklan',
                'nhfr_code' => '513',
                'hcpn_code' => null,
                // No endpoint: in the directory, not yet reachable.
                'endpoint_url' => null,
                'auth_type' => null,
                'auth_credential_key' => null,
                'accepts' => ['referral'],
                'active' => true,
            ],
        );
    }
}
