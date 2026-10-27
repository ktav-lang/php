>>>>> lang=en
The source Integer domain is signed 64-bit. This value exceeds JavaScript's safe-Number range but fits i64; use an exact host representation, not a rounded floating-point value. A typed Integer outside i64 cannot be preserved as an Integer in source and is refused instead of truncated. Use a String when a large identifier is text. Float identity distinguishes negative and positive zero; Object no-op equality ignores key order, while Array order remains significant.

```php
$updated = PureBackend::editValue("n: 1\n", "n", ['$i' => '9007199254740993']);
```

```ktav
n: 9007199254740993
```

#### 9. Insert, remove and rename (textual and segment paths)

These new 0.9 insert/remove/rename operations are available through both pure and native backends and follow the same mutation contract. Insert adds a new final Object key without overwriting; removal targets an existing Object member or Array element without pruning ancestors (later Array elements shift); renaming requires an existing final Object member and a destination key absent from that same Object, preserving position and value. Insertion requires an existing parent Object; insertion into an Array is unsupported. Missing targets and invalid path/segment inputs are errors. Textual paths split on dots, with `\.` for a literal dot and `\\` for a literal backslash. Segment variants accept decoded literal path segments. `newKey` is one decoded key, not a path. Each snippet is independent.

```php
use Ktav\PureBackend;
$source = 'settings: {old: 1, "a.b": 3}' . "\n";
$inserted = PureBackend::insertValue($source, "settings.new", 2);
$insertedLiteral = PureBackend::insertValueSegments($source, ["settings", "added"], 2);
$removed = PureBackend::removeValue($source, "settings.old");
$removedLiteral = PureBackend::removeValueSegments($source, ["settings", "a.b"]);
$renamed = PureBackend::renameKey($source, "settings.old", "new.name");
$renamedLiteral = PureBackend::renameKeySegments($source, ["settings", "old"], "new.name");
```

An existing insertion key is an error, not an overwrite. Array elements shift after removal. Structural edits to containers with interior comments are unsupported because comments cannot be preserved losslessly. Inline dotted syntax is supported for value replacement, but structural insert/remove/rename requiring a synthetic, noncontiguous dotted path is unsupported. A trailing comma is preserved when the edit can be represented losslessly; the editor does not prune empty ancestors or promise lossless rewriting for every structural layout.

>>>>> lang=ru
Домен Integer в исходнике — знаковый 64-битный. Это значение больше безопасного диапазона JavaScript Number, но помещается в i64; используйте точное host-представление, а не округлённое число с плавающей точкой. Типизированный Integer вне i64 невозможно сохранить в исходнике как Integer: правка отказывает, не обрезая значение. Для текстового большого идентификатора используйте String. Float различает отрицательный и положительный нуль; no-op для Object не зависит от порядка ключей, порядок Array значим.

```php
$updated = PureBackend::editValue("n: 1\n", "n", ['$i' => '9007199254740993']);
```

```ktav
n: 9007199254740993
```

#### 9. Вставка, удаление и переименование (текстовые пути и сегменты)

Новые операции вставки, удаления и переименования API 0.9 доступны через pure- и native-бэкенды и следуют одному контракту изменений. Вставка добавляет новый последний ключ Object без перезаписи; удаление удаляет существующий член Object или элемент Array, не удаляя предков (последующие элементы Array сдвигаются); переименование требует существующий последний член Object и отсутствующий целевой ключ в том же Object, сохраняя позицию и значение. Для вставки родительский путь должен вести к существующему Object; вставка в Array не поддерживается. Отсутствующие цели и некорректный ввод путей/сегментов завершаются ошибкой. Текстовый путь разделяется по точкам; `\.` задаёт буквальную точку, `\\` — буквальную обратную косую черту. Сегментные варианты принимают декодированные буквальные сегменты пути. `newKey` — один декодированный ключ, а не путь. Каждый пример независим.

```php
use Ktav\PureBackend;
$source = 'settings: {old: 1, "a.b": 3}' . "\n";
$inserted = PureBackend::insertValue($source, "settings.new", 2);
$insertedLiteral = PureBackend::insertValueSegments($source, ["settings", "added"], 2);
$removed = PureBackend::removeValue($source, "settings.old");
$removedLiteral = PureBackend::removeValueSegments($source, ["settings", "a.b"]);
$renamed = PureBackend::renameKey($source, "settings.old", "new.name");
$renamedLiteral = PureBackend::renameKeySegments($source, ["settings", "old"], "new.name");
```

Вставка при существующем ключе завершается ошибкой, а не перезаписью. Элементы Array после удаления сдвигаются. Структурные изменения контейнеров с внутренними комментариями не поддерживаются, поскольку комментарии нельзя сохранить без потерь. Встроенная dotted-форма поддерживается для замены значения, но структурные вставка/удаление/переименование, требующие синтетического непоследовательного dotted-пути, не поддерживаются. Конечная запятая сохраняется, если изменение можно представить без потерь; редактор не удаляет пустых предков и не гарантирует без потерь переписывать любую структурную раскладку.

>>>>> lang=zh
```php
$updated = PureBackend::editValue("n: 1\n", "n", ['$i' => '9007199254740993']);
```

```ktav
n: 9007199254740993
```

#### 9. 插入、删除和重命名（文本路径和路径段）

新的 0.9 插入、删除和重命名操作可通过 pure 和 native 后端使用，并遵循相同的修改契约。插入会添加新的最后一个 Object 键而不覆盖已有键；删除现有 Object 成员或 Array 元素但保留其祖先（后续 Array 元素会移位）；重命名要求最后一个 Object 成员存在，且同一 Object 中目标键不存在，同时保留其位置和值。插入路径的父级必须指向现有 Object；不支持向 Array 插入。目标不存在或路径/路径段输入无效时会报错。文本路径按点拆分；`\.` 表示字面点，`\\` 表示字面反斜杠。路径段变体接收已解码的字面路径段。`newKey` 是单个已解码键名，而非路径。每个代码片段相互独立。

```php
use Ktav\PureBackend;
$source = 'settings: {old: 1, "a.b": 3}' . "\n";
$inserted = PureBackend::insertValue($source, "settings.new", 2);
$insertedLiteral = PureBackend::insertValueSegments($source, ["settings", "added"], 2);
$removed = PureBackend::removeValue($source, "settings.old");
$removedLiteral = PureBackend::removeValueSegments($source, ["settings", "a.b"]);
$renamed = PureBackend::renameKey($source, "settings.old", "new.name");
$renamedLiteral = PureBackend::renameKeySegments($source, ["settings", "old"], "new.name");
```

插入时若键已存在会报错，不会覆盖。删除后 Array 元素会移位。由于无法无损保留注释，不支持对内部含注释的容器进行结构编辑。行内点分语法支持值替换，但需要合成非连续点分路径的结构插入/删除/重命名不受支持。若编辑可无损表示，则保留末尾逗号；编辑器不会清除空祖先，也不保证所有结构布局都能无损重写。

