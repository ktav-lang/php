>>>>> lang=en
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

>>>>> lang=ru
`tests/TestPaths.php` резолвит нативную библиотеку под
`target/release/` (учитывая `KTAV_LIB_PATH` и `CARGO_TARGET_DIR`), а
фикстуры — из submodule'а `spec/`. Если cabi не собран, каждая спека,
которой она нужна, **пропускается** с причиной
`cabi not built — run cargo build --release -p ktav-cabi`; если
submodule отсутствует, conformance- и corpus-спеки **пропускаются** с
причиной `spec submodule missing — run git submodule update --init`.
Голый checkout поэтому остаётся зелёным; шесть каноничных фикстур с
известной неоднозначностью пустого `array` пропускаются по имени в
`tests/ConformanceSpec.php`.

### Линт

```bash
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
npm ci && npm run docs:check
```

CI запускает те же команды; прогоняйте их локально перед пушем.

>>>>> lang=zh
`tests/TestPaths.php` 在 `target/release/` 下解析原生库(遵循
`KTAV_LIB_PATH` 与 `CARGO_TARGET_DIR`),并在 `spec/` 子模块下找固定
装置。cabi 未构建时,每个需要它的规格都会 **跳过**,原因
`cabi not built — run cargo build --release -p ktav-cabi`;子模块缺失
时,一致性与语料库规格 **跳过**,原因
`spec submodule missing — run git submodule update --init`。因此裸
checkout 依然保持绿色;六个已知空 `array` 歧义的规范形式固定装置在
`tests/ConformanceSpec.php` 里按名跳过。

### Lint

```bash
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
npm ci && npm run docs:check
```

CI 运行相同的命令;推送前请在本地先跑一遍。

