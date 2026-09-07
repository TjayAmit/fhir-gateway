<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use DCarbone\PHPFHIRGenerated\Encoding\ResourceParser;
use DCarbone\PHPFHIRGenerated\Versions\R4\Version;
$src=file_get_contents(__DIR__.'/synthetic.json');
$orig=json_decode($src,true);
$round=json_decode(json_encode(ResourceParser::parseJSON(new Version(),$src),JSON_UNESCAPED_SLASHES),true);
$d=[];
function w($a,$b,$p,&$d){
 if(is_array($a)&&is_array($b)){
  if(array_is_list($a)!==array_is_list($b)){$d[]="SHAPE  $p";return;}
  if(array_is_list($a)){
   if(count($a)!==count($b))$d[]="COUNT  $p ".count($a)."->".count($b);
   foreach($a as $i=>$v){ if(!array_key_exists($i,$b)){$d[]="MISSING {$p}[{$i}]";continue;} w($v,$b[$i],"{$p}[{$i}]",$d);}
   return;}
  foreach($a as $k=>$v){ if(!array_key_exists($k,$b)){$d[]="MISSING {$p}.{$k} = ".json_encode($v);continue;} w($v,$b[$k],"{$p}.{$k}",$d);}
  foreach($b as $k=>$v){ if(!array_key_exists($k,$a))$d[]="ADDED   {$p}.{$k} = ".json_encode($v);}
  return;}
 if($a!==$b)$d[]="VALUE  $p : ".json_encode($a)." -> ".json_encode($b)." (".gettype($a)."->".gettype($b).")";
}
w($orig,$round,'$',$d);
echo $d ? implode("\n",$d)."\n" : "identical\n";
echo "\n--- key ORDER (before) ---\n".implode(", ",array_keys($orig))."\n";
echo "--- key ORDER (after)  ---\n".implode(", ",array_keys($round))."\n";
