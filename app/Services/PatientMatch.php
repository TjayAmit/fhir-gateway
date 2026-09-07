<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The outcome of resolving a patient at a receiving facility.
 *
 * `upsertSystem` / `upsertValue` are the identifier the submission Bundle keys its conditional
 * PUT on. Getting this right is the difference between updating the patient's existing chart
 * and silently creating a second one beside it.
 */
final readonly class PatientMatch
{
    /**
     * @param  bool  $isNew  no record found under either national identifier
     * @param  string|null  $matchedType  which identifier actually found them: philsys or philhealth
     * @param  string|null  $backfilled  the identifier type the matched record was missing
     */
    public function __construct(
        public string $upsertSystem,
        public string $upsertValue,
        public bool $isNew,
        public ?string $matchedType = null,
        public ?string $matchedResourceId = null,
        public ?string $backfilled = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_new' => $this->isNew,
            'matched_by' => $this->matchedType,
            'matched_resource_id' => $this->matchedResourceId,
            'backfilled_identifier' => $this->backfilled,
        ];
    }
}
