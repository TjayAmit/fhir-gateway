<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Validates a FHIR resource against the PH Core / eReferral IGs using the
 * containerised HL7 validator (docker compose up -d validator).
 *
 * This is the only thing in the stack that knows PH Core exists — see decision D4.
 * The PHP layer will silently drop unknown fields without complaint, so a green run
 * here is the only evidence that emitted resources are conformant.
 */
class FhirValidate extends Command
{
    protected $signature = 'fhir:validate
        {file* : One or more JSON files to validate}
        {--profile=* : Canonical profile URL(s) to validate against}
        {--ig=* : IG tarball paths as seen by the container (default: both PH packages)}
        {--sv=4.0.1 : FHIR version}
        {--tx= : Terminology server URL (default: disabled)}
        {--warnings : Exit non-zero on warnings as well as errors}';

    protected $description = 'Validate FHIR resources against the PH Core and eReferral IGs';

    /** Default IGs, by their path inside the validator container (see docker-compose.yml). */
    private const DEFAULT_IGS = ['/igs/ph-core.tgz', '/igs/ph-ereferral.tgz'];

    public function handle(): int
    {
        $url = rtrim((string) config('fhir.validator_url'), '/');

        $files = [];
        foreach ($this->argument('file') as $path) {
            if (! is_file($path)) {
                $this->error("Not found: {$path}");

                return self::FAILURE;
            }
            $files[] = [
                'fileName' => basename($path),
                'fileContent' => file_get_contents($path),
                'fileType' => 'json',
            ];
        }

        $igs = $this->option('ig') ?: self::DEFAULT_IGS;

        $payload = [
            'cliContext' => [
                'sv' => $this->option('sv'),
                'igs' => array_values($igs),
                'profiles' => array_values($this->option('profile')),
                // Null disables terminology lookups. PH Core's PSGC/PSOC/PSCED are
                // mock code systems and cannot be expanded, so this is the default.
                'txServer' => $this->option('tx') ?: null,
            ],
            'filesToValidate' => $files,
        ];

        $this->line("validator: {$url}");
        $this->line('igs:       '.implode(', ', $igs));
        if ($profiles = $this->option('profile')) {
            $this->line('profiles:  '.implode(', ', $profiles));
        }
        $this->newLine();

        try {
            $response = Http::timeout(600)
                ->acceptJson()
                ->asJson()
                ->post("{$url}/validate", $payload);
        } catch (\Throwable $e) {
            $this->error('Could not reach the validator: '.$e->getMessage());
            $this->line('Is it running?  docker compose up -d validator');

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Validator returned HTTP {$response->status()}");
            $this->line(substr($response->body(), 0, 1000));

            return self::FAILURE;
        }

        $errors = 0;
        $warnings = 0;

        foreach ($response->json('outcomes') ?? [] as $outcome) {
            $name = $outcome['fileInfo']['fileName'] ?? '(unknown)';
            $issues = $outcome['issues'] ?? [];

            $this->line("<options=bold>{$name}</> — ".count($issues).' issue(s)');

            foreach ($issues as $issue) {
                $level = strtoupper((string) ($issue['level'] ?? 'INFO'));
                $location = $issue['location'] ?? '';
                $message = $issue['message'] ?? '';

                match ($level) {
                    'ERROR', 'FATAL' => $errors++,
                    'WARNING' => $warnings++,
                    default => null,
                };

                $tag = match ($level) {
                    'ERROR', 'FATAL' => 'error',
                    'WARNING' => 'comment',
                    default => 'info',
                };

                $this->line("  <{$tag}>[{$level}]</{$tag}> {$location}");
                $this->line("      {$message}");
            }
            $this->newLine();
        }

        $this->line("errors: {$errors}   warnings: {$warnings}");

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($warnings > 0 && $this->option('warnings')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
