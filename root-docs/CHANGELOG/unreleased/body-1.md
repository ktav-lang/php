>>>>> lang=en
## Unreleased

### Added

- **`Ktav::format(string $src): string`** — a comment-preserving
  formatter over Ktav *source text*, not a value-to-text renderer. It
  normalises the document's structural spelling to canonical form
  (§ 5.9) while keeping the trivia the canonical writer drops. Every
  comment survives verbatim; Ktav has no trailing comments (§ 3.4: a
  comment owns a whole line), so attachment is unambiguous. Blank lines
  survive as a grouping hint, but a run of two or more collapses to
  exactly one and blank padding immediately inside a bracket is
  dropped — which is what makes the transform a fixed point:
  `Ktav::format(Ktav::format($s)) === Ktav::format($s)`. Key order is
  never changed (canonical form has no sorting rule). For a document
  with no comments *and no blank lines* the result equals
  `Ktav::emitCanonical(Ktav::loads($src))`; the stronger condition is
  deliberate, since blank lines are no more part of the value model
  than comments are.

  ```php
  Ktav::format("## the server\nserver: {host: a, port: 80}\n");
  // ## the server
  // server: {
  //     host: a
  //     port: 80
  // }
  ```

>>>>> lang=ru
## Unreleased

### Добавлено

- **`Ktav::format(string $src): string`** — форматтер, сохраняющий
  комментарии, работающий над Ktav *исходным текстом*, а не превращающий
  значение в текст. Он приводит структурное написание документа к
  канонической форме (§ 5.9), сохраняя то оформление, которое отбрасывает
  канонический писатель. Каждый комментарий сохраняется дословно: в Ktav
  нет концевых комментариев (§ 3.4 — комментарий занимает целую строку),
  поэтому привязка однозначна. Пустые строки сохраняются как подсказка
  группировки, но серия из двух и более схлопывается ровно в одну, а
  пустые строки сразу внутри скобки отбрасываются — именно это делает
  преобразование неподвижной точкой:
  `Ktav::format(Ktav::format($s)) === Ktav::format($s)`. Порядок ключей не
  меняется никогда (у канонической формы нет правила сортировки). Для
  документа без комментариев *и без пустых строк* результат равен
  `Ktav::emitCanonical(Ktav::loads($src))`; более сильное условие — намеренное:
  пустые строки — такая же не-часть модели значения, что и комментарии.

  ```php
  Ktav::format("## the server\nserver: {host: a, port: 80}\n");
  // ## the server
  // server: {
  //     host: a
  //     port: 80
  // }
  ```

>>>>> lang=zh
## Unreleased

### 新增

- **`Ktav::format(string $src): string`** —— 一个保留注释的格式化器,
  作用于 Ktav *源文本*,而不是把值渲染成文本。它把文档的结构写法规范到
  规范形式(§ 5.9),同时保留规范写入器会丢弃的琐碎内容。每条注释都逐字
  保留:Ktav 没有行尾注释(§ 3.4,注释独占整行),因此归属毫无歧义。空行
  作为分组提示保留,但连续两行及以上会折叠为恰好一行,紧贴括号内侧的
  空填充会被丢弃 —— 正是这一点让该变换成为不动点:
  `Ktav::format(Ktav::format($s)) === Ktav::format($s)`。键序永不改变
  (规范形式没有排序规则)。对于既无注释*也无空行*的文档,结果等于
  `Ktav::emitCanonical(Ktav::loads($src))`;这个更强的条件是刻意的,
  因为空行与注释一样,都不属于值模型。

  ```php
  Ktav::format("## the server\nserver: {host: a, port: 80}\n");
  // ## the server
  // server: {
  //     host: a
  //     port: 80
  // }
  ```

