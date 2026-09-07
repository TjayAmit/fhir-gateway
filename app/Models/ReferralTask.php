<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a referral sits in the eReferral workflow.
 *
 * Only the workflow state, never its clinical content: which Task, which ServiceRequest, who
 * sent it, who owns it, and what the receiving facility has said back. That is enough to
 * answer "what happened to my referral?" without the gateway holding the referral.
 *
 * @property string|null $fhir_task_id
 * @property string|null $fhir_service_request_id
 * @property string $direction
 * @property string $status
 * @property string|null $business_status
 * @property string|null $requester_facility
 * @property string|null $performer_facility
 * @property string|null $patient_fhir_id
 */
class ReferralTask extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'fhir_task_id',
        'fhir_service_request_id',
        'direction',
        'status',
        'business_status',
        'requester_facility',
        'performer_facility',
        'patient_fhir_id',
        'replaces_service_request_id',
    ];
}
