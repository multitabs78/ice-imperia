<?php
/* =========================================================
   Империя льда — создание первого администратора
   ---------------------------------------------------------
   Открывается в браузере:

     https://ice-imperia.ru/db/seed-admin.php?key=КЛЮЧ

   Ключ — тот же migrate_key из config.php.

   Нужна ровно один раз: пока в базе нет ни одного
   администратора, войти в кабинет некому, а создать его
   из самого кабинета нельзя.

   Если администратор уже есть, страница ничего не делает
   и так и говорит. Удалять файл после использования
   не обязательно — он сам себя не повторит, — но и держать
   его на сервере незачем.
   ========================================================= */

header('Content-Type: text/html; charset=utf-8');

$root   = dirname(__DIR__);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : array();
if (!is_array($config)) {
    $config = array();
}

$key   = isset($config['migrate_key']) ? (string) $config['migrate_key'] : '';
$given = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(404);
    exit('Not found');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$db      = isset($config['db']) && is_array($config['db']) ? $config['db'] : array();
$done    = null;
$error   = null;
$exists  = false;
$form    = array('last_name' => '', 'first_name' => '', 'middle_name' => '',
                 'email' => '', 'phone' => '');

try {
    foreach (array('host', 'name', 'user') as $field) {
        if (empty($db[$field])) {
            throw new RuntimeException('В config.php не заполнен доступ к базе: поле db.' . $field);
        }
    }

    $dsn = 'mysql:host=' . $db['host']
         . (empty($db['port']) ? '' : ';port=' . (int) $db['port'])
         . ';dbname=' . $db['name'] . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $db['user'], isset($db['pass']) ? $db['pass'] : '', array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));

    $exists = (bool) $pdo->query("SELECT 1 FROM user_roles WHERE role = 'admin' LIMIT 1")
                         ->fetchColumn();

    if (!$exists && $_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($form as $field => $_) {
            $form[$field] = isset($_POST[$field]) ? trim((string) $_POST[$field]) : '';
        }
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        if ($form['last_name'] === '' || $form['first_name'] === '') {
            throw new RuntimeException('Заполните фамилию и имя');
        }
        if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Проверьте адрес почты: по нему вы будете входить');
        }
        if (mb_strlen($password) < 8) {
            throw new RuntimeException('Пароль не короче восьми символов');
        }

        $phone = preg_replace('/\D+/', '', $form['phone']);
        if ($phone !== '') {
            if (strlen($phone) === 11 && $phone[0] === '8') {
                $phone = '7' . substr($phone, 1);
            }
            if (strlen($phone) !== 11) {
                throw new RuntimeException('Телефон из 11 цифр или оставьте поле пустым');
            }
            $phone = '+' . $phone;
        } else {
            $phone = null;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO users (last_name, first_name, middle_name, phone, email,
                                password_hash, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $form['last_name'], $form['first_name'],
            $form['middle_name'] !== '' ? $form['middle_name'] : null,
            $phone, mb_strtolower($form['email']),
            password_hash($password, PASSWORD_DEFAULT),
            'active', date('Y-m-d H:i:s'),
        ));
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')
            ->execute(array($userId, 'admin'));

        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(array($userId, 'admin_created', 'user', $userId, date('Y-m-d H:i:s')));

        $pdo->commit();

        $done   = mb_strtolower($form['email']);
        $exists = true;
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Первый администратор — Империя льда</title>
<style>
  :root{ --ink:#2C2416; --sand:#F8F5F0; --blue:#0095BA; --ok:#2f7d32;
         --bad:#b3261e; --mute:#6b6356; }
  *{box-sizing:border-box}
  body{margin:0;padding:32px 20px 64px;background:var(--sand);color:var(--ink);
       font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:560px;margin:0 auto}
  h1{font-size:26px;margin:0 0 6px;font-weight:600}
  .sub{margin:0 0 28px;color:var(--mute)}
  .card{background:#fff;border-radius:14px;padding:24px;margin:0 0 20px;
        box-shadow:0 1px 2px rgba(44,36,22,.06),0 8px 24px rgba(44,36,22,.05)}
  label{display:block;margin:0 0 14px}
  label span{display:block;font-size:14px;color:var(--mute);margin:0 0 5px}
  input{width:100%;padding:11px 13px;border:1px solid #ddd6c9;border-radius:9px;
        font:inherit;background:#fff;color:inherit}
  input:focus{outline:2px solid var(--blue);outline-offset:1px;border-color:transparent}
  .row{display:flex;gap:12px}
  .row label{flex:1}
  button{width:100%;padding:13px;border:0;border-radius:9px;background:var(--blue);
         color:#fff;font:inherit;font-weight:600;cursor:pointer}
  button:hover{filter:brightness(1.07)}
  .msg{border-radius:10px;padding:14px 16px;margin:0 0 18px}
  .msg--ok{background:#e8f3e9;color:var(--ok)}
  .msg--bad{background:#fdeae8;color:var(--bad)}
  .hint{color:var(--mute);font-size:15px}
  code{font:14px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
</style>
</head>
<body>
<div class="wrap">

  <h1>Первый администратор</h1>
  <p class="sub">Учётная запись, с которой начинается работа кабинета.
     Остальных пользователей заводят уже из него.</p>

  <?php if ($error !== null): ?>
    <div class="msg msg--bad"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($done !== null): ?>
    <div class="card">
      <div class="msg msg--ok">Администратор создан.</div>
      <p>Входите на <a href="/cabinet/">/cabinet/</a> по адресу <code><?= e($done) ?></code>
         и паролю, который сейчас задали.</p>
      <p class="hint">Этот файл больше не нужен — можете удалить его с хостинга.</p>
    </div>
  <?php elseif ($exists): ?>
    <div class="card">
      <p>Администратор в базе уже есть, второго эта страница не создаёт.</p>
      <p class="hint">Если доступ потерян, заведите нового пользователя через phpMyAdmin
         или удалите роль <code>admin</code> у прежнего и обновите эту страницу.</p>
    </div>
  <?php elseif ($error === null || strpos($error, 'config.php') === false): ?>
    <form class="card" method="post">
      <div class="row">
        <label><span>Фамилия</span>
          <input name="last_name" value="<?= e($form['last_name']) ?>" required></label>
        <label><span>Имя</span>
          <input name="first_name" value="<?= e($form['first_name']) ?>" required></label>
      </div>
      <label><span>Отчество, если есть</span>
        <input name="middle_name" value="<?= e($form['middle_name']) ?>"></label>
      <label><span>Почта — по ней вход</span>
        <input type="email" name="email" value="<?= e($form['email']) ?>" required></label>
      <label><span>Телефон, если нужен</span>
        <input name="phone" value="<?= e($form['phone']) ?>" placeholder="+7 999 000 00 00"></label>
      <label><span>Пароль, не короче восьми символов</span>
        <input type="password" name="password" minlength="8" required></label>
      <button type="submit">Создать администратора</button>
    </form>
  <?php endif; ?>

</div>
</body>
</html>
