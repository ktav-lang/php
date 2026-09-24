>>>>> lang=en
## Key escaping

Since spec 0.6.4 a literal `.` or `:` inside a key segment is written
with a backslash:

```text
## The key is the single segment "a.b" → ["a.b" => "v"]
a\.b: v
## The key contains a colon → ["a:b" => "v"]
a\:b: v
## Split on the first dot only → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

A literal backslash in a key is `\\`.

>>>>> lang=ru
## Экранирование ключей

Начиная со spec 0.6.4, литеральные `.` или `:` внутри сегмента ключа
записываются с обратным слешем:

```text
## Ключ состоит из одного сегмента "a.b" → ["a.b" => "v"]
a\.b: v
## Ключ содержит двоеточие → ["a:b" => "v"]
a\:b: v
## Разделение только по первой точке → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

Литеральный обратный слеш в ключе записывается как `\\`.

>>>>> lang=zh
## 键的转义

自 spec 0.6.4 起，键段内的字面量 `.` 或 `:`
以反斜杠书写：

```text
## 键由单个段 "a.b" 组成 → ["a.b" => "v"]
a\.b: v
## 键中包含冒号 → ["a:b" => "v"]
a\:b: v
## 仅按第一个点拆分 → ["x" => ["y.z" => "v"]]
x.y\.z: v
```

键中的字面量反斜杠写作 `\\`。

