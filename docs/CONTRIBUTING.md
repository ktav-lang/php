# Contributing to ktav (PHP)

**Languages:** **English** · [Русский](ru/CONTRIBUTING.ru.md) · [简体中文](zh/CONTRIBUTING.zh.md)

## Core rules

### 1. Every bug fix ships with a regression test

When you find a bug, **before fixing it**, write a test that reproduces
it — the test **must fail on `main`** and pass after the fix. Include
both in the same PR.

Tests are Kahlan specs (`describe` / `it`) under `tests/`:

| File                           | Scope                                                                       |
| ------------------------------ | --------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings` round-trips, typed markers, quoted keys, unicode escapes, big integers, canonical output. |
| `tests/FormatterSpec.php`      | `Ktav::format` — comment preservation, blank-line collapsing, fixed point, canonical equivalence without trivia. |
| `tests/ErrorEnvelopeSpec.php`  | the nine structured error-envelope fields carried by `KtavException`.       |
| `tests/ConformanceSpec.php`    | cross-language conformance against the `ktav-lang/spec` fixture corpus.     |
| `tests/CorpusGuardSpec.php`    | guards the corpus itself: every category non-empty, one `.canonical.ktav` companion per `valid/` fixture. |
| `tests/ReadmeDocCheckSpec.php` | executes the README's documented claims so the docs cannot drift from the code. |

`tests/TestPaths.php` is the shared helper: it locates the built cabi
under `target/release/` and the spec fixtures, and honours
`KTAV_LIB_PATH`.

### 2. Don't reinvent the format in the binding

This library is deliberately a thin wrapper. Parser and format
behaviour belong in the Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)) — changing it
there updates every language binding at once. Only **PHP-specific
ergonomics** (the FFI loader, the JSON wire envelope, the exception
surface) belong in this repo.

If your change requires a format change, start a discussion in
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) first.

### 3. Public API changes note compatibility

If you touch anything exported from `Ktav\`, say in the PR description
whether it is:

- **semver-compatible** (additions, looser signatures, doc changes); or
- **semver-breaking** (renamed / removed items, changed signatures,
  tightened types) — in which case the version bump lands in the next
  MINOR while we are pre-1.0.

Update the CHANGELOG source units under `root-docs/CHANGELOG/` (all
three `>>>>> lang=` blocks) in the same PR and regenerate the output.

### 4. One concept per commit

Commits should be atomic: a bug fix and its test together, a feature
and its tests together, a rename on its own, a refactor on its own.
`git log --oneline` should read like a changelog. Don't prefix commit
messages with `feat:` / `fix:` — no conventional commits here.

### 5. The native library stays in lockstep with the package

`NativeLib::LIB_VERSION` in `src/NativeLib.php` **must** match the git
tag used to cut the release. If you bump the library version, update
`LIB_VERSION` in the same commit. Mismatched values cause consumers to
download a native library that doesn't match their code.

## Dev setup

You need:

- PHP **7.4+** with `ext-ffi` and `ext-json` (`ffi.enable=1` in
  `php.ini` outside the CLI SAPI).
- [Composer](https://getcomposer.org/).
- A Rust toolchain via [`rustup`](https://rustup.rs/). MSRV: **1.71**.
- `git`.

Layout during development — this package loads the Rust-built
`ktav_cabi` cdylib via FFI, and its conformance specs read the `spec`
submodule:

```
ktav-lang/
├── php/      ← this repo
│   └── spec/ ← conformance fixtures (git submodule)
└── rust/     ← sibling Rust crate (path-dep override for local dev)
```

The Rust C ABI crate (`crates/cabi/`) depends on the published `ktav`
crate on crates.io by default. For local cross-repo edits, switch the
`workspace.dependencies.ktav` entry in `Cargo.toml` to
`{ path = "../rust" }`.

### Build

```bash
# 1. Build the native library for your host platform.
cargo build --release -p ktav-cabi

# 2. Point the loader at it.
export KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so"   # Linux
#      ="$PWD/target/release/libktav_cabi.dylib"             # macOS
#      ="$PWD/target/release/ktav_cabi.dll"                  # Windows

# 3. Pull the conformance fixtures (the spec/ submodule).
git submodule update --init
```

### Test

```bash
composer install                                       # dev dependencies (Kahlan)
vendor/bin/kahlan                                      # full suite, verbose by default
vendor/bin/kahlan --spec=tests/ReadmeDocCheckSpec.php   # just one spec file
```

`tests/TestPaths.php` resolves the native library under
`target/release/` (it honours `KTAV_LIB_PATH` and
`CARGO_TARGET_DIR`) and the fixtures under the `spec/` submodule.
When the cabi is not built, every spec that needs it **skips** with
`cabi not built — run cargo build --release -p ktav-cabi`; when the
submodule is missing, the conformance and corpus specs **skip** with
`spec submodule missing — run git submodule update --init`. A bare
checkout therefore stays green; the six canonical-form fixtures with a
known empty-`array` ambiguity are skipped by name in
`tests/ConformanceSpec.php`.

### Lint

```bash
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
npm ci && npm run docs:check
```

CI runs the same commands; run them locally before pushing.

## Architecture notes

- **Wire format.** Rust and PHP exchange JSON over the FFI boundary,
  with `{"$i":"..."}` / `{"$f":"..."}` wrappers for typed integers /
  floats. Arbitrary-precision integers cross as digit strings;
  `WireJson` revives a `$i` to an `int` when casting round-trips —
  `(string)(int) $digits === $digits` — and otherwise hands the digit
  string back to the caller.
- **Memory ownership.** Rust allocates the output buffer; PHP copies
  the bytes out and calls `ktav_free` on the Rust side. No buffer is
  long-lived across the FFI boundary.
- **Loader.** `NativeLoader::resolve()` picks the shared library in
  order: the `KTAV_LIB_PATH` env var → the user cache
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`, with
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` as the
  per-OS cache root) → a one-time download of the matching GitHub
  Release asset, cached under the same path. The resolution order
  mirrors the Java / Go / .NET bindings.
- **Docs.** The published Markdown is generated from the `root-docs/`
  unit trees by `@ktav-lang/polydoc` (`node scripts/build-docs.mjs`)
  — edit the units, never the generated `.md`.

## Release flow

Tag `v<X.Y.Z>` on `main`. The release workflow cross-compiles the
`ktav_cabi` cdylib for six targets (`linux` amd64/arm64, `darwin`
amd64/arm64, `windows` amd64/arm64) and attaches every binary to the
GitHub Release under the exact asset name `NativeLoader` constructs
for that platform (`libktav_cabi-linux-amd64.so`,
`libktav_cabi-linux-arm64.so`, `libktav_cabi-darwin-amd64.dylib`,
`libktav_cabi-darwin-arm64.dylib`, `ktav_cabi-windows-amd64.dll`,
`ktav_cabi-windows-arm64.dll`). `NativeLib::LIB_VERSION` in
`src/NativeLib.php` must match the tag — change it in the same commit
as the release.

## Philosophy

Ktav's motto: **"be the config's friend, not its examiner."** Before
proposing a new PHP-specific feature, ask:

- Does this add a new rule the reader must hold in their head?
- Could this live in user code instead of the library?
- Does this erode the "no magic types" principle?

New rules are costly. Reject everything that doesn't clearly belong.

## Language policy

This repo participates in the org-wide three-language policy (EN / RU /
ZH). Every published Markdown document is **generated** from
`root-docs/` unit trees that carry all three languages in
`>>>>> lang=` blocks: update all three blocks in one commit, run
`node scripts/build-docs.mjs`, and let CI's `--check` enforce
byte-identity. See
[`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md).

### License of contributions

Unless you explicitly state otherwise, any contribution intentionally
submitted for inclusion in this project by you, as defined in the
Apache-2.0 license, shall be dual-licensed as **MIT OR Apache-2.0**,
without any additional terms or conditions.
