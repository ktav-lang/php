>>>>> lang=en
## 0.1.0 — first public release

First release. Targets **Ktav format 0.1**.

### Coordinates

Artifact group/name: `io.github.ktav-lang:ktav`. Maven Central
publication is planned; for now JARs ship as GitHub Release assets.

### Public API

- `Ktav.loads(String) -> Value` — parse a Ktav document.
- `Ktav.dumps(Value) -> String` — render a `array` as Ktav text.
- `Ktav.nativeVersion() -> String` — version of the loaded `ktav_cabi`.
- `KtavException` — parse / render error with the native-side message.
- `array` — associative array with seven variants (`Null`, `Bool`, `Int`,
  `Flt`, `Str`, `Arr`, `Obj`), mirroring the Rust crate's `array` enum.

### Architecture

>>>>> lang=ru
## 0.1.0 — первый публичный релиз

Первый релиз. Целевой формат — **Ktav 0.1**.

### Координаты

group/name артефакта: `io.github.ktav-lang:ktav`. Публикация в Maven
Central запланирована; пока JAR распространяются как ассеты GitHub
Release.

### Публичный API

- `Ktav.loads(String) -> Value` — разбор документа Ktav.
- `Ktav.dumps(Value) -> String` — вывод `array` в текст Ktav.
- `Ktav.nativeVersion() -> String` — версия загруженного `ktav_cabi`.
- `KtavException` — ошибка разбора / вывода с сообщением от нативной
  стороны.
- `array` — ассоциативный массив с семью вариантами (`Null`, `Bool`, `Int`,
  `Flt`, `Str`, `Arr`, `Obj`), повторяющий enum `array` из Rust-крейта.

### Архитектура

>>>>> lang=zh
## 0.1.0 —— 首次公开发布

首次发布。目标格式版本:**Ktav 0.1**。

### 构件坐标

构件的 group/name:`io.github.ktav-lang:ktav`。Maven Central 发布已
规划;在此之前 JAR 作为 GitHub Release 资产分发。

### 公共 API

- `Ktav.loads(String) -> Value` —— 解析 Ktav 文档。
- `Ktav.dumps(Value) -> String` —— 将 `array` 渲染为 Ktav 文本。
- `Ktav.nativeVersion() -> String` —— 已加载 `ktav_cabi` 的版本。
- `KtavException` —— 解析 / 渲染错误,消息来自原生侧。
- `array` —— 具有七种变体(`Null`、`Bool`、`Int`、`Flt`、`Str`、`Arr`、
  `Obj`)的关联数组,与 Rust crate 的 `array` 枚举一一对应。

### 架构

