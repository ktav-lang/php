>>>>> lang=en
## Runtime support

- PHP requirement: `^7.4 || ^8.0`; pure mode needs enabled standard `ext-json` only, with no third-party runtime Composer packages. Rust Cargo, Kahlan (`require-dev`) and Node/polydoc are maintainer tools, not consumer runtime dependencies.
- Native mode alone needs enabled `ext-ffi` and a compatible `ktav_cabi` platform binary. Native Linux binaries require glibc 2.17+; this does not apply to pure mode. No Rust compiler is needed by consumers.
- Prebuilt binaries for: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.

>>>>> lang=ru
## Поддержка рантаймов

- Требование к PHP: `^7.4 || ^8.0`; чистому режиму нужно только включённое стандартное `ext-json`, без сторонних runtime-зависимостей Composer. Rust Cargo, Kahlan (`require-dev`) и Node/polydoc — инструменты сопровождения, а не runtime-зависимости потребителя.
- Только нативному режиму нужны включённый `ext-ffi` и совместимый платформенный бинарник `ktav_cabi`. Нативным бинарникам Linux требуется glibc 2.17+; к чистому режиму это не относится. Компилятор Rust потребителю не нужен.
- Готовые бинарники для: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.

>>>>> lang=zh
## 运行时支持

- PHP 要求：`^7.4 || ^8.0`；纯模式只需启用标准 `ext-json`，没有第三方 Composer 运行时依赖。Rust Cargo、Kahlan（`require-dev`）和 Node/polydoc 是维护工具，不是消费者运行时依赖。
- 仅原生模式需要启用 `ext-ffi` 和兼容平台的 `ktav_cabi` 二进制。原生 Linux 二进制要求 glibc 2.17+；纯模式不受此限制。消费者无需 Rust 编译器。
- 预编译二进制覆盖：`linux/amd64`、`linux/arm64`、`darwin/amd64`、
  `darwin/arm64`、`windows/amd64`、`windows/arm64`。

