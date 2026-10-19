# =========================================================
#  Империя льда — подготовка фотографий для сайта (Windows)
# ---------------------------------------------------------
#  Уменьшает снимок до 1600 px по длинной стороне, выпрямляет
#  по данным съёмки, приводит к sRGB, удаляет EXIF (включая
#  геометку), слегка подшарпливает и сохраняет прогрессивный JPEG.
#
#  Как пользоваться, в окне PowerShell:
#
#     .\optimize-gallery.ps1 -Src "C:\IMG\новые" -Dst "C:\IMG\готовые"
#
#  Исходники не изменяются. Готовые файлы кладите
#  в assets/img/gallery/ и добавляйте строку в data-photos.
#
#  Если PowerShell откажется запускать скрипт, выполните один раз:
#     Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
#
#  ВАЖНО: файл сохранён в UTF-8 с BOM. Если будете править его
#  в редакторе, сохраняйте в той же кодировке, иначе Windows
#  PowerShell прочитает русский текст как набор символов.
# =========================================================

param(
  [Parameter(Mandatory = $true)][string]$Src,
  [Parameter(Mandatory = $true)][string]$Dst
)

# чтобы русский текст в консоли не превращался в кракозябры
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$Max = 1600      # длинная сторона готового файла
$Quality = 82    # 78-85 — разумный диапазон

# ImageMagick 7 — команда magick, ImageMagick 6 — convert
$im = $null
if (Get-Command magick -ErrorAction SilentlyContinue) {
  $im = 'magick'
} elseif (Get-Command convert -ErrorAction SilentlyContinue) {
  $im = 'convert'
}

if (-not $im) {
  Write-Host 'ImageMagick не найден. Установите его и повторите.' -ForegroundColor Red
  exit 1
}

if (-not (Test-Path -LiteralPath $Src)) {
  Write-Host "Папка с исходниками не найдена: $Src" -ForegroundColor Red
  exit 1
}

New-Item -ItemType Directory -Force -Path $Dst | Out-Null

$files = Get-ChildItem -Path (Join-Path $Src '*') -Include *.jpg, *.jpeg, *.png -File

if (-not $files) {
  Write-Host "В папке нет подходящих файлов: $Src" -ForegroundColor Yellow
  exit 0
}

$count = 0
foreach ($f in $files) {
  $count++
  $name = [System.IO.Path]::GetFileNameWithoutExtension($f.Name).ToLower() -replace '[ _]', '-'

  # кириллица и прочие символы в веб-путях приводят к проблемам
  if ($name -notmatch '^[a-z0-9-]+$') {
    $newName = 'photo-{0:d2}' -f $count
    Write-Host ("  имя <" + $f.Name + "> заменено на " + $newName + ".jpg")
    $name = $newName
  }

  $out = Join-Path $Dst ($name + '.jpg')

  & $im $f.FullName `
      -auto-orient `
      -colorspace sRGB `
      -resize "$($Max)x$($Max)>" `
      -unsharp 0x0.75+0.75+0.008 `
      -strip `
      -interlace Plane `
      -sampling-factor 4:2:0 `
      -define jpeg:dct-method=float `
      -quality $Quality `
      $out

  if (Test-Path -LiteralPath $out) {
    $before = [math]::Round($f.Length / 1KB)
    $after = [math]::Round((Get-Item -LiteralPath $out).Length / 1KB)
    Write-Host ($name + '.jpg : ' + $before + ' КБ -> ' + $after + ' КБ')
  } else {
    Write-Host ('не удалось обработать: ' + $f.Name) -ForegroundColor Red
  }
}

Write-Host ('Обработано файлов: ' + $count)
Write-Host ('Результат: ' + $Dst)
