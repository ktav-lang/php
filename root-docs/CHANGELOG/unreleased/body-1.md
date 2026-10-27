>>>>> lang=en
## Unreleased

- PHP source mutation now includes insert, remove and rename in both native and standalone pure backends. Insert adds a new final Object key without overwriting; remove targets an existing Object member or Array element without pruning ancestors (later Array elements shift); rename requires an existing final Object member and an absent destination key in that Object, preserving position and value. Insert requires an existing parent Object; Array insertion is unsupported. Native uses ABI 2 and the verified Rust core pin `8b6b45ffbd9e50e1588e8ab6b1ae5d422bbe8910`; package versions remain unchanged and this unreleased 0.9 functionality is not part of published 0.8.1.

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

- Мутации исходника PHP теперь включают вставку, удаление и переименование как в native-, так и в самостоятельном pure-бэкенде. Вставка добавляет новый последний ключ Object без перезаписи; удаление затрагивает существующий член Object или элемент Array, не удаляя предков (последующие элементы Array сдвигаются); переименование требует существующий последний член Object и отсутствующий целевой ключ в этом Object, сохраняя позицию и значение. Для вставки нужен существующий родительский Object; вставка в Array не поддерживается. Native использует ABI 2 и проверенный pin ядра Rust `8b6b45ffbd9e50e1588e8ab6b1ae5d422bbe8910`; версии пакетов не меняются, эта ещё не выпущенная функциональность 0.9 не входит в опубликованный 0.8.1.

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

- PHP 源文本修改现已在 native 和独立 pure 后端中支持插入、删除及重命名。插入会添加新的最后一个 Object 键而不覆盖已有键；删除现有 Object 成员或 Array 元素但保留祖先（后续 Array 元素会移位）；重命名要求最后一个 Object 成员存在且同一 Object 中目标键不存在，同时保留位置和值。插入要求父级是现有 Object；不支持向 Array 插入。Native 使用 ABI 2 及已验证的 Rust 核心 pin `8b6b45ffbd9e50e1588e8ab6b1ae5d422bbe8910`；包版本不变，这些尚未发布的 0.9 功能不属于已发布的 0.8.1。

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

