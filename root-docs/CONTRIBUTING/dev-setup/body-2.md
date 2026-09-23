>>>>> lang=en
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

>>>>> lang=ru
Rust C ABI крейт (`crates/cabi/`) по умолчанию зависит от
опубликованного `ktav` на crates.io. Для локальных cross-repo правок
замените `workspace.dependencies.ktav` в `Cargo.toml` на
`{ path = "../rust" }`.

### Сборка

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

### Тесты

```bash
composer install                                       # dev dependencies (Kahlan)
vendor/bin/kahlan                                      # full suite, verbose by default
vendor/bin/kahlan --spec=tests/ReadmeDocCheckSpec.php   # just one spec file
```

>>>>> lang=zh
Rust C ABI crate(`crates/cabi/`)默认依赖 crates.io 上发布的 `ktav`。
要做本地跨仓库改动时,把 `Cargo.toml` 里的 `workspace.dependencies.ktav`
改成 `{ path = "../rust" }`。

### 构建

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

### 测试

```bash
composer install                                       # dev dependencies (Kahlan)
vendor/bin/kahlan                                      # full suite, verbose by default
vendor/bin/kahlan --spec=tests/ReadmeDocCheckSpec.php   # just one spec file
```

