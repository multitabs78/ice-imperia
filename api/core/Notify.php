<?php
/* =========================================================
   Доставка сообщений
   ---------------------------------------------------------
   Три канала, одна точка входа. Коды входа отправляются
   сразу, уведомления — через очередь notifications, чтобы
   падение почтового сервера не роняло операцию.

   Telegram идёт через тот же ретранслятор на Google Apps
   Script, что и заявки с сайта: хостинг не пускает
   к api.telegram.org напрямую.
   ========================================================= */

class Notify
{
    /**
     * Отправляет сейчас. Возвращает массив с ok и причиной:
     * вызывающий решает, считать ли отказ ошибкой.
     */
    public static function send($channel, $to, $subject, $text)
    {
        switch ($channel) {
            case 'email':
                return self::email($to, $subject, $text);
            case 'telegram':
                return self::telegram($to, $text);
            case 'sms':
                return self::sms($to, $text);
        }
        return array('ok' => false, 'reason' => 'Неизвестный канал: ' . $channel);
    }

    private static function email($to, $subject, $text)
    {
        if (!$to) {
            return array('ok' => false, 'reason' => 'Нет адреса почты');
        }

        $from = Config::get('email_from', '');
        $headers = "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n";
        if ($from !== '') {
            $headers .= 'From: ' . Config::get('site_name', 'Империя льда') . ' <' . $from . ">\r\n";
        }

        $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $sent = @mail($to, $encoded, $text, $headers);

        return $sent
            ? array('ok' => true)
            : array('ok' => false, 'reason' => 'Почтовая служба хостинга отклонила письмо');
    }

    private static function telegram($chatId, $text)
    {
        if (!$chatId) {
            return array('ok' => false, 'reason' => 'Telegram не привязан');
        }

        $relay = Config::get('relay_url', '');
        if ($relay === '') {
            return array('ok' => false, 'reason' => 'Ретранслятор Telegram не настроен');
        }

        $answer = self::post($relay, array(
            'key'        => Config::get('relay_key', ''),
            'chat_id'    => $chatId,
            'parse_mode' => 'HTML',
            'text'       => $text,
        ));

        if (!$answer['ok']) {
            return $answer;
        }
        $data = json_decode($answer['body'], true);
        if (is_array($data) && !empty($data['ok'])) {
            return array('ok' => true);
        }
        $why = is_array($data) && isset($data['description']) ? $data['description'] : $answer['body'];
        return array('ok' => false, 'reason' => 'Telegram: ' . mb_substr((string) $why, 0, 200));
    }

    private static function sms($phone, $text)
    {
        // Канал предусмотрен, поставщик рассылки пока не выбран.
        // Когда выберут — здесь появится обращение к его адресу,
        // остальной код входа не меняется.
        return array(
            'ok'     => false,
            'reason' => 'Отправка СМС пока не подключена: не выбран сервис рассылки',
        );
    }

    private static function post($url, $fields)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => true,
            ));
            $body  = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return array('ok' => false, 'reason' => 'Нет связи: ' . $error);
            }
            return array('ok' => true, 'body' => $body);
        }

        $context = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($fields),
            'timeout'       => 10,
            'ignore_errors' => true,
        )));
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return array('ok' => false, 'reason' => 'Нет связи с ретранслятором');
        }
        return array('ok' => true, 'body' => $body);
    }

    /** Ставит уведомление в очередь. Отправит отдельный запуск. */
    public static function queue($userId, $type, $channel, $payload = array(), $sendAfter = null)
    {
        return Db::insert('notifications', array(
            'user_id'    => (int) $userId,
            'type'       => mb_substr($type, 0, 64),
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'channel'    => $channel,
            'status'     => 'queued',
            'send_after' => $sendAfter === null ? Db::now() : $sendAfter,
            'created_at' => Db::now(),
        ));
    }
}
