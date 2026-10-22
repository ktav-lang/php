>>>>> lang=en
## Install

```bash
composer require ktav-lang/ktav
```

The pure backend is an explicit opt-in; the default remains native. For a standalone pure consumer:

```php
<?php
require 'vendor/autoload.php';

use Ktav\PureBackend;

$value = PureBackend::loads("greeting: hello\n");
echo PureBackend::dumps($value);
```

Requires PHP 7.4+ (`^7.4 || ^8.0`), enabled standard `ext-json`, and no `mbstring`; there are no third-party runtime Composer packages. Composer distributes and autoloads the package; installation may fetch it. Pure parse, render, formatting, and error paths need no `ext-ffi`, native/WASM library, network, subprocess, or fallback. `ReflectionReference` and SPL are built-in PHP facilities, not separate installs.

The **default** backend remains native (FFI over the Rust core). It requires enabled `ext-ffi` and a platform-compatible `ktav_cabi` binary, resolved using `KTAV_LIB_PATH` or downloaded and cached on first use. Consumers do not need a Rust compiler. Native first-use download needs network; pure operations do not. No platform-requirement waiver is needed for pure installation.

Build, test, and documentation tools (Rust Cargo, Kahlan, and Node/polydoc) are maintainer tooling, not consumer runtime dependencies. Native assets in this release are built from Rust ktav 0.8.1.

>>>>> lang=ru
## Установка

```bash
composer require ktav-lang/ktav
```

Чистый бэкенд выбирается явно; по умолчанию остаётся нативный. Пример самостоятельного использования:

```php
<?php
require 'vendor/autoload.php';

use Ktav\PureBackend;

$value = PureBackend::loads("greeting: hello\n");
echo PureBackend::dumps($value);
```

Нужны PHP 7.4+ (`^7.4 || ^8.0`), включённое стандартное `ext-json`; `mbstring` не нужен. Сторонних runtime-зависимостей Composer нет. Composer распространяет и автозагружает пакет; при установке возможна загрузка пакета. Чистые разбор, вывод, форматирование и обработка ошибок не требуют `ext-ffi`, нативной/WASM-библиотеки, сети, подпроцесса или fallback. `ReflectionReference` и SPL — встроенные средства PHP, отдельная установка не нужна.

**Бэкенд по умолчанию** остаётся нативным (FFI поверх Rust-ядра). Ему нужны включённый `ext-ffi` и совместимый с платформой бинарник `ktav_cabi`; он ищется через `KTAV_LIB_PATH` либо скачивается и кэшируется при первом использовании. Компилятор Rust потребителю не нужен. Первая загрузка нативного бинарника требует сети; чистые операции — нет. Для чистой установки не нужно обходить платформенные требования.

Rust Cargo, Kahlan и Node/polydoc — инструменты сопровождения для сборки, тестов и документации, а не runtime-зависимости потребителя. Нативные ассеты этого релиза собраны из Rust ktav 0.8.1.

>>>>> lang=zh
## 安装

```bash
composer require ktav-lang/ktav
```

纯后端需显式选择；默认仍是原生后端。独立纯模式示例：

```php
<?php
require 'vendor/autoload.php';

use Ktav\PureBackend;

$value = PureBackend::loads("greeting: hello\n");
echo PureBackend::dumps($value);
```

需要 PHP 7.4+（`^7.4 || ^8.0`）和已启用的标准 `ext-json`；不需要 `mbstring`，也没有第三方 Composer 运行时依赖。Composer 负责分发和自动加载；安装时可能下载包。纯解析、渲染、格式化和错误处理无需 `ext-ffi`、原生/WASM 库、网络、子进程或回退。`ReflectionReference` 和 SPL 是 PHP 内置功能，不需单独安装。

**默认后端**仍是原生后端（通过 FFI 调用 Rust 内核）。它需要启用 `ext-ffi` 和平台兼容的 `ktav_cabi` 二进制；通过 `KTAV_LIB_PATH` 查找，或首次使用时下载并缓存。消费者无需 Rust 编译器。首次下载原生二进制需要网络；纯操作不需要网络。纯模式安装无需豁免平台要求。

Rust Cargo、Kahlan 和 Node/polydoc 是维护者的构建、测试和文档工具，不是消费者运行时依赖。本版本的原生资源基于 Rust ktav 0.8.1 构建。

