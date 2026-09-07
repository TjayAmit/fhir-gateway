<?php

declare(strict_types=1);

use App\Fhir\Constants;
use App\Fhir\ReferralBundleFactory;
use App\Models\Destination;
use App\Services\PatientMatch;

/**
 * @param  array<string, mixed>  $bundle
 * @return array<string, mixed>
 */
function entryOfType(array $bundle, string $resourceType): array
{
    foreach ($bundle['entry'] as $entry) {
        if ($entry['resource']['resourceType'] === $resourceType) {
            return $entry;
        }
    }

    throw new RuntimeException("No {$resourceType} entry in the bundle.");
}

/**
 * Replace every urn:uuid with a stable token, in order of first appearance, so two bundles
 * built from the same input compare equal despite freshly minted identifiers.
 */
function normaliseUuids(string $json): string
{
    preg_match_all('/urn:uuid:[0-9a-f-]{36}/i', $json, $matches);

    $seen = [];
    $n = 0;

    foreach ($matches[0] as $urn) {
        if (! isset($seen[$urn])) {
            $seen[$urn] = 'urn:uuid:REF-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        }
    }

    return strtr($json, $seen);
}

/**
 * @return array<string, mixed>
 */
function intakeFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/intake/{$name}.json")), true);
}

function kaliboDestination(): Destination
{
    return Destination::query()->create([
        'hcpn_id' => 'drstmh-kalibo',
        'display_name' => 'Dr. Rafael S. Tumbokon Memorial Hospital',
        'city' => 'Kalibo, Aklan',
        'nhfr_code' => '513',
        'endpoint_url' => null,
        'accepts' => ['referral'],
        'active' => true,
    ]);
}

function telemedicineDestination(): Destination
{
    return Destination::query()->create([
        'hcpn_id' => 'telemedicine-internal',
        'display_name' => 'Telemedicine Service',
        'nhfr_code' => 'DOH000000000000001',
        'endpoint_url' => 'http://telemedicine.test/fhir',
        'auth_type' => 'bearer',
        'auth_credential_key' => 'telemedicine_token',
        'accepts' => ['referral', 'telemedicine'],
        'active' => true,
    ]);
}

it('builds a transaction bundle with conditional upserts for identity', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    expect($bundle['resourceType'])->toBe('Bundle')
        ->and($bundle['type'])->toBe('transaction');

    // Identity resources are upserted; clinical and workflow resources are created.
    $methods = [];

    foreach ($bundle['entry'] as $entry) {
        $methods[$entry['resource']['resourceType']][] = $entry['request']['method'];
    }

    expect($methods['Patient'])->toBe(['PUT'])
        ->and($methods['Practitioner'])->toBe(['PUT'])
        ->and($methods['Organization'])->toBe(['PUT', 'PUT'])
        ->and($methods['PractitionerRole'])->toBe(['PUT'])
        ->and($methods['ServiceRequest'])->toBe(['POST'])
        ->and($methods['Task'])->toBe(['POST']);
});

it('keys the patient upsert on PhilSys when the receiver knows nothing about them', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    expect(entryOfType($bundle, 'Patient')['request']['url'])
        ->toBe('Patient?identifier='.Constants::ID_PHILSYS.'|7731-0812-4491-0326');
});

it('keys the patient upsert on whichever identifier the receiver already holds', function () {
    // The receiver knows this patient by PhilHealth only. Keying on PhilSys would create a
    // second chart beside the one they already have.
    $match = new PatientMatch(
        upsertSystem: Constants::ID_PHILHEALTH,
        upsertValue: '78-658064775-3',
        isNew: false,
        matchedType: 'philhealth',
        matchedResourceId: 'pat-9001',
        backfilled: 'philsys',
    );

    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination(), $match);

    expect(entryOfType($bundle, 'Patient')['request']['url'])
        ->toBe('Patient?identifier='.Constants::ID_PHILHEALTH.'|78-658064775-3');

    // Both identifiers still travel in the body, so the missing one is backfilled by the PUT.
    $systems = array_column(entryOfType($bundle, 'Patient')['resource']['identifier'], 'system');

    expect($systems)->toContain(Constants::ID_PHILSYS)
        ->and($systems)->toContain(Constants::ID_PHILHEALTH);
});

it('encodes PSGC address levels as Coding, not CodeableConcept', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    $extensions = entryOfType($bundle, 'Patient')['resource']['address'][0]['extension'];

    expect($extensions)->toHaveCount(4);

    foreach ($extensions as $extension) {
        // valueCodeableConcept here is a hard validation error that php-fhir will not catch.
        expect($extension)->toHaveKey('valueCoding')
            ->and($extension)->not->toHaveKey('valueCodeableConcept')
            ->and($extension['valueCoding']['system'])->toBe(Constants::CS_PSGC);
    }
});

it('satisfies the required category and reasonCode bindings', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    $serviceRequest = entryOfType($bundle, 'ServiceRequest')['resource'];

    expect($serviceRequest['category'][0]['coding'][0]['code'])->toBe('73770003')
        ->and($serviceRequest['reasonCode'][0]['coding'][0]['code'])->toBe('71388002')
        ->and($serviceRequest['intent'])->toBe('order')
        ->and($serviceRequest['priority'])->toBe('urgent')
        ->and($serviceRequest['requisition']['value'])->toBe('REF-2026-001234');
});

it('rejects a category outside the required value set', function () {
    $intake = intakeFixture('referral-kalibo');
    $intake['referral']['category'] = 'walk-in';

    app(ReferralBundleFactory::class)->build($intake, kaliboDestination());
})->throws(InvalidArgumentException::class, 'Unknown referral category [walk-in]');

it('carries a telemedicine window in a note because the profile forbids occurrencePeriod', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('telemedicine-consult'), telemedicineDestination());

    $serviceRequest = entryOfType($bundle, 'ServiceRequest')['resource'];

    // ERefServiceRequest slices occurrence[x] CLOSED on occurrenceDateTime.
    expect($serviceRequest)->not->toHaveKey('occurrencePeriod')
        ->and($serviceRequest['occurrenceDateTime'])->toBe('2026-09-09T09:00:00+08:00')
        ->and($serviceRequest['note'][0]['text'])->toContain('2026-09-09T12:00:00+08:00');
});

it('falls back to PhilHealth for the upsert key when there is no PhilSys', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('telemedicine-consult'), telemedicineDestination());

    expect(entryOfType($bundle, 'Patient')['request']['url'])
        ->toBe('Patient?identifier='.Constants::ID_PHILHEALTH.'|12-345678901-2');
});

it('sends the Task conditionally so a replay cannot open a second referral', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    $task = entryOfType($bundle, 'Task');

    expect($task['request']['ifNoneExist'])
        ->toContain('3f9a1c74-2b6e-4d18-9c33-1a5b7e0d4f21')
        ->and($task['resource']['status'])->toBe('requested')
        // businessStatus is the receiver's to write; asserting it would claim their decision.
        ->and($task['resource'])->not->toHaveKey('businessStatus');
});

it('targets Provenance at the ServiceRequest only', function () {
    $bundle = app(ReferralBundleFactory::class)
        ->build(intakeFixture('referral-kalibo'), kaliboDestination());

    $provenance = entryOfType($bundle, 'Provenance')['resource'];
    $serviceRequestUrn = entryOfType($bundle, 'ServiceRequest')['fullUrl'];

    // The profile does not allow a Task target, even though the Task carries the workflow.
    expect($provenance['target'])->toHaveCount(1)
        ->and($provenance['target'][0]['reference'])->toBe($serviceRequestUrn);
});

it('matches the committed golden fixtures', function (string $intake, string $golden, Destination $destination) {
    $built = app(ReferralBundleFactory::class)->build(intakeFixture($intake), $destination);

    $expected = (string) file_get_contents(base_path("tests/fixtures/fhir/{$golden}.json"));

    $encode = fn (array $b): string => (string) json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // The golden files are what CI validates against the IGs. If this fails, the translation
    // changed: regenerate with `php artisan fhir:build-referral` and re-validate before
    // committing, rather than editing the fixture to match.
    expect(normaliseUuids($encode($built)))
        ->toBe(normaliseUuids(trim($expected)));
})->with([
    'referral' => fn () => ['referral-kalibo', 'generated-referral-bundle', kaliboDestination()],
    'telemedicine' => fn () => ['telemedicine-consult', 'generated-telemedicine-bundle', telemedicineDestination()],
]);
