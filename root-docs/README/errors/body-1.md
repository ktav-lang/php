>>>>> lang=en
### Errors

`KtavException` is thrown on any parse or render failure. Beyond a
human-readable `getMessage()`, it carries the nine structured fields of
the core's error envelope:

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — as written
    $e->getCanonical();    // "1.1"       — as it would be stored
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

The full set is `getError()`, `getReason()`, `getErrorLine()`,
`getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
`getCanonical()`, `getSpecSection()`. Absent information is `null`,
never a missing accessor, so a caller can read any field without
checking the error class first.

`getPath()` is an **array of exact decoded key segments, never a joined
string**: a key literally named `a.b` is one segment and cannot be
confused with a two-segment path.

Two writer rejections are named apart — `"UnrepresentableAt"` when the
writer can say which node is at fault (it fills `getPath()` too), and
`"Unrepresentable"` when it cannot. The `reason` code is the same in
both, so matching on `getReason()` is enough when you only need to know
that a write was refused.

`getErrorLine()` rather than `getLine()`, because PHP declares
`Exception::getLine()` `final`.

>>>>> lang=ru
### Ошибки

`KtavException` бросается при любой ошибке разбора или вывода. Кроме
человекочитаемого `getMessage()` он несёт девять структурных полей
конверта ошибок ядра:

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — as written
    $e->getCanonical();    // "1.1"       — as it would be stored
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

Полный набор: `getError()`, `getReason()`, `getErrorLine()`,
`getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
`getCanonical()`, `getSpecSection()`. Отсутствующие сведения — это `null`,
а не отсутствующий аксессор, поэтому любое поле можно прочитать, не
проверяя предварительно класс ошибки.

`getPath()` — **массив точных декодированных сегментов ключа, а не
склеенная строка**: ключ, буквально названный `a.b`, — это один
сегмент, и его нельзя спутать с путём из двух сегментов.

Два отказа писателя названы по отдельности: `"UnrepresentableAt"`, когда
писатель может указать виновный узел (тогда он заполняет и `getPath()`),
и `"Unrepresentable"`, когда не может. Код `reason` одинаков в обоих
случаях, поэтому, если нужно лишь узнать, что в записи отказано,
достаточно сопоставления с `getReason()`.

`getErrorLine()`, а не `getLine()`, потому что в PHP
`Exception::getLine()` объявлен `final`.

>>>>> lang=zh
### 错误

解析或渲染失败时抛出 `KtavException`。
除了人类可读的 `getMessage()`，它还携带
核心错误信封的九个结构化字段：

```php
try {
    Ktav::loadsStrict("a: 1.10\n");
} catch (KtavException $e) {
    $e->getError();        // "LossyScalar"
    $e->getBody();         // "1.10"      — as written
    $e->getCanonical();    // "1.1"       — as it would be stored
    $e->getSpecSection();  // "§3.6/§5.2"
    $e->getSpan();         // ["start" => 0, "end" => 7]
}
```

完整集合为 `getError()`、`getReason()`、`getErrorLine()`、
`getLineText()`、`getSpan()`、`getPath()`、`getBody()`、
`getCanonical()`、`getSpecSection()`。缺失的信息是 `null`，
而不是缺少访问器，因此调用方无需先判断错误类别
即可读取任一字段。

`getPath()` 是**精确解码后的键段数组，
绝不是拼接字符串**：字面名为 `a.b` 的键是
单个段，不会与两段路径混淆。

写入器的两种拒绝分别命名：能指出出错节点时为
`"UnrepresentableAt"`（该节点也会填入 `getPath()`），
不能指出时为 `"Unrepresentable"`。两者的 `reason` 码
相同，因此若只需知道「写入被拒绝」，
匹配 `getReason()` 就够了。

使用 `getErrorLine()` 而非 `getLine()`，因为 PHP 的
`Exception::getLine()` 被声明为 `final`。

