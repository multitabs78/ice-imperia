/* =========================================================
   Империя льда — кабинет
   ---------------------------------------------------------
   Один из клиентов API. Своих правил не содержит: что можно
   и что нельзя, решает сервер, страница лишь показывает
   ответы и умеет не спрашивать лишнего.
   ========================================================= */

(function () {
  'use strict';

  var API = '/api/v1';

  var me = null;
  var page = document.getElementById('page');
  var sheet = document.getElementById('sheet');
  var sheetForm = document.getElementById('sheet-form');

  /* =====================================================
     Мелкие помощники
     ===================================================== */

  /** Создание узла: el('td', {class:'num'}, '14') */
  function el(tag, props, kids) {
    var node = document.createElement(tag);

    if (props) {
      Object.keys(props).forEach(function (key) {
        var value = props[key];
        if (value === null || value === undefined || value === false) { return; }
        if (key === 'class') { node.className = value; }
        else if (key === 'text') { node.textContent = value; }
        else if (key === 'html') { node.innerHTML = value; }
        else if (key.indexOf('on') === 0) { node.addEventListener(key.slice(2), value); }
        else { node.setAttribute(key, value === true ? '' : value); }
      });
    }

    [].concat(kids === undefined ? [] : kids).forEach(function (kid) {
      if (kid === null || kid === undefined || kid === false) { return; }
      node.appendChild(typeof kid === 'object' ? kid : document.createTextNode(String(kid)));
    });

    return node;
  }

  function clear(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
    return node;
  }

  function toast(text, bad) {
    var old = document.querySelector('.toast');
    if (old) { old.remove(); }

    var box = el('div', { class: 'toast' + (bad ? ' toast--bad' : ''), role: 'status', text: text });
    document.body.appendChild(box);
    setTimeout(function () { box.remove(); }, bad ? 6000 : 3000);
  }

  /** Обращение к API. 401 означает, что пора на страницу входа. */
  function api(method, path, body) {
    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    };
    if (body !== undefined && body !== null) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }

    return fetch(API + path, init).then(function (response) {
      if (response.status === 401) {
        window.location.href = '/login';
        return { ok: false, status: 401, data: {} };
      }
      return response.json().catch(function () { return {}; }).then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    }).catch(function () {
      return { ok: false, status: 0, data: { message: 'Нет связи с сервером' } };
    });
  }

  function reason(answer) {
    var data = answer.data || {};
    if (data.fields) {
      for (var key in data.fields) {
        if (Object.prototype.hasOwnProperty.call(data.fields, key)) { return data.fields[key]; }
      }
    }
    return data.message || 'Не получилось. Попробуйте ещё раз';
  }

  function plural(n, one, few, many) {
    var mod = Math.abs(n) % 100;
    var last = mod % 10;
    if (mod > 10 && mod < 20) { return many; }
    if (last > 1 && last < 5) { return few; }
    if (last === 1) { return one; }
    return many;
  }

  function money(value) {
    var n = Math.round(parseFloat(value || 0));
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
  }

  function dateRu(value) {
    if (!value) { return '—'; }
    var p = String(value).split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : value;
  }

  /* =====================================================
     Форма в модальном окне
     ===================================================== */

  function openSheet(options) {
    clear(sheetForm);

    sheetForm.appendChild(el('h2', { text: options.title }));
    if (options.note) {
      sheetForm.appendChild(el('p', { class: 'sheet__note', text: options.note }));
    }

    var alert = el('p', { class: 'alert', hidden: true, role: 'alert' });
    sheetForm.appendChild(alert);

    var inputs = {};

    (options.fields || []).forEach(function (field) {
      var id = 'f-' + field.name;
      var box = el('div', { class: 'field' });
      box.appendChild(el('label', { for: id, text: field.label }));

      var input;
      if (field.type === 'select') {
        input = el('select', { id: id, name: field.name });
        (field.options || []).forEach(function (option) {
          input.appendChild(el('option', {
            value: option.value,
            selected: String(option.value) === String(field.value === undefined ? '' : field.value)
          }, option.label));
        });
        input.className = '';
      } else if (field.type === 'checkbox') {
        input = el('input', { id: id, name: field.name, type: 'checkbox', checked: !!field.value });
      } else {
        input = el('input', {
          id: id,
          name: field.name,
          type: field.type || 'text',
          value: field.value === null || field.value === undefined ? '' : field.value,
          placeholder: field.placeholder || null,
          autocomplete: field.autocomplete || 'off'
        });
      }

      box.appendChild(input);
      if (field.hint) { box.appendChild(el('p', { class: 'field__note', text: field.hint })); }
      sheetForm.appendChild(box);
      inputs[field.name] = input;
    });

    var submit = el('button', { class: 'btn btn--primary', type: 'submit' }, options.submit || 'Сохранить');
    var cancel = el('button', { class: 'btn btn--ghost', type: 'button' }, 'Отмена');
    cancel.addEventListener('click', function () { sheet.close(); });

    sheetForm.appendChild(el('div', { class: 'sheet__act' }, [cancel, submit]));

    // Поля полей формы — только select нуждается в выравнивании
    Object.keys(inputs).forEach(function (name) {
      var node = inputs[name];
      if (node.tagName === 'SELECT') {
        node.style.width = '100%';
        node.style.padding = '13px 14px';
        node.style.font = 'inherit';
        node.style.border = '1px solid var(--ink-12)';
        node.style.borderRadius = 'var(--r)';
        node.style.background = 'var(--white)';
      }
    });

    sheetForm.onsubmit = function (event) {
      event.preventDefault();
      alert.hidden = true;

      var values = {};
      Object.keys(inputs).forEach(function (name) {
        var node = inputs[name];
        values[name] = node.type === 'checkbox' ? node.checked : node.value.trim();
      });

      submit.disabled = true;
      var label = submit.textContent;
      submit.textContent = 'Сохраняем…';

      Promise.resolve(options.onSubmit(values)).then(function (answer) {
        submit.disabled = false;
        submit.textContent = label;

        if (answer && answer.ok === false) {
          alert.textContent = reason(answer);
          alert.hidden = false;
          return;
        }
        sheet.close();
      });
    };

    sheet.showModal();
    var first = sheetForm.querySelector('input, select');
    if (first) { first.focus(); }
  }

  /** Подтверждение опасного действия с обязательной причиной. */
  function confirmWithReason(options) {
    openSheet({
      title: options.title,
      note: options.note,
      submit: options.submit || 'Подтвердить',
      fields: [{ name: 'reason', label: 'Основание', placeholder: 'Коротко, для журнала' }],
      onSubmit: function (values) {
        if (!values.reason) {
          return { ok: false, data: { message: 'Без основания операция не сохраняется' } };
        }
        return options.onConfirm(values.reason);
      }
    });
  }

  /* =====================================================
     Оболочка
     ===================================================== */

  var TABS = [
    { id: 'athletes', title: 'Спортсмены', roles: ['admin', 'coach'] },
    { id: 'groups',   title: 'Группы',     roles: ['admin', 'coach'] },
    { id: 'schedule', title: 'Расписание', roles: ['admin', 'coach'] },
    { id: 'coaches',  title: 'Тренеры',    roles: ['admin'] },
    { id: 'parents',  title: 'Родители',   roles: ['admin'] },
    { id: 'tariffs',  title: 'Тарифы',     roles: ['admin'] },
    { id: 'services', title: 'Услуги',     roles: ['admin'] },
    { id: 'settings', title: 'Настройки',  roles: ['admin'] }
  ];

  function can(roles) {
    return me && me.roles && me.roles.some(function (role) { return roles.indexOf(role) !== -1; });
  }

  function drawTabs(active) {
    var row = clear(document.getElementById('tabs-row'));
    TABS.forEach(function (tab) {
      if (!can(tab.roles)) { return; }
      row.appendChild(el('a', {
        class: 'tab' + (tab.id === active ? ' is-on' : ''),
        href: '#/' + tab.id,
        'aria-current': tab.id === active ? 'page' : null
      }, tab.title));
    });
  }

  function heading(title, count, actions) {
    var head = el('div', { class: 'head' }, [el('h1', { text: title })]);
    if (count !== null && count !== undefined) {
      head.appendChild(el('span', { class: 'head__count', text: count }));
    }
    if (actions && actions.length) {
      head.appendChild(el('div', { class: 'head__act' }, actions));
    }
    return head;
  }

  function board(kids) {
    return el('div', { class: 'board' }, kids);
  }

  function table(columns, rows) {
    var head = el('tr', null, columns.map(function (c) {
      return el('th', { class: c.class || null }, c.title);
    }));
    return el('table', { class: 'rows' }, [
      el('thead', null, [head]),
      el('tbody', null, rows)
    ]);
  }

  function loading() {
    clear(page).appendChild(el('p', { class: 'loading', text: 'Загружаем…' }));
  }

  function backLink(to, text) {
    return el('a', { class: 'back', href: '#/' + to }, '← ' + text);
  }

  /* =====================================================
     Спортсмены
     ===================================================== */

  var athleteSearch = '';

  function screenAthletes() {
    drawTabs('athletes');
    loading();

    var query = athleteSearch ? '&q=' + encodeURIComponent(athleteSearch) : '';

    api('GET', '/athletes?limit=200' + query).then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      var search = el('input', {
        type: 'search',
        placeholder: 'Поиск по фамилии',
        value: athleteSearch,
        'aria-label': 'Поиск по фамилии'
      });
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        var value = this.value;
        timer = setTimeout(function () { athleteSearch = value; screenAthletes(); }, 350);
      });

      var actions = [el('div', { class: 'search' }, [search])];
      if (can(['admin'])) {
        actions.push(el('button', { class: 'btn btn--primary btn--small', onclick: newAthlete },
                       'Добавить спортсмена'));
      }

      box.appendChild(heading('Спортсмены',
        answer.data.total + ' ' + plural(answer.data.total, 'человек', 'человека', 'человек'),
        actions));

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text:
          athleteSearch ? 'Никого не нашлось' : 'Пока никого нет' })]));
        return;
      }

      var rows = items.map(function (athlete) {
        var row = el('tr', { tabindex: '0' }, [
          el('td', { class: 'title' }, athlete.name),
          el('td', { class: 'num', 'data-label': 'Возраст' },
             athlete.age === null ? '—' : athlete.age + ' ' + plural(athlete.age, 'год', 'года', 'лет')),
          el('td', { class: 'dim', 'data-label': 'Родился' }, dateRu(athlete.birth_date)),
          el('td', null, athlete.status === 'archived'
            ? el('span', { class: 'pill pill--off' }, 'в архиве') : '')
        ]);
        function open() { window.location.hash = '#/athletes/' + athlete.id; }
        row.addEventListener('click', open);
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(); } });
        return row;
      });

      box.appendChild(board([table([
        { title: 'Фамилия и имя' },
        { title: 'Возраст', class: 'num' },
        { title: 'Дата рождения' },
        { title: '' }
      ], rows)]));
    });
  }

  function newAthlete() {
    openSheet({
      title: 'Новый спортсмен',
      submit: 'Добавить',
      fields: [
        { name: 'last_name', label: 'Фамилия' },
        { name: 'first_name', label: 'Имя' },
        { name: 'middle_name', label: 'Отчество, если есть' },
        { name: 'birth_date', label: 'Дата рождения', type: 'date' },
        { name: 'gender', label: 'Пол', type: 'select', options: [
          { value: '', label: 'не указан' },
          { value: 'f', label: 'женский' },
          { value: 'm', label: 'мужской' }
        ] }
      ],
      onSubmit: function (values) {
        return api('POST', '/athletes', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Спортсмен добавлен');
          window.location.hash = '#/athletes/' + answer.data.id;
        });
      }
    });
  }

  function screenAthlete(id) {
    drawTabs('athletes');
    loading();

    api('GET', '/athletes/' + id).then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var a = answer.data;
      var box = clear(page);
      box.appendChild(backLink('athletes', 'Спортсмены'));

      var actions = [];
      if (can(['admin'])) {
        actions.push(el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { editAthlete(a); } }, 'Изменить'));
      }
      box.appendChild(heading(a.name, null, actions));

      /* --- Левая колонка: сведения и группы --- */

      var facts = el('dl', { class: 'facts' }, [
        el('dt', null, 'Дата рождения'), el('dd', null, dateRu(a.birth_date)),
        el('dt', null, 'Возраст'), el('dd', null,
          a.age === null ? '—' : a.age + ' ' + plural(a.age, 'год', 'года', 'лет')),
        el('dt', null, 'Состояние'), el('dd', null,
          a.status === 'active' ? 'занимается' : 'в архиве')
      ]);
      if (a.note) {
        facts.appendChild(el('dt', null, 'Заметка'));
        facts.appendChild(el('dd', null, a.note));
      }

      var groupsPane = el('div', { class: 'pane' }, [
        el('h2', null, 'Группы')
      ]);

      if (!a.groups.length) {
        groupsPane.appendChild(el('p', { class: 'empty', text: 'Пока не назначен ни в одну группу' }));
      } else {
        a.groups.forEach(function (g) {
          groupsPane.appendChild(el('div', { class: 'line' }, [
            el('div', { class: 'line__main' }, [
              el('a', { href: '#/groups/' + g.group_id, class: 'linkish' }, g.code + ' · ' + g.name),
              el('span', { class: 'line__sub' },
                 'с ' + dateRu(g.date_from) + (g.date_to ? ' по ' + dateRu(g.date_to) : ''))
            ]),
            g.current ? el('span', { class: 'pill' }, 'сейчас') : el('span', { class: 'pill pill--off' }, 'прошлое')
          ]));
        });
      }

      if (can(['admin'])) {
        groupsPane.appendChild(el('div', { class: 'sheet__act' }, [
          el('button', { class: 'btn btn--ghost btn--small',
            onclick: function () { enroll(a); } }, 'Назначить в группу')
        ]));
      }

      /* --- Правая колонка: родители --- */

      var parentsPane = el('div', { class: 'pane' }, [el('h2', null, 'Родители')]);

      if (!a.parents.length) {
        parentsPane.appendChild(el('p', { class: 'empty', text: 'Родители не привязаны' }));
      } else {
        a.parents.forEach(function (p) {
          var line = el('div', { class: 'line' }, [
            el('div', { class: 'line__main' }, [
              el('span', null, p.name),
              el('span', { class: 'line__sub' },
                 [p.phone, p.email].filter(Boolean).join(' · ') || 'контактов нет')
            ]),
            p.is_primary ? el('span', { class: 'pill' }, 'основной') : null
          ]);
          if (can(['admin'])) {
            line.appendChild(el('button', {
              class: 'linkish linkish--danger line__act',
              onclick: function () { unlinkParent(a, p); }
            }, 'Отвязать'));
          }
          parentsPane.appendChild(line);
        });
      }

      if (can(['admin'])) {
        parentsPane.appendChild(el('div', { class: 'sheet__act' }, [
          el('button', { class: 'btn btn--ghost btn--small',
            onclick: function () { linkParent(a); } }, 'Привязать родителя')
        ]));
      }

      var right = el('div', null, [parentsPane]);

      if (can(['admin'])) {
        right.appendChild(el('div', { class: 'pane' }, [
          el('h2', null, 'Персональные данные'),
          el('p', { class: 'field__note', text:
            'По заявлению родителя имя и дата рождения стираются, родители отвязываются. '
            + 'Посещения и абонементы остаются обезличенными — иначе рассыплются прошлые отчёты.' }),
          el('div', { class: 'sheet__act' }, [
            el('button', { class: 'btn btn--danger btn--small',
              onclick: function () { anonymize(a); } }, 'Удалить данные')
          ])
        ]));
      }

      box.appendChild(el('div', { class: 'panes' }, [
        el('div', null, [el('div', { class: 'pane' }, [el('h2', null, 'Сведения'), facts]), groupsPane]),
        right
      ]));
    });
  }

  function editAthlete(a) {
    openSheet({
      title: 'Изменить спортсмена',
      fields: [
        { name: 'last_name', label: 'Фамилия', value: a.last_name },
        { name: 'first_name', label: 'Имя', value: a.first_name },
        { name: 'middle_name', label: 'Отчество', value: a.middle_name },
        { name: 'birth_date', label: 'Дата рождения', type: 'date', value: a.birth_date },
        { name: 'note', label: 'Заметка', value: a.note },
        { name: 'status', label: 'Состояние', type: 'select', value: a.status, options: [
          { value: 'active', label: 'занимается' },
          { value: 'archived', label: 'в архиве' }
        ] }
      ],
      onSubmit: function (values) {
        return api('PATCH', '/athletes/' + a.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenAthlete(a.id);
        });
      }
    });
  }

  function enroll(a) {
    api('GET', '/groups').then(function (answer) {
      var groups = (answer.data.items || []).map(function (g) {
        return { value: g.id, label: g.code + ' · ' + g.name };
      });
      if (!groups.length) {
        toast('Сначала создайте группу', true);
        return;
      }

      openSheet({
        title: 'Назначить в группу',
        note: 'Прошлое назначение закроется днём раньше — история групп сохранится.',
        submit: 'Назначить',
        fields: [
          { name: 'group_id', label: 'Группа', type: 'select', options: groups },
          { name: 'date_from', label: 'С какого числа', type: 'date',
            value: new Date().toISOString().slice(0, 10) }
        ],
        onSubmit: function (values) {
          values.athlete_id = a.id;
          return api('POST', '/enrollments', values).then(function (result) {
            if (!result.ok) { return result; }
            toast(result.data.closed
              ? 'Назначен, прошлая группа закрыта'
              : 'Назначен в группу');
            screenAthlete(a.id);
          });
        }
      });
    });
  }

  function linkParent(a) {
    api('GET', '/parents?limit=200').then(function (answer) {
      var parents = (answer.data.items || []).map(function (p) {
        return { value: p.id, label: p.name + (p.phone ? ' · ' + p.phone : '') };
      });
      if (!parents.length) {
        toast('Сначала заведите родителя в разделе «Родители»', true);
        return;
      }

      openSheet({
        title: 'Привязать родителя',
        submit: 'Привязать',
        fields: [
          { name: 'parent_user_id', label: 'Родитель', type: 'select', options: parents },
          { name: 'relation', label: 'Кем приходится', type: 'select', options: [
            { value: 'mother', label: 'мать' },
            { value: 'father', label: 'отец' },
            { value: 'guardian', label: 'опекун' }
          ] },
          { name: 'is_primary', label: 'Основной контакт', type: 'checkbox' }
        ],
        onSubmit: function (values) {
          return api('POST', '/athletes/' + a.id + '/parents', values).then(function (result) {
            if (!result.ok) { return result; }
            toast('Родитель привязан');
            screenAthlete(a.id);
          });
        }
      });
    });
  }

  function unlinkParent(a, p) {
    confirmWithReason({
      title: 'Отвязать родителя',
      note: p.name + ' перестанет видеть профиль ребёнка в кабинете.',
      submit: 'Отвязать',
      onConfirm: function (why) {
        return api('DELETE', '/athletes/' + a.id + '/parents/' + p.id + '?reason='
                   + encodeURIComponent(why)).then(function (result) {
          if (!result.ok) { return result; }
          toast('Отвязан');
          screenAthlete(a.id);
        });
      }
    });
  }

  function anonymize(a) {
    confirmWithReason({
      title: 'Удалить персональные данные',
      note: 'Действие необратимое. Имя и дата рождения будут стёрты, профиль уйдёт в архив.',
      submit: 'Удалить данные',
      onConfirm: function (why) {
        return api('POST', '/athletes/' + a.id + '/anonymize', { reason: why })
          .then(function (result) {
            if (!result.ok) { return result; }
            toast('Данные удалены');
            window.location.hash = '#/athletes';
          });
      }
    });
  }

  /* =====================================================
     Группы
     ===================================================== */

  function screenGroups() {
    drawTabs('groups');
    loading();

    api('GET', '/groups').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      box.appendChild(heading('Группы',
        items.length + ' ' + plural(items.length, 'группа', 'группы', 'групп'),
        can(['admin']) ? [el('button', { class: 'btn btn--primary btn--small', onclick: newGroup },
                             'Добавить группу')] : []));

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text: 'Групп пока нет' })]));
        return;
      }

      var rows = items.map(function (g) {
        var coaches = g.coaches.map(function (c) { return c.name; }).join(', ');
        var row = el('tr', { tabindex: '0' }, [
          el('td', { class: 'title' }, g.code),
          el('td', { 'data-label': 'Название' }, g.name),
          el('td', { class: 'dim', 'data-label': 'Возраст' },
             g.age_from || g.age_to ? (g.age_from || '') + '–' + (g.age_to || '') + ' лет' : '—'),
          el('td', { class: 'dim', 'data-label': 'Тренеры' }, coaches || '—'),
          el('td', { class: 'num', 'data-label': 'Занимаются' }, String(g.athletes))
        ]);
        function open() { window.location.hash = '#/groups/' + g.id; }
        row.addEventListener('click', open);
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(); } });
        return row;
      });

      box.appendChild(board([table([
        { title: 'Код' }, { title: 'Название' }, { title: 'Возраст' },
        { title: 'Тренеры' }, { title: 'Занимаются', class: 'num' }
      ], rows)]));
    });
  }

  function newGroup() {
    openSheet({
      title: 'Новая группа',
      submit: 'Создать',
      fields: [
        { name: 'code', label: 'Короткое название', placeholder: 'СОГ-1' },
        { name: 'name', label: 'Полное название', placeholder: 'Спортивно-оздоровительная группа' },
        { name: 'age_from', label: 'Возраст с', type: 'number' },
        { name: 'age_to', label: 'Возраст до', type: 'number' }
      ],
      onSubmit: function (values) {
        if (!values.age_from) { delete values.age_from; }
        if (!values.age_to) { delete values.age_to; }
        return api('POST', '/groups', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Группа создана');
          window.location.hash = '#/groups/' + answer.data.id;
        });
      }
    });
  }

  function screenGroup(id) {
    drawTabs('groups');
    loading();

    Promise.all([
      api('GET', '/groups/' + id),
      api('GET', '/athletes?limit=200&group_id=' + id)
    ]).then(function (results) {
      if (!results[0].ok) { return fail(results[0]); }

      var g = results[0].data;
      var athletes = (results[1].data.items || []);
      var box = clear(page);

      box.appendChild(backLink('groups', 'Группы'));
      box.appendChild(heading(g.code + ' · ' + g.name, null,
        can(['admin']) ? [el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { editGroup(g); } }, 'Изменить')] : []));

      var facts = el('dl', { class: 'facts' }, [
        el('dt', null, 'Возраст'),
        el('dd', null, g.age_from || g.age_to
          ? (g.age_from || '') + '–' + (g.age_to || '') + ' лет' : '—'),
        el('dt', null, 'Занимаются'), el('dd', null, String(g.athletes)),
        el('dt', null, 'Состояние'),
        el('dd', null, g.status === 'active' ? 'работает' : 'в архиве')
      ]);

      var coachPane = el('div', { class: 'pane' }, [el('h2', null, 'Тренеры')]);
      if (!g.coaches.length) {
        coachPane.appendChild(el('p', { class: 'empty', text: 'Тренер не закреплён' }));
      } else {
        g.coaches.forEach(function (c) {
          var line = el('div', { class: 'line' }, [
            el('div', { class: 'line__main' }, [
              el('span', null, c.name),
              el('span', { class: 'line__sub' }, c.role === 'main' ? 'основной' : 'помощник')
            ])
          ]);
          if (can(['admin'])) {
            line.appendChild(el('button', {
              class: 'linkish linkish--danger line__act',
              onclick: function () { unsetCoach(g, c); }
            }, 'Открепить'));
          }
          coachPane.appendChild(line);
        });
      }
      if (can(['admin'])) {
        coachPane.appendChild(el('div', { class: 'sheet__act' }, [
          el('button', { class: 'btn btn--ghost btn--small',
            onclick: function () { setCoach(g); } }, 'Закрепить тренера')
        ]));
      }

      var listPane = el('div', { class: 'pane' }, [el('h2', null, 'Состав группы')]);
      if (!athletes.length) {
        listPane.appendChild(el('p', { class: 'empty', text: 'Пока никто не назначен' }));
      } else {
        athletes.forEach(function (a) {
          listPane.appendChild(el('div', { class: 'line' }, [
            el('div', { class: 'line__main' }, [
              el('a', { class: 'linkish', href: '#/athletes/' + a.id }, a.name),
              el('span', { class: 'line__sub' },
                 a.age === null ? 'возраст не указан'
                   : a.age + ' ' + plural(a.age, 'год', 'года', 'лет'))
            ])
          ]));
        });
      }

      box.appendChild(el('div', { class: 'panes' }, [
        el('div', null, [el('div', { class: 'pane' }, [el('h2', null, 'Сведения'), facts]),
                         coachPane, rulesPane(g)]),
        listPane
      ]));
    });
  }

  function editGroup(g) {
    openSheet({
      title: 'Изменить группу',
      fields: [
        { name: 'code', label: 'Короткое название', value: g.code },
        { name: 'name', label: 'Полное название', value: g.name },
        { name: 'age_from', label: 'Возраст с', type: 'number', value: g.age_from },
        { name: 'age_to', label: 'Возраст до', type: 'number', value: g.age_to },
        { name: 'status', label: 'Состояние', type: 'select', value: g.status, options: [
          { value: 'active', label: 'работает' },
          { value: 'archived', label: 'в архиве' }
        ] }
      ],
      onSubmit: function (values) {
        if (!values.age_from) { delete values.age_from; }
        if (!values.age_to) { delete values.age_to; }
        return api('PATCH', '/groups/' + g.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenGroup(g.id);
        });
      }
    });
  }

  function setCoach(g) {
    api('GET', '/coaches').then(function (answer) {
      var coaches = (answer.data.items || []).map(function (c) {
        return { value: c.id, label: c.name };
      });
      if (!coaches.length) {
        toast('Сначала заведите тренера в разделе «Тренеры»', true);
        return;
      }

      openSheet({
        title: 'Закрепить тренера',
        submit: 'Закрепить',
        fields: [
          { name: 'coach_id', label: 'Тренер', type: 'select', options: coaches },
          { name: 'role', label: 'Роль', type: 'select', options: [
            { value: 'main', label: 'основной' },
            { value: 'assistant', label: 'помощник' }
          ] }
        ],
        onSubmit: function (values) {
          return api('POST', '/groups/' + g.id + '/coaches', values).then(function (result) {
            if (!result.ok) { return result; }
            toast('Тренер закреплён');
            screenGroup(g.id);
          });
        }
      });
    });
  }

  function unsetCoach(g, c) {
    api('DELETE', '/groups/' + g.id + '/coaches/' + c.id).then(function (result) {
      if (!result.ok) { toast(reason(result), true); return; }
      toast('Тренер откреплён');
      screenGroup(g.id);
    });
  }

  /* =====================================================
     Тренеры
     ===================================================== */

  function screenCoaches() {
    drawTabs('coaches');
    loading();

    api('GET', '/coaches?status=all').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      box.appendChild(heading('Тренеры', items.length + ' ' + plural(items.length, 'человек', 'человека', 'человек'),
        [el('button', { class: 'btn btn--primary btn--small', onclick: newCoach }, 'Добавить тренера')]));

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text: 'Тренеров пока нет' })]));
        return;
      }

      var rows = items.map(function (c) {
        var groups = (c.groups || []).map(function (g) { return g.code; }).join(', ');
        return el('tr', { class: 'is-flat' }, [
          el('td', { class: 'title' }, c.name),
          el('td', { class: 'dim', 'data-label': 'Группы' }, groups || '—'),
          el('td', null, c.has_login
            ? el('span', { class: 'pill' }, 'есть вход')
            : el('span', { class: 'pill pill--warn' }, 'без входа')),
          el('td', null, c.status === 'archived'
            ? el('span', { class: 'pill pill--off' }, 'в архиве') : ''),
          el('td', { class: 'act' },
             el('button', { class: 'linkish', onclick: function () { editCoach(c); } }, 'Изменить'))
        ]);
      });

      box.appendChild(board([table([
        { title: 'Фамилия и имя' }, { title: 'Группы' }, { title: 'Вход' },
        { title: '' }, { title: '', class: 'act' }
      ], rows)]));
    });
  }

  function newCoach() {
    // Список тех, у кого ещё нет карточки тренера: один человек
    // бывает и администратором, и тренером, и второй вход ему
    // заводить незачем
    api('GET', '/users?free_coach=1').then(function (answer) {
      var accounts = [{ value: '', label: 'создать новую учётную запись' }].concat(
        (answer.data.items || []).map(function (u) {
          return { value: u.id, label: u.name + (u.email ? ' · ' + u.email : '') };
        })
      );

      openSheet({
        title: 'Новый тренер',
        note: 'Если этот человек уже есть в кабинете — например, вы сами как администратор, — '
            + 'выберите его учётную запись. Тогда вход останется один, а ролей станет две.',
        submit: 'Добавить',
        fields: [
          { name: 'last_name', label: 'Фамилия' },
          { name: 'first_name', label: 'Имя' },
          { name: 'middle_name', label: 'Отчество, если есть' },
          { name: 'user_id', label: 'Учётная запись', type: 'select', options: accounts },
          { name: 'phone', label: 'Телефон', placeholder: '+7 999 000 00 00',
            hint: 'Телефон, почта и пароль нужны только для новой учётной записи' },
          { name: 'email', label: 'Почта' },
          { name: 'password', label: 'Временный пароль', type: 'text',
            hint: 'Не короче восьми символов. Пусто — тренер пока без входа в кабинет' }
        ],
        onSubmit: function (values) {
          if (values.user_id) {
            // Контакты существующей записи здесь не трогаем
            delete values.phone;
            delete values.email;
            delete values.password;
          }
          Object.keys(values).forEach(function (k) { if (!values[k]) { delete values[k]; } });
          return api('POST', '/coaches', values).then(function (result) {
            if (!result.ok) { return result; }
            toast('Тренер добавлен');
            screenCoaches();
          });
        }
      });
    });
  }

  function editCoach(c) {
    openSheet({
      title: 'Изменить тренера',
      note: 'Перевод в архив закрывает и вход в кабинет.',
      fields: [
        { name: 'last_name', label: 'Фамилия', value: c.last_name },
        { name: 'first_name', label: 'Имя', value: c.first_name },
        { name: 'middle_name', label: 'Отчество', value: c.middle_name },
        { name: 'status', label: 'Состояние', type: 'select', value: c.status, options: [
          { value: 'active', label: 'работает' },
          { value: 'archived', label: 'в архиве' }
        ] }
      ],
      onSubmit: function (values) {
        return api('PATCH', '/coaches/' + c.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenCoaches();
        });
      }
    });
  }

  /* =====================================================
     Родители
     ===================================================== */

  function screenParents() {
    drawTabs('parents');
    loading();

    api('GET', '/parents?limit=200').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      box.appendChild(heading('Родители',
        answer.data.total + ' ' + plural(answer.data.total, 'человек', 'человека', 'человек'),
        [el('button', { class: 'btn btn--primary btn--small', onclick: newParent }, 'Добавить родителя')]));

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text: 'Родителей пока нет' })]));
        return;
      }

      var rows = items.map(function (p) {
        var kids = (p.children || []).map(function (c) {
          return c.last_name + ' ' + c.first_name;
        }).join(', ');
        return el('tr', { class: 'is-flat' }, [
          el('td', { class: 'title' }, p.name),
          el('td', { class: 'dim', 'data-label': 'Контакты' },
             [p.phone, p.email].filter(Boolean).join(' · ') || '—'),
          el('td', { class: 'dim', 'data-label': 'Дети' }, kids || '—'),
          el('td', { class: 'act' },
             el('button', { class: 'linkish', onclick: function () { editParent(p); } }, 'Изменить'))
        ]);
      });

      box.appendChild(board([table([
        { title: 'Фамилия и имя' }, { title: 'Контакты' }, { title: 'Дети' },
        { title: '', class: 'act' }
      ], rows)]));
    });
  }

  function newParent() {
    openSheet({
      title: 'Новый родитель',
      note: 'По телефону или почте родитель входит в кабинет. Нужно хотя бы одно.',
      submit: 'Добавить',
      fields: [
        { name: 'last_name', label: 'Фамилия' },
        { name: 'first_name', label: 'Имя' },
        { name: 'middle_name', label: 'Отчество, если есть' },
        { name: 'phone', label: 'Телефон', placeholder: '+7 999 000 00 00' },
        { name: 'email', label: 'Почта' }
      ],
      onSubmit: function (values) {
        return api('POST', '/parents', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Родитель добавлен');
          screenParents();
        });
      }
    });
  }

  function editParent(p) {
    openSheet({
      title: 'Изменить родителя',
      fields: [
        { name: 'last_name', label: 'Фамилия', value: p.last_name },
        { name: 'first_name', label: 'Имя', value: p.first_name },
        { name: 'middle_name', label: 'Отчество', value: p.middle_name },
        { name: 'phone', label: 'Телефон', value: p.phone },
        { name: 'email', label: 'Почта', value: p.email },
        { name: 'status', label: 'Доступ', type: 'select', value: p.status, options: [
          { value: 'active', label: 'открыт' },
          { value: 'blocked', label: 'закрыт' }
        ] }
      ],
      onSubmit: function (values) {
        return api('PATCH', '/parents/' + p.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenParents();
        });
      }
    });
  }

  /* =====================================================
     Тарифы
     ===================================================== */

  function screenTariffs() {
    drawTabs('tariffs');
    loading();

    api('GET', '/tariffs?status=all').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      box.appendChild(heading('Тарифы', null,
        [el('button', { class: 'btn btn--primary btn--small', onclick: newTariff }, 'Добавить тариф')]));

      box.appendChild(el('p', { class: 'field__note', text:
        'Цена тарифа — не сумма абонемента, а ставка: цена занятия получается делением '
        + 'на число занятий, а сумма считается по фактическим занятиям месяца.' }));

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text: 'Тарифов пока нет' })]));
        return;
      }

      var rows = items.map(function (t) {
        var act = el('td', { class: 'act' });
        act.appendChild(el('button', { class: 'linkish',
          onclick: function () { editTariff(t); } }, 'Изменить'));
        if (t.in_use) {
          act.appendChild(document.createTextNode(' '));
          act.appendChild(el('button', { class: 'linkish',
            onclick: function () { reviseTariff(t); } }, 'Новая цена'));
        }

        return el('tr', { class: 'is-flat' }, [
          el('td', { class: 'title' }, t.name),
          el('td', { class: 'num', 'data-label': 'Цена' }, money(t.price)),
          el('td', { class: 'num dim', 'data-label': 'Занятий' },
             t.sessions_count ? String(t.sessions_count) : '—'),
          el('td', { class: 'num dim', 'data-label': 'Занятие' },
             t.lesson_price ? money(t.lesson_price) : '—'),
          el('td', null, [
            t.in_use ? el('span', { class: 'pill' }, 'используется') : null,
            t.status === 'archived' ? el('span', { class: 'pill pill--off' }, 'в архиве') : null
          ]),
          act
        ]);
      });

      box.appendChild(board([table([
        { title: 'Название' }, { title: 'Цена', class: 'num' },
        { title: 'Занятий', class: 'num' }, { title: 'Занятие', class: 'num' },
        { title: '' }, { title: '', class: 'act' }
      ], rows)]));
    });
  }

  function tariffFields(t) {
    t = t || {};
    return [
      { name: 'name', label: 'Название', value: t.name, placeholder: 'СОГ-1, 2 раза в неделю' },
      { name: 'price', label: 'Цена за месяц, ₽', type: 'number', value: t.price ? Math.round(t.price) : '' },
      { name: 'sessions_count', label: 'Занятий в месяц', type: 'number', value: t.sessions_count },
      { name: 'days_per_week', label: 'Занятий в неделю', type: 'number', value: t.days_per_week,
        hint: 'Заполняется, если у группы несколько вариантов и нужен выбор дней' }
    ];
  }

  function newTariff() {
    openSheet({
      title: 'Новый тариф',
      submit: 'Создать',
      fields: tariffFields(),
      onSubmit: function (values) {
        Object.keys(values).forEach(function (k) { if (values[k] === '') { delete values[k]; } });
        return api('POST', '/tariffs', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Тариф создан');
          screenTariffs();
        });
      }
    });
  }

  function editTariff(t) {
    openSheet({
      title: 'Изменить тариф',
      note: t.in_use
        ? 'По этому тарифу уже выданы абонементы, поэтому цену и число занятий здесь менять нельзя — '
          + 'для этого есть «Новая цена».'
        : null,
      fields: tariffFields(t).concat([
        { name: 'status', label: 'Состояние', type: 'select', value: t.status, options: [
          { value: 'active', label: 'действует' },
          { value: 'archived', label: 'в архиве' }
        ] }
      ]),
      onSubmit: function (values) {
        Object.keys(values).forEach(function (k) { if (values[k] === '') { delete values[k]; } });
        return api('PATCH', '/tariffs/' + t.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenTariffs();
        });
      }
    });
  }

  function reviseTariff(t) {
    openSheet({
      title: 'Новая цена тарифа',
      note: 'Создаётся новая версия тарифа, прежняя уходит в архив. Выданные абонементы '
          + 'останутся со своими суммами — иначе у родителей вырастут суммы оплаченного.',
      submit: 'Создать версию',
      fields: [
        { name: 'price', label: 'Новая цена за месяц, ₽', type: 'number', value: Math.round(t.price) },
        { name: 'sessions_count', label: 'Занятий в месяц', type: 'number', value: t.sessions_count },
        { name: 'reason', label: 'Основание', placeholder: 'Цены с января' }
      ],
      onSubmit: function (values) {
        return api('POST', '/tariffs/' + t.id + '/revise', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Новая версия создана');
          screenTariffs();
        });
      }
    });
  }

  /* =====================================================
     Расписание
     ===================================================== */

  var DAYS = ['', 'понедельник', 'вторник', 'среда', 'четверг',
              'пятница', 'суббота', 'воскресенье'];
  var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

  /** Понедельник недели, в которую попадает дата. */
  function weekStart(iso) {
    var d = iso ? new Date(iso + 'T00:00:00') : new Date();
    var shift = (d.getDay() + 6) % 7;        // воскресенье — седьмой день, а не нулевой
    d.setDate(d.getDate() - shift);
    return isoDate(d);
  }

  function isoDate(d) {
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
         + '-' + ('0' + d.getDate()).slice(-2);
  }

  function shiftDays(iso, days) {
    var d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return isoDate(d);
  }

  function dayTitle(iso) {
    var d = new Date(iso + 'T00:00:00');
    return d.getDate() + ' ' + MONTHS[d.getMonth()];
  }

  function screenSchedule(from) {
    drawTabs('schedule');
    loading();

    var start = weekStart(from);
    var end = shiftDays(start, 6);

    api('GET', '/trainings?from=' + start + '&to=' + end).then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      var actions = [
        el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { window.location.hash = '#/schedule/' + shiftDays(start, -7); }
        }, '← Неделя назад'),
        el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { window.location.hash = '#/schedule/' + weekStart(null); }
        }, 'Сегодня'),
        el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { window.location.hash = '#/schedule/' + shiftDays(start, 7); }
        }, 'Неделя вперёд →')
      ];

      if (can(['admin'])) {
        actions.push(el('button', { class: 'btn btn--primary btn--small',
          onclick: generateSchedule }, 'Обновить расписание'));
      }

      box.appendChild(heading('Расписание',
        dayTitle(start) + ' — ' + dayTitle(end), actions));

      // Раскладываем по дням, чтобы пустые дни тоже были видны:
      // пустой четверг — это информация, а не отсутствие строки
      var byDay = {};
      items.forEach(function (t) {
        (byDay[t.date] = byDay[t.date] || []).push(t);
      });

      var week = el('div', { class: 'week' });
      var today = isoDate(new Date());

      for (var i = 0; i < 7; i++) {
        var day = shiftDays(start, i);
        var list = byDay[day] || [];

        var head = el('div', { class: 'day__head' }, [
          el('b', null, DAYS[i + 1]),
          el('span', null, dayTitle(day))
        ]);

        var body = el('div', { class: 'day__body' });
        if (!list.length) {
          body.appendChild(el('p', { class: 'day__empty', text: 'нет занятий' }));
        } else {
          list.forEach(function (t) { body.appendChild(trainingCard(t)); });
        }

        week.appendChild(el('div', {
          class: 'day' + (day === today ? ' is-today' : '')
                       + (list.length ? '' : ' is-empty')
        }, [head, body]));
      }

      box.appendChild(week);

      if (!items.length) {
        box.appendChild(el('p', { class: 'field__note', text: can(['admin'])
          ? 'Занятий на этой неделе нет. Постоянное расписание задаётся в карточке группы, '
            + 'а кнопка «Обновить расписание» раскрывает его в конкретные занятия.'
          : 'Занятий на этой неделе нет.' }));
      }
    });
  }

  function trainingCard(t) {
    var card = el('div', { class: 'tr tr--' + t.status });

    // Время и группа в одну строку: в узкой колонке дня
    // каждая лишняя строка заметна
    card.appendChild(el('div', { class: 'tr__head' }, [
      el('span', { class: 'tr__time' }, t.time_start),
      el('b', null, t.group_code)
    ]));
    card.appendChild(el('div', { class: 'tr__main' }, [
      el('span', { class: 'tr__sub' },
         [t.kind_name, t.venue_name, t.coach_name || 'тренер не назначен']
           .filter(Boolean).join(' · '))
    ]));

    var marks = el('div', { class: 'tr__marks' });
    if (t.substitute) { marks.appendChild(el('span', { class: 'pill' }, 'замена')); }
    if (t.status === 'cancelled') {
      marks.appendChild(el('span', { class: 'pill pill--warn' }, 'отменена'));
    }
    if (t.status === 'moved') {
      marks.appendChild(el('span', { class: 'pill pill--off' }, 'перенесена'));
    }
    if (t.status === 'held') {
      marks.appendChild(el('span', { class: 'pill' }, 'проведена'));
    }
    card.appendChild(marks);

    if (t.cancel_reason) {
      card.appendChild(el('div', { class: 'tr__why', text: t.cancel_reason }));
    }

    if (t.status === 'planned') {
      var act = el('div', { class: 'tr__act' });
      act.appendChild(el('button', { class: 'linkish',
        onclick: function () { setTrainingCoach(t); } }, 'Заменить тренера'));
      if (can(['admin'])) {
        act.appendChild(el('button', { class: 'linkish',
          onclick: function () { moveTraining(t); } }, 'Перенести'));
        act.appendChild(el('button', { class: 'linkish linkish--danger',
          onclick: function () { cancelTraining(t); } }, 'Отменить'));
      }
      card.appendChild(act);
    }

    return card;
  }

  function generateSchedule() {
    openSheet({
      title: 'Обновить расписание',
      note: 'Раскрывает постоянное расписание групп в конкретные занятия. '
          + 'Уже существующие занятия не трогаются — отменённые не вернутся, '
          + 'отметки не пропадут.',
      submit: 'Обновить',
      fields: [
        { name: 'weeks', label: 'На сколько недель вперёд', type: 'number', value: 8 }
      ],
      onSubmit: function (values) {
        return api('POST', '/schedule/generate', values).then(function (answer) {
          if (!answer.ok) { return answer; }

          var d = answer.data;
          toast(d.created
            ? 'Добавлено занятий: ' + d.created
            : 'Всё уже на месте, новых занятий нет');

          if (d.skipped && d.skipped.length) {
            // Накладки надо показать подробно: «пропущено 3»
            // без объяснения заставит искать вручную
            setTimeout(function () {
              openSheet({
                title: 'Не поместилось',
                note: 'Эти занятия не созданы — в то же время занят лёд или тренер. '
                    + 'Поправьте расписание и обновите ещё раз.',
                submit: 'Понятно',
                fields: [],
                onSubmit: function () { screenSchedule(); }
              });
              var list = el('div', { class: 'pane' });
              d.skipped.forEach(function (one) {
                list.appendChild(el('div', { class: 'line' }, [
                  el('div', { class: 'line__main' }, [
                    el('span', null, one.group + ', ' + dayTitle(one.date) + ', ' + one.time),
                    el('span', { class: 'line__sub' }, one.reason)
                  ])
                ]));
              });
              sheetForm.insertBefore(list, sheetForm.querySelector('.sheet__act'));
            }, 100);
          } else {
            screenSchedule();
          }
        });
      }
    });
  }

  function cancelTraining(t) {
    confirmWithReason({
      title: 'Отменить занятие',
      note: t.group_code + ', ' + dayTitle(t.date) + ', ' + t.time_start
          + '. Занятие останется в расписании с пометкой об отмене — школа должна его '
          + 'перенести или вернуть бонусным.',
      submit: 'Отменить занятие',
      onConfirm: function (why) {
        return api('POST', '/trainings/' + t.id + '/cancel', { reason: why })
          .then(function (answer) {
            if (!answer.ok) { return answer; }
            toast('Занятие отменено');
            screenSchedule(t.date);
          });
      }
    });
  }

  function moveTraining(t) {
    openSheet({
      title: 'Перенести занятие',
      note: t.group_code + ', ' + dayTitle(t.date) + ', ' + t.time_start
          + '. На прежнем месте останется отметка о переносе со ссылкой на новое время.',
      submit: 'Перенести',
      fields: [
        { name: 'date', label: 'Новая дата', type: 'date', value: t.date },
        { name: 'time_start', label: 'Новое время', type: 'time', value: t.time_start },
        { name: 'reason', label: 'Причина', placeholder: 'Заливка льда, соревнования' }
      ],
      onSubmit: function (values) {
        return api('POST', '/trainings/' + t.id + '/move', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Занятие перенесено');
          screenSchedule(values.date);
        });
      }
    });
  }

  function setTrainingCoach(t) {
    api('GET', '/coaches').then(function (answer) {
      var coaches = (answer.data.items || [])
        .filter(function (c) { return c.id !== t.coach_id; })
        .map(function (c) { return { value: c.id, label: c.name }; });

      if (!coaches.length) {
        toast('Больше тренеров в школе нет', true);
        return;
      }

      openSheet({
        title: 'Заменить тренера',
        note: t.group_code + ', ' + dayTitle(t.date) + ', ' + t.time_start
            + '. Занятие проведёт другой тренер — и оно засчитается ему, а не '
            + (t.coach_name || 'прежнему'),
        submit: 'Назначить',
        fields: [
          { name: 'coach_id', label: 'Кто проведёт', type: 'select', options: coaches },
          { name: 'reason', label: 'Причина', placeholder: 'Болезнь' }
        ],
        onSubmit: function (values) {
          return api('POST', '/trainings/' + t.id + '/coach', values).then(function (result) {
            if (!result.ok) { return result; }
            toast('Тренер назначен');
            screenSchedule(t.date);
          });
        }
      });
    });
  }

  /* --- Постоянное расписание в карточке группы ------------- */

  function rulesPane(g) {
    var pane = el('div', { class: 'pane' }, [el('h2', null, 'Постоянное расписание')]);
    var body = el('div');
    pane.appendChild(body);

    if (can(['admin'])) {
      pane.appendChild(el('div', { class: 'sheet__act' }, [
        el('button', { class: 'btn btn--ghost btn--small',
          onclick: function () { newRule(g); } }, 'Добавить занятие')
      ]));
    }

    api('GET', '/groups/' + g.id + '/schedule').then(function (answer) {
      var rules = (answer.data && answer.data.items) || [];
      clear(body);

      if (!rules.length) {
        body.appendChild(el('p', { class: 'empty', text: 'Расписание не задано' }));
        return;
      }

      rules.forEach(function (r) {
        var line = el('div', { class: 'line' }, [
          el('div', { class: 'line__main' }, [
            el('span', null, r.weekday_name + ', ' + r.time_start),
            el('span', { class: 'line__sub' },
               [r.kind_name, r.venue_name, r.coach_name].filter(Boolean).join(' · '))
          ])
        ]);
        if (can(['admin'])) {
          line.appendChild(el('button', {
            class: 'linkish linkish--danger line__act',
            onclick: function () { closeRule(g, r); }
          }, 'Убрать'));
        }
        body.appendChild(line);
      });
    });

    return pane;
  }

  function newRule(g) {
    Promise.all([api('GET', '/venues'), api('GET', '/coaches')]).then(function (results) {
      var venues = (results[0].data.items || []).map(function (v) {
        return { value: v.id, label: v.name };
      });
      if (!venues.length) {
        toast('Сначала заведите площадку', true);
        return;
      }
      var coaches = [{ value: '', label: 'из тренеров группы' }].concat(
        (results[1].data.items || []).map(function (c) {
          return { value: c.id, label: c.name };
        }));

      var days = [];
      for (var i = 1; i <= 7; i++) { days.push({ value: i, label: DAYS[i] }); }

      openSheet({
        title: 'Занятие в расписании',
        note: 'Это шаблон, а не занятие. Чтобы он превратился в конкретные тренировки, '
            + 'нажмите «Обновить расписание» в разделе «Расписание».',
        submit: 'Добавить',
        fields: [
          { name: 'weekday', label: 'День недели', type: 'select', options: days },
          { name: 'time_start', label: 'Время начала', type: 'time', value: '17:00' },
          { name: 'duration_min', label: 'Длительность, минут', type: 'number', value: 60 },
          { name: 'kind', label: 'Вид занятия', type: 'select', options: [
            { value: 'ice', label: 'лёд' },
            { value: 'gym', label: 'зал' },
            { value: 'choreo', label: 'хореография' }
          ] },
          { name: 'venue_id', label: 'Площадка', type: 'select', options: venues },
          { name: 'coach_id', label: 'Тренер', type: 'select', options: coaches },
          { name: 'valid_from', label: 'Действует с', type: 'date',
            value: isoDate(new Date()) }
        ],
        onSubmit: function (values) {
          if (!values.coach_id) { delete values.coach_id; }
          return api('POST', '/groups/' + g.id + '/schedule', values).then(function (answer) {
            if (!answer.ok) { return answer; }
            toast('Добавлено в расписание');
            screenGroup(g.id);
          });
        }
      });
    });
  }

  function closeRule(g, r) {
    confirmWithReason({
      title: 'Убрать из расписания',
      note: r.weekday_name + ', ' + r.time_start + '. Прошедшие занятия останутся, '
          + 'будущие запланированные — уберутся.',
      submit: 'Убрать',
      onConfirm: function (why) {
        return api('DELETE', '/schedule-rules/' + r.id + '?from=' + isoDate(new Date())
                   + '&reason=' + encodeURIComponent(why)).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast(answer.data.removed
            ? 'Убрано, снято занятий: ' + answer.data.removed
            : 'Убрано из расписания');
          screenGroup(g.id);
        });
      }
    });
  }

  /* =====================================================
     Услуги и цены
     ===================================================== */

  function screenServices() {
    drawTabs('services');
    loading();

    api('GET', '/services?status=all').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);

      box.appendChild(heading('Услуги и цены', null,
        [el('button', { class: 'btn btn--primary btn--small', onclick: newService }, 'Добавить услугу')]));

      box.appendChild(el('p', { class: 'field__note', text:
        'Все цены школы в одном месте. Услуги с пометкой «для расчётов» использует система — '
        + 'их можно переименовать и переоценить, но не убрать.' }));

      var missing = items.filter(function (s) { return s.system && !s.price_set; });
      if (missing.length) {
        box.appendChild(el('p', { class: 'alert' }, 'Не задана цена: '
          + missing.map(function (s) { return s.name; }).join(', ')
          + '. Пока цены нет, начисление по этой услуге не пройдёт.'));
      }

      if (!items.length) {
        box.appendChild(board([el('p', { class: 'empty', text: 'Услуг пока нет' })]));
        return;
      }

      var rows = items.map(function (s) {
        return el('tr', { class: 'is-flat' }, [
          el('td', { class: 'title' }, s.name),
          el('td', { class: 'num', 'data-label': 'Цена' },
             s.price_set ? money(s.price) : el('span', { class: 'pill pill--warn' }, 'не задана')),
          el('td', { class: 'dim', 'data-label': 'Пояснение' }, s.note || ''),
          el('td', null, [
            s.system ? el('span', { class: 'pill' }, 'для расчётов') : null,
            s.status === 'archived' ? el('span', { class: 'pill pill--off' }, 'в архиве') : null
          ]),
          el('td', { class: 'act' },
             el('button', { class: 'linkish',
               onclick: function () { editService(s); } }, 'Изменить'))
        ]);
      });

      box.appendChild(board([table([
        { title: 'Услуга' }, { title: 'Цена', class: 'num' },
        { title: 'Пояснение' }, { title: '' }, { title: '', class: 'act' }
      ], rows)]));
    });
  }

  function newService() {
    openSheet({
      title: 'Новая услуга',
      note: 'Например, прокат коньков, заточка, индивидуальное занятие.',
      submit: 'Добавить',
      fields: [
        { name: 'name', label: 'Название' },
        { name: 'price', label: 'Цена, ₽', type: 'number' },
        { name: 'note', label: 'Пояснение, если нужно' }
      ],
      onSubmit: function (values) {
        if (values.price === '') { delete values.price; }
        return api('POST', '/services', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Услуга добавлена');
          screenServices();
        });
      }
    });
  }

  function editService(s) {
    openSheet({
      title: 'Изменить услугу',
      note: s.system
        ? 'Эта услуга участвует в расчётах, поэтому убрать её нельзя — только переименовать '
          + 'или поменять цену.'
        : null,
      fields: [
        { name: 'name', label: 'Название', value: s.name },
        { name: 'price', label: 'Цена, ₽', type: 'number',
          value: s.price_set ? Math.round(s.price) : '' },
        { name: 'note', label: 'Пояснение', value: s.note },
        { name: 'reason', label: 'Основание', placeholder: 'Для журнала' }
      ].concat(s.system ? [] : [
        { name: 'status', label: 'Состояние', type: 'select', value: s.status, options: [
          { value: 'active', label: 'действует' },
          { value: 'archived', label: 'в архиве' }
        ] }
      ]),
      onSubmit: function (values) {
        if (values.price === '') { delete values.price; }
        return api('PATCH', '/services/' + s.id, values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenServices();
        });
      }
    });
  }

  /* =====================================================
     Настройки
     ===================================================== */

  function screenSettings() {
    drawTabs('settings');
    loading();

    api('GET', '/settings').then(function (answer) {
      if (!answer.ok) { return fail(answer); }

      var items = answer.data.items || [];
      var box = clear(page);
      box.appendChild(heading('Настройки', null, []));
      box.appendChild(el('p', { class: 'field__note', text:
        'Числа, которые школа меняет без правки кода. Каждое изменение попадает в журнал.' }));

      var rows = items.map(function (s) {
        return el('tr', { class: 'is-flat' }, [
          el('td', { class: 'title' }, s.name),
          el('td', { class: 'num' }, s.value),
          el('td', { class: 'dim', 'data-label': 'Что это' }, s.note || ''),
          el('td', { class: 'act' },
             el('button', { class: 'linkish',
               onclick: function () { editSetting(s); } }, 'Изменить'))
        ]);
      });

      box.appendChild(board([table([
        { title: 'Имя' }, { title: 'Значение', class: 'num' },
        { title: 'Что это' }, { title: '', class: 'act' }
      ], rows)]));

      box.appendChild(venuesPane());
    });
  }

  /** Площадки: их мало и заводят их однажды, отдельный раздел им ни к чему. */
  function venuesPane() {
    var pane = el('div', { class: 'pane' }, [
      el('h2', null, 'Площадки'),
      el('p', { class: 'field__note', text:
        'Где проходят занятия. На площадку ссылается расписание, и по ней система '
        + 'видит, что лёд в это время уже занят.' })
    ]);

    var body = el('div');
    pane.appendChild(body);
    pane.appendChild(el('div', { class: 'sheet__act' }, [
      el('button', { class: 'btn btn--ghost btn--small', onclick: newVenue }, 'Добавить площадку')
    ]));

    api('GET', '/venues').then(function (answer) {
      var venues = (answer.data && answer.data.items) || [];
      clear(body);

      if (!venues.length) {
        body.appendChild(el('p', { class: 'empty', text: 'Площадок пока нет' }));
        return;
      }
      venues.forEach(function (v) {
        body.appendChild(el('div', { class: 'line' }, [
          el('div', { class: 'line__main' }, [
            el('span', null, v.name),
            el('span', { class: 'line__sub' }, v.address || 'адрес не указан')
          ])
        ]));
      });
    });

    return pane;
  }

  function newVenue() {
    openSheet({
      title: 'Новая площадка',
      submit: 'Добавить',
      fields: [
        { name: 'name', label: 'Название', placeholder: 'Гололёд' },
        { name: 'address', label: 'Адрес', placeholder: 'ул. Свободы, 15' }
      ],
      onSubmit: function (values) {
        return api('POST', '/venues', values).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Площадка добавлена');
          screenSettings();
        });
      }
    });
  }

  function editSetting(s) {
    openSheet({
      title: 'Изменить настройку',
      note: s.note || null,
      fields: [
        { name: 'value', label: s.name, value: s.value },
        { name: 'reason', label: 'Основание', placeholder: 'Для журнала' }
      ],
      onSubmit: function (values) {
        var body = { reason: values.reason };
        body[s.name] = values.value;
        return api('PATCH', '/settings', body).then(function (answer) {
          if (!answer.ok) { return answer; }
          toast('Сохранено');
          screenSettings();
        });
      }
    });
  }

  /* =====================================================
     Маршрутизация
     ===================================================== */

  function fail(answer) {
    clear(page).appendChild(el('div', { class: 'board' }, [
      el('p', { class: 'empty', text: reason(answer) })
    ]));
  }

  function route() {
    var hash = window.location.hash.replace(/^#\/?/, '');
    var parts = hash.split('/').filter(Boolean);
    var where = parts[0] || firstTab();

    if (where === 'athletes') { return parts[1] ? screenAthlete(parts[1]) : screenAthletes(); }
    if (where === 'groups')   { return parts[1] ? screenGroup(parts[1]) : screenGroups(); }
    if (where === 'schedule') { return screenSchedule(parts[1]); }
    if (where === 'coaches'  && can(['admin'])) { return screenCoaches(); }
    if (where === 'parents'  && can(['admin'])) { return screenParents(); }
    if (where === 'tariffs'  && can(['admin'])) { return screenTariffs(); }
    if (where === 'services' && can(['admin'])) { return screenServices(); }
    if (where === 'settings' && can(['admin'])) { return screenSettings(); }

    window.location.hash = '#/' + firstTab();
  }

  function firstTab() {
    for (var i = 0; i < TABS.length; i++) {
      if (can(TABS[i].roles)) { return TABS[i].id; }
    }
    return 'athletes';
  }

  window.addEventListener('hashchange', route);

  document.getElementById('out').addEventListener('click', function () {
    api('POST', '/auth/logout', {}).then(function () { window.location.href = '/login'; });
  });

  // Окно закрывается по Esc само — следим только за тем,
  // чтобы форма не осталась с прежним обработчиком
  sheet.addEventListener('close', function () { sheetForm.onsubmit = null; });

  /* --- Старт ----------------------------------------- */

  api('GET', '/me').then(function (answer) {
    if (!answer.ok) { return; }   // 401 уже увёл на страницу входа

    me = answer.data;
    document.getElementById('who').textContent = me.name;

    if (me.must_change_password) {
      window.location.href = '/login';
      return;
    }

    if (!can(['admin', 'coach'])) {
      drawTabs(null);
      clear(page).appendChild(el('div', { class: 'board' }, [
        el('p', { class: 'empty', text:
          'Разделы для родителей появятся вместе с абонементами и посещаемостью.' })
      ]));
      return;
    }

    route();
  });

})();
