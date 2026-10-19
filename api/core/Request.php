<?php
/* =========================================================
   Разбор входящего запроса
   ========================================================= */

class Request
{
    public $method;
    public $path;      // '/athletes/31' без префикса /api/v1
    public $query;
    public $body;      // разобранный JSON или данные формы
    public $ip;
    public $userAgent;

    public function __construct()
    {
        $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $this->query  = $_GET;
        $this->ip     = self::clientIp();
        $this->userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $this->path = $this->resolvePath();
        $this->body = $this->readBody();
    }

    /**
     * Адрес без префикса версии. Работает и когда сервер
     * передаёт путь целиком, и когда переписывает его
     * в ?route=… правилом из .htaccess.
     */
    private function resolvePath()
    {
        if (isset($_GET['route'])) {
            $path = '/' . trim((string) $_GET['route'], '/');
            unset($this->query['route']);
        } else {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path = preg_replace('#^.*?/api(?:/v\d+)?#', '', (string) $path);
            $path = '/' . trim((string) $path, '/');
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function readBody()
    {
        if ($this->method === 'GET' || $this->method === 'DELETE') {
            return array();
        }

        $type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
        $raw  = file_get_contents('php://input');

        if (strpos($type, 'application/json') !== false) {
            if (trim((string) $raw) === '') {
                return array();
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                bad_request('Тело запроса не разобрано как JSON');
            }
            return $data;
        }

        // Формы поддерживаем ради простых случаев и отладки
        return $_POST;
    }

    /** Значение из тела или строки запроса. */
    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }
        return $default;
    }

    /** Токен из заголовка или из cookie. */
    public function token()
    {
        $header = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Часть хостингов не пробрасывает заголовок напрямую
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if ($header && preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return $m[1];
        }
        if (isset($_COOKIE['cab_token'])) {
            return (string) $_COOKIE['cab_token'];
        }
        return null;
    }

    private static function clientIp()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return mb_substr((string) $ip, 0, 45);
    }
}
