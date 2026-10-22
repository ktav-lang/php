>>>>> lang=en
## Install

```bash
composer require ktav-lang/ktav
```

The package needs only `php` and `ext-json` — a pure-only install
requires **no** `ext-ffi`. The pure PHP backend (`Ktav\PureBackend`)
works with no native library at all — including when `ext-ffi` is
disabled.

The **default** backend is native (FFI over the Rust core) and does
require `ext-ffi`. Calling a native operation without the extension
fails with a clear `KtavException` ('PHP FFI extension is required').
On a dev checkout whose platform requirements are skipped, use
`composer install --ignore-platform-req=ext-ffi` — that flag is a
development convenience only, never a consumer install route.

Native assets in this release are built from Rust ktav 0.8.1.

>>>>> lang=ru
## Установка

```bash
composer require ktav-lang/ktav
```

Пакету нужны только `php` и `ext-json` — для чистой установки
`ext-ffi` **не требуется**. Чистый PHP-бэкенд (`Ktav\PureBackend`)
работает вовсе без нативной библиотеки — в том числе при выключенном
`ext-ffi`.

**Бэкенд по умолчанию** — нативный (FFI поверх Rust-ядра), и ему
`ext-ffi` нужен. Вызов нативной операции без расширения завершается
понятной ошибкой `KtavException` ('PHP FFI extension is required').
В dev-чеклауте с пропущенными платформенными требованиями используйте
`composer install --ignore-platform-req=ext-ffi` — этот флаг удобство
для разработки, но не путь установки для потребителей.

Нативные ассеты этого релиза собраны из Rust ktav 0.8.1.

>>>>> lang=zh
## 安装

```bash
composer require ktav-lang/ktav
```

本包只需 `php` 与 `ext-json` —— 纯后端安装**无需** `ext-ffi`。
纯 PHP 后端（`Ktav\PureBackend`）完全无需原生库即可工作，包括
`ext-ffi` 被禁用时。

**默认后端**是原生的（通过 FFI 调用 Rust 内核），需要 `ext-ffi`。
在没有该扩展时调用原生操作会抛出清晰的 `KtavException`
（'PHP FFI extension is required'）。在跳过平台依赖的 dev 检出中，
可使用 `composer install --ignore-platform-req=ext-ffi` —— 该参数
只是开发便利，绝不是使用者的安装方式。

本版本的原生资源基于 Rust ktav 0.8.1 构建。

