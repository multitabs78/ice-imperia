<?php
/* =========================================================
   Маршрутизатор
   ---------------------------------------------------------
   Адреса вида /athletes/{id}/attendance. Числовые части
   пути приходят в обработчик отдельным массивом.
   ========================================================= */

class Router
{
    private $routes = array();

    public function get($path, $handler)    { $this->add('GET', $path, $handler); }
    public function post($path, $handler)   { $this->add('POST', $path, $handler); }
    public function patch($path, $handler)  { $this->add('PATCH', $path, $handler); }
    public function delete($path, $handler) { $this->add('DELETE', $path, $handler); }

    public function add($method, $path, $handler)
    {
        $this->routes[] = array(
            'method'  => $method,
            'pattern' => $this->compile($path),
            'path'    => $path,
            'handler' => $handler,
        );
    }

    /** '/athletes/{id}' → регулярное выражение с именованной частью */
    private function compile($path)
    {
        $quoted = preg_quote($path, '#');
        // preg_quote экранирует фигурные скобки не во всех версиях PHP
        $quoted = str_replace(array('\{', '\}'), array('{', '}'), $quoted);
        $regex  = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $quoted);
        return '#^' . $regex . '$#u';
    }

    /**
     * Находит обработчик. Если путь есть, а метод другой —
     * это 405, а не 404: так понятнее, где ошибка.
     */
    public function dispatch(Request $request)
    {
        $pathExists = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $m)) {
                continue;
            }
            $pathExists = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = array();
            foreach ($m as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }
            return call_user_func($route['handler'], $request, $params);
        }

        if ($pathExists) {
            throw new ApiError(405, 'method_not_allowed',
                'Метод ' . $request->method . ' для этого адреса не поддерживается');
        }
        not_found('Адрес ' . $request->path . ' не найден');
    }

    /** Список адресов — для страницы состояния. */
    public function routes()
    {
        $out = array();
        foreach ($this->routes as $route) {
            $out[] = $route['method'] . ' ' . $route['path'];
        }
        sort($out);
        return $out;
    }
}
