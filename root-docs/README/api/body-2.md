>>>>> lang=en
The pure backend exposes these parsing, writing and formatting operations:
`loads`, `loadsStrict`, `dumps`, `dumpsForceStrings`, `emitCanonical`,
`format` and `canonicalFromSource`. The native API also has
`Ktav::nativeVersion()`; pure `version()` is a pure-backend marker, never
a native version. `isAvailable()` is always `true` because no native
artifact is needed.

Errors use the `KtavException` ten-field envelope, with byte-offset
spans and 1-based line numbers. The value model maps associative arrays
to Object and sequential arrays to Array. Use `['$i' => '<digits>']`
for big Integers and `['$f' => '<text>']` for exact Float payloads.

Documented differences:

- `InvalidUtf8` errors include a line number, an offset-bearing message
  and a nonempty byte span over malformed bytes.
- PHP arrays cannot retain empty Object/Array identity. At the root,
  `[]` is treated as Object; nested `[]` is treated as Array. Use
  `canonicalFromSource` when that identity must survive.
- Pure mode needs enabled standard `ext-json`, but no third-party
  runtime Composer packages, FFI or native binary.

>>>>> lang=ru
Чистый бэкенд предоставляет операции разбора, записи и форматирования:
`loads`, `loadsStrict`, `dumps`, `dumpsForceStrings`, `emitCanonical`,
`format` и `canonicalFromSource`. В нативном API также есть
`Ktav::nativeVersion()`; чистый `version()` — маркер чистого бэкенда,
а не нативная версия. `isAvailable()` всегда возвращает `true`, поскольку
нативный артефакт не нужен.

Ошибки используют десятипольный конверт `KtavException` с байтовыми
смещениями и номерами строк с 1. Ассоциативные массивы соответствуют
Object, последовательные массивы — Array. Для больших Integer используйте
`['$i' => '<цифры>']`, для точных значений Float — `['$f' => '<текст>']`.

Задокументированные отличия:

- Ошибки `InvalidUtf8` содержат номер строки, сообщение со смещением
  и непустой диапазон повреждённых байтов.
- PHP-массивы не сохраняют идентичность пустых Object/Array. На корне
  `[]` трактуется как Object, внутри — как Array. Используйте
  `canonicalFromSource`, если идентичность нужно сохранить.
- Чистому режиму нужно включённое стандартное `ext-json`, но не
  сторонние runtime-зависимости Composer, FFI или нативный бинарник.

>>>>> lang=zh
纯后端提供解析、写入和格式化操作：`loads`、`loadsStrict`、`dumps`、
`dumpsForceStrings`、`emitCanonical`、`format` 和
`canonicalFromSource`。原生 API 另有 `Ktav::nativeVersion()`；纯后端的
`version()` 只是纯模式标记，绝不是原生版本。无需原生资源时，
`isAvailable()` 始终为 `true`。

错误使用 `KtavException` 十字段信封，包含字节偏移区间和从 1 开始的
行号。关联数组对应 Object，顺序数组对应 Array。大 Integer 使用
`['$i' => '<数字>']`，精确 Float 载荷使用 `['$f' => '<文本>']`。

已记录的差异：

- `InvalidUtf8` 错误包含行号、带偏移的消息，以及覆盖损坏字节的
  非空区间。
- PHP 数组无法保留空 Object/Array 的身份。根节点的 `[]` 按 Object
  处理，嵌套的 `[]` 按 Array 处理。若需保留身份，请使用
  `canonicalFromSource`。
- 纯模式需要启用标准 `ext-json`，但无需第三方 Composer 运行时依赖、
  FFI 或原生二进制。

