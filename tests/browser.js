// Проверка страницы входа настоящим браузером
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8921';
const OUT  = process.env.OUT  || require('path').join(__dirname, 'screenshots');
require('fs').mkdirSync(OUT, { recursive: true });

let passed = 0;
const failed = [];

function check(name, condition, details = '') {
  if (condition) { passed++; console.log('  ✓ ' + name); }
  else { failed.push(name + (details ? ' — ' + details : '')); console.log('  ✗ ' + name + (details ? ' — ' + details : '')); }
}

(async () => {
  await fetch(BASE + '/__test/reset');   // чистая база на каждый прогон
  const browser = await chromium.launch();

  // Телефон: родители заходят именно так
  const phone = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });
  const page = await phone.newPage();

  const problems = [];
  page.on('console', m => {
    // «Failed to load resource» на 401 Chromium пишет всегда,
    // когда fetch получает не 2xx. Это не ошибка страницы.
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) {
      problems.push(m.text());
    }
  });
  page.on('pageerror', e => problems.push('JS: ' + e.message));

  await page.goto(BASE + '/cabinet/login.html', { waitUntil: 'load' });
  // Ждём, пока скрипт решит, что показывать: форму или уже вошедшего
  await page.waitForFunction(
    () => ['step-login', 'step-done', 'step-change']
            .some(id => document.getElementById(id) && !document.getElementById(id).hidden),
    null, { timeout: 10000 });

  console.log('\nПервый экран');
  check('заголовок на месте', (await page.textContent('#h-login')).includes('Вход'));
  check('видна только первая карточка', await page.isVisible('#step-login')
        && !(await page.isVisible('#step-code')));
  check('шрифт Prata применился',
        (await page.evaluate(() => getComputedStyle(document.querySelector('.h1')).fontFamily)).includes('Prata'));
  check('нет горизонтальной прокрутки',
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
        await page.evaluate(() => document.documentElement.scrollWidth + ' > ' + window.innerWidth));
  await page.screenshot({ path: OUT + '/login-mobile.png', fullPage: true });

  console.log('\nВыбор канала по введённому логину');
  await page.fill('#login', 'parent@example.test');
  check('для почты СМС отключены', await page.isDisabled('input[value=sms]'));
  check('канал «почта» выбран сам', await page.isChecked('input[value=email]'));
  await page.fill('#login', '+7 999 000 00 02');
  check('для телефона почта отключена', await page.isDisabled('input[value=email]'));
  check('канал СМС выбран сам', await page.isChecked('input[value=sms]'));

  console.log('\nПустая форма');
  await page.fill('#login', '');
  await page.click('#btn-code');
  check('пустое поле — своё сообщение, без запроса к серверу',
        await page.isVisible('#alert-login'));

  console.log('\nВход по коду');
  await page.fill('#login', 'parent@example.test');
  await page.check('input[value=email]');
  await page.click('#btn-code');
  await page.waitForSelector('#step-code:not([hidden])', { timeout: 8000 });
  check('перешли к вводу кода', await page.isVisible('#step-code'));
  check('подсказка называет адрес',
        (await page.textContent('#code-lede')).includes('@'),
        await page.textContent('#code-lede'));
  check('повтор отправки сперва закрыт отсчётом', await page.isDisabled('#btn-resend'));

  await page.fill('#code', 'a1b2c3');          // меньше шести цифр: сама не отправит
  check('буквы в поле кода не попадают', (await page.inputValue('#code')) === '123',
        'в поле осталось: ' + (await page.inputValue('#code')));

  await page.fill('#code', '');
  await page.fill('#code', '111111');          // неверный
  await page.waitForSelector('#alert-code:not([hidden])', { timeout: 8000 });
  check('неверный код — сообщение на странице', await page.isVisible('#alert-code'));
  check('поле очищено для повторного ввода', (await page.inputValue('#code')) === '');
  await page.screenshot({ path: OUT + '/login-code-error.png', fullPage: true });

  // Верный код кладёт в базу тестовый сервер по адресу /__test/code
  const code = await (await fetch(BASE + '/__test/code?login=parent@example.test')).text();
  await page.fill('#code', code.trim());
  await page.waitForSelector('#step-done:not([hidden])', { timeout: 8000 });
  check('верный код — вошли', await page.isVisible('#step-done'));
  check('показано имя', (await page.textContent('#done-who')).includes('Иванова'),
        await page.textContent('#done-who'));
  check('показаны дети', (await page.textContent('#done-next')).includes('Иванов'));
  await page.screenshot({ path: OUT + '/login-done.png', fullPage: true });

  console.log('\nСессия');
  await page.reload({ waitUntil: 'load' });
  await page.waitForTimeout(600);
  check('после перезагрузки вход помнится', await page.isVisible('#step-done'));
  await page.click('#btn-logout');
  await page.waitForSelector('#step-login:not([hidden])', { timeout: 8000 });
  check('выход возвращает к форме', await page.isVisible('#step-login'));
  await page.reload({ waitUntil: 'load' });
  await page.waitForTimeout(600);
  check('после выхода вход не помнится', await page.isVisible('#step-login'));

  console.log('\nВход по паролю');
  await page.click('[data-go="step-password"]');
  check('открылась форма пароля', await page.isVisible('#step-password'));
  await page.fill('#login2', 'admin@example.test');
  await page.fill('#password', 'неверный');
  await page.click('#btn-login');
  await page.waitForSelector('#alert-password:not([hidden])', { timeout: 8000 });
  check('неверный пароль — сообщение', await page.isVisible('#alert-password'));
  check('поле пароля очищено', (await page.inputValue('#password')) === '');

  await page.fill('#password', 'verysecret123');
  await page.click('#btn-login');
  await page.waitForSelector('#step-done:not([hidden])', { timeout: 8000 });
  check('верный пароль — вошли', await page.isVisible('#step-done'));
  check('роль показана', (await page.textContent('#done-next')).includes('Администратор'));

  console.log('\nОшибки в консоли');
  check('консоль чистая', problems.length === 0, problems.join(' | '));

  // Настольный вид — для снимка
  const desk = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const deskPage = await desk.newPage();
  await deskPage.goto(BASE + '/cabinet/login.html', { waitUntil: 'load' });
  await deskPage.screenshot({ path: OUT + '/login-desktop.png' });

  await browser.close();

  console.log('');
  if (failed.length) {
    console.log('Не прошло ' + failed.length + ' из ' + (passed + failed.length) + ':');
    failed.forEach(n => console.log('  - ' + n));
    process.exit(1);
  }
  console.log('Все проверки прошли: ' + passed);
})().catch(e => { console.error(e); process.exit(1); });
