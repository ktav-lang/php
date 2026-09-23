>>>>> lang=en
## Type mapping

| Ktav             | PHP                                                  |
| ---------------- | ---------------------------------------------------- |
| `null`           | `null`                                               |
| `true` / `false` | `bool`                                               |
| bare integer     | `int` if it fits, else `string` (PHP has no native bigint — wrap your own GMP / BCMath if you need arithmetic). |
| bare decimal     | `float`                                              |
| other scalar     | `string`                                             |
| `[ ... ]`        | sequential `array`                                   |
| `{ ... }`        | associative `array` (insertion order preserved)      |

To emit an arbitrary-precision integer, wrap the digit string yourself:
`['big' => ['$i' => '9999999999999999999']]` — same envelope used on the
wire between PHP and the native side.

>>>>> lang=ru
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

>>>>> lang=zh
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

