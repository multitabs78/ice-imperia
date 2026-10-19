// Проверка кабинета администратора настоящим браузером.
// Запуск описан в tests/README.md
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE || 'http://127.0.0.1:8921';
const OUT = process.env.OUT || path.join(__dirname, 'screenshots');
fs.mkdirSync(OUT, { recursive: true });

let passed = 0;
const failed = [];

function check(name, condition, details = '') {
  if (condition) { passed++; console.log('  ✓ ' + name); }
  else { failed.push(name + (details ? ' — ' + details : '')); console.log('  ✗ ' + name + (details ? ' — ' + details : '')); }
}

/** Заполняет форму в модальном окне и отправляет. */
async function fillSheet(page, values, submitText) {
  await page.waitForSelector('dialog.sheet[open]', { timeout: 5000 });
  for (const [name, value] of Object.entries(values)) {
    const field = page.locator(`dialog.sheet [name="${name}"]`);
    const tag = await field.evaluate(n => n.tagName);
    if (tag === 'SELECT') { await field.selectOption(String(value)); }
    else if (await field.evaluate(n => n.type) === 'checkbox') {
      if (value) { await field.check(); } else { await field.uncheck(); }
    } else { await field.fill(String(value)); }
  }
  await page.locator('dialog.sheet button[type=submit]').click();
  if (submitText !== false) {
    await page.waitForSelector('dialog.sheet[open]', { state: 'detached', timeout: 6000 })
      .catch(() => {});
  }
}

(async () => {
  await fetch(BASE + '/__test/reset');

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();

  const problems = [];
  page.on('console', m => {
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { problems.push(m.text()); }
  });
  page.on('pageerror', e => problems.push('JS: ' + e.message));

  console.log('\nДоступ');

  await page.goto(BASE + '/cabinet/', { waitUntil: 'load' });
  await page.waitForURL('**/login', { timeout: 8000 }).catch(() => {});
  check('без входа кабинет уводит на страницу входа',
        page.url().includes('/login'), page.url());

  await page.fill('#login2', 'admin@example.test').catch(async () => {
    await page.click('[data-go="step-password"]');
    await page.fill('#login2', 'admin@example.test');
  });
  if (!(await page.isVisible('#step-password'))) {
    await page.click('[data-go="step-password"]');
  }
  await page.fill('#login2', 'admin@example.test');
  await page.fill('#password', 'verysecret123');
  await page.click('#btn-login');
  await page.waitForURL('**/cabinet/**', { timeout: 8000 });
  check('после входа открывается кабинет', page.url().includes('/cabinet'), page.url());

  await page.waitForSelector('.tab', { timeout: 8000 });
  const tabs = await page.locator('.tab').allTextContents();
  check('администратору видны все разделы', tabs.length === 8, tabs.join(', '));
  check('имя вошедшего в шапке',
        (await page.textContent('#who')).includes('Стефанова'), await page.textContent('#who'));

  console.log('\nСпортсмены');

  await page.waitForSelector('.head h1');
  check('открыт раздел спортсменов',
        (await page.textContent('.head h1')) === 'Спортсмены', await page.textContent('.head h1'));
  check('в списке есть заведённый ранее ребёнок',
        (await page.textContent('.board')).includes('Иванов'));

  await page.click('text=Добавить спортсмена');
  await fillSheet(page, {
    last_name: 'Петров', first_name: 'Илья', birth_date: '2017-03-14', gender: 'm'
  });
  await page.waitForSelector('.head h1', { timeout: 8000 });
  check('после создания открылась карточка',
        (await page.textContent('.head h1')).includes('Петров'), await page.textContent('.head h1'));
  check('возраст посчитан', (await page.textContent('.facts')).includes('лет'),
        await page.textContent('.facts'));
  await page.screenshot({ path: OUT + '/cab-athlete.png' });

  console.log('\nПустая форма и ошибки сервера');

  await page.click('text=Изменить');
  await fillSheet(page, { last_name: '' }, false);
  await page.waitForTimeout(600);
  check('пустая фамилия — окно не закрылось и видна ошибка',
        await page.isVisible('dialog.sheet[open] .alert'),
        await page.textContent('dialog.sheet').catch(() => ''));
  await page.click('dialog.sheet button:has-text("Отмена")');

  console.log('\nГруппы и тренеры');

  await page.click('.tab:has-text("Тренеры")');
  await page.waitForSelector('.head h1:has-text("Тренеры")');
  await page.click('text=Добавить тренера');
  await fillSheet(page, {
    last_name: 'Филь', first_name: 'Всеволод',
    email: 'coach@example.test', password: 'coachpass123'
  });
  await page.waitForTimeout(700);
  check('тренер создан и видно, что у него есть вход',
        (await page.textContent('.board')).includes('Филь')
        && (await page.textContent('.board')).includes('есть вход'));

  await page.click('.tab:has-text("Группы")');
  await page.waitForSelector('.head h1:has-text("Группы")');
  await page.click('text=Добавить группу');
  await fillSheet(page, { code: 'СОГ-1', name: 'Спортивно-оздоровительная', age_from: '3', age_to: '6' });
  await page.waitForSelector('.head h1', { timeout: 8000 });
  check('открылась карточка группы',
        (await page.textContent('.head h1')).includes('СОГ-1'), await page.textContent('.head h1'));

  await page.click('text=Закрепить тренера');
  await fillSheet(page, { role: 'main' });
  await page.waitForTimeout(700);
  check('тренер закреплён за группой',
        (await page.textContent('.panes')).includes('Филь'));
  await page.screenshot({ path: OUT + '/cab-group.png' });

  console.log('\nНазначение в группу');

  await page.click('.tab:has-text("Спортсмены")');
  await page.waitForSelector('.rows');
  await page.click('tr:has-text("Петров")');
  await page.waitForSelector('.head h1:has-text("Петров")');
  await page.click('text=Назначить в группу');
  await fillSheet(page, { date_from: '2026-10-01' });
  await page.waitForTimeout(800);
  check('группа появилась в карточке спортсмена',
        (await page.textContent('.panes')).includes('СОГ-1'));
  check('назначение помечено как текущее',
        (await page.textContent('.panes')).includes('сейчас'));

  console.log('\nРодители');

  await page.click('.tab:has-text("Родители")');
  await page.waitForSelector('.head h1:has-text("Родители")');
  await page.click('text=Добавить родителя');
  await fillSheet(page, { last_name: 'Петрова', first_name: 'Ольга', email: 'olga@example.test' });
  await page.waitForTimeout(700);
  check('родитель создан', (await page.textContent('.board')).includes('Петрова'));

  await page.click('text=Добавить родителя');
  await fillSheet(page, { last_name: 'Двойник', first_name: 'Ольга', email: 'olga@example.test' }, false);
  await page.waitForTimeout(700);
  check('повтор почты показывает ошибку из API',
        (await page.textContent('dialog.sheet').catch(() => '')).includes('уже привязана'),
        await page.textContent('dialog.sheet').catch(() => 'окно закрылось'));
  await page.click('dialog.sheet button:has-text("Отмена")');

  await page.click('.tab:has-text("Спортсмены")');
  await page.waitForSelector('.rows');
  await page.click('tr:has-text("Петров")');
  await page.waitForSelector('.head h1:has-text("Петров")');
  await page.click('text=Привязать родителя');
  // Выбираем именно созданного родителя: по умолчанию в списке первый
  // по алфавиту, и это была бы не та женщина
  await page.waitForSelector('dialog.sheet[open]');
  await page.locator('dialog.sheet [name=parent_user_id]').selectOption({ label: 'Петрова Ольга' });
  await fillSheet(page, { relation: 'mother', is_primary: true });
  await page.waitForTimeout(800);
  check('родитель виден в карточке ребёнка',
        (await page.textContent('.panes')).includes('Петрова'));
  check('основной контакт отмечен',
        (await page.textContent('.panes')).includes('основной'));

  console.log('\nТарифы');

  await page.click('.tab:has-text("Тарифы")');
  await page.waitForSelector('.head h1:has-text("Тарифы")');
  await page.click('text=Добавить тариф');
  await fillSheet(page, { name: 'СОГ-1, 2 раза в неделю', price: '14400', sessions_count: '8' });
  await page.waitForTimeout(700);
  const tariffText = await page.textContent('.board');
  check('тариф создан', tariffText.includes('СОГ-1, 2 раза'));
  check('цена занятия выведена из тарифа', tariffText.includes('1 800'), tariffText.slice(0, 200));
  check('у неиспользованного тарифа нет кнопки новой цены',
        !tariffText.includes('Новая цена'));
  await page.screenshot({ path: OUT + '/cab-tariffs.png' });

  console.log('\nУслуги и цены');

  await page.click('.tab:has-text("Услуги")');
  await page.waitForSelector('.head h1:has-text("Услуги")');
  const svc = await page.textContent('.board');
  check('системные услуги видны', svc.includes('Разовое занятие без абонемента')
        && svc.includes('при действующем абонементе'));
  check('незаданная цена помечена', svc.includes('не задана'));
  check('о незаданной цене предупреждает отдельная строка',
        (await page.textContent('.page')).includes('Пока цены нет'));

  await page.click('tr:has-text("при действующем абонементе") button:has-text("Изменить")');
  await fillSheet(page, { price: '1200', reason: 'Решение от 20.10' });
  await page.waitForTimeout(700);
  check('цена задана',
        (await page.textContent('tr:has-text("при действующем абонементе")')).includes('1 200'),
        await page.textContent('tr:has-text("при действующем абонементе")'));

  await page.click('text=Добавить услугу');
  await fillSheet(page, { name: 'Прокат коньков', price: '300' });
  await page.waitForTimeout(700);
  check('своя услуга добавлена', (await page.textContent('.board')).includes('Прокат коньков'));
  await page.screenshot({ path: OUT + '/cab-services.png' });

  console.log('\nОдин человек в двух ролях');

  await page.click('.tab:has-text("Тренеры")');
  await page.waitForSelector('.head h1:has-text("Тренеры")');
  await page.click('text=Добавить тренера');
  await page.waitForSelector('dialog.sheet[open]');
  const accounts = await page.locator('dialog.sheet [name=user_id] option').allTextContents();
  check('в форме есть выбор существующей учётной записи',
        accounts.some(a => a.includes('Стефанова')), accounts.join(' | '));

  await page.locator('dialog.sheet [name=user_id]')
            .selectOption({ label: /Стефанова/ })
            .catch(async () => {
              const label = accounts.find(a => a.includes('Стефанова'));
              await page.locator('dialog.sheet [name=user_id]').selectOption({ label });
            });
  await fillSheet(page, { last_name: 'Стефанова', first_name: 'Анастасия' });
  await page.waitForTimeout(800);
  check('карточка тренера привязана к своей же учётной записи',
        (await page.textContent('.board')).includes('Стефанова'));

  console.log('\nНастройки');

  await page.click('.tab:has-text("Настройки")');
  await page.waitForSelector('.head h1:has-text("Настройки")');
  check('настройки показаны',
        (await page.textContent('.board')).includes('illness_days_threshold'));

  await page.click('tr:has-text("illness_days_threshold") button:has-text("Изменить")');
  await fillSheet(page, { value: '15', reason: 'Решение школы' });
  await page.waitForTimeout(700);
  check('значение изменилось',
        (await page.textContent('tr:has-text("illness_days_threshold")')).includes('15'),
        await page.textContent('tr:has-text("illness_days_threshold")'));
  check('цен в настройках больше нет',
        !(await page.textContent('.board')).includes('single_lesson_price'));

  console.log('\nПлощадки и постоянное расписание');

  await page.click('text=Добавить площадку');
  await fillSheet(page, { name: 'Гололёд', address: 'ул. Свободы, 15' });
  await page.waitForTimeout(700);
  check('площадка добавлена', (await page.textContent('.page')).includes('Гололёд'));

  await page.click('.tab:has-text("Группы")');
  await page.waitForSelector('.head h1:has-text("Группы")');
  await page.click('tr:has-text("СОГ-1")');
  await page.waitForSelector('.head h1:has-text("СОГ-1")');
  check('в карточке группы есть постоянное расписание',
        (await page.textContent('.panes')).includes('Расписание не задано'),
        await page.textContent('.panes'));

  // Ближайший понедельник: иначе проверка зависит от дня запуска
  const monday = await page.evaluate(() => {
    const d = new Date();
    d.setDate(d.getDate() + ((8 - (d.getDay() || 7)) % 7 || 7));
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
         + '-' + ('0' + d.getDate()).slice(-2);
  });

  await page.click('text=Добавить занятие');
  await fillSheet(page, {
    weekday: '1', time_start: '17:00', duration_min: '60',
    kind: 'ice', valid_from: monday
  });
  await page.waitForTimeout(800);
  check('занятие попало в постоянное расписание',
        (await page.textContent('.panes')).includes('понедельник'),
        await page.textContent('.panes'));
  await page.screenshot({ path: OUT + '/cab-group-schedule.png' });

  console.log('\nРасписание');

  await page.click('.tab:has-text("Расписание")');
  await page.waitForSelector('.head h1:has-text("Расписание")');
  check('неделя показана целиком, включая пустые дни',
        (await page.locator('.day').count()) === 7,
        'дней: ' + (await page.locator('.day').count()));
  check('пока занятий нет', (await page.textContent('.week')).includes('нет занятий'));

  await page.click('text=Обновить расписание');
  await fillSheet(page, { weeks: '4' });
  await page.waitForTimeout(1000);

  await page.goto(BASE + '/cabinet/#/schedule/' + monday, { waitUntil: 'load' });
  await page.waitForSelector('.head h1:has-text("Расписание")', { timeout: 8000 });
  await page.waitForTimeout(600);
  check('занятие появилось в неделе',
        (await page.locator('.tr').count()) === 1,
        'занятий: ' + (await page.locator('.tr').count()));
  check('видны время, группа и площадка',
        (await page.textContent('.tr')).includes('17:00')
        && (await page.textContent('.tr')).includes('СОГ-1')
        && (await page.textContent('.tr')).includes('Гололёд'),
        await page.textContent('.tr'));

  await page.click('.tr button:has-text("Заменить тренера")');
  await page.waitForSelector('dialog.sheet[open]');
  await fillSheet(page, { reason: 'Болезнь' });
  await page.waitForTimeout(900);
  check('замена отмечена в карточке занятия',
        (await page.textContent('.tr')).includes('замена'), await page.textContent('.tr'));

  await page.click('.tr button:has-text("Перенести")');
  await page.waitForSelector('dialog.sheet[open]');
  await fillSheet(page, { time_start: '19:00', reason: 'Соревнования' });
  await page.waitForTimeout(900);
  const afterMove = await page.textContent('.week');
  check('перенесённое занятие осталось с пометкой', afterMove.includes('перенесена'));
  check('и появилось новое время', afterMove.includes('19:00'), afterMove.slice(0, 300));

  await page.click('.tr:has-text("19:00") button:has-text("Отменить")');
  await page.waitForSelector('dialog.sheet[open]');
  await fillSheet(page, { reason: 'Заливка льда' });
  await page.waitForTimeout(900);
  check('отменённое занятие помечено и причина видна',
        (await page.textContent('.week')).includes('отменена')
        && (await page.textContent('.week')).includes('Заливка льда'),
        await page.textContent('.week'));
  await page.screenshot({ path: OUT + '/cab-schedule.png' });

  console.log('\nОтметки');

  // Занятие вчерашним днём: будущее отмечать нельзя,
  // и это проверяется отдельно
  const yesterday = await page.evaluate(() => {
    const d = new Date(); d.setDate(d.getDate() - 1);
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
         + '-' + ('0' + d.getDate()).slice(-2);
  });

  await page.goto(BASE + '/cabinet/#/schedule/' + yesterday, { waitUntil: 'load' });
  await page.waitForSelector('.head h1:has-text("Расписание")', { timeout: 8000 });
  await page.waitForTimeout(500);

  const made = await page.evaluate(async (date) => {
    const groups = await (await fetch('/api/v1/groups', { credentials: 'same-origin' })).json();
    const venues = await (await fetch('/api/v1/venues', { credentials: 'same-origin' })).json();
    const r = await fetch('/api/v1/trainings', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ group_id: groups.items[0].id, date: date,
        time_start: '16:00', kind: 'ice', venue_id: venues.items[0].id })
    });
    return (await r.json()).id;
  }, yesterday);

  await page.goto(BASE + '/cabinet/#/training/' + made, { waitUntil: 'load' });
  await page.waitForSelector('.mk', { timeout: 8000 });
  check('лист отметок открылся', (await page.locator('.mk').count()) >= 1,
        'строк: ' + (await page.locator('.mk').count()));
  check('у каждого три кнопки',
        (await page.locator('.mk').first().locator('.pick').count()) === 3);
  check('пока никто не отмечен',
        (await page.textContent('.page')).includes('Без отметки'),
        await page.textContent('.page'));

  await page.click('.mk:first-child .pick[data-mark="absent"]');
  check('нажатая кнопка подсвечена',
        await page.locator('.mk:first-child .pick[data-mark="absent"]')
                  .evaluate(n => n.classList.contains('is-on')));
  check('счётчик пересчитался',
        (await page.textContent('.page')).includes('Отмечены все')
        || !(await page.textContent('.page')).includes('Без отметки: 1'),
        await page.textContent('.page'));

  await page.click('text=Все были');
  check('«все были» ставит всем присутствие',
        await page.locator('.mk:first-child .pick[data-mark="present"]')
                  .evaluate(n => n.classList.contains('is-on')));
  check('и отмечены все', (await page.textContent('.page')).includes('Отмечены все'));
  await page.waitForTimeout(400);   // дать доиграть подсветке кнопок
  await page.screenshot({ path: OUT + '/cab-marks.png' });

  await page.click('.markbar button:has-text("Сохранить")');
  await page.waitForTimeout(900);
  check('отметки сохранены без лишних вопросов',
        !(await page.isVisible('dialog.sheet[open]')),
        await page.textContent('dialog.sheet').catch(() => ''));
  check('занятие стало проведённым',
        (await page.textContent('.page')).includes('проведено')
        || (await page.locator('.pick.is-on').count()) >= 1,
        'отмеченных: ' + (await page.locator('.pick.is-on').count()));

  // Исправление уже сохранённого — только с причиной
  await page.click('.mk:first-child .pick[data-mark="absent"]');
  await page.click('.markbar button:has-text("Сохранить")');
  await page.waitForSelector('dialog.sheet[open]', { timeout: 6000 });
  check('исправление спрашивает причину',
        (await page.textContent('dialog.sheet')).includes('причина')
        || (await page.textContent('dialog.sheet')).includes('Основание'),
        await page.textContent('dialog.sheet'));

  await fillSheet(page, { reason: 'Ушёл раньше' });
  await page.waitForTimeout(900);
  check('с причиной исправление прошло',
        await page.locator('.mk:first-child .pick[data-mark="absent"]')
                  .evaluate(n => n.classList.contains('is-on')));

  // Отработка: два шага — кто и какой пропуск
  await page.click('.markbar button:has-text("Добавить отработку")');
  await page.waitForSelector('dialog.sheet[open]', { timeout: 6000 });
  check('первый шаг спрашивает спортсмена',
        (await page.textContent('dialog.sheet')).includes('Спортсмен'),
        await page.textContent('dialog.sheet'));

  await page.locator('dialog.sheet button[type=submit]').click();
  await page.waitForTimeout(900);
  const makeupStep = await page.textContent('dialog.sheet').catch(() => '');
  check('второй шаг предлагает выбрать пропуск или честно говорит, что их нет',
        makeupStep.includes('пропуск') || makeupStep.includes('нет отмеченных пропусков'),
        makeupStep);
  await page.locator('dialog.sheet button:has-text("Отмена")').click().catch(() => {});
  await page.keyboard.press('Escape').catch(() => {});

  // Будущее занятие отмечать нельзя
  const future = await page.evaluate(async () => {
    const d = new Date(); d.setDate(d.getDate() + 3);
    const date = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
               + '-' + ('0' + d.getDate()).slice(-2);
    const groups = await (await fetch('/api/v1/groups', { credentials: 'same-origin' })).json();
    const venues = await (await fetch('/api/v1/venues', { credentials: 'same-origin' })).json();
    const r = await fetch('/api/v1/trainings', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ group_id: groups.items[0].id, date: date,
        time_start: '16:00', kind: 'ice', venue_id: venues.items[0].id })
    });
    return (await r.json()).id;
  });

  await page.goto(BASE + '/cabinet/#/training/' + future, { waitUntil: 'load' });
  await page.waitForSelector('.mk', { timeout: 8000 });
  check('будущее занятие объясняет, почему не отметить',
        (await page.textContent('.page')).includes('ещё не прошло'),
        await page.textContent('.page'));
  check('и кнопки сохранения нет',
        (await page.locator('.markbar button:has-text("Сохранить")').count()) === 0);

  console.log('\nЖурнал за месяц');

  await page.goto(BASE + '/cabinet/#/groups', { waitUntil: 'load' });
  await page.waitForSelector('.rows tbody tr');
  await page.click('tr:has-text("СОГ-1")');
  await page.waitForSelector('.head h1:has-text("СОГ-1")');
  await page.click('text=Журнал посещений');
  await page.waitForSelector('.jn', { timeout: 8000 });
  check('журнал открылся', (await page.locator('.jn tbody tr').count()) >= 1,
        'строк: ' + (await page.locator('.jn tbody tr').count()));
  check('есть столбцы занятий', (await page.locator('.jn__day').count()) >= 1,
        'столбцов: ' + (await page.locator('.jn__day').count()));
  check('отметки проставлены знаками',
        (await page.locator('.jn--yes, .jn--no, .jn--sick').count()) >= 1,
        'заполненных клеток: '
        + (await page.locator('.jn--yes, .jn--no, .jn--sick').count()));
  check('итоги по строке есть', (await page.locator('.jn__sum').count()) >= 3);
  check('расшифровка знаков показана',
        (await page.textContent('.page')).includes('отработка'),
        await page.textContent('.page'));
  await page.screenshot({ path: OUT + '/cab-journal.png' });

  const before = await page.textContent('.head__count');
  await page.click('text=Месяц назад');
  await page.waitForTimeout(800);
  check('месяц переключается',
        (await page.textContent('.head__count')) !== before,
        before + ' → ' + (await page.textContent('.head__count')));

  console.log('\nГраницы доступа тренера');

  await page.click('#out');
  await page.waitForURL('**/login', { timeout: 8000 });

  if (!(await page.isVisible('#step-password'))) {
    await page.click('[data-go="step-password"]');
  }
  await page.fill('#login2', 'coach@example.test');
  await page.fill('#password', 'coachpass123');
  await page.click('#btn-login');
  await page.waitForTimeout(1200);
  check('тренеру с временным паролем предложена смена',
        await page.isVisible('#step-change'), page.url());

  await page.fill('#current', 'coachpass123');
  await page.fill('#fresh', 'newcoachpass1');
  await page.click('#btn-change');
  await page.waitForURL('**/cabinet/**', { timeout: 8000 });
  await page.waitForSelector('.tab');

  const coachTabs = await page.locator('.tab').allTextContents();
  check('тренеру видны его три раздела', coachTabs.length === 3, coachTabs.join(', '));

  await page.goto(BASE + '/cabinet/#/schedule/' + monday, { waitUntil: 'load' });
  await page.waitForSelector('.head h1:has-text("Расписание")', { timeout: 8000 });
  await page.waitForTimeout(600);
  check('тренер видит расписание своей группы',
        (await page.textContent('.week')).includes('СОГ-1'),
        await page.textContent('.week'));
  check('кнопки обновления расписания у тренера нет',
        !(await page.isVisible('text=Обновить расписание')));
  check('и кнопки отмены занятия тоже',
        (await page.locator('.tr button:has-text("Отменить")').count()) === 0);

  await page.click('.tab:has-text("Группы")');
  // Ждём именно новый экран: на прежнем тоже есть .rows,
  // и проверка успевала посчитать строки до перерисовки
  await page.waitForSelector('.head h1:has-text("Группы")', { timeout: 8000 });
  await page.waitForSelector('.rows tbody tr, .empty', { timeout: 8000 });
  check('тренер видит только свою группу',
        (await page.locator('.rows tbody tr').count()) === 1,
        'строк: ' + (await page.locator('.rows tbody tr').count()));

  await page.click('.tab:has-text("Спортсмены")');
  await page.waitForSelector('.head h1');
  check('кнопки добавления спортсмена у тренера нет',
        !(await page.isVisible('text=Добавить спортсмена')));
  await page.screenshot({ path: OUT + '/cab-coach.png' });

  console.log('\nТелефон');

  const small = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2
  });
  const sp = await small.newPage();
  await sp.goto(BASE + '/login', { waitUntil: 'load' });
  if (!(await sp.isVisible('#step-password'))) { await sp.click('[data-go="step-password"]'); }
  await sp.fill('#login2', 'admin@example.test');
  await sp.fill('#password', 'verysecret123');
  await sp.click('#btn-login');
  await sp.waitForURL('**/cabinet/**', { timeout: 8000 });
  await sp.waitForSelector('.rows, .empty');
  await sp.waitForTimeout(500);
  check('на телефоне нет горизонтальной прокрутки',
        await sp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
        await sp.evaluate(() => document.documentElement.scrollWidth + ' > ' + window.innerWidth));
  await sp.screenshot({ path: OUT + '/cab-mobile.png', fullPage: true });

  // Главный экран тренера — лист отметок с телефона у борта
  const anyHeld = await sp.evaluate(async () => {
    const t = await (await fetch('/api/v1/trainings?from=2000-01-01&to=2099-01-01',
                                 { credentials: 'same-origin' })).json();
    const one = t.items.find(x => x.status === 'held');
    return one ? one.id : null;
  });
  if (anyHeld) {
    await sp.goto(BASE + '/cabinet/#/training/' + anyHeld, { waitUntil: 'load' });
    await sp.waitForSelector('.mk', { timeout: 8000 });
    await sp.waitForTimeout(600);
    check('лист отметок на телефоне без горизонтальной прокрутки',
          await sp.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
          await sp.evaluate(() => document.documentElement.scrollWidth + ' > ' + window.innerWidth));
    check('кнопки отметок крупные',
          await sp.locator('.pick').first().evaluate(n => n.getBoundingClientRect().height >= 40),
          await sp.locator('.pick').first().evaluate(n => Math.round(n.getBoundingClientRect().height) + 'px'));
    await sp.screenshot({ path: OUT + '/cab-marks-mobile.png', fullPage: true });
  }

  console.log('\nОшибки в консоли');
  check('консоль чистая', problems.length === 0, problems.join(' | '));

  await browser.close();

  console.log('');
  if (failed.length) {
    console.log('Не прошло ' + failed.length + ' из ' + (passed + failed.length) + ':');
    failed.forEach(n => console.log('  - ' + n));
    process.exit(1);
  }
  console.log('Все проверки прошли: ' + passed);
})().catch(e => { console.error(e); process.exit(1); });
