/* =========================================================
   Империя льда — ретранслятор заявок на Google Apps Script
   ---------------------------------------------------------
   Зачем: хостинг не может соединиться с api.telegram.org напрямую.
   Сайт отправляет заявку сюда, а этот скрипт пересылает её в Telegram.
   Дополнительно каждая заявка может записываться в Google Таблицу.

   ---------------------------------------------------------
   КАК РАЗВЕРНУТЬ (10 минут, бесплатно)
   ---------------------------------------------------------
   1. Откройте script.google.com → «Новый проект».
      Дайте имя, например «Империя льда — заявки».

   2. Удалите содержимое файла Code.gs и вставьте весь этот код.

   3. Слева «Настройки проекта» (шестерёнка) → «Свойства скрипта»
      → «Добавить свойство». Создайте:

         BOT_TOKEN   — токен бота от @BotFather
         CHAT_ID     — id чата, например -1001234567890
         RELAY_KEY   — любой придуманный пароль, например ImperiaLda2026xY
         SHEET_ID    — необязательно: id Google Таблицы для журнала заявок

      Нажмите «Сохранить свойства скрипта».

   4. Кнопка «Начать развёртывание» → «Новое развёртывание».
      Тип: «Веб-приложение».
         Запуск от имени: «Я»
         У кого есть доступ: «Все»          ← обязательно, иначе сайт не достучится
      Нажмите «Развернуть», разрешите доступ к аккаунту.

   5. Скопируйте «URL веб-приложения» (вида
      https://script.google.com/macros/s/AKfyc.../exec)
      и впишите в send.php:

         $relay_url = 'сюда адрес';
         $relay_key = 'тот же пароль, что в RELAY_KEY';

   6. Проверьте: откройте адрес веб-приложения в браузере.
      Должно появиться {"ok":true,"service":"relay"}.

   Где брать SHEET_ID: создайте таблицу, возьмите часть адреса между
   /d/ и /edit — это и есть id. Дайте скрипту доступ при первом запуске.

   ВАЖНО: после любой правки кода нужно заново нажать
   «Развернуть» → «Управление развёртываниями» → изменить версию,
   иначе сайт будет обращаться к старой версии.
   ========================================================= */

var props = PropertiesService.getScriptProperties();


/** Проверка из браузера: открыли адрес — увидели, что ретранслятор жив. */
function doGet() {
  return reply({ ok: true, service: 'relay' });
}


/** Приём заявки с сайта. */
function doPost(e) {
  var params = (e && e.parameter) || {};

  // Проверка пароля: без него ретранслятором мог бы пользоваться кто угодно
  var key = props.getProperty('RELAY_KEY');
  if (key && params.key !== key) {
    return reply({ ok: false, description: 'Неверный ключ' });
  }

  // Пинг из send.php?selftest=1 — просто проверка связи, ничего не отправляем
  if (params.ping) {
    return reply({ ok: true, ping: true });
  }

  var text = (params.text || '').toString().slice(0, 3000);
  if (!text.trim()) {
    return reply({ ok: false, description: 'Пустая заявка' });
  }

  var token = props.getProperty('BOT_TOKEN');
  var chatId = params.chat_id || props.getProperty('CHAT_ID');

  if (!token || !chatId) {
    return reply({ ok: false, description: 'Не заданы BOT_TOKEN или CHAT_ID' });
  }

  // Журнал в таблице — необязательно, но удобно: заявки не потеряются,
  // даже если Telegram в этот момент не ответит
  logToSheet(params, text);

  var result;
  try {
    var response = UrlFetchApp.fetch(
      'https://api.telegram.org/bot' + token + '/sendMessage',
      {
        method: 'post',
        payload: {
          chat_id: chatId,
          parse_mode: params.parse_mode || 'HTML',
          text: text
        },
        muteHttpExceptions: true
      }
    );
    result = JSON.parse(response.getContentText());
  } catch (err) {
    return reply({ ok: false, description: 'Ошибка соединения: ' + err });
  }

  return reply(result);
}


/** Записывает заявку в Google Таблицу, если указан SHEET_ID. */
function logToSheet(params, text) {
  var sheetId = props.getProperty('SHEET_ID');
  if (!sheetId) return;

  try {
    var sheet = SpreadsheetApp.openById(sheetId).getSheets()[0];

    // Шапка создаётся один раз, при первой заявке
    if (sheet.getLastRow() === 0) {
      sheet.appendRow(['Дата', 'Группа', 'Родитель', 'Ребёнок', 'Возраст', 'Телефон', 'Комментарий', 'Текст']);
      sheet.getRange(1, 1, 1, 8).setFontWeight('bold');
    }

    sheet.appendRow([
      new Date(),
      params.group || '',
      params.parent || '',
      params.child || '',
      params.age || '',
      params.phone || '',
      params.comment || '',
      text.replace(/<[^>]+>/g, '')
    ]);
  } catch (err) {
    // Журнал не должен мешать отправке заявки
    console.error('Не удалось записать в таблицу: ' + err);
  }
}


function reply(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
