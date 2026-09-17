# ktav — PHP 绑定

[![Packagist](https://img.shields.io/packagist/v/ktav-lang/ktav?style=flat-square&logo=packagist&logoColor=white&label=Packagist)](https://packagist.org/packages/ktav-lang/ktav)
[![CI](https://img.shields.io/github/actions/workflow/status/ktav-lang/php/ci.yml?style=flat-square&logo=github&label=CI)](https://github.com/ktav-lang/php/actions)
![License: MIT OR Apache-2.0](https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue?style=flat-square)
[![Playground](https://img.shields.io/badge/playground-try%20online-7c3aed?style=flat-square&logo=rocket&logoColor=white)](https://ktav-lang.github.io/)

**Languages:** [English](../../README.md) · [Русский](../ru/README.ru.md) · **简体中文**

**演练场：** 在浏览器中互转 JSON / YAML / TOML / INI ⇄ Ktav — **[ktav-lang.github.io](https://ktav-lang.github.io/)**。

[Ktav 配置格式](https://github.com/ktav-lang/spec) 的 PHP 绑定。
在参考 Rust 解析器之上的一层薄封装,运行时通过
**[PHP FFI 扩展](https://www.php.net/manual/zh/book.ffi.php)**
加载 —— 无需编译 PHP 扩展、无需 PECL,普通 Composer 依赖即可。
原生二进制在首次调用时下载。

需要 **PHP 7.4+**,并启用 `ext-ffi`(CLI 默认开启;Web SAPI
需要在 `php.ini` 设 `ffi.enable=1`)。

## 安装

```bash
composer require ktav-lang/ktav
```

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

完整可运行示例:[`examples/basic.php`](../../examples/basic.php)。

## API

| 方法 | 用途 |
| --- | --- |
| `Ktav::loads(string $src): mixed` | 解析 Ktav 文档。 |
| `Ktav::loadsStrict(string $src): mixed` | 使用严格数字词法检查解析文档。 |
| `Ktav::dumps(array $value): string` | 将关联数组渲染为 Ktav 文本。 |
| `Ktav::format(string $src): string` | 规范文档写法,同时保留注释。 |
| `Ktav::nativeVersion(): string` | 已加载 `ktav_cabi` 的版本。 |

### 格式化

`Ktav::format()` 接受 Ktav **源文本**并返回 Ktav 源文本。它把结构规范到
规范形式(§ 5.9),同时保留规范写入器会丢弃的附属内容:

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

每条注释都逐字保留 —— Ktav 没有行尾注释(§ 3.4:注释占据整行),因此
归属毫无歧义。空行作为分组提示保留,但连续两行及以上会折叠为恰好一行,
紧贴括号内侧的空行填充会被丢弃;正因如此该变换是不动点 —— 对已格式化的
文本再次格式化不会有任何改变。键序永不改变:规范形式没有排序规则,而
重排键只会让评审差异更糟。

### 错误

解析或渲染出错时抛出 `KtavException`。除了人类可读的 `getMessage()`,
它还携带核心错误信封的九个结构化字段:

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      —— 书写形式
    $e->getCanonical();    // "1.1"       —— 存储形式
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

完整集合为 `getError()`、`getReason()`、`getErrorLine()`、
`getLineText()`、`getSpan()`、`getPath()`、`getBody()`、
`getCanonical()`、`getSpecSection()`。缺失的信息是 `null`,而不是缺少
访问器,因此调用方无需先判断错误类别即可读取任一字段。

`getPath()` 是**精确解码后的键段数组,绝不是拼接字符串**:字面名为
`a.b` 的键是一个段,不会与两段路径混淆。

写入器的两种拒绝分别命名:能指出出错节点时为 `"UnrepresentableAt"`
(此时也会填充 `getPath()`),不能指出时为 `"Unrepresentable"`。两者的
原因码相同,因此若只需知道「写入被拒绝」,匹配 `getReason()` 就够了。

使用 `getErrorLine()` 而非 `getLine()`,因为 PHP 的
`Exception::getLine()` 被声明为 `final`。

## 类型映射

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| 裸整数           | `int`(若超出范围则为 `string` —— PHP 没有原生 bigint,需要 GMP / BCMath 自己包装)。 |
| 裸小数           | `float`                                              |
| 其他标量         | `string`                                             |
| `[ ... ]`        | 顺序 `array`                                         |
| `{ ... }`        | 关联 `array`(保留插入顺序)                          |

要发送任意精度整数,请自行包装:
`['big' => ['$i' => '9999999999999999999']]` —— 与 PHP 和原生侧
之间的 wire 格式一致。

## 键的转义

自 spec 0.6.4 起,键段内的字面量 `.` 或 `:` 通过反斜杠书写:

```text
a\.b: v        # 键是单个段 "a.b"        → ["a.b" => "v"]
a\:b: v        # 键中包含冒号            → ["a:b" => "v"]
x.y\.z: v      # 只按第一个点切分        → ["x" => ["y.z" => "v"]]
```

键中的字面量反斜杠写作 `\\`。

## 原生库的查找顺序

首次调用时:

1. **`KTAV_LIB_PATH`** 环境变量(若设置)。
2. **用户缓存** —— `<userCache>/ktav-php/v<版本>/<资产>`。
3. **从 GitHub Release 下载** —— 一次性从
   `github.com/ktav-lang/php/releases/download/v<版本>/<名称>`
   下载并缓存到 (2)。安装后首次调用需要网络。

`<userCache>` 在 Windows 是 `%LOCALAPPDATA%`,macOS 是
`~/Library/Caches`,Linux 是 `$XDG_CACHE_HOME` 或 `~/.cache`。

## 运行时支持

- PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3+。CI 在 LTS 上每次都跑。
- 预编译二进制覆盖:`linux/amd64`、`linux/arm64`、`darwin/amd64`、
  `darwin/arm64`、`windows/amd64`、`windows/arm64`。
- Linux 需 glibc 2.17+(zigbuild 基线)。Alpine(musl)已规划。

## 许可证

MIT OR Apache-2.0 —— 见 [LICENSE-MIT](../../LICENSE-MIT) 和 [LICENSE-APACHE](../../LICENSE-APACHE)。

## 其他 Ktav 实现

- [`spec`](https://github.com/ktav-lang/spec) —— 规范 + 一致性测试套件
- [`rust`](https://github.com/ktav-lang/rust) —— 参考 Rust crate(`cargo add ktav`)
- [`csharp`](https://github.com/ktav-lang/csharp) —— C# / .NET(`dotnet add package Ktav`)
- [`golang`](https://github.com/ktav-lang/golang) —— Go(`go get github.com/ktav-lang/golang`)
- [`java`](https://github.com/ktav-lang/java) —— Java / JVM(`io.github.ktav-lang:ktav`,Maven Central)
- [`js`](https://github.com/ktav-lang/js) —— JS / TS(`npm install @ktav-lang/ktav`)
- [`python`](https://github.com/ktav-lang/python) —— Python(`pip install ktav`)
