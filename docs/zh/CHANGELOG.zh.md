# 变更日志

**语言:** [English](../../CHANGELOG.md) · [Русский](../ru/CHANGELOG.ru.md) · **简体中文**

PHP 绑定的所有显著变更都记录在此。格式基于
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/);版本号遵循
[Semantic Versioning](https://semver.org/),并采用 pre-1.0 约定:
MINOR 递增即破坏性变更。

本 changelog 跟踪 **绑定发布**,不涵盖 Ktav 格式自身的变更 ——
后者见
[`ktav-lang/spec`](https://github.com/ktav-lang/spec/blob/main/CHANGELOG.md)。

## Unreleased

## 0.8.0 — 2026-09-24

### 新增

- **`Ktav::format(string $src): string`** —— 一个保留注释的格式化器,
  作用于 Ktav *源文本*,而不是把值渲染成文本。它把文档的结构写法规范到
  规范形式(§ 5.9),同时保留规范写入器会丢弃的琐碎内容。每条注释都逐字
  保留:Ktav 没有行尾注释(§ 3.4,注释独占整行),因此归属毫无歧义。空行
  作为分组提示保留,但连续两行及以上会折叠为恰好一行,紧贴括号内侧的
  空填充会被丢弃 —— 正是这一点让该变换成为不动点:
  `Ktav::format(Ktav::format($s)) === Ktav::format($s)`。键序永不改变
  (规范形式没有排序规则)。对于既无注释*也无空行*的文档,结果等于
  `Ktav::canonicalFromSource($src)`;这个更强的条件是刻意的,
  因为空行与注释一样,都不属于值模型。

  ```php
  Ktav::format("## the server\nserver: {host: a, port: 80}\n");
  // ## the server
  // server: {
  //     host: a
  //     port: 80
  // }
  ```

- **`KtavException` 现在携带来自 Rust 核心错误信封的九个结构化字段**:
  `getError()`、`getReason()`、`getErrorLine()`、`getLineText()`、
  `getSpan()`、`getPath()`、`getBody()`、`getCanonical()`、
  `getSpecSection()`。缺失的信息是 `null`,而不是缺少访问器。

  `getPath()` 返回**精确解码后的键段数组,绝不是拼接字符串** ——
  字面名为 `a.b` 的键就是一个段,不会与两段路径混淆。

  ```php
  try {
      Ktav::loadsStrict("a: 1.10\n");
  } catch (KtavException $e) {
      $e->getError();        // "LossyScalar"
      $e->getBody();         // "1.10"
      $e->getCanonical();    // "1.1"
      $e->getSpecSection();  // "§3.6/§5.2"
  }
  ```

  信封把写入器的两种拒绝分别命名:能指出问题节点位置时为
  `"UnrepresentableAt"`(此时也会填充 `getPath()`),不能指出时为
  `"Unrepresentable"`。两者的原因码完全相同,因此只需知道「写入被
  拒绝」的调用方匹配 `getReason()` 即可。

  用 `getErrorLine()` 而不是 `getLine()`,因为 PHP 的
  `Exception::getLine()` 是 `final`。

- **`Ktav::canonicalFromSource(string $src): string`** 直接规范化源文本,
  保留经 PHP 值转换会丢失的空 Object 与空 Array 之别。

### 变更

- **错误消息优先使用核心提供的 `message` 字段。** 对于 0.8.0 核心,这会
  原样保留其 `Display` 输出;不含该字段的旧核心则回退到由信封字段构造的
  消息。`getMessage()` 仍然人类可读,且绝不会是原始 JSON。

- PHP 层对无效 UTF-8、标量根节点和非有限 Float 的错误现在携带规范中的
  错误类别或写入拒绝原因。

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
- 带引号的键(§ 5.3.3)与 inline 值中的 `\uXXXX` 转义(§ 3.7.1)始于
  spec 0.7.0,并继续由 Rust 内核提供。MSRV 为 Rust 1.71;当前
  `[package.metadata.ktav] spec-version` 为 "0.8.0"。
- 一致性测试套件指向 `spec/versions/0.8/tests`(子模块重新固定到
  `0.8.0` 之后,它一直静默读取过期的 `0.7` 语料——路径是硬编码的,
  并非从固定版本推导而来),并执行语料中的每个类别:
  `unrepresentable/` 与 `parseable-unrepresentable/`(写入方必须拒绝
  fixture 的值 / 可正常解析但规范输出必须拒绝的值),以及新增的
  `strict-lossy/`(`loads()` 必须等于 lax 值,`loadsStrict()` 必须以
  匹配的原因、body 与规范形式抛出异常)。一个 guard 测试会在语料中
  出现无法识别的类别目录时使构建失败,以防止这个问题再次悄然发生。
### 已知限制

- **空的 Object 与空的 Array 在解析后无法区分。** PHP 对两种复合值只有
  一个 `array` 类型,*空*复合值在 `Ktav::loads()` 返回的那一刻就丢失了
  自己原本是哪一种:

  ```php
  Ktav::loads("a: {}\n");  // → ["a" => []]
  Ktav::loads("a: []\n");  // → ["a" => []] —— 相同的值
  ```

  写回时两者都输出为 `a: []`。在文档根部,歧义向相反方向消解:空的 PHP
  数组会输出为空 Object(`Ktav::dumps([])` → `{}`),因此顶层的 `[]`
  文档读回再渲染会变成 `{}`。存了 `a: {}` 并期望读回 `a: {}` 的配置
  实际会得到 `a: []`。

  非空复合值不受影响 —— object 与 array 的形状可由键的形式恢复。修复它
  需要在 PHP 侧用不同方式表示对象与列表(例如解码为 `stdClass`),这属于
  破坏性的公共 API 变更。一致性测试套件将六个受影响的 fixture 保留为
  带名字的跳过规范,而不是静默放宽其断言。

## 0.6.4 —— 2026-08-23

### 新增

- **`Ktav::loadsStrict(string $src): mixed`** —— 通过 PHP FFI 与
  `ktav_loads_strict` C ABI 符号暴露 strict numeric parsing。

### 变更

- 跟踪 `ktav 0.6.4` 与 spec 0.6.4,包括规范性的 float 规范化边界与
  `notation_boundaries` fixture。
- 原生库解析现在指向精确的 `v0.6.4` 发布资产。

## [0.6.1] —— 2026-06-05

- 文档:所有 README 示例改写为 spec 0.6 语法(裸数字替代已移除的 `:i`/`:f` 标记;`##` 注释替代 `#`)。

## [0.6.0] —— 2026-06-01

同步至 Ktav 0.6.0 —— 键现在支持转义。

### 新增

- 键处理完整的 §3.7 转义集合,并新增两个转义:
  - `\.` → `.`(字面量点 —— **不**会切分 dotted path)
  - `\:` → `:`(字面量冒号 —— **不**作为键/值分隔符)
- 示例:`a\.b: v` → `{"a.b": "v"}`,`a\:b: v` → `{"a:b": "v"}`,
  `x.y\.z: v` → `{"x": {"y.z": "v"}}`。

### 破坏性变更

- 键中的字面量反斜杠现在需要写成 `\\`(此前键中的 `\` 是普通字节)。
  实际使用中很少出现;按 pre-1.0 SemVer 这属于 MINOR bump。

### 变更

- 跟踪 ktav-rust 0.6.0 / Ktav 规范 0.6.0。绑定源码未改动 ——
  escape 语义的变化完全在 Rust 内核中实现,跨越 FFI 边界对调用方透明。

---

## [0.5.0] —— 2026-05-28

实现 Ktav 规范 0.5.0。跟踪 ktav-rust 0.5.0。

### 破坏性变更

- 类型标记 `:i` / `:f` 已移除。数字、布尔值与 `null` 根据词法形式推断
  (规范 §§ 3.6, 5.2)。写 `port: 8080` 得到 Integer,写 `port:: 8080`
  保留 String。
- 注释现在使用 `##`(独占一行)。单个 `#` 字节是内容,不是注释。
- 裸整数与浮点数不再解析为 String —— `port: 8080` 得到的是整数
  `8080`,而不是字符串 `"8080"`。
- 键段的首尾空白现在被裁剪。

### 新增

- **内联复合类型** `{k: v, …}` / `[i, …]`(规范 § 5.8)。
- **inline 标量中的八个转义序列**:`\\`、`\,`、`\}`、`\]`、`\{`、
  `\[`、`\n`、`\r`(规范 § 3.7)。
- **`Ktav::emitCanonical($value)`** —— 渲染为确定性的规范 Ktav 形式
  (规范 § 7),通过新增的 `ktav_emit_canonical` C ABI 导出。

### 变更

- 许可证:MIT → MIT OR Apache-2.0(`LICENSE-MIT` + `LICENSE-APACHE`)。
- spec 子模块:v0.5.0。
- ktav-rust 依赖:0.5.0。
- 一致性测试现在针对 `spec/versions/0.5/tests/` 运行。

---

## [0.3.1] —— 2026-05-10

### 新增

- **支持顶层 Array(规范 § 5.0.1)。** 首个内容行具有数组项形状(裸标量、
  `:: text`、`:i 42`、`:f 3.14`、单独的 `{` / `[`,或多行开括号 `(` /
  `((`)的文档,现在解析为顺序 PHP 列表。此前首行是裸标量时会报
  `MissingSeparator` 错误。空文档和只含注释的文档仍默认为空关联数组
  (保留 0.3.0 的行为)。
- **`Ktav::dumps()` 接受顶层 Array。** 根部的顺序 PHP 数组现在渲染为
  每项一行(不带 `[...]` 外层括号)。此前 `dumps([1,2,3])` 会抛异常 ——
  现在会成功。根部的裸标量仍被拒绝。
- **`Ktav::dumpsForceStrings($value)`** —— 把任意值的每个标量都强制为
  String 后输出:带类型的整数、带类型的浮点、布尔与 null 都被摊平成
  它们的文本形式(`42`、`3.14`、`true`、`null`),并通过原始标记 `::`
  写出,使输出再次经过解析器时仍得到相同的字符串标量。复合值保留其
  结构;只有叶子标量被强制。适合面向不理解 `:i` / `:f` 类型标记的下游
  消费者的“一切皆字符串”输出。

### 变更

- **已跟进 `ktav 0.3.1`** —— 上游 Rust crate 现在实现了顶层 Array 检测,
  并提供了 `ktav::to_string_force_strings`;两者都通过 cabi 层暴露
  (`ktav_dumps_force_strings`,以及 `ktav_dumps` 接受根部 Array)。参见
  [`ktav` crate 的 CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#031--2026-05-10)。

### 兼容性

纯新增。每个在 0.3.0 下合法的文档仍然合法,并产生相同的值。每个在
0.3.0 下合法的 `dumps` 调用返回相同的文本。只有 0.3.0 以
`MissingSeparator` 拒绝的输入现在会成功(作为顶层 Array),也只有此前
抛异常的 `dumps([1,2,3])` 式调用现在会成功。

### 规范

- spec 子模块同步至 **0.1.1**(提交 `7256816`)—— § 5.0.1 的顶层 Array
  检测、锚定的首行非法 fixture、澄清了 Array 内成对形状的行为。


## 0.3.0 —— 2026-05-08

### 变更(破坏性)

- **已跟进 `ktav 0.3.0`** —— 上游 Rust crate 现在会拒绝 `key: (value)`
  与 `key: ((value))`,报
  `ErrorKind::InlineNonEmptyCompound { body: "paren-string" }`。
  这些形状此前被当作普通字符串标量接受,但它们与多行开括号在视觉上
  无法区分。要编码此类字面量,请使用原始标记形式 `key:: (value)`;
  ktav-lsp 格式化器会在保存时自动重写旧形式。参见
  [`ktav` crate 的 CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#030--2026-05-08)。

### 修复

- `LIB_VERSION` 现在跟踪绑定的发布标签,因此运行时下载对应的
  `ktav_cabi-*` 资产,来自
  `https://github.com/ktav-lang/php/releases/download/v0.3.0/`。
  此前该常量即使到了 `0.2.0` 也固定在 `0.1.1`,加载器因此会取回
  较旧的 cabi 构建。

### 规范

- spec 子模块已同步(paren-string 处理收紧 —— 新增非法 fixture
  `inline_paren_string_single` / `inline_paren_string_double`;合法
  fixture `partial_parens` 已剔除新近非法的形式)。


## 0.2.0 —— 2026-05-07

### 变更(破坏性)

- **已跟进 `ktav 0.2.0`** —— 多行字符串现在默认序列化为缩进的
  剥离形式 `( ... )`。`:f 42` 接受整数字面量(解析为 `42.0`)。参见
  [`ktav` crate 的 CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#020--2026-05-07)。

  逐字节比对序列化输出与内置 `((...))` 字面量的代码需要更新。往返
  行为不变。

### 规范

- spec 子模块已同步(fixture typed_float_integer_body;oracle 42.0)。


## 0.1.2 —— 2026-05-03

### 变更

- **已跟进 `ktav 0.1.5`** —— 上游 Rust crate 现在提供了
  `Error::Structured(ErrorKind)`,带字节偏移 span,对错误枚举追溯应用
  `#[non_exhaustive]`,并给出公开的事件式解析器 `ktav::thin`。PHP 绑定
  对用户可见的行为没有变化:`KtavException` 仍携带相同的人类可读消息
  (七个标准类别的 `Display` 字符串与 ktav 0.1.4 逐字节相同 —— 由 ktav
  自己的 pinning 测试验证)。将 `ktav::ErrorKind` 映射到结构化的 PHP
  异常层级(`KtavMissingSeparatorSpaceException`、
  `KtavDuplicateKeyException` 等)是单独的后续工作,记录在工作区的
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md)。

Packagist:`ktav-lang/ktav` ^0.1.2。

## 0.1.1 —— 2026-04-26

### 变更

- **已升级到 `ktav 0.1.4`** —— 上游 Rust crate 中 `cabi` 所使用的 untyped
  `parse() → Value` 路径,在小文档上快约 30%,在大文档上快约 13%,仅来自
  `Frame::Object` 容量的一行微调(4 → 8)。每次 `Ktav::loads` 调用都透明
  受益。
- **`Ktav::dumps([])` 现在渲染空文档**,不再抛异常。此前 list/object
  消歧逻辑拒绝空数组,认为其含义不明,这与 cabi 的「接受空对象」语义不一致。
- **`NativeLoader::download` 在 rename 之前 flush + fsync** 临时文件 ——
  rename 中途崩溃不再可能表现为被截断的缓存库。
- **`fopen` 失败时提示 `allow_url_fopen=Off`**(php.ini)—— 这是锁定环境
  下最常见的首次运行困扰。
- **从 `NativeLib::CDEF` 中移除了冗余的 `typedef`**(PHP-FFI 原生认识
  `uint8_t` / `size_t`,且 `size_t` 的 typedef 在 32 位平台上是错的)。
- 测试已从 PHPUnit 迁移到
  **[Kahlan](https://kahlan.github.io/docs/)**(BDD 风格的 `describe` / `it`
  规范)。`composer require-dev` 现在拉取 `kahlan/kahlan` 而非
  `phpunit/phpunit`;`composer test`(或 `vendor/bin/kahlan`)取代
  `vendor/bin/phpunit`。覆盖范围相同 —— smoke 规范 + 来自 spec 子模块的
  全部 Ktav 0.1 `valid/` + `invalid/` 一致性套件。

## 0.1.0 —— 首次公开发布

首次发布。目标格式版本:**Ktav 0.1**。

### 构件坐标

构件的 group/name:`io.github.ktav-lang:ktav`。Maven Central 发布已
规划;在此之前 JAR 作为 GitHub Release 资产分发。

### 公共 API

- `Ktav.loads(String) -> Value` —— 解析 Ktav 文档。
- `Ktav.dumps(Value) -> String` —— 将 `array` 渲染为 Ktav 文本。
- `Ktav.nativeVersion() -> String` —— 已加载 `ktav_cabi` 的版本。
- `KtavException` —— 解析 / 渲染错误,消息来自原生侧。
- `array` —— 具有七种变体(`Null`、`Bool`、`Int`、`Flt`、`Str`、`Arr`、
  `Obj`)的关联数组,与 Rust crate 的 `array` 枚举一一对应。

### 架构

- **原生核心** —— 参考 Rust crate `ktav`,以极简的 `extern "C"` C ABI
  (`crates/cabi`) 封装,并以预编译的 `.so` / `.dylib` / `.dll` 分发。
- **Java 加载器** —— FFI(使用方无需 JNI 编译):库在首次调用时从
  `$KTAV_LIB_PATH` 解析,或一次性从对应的 GitHub Release 资产下载到用户
  缓存。
- **Wire 格式** —— Rust 与 Java 之间使用 JSON,带 `{"$i":"..."}` /
  `{"$f":"..."}` 标记包装,实现带类型的整数 / 浮点无损往返与任意精度
  整数(`BigInteger`)。

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

Alpine(musl)计划在后续版本加入。

### 测试覆盖

在 JDK 17 / 21 跨 Linux / macOS / Windows 上运行完整的 Ktav 0.1
一致性套件(所有 `valid/` 与 `invalid/` fixture)。

### 致谢

构建在参考 Rust crate `ktav` 之上。动态加载通过
[FFI](https://github.com/java-native-access/jna)。流式 JSON 通过
[Jackson](https://github.com/FasterXML/jackson-core)。
