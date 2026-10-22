>>>>> lang=en
The operation surface mirrors `Ktav` exactly: `loads`, `loadsStrict`,
`dumps`, `dumpsForceStrings`, `emitCanonical`, `format`,
`canonicalFromSource`, plus `version()` (a pure-backend marker, never
a native version) and `isAvailable()`. Errors are the same
`KtavException` nine-field envelopes with byte-offset spans and
1-based line numbers. The value model matches the native path:
assoc array = Object, sequential list = Array, `['$i' => '<digits>']`
for big Integers and `['$f' => '<text>']` for exact Float payloads.

Documented differences:

- `InvalidUtf8` errors follow the Rust raw envelope: a line number,
  an offset-bearing message and a nonempty byte span over the
  malformed bytes.
- `emitCanonical` loses empty Object/Array identity the same way the
  native `dumps` does (an empty PHP array is an empty assoc array);
  use `canonicalFromSource` when empty-compound identity must survive.
- The pure backend requires only `ext-json` (always compiled in) —
  no FFI extension and no native binary; `PureBackend::isAvailable()`
  is always `true`.

>>>>> lang=ru
Набор операций в точности повторяет `Ktav`: `loads`, `loadsStrict`,
`dumps`, `dumpsForceStrings`, `emitCanonical`, `format`,
`canonicalFromSource`, плюс `version()` (маркер чистого бэкенда,
никогда не нативная версия) и `isAvailable()`. Ошибки — те же
девятипольные конверты `KtavException` с байтовыми смещениями и
номерами строк с 1. Модель значений совпадает с нативным путём:
ассоц. массив = Object, последовательный список = Array,
`['$i' => '<цифры>']` для больших Integer и `['$f' => '<текст>']`
для точных значений Float.

Задокументированные отличия:

- Ошибки `InvalidUtf8` следуют сырому конверту Rust: номер строки,
  сообщение со смещением и непустой байтовый диапазон повреждённых
  байтов.
- `emitCanonical` теряет идентичность пустых Object/Array так же,
  как нативный `dumps` (пустой PHP-массив — это пустой ассоц.
  массив); когда идентичность пустых составных значений должна
  сохраниться, используйте `canonicalFromSource`.
- Чистому бэкенду нужен только `ext-json` (всегда собран) — без
  расширения FFI и без нативного бинарника;
  `PureBackend::isAvailable()` всегда `true`.

>>>>> lang=zh
操作面与 `Ktav` 完全一致：`loads`、`loadsStrict`、`dumps`、
`dumpsForceStrings`、`emitCanonical`、`format`、
`canonicalFromSource`，另有 `version()`（纯后端标记，绝不是原生
版本）与 `isAvailable()`。错误同样是 `KtavException` 九字段信封，
带字节偏移区间和从 1 开始的行号。值模型与原生路径一致：
关联数组 = Object，顺序列表 = Array，`['$i' => '<数字>']`
表示大 Integer，`['$f' => '<文本>']` 表示精确 Float 载荷。

已记录的差异：

- `InvalidUtf8` 错误遵循 Rust 原始信封：行号、带偏移的消息，
  以及覆盖损坏字节的非空字节区间。
- `emitCanonical` 与原生 `dumps` 一样会丢失空 Object/Array 的
  身份（空 PHP 数组即空关联数组）；若需保留空复合结构的身份，
  请使用 `canonicalFromSource`。
- 纯后端只需要 `ext-json`（始终内建）—— 无需 FFI 扩展，也无需
  原生二进制；`PureBackend::isAvailable()` 恒为 `true`。

