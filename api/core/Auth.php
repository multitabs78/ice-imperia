<?php
/* =========================================================
   Кто вошёл и что ему можно
   ---------------------------------------------------------
   Проверки стоят здесь, а не в кабинете. Кабинет — один
   из клиентов, и доверять ему нельзя: запрос чужой группы
   может прийти и в обход интерфейса.
   ========================================================= */

class Auth
{
    /** Срок жизни токена, суток. */
    const TOKEN_DAYS = 30;

    private static $user;     // null — не входил
    private static $loaded = false;

    /** Текущий пользователь или null. */
    public static function user()
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        self::$user   = null;

        $request = App::request();
        $token   = $request->token();
        if (!$token) {
            return null;
        }

        $row = Db::row(
            'SELECT t.id AS token_id, u.*
               FROM access_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ?
                AND t.revoked_at IS NULL
                AND t.expires_at > ?
              LIMIT 1',
            array(hash('sha256', $token), Db::now())
        );

        if (!$row || $row['status'] !== 'active') {
            return null;
        }

        $row['roles'] = self::rolesOf((int) $row['id']);
        unset($row['password_hash']);

        // Отметка «был в сети» пишется не чаще раза в час:
        // иначе каждый запрос кабинета — лишняя запись в базу
        if (empty($row['last_login_at']) || strtotime($row['last_login_at']) < time() - 3600) {
            Db::run('UPDATE access_tokens SET last_used_at = ? WHERE id = ?',
                    array(Db::now(), $row['token_id']));
        }

        self::$user = $row;
        return self::$user;
    }

    public static function rolesOf($userId)
    {
        $rows = Db::all('SELECT role FROM user_roles WHERE user_id = ?', array($userId));
        $out  = array();
        foreach ($rows as $row) {
            $out[] = $row['role'];
        }
        return $out;
    }

    /** Подменить пользователя — нужно тестам. */
    public static function use_user($user)
    {
        self::$user   = $user;
        self::$loaded = true;
    }

    public static function id()
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function has($role)
    {
        $user = self::user();
        return $user && in_array($role, $user['roles'], true);
    }

    public static function require_login()
    {
        $user = self::user();
        if (!$user) {
            unauthorized();
        }
        return $user;
    }

    /** Любая из перечисленных ролей. */
    public static function require_role($roles)
    {
        $user  = self::require_login();
        $roles = (array) $roles;

        foreach ($roles as $role) {
            if (in_array($role, $user['roles'], true)) {
                return $user;
            }
        }
        forbidden('Это действие доступно другой роли');
    }

    public static function require_admin()
    {
        return self::require_role('admin');
    }

    /** Запись тренера для текущего пользователя, если он тренер. */
    public static function coach_id()
    {
        $id = self::id();
        if (!$id) {
            return null;
        }
        $value = Db::value('SELECT id FROM coaches WHERE user_id = ? AND status = ? LIMIT 1',
                           array($id, 'active'));
        return $value === null ? null : (int) $value;
    }

    /* ---------------------------------------------------------
       Границы доступа к данным
       --------------------------------------------------------- */

    /** Видит ли текущий пользователь этого спортсмена. */
    public static function sees_athlete($athleteId)
    {
        $athleteId = (int) $athleteId;
        $user = self::user();
        if (!$user) {
            return false;
        }
        if (self::has('admin')) {
            return true;
        }

        // Родитель — только свои дети
        if (self::has('parent')) {
            $own = Db::value(
                'SELECT 1 FROM parent_athlete WHERE parent_user_id = ? AND athlete_id = ? LIMIT 1',
                array($user['id'], $athleteId)
            );
            if ($own) {
                return true;
            }
        }

        // Тренер — спортсмены своих групп
        $coachId = self::coach_id();
        if ($coachId) {
            $own = Db::value(
                'SELECT 1
                   FROM enrollments e
                   JOIN group_coaches gc ON gc.group_id = e.group_id
                  WHERE e.athlete_id = ? AND gc.coach_id = ?
                    AND (e.date_to IS NULL OR e.date_to >= ?)
                  LIMIT 1',
                array($athleteId, $coachId, date('Y-m-d'))
            );
            if ($own) {
                return true;
            }
        }

        // Спортсмен — сам себя
        if (self::has('athlete')) {
            $own = Db::value('SELECT 1 FROM athletes WHERE id = ? AND user_id = ? LIMIT 1',
                             array($athleteId, $user['id']));
            if ($own) {
                return true;
            }
        }

        return false;
    }

    /**
     * Отказ, если спортсмен чужой.
     * Чужой номер отвечает так же, как несуществующий: иначе
     * перебором можно узнать, кто занимается в школе.
     */
    public static function require_athlete($athleteId)
    {
        if (!self::sees_athlete($athleteId)) {
            forbidden('Этот спортсмен не в вашем доступе');
        }
        return (int) $athleteId;
    }

    /** Ведёт ли текущий тренер эту группу. */
    public static function sees_group($groupId)
    {
        if (self::has('admin')) {
            return true;
        }
        $coachId = self::coach_id();
        if (!$coachId) {
            return false;
        }
        return (bool) Db::value(
            'SELECT 1 FROM group_coaches WHERE group_id = ? AND coach_id = ? LIMIT 1',
            array((int) $groupId, $coachId)
        );
    }

    public static function require_group($groupId)
    {
        if (!self::sees_group($groupId)) {
            forbidden('Эта группа не в вашем доступе');
        }
        return (int) $groupId;
    }

    /* ---------------------------------------------------------
       Токены
       --------------------------------------------------------- */

    /** Выдаёт токен. Возвращает сам токен — в базе только его хеш. */
    public static function issue_token($userId, Request $request)
    {
        $token = bin2hex(random_bytes(32));

        Db::insert('access_tokens', array(
            'user_id'    => (int) $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => $request->userAgent,
            'ip'         => $request->ip,
            'created_at' => Db::now(),
            'expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_DAYS * 86400),
        ));

        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', array(Db::now(), $userId));

        return array(
            'token'      => $token,
            'expires_at' => date('c', time() + self::TOKEN_DAYS * 86400),
        );
    }

    public static function revoke_token($token)
    {
        Db::run('UPDATE access_tokens SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
                array(Db::now(), hash('sha256', $token)));
    }

    public static function revoke_all($userId)
    {
        Db::run('UPDATE access_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL',
                array(Db::now(), (int) $userId));
    }

    /** Кладёт токен в cookie, недоступную скриптам страницы. */
    public static function set_cookie($token, $expiresAt)
    {
        if (headers_sent()) {
            return;
        }
        $secure = !isset($_SERVER['HTTP_HOST']) || strpos($_SERVER['HTTP_HOST'], 'localhost') === false;

        if (PHP_VERSION_ID >= 70300) {
            setcookie('cab_token', $token, array(
                'expires'  => strtotime($expiresAt),
                'path'     => '/',
                'httponly' => true,
                'secure'   => $secure,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie('cab_token', $token, strtotime($expiresAt), '/; SameSite=Lax', '', $secure, true);
        }
    }

    public static function clear_cookie()
    {
        if (!headers_sent()) {
            setcookie('cab_token', '', time() - 3600, '/');
        }
    }
}
