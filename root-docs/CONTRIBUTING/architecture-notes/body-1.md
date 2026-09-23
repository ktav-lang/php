>>>>> lang=en
## Architecture notes

- **Wire format.** Rust and PHP exchange JSON over the FFI boundary,
  with `{"$i":"..."}` / `{"$f":"..."}` wrappers for typed integers /
  floats. Arbitrary-precision integers cross as digit strings;
  `WireJson` revives a `$i` to an `int` when casting round-trips —
  `(string)(int) $digits === $digits` — and otherwise hands the digit
  string back to the caller.
- **Memory ownership.** Rust allocates the output buffer; PHP copies
  the bytes out and calls `ktav_free` on the Rust side. No buffer is
  long-lived across the FFI boundary.
- **Loader.** `NativeLoader::resolve()` picks the shared library in
  order: the `KTAV_LIB_PATH` env var → the user cache
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`, with
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` as the
  per-OS cache root) → a one-time download of the matching GitHub
  Release asset, cached under the same path. The resolution order
  mirrors the Java / Go / .NET bindings.
- **Docs.** The published Markdown is generated from the `root-docs/`
  unit trees by `@ktav-lang/polydoc` (`node scripts/build-docs.mjs`)
  — edit the units, never the generated `.md`.

>>>>> lang=ru
## Архитектурные заметки

- **Wire-формат.** Rust и PHP обмениваются JSON через FFI-границу,
  с обёртками `{"$i":"..."}` / `{"$f":"..."}` для типизированных
  integer / float. Целые произвольной точности идут строками цифр;
  `WireJson` оживляет `$i` в `int`, когда приведение типа проходит
  туда-обратно — `(string)(int) $digits === $digits` — и иначе
  возвращает вызывающему строку цифр.
- **Владение памятью.** Rust аллоцирует выходной буфер; PHP копирует
  байты и вызывает `ktav_free` на Rust-стороне. Никакой буфер не
  живёт долго через FFI-границу.
- **Loader.** `NativeLoader::resolve()` выбирает shared library в
  порядке: env-переменная `KTAV_LIB_PATH` → пользовательский кэш
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`, корень кэша —
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` в
  зависимости от ОС) → разовая загрузка ассета соответствующего
  GitHub Release, которая кладётся в тот же путь. Порядок резолвинга
  повторяет биндинги Java / Go / .NET.
- **Доки.** Публикуемый Markdown генерируется из unit-деревьев
  `root-docs/` пакетом `@ktav-lang/polydoc`
  (`node scripts/build-docs.mjs`) — правьте юниты, никогда не
  сгенерированные `.md`.

>>>>> lang=zh
## 架构笔记

- **Wire 格式。** Rust 与 PHP 通过 FFI 边界交换 JSON,用
  `{"$i":"..."}` / `{"$f":"..."}` 包装带类型的整数 / 浮点。任意精度
  整数以数字串跨越边界;当强转可以往返时 ——
  `(string)(int) $digits === $digits` —— `WireJson`
  会把 `$i` 还原为 `int`,否则把数字字符串原样交还给调用方。
- **内存所有权。** Rust 分配输出缓冲;PHP 复制出字节后在 Rust 侧
  调用 `ktav_free`。没有缓冲会长期跨越 FFI 边界。
- **加载器。** `NativeLoader::resolve()` 按顺序挑选共享库:
  `KTAV_LIB_PATH` 环境变量 → 用户缓存
  (`<userCache>/ktav-php/v<LIB_VERSION>/<asset>`,按操作系统取
  `%LOCALAPPDATA%` / `~/Library/Caches` / `$XDG_CACHE_HOME` 作为
  缓存根)→ 一次性下载对应的 GitHub Release 资产,并存到同一路径。
  解析顺序与 Java / Go / .NET 绑定一致。
- **文档。** 公开的 Markdown 由 `root-docs/` 单元树经
  `@ktav-lang/polydoc`(`node scripts/build-docs.mjs`)生成 ——
  请编辑单元,绝不编辑生成的 `.md`。

