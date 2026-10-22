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
function cycleReferenceId(&$value, $key) { $reference = ReflectionReference::fromArrayElement($value, $key); return $reference === null ? null : bin2hex($reference->getId()); }
function cycleStrictHandlerControls($backend, $operation)
{
    $class = $backend === 'native' ? Ktav::class : PureBackend::class; $deliveries = []; $sentinel = 'restored-' . $backend . '-' . $operation;
    $previous = static function ($severity, $message) use (&$deliveries, $sentinel) { $deliveries[] = [$severity, $message]; if ($severity === E_USER_WARNING && $message === $sentinel) { return true; } throw new RuntimeException('Unexpected writer warning or handler delivery: ' . $severity . '/' . $message); };
    set_error_handler($previous, E_ALL);
    try {
        cycleMarker("CASE $backend CONTROL-handler-success-$operation BEGIN");
        $output = call_user_func([$class, $operation], ['ok' => 1]);
        $expected = $operation === 'dumpsForceStrings' ? ['ok' => '1'] : ['ok' => 1];
        if (call_user_func([$class, 'loads'], $output) !== $expected) { throw new RuntimeException('Handler success output mismatch'); }
        $probe = static function () {}; $observed = set_error_handler($probe, E_ALL); restore_error_handler();
        if ($observed !== $previous || count($deliveries) !== 0) { throw new RuntimeException('Handler not restored after success or warning leaked'); }
        trigger_error($sentinel, E_USER_WARNING);
        if (count($deliveries) !== 1 || $deliveries[0] !== [E_USER_WARNING, $sentinel]) { throw new RuntimeException('Restored handler did not receive success sentinel'); }
        cycleMarker("CASE $backend CONTROL-handler-success-$operation PASS");
        $deliveries = []; cycleMarker("CASE $backend CONTROL-handler-cycle-$operation BEGIN");
        cycleAssertExpected($backend, 'mutualCycle', $operation, cycleFactory('mutualCycle'));
        $observed = set_error_handler($probe, E_ALL); restore_error_handler();
        if ($observed !== $previous || count($deliveries) !== 0) { throw new RuntimeException('Handler not restored after cycle or warning leaked'); }
        trigger_error($sentinel, E_USER_WARNING);
        if (count($deliveries) !== 1 || $deliveries[0] !== [E_USER_WARNING, $sentinel]) { throw new RuntimeException('Restored handler did not receive cycle sentinel'); }
        cycleMarker("CASE $backend CONTROL-handler-cycle-$operation PASS");
    } finally { restore_error_handler(); }
}
function cycleAliasControl($backend, $operation)
{
    $class = $backend === 'native' ? Ktav::class : PureBackend::class; $shared = ['z' => 1, 'a' => [2]]; $alias = &$shared; $root = []; $root['second'] = &$shared; $root['first'] = &$shared;
    $ids = [cycleReferenceId($root, 'second'), cycleReferenceId($root, 'first')]; $nestedIds = [cycleReferenceId($shared, 'a'), cycleReferenceId($shared['a'], 0)];
    if ($nestedIds !== [null, null]) { throw new RuntimeException('Unexpected nested reference'); }
    $beforeRoot = array_keys($root); $expected = $operation === 'dumpsForceStrings' ? ['second' => ['z' => '1', 'a' => ['2']], 'first' => ['z' => '1', 'a' => ['2']]] : ['second' => ['z' => 1, 'a' => [2]], 'first' => ['z' => 1, 'a' => [2]]];
    cycleMarker("CASE $backend CONTROL-caller-alias-$operation BEGIN"); $text = call_user_func([$class, $operation], $root);
    if (call_user_func([$class, 'loads'], $text) !== $expected || $shared !== ['z' => 1, 'a' => [2]] || $alias !== ['z' => 1, 'a' => [2]] || array_keys($root) !== $beforeRoot || $root['second'] !== ['z' => 1, 'a' => [2]] || $root['first'] !== ['z' => 1, 'a' => [2]] || [cycleReferenceId($root, 'second'), cycleReferenceId($root, 'first')] !== $ids || [cycleReferenceId($shared, 'a'), cycleReferenceId($shared['a'], 0)] !== $nestedIds) { throw new RuntimeException('Caller alias graph changed'); }
    $alias['z'] = 9;
    if ($root['second']['z'] !== 9 || $root['first']['z'] !== 9) { throw new RuntimeException('Caller aliases no longer linked'); }
    cycleMarker("CASE $backend CONTROL-caller-alias-$operation PASS");
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
    cycleAliasControl($backend, $operation); $controls++;
    cycleStrictHandlerControls($backend, $operation); $controls += 2;
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
