>>>>> lang=en
- Migrated `crates/cabi` to a single `ktav::declare_cabi!()` invocation
  (ktav's `cabi` feature) instead of a hand-rolled C ABI shim; the
  exported symbol surface is unchanged, so the PHP API is unaffected.
  Dependency floor is **0.8.0**, and the spec submodule is pinned to
  `v0.8.0` (adds § 5.2: a decimal with a redundant leading zero parses
  as a String, not an Integer).

- The package version moves to **0.8.0**, in step with the core and the
  specification; the prebuilt-library download fallback now targets the
  `v0.8.0` release asset.

- Tracks ktav 0.7.0 and spec 0.7.0: quoted keys (§ 5.3.3) and the
  `\uXXXX` escape in inline values (§ 3.7.1) come from the Rust core
  and are transparent across the FFI boundary — binding source
  unchanged apart from the dependency bump. MSRV raised to Rust 1.71
  (ktav 0.7's real MSRV); `[package.metadata.ktav] spec-version` is
  now "0.7.0".
- Conformance suite points at `spec/versions/0.8/tests` (it silently
  kept reading the stale `0.7` corpus after the submodule was
  re-pinned to `0.8.0` — the path was hardcoded, not derived from the
  pin) and executes every fixture category the corpus ships:
  `unrepresentable/` and `parseable-unrepresentable/` (the writer must
  refuse the fixture's value / a parseable value the canonical emit
  must refuse) and the new `strict-lossy/` (`loads()` must equal the
  lax value, `loadsStrict()` must throw with the matching reason, body
  and canonical form). A guard test fails the build if an unrecognized
  category directory appears under the corpus, so a future addition
  can't repeat this silently.

>>>>> lang=ru
- `crates/cabi` переведён на единственный вызов `ktav::declare_cabi!()`
  (фича `cabi` крейта ktav) вместо самописной C ABI-прослойки; набор
  экспортируемых символов не изменился, поэтому API биндинга не затронут.
  Минимальная версия ядра — **0.8.0**, подмодуль spec закреплён на
  `v0.8.0` (добавлен § 5.2: десятичное число с избыточным ведущим нулём
  разбирается как String, а не Integer).

- Версия пакета переходит на **0.8.0**, синхронно с ядром и
  спецификацией; резервная загрузка предсобранной библиотеки теперь
  нацелена на ассет релиза `v0.8.0`.

- Отслеживает ktav 0.7.0 и spec 0.7.0: ключи в кавычках (§ 5.3.3) и
  escape-последовательность `\uXXXX` в inline-значениях (§ 3.7.1) приходят
  из ядра на Rust и прозрачны через границу FFI — исходники биндинга не
  менялись, кроме подъёма версии зависимости. MSRV поднят до Rust 1.71
  (настоящий MSRV ktav 0.7); `[package.metadata.ktav] spec-version` теперь
  равен "0.7.0".
- Набор конформных тестов указывает на `spec/versions/0.8/tests` (после
  перезакрепления сабмодуля на `0.8.0` он молча продолжал читать
  устаревший корпус `0.7` — путь был захардкожен, а не выведен из
  пина) и выполняет все категории корпуса: `unrepresentable/` и
  `parseable-unrepresentable/` (писатель обязан отвергнуть значение
  fixture / разбираемое значение, канонический вывод которого обязан
  отказать), а также новую `strict-lossy/` (`loads()` обязан совпасть
  с lax-значением, `loadsStrict()` обязан выбросить исключение с
  соответствующей причиной, телом и канонической формой). Guard-тест
  обрушивает сборку при появлении нераспознанной категории в корпусе,
  чтобы это не повторилось молча.

>>>>> lang=zh
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

