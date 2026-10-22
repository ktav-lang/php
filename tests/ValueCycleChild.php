<?php

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\Pure\PureList;
use Ktav\Pure\PureObject;
use Ktav\PureBackend;

require __DIR__ . '/../vendor/autoload.php';

function cycleMarker($text) { echo $text, "\n"; flush(); }
function cycleFactory($name)
{
    if ($name === 'assocCycle') { $value = []; $value['loop'] = &$value; return $value; }
    if ($name === 'listCycle') { $value = [1]; $value[] = &$value; return $value; }
    if ($name === 'mutualCycle') { $left = []; $right = []; $left['right'] = &$right; $right['left'] = &$left; return $left; }
    if ($name === 'nestedRootCycle') { $value = []; $root = &$value; $value = ['x' => ['y' => &$root]]; return $value; }
    throw new RuntimeException('Unknown factory: ' . $name);
}
function cycleAssertExpected($backend, $factory, $operation, $value)
{
    try {
        cycleMarker("CASE $backend $factory $operation PUBLIC-CALL");
        $class = $backend === 'native' ? Ktav::class : PureBackend::class;
        call_user_func([$class, $operation], $value);
    } catch (KtavException $e) {
        if ($e->getError() !== 'Message' || $e->getReason() !== null || $e->getSpecSection() !== null || stripos($e->getMessage(), 'cyclic') === false) { throw new RuntimeException('Unexpected cycle error envelope for ' . $backend . '/' . $factory . '/' . $operation); }
        cycleMarker("CASE $backend $factory $operation PASS"); return;
    }
    throw new RuntimeException('Cycle was accepted: ' . $backend . '/' . $factory . '/' . $operation);
}
function cycleControl($backend, $name, $operation, $value, $expected)
{
    cycleMarker("CASE $backend CONTROL-$name $operation BEGIN");
    $class = $backend === 'native' ? Ktav::class : PureBackend::class;
    $text = call_user_func([$class, $operation], $value);
    if (call_user_func([$class, 'loads'], $text) !== $expected) { throw new RuntimeException('Control mismatch: ' . $backend . '/' . $name . '/' . $operation); }
    cycleMarker("CASE $backend CONTROL-$name $operation PASS");
}
$backend = isset($argv[1]) ? $argv[1] : '';
if (!in_array($backend, ['pure', 'native'], true)) { fwrite(STDERR, "backend must be pure or native\n"); exit(2); }
if ($backend === 'native') {
    $lib = getenv('KTAV_LIB_PATH');
    if ($lib === false || $lib === '' || !is_file($lib)) { fwrite(STDERR, "KTAV_LIB_PATH must name an existing native artifact\n"); exit(2); }
    \Ktav\NativeLoader::setLibraryPath($lib);
    if (Ktav::nativeVersion() !== '0.8.1') { throw new RuntimeException('nativeVersion is not 0.8.1'); }
}
$factories = ['assocCycle', 'listCycle', 'mutualCycle', 'nestedRootCycle']; $operations = ['dumps', 'emitCanonical', 'dumpsForceStrings'];
foreach ($factories as $factory) foreach ($operations as $operation) {
    cycleMarker("CASE $backend $factory $operation BEGIN"); $value = cycleFactory($factory); cycleMarker("CASE $backend $factory $operation FACTORY-BUILT");
    cycleAssertExpected($backend, $factory, $operation, $value); unset($value);
}
$controls = 0;
foreach ($operations as $operation) {
    $shared = ['k' => 1]; $dag = []; $dag['a'] = &$shared; $dag['b'] = &$shared;
    $expected = $operation === 'dumpsForceStrings' ? ['a' => ['k' => '1'], 'b' => ['k' => '1']] : ['a' => ['k' => 1], 'b' => ['k' => 1]];
    cycleControl($backend, 'shared-dag', $operation, $dag, $expected); unset($dag, $shared); $controls++;
    $equal = ['a' => ['n' => 1], 'b' => ['n' => 1]]; $expected = $operation === 'dumpsForceStrings' ? ['a' => ['n' => '1'], 'b' => ['n' => '1']] : $equal;
    cycleControl($backend, 'equal-independent', $operation, $equal, $expected); $controls++;
}
if ($backend === 'pure') {
    foreach ([new PureObject(), new PureList()] as $index => $node) {
        if ($node instanceof PureObject) { $node->pairs['self'] = $node; } else { $node->items[] = $node; }
        foreach ($operations as $operation) { cycleMarker("CASE pure CONTROL-internal-" . ($index === 0 ? 'object' : 'list') . " $operation BEGIN"); cycleAssertExpected('pure', 'internalCycle', $operation, ['k' => $node]); cycleMarker("CASE pure CONTROL-internal-" . ($index === 0 ? 'object' : 'list') . " $operation PASS"); $controls++; } unset($node);
    }
    $left = new PureObject(); $right = new PureObject(); $left->pairs['right'] = $right; $right->pairs['left'] = $left;
    foreach ($operations as $operation) { cycleMarker("CASE pure CONTROL-internal-mutual $operation BEGIN"); cycleAssertExpected('pure', 'internalMutualCycle', $operation, ['k' => $left]); cycleMarker("CASE pure CONTROL-internal-mutual $operation PASS"); $controls++; } unset($left, $right);
    $shared = new PureObject(); $shared->pairs['n'] = 1; $root = ['a' => $shared, 'b' => $shared]; cycleMarker("CASE pure CONTROL-internal-shared dumps BEGIN");
    if (PureBackend::loads(PureBackend::dumps($root)) !== ['a' => ['n' => 1], 'b' => ['n' => 1]]) { throw new RuntimeException('Internal shared graph mismatch'); } cycleMarker("CASE pure CONTROL-internal-shared dumps PASS"); $controls++; unset($root, $shared);
    foreach ([['v' => ['$i' => '5']], ['v' => ['$f' => '1.5']]] as $index => $marker) { $name = $index === 0 ? 'marker-i' : 'marker-f'; cycleMarker("CASE pure CONTROL-$name dumps BEGIN"); $want = isset($marker['v']['$i']) ? ['v' => 5] : ['v' => 1.5]; if (PureBackend::loads(PureBackend::dumps($marker)) !== $want) { throw new RuntimeException('Marker control mismatch'); } cycleMarker("CASE pure CONTROL-$name dumps PASS"); $controls++; }
    cycleMarker("CASE pure CONTROL-empty-root dumps BEGIN"); if (PureBackend::dumps([]) !== '') { throw new RuntimeException('Root empty control mismatch'); } cycleMarker("CASE pure CONTROL-empty-root dumps PASS"); $controls++;
    cycleMarker("CASE pure CONTROL-empty-root emitCanonical BEGIN"); if (PureBackend::emitCanonical([]) !== '') { throw new RuntimeException('Canonical empty control mismatch'); } cycleMarker("CASE pure CONTROL-empty-root emitCanonical PASS"); $controls++;
    cycleMarker("CASE pure CONTROL-empty-nested dumps BEGIN"); if (PureBackend::loads(PureBackend::dumps(['v' => []])) !== ['v' => []]) { throw new RuntimeException('Nested empty control mismatch'); } cycleMarker("CASE pure CONTROL-empty-nested dumps PASS"); $controls++;
    if (class_exists(Ktav::class, false) || class_exists(\Ktav\NativeLoader::class, false) || class_exists(\Ktav\NativeLib::class, false)) { throw new RuntimeException('Pure run loaded native classes'); }
}
echo "COMPLETE backend=$backend cycles=12 controls=$controls\n";
