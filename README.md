# ktav — PHP bindings

[![Packagist](https://img.shields.io/packagist/v/ktav-lang/ktav?style=flat-square&logo=packagist&logoColor=white&label=Packagist)](https://packagist.org/packages/ktav-lang/ktav)
[![CI](https://img.shields.io/github/actions/workflow/status/ktav-lang/php/ci.yml?style=flat-square&logo=github&label=CI)](https://github.com/ktav-lang/php/actions)
![License: MIT OR Apache-2.0](https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue?style=flat-square)
[![Playground](https://img.shields.io/badge/playground-try%20online-7c3aed?style=flat-square&logo=rocket&logoColor=white)](https://ktav-lang.github.io/)

**Languages:** **English** · [Русский](docs/ru/README.ru.md) · [简体中文](docs/zh/README.zh.md)

**Playground:** convert JSON / YAML / TOML / INI ⇄ Ktav in your browser at **[ktav-lang.github.io](https://ktav-lang.github.io/)**.

PHP bindings for the [Ktav configuration format](https://github.com/ktav-lang/spec).
Thin wrapper around the reference Rust parser, loaded at runtime through
the **[PHP FFI extension](https://www.php.net/manual/en/book.ffi.php)** —
no PHP extension to compile, no PECL install. Plain Composer
dependency, the native binary is fetched on first call.

Requires **PHP 7.4+** with `ext-json`; `ext-ffi` only for the default native backend (enabled by default in CLI; web SAPIs
need `ffi.enable=1` in `php.ini`).

## Install

```bash
composer require ktav-lang/ktav
```

The pure backend is an explicit opt-in; the default remains native. For a standalone pure consumer:

```php
<?php
require 'vendor/autoload.php';

use Ktav\PureBackend;

$value = PureBackend::loads("greeting: hello\n");
echo PureBackend::dumps($value);
```

Requires PHP 7.4+ (`^7.4 || ^8.0`), enabled standard `ext-json`, and no `mbstring`; there are no third-party runtime Composer packages. Composer distributes and autoloads the package; installation may fetch it. Pure parse, render, formatting, and error paths need no `ext-ffi`, native/WASM library, network, subprocess, or fallback. `ReflectionReference` and SPL are built-in PHP facilities, not separate installs.

The **default** backend remains native (FFI over the Rust core). It requires enabled `ext-ffi` and a platform-compatible `ktav_cabi` binary, resolved using `KTAV_LIB_PATH` or downloaded and cached on first use. Consumers do not need a Rust compiler. Native first-use download needs network; pure operations do not. No platform-requirement waiver is needed for pure installation.

Build, test, and documentation tools (Rust Cargo, Kahlan, and Node/polydoc) are maintainer tooling, not consumer runtime dependencies. Native assets in this release are built from Rust ktav 0.8.1.

## Quick start

### Parse — read typed values straight off the array

```php
use Ktav\Ktav;

$src = <<<KTAV
service: web
port: 8080
ratio: 0.75
tls: true
tags: [
    prod
    eu-west-1
]
db.host: primary.internal
db.timeout: 30
KTAV;

$cfg = Ktav::loads($src);

$service   = $cfg['service'];        // string
$port      = $cfg['port'];           // int
$ratio     = $cfg['ratio'];          // float
$tls       = $cfg['tls'];            // bool
$tags      = $cfg['tags'];           // array<string>
$dbHost    = $cfg['db']['host'];     // string
$dbTimeout = $cfg['db']['timeout'];  // int
```

### Build & render — construct a document in code

```php
$doc = [
    'name'  => 'frontend',
    'port'  => 8443,
    'tls'   => true,
    'ratio' => 0.95,
    'upstreams' => [
        ['host' => 'a.example', 'port' => 1080],
        ['host' => 'b.example', 'port' => 1080],
    ],
    'notes' => null,
];
$text = Ktav::dumps($doc);
```

A complete runnable example lives in [`examples/basic.php`](examples/basic.php).

## API

| Method | Purpose |
| --- | --- |
| `Ktav::loads(string $src): mixed` | Parse a Ktav document. |
| `Ktav::loadsStrict(string $src): mixed` | Parse with strict numeric spelling checks. |
| `Ktav::dumps(array $value): string` | Render an associative array as Ktav text. |
| `Ktav::dumpsForceStrings(array $value): string` | Render like `dumps`, but coerce every leaf scalar to a String. |
| `Ktav::emitCanonical(array $value): string` | Render a value as deterministic canonical form. |
| `Ktav::format(string $src): string` | Normalise a document's spelling, keeping comments. |
| `Ktav::canonicalFromSource(string $src): string` | Canonicalize source directly, preserving compound shape that PHP values may collapse (for example, `a: {}` stays `a: {}` while `loads` represents the empty Object as `[]`). Drops comments and blank lines. |
| `Ktav::nativeVersion(): string` | Version of the loaded `ktav_cabi`. |

`dumpsForceStrings` flattens integers, floats, booleans and `null` to
their textual form via the raw marker (`::`); objects and arrays keep
their structure, since only leaves are coerced. The result parses back
through `loads` as the same set of String scalars — useful when a
downstream consumer needs string-only values.

## Pure PHP backend

`Ktav\PureBackend` is a full parser and writer implementation of Ktav
in pure PHP — it loads no native library, WASM module or subprocess.
Both backends are always present: choose per call
(`PureBackend::loads($src)` vs `Ktav::loads($src)`). There is no
global backend flag and no fallback in either direction — choosing
pure never touches FFI, choosing native never runs PHP parsing.

The pure backend exposes these parsing, writing and formatting operations:
`loads`, `loadsStrict`, `dumps`, `dumpsForceStrings`, `emitCanonical`,
`format` and `canonicalFromSource`. The native API also has
`Ktav::nativeVersion()`; pure `version()` is a pure-backend marker, never
a native version. `isAvailable()` is always `true` because no native
artifact is needed.

Errors use the `KtavException` ten-field envelope, with byte-offset
spans and 1-based line numbers. The value model maps associative arrays
to Object and sequential arrays to Array. Use `['$i' => '<digits>']`
for big Integers and `['$f' => '<text>']` for exact Float payloads.

Documented differences:

- `InvalidUtf8` errors include a line number, an offset-bearing message
  and a nonempty byte span over malformed bytes.
- PHP arrays cannot retain empty Object/Array identity. At the root,
  `[]` is treated as Object; nested `[]` is treated as Array. Use
  `canonicalFromSource` when that identity must survive.
- Pure mode needs enabled standard `ext-json`, but no third-party
  runtime Composer packages, FFI or native binary.

### Formatting

`Ktav::format()` takes Ktav **source text** and returns Ktav source
text. It normalises structure to canonical form (§ 5.9) while keeping
the trivia the canonical writer drops:

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

Every comment survives verbatim — Ktav has no trailing comments (§ 3.4:
a comment owns a whole line), so attachment is unambiguous. Blank lines
survive as a grouping hint, but a run of two or more collapses to
exactly one and blank padding just inside a bracket is dropped, which
makes the transform a fixed point: formatting already-formatted text
changes nothing. Key order is never changed — canonical form has no
sorting rule, and reordering keys would make review diffs worse.

### Errors

`KtavException` is thrown on any parse or render failure. Beyond a
human-readable `getMessage()`, it carries the nine structured fields of
the core's error envelope:

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — as written
    $e->getCanonical();    // "1.1"       — as it would be stored
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

The full set is `getError()`, `getReason()`, `getErrorLine()`,
`getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
`getCanonical()`, `getSpecSection()`. Absent information is `null`,
never a missing accessor, so a caller can read any field without
checking the error class first.

`getPath()` is an **array of exact decoded key segments, never a joined
string**: a key literally named `a.b` is one segment and cannot be
confused with a two-segment path.

Two writer rejections are named apart — `"UnrepresentableAt"` when the
writer can say which node is at fault (it fills `getPath()` too), and
`"Unrepresentable"` when it cannot. The `reason` code is the same in
both, so matching on `getReason()` is enough when you only need to know
that a write was refused.

`getErrorLine()` rather than `getLine()`, because PHP declares
`Exception::getLine()` `final`.

## Type mapping

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| bare integer     | `int` if it fits, else `string` (PHP has no native bigint — wrap your own GMP / BCMath if you need arithmetic). |
| bare decimal     | `float`                                              |
| other scalar     | `string`                                             |
| `[ ... ]`        | sequential `array`                                   |
| `{ ... }`        | associative `array` (insertion order preserved)      |

To emit an arbitrary-precision integer, wrap the digit string yourself:
`['big' => ['$i' => '9999999999999999999']]` — same envelope used on the
wire between PHP and the native side.

`Ktav\PureBackend` (pure PHP) uses the same value model and the same
`'$i'` / `'$f'` conventions.

## Key escaping

Since spec 0.6.4 a literal `.` or `:` inside a key segment is written
with a backslash:

```text
## The key is the single segment "a.b" → ["a.b" => "v"]
a\.b: v
## The key contains a colon → ["a:b" => "v"]
a\:b: v
## Split on the first dot only → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

A literal backslash in a key is `\\`.

## How the native library is resolved

On first call:

1. **`KTAV_LIB_PATH`** env var, if set.
2. **User cache** — `<userCache>/ktav-php/v<version>/<asset>`, downloaded
   on a previous call.
3. **GitHub Release download** — fetched once from
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` and
   cached under (2). Requires network on first call after install.

`<userCache>` is `%LOCALAPPDATA%` on Windows, `~/Library/Caches` on
macOS, `$XDG_CACHE_HOME` or `~/.cache` on Linux.

Version 0.8.1 native assets are resolved by the steps above. Overrides and cached binaries are verified at load time: the loaded library must report version 0.8.1 or a KtavException names expected vs actual. The
pure PHP backend (`Ktav\PureBackend`) bypasses this entire chain —
it needs no native library, even with `ext-ffi` disabled.

## Runtime support

- PHP requirement: `^7.4 || ^8.0`; pure mode needs enabled standard `ext-json` only, with no third-party runtime Composer packages. Rust Cargo, Kahlan (`require-dev`) and Node/polydoc are maintainer tools, not consumer runtime dependencies.
- Native mode alone needs enabled `ext-ffi` and a compatible `ktav_cabi` platform binary. Native Linux binaries require glibc 2.17+; this does not apply to pure mode. No Rust compiler is needed by consumers.
- Prebuilt binaries for: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.

## License

MIT OR Apache-2.0 — see [LICENSE-MIT](LICENSE-MIT) and [LICENSE-APACHE](LICENSE-APACHE).

## Other Ktav implementations

- [`spec`](https://github.com/ktav-lang/spec) — specification + conformance suite
- [`rust`](https://github.com/ktav-lang/rust) — reference Rust crate (`cargo add ktav`)
- [`csharp`](https://github.com/ktav-lang/csharp) — C# / .NET (`dotnet add package Ktav`)
- [`golang`](https://github.com/ktav-lang/golang) — Go (`go get github.com/ktav-lang/golang`)
- [`java`](https://github.com/ktav-lang/java) — Java / JVM (`io.github.ktav-lang:ktav` on Maven Central)
- [`js`](https://github.com/ktav-lang/js) — JS / TS (`npm install @ktav-lang/ktav`)
- [`python`](https://github.com/ktav-lang/python) — Python (`pip install ktav`)
