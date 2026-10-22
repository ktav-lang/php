<?php

function traceMutual()
{
    $left = [];
    $right = [];
    $left['right'] = &$right;
    $right['left'] = &$left;
    return $left;
}

function traceAssoc()
{
    $value = [];
    $value['loop'] = &$value;
    return $value;
}

function traceDag()
{
    $shared = ['leaf' => 1];
    $value = [];
    $value['a'] = &$shared;
    $value['b'] = &$shared;
    return $value;
}

function traceAcyclic()
{
    return ['a' => ['leaf' => 1], 'b' => ['leaf' => 2]];
}

function traceEvent($event, $variant, $factory, $depth, $path, &$active, $extra = [])
{
    echo json_encode(array_merge(['event' => $event, 'variant' => $variant, 'factory' => $factory, 'depth' => $depth, 'path' => $path, 'active' => array_map('bin2hex', array_keys($active))], $extra)), "\n";
}

function traceLimit($variant, $factory, $depth, $path, &$active, &$stats)
{
    if ($depth !== 10) {
        return false;
    }
    $stats['limit']++;
    traceEvent('LIMIT', $variant, $factory, $depth, $path, $active);
    return true;
}

function traceEnter($variant, $factory, $depth, $path, &$active, &$stats)
{
    $stats['enter']++;
    traceEvent('ENTER', $variant, $factory, $depth, $path, $active);
}

function traceExit($variant, $factory, $depth, $path, &$active, &$stats)
{
    $stats['exit']++;
    traceEvent('EXIT', $variant, $factory, $depth, $path, $active, ['active_count' => count($active)]);
}

function traceLeaf($variant, $factory, $depth, $path, &$active, &$stats)
{
    $stats['leaf']++;
    traceEvent('LEAF', $variant, $factory, $depth, $path, $active);
}

function traceEdge($variant, $factory, $depth, $path, $key, $id, &$active)
{
    traceEvent('EDGE', $variant, $factory, $depth, $path, $active, ['key' => (string) $key, 'ref' => $id === null ? null : bin2hex($id)]);
}

function traceRepeat($variant, $factory, $depth, $path, $key, $id, &$active, &$stats)
{
    if ($id === null || !isset($active[$id])) {
        return false;
    }
    $stats['repeat']++;
    traceEvent('REPEAT', $variant, $factory, $depth + 1, $path . '/' . (string) $key, $active, ['ref' => bin2hex($id)]);
    return true;
}

function traceRelease($variant, $factory, $depth, $path, $key, $id, &$active)
{
    if ($id !== null) {
        unset($active[$id]);
        traceEvent('RELEASE', $variant, $factory, $depth, $path . '/' . (string) $key, $active, ['ref' => bin2hex($id), 'active_remaining' => count($active)]);
    }
}

function traceWire($v, $factory, $depth, $path, &$active, &$stats)
{
    $variant = 'wire-byvalue-bool';
    if (traceLimit($variant, $factory, $depth, $path, $active, $stats)) return;
    traceEnter($variant, $factory, $depth, $path, $active, $stats);
    try {
        if (!is_array($v)) { traceLeaf($variant, $factory, $depth, $path, $active, $stats); return; }
        foreach ($v as $k => $_) {
            $ref = ReflectionReference::fromArrayElement($v, $k);
            $id = $ref === null ? null : $ref->getId();
            traceEdge($variant, $factory, $depth, $path, $k, $id, $active);
            if (traceRepeat($variant, $factory, $depth, $path, $k, $id, $active, $stats)) continue;
            if ($id !== null) $active[$id] = true;
            try { traceWire($v[$k], $factory, $depth + 1, $path . '/' . (string) $k, $active, $stats); }
            finally { traceRelease($variant, $factory, $depth, $path, $k, $id, $active); }
        }
    } finally { traceExit($variant, $factory, $depth, $path, $active, $stats); }
}

function tracePure($v, $factory, $depth, $path, &$active, &$stats, $anchor = false)
{
    $variant = $anchor ? 'pure-byvalue-anchor' : 'pure-byvalue-bool';
    if (traceLimit($variant, $factory, $depth, $path, $active, $stats)) return;
    traceEnter($variant, $factory, $depth, $path, $active, $stats);
    try {
        if (!is_array($v)) { traceLeaf($variant, $factory, $depth, $path, $active, $stats); return; }
        foreach ($v as $k => $_) tracePureChild($v, $k, $factory, $depth, $path, $active, $stats, $anchor);
    } finally { traceExit($variant, $factory, $depth, $path, $active, $stats); }
}

function tracePureChild(array $container, $k, $factory, $depth, $path, &$active, &$stats, $anchor)
{
    $variant = $anchor ? 'pure-byvalue-anchor' : 'pure-byvalue-bool';
    $ref = ReflectionReference::fromArrayElement($container, $k);
    $id = $ref === null ? null : $ref->getId();
    traceEdge($variant, $factory, $depth, $path, $k, $id, $active);
    if (traceRepeat($variant, $factory, $depth, $path, $k, $id, $active, $stats)) return;
    if ($id !== null) $active[$id] = $anchor ? $ref : true;
    try { tracePure($container[$k], $factory, $depth + 1, $path . '/' . (string) $k, $active, $stats, $anchor); }
    finally { traceRelease($variant, $factory, $depth, $path, $k, $id, $active); }
}

function tracePureRef(&$v, $factory, $depth, $path, &$active, &$stats)
{
    $variant = 'pure-byref-anchor';
    if (traceLimit($variant, $factory, $depth, $path, $active, $stats)) return;
    traceEnter($variant, $factory, $depth, $path, $active, $stats);
    try {
        if (!is_array($v)) { traceLeaf($variant, $factory, $depth, $path, $active, $stats); return; }
        foreach ($v as $k => $_) tracePureRefChild($v, $k, $factory, $depth, $path, $active, $stats);
    } finally { traceExit($variant, $factory, $depth, $path, $active, $stats); }
}

function tracePureRefChild(array &$container, $k, $factory, $depth, $path, &$active, &$stats)
{
    $variant = 'pure-byref-anchor';
    $ref = ReflectionReference::fromArrayElement($container, $k);
    $id = $ref === null ? null : $ref->getId();
    traceEdge($variant, $factory, $depth, $path, $k, $id, $active);
    if (traceRepeat($variant, $factory, $depth, $path, $k, $id, $active, $stats)) return;
    if ($id !== null) $active[$id] = $ref;
    try { tracePureRef($container[$k], $factory, $depth + 1, $path . '/' . (string) $k, $active, $stats); }
    finally { traceRelease($variant, $factory, $depth, $path, $k, $id, $active); }
}

function tracePureEntryPinRef(&$v, $factory, $depth, $path, &$active, &$stats)
{
    $variant = 'pure-entry-pin-anchor';
    if (traceLimit($variant, $factory, $depth, $path, $active, $stats)) return;
    traceEnter($variant, $factory, $depth, $path, $active, $stats);
    try {
        if (!is_array($v)) { traceLeaf($variant, $factory, $depth, $path, $active, $stats); return; }
        foreach ($v as $k => $_) tracePureEntryPinChild($v, $k, $factory, $depth, $path, $active, $stats);
    } finally { traceExit($variant, $factory, $depth, $path, $active, $stats); }
}

function tracePureEntryPinChild(array &$container, $k, $factory, $depth, $path, &$active, &$stats)
{
    $variant = 'pure-entry-pin-anchor';
    $edge =& $container[$k];
    try {
        $ref = ReflectionReference::fromArrayElement($container, $k);
        $id = $ref === null ? null : $ref->getId();
        traceEdge($variant, $factory, $depth, $path, $k, $id, $active);
        if ($id === null) throw new RuntimeException('entry pin did not produce a reference ID');
        if (traceRepeat($variant, $factory, $depth, $path, $k, $id, $active, $stats)) return;
        $active[$id] = $ref;
        try { tracePureEntryPinRef($edge, $factory, $depth + 1, $path . '/' . (string) $k, $active, $stats); }
        finally { traceRelease($variant, $factory, $depth, $path, $k, $id, $active); }
    } finally { unset($edge); }
}

function traceRun($factory, $variant)
{
    $root = call_user_func($factory);
    $active = [];
    $stats = ['enter' => 0, 'exit' => 0, 'leaf' => 0, 'limit' => 0, 'repeat' => 0];
    echo json_encode(['event' => 'BEGIN', 'php' => PHP_VERSION, 'factory' => $factory, 'variant' => $variant, 'max_depth' => 10]), "\n";
    if ($variant === 'pure-entry-pin-anchor') {
        if ($factory === 'traceDag') {
            $beforeA = ReflectionReference::fromArrayElement($root, 'a');
            $beforeB = ReflectionReference::fromArrayElement($root, 'b');
            $beforeLeafA = ReflectionReference::fromArrayElement($root['a'], 'leaf');
            $beforeLeafB = ReflectionReference::fromArrayElement($root['b'], 'leaf');
            if ($beforeA === null || $beforeB === null || $beforeLeafA !== null || $beforeLeafB !== null) throw new RuntimeException('DAG before-observation failed');
            $beforeAId = $beforeA->getId();
            $beforeBId = $beforeB->getId();
            if ($beforeAId !== $beforeBId || $root !== ['a' => ['leaf' => 1], 'b' => ['leaf' => 1]] || array_keys($root) !== ['a', 'b'] || array_keys($root['a']) !== ['leaf'] || array_keys($root['b']) !== ['leaf']) throw new RuntimeException('DAG before-state failed');
        } elseif ($factory === 'traceAcyclic') {
            if (ReflectionReference::fromArrayElement($root, 'a') !== null || ReflectionReference::fromArrayElement($root, 'b') !== null || ReflectionReference::fromArrayElement($root['a'], 'leaf') !== null || ReflectionReference::fromArrayElement($root['b'], 'leaf') !== null || $root !== ['a' => ['leaf' => 1], 'b' => ['leaf' => 2]] || array_keys($root) !== ['a', 'b'] || array_keys($root['a']) !== ['leaf'] || array_keys($root['b']) !== ['leaf']) throw new RuntimeException('acyclic before-state failed');
        }
        tracePureEntryPinRef($root, $factory, 0, '', $active, $stats);
        if (count($active) !== 0 || $stats['enter'] !== $stats['exit']) throw new RuntimeException('entry-pin traversal cleanup failed');
        if ($factory === 'traceDag') {
            $afterA = ReflectionReference::fromArrayElement($root, 'a');
            $afterB = ReflectionReference::fromArrayElement($root, 'b');
            $afterLeafA = ReflectionReference::fromArrayElement($root['a'], 'leaf');
            $afterLeafB = ReflectionReference::fromArrayElement($root['b'], 'leaf');
            if ($afterA === null || $afterB === null || $afterA->getId() !== $beforeAId || $afterB->getId() !== $beforeBId || $afterLeafA !== null || $afterLeafB !== null || $root !== ['a' => ['leaf' => 1], 'b' => ['leaf' => 1]] || array_keys($root) !== ['a', 'b'] || array_keys($root['a']) !== ['leaf'] || array_keys($root['b']) !== ['leaf']) throw new RuntimeException('DAG nonmutation observation failed');
        } elseif ($factory === 'traceAcyclic') {
            if (ReflectionReference::fromArrayElement($root, 'a') !== null || ReflectionReference::fromArrayElement($root, 'b') !== null || ReflectionReference::fromArrayElement($root['a'], 'leaf') !== null || ReflectionReference::fromArrayElement($root['b'], 'leaf') !== null || $root !== ['a' => ['leaf' => 1], 'b' => ['leaf' => 2]] || array_keys($root) !== ['a', 'b'] || array_keys($root['a']) !== ['leaf'] || array_keys($root['b']) !== ['leaf']) throw new RuntimeException('acyclic nonmutation observation failed');
        }
        if ($factory === 'traceDag' || $factory === 'traceAcyclic') traceEvent('NONMUTATION-PASS', $variant, $factory, 0, '', $active);
    }
    elseif ($variant === 'wire-byvalue-bool') traceWire($root, $factory, 0, '', $active, $stats);
    elseif ($variant === 'pure-byvalue-bool') tracePure($root, $factory, 0, '', $active, $stats, false);
    elseif ($variant === 'pure-byvalue-anchor') tracePure($root, $factory, 0, '', $active, $stats, true);
    elseif ($variant === 'pure-byref-anchor') tracePureRef($root, $factory, 0, '', $active, $stats);
    echo json_encode(['event' => 'SUMMARY', 'factory' => $factory, 'variant' => $variant, 'counts' => $stats, 'active_remaining' => count($active)]), "\n";
    unset($root);
}

foreach (['traceMutual', 'traceAssoc', 'traceDag'] as $factory) {
    foreach (['wire-byvalue-bool', 'pure-byvalue-bool', 'pure-byvalue-anchor', 'pure-byref-anchor', 'pure-entry-pin-anchor'] as $variant) traceRun($factory, $variant);
}
traceRun('traceAcyclic', 'pure-entry-pin-anchor');
