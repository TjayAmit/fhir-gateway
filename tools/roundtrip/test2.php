<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use DCarbone\PHPFHIRGenerated\Encoding\ResourceParser;
use DCarbone\PHPFHIRGenerated\Versions\R4\Version;

$src  = file_get_contents(__DIR__ . '/synthetic.json');
$orig = json_decode($src, true, 512, JSON_THROW_ON_ERROR);

$res  = ResourceParser::parseJSON(new Version(), $src);
$out  = json_encode($res, JSON_UNESCAPED_SLASHES);
$round= json_decode($out, true, 512, JSON_THROW_ON_ERROR);

file_put_contents(__DIR__.'/synthetic-output.json', json_encode($round, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

function canon(mixed $n): mixed {
    if (!is_array($n)) return $n;
    if (array_is_list($n)) return array_map('canon', $n);
    ksort($n); return array_map('canon', $n);
}
$match = json_encode(canon($orig)) === json_encode(canon($round));
echo "parsed:     ".get_class($res)."\n";
echo "canonical:  ".($match ? "MATCH" : "MISMATCH")."\n\n";

echo "=== primitive extension survival ===\n";
foreach (['_gender','_birthDate'] as $k) {
    $b = isset($orig[$k]) ? 'present' : 'absent';
    $a = isset($round[$k]) ? 'present' : 'absent';
    printf("  %-12s before=%-8s after=%-8s %s\n", $k, $b, $a, $b===$a ? 'OK' : '*** LOST ***');
    if (isset($orig[$k]) && !isset($round[$k])) {
        echo "      lost value: ".json_encode($orig[$k])."\n";
    }
}

echo "\n=== other checks ===\n";
$checks = [
  'meta.profile'          => fn($d) => $d['meta']['profile'][0] ?? null,
  'PH extension count'    => fn($d) => count($d['extension'] ?? []),
  'identifier count'      => fn($d) => count($d['identifier'] ?? []),
  'address extension'     => fn($d) => $d['address'][0]['extension'][0]['url'] ?? null,
  'deceasedBoolean'       => fn($d) => $d['deceasedBoolean'] ?? '(absent)',
  'multipleBirthInteger'  => fn($d) => $d['multipleBirthInteger'] ?? '(absent)',
];
foreach ($checks as $label => $fn) {
  $a = $fn($orig); $b = $fn($round);
  printf("  %-22s %s\n      before: %s\n      after : %s\n", $label, var_export($a,true)===var_export($b,true)?'OK':'*** DIFF ***', var_export($a,true), var_export($b,true));
}

if (!$match) {
  echo "\n=== keys after ===\n";
  echo implode(", ", array_keys($round))."\n";
}
