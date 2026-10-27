>>>>> lang=en
### Source-edit cookbook

These examples describe the unreleased 0.9 API, not the published 0.8.1 package. Value replacement and insert/remove/rename are available through both pure and native backends. Each snippet is independent; Rust `?` assumes an enclosing `Result`-returning function. Go examples panic only for these fixed, known-valid inputs; applications should handle the returned error.

The binding examples use the standalone pure backend, without a native binary. Native entry points accept the same paths and values: `ktav.EditValue` (Go), `Ktav.editValue` (Java), `KtavApi.EditValue(..., KtavBackend.Native)` (C#), `Ktav::editValue` (PHP), the package's native/FFI exports (JS), and `ktav.edit_value` (Python). C-ABI backends require ABI 2; PyO3/N-API/WASM require the matching preparing-0.9 core. Bun/Deno FFI calls return promises: use `await`; pure/Node/WASM examples shown here are synchronous.

Use `PureBackend` or the native backend (`Ktav::`) for value replacement, insertion, removal, and renaming; both backends follow the same mutation contract. Textual paths split on dots, with `\.` for a literal dot and `\\` for a literal backslash; segment variants take decoded literal path segments. For rename, `newKey` is one decoded key, not a path. Bun/Deno FFI calls return promises and require `await`.

#### 1. Dotted deployment settings

Only the address changes; the comment, dotted form and neighbouring user entry remain byte-for-byte unchanged.

```php
use Ktav\PureBackend;
$source = "## deployment\ntargets.storagehost.address: old\ntargets.storagehost.user: mcp-agent\n";
$updated = PureBackend::editValue($source, "targets.storagehost.address", "192.168.0.88:22");
```

Result:

```ktav
## deployment
targets.storagehost.address: 192.168.0.88:22
targets.storagehost.user: mcp-agent
```

>>>>> lang=ru
### Практические примеры правки исходника

Замена значений и операции вставки, удаления и переименования доступны через pure- и native-бэкенды. Примеры относятся к ещё не выпущенному API 0.9, а не к опубликованному пакету 0.8.1. Фрагменты независимы; Rust `?` предполагает внешнюю функцию с результатом `Result`. В Go panic используется только для фиксированных корректных данных примера; приложение должно обработать возвращённую ошибку.

Примеры привязок используют самостоятельный pure-бэкенд без native-бинарника. Нативные точки входа принимают те же пути и значения: `ktav.EditValue` (Go), `Ktav.editValue` (Java), `KtavApi.EditValue(..., KtavBackend.Native)` (C#), `Ktav::editValue` (PHP), native/FFI-экспорты пакета (JS), `ktav.edit_value` (Python). C ABI требует версию 2; PyO3/N-API/WASM — совместимое ядро подготовки 0.9. FFI-вызовы Bun/Deno возвращают promise и требуют `await`; показанные pure/Node/WASM-варианты синхронны.

Для замены значения и операций вставки, удаления и переименования используйте `PureBackend` или native-бэкенд (`Ktav::`); оба бэкенда следуют одному контракту изменений. Текстовые пути разделяются по точкам; `\.` задаёт буквальную точку, а `\\` — буквальную обратную косую черту. Сегментные варианты принимают декодированные буквальные сегменты пути. При переименовании `newKey` — один декодированный ключ, а не путь. FFI-вызовы Bun/Deno возвращают promise и требуют `await`.

#### 1. Настройка развёртывания через dotted-ключ

Меняется только адрес; комментарий, dotted-форма и соседнее поле пользователя сохраняются байт-в-байт.

```php
use Ktav\PureBackend;
$source = "## deployment\ntargets.storagehost.address: old\ntargets.storagehost.user: mcp-agent\n";
$updated = PureBackend::editValue($source, "targets.storagehost.address", "192.168.0.88:22");
```

Результат:

```ktav
## deployment
targets.storagehost.address: 192.168.0.88:22
targets.storagehost.user: mcp-agent
```

>>>>> lang=zh
### 源文本编辑示例

以下示例适用于尚未发布的 0.9 API，而非已发布的 0.8.1 包。值替换以及插入、删除和重命名操作可通过 pure 和 native 后端使用。各代码片段相互独立；Rust 的 `?` 假定外层函数返回 `Result`。Go 示例仅针对固定且有效的输入使用 panic；应用程序应处理返回的错误。

绑定示例使用无需原生二进制的独立纯实现。原生入口接收相同的路径和值：Go 的 `ktav.EditValue`、Java 的 `Ktav.editValue`、C# 的 `KtavApi.EditValue(..., KtavBackend.Native)`、PHP 的 `Ktav::editValue`、JS 包的 native/FFI 导出及 Python 的 `ktav.edit_value`。C ABI 后端要求 ABI 2；PyO3/N-API/WASM 要求兼容的 0.9 准备核心。Bun/Deno FFI 返回 promise，需要 `await`；此处 pure/Node/WASM 示例为同步调用。

值替换以及插入、删除和重命名操作可使用 `PureBackend` 或 native 后端（`Ktav::`）；两种后端遵循相同的修改契约。文本路径按点拆分；`\.` 表示字面点，`\\` 表示字面反斜杠。路径段变体接收已解码的字面路径段。重命名时，`newKey` 是单个已解码键名，而非路径。Bun/Deno FFI 调用返回 promise，需要 `await`。

#### 1. 通过点分键修改部署配置

只修改地址；注释、点分形式及相邻的用户字段逐字节保持不变。

```php
use Ktav\PureBackend;
$source = "## deployment\ntargets.storagehost.address: old\ntargets.storagehost.user: mcp-agent\n";
$updated = PureBackend::editValue($source, "targets.storagehost.address", "192.168.0.88:22");
```

结果:

```ktav
## deployment
targets.storagehost.address: 192.168.0.88:22
targets.storagehost.user: mcp-agent
```
#### 2. 字面点号：文本路径或路径段

