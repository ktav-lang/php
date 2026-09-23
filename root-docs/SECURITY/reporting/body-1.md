>>>>> lang=en
## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Email **phpcraftdream@gmail.com** with:

- A short description of the vulnerability.
- Steps or a snippet to reproduce it (Ktav input that triggers the
  behaviour, the affected API, expected vs actual).
- The ktav version you observed it on (JAR coordinates /
  `Ktav.nativeVersion()` output is usually enough), plus the JDK, OS
  and arch so we know which prebuilt `ktav_cabi` was in use.
- Your disclosure timeline preference, if you have one.

You should get an acknowledgement within **72 hours**. A published
fix typically follows within **a week** for high-impact issues, longer
if the fix needs to coordinate with the Rust crate or the format spec.

>>>>> lang=ru
## Сообщение об уязвимости

**Пожалуйста, не открывайте публичный issue по проблемам безопасности.**

Напишите на **phpcraftdream@gmail.com** и укажите:

- Краткое описание уязвимости.
- Шаги или фрагмент для воспроизведения (Ktav-вход, который запускает
  поведение; затронутый API; ожидаемое против фактического).
- Версия ktav, на которой вы наблюдали проблему (обычно достаточно
  координат JAR / вывода `Ktav.nativeVersion()`), плюс JDK, OS и arch,
  чтобы понять, какой прекомпилированный `ktav_cabi` использовался.
- Предпочтительный таймлайн раскрытия, если он у вас есть.

Подтверждение вы получите в течение **72 часов**. Опубликованный фикс
обычно выходит в течение **недели** для высокоприоритетных проблем,
дольше — если фикс нужно согласовать с Rust-крейтом или со спецификацией
формата.

>>>>> lang=zh
## 上报漏洞

**请不要为安全问题开公开 issue。**

请发邮件至 **phpcraftdream@gmail.com**,并提供:

- 对漏洞的简短描述。
- 复现步骤或代码片段(触发该行为的 Ktav 输入、受影响的 API、
  预期结果与实际结果的对比)。
- 你观察到问题时的 ktav 版本(JAR 坐标 / `Ktav.nativeVersion()`
  的输出通常就够了),以及 JDK、OS 和 arch,方便我们确认当时用的是
  哪个预编译 `ktav_cabi`。
- 你偏好的披露时间线(如果有的话)。

你应该在 **72 小时**内收到确认。对于高影响问题,已发布的修复通常在
**一周**内跟进;如果修复需要与 Rust crate 或格式规范协同推进,则
可能要更久。

