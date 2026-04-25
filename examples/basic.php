<?php

declare(strict_types=1);

// End-to-end demo: parse a Ktav document, pull out typed fields,
// then build a fresh document in PHP and render it back to Ktav text.
//
// Run from the repo root:
//
//   cargo build --release -p ktav-cabi
//   KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so" \
//       php examples/basic.php

require __DIR__ . '/../vendor/autoload.php';

use Ktav\Ktav;

$src = <<<KTAV
service: web
port:i 8080
ratio:f 0.75
tls: true
tags: [
    prod
    eu-west-1
]
db.host: primary.internal
db.timeout:i 30
KTAV;

// ── 1. Parse — typed reads off the parsed array. ─────────────────────
$cfg = Ktav::loads($src);

printf("service=%s port=%d tls=%s ratio=%.2f\n",
    $cfg['service'], $cfg['port'], $cfg['tls'] ? 'true' : 'false', $cfg['ratio']);
printf("tags=[%s]\n", implode(', ', $cfg['tags']));
printf("db: %s (timeout=%ds)\n\n",
    $cfg['db']['host'], $cfg['db']['timeout']);

// ── 2. Build & render — construct a doc in code. ─────────────────────
$doc = [
    'name'  => 'frontend',
    'port'  => 8443,
    'tls'   => true,
    'ratio' => 0.95,
    'upstreams' => [
        ['host' => 'a.example', 'port' => 1080],
        ['host' => 'b.example', 'port' => 1080],
        ['host' => 'c.example', 'port' => 1080],
    ],
    'notes' => null,
];

echo "--- rendered ---\n";
echo Ktav::dumps($doc);

echo "\nktav_cabi version: " . Ktav::nativeVersion() . "\n";
