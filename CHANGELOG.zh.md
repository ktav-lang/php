# Changelog

**语言:** [English](CHANGELOG.md) · [Русский](CHANGELOG.ru.md) · **简体中文**

Java 绑定的所有显著变更记录于此。格式基于
[Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/);版本号遵循
[Semantic Versioning](https://semver.org/),采用 pre-1.0 约定:
MINOR 递增视为破坏性变更。

本 changelog 跟踪 **绑定发布**,不覆盖 Ktav 格式自身的变更 ——
后者见 [`ktav-lang/spec`](https://github.com/ktav-lang/spec/blob/main/CHANGELOG.md)。

## [0.6.1] — 2026-06-05

- 文档：将所有 README 示例改写为 spec 0.6 语法（裸数字替代已移除的 `:i`/`:f` 标记；`##` 注释替代 `#`）。

## 0.6.0 —— 2026-06-01

同步至 Ktav 0.6.0 —— 键现在支持转义。

### 新增

- 键处理完整的 §3.7 转义集合,并新增两个转义:
  - `\.` → `.`(字面量点 —— **不**会切分 dotted-path)
  - `\:` → `:`(字面量冒号 —— **不**作为键/值分隔符)
- 示例: `a\.b: v` → `{"a.b": "v"}`,`a\:b: v` → `{"a:b": "v"}`,
  `x.y\.z: v` → `{"x": {"y.z": "v"}}`。

### 破坏性变更

- 键中的字面量反斜杠现在需要写作 `\\`(此前键中的 `\` 是普通字节)。
  实际使用中很少出现;按 pre-1.0 SemVer 为 MINOR bump。

### 变更

- 跟踪 ktav-rust 0.6.0 / Ktav 规范 0.6.0。绑定源码未改动 —— escape
  语义的变化完全在 Rust 内核中实现,通过 FFI 边界对调用方透明。

---

## 0.5.0 —— 2026-05-28

实现 Ktav 规范 0.5.0。跟踪 ktav-rust 0.5.0。

### 破坏性变更

- 类型标记 `:i` / `:f` 已移除。数字、布尔值和 `null` 根据词法形式推断
  (规范 §§ 3.6, 5.2)。写 `port: 8080` 得到 Integer，写 `port:: 8080`
  保留 String。
- 注释现在使用 `##`。单个 `#` 字节是内容，不是注释。
- 裸整数和浮点数不再解析为 String。
- 键的首尾空格现在被裁剪。

### 新增

- **内联复合类型** `{k: v, …}` / `[i, …]`（规范 § 5.8）。
- **八个转义序列**（规范 § 3.7）。
- **`Ktav::emitCanonical($value)`** — 渲染为确定性规范 Ktav 形式
  （规范 § 7）。

### 变更

- 许可证：MIT → MIT OR Apache-2.0（`LICENSE-MIT` + `LICENSE-APACHE`）。
- spec 子模块：v0.5.0。
- ktav-rust 依赖：0.5.0。

---

## 0.1.2 —— 2026-05-03

### 变更

- **已采用 `ktav 0.1.5`** —— 上游 Rust crate 引入了结构化错误 API
  (`Error::Structured(ErrorKind)` 带字节偏移 span)、对错误枚举追溯
  应用了 `#[non_exhaustive]`,以及公开的事件式解析器 `ktav::thin`。
  PHP 绑定对用户可见的行为没有变化:`KtavException` 仍携带相同的
  人类可读消息(七个标准类别的 Display 字符串与 ktav 0.1.4 完全
  字节相同,由 ktav 自己的 pinning 测试验证)。将 `ktav::ErrorKind`
  映射到结构化 PHP 异常层级(`KtavMissingSeparatorSpaceException`、
  `KtavDuplicateKeyException` 等)是单独的后续工作,记录在
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md)。

Packagist:`ktav-lang/ktav` ^0.1.2。

## 0.1.1 —— 2026-04-26

### 变更

- **升级到 `ktav 0.1.4`** —— 上游 Rust crate 中 `cabi` 使用的 untyped
  `parse() → Value` 路径,小文档加速约 30%、大文档加速约 13%,只是
  `Frame::Object` 的初始容量微调(4 → 8)。每次 `Ktav::loads` 都会
  透明地受益。
- **`Ktav::dumps([])` 现在渲染空文档**,不再抛异常。此前
  list/object 消歧逻辑会拒绝空数组,这与 cabi 的「接受空对象」
  语义不一致。
- **`NativeLoader::download` 在 rename 之前 flush + fsync** 临时文件
  —— 中途崩溃不再可能留下被截断的缓存库。
- **`fopen` 失败时提示检查 `allow_url_fopen=Off`**(php.ini),—— 这是
  锁定环境下最常见的首次运行陷阱。
- **去除 `NativeLib::CDEF` 中冗余的 `typedef`**(PHP-FFI 原生认识
  `uint8_t` / `size_t`,且 `size_t` 的 typedef 在 32 位平台上是错的)。
- 测试已从 PHPUnit 迁移到
  **[Kahlan](https://kahlan.github.io/docs/)**(BDD 风格的
  `describe` / `it` 规范)。`composer require-dev` 现在拉取
  `kahlan/kahlan` 而非 `phpunit/phpunit`;
  `composer test`(或 `vendor/bin/kahlan`)替换
  `vendor/bin/phpunit`。覆盖范围相同 —— smoke 规范 + 来自
  submodule 的全部 Ktav 0.1 `valid/` + `invalid/`
  conformance 套件。

## 0.1.0 —— 首次公开发布

首次发布。目标格式版本:**Ktav 0.1**。

### 构件坐标

group/name:`io.github.ktav-lang:ktav`。Maven Central 发布 ——
已规划;在此之前 JAR 作为 GitHub Release 资产分发。

### 公共 API

- `Ktav.loads(String) -> Value` —— 解析 Ktav 文档。
- `Ktav.dumps(Value) -> String` —— 将 `array` 渲染为 Ktav 文本。
- `Ktav.nativeVersion() -> String` —— 已加载 `ktav_cabi` 的版本。
- `KtavException` —— 解析/渲染错误,消息来自原生侧。
- `array` —— 七变体的 sealed 接口 (`Null`、`Bool`、`Int`、`Flt`、
  `Str`、`Arr`、`Obj`),与 Rust crate 的 `array` 枚举一一对应。

### 架构

- **原生核心** —— 参考 Rust crate `ktav`,通过极简的 `extern "C"` C
  ABI (`crates/cabi`) 封装,分发为预编译的 `.so` / `.dylib` / `.dll`。
- **Java 加载器** —— FFI(使用方无需 JNI 编译):库在首次调用时
  从 `$KTAV_LIB_PATH` 解析,或从对应的 GitHub Release 资产一次性
  下载到用户缓存。
- **Wire 格式** —— Rust 与 Java 之间使用 JSON,带有
  `{"$i":"..."}` / `{"$f":"..."}` 标记包装,实现带类型的
  整数/浮点无损往返及任意精度整数 (`BigInteger`)。

### 类型映射

| Ktav             | `array` 变体                                             |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int`(文本形式 —— 任意精度)                      |
| `:f <number>`    | `Value.Flt`(文本形式 —— 精确往返)                      |
| 裸 scalar        | `Value.Str`                                             |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

### 平台

预编译原生二进制覆盖:

- `linux/amd64`、`linux/arm64`(glibc)
- `darwin/amd64`、`darwin/arm64`
- `windows/amd64`、`windows/arm64`

Alpine(musl) —— 计划在后续版本加入。

### 测试覆盖

在 JDK 17 / 21 × Linux / macOS / Windows 上运行完整的
Ktav 0.1 conformance 套件(所有 `valid/` 与 `invalid/` fixture)。

### 致谢

基于参考 Rust crate `ktav` 构建。动态加载通过
[FFI](https://github.com/java-native-access/jna)。Streaming JSON
通过 [Jackson](https://github.com/FasterXML/jackson-core)。
