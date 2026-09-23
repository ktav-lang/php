>>>>> lang=en
### Platforms

Prebuilt native binaries ship for:

- `linux/amd64`, `linux/arm64` (glibc)
- `darwin/amd64`, `darwin/arm64`
- `windows/amd64`, `windows/arm64`

Alpine (musl) is planned for a follow-up.

### Test coverage

Runs the full Ktav 0.1 conformance suite (all `valid/` and `invalid/`
fixtures) on JDK 17 / 21 across Linux / macOS / Windows.

### Credits

Built on top of the reference `ktav` Rust crate. Dynamic loading via
[FFI](https://github.com/java-native-access/jna). JSON streaming via
[Jackson](https://github.com/FasterXML/jackson-core).
>>>>> lang=ru
### Платформы

Предсобранные нативные бинарники поставляются для:

- `linux/amd64`, `linux/arm64` (glibc)
- `darwin/amd64`, `darwin/arm64`
- `windows/amd64`, `windows/arm64`

Alpine (musl) планируется в следующем релизе.

### Покрытие тестами

Запускается полный conformance-набор Ktav 0.1 (все fixtures `valid/` и
`invalid/`) на JDK 17 / 21 на Linux / macOS / Windows.

### Благодарности

Построено поверх референсного Rust-крейта `ktav`. Динамическая загрузка —
через [FFI](https://github.com/java-native-access/jna). Потоковый JSON —
через [Jackson](https://github.com/FasterXML/jackson-core).
>>>>> lang=zh
### 平台

预编译原生二进制覆盖:

- `linux/amd64`、`linux/arm64`(glibc)
- `darwin/amd64`、`darwin/arm64`
- `windows/amd64`、`windows/arm64`

Alpine(musl)计划在后续版本加入。

### 测试覆盖

在 JDK 17 / 21 跨 Linux / macOS / Windows 上运行完整的 Ktav 0.1
一致性套件(所有 `valid/` 与 `invalid/` fixture)。

### 致谢

构建在参考 Rust crate `ktav` 之上。动态加载通过
[FFI](https://github.com/java-native-access/jna)。流式 JSON 通过
[Jackson](https://github.com/FasterXML/jackson-core)。
