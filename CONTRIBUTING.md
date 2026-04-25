# Contributing to ktav (Java)

**Languages:** **English** · [Русский](CONTRIBUTING.ru.md) · [简体中文](CONTRIBUTING.zh.md)

## Core rules

### 1. Every bug fix ships with a regression test

When you find a bug, **before fixing it**, write a test that reproduces
it — the test **must fail on `main`** and pass after the fix. Include
both in the same PR.

Tests live under `lib/src/test/java/lang/ktav/`:

| File                 | Scope                                                      |
| -------------------- | ---------------------------------------------------------- |
| `SmokeTest.java`     | Loads / Dumps happy paths, BigInteger, error surface.      |
| `ConformanceTest.java` | Cross-language conformance against `ktav-lang/spec`.     |

### 2. Don't reinvent the format in the bindings

This Java library is deliberately a thin wrapper. Parser and format
behaviour belong in the Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)) — changing it
there updates every language binding at once. Only **Java-specific
ergonomics** (`array` type tree, FFI loader, cache / download logic)
belong in this repo.

If your change requires a format change, start a discussion in
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) first.

### 3. Public API changes note compatibility

If you touch anything exported from `lang.ktav`, say in the PR
description whether it is:

- **semver-compatible** (additions, looser signatures, doc changes); or
- **semver-breaking** (renamed / removed items, changed signatures,
  tightened types) — in which case the version bump lands in the next
  MINOR while we are pre-1.0.

Update `CHANGELOG.md` and the two translations in the same PR.

### 4. One concept per commit

Commits should be atomic: a bug fix and its test together, a feature
and its tests together, a rename on its own, a refactor on its own.
`git log --oneline` should read like a changelog. Don't prefix commit
messages with `feat:` / `fix:` — no conventional commits here.

### 5. Native library stays in lockstep with the JAR

The `LIB_VERSION` constant in
`lib/src/main/java/lang/ktav/internal/NativeLoader.java` **must** match
the git tag used to cut the release. If you bump the library version,
update `LIB_VERSION` in the same commit. Mismatched values cause
consumers to download a native library that doesn't match their code.

## Dev setup

You need:

- JDK **17+**.
- A Rust toolchain via [`rustup`](https://rustup.rs/). MSRV: **1.70**.
- `git`.

Gradle ships via the included wrapper (`./gradlew`); no separate
install needed.

Layout during development — the Java library loads the Rust-built
`ktav_cabi` cdylib via FFI. Clone the sibling spec repo (used by
conformance tests) next to this one or initialise the submodule:

```
ktav-lang/
├── java/     ← this repo
├── rust/     ← sibling Rust crate (path dep for local dev)
└── spec/     ← conformance fixtures (git submodule at java/spec/)
```

The Rust C ABI crate (`crates/cabi/`) depends on the published `ktav`
crate on crates.io by default. For local cross-repo edits, switch the
`workspace.dependencies.ktav` entry in `Cargo.toml` to
`{ path = "../rust" }`.

### Build

```bash
# 1. Build the native library for your host platform.
cargo build --release -p ktav-cabi

# 2. Point Java at it.
export KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so"   # Linux
#      ="$PWD/target/release/libktav_cabi.dylib"             # macOS
#      ="$PWD/target/release/ktav_cabi.dll"                  # Windows

# 3. For conformance tests, point at the spec submodule.
git submodule update --init
export KTAV_SPEC_ROOT="$PWD/spec/versions/0.1/tests"
```

### Test

```bash
./gradlew :lib:test                                        # full suite
./gradlew :lib:test --tests '*SmokeTest*'                  # filter by class
./gradlew :lib:test --tests '*ConformanceTest*'            # spec fixtures only
```

When either `KTAV_LIB_PATH` or `KTAV_SPEC_ROOT` is unset, the relevant
tests **skip / no-op** rather than fail — so `./gradlew test` in a bare
checkout stays green.

### Lint

```bash
./gradlew :lib:compileJava                       # javac warnings as-is
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
```

CI runs the same commands; run them locally before pushing.

## Architecture notes

- **Wire format.** Rust and Java exchange JSON over the FFI boundary,
  with `{"$i":"..."}` / `{"$f":"..."}` wrappers for typed integers /
  floats. This preserves arbitrary precision and the `:i` vs `:f`
  distinction through encoding / decoding.
- **Memory ownership.** Rust allocates the output buffer; Java copies
  it into a `byte[]` and immediately calls `ktav_free` on the Rust
  side. No buffer is long-lived across the FFI boundary.
- **Loader.** `lang.ktav.internal.NativeLib` dlopens the shared library
  once per process via FFI's `Native.load`. The path is resolved by
  `NativeLoader.resolve()` — env / cache / download.

## Release flow

Tag `v<X.Y.Z>` on `main`. The release workflow cross-compiles six
platform binaries (`linux` amd64/arm64, `darwin` amd64/arm64, `windows`
amd64/arm64) plus builds the library JAR, and attaches all of them as
GitHub Release assets. The `LIB_VERSION` constant in
`NativeLoader.java` must match the tag — change it in the same commit
as the tag message.

## Philosophy

Ktav's motto: **"be the config's friend, not its examiner."** Before
proposing a new Java-specific feature, ask:

- Does this add a new rule the reader must hold in their head?
- Could this live in user code instead of the library?
- Does this erode the "no magic types" principle?

New rules are costly. Reject everything that doesn't clearly belong.

## Language policy

This repo participates in the org-wide three-language policy (EN / RU /
ZH). Every prose file lives in three parallel versions — see
[`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md)
for the naming convention and the "update all three in one commit"
rule.
