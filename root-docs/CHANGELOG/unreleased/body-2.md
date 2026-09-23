>>>>> lang=en
- **`KtavException` now carries the nine structured error fields** from
  the Rust core's error envelope: `getError()`, `getReason()`,
  `getErrorLine()`, `getLineText()`, `getSpan()`, `getPath()`,
  `getBody()`, `getCanonical()`, `getSpecSection()`. Absent information
  is `null`, never a missing accessor.

  `getPath()` returns an **array of exact decoded key segments, never a
  joined string** — a key literally named `a.b` is one segment and
  cannot be confused with a two-segment path.

  ```php
  try {
      Ktav::loadsStrict("a: 1.10\n");
  } catch (KtavException $e) {
      $e->getError();        // "LossyScalar"
      $e->getBody();         // "1.10"
      $e->getCanonical();    // "1.1"
      $e->getSpecSection();  // "§3.6/§5.2"
  }
  ```

>>>>> lang=ru
- **`KtavException` теперь несёт девять структурных полей ошибки** из
  конверта ошибок ядра на Rust: `getError()`, `getReason()`,
  `getErrorLine()`, `getLineText()`, `getSpan()`, `getPath()`, `getBody()`,
  `getCanonical()`, `getSpecSection()`. Отсутствующие сведения — `null`,
  а не недостающий аксессор.

  `getPath()` возвращает **массив точных декодированных сегментов ключа,
  а не склеенную строку** — ключ, буквально названный `a.b`, является
  одним сегментом и не может быть спутан с путём из двух сегментов.

  ```php
  try {
      Ktav::loadsStrict("a: 1.10\n");
  } catch (KtavException $e) {
      $e->getError();        // "LossyScalar"
      $e->getBody();         // "1.10"
      $e->getCanonical();    // "1.1"
      $e->getSpecSection();  // "§3.6/§5.2"
  }
  ```

>>>>> lang=zh
- **`KtavException` 现在携带来自 Rust 核心错误信封的九个结构化字段**:
  `getError()`、`getReason()`、`getErrorLine()`、`getLineText()`、
  `getSpan()`、`getPath()`、`getBody()`、`getCanonical()`、
  `getSpecSection()`。缺失的信息是 `null`,而不是缺少访问器。

  `getPath()` 返回**精确解码后的键段数组,绝不是拼接字符串** ——
  字面名为 `a.b` 的键就是一个段,不会与两段路径混淆。

  ```php
  try {
      Ktav::loadsStrict("a: 1.10\n");
  } catch (KtavException $e) {
      $e->getError();        // "LossyScalar"
      $e->getBody();         // "1.10"
      $e->getCanonical();    // "1.1"
      $e->getSpecSection();  // "§3.6/§5.2"
  }
  ```

