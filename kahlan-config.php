<?php

// Kahlan looks for `spec/` by default, but in this repo that path is
// owned by the upstream `ktav-lang/spec` submodule (the conformance
// fixtures source). Point Kahlan at `tests/` instead.
$commandLine = $this->commandLine();
$commandLine->option('spec', 'default', 'tests');
$commandLine->option('reporter', 'default', 'verbose');

// Env-driven spec-DIR include list (comma-separated), used by CI to
// select only the pure-backend suites (`KTAV_KAHLAN_SPECS=tests/Pure`)
// without hardcoding the path into every job. An explicit `--spec=`
// CLI flag still wins over this default.
$envSpecs = getenv('KTAV_KAHLAN_SPECS');
if (is_string($envSpecs) && $envSpecs !== '') {
    $commandLine->option('spec', 'default', $envSpecs);
}
