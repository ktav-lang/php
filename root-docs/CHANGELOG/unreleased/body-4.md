>>>>> lang=en
- Migrated `crates/cabi` to a single `ktav::declare_cabi!()` invocation
  (ktav's `cabi` feature) instead of a hand-rolled C ABI shim; the
  exported symbol surface is unchanged, so the PHP API is unaffected.
  Dependency floor raised to **0.8.0**, spec submodule re-pinned to
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
  Нижняя граница зависимости поднята до **0.8.0**, подмодуль spec
  перезакреплён на `v0.8.0` (добавлен § 5.2: десятичное число с
  избыточным ведущим нулём разбирается как String, а не Integer).
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
- `crates/cabi` 改为单次调用 `ktav::declare_cabi!()`(ktav 的 `cabi`
  特性),取代手写的 C ABI 垫片;导出的符号集不变,因此绑定 API 不受
  影响。依赖下限提升至 **0.8.0**,spec 子模块重新固定到 `v0.8.0`
  (新增 § 5.2:带多余前导零的十进制数解析为 String,而非 Integer)。
- 包版本升至 **0.8.0**,与核心和规范同步;预编译库的回退下载现在指向
  `v0.8.0` 发布资产。

- 跟踪 ktav 0.7.0 与 spec 0.7.0:带引号的键(§ 5.3.3)与 inline 值中的
  `\uXXXX` 转义(§ 3.7.1)来自 Rust 内核,跨越 FFI 边界完全透明 ——
  绑定源码除依赖升级外未改动。MSRV 提升至 Rust 1.71(ktav 0.7 的真实
  MSRV);`[package.metadata.ktav] spec-version` 现为 "0.7.0"。
- 一致性测试套件指向 `spec/versions/0.8/tests`(子模块重新固定到
  `0.8.0` 之后,它一直静默读取过期的 `0.7` 语料——路径是硬编码的,
  并非从固定版本推导而来),并执行语料中的每个类别:
  `unrepresentable/` 与 `parseable-unrepresentable/`(写入方必须拒绝
  fixture 的值 / 可正常解析但规范输出必须拒绝的值),以及新增的
  `strict-lossy/`(`loads()` 必须等于 lax 值,`loadsStrict()` 必须以
  匹配的原因、body 与规范形式抛出异常)。一个 guard 测试会在语料中
  出现无法识别的类别目录时使构建失败,以防止这个问题再次悄然发生。

