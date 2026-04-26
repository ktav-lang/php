# ktav — биндинги для PHP

**Languages:** [English](README.md) · **Русский** · [简体中文](README.zh.md)

PHP-биндинги к [формату конфигурации Ktav](https://github.com/ktav-lang/spec).
Тонкая обёртка над эталонным парсером на Rust, подгружаемая в runtime
через **[PHP FFI](https://www.php.net/manual/ru/book.ffi.php)** —
никаких PHP-расширений компилировать не нужно, никакого PECL.
Обычная Composer-зависимость, нативный бинарь скачивается на первый вызов.

Требуется **PHP 7.4+** с включённым `ext-ffi` (по умолчанию в CLI;
для веб-SAPI нужно `ffi.enable=1` в `php.ini`).

## Установка

```bash
composer require ktav-lang/ktav
```

## Быстрый старт

### Парсинг — типизированно читаем поля

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

### Билд + рендер — собираем документ в коде

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

Полный запускаемый пример — в [`examples/basic.php`](examples/basic.php).

## API

| Метод | Назначение |
| --- | --- |
| `Ktav::loads(string $src): mixed` | Разобрать Ktav-документ. |
| `Ktav::dumps(array $value): string` | Отрендерить ассоциативный массив в Ktav. |
| `Ktav::nativeVersion(): string` | Версия загруженного `ktav_cabi`. |

На любой ошибке — `KtavException` (сообщение от нативного парсера, UTF-8).

## Маппинг типов

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| `:i <digits>`    | `int` если влезает, иначе `string` (нативного bigint в PHP нет — оборачивайте сами через GMP / BCMath если нужна арифметика). |
| `:f <number>`    | `float`                                              |
| bare scalar      | `string`                                             |
| `[ ... ]`        | последовательный `array`                             |
| `{ ... }`        | ассоциативный `array` (порядок вставки сохраняется)  |

Чтобы отдать целое произвольной точности — оборачивайте сами:
`['big' => ['$i' => '9999999999999999999']]` — тот же envelope что
ходит по wire между PHP и нативкой.

## Как резолвится нативная библиотека

На первый вызов:

1. **`KTAV_LIB_PATH`** env, если задан.
2. **Кэш пользователя** — `<userCache>/ktav-php/v<версия>/<ассет>`.
3. **Скачивание с GitHub Release** — один раз с
   `github.com/ktav-lang/php/releases/download/v<версия>/<имя>` и
   кладётся в (2). Сеть нужна только при первом вызове.

`<userCache>` это `%LOCALAPPDATA%` на Windows, `~/Library/Caches` на
macOS, `$XDG_CACHE_HOME` или `~/.cache` на Linux.

## Поддерживаемые версии

- PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3+. Тестируется на LTS-линиях в CI.
- Собранные бинарники: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.
- Linux — glibc 2.17+ (zigbuild baseline). Alpine (musl) — запланировано.

## Лицензия

MIT — см. [LICENSE](LICENSE).

## Другие реализации Ktav

- [`spec`](https://github.com/ktav-lang/spec) — спецификация + conformance-тесты
- [`rust`](https://github.com/ktav-lang/rust) — эталонный Rust crate (`cargo add ktav`)
- [`csharp`](https://github.com/ktav-lang/csharp) — C# / .NET (`dotnet add package Ktav`)
- [`golang`](https://github.com/ktav-lang/golang) — Go (`go get github.com/ktav-lang/golang`)
- [`java`](https://github.com/ktav-lang/java) — Java / JVM (`io.github.ktav-lang:ktav` на Maven Central)
- [`js`](https://github.com/ktav-lang/js) — JS / TS (`npm install @ktav-lang/ktav`)
- [`python`](https://github.com/ktav-lang/python) — Python (`pip install ktav`)
