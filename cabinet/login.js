/* =========================================================
   Империя льда — вход в личный кабинет
   ---------------------------------------------------------
   Страница ничего не решает сама: все правила — на сервере,
   здесь только форма, переключение шагов и показ ответов.
   Проверки в браузере нужны для удобства, а не для защиты:
   те же проверки повторяет API.
   ========================================================= */

(function () {
  'use strict';

  var API = '/api/v1';

  // Куда уходить после входа. Пока кабинет не готов, остаёмся
  // на странице и показываем, кто вошёл: подменить на '/cabinet/'
  // достаточно будет здесь.
  var AFTER_LOGIN = null;

  var RESEND_SECONDS = 60;

  var steps = {};
  ['step-login', 'step-code', 'step-password', 'step-change', 'step-done']
    .forEach(function (id) { steps[id] = document.getElementById(id); });

  var state = { login: '', channel: 'sms', timer: null, left: 0 };

  /* --- Мелкие помощники ---------------------------------- */

  function $(id) { return document.getElementById(id); }

  function show(id) {
    Object.keys(steps).forEach(function (key) {
      steps[key].hidden = (key !== id);
    });
    // Фокус на первое поле — чтобы с клавиатуры можно было
    // пройти вход, не трогая мышь
    var first = steps[id].querySelector('input:not([type=radio]), button');
    if (first) {
      try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
    }
    hideAlerts();
  }

  function hideAlerts() {
    var list = document.querySelectorAll('.alert');
    for (var i = 0; i < list.length; i++) {
      list[i].hidden = true;
      list[i].className = 'alert';
    }
  }

  function say(id, text, calm) {
    var box = $(id);
    box.textContent = text;
    box.className = calm ? 'alert alert--calm' : 'alert';
    box.hidden = false;
  }

  function busy(button, on, labelWhenBusy) {
    if (on) {
      button.dataset.label = button.textContent;
      button.textContent = labelWhenBusy || 'Минуту…';
      button.disabled = true;
      button.classList.add('is-busy');
    } else {
      if (button.dataset.label) { button.textContent = button.dataset.label; }
      button.disabled = false;
      button.classList.remove('is-busy');
    }
  }

  /** Обращение к API. Возвращает объект со статусом и телом. */
  function api(path, body) {
    return fetch(API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (response) {
      return response.json()
        .catch(function () { return {}; })
        .then(function (data) {
          return { ok: response.ok, status: response.status, data: data };
        });
    }).catch(function () {
      return {
        ok: false,
        status: 0,
        data: { message: 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз' }
      };
    });
  }

  /** Текст ошибки: сначала по полям, потом общий. */
  function reason(answer) {
    var data = answer.data || {};
    if (data.fields) {
      for (var key in data.fields) {
        if (Object.prototype.hasOwnProperty.call(data.fields, key)) {
          return data.fields[key];
        }
      }
    }
    return data.message || 'Что-то пошло не так. Попробуйте ещё раз';
  }

  /* --- Подсказка по введённому логину --------------------- */

  var loginInput = $('login');
  var note = $('login-note');

  function looksLikeEmail(value) { return value.indexOf('@') !== -1; }
  function digitsOf(value) { return (value || '').replace(/\D+/g, ''); }

  /**
   * Каналы, которые имеют смысл для введённого логина.
   * СМС бессмысленны для почты, и наоборот — но Telegram
   * подходит всегда, если он привязан.
   */
  function syncChannels() {
    var value = loginInput.value.trim();
    var email = looksLikeEmail(value);
    var phone = digitsOf(value).length >= 10;

    var radios = document.querySelectorAll('input[name=channel]');
    for (var i = 0; i < radios.length; i++) {
      var radio = radios[i];
      var fits = radio.value === 'telegram'
              || (radio.value === 'sms' && (phone || !email && !value))
              || (radio.value === 'email' && (email || !phone && !value));
      radio.disabled = !fits;
      if (radio.checked && !fits) { radio.checked = false; }
    }

    if (!document.querySelector('input[name=channel]:checked')) {
      var fallback = document.querySelector('input[name=channel]:not([disabled])');
      if (fallback) { fallback.checked = true; }
    }

    if (value === '') {
      note.hidden = true;
    } else if (email) {
      note.textContent = 'Код придёт письмом на этот адрес';
      note.hidden = false;
    } else if (phone) {
      note.textContent = 'Телефон, который вы оставляли в школе';
      note.hidden = false;
    } else {
      note.hidden = true;
    }
  }

  loginInput.addEventListener('input', syncChannels);
  syncChannels();

  /* --- Переключение шагов по ссылкам ---------------------- */

  var jumps = document.querySelectorAll('[data-go]');
  for (var j = 0; j < jumps.length; j++) {
    jumps[j].addEventListener('click', function () {
      stopTimer();
      show(this.getAttribute('data-go'));
    });
  }

  /* --- Шаг 1: запрос кода --------------------------------- */

  $('form-code').addEventListener('submit', function (event) {
    event.preventDefault();
    hideAlerts();

    var value   = loginInput.value.trim();
    var checked = document.querySelector('input[name=channel]:checked');

    if (value === '') {
      say('alert-login', 'Введите телефон или почту');
      loginInput.focus();
      return;
    }
    if (!checked) {
      say('alert-login', 'Выберите, куда прислать код');
      return;
    }

    state.login   = value;
    state.channel = checked.value;

    requestCode($('btn-code'), 'alert-login');
  });

  function requestCode(button, alertId) {
    busy(button, true, 'Отправляем…');

    api('/auth/request-code', { login: state.login, channel: state.channel })
      .then(function (answer) {
        busy(button, false);

        if (answer.status === 409 && answer.data.error === 'telegram_not_linked') {
          say(alertId, 'Telegram ещё не привязан к кабинету. Напишите боту школы '
                     + 'или войдите по почте либо по СМС');
          return;
        }
        if (answer.status === 503) {
          say(alertId, reason(answer));
          return;
        }
        if (!answer.ok) {
          say(alertId, reason(answer));
          return;
        }

        var where = { sms: 'в СМС', email: 'на почту', telegram: 'в Telegram' }[state.channel];
        $('code-lede').textContent = answer.data.hint
          ? 'Шесть цифр отправлены ' + where + ': ' + answer.data.hint
          : 'Введите шесть цифр, отправленные ' + where + '.';

        $('code').value = '';
        show('step-code');
        startTimer();
      });
  }

  /* --- Повтор отправки с обратным отсчётом ---------------- */

  var resend = $('btn-resend');

  function startTimer() {
    state.left = RESEND_SECONDS;
    tick();
    stopTimer();
    state.timer = setInterval(tick, 1000);
  }

  function stopTimer() {
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
  }

  function tick() {
    if (state.left > 0) {
      resend.disabled = true;
      resend.textContent = 'Отправить код ещё раз — через ' + state.left + ' с';
      state.left--;
    } else {
      stopTimer();
      resend.disabled = false;
      resend.textContent = 'Отправить код ещё раз';
    }
  }

  resend.addEventListener('click', function () {
    hideAlerts();
    requestCode(resend, 'alert-code');
  });

  /* --- Шаг 2: проверка кода ------------------------------- */

  var codeInput = $('code');

  codeInput.addEventListener('input', function () {
    this.value = this.value.replace(/\D+/g, '').slice(0, 6);
    // Шесть цифр — отправляем сами: лишнее нажатие ни к чему
    if (this.value.length === 6) {
      $('form-verify').requestSubmit
        ? $('form-verify').requestSubmit()
        : $('btn-verify').click();
    }
  });

  $('form-verify').addEventListener('submit', function (event) {
    event.preventDefault();
    hideAlerts();

    if (codeInput.value.length !== 6) {
      say('alert-code', 'Код состоит из шести цифр');
      return;
    }

    var button = $('btn-verify');
    busy(button, true, 'Проверяем…');

    api('/auth/verify-code', { login: state.login, code: codeInput.value })
      .then(function (answer) {
        busy(button, false);
        if (!answer.ok) {
          say('alert-code', reason(answer));
          codeInput.value = '';
          codeInput.focus();
          return;
        }
        stopTimer();
        entered(answer.data);
      });
  });

  /* --- Вход по паролю ------------------------------------- */

  $('form-password').addEventListener('submit', function (event) {
    event.preventDefault();
    hideAlerts();

    var button = $('btn-login');
    busy(button, true, 'Проверяем…');

    api('/auth/login', {
      login: $('login2').value.trim(),
      password: $('password').value
    }).then(function (answer) {
      busy(button, false);
      if (!answer.ok) {
        say('alert-password', reason(answer));
        $('password').value = '';
        $('password').focus();
        return;
      }
      entered(answer.data);
    });
  });

  /* --- Смена пароля при первом входе ---------------------- */

  $('form-change').addEventListener('submit', function (event) {
    event.preventDefault();
    hideAlerts();

    if ($('fresh').value.length < 8) {
      say('alert-change', 'Новый пароль — не короче восьми символов');
      return;
    }

    var button = $('btn-change');
    busy(button, true, 'Сохраняем…');

    api('/auth/change-password', {
      current_password: $('current').value,
      new_password: $('fresh').value
    }).then(function (answer) {
      busy(button, false);
      if (!answer.ok) {
        say('alert-change', reason(answer));
        return;
      }
      $('current').value = '';
      $('fresh').value = '';
      finish();
    });
  });

  /* --- Вошли ---------------------------------------------- */

  var me = null;

  function entered(data) {
    me = data.user || null;

    if (me && me.must_change_password) {
      show('step-change');
      return;
    }
    finish();
  }

  function finish() {
    if (AFTER_LOGIN) {
      window.location.href = AFTER_LOGIN;
      return;
    }

    var names = {
      admin: 'Администратор', coach: 'Тренер',
      parent: 'Родитель', athlete: 'Спортсмен'
    };

    $('done-who').textContent = me && me.name ? me.name : '';

    var next = $('done-next');
    next.innerHTML = '';

    if (me && me.roles && me.roles.length) {
      var roles = document.createElement('ul');
      roles.className = 'done__roles';
      me.roles.forEach(function (role) {
        var item = document.createElement('li');
        item.textContent = names[role] || role;
        roles.appendChild(item);
      });
      next.appendChild(roles);
    }

    if (me && me.children && me.children.length) {
      var list = document.createElement('ul');
      list.className = 'done__children';
      me.children.forEach(function (child) {
        var item = document.createElement('li');
        item.textContent = child.name;
        list.appendChild(item);
      });
      next.appendChild(list);
    }

    // Класс свой, не .alert: сообщения об ошибках гасятся
    // при каждом переключении шага, а это должно остаться
    var about = document.createElement('p');
    about.className = 'notebox';
    about.textContent = 'Разделы кабинета появятся здесь по мере готовности.';
    next.appendChild(about);

    show('step-done');
  }

  $('btn-logout').addEventListener('click', function () {
    api('/auth/logout', {}).then(function () {
      me = null;
      loginInput.value = '';
      syncChannels();
      show('step-login');
    });
  });

  /* --- Если токен ещё жив, сразу показываем, кто вошёл ---- */

  fetch(API + '/me', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (data) {
      if (data && data.id) {
        me = data;
        if (data.must_change_password) { show('step-change'); } else { finish(); }
      }
    })
    .catch(function () { /* нет связи — просто показываем форму */ });

})();
