# Changelog

**Языки:** [English](CHANGELOG.md) · **Русский** · [简体中文](CHANGELOG.zh.md)

Все значимые изменения Java-биндинга документируются здесь. Формат
основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/);
версионирование — [Semantic Versioning](https://semver.org/) с pre-1.0
соглашением, что MINOR bump — ломающий.

Этот changelog отслеживает **релизы биндинга**, а не изменения самого
формата Ktav — для последнего см.
[`ktav-lang/spec`](https://github.com/ktav-lang/spec/blob/main/CHANGELOG.md).

## Unreleased

### Изменено

- Тесты переехали с PHPUnit на **[Kahlan](https://kahlan.github.io/docs/)**
  (BDD-style `describe`/`it`-спеки). `composer require-dev` теперь
  тянет `kahlan/kahlan` вместо `phpunit/phpunit`; `composer test`
  (или `vendor/bin/kahlan`) заменяет `vendor/bin/phpunit`. Покрытие
  то же — smoke-спеки + вся conformance-сьюта `valid/` + `invalid/`
  Ktav 0.1 из submodule'а.

## 0.1.0 — первый публичный релиз

Первый релиз. Цель — **формат Ktav 0.1**.

### Координаты артефакта

group/name: `io.github.ktav-lang:ktav`. Публикация в Maven Central —
запланирована; пока что JAR выкладывается как ассет GitHub Release.

### Публичный API

- `Ktav.loads(String) -> Value` — разобрать документ Ktav.
- `Ktav.dumps(Value) -> String` — отрендерить `array` в Ktav-текст.
- `Ktav.nativeVersion() -> String` — версия загруженного `ktav_cabi`.
- `KtavException` — ошибка парсинга/рендера с сообщением от нативной
  стороны.
- `array` — sealed-интерфейс с семью вариантами (`Null`, `Bool`, `Int`,
  `Flt`, `Str`, `Arr`, `Obj`), повторяет enum `array` из Rust-крейта.

### Архитектура

- **Нативное ядро** — референсный Rust-крейт `ktav`, обёрнутый тонким
  `extern "C"` C ABI (`crates/cabi`) и распространяемый как
  прекомпилированный `.so` / `.dylib` / `.dll`.
- **Java-лоадер** — FFI (без JNI-компиляции на стороне потребителя):
  библиотека резолвится на первый вызов из `$KTAV_LIB_PATH` или
  скачивается один раз в пользовательский кэш из соответствующего
  GitHub Release asset.
- **Wire-формат** — JSON между Rust и Java с тегированными обёртками
  `{"$i":"..."}` / `{"$f":"..."}` для lossless round-trip типизированных
  integer / float и произвольной точности (`BigInteger`).

### Соответствие типов

| Ktav             | вариант `array`                                         |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int` (текстовая форма — произвольная точность)   |
| `:f <number>`    | `Value.Flt` (текстовая форма — точный round-trip)       |
| scalar без маркера | `Value.Str`                                           |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

### Платформы

Прекомпилированные нативные бинари:

- `linux/amd64`, `linux/arm64` (glibc)
- `darwin/amd64`, `darwin/arm64`
- `windows/amd64`, `windows/arm64`

Alpine (musl) — в следующем релизе.

### Протестировано на

Полная conformance-сьюта Ktav 0.1 (все `valid/` и `invalid/` фикстуры)
на JDK 17 / 21 × Linux / macOS / Windows.

### Благодарности

Построено поверх reference-Rust-крейта `ktav`. Динамическая загрузка
через [FFI](https://github.com/java-native-access/jna). Streaming JSON
через [Jackson](https://github.com/FasterXML/jackson-core).
