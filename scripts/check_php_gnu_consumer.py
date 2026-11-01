"""Проверить original PHP suites на actual GNU DLL и matching native PHP CPU."""

import hashlib
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import sys

SOURCE = 'c979b98d6decfb3c2808c8177eecf5d34dbc8634'
arch = os.environ['EXPECTED_ARCH']
target = os.environ['EXPECTED_TARGET']
if os.environ['ACTUAL_RUNNER_ARCH'].lower() != arch:
    raise ValueError('genuine matching CPU runner required')
if subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip() != SOURCE:
    raise ValueError('original PHP source identity differs')
root = Path(os.environ['RUNNER_TEMP'])
delivered = root / 'php-gnu-delivered'
output = root / 'php-gnu-consumer'
output.mkdir()
raw = (delivered / 'ktav_cabi.dll').read_bytes()
checksum = hashlib.sha256(raw).hexdigest()
identity = json.loads((delivered / 'native-verification.json').read_bytes())
runtime = json.loads((delivered / 'native-runtime-verification.json').read_bytes())
if identity['target'] != target or identity['binary_sha256'] != checksum or runtime['binary_sha256'] != checksum:
    raise ValueError('actual GNU DLL differs from source-bound producer')
if Path('target').exists():
    raise ValueError('consumer requires fresh no-Rust native build tree')
binary = Path('target/release/ktav_cabi.dll')
binary.parent.mkdir(parents=True)
binary.write_bytes(raw)
php = Path(os.environ.get('KTAV_TEST_PHP') or shutil.which('php')).resolve()
php_bytes = php.read_bytes()
pe = int.from_bytes(php_bytes[60:64], 'little')
expected_machine = 0xAA64 if arch == 'arm64' else 0x8664
if php_bytes[:2] != b'MZ' or pe + 24 > len(php_bytes) or php_bytes[pe:pe + 4] != b'PE\0\0' or struct.unpack_from('<H', php_bytes, pe + 4)[0] != expected_machine:
    raise ValueError('actual PHP CPU must match DLL, not x64 emulation')
environment = dict(os.environ)
for name in ['KTAV_LIB_PATH', 'CARGO_TARGET_DIR', 'KTAV_SPEC_DIR', 'KTAV_KAHLAN_SPECS']:
    environment.pop(name, None)
environment['KTAV_ENABLED_BACKENDS'] = 'native,pure'
key = next(name for name in environment if name.upper() == 'PATH')
environment[key] = os.pathsep.join(part for part in environment[key].split(os.pathsep) if part and not any(token in part.lower() for token in ['.cargo', '.rustup', 'llvm-mingw']))
info_code = 'echo json_encode(["binary"=>PHP_BINARY,"version"=>PHP_VERSION,"int_size"=>PHP_INT_SIZE,"sapi"=>PHP_SAPI,"ffi"=>extension_loaded("FFI"),"mbstring"=>extension_loaded("mbstring"),"tokenizer"=>extension_loaded("tokenizer")]);'
info = json.loads(subprocess.check_output([str(php), '-d', 'ffi.enable=1', '-r', info_code], env=environment))
if info['int_size'] != 8 or info['sapi'] != 'cli' or not all(info[name] for name in ['ffi', 'mbstring', 'tokenizer']):
    raise ValueError('native original PHP CLI test requirements missing')
(output / 'php-info.json').write_text(json.dumps(info, indent=2), encoding='utf-8')
rows = []
for label, options in [('full', []), ('native-corpus', ['--spec=tests/ConformanceSpec.php'])]:
    report = 'php-gnu-' + label + '.json'
    command = [str(php), '-d', 'ffi.enable=1', 'vendor/bin/kahlan', '--reporter=verbose', '--reporter=json:' + report, *options]
    result = subprocess.run(command, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=1500)
    (output / (label + '-stdout.txt')).write_bytes(result.stdout)
    (output / (label + '-stderr.txt')).write_bytes(result.stderr)
    sys.stdout.buffer.write(result.stdout)
    sys.stderr.buffer.write(result.stderr)
    if result.returncode:
        raise ValueError('original PHP test suite failed: ' + label)
    data = Path(report).read_bytes()
    summary = json.loads(data)
    counts = summary['summary']
    if summary['errors'] or any(counts[name] for name in ['failed', 'errored', 'pending', 'excluded']) or counts['passed'] <= 0 or counts['skipped'] != 6 or counts['total'] != counts['passed'] + counts['skipped']:
        raise ValueError('original suite must pass with only its six documented canonical gaps: ' + label)
    (output / report).write_bytes(data)
    rows.append({'suite': label, **counts})
if binary.read_bytes() != raw:
    raise ValueError('actual DLL changed during PHP consumers')
subprocess.run(['git', 'diff', '--exit-code', '--', 'Cargo.toml', 'crates', 'src', 'tests', 'examples', 'composer.json', 'kahlan-config.php', 'root-docs', 'spec'], check=True)
record = {'source_ref': SOURCE, 'target': target, 'runner_arch': arch, 'php_machine': expected_machine, 'php_executable_sha256': hashlib.sha256(php_bytes).hexdigest(), 'php_version': info['version'], 'binary_sha256': checksum, 'rust_and_sdk_paths_removed': True, 'original_tests_unchanged': True, 'suites': rows, 'scope': 'Actual original PHP native/pure consumers on matching CPU and source-bound GNU DLL. Six original canonical known-gap skips remain explicit, not skip-free corpus acceptance. No production/publication/historical repair.'}
(output / 'consumer-verification.json').write_text(json.dumps(record, indent=2) + '\n', encoding='utf-8')
