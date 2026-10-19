#!/bin/sh
# =========================================================
#  Империя льда — подготовка фотографий для сайта
# ---------------------------------------------------------
#  Что делает: уменьшает снимок до 1600 px по длинной стороне,
#  выпрямляет по данным съёмки, приводит к sRGB, удаляет EXIF
#  (включая геометку), слегка подшарпливает после уменьшения
#  и сохраняет прогрессивный JPEG.
#
#  Как пользоваться:
#     sh optimize-gallery.sh ~/Снимки/новые ~/Рабочий\ стол/готовые
#
#  Первый путь — папка с исходниками, второй — куда положить
#  результат. Исходники не изменяются.
#
#  Готовые файлы кладите в assets/img/gallery/ и добавляйте
#  строку в список data-photos в index.html.
# =========================================================

SRC="$1"
DST="$2"

if [ -z "$SRC" ] || [ -z "$DST" ]; then
  echo "Использование: sh optimize-gallery.sh <папка-с-исходниками> <папка-для-результата>"
  exit 1
fi

# ImageMagick 7 — команда magick, ImageMagick 6 — convert
if command -v magick >/dev/null 2>&1; then
  IM="magick"
elif command -v convert >/dev/null 2>&1; then
  IM="convert"
else
  echo "ImageMagick не найден. Установите его и повторите."
  exit 1
fi

mkdir -p "$DST"

MAX=1600        # длинная сторона готового файла
QUALITY=82      # 78–85 — разумный диапазон

count=0
for file in "$SRC"/*.jpg "$SRC"/*.JPG "$SRC"/*.jpeg "$SRC"/*.png; do
  [ -e "$file" ] || continue
  name=$(basename "$file")
  name="${name%.*}"
  # имя приводим к нижнему регистру: хостинг на Linux различает регистр
  name=$(echo "$name" | tr '[:upper:]' '[:lower:]' | tr ' _' '--')
  # кириллица и прочие символы в веб-путях приводят к проблемам —
  # такие файлы получают простое имя photo-01, photo-02 и так далее
  case "$name" in
    *[!a-z0-9-]*|"")
      num=$((count+1))
      [ $num -lt 10 ] && num="0$num"
      echo "  имя «$(basename "$file")» заменено на photo-$num.jpg"
      name="photo-$num"
      ;;
  esac
  out="$DST/$name.jpg"

  $IM "$file" \
      -auto-orient \
      -colorspace sRGB \
      -resize "${MAX}x${MAX}>" \
      -unsharp 0x0.75+0.75+0.008 \
      -strip \
      -interlace Plane \
      -sampling-factor 4:2:0 \
      -define jpeg:dct-method=float \
      -quality $QUALITY \
      "$out"

  before=$(wc -c < "$file")
  after=$(wc -c < "$out")
  echo "$name.jpg: $((before/1024)) КБ -> $((after/1024)) КБ"
  count=$((count+1))
done

echo "Обработано файлов: $count"
echo "Результат: $DST"
