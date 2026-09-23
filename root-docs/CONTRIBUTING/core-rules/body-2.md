>>>>> lang=en
`tests/TestPaths.php` is the shared helper: it locates the built cabi
under `target/release/` and the spec fixtures, and honours
`KTAV_LIB_PATH`.

### 2. Don't reinvent the format in the binding

This library is deliberately a thin wrapper. Parser and format
behaviour belong in the Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)) — changing it
there updates every language binding at once. Only **PHP-specific
ergonomics** (the FFI loader, the JSON wire envelope, the exception
surface) belong in this repo.

If your change requires a format change, start a discussion in
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) first.

### 3. Public API changes note compatibility

If you touch anything exported from `Ktav\`, say in the PR description
whether it is:

>>>>> lang=ru
`tests/TestPaths.php` — общий помощник: он находит собранный cabi под
`target/release/` и spec-фикстуры, и учитывает `KTAV_LIB_PATH`.

### 2. Не переосмысливайте формат в биндинге

Эта библиотека — сознательно тонкая обёртка. Поведение парсера и
формата — в Rust-крейте
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)): правка там
обновляет все языковые биндинги одновременно. Здесь — только
**PHP-специфичная эргономика** (FFI-лоадер, JSON-обёртка провода,
поверхность исключений).

Если правка требует изменения формата — сначала обсуждение в
[`ktav-lang/spec`](https://github.com/ktav-lang/spec).

### 3. Изменения публичного API помечаются по совместимости

Если трогаете экспорт из `Ktav\`, укажите в PR-описании:

>>>>> lang=zh
`tests/TestPaths.php` 是共享助手:它定位 `target/release/` 下构建好的
cabi 与 spec 固定装置,并遵循 `KTAV_LIB_PATH`。

### 2. 不要在绑定里重造格式

这个库刻意保持为薄封装。解析器和格式行为属于 Rust crate
([`ktav-lang/rust`](https://github.com/ktav-lang/rust)) —— 在那边改
等于一次更新所有语言绑定。这里只放 **PHP 特定的人体工学**
(FFI 加载器、JSON 线信封、异常面)。

如果改动需要格式变更,请先到
[`ktav-lang/spec`](https://github.com/ktav-lang/spec) 讨论。

### 3. 公共 API 改动需标注兼容性

如果你动到 `Ktav\` 的导出项,请在 PR 描述里说明它属于:

