<?php
/* =========================================================
   Сборка приложения
   ========================================================= */

class Config
{
    private static $data;

    public static function load($path)
    {
        self::$data = is_file($path) ? require $path : array();
        if (!is_array(self::$data)) {
            self::$data = array();
        }
    }

    public static function get($key, $default = null)
    {
        if (self::$data === null) {
            return $default;
        }
        return isset(self::$data[$key]) && self::$data[$key] !== null ? self::$data[$key] : $default;
    }

    public static function set($key, $value)
    {
        self::$data[$key] = $value;
    }
}


class Settings
{
    private static $cache;

    /** Значение из таблицы настроек. */
    public static function get($name, $default = null)
    {
        if (self::$cache === null) {
            self::$cache = array();
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                self::$cache[$row['name']] = $row['value'];
            }
        }
        return array_key_exists($name, self::$cache) ? self::$cache[$name] : $default;
    }

    public static function num($name, $default = 0)
    {
        return (float) self::get($name, $default);
    }

    public static function forget()
    {
        self::$cache = null;
    }
}


class App
{
    private static $request;
    private static $router;

    public static function request()
    {
        return self::$request;
    }

    public static function router()
    {
        return self::$router;
    }

    /** Готовит окружение: настройки, обработчики ошибок, маршруты. */
    public static function boot($root)
    {
        Config::load($root . '/config.php');

        date_default_timezone_set('Europe/Moscow');
        mb_internal_encoding('UTF-8');

        // Подробности ошибок — в лог, наружу только общий ответ:
        // текст исключения может содержать части запроса
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        self::$request = new Request();
        self::$router  = new Router();

        foreach (glob(__DIR__ . '/../modules/*.php') as $module) {
            $register = require $module;
            if (is_callable($register)) {
                $register(self::$router);
            }
        }

        return self::$router;
    }

    /** Выполняет запрос и отдаёт ответ. */
    public static function run()
    {
        try {
            self::$router->dispatch(self::$request);
            // Обработчик обязан ответить сам; сюда попадаем,
            // только если он этого не сделал
            Response::ok(array('ok' => true));
        } catch (ApiError $error) {
            Db::rollback();
            Response::fail($error);
        } catch (PDOException $error) {
            Db::rollback();
            error_log('[Империя льда] База: ' . $error->getMessage());
            Response::fail(new ApiError(500, 'server_error',
                'Сбой на сервере. Попробуйте ещё раз или сообщите администратору'));
        } catch (Throwable $error) {
            Db::rollback();
            error_log('[Империя льда] ' . get_class($error) . ': ' . $error->getMessage()
                    . ' в ' . $error->getFile() . ':' . $error->getLine());
            Response::fail(new ApiError(500, 'server_error',
                'Сбой на сервере. Попробуйте ещё раз или сообщите администратору'));
        }
    }
}
