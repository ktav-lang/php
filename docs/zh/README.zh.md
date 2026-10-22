# ktav — PHP 绑定

[![Packagist](https://img.shields.io/packagist/v/ktav-lang/ktav?style=flat-square&logo=packagist&logoColor=white&label=Packagist)](https://packagist.org/packages/ktav-lang/ktav)
[![CI](https://img.shields.io/github/actions/workflow/status/ktav-lang/php/ci.yml?style=flat-square&logo=github&label=CI)](https://github.com/ktav-lang/php/actions)
![License: MIT OR Apache-2.0](https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue?style=flat-square)
[![Playground](https://img.shields.io/badge/playground-try%20online-7c3aed?style=flat-square&logo=rocket&logoColor=white)](https://ktav-lang.github.io/)

**语言:** [English](../../README.md) · [Русский](../ru/README.ru.md) · **简体中文**

**演练场：** 在浏览器中互转 JSON / YAML / TOML / INI ⇄ Ktav —— **[ktav-lang.github.io](https://ktav-lang.github.io/)**。

[Ktav 配置格式](https://github.com/ktav-lang/spec) 的 PHP 绑定。
在参考 Rust 解析器之上的一层薄封装，运行时通过
**[PHP FFI 扩展](https://www.php.net/manual/en/book.ffi.php)** 加载 ——
无需编译 PHP 扩展，也无需 PECL，普通 Composer 依赖即可，
原生二进制在首次调用时下载。

需要 **PHP 7.4+** 和 `ext-json`；仅默认原生后端需要 `ext-ffi`（CLI 默认开启；Web SAPI
需要在 `php.ini` 中设置 `ffi.enable=1`）。

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

## 快速开始

### 解析 —— 直接从数组按类型读取字段

```php
use Ktav\Ktav;

$src = <<<KTAV
service: web
port: 8080
ratio: 0.75
tls: true
tags: [
    prod
    eu-west-1
]
db.host: primary.internal
db.timeout: 30
KTAV;

$cfg = Ktav::loads($src);

$service   = $cfg['service'];        // string
$port      = $cfg['port'];           // int
$ratio     = $cfg['ratio'];          // float
$tls       = $cfg['tls'];            // bool
$tags      = $cfg['tags'];           // array<string>
$dbHost    = $cfg['db']['host'];     // string
$dbTimeout = $cfg['db']['timeout'];  // int
```

### 构建并渲染 —— 用代码搭建文档

```php
$doc = [
    'name'  => 'frontend',
    'port'  => 8443,
    'tls'   => true,
    'ratio' => 0.95,
    'upstreams' => [
        ['host' => 'a.example', 'port' => 1080],
        ['host' => 'b.example', 'port' => 1080],
    ],
    'notes' => null,
];
$text = Ktav::dumps($doc);
```

完整可运行示例见 [`examples/basic.php`](../../examples/basic.php)。

## API

| 方法 | 用途 |
| --- | --- |
| `Ktav::loads(string $src): mixed` | 解析 Ktav 文档。 |
| `Ktav::loadsStrict(string $src): mixed` | 解析文档，并严格检查数字写法。 |
| `Ktav::dumps(array $value): string` | 将关联数组渲染为 Ktav 文本。 |
| `Ktav::dumpsForceStrings(array $value): string` | 与 `dumps` 相同，但把每个叶子标量强制为 String。 |
| `Ktav::emitCanonical(array $value): string` | 将值渲染为确定性的规范形式。 |
| `Ktav::format(string $src): string` | 规范文档写法，同时保留注释。 |
| `Ktav::canonicalFromSource(string $src): string` | 直接将源文本规范化，并保留 PHP 值表示可能折叠的复合结构（例如 `a: {}` 仍为 `a: {}`，而 `loads` 会把空 Object 表示为 `[]`）。丢弃注释和空行。 |
| `Ktav::nativeVersion(): string` | 已加载 `ktav_cabi` 的版本。 |

`dumpsForceStrings` 把整数、float、布尔与 `null` 用原始标记（`::`）
压平为文本形式；对象与数组保持自身结构，因为只有叶子会被强制。
结果经由 `loads` 解析回来仍是同一组 String 标量 ——
当下游消费方只接受字符串值时，
这很有用。

## 纯 PHP 后端

`Ktav\PureBackend` 是 Ktav 的纯 PHP 完整解析器与写入器实现，
不加载原生库、WASM 模块或子进程。两个后端始终同时可用：每次调用
显式选择（`PureBackend::loads($src)` 或 `Ktav::loads($src)`）。
没有全局后端开关，任何方向都不会静默回退 —— 选择纯模式不会触及
FFI，选择原生模式也不会执行 PHP 解析。

操作面与 `Ktav` 完全一致：`loads`、`loadsStrict`、`dumps`、
`dumpsForceStrings`、`emitCanonical`、`format`、
`canonicalFromSource`，另有 `version()`（纯后端标记，绝不是原生
版本）与 `isAvailable()`。错误同样是 `KtavException` 九字段信封，
带字节偏移区间和从 1 开始的行号。值模型与原生路径一致：
关联数组 = Object，顺序列表 = Array，`['$i' => '<数字>']`
表示大 Integer，`['$f' => '<文本>']` 表示精确 Float 载荷。

已记录的差异：

- `InvalidUtf8` 错误遵循 Rust 原始信封：行号、带偏移的消息，
  以及覆盖损坏字节的非空字节区间。
- `emitCanonical` 与原生 `dumps` 一样会丢失空 Object/Array 的
  身份（空 PHP 数组即空关联数组）；若需保留空复合结构的身份，
  请使用 `canonicalFromSource`。
- 纯后端只需要 `ext-json`（始终内建）—— 无需 FFI 扩展，也无需
  原生二进制；`PureBackend::isAvailable()` 恒为 `true`。

### 格式化

`Ktav::format()` 接受 Ktav **源文本**，并返回 Ktav 源文本。
它把结构规范到规范形式（§ 5.9），
同时保留规范写入器会丢弃的附属内容：

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

每条注释都逐字保留 —— Ktav 没有行尾注释（§ 3.4：
注释占据整行），因此归属毫无歧义。空行作为分组提示保留，
但连续两个及以上会折叠为恰好一个，紧贴括号内侧的空行
填充则被丢弃；正因如此该变换是不动点 —— 对已格式化的
文本再次格式化不会有任何改变。键序永不改变：规范形式
没有排序规则，
而重排键只会让评审差异更糟。

### 错误

解析或渲染失败时抛出 `KtavException`。
除了人类可读的 `getMessage()`，它还携带
核心错误信封的九个结构化字段：

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — as written
    $e->getCanonical();    // "1.1"       — as it would be stored
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

完整集合为 `getError()`、`getReason()`、`getErrorLine()`、
`getLineText()`、`getSpan()`、`getPath()`、`getBody()`、
`getCanonical()`、`getSpecSection()`。缺失的信息是 `null`，
而不是缺少访问器，因此调用方无需先判断错误类别
即可读取任一字段。

`getPath()` 是**精确解码后的键段数组，
绝不是拼接字符串**：字面名为 `a.b` 的键是
单个段，不会与两段路径混淆。

写入器的两种拒绝分别命名：能指出出错节点时为
`"UnrepresentableAt"`（该节点也会填入 `getPath()`），
不能指出时为 `"Unrepresentable"`。两者的 `reason` 码
相同，因此若只需知道「写入被拒绝」，
匹配 `getReason()` 就够了。

使用 `getErrorLine()` 而非 `getLine()`，因为 PHP 的
`Exception::getLine()` 被声明为 `final`。

## 类型映射

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| 裸整数           | `int`（放得下时），否则 `string`（PHP 没有原生 bigint —— 需要运算请自行包装 GMP / BCMath）。 |
| 裸小数           | `float`                                              |
| 其他标量         | `string`                                             |
| `[ ... ]`        | 顺序 `array`                                         |
| `{ ... }`        | 关联 `array`（保留插入顺序）                          |

要输出任意精度整数，请自行包装数字字符串：
`['big' => ['$i' => '9999999999999999999']]` —— 与 PHP 和原生侧之间的
wire 信封相同。

`Ktav\PureBackend`（纯 PHP）使用相同的值模型与相同的 `'$i'` / `'$f'` 约定。

## 键的转义

自 spec 0.6.4 起，键段内的字面量 `.` 或 `:`
以反斜杠书写：

```text
## 键由单个段 "a.b" 组成 → ["a.b" => "v"]
a\.b: v
## 键中包含冒号 → ["a:b" => "v"]
a\:b: v
## 仅按第一个点拆分 → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

键中的字面量反斜杠写作 `\\`。

## 原生库的查找顺序

首次调用时：

1. **`KTAV_LIB_PATH`** 环境变量（若已设置）。
2. **用户缓存** —— `<userCache>/ktav-php/v<version>/<asset>`，在此前某次调用中
   下载。
3. **从 GitHub Release 下载** —— 一次性从
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` 获取并
   缓存到 (2)。安装后的首次调用需要网络。

`<userCache>` 在 Windows 上是 `%LOCALAPPDATA%`，macOS 上是
`~/Library/Caches`，Linux 上是 `$XDG_CACHE_HOME` 或 `~/.cache`。

版本 0.8.1 的原生资源按上述步骤解析。覆盖路径与缓存的二进制在加载时会被校验：加载的库必须报告版本 0.8.1，否则抛出 KtavException 并指明期望与实际版本。纯 PHP 后端
（`Ktav\PureBackend`）完全绕过这条链 —— 即使 `ext-ffi` 被禁用，
它也不需要原生库。

## 运行时支持

- 支持的 PHP 版本：7.4 / 8.0 / 8.1 / 8.2 / 8.3+。CI 在 Linux、macOS 和 Windows 上测试 PHP 7.4、8.2 和 8.3。
- 预编译二进制覆盖：`linux/amd64`、`linux/arm64`、`darwin/amd64`、
  `darwin/arm64`、`windows/amd64`、`windows/arm64`。
- Linux 发行版需 glibc 2.17+（zigbuild 基线）。Alpine（musl）
  支持已在计划中。

## 许可证

MIT OR Apache-2.0 —— 见 [LICENSE-MIT](../../LICENSE-MIT) 和 [LICENSE-APACHE](../../LICENSE-APACHE)。

## 其他 Ktav 实现

- [`spec`](https://github.com/ktav-lang/spec) —— 规范 + 一致性测试套件
- [`rust`](https://github.com/ktav-lang/rust) —— 参考 Rust crate（`cargo add ktav`）
- [`csharp`](https://github.com/ktav-lang/csharp) —— C# / .NET（`dotnet add package Ktav`）
- [`golang`](https://github.com/ktav-lang/golang) —— Go（`go get github.com/ktav-lang/golang`）
- [`java`](https://github.com/ktav-lang/java) —— Java / JVM（`io.github.ktav-lang:ktav`，Maven Central）
- [`js`](https://github.com/ktav-lang/js) —— JS / TS（`npm install @ktav-lang/ktav`）
- [`python`](https://github.com/ktav-lang/python) —— Python（`pip install ktav`）
