>>>>> lang=en
### Known limitations

- **An empty Object and an empty Array are indistinguishable after
  parsing.** PHP has a single `array` type for both compounds, and an
  *empty* compound loses which one it was the moment `Ktav::loads()`
  returns it:

  ```php
  Ktav::loads("a: {}\n");  // → ["a" => []]
  Ktav::loads("a: []\n");  // → ["a" => []] — same value
  ```

  Written back, both come out as `a: []`. At the document root the
  ambiguity resolves the other way: an empty PHP array is emitted as
  an empty Object (`Ktav::dumps([])` → `{}`), so a top-level `[]`
  document reads back and re-renders as `{}`. A configuration storing
  `a: {}` and expecting `a: {}` back will observe `a: []`.

  Non-empty compounds are unaffected — object vs array shape is
  recoverable from key form. Fixing this requires representing
  objects differently from lists on the PHP side (for example
  decoding to `stdClass`), which is a breaking public-API change.
  The conformance suite keeps the six affected fixtures as named,
  skipped specs rather than silently relaxing their assertions.

>>>>> lang=ru
### Известные ограничения

- **Пустой Object и пустой Array неразличимы после разбора.** В PHP один
  тип `array` для обоих составных значений, и *пустое* составное значение
  теряет информацию о том, чем оно было, в момент возврата из
  `Ktav::loads()`:

  ```php
  Ktav::loads("a: {}\n");  // → ["a" => []]
  Ktav::loads("a: []\n");  // → ["a" => []] — то же значение
  ```

  При обратной записи оба выводятся как `a: []`. На уровне корня документа
  неоднозначность разрешается в обратную сторону: пустой PHP-массив
  выводится как пустой Object (`Ktav::dumps([])` → `{}`), поэтому документ
  верхнего уровня `[]` читается обратно и повторно выводится как `{}`.
  Конфигурация, сохранившая `a: {}` и ожидающая `a: {}` обратно, получит
  `a: []`.

  Непустые составные значения не затронуты — форма object или array
  восстанавливается по виду ключей. Исправление требует представлять
  объекты иначе, чем списки, на стороне PHP (например, декодировать в
  `stdClass`), что является ломающим изменением публичного API. Набор
  конформных тестов сохраняет шесть затронутых fixtures как именованные
  пропускаемые спецификации, а не молча ослабляет их проверки.

>>>>> lang=zh
### 已知限制

- **空的 Object 与空的 Array 在解析后无法区分。** PHP 对两种复合值只有
  一个 `array` 类型,*空*复合值在 `Ktav::loads()` 返回的那一刻就丢失了
  自己原本是哪一种:

  ```php
  Ktav::loads("a: {}\n");  // → ["a" => []]
  Ktav::loads("a: []\n");  // → ["a" => []] —— 相同的值
  ```

  写回时两者都输出为 `a: []`。在文档根部,歧义向相反方向消解:空的 PHP
  数组会输出为空 Object(`Ktav::dumps([])` → `{}`),因此顶层的 `[]`
  文档读回再渲染会变成 `{}`。存了 `a: {}` 并期望读回 `a: {}` 的配置
  实际会得到 `a: []`。

  非空复合值不受影响 —— object 与 array 的形状可由键的形式恢复。修复它
  需要在 PHP 侧用不同方式表示对象与列表(例如解码为 `stdClass`),这属于
  破坏性的公共 API 变更。一致性测试套件将六个受影响的 fixture 保留为
  带名字的跳过规范,而不是静默放宽其断言。

