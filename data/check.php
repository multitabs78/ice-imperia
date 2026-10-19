<?php
/* =========================================================
   Империя льда — проверка файлов данных
   ---------------------------------------------------------
   Откройте в браузере:  https://ice-imperia.ru/data/check.php

   Страница показывает, правильно ли заполнены groups.json
   и team.json, и, если в файле опечатка, называет строку.
   Ничего не меняет — только читает и проверяет.
   ========================================================= */

require __DIR__ . '/render.php';

header('Content-Type: text/html; charset=utf-8');

/**
 * Разбирает JSON и при ошибке сообщает номер строки.
 * Нужен только здесь: json_decode сам место ошибки не показывает.
 */
function imp_check_json($raw)
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $p = array('s' => $raw, 'i' => 0, 'n' => strlen($raw), 'err' => null);

    imp_ws($p);
    imp_value($p);
    imp_ws($p);

    if ($p['err'] === null && $p['i'] < $p['n']) {
        $p['err'] = array('Лишний текст после закрывающей скобки', $p['i']);
    }

    if ($p['err'] === null) {
        return null;
    }

    $line = substr_count(substr($raw, 0, $p['err'][1]), "\n") + 1;
    $lines = explode("\n", $raw);
    $text  = isset($lines[$line - 1]) ? trim($lines[$line - 1]) : '';

    return array(
        'message' => $p['err'][0],
        'line'    => $line,
        'text'    => $text,
    );
}

function imp_ws(&$p)
{
    while ($p['i'] < $p['n'] && strpos(" \t\r\n", $p['s'][$p['i']]) !== false) {
        $p['i']++;
    }
}

function imp_fail(&$p, $message)
{
    if ($p['err'] === null) {
        $p['err'] = array($message, $p['i']);
    }
}

function imp_value(&$p)
{
    if ($p['err'] !== null) {
        return;
    }
    if ($p['i'] >= $p['n']) {
        imp_fail($p, 'Файл закончился раньше, чем закрылись все скобки');
        return;
    }

    $c = $p['s'][$p['i']];

    if ($c === '{') { imp_object($p); return; }
    if ($c === '[') { imp_array($p);  return; }
    if ($c === '"') { imp_string($p); return; }

    if (preg_match('/^(true|false|null)/', substr($p['s'], $p['i'], 5), $m)) {
        $p['i'] += strlen($m[1]);
        return;
    }
    if (preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?/', substr($p['s'], $p['i'], 40), $m)) {
        $p['i'] += strlen($m[0]);
        return;
    }

    imp_fail($p, 'Непонятное значение. Текст нужно брать в двойные кавычки');
}

function imp_string(&$p)
{
    $p['i']++; // открывающая кавычка
    while ($p['i'] < $p['n']) {
        $c = $p['s'][$p['i']];
        if ($c === '\\') { $p['i'] += 2; continue; }
        if ($c === '"')  { $p['i']++; return; }
        if ($c === "\n") { imp_fail($p, 'Кавычка не закрыта до конца строки'); return; }
        $p['i']++;
    }
    imp_fail($p, 'Кавычка не закрыта');
}

function imp_object(&$p)
{
    $p['i']++; // {
    imp_ws($p);

    if ($p['i'] < $p['n'] && $p['s'][$p['i']] === '}') { $p['i']++; return; }

    while ($p['err'] === null) {
        imp_ws($p);

        if ($p['i'] < $p['n'] && $p['s'][$p['i']] === '}') {
            imp_fail($p, 'Лишняя запятая перед закрывающей скобкой }');
            return;
        }
        if ($p['i'] >= $p['n'] || $p['s'][$p['i']] !== '"') {
            imp_fail($p, 'Ожидается название поля в двойных кавычках');
            return;
        }

        imp_string($p);
        imp_ws($p);

        if ($p['i'] >= $p['n'] || $p['s'][$p['i']] !== ':') {
            imp_fail($p, 'После названия поля нужно двоеточие');
            return;
        }
        $p['i']++;
        imp_ws($p);
        imp_value($p);
        imp_ws($p);

        if ($p['err'] !== null) { return; }
        if ($p['i'] >= $p['n']) {
            imp_fail($p, 'Не закрыта скобка }');
            return;
        }

        $c = $p['s'][$p['i']];
        if ($c === ',') { $p['i']++; continue; }
        if ($c === '}') { $p['i']++; return; }

        imp_fail($p, 'Пропущена запятая между полями');
        return;
    }
}

function imp_array(&$p)
{
    $p['i']++; // [
    imp_ws($p);

    if ($p['i'] < $p['n'] && $p['s'][$p['i']] === ']') { $p['i']++; return; }

    while ($p['err'] === null) {
        imp_ws($p);

        if ($p['i'] < $p['n'] && $p['s'][$p['i']] === ']') {
            imp_fail($p, 'Лишняя запятая перед закрывающей скобкой ]');
            return;
        }

        imp_value($p);
        imp_ws($p);

        if ($p['err'] !== null) { return; }
        if ($p['i'] >= $p['n']) {
            imp_fail($p, 'Не закрыта скобка ]');
            return;
        }

        $c = $p['s'][$p['i']];
        if ($c === ',') { $p['i']++; continue; }
        if ($c === ']') { $p['i']++; return; }

        imp_fail($p, 'Пропущена запятая между значениями');
        return;
    }
}

/** Собирает отчёт по одному файлу. */
function imp_report($file)
{
    $path = __DIR__ . '/' . $file;
    $r = array('file' => $file, 'ok' => false, 'error' => null, 'notes' => array(), 'data' => null);

    if (!is_file($path)) {
        $r['error'] = array('message' => 'Файл не найден. Загрузите его в папку data/', 'line' => 0, 'text' => '');
        return $r;
    }

    $raw = file_get_contents($path);
    $problem = imp_check_json($raw);

    if ($problem) {
        $r['error'] = $problem;
        return $r;
    }

    $r['ok']   = true;
    $r['data'] = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw), true);
    return $r;
}

$groups = imp_report('groups.json');
$team   = imp_report('team.json');

// Что получилось из groups.json
if ($groups['ok']) {
    $sets = isset($groups['data']['sets']) ? (array)$groups['data']['sets'] : array();
    if (!$sets) {
        $groups['notes'][] = array('warn', 'В файле нет ни одного блока «sets» — секция на сайте будет пустой');
    }
    foreach ($sets as $set) {
        $cards = isset($set['cards']) ? (array)$set['cards'] : array();
        $shown = 0;
        $names = array();
        foreach ($cards as $card) {
            if (!empty($card['hide'])) { continue; }
            $shown++;
            $name = isset($card['name']) ? trim($card['name']) : '';
            if ($name === '') {
                $groups['notes'][] = array('warn', 'В блоке «' . imp_get($set, 'title', '—') . '» у карточки не указано поле name');
            } else {
                $names[] = $name;
            }
            if (empty($card['price'])) {
                $groups['notes'][] = array('warn', 'У группы «' . $name . '» не указана цена (поле price)');
            }
            if (empty($card['schedule'])) {
                $groups['notes'][] = array('warn', 'У группы «' . $name . '» нет расписания — кнопка «Расписание и подробнее» не появится');
            }
        }
        $groups['notes'][] = array('ok', 'Блок «' . imp_get($set, 'title', '—') . '»: ' . $shown
            . ' ' . imp_plural($shown, 'группа', 'группы', 'групп')
            . ($names ? ' — ' . implode(', ', $names) : ''));

        $hidden = count($cards) - $shown;
        if ($hidden > 0) {
            $groups['notes'][] = array('info', 'В этом блоке скрыто (hide: true): ' . $hidden);
        }
    }
}

// Что получилось из team.json
if ($team['ok']) {
    $people = array();
    if (!empty($team['data']['lead_card'])) {
        $people[] = $team['data']['lead_card'];
    }
    foreach ((array)imp_get($team['data'], 'coaches', array()) as $coach) {
        $people[] = $coach;
    }
    if (!$people) {
        $team['notes'][] = array('warn', 'В файле нет ни одного человека — секция «Команда» будет пустой');
    }
    foreach ($people as $person) {
        $name = isset($person['name']) ? trim($person['name']) : '';
        if ($name === '') {
            $team['notes'][] = array('warn', 'У одной из карточек не заполнено поле name');
            continue;
        }
        if (!empty($person['hide'])) {
            $team['notes'][] = array('info', '«' . $name . '» скрыт на сайте (hide: true)');
            continue;
        }

        $photo = isset($person['photo']) ? trim($person['photo']) : '';
        if ($photo === '') {
            $team['notes'][] = array('warn', '«' . $name . '»: фотография не указана — карточка скрыта от посетителей, пока не появится кадр');
            continue;
        }

        $stem = (strpos($photo, '/') !== false) ? $photo : 'assets/img/' . $photo;
        $stem = preg_replace('/\.(jpe?g|png|webp)$/i', '', $stem);

        $jpg  = is_file(imp_root($stem . '.jpg')) || is_file(imp_root($stem . '.png'));
        $webp = is_file(imp_root($stem . '.webp'));

        if (!$jpg) {
            $team['notes'][] = array('bad', '«' . $name . '»: нет файла ' . $stem . '.jpg — фотография не покажется');
        } elseif (!$webp) {
            $team['notes'][] = array('info', '«' . $name . '»: фотография на месте, облегчённой версии ' . basename($stem) . '.webp нет — будет загружаться jpg');
        } else {
            $team['notes'][] = array('ok', '«' . $name . '»: фотография и облегчённая версия на месте');
        }
    }
}

function imp_plural($n, $one, $few, $many)
{
    $n = abs($n) % 100;
    $d = $n % 10;
    if ($n > 10 && $n < 20) { return $many; }
    if ($d > 1 && $d < 5)   { return $few; }
    if ($d === 1)           { return $one; }
    return $many;
}

function imp_icon($kind)
{
    $map = array('ok' => '✓', 'info' => 'i', 'warn' => '!', 'bad' => '×');
    return isset($map[$kind]) ? $map[$kind] : '·';
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Проверка файлов данных — Империя льда</title>
<style>
  :root{
    --ink:#2C2416; --sand:#F8F5F0; --blue:#0095BA; --gold:#CD9E55;
    --ok:#2f7d32; --bad:#b3261e; --warn:#8a6100;
  }
  *{box-sizing:border-box}
  body{
    margin:0; padding:32px 20px 64px;
    font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    color:var(--ink); background:var(--sand);
  }
  .wrap{max-width:860px;margin:0 auto}
  h1{font-size:26px;margin:0 0 6px;font-weight:600}
  .sub{margin:0 0 32px;color:#6b6356}
  .card{background:#fff;border-radius:14px;padding:22px 24px;margin:0 0 20px;
        box-shadow:0 1px 2px rgba(44,36,22,.06),0 8px 24px rgba(44,36,22,.05)}
  h2{font-size:19px;margin:0 0 4px;font-weight:600}
  h2 code{font:inherit;color:#6b6356;font-weight:400}
  .state{display:inline-block;margin:8px 0 14px;padding:4px 12px;border-radius:999px;
         font-size:14px;font-weight:600}
  .state--ok{background:#e8f3e9;color:var(--ok)}
  .state--bad{background:#fdeae8;color:var(--bad)}
  ul.notes{list-style:none;margin:10px 0 0;padding:0}
  ul.notes li{display:flex;gap:10px;align-items:flex-start;padding:6px 0;
              border-top:1px solid #f0ece4}
  ul.notes li:first-child{border-top:0}
  .i{flex:0 0 22px;height:22px;border-radius:50%;display:grid;place-items:center;
     font-size:13px;font-weight:700;line-height:1}
  .i--ok{background:#e8f3e9;color:var(--ok)}
  .i--info{background:#e8eef0;color:#45707f}
  .i--warn{background:#fdf3e0;color:var(--warn)}
  .i--bad{background:#fdeae8;color:var(--bad)}
  .err{background:#fdeae8;border-radius:10px;padding:16px 18px;margin:6px 0 0}
  .err p{margin:0 0 8px;font-weight:600;color:var(--bad)}
  .err pre{margin:0;padding:10px 12px;background:#fff;border-radius:8px;
           overflow:auto;font-size:14px}
  .hint{margin:28px 0 0;color:#6b6356;font-size:15px}
  .hint b{color:var(--ink)}
  a{color:var(--blue)}
</style>
</head>
<body>
<div class="wrap">

  <h1>Проверка файлов данных</h1>
  <p class="sub">Страница только читает файлы и показывает, всё ли в порядке. Обновите её после каждой правки.</p>

<?php foreach (array($groups, $team) as $r): ?>
  <div class="card">
    <h2><?= $r['file'] === 'groups.json' ? 'Группы, цены и расписание' : 'Тренеры' ?>
        <code>— data/<?= imp_e($r['file']) ?></code></h2>

    <?php if ($r['ok']): ?>
      <span class="state state--ok">Файл заполнен правильно</span>
    <?php else: ?>
      <span class="state state--bad">Файл не читается, сайт показывает прошлую версию секции</span>
      <div class="err">
        <p><?= imp_e($r['error']['message']) ?><?= $r['error']['line'] ? ', строка ' . (int)$r['error']['line'] : '' ?></p>
        <?php if ($r['error']['text'] !== ''): ?>
          <pre><?= (int)$r['error']['line'] ?>:  <?= imp_e($r['error']['text']) ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($r['notes']): ?>
      <ul class="notes">
        <?php foreach ($r['notes'] as $note): ?>
          <li>
            <span class="i i--<?= imp_e($note[0]) ?>"><?= imp_icon($note[0]) ?></span>
            <span><?= imp_e($note[1]) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php
  $cacheDir = __DIR__ . '/cache';
  $netOn = is_dir($cacheDir) && is_writable($cacheDir)
           && is_file($cacheDir . '/groups.json') && is_file($cacheDir . '/team.json');
?>
  <div class="card">
    <h2>Страховка от опечаток</h2>
    <?php if ($netOn): ?>
      <span class="state state--ok">Работает</span>
      <ul class="notes">
        <li><span class="i i--ok">✓</span><span>Исправные копии обоих файлов сохранены в data/cache/. Если в файле появится опечатка, сайт покажет последнюю рабочую версию секции.</span></li>
      </ul>
    <?php else: ?>
      <span class="state state--bad">Не работает</span>
      <ul class="notes">
        <li><span class="i i--warn">!</span><span>Папка data/cache/ отсутствует или недоступна для записи. Создайте её и поставьте права 755 — тогда опечатка в файле данных не будет убирать секцию с сайта.</span></li>
      </ul>
    <?php endif; ?>
  </div>

  <p class="hint">
    <b>Если файл не читается:</b> чаще всего пропущена или лишняя запятая, либо незакрытая кавычка.
    Исправьте указанную строку и обновите страницу.<br><br>
    <a href="/">Открыть сайт</a>
  </p>

</div>
</body>
</html>
