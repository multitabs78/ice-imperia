<?php
/* =========================================================
   Журнал операций
   ---------------------------------------------------------
   Пишется при каждом изменении абонемента, посещения,
   расписания, тарифа, скидки и перерасчёта. Хранит, что было
   и что стало, — и причину: без неё через полгода непонятно,
   почему сумма другая.
   ========================================================= */

class Audit
{
    /** Поля, которые в журнал не попадают ни при каких условиях. */
    private static $hidden = array(
        'password', 'password_hash', 'code', 'code_hash', 'token', 'token_hash',
    );

    public static function write($action, $entity, $entityId = null,
                                 $before = null, $after = null, $reason = null)
    {
        try {
            Db::insert('audit_log', array(
                'user_id'     => Auth::id(),
                'action'      => mb_substr((string) $action, 0, 64),
                'entity'      => mb_substr((string) $entity, 0, 64),
                'entity_id'   => $entityId === null ? null : (int) $entityId,
                'before_json' => self::pack($before),
                'after_json'  => self::pack($after),
                'reason'      => $reason === null ? null : mb_substr((string) $reason, 0, 255),
                'ip'          => App::request() ? App::request()->ip : null,
                'created_at'  => Db::now(),
            ));
        } catch (Throwable $error) {
            // Журнал не должен ронять операцию, которую он записывает
            error_log('[Империя льда] Не записан журнал: ' . $error->getMessage());
        }
    }

    /** Только изменившиеся поля — иначе журнал нечитаем. */
    public static function diff($before, $after)
    {
        $before = is_array($before) ? $before : array();
        $after  = is_array($after) ? $after : array();

        $wasChanged = array();
        $became     = array();

        foreach ($after as $key => $value) {
            $old = array_key_exists($key, $before) ? $before[$key] : null;
            if ((string) $old !== (string) $value) {
                $wasChanged[$key] = $old;
                $became[$key]     = $value;
            }
        }
        return array($wasChanged, $became);
    }

    private static function pack($data)
    {
        if ($data === null) {
            return null;
        }
        if (is_array($data)) {
            foreach (self::$hidden as $key) {
                unset($data[$key]);
            }
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 60000);
    }
}
