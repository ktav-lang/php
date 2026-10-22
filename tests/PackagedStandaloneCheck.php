<?php

declare(strict_types=1);

use Ktav\Ktav;
use Ktav\KtavException;
use Ktav\NativeLib;
use Ktav\NativeLoader;
use Ktav\PureBackend;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$consumer = getenv('CONSUMER') ?: ($argv[1] ?? '');
check($consumer !== '' && is_dir($consumer), 'CONSUMER must name the installed consumer directory');
$root = realpath($consumer);
check($root !== false && !is_link($consumer), 'consumer must be a real directory, not a symlink');
$autoload = $root . DIRECTORY_SEPARATOR . 'vendor/autoload.php';
$packageRoot = $root . DIRECTORY_SEPARATOR . 'vendor/ktav-lang/ktav';
check(is_file($autoload), 'Composer autoloader is missing');
check(is_dir($packageRoot) && !is_link($packageRoot), 'installed package must be a real copied directory');

$metadataPath = $packageRoot . DIRECTORY_SEPARATOR . 'composer.json';
check(is_file($metadataPath), 'installed package composer.json is missing');
$metadata = json_decode((string) file_get_contents($metadataPath), true);
check(is_array($metadata), 'installed package composer.json is invalid');
$requirements = array_keys($metadata['require'] ?? []);
sort($requirements, SORT_STRING);
check($requirements === ['ext-json', 'php'], 'package runtime requirements must be exactly ext-json and php');
check(isset($metadata['suggest']['ext-ffi']) && !isset($metadata['require']['ext-ffi']), 'FFI must remain optional');
check(empty($metadata['autoload']['files']), 'package must not eagerly include files from autoload.files');

$installedPath = $root . DIRECTORY_SEPARATOR . 'vendor/composer/installed.json';
check(is_file($installedPath), 'Composer installed package metadata is missing');
$installed = json_decode((string) file_get_contents($installedPath), true);
$packages = is_array($installed) && isset($installed['packages']) ? $installed['packages'] : $installed;
check(is_array($packages), 'Composer installed package metadata is invalid');
$names = [];
foreach ($packages as $package) {
    check(is_array($package) && isset($package['name']) && is_string($package['name']), 'Composer package entry is malformed');
    $names[] = $package['name'];
}
sort($names, SORT_STRING);
check($names === ['ktav-lang/ktav'], 'consumer must install only ktav-lang/ktav as a package');

$nativeFiles = [
    'target/release/libktav_cabi.so', 'target/debug/libktav_cabi.so',
    'target/release/libktav_cabi.dylib', 'target/debug/libktav_cabi.dylib',
    'target/release/ktav_cabi.dll', 'target/debug/ktav_cabi.dll',
    'libktav_cabi.so', 'libktav_cabi.dylib', 'ktav_cabi.dll',
];
foreach ($nativeFiles as $nativeFile) {
    check(!file_exists($packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $nativeFile)), 'installed package unexpectedly bundles a native artifact');
}

$nativePath = getenv('KTAV_LIB_PATH');
check($nativePath !== false && $nativePath !== '', 'KTAV_LIB_PATH must name an absent native library');
check(!file_exists($nativePath), 'KTAV_LIB_PATH unexpectedly exists');

require $autoload;
check(extension_loaded('json'), 'ext-json must be loaded');
check(!extension_loaded('ffi'), 'FFI must be absent');
check(!extension_loaded('mbstring'), 'mbstring must not be required or present');
check(PureBackend::isAvailable() && PureBackend::version() === 'pure-0.8.1', 'pure version/availability mismatch');
check(PureBackend::loads("ключ: value\ncount: 7\n") === ['ключ' => 'value', 'count' => 7], 'UTF-8 key parse failed');
check(PureBackend::loadsStrict("count: 7\n") === ['count' => 7], 'strict canonical number failed');

$value = ['name' => 'demo', 'count' => 7];
check(PureBackend::dumps($value) === "name: demo\ncount: 7\n", 'dumps output mismatch');
check(PureBackend::emitCanonical($value) === "name: demo\ncount: 7\n", 'canonical output mismatch');
check(PureBackend::dumps(['ratio' => 1.25]) === "ratio: 1.25\n", 'standalone float writer failed');
check(PureBackend::loads("ratio: 1.25\n") === ['ratio' => 1.25], 'standalone float parser failed');
$forceText = PureBackend::dumpsForceStrings($value);
check(PureBackend::loads($forceText) === ['name' => 'demo', 'count' => '7'], 'force-string values did not round-trip as strings');
check(strpos($forceText, '::') !== false, 'force-string output omitted the raw marker');
check(PureBackend::format("## keep\na: 1\n\n\n## end\n") === "## keep\na: 1\n\n## end\n", 'formatter did not preserve comments/collapse blank runs');
check(PureBackend::canonicalFromSource("empty: {}\nlist: []\n") === "empty: {}\nlist: []\n", 'canonical source empty compound identity mismatch');

foreach (["bad: \xFF\n", "version: 1.10\n"] as $index => $source) {
    try {
        if ($index === 0) {
            PureBackend::loads($source);
        } else {
            PureBackend::loadsStrict($source);
        }
        throw new RuntimeException('invalid UTF-8 or strict-lossy input unexpectedly succeeded');
    } catch (KtavException $exception) {
        $expectedError = $index === 0 ? 'InvalidUtf8' : 'LossyScalar';
        check($exception->getError() === $expectedError, 'unexpected parse error category');
        check($exception->getReason() === null, 'unexpected parse error reason');
        if ($index === 1) {
            check($exception->getBody() === '1.10', 'strict-lossy body mismatch');
            check($exception->getCanonical() === '1.1', 'strict-lossy canonical mismatch');
        }
    }
}
$cycle = [];
$cycle['loop'] = &$cycle;
try {
    PureBackend::dumps($cycle);
    throw new RuntimeException('cyclic host value unexpectedly succeeded');
} catch (KtavException $exception) {
    check($exception->getError() === 'Message' && $exception->getReason() === null, 'cycle error envelope mismatch');
    check(stripos($exception->getMessage(), 'cyclic') !== false, 'cycle error message is not meaningful');
}
check(!class_exists(NativeLib::class, false) && !class_exists(NativeLoader::class, false) && !class_exists(Ktav::class, false), 'pure operations eagerly loaded native facade/loader');
echo "Packaged standalone assertions passed\n";
