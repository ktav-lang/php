# 为 ktav(Java)做贡献

**语言:** [English](CONTRIBUTING.md) · [Русский](CONTRIBUTING.ru.md) · **简体中文**

## 核心规则

### 1. 每个 bug 修复都带回归测试

发现 bug 时,**在修复之前** 先写一个复现它的测试 —— 测试在
`main` 分支上 **必须失败**,修复之后才通过。两者放在同一个 PR。

测试位于 `lib/src/test/java/lang/ktav/`:

| 文件                   | 范围                                             |
| ---------------------- | ------------------------------------------------ |
| `SmokeTest.java`       | Loads / Dumps 主路径、BigInteger、错误面。       |
| `ConformanceTest.java` | 对齐 `ktav-lang/spec` 的一致性测试。             |

### 2. 不要在绑定里重造格式

这个 Java 库刻意保持为薄封装。解析器和格式行为属于 Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust))—— 改那里
等于同步更新所有语言绑定。这里仅收 **Java 特定的人体工学**
(`array` 类型树、FFI 加载器、缓存 / 下载逻辑)。

如果改动需要格式变更,先去
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) 讨论。

### 3. 公共 API 改动需标注兼容性

若动到 `lang.ktav` 的导出项,请在 PR 描述里说明:

- **semver 兼容**(新增、签名放宽、文档改动);或
- **semver 破坏性**(重命名 / 删除、签名变更、类型收紧)——
  在 pre-1.0 阶段将会导致下一个 MINOR 递增。

在同一个 PR 中更新 `CHANGELOG.md` 与两个翻译。

### 4. 一个概念一次提交

提交要保持原子:bug 修复与其测试一起、新功能与其测试一起、
重命名单独、重构单独。`git log --oneline` 应当读起来像 changelog。
不要使用 `feat:` / `fix:` 前缀 —— 这里不走 conventional commits。

### 5. 原生库与 JAR 步调一致

`lib/src/main/java/lang/ktav/internal/NativeLoader.java` 中的
`LIB_VERSION` 常量 **必须** 与发布用的 git tag 相同。升级版本时
请在同一提交里更新 `LIB_VERSION`。不一致会让使用方下载到与代码
不匹配的原生库。

## 开发环境

你需要:

- JDK **17+**。
- 通过 [`rustup`](https://rustup.rs/) 安装的 Rust 工具链。MSRV:**1.70**。
- `git`。

Gradle 已随仓库附带 wrapper(`./gradlew`),无需单独安装。

本地开发的目录布局 —— Java 通过 FFI 加载 Rust 构建的
`ktav_cabi` cdylib。克隆相邻仓库或初始化 submodule:

```
ktav-lang/
├── java/     ← 本仓库
├── rust/     ← 相邻 Rust crate(本地改动用 path-dep)
└── spec/     ← 一致性 fixture(git submodule,挂在 java/spec/)
```

Rust C ABI crate(`crates/cabi/`)默认依赖 crates.io 上发布的
`ktav`。本地跨仓库改动时,把 `Cargo.toml` 中
`workspace.dependencies.ktav` 改为 `{ path = "../rust" }`。

### 构建

```bash
# 1. 为当前平台构建原生库。
cargo build --release -p ktav-cabi

# 2. 告诉 Java 库位置。
export KTAV_LIB_PATH="$PWD/target/release/libktav_cabi.so"   # Linux
#      ="$PWD/target/release/libktav_cabi.dylib"             # macOS
#      ="$PWD/target/release/ktav_cabi.dll"                  # Windows

# 3. 一致性测试需要 spec submodule。
git submodule update --init
export KTAV_SPEC_ROOT="$PWD/spec/versions/0.1/tests"
```

### 测试

```bash
./gradlew :lib:test                                        # 完整套件
./gradlew :lib:test --tests '*SmokeTest*'                  # 按类过滤
./gradlew :lib:test --tests '*ConformanceTest*'            # 仅 spec
```

如果 `KTAV_LIB_PATH` 或 `KTAV_SPEC_ROOT` 未设置,相关测试会
**跳过** 而非失败。

### Lint

```bash
./gradlew :lib:compileJava
cargo fmt --all --check
cargo clippy --release -p ktav-cabi -- -D warnings
```

## 架构笔记

- **Wire 格式。** Rust 与 Java 间使用 JSON,并用
  `{"$i":"..."}` / `{"$f":"..."}` 包装带类型的整数 / 浮点。
- **内存所有权。** Rust 分配输出缓冲,Java 复制到 `byte[]` 后
  立即调 `ktav_free` 释放。缓冲不会长期跨越 FFI。
- **加载器。** `lang.ktav.internal.NativeLib` 通过 FFI 的
  `Native.load` 每进程 dlopen 一次。路径由
  `NativeLoader.resolve()` 决定 —— env / 缓存 / 下载。

## 发布流程

在 `main` 上打 `v<X.Y.Z>` tag。Release workflow 交叉编译六个
原生二进制 + 构建库 JAR,全部作为 GitHub Release 资产。
`NativeLoader.java` 中的 `LIB_VERSION` 必须与 tag 一致 —— 请在
同一提交里更新。

## 哲学

Ktav 的口号:**"做配置的朋友,不做配置的考官。"**
在提出 Java 特定功能前,问自己:

- 它是否给读者增加了一条需要记住的新规则?
- 它能否放在用户代码里而不是库里?
- 它是否侵蚀了"无类型魔法"原则?

## 语言政策

本仓库参与组织级三语政策(EN / RU / ZH)。命名约定和
"三份一并更新"规则见
[`ktav-lang/.github/AGENTS.md`](https://github.com/ktav-lang/.github/blob/main/AGENTS.md)。
