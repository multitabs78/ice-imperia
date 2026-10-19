<?php
/* =========================================================
   Проверка входа и ядра API
   ---------------------------------------------------------
   Запуск из корня проекта:

     php tests/run.php

   Поднимает встроенный сервер PHP на тестовой базе SQLite
   и ходит по настоящим адресам настоящими запросами. Нужен
   только PHP — ни MySQL, ни интернета.
   ========================================================= */

require __DIR__ . '/sqlite.php';

// Тот же часовой пояс, что у приложения: иначе сроки, записанные
// тестом, разойдутся с теми, что проверяет сервер
date_default_timezone_set('Europe/Moscow');

$root = dirname(__DIR__);
$dir  = sys_get_temp_dir() . '/imperia-tests';
@mkdir($dir, 0777, true);
$dbPath = $dir . '/test.sqlite';
$port   = 8911;
$base   = 'http://127.0.0.1:' . $port . '/api/v1';

echo "Готовлю базу…\n";
$pdo = SqliteTestDb::build($dbPath);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
              ->fetchAll(PDO::FETCH_COLUMN);
echo '  таблиц: ' . count($tables) . "\n";

echo "Поднимаю сервер…\n";
$cmd = sprintf(
    'IMPERIA_TEST_DB=%s php -S 127.0.0.1:%d -t %s -d auto_prepend_file=%s > %s 2>&1 & echo $!',
    escapeshellarg($dbPath), $port, escapeshellarg($root),
    escapeshellarg(__DIR__ . '/bootstrap.php'), escapeshellarg($dir . '/server.log')
);
$pid = (int) shell_exec($cmd);
register_shutdown_function(function () use ($pid) {
    if ($pid) {
        shell_exec('kill ' . $pid . ' 2>/dev/null');
    }
});

for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    if ($probe) {
        fclose($probe);
        break;
    }
}

/* --- Мелкая обвязка -------------------------------------- */

$passed = 0;
$failed = array();

function check($name, $condition, $details = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed[] = $name . ($details !== '' ? ' — ' . $details : '');
        echo "  ✗ $name" . ($details !== '' ? ' — ' . $details : '') . "\n";
    }
}

function call($method, $path, $body = null, $token = null, $ip = null)
{
    global $base;

    $ch = curl_init($base . $path);
    $headers = array('Accept: application/json');

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($ip) {
        // Встроенный сервер берёт адрес соединения, поэтому
        // разные адреса в тестах различаем заголовком
        $headers[] = 'X-Test-Ip: ' . $ip;
    }

    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY          => '',
        CURLOPT_TIMEOUT        => 15,
    ));

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return array('status' => 0, 'body' => array(), 'raw' => $error);
    }
    $data = json_decode($raw, true);
    return array('status' => $status, 'body' => is_array($data) ? $data : array(), 'raw' => $raw);
}

/* --- Данные для проверок --------------------------------- */

$now = date('Y-m-d H:i:s');

$pdo->prepare('INSERT INTO users (last_name, first_name, email, phone, password_hash, status, created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute(array('Стефанова', 'Анастасия', 'admin@example.test', '+79990000001',
                    password_hash('verysecret123', PASSWORD_DEFAULT), 'active', $now));
$adminId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)')->execute(array($adminId, 'admin'));

$pdo->prepare('INSERT INTO users (last_name, first_name, email, phone, status, created_at)
               VALUES (?,?,?,?,?,?)')
    ->execute(array('Иванова', 'Мария', 'parent@example.test', '+79990000002', 'active', $now));
$parentId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)')->execute(array($parentId, 'parent'));

$pdo->prepare('INSERT INTO athletes (last_name, first_name, birth_date, status, created_at)
               VALUES (?,?,?,?,?)')
    ->execute(array('Иванов', 'Пётр', '2019-05-11', 'active', $now));
$childId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO athletes (last_name, first_name, birth_date, status, created_at)
               VALUES (?,?,?,?,?)')
    ->execute(array('Сидорова', 'Анна', '2018-02-03', 'active', $now));
$otherChildId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO parent_athlete (parent_user_id, athlete_id, relation, is_primary, created_at)
               VALUES (?,?,?,?,?)')
    ->execute(array($parentId, $childId, 'mother', 1, $now));

/* --- Ядро ------------------------------------------------ */

echo "\nЯдро\n";

$r = call('GET', '/ping');
check('ping отвечает', $r['status'] === 200 && !empty($r['body']['ok']), 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/no-such-address');
check('несуществующий адрес — 404', $r['status'] === 404 && $r['body']['error'] === 'not_found',
      'код ' . $r['status']);

$r = call('DELETE', '/ping');
check('чужой метод — 405, а не 404', $r['status'] === 405, 'код ' . $r['status']);

$r = call('GET', '/me');
check('без токена — 401', $r['status'] === 401 && $r['body']['error'] === 'unauthorized',
      'код ' . $r['status']);

$r = call('GET', '/me', null, 'подделанный-токен');
check('поддельный токен — 401', $r['status'] === 401, 'код ' . $r['status']);

$r = call('POST', '/auth/request-code', array('login' => '', 'channel' => 'почтой'));
check('проверка полей — 422 со списком', $r['status'] === 422
      && isset($r['body']['fields']['login']) && isset($r['body']['fields']['channel']),
      'код ' . $r['status'] . ' ' . $r['raw']);

/* --- Вход паролем ---------------------------------------- */

echo "\nВход паролем\n";

$r = call('POST', '/auth/login', array('login' => 'admin@example.test', 'password' => 'неверный'));
check('неверный пароль — 401', $r['status'] === 401, 'код ' . $r['status']);

$r = call('POST', '/auth/login', array('login' => 'нет-такого@example.test', 'password' => 'x'));
check('несуществующий логин — тоже 401', $r['status'] === 401, 'код ' . $r['status']);

$r = call('POST', '/auth/login', array('login' => 'admin@example.test', 'password' => 'verysecret123'));
$adminToken = isset($r['body']['token']) ? $r['body']['token'] : null;
check('верный пароль — токен', $r['status'] === 200 && $adminToken, 'код ' . $r['status'] . ' ' . $r['raw']);
check('в ответе роль администратора',
      isset($r['body']['user']['roles']) && in_array('admin', $r['body']['user']['roles'], true));

$r = call('GET', '/me', null, $adminToken);
check('с токеном /me работает', $r['status'] === 200 && $r['body']['id'] === $adminId,
      'код ' . $r['status'] . ' ' . $r['raw']);

$stored = $pdo->query('SELECT token_hash FROM access_tokens LIMIT 1')->fetchColumn();
check('в базе лежит хеш, а не сам токен',
      $stored !== $adminToken && $stored === hash('sha256', $adminToken));

$r = call('POST', '/auth/login', array('login' => '+7 (999) 000-00-01', 'password' => 'verysecret123'));
check('вход по телефону в любом написании', $r['status'] === 200, 'код ' . $r['status']);

/* --- Вход по коду ---------------------------------------- */

echo "\nВход по коду\n";

$r = call('POST', '/auth/request-code',
          array('login' => 'нет-такого@example.test', 'channel' => 'email'));
check('о чужом логине не сообщаем', $r['status'] === 200 && !empty($r['body']['sent'])
      && !isset($r['body']['hint']), 'код ' . $r['status'] . ' ' . $r['raw']);

// Код кладём прямо в базу: доставка — отдельная забота,
// здесь проверяется сама сверка
function putCode($pdo, $userId, $code, $shift = 300, $attempts = 0)
{
    $pdo->prepare('UPDATE auth_codes SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute(array(date('Y-m-d H:i:s'), $userId));
    $pdo->prepare('INSERT INTO auth_codes (user_id, channel, code_hash, expires_at, attempts, ip, created_at)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute(array($userId, 'email', hash('sha256', $code),
                        date('Y-m-d H:i:s', time() + $shift), $attempts, '127.0.0.1',
                        date('Y-m-d H:i:s')));
}

putCode($pdo, $parentId, '123456');

$r = call('POST', '/auth/verify-code', array('login' => 'parent@example.test', 'code' => '000000'));
check('неверный код — 401', $r['status'] === 401, 'код ' . $r['status']);

$attempts = (int) $pdo->query('SELECT attempts FROM auth_codes ORDER BY id DESC LIMIT 1')->fetchColumn();
check('неудачная попытка посчитана', $attempts === 1, 'попыток: ' . $attempts);

$r = call('POST', '/auth/verify-code', array('login' => 'parent@example.test', 'code' => '123456'));
$parentToken = isset($r['body']['token']) ? $r['body']['token'] : null;
check('верный код — токен', $r['status'] === 200 && $parentToken, 'код ' . $r['status'] . ' ' . $r['raw']);
check('родителю вернулись его дети',
      isset($r['body']['user']['children']) && count($r['body']['user']['children']) === 1);

$r = call('POST', '/auth/verify-code', array('login' => 'parent@example.test', 'code' => '123456'));
check('тот же код второй раз не проходит', $r['status'] === 401, 'код ' . $r['status']);

putCode($pdo, $parentId, '222222', -10);
$r = call('POST', '/auth/verify-code', array('login' => 'parent@example.test', 'code' => '222222'));
check('просроченный код не проходит', $r['status'] === 401
      && mb_strpos($r['body']['message'], 'устарел') !== false,
      $r['raw']);

putCode($pdo, $parentId, '333333', 300, 5);
$r = call('POST', '/auth/verify-code', array('login' => 'parent@example.test', 'code' => '333333'));
check('после пяти попыток код закрыт — 429', $r['status'] === 429, 'код ' . $r['status']);

// Ограничение на число запросов кода с одного адреса
$pdo->exec('DELETE FROM auth_codes');
$limited = false;
for ($i = 0; $i < 7; $i++) {
    $r = call('POST', '/auth/request-code', array('login' => 'parent@example.test', 'channel' => 'email'));
    if ($r['status'] === 429) {
        $limited = true;
        break;
    }
}
check('перебор запросов кода упирается в 429', $limited);

/* --- Границы доступа ------------------------------------- */

echo "\nГраницы доступа\n";

require_once $root . '/api/core/Response.php';
require_once $root . '/api/core/Db.php';
require_once $root . '/api/core/Auth.php';
Db::use_pdo($pdo);

$parentUser = $pdo->query('SELECT * FROM users WHERE id = ' . $parentId)->fetch();
$parentUser['roles'] = array('parent');
Auth::use_user($parentUser);

check('родитель видит своего ребёнка', Auth::sees_athlete($childId) === true);
check('родитель не видит чужого', Auth::sees_athlete($otherChildId) === false);

$adminUser = $pdo->query('SELECT * FROM users WHERE id = ' . $adminId)->fetch();
$adminUser['roles'] = array('admin');
Auth::use_user($adminUser);
check('администратор видит всех', Auth::sees_athlete($otherChildId) === true);

Auth::use_user(null);
check('без входа не виден никто', Auth::sees_athlete($childId) === false);

/* --- Выход и смена пароля -------------------------------- */

echo "\nВыход и смена пароля\n";

$r = call('POST', '/auth/logout', array(), $adminToken);
check('выход принят', $r['status'] === 200, 'код ' . $r['status']);

$r = call('GET', '/me', null, $adminToken);
check('после выхода токен не работает', $r['status'] === 401, 'код ' . $r['status']);

$r = call('POST', '/auth/login', array('login' => 'admin@example.test', 'password' => 'verysecret123'));
$adminToken = $r['body']['token'];

$r = call('POST', '/auth/change-password',
          array('current_password' => 'неверный', 'new_password' => 'newpassword123'), $adminToken);
check('смена пароля без текущего — 422', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/auth/change-password',
          array('current_password' => 'verysecret123', 'new_password' => 'корот'), $adminToken);
check('короткий пароль не принимается', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/auth/change-password',
          array('current_password' => 'verysecret123', 'new_password' => 'newpassword123'), $adminToken);
$freshToken = isset($r['body']['token']) ? $r['body']['token'] : null;
check('пароль сменился, выдан новый токен', $r['status'] === 200 && $freshToken,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/me', null, $adminToken);
check('прежние токены после смены пароля закрыты', $r['status'] === 401, 'код ' . $r['status']);

$r = call('GET', '/me', null, $freshToken);
check('новый токен работает', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/auth/login', array('login' => 'admin@example.test', 'password' => 'newpassword123'));
check('вход с новым паролем', $r['status'] === 200, 'код ' . $r['status']);

/* --- Журнал ---------------------------------------------- */

echo "\nЖурнал\n";

$logins = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login'")->fetchColumn();
check('входы записаны в журнал', $logins >= 3, 'записей: ' . $logins);

$dump = '';
foreach ($pdo->query('SELECT * FROM audit_log')->fetchAll() as $row) {
    $dump .= json_encode($row, JSON_UNESCAPED_UNICODE);
}
$leaked = array();
foreach (array('verysecret123', 'newpassword123', '123456', 'корот') as $secret) {
    if (mb_strpos($dump, $secret) !== false) {
        $leaked[] = $secret;
    }
}
check('ни пароль, ни код входа в журнал не попали', !$leaked, implode(', ', $leaked));

$codes = (int) $pdo->query("SELECT COUNT(*) FROM auth_codes WHERE code_hash LIKE '%123456%'")
                   ->fetchColumn();
check('коды в базе только хешем', $codes === 0);

/* --- Итог ------------------------------------------------ */

echo "\n";
if ($failed) {
    echo 'Не прошло ' . count($failed) . " из " . ($passed + count($failed)) . ":\n";
    foreach ($failed as $name) {
        echo '  - ' . $name . "\n";
    }
    $log = $dir . '/server.log';
    if (is_file($log)) {
        $tail = array_slice(file($log), -15);
        echo "\nПоследние строки лога сервера:\n" . implode('', $tail);
    }
    exit(1);
}

echo 'Все проверки прошли: ' . $passed . "\n";
exit(0);
