# ktav — биндинги для PHP

[![Packagist](https://img.shields.io/packagist/v/ktav-lang/ktav?style=flat-square&logo=packagist&logoColor=white&label=Packagist)](https://packagist.org/packages/ktav-lang/ktav)
[![CI](https://img.shields.io/github/actions/workflow/status/ktav-lang/php/ci.yml?style=flat-square&logo=github&label=CI)](https://github.com/ktav-lang/php/actions)
![License: MIT OR Apache-2.0](https://img.shields.io/badge/license-MIT%20OR%20Apache--2.0-blue?style=flat-square)
[![Playground](https://img.shields.io/badge/playground-try%20online-7c3aed?style=flat-square&logo=rocket&logoColor=white)](https://ktav-lang.github.io/)

**Языки:** [English](../../README.md) · **Русский** · [简体中文](../zh/README.zh.md)

**Песочница:** конвертация JSON / YAML / TOML / INI ⇄ Ktav прямо в браузере — **[ktav-lang.github.io](https://ktav-lang.github.io/)**.

PHP-биндинги к [формату конфигурации Ktav](https://github.com/ktav-lang/spec).
Тонкая обёртка над эталонным парсером на Rust, подгружаемая в runtime
через **[PHP FFI extension](https://www.php.net/manual/en/book.ffi.php)** —
ничего компилировать не надо, никакого PECL. Обычная
Composer-зависимость, нативный бинарь скачивается при первом вызове.

Требуется **PHP 7.4+** с включённым `ext-ffi` (по умолчанию в CLI; для
веб-SAPI нужен `ffi.enable=1` в `php.ini`).

## Установка

```bash
composer require ktav-lang/ktav
```

## Быстрый старт

### Парсинг — типизированно читаем значения прямо из массива

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

### Сборка и рендер — собираем документ в коде

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
| `Ktav::dumps(array $value): string` | Вывести ассоциативный массив как Ktav-текст. |
| `Ktav::dumpsForceStrings(array $value): string` | Вывести как `dumps`, но привести каждый листовой скаляр к String. |
| `Ktav::emitCanonical(array $value): string` | Вывести значение в детерминированной канонической форме. |
| `Ktav::format(string $src): string` | Нормализовать написание документа, сохранив комментарии. |
| `Ktav::canonicalFromSource(string $src): string` | Канонизировать исходный текст напрямую, сохраняя форму составных значений, которая может теряться в PHP-представлении (например, `a: {}` остаётся `a: {}`, тогда как `loads` представляет пустой Object как `[]`). Комментарии и пустые строки отбрасываются. |
| `Ktav::nativeVersion(): string` | Версия загруженного `ktav_cabi`. |

`dumpsForceStrings` расплющивает целые, дробные, булевы и `null` в их
текстовую форму через сырой маркер (`::`); объекты и массивы сохраняют
свою структуру, ведь приводятся только листья. Результат разбирается
обратно через `loads` как тот же набор String-скаляров — полезно, когда
потребителю на выходе нужны только строковые значения.

### Форматирование

`Ktav::format()` принимает Ktav-**исходник** и возвращает Ktav-исходник.
Он приводит структуру к канонической форме (§ 5.9), сохраняя
оформление, которое канонический писатель выбрасывает:

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

Каждый комментарий сохраняется дословно — в Ktav нет комментариев в конце строки
(§ 3.4: комментарий занимает целую строку), поэтому привязка однозначна.
Пустые строки сохраняются как подсказка группировки, но серия из двух и более
схлопывается ровно в одну, а пустая строка сразу внутри скобки выбрасывается,
из-за чего преобразование является неподвижной точкой: форматирование уже
отформатированного текста ничего не меняет. Порядок ключей не меняется
никогда — у канонической формы нет правила сортировки, а перестановка ключей ухудшила бы диффы.

### Ошибки

`KtavException` бросается при любой ошибке разбора или вывода. Кроме
человекочитаемого `getMessage()` он несёт девять структурных полей
конверта ошибок ядра:

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

Полный набор: `getError()`, `getReason()`, `getErrorLine()`,
`getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
`getCanonical()`, `getSpecSection()`. Отсутствующие сведения — это `null`,
а не отсутствующий аксессор, поэтому любое поле можно прочитать, не
проверяя предварительно класс ошибки.

`getPath()` — **массив точных декодированных сегментов ключа, а не
склеенная строка**: ключ, буквально названный `a.b`, — это один
сегмент, и его нельзя спутать с путём из двух сегментов.

Два отказа писателя названы по отдельности: `"UnrepresentableAt"`, когда
писатель может указать виновный узел (тогда он заполняет и `getPath()`),
и `"Unrepresentable"`, когда не может. Код `reason` одинаков в обоих
случаях, поэтому, если нужно лишь узнать, что в записи отказано,
достаточно сопоставления с `getReason()`.

`getErrorLine()`, а не `getLine()`, потому что в PHP
`Exception::getLine()` объявлен `final`.

## Маппинг типов

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| голое целое      | `int`, если влезает, иначе `string` (нативного bigint в PHP нет — оберните свои GMP / BCMath, если нужна арифметика). |
| голое десятичное | `float`                                              |
| прочий скаляр    | `string`                                             |
| `[ ... ]`        | последовательный `array`                             |
| `{ ... }`        | ассоциативный `array` (порядок вставки сохраняется)  |

Чтобы выдать целое произвольной точности, оберните строку цифр сами:
`['big' => ['$i' => '9999999999999999999']]` — тот же конверт, которым
нативная сторона и PHP обмениваются по проводу.

## Экранирование ключей

Начиная со spec 0.6.4, литеральные `.` или `:` внутри сегмента ключа
записываются с обратным слешем:

```text
## Ключ состоит из одного сегмента "a.b" → ["a.b" => "v"]
a\.b: v
## Ключ содержит двоеточие → ["a:b" => "v"]
a\:b: v
## Разделение только по первой точке → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

Литеральный обратный слеш в ключе записывается как `\\`.

## Как резолвится нативная библиотека

При первом вызове:

1. **`KTAV_LIB_PATH`** env-переменная, если задана.
2. **Кэш пользователя** — `<userCache>/ktav-php/v<version>/<asset>`, скачанный
   на одном из предыдущих вызовов.
3. **Скачивание с GitHub Release** — загружается один раз с
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` и
   кладётся в кэш (2). Сеть нужна на первый вызов после установки.

`<userCache>` — это `%LOCALAPPDATA%` на Windows, `~/Library/Caches` на
macOS, `$XDG_CACHE_HOME` или `~/.cache` на Linux.

## Поддержка рантаймов

- Поддерживаемые версии PHP: 7.4 / 8.0 / 8.1 / 8.2 / 8.3+. CI тестирует PHP 7.4, 8.2 и 8.3 на Linux, macOS и Windows.
- Готовые бинарники для: `linux/amd64`, `linux/arm64`, `darwin/amd64`,
  `darwin/arm64`, `windows/amd64`, `windows/arm64`.
- Linux-дистрибутивы требуют glibc 2.17+ (базовая линия zigbuild). Поддержка
  Alpine (musl) запланирована.

## Лицензия

MIT OR Apache-2.0 — см. [LICENSE-MIT](../../LICENSE-MIT) и [LICENSE-APACHE](../../LICENSE-APACHE).

## Другие реализации Ktav

- [`spec`](https://github.com/ktav-lang/spec) — спецификация + набор проверок соответствия
- [`rust`](https://github.com/ktav-lang/rust) — эталонный Rust crate (`cargo add ktav`)
- [`csharp`](https://github.com/ktav-lang/csharp) — C# / .NET (`dotnet add package Ktav`)
- [`golang`](https://github.com/ktav-lang/golang) — Go (`go get github.com/ktav-lang/golang`)
- [`java`](https://github.com/ktav-lang/java) — Java / JVM (`io.github.ktav-lang:ktav` на Maven Central)
- [`js`](https://github.com/ktav-lang/js) — JS / TS (`npm install @ktav-lang/ktav`)
- [`python`](https://github.com/ktav-lang/python) — Python (`pip install ktav`)
