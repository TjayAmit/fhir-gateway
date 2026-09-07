<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;
use App\Models\ReferralTask;

/**
 * Renders a tracked referral back out as a FHIR Task.
 *
 * This is the read side of the gateway, and it can only be as rich as what we store — which
 * is workflow state and nothing else (D1). There is no clinical content here because there is
 * none to render: no reason, no note, no diagnosis.
 *
 * Facilities appear as identifier-only References. That is deliberate rather than lazy: we
 * hold an NHFR code, not the receiving system's Organization resource id, and a Reference
 * carrying an identifier is exactly how FHIR expresses "this organisation, which you can
 * resolve yourself" without inventing a URL we cannot honour.
 */
final class TaskViewBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(ReferralTask $task): array
    {
        return Element::prune([
            'resourceType' => 'Task',
            'id' => $task->fhir_task_id,
            'meta' => [
                'profile' => [Constants::PROFILE_TASK],
                'lastUpdated' => $task->updated_at?->toIso8601String(),
            ],
            'status' => $task->status,
            'businessStatus' => $task->business_status !== null
                ? Element::conceptOf(Constants::CS_EREF_WORKFLOW, $task->business_status)
                : null,
            'statusReason' => $task->status_reason !== null
                ? ['text' => $task->status_reason]
                : null,
            'intent' => 'order',
            'code' => Element::conceptOf(
                Constants::CS_SNOMED,
                Constants::TASK_CODE_REFERRAL['code'],
                Constants::TASK_CODE_REFERRAL['display'],
            ),
            'focus' => $task->fhir_service_request_id !== null
                ? Element::reference('ServiceRequest/'.$task->fhir_service_request_id)
                : null,
            'for' => $task->patient_fhir_id !== null
                ? Element::reference('Patient/'.$task->patient_fhir_id)
                : null,
            'authoredOn' => $task->created_at?->toIso8601String(),
            'lastModified' => $task->updated_at?->toIso8601String(),
            'requester' => self::facility($task->requester_facility),
            'owner' => self::facility($task->performer_facility),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function facility(?string $nhfrCode): ?array
    {
        if ($nhfrCode === null || $nhfrCode === '') {
            return null;
        }

        return [
            'type' => 'Organization',
            'identifier' => Element::identifier(Constants::ID_NHFR, $nhfrCode),
        ];
    }

    private function __construct() {}
}
