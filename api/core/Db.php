<?php
/* =========================================================
   Обёртка над PDO
   ---------------------------------------------------------
   Все запросы — только подготовленные. Строка, собранная
   склейкой, рано или поздно получит кавычку из имени
   «Д'Артаньян» и перестанет быть запросом.
   ========================================================= */

class Db
{
    private static $pdo;

    /** Соединение. Создаётся один раз за запрос. */
    public static function pdo()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = Config::get('db', array());
        foreach (array('host', 'name', 'user') as $field) {
            if (empty($db[$field])) {
                throw new RuntimeException('В config.php не заполнено поле db.' . $field);
            }
        }

        $dsn = 'mysql:host=' . $db['host']
             . (empty($db['port']) ? '' : ';port=' . (int) $db['port'])
             . ';dbname=' . $db['name']
             . ';charset=utf8mb4';

        self::$pdo = new PDO($dsn, $db['user'], isset($db['pass']) ? $db['pass'] : '', array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));

        return self::$pdo;
    }

    /** Подменить соединение — нужно тестам. */
    public static function use_pdo(PDO $pdo)
    {
        self::$pdo = $pdo;
    }

    public static function run($sql, $params = array())
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Одна строка или null. */
    public static function row($sql, $params = array())
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Все строки. */
    public static function all($sql, $params = array())
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Одно значение первой строки. */
    public static function value($sql, $params = array(), $default = null)
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    /** Вставка из массива «столбец => значение». Возвращает id. */
    public static function insert($table, $data)
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
              . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** Обновление по id. Возвращает число затронутых строк. */
    public static function update($table, $id, $data)
    {
        if (!$data) {
            return 0;
        }
        $set = array();
        foreach (array_keys($data) as $col) {
            $set[] = $col . ' = ?';
        }
        $params   = array_values($data);
        $params[] = $id;

        return self::run('UPDATE ' . $table . ' SET ' . implode(', ', $set)
                       . ' WHERE id = ?', $params)->rowCount();
    }

    /** Время в формате базы. */
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    public static function begin()
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit()
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback()
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
