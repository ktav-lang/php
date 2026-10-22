<?php

describe('Value cycle writer boundaries', function () {
    foreach (\Ktav\Tests\Pure\Corpus::backends() as $backend) {
        it('isolates bounded cycle diagnostics for ' . $backend, function () use ($backend) {
            $library = null;
            if ($backend === 'native') {
                \Ktav\Tests\Pure\Corpus::requireNative();
                $library = \Ktav\Tests\TestPaths::cabi();
            }
            \Ktav\Tests\CycleChildProcess::run($backend, $library);
        });
    }
});
