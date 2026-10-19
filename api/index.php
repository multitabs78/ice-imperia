<?php
/* =========================================================
   Империя льда — API личного кабинета
   ---------------------------------------------------------
   Единственный вход в систему. Кабинет в браузере и будущие
   мобильные приложения обращаются сюда: своей логики у них
   нет, правила живут здесь.

   Адреса: /api/v1/…  Описание — docs/cabinet/api.md
   ========================================================= */

// require_once, а не require: тестовая обвязка загружает ядро
// заранее, чтобы подменить соединение с базой
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Request.php';
require_once __DIR__ . '/core/App.php';
require_once __DIR__ . '/core/Db.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Validate.php';
require_once __DIR__ . '/core/Repo.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Audit.php';
require_once __DIR__ . '/core/Notify.php';

App::boot(dirname(__DIR__));
App::run();
