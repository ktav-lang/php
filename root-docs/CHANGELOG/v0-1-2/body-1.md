>>>>> lang=en

## 0.1.2 — 2026-05-03

### Changed

- **Picked up `ktav 0.1.5`** — the upstream Rust crate now exposes
  `Error::Structured(ErrorKind)` with byte-offset spans, retroactive
  `#[non_exhaustive]` on the error enums, and a public `ktav::thin`
  event-based parser. The PHP binding's user-visible behaviour is
  unchanged: `KtavException` carries the same human-readable message
  (Display strings for the seven canonical categories are byte-
  identical to ktav 0.1.4 — verified by ktav's own pinning tests).
  Mapping `ktav::ErrorKind` to a structured PHP exception hierarchy
  (`KtavMissingSeparatorSpaceException`, `KtavDuplicateKeyException`,
  etc.) is separate follow-up work tracked in the workspace's
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md).

Packagist: `ktav-lang/ktav` ^0.1.2.

>>>>> lang=ru

## 0.1.2 — 2026-05-03

### Изменено

- **Подхвачен `ktav 0.1.5`** — upstream Rust crate теперь предоставляет
  `Error::Structured(ErrorKind)` со span-ами в байтовых смещениях,
  ретроактивный `#[non_exhaustive]` на enum-ах ошибок и публичный
  событийный парсер `ktav::thin`. Видимое пользователю поведение
  PHP-биндинга не изменилось: `KtavException` несёт то же человекочитаемое
  сообщение (строки `Display` для семи канонических категорий побайтово
  идентичны ktav 0.1.4 — проверено собственными pinning-тестами ktav).
  Отображение `ktav::ErrorKind` на структурную иерархию PHP-исключений
  (`KtavMissingSeparatorSpaceException`, `KtavDuplicateKeyException` и
  т.д.) — отдельная последующая работа, описанная в
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md).

Packagist: `ktav-lang/ktav` ^0.1.2.

>>>>> lang=zh

## 0.1.2 —— 2026-05-03

### 变更

- **已跟进 `ktav 0.1.5`** —— 上游 Rust crate 现在提供了
  `Error::Structured(ErrorKind)`,带字节偏移 span,对错误枚举追溯应用
  `#[non_exhaustive]`,并给出公开的事件式解析器 `ktav::thin`。PHP 绑定
  对用户可见的行为没有变化:`KtavException` 仍携带相同的人类可读消息
  (七个标准类别的 `Display` 字符串与 ktav 0.1.4 逐字节相同 —— 由 ktav
  自己的 pinning 测试验证)。将 `ktav::ErrorKind` 映射到结构化的 PHP
  异常层级(`KtavMissingSeparatorSpaceException`、
  `KtavDuplicateKeyException` 等)是单独的后续工作,记录在工作区的
  [`STRUCTURED_ERRORS.md`](https://github.com/ktav-lang/.github/blob/main/STRUCTURED_ERRORS.md)。

Packagist:`ktav-lang/ktav` ^0.1.2。

