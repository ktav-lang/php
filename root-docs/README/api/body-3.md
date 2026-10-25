>>>>> lang=en
Source editing (spec 0.9). `editValue($source, $path, $replacement)`
and `editValueSegments($source, $segments, $replacement)` replace an
EXISTING value in the source text and return the new text; the input
is never mutated. Only the bytes of the target (and of inline wrappers
the layout rules force to expand) change: comments, key spelling and
order, dotted/inline/block forms, BOM, CR/LF/CRLF and the final-EOL
state survive; a no-op is byte-identical even for noncanonical
spelling. The textual path `key1.key2.0.key4` uses `.` as the
separator, `\.` for a literal dot and `\\` for a literal backslash;
segments are already-decoded literal names. Errors are distinct
envelope codes: `EditInvalidPath` (body = UTF-8 byte offset in the
path), `EditNotFound` (missing key, out-of-bounds index, scalar
descent, empty segments list) and `EditUnsupported` (noncontiguous
dotted/reopen targets, structural replacement of a comment-carrying
container, or a replacement without a lossless representation in its target context). A
source that does not parse raises the ordinary parser envelope. On the
native side these methods require a `ktav_cabi` exporting the
source-edit ABI (version 2); an older binary fails with a clear
version error and is never replaced by a hidden pure fallback. A PHP
empty array as the replacement follows the nested empty-LIST
convention; an EMPTY `stdClass` is the explicit empty Object (`[]`
cannot express it), a NON-empty `stdClass` is a `Message` transport
failure; typed payloads (`['$i' => …]`, `['$f' => …]`) go through
the same strict wire ingest as the native codec — `$i` is canonicalized
(explicit `+` stripped, redundant leading zeros trimmed, signed zero
normalized to `0`, bignums digit-exact), `$f` must be a decimal lexical
form re-emitted shortest round-trip, and any invalid payload is a
`Message` transport failure, never a coerced scalar. Integer identity
is exact digit-string equality and Float identity is exact f64 equality
(the sign of zero is preserved, never a `precision`-ini string cast);
Object equality IGNORES key order (Rust IndexMap PartialEq), arrays
stay ordered.

>>>>> lang=ru
Правка исходника (spec 0.9). `editValue($source, $path, $replacement)`
и `editValueSegments($source, $segments, $replacement)` заменяют
СУЩЕСТВУЮЩЕЕ значение в тексте исходника и возвращают новую строку;
вход не мутируется. Меняются только байты цели (и inline-обёрток,
которые требует раскрыть правило компоновки): комментарии, написание
и порядок ключей, формы dotted/inline/block, BOM, CR/LF/CRLF и наличие
финального EOL сохраняются; no-op байт-в-байт идентичен исходнику,
даже при неканоничном написании. Текстовый путь `key1.key2.0.key4`
использует `.` как разделитель, `\.` для literal точки и `\\` для
literal обратного слеша; сегменты — уже декодированные literal имена.
Ошибки — отдельные коды конверта: `EditInvalidPath` (body = UTF-8
смещение в пути), `EditNotFound` (нет ключа, индекс вне диапазона,
спуск через скаляр, пустой список сегментов) и `EditUnsupported`
(несмежные цели из dotted/reopened-определений, структурная замена контейнера с
комментариями или замена без lossless-представления в контексте цели). Исходник с
ошибкой разбора даёт обычный конверт парсера. На нативной стороне эти
методы требуют `ktav_cabi` с ABI правки исходника (версия 2); старый
бинарник отклоняется с понятной ошибкой версии и никогда не
подменяется скрытым pure-fallback. Пустой PHP-массив в качестве
замены следует соглашению вложенного пустого LIST; ПУСТОЙ `stdClass` —
это явный пустой Object (`[]` его не выражает), НЕпустой `stdClass` —
транспортный сбой `Message`; типизированные
полезные нагрузки (`['$i' => …]`, `['$f' => …]`) проходят тот же
строгий wire-ingest, что и нативный кодек — `$i` канонизируется
(явный `+` снимается, лишние ведущие нули срезаются, знаковый нуль
нормализуется к `0`, бигнумы точны по цифрам), `$f` обязана быть
десятичной лексической формой и переиспускается в кратчайшей форме,
любой невалидный payload — транспортный сбой `Message`, а не
принудительно приведённый скаляр. Идентичность Integer — точное
сравнение строк цифр, Float — точное сравнение f64 (знак нуля
сохраняется, без строкового каста через `precision`); равенство
Object ИГНОРИРУЕТ порядок ключей (Rust IndexMap PartialEq), массивы
остаются упорядоченными.

>>>>> lang=zh
源码编辑（spec 0.9）。`editValue($source, $path, $replacement)` 和
`editValueSegments($source, $segments, $replacement)` 会替换源文本中的
已有值并返回新文本；输入不会被修改。只有目标字节（以及布局规则要求
展开的 inline 包装）会改变：注释、键的拼写与顺序、dotted/inline/block
形式、BOM、CR/LF/CRLF 和末尾 EOL 状态都会保留；即使源码拼写非规范，
no-op 结果也与源码逐字节相同。文本路径 `key1.key2.0.key4` 以 `.` 为
分隔符，`\.` 表示字面点号，`\\` 表示字面反斜杠；segments 是已解码的
字面名称。错误是不同的信封代码：`EditInvalidPath`（body = 路径中的
UTF-8 字节偏移）、`EditNotFound`（键缺失、索引越界、穿过标量、空
路径段列表）和 `EditUnsupported`（dotted/reopen 的非连续目标、对含注释
容器的结构性替换、或无法在目标上下文中无损表示的替换值）。源码解析失败会
抛出普通的解析器信封。原生侧这些方法需要导出源码编辑 ABI（版本 2）
的 `ktav_cabi`；旧二进制会以明确的版本错误被拒绝，绝不会静默退化为
纯 PHP 后备。空 PHP 数组作为替换值遵循嵌套空 LIST 约定；空的 `stdClass` 表示显式的空
Object（`[]` 无法表达），非空 `stdClass` 是 `Message` 传输失败；类型化载荷
（`['$i' => …]`、`['$f' => …]`）经过与原生编解码器相同的严格 wire-ingest
—— `$i` 会规范化（去掉显式 `+`、裁剪多余的先导零、带符号零归一化为
`0`、大数按位精确），`$f` 必须是十进制词法形式并按最短往返形式重新
输出；任何无效载荷都是 `Message` 传输失败，绝不会被强转成标量。
Integer 身份是精确的数字串比较，Float 身份是精确的 f64 比较（保留零
的符号，不经过 `precision` 的字符串转换）；Object 相等会忽略键顺序
（Rust IndexMap PartialEq），数组保持有序。

