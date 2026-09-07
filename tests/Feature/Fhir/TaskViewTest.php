<?php

declare(strict_types=1);

use App\Fhir\Builders\TaskViewBuilder;
use App\Fhir\Constants;
use App\Models\ReferralTask;

function storedTask(array $overrides = []): ReferralTask
{
    return ReferralTask::query()->create(array_merge([
        'fhir_task_id' => 'task-99',
        'fhir_service_request_id' => 'sr-42',
        'direction' => ReferralTask::DIRECTION_OUTBOUND,
        'status' => 'requested',
        'business_status' => null,
        'requester_facility' => '3056',
        'performer_facility' => '513',
        'patient_fhir_id' => 'pat-77',
    ], $overrides));
}

it('renders a stored referral as a FHIR Task', function () {
    $task = TaskViewBuilder::build(storedTask());

    expect($task['resourceType'])->toBe('Task')
        ->and($task['id'])->toBe('task-99')
        ->and($task['meta']['profile'][0])->toBe(Constants::PROFILE_TASK)
        ->and($task['status'])->toBe('requested')
        ->and($task['intent'])->toBe('order')
        ->and($task['focus']['reference'])->toBe('ServiceRequest/sr-42')
        ->and($task['for']['reference'])->toBe('Patient/pat-77');
});

it('expresses facilities as identifier-only references', function () {
    $task = TaskViewBuilder::build(storedTask());

    // We hold an NHFR code, not the receiver's Organization resource id. A Reference carrying
    // an identifier says "this organisation, resolve it yourself" instead of inventing a URL.
    expect($task['requester']['identifier']['system'])->toBe(Constants::ID_NHFR)
        ->and($task['requester']['identifier']['value'])->toBe('3056')
        ->and($task['requester'])->not->toHaveKey('reference')
        ->and($task['owner']['identifier']['value'])->toBe('513');
});

it('codes the business status against the eReferral workflow system', function () {
    $task = TaskViewBuilder::build(storedTask([
        'fhir_task_id' => 'task-100',
        'status' => 'accepted',
        'business_status' => Constants::WORKFLOW_ACCEPTED,
    ]));

    expect($task['businessStatus']['coding'][0]['system'])->toBe(Constants::CS_EREF_WORKFLOW)
        ->and($task['businessStatus']['coding'][0]['code'])->toBe('accepted');
});

it('carries no clinical content, because none is stored', function () {
    $json = json_encode(TaskViewBuilder::build(storedTask()));

    // The read side can only be as rich as what we keep, and we keep workflow state only.
    expect($json)->not->toContain('reasonCode')
        ->and($json)->not->toContain('note')
        ->and($json)->not->toContain('description');
});

it('omits elements it has no value for rather than emitting null', function () {
    $task = TaskViewBuilder::build(storedTask([
        'fhir_task_id' => 'task-101',
        'patient_fhir_id' => null,
        'performer_facility' => null,
    ]));

    expect($task)->not->toHaveKey('for')
        ->and($task)->not->toHaveKey('owner')
        ->and($task)->not->toHaveKey('businessStatus')
        ->and(json_encode($task))->not->toContain(':null');
});
