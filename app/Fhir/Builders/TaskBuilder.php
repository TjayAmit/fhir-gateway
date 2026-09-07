<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * ERefTask — the workflow wrapper around a ServiceRequest.
 *
 * The ServiceRequest says what is being asked for; the Task says where it is in the
 * referral workflow and who currently owns it. A submission always starts at
 * `status = requested`, owned by the receiving facility.
 *
 * `businessStatus` carries the eReferral workflow sub-state (received, accepted, rejected,
 * referred-onward, capacity-full). We leave it unset on submission — it is the receiver's
 * to write, and asserting it ourselves would be claiming their decision for them.
 */
final class TaskBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        string $messageId,
        string $focusRef,
        string $patientRef,
        string $requesterRef,
        string $ownerRef,
        string $authoredOn,
        ?string $reasonText = null,
        ?string $note = null,
        string $status = 'requested',
        ?string $businessStatus = null,
    ): array {
        return Element::prune([
            'resourceType' => 'Task',
            'meta' => ['profile' => [Constants::PROFILE_TASK]],
            // The sender's message_id doubles as the Task identifier, which is what makes a
            // replayed submission detectable as a duplicate at the receiving end too.
            'identifier' => [
                Element::identifier((string) config('fhir.message_identifier_system'), $messageId),
            ],
            'status' => $status,
            'businessStatus' => $businessStatus !== null
                ? Element::conceptOf(Constants::CS_EREF_WORKFLOW, $businessStatus)
                : null,
            'intent' => 'order',
            'code' => Element::conceptOf(
                Constants::CS_SNOMED,
                Constants::TASK_CODE_REFERRAL['code'],
                Constants::TASK_CODE_REFERRAL['display'],
                $reasonText,
            ),
            'focus' => Element::reference($focusRef),
            'for' => Element::reference($patientRef),
            'authoredOn' => $authoredOn,
            'lastModified' => $authoredOn,
            'requester' => Element::reference($requesterRef),
            'owner' => Element::reference($ownerRef),
            'note' => $note !== null && $note !== '' ? [['text' => $note]] : null,
        ]);
    }

    private function __construct() {}
}
