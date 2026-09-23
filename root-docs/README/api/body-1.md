>>>>> lang=en
## API

| Method | Purpose |
| --- | --- |
| `Ktav::loads(string $src): mixed` | Parse a Ktav document. |
| `Ktav::loadsStrict(string $src): mixed` | Parse with strict numeric spelling checks. |
| `Ktav::dumps(array $value): string` | Render an associative array as Ktav text. |
| `Ktav::dumpsForceStrings(array $value): string` | Render like `dumps`, but coerce every leaf scalar to a String. |
| `Ktav::emitCanonical(array $value): string` | Render a value as deterministic canonical form. |
| `Ktav::format(string $src): string` | Normalise a document's spelling, keeping comments. |
| `Ktav::canonicalFromSource(string $src): string` | Parse and re-emit as canonical Ktav in one call — `emitCanonical(loads($src))` with no intermediate value. Drops comments and blank lines like `emitCanonical` does. |
| `Ktav::nativeVersion(): string` | Version of the loaded `ktav_cabi`. |

`dumpsForceStrings` flattens integers, floats, booleans and `null` to
their textual form via the raw marker (`::`); objects and arrays keep
their structure, since only leaves are coerced. The result parses back
through `loads` as the same set of String scalars — useful when a
downstream consumer does not understand typed markers.

>>>>> lang=ru
## API

| Метод | Назначение |
| --- | --- |
| `Ktav::loads(string $src): mixed` | Разобрать Ktav-документ. |
| `Ktav::loadsStrict(string $src): mixed` | Разобрать документ со строгой проверкой записи чисел. |
| `Ktav::dumps(array $value): string` | Вывести ассоциативный массив как Ktav-текст. |
| `Ktav::dumpsForceStrings(array $value): string` | Вывести как `dumps`, но привести каждый листовой скаляр к String. |
| `Ktav::emitCanonical(array $value): string` | Вывести значение в детерминированной канонической форме. |
| `Ktav::format(string $src): string` | Нормализовать написание документа, сохранив комментарии. |
| `Ktav::canonicalFromSource(string $src): string` | Разобрать и заново выдать канонический Ktav за один вызов — `emitCanonical(loads($src))` без промежуточного значения. Комментарии и пустые строки отбрасываются так же, как у `emitCanonical`. |
| `Ktav::nativeVersion(): string` | Версия загруженного `ktav_cabi`. |

`dumpsForceStrings` расплющивает целые, дробные, булевы и `null` в их
текстовую форму через сырой маркер (`::`); объекты и массивы сохраняют
свою структуру, ведь приводятся только листья. Результат разбирается
обратно через `loads` как тот же набор String-скаляров — полезно, когда
потребитель на выходе не понимает типизированные маркеры.

>>>>> lang=zh
## API

| 方法 | 用途 |
| --- | --- |
| `Ktav::loads(string $src): mixed` | 解析 Ktav 文档。 |
| `Ktav::loadsStrict(string $src): mixed` | 解析文档，并严格检查数字写法。 |
| `Ktav::dumps(array $value): string` | 将关联数组渲染为 Ktav 文本。 |
| `Ktav::dumpsForceStrings(array $value): string` | 与 `dumps` 相同，但把每个叶子标量强制为 String。 |
| `Ktav::emitCanonical(array $value): string` | 将值渲染为确定性的规范形式。 |
| `Ktav::format(string $src): string` | 规范文档写法，同时保留注释。 |
| `Ktav::canonicalFromSource(string $src): string` | 一次调用完成解析并重新输出为规范 Ktav —— 相当于 `emitCanonical(loads($src))`，中间不产生值。像 `emitCanonical` 一样丢弃注释和空行。 |
| `Ktav::nativeVersion(): string` | 已加载 `ktav_cabi` 的版本。 |

`dumpsForceStrings` 把整数、float、布尔与 `null` 用原始标记（`::`）
压平为文本形式；对象与数组保持自身结构，因为只有叶子会被强制。
结果经由 `loads` 解析回来仍是同一组 String 标量 ——
当下游消费方不理解类型标记时，
这很有用。

