>>>>> lang=en
  `getErrorLine()` rather than `getLine()` because PHP's
  `Exception::getLine()` is `final`.

- **`Ktav::canonicalFromSource(string $src): string`** canonicalizes source
  directly, preserving empty Object/Array distinctions that PHP values lose.
  Unlike `emitCanonical(loads($src))`, it never round-trips through PHP values.

### Changed

- **Error messages now use the core's `message` when provided.** With
  core 0.8.0 this preserves its `Display` output verbatim; older cores
  without that field fall back to a message built from the envelope.
  `getMessage()` remains human-readable and is never the raw JSON.

- PHP-side invalid UTF-8, scalar-root and non-finite Float errors now carry
  the spec's error category or writer reason in `KtavException`.

- Minimum `ktav` core is **0.8.0**; the conformance tests are pinned to
  spec **v0.8.0**.

- **Float serialization now round-trips PHP floats reliably.** After
  ktav 0.6.4, floats are encoded through `json_encode()`'s
  round-trip-safe representation instead of PHP's precision-limited
  `(string)` conversion; integral floats retain a decimal marker.

>>>>> lang=ru
  `getErrorLine()`, а не `getLine()`, потому что в PHP
  `Exception::getLine()` объявлен `final`.

- **`Ktav::canonicalFromSource(string $src): string`** канонизирует исходный
  текст напрямую, сохраняя различие между пустыми Object и Array, которое
  теряется при преобразовании через значения PHP.

### Изменено

- **Текст ошибки теперь берётся из поля `message` ядра, если оно есть.**
  В ядре 0.8.0 это дословный вывод `Display`; у более старых ядер без
  этого поля используется запасное сообщение, составленное из конверта.
  `getMessage()` остаётся человекочитаемым и никогда не является сырым JSON.

- Ошибки PHP-слоя для невалидного UTF-8, скалярного корня и нечисловых
  значений Float теперь содержат категорию или причину отказа из спеки.

- Минимальная версия ядра `ktav` — **0.8.0**; тесты соответствия
  закреплены на spec **v0.8.0**.

- **Сериализация чисел с плавающей точкой теперь надёжно сохраняет
  round-trip PHP-значений.** После ktav 0.6.4 числа кодируются через
  round-trip-представление `json_encode()`, а не ограниченное настройкой
  точности преобразование `(string)`; целые значения float сохраняют
  десятичную точку.

>>>>> lang=zh
  用 `getErrorLine()` 而不是 `getLine()`,因为 PHP 的
  `Exception::getLine()` 是 `final`。

- **`Ktav::canonicalFromSource(string $src): string`** 直接规范化源文本,
  保留经 PHP 值转换会丢失的空 Object 与空 Array 之别。

### 变更

- **错误消息优先使用核心提供的 `message` 字段。** 对于 0.8.0 核心,这会
  原样保留其 `Display` 输出;不含该字段的旧核心则回退到由信封字段构造的
  消息。`getMessage()` 仍然人类可读,且绝不会是原始 JSON。

- PHP 层对无效 UTF-8、标量根节点和非有限 Float 的错误现在携带规范中的
  错误类别或写入拒绝原因。

- `ktav` 核心最低版本为 **0.8.0**;一致性测试固定使用 spec **v0.8.0**。

- **浮点数序列化现在能可靠往返 PHP 浮点值。** ktav 0.6.4 之后,浮点数改用
  `json_encode()` 的往返安全表示编码,不再使用受 PHP 精度设置影响的
  `(string)` 转换;整数值的 float 会保留小数标记。

