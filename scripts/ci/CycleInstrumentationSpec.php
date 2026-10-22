<?php

use Ktav\KtavException;
use Ktav\PureBackend;
use Ktav\Tests\Pure\Corpus;
use Ktav\Ktav;

function cycleDiagnosticFactory()
{
    echo "DIAG factory START\n"; flush();
    $value = []; $value['loop'] = &$value;
    echo "DIAG factory RETURN\n"; flush();
    return $value;
}
function cycleDiagnosticExpect($backend, $value)
{
    echo "DIAG $backend HELPER-ENTRY\n"; flush();
    try {
        Corpus::call($backend, 'dumps', $value);
    } catch (KtavException $e) {
        if ($e->getError() !== 'Message' || $e->getReason() !== null || $e->getSpecSection() !== null || stripos($e->getMessage(), 'cyclic') === false) { throw new RuntimeException('Unexpected instrumented exception'); }
        echo "DIAG $backend EXPECTED-ENVELOPE\n"; flush(); return;
    }
    throw new RuntimeException('Instrumented cycle was accepted');
}

describe('focused cycle instrumentation', function () {
    it('records the original factory-helper-Corpus call chain', function () {
        $backend = getenv('KTAV_CYCLE_BACKEND') ?: 'pure';
        if (!in_array($backend, ['pure', 'native'], true)) { throw new RuntimeException('Invalid diagnostic backend'); }
        if ($backend === 'native') { Corpus::requireNative(); }
        echo "DIAG $backend BEFORE-FACTORY-CALL\n"; flush();
        $value = call_user_func('cycleDiagnosticFactory');
        echo "DIAG $backend AFTER-FACTORY-CALL\n"; flush();
        cycleDiagnosticExpect($backend, $value);
    });
});
