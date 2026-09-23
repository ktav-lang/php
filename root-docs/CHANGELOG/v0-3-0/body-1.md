>>>>> lang=en

## 0.3.0 — 2026-05-08

### Changed (breaking)

- **Picked up `ktav 0.3.0`** — the upstream Rust crate now rejects
  `key: (value)` and `key: ((value))` with
  `ErrorKind::InlineNonEmptyCompound { body: "paren-string" }`.
  These shapes were previously accepted as plain string scalars
  but are visually indistinguishable from multi-line openers. Use
  the raw-marker form `key:: (value)` to encode such literals;
  the ktav-lsp formatter auto-rewrites the legacy form on save.
  See the
  [`ktav` crate CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#030--2026-05-08).

### Fixed

- `LIB_VERSION` now tracks the binding's release tag, so the
  runtime downloads the matching `ktav_cabi-*` asset from
  `https://github.com/ktav-lang/php/releases/download/v0.3.0/`.
  Previously this constant was pinned at `0.1.1` even on `0.2.0`,
  so the loader fetched the older cabi build.

### Spec

- spec submodule synced (paren-string handling tightened —
  new invalid fixtures `inline_paren_string_single` /
  `inline_paren_string_double`; `partial_parens` valid fixture
  trimmed of newly-illegal forms).

>>>>> lang=ru

## 0.3.0 — 2026-05-08

### Изменено (ломающее)

- **Подхвачен `ktav 0.3.0`** — upstream Rust crate теперь отвергает
  `key: (value)` и `key: ((value))` с ошибкой
  `ErrorKind::InlineNonEmptyCompound { body: "paren-string" }`.
  Эти формы ранее принимались как обычные строковые скаляры, но визуально
  они неотличимы от многострочных открывателей. Чтобы закодировать такие
  литералы, используйте форму с сырым маркером `key:: (value)`; форматтер
  ktav-lsp автоматически переписывает унаследованную форму при
  сохранении. См.
  [`CHANGELOG` крейта `ktav`](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#030--2026-05-08).

### Исправлено

- `LIB_VERSION` теперь отслеживает тег релиза биндинга, поэтому
  загрузчик скачивает соответствующий ассет `ktav_cabi-*` из
  `https://github.com/ktav-lang/php/releases/download/v0.3.0/`.
  Раньше эта константа была закреплена на `0.1.1` даже в `0.2.0`,
  поэтому загрузчик брал более старую сборку cabi.

### Спецификация

- подмодуль spec синхронизирован (обработка paren-string ужесточена —
  новые невалидные fixtures `inline_paren_string_single` /
  `inline_paren_string_double`; валидный fixture `partial_parens`
  очищен от новых нелегальных форм).

>>>>> lang=zh

## 0.3.0 —— 2026-05-08

### 变更(破坏性)

- **已跟进 `ktav 0.3.0`** —— 上游 Rust crate 现在会拒绝 `key: (value)`
  与 `key: ((value))`,报
  `ErrorKind::InlineNonEmptyCompound { body: "paren-string" }`。
  这些形状此前被当作普通字符串标量接受,但它们与多行开括号在视觉上
  无法区分。要编码此类字面量,请使用原始标记形式 `key:: (value)`;
  ktav-lsp 格式化器会在保存时自动重写旧形式。参见
  [`ktav` crate 的 CHANGELOG](https://github.com/ktav-lang/rust/blob/main/CHANGELOG.md#030--2026-05-08)。

### 修复

- `LIB_VERSION` 现在跟踪绑定的发布标签,因此运行时下载对应的
  `ktav_cabi-*` 资产,来自
  `https://github.com/ktav-lang/php/releases/download/v0.3.0/`。
  此前该常量即使到了 `0.2.0` 也固定在 `0.1.1`,加载器因此会取回
  较旧的 cabi 构建。

### 规范

- spec 子模块已同步(paren-string 处理收紧 —— 新增非法 fixture
  `inline_paren_string_single` / `inline_paren_string_double`;合法
  fixture `partial_parens` 已剔除新近非法的形式)。

