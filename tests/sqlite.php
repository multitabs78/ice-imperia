<?php
/* =========================================================
   Тестовая база на SQLite
   ---------------------------------------------------------
   Нужна только для проверки кода на своей машине. На хостинге
   кабинет работает с MySQL, и единственная настоящая схема —
   db/migrations/001_init.sql. Здесь она переводится на диалект
   SQLite на лету, чтобы не держать вторую копию, которая
   рано или поздно разойдётся с первой.

   Перевод намеренно простой и покрывает ровно то, что
   встречается в наших миграциях.
   ========================================================= */

class SqliteTestDb
{
    /** Создаёт базу по файлам миграций и возвращает PDO. */
    public static function build($path = null)
    {
        if ($path !== null && is_file($path)) {
            unlink($path);
        }

        $pdo = new PDO($path === null ? 'sqlite::memory:' : 'sqlite:' . $path, null, null, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $pdo->exec('PRAGMA foreign_keys = ON');

        $files = glob(dirname(__DIR__) . '/db/migrations/*.sql');
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            foreach (self::statements(file_get_contents($file)) as $sql) {
                // MODIFY COLUMN SQLite не умеет вовсе. Там, где
                // столбец становится необязательным, пересобираем
                // таблицу: без этого тесты не увидели бы того,
                // что на MySQL работает
                if (preg_match('/^@@RELAX@@\s+(\w+)\s+(\w+)$/', $sql, $m)) {
                    self::relax($pdo, $m[1], $m[2]);
                    continue;
                }
                try {
                    $pdo->exec($sql);
                } catch (PDOException $error) {
                    throw new RuntimeException(
                        'Не применилось: ' . $error->getMessage() . "\n\n" . $sql
                    );
                }
            }
        }

        return $pdo;
    }

    /** Файл миграции → список команд на диалекте SQLite. */
    public static function statements($sql)
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        $out = array();
        foreach (explode(';', $sql) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = self::translate($part);
        }
        return $out;
    }

    private static function translate($sql)
    {
        if (preg_match('/^CREATE\s+TABLE/i', $sql)) {
            return self::createTable($sql);
        }

        if (preg_match('/^ALTER\s+TABLE/i', $sql)) {
            // Внешний ключ после создания таблицы SQLite добавить
            // не умеет. В тестах это не мешает: целостность проверяет
            // настоящая база, а здесь важно, чтобы столбец появился.
            if (preg_match('/ADD\s+CONSTRAINT/i', $sql)) {
                return 'SELECT 1';
            }

            // ADD KEY — это индекс, а не столбец. Пишем его
            // отдельной командой, как того и хочет SQLite
            if (preg_match('/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+(?:KEY|INDEX)\s+(\w+)\s*\((.+)\)\s*$/is',
                           $sql, $m)) {
                return 'CREATE INDEX IF NOT EXISTS ' . $m[2] . ' ON ' . $m[1] . ' (' . $m[3] . ')';
            }

            // MODIFY COLUMN, снимающий обязательность, отмечаем
            // для пересборки таблицы. Остальные изменения типа
            // SQLite и так переживёт: типы в нём необязательны
            if (preg_match('/^ALTER\s+TABLE\s+(\w+)\s+MODIFY\s+COLUMN\s+(\w+)\s+(.*)$/is',
                           $sql, $m)) {
                return preg_match('/\bNOT\s+NULL\b/i', $m[3])
                    ? 'SELECT 1'
                    : '@@RELAX@@ ' . $m[1] . ' ' . $m[2];
            }

            if (preg_match('/^(ALTER\s+TABLE\s+\S+\s+ADD\s+COLUMN\s+`?\w+`?)\s+(.*)$/is', $sql, $m)) {
                // AFTER <столбец> SQLite не понимает, а тип нужно перевести
                $rest = preg_replace('/\s+AFTER\s+`?\w+`?\s*$/i', '', $m[2]);
                return $m[1] . ' ' . self::columnType($rest);
            }

            return $sql;
        }

        // Вставки начальных значений
        $sql = preg_replace('/\s*ON DUPLICATE KEY UPDATE .*$/is', '', $sql);
        $sql = preg_replace('/^INSERT\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql);

        return trim($sql);
    }

    /**
     * Снимает NOT NULL со столбца, пересобирая таблицу.
     *
     * В SQLite иначе никак: таблица создаётся заново без этого
     * ограничения, данные переливаются, старая убирается.
     * Внешние ключи на время отключаем — иначе перелив упрётся
     * в ссылки, которые через мгновение снова станут верными.
     */
    private static function relax(PDO $pdo, $table, $column)
    {
        $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table'
                             AND name = " . $pdo->quote($table))->fetchColumn();
        if (!$sql) {
            return;
        }

        // Убираем NOT NULL ровно у нужного столбца
        $fixed = preg_replace(
            '/(^\s*' . preg_quote($column, '/') . '\s+[^,\n]*?)\s+NOT\s+NULL/im',
            '$1', $sql, 1);
        if ($fixed === $sql) {
            return;
        }

        $names = array();
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $one) {
            $names[] = $one['name'];
        }
        $list = implode(', ', $names);

        $pdo->exec('PRAGMA foreign_keys = OFF');
        // Без legacy_alter_table SQLite при переименовании
        // перепишет ссылки на эту таблицу в чужих внешних
        // ключах — и они станут указывать на временное имя,
        // которое мы через мгновение удалим
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('ALTER TABLE ' . $table . ' RENAME TO ' . $table . '__old');
        $pdo->exec(preg_replace('/\b' . preg_quote($table, '/') . '\b/', $table, $fixed, 1));
        $pdo->exec('INSERT INTO ' . $table . ' (' . $list . ') SELECT ' . $list
                 . ' FROM ' . $table . '__old');
        $pdo->exec('DROP TABLE ' . $table . '__old');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * CREATE TABLE разбирается построчно, а не заменами по всему
     * тексту. Иначе столбец с именем date превращается в тип:
     * «date DATE NOT NULL» стало бы «TEXT TEXT NOT NULL».
     */
    private static function createTable($sql)
    {
        $open  = strpos($sql, '(');
        $close = strrpos($sql, ')');

        $head = substr($sql, 0, $open);
        $body = substr($sql, $open + 1, $close - $open - 1);

        $lines = self::splitTop($body);
        $out   = array();
        $autoId = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $upper = strtoupper($line);

            if (strpos($upper, 'UNIQUE KEY') === 0) {
                $cols = substr($line, strpos($line, '('));
                $out[] = 'UNIQUE ' . $cols;
                continue;
            }
            if (strpos($upper, 'KEY ') === 0) {
                continue;   // обычные индексы в тестовой базе ни к чему
            }
            if (strpos($upper, 'PRIMARY KEY') === 0) {
                if ($autoId && preg_match('/^PRIMARY KEY\s*\(\s*`?id`?\s*\)$/i', $line)) {
                    continue;   // уже объявлен в самом столбце
                }
                $out[] = $line;
                continue;
            }
            if (strpos($upper, 'CONSTRAINT') === 0 || strpos($upper, 'FOREIGN KEY') === 0) {
                $out[] = $line;
                continue;
            }

            // Столбец: первое слово — имя, остальное — тип и признаки
            if (!preg_match('/^(`?\w+`?)\s+(.*)$/s', $line, $m)) {
                $out[] = $line;
                continue;
            }
            $name = $m[1];
            $rest = $m[2];

            if (preg_match('/^INT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT$/i', trim($rest))
                || preg_match('/^BIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT$/i', trim($rest))) {
                $out[]  = $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }

            $out[] = $name . ' ' . self::columnType($rest);
        }

        return rtrim($head) . " (\n  " . implode(",\n  ", $out) . "\n)";
    }

    /** Тип столбца и его признаки. */
    private static function columnType($rest)
    {
        $rest = preg_replace('/\bENUM\s*\([^)]*\)/i', 'TEXT', $rest);
        $rest = preg_replace('/\bDECIMAL\s*\(\d+,\s*\d+\)/i', 'NUMERIC', $rest);
        $rest = preg_replace('/\b(?:BIG|SMALL|TINY|MEDIUM)?INT\b(\s*\(\d+\))?(\s+UNSIGNED)?/i',
                             'INTEGER', $rest);
        $rest = preg_replace('/\b(?:VAR)?CHAR\s*\(\d+\)/i', 'TEXT', $rest);
        $rest = preg_replace('/\b(?:DATETIME|DATE|TIME|LONGTEXT)\b/i', 'TEXT', $rest);
        $rest = preg_replace('/\bAUTO_INCREMENT\b/i', '', $rest);
        return trim(preg_replace('/\s+/', ' ', $rest));
    }

    /** Делит тело CREATE TABLE по запятым верхнего уровня. */
    private static function splitTop($body)
    {
        $parts = array();
        $depth = 0;
        $buf   = '';

        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }
        return $parts;
    }
}
