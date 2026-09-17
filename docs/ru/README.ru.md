# ktav — биндинги для PHP

[![Packagist](https://img.shields.io/packagist/v/ktav-lang/ktav?style=flat-square&logo=packagist&logoColor=white&label=Packagist)](https://packagist.org/packages/ktav-lang/ktav)
[![CI](https://img.shields.io/github/actions/workflow/status/ktav-lang/php/ci.yml?style=flat-square&logo=github&label=CI)](https://github.com/ktav-lang/php/actions)
![License: MIT OR Apache-2.0](https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue?style=flat-square)
[![Playground](https://img.shields.io/badge/playground-try%20online-7c3aed?style=flat-square&logo=rocket&logoColor=white)](https://ktav-lang.github.io/)

**Languages:** [English](../../README.md) · **Русский** · [简体中文](../zh/README.zh.md)

**Песочница:** конвертация JSON / YAML / TOML / INI ⇄ Ktav прямо в браузере — **[ktav-lang.github.io](https://ktav-lang.github.io/)**.

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

Полный запускаемый пример — в [`examples/basic.php`](../../examples/basic.php).

## API

| Метод | Назначение |
| --- | --- |
| `Ktav::loads(string $src): mixed` | Разобрать Ktav-документ. |
| `Ktav::loadsStrict(string $src): mixed` | Разобрать документ со строгой проверкой записи чисел. |
| `Ktav::dumps(array $value): string` | Отрендерить ассоциативный массив в Ktav. |
| `Ktav::format(string $src): string` | Нормализует написание документа, сохраняя комментарии. |
| `Ktav::nativeVersion(): string` | Версия загруженного `ktav_cabi`. |

### Форматирование

`Ktav::format()` принимает Ktav-**текст** и возвращает Ktav-текст. Он
приводит структуру к канонической форме (§ 5.9), сохраняя оформление,
которое канонический писатель выбрасывает:

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

Каждый комментарий сохраняется дословно — в Ktav нет комментариев в
конце строки (§ 3.4: комментарий занимает целую строку), поэтому
привязка однозначна. Пустые строки сохраняются как подсказка
группировки, но серия из двух и более схлопывается ровно в одну, а
пустой отступ сразу внутри скобки выбрасывается; из-за этого
преобразование является неподвижной точкой — форматирование уже
отформатированного текста ничего не меняет. Порядок ключей не меняется
никогда: у канонической формы нет правила сортировки, а перестановка
ключей ухудшила бы диффы на ревью.

### Ошибки

`KtavException` бросается на любой ошибке разбора или отрисовки. Кроме
человекочитаемого `getMessage()` он несёт девять структурных полей
конверта ошибок ядра:

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — как записано
    $e->getCanonical();    // "1.1"       — как будет храниться
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

Полный набор: `getError()`, `getReason()`, `getErrorLine()`,
`getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
`getCanonical()`, `getSpecSection()`. Отсутствующие сведения — `null`,
а не пропущенный аксессор, так что любое поле можно прочитать, не
выясняя предварительно класс ошибки.

`getPath()` — **массив точных декодированных сегментов ключа, а не
склеенная строка**: ключ, буквально названный `a.b`, — это один
сегмент, и спутать его с путём из двух нельзя.

Два отказа писателя названы раздельно: `"UnrepresentableAt"`, когда
писатель может указать виновный узел (тогда он заполняет и
`getPath()`), и `"Unrepresentable"`, когда не может. Код причины в
обоих случаях одинаков, поэтому `getReason()` достаточно, если нужно
лишь знать, что в записи отказано.

`getErrorLine()`, а не `getLine()`, потому что в PHP
`Exception::getLine()` объявлен `final`.

## Маппинг типов

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| голое целое      | `int` если влезает, иначе `string` (нативного bigint в PHP нет — оборачивайте сами через GMP / BCMath если нужна арифметика). |
| голое десятичное | `float`                                              |
| прочий скаляр    | `string`                                             |
| `[ ... ]`        | последовательный `array`                             |
| `{ ... }`        | ассоциативный `array` (порядок вставки сохраняется)  |

Чтобы отдать целое произвольной точности — оборачивайте сами:
`['big' => ['$i' => '9999999999999999999']]` — тот же envelope что
ходит по wire между PHP и нативкой.

## Экранирование в ключах

Начиная со spec 0.6.4 литеральные `.` или `:` внутри сегмента ключа
записываются через backslash:

```text
a\.b: v        # ключ — один сегмент "a.b"    → ["a.b" => "v"]
a\:b: v        # двоеточие внутри ключа       → ["a:b" => "v"]
x.y\.z: v      # делим только по первой точке → ["x" => ["y.z" => "v"]]
```

Литеральный backslash в ключе пишется как `\\`.

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

MIT OR Apache-2.0 — см. [LICENSE-MIT](../../LICENSE-MIT) и [LICENSE-APACHE](../../LICENSE-APACHE).

## Другие реализации Ktav

- [`spec`](https://github.com/ktav-lang/spec) — спецификация + conformance-тесты
- [`rust`](https://github.com/ktav-lang/rust) — эталонный Rust crate (`cargo add ktav`)
- [`csharp`](https://github.com/ktav-lang/csharp) — C# / .NET (`dotnet add package Ktav`)
- [`golang`](https://github.com/ktav-lang/golang) — Go (`go get github.com/ktav-lang/golang`)
- [`java`](https://github.com/ktav-lang/java) — Java / JVM (`io.github.ktav-lang:ktav` на Maven Central)
- [`js`](https://github.com/ktav-lang/js) — JS / TS (`npm install @ktav-lang/ktav`)
- [`python`](https://github.com/ktav-lang/python) — Python (`pip install ktav`)
