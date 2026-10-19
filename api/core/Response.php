<?php
/* =========================================================
   Ответы API
   ---------------------------------------------------------
   Один формат на все случаи: кабинет и приложения разбирают
   ошибку по коду, а человеку показывают message.
   ========================================================= */

class ApiError extends Exception
{
    public $error;
    public $fields;

    public function __construct($status, $error, $message, $fields = array())
    {
        parent::__construct($message, $status);
        $this->error  = $error;
        $this->fields = $fields;
    }
}

class Response
{
    /** Удачный ответ. */
    public static function ok($data = array(), $status = 200)
    {
        self::send($status, $data);
    }

    /** Список с постраничной разбивкой. */
    public static function items($items, $total = null, $limit = null, $offset = 0)
    {
        self::send(200, array(
            'items'  => array_values($items),
            'total'  => $total === null ? count($items) : (int) $total,
            'limit'  => $limit,
            'offset' => (int) $offset,
        ));
    }

    /** Ошибка. Бросается через ApiError, сюда приходит уже разобранной. */
    public static function fail(ApiError $error)
    {
        $body = array(
            'error'   => $error->error,
            'message' => $error->getMessage(),
        );
        if ($error->fields) {
            $body['fields'] = $error->fields;
        }
        self::send($error->getCode() ?: 500, $body);
    }

    private static function send($status, $body)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            // Ответы API не кешируются: в них личные данные
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}


/* --- Короткие способы бросить ошибку --------------------- */

function bad_request($message = 'Запрос не разобран')
{
    throw new ApiError(400, 'bad_request', $message);
}

function unauthorized($message = 'Нужно войти')
{
    throw new ApiError(401, 'unauthorized', $message);
}

function forbidden($message = 'Нет доступа')
{
    throw new ApiError(403, 'forbidden', $message);
}

function not_found($message = 'Не найдено')
{
    throw new ApiError(404, 'not_found', $message);
}

function conflict($error, $message)
{
    throw new ApiError(409, $error, $message);
}

function invalid($fields, $message = 'Проверьте заполненные поля')
{
    throw new ApiError(422, 'validation_failed', $message, $fields);
}

function too_many($message = 'Слишком часто. Попробуйте позже')
{
    throw new ApiError(429, 'too_many_requests', $message);
}
