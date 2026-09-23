>>>>> lang=en

## 0.2.0 — 2026-05-07

### Changed (breaking)

- **Picked up `ktav 0.2.0`** — multi-line strings now serialize in the
  indented stripped `( ... )` form by default. `:f 42` accepts integer
  literals (parsed as `42.0`). See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#020--2026-05-07).

  Code comparing serialized output byte-for-byte to a baked-in
  `((...))` literal must be updated. Round-trip is unchanged.

### Spec

- spec submodule synced (typed_float_integer_body fixture; oracle 42.0).

>>>>> lang=ru

## 0.2.0 — 2026-05-07

### Изменено (ломающее)

- **Подхвачен `ktav 0.2.0`** — многострочные строки теперь по умолчанию
  сериализуются в отступной обрезанной форме `( ... )`. `:f 42` принимает
  целые литералы (разбираются как `42.0`). См.
  [`CHANGELOG` крейта `ktav`](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#020--2026-05-07).

  Код, который побайтово сравнивает сериализованный вывод со вписанным
  литералом `((...))`, придётся обновить. Round-trip не изменился.

### Спецификация

- подмодуль spec синхронизирован (fixture typed_float_integer_body; oracle 42.0).

>>>>> lang=zh

## 0.2.0 —— 2026-05-07

### 变更(破坏性)

- **已跟进 `ktav 0.2.0`** —— 多行字符串现在默认序列化为缩进的
  剥离形式 `( ... )`。`:f 42` 接受整数字面量(解析为 `42.0`)。参见
  [`ktav` crate 的 CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#020--2026-05-07)。

  逐字节比对序列化输出与内置 `((...))` 字面量的代码需要更新。往返
  行为不变。

### 规范

- spec 子模块已同步(fixture typed_float_integer_body;oracle 42.0)。

