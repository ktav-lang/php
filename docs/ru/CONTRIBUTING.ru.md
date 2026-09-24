# Как участвовать в ktav (PHP)

**Языки:** [English](../CONTRIBUTING.md) · **Русский** · [简体中文](../zh/CONTRIBUTING.zh.md)

## Основные правила

### 1. Каждый баг-фикс приходит с регрессионным тестом

Найдя баг, **до исправления** напишите тест, который его
воспроизводит — он **должен падать на `main`** и проходить после
фикса. Оба — в одном PR.

Тесты — Kahlan-спеки (`describe` / `it`) под `tests/`:

| Файл                           | Область                                                                                 |
| ------------------------------ | --------------------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | Round-trip'ы `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings`, вывод типов скаляров, quoted keys, unicode-эскейпы, большие целые, каноничный вывод. |
| `tests/FormatterSpec.php`      | `Ktav::format` — сохранение комментариев, схлопывание пустых строк, фиксированная точка, совпадение с каноничной формой без тривии. |
| `tests/ErrorEnvelopeSpec.php`  | девять структурных полей обёртки ошибки, которые несёт `KtavException`.                  |
| `tests/ConformanceSpec.php`    | кросс-языковая conformance-сверка с корпусом фикстур `ktav-lang/spec`.                   |
| `tests/CorpusGuardSpec.php`    | сторожит сам корпус: каждая категория непуста, у каждого фикстура из `valid/` ровно один `.canonical.ktav`-компаньон. |
| `tests/ReadmeDocCheckSpec.php` | исполняет задокументированные в README обещания, чтобы доки не отставали от кода.       |

`tests/TestPaths.php` — общий помощник: он находит собранный cabi под
`target/release/` и spec-фикстуры, и учитывает `KTAV_LIB_PATH`.

### 2. Не переосмысливайте формат в биндинге

Эта библиотека — сознательно тонкая обёртка. Поведение парсера и
формата — в Rust-крейте
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)): правка там
обновляет все языковые биндинги одновременно. Здесь — только
**PHP-специфичная эргономика** (FFI-лоадер, JSON-обёртка провода,
поверхность исключений).

Если правка требует изменения формата — сначала обсуждение в
[`ktav-lang/spec`](https://github.com/ktav-lang/spec).

### 3. Изменения публичного API помечаются по совместимости

Если трогаете экспорт из `Ktav\`, укажите в PR-описании:

- **semver-совместимо** (добавления, ослабления сигнатур, доки); или
- **semver-ломающее** (переименования / удаления, изменения сигнатур,
  ужесточения типов) — bump пойдёт в следующий MINOR, пока мы pre-1.0.

Обновите CHANGELOG-юниты под `root-docs/CHANGELOG/` (все три блока
`>>>>> lang=`) в том же PR и перегенерируйте вывод.

### 4. Один концепт — один коммит

Коммиты атомарные: фикс вместе с тестом, фича вместе с тестами,
переименование — отдельно, рефакторинг — отдельно. `git log --oneline`
должен читаться как changelog. Не префиксуйте сообщения `feat:` /
`fix:` — здесь нет conventional commits.

### 5. Нативная библиотека шагает в lockstep с пакетом

`NativeLib::LIB_VERSION` в `src/NativeLib.php` **обязан** совпадать с
git-тегом, которым вырезан релиз. Если бампите версию библиотеки —
правьте `LIB_VERSION` в том же коммите. Рассогласование заставит
потребителей скачивать нативку, не соответствующую их коду.

## Настройка окружения

Нужно:

- PHP **7.4+** с `ext-ffi` и `ext-json` (`ffi.enable=1` в `php.ini`
  вне CLI SAPI).
- [Composer](https://getcomposer.org/).
- Rust-toolchain через [`rustup`](https://rustup.rs/). MSRV: **1.71**.
- `git`.

Раскладка при разработке — этот пакет грузит собранный Rust'ом
cdylib `ktav_cabi` через FFI, а его conformance-спеки читают
submodule `spec`:

```
ktav-lang/
├── php/      ← this repo
│   └── spec/ ← conformance fixtures (git submodule)
└── rust/     ← sibling Rust crate (path-dep override for local dev)
```

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

## Архитектурные заметки

- **Wire-формат.** Rust и PHP обмениваются JSON через FFI-границу,
  с обёртками `{"$i":"..."}` / `{"$f":"..."}` для типизированных
  integer / float. Целые произвольной точности идут строками цифр;
  `WireJson` оживляет `$i` в `int`, когда приведение типа проходит
  туда-обратно — `(string)(int) $digits === $digits` — и иначе
  возвращает вызывающему строку цифр.
- **Владение памятью.** Rust аллоцирует выходной буфер; PHP копирует
  байты и вызывает `ktav_free` на Rust-стороне. Никакой буфер не
  живёт долго через FFI-границу.
- **Loader.** `NativeLoader::resolve()` выбирает shared library в
  порядке: env-переменная `KTAV_LIB_PATH` → пользовательский кэш
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`, корень кэша —
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` в
  зависимости от ОС) → разовая загрузка ассета соответствующего
  GitHub Release, которая кладётся в тот же путь. Порядок резолвинга
  повторяет биндинги Java / Go / .NET.
- **Доки.** Публикуемый Markdown генерируется из unit-деревьев
  `root-docs/` пакетом `@ktav-lang/polydoc`
  (`node scripts/build-docs.mjs`) — правьте юниты, никогда не
  сгенерированные `.md`.

## Процесс релиза

Тег `v<X.Y.Z>` на `main`. Release-workflow кросс-компилирует cdylib
`ktav_cabi` под шесть таргетов (`linux` amd64/arm64, `darwin`
amd64/arm64, `windows` amd64/arm64) и прикрепляет все бинари к
GitHub Release под точными именами ассетов, которые формирует
`NativeLoader` под каждую платформу
(`libktav_cabi-linux-amd64.so`, `libktav_cabi-linux-arm64.so`,
`libktav_cabi-darwin-amd64.dylib`, `libktav_cabi-darwin-arm64.dylib`,
`ktav_cabi-windows-amd64.dll`, `ktav_cabi-windows-arm64.dll`).
`NativeLib::LIB_VERSION` в `src/NativeLib.php` обязан совпадать с
тегом — меняйте его в том же коммите, что и релиз.

## Философия

Девиз Ktav: **"будь другом конфига, а не экзаменатором."** Прежде чем
предложить новую PHP-специфичную фичу, спросите:

- Добавляет ли это новое правило, которое читатель должен держать в
  голове?
- Может ли это жить в коде пользователя, а не в библиотеке?
- Размывает ли это принцип "никакой магии с типами"?

Новые правила дороги. Отвергайте всё, что явно не принадлежит.

## Языковая политика

Этот репо участвует в org-wide три-язычной политике (EN / RU / ZH).
Каждый публикуемый Markdown-документ **генерируется** из unit-деревьев
`root-docs/`, несущих все три языка в блоках `>>>>> lang=`: обновляйте
все три блока в одном коммите, запускайте
`node scripts/build-docs.mjs` и позволяйте CI-овскому `--check`
обеспечивать побайтовую идентичность. См.
[`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md).

### Лицензия вкладов

Если вы явно не заявите иное, любой вклад, намеренно отправленный
вами для включения в этот проект, как определено в лицензии
Apache-2.0, лицензируется двояко: **MIT OR Apache-2.0**, без каких-либо
дополнительных условий или ограничений.
