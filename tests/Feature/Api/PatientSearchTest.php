<?php

declare(strict_types=1);

use App\Fhir\Constants;
use App\Models\AuditEvent;
use App\Models\Destination;
use Illuminate\Support\Facades\Http;

/**
 * Flow 1 — do we know this patient?
 *
 * The gateway holds no patient records, so it asks the native systems over the native contract
 * and translates what comes back. Nothing is cached: a facade that keeps demographics has
 * stopped being a facade.
 */
function searchableSystem(array $overrides = []): Destination
{
    return Destination::query()->create(array_merge([
        'hcpn_id' => 'referral-internal',
        'display_name' => 'Referral System',
        'nhfr_code' => '3056',
        'endpoint_url' => 'http://receiver.test/fhir',
        'patient_search_url' => 'http://referral.internal/gateway/patients',
        'active' => true,
    ], $overrides));
}

function nativeReturnsPatient(): void
{
    Http::fake([
        'referral.internal/gateway/patients*' => Http::response(['data' => [[
            'identifiers' => [
                ['type' => 'philsys', 'value' => '7731-0812-4491-0326'],
                ['type' => 'philhealth', 'value' => '78-658064775-3'],
            ],
            'family_name' => 'Reyes',
            'given_names' => ['Ana', 'Luisa'],
            'birth_date' => '1988-03-12',
            'sex' => 'female',
            'mobile' => '+63-919-876-5432',
            'address' => ['barangay_psgc' => '0600407013', 'postal_code' => '5600'],
            'native_id' => 'MRN-0099123',
        ]]]),
    ]);
}

$philsys = 'identifier='.Constants::ID_PHILSYS.'|7731-0812-4491-0326';

it('translates a native match into a PH Core Patient searchset', function () use ($philsys) {
    searchableSystem();
    nativeReturnsPatient();

    $response = $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys);

    $response->assertOk()
        ->assertJsonPath('resourceType', 'Bundle')
        ->assertJsonPath('type', 'searchset')
        ->assertJsonPath('total', 1)
        ->assertJsonPath('entry.0.resource.resourceType', 'Patient')
        ->assertJsonPath('entry.0.resource.name.0.family', 'Reyes')
        ->assertJsonPath('entry.0.resource.birthDate', '1988-03-12')
        ->assertJsonPath('entry.0.search.mode', 'match');

    // PH Core address extensions, not a plain city string.
    expect($response->json('entry.0.resource.address.0.extension.0.valueCoding.system'))
        ->toBe(Constants::CS_PSGC);
});

it('asks the native system in the native contract, not in FHIR', function () use ($philsys) {
    searchableSystem();
    nativeReturnsPatient();

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys)->assertOk();

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'http://referral.internal/gateway/patients')
        && str_contains(urldecode($request->url()), Constants::ID_PHILSYS.'|7731-0812-4491-0326'));
});

it('returns an empty searchset when nobody knows the patient', function () use ($philsys) {
    searchableSystem();
    Http::fake(['referral.internal/*' => Http::response(['data' => []])]);

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys)
        ->assertOk()
        ->assertJsonPath('total', 0);
});

it('refuses name and birth-date search outright', function () {
    searchableSystem();

    // A search surface that can be walked is a patient enumeration tool.
    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?family=Reyes')
        ->assertStatus(400)
        ->assertJsonPath('issue.0.code', 'not-supported');

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?birthdate=1988-03-12')
        ->assertStatus(400);
});

it('refuses identifier systems that are not national', function () {
    searchableSystem();

    $this->withHeaders(asClient())
        ->getJson('/api/fhir/Patient?identifier='.urlencode('https://example.org/mrn').'|MRN-1')
        ->assertStatus(400)
        ->assertJsonPath('issue.0.code', 'value');

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?identifier=7731-0812')
        ->assertStatus(400)
        ->assertJsonPath('issue.0.code', 'required');
});

it('only asks the systems belonging to the caller facility', function () use ($philsys) {
    searchableSystem();

    // Another facility's system. The token's scope must keep it out of reach.
    searchableSystem([
        'hcpn_id' => 'other-facility',
        'nhfr_code' => '9999',
        'patient_search_url' => 'http://elsewhere.internal/gateway/patients',
    ]);

    Http::fake([
        'referral.internal/*' => Http::response(['data' => []]),
        'elsewhere.internal/*' => Http::response(['data' => []]),
    ]);

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys)->assertOk();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'elsewhere.internal'));
});

it('keeps no copy of what the native system returned', function () use ($philsys) {
    searchableSystem();
    nativeReturnsPatient();

    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys)->assertOk();

    // The audit records that a lookup happened and for which identifier — never the answer.
    $audit = AuditEvent::query()->latest('id')->firstOrFail();
    $everything = json_encode($audit->getAttributes());

    expect($audit->action)->toBe('search')
        ->and($audit->result_count)->toBe(1)
        ->and($everything)->not->toContain('Reyes')
        ->and($everything)->not->toContain('1988-03-12');
});

it('still answers when one native system is down', function () use ($philsys) {
    searchableSystem();

    Http::fake(['referral.internal/*' => Http::response('gone', 500)]);

    // A partial answer beats no answer, and the failure is reported rather than swallowed.
    $this->withHeaders(asClient())->getJson('/api/fhir/Patient?'.$philsys)
        ->assertOk()
        ->assertJsonPath('total', 0);
});

it('needs a token', function () use ($philsys) {
    searchableSystem();
    asClient();

    $this->getJson('/api/fhir/Patient?'.$philsys)->assertStatus(401);
});
