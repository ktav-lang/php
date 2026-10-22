<?php

use Ktav\KtavException;
use Ktav\Pure\PureList;
use Ktav\Pure\PureObject;
use Ktav\PureBackend;
use Ktav\Tests\Pure\Corpus;

function expectCycleFailure($backend, $value, $operation)
{
    $threw = false;
    try {
        Corpus::call($backend, $operation, $value);
    } catch (KtavException $e) {
        $threw = true;
        expect($e->getError())->toBe('Message');
        expect($e->getReason())->toBe(null);
        expect(stripos($e->getMessage(), 'cyclic'))->not->toBe(false);
    }
    expect($threw)->toBe(true);
}

function assocCycle()
{
    $value = [];
    $value['loop'] = &$value;
    return $value;
}

function listCycle()
{
    $value = [1];
    $value[] = &$value;
    return $value;
}

function mutualCycle()
{
    $left = [];
    $right = [];
    $left['right'] = &$right;
    $right['left'] = &$left;
    return $left;
}

function nestedRootCycle()
{
    $value = [];
    $root = &$value;
    $value = ['x' => ['y' => &$root]];
    return $value;
}

describe('Value cycle writer boundaries', function () {
    it('rejects host reference cycles in every selected backend and writer mode', function () {
        $factories = ['assocCycle', 'listCycle', 'mutualCycle', 'nestedRootCycle'];
        foreach (Corpus::backends() as $backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            foreach ($factories as $factory) {
                foreach (['dumps', 'emitCanonical', 'dumpsForceStrings'] as $operation) {
                    expectCycleFailure($backend, call_user_func($factory), $operation);
                }
            }
        }
    });

    it('preserves shared-reference DAGs and independent equal arrays in every writer mode', function () {
        foreach (Corpus::backends() as $backend) {
            if ($backend === 'native') {
                Corpus::requireNative();
            }
            foreach (['dumps', 'emitCanonical', 'dumpsForceStrings'] as $operation) {
                $shared = ['k' => 1];
                $dag = [];
                $dag['a'] = &$shared;
                $dag['b'] = &$shared;
                $expectedDag = $operation === 'dumpsForceStrings'
                    ? ['a' => ['k' => '1'], 'b' => ['k' => '1']]
                    : ['a' => ['k' => 1], 'b' => ['k' => 1]];
                $text = Corpus::call($backend, $operation, $dag);
                expect(Corpus::equals(Corpus::call($backend, 'loads', $text), $expectedDag))->toBe(true);

                $equal = ['a' => ['n' => 1], 'b' => ['n' => 1]];
                $expectedEqual = $operation === 'dumpsForceStrings'
                    ? ['a' => ['n' => '1'], 'b' => ['n' => '1']]
                    : $equal;
                $text = Corpus::call($backend, $operation, $equal);
                expect(Corpus::equals(Corpus::call($backend, 'loads', $text), $expectedEqual))->toBe(true);
            }
        }
    });

    it('rejects mutable internal cycles with public array roots and accepts shared internal graphs', function () {
        $object = new PureObject();
        $object->pairs['self'] = $object;
        $list = new PureList();
        $list->items[] = $list;
        foreach ([$object, $list] as $value) {
            foreach (['dumps', 'emitCanonical', 'dumpsForceStrings'] as $operation) {
                expectCycleFailure('pure', ['k' => $value], $operation);
            }
        }

        $shared = new PureObject();
        $shared->pairs['n'] = 1;
        $root = ['a' => $shared, 'b' => $shared];
        $text = PureBackend::dumps($root);
        expect(PureBackend::loads($text))->toBe(['a' => ['n' => 1], 'b' => ['n' => 1]]);
    });

    it('preserves markers and empty root/nested array conventions', function () {
        expect(PureBackend::loads(PureBackend::dumps(['v' => ['$i' => '5']])))->toBe(['v' => 5]);
        expect(PureBackend::loads(PureBackend::dumps(['v' => ['$f' => '1.5']])))->toBe(['v' => 1.5]);
        expect(PureBackend::dumps([]))->toBe('');
        expect(PureBackend::emitCanonical([]))->toBe('');
        expect(PureBackend::loads(PureBackend::dumps(['v' => []])))->toBe(['v' => []]);
    });
});
