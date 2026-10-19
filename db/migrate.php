<?php
/* =========================================================
   Империя льда — применение миграций базы
   ---------------------------------------------------------
   Открывается в браузере:

     https://ice-imperia.ru/db/migrate.php?key=КЛЮЧ

   Ключ берётся из config.php, поле migrate_key. Без него
   страница ничего не показывает и ничего не делает: иначе
   структуру базы мог бы перестраивать кто угодно.

   Что делает: смотрит файлы db/migrations/*.sql, сравнивает
   со списком уже применённых в таблице migrations и выполняет
   недостающие по порядку имён. Применённые повторно
   не запускаются.

   Почему не «одной кнопкой на всё»: DDL в MySQL не умеет
   откатываться. Если файл упадёт на середине, часть таблиц
   уже создана. Поэтому миграции написаны с CREATE TABLE
   IF NOT EXISTS — после исправления файл можно запустить
   снова, и он доделает остаток.
   ========================================================= */

header('Content-Type: text/html; charset=utf-8');

$root   = dirname(__DIR__);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : array();
if (!is_array($config)) {
    $config = array();
}

$key = isset($config['migrate_key']) ? (string) $config['migrate_key'] : '';
$given = isset($_GET['key']) ? (string) $_GET['key'] : '';

// hash_equals, а не ==: сравнение за постоянное время не даёт
// подбирать ключ по времени ответа
if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(404);
    exit('Not found');
}

$db = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();

$steps  = array();   // что сделали
$fatal  = null;      // на чём остановились

/** Короткая запись htmlspecialchars. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Делит файл миграции на отдельные команды.
 *
 * Сначала убираются комментарии -- до конца строки: в них
 * попадаются точки с запятой, и без этого шага файл разошёлся
 * бы по ним. Внутри строковых значений миграций точки
 * с запятой нет — это условие, которого мы держимся при
 * написании файлов.
 */
function split_sql($sql)
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $out = array();
    foreach (explode(';', $sql) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

try {
    foreach (array('host', 'name', 'user') as $field) {
        if (empty($db[$field])) {
            throw new RuntimeException('В config.php не заполнен доступ к базе: поле db.' . $field);
        }
    }

    $dsn = 'mysql:host=' . $db['host']
         . (empty($db['port']) ? '' : ';port=' . (int) $db['port'])
         . ';dbname=' . $db['name']
         . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $db['user'], isset($db['pass']) ? $db['pass'] : '', array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));

    // Таблицу учёта создаём здесь, а не миграцией: без неё
    // непонятно, что уже применено
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(190) NOT NULL,
            statements SMALLINT UNSIGNED NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $applied = $pdo->query('SELECT name, applied_at FROM migrations ORDER BY name')->fetchAll();
    $done    = array();
    foreach ($applied as $row) {
        $done[$row['name']] = $row['applied_at'];
    }

    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files, SORT_STRING);

    if (!$files) {
        throw new RuntimeException('В db/migrations/ нет ни одного файла .sql');
    }

    $insert = $pdo->prepare(
        'INSERT INTO migrations (name, statements, applied_at) VALUES (?, ?, NOW())'
    );

    foreach ($files as $path) {
        $name = basename($path);

        if (isset($done[$name])) {
            $steps[] = array('skip', $name, 'применена ранее, ' . $done[$name]);
            continue;
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Не удалось прочитать ' . $name);
        }

        $statements = split_sql(preg_replace('/^\xEF\xBB\xBF/', '', $sql));
        $n = 0;

        $skipped = 0;

        foreach ($statements as $i => $statement) {
            try {
                $pdo->exec($statement);
                $n++;
            } catch (PDOException $error) {
                // DDL в MySQL не откатывается: если файл упал
                // на середине, часть изменений уже внесена.
                // CREATE TABLE пишется с IF NOT EXISTS, а ALTER
                // так не умеет — поэтому «уже есть» считаем
                // выполненным, а не ошибкой. Иначе повторный
                // запуск после сбоя невозможен.
                $code = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : 0;
                $done = array(
                    1050,  // таблица уже есть
                    1060,  // столбец уже есть
                    1061,  // такой индекс уже есть
                    1091,  // нечего удалять: столбца или ключа нет
                    1826,  // внешний ключ с таким именем уже есть
                );

                if (in_array($code, $done, true)) {
                    $skipped++;
                    continue;
                }

                throw new RuntimeException(
                    'Файл ' . $name . ', команда ' . ($i + 1) . ' из ' . count($statements)
                    . ': ' . $error->getMessage()
                    . "\n\n" . mb_substr($statement, 0, 500)
                );
            }
        }

        $insert->execute(array($name, $n));
        $steps[] = array('ok', $name, 'применена, команд: ' . $n
                       . ($skipped ? ', уже было: ' . $skipped : ''));
    }

    // Что получилось в базе
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $error) {
    $fatal  = $error->getMessage();
    $tables = array();
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Миграции базы — Империя льда</title>
<style>
  :root{ --ink:#2C2416; --sand:#F8F5F0; --blue:#0095BA;
         --ok:#2f7d32; --bad:#b3261e; --mute:#6b6356; }
  *{box-sizing:border-box}
  body{margin:0;padding:32px 20px 64px;background:var(--sand);color:var(--ink);
       font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:860px;margin:0 auto}
  h1{font-size:26px;margin:0 0 6px;font-weight:600}
  .sub{margin:0 0 32px;color:var(--mute)}
  .card{background:#fff;border-radius:14px;padding:22px 24px;margin:0 0 20px;
        box-shadow:0 1px 2px rgba(44,36,22,.06),0 8px 24px rgba(44,36,22,.05)}
  h2{font-size:19px;margin:0 0 14px;font-weight:600}
  ul{list-style:none;margin:0;padding:0}
  li{display:flex;gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid #f0ece4}
  li:first-child{border-top:0}
  .i{flex:0 0 22px;height:22px;border-radius:50%;display:grid;place-items:center;
     font-size:13px;font-weight:700;line-height:1}
  .i--ok{background:#e8f3e9;color:var(--ok)}
  .i--skip{background:#e8eef0;color:#45707f}
  .i--bad{background:#fdeae8;color:var(--bad)}
  code{font:14px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .name{font-weight:600}
  .note{color:var(--mute)}
  pre{margin:10px 0 0;padding:14px 16px;background:#fdeae8;border-radius:10px;
      overflow:auto;font-size:14px;white-space:pre-wrap}
  .tables{display:flex;flex-wrap:wrap;gap:8px}
  .tables span{background:#E8EEF0;border-radius:7px;padding:4px 10px;font-size:14px}
  .hint{color:var(--mute);font-size:15px;margin:28px 0 0}
</style>
</head>
<body>
<div class="wrap">

  <h1>Миграции базы</h1>
  <p class="sub">Страница применяет недостающие файлы структуры и показывает результат.
     Применённое повторно не запускается — обновлять её безопасно.</p>

<?php if ($fatal !== null): ?>
  <div class="card">
    <h2>Остановились на ошибке</h2>
    <ul><li><span class="i i--bad">×</span><span>Дальше ничего не применялось</span></li></ul>
    <pre><?= e($fatal) ?></pre>
  </div>
<?php endif; ?>

<?php if ($steps): ?>
  <div class="card">
    <h2>Файлы</h2>
    <ul>
      <?php foreach ($steps as $step): ?>
        <li>
          <span class="i i--<?= e($step[0]) ?>"><?= $step[0] === 'ok' ? '✓' : 'i' ?></span>
          <span><code class="name"><?= e($step[1]) ?></code> — <span class="note"><?= e($step[2]) ?></span></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($tables): ?>
  <div class="card">
    <h2>Таблицы в базе: <?= count($tables) ?></h2>
    <div class="tables">
      <?php foreach ($tables as $table): ?><span><?= e($table) ?></span><?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

  <p class="hint">
    Если файл упал на середине, часть таблиц уже создана, а сама миграция
    не отмечена применённой. Структура написана так, что повторный запуск
    доделает остаток: исправьте причину и обновите страницу.
  </p>

</div>
</body>
</html>
