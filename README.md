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

> **Unreleased (0.9 preparation):** These editing APIs and updated diagnostics are not included in published 0.8.1 packages. Native backends require the pinned 0.9 core (C ABI 2 for C-FFI); pure backends remain standalone.

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

Source editing (spec 0.9). `editValue($source, $path, $replacement)`
and `editValueSegments($source, $segments, $replacement)` replace an
EXISTING value in the source text and return the new text; the input
is never mutated. Only the bytes of the target (and of inline wrappers
the layout rules force to expand) change: comments, key spelling and
order, dotted/inline/block forms, BOM, CR/LF/CRLF and the final-EOL
state survive; a no-op is byte-identical even for noncanonical
spelling. The textual path `key1.key2.0.key4` uses `.` as the
separator, `\.` for a literal dot and `\\` for a literal backslash;
segments are already-decoded literal names. Errors are distinct
envelope codes: `EditInvalidPath` (body = UTF-8 byte offset in the
path), `EditNotFound` (missing key, out-of-bounds index, scalar
descent, empty segments list) and `EditUnsupported` (noncontiguous
dotted/reopen targets, structural replacement of a comment-carrying
container, or a replacement without a lossless representation in its target context). A
source that does not parse raises the ordinary parser envelope. On the
native side these methods require a `ktav_cabi` exporting the
source-edit ABI (version 2); an older binary fails with a clear
version error and is never replaced by a hidden pure fallback. A PHP
empty array as the replacement follows the nested empty-LIST
convention; an EMPTY `stdClass` is the explicit empty Object (`[]`
cannot express it), a NON-empty `stdClass` is a `Message` transport
failure; typed payloads (`['$i' => …]`, `['$f' => …]`) go through
the same strict wire ingest as the native codec — `$i` is canonicalized
(explicit `+` stripped, redundant leading zeros trimmed, signed zero
normalized to `0`, bignums digit-exact), `$f` must be a decimal lexical
form re-emitted shortest round-trip, and any invalid payload is a
`Message` transport failure, never a coerced scalar. Integer identity
is exact digit-string equality and Float identity is exact f64 equality
(the sign of zero is preserved, never a `precision`-ini string cast);
Object equality IGNORES key order (Rust IndexMap PartialEq), arrays
stay ordered.

### Source-edit cookbook

These examples describe the unreleased 0.9 API, not the published 0.8.1 package. They replace existing values only: keys are not renamed, missing paths are not created, and entries are not removed. Each snippet is independent; Rust `?` assumes an enclosing `Result`-returning function. Go examples panic only for these fixed, known-valid inputs; applications should handle the returned error.

The binding examples use the standalone pure backend, without a native binary. Native entry points accept the same paths and values: `ktav.EditValue` (Go), `Ktav.editValue` (Java), `KtavApi.EditValue(..., KtavBackend.Native)` (C#), `Ktav::editValue` (PHP), the package's native/FFI exports (JS), and `ktav.edit_value` (Python). C-ABI backends require ABI 2; PyO3/N-API/WASM require the matching preparing-0.9 core. Bun/Deno FFI calls return promises: use `await`; pure/Node/WASM examples shown here are synchronous.

```php
use Ktav\PureBackend;
```

#### 1. Dotted deployment settings

Only the address changes; the comment, dotted form and neighbouring user entry remain byte-for-byte unchanged.

```php
$source = "## deployment\ntargets.storagehost.address: old\ntargets.storagehost.user: mcp-agent\n";
$updated = PureBackend::editValue($source, "targets.storagehost.address", "192.168.0.88:22");
```

Result:

```ktav
## deployment
targets.storagehost.address: 192.168.0.88:22
targets.storagehost.user: mcp-agent
```

#### 2. Literal dots: textual path or segments

The quoted source key is literally `a.b`. The textual path escapes its dot; the segments call passes the decoded key directly. Both calls return the same result.

```php
$source = "\"a.b\": {port: 80}\n";
$updated = PureBackend::editValue($source, "a\\.b.port", 90);
$same = PureBackend::editValueSegments($source, ["a.b", "port"], 90);
```

Result:

```ktav
"a.b": {port: 90}
```

#### 3. A leaf inside a mixed-form array

`0` is an array index here. Inside an Object, the same segment would address the key named `0`; untouched containers remain inline.

```php
$source = "servers: [{host: old, keep: yes}, standby]\n";
$updated = PureBackend::editValueSegments($source, ["servers", "0", "host"], "new");
```

Result:

```ktav
servers: [{host: new, keep: yes}, standby]
```

#### 4. A numeric-looking String stays a String

The replacement is a String, not an Integer. The raw marker preserves that distinction. Supply a typed Integer when a numeric value is intended.

```php
$source = "port: 8080\n";
$updated = PureBackend::editValue($source, "port", "9090");
```

Result:

```ktav
port:: 9090
```

#### 5. Multiline replacement: expand only what is necessary

The changed array expands with four-space indentation. The untouched object stays inline, and verbatim string contents are not reindented.

```php
$source = "a: [old, {keep: yes}]\n";
$updated = PureBackend::editValue($source, "a.0", "x\ny");
```

Result:

```ktav
a: [
    ((
x
y
    ))
    {keep: yes}
]
```

#### 6. No-op preserves noncanonical spelling

The Integer value is already 1. Equality returns the original bytes, including `+1` and the original separator spacing; readability thresholds are bypassed.

```php
$source = "n  : +1\n";
$updated = PureBackend::editValue($source, "n", 1);
assert($updated === $source);
```

Result:

```ktav
n  : +1
```

#### 7. BOM, CRLF and an unterminated final line

The returned string keeps the BOM and CRLF, and does not gain a final newline. Its escaped representation is shown to make the bytes visible.

```php
$source = "\u{FEFF}port: 8080\r\nhost: db";
$updated = PureBackend::editValue($source, "port", 9090);
```

Result:

```text
"\uFEFFport: 9090\r\nhost: db"
```

Expansion is deterministic: a multiline replacement, growth beyond `max(100, original line length)`, or at least four escapes with at least 25% encoding overhead for an individual replacement string. Width counts Unicode scalar values, not UTF-8 bytes or terminal columns. An already-long line without growth is kept. New levels use four spaces and the source EOL. If CR or a `))` data line prevents lossless standalone encoding, a valid inline candidate is retained; these characters do not automatically make every inline edit unsupported.

#### 8. Exact Integer replacement

The source Integer domain is signed 64-bit. This value exceeds JavaScript's safe-Number range but fits i64; use an exact host representation, not a rounded floating-point value. A typed Integer outside i64 cannot be preserved as an Integer in source and is refused instead of truncated. Use a String when a large identifier is text. Float identity distinguishes negative and positive zero; Object no-op equality ignores key order, while Array order remains significant.

```php
$updated = PureBackend::editValue("n: 1\n", "n", ['$i' => '9007199254740993']);
```

```ktav
n: 9007199254740993
```

### Diagnostics and safe failures in 0.9

Source coordinates are UTF-8 byte offsets, including a BOM; spans are half-open `[start, end)`, and lines are 1-based. LF, lone CR and CRLF are handled, with CRLF counted as one terminator. A Unicode character may occupy several bytes.

An unfinished `a: [\n` reports `UnclosedCompound`, line 1 and span `3..5`: the span begins at the innermost unmatched opener, not the start of its key or line. For raw bytes `61 3A 20 FF` (`a: ` followed by invalid UTF-8), validation reports `InvalidUtf8`, line 1 and a covering span `3..4`; `line_text` is null rather than fabricated by lossy decoding. UTF-8 validation precedes grammar processing.

A malformed textual path, including an empty textual path or empty textual segment, is `EditInvalidPath`; its offset is measured in the path's UTF-8 bytes. An empty segments list, missing key, out-of-range index or scalar descent is `EditNotFound`. Object numeric segments remain keys; array indexes use ASCII digits, allow leading zeros and reject signs.

Whole dotted/reopened intermediate objects may occupy several physical ranges and are `EditUnsupported`; their existing leaves can still be edited. Replacing a container must not discard interior comments. No failed call returns a partial patch or changes the supplied source. The returned text is re-parsed and semantically checked: the target must equal the replacement and the rest of the value tree must remain equal.

For bindings, invalid source is reported with the ordinary parser envelope before path validation. Malformed wire payloads are `Message` errors without invented source coordinates. The wire envelope has `error`, `reason`, `line`, `line_text`, `span`, `path`, `body`, `canonical`, `spec_section` and `message`; host accessor names are documented in the errors section. `body` carries the decimal path-byte offset for `EditInvalidPath`, null for `EditNotFound`, and the refusal reason for `EditUnsupported`.

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
