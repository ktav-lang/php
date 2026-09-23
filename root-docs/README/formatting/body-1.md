>>>>> lang=en
### Formatting

`Ktav::format()` takes Ktav **source text** and returns Ktav source
text. It normalises structure to canonical form (§ 5.9) while keeping
the trivia the canonical writer drops:

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

Every comment survives verbatim — Ktav has no trailing comments (§ 3.4:
a comment owns a whole line), so attachment is unambiguous. Blank lines
survive as a grouping hint, but a run of two or more collapses to
exactly one and blank padding just inside a bracket is dropped, which
makes the transform a fixed point: formatting already-formatted text
changes nothing. Key order is never changed — canonical form has no
sorting rule, and reordering keys would make review diffs worse.

>>>>> lang=ru
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

>>>>> lang=zh
### 格式化

`Ktav::format()` 接受 Ktav **源文本**，并返回 Ktav 源文本。
它把结构规范到规范形式（§ 5.9），
同时保留规范写入器会丢弃的附属内容：

```php
echo Ktav::format("## the server\nserver: {host: a, port: 80}\n");
// ## the server
// server: {
//     host: a
//     port: 80
// }
```

每条注释都逐字保留 —— Ktav 没有行尾注释（§ 3.4：
注释占据整行），因此归属毫无歧义。空行作为分组提示保留，
但连续两个及以上会折叠为恰好一个，紧贴括号内侧的空行
填充则被丢弃；正因如此该变换是不动点 —— 对已格式化的
文本再次格式化不会有任何改变。键序永不改变：规范形式
没有排序规则，
而重排键只会让评审差异更糟。

