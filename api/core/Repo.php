<?php
/* =========================================================
   Мелкие повторяющиеся действия над записями
   ---------------------------------------------------------
   Выборка с постраничной разбивкой, поиск по id, архивация.
   Вынесено, чтобы в модулях не повторялось одно и то же
   и чтобы ошибка правилась в одном месте.
   ========================================================= */

class Repo
{
    /** Границы выборки из запроса. */
    public static function page(Request $request, $default = 50, $max = 200)
    {
        $limit  = (int) $request->input('limit', $default);
        $offset = (int) $request->input('offset', 0);

        if ($limit < 1)    { $limit = $default; }
        if ($limit > $max) { $limit = $max; }
        if ($offset < 0)   { $offset = 0; }

        return array($limit, $offset);
    }

    /** Запись по id или 404. */
    public static function find($table, $id, $what = 'Запись')
    {
        $row = Db::row('SELECT * FROM ' . $table . ' WHERE id = ?', array((int) $id));
        if (!$row) {
            not_found($what . ' не найдена');
        }
        return $row;
    }

    /**
     * Сохраняет изменения и пишет в журнал только то,
     * что действительно поменялось.
     */
    public static function save($table, $id, $data, $entity, $reason = null)
    {
        $before = self::find($table, $id);

        $changes = array();
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $before) && (string) $before[$key] !== (string) $value) {
                $changes[$key] = $value;
            } elseif (!array_key_exists($key, $before)) {
                $changes[$key] = $value;
            }
        }

        if (!$changes) {
            return $before;
        }

        Db::update($table, $id, $changes);

        list($was, $became) = Audit::diff($before, $changes);
        Audit::write('update', $entity, $id, $was, $became, $reason);

        return self::find($table, $id);
    }

    /** Создание с записью в журнал. */
    public static function create($table, $data, $entity, $reason = null)
    {
        $id = Db::insert($table, $data);
        Audit::write('create', $entity, $id, null, $data, $reason);
        return $id;
    }

    /**
     * Поиск по нескольким столбцам. Возвращает кусок условия
     * и его значения — собирать запрос склейкой строк нельзя,
     * но имена столбцов здесь задаёт код, а не пользователь.
     */
    public static function search($query, $columns)
    {
        $query = trim((string) $query);
        if ($query === '') {
            return array('', array());
        }

        $parts  = array();
        $values = array();
        foreach ($columns as $column) {
            $parts[]  = $column . ' LIKE ?';
            $values[] = '%' . $query . '%';
        }

        return array(' AND (' . implode(' OR ', $parts) . ')', $values);
    }

    /** Имя человека из трёх полей. */
    public static function fullName($row, $prefix = '')
    {
        $parts = array(
            isset($row[$prefix . 'last_name']) ? $row[$prefix . 'last_name'] : '',
            isset($row[$prefix . 'first_name']) ? $row[$prefix . 'first_name'] : '',
            isset($row[$prefix . 'middle_name']) ? $row[$prefix . 'middle_name'] : '',
        );
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Цена услуги по коду. Пустая цена — не ноль, а «не задана»:
     * начисление по такой услуге должно останавливаться
     * с понятным объяснением, а не списывать ничего молча.
     */
    public static function servicePrice($code, $required = true)
    {
        $row = Db::row('SELECT name, price FROM services WHERE code = ? AND status = ? LIMIT 1',
                       array($code, 'active'));

        if (!$row) {
            if ($required) {
                conflict('service_missing', 'В справочнике услуг нет записи «' . $code . '»');
            }
            return null;
        }
        if ($row['price'] === null || $row['price'] === '') {
            if ($required) {
                conflict('price_not_set',
                    'Не задана цена услуги «' . $row['name'] . '». Укажите её в разделе «Услуги»');
            }
            return null;
        }

        return (float) $row['price'];
    }

    /** Возраст в полных годах или null. */
    public static function age($birthDate)
    {
        if (!$birthDate) {
            return null;
        }
        try {
            $born = new DateTime($birthDate);
        } catch (Exception $error) {
            return null;
        }
        return (int) $born->diff(new DateTime('today'))->y;
    }
}
