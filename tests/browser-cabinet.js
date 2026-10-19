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
  check('администратору видны все шесть разделов', tabs.length === 6, tabs.join(', '));
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

  console.log('\nНастройки');

  await page.click('.tab:has-text("Настройки")');
  await page.waitForSelector('.head h1:has-text("Настройки")');
  check('настройки показаны',
        (await page.textContent('.board')).includes('single_lesson_price'));

  await page.click('tr:has-text("single_lesson_price") button:has-text("Изменить")');
  await fillSheet(page, { value: '2600', reason: 'Цены с января' });
  await page.waitForTimeout(700);
  check('значение изменилось',
        (await page.textContent('tr:has-text("single_lesson_price")')).includes('2600'),
        await page.textContent('tr:has-text("single_lesson_price")'));

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
  check('тренеру видны только два раздела', coachTabs.length === 2, coachTabs.join(', '));

  await page.click('.tab:has-text("Группы")');
  await page.waitForSelector('.rows, .empty');
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
