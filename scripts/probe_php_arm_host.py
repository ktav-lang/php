"""Собрать отдельный ARM64 PHP CLI/FFI host; не включать его в Ktav assets."""

import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import struct
import subprocess
import sys
import tarfile
import urllib.request

PHP_SOURCE = 'https://www.php.net/distributions/php-8.2.35.tar.gz'
PHP_SHA256 = '9db4204f0a2d1911277ae832185e07bb4c3b3559772753a70d2968ca346d6910'
SDK = '81b356186db123ce9cd65149cee4683211a59ebd'
VCPKG = '9a5dfef5d1a0438a7d24856a85bd60f309d355e9'


def run(command, log, cwd=None):
    result = subprocess.run(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=5400)
    log.write_bytes(result.stdout)
    sys.stdout.buffer.write(result.stdout)
    if result.returncode:
        raise RuntimeError('native PHP host preparation failed: ' + log.name)
    return result.stdout


def pe(path):
    data = path.read_bytes()
    if len(data) < 64 or len(data) > 128 * 1024 * 1024 or data[:2] != b'MZ':
        raise ValueError('bounded actual PHP PE required')
    offset = int.from_bytes(data[60:64], 'little')
    if offset + 24 > len(data) or data[offset:offset + 4] != b'PE\0\0' or struct.unpack_from('<H', data, offset + 4)[0] != 0xAA64:
        raise ValueError('actual PHP executable must be ARM64, not x64 emulation')
    return {'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data), 'machine': 'ARM64'}


if os.environ['RUNNER_ARCH'] != 'ARM64':
    raise ValueError('genuine ARM64 runner required')
workspace = Path(os.environ['GITHUB_WORKSPACE'])
root = Path(os.environ['RUNNER_TEMP']) / 'php-arm-host'
root.mkdir()
evidence = root / 'evidence'
evidence.mkdir()
sdk = workspace / '.ci-tools/php-sdk'
vcpkg = workspace / '.ci-tools/vcpkg'
for folder, expected in [(sdk, SDK), (vcpkg, VCPKG)]:
    if subprocess.check_output(['git', '-C', str(folder), 'rev-parse', 'HEAD'], text=True).strip() != expected:
        raise ValueError('exact source-built host tooling required')
    subprocess.run(['git', '-C', str(folder), 'diff', '--exit-code'], check=True)
archive = root / 'php-source.tar.gz'
request = urllib.request.Request(PHP_SOURCE, headers={'User-Agent': 'ktav-php-arm-host-probe'})
checksum = hashlib.sha256()
size = 0
with urllib.request.urlopen(request, timeout=90) as response, archive.open('xb') as output:
    while chunk := response.read(1024 * 1024):
        size += len(chunk)
        if size > 64 * 1024 * 1024:
            raise ValueError('PHP source archive exceeds bound')
        checksum.update(chunk)
        output.write(chunk)
if checksum.hexdigest() != PHP_SHA256:
    raise ValueError('official PHP source archive identity differs')
(evidence / 'source-archive.json').write_text(json.dumps({'url': PHP_SOURCE, 'sha256': checksum.hexdigest(), 'bytes': size, 'reviewed_large_source_member': {'path': 'php-8.2.35/ext/fileinfo/data_file.c', 'bytes': 42528803, 'sha256': 'd2375f7306b94d7b35f42cd9afc83f54b3072936fc1dfb90eac2add22d60ef5a'}}), encoding='utf-8')
source_root = root / 'source'
source_root.mkdir()
with tarfile.open(archive, 'r:gz') as packed:
    total = 0
    seen = set()
    for item in packed.getmembers():
        path = PurePosixPath(item.name)
        total += item.size
        large_source = item.name == 'php-8.2.35/ext/fileinfo/data_file.c' and item.size == 42528803 and item.isfile()
        if large_source and hashlib.file_digest(packed.extractfile(item), 'sha256').hexdigest() != 'd2375f7306b94d7b35f42cd9afc83f54b3072936fc1dfb90eac2add22d60ef5a':
            raise ValueError('reviewed PHP large source member differs')
        if path.is_absolute() or '..' in path.parts or '\\' in item.name or ':' in item.name or item.name in seen or not (item.isdir() or item.isfile()) or (item.size > 32 * 1024 * 1024 and not large_source) or total > 512 * 1024 * 1024:
            raise ValueError('unsafe or oversized PHP source archive member: ' + repr(item.name))
        seen.add(item.name)
    packed.extractall(source_root, filter='data')
source = source_root / 'php-8.2.35'
run(['cmd', '/d', '/c', str(vcpkg / 'bootstrap-vcpkg.bat'), '-disableMetrics'], evidence / 'vcpkg-bootstrap.log')
run([str(vcpkg / 'vcpkg.exe'), 'install', 'libffi:arm64-windows-static', '--disable-metrics'], evidence / 'libffi-build.log', vcpkg)
installed = vcpkg / 'installed/arm64-windows-static'
deps = root / 'deps'
shutil.copytree(installed / 'include', deps / 'include')
(deps / 'lib').mkdir()
libraries = [path for path in (installed / 'lib').glob('*.lib') if path.name.lower() in ['libffi.lib', 'ffi.lib']]
if len(libraries) != 1:
    raise ValueError('one actual built ARM64 libffi archive required')
shutil.copyfile(libraries[0], deps / 'lib/libffi.lib')
# SDK передаёт genuine ARM64 compiler environment без target spoof.
batch = root / 'build-host.bat'
commands = [
    '@echo off',
    'cd /d "' + str(source) + '"',
    'call buildconf.bat',
    'if errorlevel 1 exit /b 1',
    'call configure.bat --disable-all --disable-zts --enable-cli --enable-phpdbg --enable-tokenizer --enable-ctype --enable-filter --enable-mbstring --disable-mbregex --with-ffi --without-pcre-jit --with-mp=4 --with-php-build="' + str(deps) + '"',
    'if errorlevel 1 exit /b 1',
    'nmake',
    'if errorlevel 1 exit /b 1',
]
batch.write_text('\r\n'.join(commands) + '\r\n', encoding='utf-8')
run(['cmd', '/d', '/c', str(sdk / 'phpsdk-starter.bat'), '-c', 'vs18', '-a', 'arm64', '-t', str(batch)], evidence / 'php-build.log')
executables = [path for path in source.rglob('php.exe') if path.parent.name == 'Release']
if len(executables) != 1:
    raise ValueError('one actual Release PHP CLI required')
php = executables[0]
identity = pe(php)
code = '$f=FFI::cdef("unsigned long GetCurrentProcessId(void);", "kernel32.dll"); echo json_encode(["version"=>PHP_VERSION,"int_size"=>PHP_INT_SIZE,"sapi"=>PHP_SAPI,"ffi"=>extension_loaded("FFI"),"pid"=>$f->GetCurrentProcessId(),"self_pid"=>getmypid()]);'
raw = run([str(php), '-n', '-d', 'ffi.enable=1', '-r', code], evidence / 'php-ffi-probe.json')
probe = json.loads(raw)
if probe['version'] != '8.2.35' or probe['int_size'] != 8 or probe['sapi'] != 'cli' or not probe['ffi'] or probe['pid'] != probe['self_pid']:
    raise ValueError('genuine ARM64 PHP FFI execution required')
phpdbg = php.with_name('phpdbg.exe')
debugger = pe(phpdbg)
record = {'runner_arch': 'ARM64', 'php': identity, 'phpdbg': debugger, 'php_source': PHP_SOURCE, 'php_source_sha256': PHP_SHA256, 'sdk_commit': SDK, 'vcpkg_commit': VCPKG, 'libffi_sha256': hashlib.sha256(libraries[0].read_bytes()).hexdigest(), 'probe': probe, 'scope': 'CI-only native ARM64 PHP host feasibility. No Ktav DLL build/consumer acceptance, interpreter redistribution, package publication or product dependency change.'}
(evidence / 'host-verification.json').write_text(json.dumps(record, sort_keys=True, indent=2) + '\n', encoding='utf-8')

php.with_name('php.ini').write_text('ffi.enable=1' + chr(10), encoding='utf-8')
for extension in ['mbstring', 'tokenizer', 'ctype', 'filter']:
    if subprocess.check_output([str(php), '-n', '-r', 'echo extension_loaded("' + extension + '") ? "yes" : "no";'], text=True).strip() != 'yes':
        raise ValueError('original test host extension missing: ' + extension)
with Path(os.environ['GITHUB_ENV']).open('a', encoding='utf-8') as environment:
    environment.write('KTAV_TEST_PHP=' + str(php) + chr(10))
