<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use DCarbone\PHPFHIRGenerated\Encoding\ResourceParser;
use DCarbone\PHPFHIRGenerated\Versions\R4\Version;

$srcFile = __DIR__ . '/submission-bundle.json';
$srcJson = file_get_contents($srcFile);
$orig    = json_decode($srcJson, true, 512, JSON_THROW_ON_ERROR);

echo "=== PARSE ===\n";
$t0 = microtime(true);
try {
    $resource = ResourceParser::parseJSON(new Version(), $srcJson);
} catch (\Throwable $e) {
    echo "FATAL parse error: " . get_class($e) . ": " . $e->getMessage() . "\n";
    exit(1);
}
printf("parsed OK in %.2fs -> %s\n", microtime(true) - $t0, get_class($resource));

echo "\n=== SERIALIZE ===\n";
try {
    $outJson = json_encode($resource, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    echo "FATAL serialize error: " . get_class($e) . ": " . $e->getMessage() . "\n";
    exit(1);
}
$round = json_decode($outJson, true, 512, JSON_THROW_ON_ERROR);
printf("serialized OK, %d bytes in / %d bytes out\n", strlen($srcJson), strlen($outJson));
file_put_contents(__DIR__ . '/roundtrip-output.json', json_encode($round, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---- recursive structural diff ----
$diffs = [];

function walk(mixed $a, mixed $b, string $path, array &$diffs): void
{
    if (is_array($a) && is_array($b)) {
        $isListA = array_is_list($a);
        $isListB = array_is_list($b);
        if ($isListA !== $isListB) {
            $diffs[] = ['SHAPE', $path, ($isListA ? 'list' : 'object') . ' -> ' . ($isListB ? 'list' : 'object')];
            return;
        }
        if ($isListA) {
            if (count($a) !== count($b)) {
                $diffs[] = ['COUNT', $path, count($a) . ' -> ' . count($b)];
            }
            foreach ($a as $i => $v) {
                if (!array_key_exists($i, $b)) {
                    $diffs[] = ['MISSING', "{$path}[{$i}]", jshort($v)];
                    continue;
                }
                walk($v, $b[$i], "{$path}[{$i}]", $diffs);
            }
            return;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b)) {
                $diffs[] = ['MISSING', "$path.$k", jshort($v)];
                continue;
            }
            walk($v, $b[$k], "$path.$k", $diffs);
        }
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a)) {
                $diffs[] = ['ADDED', "$path.$k", jshort($v)];
            }
        }
        return;
    }
    if ($a !== $b) {
        // tolerate int/float/string drift on numerics, report separately
        if (is_scalar($a) && is_scalar($b) && (string)$a === (string)$b) {
            $diffs[] = ['TYPE', $path, gettype($a) . '(' . var_export($a, true) . ') -> ' . gettype($b) . '(' . var_export($b, true) . ')'];
            return;
        }
        $diffs[] = ['VALUE', $path, jshort($a) . ' -> ' . jshort($b)];
    }
}

function jshort(mixed $v): string
{
    $s = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES);
    $s = (string)$s;
    return strlen($s) > 90 ? substr($s, 0, 90) . '…' : $s;
}

walk($orig, $round, '$', $diffs);

echo "\n=== DIFF SUMMARY ===\n";
if (!$diffs) {
    echo "IDENTICAL — no structural differences.\n";
} else {
    $byKind = [];
    foreach ($diffs as [$kind, , ]) {
        $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
    }
    foreach ($byKind as $k => $n) {
        printf("%-8s %d\n", $k, $n);
    }
    echo "\n--- first 40 ---\n";
    foreach (array_slice($diffs, 0, 40) as [$kind, $path, $detail]) {
        printf("[%-7s] %s\n            %s\n", $kind, $path, $detail);
    }
    if (count($diffs) > 40) {
        printf("\n... and %d more\n", count($diffs) - 40);
    }
}

// ---- targeted checks (the things that decide the gate) ----
echo "\n=== TARGETED CHECKS ===\n";

function countKeysDeep(mixed $node, callable $pred): int
{
    $n = 0;
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            if (is_string($k) && $pred($k, $v)) {
                $n++;
            }
            $n += countKeysDeep($v, $pred);
        }
    }
    return $n;
}

function countStrDeep(mixed $node, callable $pred): int
{
    $n = 0;
    if (is_array($node)) {
        foreach ($node as $v) {
            if (is_string($v) && $pred($v)) {
                $n++;
            }
            $n += countStrDeep($v, $pred);
        }
    } elseif (is_string($node) && $pred($node)) {
        $n++;
    }
    return $n;
}

$checks = [
    'primitive extensions (_field)' => fn($d) => countKeysDeep($d, fn($k, $v) => str_starts_with($k, '_')),
    'urn:uuid references'          => fn($d) => countStrDeep($d, fn($s) => str_starts_with($s, 'urn:uuid:')),
    'meta.profile canonicals'      => fn($d) => countStrDeep($d, fn($s) => str_contains($s, 'fhir.doh.gov.ph')),
    'extension[] entries'          => fn($d) => countKeysDeep($d, fn($k, $v) => $k === 'extension'),
    'PH identifier systems'        => fn($d) => countStrDeep($d, fn($s) => str_contains($s, 'philsys.gov.ph') || str_contains($s, 'philhealth.gov.ph')),
];

printf("%-32s %8s %8s   %s\n", 'check', 'before', 'after', 'verdict');
foreach ($checks as $label => $fn) {
    $a = $fn($orig);
    $b = $fn($round);
    printf("%-32s %8d %8d   %s\n", $label, $a, $b, $a === $b ? 'OK' : '*** LOST ***');
}

// choice types present in source
echo "\n--- choice types in source ---\n";
$choices = [];
array_walk_recursive($orig, function ($v, $k) use (&$choices) {});
function findChoiceKeys(mixed $node, array &$found): void
{
    if (!is_array($node)) return;
    foreach ($node as $k => $v) {
        if (is_string($k) && preg_match('/^(occurrence|value|onset|effective|deceased|multipleBirth|performed|medication|serviced)[A-Z]/', $k)) {
            $found[$k] = ($found[$k] ?? 0) + 1;
        }
        findChoiceKeys($v, $found);
    }
}
$cBefore = [];
$cAfter  = [];
findChoiceKeys($orig, $cBefore);
findChoiceKeys($round, $cAfter);
ksort($cBefore);
ksort($cAfter);
foreach ($cBefore as $k => $n) {
    $m = $cAfter[$k] ?? 0;
    printf("  %-28s %3d -> %3d  %s\n", $k, $n, $m, $n === $m ? 'OK' : '*** LOST ***');
}
foreach ($cAfter as $k => $n) {
    if (!isset($cBefore[$k])) {
        printf("  %-28s %3s -> %3d  *** ADDED ***\n", $k, '0', $n);
    }
}

echo "\n=== GATE ===\n";
$fatal = array_filter($diffs, fn($d) => in_array($d[0], ['MISSING', 'VALUE', 'SHAPE', 'COUNT'], true));
printf("structural diffs: %d (fatal-class: %d)\n", count($diffs), count($fatal));
echo count($fatal) === 0 ? "PASS — safe to build on this package.\n" : "REVIEW — inspect the diffs above before committing.\n";

// ---- independent verification: canonical hash compare ----
function canon(mixed $n): mixed {
    if (!is_array($n)) return $n;
    if (array_is_list($n)) return array_map('canon', $n);
    ksort($n);
    return array_map('canon', $n);
}
$h1 = md5(json_encode(canon($orig)));
$h2 = md5(json_encode(canon($round)));
echo "\n=== INDEPENDENT CHECK (canonical hash) ===\n";
printf("before: %s\nafter : %s\n%s\n", $h1, $h2, $h1 === $h2 ? 'MATCH' : 'MISMATCH');
