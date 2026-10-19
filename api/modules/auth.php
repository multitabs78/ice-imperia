<?php
/* =========================================================
   Вход
   ---------------------------------------------------------
   Три канала равноправны, механизм под ними один: одноразовый
   код с коротким сроком жизни. Меняется только доставка,
   поэтому отключение канала не трогает проверку входа.

   Тренер и администратор входят паролем.
   ========================================================= */

class AuthService
{
    const CODE_TTL_SECONDS   = 300;   // сколько живёт код
    const CODE_MAX_ATTEMPTS  = 5;     // неверных попыток на код
    const CODES_PER_HOUR_IP  = 5;     // запросов кода с адреса за час
    const LOGIN_FAILS_PER_IP = 10;    // неудачных входов паролем за час

    /**
     * Ищет пользователя по телефону, почте или признаку telegram.
     * Возвращает null, если не нашли: наружу это всё равно
     * не покажем.
     */
    public static function findUser($login)
    {
        $login = trim((string) $login);
        if ($login === '') {
            return null;
        }

        if (strpos($login, '@') !== false) {
            return Db::row('SELECT * FROM users WHERE email = ? LIMIT 1',
                           array(mb_strtolower($login)));
        }

        $digits = preg_replace('/\D+/', '', $login);
        if (strlen($digits) === 11) {
            if ($digits[0] === '8') {
                $digits = '7' . substr($digits, 1);
            }
            return Db::row('SELECT * FROM users WHERE phone = ? LIMIT 1', array('+' . $digits));
        }

        return null;
    }

    /** Маска, по которой человек узнаёт свой контакт, а чужой — нет. */
    public static function hint($channel, $user)
    {
        if ($channel === 'email' && !empty($user['email'])) {
            $parts = explode('@', $user['email']);
            $name  = $parts[0];
            $head  = mb_substr($name, 0, 2);
            return $head . str_repeat('•', max(1, mb_strlen($name) - 2)) . '@' . $parts[1];
        }
        if ($channel === 'sms' && !empty($user['phone'])) {
            return mb_substr($user['phone'], 0, 2) . ' ' . mb_substr($user['phone'], 2, 3)
                 . ' ••• ' . mb_substr($user['phone'], -2);
        }
        if ($channel === 'telegram') {
            return 'Telegram';
        }
        return null;
    }

    /** Создаёт и отправляет код. */
    public static function sendCode($user, $channel, Request $request)
    {
        // Старые коды этого пользователя гасим: иначе их
        // накапливается несколько и каждый остаётся рабочим
        Db::run('UPDATE auth_codes SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                array(Db::now(), $user['id']));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Db::insert('auth_codes', array(
            'user_id'    => $user['id'],
            'channel'    => $channel,
            'code_hash'  => hash('sha256', $code),
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            'ip'         => $request->ip,
            'created_at' => Db::now(),
        ));

        $site = Config::get('site_name', 'Империя льда');
        $text = 'Код для входа в личный кабинет: ' . $code . "\n"
              . 'Он действует 5 минут. Если вы не запрашивали вход, просто не вводите код.';

        $to = $channel === 'email' ? $user['email']
            : ($channel === 'telegram' ? $user['telegram_chat_id'] : $user['phone']);

        return Notify::send($channel, $to, $site . ': код для входа', $text);
    }

    /** Защита от перебора: сколько кодов запрошено с адреса за час. */
    public static function codesFromIp($ip)
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM auth_codes WHERE ip = ? AND created_at > ?',
            array($ip, date('Y-m-d H:i:s', time() - 3600))
        );
    }

    /** Данные пользователя для ответа. */
    public static function profile($user)
    {
        $name = trim($user['last_name'] . ' ' . $user['first_name']
              . ($user['middle_name'] ? ' ' . $user['middle_name'] : ''));

        $out = array(
            'id'    => (int) $user['id'],
            'name'  => $name,
            'roles' => isset($user['roles']) ? $user['roles'] : Auth::rolesOf($user['id']),
        );

        if (in_array('parent', $out['roles'], true)) {
            $children = Db::all(
                'SELECT a.id, a.last_name, a.first_name
                   FROM parent_athlete pa
                   JOIN athletes a ON a.id = pa.athlete_id
                  WHERE pa.parent_user_id = ? AND a.status = ?
                  ORDER BY a.birth_date',
                array($user['id'], 'active')
            );
            $out['children'] = array();
            foreach ($children as $child) {
                $out['children'][] = array(
                    'id'   => (int) $child['id'],
                    'name' => $child['last_name'] . ' ' . $child['first_name'],
                );
            }
        }

        if (!empty($user['must_change_password'])) {
            $out['must_change_password'] = true;
        }

        return $out;
    }
}


return function (Router $router) {

    /* --- Запрос кода ------------------------------------- */

    $router->post('/auth/request-code', function (Request $request) {
        $data = (new Validate($request->body))
            ->text('login', 'Телефон или почта', true, 190)
            ->one_of('channel', 'Способ входа', array('sms', 'email', 'telegram'))
            ->done();

        if (AuthService::codesFromIp($request->ip) >= AuthService::CODES_PER_HOUR_IP) {
            too_many('Слишком много запросов кода. Попробуйте через час');
        }

        $user = AuthService::findUser($data['login']);

        // Ответ не говорит, есть ли такой пользователь: иначе
        // перебором собирается список клиентов школы
        $silent = array(
            'sent'       => true,
            'channel'    => $data['channel'],
            'expires_in' => AuthService::CODE_TTL_SECONDS,
        );

        if (!$user || $user['status'] !== 'active') {
            Response::ok($silent);
        }

        if ($data['channel'] === 'telegram' && empty($user['telegram_chat_id'])) {
            conflict('telegram_not_linked',
                     'Telegram не привязан. Напишите боту школы, чтобы связать его с кабинетом');
        }
        if ($data['channel'] === 'email' && empty($user['email'])) {
            Response::ok($silent);
        }
        if ($data['channel'] === 'sms' && empty($user['phone'])) {
            Response::ok($silent);
        }

        $result = AuthService::sendCode($user, $data['channel'], $request);

        if (!$result['ok']) {
            // Про неработающий канал молчать нельзя: человек
            // будет ждать код, которого не будет
            throw new ApiError(503, 'channel_unavailable',
                'Не удалось отправить код: ' . $result['reason']);
        }

        $silent['hint'] = AuthService::hint($data['channel'], $user);
        Response::ok($silent);
    });


    /* --- Проверка кода ----------------------------------- */

    $router->post('/auth/verify-code', function (Request $request) {
        $data = (new Validate($request->body))
            ->text('login', 'Телефон или почта', true, 190)
            ->text('code', 'Код', true, 10)
            ->done();

        $user = AuthService::findUser($data['login']);
        if (!$user || $user['status'] !== 'active') {
            unauthorized('Код не подошёл');
        }

        $row = Db::row(
            'SELECT * FROM auth_codes
              WHERE user_id = ? AND used_at IS NULL
              ORDER BY id DESC LIMIT 1',
            array($user['id'])
        );

        if (!$row) {
            unauthorized('Сначала запросите код');
        }
        if ($row['expires_at'] <= Db::now()) {
            unauthorized('Код устарел, запросите новый');
        }
        if ((int) $row['attempts'] >= AuthService::CODE_MAX_ATTEMPTS) {
            too_many('Слишком много попыток. Запросите новый код');
        }

        if (!hash_equals($row['code_hash'], hash('sha256', trim($data['code'])))) {
            Db::run('UPDATE auth_codes SET attempts = attempts + 1 WHERE id = ?',
                    array($row['id']));
            unauthorized('Код не подошёл');
        }

        Db::run('UPDATE auth_codes SET used_at = ? WHERE id = ?', array(Db::now(), $row['id']));

        $token = Auth::issue_token($user['id'], $request);
        Auth::set_cookie($token['token'], $token['expires_at']);

        $user['roles'] = Auth::rolesOf($user['id']);
        Auth::use_user($user);
        Audit::write('login', 'user', $user['id'], null, array('channel' => $row['channel']));

        Response::ok(array(
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'user'       => AuthService::profile($user),
        ));
    });


    /* --- Вход паролем ------------------------------------ */

    $router->post('/auth/login', function (Request $request) {
        $data = (new Validate($request->body))
            ->text('login', 'Телефон или почта', true, 190)
            ->text('password', 'Пароль', true, 200)
            ->done();

        $fails = (int) Db::value(
            'SELECT COUNT(*) FROM audit_log
              WHERE action = ? AND ip = ? AND created_at > ?',
            array('login_failed', $request->ip, date('Y-m-d H:i:s', time() - 3600))
        );
        if ($fails >= AuthService::LOGIN_FAILS_PER_IP) {
            too_many('Слишком много неудачных попыток. Попробуйте через час');
        }

        $user = AuthService::findUser($data['login']);

        // password_verify вызывается даже когда пользователя нет:
        // иначе по времени ответа видно, существует ли логин
        $hash = $user && $user['password_hash'] ? $user['password_hash']
              : '$2y$10$usesomesillystringfoesillystringforsalteu';
        $matches = password_verify($data['password'], $hash);

        if (!$user || !$matches || $user['status'] !== 'active') {
            Audit::write('login_failed', 'user', $user ? $user['id'] : null);
            unauthorized('Неверный логин или пароль');
        }

        $token = Auth::issue_token($user['id'], $request);
        Auth::set_cookie($token['token'], $token['expires_at']);

        $user['roles'] = Auth::rolesOf($user['id']);
        Auth::use_user($user);
        Audit::write('login', 'user', $user['id'], null, array('channel' => 'password'));

        Response::ok(array(
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'user'       => AuthService::profile($user),
        ));
    });


    /* --- Смена пароля ------------------------------------ */

    $router->post('/auth/change-password', function (Request $request) {
        $user = Auth::require_login();

        $data = (new Validate($request->body))
            ->text('current_password', 'Текущий пароль', !empty($user['password_hash']), 200)
            ->text('new_password', 'Новый пароль', true, 200, 8)
            ->done();

        $row = Db::row('SELECT password_hash FROM users WHERE id = ?', array($user['id']));

        if (!empty($row['password_hash'])
            && !password_verify($data['current_password'], $row['password_hash'])) {
            invalid(array('current_password' => 'Текущий пароль не подошёл'));
        }

        Db::update('users', $user['id'], array(
            'password_hash'        => password_hash($data['new_password'], PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ));

        // Остальные входы закрываем: если пароль меняют из-за
        // того, что его узнали, чужая сессия не должна остаться
        Auth::revoke_all($user['id']);
        $token = Auth::issue_token($user['id'], $request);
        Auth::set_cookie($token['token'], $token['expires_at']);

        Audit::write('password_changed', 'user', $user['id']);

        Response::ok(array('token' => $token['token'], 'expires_at' => $token['expires_at']));
    });


    /* --- Выход ------------------------------------------- */

    $router->post('/auth/logout', function (Request $request) {
        $user  = Auth::require_login();
        $token = $request->token();

        if ($request->input('everywhere')) {
            Auth::revoke_all($user['id']);
        } elseif ($token) {
            Auth::revoke_token($token);
        }

        Auth::clear_cookie();
        Audit::write('logout', 'user', $user['id']);

        Response::ok(array('ok' => true));
    });


    /* --- Кто вошёл --------------------------------------- */

    $router->get('/me', function () {
        $user = Auth::require_login();
        Response::ok(AuthService::profile($user));
    });


    /* --- Проверка, что API жив --------------------------- */

    $router->get('/ping', function () {
        Response::ok(array(
            'ok'   => true,
            'time' => date('c'),
        ));
    });
};
