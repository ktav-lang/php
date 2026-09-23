>>>>> lang=en
## Dev setup

You need:

- PHP **7.4+** with `ext-ffi` and `ext-json` (`ffi.enable=1` in
  `php.ini` outside the CLI SAPI).
- [Composer](https://getcomposer.org/).
- A Rust toolchain via [`rustup`](https://rustup.rs/). MSRV: **1.71**.
- `git`.

Layout during development — this package loads the Rust-built
`ktav_cabi` cdylib via FFI, and its conformance specs read the `spec`
submodule:

```
ktav-lang/
├── php/      ← this repo
│   └── spec/ ← conformance fixtures (git submodule)
└── rust/     ← sibling Rust crate (path-dep override for local dev)
```

>>>>> lang=ru
## Настройка окружения

Нужно:

- PHP **7.4+** с `ext-ffi` и `ext-json` (`ffi.enable=1` в `php.ini`
  вне CLI SAPI).
- [Composer](https://getcomposer.org/).
- Rust-toolchain через [`rustup`](https://rustup.rs/). MSRV: **1.71**.
- `git`.

Раскладка при разработке — этот пакет грузит собранный Rust'ом
cdylib `ktav_cabi` через FFI, а его conformance-спеки читают
submodule `spec`:

```
ktav-lang/
├── php/      ← this repo
│   └── spec/ ← conformance fixtures (git submodule)
└── rust/     ← sibling Rust crate (path-dep override for local dev)
```

>>>>> lang=zh
## 开发环境

你需要:

- PHP **7.4+**,带 `ext-ffi` 与 `ext-json`(CLI SAPI 之外需在
  `php.ini` 里 `ffi.enable=1`)。
- [Composer](https://getcomposer.org/)。
- 通过 [`rustup`](https://rustup.rs/) 安装的 Rust 工具链。MSRV:**1.71**。
- `git`。

本地开发时的目录布局 —— 本包通过 FFI 加载 Rust 构建的 `ktav_cabi`
cdylib,其一致性规格读取 `spec` 子模块:

```
ktav-lang/
├── php/      ← this repo
│   └── spec/ ← conformance fixtures (git submodule)
└── rust/     ← sibling Rust crate (path-dep override for local dev)
```

