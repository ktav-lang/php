>>>>> lang=en
## [0.5.0] — 2026-05-28

Implements Ktav spec 0.5.0. Tracks ktav-rust 0.5.0.

### Breaking

- Typed markers `:i` / `:f` removed. Numbers, booleans, and `null` are
  inferred from the lexical form (spec §§ 3.6, 5.2). Write `port: 8080`
  for Integer, `port:: 8080` to keep a String.
- Comments now use `##` (own line). A single `#` byte is content, not a
  comment.
- Bare integers and floats no longer parse as String — `port: 8080`
  yields integer `8080`, not string `"8080"`.
- Key segments are trimmed of leading/trailing whitespace.

### Added

- **Inline compounds** `{k: v, …}` / `[i, …]` (spec § 5.8).
- **Eight escape sequences** in inline scalars: `\\`, `\,`, `\}`, `\]`,
  `\{`, `\[`, `\n`, `\r` (spec § 3.7).
- **`Ktav::emitCanonical($value)`** — render to the deterministic
  canonical Ktav form (spec § 7), via the new `ktav_emit_canonical`
  C ABI export.

### Changed

- License: MIT → MIT OR Apache-2.0 (`LICENSE-MIT` + `LICENSE-APACHE`).
- Spec submodule: v0.5.0.
- ktav-rust dependency: 0.5.0.
- Conformance tests now run against `spec/versions/0.5/tests/`.

---

>>>>> lang=ru
## [0.5.0] — 2026-05-28

Реализует спецификацию Ktav 0.5.0. Отслеживает ktav-rust 0.5.0.

### Ломающие изменения

- Типизированные маркеры `:i` / `:f` удалены. Числа, булевы значения и
  `null` определяются по лексической форме (spec §§ 3.6, 5.2). Пишите
  `port: 8080` для Integer и `port:: 8080`, чтобы сохранить String.
- Комментарии теперь начинаются с `##` (своя строка). Одиночный байт `#` —
  это содержимое, а не комментарий.
- Голые целые и дробные числа больше не разбираются как String —
  `port: 8080` даёт integer `8080`, а не string `"8080"`.
- Сегменты ключа обрезаются от пробелов в начале и в конце.

### Добавлено

- **Inline-компоунды** `{k: v, …}` / `[i, …]` (spec § 5.8).
- **Восемь escape-последовательностей** в inline-скалярах: `\\`, `\,`,
  `\}`, `\]`, `\{`, `\[`, `\n`, `\r` (spec § 3.7).
- **`Ktav::emitCanonical($value)`** — вывод в детерминированную
  каноническую форму Ktav (spec § 7) через новый экспорт C ABI
  `ktav_emit_canonical`.

### Изменено

- Лицензия: MIT → MIT OR Apache-2.0 (`LICENSE-MIT` + `LICENSE-APACHE`).
- Подмодуль spec: v0.5.0.
- Зависимость ktav-rust: 0.5.0.
- Конформные тесты теперь выполняются против `spec/versions/0.5/tests/`.

---

>>>>> lang=zh
## [0.5.0] —— 2026-05-28

实现 Ktav 规范 0.5.0。跟踪 ktav-rust 0.5.0。

### 破坏性变更

- 类型标记 `:i` / `:f` 已移除。数字、布尔值与 `null` 根据词法形式推断
  (规范 §§ 3.6, 5.2)。写 `port: 8080` 得到 Integer,写 `port:: 8080`
  保留 String。
- 注释现在使用 `##`(独占一行)。单个 `#` 字节是内容,不是注释。
- 裸整数与浮点数不再解析为 String —— `port: 8080` 得到的是整数
  `8080`,而不是字符串 `"8080"`。
- 键段的首尾空白现在被裁剪。

### 新增

- **内联复合类型** `{k: v, …}` / `[i, …]`(规范 § 5.8)。
- **inline 标量中的八个转义序列**:`\\`、`\,`、`\}`、`\]`、`\{`、
  `\[`、`\n`、`\r`(规范 § 3.7)。
- **`Ktav::emitCanonical($value)`** —— 渲染为确定性的规范 Ktav 形式
  (规范 § 7),通过新增的 `ktav_emit_canonical` C ABI 导出。

### 变更

- 许可证:MIT → MIT OR Apache-2.0(`LICENSE-MIT` + `LICENSE-APACHE`)。
- spec 子模块:v0.5.0。
- ktav-rust 依赖:0.5.0。
- 一致性测试现在针对 `spec/versions/0.5/tests/` 运行。

---

