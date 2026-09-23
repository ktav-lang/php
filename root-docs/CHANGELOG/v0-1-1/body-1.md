>>>>> lang=en
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

>>>>> lang=ru
## 0.1.1 — 2026-04-26

### Изменено

- **Подхвачен `ktav 0.1.4`** — untyped путь `parse() → Value` в upstream
  Rust crate (тот, что использует `cabi`) теперь на ~30% быстрее на
  маленьких документах и на ~13% быстрее на больших — благодаря
  однострочной правке ёмкости `Frame::Object` (4 → 8). Каждый вызов
  `Ktav::loads` получает ускорение прозрачно.
- **`Ktav::dumps([])` теперь выводит пустой документ** вместо исключения.
  Раньше различение list/object отвергало пустой массив как неоднозначный,
  что расходилось с cabi-семантикой приёма пустого объекта.
- **`NativeLoader::download` делает flush + fsync** временного файла перед
  переименованием, поэтому падение в середине переименования больше не
  приведёт к усечённой кэшированной библиотеке.
- **Ошибка `fopen` теперь подсказывает про `allow_url_fopen=Off`** в
  php.ini — самая частая first-run боль на залоченных установках.
- **Убраны лишние `typedef`'ы** из `NativeLib::CDEF` (PHP-FFI знает
  `uint8_t` / `size_t` нативно, а typedef для `size_t` был неверен на
  32-битных платформах).
- Тесты переехали с PHPUnit на **[Kahlan](https://kahlan.github.io/docs/)**
  (BDD-спеки в стиле `describe`/`it`). `composer require-dev` теперь
  тянет `kahlan/kahlan` вместо `phpunit/phpunit`; `composer test` (или
  `vendor/bin/kahlan`) заменяет `vendor/bin/phpunit`. Покрытие то же —
  smoke-спеки + полный conformance-набор `valid/` + `invalid/` Ktav 0.1 из
  подмодуля spec.

>>>>> lang=zh
## 0.1.1 —— 2026-04-26

### 变更

- **已升级到 `ktav 0.1.4`** —— 上游 Rust crate 中 `cabi` 所使用的 untyped
  `parse() → Value` 路径,在小文档上快约 30%,在大文档上快约 13%,仅来自
  `Frame::Object` 容量的一行微调(4 → 8)。每次 `Ktav::loads` 调用都透明
  受益。
- **`Ktav::dumps([])` 现在渲染空文档**,不再抛异常。此前 list/object
  消歧逻辑拒绝空数组,认为其含义不明,这与 cabi 的「接受空对象」语义不一致。
- **`NativeLoader::download` 在 rename 之前 flush + fsync** 临时文件 ——
  rename 中途崩溃不再可能表现为被截断的缓存库。
- **`fopen` 失败时提示 `allow_url_fopen=Off`**(php.ini)—— 这是锁定环境
  下最常见的首次运行困扰。
- **从 `NativeLib::CDEF` 中移除了冗余的 `typedef`**(PHP-FFI 原生认识
  `uint8_t` / `size_t`,且 `size_t` 的 typedef 在 32 位平台上是错的)。
- 测试已从 PHPUnit 迁移到
  **[Kahlan](https://kahlan.github.io/docs/)**(BDD 风格的 `describe` / `it`
  规范)。`composer require-dev` 现在拉取 `kahlan/kahlan` 而非
  `phpunit/phpunit`;`composer test`(或 `vendor/bin/kahlan`)取代
  `vendor/bin/phpunit`。覆盖范围相同 —— smoke 规范 + 来自 spec 子模块的
  全部 Ktav 0.1 `valid/` + `invalid/` 一致性套件。

