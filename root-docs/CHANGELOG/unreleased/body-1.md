>>>>> lang=en
## Unreleased

### 0.8.1

#### Added

- **Pure-PHP backend** — a full parser AND writer implementation of
  Ktav 0.8.1 in pure PHP, usable in both directions as a complete
  alternative to the Rust-backed implementation. Opt in per call via
  `Ktav\PureBackend` (`loads`, `loadsStrict`, `dumps`,
  `dumpsForceStrings`, `emitCanonical`, `format`,
  `canonicalFromSource`, `version()`, `isAvailable()`); the native
  default `Ktav` facade is unchanged, there is no global backend flag
  and no silent fallback in either direction. The pure backend
  requires only `ext-json` — no FFI extension and no native binary
  download. The same `KtavException` nine-field error envelopes with
  byte-offset spans are produced on both paths.

#### Changed

- Core upgraded to the published Rust ktav **0.8.1**; the spec is
  pinned to 0.8.1 (normative corpus 0.8.1) and native assets are
  built from it.

>>>>> lang=ru
## Unreleased

### 0.8.1

#### Добавлено

- **Чистый PHP-бэкенд** — полная реализация парсера И писателя Ktav
  0.8.1 на чистом PHP, работающая в обе стороны как полноценная
  альтернатива реализации на Rust. Включается на каждый вызов через
  `Ktav\PureBackend` (`loads`, `loadsStrict`, `dumps`,
  `dumpsForceStrings`, `emitCanonical`, `format`,
  `canonicalFromSource`, `version()`, `isAvailable()`); нативный
  фасад `Ktav` по умолчанию не изменился, глобального флага бэкенда
  нет, тихого отката ни в одну сторону нет. Чистому бэкенду нужен
  только `ext-json` — без расширения FFI и без скачивания нативного
  бинарника. На обоих путях формируются одни и те же девятипольные
  конверты ошибок `KtavException` с байтовыми смещениями.

#### Изменено

- Ядро обновлено до опубликованного Rust ktav **0.8.1**; спецификация
  закреплена на 0.8.1 (нормативный корпус 0.8.1), нативные ассеты
  собраны из него.

>>>>> lang=zh
## Unreleased

### 0.8.1

#### 新增

- **纯 PHP 后端** —— 用纯 PHP 实现的 Ktav 0.8.1 完整解析器与写入器，
  双向皆可，作为 Rust 后端实现的完整替代。通过 `Ktav\PureBackend`
  在每次调用时显式选择（`loads`、`loadsStrict`、`dumps`、
  `dumpsForceStrings`、`emitCanonical`、`format`、
  `canonicalFromSource`、`version()`、`isAvailable()`）；默认的原生
  `Ktav` 门面保持不变，没有全局后端开关，任何方向都不会静默回退。
  纯后端只需要 `ext-json` —— 无需 FFI 扩展，也无需下载原生二进制。
  两条路径产生相同的 `KtavException` 九字段错误信封，带字节偏移区间。

#### 变更

- 核心升级到已发布的 Rust ktav **0.8.1**；规范固定为 0.8.1
  （规范性语料库 0.8.1），原生资源由其构建。

