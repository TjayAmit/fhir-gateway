<?php

declare(strict_types=1);

use App\Fhir\Constants;
use App\Models\AuditEvent;
use App\Models\Destination;
use App\Models\OutboundReferral;
use App\Models\ReferralTask;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function intakePayload(array $overrides = []): array
{
    $payload = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/intake/telemedicine-consult.json')),
        true,
    );

    return array_replace_recursive($payload, $overrides);
}

function receiver(): Destination
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

/**
 * @param  list<array<string, mixed>>  $patients  resources the identifier search returns
 */
function fakeReceiver(array $patients = []): void
{
    Http::fake([
        'telemedicine.test/fhir/Patient*' => Http::response([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => count($patients),
            'entry' => array_map(static fn (array $p): array => ['resource' => $p], $patients),
        ]),
        'telemedicine.test/fhir' => Http::response([
            'resourceType' => 'Bundle',
            'type' => 'transaction-response',
            'entry' => [
                ['response' => ['status' => '200 OK', 'location' => 'Patient/pat-77/_history/3']],
                ['response' => ['status' => '201 Created', 'location' => 'ServiceRequest/sr-42/_history/1']],
                ['response' => ['status' => '201 Created', 'location' => 'Task/task-99/_history/1']],
            ],
        ]),
    ]);
}

it('accepts a submission and delivers it', function () {
    receiver();
    fakeReceiver();

    $response = $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload());

    $response->assertStatus(202)
        ->assertJsonPath('status', OutboundReferral::STATUS_DELIVERED)
        ->assertJsonPath('task_id', 'task-99')
        ->assertJsonPath('service_request_id', 'sr-42');

    $this->assertDatabaseHas('outbound_referrals', [
        'message_id' => '8c2d5b31-77af-4e0a-b1c6-2d9f4a83e510',
        'status' => OutboundReferral::STATUS_DELIVERED,
        'attempts' => 1,
    ]);

    // Workflow state is tracked; the referral's content is not.
    $task = ReferralTask::query()->firstOrFail();
    expect($task->fhir_task_id)->toBe('task-99')
        ->and($task->direction)->toBe(ReferralTask::DIRECTION_OUTBOUND)
        ->and($task->performer_facility)->toBe('DOH000000000000001');
});

it('stores no clinical content anywhere', function () {
    receiver();
    fakeReceiver();

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())->assertStatus(202);

    $referral = OutboundReferral::query()->firstOrFail()->getAttributes();
    $serialised = json_encode($referral);

    // The diagnosis, the reason and the patient's name must not have been persisted.
    expect($serialised)->not->toContain('hypertension')
        ->and($serialised)->not->toContain('Dela Cruz')
        ->and($serialised)->not->toContain('Follow-up');
});

it('replays an already delivered submission without sending it twice', function () {
    receiver();
    fakeReceiver();

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())->assertStatus(202);

    $sentFirstTime = count(Http::recorded());

    // Same message_id: the sender retried, not referred again.
    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())
        ->assertStatus(200)
        ->assertJsonPath('task_id', 'task-99');

    expect(Http::recorded())->toHaveCount($sentFirstTime)
        ->and(OutboundReferral::query()->count())->toBe(1);
});

it('keys the upsert on the identifier the receiver already holds', function () {
    receiver();

    // The receiver knows this patient by PhilHealth. They have no PhilSys on file.
    fakeReceiver([[
        'resourceType' => 'Patient',
        'id' => 'pat-77',
        'identifier' => [['system' => Constants::ID_PHILHEALTH, 'value' => '12-345678901-2']],
    ]]);

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())->assertStatus(202);

    $transaction = collect(Http::recorded())
        ->first(fn (array $pair): bool => $pair[0]->method() === 'POST');

    $bundle = json_decode($transaction[0]->body(), true);
    $patientEntry = collect($bundle['entry'])
        ->first(fn (array $e): bool => $e['resource']['resourceType'] === 'Patient');

    expect($patientEntry['request']['url'])
        ->toBe('Patient?identifier='.Constants::ID_PHILHEALTH.'|12-345678901-2');
});

it('refuses to merge two patients when the identifiers disagree', function () {
    receiver();

    // PhilSys finds one person, PhilHealth finds a different one.
    Http::fake([
        'telemedicine.test/fhir/Patient?identifier='.urlencode(Constants::ID_PHILSYS).'*' => Http::response([
            'resourceType' => 'Bundle',
            'entry' => [['resource' => ['resourceType' => 'Patient', 'id' => 'pat-100']]],
        ]),
        'telemedicine.test/fhir/Patient*' => Http::response([
            'resourceType' => 'Bundle',
            'entry' => [['resource' => ['resourceType' => 'Patient', 'id' => 'pat-200']]],
        ]),
        'telemedicine.test/fhir' => Http::response(['resourceType' => 'Bundle']),
    ]);

    $payload = intakePayload();
    $payload['patient']['identifiers'] = [
        ['type' => 'philsys', 'value' => '7731-0812-4491-0326'],
        ['type' => 'philhealth', 'value' => '12-345678901-2'],
    ];

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', $payload)
        ->assertStatus(409)
        ->assertJsonPath('resourceType', 'OperationOutcome')
        ->assertJsonPath('issue.0.code', 'conflict');

    // Nothing was sent to the receiver.
    expect(collect(Http::recorded())->every(fn (array $p): bool => $p[0]->method() === 'GET'))->toBeTrue();
});

it('records a failed delivery without losing the submission', function () {
    receiver();

    Http::fake([
        'telemedicine.test/fhir/Patient*' => Http::response(['resourceType' => 'Bundle', 'entry' => []]),
        'telemedicine.test/fhir' => Http::response('upstream exploded', 503),
    ]);

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())
        ->assertStatus(202)
        ->assertJsonPath('status', OutboundReferral::STATUS_FAILED);

    $referral = OutboundReferral::query()->firstOrFail();
    expect($referral->last_error)->toContain('503');
});

it('rejects a patient with no national identifier', function () {
    receiver();

    $payload = intakePayload();
    $payload['patient']['identifiers'] = [['type' => 'local', 'value' => 'MRN-1']];

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', $payload)
        ->assertStatus(422)
        ->assertJsonPath('resourceType', 'OperationOutcome')
        ->assertJsonPath('issue.0.expression.0', 'patient.identifiers');
});

it('rejects a destination that has no endpoint', function () {
    Destination::query()->create([
        'hcpn_id' => 'telemedicine-internal',
        'display_name' => 'Listed but unreachable',
        'nhfr_code' => '513',
        'endpoint_url' => null,
        'active' => true,
    ]);

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())
        ->assertStatus(422)
        ->assertJsonPath('issue.0.expression.0', 'destination.hcpn_id');
});

it('rejects a timestamp with no UTC offset', function () {
    receiver();

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload(['sent_at' => '2026-09-07 10:15:00']))
        ->assertStatus(422)
        ->assertJsonPath('issue.0.expression.0', 'sent_at');
});

it('reports the status of a submission', function () {
    receiver();
    fakeReceiver();

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())->assertStatus(202);

    $this->withHeaders(asClient())->getJson('/api/intake/v1/requests/8c2d5b31-77af-4e0a-b1c6-2d9f4a83e510')
        ->assertOk()
        ->assertJsonPath('status', OutboundReferral::STATUS_DELIVERED);

    $this->withHeaders(asClient())->getJson('/api/intake/v1/requests/'.Str::uuid()->toString())
        ->assertStatus(404)
        ->assertJsonPath('resourceType', 'OperationOutcome');
});

it('audits every submission with the patient identifiers', function () {
    receiver();
    fakeReceiver();

    $this->withHeaders(asClient())->postJson('/api/intake/v1/requests', intakePayload())->assertStatus(202);

    $audit = AuditEvent::query()->firstOrFail();

    expect($audit->action)->toBe('create')
        ->and($audit->actor_facility)->toBe('3056')
        ->and($audit->http_status)->toBe(202)
        // An audit trail that cannot say which patient was involved does not do its job.
        ->and($audit->query['patient_identifiers'][0]['value'])->toBe('12-345678901-2');
});
