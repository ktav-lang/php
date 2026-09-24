>>>>> lang=en
## Core rules

### 1. Every bug fix ships with a regression test

When you find a bug, **before fixing it**, write a test that reproduces
it — the test **must fail on `main`** and pass after the fix. Include
both in the same PR.

Tests are Kahlan specs (`describe` / `it`) under `tests/`:

| File                           | Scope                                                                       |
| ------------------------------ | --------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings` round-trips, typed scalar inference, quoted keys, unicode escapes, big integers, canonical output. |
| `tests/FormatterSpec.php`      | `Ktav::format` — comment preservation, blank-line collapsing, fixed point, canonical equivalence without trivia. |
| `tests/ErrorEnvelopeSpec.php`  | the nine structured error-envelope fields carried by `KtavException`.       |
| `tests/ConformanceSpec.php`    | cross-language conformance against the `ktav-lang/spec` fixture corpus.     |
| `tests/CorpusGuardSpec.php`    | guards the corpus itself: every category non-empty, one `.canonical.ktav` companion per `valid/` fixture. |
| `tests/ReadmeDocCheckSpec.php` | executes the README's documented claims so the docs cannot drift from the code. |

>>>>> lang=ru
## Основные правила

### 1. Каждый баг-фикс приходит с регрессионным тестом

Найдя баг, **до исправления** напишите тест, который его
воспроизводит — он **должен падать на `main`** и проходить после
фикса. Оба — в одном PR.

Тесты — Kahlan-спеки (`describe` / `it`) под `tests/`:

| Файл                           | Область                                                                                 |
| ------------------------------ | --------------------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | Round-trip'ы `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings`, вывод типов скаляров, quoted keys, unicode-эскейпы, большие целые, каноничный вывод. |
| `tests/FormatterSpec.php`      | `Ktav::format` — сохранение комментариев, схлопывание пустых строк, фиксированная точка, совпадение с каноничной формой без тривии. |
| `tests/ErrorEnvelopeSpec.php`  | девять структурных полей обёртки ошибки, которые несёт `KtavException`.                  |
| `tests/ConformanceSpec.php`    | кросс-языковая conformance-сверка с корпусом фикстур `ktav-lang/spec`.                   |
| `tests/CorpusGuardSpec.php`    | сторожит сам корпус: каждая категория непуста, у каждого фикстура из `valid/` ровно один `.canonical.ktav`-компаньон. |
| `tests/ReadmeDocCheckSpec.php` | исполняет задокументированные в README обещания, чтобы доки не отставали от кода.       |

>>>>> lang=zh
## 核心规则

### 1. 每个 bug 修复都带回归测试

发现 bug 时,**在修复之前** 先写一个能复现它的测试 —— 该测试在
`main` 上 **必须失败**,修复之后才通过。两者放进同一个 PR。

测试是 `tests/` 下的 Kahlan 规格(`describe` / `it`):

| 文件                           | 范围                                                                                 |
| ------------------------------ | ------------------------------------------------------------------------------------- |
| `tests/SmokeSpec.php`          | `loads` / `loadsStrict` / `dumps` / `dumpsForceStrings` 往返、标量类型推断、引号键、unicode 转义、大整数、规范输出。 |
| `tests/FormatterSpec.php`      | `Ktav::format` —— 保留注释、折叠空行、不动点、无杂注时与规范形式一致。 |
| `tests/ErrorEnvelopeSpec.php`  | `KtavException` 携带的九个结构化错误信封字段。 |
| `tests/ConformanceSpec.php`    | 针对 `ktav-lang/spec` 固定装置语料库的跨语言一致性对齐。 |
| `tests/CorpusGuardSpec.php`    | 看守语料库本身:每个类别非空,`valid/` 中每个固定装置恰有一个 `.canonical.ktav` 伴生文件。 |
| `tests/ReadmeDocCheckSpec.php` | 执行 README 写下的承诺,让文档无法悄悄落后于代码。 |

