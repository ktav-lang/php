# ktav — PHP bindings

**Languages:** **English** · [Русский](README.ru.md) · [简体中文](README.zh.md)

PHP bindings for the [Ktav configuration format](https://github.com/ktav-lang/spec).
Thin wrapper around the reference Rust parser, loaded at runtime through
the **[PHP FFI extension](https://www.php.net/manual/en/book.ffi.php)** —
no PHP extension to compile, no PECL install. Plain Composer
dependency, the native binary is fetched on first call.

Requires **PHP 7.4+** with `ext-ffi` enabled (default in CLI; web SAPIs
need `ffi.enable=1` in `php.ini`).

## Install

```bash
composer require ktav-lang/ktav
```

## Quick start

### Parse — read typed values straight off the array

```php
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
| `Ktav::dumps(array $value): string` | Render an associative array as Ktav text. |
| `Ktav::nativeVersion(): string` | Version of the loaded `ktav_cabi`. |

`KtavException` is thrown on any parse / render failure; the message is
the UTF-8 string produced by the native parser.

## Type mapping

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| `:i <digits>`    | `int` if it fits, else `string` (PHP has no native bigint — wrap your own GMP / BCMath if you need arithmetic). |
| `:f <number>`    | `float`                                              |
| bare scalar      | `string`                                             |
| `[ ... ]`        | sequential `array`                                   |
| `{ ... }`        | associative `array` (insertion order preserved)      |

To emit an arbitrary-precision integer, wrap the digit string yourself:
`['big' => ['$i' => '9999999999999999999']]` — same envelope used on the
wire between PHP and the native side.

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

## Runtime support

- PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3+. Tested on the LTS lines on every CI run.
- Prebuilt binaries for: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.
- Linux distros must use glibc 2.17+ (zigbuild baseline). Alpine
  (musl) support is planned.

## License

MIT — see [LICENSE](LICENSE).

Ktav spec: [ktav-lang/spec](https://github.com/ktav-lang/spec).
Reference Rust crate: [ktav-lang/rust](https://github.com/ktav-lang/rust).
