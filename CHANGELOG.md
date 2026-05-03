# Changelog

**Languages:** **English** · [Русский](CHANGELOG.ru.md) · [简体中文](CHANGELOG.zh.md)

All notable changes to the PHP binding are tracked here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/) with the pre-1.0 convention
that a MINOR bump is breaking.

This changelog tracks **binding releases**, not changes to the Ktav format
itself — for the latter see
[`ktav-lang/spec`](https://github.com/ktav-lang/spec/blob/main/CHANGELOG.md).

## 0.1.2 — 2026-05-03

### Changed

- **Picked up `ktav 0.1.5`** — the upstream Rust crate now exposes
  `Error::Structured(ErrorKind)` with byte-offset spans, retroactive
  `#[non_exhaustive]` on the error enums, and a public `ktav::thin`
  event-based parser. The PHP binding's user-visible behaviour is
  unchanged: `KtavException` carries the same human-readable message
  (Display strings for the seven canonical categories are byte-
  identical to ktav 0.1.4 — verified by ktav's own pinning tests).
  Mapping `ktav::ErrorKind` to a structured PHP exception hierarchy
  (`KtavMissingSeparatorSpaceException`, `KtavDuplicateKeyException`,
  etc.) is separate follow-up work tracked in the workspace's
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md).

Packagist: `ktav-lang/ktav` ^0.1.2.

## 0.1.1 — 2026-04-26

### Changed

- **Picked up `ktav 0.1.4`** — the upstream Rust crate's untyped
  `parse() → Value` path (which is what `cabi` uses) is now ~30%
  faster on small documents and ~13% faster on large ones, just from
  a one-line `Frame::Object` capacity tweak (4 → 8). Every `Ktav::loads`
  call benefits transparently.
- **`Ktav::dumps([])` now renders an empty document** instead of
  throwing. Previously the list/object disambiguation rejected the
  empty array as ambiguous, which diverged from cabi's accept-empty-
  object semantics.
- **`NativeLoader::download` flushes + fsyncs** the temp file before
  rename so a crash mid-rename can't surface as a truncated cached
  library.
- **`fopen` failure now hints at `allow_url_fopen=Off`** in php.ini
  — the most common first-run grief on locked-down installs.
- **Dropped redundant `typedef`s** from `NativeLib::CDEF` (PHP-FFI
  knows `uint8_t` / `size_t` natively, and the `size_t` typedef was
  wrong on 32-bit platforms).
- Tests migrated from PHPUnit to **[Kahlan](https://kahlan.github.io/docs/)**
  (BDD-style `describe`/`it` specs). `composer require-dev` now
  pulls `kahlan/kahlan` instead of `phpunit/phpunit`; `composer test`
  (or `vendor/bin/kahlan`) replaces `vendor/bin/phpunit`. Same
  coverage — smoke specs + the full `valid/` + `invalid/` Ktav 0.1
  conformance suite from the spec submodule.

## 0.1.0 — first public release

First release. Targets **Ktav format 0.1**.

### Coordinates

Artifact group/name: `io.github.ktav-lang:ktav`. Maven Central
publication is planned; for now JARs ship as GitHub Release assets.

### Public API

- `Ktav.loads(String) -> Value` — parse a Ktav document.
- `Ktav.dumps(Value) -> String` — render a `array` as Ktav text.
- `Ktav.nativeVersion() -> String` — version of the loaded `ktav_cabi`.
- `KtavException` — parse / render error with the native-side message.
- `array` — associative array with seven variants (`Null`, `Bool`, `Int`,
  `Flt`, `Str`, `Arr`, `Obj`), mirroring the Rust crate's `array` enum.

### Architecture

- **Native core** — the reference Rust `ktav` crate, wrapped with a tiny
  `extern "C"` C ABI (`crates/cabi`) and distributed as a prebuilt
  `.so` / `.dylib` / `.dll`.
- **Java loader** — FFI (no JNI compilation on the consumer side):
  the library is resolved at first call from `$KTAV_LIB_PATH` or
  downloaded once into the user cache from the matching GitHub Release
  asset.
- **Wire format** — JSON between Rust and Java, with `{"$i":"..."}` /
  `{"$f":"..."}` tagged wrappers for lossless typed-integer / typed-float
  round-trips and arbitrary-precision integers (`BigInteger`).

### Type mapping

| Ktav             | `array` variant                                         |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int` (text form — arbitrary precision)           |
| `:f <number>`    | `Value.Flt` (text form — exact round-trip)              |
| bare scalar      | `Value.Str`                                             |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

### Platforms

Prebuilt native binaries ship for:

- `linux/amd64`, `linux/arm64` (glibc)
- `darwin/amd64`, `darwin/arm64`
- `windows/amd64`, `windows/arm64`

Alpine (musl) is planned for a follow-up.

### Test coverage

Runs the full Ktav 0.1 conformance suite (all `valid/` and `invalid/`
fixtures) on JDK 17 / 21 across Linux / macOS / Windows.

### Credits

Built on top of the reference `ktav` Rust crate. Dynamic loading via
[FFI](https://github.com/java-native-access/jna). JSON streaming via
[Jackson](https://github.com/FasterXML/jackson-core).
