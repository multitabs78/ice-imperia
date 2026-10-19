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

$row = $pdo->query('SELECT last_name, birth_date, status FROM athletes WHERE id = ' . (int) $newAthlete)
           ->fetch();
check('имя и дата рождения стёрты',
      $row['last_name'] === 'Удалённый' && $row['birth_date'] === null && $row['status'] === 'archived',
      json_encode($row, JSON_UNESCAPED_UNICODE));

$kept = (int) $pdo->query('SELECT COUNT(*) FROM subscriptions WHERE athlete_id = ' . (int) $newAthlete)
                  ->fetchColumn();
check('история абонементов осталась', $kept === 1, 'записей: ' . $kept);

echo "\nПостоянное расписание\n";

$r = call('POST', '/venues', array('name' => 'Гололёд', 'address' => 'ул. Свободы, 15'), $admin);
$venue = isset($r['body']['id']) ? $r['body']['id'] : null;
check('площадка создана', $r['status'] === 201 && $venue, 'код ' . $r['status'] . ' ' . $r['raw']);

// Считаем от ближайшего понедельника: иначе проверки
// зависят от того, в какой день недели их запустили
$monday = date('Y-m-d', strtotime('next monday'));
$season = date('Y-m-d', strtotime($monday . ' -30 days'));

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'duration_min' => 60,
    'kind' => 'ice', 'venue_id' => $venue, 'coach_id' => $coach,
    'valid_from' => $season), $admin);
$rule1 = isset($r['body']['id']) ? $r['body']['id'] : null;
check('правило расписания создано', $r['status'] === 201 && $rule1,
      'код ' . $r['status'] . ' ' . $r['raw']);
check('день недели назван словом', isset($r['body']['weekday_name'])
      && $r['body']['weekday_name'] === 'понедельник',
      isset($r['body']['weekday_name']) ? $r['body']['weekday_name'] : '—');

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'kind' => 'ice',
    'venue_id' => $venue, 'valid_from' => $season), $admin);
check('повтор того же дня и времени — 409', $r['status'] === 409
      && $r['body']['error'] === 'rule_exists', 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 8, 'time_start' => '17:00', 'kind' => 'ice',
    'venue_id' => $venue, 'valid_from' => $season), $admin);
check('восьмого дня недели не бывает', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 3, 'time_start' => '25:00', 'kind' => 'ice',
    'venue_id' => $venue, 'valid_from' => $season), $admin);
check('время 25:00 не принимается', $r['status'] === 422, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 3, 'time_start' => '18:30', 'kind' => 'ice',
    'venue_id' => $venue, 'coach_id' => $coach, 'valid_from' => $season), $admin);
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

// Вторая группа на тот же лёд в то же время
$r = call('POST', '/groups/' . $group2 . '/schedule', array(
    'weekday' => 1, 'time_start' => '17:00', 'kind' => 'ice',
    'venue_id' => $venue, 'valid_from' => $season), $admin);
check('правило со встречным временем само по себе создаётся', $r['status'] === 201);

$r = call('POST', '/schedule/generate', array('group_id' => $group2, 'weeks' => 2,
                                              'from' => $monday), $admin);
check('занятый лёд не занимается дважды',
      $r['body']['created'] === 0 && count($r['body']['skipped']) === 2,
      'создано ' . $r['body']['created'] . ', пропущено ' . count($r['body']['skipped']));
check('пропуск объяснён словами',
      isset($r['body']['skipped'][0]['reason'])
      && strpos($r['body']['skipped'][0]['reason'], 'на льду') !== false,
      isset($r['body']['skipped'][0]['reason']) ? $r['body']['skipped'][0]['reason'] : '—');

$r = call('GET', '/trainings?from=' . $monday . '&to='
          . date('Y-m-d', strtotime($monday . ' +13 days')), null, $admin);
$trainings = $r['body']['items'];
check('тренировки видны администратору', $r['status'] === 200 && count($trainings) === 4,
      'тренировок: ' . count($trainings));
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
foreach ($r['body']['items'] as $one) { $codes[$one['group_code']] = true; }
check('тренер видит только свою группу', $r['status'] === 200
      && array_keys($codes) === array('СОГ-1'), implode(', ', array_keys($codes)));

$r = call('GET', '/trainings?group_id=' . $group2 . '&from=' . $monday
          . '&to=' . date('Y-m-d', strtotime($monday . ' +13 days')), null, $coachToken);
check('чужую группу в расписании не запросить', $r['status'] === 403, 'код ' . $r['status']);

$r = call('POST', '/groups/' . $group1 . '/schedule', array(
    'weekday' => 5, 'time_start' => '17:00', 'kind' => 'ice',
    'venue_id' => $venue, 'valid_from' => $season), $coachToken);
check('тренер расписание не правит', $r['status'] === 403, 'код ' . $r['status']);

echo "\nЖурнал справочников\n";

$kinds = $pdo->query("SELECT DISTINCT entity FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
check('в журнале есть записи по справочникам',
      in_array('athlete', $kinds) && in_array('group', $kinds) && in_array('tariff', $kinds),
      implode(', ', $kinds));
check('в журнале есть записи по расписанию',
      in_array('schedule_rule', $kinds) && in_array('training', $kinds),
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
