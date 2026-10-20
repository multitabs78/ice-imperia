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

    // Кириллица в строке запроса должна уходить закодированной,
    // иначе сервер получит не то, что мы думаем
    if (strpos($path, '?') !== false) {
        list($route, $query) = explode('?', $path, 2);
        parse_str($query, $parsed);
        $path = $route . '?' . http_build_query($parsed);
    }

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

/* --- Справочники ----------------------------------------- */

echo "\nСправочники: спортсмены\n";

$r = call('POST', '/auth/login', array('login' => 'admin@example.test', 'password' => 'newpassword123'));
$admin = $r['body']['token'];

$r = call('POST', '/athletes', array(
    'last_name' => 'Петров', 'first_name' => 'Илья', 'birth_date' => '2017-03-14'), $admin);
$newAthlete = isset($r['body']['id']) ? $r['body']['id'] : null;
check('спортсмен создан', $r['status'] === 201 && $newAthlete, 'код ' . $r['status'] . ' ' . $r['raw']);
check('возраст посчитан', isset($r['body']['age']) && $r['body']['age'] >= 8,
      'возраст: ' . (isset($r['body']['age']) ? $r['body']['age'] : '—'));

$r = call('POST', '/athletes', array('last_name' => '', 'first_name' => 'Илья'), $admin);
check('без фамилии — 422', $r['status'] === 422 && isset($r['body']['fields']['last_name']));

$r = call('POST', '/athletes', array(
    'last_name' => 'Иванов', 'first_name' => 'Тест', 'birth_date' => '2099-01-01'), $admin);
check('дата рождения в будущем не принимается', $r['status'] === 422,
      'код ' . $r['status']);

$r = call('GET', '/athletes?q=Петров', null, $admin);
check('поиск по фамилии находит', $r['status'] === 200 && $r['body']['total'] >= 1,
      'найдено: ' . (isset($r['body']['total']) ? $r['body']['total'] : '—'));

$r = call('POST', '/athletes', array('last_name' => 'Сидоров', 'first_name' => 'Иван'));
check('без токена создать нельзя', $r['status'] === 401, 'код ' . $r['status']);

echo "\nКарточка спортсмена: разряд и сроки\n";

$r = call('GET', '/athletes/' . $newAthlete, null, $admin);
check('дата зачисления проставлена сама',
      $r['body']['joined_at'] === date('Y-m-d'), $r['raw']);
check('отчисления пока нет', $r['body']['left_at'] === null, $r['raw']);

$r = call('GET', '/ranks', null, $admin);
$ranks = array();
foreach ($r['body']['items'] as $one) { $ranks[$one['name']] = $one['id']; }
check('разряды заведены миграцией', count($ranks) === 9, implode(', ', array_keys($ranks)));
check('порядок от младшего к старшему',
      $r['body']['items'][0]['name'] === 'Юный фигурист'
      && $r['body']['items'][8]['name'] === 'МС', $r['raw']);

$r = call('PATCH', '/athletes/' . $newAthlete, array(
    'rank_id' => $ranks['2 юношеский'], 'rank_date' => '2026-03-15',
    'rank_doc' => 'Приказ № 114-с',
    'medical_until' => date('Y-m-d', strtotime('+40 days')),
    'insurance_until' => date('Y-m-d', strtotime('-3 days'))), $admin);
check('разряд и документ сохранены', $r['status'] === 200
      && $r['body']['rank_name'] === '2 юношеский'
      && $r['body']['rank_doc'] === 'Приказ № 114-с',
      'код ' . $r['status'] . ' ' . $r['raw']);
check('до медосмотра считается запас в днях',
      $r['body']['medical_left'] === 40, 'осталось: ' . $r['body']['medical_left']);
check('просроченная страховка видна отрицательным числом',
      $r['body']['insurance_left'] === -3, 'осталось: ' . $r['body']['insurance_left']);

$r = call('PATCH', '/athletes/' . $newAthlete,
          array('rank_id' => 999999), $admin);
check('несуществующего разряда не поставить', $r['status'] === 404, 'код ' . $r['status']);

$r = call('PATCH', '/athletes/' . $newAthlete, array(
    'left_at' => date('Y-m-d'), 'reason' => 'Ушёл из школы'), $admin);
check('отчисление уводит в архив само', $r['status'] === 200
      && $r['body']['status'] === 'archived', $r['raw']);

$r = call('PATCH', '/athletes/' . $newAthlete,
          array('status' => 'active', 'reason' => 'Вернулся'), $admin);
check('возврат снимает дату отчисления', $r['status'] === 200
      && $r['body']['left_at'] === null, $r['raw']);

$r = call('POST', '/ranks', array('name' => 'МСМК', 'sort' => 95), $admin);
check('свой разряд можно добавить', $r['status'] === 201, 'код ' . $r['status']);

$r = call('POST', '/ranks', array('name' => 'МСМК'), $admin);
check('дважды — нельзя', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/ranks', array('name' => 'Свой'));
check('без входа разряды не править', $r['status'] === 401, 'код ' . $r['status']);

echo "\nВиды занятий как справочник\n";

$r = call('POST', '/lesson-kinds', array('name' => 'ОФП', 'sort' => 40), $admin);
$kindOfp = isset($r['body']['id']) ? $r['body']['id'] : null;
check('свой вид занятия добавлен', $r['status'] === 201 && $kindOfp,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/lesson-kinds', array('name' => 'ОФП'), $admin);
check('повтор названия — 409', $r['status'] === 409, 'код ' . $r['status']);

$r = call('PATCH', '/lesson-kinds/' . $kindOfp, array('name' => 'ОФП и растяжка'), $admin);
check('вид переименовывается', $r['status'] === 200, 'код ' . $r['status']);

$r = call('PATCH', '/lesson-kinds/' . $kindOfp, array('status' => 'archived'), $admin);
check('неиспользуемый вид убирается в архив', $r['status'] === 200, 'код ' . $r['status']);

$r = call('GET', '/lesson-kinds', null, $coachToken);
$names = array();
foreach ($r['body']['items'] as $one) { $names[] = $one['name']; }
check('в архиве его больше не предлагают', !in_array('ОФП и растяжка', $names),
      implode(', ', $names));

echo "\nАдминистраторы\n";

$r = call('GET', '/admins', null, $admin);
check('в списке администраторов пока один', $r['status'] === 200
      && count($r['body']['items']) === 1, $r['raw']);
check('себя видно как себя', $r['body']['items'][0]['self'] === true, $r['raw']);

$r = call('POST', '/admins', array(
    'last_name' => 'Директоров', 'first_name' => 'Павел',
    'email' => 'director@example.test', 'password' => 'adminpass123'), $admin);
$admin2 = isset($r['body']['id']) ? $r['body']['id'] : null;
check('второй администратор заведён', $r['status'] === 201 && $admin2,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('роль ему выдана', in_array('admin', $r['body']['roles']), $r['raw']);

$r = call('POST', '/auth/login', array('login' => 'director@example.test',
                                       'password' => 'adminpass123'));
$admin2Token = isset($r['body']['token']) ? $r['body']['token'] : null;
check('он входит по временному паролю', $r['status'] === 200 && $admin2Token,
      'код ' . $r['status']);
check('и система требует сменить его',
      !empty($r['body']['user']['must_change_password']), $r['raw']);

$r = call('GET', '/athletes', null, $admin2Token);
check('и видит то же, что первый', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/admins', array(
    'last_name' => 'Двойников', 'first_name' => 'Иван',
    'email' => 'director@example.test', 'password' => 'otherpass123'), $admin);
check('занятую почту второй раз не взять', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/admins', array('last_name' => 'Безсвязи', 'first_name' => 'Пётр'), $admin);
check('без телефона и почты администратора не завести', $r['status'] === 422,
      'код ' . $r['status']);

$r = call('POST', '/admins', array('password' => 'nopass12345'), $admin);
check('и без имени тоже', $r['status'] === 422, 'код ' . $r['status']);

$r = call('PATCH', '/admins/' . $adminId, array('status' => 'blocked'), $admin);
check('и вход себе не закрыть', $r['status'] === 409
      && $r['body']['error'] === 'self_block', 'код ' . $r['status']);

$r = call('DELETE', '/admins/' . $admin2, null, $admin);
check('без основания права не снимаем', $r['status'] === 422, 'код ' . $r['status']);

$r = call('DELETE', '/admins/' . $admin2 . '?reason=' . rawurlencode('Уволился'), null, $admin);
check('с основанием снимаются', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);
check('роль администратора ушла',
      !in_array('admin', $r['body']['roles']), json_encode($r['body']));

$left = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . (int) $admin2)
                  ->fetchColumn();
check('а сама учётная запись осталась', $left === 1, 'записей: ' . $left);

$r = call('GET', '/athletes', null, $admin2Token);
check('без роли разделы администратора закрыты', $r['status'] === 403,
      'код ' . $r['status']);

// Последнего администратора снять нельзя
$r = call('POST', '/admins', array('user_id' => $admin2), $admin);
check('права можно вернуть существующему человеку', $r['status'] === 201,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/admins', array('user_id' => $admin2), $admin);
check('дважды — отказ', $r['status'] === 409
      && $r['body']['error'] === 'already_admin', 'код ' . $r['status']);

// Пока второй администратор есть, уйти самому можно
$r = call('DELETE', '/admins/' . $adminId . '?reason=' . rawurlencode('Передаю дела'), null, $admin);
check('уйти самому можно, если есть кому передать', $r['status'] === 200,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/admins', null, $admin);
check('и прежний администратор сразу теряет доступ', $r['status'] === 403,
      'код ' . $r['status']);

// Возвращаем себе права от имени второго и проверяем,
// что последнего снять нельзя
$r = call('POST', '/auth/change-password', array(
    'current' => 'adminpass123', 'password' => 'newadminpass1'), $admin2Token);
$r = call('POST', '/admins', array('user_id' => $adminId), $admin2Token);
check('второй администратор вернул права первому', $r['status'] === 201,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('DELETE', '/admins/' . $adminId . '?reason=' . rawurlencode('Обратно'), null, $admin2Token);
$r = call('DELETE', '/admins/' . $admin2 . '?reason=' . rawurlencode('И себя'), null, $admin2Token);
check('последнего администратора снять нельзя', $r['status'] === 409
      && $r['body']['error'] === 'last_admin', 'код ' . $r['status'] . ' ' . $r['raw']);

// Возвращаем первого, дальше проверки идут от его имени
call('POST', '/admins', array('user_id' => $adminId), $admin2Token);
$r = call('POST', '/auth/login', array('login' => 'admin@example.test',
                                       'password' => 'newpassword123'));
$admin = $r['body']['token'];
check('первый администратор снова в деле', $r['status'] === 200 && $admin,
      'код ' . $r['status']);

$r = call('GET', '/admins', null, $coachToken ?? null);
check('тренеру раздел закрыт', $r['status'] === 401 || $r['status'] === 403,
      'код ' . $r['status']);

echo "\nСправочники: группы и тренеры\n";

$r = call('POST', '/groups', array('code' => 'СОГ-1', 'name' => 'Спортивно-оздоровительная'), $admin);
$group1 = isset($r['body']['id']) ? $r['body']['id'] : null;
check('группа создана', $r['status'] === 201 && $group1, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/groups', array('code' => 'СОГ-1', 'name' => 'Дубль'), $admin);
check('повтор короткого названия — 409', $r['status'] === 409
      && $r['body']['error'] === 'code_taken', 'код ' . $r['status']);

$r = call('POST', '/groups', array('code' => 'СОГ-2', 'name' => 'Вторая', 'age_from' => 9, 'age_to' => 5), $admin);
check('возраст наоборот не принимается', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/groups', array('code' => 'НП-1', 'name' => 'Начальная подготовка'), $admin);
$group2 = $r['body']['id'];

$r = call('POST', '/coaches', array(
    'last_name' => 'Филь', 'first_name' => 'Всеволод',
    'email' => 'coach@example.test', 'password' => 'coachpass123'), $admin);
$coach = isset($r['body']['id']) ? $r['body']['id'] : null;
check('тренер создан с учётной записью', $r['status'] === 201 && !empty($r['body']['has_login']),
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/groups/' . $group1 . '/coaches', array('coach_id' => $coach), $admin);
check('тренер закреплён за группой', $r['status'] === 200 && count($r['body']['items']) === 1,
      $r['raw']);

$r = call('POST', '/coaches', array(
    'last_name' => 'Мухаметзянова', 'first_name' => 'Алина'), $admin);
$coach2base = $r['body']['id'];
check('второй тренер заведён без входа в кабинет',
      $r['status'] === 201 && empty($r['body']['has_login']), $r['raw']);

echo "\nДоступ тренера в кабинет\n";

// Самый частый случай: администратор задал почту,
// а пароль оставил пустым
$r = call('POST', '/coaches', array(
    'last_name' => 'Безпароля', 'first_name' => 'Олег',
    'email' => 'nopass@example.test'), $admin);
$noPass = $r['body']['id'];
check('тренер с контактом, но без пароля заведён', $r['status'] === 201, 'код ' . $r['status']);
check('учётная запись всё равно создана', $r['body']['has_login'] === true, $r['raw']);

$r = call('GET', '/coaches', null, $admin);
$found = null;
foreach ($r['body']['items'] as $one) { if ((int) $one['id'] === (int) $noPass) { $found = $one; } }
check('в списке видна заданная почта', $found && $found['email'] === 'nopass@example.test',
      json_encode($found, JSON_UNESCAPED_UNICODE));
check('и видно, что пароль не задан', $found['has_password'] === false,
      json_encode($found, JSON_UNESCAPED_UNICODE));

$r = call('POST', '/auth/login', array('login' => 'nopass@example.test',
                                       'password' => 'ничего'));
check('войти паролем он пока не может', $r['status'] === 401, 'код ' . $r['status']);

// Учётная запись всё же заведена — значит, вход по коду
// для него возможен. Саму отправку письма проверяет
// отдельный набор: здесь почтовой службы нет
$row = $pdo->query("SELECT id, password_hash FROM users
                     WHERE email = 'nopass@example.test'")->fetch();
check('учётная запись заведена, пароля нет',
      $row && $row['password_hash'] === null, json_encode($row));

$r = call('POST', '/coaches/' . $noPass . '/password',
          array('password' => 'короткий'), $admin);
check('слишком короткий пароль не принимается', $r['status'] === 200 || $r['status'] === 422,
      'код ' . $r['status']);

$r = call('POST', '/coaches/' . $noPass . '/password',
          array('password' => 'freshpass123', 'reason' => 'Выдали доступ'), $admin);
check('пароль задан', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);
check('и сказано, по чему входить',
      $r['body']['login'] === 'nopass@example.test', $r['raw']);

$r = call('POST', '/auth/login', array('login' => 'nopass@example.test',
                                       'password' => 'freshpass123'));
check('теперь тренер входит', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);
check('и система требует сменить пароль',
      !empty($r['body']['user']['must_change_password']), $r['raw']);

// Второй частый случай: тренер заведён вовсе без контактов
$r = call('POST', '/coaches', array('last_name' => 'Никак', 'first_name' => 'Иван'), $admin);
$noLogin = $r['body']['id'];
check('тренер без контактов — карточка без входа',
      $r['status'] === 201 && $r['body']['has_login'] === false, $r['raw']);

$r = call('POST', '/coaches/' . $noLogin . '/password',
          array('password' => 'freshpass123'), $admin);
check('пароль такому задать нельзя, и сказано почему', $r['status'] === 409
      && $r['body']['error'] === 'no_login', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/coaches/' . $noLogin,
          array('phone' => '+7 999 777 66 55'), $admin);
check('добавление телефона заводит учётную запись', $r['status'] === 200
      && $r['body']['has_login'] === true, 'код ' . $r['status'] . ' ' . $r['raw']);
check('телефон приведён к единому виду',
      $r['body']['phone'] === '+79997776655', $r['raw']);

$r = call('POST', '/coaches/' . $noLogin . '/password',
          array('password' => 'freshpass999'), $admin);
check('и теперь пароль задаётся', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/auth/login', array('login' => '8 999 777 66 55',
                                       'password' => 'freshpass999'));
check('вход по телефону в любом написании', $r['status'] === 200,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/coaches/' . $noLogin,
          array('email' => 'nopass@example.test'), $admin);
check('занятую почту другому тренеру не отдать', $r['status'] === 409
      && $r['body']['error'] === 'contact_taken', 'код ' . $r['status']);

// Сброс пароля закрывает прежние входы
$r = call('POST', '/auth/login', array('login' => 'nopass@example.test',
                                       'password' => 'freshpass123'));
$oldToken = $r['body']['token'];
call('POST', '/coaches/' . $noPass . '/password',
     array('password' => 'anotherpass1', 'reason' => 'Попал не в те руки'), $admin);
$r = call('GET', '/me', null, $oldToken);
check('после сброса прежний вход закрыт', $r['status'] === 401, 'код ' . $r['status']);

echo "\nПеревод между группами\n";

$r = call('POST', '/enrollments', array(
    'athlete_id' => $newAthlete, 'group_id' => $group1, 'date_from' => '2026-09-01'), $admin);
check('назначение в группу', $r['status'] === 201, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/enrollments', array(
    'athlete_id' => $newAthlete, 'group_id' => $group1, 'date_from' => '2026-10-01'), $admin);
check('повторное назначение в ту же группу — 409', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/enrollments', array(
    'athlete_id' => $newAthlete, 'group_id' => $group2, 'date_from' => '2026-10-01'), $admin);
check('перевод закрыл прошлое назначение', $r['status'] === 201 && $r['body']['closed'] === 1,
      'закрыто: ' . (isset($r['body']['closed']) ? $r['body']['closed'] : '—'));

$groups = $r['body']['groups'];
$closed = null;
foreach ($groups as $g) { if ((int) $g['group_id'] === (int) $group1) { $closed = $g; } }
check('история прошлой группы сохранилась', $closed && $closed['date_to'] === '2026-09-30',
      $closed ? 'дата окончания ' . $closed['date_to'] : 'записи нет');

echo "\nТренер видит только своих\n";

$r = call('POST', '/auth/login', array('login' => 'coach@example.test', 'password' => 'coachpass123'));
$coachToken = isset($r['body']['token']) ? $r['body']['token'] : null;
check('тренер вошёл', $r['status'] === 200 && $coachToken, 'код ' . $r['status']);
check('тренеру предписано сменить пароль', !empty($r['body']['user']['must_change_password']));

$r = call('GET', '/groups', null, $coachToken);
check('в списке групп только своя', $r['status'] === 200 && count($r['body']['items']) === 1,
      'групп: ' . (isset($r['body']['items']) ? count($r['body']['items']) : '—'));

$r = call('GET', '/groups/' . $group2, null, $coachToken);
check('чужая группа — 403', $r['status'] === 403, 'код ' . $r['status']);

$r = call('GET', '/athletes/' . $newAthlete, null, $coachToken);
check('спортсмен чужой группы тренеру не виден', $r['status'] === 403, 'код ' . $r['status']);

$r = call('POST', '/athletes', array('last_name' => 'Чужой', 'first_name' => 'Ребёнок'), $coachToken);
check('тренер не создаёт спортсменов', $r['status'] === 403, 'код ' . $r['status']);

$r = call('POST', '/enrollments', array(
    'athlete_id' => $newAthlete, 'group_id' => $group1, 'date_from' => '2026-11-01'), $admin);
$r = call('GET', '/athletes', null, $coachToken);
check('после возврата в группу спортсмен виден тренеру',
      $r['status'] === 200 && $r['body']['total'] >= 1,
      'видит: ' . (isset($r['body']['total']) ? $r['body']['total'] : '—'));

echo "\nРодитель и ребёнок\n";

$r = call('POST', '/parents', array(
    'last_name' => 'Петрова', 'first_name' => 'Ольга', 'email' => 'olga@example.test'), $admin);
$newParent = isset($r['body']['id']) ? $r['body']['id'] : null;
check('родитель создан', $r['status'] === 201 && $newParent, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/parents', array(
    'last_name' => 'Двойник', 'first_name' => 'Ольга', 'email' => 'olga@example.test'), $admin);
check('повтор почты — 409', $r['status'] === 409 && $r['body']['error'] === 'contact_taken',
      'код ' . $r['status']);

$r = call('POST', '/parents', array('last_name' => 'Безсвязи', 'first_name' => 'Пётр'), $admin);
check('родитель без телефона и почты не создаётся', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/athletes/' . $newAthlete . '/parents',
          array('parent_user_id' => $newParent, 'relation' => 'mother', 'is_primary' => true), $admin);
check('родитель привязан к ребёнку', $r['status'] === 200 && count($r['body']['items']) === 1,
      $r['raw']);

$r = call('POST', '/athletes/' . $newAthlete . '/parents',
          array('parent_user_id' => $newParent, 'relation' => 'mother'), $admin);
check('повторная привязка — 409', $r['status'] === 409, 'код ' . $r['status']);

echo "\nТарифы\n";

$r = call('POST', '/tariffs', array('name' => 'СОГ-1', 'price' => 14400, 'sessions_count' => 8), $admin);
$tariff = isset($r['body']['id']) ? $r['body']['id'] : null;
check('тариф создан', $r['status'] === 201 && $tariff, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/tariffs', null, $admin);
$found = null;
foreach ($r['body']['items'] as $t) { if ((int) $t['id'] === (int) $tariff) { $found = $t; } }
check('цена занятия выведена из тарифа', $found && $found['lesson_price'] === '1800.00',
      $found ? 'вышло ' . $found['lesson_price'] : 'тариф не найден');

// Абонемент, чтобы тариф стал использованным
$pdo->prepare('INSERT INTO subscriptions (athlete_id, group_id, tariff_id, period, sessions_total,
               lesson_price, price_base, price, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute(array($newAthlete, $group1, $tariff, '2026-11', 8, 1800, 14400, 14400, 'active',
                    date('Y-m-d H:i:s')));

$r = call('PATCH', '/tariffs/' . $tariff, array('price' => 15000), $admin);
check('цену использованного тарифа менять нельзя', $r['status'] === 409
      && $r['body']['error'] === 'tariff_in_use', 'код ' . $r['status']);

$r = call('PATCH', '/tariffs/' . $tariff, array('name' => 'СОГ-1, 2 раза в неделю'), $admin);
check('название менять можно', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/tariffs/' . $tariff . '/revise',
          array('price' => 15000, 'reason' => 'Цены с января'), $admin);
$tariff2 = isset($r['body']['id']) ? $r['body']['id'] : null;
check('новая версия тарифа создана', $r['status'] === 201 && $tariff2 && $tariff2 != $tariff,
      'код ' . $r['status'] . ' ' . $r['raw']);

$old = $pdo->query('SELECT status FROM tariffs WHERE id = ' . (int) $tariff)->fetchColumn();
check('прежняя версия ушла в архив', $old === 'archived', 'состояние: ' . $old);

$sub = $pdo->query('SELECT price FROM subscriptions WHERE tariff_id = ' . (int) $tariff)->fetchColumn();
check('выданный абонемент не пересчитался', (float) $sub === 14400.0, 'в абонементе ' . $sub);

echo "\nОдин человек в двух ролях\n";

$r = call('POST', '/coaches', array(
    'last_name' => 'Стефанова', 'first_name' => 'Анастасия',
    'email' => 'admin@example.test', 'password' => 'coachpass123'), $admin);
check('занятая почта — понятный отказ', $r['status'] === 409
      && $r['body']['error'] === 'contact_taken', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/users?free_coach=1', null, $admin);
$free = array();
foreach ($r['body']['items'] as $u) { $free[$u['id']] = $u['name']; }
check('список свободных учётных записей отдаётся',
      $r['status'] === 200 && isset($free[$adminId]), implode(', ', $free));
$coachUser = (int) $pdo->query('SELECT user_id FROM coaches WHERE id = ' . (int) $coach)
                       ->fetchColumn();
check('тренер с учётной записью в список не попал', !isset($free[$coachUser]),
      'учётная запись ' . $coachUser . ' в списке');

$r = call('POST', '/coaches', array(
    'last_name' => 'Стефанова', 'first_name' => 'Анастасия',
    'user_id' => $adminId), $admin);
$adminCoach = isset($r['body']['id']) ? $r['body']['id'] : null;
check('карточка тренера привязана к учётной записи администратора',
      $r['status'] === 201 && $adminCoach, 'код ' . $r['status'] . ' ' . $r['raw']);

$roles = $pdo->query('SELECT role FROM user_roles WHERE user_id = ' . (int) $adminId)
             ->fetchAll(PDO::FETCH_COLUMN);
sort($roles);
check('у человека стало две роли', $roles === array('admin', 'coach'), implode(', ', $roles));

$r = call('POST', '/coaches', array(
    'last_name' => 'Ещё', 'first_name' => 'Раз', 'user_id' => $adminId), $admin);
check('вторую карточку на ту же запись не завести', $r['status'] === 409
      && $r['body']['error'] === 'user_is_coach', 'код ' . $r['status']);

$r = call('GET', '/me', null, $admin);
check('в ответе о себе видны обе роли',
      in_array('admin', $r['body']['roles']) && in_array('coach', $r['body']['roles']),
      implode(', ', $r['body']['roles']));

echo "\nУслуги и цены\n";

$r = call('GET', '/services', null, $admin);
$byCode = array();
foreach ($r['body']['items'] as $one) { if ($one['code']) { $byCode[$one['code']] = $one; } }
check('системные услуги заведены миграцией',
      isset($byCode['single_lesson']) && isset($byCode['extra_lesson']),
      implode(', ', array_keys($byCode)));
check('цена разового без абонемента задана',
      $byCode['single_lesson']['price_set'] === true);
check('цена разового при абонементе пока не задана',
      $byCode['extra_lesson']['price_set'] === false);

$r = call('PATCH', '/services/' . $byCode['extra_lesson']['id'],
          array('price' => 1200, 'reason' => 'Решение от 20.10'), $admin);
check('цену услуги можно задать', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/services/' . $byCode['single_lesson']['id'],
          array('status' => 'archived'), $admin);
check('системную услугу в архив не убрать', $r['status'] === 409
      && $r['body']['error'] === 'service_is_system', 'код ' . $r['status']);

$r = call('PATCH', '/services/' . $byCode['single_lesson']['id'],
          array('name' => 'Разовое занятие', 'price' => 2700), $admin);
check('название и цену системной услуги менять можно', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/services', array('name' => 'Прокат коньков', 'price' => 300), $admin);
$rental = isset($r['body']['id']) ? $r['body']['id'] : null;
check('своя услуга добавляется', $r['status'] === 201 && $rental, 'код ' . $r['status']);

$r = call('PATCH', '/services/' . $rental, array('status' => 'archived'), $admin);
check('свою услугу в архив убрать можно', $r['status'] === 200, 'код ' . $r['status']);

$r = call('GET', '/services', null, $coachToken);
check('тренеру справочник услуг не отдаётся', $r['status'] === 403, 'код ' . $r['status']);

echo "\nНастройки\n";

$r = call('GET', '/settings', null, $admin);
check('настройки читаются', $r['status'] === 200 && count($r['body']['items']) >= 4,
      'записей: ' . (isset($r['body']['items']) ? count($r['body']['items']) : '—'));

$r = call('PATCH', '/settings', array('illness_days_threshold' => '15', 'reason' => 'Решение школы'), $admin);
check('настройка сохранена', $r['status'] === 200
      && in_array('illness_days_threshold', $r['body']['saved']), $r['raw']);

$value = $pdo->query("SELECT value FROM settings WHERE name = 'illness_days_threshold'")->fetchColumn();
check('значение действительно изменилось', $value === '15', 'в базе ' . $value);

$r = call('PATCH', '/settings', array('illness_days_threshold' => ''), $admin);
check('пустое значение не сохраняется', $r['status'] === 422, 'код ' . $r['status']);

$gone = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE name = 'single_lesson_price'")
                  ->fetchColumn();
check('цена уехала из настроек в услуги', $gone === 0, 'записей: ' . $gone);

echo "\nУдаление персональных данных\n";

$r = call('POST', '/athletes/' . $newAthlete . '/anonymize', array(), $admin);
check('без основания не удаляем', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/athletes/' . $newAthlete . '/anonymize',
          array('reason' => 'Заявление родителя от 19.10'), $admin);
check('профиль обезличен', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);

$row = $pdo->query('SELECT last_name, birth_date, status, left_at, rank_doc, medical_until
                     FROM athletes WHERE id = ' . (int) $newAthlete)->fetch();
check('имя и дата рождения стёрты',
      $row['last_name'] === 'Удалённый' && $row['birth_date'] === null && $row['status'] === 'archived',
      json_encode($row, JSON_UNESCAPED_UNICODE));
check('дата отчисления проставилась сама', $row['left_at'] === date('Y-m-d'),
      'дата: ' . var_export($row['left_at'], true));
check('номер документа и сроки тоже стёрты',
      $row['rank_doc'] === null && $row['medical_until'] === null,
      json_encode($row, JSON_UNESCAPED_UNICODE));

$kept = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions WHERE athlete_id = ' . (int) $newAthlete)
                  ->fetchColumn();
check('история абонементов осталась', $kept === 1, 'записей: ' . $kept);

echo "\nПостоянное расписание\n";

$r = call('POST', '/venues', array('name' => 'Гололёд', 'address' => 'ул. Свободы, 15'), $admin);
$venue = isset($r['body']['id']) ? $r['body']['id'] : null;
check('спорткомплекс создан', $r['status'] === 201 && $venue, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/lesson-kinds', null, $admin);
$kinds = array();
foreach ($r['body']['items'] as $one) { $kinds[$one['name']] = $one['id']; }
$kindIce = isset($kinds['Лёд']) ? $kinds['Лёд'] : null;
$kindGym = isset($kinds['Зал']) ? $kinds['Зал'] : null;
check('виды занятий заведены миграцией', $kindIce && $kindGym,
      implode(', ', array_keys($kinds)));

$r = call('POST', '/venues/' . $venue . '/spots',
          array('name' => 'Лёд', 'kind' => 'ice'), $admin);
$spot = isset($r['body']['id']) ? $r['body']['id'] : null;
check('площадка внутри комплекса создана', $r['status'] === 201 && $spot,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/venues/' . $venue . '/spots',
          array('name' => 'Зал 2', 'kind' => 'hall'), $admin);
$hall = isset($r['body']['id']) ? $r['body']['id'] : null;
check('зал с номером добавлен', $r['status'] === 201 && $hall, 'код ' . $r['status']);

$r = call('POST', '/venues/' . $venue . '/spots',
          array('name' => 'Лёд', 'kind' => 'ice'), $admin);
check('двух площадок с одним названием не бывает', $r['status'] === 409
      && $r['body']['error'] === 'spot_exists', 'код ' . $r['status']);

$r = call('GET', '/venues', null, $admin);
$found = null;
foreach ($r['body']['items'] as $one) { if ((int) $one['id'] === (int) $venue) { $found = $one; } }
check('комплекс отдаётся вместе со своими площадками',
      $found && count($found['spots']) === 2, $r['raw']);

// Считаем от ближайшего понедельника: иначе проверки
// зависят от того, в какой день недели их запустили
$monday = date('Y-m-d', strtotime('next monday'));
$season = date('Y-m-d', strtotime($monday . ' -30 days'));

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'duration_min' => 60,
    'kind_id' => $kindIce, 'spot_id' => $spot, 'coach_id' => $coach,
    'valid_from' => $season), $admin);
$rule1 = isset($r['body']['id']) ? $r['body']['id'] : null;
check('правило расписания создано', $r['status'] === 201 && $rule1,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('день недели назван словом', isset($r['body']['weekday_name'])
      && $r['body']['weekday_name'] === 'понедельник',
      isset($r['body']['weekday_name']) ? $r['body']['weekday_name'] : '—');

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $admin);
check('повтор того же дня и времени — 409', $r['status'] === 409
      && $r['body']['error'] === 'rule_exists', 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 8, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $admin);
check('восьмого дня недели не бывает', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 3, 'time_start' => '25:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $admin);
check('время 25:00 не принимается', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 3, 'time_start' => '18:30', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'coach_id' => $coach, 'valid_from' => $season), $admin);
$rule2 = $r['body']['id'];
check('второе занятие в неделю добавлено', $r['status'] === 201);

$r = call('GET', '/groups/' . $group1 . '/schedule', null, $admin);
check('расписание группы отдаётся по порядку дней',
      $r['status'] === 200 && count($r['body']['items']) === 2
      && $r['body']['items'][0]['weekday'] === 1,
      $r['raw']);

echo "\nГенерация тренировок\n";

$r = call('POST', '/schedule/generate', array('group_id' => $group1, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('тренировки созданы', $r['status'] === 200 && $r['body']['created'] === 4,
      'создано: ' . (isset($r['body']['created']) ? $r['body']['created'] : '—'));

$r = call('POST', '/schedule/generate', array('group_id' => $group1, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('повторный запуск ничего не плодит',
      $r['body']['created'] === 0 && $r['body']['existed'] === 4,
      'создано ' . $r['body']['created'] . ', было ' . $r['body']['existed']);

// Вторая группа на тот же лёд в то же время — это норма:
// на льду одновременно работает несколько тренеров
$r = call('POST', '/groups/' . $group2 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'coach_id' => $coach2base, 'valid_from' => $season), $admin);
check('вторая группа встаёт на тот же лёд', $r['status'] === 201,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/schedule/generate', array('group_id' => $group2, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('одну площадку делят свободно',
      $r['body']['created'] === 2 && count($r['body']['skipped']) === 0,
      'создано ' . $r['body']['created'] . ', пропущено ' . count($r['body']['skipped']));

// А вот один человек в двух местах сразу — ошибка
$r = call('POST', '/groups', array('code' => 'ТЭ-1', 'name' => 'Тренировочная'), $admin);
$group3 = $r['body']['id'];
$r = call('POST', '/groups/' . $group3 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'kind_id' => $kindGym,
    'spot_id' => $hall, 'coach_id' => $coach, 'valid_from' => $season), $admin);
check('правило в зале создаётся', $r['status'] === 201, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/schedule/generate', array('group_id' => $group3, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('тренер не раздваивается между льдом и залом',
      $r['body']['created'] === 0 && count($r['body']['skipped']) === 2,
      'создано ' . $r['body']['created'] . ', пропущено ' . count($r['body']['skipped']));
check('пропуск объяснён словами',
      isset($r['body']['skipped'][0]['reason'])
      && strpos($r['body']['skipped'][0]['reason'], 'тренер') !== false,
      isset($r['body']['skipped'][0]['reason']) ? $r['body']['skipped'][0]['reason'] : '—');

// Но две группы у одного тренера на одной площадке — это
// объединённое занятие, а не ошибка. Проверяем на других
// группах, чтобы не путать расписание первой
$r = call('POST', '/groups/' . $group2 . '/schedule', array(
    'weekday' => 2, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'coach_id' => $coach2base, 'valid_from' => $season), $admin);
check('правило второй группы на вторник создано', $r['status'] === 201);

$r = call('POST', '/groups/' . $group3 . '/schedule', array(
    'weekday' => 2, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'coach_id' => $coach2base, 'valid_from' => $season), $admin);
check('две группы одного тренера на одной площадке допустимы',
      $r['status'] === 201, 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/schedule/generate', array('group_id' => $group3, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('объединённое занятие генерируется', $r['body']['created'] === 2,
      'создано ' . $r['body']['created'] . ', пропущено ' . count($r['body']['skipped']));

$r = call('GET', '/trainings?from=' . $monday . '&to='
          . date('Y-m-d', strtotime($monday . ' +13 days')), null, $admin);
$all = $r['body']['items'];
check('тренировки видны администратору', $r['status'] === 200 && count($all) >= 8,
      'тренировок: ' . count($all));

// Дальше работаем только с занятиями первой группы
$trainings = array();
foreach ($all as $one) {
    if ((int) $one['group_id'] === (int) $group1) { $trainings[] = $one; }
}
check('у первой группы четыре занятия на две недели', count($trainings) === 4,
      'занятий: ' . count($trainings));
check('в занятии видна площадка, а не только комплекс',
      $trainings[0]['spot_name'] === 'Лёд', $r['raw']);
check('у тренировки есть название группы и площадки',
      $trainings[0]['group_code'] === 'СОГ-1' && $trainings[0]['venue_name'] === 'Гололёд',
      json_encode($trainings[0], JSON_UNESCAPED_UNICODE));

$first = $trainings[0]['id'];

echo "\nОтмена и перенос\n";

$r = call('POST', '/trainings/' . $first . '/cancel', array(), $admin);
check('без причины не отменяем', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/trainings/' . $first . '/cancel',
          array('reason' => 'Заливка льда'), $admin);
check('тренировка отменена', $r['status'] === 200 && $r['body']['status'] === 'cancelled',
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/trainings/' . $first . '/cancel',
          array('reason' => 'Ещё раз'), $admin);
check('дважды не отменяем', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/schedule/generate', array('group_id' => $group1, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('генерация не воскрешает отменённое', $r['body']['created'] === 0,
      'создано: ' . $r['body']['created']);

// Проведённое занятие отменять нельзя: на нём уже
// отмечены посещения, и отмена молча обнулила бы их
$held = $trainings[3]['id'];
$pdo->exec('UPDATE trainings SET status = \'held\' WHERE id = ' . (int) $held);
$r = call('POST', '/trainings/' . $held . '/cancel',
          array('reason' => 'Передумали'), $admin);
check('проведённое занятие не отменяем', $r['status'] === 409
      && $r['body']['error'] === 'training_held', 'код ' . $r['status'] . ' ' . $r['raw']);
$pdo->exec('UPDATE trainings SET status = \'planned\' WHERE id = ' . (int) $held);

$second = $trainings[1]['id'];
$moveTo = date('Y-m-d', strtotime($monday . ' +1 day'));

$r = call('POST', '/trainings/' . $second . '/move',
          array('date' => $moveTo, 'time_start' => '17:00', 'reason' => 'Соревнования'), $admin);
$movedId = isset($r['body']['training']['id']) ? $r['body']['training']['id'] : null;
check('тренировка перенесена', $r['status'] === 201 && $movedId,
      'код ' . $r['status'] . ' ' . $r['raw']);

$old = $pdo->query('SELECT status, moved_to_training_id FROM trainings WHERE id = ' . (int) $second)
           ->fetch();
check('у прежней осталась ссылка на новую',
      $old['status'] === 'moved' && (int) $old['moved_to_training_id'] === (int) $movedId,
      json_encode($old));

$r = call('POST', '/trainings/' . $second . '/move',
          array('date' => $moveTo, 'time_start' => '19:00', 'reason' => 'Ещё раз'), $admin);
check('перенесённую второй раз не переносим', $r['status'] === 409, 'код ' . $r['status']);

$pdo->exec('UPDATE trainings SET status = \'held\' WHERE id = ' . (int) $movedId);
$r = call('POST', '/trainings/' . $movedId . '/move',
          array('date' => $moveTo, 'time_start' => '20:00', 'reason' => 'Поздно'), $admin);
check('проведённую не переносим', $r['status'] === 409
      && $r['body']['error'] === 'training_held', 'код ' . $r['status']);
$pdo->exec('UPDATE trainings SET status = \'planned\' WHERE id = ' . (int) $movedId);

echo "\nЗамена тренера\n";

$r = call('POST', '/coaches', array(
    'last_name' => 'Лазурина', 'first_name' => 'Мария',
    'email' => 'lazurina@example.test', 'password' => 'subpass12345'), $admin);
$coach2 = $r['body']['id'];
check('второй тренер заведён', $r['status'] === 201 && $coach2, 'код ' . $r['status']);

$third = $trainings[2]['id'];

$r = call('POST', '/trainings/' . $third . '/coach', array('coach_id' => $coach2), $coachToken);
check('тренер группы ставит замену сам', $r['status'] === 200
      && (int) $r['body']['coach_id'] === (int) $coach2, 'код ' . $r['status'] . ' ' . $r['raw']);
check('замена помечена как замена', $r['body']['substitute'] === true, $r['raw']);

$r = call('POST', '/auth/login', array('login' => 'lazurina@example.test',
                                       'password' => 'subpass12345'));
$coach2Token = $r['body']['token'];

$r = call('GET', '/trainings/' . $third, null, $coach2Token);
check('заменяющий видит тренировку чужой группы', $r['status'] === 200,
      'код ' . $r['status']);
check('и видит состав группы на этот день',
      isset($r['body']['athletes']) && is_array($r['body']['athletes']), $r['raw']);

$fourth = $trainings[3]['id'];
$r = call('GET', '/trainings/' . $fourth, null, $coach2Token);
check('остальные занятия чужой группы ему закрыты', $r['status'] === 403,
      'код ' . $r['status']);

$r = call('POST', '/trainings/' . $fourth . '/coach', array('coach_id' => $coach2), $coach2Token);
check('посторонний тренер замену не назначает', $r['status'] === 403, 'код ' . $r['status']);

$r = call('POST', '/trainings/' . $third . '/coach', array('coach_id' => $coach2), $admin);
check('назначить того же — 409', $r['status'] === 409
      && $r['body']['error'] === 'same_coach', 'код ' . $r['status']);

$r = call('GET', '/trainings?mine=1&from=' . $monday . '&to='
          . date('Y-m-d', strtotime($monday . ' +13 days')), null, $coach2Token);
check('в своих занятиях у заменяющего ровно одно',
      $r['status'] === 200 && count($r['body']['items']) === 1,
      'занятий: ' . count($r['body']['items']));

echo "\nВиды занятий в деле\n";

$r = call('GET', '/lesson-kinds?status=all', null, $admin);
$inUse = null;
foreach ($r['body']['items'] as $one) { if ($one['name'] === 'Лёд') { $inUse = $one; } }
check('видно, какой вид уже используется', $inUse && $inUse['in_use'] === true, $r['raw']);

$r = call('PATCH', '/lesson-kinds/' . $kindIce, array('status' => 'archived'), $admin);
check('используемый вид в архив не убрать', $r['status'] === 409
      && $r['body']['error'] === 'kind_in_use', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/lesson-kinds', array('name' => 'Чужой'), $coachToken);
check('тренер виды не правит', $r['status'] === 403, 'код ' . $r['status']);

$r = call('GET', '/trainings?from=' . $monday . '&to='
          . date('Y-m-d', strtotime($monday . ' +6 days')), null, $admin);
check('в занятии вид назван из справочника',
      $r['body']['items'][0]['kind_name'] === 'Лёд', $r['raw']);

echo "\nПравка расписания без пересоздания\n";

$r = call('PATCH', '/schedule-rules/' . $rule1, array(
    'time_start' => '18:00', 'reason' => 'Сдвинули лёд'), $admin);
check('время в правиле поменялось', $r['status'] === 200
      && $r['body']['time_start'] === '18:00', 'код ' . $r['status'] . ' ' . $r['raw']);
check('без указания даты занятия не тронуты', $r['body']['applied'] === 0,
      'перенесено: ' . $r['body']['applied']);

$still = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE rule_id = ' . (int) $rule1
                           . ' AND time_start = "17:00:00"')->fetchColumn();
check('и правда остались на прежнем времени', $still > 0, 'занятий: ' . $still);

$r = call('PATCH', '/schedule-rules/' . $rule1, array(
    'time_start' => '18:30', 'apply_from' => $monday,
    'reason' => 'Переносим и занятия'), $admin);
check('с датой изменение доходит до занятий', $r['status'] === 200
      && $r['body']['applied'] > 0, 'перенесено: ' . $r['body']['applied']);

$moved = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE rule_id = ' . (int) $rule1
                           . ' AND time_start = "18:30:00"')->fetchColumn();
check('занятия встали на новое время', $moved > 0, 'занятий: ' . $moved);

$held = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE rule_id = ' . (int) $rule1
                          . ' AND status <> "planned" AND time_start = "18:30:00"')->fetchColumn();
check('проведённые и отменённые не переписаны', $held === 0, 'задето: ' . $held);

$r = call('PATCH', '/schedule-rules/' . $rule1, array(
    'spot_id' => $hall, 'apply_from' => $monday, 'reason' => 'Переехали в зал'), $admin);
check('площадку тоже можно сменить разом', $r['status'] === 200
      && $r['body']['spot_name'] === 'Зал 2', $r['raw']);

// Второй комплекс: только на нём и видно, что комплекс
// следует за площадкой, а не остаётся прежним
$r = call('POST', '/venues', array('name' => 'Юбилейный', 'address' => 'пр. Добролюбова, 18'), $admin);
$venue2 = $r['body']['id'];
$r = call('POST', '/venues/' . $venue2 . '/spots',
          array('name' => 'Малая арена', 'kind' => 'ice'), $admin);
$spot2 = $r['body']['id'];

$r = call('PATCH', '/schedule-rules/' . $rule1, array(
    'spot_id' => $spot2, 'reason' => 'Другая арена'), $admin);
check('переезд в другой комплекс принят', $r['status'] === 200
      && $r['body']['spot_name'] === 'Малая арена', $r['raw']);

$venueToo = $pdo->query('SELECT venue_id FROM schedule_rules WHERE id = ' . (int) $rule1)
                ->fetchColumn();
check('комплекс подтянулся за площадкой', (int) $venueToo === (int) $venue2,
      'комплекс ' . $venueToo . ', ожидали ' . $venue2);

$r = call('PATCH', '/schedule-rules/' . $rule1, array('spot_id' => 999999), $admin);
check('несуществующей площадки нет', $r['status'] === 404, 'код ' . $r['status']);

$r = call('PATCH', '/spots/' . $spot . '?reason=' . rawurlencode('Закрываем'),
          array('status' => 'archived'), $admin);
check('площадку с будущими занятиями в архив не убрать', $r['status'] === 409
      && $r['body']['error'] === 'spot_in_use', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/spots/' . $spot2, array('status' => 'archived'), $admin);
check('свободную площадку убрать можно', $r['status'] === 200, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 6, 'time_start' => '10:00', 'kind_id' => $kindIce,
    'spot_id' => $spot2, 'valid_from' => $season), $admin);
check('на архивную площадку расписание не поставить', $r['status'] === 409
      && $r['body']['error'] === 'spot_archived', 'код ' . $r['status']);

call('PATCH', '/spots/' . $spot2, array('status' => 'active'), $admin);

// Возвращаем обратно, чтобы дальнейшие проверки шли по льду
call('PATCH', '/schedule-rules/' . $rule1, array(
    'spot_id' => $spot, 'apply_from' => $monday, 'reason' => 'Обратно на лёд'), $admin);

$r = call('PATCH', '/schedule-rules/' . $rule1, array('coach_id' => 999999), $admin);
check('несуществующего тренера не поставить', $r['status'] === 404, 'код ' . $r['status']);

echo "\nИндивидуальные занятия\n";

$r = call('POST', '/athletes', array(
    'last_name' => 'Соловьёва', 'first_name' => 'Ксения',
    'birth_date' => '2014-02-02'), $admin);
$solo = $r['body']['id'];
call('POST', '/enrollments', array('athlete_id' => $solo,
     'group_id' => $group1, 'date_from' => $season), $admin);

$soloDay = date('Y-m-d', strtotime('-1 day'));

$r = call('POST', '/trainings', array(
    'athlete_id' => $solo, 'date' => $soloDay, 'time_start' => '15:00',
    'kind_id' => $kindIce, 'spot_id' => $spot, 'coach_id' => $coach), $admin);
$soloOne = isset($r['body']['id']) ? $r['body']['id'] : null;
check('разовое индивидуальное занятие заведено', $r['status'] === 201 && $soloOne,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('оно помечено как индивидуальное', $r['body']['personal'] === true, $r['raw']);
check('подписано именем, а не группой',
      $r['body']['title'] === 'Соловьёва Ксения' && $r['body']['group_id'] === null,
      $r['raw']);

$r = call('POST', '/trainings', array(
    'group_id' => $group1, 'athlete_id' => $solo, 'date' => $soloDay,
    'time_start' => '15:30', 'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
check('и группа, и спортсмен сразу — отказ', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/trainings', array(
    'date' => $soloDay, 'time_start' => '15:30',
    'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
check('без того и другого — тоже', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/trainings', array(
    'athlete_id' => $solo, 'date' => $soloDay, 'time_start' => '15:00',
    'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
check('двух занятий в одно время у человека не бывает', $r['status'] === 409
      && $r['body']['error'] === 'training_exists', 'код ' . $r['status'] . ' ' . $r['raw']);

// Постоянное индивидуальное расписание
$r = call('POST', '/athletes/' . $solo . '/schedule', array(
    'weekday' => 5, 'time_start' => '15:00', 'duration_min' => 45,
    'kind_id' => $kindIce, 'spot_id' => $spot, 'coach_id' => $coach,
    'valid_from' => $season), $admin);
$soloRule = isset($r['body']['id']) ? $r['body']['id'] : null;
check('постоянное индивидуальное занятие заведено', $r['status'] === 201 && $soloRule,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('в правиле видно имя спортсмена',
      $r['body']['personal'] === true && $r['body']['athlete_name'] === 'Соловьёва Ксения',
      $r['raw']);

$r = call('POST', '/athletes/' . $solo . '/schedule', array(
    'weekday' => 5, 'time_start' => '15:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $admin);
check('повтор того же дня и времени — 409', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/schedule/generate', array('athlete_id' => $solo, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('личные занятия генерируются', $r['status'] === 200 && $r['body']['created'] === 2,
      'создано: ' . (isset($r['body']['created']) ? $r['body']['created'] : '—'));

$r = call('POST', '/schedule/generate', array('athlete_id' => $solo, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('повторная генерация их не плодит', $r['body']['created'] === 0,
      'создано: ' . $r['body']['created']);

$r = call('GET', '/athletes/' . $solo . '/schedule', null, $admin);
check('расписание спортсмена отдаётся', $r['status'] === 200
      && count($r['body']['items']) === 1, $r['raw']);

$r = call('GET', '/trainings?from=' . $soloDay . '&to=' . $soloDay, null, $admin);
$mine = null;
foreach ($r['body']['items'] as $one) { if ((int) $one['id'] === (int) $soloOne) { $mine = $one; } }
check('личное занятие попадает в общее расписание', $mine !== null, $r['raw']);

// Отметки на личном занятии
$r = call('GET', '/trainings/' . $soloOne . '/attendance', null, $coachToken);
check('в листе отметок один человек', $r['status'] === 200
      && count($r['body']['items']) === 1, $r['raw']);
check('и это тот, ради кого занятие',
      $r['body']['items'][0]['name'] === 'Соловьёва Ксения', $r['raw']);

$r = call('POST', '/trainings/' . $soloOne . '/attendance', array('marks' => array(
    array('athlete_id' => $solo, 'status' => 'present'))), $coachToken);
check('личное занятие отмечается', $r['status'] === 200 && $r['body']['saved'] === 1,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/athletes/' . $solo . '/attendance?from=' . $soloDay
          . '&to=' . $soloDay, null, $admin);
check('в истории спортсмена оно названо индивидуальным',
      count($r['body']['items']) === 1 && $r['body']['items'][0]['personal'] === true,
      $r['raw']);

$r = call('POST', '/trainings/' . $soloOne . '/cancel',
          array('reason' => 'Передумали'), $admin);
check('отмеченное личное занятие уже не отменить', $r['status'] === 409,
      'код ' . $r['status']);

echo "\nПередача групп новому тренеру\n";

// Отдельная пара тренеров и своя группа: передача меняет
// закрепления целиком, и трогать ею общие проверки нельзя
$r = call('POST', '/coaches', array('last_name' => 'Уволин', 'first_name' => 'Олег'), $admin);
$quit = $r['body']['id'];
$r = call('POST', '/coaches', array('last_name' => 'Новиков', 'first_name' => 'Егор'), $admin);
$fresh = $r['body']['id'];

$r = call('POST', '/groups', array('code' => 'ПЕР-1', 'name' => 'Для передачи'), $admin);
$groupH = $r['body']['id'];
call('POST', '/groups/' . $groupH . '/coaches', array('coach_id' => $quit), $admin);
call('POST', '/groups/' . $groupH . '/schedule', array(
    'weekday' => 4, 'time_start' => '16:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'coach_id' => $quit, 'valid_from' => $season), $admin);
call('POST', '/schedule/generate', array('group_id' => $groupH, 'weeks' => 2,
                                         'from' => $monday), $admin);

$r = call('POST', '/coaches/' . $quit . '/handover', array(
    'to_coach_id' => $quit, 'reason' => 'Ошибка'), $admin);
check('самому себе не передать', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/coaches/' . $quit . '/handover', array(
    'to_coach_id' => $fresh), $admin);
check('без основания не передаём', $r['status'] === 422, 'код ' . $r['status']);

$beforeGroups = (int) $pdo->query('SELECT COUNT(*) FROM group_coaches WHERE coach_id = '
                                  . (int) $quit)->fetchColumn();
$future0 = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE coach_id = ' . (int) $quit
                             . ' AND status = "planned"')->fetchColumn();

// Одно занятие делаем проведённым: оно должно остаться за ним
$pdo->exec('UPDATE trainings SET status = "held" WHERE coach_id = ' . (int) $quit . ' LIMIT 1');
$pastHeld = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE coach_id = ' . (int) $quit
                              . ' AND status = "held"')->fetchColumn();

$r = call('POST', '/coaches/' . $quit . '/handover', array(
    'to_coach_id' => $fresh, 'reason' => 'Уволился, принял другой'), $admin);
check('группы переданы', $r['status'] === 200 && $r['body']['groups'] === $beforeGroups,
      'передано ' . (isset($r['body']['groups']) ? $r['body']['groups'] : '—')
      . ' из ' . $beforeGroups);
check('и расписание вместе с ними', $r['body']['rules'] > 0,
      'правил: ' . $r['body']['rules']);

$left = (int) $pdo->query('SELECT COUNT(*) FROM group_coaches WHERE coach_id = '
                          . (int) $quit)->fetchColumn();
check('за прежним тренером групп не осталось', $left === 0, 'осталось: ' . $left);

$rules = (int) $pdo->query('SELECT COUNT(*) FROM schedule_rules WHERE coach_id = '
                           . (int) $quit)->fetchColumn();
check('и правил расписания тоже', $rules === 0, 'осталось: ' . $rules);

$stillHis = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE coach_id = ' . (int) $quit
                              . ' AND status = "held"')->fetchColumn();
check('проведённые занятия остались за тем, кто их вёл',
      $stillHis === $pastHeld, 'было ' . $pastHeld . ', стало ' . $stillHis);

$future = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE coach_id = ' . (int) $quit
                            . ' AND status = "planned"')->fetchColumn();
check('будущие занятия перешли новому', $future === 0,
      'осталось ' . $future . ' из ' . $future0);

echo "\nЗакрытие правила\n";

$before = (int) $pdo->query('SELECT COUNT(*) FROM trainings WHERE rule_id = ' . (int) $rule2)
                    ->fetchColumn();
$r = call('DELETE', '/schedule-rules/' . $rule2 . '?from=' . date('Y-m-d')
          . '&reason=' . rawurlencode('Сняли со среды'), null, $admin);
check('правило закрыто', $r['status'] === 200 && $r['body']['removed'] > 0,
      'убрано: ' . (isset($r['body']['removed']) ? $r['body']['removed'] : '—')
      . ' из ' . $before);

$left = (int) $pdo->query('SELECT COUNT(*) FROM trainings
                            WHERE rule_id = ' . (int) $rule2 . ' AND status = "planned"')
                  ->fetchColumn();
check('будущих занятий по закрытому правилу не осталось', $left === 0, 'осталось: ' . $left);

$r = call('GET', '/groups/' . $group1 . '/schedule', null, $admin);
check('закрытое правило ушло из текущего расписания',
      count($r['body']['items']) === 1, 'правил: ' . count($r['body']['items']));

$r = call('GET', '/groups/' . $group1 . '/schedule?all=1', null, $admin);
check('но в истории осталось', count($r['body']['items']) === 2,
      'правил: ' . count($r['body']['items']));

echo "\nКто что видит в расписании\n";

$r = call('GET', '/trainings?from=' . $monday . '&to='
          . date('Y-m-d', strtotime($monday . ' +13 days')), null, $coachToken);
$codes = array();
foreach ($r['body']['items'] as $one) { $codes[$one['title']] = true; }
// Кроме своей группы тренер видит личные занятия, которые
// ведёт сам — чужих групп среди них быть не должно
check('тренер видит свою группу и свои личные занятия', $r['status'] === 200
      && isset($codes['СОГ-1']) && !isset($codes['НП-1']) && !isset($codes['ТЭ-1']),
      implode(', ', array_keys($codes)));

$r = call('GET', '/trainings?group_id=' . $group2 . '&from=' . $monday
          . '&to=' . date('Y-m-d', strtotime($monday . ' +13 days')), null, $coachToken);
check('чужую группу в расписании не запросить', $r['status'] === 403, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 5, 'time_start' => '17:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $coachToken);
check('тренер расписание не правит', $r['status'] === 403, 'код ' . $r['status']);

echo "\nПосещаемость: лист отметок\n";

// Своя группа со своими спортсменами: так проверки
// не зависят от того, что осталось от прежних разделов
$r = call('POST', '/groups', array('code' => 'ПОС-1', 'name' => 'Для отметок'), $admin);
$gm = $r['body']['id'];
call('POST', '/groups/' . $gm . '/coaches', array('coach_id' => $coach), $admin);

$long = date('Y-m-d', strtotime('-90 days'));
$kids = array();
foreach (array(array('Белов', 'Пётр'), array('Волкова', 'Аня'), array('Громов', 'Лев')) as $one) {
    $r = call('POST', '/athletes', array(
        'last_name' => $one[0], 'first_name' => $one[1], 'birth_date' => '2016-05-05'), $admin);
    $kids[$one[0]] = $r['body']['id'];
    call('POST', '/enrollments', array('athlete_id' => $r['body']['id'],
         'group_id' => $gm, 'date_from' => $long), $admin);
}

$yesterday = date('Y-m-d', strtotime('-1 day'));
$twoAgo    = date('Y-m-d', strtotime('-2 days'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));

$r = call('POST', '/trainings', array('group_id' => $gm, 'date' => $yesterday,
    'time_start' => '17:00', 'kind_id' => $kindIce, 'spot_id' => $spot,
    'coach_id' => $coach), $admin);
$t1 = $r['body']['id'];
check('вчерашнее занятие заведено', $r['status'] === 201 && $t1,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/trainings/' . $t1 . '/attendance', null, $coachToken);
check('лист отметок отдаётся тренеру', $r['status'] === 200, 'код ' . $r['status']);
check('в листе весь состав группы', count($r['body']['items']) === 3,
      'человек: ' . count($r['body']['items']));
check('отмечать можно', $r['body']['can_mark'] === true, $r['raw']);
check('пока ничего не отмечено', $r['body']['items'][0]['mark'] === null, $r['raw']);

$r = call('POST', '/trainings/' . $t1 . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'],   'status' => 'present'),
    array('athlete_id' => $kids['Волкова'], 'status' => 'absent'),
    array('athlete_id' => $kids['Громов'],  'status' => 'absent_sick'),
)), $coachToken);
check('группа отмечена одним действием', $r['status'] === 200 && $r['body']['saved'] === 3,
      'код ' . $r['status'] . ' ' . $r['raw']);

$status = $pdo->query('SELECT status FROM trainings WHERE id = ' . (int) $t1)->fetchColumn();
check('отмеченное занятие стало проведённым', $status === 'held', 'состояние: ' . $status);

$r = call('GET', '/trainings/' . $t1 . '/attendance', null, $coachToken);
$byName = array();
foreach ($r['body']['items'] as $one) { $byName[$one['name']] = $one; }
check('пропуск списывает занятие',
      $byName['Волкова Аня']['mark']['charged'] === true, $r['raw']);
check('болезнь списывает так же',
      $byName['Громов Лев']['mark']['charged'] === true, $r['raw']);
check('отметка названа словами',
      $byName['Громов Лев']['mark']['label'] === 'пропуск по болезни', $r['raw']);

$r = call('POST', '/trainings/' . $t1 . '/cancel', array('reason' => 'Передумали'), $admin);
check('отмеченное занятие уже не отменить', $r['status'] === 409
      && $r['body']['error'] === 'training_held', 'код ' . $r['status']);

echo "\nИсправление отметок\n";

$r = call('POST', '/trainings/' . $t1 . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Волкова'], 'status' => 'present'),
)), $coachToken);
check('исправление без причины не проходит', $r['status'] === 422
      && isset($r['body']['fields']['reason']), 'код ' . $r['status'] . ' ' . $r['raw']);

$was = $pdo->query('SELECT status FROM attendance WHERE training_id = ' . (int) $t1
                   . ' AND athlete_id = ' . (int) $kids['Волкова'])->fetchColumn();
check('и ничего не меняет', $was === 'absent', 'в базе: ' . $was);

$r = call('POST', '/trainings/' . $t1 . '/attendance', array(
    'reason' => 'Опоздала, но была',
    'marks' => array(array('athlete_id' => $kids['Волкова'], 'status' => 'present'))), $coachToken);
check('с причиной исправляется', $r['status'] === 200 && $r['body']['changed'] === 1,
      'код ' . $r['status'] . ' ' . $r['raw']);

$why = $pdo->query("SELECT reason FROM audit_log WHERE entity = 'attendance'
                     AND action = 'update' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('причина исправления в журнале', $why === 'Опоздала, но была', 'причина: ' . $why);

$r = call('POST', '/trainings/' . $t1 . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'present'),
)), $coachToken);
check('повтор той же отметки причины не требует', $r['status'] === 200
      && $r['body']['changed'] === 0, 'изменено: ' . $r['body']['changed']);

echo "\nКогда отмечать нельзя\n";

$r = call('POST', '/trainings', array('group_id' => $gm, 'date' => $tomorrow,
    'time_start' => '17:00', 'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
$future = $r['body']['id'];

$r = call('GET', '/trainings/' . $future . '/attendance', null, $coachToken);
check('будущее занятие отмечать нельзя', $r['body']['can_mark'] === false
      && strpos($r['body']['closed_why'], 'ещё не прошло') !== false,
      $r['body']['closed_why']);

$r = call('POST', '/trainings/' . $future . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'present'))), $coachToken);
check('и запрос отклоняется', $r['status'] === 409
      && $r['body']['error'] === 'marking_closed', 'код ' . $r['status']);

$r = call('POST', '/trainings/' . $future . '/cancel', array('reason' => 'Каникулы'), $admin);
$r = call('POST', '/trainings/' . $future . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'present'))), $admin);
check('отменённое занятие отмечать нечего', $r['status'] === 409, 'код ' . $r['status']);

$r = call('POST', '/trainings/' . $t1 . '/attendance', array('marks' => array(
    array('athlete_id' => $newAthlete, 'status' => 'present'))), $coachToken);
check('чужого в лист не вписать', $r['status'] === 422, 'код ' . $r['status'] . ' ' . $r['raw']);

// Окно правки: ставим сутки и заводим занятие пятидневной давности
call('PATCH', '/settings', array('coach_edit_window_days' => '1',
                                 'reason' => 'Для проверки'), $admin);
$old5 = date('Y-m-d', strtotime('-5 days'));
$r = call('POST', '/trainings', array('group_id' => $gm, 'date' => $old5,
    'time_start' => '17:00', 'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
$stale = $r['body']['id'];

$r = call('POST', '/trainings/' . $stale . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'present'))), $coachToken);
check('после срока тренер не отмечает', $r['status'] === 409
      && strpos($r['body']['message'], 'администратор') !== false,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/trainings/' . $stale . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'present'))), $admin);
check('администратор отмечает и после срока', $r['status'] === 200, 'код ' . $r['status']);

call('PATCH', '/settings', array('coach_edit_window_days' => '3',
                                 'reason' => 'Обратно'), $admin);

echo "\nОтработка пропуска\n";

$r = call('POST', '/trainings', array('group_id' => $gm, 'date' => $twoAgo,
    'time_start' => '17:00', 'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
$t2 = $r['body']['id'];
call('POST', '/trainings/' . $t2 . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'absent'),
    array('athlete_id' => $kids['Волкова'], 'status' => 'present'))), $coachToken);

$r = call('POST', '/trainings/' . $t1 . '/makeup', array(
    'athlete_id' => $kids['Белов'], 'missed_training_id' => $t2), $coachToken);
check('отработка не заводится дважды на одно занятие', $r['status'] === 409
      && $r['body']['error'] === 'already_marked', 'код ' . $r['status'] . ' ' . $r['raw']);

// Отработка в другой группе — обычный случай
$r = call('POST', '/trainings', array('group_id' => $group1, 'date' => $yesterday,
    'time_start' => '19:30', 'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
$other = $r['body']['id'];

$r = call('POST', '/trainings/' . $other . '/makeup', array(
    'athlete_id' => $kids['Белов'], 'missed_training_id' => $t2,
    'comment' => 'Согласовано с тренером'), $admin);
check('отработка заведена', $r['status'] === 201, 'код ' . $r['status'] . ' ' . $r['raw']);
check('отработка не списывает занятие', $r['body']['charged'] === false, $r['raw']);
check('видно, какой пропуск она закрывает',
      (int) $r['body']['makeup_for'] === (int) $t2, $r['raw']);
$makeupId = $r['body']['id'];

// Второй раз тот же пропуск — уже на другом занятии,
// иначе сработает запрет двух отметок на одном и настоящая
// проверка «пропуск отрабатывается один раз» не проверится
$r = call('POST', '/trainings', array('group_id' => $group1, 'date' => $yesterday,
    'time_start' => '20:30', 'kind_id' => $kindGym, 'spot_id' => $spot), $admin);
$other2 = $r['body']['id'];

$r = call('POST', '/trainings/' . $other2 . '/makeup', array(
    'athlete_id' => $kids['Белов'], 'missed_training_id' => $t2), $admin);
check('один пропуск отрабатывается один раз', $r['status'] === 409
      && $r['body']['error'] === 'already_made_up', 'код ' . $r['status'] . ' ' . $r['raw']);

// Правка отработки не должна превращать её в списанное
// занятие: пропуск уже списан раньше
$r = call('POST', '/trainings/' . $other . '/attendance', array(
    'reason' => 'Всё-таки не пришёл',
    'marks' => array(array('athlete_id' => $kids['Белов'], 'status' => 'absent'))), $admin);
check('отработку можно исправить', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);

$after = $pdo->query('SELECT charged, makeup_for_training_id FROM attendance
                       WHERE training_id = ' . (int) $other . '
                         AND athlete_id = ' . (int) $kids['Белов'])->fetch();
check('и она остаётся отработкой, без списания',
      (int) $after['charged'] === 0 && (int) $after['makeup_for_training_id'] === (int) $t2,
      json_encode($after));

$r = call('POST', '/trainings/' . $other . '/makeup', array(
    'athlete_id' => $kids['Волкова'], 'missed_training_id' => $t2), $admin);
check('посещённое занятие не отрабатывают', $r['status'] === 409
      && $r['body']['error'] === 'not_a_miss', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('GET', '/trainings/' . $other . '/attendance', null, $admin);
$found = null;
foreach ($r['body']['items'] as $one) {
    if ((int) $one['athlete_id'] === (int) $kids['Белов']) { $found = $one; }
}
check('отработавший виден в листе чужой группы', $found && $found['makeup'] === true,
      $r['raw']);

$r = call('DELETE', '/attendance/' . $makeupId, null, $admin);
check('снять отработку без причины нельзя', $r['status'] === 422, 'код ' . $r['status']);

$r = call('DELETE', '/attendance/' . $makeupId . '?reason=' . rawurlencode('Ошиблись'), null, $admin);
check('с причиной снимается', $r['status'] === 200, 'код ' . $r['status']);

$mark = $pdo->query('SELECT id FROM attendance WHERE training_id = ' . (int) $t1
                    . ' AND athlete_id = ' . (int) $kids['Белов'])->fetchColumn();
$r = call('DELETE', '/attendance/' . $mark . '?reason=' . rawurlencode('Просто так'), null, $admin);
check('обычную отметку удалить нельзя — только исправить', $r['status'] === 409
      && $r['body']['error'] === 'not_a_makeup', 'код ' . $r['status']);

echo "\nЖурнал группы\n";

$r = call('GET', '/groups/' . $gm . '/attendance?month=' . date('Y-m'), null, $coachToken);
check('журнал отдаётся', $r['status'] === 200, 'код ' . $r['status'] . ' ' . $r['raw']);
check('в журнале три строки', count($r['body']['rows']) === 3,
      'строк: ' . count($r['body']['rows']));
check('столбцов ровно по числу занятий месяца',
      count($r['body']['columns']) === count($r['body']['rows'][0]['cells']),
      'столбцов ' . count($r['body']['columns'])
      . ', клеток ' . count($r['body']['rows'][0]['cells']));

$row = null;
foreach ($r['body']['rows'] as $one) { if ($one['name'] === 'Громов Лев') { $row = $one; } }
check('итоги по человеку посчитаны', $row && $row['absent'] === 1 && $row['charged'] === 1,
      json_encode($row, JSON_UNESCAPED_UNICODE));

$r = call('GET', '/groups/' . $gm . '/attendance?month=2026-13', null, $admin);
check('тринадцатого месяца не бывает', $r['status'] === 422, 'код ' . $r['status']);

$r = call('GET', '/groups/' . $group2 . '/attendance?month=' . date('Y-m'), null, $coachToken);
check('журнал чужой группы тренеру закрыт', $r['status'] === 403, 'код ' . $r['status']);

echo "\nИстория спортсмена\n";

$r = call('GET', '/athletes/' . $kids['Белов'] . '/attendance?from='
          . date('Y-m-d', strtotime('-10 days')) . '&to=' . date('Y-m-d'), null, $admin);
check('история посещений отдаётся', $r['status'] === 200 && count($r['body']['items']) >= 2,
      'записей: ' . count($r['body']['items']));
check('в записи видно группу и дату',
      isset($r['body']['items'][0]['group_code']) && isset($r['body']['items'][0]['date']),
      $r['raw']);

$r = call('GET', '/athletes/' . $kids['Белов'] . '/attendance', null, $coach2Token);
check('посторонний тренер историю не видит', $r['status'] === 403, 'код ' . $r['status']);

echo "\nОтчисление выводит из группы\n";

$r = call('POST', '/athletes', array('last_name' => 'Тестов', 'first_name' => 'Тест'), $admin);
$temp = $r['body']['id'];
call('POST', '/enrollments', array('athlete_id' => $temp,
     'group_id' => $gm, 'date_from' => $long), $admin);

$r = call('GET', '/groups/' . $gm, null, $admin);
$was = $r['body']['athletes'];
check('после назначения он в счётчике', $was >= 1, 'занимающихся: ' . $was);

$r = call('PATCH', '/athletes/' . $temp,
          array('status' => 'archived', 'reason' => 'Тестовая запись'), $admin);
check('отчисление прошло', $r['status'] === 200, 'код ' . $r['status']);
check('дата отчисления проставилась', $r['body']['left_at'] === date('Y-m-d'), $r['raw']);

$open = (int) $pdo->query('SELECT COUNT(*) FROM enrollments
                            WHERE athlete_id = ' . (int) $temp . ' AND date_to IS NULL')
                  ->fetchColumn();
check('назначение в группу закрылось', $open === 0, 'открытых: ' . $open);

$r = call('GET', '/groups/' . $gm, null, $admin);
check('и счётчик группы уменьшился', $r['body']['athletes'] === $was - 1,
      'было ' . $was . ', стало ' . $r['body']['athletes']);

$r = call('GET', '/athletes/' . $temp, null, $admin);
$cur = 0;
foreach ($r['body']['groups'] as $one) { if ($one['current']) { $cur++; } }
check('и ни одна группа у него больше не текущая', $cur === 0, $r['raw']);

$r = call('GET', '/athletes?group_id=' . $gm, null, $admin);
check('счётчик сходится со списком состава',
      count($r['body']['items']) === $r['body']['total']
      && $r['body']['total'] === $was - 1,
      'в списке ' . $r['body']['total']);

$r = call('POST', '/enrollments', array('athlete_id' => $temp,
          'group_id' => $gm, 'date_from' => date('Y-m-d')), $admin);
check('отчисленного в группу не назначить', $r['status'] === 409
      && $r['body']['error'] === 'athlete_archived', 'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/athletes/' . $temp,
          array('status' => 'active', 'reason' => 'Вернули'), $admin);
check('возврат снимает дату отчисления', $r['body']['left_at'] === null, $r['raw']);

$r = call('POST', '/enrollments', array('athlete_id' => $temp,
          'group_id' => $gm, 'date_from' => date('Y-m-d')), $admin);
check('и назначить его снова можно', $r['status'] === 200 || $r['status'] === 201,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('прежнее назначение продолжено, а не задвоено',
      !empty($r['body']['reopened']), $r['raw']);

$rows = (int) $pdo->query('SELECT COUNT(*) FROM enrollments
                            WHERE athlete_id = ' . (int) $temp . ' AND group_id = ' . (int) $gm)
                  ->fetchColumn();
check('назначение в группе осталось одно', $rows === 1, 'записей: ' . $rows);

$r = call('POST', '/enrollments', array('athlete_id' => $temp,
          'group_id' => $gm, 'date_from' => date('Y-m-d')), $admin);
check('а повтор при открытом назначении по-прежнему отказ', $r['status'] === 409
      && $r['body']['error'] === 'already_enrolled', 'код ' . $r['status']);

// Обезличивание тоже выводит из группы
$r = call('GET', '/groups/' . $gm, null, $admin);
$before = $r['body']['athletes'];
call('POST', '/athletes/' . $temp . '/anonymize',
     array('reason' => 'Заявление'), $admin);

$open = (int) $pdo->query('SELECT COUNT(*) FROM enrollments
                            WHERE athlete_id = ' . (int) $temp . ' AND date_to IS NULL')
                  ->fetchColumn();
check('после удаления данных назначений тоже не осталось', $open === 0,
      'открытых: ' . $open);

$r = call('GET', '/groups/' . $gm, null, $admin);
check('и счётчик снова сошёлся', $r['body']['athletes'] === $before - 1,
      'было ' . $before . ', стало ' . $r['body']['athletes']);

echo "\nРазбор замечаний по базе\n";

// R02: тренер на личном занятии виден проверке занятости
$r = call('POST', '/athletes', array('last_name' => 'Личный', 'first_name' => 'Ученик'), $admin);
$r02 = $r['body']['id'];
$day = date('Y-m-d', strtotime($monday . ' +21 days'));

call('POST', '/trainings', array('athlete_id' => $r02, 'date' => $day,
     'time_start' => '12:00', 'kind_id' => $kindIce, 'spot_id' => $spot,
     'coach_id' => $coach), $admin);
$r = call('POST', '/trainings', array('group_id' => $group1, 'date' => $day,
     'time_start' => '12:00', 'kind_id' => $kindGym, 'spot_id' => $hall,
     'coach_id' => $coach), $admin);
check('R02: личное занятие учитывается в занятости тренера', $r['status'] === 409
      && $r['body']['error'] === 'slot_busy', 'код ' . $r['status'] . ' ' . $r['raw']);
check('R02: и в тексте названо имя спортсмена',
      strpos($r['body']['message'], 'Личный') !== false, $r['body']['message']);

// R03: отработка не исчезает вместе с правилом
$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 7, 'time_start' => '09:00', 'kind_id' => $kindIce,
    'spot_id' => $spot, 'valid_from' => $season), $admin);
$r03rule = $r['body']['id'];
call('POST', '/schedule/generate', array('group_id' => $group1, 'weeks' => 3,
     'from' => date('Y-m-d', strtotime('-14 days'))), $admin);

$r = call('GET', '/trainings?from=' . date('Y-m-d', strtotime('-14 days'))
          . '&to=' . date('Y-m-d') . '&group_id=' . $group1, null, $admin);
$sunday = null;
foreach ($r['body']['items'] as $one) {
    if ($one['time_start'] === '09:00' && $one['status'] === 'planned') { $sunday = $one['id']; }
}
check('R03: занятие для проверки нашлось', $sunday !== null, $r['raw']);

if ($sunday) {
    $gap = $pdo->query('SELECT id FROM trainings WHERE id <> ' . (int) $sunday
                       . ' AND status = "held" LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO attendance (training_id, athlete_id, status, charged,
                   makeup_for_training_id, marked_at) VALUES (?,?,?,?,?,?)')
        ->execute(array($sunday, $kids['Белов'], 'present', 0, $gap, date('Y-m-d H:i:s')));

    $r = call('DELETE', '/schedule-rules/' . $r03rule . '?from='
              . date('Y-m-d', strtotime('-14 days'))
              . '&reason=' . rawurlencode('Сняли'), null, $admin);
    check('R03: занятие с отметкой отменено, а не удалено',
          $r['status'] === 200 && $r['body']['cancelled'] >= 1,
          'убрано ' . $r['body']['removed'] . ', отменено ' . $r['body']['cancelled']);

    $kept = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE training_id = '
                              . (int) $sunday)->fetchColumn();
    check('R03: отработка уцелела', $kept === 1, 'отметок: ' . $kept);
}

// R04: родитель видит занятие ребёнка, но не правит отметки
$r = call('POST', '/parents', array('last_name' => 'Родителев', 'first_name' => 'Пётр',
          'email' => 'r04@example.test'), $admin);
$r04parent = $r['body']['id'];
call('POST', '/athletes/' . $kids['Белов'] . '/parents',
     array('parent_user_id' => $r04parent, 'relation' => 'father'), $admin);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute(array(password_hash('parentpass12', PASSWORD_DEFAULT), $r04parent));
$r = call('POST', '/auth/login', array('login' => 'r04@example.test',
                                       'password' => 'parentpass12'));
$parentToken = $r['body']['token'];

// Личное занятие ребёнка — то самое, что родителю видно
$r = call('POST', '/trainings', array('athlete_id' => $kids['Белов'],
     'date' => date('Y-m-d', strtotime('-1 day')), 'time_start' => '08:00',
     'kind_id' => $kindIce, 'spot_id' => $spot), $admin);
$solo04 = $r['body']['id'];

$r = call('GET', '/trainings/' . $solo04 . '/attendance', null, $parentToken);
check('R04: родитель видит лист личного занятия своего ребёнка', $r['status'] === 200,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/trainings/' . $solo04 . '/attendance', array('marks' => array(
    array('athlete_id' => $kids['Белов'], 'status' => 'absent'))), $parentToken);
check('R04: но отметки менять не может', $r['status'] === 403,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('POST', '/trainings/' . $solo04 . '/makeup', array(
    'athlete_id' => $kids['Белов'], 'missed_training_id' => $t2), $parentToken);
check('R04: и отработку не заводит', $r['status'] === 403, 'код ' . $r['status']);

$r = call('GET', '/trainings/' . $t1 . '/attendance', null, $parentToken);
check('R04: чужой групповой лист ему закрыт', $r['status'] === 403,
      'код ' . $r['status']);

// R05: возврат в группу не оставляет два открытых назначения
$r = call('POST', '/athletes', array('last_name' => 'Двугруппов', 'first_name' => 'Иван'), $admin);
$r05 = $r['body']['id'];
call('POST', '/enrollments', array('athlete_id' => $r05, 'group_id' => $group1,
     'date_from' => '2026-09-01'), $admin);
call('POST', '/enrollments', array('athlete_id' => $r05, 'group_id' => $group2,
     'date_from' => '2026-10-01'), $admin);
$r = call('POST', '/enrollments', array('athlete_id' => $r05, 'group_id' => $group1,
     'date_from' => date('Y-m-d')), $admin);
check('R05: вернулись в прежнюю группу', $r['status'] === 200 || $r['status'] === 201,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('R05: прежняя группа закрыта при возврате', $r['body']['closed'] >= 1,
      'закрыто: ' . $r['body']['closed']);

$open = (int) $pdo->query('SELECT COUNT(*) FROM enrollments WHERE athlete_id = '
                          . (int) $r05 . ' AND date_to IS NULL')->fetchColumn();
check('R05: открытое назначение осталось одно', $open === 1, 'открытых: ' . $open);

// Тот же случай, но с настоящим продолжением прежнего
// назначения: уходим в другую группу вчера и возвращаемся
// сегодня — прежнее должно ожить, а новое закрыться
$r = call('POST', '/athletes', array('last_name' => 'Вернулов', 'first_name' => 'Пётр'), $admin);
$r05b = $r['body']['id'];
call('POST', '/enrollments', array('athlete_id' => $r05b, 'group_id' => $group1,
     'date_from' => date('Y-m-d', strtotime('-30 days'))), $admin);

// Второе открытое назначение заводим мимо обработчика:
// ровно такие записи и остались в базе от прежней ошибки,
// и именно их возврат в группу должен подобрать
$pdo->exec('UPDATE enrollments SET date_to = "' . date('Y-m-d', strtotime('-1 day'))
           . '" WHERE athlete_id = ' . (int) $r05b);
$pdo->prepare('INSERT INTO enrollments (athlete_id, group_id, date_from, created_at)
               VALUES (?,?,?,?)')
    ->execute(array($r05b, $group2, date('Y-m-d', strtotime('-1 day')),
                    date('Y-m-d H:i:s')));

$r = call('POST', '/enrollments', array('athlete_id' => $r05b, 'group_id' => $group1,
     'date_from' => date('Y-m-d')), $admin);
check('R05: прежнее назначение продолжено', !empty($r['body']['reopened']),
      'код ' . $r['status'] . ' ' . $r['raw']);
check('R05: и вторая группа при этом закрыта', $r['body']['closed'] === 1,
      'закрыто: ' . $r['body']['closed']);

$open = (int) $pdo->query('SELECT COUNT(*) FROM enrollments WHERE athlete_id = '
                          . (int) $r05b . ' AND date_to IS NULL')->fetchColumn();
check('R05: открытое назначение снова одно', $open === 1, 'открытых: ' . $open);

// R06: перевод задним числом не создаёт отрицательный промежуток
$r = call('POST', '/athletes', array('last_name' => 'Задним', 'first_name' => 'Числом'), $admin);
$r06 = $r['body']['id'];
call('POST', '/enrollments', array('athlete_id' => $r06, 'group_id' => $group1,
     'date_from' => '2026-12-01'), $admin);
call('POST', '/enrollments', array('athlete_id' => $r06, 'group_id' => $group2,
     'date_from' => '2026-10-05'), $admin);
$bad = (int) $pdo->query('SELECT COUNT(*) FROM enrollments
                           WHERE athlete_id = ' . (int) $r06 . '
                             AND date_to IS NOT NULL AND date_to < date_from')->fetchColumn();
check('R06: окончаний раньше начала нет', $bad === 0, 'плохих: ' . $bad);

// R07: ошибочно заполненное поле можно очистить
call('PATCH', '/athletes/' . $r06, array('rank_doc' => 'Ошибка',
     'medical_until' => '2027-01-01'), $admin);
$r = call('PATCH', '/athletes/' . $r06, array('rank_doc' => '',
          'medical_until' => '', 'reason' => 'Внесли по ошибке'), $admin);
check('R07: поля очищаются', $r['status'] === 200 && $r['body']['rank_doc'] === null
      && $r['body']['medical_until'] === null, $r['raw']);

// R08: проверка дат смотрит на итог, а не на присланное
call('PATCH', '/athletes/' . $r06, array('joined_at' => '2026-09-01'), $admin);
$r = call('PATCH', '/athletes/' . $r06, array('left_at' => '2026-08-01'), $admin);
check('R08: отчисление раньше зачисления не проходит', $r['status'] === 422,
      'код ' . $r['status'] . ' ' . $r['raw']);

$r = call('PATCH', '/athletes/' . $r06,
          array('birth_date' => '2030-01-01'), $admin);
check('R08: дата рождения в будущем не проходит', $r['status'] === 422,
      'код ' . $r['status']);

// R09: назначенный с будущей даты ещё не в составе
$r = call('POST', '/athletes', array('last_name' => 'Будущий', 'first_name' => 'Член'), $admin);
$r09 = $r['body']['id'];
$r = call('GET', '/groups/' . $group2, null, $admin);
$was09 = $r['body']['athletes'];
call('POST', '/enrollments', array('athlete_id' => $r09, 'group_id' => $group2,
     'date_from' => date('Y-m-d', strtotime('+30 days'))), $admin);
$r = call('GET', '/groups/' . $group2, null, $admin);
check('R09: будущее назначение в счётчик не идёт', $r['body']['athletes'] === $was09,
      'было ' . $was09 . ', стало ' . $r['body']['athletes']);

// R13: правка тарифа без изменения цены не блокируется.
// Цену записываем с дробной частью руками: на MySQL она
// и хранится как 14400.00, а SQLite в тестах округляет —
// без этого проверка на нём ничего бы не значила
$pdo->exec('UPDATE tariffs SET price = "14400.00" WHERE id = ' . (int) $tariff);

$r = call('PATCH', '/tariffs/' . $tariff, array('price' => 14400,
          'name' => 'СОГ-1, два раза'), $admin);
check('R13: та же цена правку не блокирует', $r['status'] === 200,
      'код ' . $r['status'] . ' ' . $r['raw']);

// R15: повторное сохранение отчисленной карточки
call('PATCH', '/athletes/' . $r09, array('status' => 'archived',
     'reason' => 'Передумали'), $admin);
$r = call('PATCH', '/athletes/' . $r09, array('status' => 'archived',
          'reason' => 'Ещё раз'), $admin);
check('R15: повторное сохранение проходит', $r['status'] === 200,
      'код ' . $r['status'] . ' ' . $r['raw']);

// R10: персональные данные не оседают в журнале
$leak = (int) $pdo->query("SELECT COUNT(*) FROM audit_log
                            WHERE entity = 'athlete'
                              AND (before_json LIKE '%Белов%' OR after_json LIKE '%Белов%'
                                OR before_json LIKE '%2016-05-05%'
                                OR after_json LIKE '%2016-05-05%')")->fetchColumn();
check('R10: имён и дат рождения в журнале нет', $leak === 0, 'записей с данными: ' . $leak);

$kept = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'athlete'")
                  ->fetchColumn();
check('R10: сами записи журнала при этом остались', $kept > 0, 'записей: ' . $kept);

echo "\nЖурнал справочников\n";

$kinds = $pdo->query("SELECT DISTINCT entity FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
check('в журнале есть записи по справочникам',
      in_array('athlete', $kinds) && in_array('group', $kinds) && in_array('tariff', $kinds),
      implode(', ', $kinds));
check('в журнале есть записи по расписанию',
      in_array('schedule_rule', $kinds) && in_array('training', $kinds),
      implode(', ', $kinds));
check('в журнале есть записи по отметкам', in_array('attendance', $kinds),
      implode(', ', $kinds));

$move = $pdo->query("SELECT reason FROM audit_log WHERE action = 'move' LIMIT 1")->fetchColumn();
check('причина переноса записана', $move === 'Соревнования', 'причина: ' . $move);

$why = $pdo->query("SELECT reason FROM audit_log WHERE action = 'anonymize' LIMIT 1")->fetchColumn();
check('основание удаления записано', $why === 'Заявление родителя от 19.10', 'причина: ' . $why);

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
