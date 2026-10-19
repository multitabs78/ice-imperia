<?php require __DIR__ . '/data/render.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Империя льда - школа фигурного катания в Санкт-Петербурге</title>
  <meta name="description" content="Школа фигурного катания «Империя льда» в Санкт-Петербурге. Занятия для детей от 3-х лет. Запишитесь на пробное занятие.">

  <!-- Ссылка на сайт в мессенджерах и соцсетях -->
  <link rel="canonical" href="https://ice-imperia.ru/">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Империя льда">
  <meta property="og:title" content="Империя льда — школа фигурного катания в Санкт-Петербурге">
  <meta property="og:description" content="Занятия для детей от 3-х лет. Первое пробное занятие — бесплатно.">
  <meta property="og:image" content="https://ice-imperia.ru/assets/img/hero.jpg">
  <meta property="og:url" content="https://ice-imperia.ru/">
  <meta name="theme-color" content="#0F1C24">

  <!-- Первый экран — самый важный кадр страницы, грузим его заранее -->
  <link rel="preload" as="image" href="assets/img/hero.webp" type="image/webp" fetchpriority="high">


  <link rel="stylesheet" href="styles.css?v=20260903c">
  <link rel="icon" href="favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
  <link rel="apple-touch-icon" href="apple-touch-icon.png">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SportsActivityLocation",
    "name": "Империя льда",
    "description": "Школа фигурного катания для детей от 3-х лет в Санкт-Петербурге.",
    "url": "https://ice-imperia.ru/",
    "telephone": "+7 999 215-45-56",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Санкт-Петербург",
      "streetAddress": "ул. Свободы, 15",
      "addressCountry": "RU"
    },
    "sameAs": [
      "https://vk.ru/iceimperia",
      "https://t.me/ice_imperia"
    ]
  }
  </script>
</head>
<body>

<a class="skip-link" href="#main">Перейти к содержанию</a>

<!-- ============ ШАПКА ============ -->
<header class="header" id="header">
  <div class="shell header__inner">

    <a class="brand" href="#top" aria-label="Империя льда - на главную">
      <!-- Логотип используется как есть, без изменений формы, цвета и пропорций -->
      <img class="brand__mark" src="assets/logo.png" alt="Логотип школы фигурного катания «Империя льда»" width="560" height="532">
      <span class="brand__text">
        <span class="brand__name">Империя льда</span>
        <span class="brand__sub">Школа фигурного катания</span>
      </span>
    </a>

    <nav class="nav" id="nav" aria-label="Основная навигация">
      <ul class="nav__list">
        <li><a class="nav__link" href="#about">О школе</a></li>
        <li><a class="nav__link" href="#team">Команда</a></li>
        <li><a class="nav__link" href="#prices">Группы и цены</a></li>
        <!-- FAQ скрывается, когда ширины на все пункты не хватает -->
        <li class="nav__item--faq"><a class="nav__link" href="#faq">FAQ</a></li>
        <li><a class="nav__link" href="#contacts">Контакты</a></li>
      </ul>

      <div class="nav__actions">
        <!-- Телефон взят из переданных материалов -->
        <a class="phone" href="tel:+79992154556">+7 (999) 215-45-56</a>
        <a class="btn btn--primary btn--sm nav__cta" href="#zapis">
          <span class="label-wide">Пробное бесплатно</span>
          <span class="label-menu">Пробное занятие бесплатно</span>
        </a>
      </div>
    </nav>

    <!-- Кнопка в строке шапки: на планшете всегда, на мобильном — после прокрутки -->
    <a class="btn btn--primary btn--sm header__cta" href="#zapis">
      <span class="label-wide">Пробное бесплатно</span>
      <span class="label-mobile">Записаться бесплатно</span>
    </a>

    <button class="burger" id="burger" type="button" aria-label="Открыть меню" aria-expanded="false" aria-controls="nav">
      <span></span><span></span><span></span>
    </button>

  </div>
</header>

<main id="main">

  <!-- ============ 1. ПЕРВЫЙ ЭКРАН ============ -->
  <section class="hero" id="top">

    <!-- Фотография — фон всего первого экрана -->
    <div class="hero__media parallax" data-speed="0.03">
      <picture>
        <source srcset="assets/img/hero.webp" type="image/webp">
        <img src="assets/img/hero.jpg" alt="Юная фигуристка скользит по льду на тренировке школы «Империя льда»" width="1372" height="896" fetchpriority="high">
      </picture>
    </div>

    <div class="hero__content">
      <div class="hero__text">
        <p class="hero__brand hero__anim">
          <span class="hero__brand-name">Империя льда</span>
          <span class="hero__brand-sub">Школа фигурного катания</span>
        </p>

        <h1 class="hero__title hero__anim">
          Здесь начинается<br>
          <span class="hero__title-accent">путь фигуриста</span>
        </h1>

        <p class="hero__lead hero__anim">
          Фигурное катание для детей от 3-х лет. От первых шагов на льду
          до соревнований и спортивных разрядов.
        </p>

        <div class="hero__actions hero__anim">
          <a class="btn btn--primary hero__cta" href="#zapis">
            <span class="label-full">Записаться на бесплатное занятие</span>
            <span class="label-short">Записаться бесплатно</span>
          </a>
          <a class="hero__link" href="#prices">Посмотреть группы <span aria-hidden="true">→</span></a>
        </div>
      </div>
    </div>

  </section>

  <!-- ============ 2. О ШКОЛЕ ============ -->
  <section class="section section--sand section--about" id="about">
    <div class="shell about">

      <div class="about__intro reveal">
        <p class="eyebrow">О школе</p>
        <h2 class="h2">Растём вместе<br>с ребёнком</h2>
        <p class="about__lead">
          В «Империи льда» ребёнок может начать заниматься с нуля и постепенно
          двигаться дальше — от первых шагов на коньках к технике, выступлениям
          и спортивным результатам.
        </p>
      </div>

      <!-- Фотографии идут по возрастанию размера: путь ребёнка от первых шагов
           к уверенному катанию. На мобильном скрипт переставляет их внутрь этапов. -->
      <div class="about__media">
        <figure class="about__ph about__ph--a reveal" data-stage="1">
          <picture>
            <source srcset="assets/img/about-1.webp" type="image/webp">
            <img src="assets/img/about-1.jpg" alt="Девочка в синем комбинезоне улыбается на льду" width="896" height="1372" loading="lazy">
          </picture>
        </figure>

        <figure class="about__ph about__ph--b reveal" data-stage="2">
          <picture>
            <source srcset="assets/img/about-2-coach.webp" type="image/webp">
            <img src="assets/img/about-2-coach.jpg" alt="Тренер занимается с ребёнком на льду" width="896" height="1372" loading="lazy">
          </picture>
        </figure>

        <figure class="about__ph about__ph--c reveal" data-stage="3">
          <picture>
            <source srcset="assets/img/about-3.webp" type="image/webp">
            <img src="assets/img/about-3.jpg" alt="Спортсменка школы выполняет элемент на льду" width="1372" height="896" loading="lazy">
          </picture>
        </figure>
      </div>

      <ol class="stages">
        <li class="stage reveal" data-stage="1">
          <span class="stage__num">01</span>
          <div class="stage__body">
            <h3 class="stage__title">Начинаем с основ</h3>
            <p>Помогаем освоиться на льду, научиться держать равновесие и уверенно чувствовать себя на коньках.</p>
          </div>
          <div class="stage__slot"></div>
        </li>

        <li class="stage reveal" data-stage="2">
          <span class="stage__num">02</span>
          <div class="stage__body">
            <h3 class="stage__title">Развиваем постепенно</h3>
            <p>Добавляем новые движения и элементы тогда, когда ребёнок готов к ним технически и физически.</p>
          </div>
          <div class="stage__slot"></div>
        </li>

        <li class="stage reveal" data-stage="3">
          <span class="stage__num">03</span>
          <div class="stage__body">
            <h3 class="stage__title">Есть куда расти</h3>
            <p>В школе есть группы разных уровней — от первых занятий до подготовки к соревнованиям и спортивным разрядам.</p>
          </div>
          <div class="stage__slot"></div>
        </li>
      </ol>

    </div>
  </section>

  <!-- ============ 3. ПЕРВОЕ ЗАНЯТИЕ ============ -->
  <section class="section section--trial" id="first">
    <div class="shell trial">

      <div class="trial__intro reveal">
        <p class="eyebrow">Первое занятие</p>
        <h2 class="h2">Первый шаг —<br>просто попробовать</h2>
        <p class="trial__lead">
          Первое занятие бесплатно. Ребёнок познакомится с тренером, выйдет на лёд
          и попробует первые упражнения, и вы сможете понять, подходит ли ему
          фигурное катание.
        </p>
      </div>

      <figure class="trial__photo reveal">
        <picture>
          <source srcset="assets/img/first-lesson.webp" type="image/webp">
          <img src="assets/img/first-lesson.jpg" alt="Тренер помогает девочке удерживать равновесие на льду во время занятия" width="1344" height="896" loading="lazy">
        </picture>
      </figure>

      <div class="trial__body">
        <!-- Три этапа как одна траектория: точки смещаются вправо,
             тонкая линия ведёт взгляд от заявки к выходу на лёд -->
        <div class="route">

          <div class="route__step route__step--1 reveal">
            <h3 class="route__title">Вы оставляете заявку</h3>
            <p>Мы свяжемся с вами и ответим на вопросы.</p>
          </div>

          <svg class="route__link route__link--1" viewBox="0 0 100 100" preserveAspectRatio="none" fill="none" aria-hidden="true">
            <path d="M0 0C0 44 100 56 100 100" stroke="currentColor" stroke-width="1.6"
                  stroke-linecap="round" vector-effect="non-scaling-stroke"/>
          </svg>

          <div class="route__step route__step--2 reveal">
            <h3 class="route__title">Мы подбираем группу</h3>
            <p>Найдём подходящее время и расскажем, как подготовиться.</p>
          </div>

          <svg class="route__link route__link--2" viewBox="0 0 100 100" preserveAspectRatio="none" fill="none" aria-hidden="true">
            <path d="M0 0C0 44 100 56 100 100" stroke="currentColor" stroke-width="1.6"
                  stroke-linecap="round" vector-effect="non-scaling-stroke"/>
          </svg>

          <div class="route__step route__step--3 reveal">
            <h3 class="route__title">Ребёнок выходит на лёд</h3>
            <p>Познакомится с тренером и попробует первые упражнения.</p>
          </div>

          <!-- Траектория завершается сердцем, нарисованным одним росчерком
          <svg class="route__heart" viewBox="0 0 64 76" fill="none" aria-hidden="true">
            <path d="M32 0C33 9 34 16 32 22C29 14 22 11 15 15C5 20 1 28 3 37C6 50 19 61 32 71C45 61 58 50 61 37C63 28 59 20 49 15C42 11 35 14 33 22C31 27 25 26 26 21C27 17 33 17 34 23"
                  stroke="currentColor" stroke-width="2.1"
                  stroke-linecap="round" stroke-linejoin="round"/>
          </svg> -->

        </div>

        <a class="btn btn--primary trial__cta reveal" href="#zapis">Записаться бесплатно</a>
      </div>

    </div>
  </section>

  <!-- ============ 4. КОМАНДА ============ -->
  <section class="section section--sand section--team" id="team">
    <div class="shell">

<?php echo imp_render_team(); ?>
    </div>
  </section>

  <!-- ============ 5. СПЕЦИАЛЬНЫЕ ПРЕДЛОЖЕНИЯ ============ -->
  <section class="section section--offers" id="promo">
    <div class="shell">

      <div class="offers__head reveal">
        <p class="eyebrow eyebrow--light">Специальные предложения</p>
        <h2 class="h2 h2--light">Начать проще,<br>чем кажется</h2>
      </div>

      <div class="offers reveal">

        <!-- Главное предложение -->
        <div class="offer offer--main">
          <h3 class="offer__title">Первое занятие</h3>
          <p class="offer__big offer__big--gold">Бесплатно</p>
          <p class="offer__text">
            Попробуйте фигурное катание и познакомьтесь с «Империей льда»
            без оплаты первого занятия.
          </p>
          <a class="btn btn--primary offer__cta" href="#zapis">Записаться</a>
        </div>

        <!-- Скидка для семьи -->
        <div class="offer offer--family">
          <p class="offer__label">Для всей семьи</p>
          <h3 class="offer__headline">
            <span class="offer__big">−30%</span>
            <span class="offer__headline-text">на абонемент второго ребёнка</span>
          </h3>
          <p class="offer__text">
            Если в «Империи льда» занимаются двое детей из одной семьи,
            на абонемент второго ребёнка действует скидка 30%.
          </p>
        </div>

        <!--
          ВНИМАНИЕ: содержание акции «Парни на лёд» временное.
          Условия ещё не определены — перед публикацией замените
          текст внутри .offer--boys. Структура менять не требуется.
        -->
        <div class="offer offer--boys">
          <h3 class="offer__title">Парни на лёд</h3>
          <p class="offer__words">
            <span>Скорость.</span>
            <span>Прыжки.</span>
            <span>Координация.</span>
            <span>Характер.</span>
          </p>
          <p class="offer__text">
            Специальное предложение для мальчиков, которые хотят попробовать
            фигурное катание.
          </p>
        </div>

      </div>
    </div>
  </section>

  <!-- ============ 6. ГРУППЫ И АБОНЕМЕНТЫ ============ -->
  <section class="section section--groups" id="prices">
    <div class="shell">

<?php echo imp_render_groups(); ?>
    </div>
  </section>

  <!-- ============ 7. ЖИЗНЬ ШКОЛЫ ============ -->
  <section class="section section--life" id="life">
    <div class="shell">

      <div class="life__head reveal">
        <p class="eyebrow">Жизнь школы</p>
        <h2 class="h2">Лёд, тренировки,<br>выступления</h2>
        <p class="life__lead">То, что невозможно передать расписанием и цифрами.</p>
      </div>

      <!--
        Живой фотоколлаж. Снимки в плитках меняются по очереди,
        по одному раз в полторы секунды, в случайном порядке.

        СПИСОК ФОТОГРАФИЙ СОБИРАЕТСЯ САМ.
        Чтобы добавить кадр в галерею, просто положите файл
        в assets/img/gallery/ — правки в этом файле не нужны.
        Перечень отдаёт gallery.php, он читает содержимое папки.

        Подписи (alt) можно задать в assets/img/gallery/alt.json:
          { "life-1.jpg": "Ребёнок на льду во время тренировки" }
        Для остальных файлов подставляется общая подпись.

        Список в data-photos ниже — запасной. Он используется,
        только если gallery.php почему-то недоступен.
      -->
      <div class="life" data-gallery data-source="gallery.php" data-photos='[
        {"src":"assets/img/gallery/life-1.jpg","alt":"Ребёнок на льду во время тренировки в школе «Империя льда»"},
        {"src":"assets/img/gallery/life-2.jpg","alt":"Юная фигуристка выполняет элемент на тренировке"},
        {"src":"assets/img/gallery/life-3.jpg","alt":"Спортсменка «Империи льда» удерживает элемент на льду"},
        {"src":"assets/img/gallery/life-4.jpg","alt":"Спортсменка школы во время выступления на ледовой арене"},
        {"src":"assets/img/gallery/life-5.jpg","alt":"Юная спортсменка школы с наградой после соревнований"}
      ]'>
        <figure class="tile tile--big" data-slot></figure>
        <figure class="tile tile--s1" data-slot></figure>
        <figure class="tile tile--s2" data-slot></figure>
        <figure class="tile tile--s3" data-slot></figure>
      </div>

    </div>
  </section>

  <!-- Полноэкранный просмотр: открывается нажатием на любую фотографию -->
  <dialog class="viewer" id="photo-viewer" aria-label="Просмотр фотографий">
    <button class="viewer__close" type="button" data-viewer-close aria-label="Закрыть просмотр"></button>
    <button class="viewer__nav viewer__nav--prev" type="button" data-viewer-prev aria-label="Предыдущая фотография"></button>
    <img class="viewer__img" id="viewer-img" alt="">
    <button class="viewer__nav viewer__nav--next" type="button" data-viewer-next aria-label="Следующая фотография"></button>
    <p class="viewer__count" data-viewer-count aria-live="polite"></p>
  </dialog>

  <!-- ============ 8. ВОПРОСЫ И ОТВЕТЫ ============ -->
  <section class="section section--sand section--faq" id="faq">
    <div class="shell faq">

      <div class="faq__head reveal">
        <p class="eyebrow">Вопросы и ответы</p>
        <h2 class="h2">Перед первым<br>выходом на лёд</h2>
      </div>

      <div class="faq__body reveal">

        <div class="faq__group">
          <h3 class="faq__group-title">Первое занятие</h3>
          <div class="faq__list">
          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-1" id="faq-1-btn">
              <span class="acc__q">С какого возраста можно начинать заниматься?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-1" role="region" aria-labelledby="faq-1-btn" hidden>
              <p>В «Империи льда» есть группы для детей от 3-х лет. Для самых маленьких занятия начинаются со знакомства со льдом, базовых движений и постепенного развития уверенности на коньках.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-2" id="faq-2-btn">
              <span class="acc__q">Что нужно взять на первое занятие?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-2" role="region" aria-labelledby="faq-2-btn" hidden>
              <p>Возьмите флисовые штаны и кофту, перчатки и шапку. Одежда должна быть удобной и не сковывать движения.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-3" id="faq-3-btn">
              <span class="acc__q">Нужно ли покупать коньки перед первым занятием?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-3" role="region" aria-labelledby="faq-3-btn" hidden>
              <p>Нет. У нас есть недорогой прокат профессиональных коньков. На первое время этого достаточно, а позже тренер поможет подобрать подходящие коньки для регулярных занятий.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-4" id="faq-4-btn">
              <span class="acc__q">Что делать, если ребёнок никогда не стоял на коньках?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-4" role="region" aria-labelledby="faq-4-btn" hidden>
              <p>Это не проблема. В начальные группы приходят в том числе дети без опыта. Тренер начинает с простых упражнений и постепенно знакомит ребёнка со льдом.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-5" id="faq-5-btn">
              <span class="acc__q">Можно ли родителям присутствовать на тренировке?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-5" role="region" aria-labelledby="faq-5-btn" hidden>
              <p>Мы стараемся ограничивать присутствие родителей непосредственно у льда, чтобы дети меньше отвлекались и могли сосредоточиться на занятии и работе с тренером. При этом на ледовой арене есть тёплое кафе, откуда родители могут наблюдать за тренировочным процессом в комфортной обстановке.</p>
            </div>
          </div>
          </div>
        </div>

        <div class="faq__group">
          <h3 class="faq__group-title">Обучение</h3>
          <div class="faq__list">
          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-6" id="faq-6-btn">
              <span class="acc__q">Как понять, какая группа подходит ребёнку?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-6" role="region" aria-labelledby="faq-6-btn" hidden>
              <p>Возраст помогает определить примерное направление, но окончательно подходящую группу лучше выбирать после пробного занятия. Тренер оценит его уровень подготовки и предложит подходящий вариант.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-7" id="faq-7-btn">
              <span class="acc__q">Когда ребёнка переводят в следующую группу?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-7" role="region" aria-labelledby="faq-7-btn" hidden>
              <p>Переход происходит, когда спортсмен достигает необходимого для следующего этапа уровня подготовки. Также перевод может быть рекомендован тренером, если он видит, что ребёнок готов двигаться дальше.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-8" id="faq-8-btn">
              <span class="acc__q">Сколько раз в неделю проходят занятия?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-8" role="region" aria-labelledby="faq-8-btn" hidden>
              <p>Количество тренировок зависит от группы и уровня подготовки. Актуальное количество занятий и расписание можно посмотреть в разделе <a class="faq__link" href="#prices">«Группы и абонементы»</a>.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-10" id="faq-10-btn">
              <span class="acc__q">Участвуют ли спортсмены школы в соревнованиях?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-10" role="region" aria-labelledby="faq-10-btn" hidden>
              <p>Да. По мере подготовки спортсмены участвуют в соревнованиях и движутся к выполнению спортивных разрядов. «Империя льда» входит в <a class="faq__link" href="https://fskating.spb.ru/schools" target="_blank" rel="noopener noreferrer">Реестр организаций</a>, развивающих фигурное катание в Санкт-Петербурге, поэтому спортивное направление является полноценной частью обучения в школе.</p>
            </div>
          </div>

          <div class="acc">
            <button class="acc__btn" type="button" aria-expanded="false" aria-controls="faq-9" id="faq-9-btn">
              <span class="acc__q">Можно ли заниматься фигурным катанием без соревнований?</span>
              <span class="acc__icon" aria-hidden="true"></span>
            </button>
            <div class="acc__panel" id="faq-9" role="region" aria-labelledby="faq-9-btn" hidden>
              <p>Да. В школе есть любительские группы для детей, которые хотят заниматься фигурным катанием для себя, без интенсивного спортивного графика.</p>
            </div>
          </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ============ 9. ЗАЯВКА ============ -->
  <!-- data-mobile-anchor: на узком экране ссылки на этот раздел ведут сразу к форме -->
  <!-- ============ 9. ЗАПИСЬ НА ПРОБНОЕ ЗАНЯТИЕ ============ -->
  <section class="section section--lead" id="zapis" data-mobile-anchor="#lead-form">
    <div class="shell lead">

      <div class="lead__intro reveal">
        <p class="eyebrow">Пробное занятие</p>
        <h2 class="h2">Попробуйте фигурное катание бесплатно</h2>
        <p class="lead__sub">
          Оставьте контакты. Мы свяжемся с вами, подберём подходящую группу
          и удобное время для первого занятия.
        </p>
      </div>

      <figure class="lead__photo reveal">
        <picture>
          <source srcset="assets/img/lead.webp" type="image/webp">
          <img src="assets/img/lead.jpg" alt="Ребёнок с тренером на льду" width="900" height="1200" loading="lazy">
        </picture>
      </figure>

      <div class="lead__box reveal" id="lead-form" tabindex="-1">

        <!-- Появляется, только если посетитель пришёл с карточки группы -->
        <p class="chosen" data-chosen hidden>
          <span>Вы выбрали: <strong data-chosen-name></strong></span>
          <a class="chosen__reset" href="#prices" data-chosen-reset>Изменить</a>
        </p>

        <form class="form" id="apply-form" data-lead-form novalidate>

          <!-- Название группы с карточки; пустое при обычной записи -->
          <input type="hidden" name="group" data-group-field value="">

          <div class="field">
            <label class="field__label" for="parent">Имя родителя</label>
            <input class="field__input" type="text" id="parent" name="parent" data-validate
                   autocomplete="name" placeholder="Ваше имя"
                   aria-describedby="err-parent" required>
            <p class="field__error" id="err-parent" data-error-for="parent"></p>
          </div>

          <div class="field">
            <label class="field__label" for="phone">Телефон</label>
            <input class="field__input" type="tel" id="phone" name="phone" data-validate
                   autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__"
                   aria-describedby="err-phone" required>
            <p class="field__error" id="err-phone" data-error-for="phone"></p>
          </div>

          <div class="field-row">
            <div class="field">
              <label class="field__label" for="child">Имя ребёнка</label>
              <input class="field__input" type="text" id="child" name="child" data-validate
                     placeholder="Имя ребёнка" aria-describedby="err-child" required>
              <p class="field__error" id="err-child" data-error-for="child"></p>
            </div>
            <div class="field field--age">
              <label class="field__label" for="age">Возраст ребёнка</label>
              <input class="field__input" type="number" id="age" name="age" data-validate
                     inputmode="numeric" min="2" max="15" step="1" placeholder="4"
                     aria-describedby="err-age" required>
              <p class="field__error" id="err-age" data-error-for="age"></p>
            </div>
          </div>

          <div class="field">
            <label class="field__label" for="comment">
              Комментарий <span class="field__optional">необязательно</span>
            </label>
            <textarea class="field__input field__input--area" id="comment" name="comment"
                      rows="3" placeholder="Напишите, если хотите что-то уточнить"></textarea>
          </div>

          <!-- Ловушка для спам-ботов: скрыта от людей, не удалять -->
          <div class="field-trap" aria-hidden="true">
            <label for="company">Не заполняйте это поле</label>
            <input type="text" id="company" name="company" tabindex="-1" autocomplete="off">
          </div>

          <!-- Согласие: отдельное обязательное поле, без отметки форма не уходит -->
          <div class="field field--check">
            <label class="check">
              <input type="checkbox" id="agree" name="agree" value="1" data-validate
                     aria-describedby="err-agree" required>
              <span class="check__box" aria-hidden="true"></span>
              <span class="check__text">
                <a href="consent.html" target="_blank" rel="noopener">Я даю согласие на обработку персональных данных</a>
                и подтверждаю, что ознакомлен(а) с
                <a href="privacy.html" target="_blank" rel="noopener">Политикой в отношении обработки персональных данных</a>.
              </span>
            </label>
            <p class="field__error" id="err-agree" data-error-for="agree"></p>
          </div>

          <button class="btn btn--primary lead__cta" type="submit" id="submit-btn">Записаться бесплатно</button>

          <p class="form__fail" data-fail role="alert" hidden></p>
        </form>

        <div class="form-success" data-success role="status" aria-live="polite" tabindex="-1" hidden>
          <span class="form-success__mark" aria-hidden="true"></span>
          <h3 class="h3">Заявка отправлена</h3>
          <p>Спасибо! Мы свяжемся с вами, чтобы подобрать группу и время первого занятия.</p>
          <button class="btn btn--link" type="button" data-reset>Отправить ещё одну заявку</button>
        </div>
      </div>

    </div>
  </section>

</main>

<!-- ============ 10. ФУТЕР ============ -->
<footer class="footer" id="contacts">
  <div class="shell footer__inner">

    <div class="footer__brand">
      <a class="footer__logo" href="#top" aria-label="Империя льда — в начало страницы">
        <!-- Логотип используется как есть, без изменений формы, цвета и пропорций -->
        <img src="assets/logo.png" alt="Логотип школы фигурного катания «Империя льда»" width="560" height="532" loading="lazy">
        <span class="footer__name">Империя льда</span>
      </a>
      <p class="footer__about">Школа фигурного катания для детей от 3-х лет в Санкт-Петербурге.</p>
      <p class="footer__claim">Здесь начинается путь фигуриста.</p>
    </div>

    <div class="footer__col">
      <h2 class="footer__title">Контакты</h2>
      <a class="footer__phone" href="tel:+79992154556">+7 (999) 215-45-56</a>
      <address class="footer__address">
        Санкт-Петербург, Красное Село<br>
        ул. Свободы, 15<br>
        ледовая арена «Гололёд»
      </address>
      <ul class="socials">
        <li>
          <a class="social" href="https://vk.ru/iceimperia" target="_blank" rel="noopener noreferrer" aria-label="ВКонтакте">
            <svg class="social__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <rect x="1.5" y="1.5" width="21" height="21" rx="7" fill="none" stroke="currentColor" stroke-width="1.6"/>
              <path d="M6.6 8.6h2.05c.13 2.2.99 3.68 1.83 4.28.28.2.45.05.45-.35V8.6h1.93v2.86c0 .38.13.5.28.5.32 0 .74-.5 1.42-2.14l.36-.86h1.93c-.6 1.62-1.32 2.83-2.02 3.5-.28.27-.28.5 0 .78.6.6 1.5 1.6 2.02 2.66h-2.12c-.5-.9-1.2-1.72-1.62-2.06-.28-.22-.45-.13-.45.28v1.78h-.66c-2.6 0-4.72-2.2-5.4-6.3z" fill="currentColor"/>
            </svg>
            <span class="social__text">ВКонтакте</span>
          </a>
        </li>
        <li>
          <a class="social" href="https://t.me/ice_imperia" target="_blank" rel="noopener noreferrer" aria-label="Telegram">
            <svg class="social__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M21.9 4.3 3.2 11.5c-1.05.4-1.04 1.2.05 1.53l4.66 1.45 1.8 5.4c.2.6.38.85.8.85.32 0 .46-.15.64-.32l2.24-2.18 4.66 3.44c.86.47 1.47.23 1.68-.79l3.02-14.2c.31-1.25-.48-1.82-1.3-1.44zM8.9 14.16 18.5 8.1c.42-.26.8-.12.49.17l-8.22 7.42-.32 3.4-1.55-4.93z" fill="currentColor"/>
            </svg>
            <span class="social__text">Telegram</span>
          </a>
        </li>
      </ul>
    </div>

    <nav class="footer__col" aria-label="Разделы сайта">
      <h2 class="footer__title">Навигация</h2>
      <ul class="footer__nav">
        <li><a class="footer__link" href="#about">О школе</a></li>
        <li><a class="footer__link" href="#team">Команда</a></li>
        <li><a class="footer__link" href="#prices">Группы и цены</a></li>
        <li><a class="footer__link" href="#faq">FAQ</a></li>
        <li><a class="footer__link" href="#contacts">Контакты</a></li>
      </ul>
      <a class="footer__lead-link" href="#zapis">Записаться на бесплатное занятие</a>
    </nav>

  </div>

  <div class="shell footer__bottom">
    <p>© <span id="year">2026</span> ИП Стефанова А.Д.</p>
    <span class="footer__legals">
      <a class="footer__legal" href="privacy.html">Политика обработки персональных данных</a>
      <a class="footer__legal" href="consent.html">Согласие на обработку данных</a>
    </span>
  </div>
</footer>

<!-- Быстрый возврат к началу: появляется на мобильном после прокрутки -->
<button class="totop" id="totop" type="button" aria-label="Вернуться к началу страницы"></button>

<script src="script.js?v=20260903c"></script>
</body>
</html>
