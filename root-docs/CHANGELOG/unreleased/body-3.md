>>>>> lang=en
  The envelope names the two writer rejections apart:
  `"UnrepresentableAt"` when the writer can say where the offending
  node is (it also fills `getPath()`), `"Unrepresentable"` when it
  cannot. The `reason` code is identical in both, so a caller that only
  needs "the write was refused" matches on `getReason()`.

  `getErrorLine()` rather than `getLine()` because PHP's
  `Exception::getLine()` is `final`.

### Changed

- **Error messages have changed.** They are now reconstructed from the
  envelope's fields rather than passed through from the core's
  `Display` output. Callers matching on message strings will need to
  match on `getError()` / `getReason()` instead — which is the point of
  the change. `getMessage()` remains human-readable and is never the
  raw JSON.

- Minimum `ktav` core raised to **0.7.1**: `format_str` and
  `ErrorEnvelope` do not exist before it. In Cargo terms the
  requirement is `>=0.7.1, <0.8.0` — the floor rises, the ceiling stays
  inside 0.7.x.

>>>>> lang=ru
  Конверт по-разному называет два отказа писателя:
  `"UnrepresentableAt"`, когда писатель может указать проблемный узел
  (тогда он заполняет и `getPath()`), и `"Unrepresentable"`, когда не
  может. Код причины одинаков, поэтому вызывающему, которому достаточно
  знать «в записи отказано», хватает сверки с `getReason()`.

  `getErrorLine()`, а не `getLine()`, потому что в PHP
  `Exception::getLine()` объявлен `final`.

### Изменено

- **Тексты сообщений об ошибках изменились.** Теперь они собираются заново
  из полей конверта, а не передаются насквозь из вывода `Display` ядра.
  Вызывающим, которые сверяли строки сообщений, нужно переходить на
  `getError()` / `getReason()` — в этом и смысл изменения. `getMessage()`
  остаётся человекочитаемым и никогда не является сырым JSON.

- Минимальная версия ядра `ktav` поднята до **0.7.1**: `format_str` и
  `ErrorEnvelope` до неё не существуют. В терминах Cargo требование —
  `>=0.7.1, <0.8.0` — нижняя граница растёт, верхняя остаётся в
  пределах 0.7.x.

>>>>> lang=zh
  信封把写入器的两种拒绝分别命名:能指出问题节点位置时为
  `"UnrepresentableAt"`(此时也会填充 `getPath()`),不能指出时为
  `"Unrepresentable"`。两者的原因码完全相同,因此只需知道「写入被
  拒绝」的调用方匹配 `getReason()` 即可。

  用 `getErrorLine()` 而不是 `getLine()`,因为 PHP 的
  `Exception::getLine()` 是 `final`。

### 变更

- **错误消息文本已改变。** 它们现在由信封的字段重新拼装,而不是从核心的
  `Display` 输出透传。此前按消息字符串匹配的调用方需要改为匹配
  `getError()` / `getReason()` —— 这正是本次改动的目的。`getMessage()`
  仍然人类可读,且绝不会是原始 JSON。

- `ktav` 核心的最低版本提升至 **0.7.1**:在此之前 `format_str` 与
  `ErrorEnvelope` 都不存在。用 Cargo 的语义表述即 `>=0.7.1, <0.8.0` ——
  下限上移,上限仍留在 0.7.x 之内。

