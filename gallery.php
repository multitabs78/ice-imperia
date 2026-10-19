<?php
/* =========================================================
   Империя льда — список фотографий галереи
   ---------------------------------------------------------
   Отдаёт сайту перечень файлов из assets/img/gallery/.
   Чтобы добавить фотографию в галерею, достаточно положить
   файл в эту папку — править index.html не нужно.

   Подписи (alt) берутся из необязательного файла
   assets/img/gallery/alt.json, например:

   {
     "life-1.jpg": "Ребёнок на льду во время тренировки",
     "life-2.jpg": { "alt": "Прыжок на тренировке", "focus": "50% 25%" },
     "life-3.jpg": { "alt": "Разминка", "focus": "50% 30%", "focusMobile": "50% 15%" }
   }

   Короткая запись задаёт только подпись. Развёрнутая позволяет
   дополнительно указать точку кадрирования focus — она пригодится,
   если при обрезке уходит лицо. Значение такое же, как у CSS
   object-position: первое число — по горизонтали, второе — по вертикали.

   focusMobile — отдельная точка для экранов уже 700 px. Там плитки
   меняют форму, и кадр обрезается иначе. Если её не задать,
   на узком экране используется обычный focus.

   Для файлов, которых нет в alt.json, подставляется
   общая подпись из $alt_default ниже.

   Вместе с путём отдаются размеры кадра: по ним сайт подбирает
   снимок под форму плитки, чтобы вертикальное фото не попадало
   в широкую ячейку и наоборот.
   ========================================================= */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$dir = __DIR__ . '/assets/img/gallery';
$web = 'assets/img/gallery/';
$allowed = ['jpg', 'jpeg', 'png', 'webp'];

/* ---------------------------------------------------------
   Уменьшенные копии для плиток
   ---------------------------------------------------------
   В коллаже кадр показывается шириной максимум 600 px, поэтому
   отдавать туда исходник на 1600 px — лишний трафик. При первом
   обращении скрипт делает уменьшенную копию в подпапке preview
   и дальше отдаёт её; полный файл остаётся для просмотра во весь экран.

   Копии создаются один раз и сами обновляются, если исходник
   заменили. Если GD на хостинге нет или папка недоступна для записи,
   сайт просто покажет исходники — ничего не сломается.
   --------------------------------------------------------- */
$preview_dir = $dir . '/preview';
$preview_web = $web . 'preview/';
$preview_max = 800;      // ширина уменьшенной копии
$preview_q   = 78;

$gd = function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
$gd_webp = $gd && function_exists('imagewebp');

if ($gd && !is_dir($preview_dir)) @mkdir($preview_dir, 0775, true);
$can_write = $gd && is_dir($preview_dir) && is_writable($preview_dir);

/**
 * Возвращает путь к уменьшенной копии или пустую строку,
 * если сделать её не удалось.
 */
function preview_for($name, $dir, $preview_dir, $max, $quality, $webp)
{
    $src = $dir . '/' . $name;
    $base = pathinfo($name, PATHINFO_FILENAME);
    $out_name = $base . ($webp ? '.webp' : '.jpg');
    $out = $preview_dir . '/' . $out_name;

    // копия уже есть и не устарела
    if (is_file($out) && filemtime($out) >= filemtime($src)) return $out_name;

    $info = @getimagesize($src);
    if (!$info) return '';
    list($w, $h, $type) = $info;
    if ($w <= $max) return '';          // исходник и так небольшой

    switch ($type) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src); break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
        default: $img = false;
    }
    if (!$img) return '';

    $nw = $max;
    $nh = (int) round($h * $max / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = $webp ? @imagewebp($dst, $out, $quality) : @imagejpeg($dst, $out, $quality);

    imagedestroy($img);
    imagedestroy($dst);

    return $ok && is_file($out) ? $out_name : '';
}

$alt_default = 'Фотография из жизни школы фигурного катания «Империя льда»';

// Подписи к конкретным файлам, если они заданы
$alts = [];
$alt_file = $dir . '/alt.json';
if (is_file($alt_file)) {
    $parsed = json_decode((string) file_get_contents($alt_file), true);
    if (is_array($parsed)) $alts = $parsed;
}

$photos = [];

if (is_dir($dir)) {
    $files = scandir($dir);
    if ($files !== false) {
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($files as $name) {
            if ($name === '' || $name[0] === '.') continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;

            $entry = isset($alts[$name]) ? $alts[$name] : null;
            $alt = $alt_default;
            $focus = '';
            $focus_mobile = '';

            if (is_string($entry) && $entry !== '') {
                $alt = $entry;
            } elseif (is_array($entry)) {
                if (!empty($entry['alt'])) $alt = $entry['alt'];
                if (!empty($entry['focus'])) $focus = $entry['focus'];
                // ключ можно писать и как focusMobile, и как focus_mobile
                if (!empty($entry['focusMobile']))  $focus_mobile = $entry['focusMobile'];
                if (!empty($entry['focus_mobile'])) $focus_mobile = $entry['focus_mobile'];
            }

            $full = $web . rawurlencode($name);
            $small = '';
            if ($can_write) {
                $made = preview_for($name, $dir, $preview_dir, $preview_max, $preview_q, $gd_webp);
                if ($made !== '') $small = $preview_web . rawurlencode($made);
            }

            $photo = [
                'src'  => $small !== '' ? $small : $full,   // в плитки — лёгкая копия
                'full' => $full,                            // в полноэкранный просмотр — исходник
                'alt'  => $alt,
            ];

            // размеры нужны, чтобы подобрать кадр под форму плитки
            $size = @getimagesize($dir . '/' . $name);
            if ($size && $size[0] > 0 && $size[1] > 0) {
                $photo['w'] = $size[0];
                $photo['h'] = $size[1];
            }
            if ($focus !== '') $photo['focus'] = $focus;
            if ($focus_mobile !== '') $photo['focusMobile'] = $focus_mobile;

            $photos[] = $photo;
        }
    }
}

echo json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
