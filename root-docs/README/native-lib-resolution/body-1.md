>>>>> lang=en
## How the native library is resolved

On first call:

1. **`KTAV_LIB_PATH`** env var, if set.
2. **User cache** — `<userCache>/ktav-php/v<version>/<asset>`, downloaded
   on a previous call.
3. **GitHub Release download** — fetched once from
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` and
   cached under (2). Requires network on first call after install.

`<userCache>` is `%LOCALAPPDATA%` on Windows, `~/Library/Caches` on
macOS, `$XDG_CACHE_HOME` or `~/.cache` on Linux.

Version 0.8.1 native assets are resolved by the steps above. Overrides and cached binaries are verified at load time: the loaded library must report version 0.8.1 or a KtavException names expected vs actual. The
pure PHP backend (`Ktav\PureBackend`) bypasses this entire chain —
it needs no native library, even with `ext-ffi` disabled.

>>>>> lang=ru
## Как резолвится нативная библиотека

При первом вызове:

1. **`KTAV_LIB_PATH`** env-переменная, если задана.
2. **Кэш пользователя** — `<userCache>/ktav-php/v<version>/<asset>`, скачанный
   на одном из предыдущих вызовов.
3. **Скачивание с GitHub Release** — загружается один раз с
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` и
   кладётся в кэш (2). Сеть нужна на первый вызов после установки.

`<userCache>` — это `%LOCALAPPDATA%` на Windows, `~/Library/Caches` на
macOS, `$XDG_CACHE_HOME` или `~/.cache` на Linux.

Нативные ассеты версии 0.8.1 резолвятся по шагам выше. Переопределения и кэшированные бинарники проверяются при загрузке: загруженная библиотека должна сообщать версию 0.8.1, иначе KtavException назовёт ожидаемую и фактическую. Чистый
PHP-бэкенд (`Ktav\PureBackend`) обходит всю эту цепочку — ему не нужна
нативная библиотека, даже при выключенном `ext-ffi`.

>>>>> lang=zh
## 原生库的查找顺序

首次调用时：

1. **`KTAV_LIB_PATH`** 环境变量（若已设置）。
2. **用户缓存** —— `<userCache>/ktav-php/v<version>/<asset>`，在此前某次调用中
   下载。
3. **从 GitHub Release 下载** —— 一次性从
   `github.com/ktav-lang/php/releases/download/v<version>/<asset>` 获取并
   缓存到 (2)。安装后的首次调用需要网络。

`<userCache>` 在 Windows 上是 `%LOCALAPPDATA%`，macOS 上是
`~/Library/Caches`，Linux 上是 `$XDG_CACHE_HOME` 或 `~/.cache`。

版本 0.8.1 的原生资源按上述步骤解析。覆盖路径与缓存的二进制在加载时会被校验：加载的库必须报告版本 0.8.1，否则抛出 KtavException 并指明期望与实际版本。纯 PHP 后端
（`Ktav\PureBackend`）完全绕过这条链 —— 即使 `ext-ffi` 被禁用，
它也不需要原生库。

