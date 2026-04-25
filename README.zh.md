# ktav — PHP 绑定

**Languages:** [English](README.md) · [Русский](README.ru.md) · **简体中文**

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
port:i 8080
ratio:f 0.75
tls: true
tags: [
    prod
    eu-west-1
]
db.host: primary.internal
db.timeout:i 30
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

完整可运行示例:[`examples/basic.php`](examples/basic.php)。

## API

| 方法 | 用途 |
| --- | --- |
| `Ktav::loads(string $src): mixed` | 解析 Ktav 文档。 |
| `Ktav::dumps(array $value): string` | 将关联数组渲染为 Ktav 文本。 |
| `Ktav::nativeVersion(): string` | 已加载 `ktav_cabi` 的版本。 |

解析 / 渲染出错时抛出 `KtavException`(消息为原生侧的 UTF-8 字符串)。

## 类型映射

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| `:i <digits>`    | `int`(若超出范围则为 `string` —— PHP 没有原生 bigint,需要 GMP / BCMath 自己包装)。 |
| `:f <number>`    | `float`                                              |
| 裸 scalar        | `string`                                             |
| `[ ... ]`        | 顺序 `array`                                         |
| `{ ... }`        | 关联 `array`(保留插入顺序)                          |

要发送任意精度整数,请自行包装:
`['big' => ['$i' => '9999999999999999999']]` —— 与 PHP 和原生侧
之间的 wire 格式一致。

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

MIT —— 见 [LICENSE](LICENSE)。

规范:[ktav-lang/spec](https://github.com/ktav-lang/spec)。
参考 Rust crate:[ktav-lang/rust](https://github.com/ktav-lang/rust)。
