>>>>> lang=en
  The envelope names the two writer rejections apart:
  `"UnrepresentableAt"` when the writer can say where the offending
  node is (it also fills `getPath()`), `"Unrepresentable"` when it
  cannot. The `reason` code is identical in both, so a caller that only
  needs "the write was refused" matches on `getReason()`.

  `getErrorLine()` rather than `getLine()` because PHP's
  `Exception::getLine()` is `final`.

### Changed

- **Error messages now use the core's `message` when provided.** With
  core 0.8.0 this preserves its `Display` output verbatim; older cores
  without that field fall back to a message built from the envelope.
  `getMessage()` remains human-readable and is never the raw JSON.

- Minimum `ktav` core is **0.8.0**; the conformance tests are pinned to
  spec **v0.8.0**.

- **Float serialization now round-trips PHP floats reliably.** After
  ktav 0.6.4, floats are encoded through `json_encode()`'s
  round-trip-safe representation instead of PHP's precision-limited
  `(string)` conversion; integral floats retain a decimal marker.


>>>>> lang=ru
  Конверт по-разному называет два отказа писателя:
  `"UnrepresentableAt"`, когда писатель может указать проблемный узел
  (тогда он заполняет и `getPath()`), и `"Unrepresentable"`, когда не
  может. Код причины одинаков, поэтому вызывающему, которому достаточно
  знать «в записи отказано», хватает сверки с `getReason()`.

  `getErrorLine()`, а не `getLine()`, потому что в PHP
  `Exception::getLine()` объявлен `final`.

### Изменено

- **Текст ошибки теперь берётся из поля `message` ядра, если оно есть.**
  В ядре 0.8.0 это дословный вывод `Display`; у более старых ядер без
  этого поля используется запасное сообщение, составленное из конверта.
  `getMessage()` остаётся человекочитаемым и никогда не является сырым JSON.

- Минимальная версия ядра `ktav` — **0.8.0**; тесты соответствия
  закреплены на spec **v0.8.0**.

- **Сериализация чисел с плавающей точкой теперь надёжно сохраняет
  round-trip PHP-значений.** После ktav 0.6.4 числа кодируются через
  round-trip-представление `json_encode()`, а не ограниченное настройкой
  точности преобразование `(string)`; целые значения float сохраняют
  десятичную точку.


>>>>> lang=zh
  信封把写入器的两种拒绝分别命名:能指出问题节点位置时为
  `"UnrepresentableAt"`(此时也会填充 `getPath()`),不能指出时为
  `"Unrepresentable"`。两者的原因码完全相同,因此只需知道「写入被
  拒绝」的调用方匹配 `getReason()` 即可。

  用 `getErrorLine()` 而不是 `getLine()`,因为 PHP 的
  `Exception::getLine()` 是 `final`。

### 变更

- **错误消息优先使用核心提供的 `message` 字段。** 对于 0.8.0 核心,这会
  原样保留其 `Display` 输出;不含该字段的旧核心则回退到由信封字段构造的
  消息。`getMessage()` 仍然人类可读,且绝不会是原始 JSON。

- `ktav` 核心最低版本为 **0.8.0**;一致性测试固定使用 spec **v0.8.0**。

- **浮点数序列化现在能可靠往返 PHP 浮点值。** ktav 0.6.4 之后,浮点数改用
  `json_encode()` 的往返安全表示编码,不再使用受 PHP 精度设置影响的
  `(string)` 转换;整数值的 float 会保留小数标记。

- `crates/cabi` 改为单次调用 `ktav::declare_cabi!()`(ktav 的 `cabi`
  特性),取代手写的 C ABI 垫片;导出的符号集不变,因此绑定 API 不受
  影响。依赖下限提升至 **0.8.0**,spec 子模块重新固定到 `v0.8.0`
  (新增 § 5.2:带多余前导零的十进制数解析为 String,而非 Integer)。
- 包版本升至 **0.8.0**,与核心和规范同步;预编译库的回退下载现在指向
  `v0.8.0` 发布资产。
- 跟踪 ktav 0.7.0 与 spec 0.7.0:带引号的键(§ 5.3.3)与 inline 值中的
  `\uXXXX` 转义(§ 3.7.1)来自 Rust 内核,跨越 FFI 边界完全透明 ——
  绑定源码除依赖升级外未改动。MSRV 提升至 Rust 1.71(ktav 0.7 的真实
  MSRV);`[package.metadata.ktav] spec-version` 现为 "0.7.0"。
- 一致性测试套件指向 `spec/versions/0.8/tests`(子模块重新固定到
  `0.8.0` 之后,它一直静默读取过期的 `0.7` 语料——路径是硬编码的,
  并非从固定版本推导而来),并执行语料中的每个类别:
  `unrepresentable/` 与 `parseable-unrepresentable/`(写入方必须拒绝
  fixture 的值 / 可正常解析但规范输出必须拒绝的值),以及新增的
  `strict-lossy/`(`loads()` 必须等于 lax 值,`loadsStrict()` 必须以
  匹配的原因、body 与规范形式抛出异常)。一个 guard 测试会在语料中
  出现无法识别的类别目录时使构建失败,以防止这个问题再次悄然发生。
### 已知限制

