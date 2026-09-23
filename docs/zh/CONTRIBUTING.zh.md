# 为 ktav (PHP) 做贡献

**语言:** [English](../CONTRIBUTING.md) · [Русский](../ru/CONTRIBUTING.ru.md) · **简体中文**

## 核心规则

### 1. 每个 bug 修复都带回归测试

发现 bug 时,**在修复之前** 先写一个能复现它的测试 —— 该测试在
`main` 上 **必须失败**,修复之后才通过。两者放进同一个 PR。

测试是 `tests/` 下的 Kahlan 规格(`describe` / `it`):

| 文件                           | 范围                                                                                 |
| ------------------------------ | ------------------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings` 往返、类型标记、引号键、unicode 转义、大整数、规范输出。 |
| `tests/FormatterSpec.php`      | `Ktav::format` —— 保留注释、折叠空行、不动点、无杂注时与规范形式一致。 |
| `tests/ErrorEnvelopeSpec.php`  | `KtavException` 携带的九个结构化错误信封字段。 |
| `tests/ConformanceSpec.php`    | 针对 `ktav-lang/spec` 固定装置语料库的跨语言一致性对齐。 |
| `tests/CorpusGuardSpec.php`    | 看守语料库本身:每个类别非空,`valid/` 中每个固定装置恰有一个 `.canonical.ktav` 伴生文件。 |
| `tests/ReadmeDocCheckSpec.php` | 执行 README 写下的承诺,让文档无法悄悄落后于代码。 |

`tests/TestPaths.php` 是共享助手:它定位 `target/release/` 下构建好的
cabi 与 spec 固定装置,并遵循 `KTAV_LIB_PATH`。

### 2. 不要在绑定里重造格式

这个库刻意保持为薄封装。解析器和格式行为属于 Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)) —— 在那边改
等于一次更新所有语言绑定。这里只放 **PHP 特定的人体工学**
(FFI 加载器、JSON 线信封、异常面)。

如果改动需要格式变更,请先到
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) 讨论。

### 3. 公共 API 改动需标注兼容性

如果你动到 `Ktav\` 的导出项,请在 PR 描述里说明它属于:

- **semver 兼容**(新增、签名放宽、文档改动);或
- **semver 破坏性**(重命名 / 移除、签名变更、类型收紧)——
  这种情况下版本递增会落在下一个 MINOR,毕竟我们还处于 pre-1.0。

在同一个 PR 中更新 `root-docs/CHANGELOG/` 下的 CHANGELOG 源单元
(全部三个 `>>>>> lang=` 块)并重新生成产物。

### 4. 一个概念一次提交

提交应当原子化:bug 修复和它的测试一起、功能和它的测试一起、
重命名单独、重构单独。`git log --oneline` 应当读起来像 changelog。
不要给提交消息加 `feat:` / `fix:` 前缀 —— 这里不走 conventional commits。

### 5. 原生库与包步调一致

`src/NativeLib.php` 里的 `NativeLib::LIB_VERSION` **必须** 与用于切
版本的 git tag 一致。如果你提升了库版本,请在同一个提交里更新
`LIB_VERSION`。两者不一致会让使用方下载到与代码不匹配的原生库。

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

Rust C ABI crate(`crates/cabi/`)默认依赖 crates.io 上发布的 `ktav`。
要做本地跨仓库改动时,把 `Cargo.toml` 里的 `workspace.dependencies.ktav`
改成 `{ path = "../rust" }`。

### 构建

```bash
# 1. Build the native library for your host platform.
cargo build --release -p ktav-cabi

# 2. Point the loader at it.
export KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so"   # Linux
#      ="$PWD/target/release/libktav_cabi.dylib"             # macOS
#      ="$PWD/target/release/ktav_cabi.dll"                  # Windows

# 3. Pull the conformance fixtures (the spec/ submodule).
git submodule update --init
```

### 测试

```bash
composer install                                       # dev dependencies (Kahlan)
vendor/bin/kahlan                                      # full suite, verbose by default
vendor/bin/kahlan --spec=tests/ReadmeDocCheckSpec.php   # just one spec file
```

`tests/TestPaths.php` 在 `target/release/` 下解析原生库(遵循
`KTAV_LIB_PATH` 与 `CARGO_TARGET_DIR`),并在 `spec/` 子模块下找固定
装置。cabi 未构建时,每个需要它的规格都会 **跳过**,原因
`cabi not built — run cargo build --release -p ktav-cabi`;子模块缺失
时,一致性与语料库规格 **跳过**,原因
`spec submodule missing — run git submodule update --init`。因此裸
checkout 依然保持绿色;六个已知空 `array` 歧义的规范形式固定装置在
`tests/ConformanceSpec.php` 里按名跳过。

### Lint

```bash
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
npm ci && npm run docs:check
```

CI 运行相同的命令;推送前请在本地先跑一遍。

## 架构笔记

- **Wire 格式。** Rust 与 PHP 通过 FFI 边界交换 JSON,用
  `{"$i":"..."}` / `{"$f":"..."}` 包装带类型的整数 / 浮点。任意精度
  整数以数字串跨越边界;当强转可以往返时 ——
  `(string)(int) $digits === $digits` —— `WireJson`
  会把 `$i` 还原为 `int`,否则把数字字符串原样交还给调用方。
- **内存所有权。** Rust 分配输出缓冲;PHP 复制出字节后在 Rust 侧
  调用 `ktav_free`。没有缓冲会长期跨越 FFI 边界。
- **加载器。** `NativeLoader::resolve()` 按顺序挑选共享库:
  `KTAV_LIB_PATH` 环境变量 → 用户缓存
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`,按操作系统取
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` 作为
  缓存根)→ 一次性下载对应的 GitHub Release 资产,并存到同一路径。
  解析顺序与 Java / Go / .NET 绑定一致。
- **文档。** 公开的 Markdown 由 `root-docs/` 单元树经
  `@ktav-lang/polydoc`(`node scripts/build-docs.mjs`)生成 ——
  请编辑单元,绝不编辑生成的 `.md`。

## 发布流程

在 `main` 上打 `v<X.Y.Z>` tag。release workflow 会为六个目标
(`linux` amd64/arm64、`darwin` amd64/arm64、`windows` amd64/arm64)
交叉编译 `ktav_cabi` cdylib,并把每个二进制以 `NativeLoader`
为该平台构造的准确资产名附加到 GitHub Release
(`libktav_cabi-linux-amd64.so`、`libktav_cabi-linux-arm64.so`、
`libktav_cabi-darwin-amd64.dylib`、`libktav_cabi-darwin-arm64.dylib`、
`ktav_cabi-windows-amd64.dll`、`ktav_cabi-windows-arm64.dll`)。
`src/NativeLib.php` 里的 `NativeLib::LIB_VERSION` 必须与 tag 一致 ——
请在同一个提交里改掉它。

## 哲学

Ktav 的口号:**"做配置的朋友,不做配置的考官。"**
在提出新的 PHP 特定功能之前,先问:

- 它是否给读者增加了一条必须记住的新规则?
- 它能否放在用户代码里,而不是库里?
- 它是否侵蚀了"无类型魔法"原则?

新规则代价很高。拒绝一切不明确属于这里的东西。

## 语言政策

本仓库参与组织级的三语政策(EN / RU / ZH)。每篇公开的 Markdown
文档都由承载三种语言、以 `>>>>> lang=` 块组织的 `root-docs/`
单元树 **生成**:在同一个提交里更新全部三个块,运行
`node scripts/build-docs.mjs`,并让 CI 的 `--check` 强制字节一致。
参见
[`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md)。

### 贡献的许可

除非你另有明确声明,否则你有意提交以纳入本项目的任何贡献(按
Apache-2.0 许可证中的定义),均按 **MIT OR Apache-2.0** 双重许可,
不附加任何额外条款或条件。
