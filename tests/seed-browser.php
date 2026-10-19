<?php
/* =========================================================
   Тестовая база для проверки страницы входа браузером
   ---------------------------------------------------------
   Создаёт SQLite по той же миграции, что применяется
   на хостинге, и кладёт в неё администратора и родителя
   с ребёнком. Подробности — в tests/README.md
   ========================================================= */

require __DIR__ . '/sqlite.php';

date_default_timezone_set('Europe/Moscow');

$dir  = sys_get_temp_dir() . '/imperia-tests';
@mkdir($dir, 0777, true);
$path = $dir . '/login.sqlite';

$pdo = SqliteTestDb::build($path);
$now = date('Y-m-d H:i:s');

$pdo->prepare('INSERT INTO users (last_name, first_name, email, phone, password_hash, status, created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute(array('Стефанова', 'Анастасия', 'admin@example.test', '+79990000001',
                    password_hash('verysecret123', PASSWORD_DEFAULT), 'active', $now));
$admin = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)')->execute(array($admin, 'admin'));

$pdo->prepare('INSERT INTO users (last_name, first_name, email, phone, status, created_at)
               VALUES (?,?,?,?,?,?)')
    ->execute(array('Иванова', 'Мария', 'parent@example.test', '+79990000002', 'active', $now));
$parent = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?,?)')->execute(array($parent, 'parent'));

$pdo->prepare('INSERT INTO athletes (last_name, first_name, birth_date, status, created_at)
               VALUES (?,?,?,?,?)')
    ->execute(array('Иванов', 'Пётр', '2019-05-11', 'active', $now));
$child = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO parent_athlete (parent_user_id, athlete_id, relation, is_primary, created_at)
               VALUES (?,?,?,?,?)')
    ->execute(array($parent, $child, 'mother', 1, $now));

echo "База готова: " . $path . "\n";
echo "  администратор admin@example.test / verysecret123\n";
echo "  родитель parent@example.test, вход по коду\n";
