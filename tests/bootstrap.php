<?php
/* =========================================================
   Подкладывается перед каждым запросом тестового сервера
   ---------------------------------------------------------
   Загружает ядро и подменяет соединение с базой на SQLite.
   В рабочем коде ничего для этого делать не нужно: Db::use_pdo
   существует ровно для такой подмены.

   Запускается так (это делает tests/run.php):
     php -S 127.0.0.1:8900 -t . -d auto_prepend_file=tests/bootstrap.php
   ========================================================= */

$root = dirname(__DIR__);

require_once $root . '/api/core/Response.php';
require_once $root . '/api/core/Request.php';
require_once $root . '/api/core/App.php';
require_once $root . '/api/core/Db.php';
require_once $root . '/api/core/Router.php';
require_once $root . '/api/core/Validate.php';
require_once $root . '/api/core/Auth.php';
require_once $root . '/api/core/Audit.php';
require_once $root . '/api/core/Notify.php';

date_default_timezone_set('Europe/Moscow');

$path = getenv('IMPERIA_TEST_DB');
if ($path) {
    $pdo = new PDO('sqlite:' . $path, null, null, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    $pdo->exec('PRAGMA foreign_keys = ON');
    Db::use_pdo($pdo);
}
