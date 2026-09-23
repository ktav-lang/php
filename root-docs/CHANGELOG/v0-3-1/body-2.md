>>>>> lang=en
### Changed

- **Picked up `ktav 0.3.1`** — the upstream Rust crate now
  implements top-level Array detection and exposes
  `ktav::to_string_force_strings`, both surfaced through the cabi
  layer (`ktav_dumps_force_strings`, plus root-Array acceptance in
  `ktav_dumps`). See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#031--2026-05-10).

### Compatibility

Strictly additive. Every 0.3.0-valid document stays valid and
produces the same value. Every 0.3.0-valid `dumps` call returns
the same text. Only inputs 0.3.0 rejected as `MissingSeparator`
now succeed (as top-level Arrays), and only `dumps([1,2,3])`-style
calls that previously threw now succeed.

### Spec

- spec submodule synced to **0.1.1** (commit `7256816`) — top-level
  Array detection in § 5.0.1, anchored first-line invalid fixtures,
  clarified pair-shape-inside-Array behaviour.

>>>>> lang=ru
### Изменено

- **Подхвачен `ktav 0.3.1`** — upstream Rust crate теперь реализует
  определение Array верхнего уровня и предоставляет
  `ktav::to_string_force_strings`; обе возможности выходят наружу через
  слой cabi (`ktav_dumps_force_strings`, плюс приём Array в корне в
  `ktav_dumps`). См.
  [`CHANGELOG` крейта `ktav`](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#031--2026-05-10).

### Совместимость

Строго добавление. Каждый документ, валидный в 0.3.0, остаётся валидным
и даёт то же значение. Каждый вызов `dumps`, валидный в 0.3.0, возвращает
тот же текст. Теперь успешны только те входы, которые 0.3.0 отвергал как
`MissingSeparator` (как Array верхнего уровня), и только вызовы вида
`dumps([1,2,3])`, которые раньше бросали исключение.

### Спецификация

- подмодуль spec синхронизирован до **0.1.1** (коммит `7256816`) —
  определение Array верхнего уровня в § 5.0.1, закреплённые невалидные
  fixtures для первой строки, уточнено поведение пары внутри Array.

>>>>> lang=zh
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

