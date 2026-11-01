>>>>> lang=en
## Prepared Windows native runtime

The prepared **0.8.1** Windows C ABI uses genuine
`x86_64-pc-windows-gnullvm` and `aarch64-pc-windows-gnullvm` targets with
the pinned LLVM MinGW UCRT SDK instead of the MSVC build path. Public DLL
filenames are unchanged. PHP process CPU must match the DLL; an x64 PHP
process on Windows ARM64 still needs the x64 DLL.

Original native/pure suites run on genuine x64 and ARM64 PHP CLI before
release staging. The source-built ARM64 PHP host is a CI tool, not a
bundled interpreter or a consumer dependency. Consumers need their PHP
runtime and `ext-ffi`, not Rust or a native compiler; pure mode remains
self-contained with standard `ext-json` only.

Each DLL retains its embedded Cargo/std, Rust compiler and GNU runtime
source/grant resource. Adjacent `.NOTICE.json` and `.NOTICE.manifest.json`
retain the complete original materials and binary/source identities.
Windows system/UCRT APIs remain OS dependencies.

Six previously documented canonical round-trip gaps remain unchanged.
The two no-expectation cycle specifications also require separate bounded
native/pure child execution. This runtime preparation does not publish a
package, accept Microsoft redistribution terms or repair historical assets.

>>>>> lang=ru
## Подготовленный нативный Windows runtime

Подготовленный Windows C ABI **0.8.1** использует настоящие цели
`x86_64-pc-windows-gnullvm` и `aarch64-pc-windows-gnullvm` с закреплённым
LLVM MinGW UCRT SDK вместо сборки MSVC. Публичные имена DLL сохранены.
Архитектура процесса PHP должна совпадать с DLL: x64 PHP на Windows ARM64
по-прежнему нужна x64 DLL.

Перед staging выполняются исходные native/pure suites на настоящих x64
и ARM64 PHP CLI. Собранный из исходников ARM64 PHP host — инструмент CI,
а не включённый интерпретатор или зависимость потребителя. Потребителям
нужны PHP runtime и `ext-ffi`, но не Rust или нативный компилятор; чистый
режим остаётся самодостаточным со стандартным `ext-json`.

Каждая DLL сохраняет встроенный ресурс Cargo/std, Rust compiler и исходных
текстов/лицензий GNU runtime. Соседние `.NOTICE.json` и
`.NOTICE.manifest.json` сохраняют полные оригинальные материалы и
идентичности бинарника/исходников. Системные Windows/UCRT API остаются
зависимостями ОС.

Шесть ранее документированных canonical round-trip gaps не изменены.
Две cycle specifications без `expect()` дополнительно требуют отдельного
bounded native/pure child execution. Эта подготовка не публикует пакет,
не принимает Microsoft redistribution terms и не исправляет старые assets.

>>>>> lang=zh
## 准备中的 Windows 原生运行时

准备中的 **0.8.1** Windows C ABI 使用真正的
`x86_64-pc-windows-gnullvm` 和 `aarch64-pc-windows-gnullvm` 目标以及固定的
LLVM MinGW UCRT SDK，而非 MSVC 构建路径。公开 DLL 文件名不变。
PHP 进程的 CPU 必须与 DLL 匹配：Windows ARM64 上的 x64 PHP 进程仍需 x64 DLL。

暂存发行载荷之前，会在真正的 x64 和 ARM64 PHP CLI 上运行原始 native/pure
测试。源码构建的 ARM64 PHP host 只是 CI 工具，不是捆绑的解释器或消费者依赖。
使用者需要自己的 PHP runtime 和 `ext-ffi`，无需 Rust 或原生编译器；
纯模式仍然自包含，只需标准 `ext-json`。

每个 DLL 保留内嵌的 Cargo/std、Rust 编译器和 GNU runtime 源码/许可资源。
旁边的 `.NOTICE.json` 和 `.NOTICE.manifest.json` 保留完整原文及二进制/源码身份。
Windows 系统/UCRT API 仍由操作系统提供。

此前记录的六个 canonical round-trip 限制保持不变。两个不含 `expect()` 的
cycle specifications 还必须单独执行有界 native/pure 子进程测试。这些准备不会
发布软件包、接受 Microsoft 再分发条款，也不会修复历史资产。

