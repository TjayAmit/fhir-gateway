<?php

declare(strict_types=1);

namespace App\Fhir\Support;

/**
 * Small constructors for the FHIR datatypes the builders reach for constantly.
 *
 * Resources are built as plain arrays rather than php-fhir objects. The vendored library
 * cannot express a profile, a slice or a binding, so it would add ceremony without adding
 * safety — the HL7 validator is what actually proves conformance (decision D4). php-fhir
 * still earns its place parsing what we emit back into typed R4 objects in the tests.
 */
final class Element
{
    /**
     * @return array<string, mixed>
     */
    public static function coding(string $system, string $code, ?string $display = null): array
    {
        return self::prune([
            'system' => $system,
            'code' => $code,
            'display' => $display,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $codings
     * @return array<string, mixed>
     */
    public static function concept(array $codings, ?string $text = null): array
    {
        return self::prune([
            'coding' => $codings,
            'text' => $text,
        ]);
    }

    /**
     * A CodeableConcept carrying exactly one coding — by far the common case.
     *
     * @return array<string, mixed>
     */
    public static function conceptOf(string $system, string $code, ?string $display = null, ?string $text = null): array
    {
        return self::concept([self::coding($system, $code, $display)], $text);
    }

    /**
     * @return array<string, mixed>
     */
    public static function reference(string $reference, ?string $display = null): array
    {
        return self::prune([
            'reference' => $reference,
            'display' => $display,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function identifier(string $system, string $value, ?string $use = null): array
    {
        return self::prune([
            'use' => $use,
            'system' => $system,
            'value' => $value,
        ]);
    }

    /**
     * @param  array<int, string|null>  $given
     * @return array<string, mixed>
     */
    public static function humanName(string $family, array $given, ?string $suffix = null, string $use = 'official'): array
    {
        return self::prune([
            'use' => $use,
            'family' => $family,
            'given' => array_values(array_filter($given, static fn ($g) => $g !== null && $g !== '')),
            'suffix' => $suffix !== null && $suffix !== '' ? [$suffix] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function contactPoint(string $system, string $value, ?string $use = null): array
    {
        return self::prune([
            'system' => $system,
            'value' => $value,
            'use' => $use,
        ]);
    }

    /**
     * Strip nulls and empty arrays, recursively.
     *
     * FHIR has no concept of a null-valued element: emitting one is a validation error, not a
     * shrug. Builders therefore assemble the full shape with optional slots left null and let
     * this remove them, which keeps the builders flat and readable.
     *
     * Zero, "0" and false are values and survive. Only null and [] are dropped.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function prune(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::prune($item);
            }

            if ($item === null || $item === [] || $item === '') {
                continue;
            }

            $out[$key] = $item;
        }

        // Re-index anything that was a list before pruning punched holes in it.
        return array_is_list($value) ? array_values($out) : $out;
    }

    private function __construct() {}
}
