>>>>> lang=en
## [0.3.1] — 2026-05-10

### Added

- **Top-level Array support (spec § 5.0.1).** A document whose first
  content line has an array-item shape (bare scalar, `:: text`,
  `:i 42`, `:f 3.14`, lone `{` / `[`, or a multi-line opener `(` /
  `((`) now parses as a sequential PHP list. Previously a bare-scalar
  first line errored as `MissingSeparator`. Empty / comments-only
  documents still default to an empty associative array (preserves
  0.3.0 behaviour).
- **`Ktav::dumps()` accepts top-level Arrays.** Sequential PHP arrays
  at the root now render as bare item-per-line (no surrounding
  `[...]` brackets). Previously `dumps([1,2,3])` threw — now it
  succeeds. Bare scalars at the root are still rejected.
- **`Ktav::dumpsForceStrings($value)`** — render any value with
  every scalar coerced to a String: typed integers, typed floats,
  booleans, and null are flattened to their textual form
  (`42`, `3.14`, `true`, `null`) and emitted via the raw-marker
  `::` so the output round-trips back through the parser as the
  same string scalars. Compounds preserve their structure; only
  leaf scalars are coerced. Useful for "everything is a string"
  dumps for downstream consumers that don't understand the
  `:i` / `:f` typed markers.

>>>>> lang=ru
## [0.3.1] — 2026-05-10

### Добавлено

- **Поддержка Array верхнего уровня (spec § 5.0.1).** Документ, чья первая
  содержательная строка имеет форму элемента массива (голый скаляр,
  `:: text`, `:i 42`, `:f 3.14`, одинокая `{` / `[` или многострочный
  открыватель `(` / `((`), теперь разбирается как последовательный
  PHP-список. Раньше первая строка с голым скаляром давала ошибку
  `MissingSeparator`. Пустые документы и документы только из комментариев
  по-прежнему по умолчанию дают пустой ассоциативный массив (сохраняется
  поведение 0.3.0).
- **`Ktav::dumps()` принимает Array верхнего уровня.** Последовательные
  PHP-массивы в корне теперь выводятся как по элементу на строку (без
  обрамляющих скобок `[...]`). Раньше `dumps([1,2,3])` бросал исключение —
  теперь он работает. Голые скаляры в корне по-прежнему отвергаются.
- **`Ktav::dumpsForceStrings($value)`** — выводит любое значение, приводя
  каждый скаляр к String: типизированные целые, типизированные дробные,
  булевы значения и null сводятся к их текстовой форме (`42`, `3.14`,
  `true`, `null`) и записываются через сырой маркер `::`, поэтому
  результат при обратном разборе снова даёт те же строковые скаляры.
  Составные значения сохраняют структуру; приводятся только скаляры в
  листьях. Полезно для выдачи «всё — строки» потребителям, которые не
  понимают типизированные маркеры `:i` / `:f`.

>>>>> lang=zh
## [0.3.1] —— 2026-05-10

### 新增

- **支持顶层 Array(规范 § 5.0.1)。** 首个内容行具有数组项形状(裸标量、
  `:: text`、`:i 42`、`:f 3.14`、单独的 `{` / `[`,或多行开括号 `(` /
  `((`)的文档,现在解析为顺序 PHP 列表。此前首行是裸标量时会报
  `MissingSeparator` 错误。空文档和只含注释的文档仍默认为空关联数组
  (保留 0.3.0 的行为)。
- **`Ktav::dumps()` 接受顶层 Array。** 根部的顺序 PHP 数组现在渲染为
  每项一行(不带 `[...]` 外层括号)。此前 `dumps([1,2,3])` 会抛异常 ——
  现在会成功。根部的裸标量仍被拒绝。
- **`Ktav::dumpsForceStrings($value)`** —— 把任意值的每个标量都强制为
  String 后输出:带类型的整数、带类型的浮点、布尔与 null 都被摊平成
  它们的文本形式(`42`、`3.14`、`true`、`null`),并通过原始标记 `::`
  写出,使输出再次经过解析器时仍得到相同的字符串标量。复合值保留其
  结构;只有叶子标量被强制。适合面向不理解 `:i` / `:f` 类型标记的下游
  消费者的“一切皆字符串”输出。

