<?php
/* =========================================================
   Империя льда — приём заявки и отправка в Telegram
   ---------------------------------------------------------
   Файл кладётся в корень сайта и работает на любом хостинге
   с PHP 7+. Настройки лежат в config.php рядом — на сервере,
   и никогда не попадают в браузер посетителя.
   ========================================================= */

/* ---------------------------------------------------------
   1. НАСТРОЙКИ

   Все настройки лежат в отдельном файле config.php рядом с этим.
   Если его нет — скопируйте config.sample.php в config.php
   и заполните. В репозиторий config.php не попадает: в нём
   ключи и адреса, которым не место в истории версий.

   ВАЖНО, ПРОВЕРЕНО НА ЭТОМ ХОСТИНГЕ:
   прямое соединение с api.telegram.org блокируется (Connection timed out),
   поэтому заявки идут через ретранслятор. Токен бота хранится в свойствах
   Google Apps Script (см. docs/telegram-relay.gs), а не здесь.

   Проверка связи:  https://ice-imperia.ru/send.php?selftest=1
   Тестовое сообщение в чат и на почту:
                    https://ice-imperia.ru/send.php?selftest=send
   После настройки блок selftest из этого файла можно удалить.
   --------------------------------------------------------- */

$config_path = __DIR__ . '/config.php';
$config      = is_file($config_path) ? require $config_path : array();
if (!is_array($config)) {
    $config = array();
}

function setting($key, $default = '') {
    global $config;
    return (isset($config[$key]) && $config[$key] !== null) ? $config[$key] : $default;
}

$token      = (string) setting('token');
$chat_id    = (string) setting('chat_id');
$site_name  = (string) setting('site_name', 'Империя льда');
$email_to   = (string) setting('email_to');
$email_from = (string) setting('email_from');
$relay_url  = (string) setting('relay_url');
$relay_key  = (string) setting('relay_key');
$proxy      = (string) setting('proxy');

$config_missing = !is_file($config_path);


/* ---------------------------------------------------------
   2. Служебное
   --------------------------------------------------------- */

header('Content-Type: application/json; charset=utf-8');

function respond($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function field($key, $limit = 200) {
    $value = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    return mb_substr($value, 0, $limit);
}

/* ---------------------------------------------------------
   Проверка связи: откройте в браузере https://ваш-сайт/send.php?selftest=1
   Покажет, доступен ли Telegram с хостинга. Токен не выводится.
   После настройки эту строку можно удалить.
   --------------------------------------------------------- */
if (isset($_GET['selftest'])) {
    $report = [
        'config.php'      => $config_missing
                             ? 'НЕ НАЙДЕН — скопируйте config.sample.php в config.php и заполните'
                             : 'на месте',
        'curl'            => function_exists('curl_init'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
    ];

    // Прямая связь с Telegram — проверяем, только если токен указан на сайте
    if (strpos($token, ':') !== false) {
        $direct = tg_request('https://api.telegram.org/bot' . $token . '/getMe', []);
        $info   = json_decode($direct['body'], true);
        $report['telegram_напрямую'] = [
            'способ'            => $direct['via'],
            'http_код'          => $direct['code'],
            'ошибка_соединения' => $direct['error'],
            'ответ'             => is_array($info) ? $info : $direct['body'],
        ];
    } else {
        $report['telegram_напрямую'] = 'токен на сайте не указан — заявки идут через ретранслятор';
    }

    // Связь через ретранслятор, если он настроен
    if ($relay_url !== '') {
        $ping = tg_request($relay_url, ['ping' => 1, 'key' => $relay_key]);
        $pong = json_decode($ping['body'], true);
        $report['ретранслятор'] = [
            'адрес'             => $relay_url,
            'http_код'          => $ping['code'],
            'ошибка_соединения' => $ping['error'],
            'ответ'             => is_array($pong) ? $pong : $ping['body'],
        ];
    } else {
        $report['ретранслятор'] = 'не настроен';
    }

    // ?selftest=send — отправляет настоящее тестовое сообщение тем же путём,
    // что и обычная заявка. Так видно, доходит ли она до чата.
    if ($_GET['selftest'] === 'send') {
        $probe = ['parse_mode' => 'HTML', 'text' => '<b>Проверка связи</b>' . "\n" . 'Сообщение отправлено со страницы send.php?selftest=send'];
        if ($chat_id !== '') $probe['chat_id'] = $chat_id;
        if ($relay_url !== '') { $probe['key'] = $relay_key; $where = $relay_url; }
        else { $where = 'https://api.telegram.org/bot' . $token . '/sendMessage'; }

        $try = tg_request($where, $probe);
        $res = json_decode($try['body'], true);

        // Расшифровка частых ответов Telegram
        $hint = '';
        $why = is_array($res) && isset($res['description']) ? $res['description'] : '';
        if (stripos($why, 'unauthorized') !== false) {
            $hint = 'Токен бота недействителен. Если заявки идут через ретранслятор, '
                  . 'токен берётся из свойства BOT_TOKEN в Apps Script, а не из config.php. '
                  . 'Проверьте, что там свежий токен от @BotFather, целиком и без пробелов.';
        } elseif (stripos($why, 'chat not found') !== false) {
            $hint = 'Чат не найден: бот не добавлен в группу либо указан неверный chat_id.';
        } elseif (stripos($why, 'not enough rights') !== false || stripos($why, 'blocked') !== false) {
            $hint = 'Бот в чате есть, но не может писать: дайте ему права администратора.';
        } elseif (stripos($why, 'ключ') !== false) {
            $hint = 'Ретранслятор отклонил запрос: relay_key в config.php не совпадает с RELAY_KEY в Apps Script.';
        }
        $report['тестовое_сообщение'] = [
            'куда'              => $relay_url !== '' ? 'через ретранслятор' : 'напрямую в Telegram',
            'http_код'          => $try['code'],
            'ошибка_соединения' => $try['error'],
            'ответ'             => is_array($res) ? $res : $try['body'],
            'итог'              => (is_array($res) && !empty($res['ok']))
                                   ? 'сообщение доставлено, проверьте чат'
                                   : 'НЕ доставлено, смотрите ответ выше',
        ];
        if ($hint !== '') $report['тестовое_сообщение']['подсказка'] = $hint;

        // Заодно проверяем запасной канал — письмо
        if ($email_to !== '') {
            $sent = send_mail_lead($email_to, $email_from, 'Проверка связи — ' . $site_name,
                                   'Тестовое сообщение со страницы send.php?selftest=send');
            $report['тестовое_письмо'] = [
                'куда' => $email_to,
                'итог' => $sent ? 'функция mail() приняла письмо, проверьте почту и папку «Спам»'
                                : 'mail() вернула отказ — на хостинге может быть отключена отправка почты',
            ];
        }
    }

    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Метод не поддерживается', 405);
}

// Настройки не заполнены — сразу говорим об этом в лог, а не молча падаем.
// При работе через ретранслятор токен и chat_id на сайте не требуются.
if ($config_missing) {
    error_log('[Империя льда] Нет файла config.php — скопируйте config.sample.php в config.php и заполните');
    respond(false, 'Форма не настроена', 500);
}
if ($relay_url === '' && (strpos($token, ':') === false || $chat_id === '')) {
    error_log('[Империя льда] В config.php не заполнены relay_url либо token и chat_id');
    respond(false, 'Форма не настроена', 500);
}


/* ---------------------------------------------------------
   3. Проверка данных (дублирует проверку в браузере)
   --------------------------------------------------------- */

// Скрытое поле-ловушка: люди его не видят и не заполняют, боты заполняют
if (field('company') !== '') {
    respond(true, 'Заявка принята');
}

$parent = field('parent', 80);
$child  = field('child', 80);
$age    = field('age', 2);
$phone  = field('phone', 30);
$comment = field('comment', 500);
// Согласие проверяется независимо от браузера: пустое или «0» не принимается
$agree  = isset($_POST['agree']) && trim((string) $_POST['agree']) !== ''
          && trim((string) $_POST['agree']) !== '0';

$digits = preg_replace('/\D/', '', $phone);

/**
 * Отклоняет заявку и пишет в лог, какая проверка не прошла.
 * В лог попадают только имена полей и длины значений, без самих данных.
 */
function reject($message, $reason) {
    $names = array_keys($_POST);
    sort($names);
    error_log(sprintf(
        '[Империя льда] Заявка отклонена (%s). Пришли поля: %s',
        $reason,
        $names ? implode(', ', $names) : 'НИ ОДНОГО — тело запроса пустое'
    ));
    respond(false, $message, 422);
}

if (mb_strlen($parent) < 2) {
    reject('Проверьте имя родителя', 'имя родителя: ' . mb_strlen($parent) . ' симв.');
}
if (mb_strlen($child) < 2) {
    reject('Проверьте имя ребёнка', 'имя ребёнка: ' . mb_strlen($child) . ' симв.');
}
if (!ctype_digit($age) || (int) $age < 2 || (int) $age > 15) {
    reject('Проверьте возраст ребёнка', 'возраст: «' . $age . '»');
}
if (strlen($digits) !== 11) {
    reject('Проверьте номер телефона', 'цифр в телефоне: ' . strlen($digits));
}
if (!$agree) {
    reject('Нужно согласие на обработку персональных данных', 'нет отметки согласия');
}

// Простая защита от повторных отправок: не чаще одной заявки в 20 секунд
session_start();
if (isset($_SESSION['last_lead']) && (time() - $_SESSION['last_lead']) < 20) {
    respond(false, 'Заявка уже отправлена, подождите немного', 429);
}


/* ---------------------------------------------------------
   4. Сборка сообщения
   --------------------------------------------------------- */

$fields = [
    'Родитель'      => $parent,
    'Ребёнок'       => $child . ', ' . (int) $age . ' лет',
    'Телефон'       => '+' . $digits,
    'Отправлено'    => date('d.m.Y H:i'),
];

// Название группы приходит из карточки с ценами, если заявка отправлена оттуда
$group = field('group', 80);
if ($group !== '') {
    $fields = ['Группа' => $group] + $fields;
}

// Комментарий необязателен — добавляем только если он заполнен
if ($comment !== '') {
    $fields['Комментарий'] = $comment;
}

$text  = '<b>Заявка с сайта — ' . htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8') . "</b>\n\n";
$plain = 'Заявка с сайта — ' . $site_name . "\n\n";
foreach ($fields as $key => $value) {
    $text  .= '<b>' . $key . ':</b> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "\n";
    $plain .= $key . ': ' . $value . "\n";
}


/* ---------------------------------------------------------
   5. Отправка в Telegram
   --------------------------------------------------------- */

/**
 * Запрос к Telegram. Сначала пробуем cURL, при неудаче — потоковую обёртку.
 * Возвращаем не только ответ, но и причину сбоя, чтобы её было видно в логе.
 */
function tg_request($url, $payload) {
    global $proxy;
    $out = ['body' => '', 'error' => '', 'code' => 0, 'via' => ''];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ]);
        if ($proxy !== '') curl_setopt($ch, CURLOPT_PROXY, $proxy);
        $body = curl_exec($ch);
        $out['via']   = 'curl';
        $out['error'] = curl_error($ch);
        $out['code']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body !== false && $body !== '') {
            $out['body'] = $body;
            return $out;
        }
    }

    // Запасной путь, если cURL отсутствует или не смог соединиться
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query($payload),
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $out['via'] = $out['via'] ? $out['via'] . '+stream' : 'stream';
        if ($body !== false && $body !== '') {
            $out['body'] = $body;
            $out['error'] = '';
            return $out;
        }
        $last = error_get_last();
        if ($last && !$out['error']) $out['error'] = $last['message'];
    } elseif (!$out['via']) {
        $out['error'] = 'на хостинге нет ни cURL, ни allow_url_fopen';
    }

    return $out;
}

$url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

/**
 * Письмо на почту. Используется, когда Telegram с хостинга недоступен,
 * чтобы заявка не потерялась.
 */
function send_mail_lead($to, $from, $subject, $body) {
    if ($to === '') return false;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($from !== '') $headers .= 'From: ' . $from . "\r\n";
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subject, $body, $headers);
}

$payload = [
    'parse_mode' => 'HTML',
    'text'       => $text,
];
if ($chat_id !== '') $payload['chat_id'] = $chat_id;

// Ретранслятору дополнительно отдаём ключ и поля по отдельности:
// по ним он ведёт журнал заявок в Google Таблице
if ($relay_url !== '') {
    $payload['key']    = $relay_key;
    $payload['group']  = $group;
    $payload['parent'] = $parent;
    $payload['child']  = $child;
    $payload['age']    = $age;
    $payload['phone']  = '+' . $digits;
    $payload['comment'] = $comment;
}

// Если задан ретранслятор, идём через него: он перешлёт заявку в Telegram
$endpoint = $relay_url !== '' ? $relay_url : $url;

$sent   = tg_request($endpoint, $payload);
$result = $sent['body'] ? json_decode($sent['body'], true) : null;
$tg_ok  = is_array($result) && !empty($result['ok']);

if ($tg_ok) {
    $_SESSION['last_lead'] = time();
    respond(true, 'Заявка принята');
}

// Telegram не ответил — отправляем письмо, чтобы заявка не пропала
$mailed = send_mail_lead($email_to, $email_from, 'Заявка с сайта — ' . $site_name, $plain);

if ($mailed) {
    $_SESSION['last_lead'] = time();
    error_log('[Империя льда] Telegram недоступен, заявка ушла на почту ' . $email_to
        . '. Причина: ' . ($sent['error'] ?: 'пустой ответ'));
    respond(true, 'Заявка принята');
}

// Заявка не ушла — пишем в лог сервера, посетителю показываем телефон
$reason = is_array($result) && isset($result['description'])
    ? 'Telegram: ' . $result['description']
    : ($sent['error'] ? 'соединение: ' . $sent['error'] : 'пустой ответ');
error_log(sprintf(
    '[Империя льда] Заявка не ушла. Способ: %s, HTTP: %d, причина: %s, ответ: %s',
    $sent['via'] ?: 'нет', $sent['code'], $reason, substr((string) $sent['body'], 0, 300)
));
respond(false, 'Не получилось отправить заявку', 502);
