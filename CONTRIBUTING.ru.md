# Как участвовать в ktav (Java)

**Языки:** [English](CONTRIBUTING.md) · **Русский** · [简体中文](CONTRIBUTING.zh.md)

## Основные правила

### 1. Каждый баг-фикс приходит с регрессионным тестом

Найдя баг, **до исправления** напишите тест, который его
воспроизводит — он **должен падать на `main`** и проходить после
фикса. Оба — в одном PR.

Тесты лежат под `lib/src/test/java/lang/ktav/`:

| Файл                   | Область                                             |
| ---------------------- | --------------------------------------------------- |
| `SmokeTest.java`       | Loads / Dumps happy paths, BigInteger, ошибки.      |
| `ConformanceTest.java` | Conformance против `ktav-lang/spec`.                |

### 2. Не переосмысливайте формат в биндинге

Эта Java-библиотека — сознательно тонкая обёртка. Поведение парсера
и формата — в Rust-крейте
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)): правка там
обновляет все языковые биндинги одновременно. Здесь — только
**Java-specific эргономика** (дерево `array`, FFI-лоадер, логика
кэша / скачивания).

Если правка требует изменения формата — сначала обсуждение в
[`ktav-lang/spec`](https://github.com/ktav-lang/spec).

### 3. Изменения публичного API помечаются по совместимости

Если трогаете экспорт из `lang.ktav`, укажите в PR:

- **semver-совместимо** (добавления, ослабления сигнатур, доки); или
- **semver-ломающее** (переименования / удаления, изменения сигнатур,
  ужесточения типов) — bump пойдёт в следующий MINOR пока pre-1.0.

Обновите `CHANGELOG.md` и два перевода в том же PR.

### 4. Один концепт — один коммит

Коммиты атомарные: фикс вместе с тестом, фича вместе с тестами,
переименование — отдельно, рефакторинг — отдельно. `git log --oneline`
должен читаться как changelog. Без `feat:` / `fix:` — не conventional
commits здесь.

### 5. Нативная библиотека в lockstep с JAR

Константа `LIB_VERSION` в
`lib/src/main/java/lang/ktav/internal/NativeLoader.java` **обязана**
совпадать с git-тегом релиза. Если бампите версию — правьте
`LIB_VERSION` в том же коммите. Рассинхрон заставит потребителей
скачивать нативку, не соответствующую их коду.

## Dev-setup

Нужно:

- JDK **17+**.
- Rust-toolchain через [`rustup`](https://rustup.rs/). MSRV: **1.70**.
- `git`.

Gradle идёт вместе с wrapper'ом (`./gradlew`) — отдельно ставить
не нужно.

Раскладка для локальной разработки — Java загружает собранный Rust'ом
cdylib `ktav_cabi` через FFI. Клонируйте соседние репо или
инициализируйте submodule:

```
ktav-lang/
├── java/     ← этот репо
├── rust/     ← соседний Rust-крейт (path-dep для локальных правок)
└── spec/     ← conformance-фикстуры (git submodule в java/spec/)
```

Rust C ABI крейт (`crates/cabi/`) по умолчанию зависит от
опубликованного `ktav` на crates.io. Для локальных cross-repo правок
замените `workspace.dependencies.ktav` в `Cargo.toml` на
`{ path = "../rust" }`.

### Сборка

```bash
# 1. Собрать нативку для вашей платформы.
cargo build --release -p ktav-cabi

# 2. Указать Java-библиотеке путь.
export KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so"   # Linux
#      ="$PWD/target/release/libktav_cabi.dylib"             # macOS
#      ="$PWD/target/release/ktav_cabi.dll"                  # Windows

# 3. Для conformance — указать submodule со спекой.
git submodule update --init
export KTAV_SPEC_ROOT="$PWD/spec/versions/0.1/tests"
```

### Тесты

```bash
./gradlew :lib:test                                        # полный прогон
./gradlew :lib:test --tests '*SmokeTest*'                  # фильтр по классу
./gradlew :lib:test --tests '*ConformanceTest*'            # только spec
```

Если `KTAV_LIB_PATH` или `KTAV_SPEC_ROOT` не заданы — соответствующие
тесты **skip'аются**, а не падают.

### Линт

```bash
./gradlew :lib:compileJava
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
```

## Архитектурные заметки

- **Wire-формат.** Между Rust и Java — JSON, с обёртками
  `{"$i":"..."}` / `{"$f":"..."}` для типизированных integer / float.
- **Владение памятью.** Rust аллоцирует буфер, Java копирует его в
  `byte[]` и сразу же вызывает `ktav_free` на Rust-стороне.
- **Loader.** `lang.ktav.internal.NativeLib` dlopen'ит библиотеку
  один раз на процесс через `Native.load` из FFI. Путь резолвит
  `NativeLoader.resolve()` — env / кэш / скачивание.

## Процесс релиза

Тег `v<X.Y.Z>` на `main`. Release-workflow кросс-компилирует шесть
нативных бинарей + собирает библиотечный JAR, всё прикрепляется к
GitHub Release. Константа `LIB_VERSION` в `NativeLoader.java` обязана
совпадать с тегом — правьте в том же коммите.

## Философия

Девиз Ktav: **"будь другом конфига, а не экзаменатором."** Прежде чем
предлагать Java-фичу, спросите:

- Добавляет ли это новое правило в голове читателя?
- Может ли это жить в коде пользователя, а не в библиотеке?
- Не размывает ли это принцип "никакой магии с типами"?

## Языковая политика

Репо участвует в org-wide три-языковой политике (EN / RU / ZH).
Naming convention и правило "обновлять все три в одном коммите" —
в [`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md).
