>>>>> lang=en
- **Native core** — the reference Rust `ktav` crate, wrapped with a tiny
  `extern "C"` C ABI (`crates/cabi`) and distributed as a prebuilt
  `.so` / `.dylib` / `.dll`.
- **Java loader** — FFI (no JNI compilation on the consumer side):
  the library is resolved at first call from `$KTAV_LIB_PATH` or
  downloaded once into the user cache from the matching GitHub Release
  asset.
- **Wire format** — JSON between Rust and Java, with `{"$i":"..."}` /
  `{"$f":"..."}` tagged wrappers for lossless typed-integer / typed-float
  round-trips and arbitrary-precision integers (`BigInteger`).

### Type mapping

| Ktav             | `array` variant                                         |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int` (text form — arbitrary precision)           |
| `:f <number>`    | `Value.Flt` (text form — exact round-trip)              |
| bare scalar      | `Value.Str`                                             |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

>>>>> lang=ru
- **Нативное ядро** — референсный Rust-крейт `ktav`, обёрнутый тонким
  `extern "C"` C ABI (`crates/cabi`) и распространяемый как предсобранный
  `.so` / `.dylib` / `.dll`.
- **Java-лоадер** — FFI (без JNI-компиляции на стороне потребителя):
  библиотека резолвится при первом вызове из `$KTAV_LIB_PATH` или
  скачивается один раз в пользовательский кэш из соответствующего ассета
  GitHub Release.
- **Формат обмена** — JSON между Rust и Java с тегированными обёртками
  `{"$i":"..."}` / `{"$f":"..."}` для lossless round-trip типизированных
  целых / дробных и целых произвольной точности (`BigInteger`).

### Соответствие типов

| Ktav             | вариант `array`                                         |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int` (текстовая форма — произвольная точность)   |
| `:f <number>`    | `Value.Flt` (текстовая форма — точный round-trip)       |
| скаляр без маркера | `Value.Str`                                           |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

>>>>> lang=zh
- **原生核心** —— 参考 Rust crate `ktav`,以极简的 `extern "C"` C ABI
  (`crates/cabi`) 封装,并以预编译的 `.so` / `.dylib` / `.dll` 分发。
- **Java 加载器** —— FFI(使用方无需 JNI 编译):库在首次调用时从
  `$KTAV_LIB_PATH` 解析,或一次性从对应的 GitHub Release 资产下载到用户
  缓存。
- **Wire 格式** —— Rust 与 Java 之间使用 JSON,带 `{"$i":"..."}` /
  `{"$f":"..."}` 标记包装,实现带类型的整数 / 浮点无损往返与任意精度
  整数(`BigInteger`)。

### 类型映射

| Ktav             | `array` 变体                                             |
| ---------------- | ------------------------------------------------------- |
| `null`           | `Value.Null.NULL`                                       |
| `true` / `false` | `Value.Bool`                                            |
| `:i <digits>`    | `Value.Int`(文本形式 —— 任意精度)                      |
| `:f <number>`    | `Value.Flt`(文本形式 —— 精确往返)                      |
| 裸 scalar        | `Value.Str`                                             |
| `[ ... ]`        | `Value.Arr` (`List<Value>`)                             |
| `{ ... }`        | `Value.Obj` (`LinkedHashMap<String, Value>`)            |

