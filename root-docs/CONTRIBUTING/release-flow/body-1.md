>>>>> lang=en
## Release flow

Tag `v<X.Y.Z>` on `main`. The release workflow cross-compiles the
`ktav_cabi` cdylib for six targets (`linux` amd64/arm64, `darwin`
amd64/arm64, `windows` amd64/arm64) and attaches every binary to the
GitHub Release under the exact asset name `NativeLoader` constructs
for that platform (`libktav_cabi-linux-amd64.so`,
`libktav_cabi-linux-arm64.so`, `libktav_cabi-darwin-amd64.dylib`,
`libktav_cabi-darwin-arm64.dylib`, `ktav_cabi-windows-amd64.dll`,
`ktav_cabi-windows-arm64.dll`). `NativeLib::LIB_VERSION` in
`src/NativeLib.php` must match the tag — change it in the same commit
as the release.

>>>>> lang=ru
## Процесс релиза

Тег `v<X.Y.Z>` на `main`. Release-workflow кросс-компилирует cdylib
`ktav_cabi` под шесть таргетов (`linux` amd64/arm64, `darwin`
amd64/arm64, `windows` amd64/arm64) и прикрепляет все бинари к
GitHub Release под точными именами ассетов, которые формирует
`NativeLoader` под каждую платформу
(`libktav_cabi-linux-amd64.so`, `libktav_cabi-linux-arm64.so`,
`libktav_cabi-darwin-amd64.dylib`, `libktav_cabi-darwin-arm64.dylib`,
`ktav_cabi-windows-amd64.dll`, `ktav_cabi-windows-arm64.dll`).
`NativeLib::LIB_VERSION` в `src/NativeLib.php` обязан совпадать с
тегом — меняйте его в том же коммите, что и релиз.

>>>>> lang=zh
## 发布流程

在 `main` 上打 `v<X.Y.Z>` tag。release workflow 会为六个目标
(`linux` amd64/arm64、`darwin` amd64/arm64、`windows` amd64/arm64)
交叉编译 `ktav_cabi` cdylib,并把每个二进制以 `NativeLoader`
为该平台构造的准确资产名附加到 GitHub Release
(`libktav_cabi-linux-amd64.so`、`libktav_cabi-linux-arm64.so`、
`libktav_cabi-darwin-amd64.dylib`、`libktav_cabi-darwin-arm64.dylib`、
`ktav_cabi-windows-amd64.dll`、`ktav_cabi-windows-arm64.dll`)。
`src/NativeLib.php` 里的 `NativeLib::LIB_VERSION` 必须与 tag 一致 ——
请在同一个提交里改掉它。

