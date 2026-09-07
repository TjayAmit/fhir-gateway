<?php

declare(strict_types=1);

use App\Fhir\ReferralBundleFactory;
use App\Fhir\ReferralBundleReader;
use App\Models\Destination;

/**
 * intake JSON → Bundle → intake JSON.
 *
 * The factory and the reader are the two halves of the gateway, and this is the only test that
 * puts them back to back. Anything the outbound translation quietly loses shows up here as a
 * field that fails to come home — which is exactly the failure mode this project exists to
 * catch, php-fhir having taught us it will not be reported any other way.
 */
function destinationFor(string $hcpnId, string $nhfr): Destination
{
    return Destination::query()->create([
        'hcpn_id' => $hcpnId,
        'display_name' => 'Round-trip destination',
        'nhfr_code' => $nhfr,
        'endpoint_url' => 'http://receiver.test/fhir',
        'accepts' => ['referral', 'telemedicine'],
        'active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function roundTrip(string $fixture, Destination $destination): array
{
    $intake = json_decode(
        (string) file_get_contents(base_path("tests/fixtures/intake/{$fixture}.json")),
        true,
    );

    $bundle = app(ReferralBundleFactory::class)->build($intake, $destination);

    return (new ReferralBundleReader)->read($bundle);
}

it('brings a referral home unchanged in everything that matters clinically', function () {
    $destination = destinationFor('drstmh-kalibo', '513');
    $out = roundTrip('referral-kalibo', $destination);

    expect($out['request_type'])->toBe('referral')
        ->and($out['message_id'])->toBe('3f9a1c74-2b6e-4d18-9c33-1a5b7e0d4f21')
        ->and($out['sent_at'])->toBe('2026-06-18T08:30:00+08:00')
        ->and($out['source']['record_id'])->toBe('REF-2026-001234')
        ->and($out['source']['facility_code'])->toBe('3056')
        ->and($out['source']['facility_name'])->toBe('Kalibo Health Center')
        ->and($out['source']['practitioner']['prc_license'])->toBe('5466863')
        ->and($out['source']['practitioner']['family_name'])->toBe('Villanueva')
        ->and($out['source']['practitioner']['role'])->toBe('physician')
        ->and($out['destination']['hcpn_id'])->toBe('drstmh-kalibo');
});

it('brings the patient home, identifiers and PSGC codes intact', function () {
    $out = roundTrip('referral-kalibo', destinationFor('drstmh-kalibo', '513'));
    $patient = $out['patient'];

    expect($patient['family_name'])->toBe('Reyes')
        ->and($patient['given_names'])->toBe(['Ana', 'Luisa'])
        ->and($patient['birth_date'])->toBe('1988-03-12')
        ->and($patient['sex'])->toBe('female')
        ->and($patient['mobile'])->toBe('+63-919-876-5432')
        ->and($patient['identifiers'])->toEqual([
            ['type' => 'philsys', 'value' => '7731-0812-4491-0326'],
            ['type' => 'philhealth', 'value' => '78-658064775-3'],
        ])
        ->and($patient['address']['barangay_psgc'])->toBe('0600407013')
        ->and($patient['address']['region_psgc'])->toBe('0600000000')
        ->and($patient['address']['postal_code'])->toBe('5600');
});

it('brings the clinical payload home', function () {
    $out = roundTrip('referral-kalibo', destinationFor('drstmh-kalibo', '513'));
    $clinical = $out['clinical'];

    expect($clinical['reason_text'])->toContain('Severe pre-eclampsia')
        ->and($clinical['encounter']['class'])->toBe('AMB')
        ->and($clinical['encounter']['start'])->toBe('2026-06-18T07:45:00+08:00')
        ->and($clinical['diagnoses'])->toHaveCount(2)
        ->and($clinical['diagnoses'][0]['code'])->toBe('398254007')
        ->and($clinical['diagnoses'][0]['system'])->toBe('snomed')
        ->and($clinical['vitals'])->toHaveCount(3)
        ->and($clinical['vitals'][1]['value'])->toBe(36.8)
        ->and($clinical['vitals'][1]['unit'])->toBe('Cel');
});

it('brings the referral fields home, including the two that used to be dropped', function () {
    $out = roundTrip('referral-kalibo', destinationFor('drstmh-kalibo', '513'));

    expect($out['referral']['category'])->toBe('emergency')
        ->and($out['referral']['service_type'])->toBe('procedure')
        ->and($out['referral']['priority'])->toBe('urgent')
        // Both of these were validated on intake and then silently discarded in translation,
        // until this round trip made their absence visible.
        ->and($out['referral']['service_requested'])->toBe('Emergency obstetric management')
        ->and($out['referral']['specialty'])->toBe('obstetrics');
});

it('brings a telemedicine consult home, modality and all', function () {
    $out = roundTrip('telemedicine-consult', destinationFor('telemedicine-internal', 'DOH000000000000001'));

    expect($out['request_type'])->toBe('telemedicine')
        ->and($out['telemedicine']['modality'])->toBe('video')
        ->and($out['telemedicine']['specialty'])->toBe('internal-medicine')
        ->and($out['telemedicine']['priority'])->toBe('routine')
        ->and($out['telemedicine']['preferred_windows'][0]['start'])->toBe('2026-09-09T09:00:00+08:00')
        ->and($out['patient']['identifiers'][0])->toEqual(['type' => 'philhealth', 'value' => '12-345678901-2']);
});

it('documents exactly what the wire format cannot carry', function () {
    $out = roundTrip('telemedicine-consult', destinationFor('telemedicine-internal', 'DOH000000000000001'));

    // The window's end. ERefServiceRequest slices occurrence[x] closed on occurrenceDateTime,
    // so only the start is structural — the end survives as prose in the note and nothing else.
    expect($out['telemedicine']['preferred_windows'][0])->not->toHaveKey('end')
        ->and($out['clinical']['notes'])->toContain('2026-09-09T12:00:00+08:00');

    // `destination.department` and `source.system` are ours, not the IG's: neither has a home
    // in an eReferral Bundle. `source.system` is recovered from the authenticated client on the
    // way back in, and `department` is genuinely lost.
    expect($out['source'])->not->toHaveKey('system')
        ->and($out['destination'])->not->toHaveKey('department');
})->note('These losses are intended and documented in docs/intake-contract.md. A new one appearing here is a bug.');

it('refuses a bundle that is not a referral', function () {
    (new ReferralBundleReader)->read(['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => []]);
})->throws(RuntimeException::class, 'no ServiceRequest');
