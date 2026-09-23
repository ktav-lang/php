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

