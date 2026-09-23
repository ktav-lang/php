>>>>> lang=en
- **semver-compatible** (additions, looser signatures, doc changes); or
- **semver-breaking** (renamed / removed items, changed signatures,
  tightened types) — in which case the version bump lands in the next
  MINOR while we are pre-1.0.

Update the CHANGELOG source units under `root-docs/CHANGELOG/` (all
three `>>>>> lang=` blocks) in the same PR and regenerate the output.

### 4. One concept per commit

Commits should be atomic: a bug fix and its test together, a feature
and its tests together, a rename on its own, a refactor on its own.
`git log --oneline` should read like a changelog. Don't prefix commit
messages with `feat:` / `fix:` — no conventional commits here.

### 5. The native library stays in lockstep with the package

`NativeLib::LIB_VERSION` in `src/NativeLib.php` **must** match the git
tag used to cut the release. If you bump the library version, update
`LIB_VERSION` in the same commit. Mismatched values cause consumers to
download a native library that doesn't match their code.

>>>>> lang=ru
- **semver-совместимо** (добавления, ослабления сигнатур, доки); или
- **semver-ломающее** (переименования / удаления, изменения сигнатур,
  ужесточения типов) — bump пойдёт в следующий MINOR, пока мы pre-1.0.

Обновите CHANGELOG-юниты под `root-docs/CHANGELOG/` (все три блока
`>>>>> lang=`) в том же PR и перегенерируйте вывод.

### 4. Один концепт — один коммит

Коммиты атомарные: фикс вместе с тестом, фича вместе с тестами,
переименование — отдельно, рефакторинг — отдельно. `git log --oneline`
должен читаться как changelog. Не префиксуйте сообщения `feat:` /
`fix:` — здесь нет conventional commits.

### 5. Нативная библиотека шагает в lockstep с пакетом

`NativeLib::LIB_VERSION` в `src/NativeLib.php` **обязан** совпадать с
git-тегом, которым вырезан релиз. Если бампите версию библиотеки —
правьте `LIB_VERSION` в том же коммите. Рассогласование заставит
потребителей скачивать нативку, не соответствующую их коду.

>>>>> lang=zh
- **semver 兼容**(新增、签名放宽、文档改动);或
- **semver 破坏性**(重命名 / 移除、签名变更、类型收紧)——
  这种情况下版本递增会落在下一个 MINOR,毕竟我们还处于 pre-1.0。

在同一个 PR 中更新 `root-docs/CHANGELOG/` 下的 CHANGELOG 源单元
(全部三个 `>>>>> lang=` 块)并重新生成产物。

### 4. 一个概念一次提交

提交应当原子化:bug 修复和它的测试一起、功能和它的测试一起、
重命名单独、重构单独。`git log --oneline` 应当读起来像 changelog。
不要给提交消息加 `feat:` / `fix:` 前缀 —— 这里不走 conventional commits。

### 5. 原生库与包步调一致

`src/NativeLib.php` 里的 `NativeLib::LIB_VERSION` **必须** 与用于切
版本的 git tag 一致。如果你提升了库版本,请在同一个提交里更新
`LIB_VERSION`。两者不一致会让使用方下载到与代码不匹配的原生库。

