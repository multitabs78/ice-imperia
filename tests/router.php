<?php
/* Маршрутизатор только для проверки страницы входа браузером.
   В репозиторий не входит: он умеет подсматривать код из базы,
   чего на рабочем сервере быть не должно.

   Заодно подменяет mod_rewrite: встроенный сервер PHP
   не умеет переписывать адреса, а /api/v1/... именно на этом
   и держится. */

// Предохранитель: без тестовой базы файл бесполезен и опасен —
// он умеет показывать коды входа. На рабочем сервере папки tests
// быть не должно вовсе, но если она туда попадёт, этот файл
// ничего не сделает.
if (!getenv('IMPERIA_TEST_DB')) {
    http_response_code(404);
    exit('Not found');
}

$root = getenv('IMPERIA_ROOT');

// auto_prepend_file к самому маршрутизатору не применяется —
// ядро и подмену базы подключаем здесь
require_once $root . '/tests/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Чистый лист перед прогоном: иначе ограничение на число
// запросов кода за час срабатывает от прошлых запусков
if ($path === '/__test/reset') {
    header('Content-Type: text/plain; charset=utf-8');
    Db::run('DELETE FROM auth_codes');
    Db::run('DELETE FROM access_tokens');
    Db::run('DELETE FROM audit_log');
    exit('ok');
}

// Подсказка браузеру: какой сейчас код у пользователя
if (strpos($path, '/__test/code') === 0) {
    header('Content-Type: text/plain; charset=utf-8');

    $login = isset($_GET['login']) ? $_GET['login'] : '';
    $user  = Db::row('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1',
                     array(mb_strtolower($login), $login));
    if (!$user) {
        http_response_code(404);
        exit('нет такого пользователя');
    }

    $row = Db::row('SELECT id FROM auth_codes WHERE user_id = ? AND used_at IS NULL
                     ORDER BY id DESC LIMIT 1', array($user['id']));
    if (!$row) {
        http_response_code(404);
        exit('код не запрашивали');
    }

    $code = '654321';
    Db::run('UPDATE auth_codes SET code_hash = ?, attempts = 0, expires_at = ? WHERE id = ?',
            array(hash('sha256', $code), date('Y-m-d H:i:s', time() + 300), $row['id']));

    exit($code);
}

// /login — то, что на хостинге делает правило в .htaccess
if (preg_match('#^/login/?$#', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/cabinet/login.html';
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/cabinet/login.html');
    return true;
}

// Адреса API обслуживает настоящий api/index.php
if (strpos($path, '/api/') === 0) {
    $_GET['route'] = trim(preg_replace('#^/api(?:/v\d+)?#', '', $path), '/');
    require $root . '/api/index.php';
    return true;
}

return false;
