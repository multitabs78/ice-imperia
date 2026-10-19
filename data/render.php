<?php
/* =========================================================
   Империя льда — сборка секций «Группы и абонементы» и «Команда»
   из файлов data/groups.json и data/team.json
   ---------------------------------------------------------
   Этот файл трогать не нужно. Вся информация, которая меняется
   (цены, расписание, тренеры), лежит в двух файлах рядом:

     data/groups.json  — группы, стоимость, расписание
     data/team.json    — директор и тренеры

   Проверить файлы после правки: откройте /data/check.php
   ========================================================= */

if (!defined('IMPERIA_RENDER')) {
    define('IMPERIA_RENDER', 1);
}

/** Короткая запись htmlspecialchars для вывода текста в разметку. */
function imp_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Читает файл данных.
 *
 * Пока в файле всё в порядке, его копия сохраняется в data/cache/.
 * Если потом в файле окажется опечатка, страница покажет последнюю
 * исправную версию — посетители ничего странного не увидят, а причину
 * можно посмотреть на /data/check.php.
 *
 * Возвращает массив или null. Причина проблемы остаётся в $error.
 */
function imp_load($file, &$error = null)
{
    $path  = __DIR__ . '/' . $file;
    $cache = __DIR__ . '/cache/' . $file;

    $error = null;

    if (is_file($path)) {
        $raw = file_get_contents($path);

        if ($raw !== false) {
            // Убираем BOM, который иногда добавляют редакторы в Windows
            $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $data = json_decode($raw, true);

            if (is_array($data)) {
                imp_cache_save($cache, $raw);
                return $data;
            }

            $error = 'ошибка в разметке файла data/' . $file . ': ' . json_last_error_msg();
        } else {
            $error = 'не удалось прочитать файл data/' . $file;
        }
    } else {
        $error = 'файл не найден: data/' . $file;
    }

    // Подстраховка: последняя исправная версия
    if (is_file($cache)) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data)) {
            $error .= ' — показана последняя исправная версия';
            return $data;
        }
    }

    return null;
}

/** Сохраняет исправную копию файла. Если папка не пишется — молча пропускает. */
function imp_cache_save($cache, $raw)
{
    $dir = dirname($cache);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    if (is_file($cache) && (string)file_get_contents($cache) === $raw) {
        return; // не изменилось
    }

    @file_put_contents($cache, $raw, LOCK_EX);
}

/** Берёт значение по ключу, если его нет — возвращает значение по умолчанию. */
function imp_get($arr, $key, $default = '')
{
    return (is_array($arr) && isset($arr[$key]) && $arr[$key] !== null) ? $arr[$key] : $default;
}

/** Список строк: всегда массив, пустые значения отброшены. */
function imp_list($value)
{
    if (!is_array($value)) {
        $value = ($value === '' || $value === null) ? array() : array($value);
    }
    $out = array();
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * «16:30 лёд» → <span class="gsched__slot"><b>16:30</b> лёд</span>
 * Если пробела нет, вся строка становится временем.
 */
function imp_slot($slot)
{
    $slot = trim((string)$slot);
    if ($slot === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $slot, 2);
    $time  = imp_e($parts[0]);
    $what  = isset($parts[1]) ? ' ' . imp_e($parts[1]) : '';

    return '<span class="gsched__slot"><b>' . $time . '</b>' . $what . '</span>';
}

/** Строки расписания: день + его слоты. */
function imp_schedule($schedule)
{
    if (!is_array($schedule) || !$schedule) {
        return '';
    }

    $rows = '';

    foreach ($schedule as $day => $slots) {
        // Поддерживаем обе записи:
        //   "ВТ": ["16:30 лёд"]            — день ключом
        //   { "day": "ВТ", "slots": [...] } — день полем
        if (is_array($slots) && (isset($slots['day']) || isset($slots['slots']))) {
            $day   = imp_get($slots, 'day', $day);
            $slots = imp_get($slots, 'slots', array());
        }

        $cells = '';
        foreach (imp_list($slots) as $slot) {
            $cells .= imp_slot($slot);
        }

        if ($cells === '') {
            continue;
        }

        $rows .= '<li><span class="gsched__day">' . imp_e($day) . '</span>'
               . '<span class="gsched__slots">' . $cells . '</span></li>' . "\n                ";
    }

    if ($rows === '') {
        return '';
    }

    return '<ul class="gsched">' . "\n                " . rtrim($rows) . "\n                " . '</ul>';
}


/* ---------------------------------------------------------
   Секция «Группы и абонементы»
   --------------------------------------------------------- */

function imp_render_groups()
{
    $data = imp_load('groups.json', $error);

    if ($data === null) {
        return "      <!-- Группы: " . imp_e($error) . " -->\n";
    }

    $head = imp_get($data, 'head', array());
    $cta  = imp_get($data, 'cta', array());
    $out  = '';

    $out .= '      <div class="groups__head reveal">' . "\n";
    if (imp_get($head, 'eyebrow') !== '') {
        $out .= '        <p class="eyebrow">' . imp_e($head['eyebrow']) . '</p>' . "\n";
    }
    if (imp_get($head, 'title') !== '') {
        $out .= '        <h2 class="h2">' . imp_inline($head['title']) . '</h2>' . "\n";
    }
    if (imp_get($head, 'lead') !== '') {
        $out .= '        <p class="groups__lead">' . imp_inline($head['lead']) . '</p>' . "\n";
    }
    $out .= '      </div>' . "\n\n";

    $sets = imp_get($data, 'sets', array());
    $si   = 0;

    foreach ((array)$sets as $set) {
        if (imp_get($set, 'hide', false)) {
            continue;
        }

        $cards = array();
        foreach ((array)imp_get($set, 'cards', array()) as $card) {
            if (!imp_get($card, 'hide', false)) {
                $cards[] = $card;
            }
        }
        if (!$cards) {
            continue;
        }

        $si++;
        // Сетка подстраивается под число карточек: 2 или 3 в ряд
        $cols = (count($cards) % 3 === 0) ? 3 : 2;

        $out .= '      <section class="gset reveal">' . "\n";
        $out .= '        <div class="gset__head">' . "\n";
        $out .= '          <h3 class="gset__title">' . imp_inline(imp_get($set, 'title')) . '</h3>' . "\n";
        if (imp_get($set, 'note') !== '') {
            $out .= '          <p class="gset__note">' . imp_inline($set['note']) . '</p>' . "\n";
        }
        $out .= '        </div>' . "\n";
        $out .= '        <div class="gset__grid gset__grid--' . $cols . '">' . "\n";

        $ci = 0;
        foreach ($cards as $card) {
            $ci++;
            $out .= imp_render_card($card, 'g-' . $si . '-' . $ci);
        }

        $out .= '        </div>' . "\n";
        $out .= '      </section>' . "\n\n";
    }

    if (imp_get($cta, 'title') !== '' || imp_get($cta, 'text') !== '') {
        $out .= '      <div class="groups__cta reveal">' . "\n";
        $out .= '        <div>' . "\n";
        if (imp_get($cta, 'title') !== '') {
            $out .= '          <h3 class="h3">' . imp_inline($cta['title']) . '</h3>' . "\n";
        }
        if (imp_get($cta, 'text') !== '') {
            $out .= '          <p>' . imp_inline($cta['text']) . '</p>' . "\n";
        }
        $out .= '        </div>' . "\n";
        $out .= '        <a class="btn btn--primary" href="#zapis">'
              . imp_e(imp_get($cta, 'button', 'Записаться на бесплатное занятие')) . '</a>' . "\n";
        $out .= '      </div>' . "\n";
    }

    return $out;
}

/** Одна карточка группы. */
function imp_render_card($card, $panelId)
{
    $name   = imp_get($card, 'name');
    $accent = imp_get($card, 'accent', false) ? ' gcard--accent' : '';

    $out  = '          <article class="gcard' . $accent . ' reveal">' . "\n";

    if (imp_get($card, 'age') !== '') {
        $out .= '            <p class="gcard__age">' . imp_e($card['age']) . '</p>' . "\n";
    }
    if (imp_get($card, 'mark') !== '') {
        $out .= '            <p class="gcard__mark">' . imp_e($card['mark']) . '</p>' . "\n";
    }

    $out .= '            <h4 class="gcard__name">' . imp_e($name) . '</h4>' . "\n";

    if (imp_get($card, 'full') !== '') {
        $out .= '            <p class="gcard__full">' . imp_e($card['full']) . '</p>' . "\n";
    }
    if (imp_get($card, 'price') !== '') {
        $out .= '            <p class="gcard__price">' . imp_e($card['price']);
        if (imp_get($card, 'per') !== '') {
            $out .= '<span class="gcard__per">' . imp_e($card['per']) . '</span>';
        }
        $out .= '</p>' . "\n";
    }

    $facts = imp_list(imp_get($card, 'facts', array()));
    if ($facts) {
        $out .= '            <ul class="gcard__facts">' . "\n";
        foreach ($facts as $fact) {
            $out .= '              <li>' . imp_inline($fact) . '</li>' . "\n";
        }
        $out .= '            </ul>' . "\n";
    }

    if (imp_get($card, 'desc') !== '') {
        $out .= '            <p class="gcard__desc">' . imp_inline($card['desc']) . '</p>' . "\n";
    }

    $schedule = imp_schedule(imp_get($card, 'schedule', array()));
    $extra    = imp_get($card, 'extra', array());
    $extraRows = imp_list(imp_get($extra, 'rows', array()));

    // Нечего раскрывать — обходимся без кнопки и панели
    if ($schedule === '' && !$extraRows) {
        $out .= '            <a class="btn btn--primary btn--full" href="#zapis" data-group="'
              . imp_e($name) . '">Записаться в группу</a>' . "\n";
        $out .= '          </article>' . "\n";
        return $out;
    }

    $out .= "\n";
    $out .= '            <button class="gcard__toggle" type="button" aria-expanded="false" aria-controls="'
          . imp_e($panelId) . '">' . "\n";
    $out .= '              <span>' . imp_e(imp_get($card, 'toggle', 'Расписание и подробнее')) . '</span>' . "\n";
    $out .= '              <span class="gcard__icon" aria-hidden="true"></span>' . "\n";
    $out .= '            </button>' . "\n\n";

    $out .= '            <div class="gcard__panel" id="' . imp_e($panelId) . '" hidden>' . "\n";
    $out .= '              <div class="gcard__panel-inner">' . "\n";

    if ($schedule !== '') {
        $out .= '                <p class="gcard__sub">' . imp_e(imp_get($card, 'schedule_title', 'Расписание')) . '</p>' . "\n";
        $out .= '                ' . $schedule . "\n";
    }

    if ($extraRows) {
        $out .= '                <p class="gcard__sub">' . imp_e(imp_get($extra, 'title', 'Варианты абонемента')) . '</p>' . "\n";
        $out .= '                <ul class="gsched">' . "\n";
        foreach ($extraRows as $row) {
            $out .= '                  <li>' . imp_inline($row) . '</li>' . "\n";
        }
        $out .= '                </ul>' . "\n";
    }

    if (imp_get($card, 'note') !== '') {
        $out .= '                <p class="gcard__desc">' . imp_inline($card['note']) . '</p>' . "\n";
    }

    $out .= '                <a class="btn btn--primary btn--full" href="#zapis" data-group="'
          . imp_e($name) . '">Записаться в группу</a>' . "\n";
    $out .= '                <button class="gcard__close" type="button" data-collapse>Свернуть</button>' . "\n";
    $out .= '              </div>' . "\n";
    $out .= '            </div>' . "\n";
    $out .= '          </article>' . "\n";

    return $out;
}


/* ---------------------------------------------------------
   Секция «Команда»
   --------------------------------------------------------- */

function imp_render_team()
{
    $data = imp_load('team.json', $error);

    if ($data === null) {
        return "      <!-- Команда: " . imp_e($error) . " -->\n";
    }

    $head = imp_get($data, 'head', array());
    $out  = '';

    $out .= '      <div class="team__head reveal">' . "\n";
    if (imp_get($head, 'eyebrow') !== '') {
        $out .= '        <p class="eyebrow">' . imp_e($head['eyebrow']) . '</p>' . "\n";
    }
    if (imp_get($head, 'title') !== '') {
        $out .= '        <h2 class="h2">' . imp_inline($head['title']) . '</h2>' . "\n";
    }
    if (imp_get($head, 'lead') !== '') {
        $out .= '        <p class="team__lead">' . imp_inline($head['lead']) . '</p>' . "\n";
    }
    $out .= '      </div>' . "\n\n";

    $lead = imp_get($data, 'lead_card', array());
    if (is_array($lead) && $lead && !imp_get($lead, 'hide', false) && imp_get($lead, 'name') !== '') {
        $out .= '      <article class="lead-card reveal">' . "\n";
        $out .= '        <figure class="lead-card__photo">' . "\n";
        $out .= imp_photo($lead, 10);
        $out .= '        </figure>' . "\n\n";
        $out .= '        <div class="lead-card__info">' . "\n";
        $out .= '          <h3 class="lead-card__name">' . imp_e($lead['name']) . '</h3>' . "\n";
        $out .= imp_person_text($lead, 10, true);
        $out .= '        </div>' . "\n";
        $out .= '      </article>' . "\n\n";
    }

    $coaches = array();
    foreach ((array)imp_get($data, 'coaches', array()) as $coach) {
        if (!imp_get($coach, 'hide', false) && imp_get($coach, 'name') !== '') {
            $coaches[] = $coach;
        }
    }

    if ($coaches) {
        $out .= '      <div class="team__grid">' . "\n";
        foreach ($coaches as $coach) {
            // Пока фотографии нет, карточка остаётся в разметке, но скрыта
            // от посетителей: внутри неё памятка о том, какой нужен кадр
            $pending = (trim((string)imp_get($coach, 'photo')) === '') ? ' person--pending' : '';
            $out .= '        <article class="person' . $pending . ' reveal">' . "\n";
            $out .= '          <figure class="person__photo">' . "\n";
            $out .= imp_photo($coach, 12);
            $out .= '          </figure>' . "\n";
            $out .= '          <div class="person__info">' . "\n";
            $out .= '            <h3 class="person__name">' . imp_e($coach['name']) . '</h3>' . "\n";
            $out .= imp_person_text($coach, 12, false);
            $out .= '          </div>' . "\n";
            $out .= '        </article>' . "\n";
        }
        $out .= '      </div>' . "\n";
    }

    return $out;
}

/** Должность, квалификация и факты — общая часть карточек. */
function imp_person_text($person, $indent, $isLead)
{
    $pad  = str_repeat(' ', $indent);
    $out  = '';

    if (imp_get($person, 'role') !== '') {
        $out .= $pad . '<p class="person__role">' . imp_e($person['role']) . '</p>' . "\n";
    }

    $rank       = imp_get($person, 'rank');
    $discipline = imp_get($person, 'discipline');

    if ($rank !== '' || $discipline !== '') {
        $cls = 'person__rank' . ($isLead ? ' person__rank--lead' : '');
        $out .= $pad . '<p class="' . $cls . '">' . "\n";
        $out .= $pad . '  <span class="person__rank-abbr">' . imp_e($rank) . '</span>' . "\n";
        $out .= $pad . '  <span class="person__rank-text">' . imp_e($discipline) . '</span>' . "\n";
        $out .= $pad . '</p>' . "\n";
    }

    $facts = imp_list(imp_get($person, 'facts', array()));
    if ($facts) {
        $out .= $pad . '<ul class="person__facts">' . "\n";
        foreach ($facts as $fact) {
            $out .= $pad . '  <li>' . imp_inline($fact) . '</li>' . "\n";
        }
        $out .= $pad . '</ul>' . "\n";
    }

    return $out;
}

/**
 * Фотография тренера.
 * В JSON достаточно написать имя файла без расширения: "coach-ivanov".
 * Файл ищется в assets/img/. Если рядом лежит .webp, он подключается
 * автоматически — лёгкая версия для современных браузеров.
 * Если фотографии нет, вместо неё выводится памятка «нужно фото».
 */
function imp_photo($person, $indent)
{
    $pad   = str_repeat(' ', $indent);
    $photo = trim((string)imp_get($person, 'photo'));
    $alt   = imp_get($person, 'alt');

    if ($alt === '') {
        $role = imp_get($person, 'role');
        $alt  = imp_get($person, 'name');
        if ($role !== '') {
            $alt .= ', ' . mb_strtolower(mb_substr($role, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($role, 1, null, 'UTF-8');
        }
    }

    // Памятка вместо фотографии
    if ($photo === '') {
        $out  = $pad . '<div class="photo-need">' . "\n";
        $out .= $pad . '  <div class="need">' . "\n";
        $out .= $pad . '    <p class="need__label">Нужно фото</p>' . "\n";
        $out .= $pad . '    <p class="need__title">' . imp_e(imp_get($person, 'name')) . '</p>' . "\n";
        $out .= $pad . '    <ul class="need__list">' . "\n";
        $out .= $pad . '      <li>портрет на ледовой арене, вертикальный кадр</li>' . "\n";
        $out .= $pad . '      <li>спокойная естественная поза</li>' . "\n";
        $out .= $pad . '      <li>единая обработка со всей командой</li>' . "\n";
        $out .= $pad . '    </ul>' . "\n";
        $out .= $pad . '  </div>' . "\n";
        $out .= $pad . '</div>' . "\n";
        return $out;
    }

    // Путь: "coach-ivanov" → assets/img/coach-ivanov.jpg
    $hasPath = (strpos($photo, '/') !== false);
    $hasExt  = (bool)preg_match('/\.(jpe?g|png|webp)$/i', $photo);

    $base = $hasPath ? $photo : 'assets/img/' . $photo;
    if ($hasExt) {
        $src  = $base;
        $stem = preg_replace('/\.(jpe?g|png|webp)$/i', '', $base);
    } else {
        $stem = $base;
        $src  = is_file(imp_root($stem . '.jpg')) ? $stem . '.jpg'
              : (is_file(imp_root($stem . '.png')) ? $stem . '.png' : $stem . '.jpg');
    }

    $webp = $stem . '.webp';
    $w    = imp_get($person, 'width', 900);
    $h    = imp_get($person, 'height', 1125);

    // Размеры берём из самого файла, если он на месте — так меньше поводов
    // ошибиться при замене фотографии
    $size = @getimagesize(imp_root($src));
    if ($size && !empty($size[0]) && !empty($size[1])) {
        $w = $size[0];
        $h = $size[1];
    }

    $img = '<img src="' . imp_e($src) . '" alt="' . imp_e($alt) . '"'
         . ' width="' . (int)$w . '" height="' . (int)$h . '" loading="lazy">';

    if (is_file(imp_root($webp))) {
        $out  = $pad . '<picture>' . "\n";
        $out .= $pad . '  <source srcset="' . imp_e($webp) . '" type="image/webp">' . "\n";
        $out .= $pad . '  ' . $img . "\n";
        $out .= $pad . '</picture>' . "\n";
        return $out;
    }

    return $pad . $img . "\n";
}

/** Абсолютный путь к файлу сайта по относительному адресу. */
function imp_root($relative)
{
    return dirname(__DIR__) . '/' . ltrim($relative, '/');
}

/**
 * Текст с разрешёнными простыми тегами.
 * Позволяет писать в JSON <br>, <b>, <i>, <em>, <strong>, <nobr>
 * и не бояться, что случайный «<» сломает страницу.
 */
function imp_inline($text)
{
    $text = imp_e($text);

    $allowed = array('br', 'br/', 'br /', 'b', '/b', 'i', '/i',
                     'em', '/em', 'strong', '/strong', 'nobr', '/nobr');

    foreach ($allowed as $tag) {
        $text = str_ireplace('&lt;' . $tag . '&gt;', '<' . $tag . '>', $text);
    }

    return $text;
}
