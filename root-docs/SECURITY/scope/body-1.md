>>>>> lang=en
## Scope

Issues that count as security problems for this package:

- Out-of-bounds reads / writes or panics in the native `ktav_cabi`
  shared library that crash or hang the host PHP process. The library is
  loaded via FFI (`Native.load`), so a native crash tears down the
  whole PHP process — no Java-side `catch` can stop it.
- Runaway memory or CPU when parsing crafted input.
- Incorrect FFI memory handling (double-free, missing free, reading
  freed buffers across the `ktav_free` boundary).
- Any behaviour that allows crafted Ktav input to escape the expected
  value-domain (arbitrary code execution in the loaded library,
  uninitialised-memory disclosure, etc.).
- A download-time vector: `NativeLoader` fetches the prebuilt
  binary from the matching GitHub Release. Reports about TLS /
  integrity-check gaps in that path belong here.

Issues that are **not** security problems here — please use regular
issues for these:

- Performance regressions without crash / hang characteristics.
- Behavioural mismatches that aren't exploitable.
- Problems in the Ktav format itself — those belong in
  [`ktav-lang/spec`](https://github.com/ktav-lang/spec).
>>>>> lang=ru
## Область

Что считается проблемой безопасности для этого пакета:

- Out-of-bounds чтения / записи или паники в нативной библиотеке
  `ktav_cabi`, которые роняют или вешают хост-PHP process. Библиотека
  грузится через FFI (`Native.load`), поэтому нативный crash уносит всю
  PHP process — на Java-стороне его никаким `catch` не остановить.
- Неконтролируемое потребление памяти или CPU при разборе специально
  сформированного входа.
- Некорректная работа с памятью на FFI-границе (double-free, missing
  free, чтение освобождённых буферов за границей `ktav_free`).
- Любое поведение, при котором сформированный Ktav-вход выходит за
  ожидаемый value-домен (произвольное выполнение кода в загруженной
  библиотеке, раскрытие неинициализированной памяти и т. п.).
- Download-time вектор: `NativeLoader` качает прекомпилированный
  бинарь из соответствующего GitHub Release. Репорты про пробелы TLS /
  проверки целостности в этой цепочке сюда.

Что **не** считается проблемой безопасности здесь — пожалуйста,
используйте обычные issue:

- Регрессии производительности без характеристик crash / hang.
- Поведенческие расхождения, которые не эксплуатируются.
- Проблемы в самом формате Ktav — им место в
  [`ktav-lang/spec`](https://github.com/ktav-lang/spec).
>>>>> lang=zh
## 范围

以下问题算作本包的安全问题:

- 原生 `ktav_cabi` 共享库中的越界读写或 panic,导致宿主 PHP process
  崩溃或挂起。该库通过 FFI(`Native.load`)加载,原生崩溃会直接拉垮
  整个 PHP process,Java 侧任何 `catch` 都拦不住。
- 解析构造输入时出现失控的内存或 CPU 消耗。
- FFI 边界上的内存处理错误(double-free、missing free、越过
  `ktav_free` 边界读取已释放缓冲区)。
- 任何让构造的 Ktav 输入逃逸出预期值域的行为(加载库内的任意代码
  执行、未初始化内存泄露等)。
- 下载期向量:`NativeLoader` 会从对应的 GitHub Release 拉取预编译
  二进制。该路径上关于 TLS / 完整性校验缺口的报告属于这里。

以下**不**算本包的安全问题 —— 请使用普通 issue:

- 没有崩溃 / 挂起特征的性能回归。
- 不可利用的行为差异。
- Ktav 格式本身的问题 —— 这些属于
  [`ktav-lang/spec`](https://github.com/ktav-lang/spec)。
