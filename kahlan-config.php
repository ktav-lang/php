<?php

// Kahlan looks for `spec/` by default, but in this repo that path is
// owned by the upstream `ktav-lang/spec` submodule (the conformance
// fixtures source). Point Kahlan at `tests/` instead.
$commandLine = $this->commandLine();
$commandLine->option('spec', 'default', 'tests');
$commandLine->option('reporter', 'default', 'verbose');
