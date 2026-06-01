# Changelog

**Languages:** **English** · [Русский](CHANGELOG.ru.md) · [简体中文](CHANGELOG.zh.md)

All notable changes to the PHP binding are tracked here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/) with the pre-1.0 convention
that a MINOR bump is breaking.

This changelog tracks **binding releases**, not changes to the Ktav format
itself — for the latter see
[`ktav-lang/spec`](https://github.com/ktav-lang/spec/blob/main/CHANGELOG.md).

## [0.6.0] — 2026-06-01

Sync to Ktav 0.6.0 — keys now support escaping.

### Added

- Keys process the full §3.7 escape set, with two new escapes:
  - `\.` → `.` (literal dot — does **not** split a dotted path)
  - `\:` → `:` (literal colon — does **not** act as the key/value separator)
- Examples: `a\.b: v` → `{"a.b": "v"}`, `a\:b: v` → `{"a:b": "v"}`,
  `x.y\.z: v` → `{"x": {"y.z": "v"}}`.

### Breaking

- A literal backslash inside a key now requires `\\` (previously `\` in a
  key was a plain byte). Rare in practice; per pre-1.0 SemVer this is a
  MINOR bump.

### Changed

- Tracks ktav-rust 0.6.0 / Ktav spec 0.6.0. Binding source unchanged —
  the escape change is internal to the Rust core and transparent across
  the FFI boundary.

---

## [0.5.0] — 2026-05-28

Implements Ktav spec 0.5.0. Tracks ktav-rust 0.5.0.

### Breaking

- Typed markers `:i` / `:f` removed. Numbers, booleans, and `null` are
  inferred from the lexical form (spec §§ 3.6, 5.2). Write `port: 8080`
  for Integer, `port:: 8080` to keep a String.
- Comments now use `##` (own line). A single `#` byte is content, not a
  comment.
- Bare integers and floats no longer parse as String — `port: 8080`
  yields integer `8080`, not string `"8080"`.
- Key segments are trimmed of leading/trailing whitespace.

### Added

- **Inline compounds** `{k: v, …}` / `[i, …]` (spec § 5.8).
- **Eight escape sequences** in inline scalars: `\\`, `\,`, `\}`, `\]`,
  `\{`, `\[`, `\n`, `\r` (spec § 3.7).
- **`Ktav::emitCanonical($value)`** — render to the deterministic
  canonical Ktav form (spec § 7), via the new `ktav_emit_canonical`
  C ABI export.

### Changed

- License: MIT → MIT OR Apache-2.0 (`LICENSE-MIT` + `LICENSE-APACHE`).
- Spec submodule: v0.5.0.
- ktav-rust dependency: 0.5.0.
- Conformance tests now run against `spec/versions/0.5/tests/`.

---

## [0.3.1] — 2026-05-10

### Added

- **Top-level Array support (spec § 5.0.1).** A document whose first
  content line has an array-item shape (bare scalar, `:: text`,
  `:i 42`, `:f 3.14`, lone `{` / `[`, or a multi-line opener `(` /
  `((`) now parses as a sequential PHP list. Previously a bare-scalar
  first line errored as `MissingSeparator`. Empty / comments-only
  documents still default to an empty associative array (preserves
  0.3.0 behaviour).
- **`Ktav::dumps()` accepts top-level Arrays.** Sequential PHP arrays
  at the root now render as bare item-per-line (no surrounding
  `[...]` brackets). Previously `dumps([1,2,3])` threw — now it
  succeeds. Bare scalars at the root are still rejected.
- **`Ktav::dumpsForceStrings($value)`** — render any value with
  every scalar coerced to a String: typed integers, typed floats,
  booleans, and null are flattened to their textual form
  (`42`, `3.14`, `true`, `null`) and emitted via the raw-marker
  `::` so the output round-trips back through the parser as the
  same string scalars. Compounds preserve their structure; only
  leaf scalars are coerced. Useful for "everything is a string"
  dumps for downstream consumers that don't understand the
  `:i` / `:f` typed markers.

### Changed

- **Picked up `ktav 0.3.1`** — the upstream Rust crate now
  implements top-level Array detection and exposes
  `ktav::to_string_force_strings`, both surfaced through the cabi
  layer (`ktav_dumps_force_strings`, plus root-Array acceptance in
  `ktav_dumps`). See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#031--2026-05-10).

### Compatibility

Strictly additive. Every 0.3.0-valid document stays valid and
produces the same value. Every 0.3.0-valid `dumps` call returns
the same text. Only inputs 0.3.0 rejected as `MissingSeparator`
now succeed (as top-level Arrays), and only `dumps([1,2,3])`-style
calls that previously threw now succeed.

### Spec

- spec submodule synced to **0.1.1** (commit `7256816`) — top-level
  Array detection in § 5.0.1, anchored first-line invalid fixtures,
  clarified pair-shape-inside-Array behaviour.


## 0.3.0 — 2026-05-08

### Changed (breaking)

- **Picked up `ktav 0.3.0`** — the upstream Rust crate now rejects
  `key: (value)` and `key: ((value))` with
  `ErrorKind::InlineNonEmptyCompound { body: "paren-string" }`.
  These shapes were previously accepted as plain string scalars
  but are visually indistinguishable from multi-line openers. Use
  the raw-marker form `key:: (value)` to encode such literals;
  the ktav-lsp formatter auto-rewrites the legacy form on save.
  See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#030--2026-05-08).

### Fixed

- `LIB_VERSION` now tracks the binding's release tag, so the
  runtime downloads the matching `ktav_cabi-*` asset from
  `https://github.com/ktav-lang/php/releases/download/v0.3.0/`.
  Previously this constant was pinned at `0.1.1` even on `0.2.0`,
  so the loader fetched the older cabi build.

### Spec

- spec submodule synced (paren-string handling tightened —
  new invalid fixtures `inline_paren_string_single` /
  `inline_paren_string_double`; `partial_parens` valid fixture
  trimmed of newly-illegal forms).


## 0.2.0 — 2026-05-07

### Changed (breaking)

- **Picked up `ktav 0.2.0`** — multi-line strings now serialize in the
  indented stripped `( ... )` form by default. `:f 42` accepts integer
  literals (parsed as `42.0`). See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#020--2026-05-07).

  Code comparing serialized output byte-for-byte to a baked-in
  `((...))` literal must be updated. Round-trip is unchanged.

### Spec

- spec submodule synced (typed_float_integer_body fixture; oracle 42.0).


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
