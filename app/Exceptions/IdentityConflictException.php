<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Two national identifiers on one submission point at two different patients.
 *
 * The gateway refuses rather than choosing. Picking one would merge two people's charts —
 * their allergies, their medications — and once downstream systems copy the merged record
 * it is very hard to pull apart again. A refusal is recoverable; a bad merge often is not.
 *
 * Each occurrence is also a duplicate report the Master Patient Index can act on.
 */
final class IdentityConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $philsysMatch,
        public readonly string $philhealthMatch,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'PhilSys resolves to Patient/%s but PhilHealth resolves to Patient/%s. '.
            'Refusing to merge two records; the identifiers need reconciling at the source.',
            $philsysMatch,
            $philhealthMatch,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toOperationOutcome(): array
    {
        return [
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity' => 'error',
                'code' => 'conflict',
                'details' => ['text' => 'Conflicting patient identity'],
                'diagnostics' => $this->getMessage(),
                'expression' => ['patient.identifiers'],
            ]],
        ];
    }
}
