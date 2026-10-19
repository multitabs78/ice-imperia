/* =========================================================
   Империя льда — интерактив
   Меню, аккордеон, маска телефона, валидация формы, анимации
   ========================================================= */
(function () {
  'use strict';

  /* Адрес обработчика формы.
     Заявка уходит в Telegram из send.php — токен бота и chat_id
     указываются там, на сервере. Если файл лежит в другой папке,
     поменяйте путь здесь. */
  var FORM_ENDPOINT = 'send.php';

  /* Предпросмотр без сервера (открытие файла с диска): форма
     показывает сообщение об успехе, не обращаясь к send.php.
     На реальном хостинге по http/https заявка уходит в Telegram. */
  var DEMO_MODE = !/^https?:$/.test(window.location.protocol);

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------
     1. Год в футере
  ------------------------------------------------------ */
  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ------------------------------------------------------
     2. Шапка: фон при прокрутке
  ------------------------------------------------------ */
  var header = document.getElementById('header');
  var hero = document.getElementById('top');

  var heroBottom = 0;

  function measureHero() {
    heroBottom = hero ? hero.getBoundingClientRect().bottom + window.pageYOffset : 0;
  }

  function isNarrow() {
    return window.innerWidth <= 767;
  }

  // На узком экране фон шапки появляется только после первого экрана
  function solidThreshold() {
    return (isNarrow() && heroBottom) ? heroBottom - 72 : 40;
  }

  function updateHeader() {
    if (!header) return;
    header.classList.toggle('is-solid', window.pageYOffset > solidThreshold());
  }

  measureHero();
  updateHeader();
  window.addEventListener('load', function () { measureHero(); updateHeader(); });

  /* ------------------------------------------------------
     3. Мобильное меню
  ------------------------------------------------------ */
  var burger = document.getElementById('burger');
  var nav = document.getElementById('nav');

  function closeMenu() {
    if (!nav || !burger) return;
    nav.classList.remove('is-open');
    burger.classList.remove('is-open');
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-label', 'Открыть меню');
    document.body.style.overflow = '';
  }

  function toggleMenu() {
    if (!nav || !burger) return;
    var open = !nav.classList.contains('is-open');
    nav.classList.toggle('is-open', open);
    burger.classList.toggle('is-open', open);
    if (open) updateSpy();   // в открытом меню видно, где сейчас посетитель
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  if (burger) burger.addEventListener('click', toggleMenu);

  // Закрываем меню по клику на ссылку и по Esc
  document.querySelectorAll('.nav a').forEach(function (link) {
    link.addEventListener('click', closeMenu);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 1023) closeMenu();
    measureHero();
    updateHeader();
  });

  /* ------------------------------------------------------
     2.1 Кнопка возврата к началу (только на узких экранах)
  ------------------------------------------------------ */
  var toTop = document.getElementById('totop');

  function updateToTop() {
    if (!toTop) return;
    toTop.classList.toggle('is-visible', window.pageYOffset > window.innerHeight * 1.2);
  }

  if (toTop) {
    toTop.addEventListener('click', function () {
      closeMenu();
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
    updateToTop();
  }

  /* ------------------------------------------------------
     3.1 Подсветка текущего раздела в меню
  ------------------------------------------------------ */
  var navLinks = Array.prototype.slice.call(document.querySelectorAll('.nav__link'));
  var spySections = navLinks
    .map(function (link) {
      var id = link.getAttribute('href');
      return id && id.charAt(0) === '#' ? document.querySelector(id) : null;
    })
    .filter(Boolean);

  function updateSpy() {
    if (!spySections.length) return;

    var line = window.pageYOffset + (header ? header.offsetHeight : 0) + 60;
    var currentId = '';

    spySections.forEach(function (section) {
      if (section.getBoundingClientRect().top + window.pageYOffset <= line) {
        currentId = section.id;
      }
    });

    // У самого низа страницы подсвечиваем последний раздел
    if (window.innerHeight + window.pageYOffset >= document.documentElement.scrollHeight - 8) {
      currentId = spySections[spySections.length - 1].id;
    }

    navLinks.forEach(function (link) {
      var on = currentId !== '' && link.getAttribute('href') === '#' + currentId;
      link.classList.toggle('is-current', on);
      if (on) link.setAttribute('aria-current', 'true');
      else link.removeAttribute('aria-current');
    });
  }

  updateSpy();

  /* ------------------------------------------------------
     4. Плавная прокрутка к якорям
        (дублирует scroll-behavior для старых браузеров
        и корректно учитывает высоту шапки)
  ------------------------------------------------------ */
  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var id = link.getAttribute('href');
      if (!id || id === '#') return;
      var target = document.querySelector(id);
      if (!target) return;

      // На узком экране раздел может указывать, куда вести на самом деле:
      // ссылки на блок заявки прокручивают сразу к форме, а не к заголовку
      var alt = target.getAttribute('data-mobile-anchor');
      if (alt && window.innerWidth <= 980) {
        var altTarget = document.querySelector(alt);
        if (altTarget) target = altTarget;
      }

      e.preventDefault();
      closeMenu();

      var offset = header ? header.offsetHeight + 12 : 0;
      var top = target.getBoundingClientRect().top + window.pageYOffset - offset;

      window.scrollTo({
        top: top,
        behavior: reduceMotion ? 'auto' : 'smooth'
      });

      // Возвращаем фокус для доступности
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
  });

  /* ------------------------------------------------------
     5. Появление блоков при прокрутке
  ------------------------------------------------------ */
  var revealItems = document.querySelectorAll('.reveal');

  if (reduceMotion || !('IntersectionObserver' in window)) {
    revealItems.forEach(function (el) { el.classList.add('is-visible'); });
  } else {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });

    revealItems.forEach(function (el, i) {
      el.style.transitionDelay = (i % 4) * 70 + 'ms';
      observer.observe(el);
    });
  }

  /* ------------------------------------------------------
     6. Лёгкое смещение фотографий при прокрутке
  ------------------------------------------------------ */
  var parallaxItems = Array.prototype.slice.call(document.querySelectorAll('.parallax'));
  var ticking = false;

  function applyParallax() {
    var vh = window.innerHeight;
    parallaxItems.forEach(function (el) {
      var rect = el.getBoundingClientRect();
      if (rect.bottom < -200 || rect.top > vh + 200) return;
      var img = el.querySelector('img') || el;
      var speed = parseFloat(el.getAttribute('data-speed') || '0.05');
      var shift = (rect.top + rect.height / 2 - vh / 2) * speed;
      img.style.transform = 'translate3d(0,' + shift.toFixed(1) + 'px,0) scale(1.08)';
    });
  }

  function onScroll() {
    updateHeader();
    updateSpy();
    updateToTop();
    if (!ticking && !reduceMotion && parallaxItems.length) {
      ticking = true;
      window.requestAnimationFrame(function () {
        applyParallax();
        ticking = false;
      });
    }
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', applyParallax);
  if (!reduceMotion) applyParallax();

  /* ------------------------------------------------------
     6.1 «О школе»: на мобильном фотографии переезжают
         внутрь своих этапов, чтобы история читалась подряд
  ------------------------------------------------------ */
  (function () {
    var media = document.querySelector('.about__media');
    if (!media) return;

    var figures = Array.prototype.slice.call(media.querySelectorAll('.about__ph'));
    if (!figures.length) return;

    var narrow = window.matchMedia('(max-width: 767px)');

    function place() {
      figures.forEach(function (fig) {
        if (narrow.matches) {
          var stage = document.querySelector('.stage[data-stage="' + fig.getAttribute('data-stage') + '"]');
          var slot = stage && stage.querySelector('.stage__slot');
          if (slot && fig.parentNode !== slot) slot.appendChild(fig);
        } else if (fig.parentNode !== media) {
          media.appendChild(fig);
        }
      });
    }

    place();
    if (narrow.addEventListener) narrow.addEventListener('change', place);
    else if (narrow.addListener) narrow.addListener(place);
  })();

  /* ------------------------------------------------------
     7. Слайдер карточек (тренеры, цены)
        Карусель включается только тогда, когда карточки
        не помещаются в один ряд — на мобильных это всегда.
  ------------------------------------------------------ */
  document.querySelectorAll('[data-slider]').forEach(function (slider) {
    var track = slider.querySelector('.slider__track');
    var dotsBox = slider.querySelector('[data-dots]');
    var arrows = slider.querySelectorAll('[data-dir]');
    if (!track) return;

    var items = Array.prototype.slice.call(track.children);
    if (items.length < 2) return;

    var pages = items.length;

    function isCarousel() {
      return slider.classList.contains('is-carousel');
    }

    function gapSize() {
      var g = parseFloat(window.getComputedStyle(track).columnGap);
      return isNaN(g) ? 0 : g;
    }

    // Позиция прокрутки, при которой карточка встаёт на своё место
    function targetFor(item) {
      var base = item.offsetLeft - track.offsetLeft;
      var align = window.getComputedStyle(item).scrollSnapAlign || '';
      if (align.indexOf('center') > -1) {
        base -= (track.clientWidth - item.offsetWidth) / 2;
      }
      var max = track.scrollWidth - track.clientWidth;
      return Math.max(0, Math.min(base, max));
    }

    function currentIndex() {
      var pos = track.scrollLeft;
      var best = 0, min = Infinity;
      items.forEach(function (item, i) {
        var d = Math.abs(targetFor(item) - pos);
        if (d < min - 0.5) { min = d; best = i; }
      });
      return Math.min(best, pages - 1);
    }

    function goTo(index) {
      // Цикличность: за последним слайдом идёт первый, и наоборот
      if (index < 0) index = pages - 1;
      if (index > pages - 1) index = 0;
      var item = items[index];
      if (!item) return;
      track.scrollTo({
        left: targetFor(item),
        behavior: reduceMotion ? 'auto' : 'smooth'
      });
    }

    function syncControls() {
      if (!isCarousel() || !dotsBox) return;
      var active = currentIndex();
      Array.prototype.forEach.call(dotsBox.children, function (dot, i) {
        var on = i === active;
        dot.classList.toggle('is-active', on);
        dot.setAttribute('aria-current', on ? 'true' : 'false');
      });
    }

    function buildDots() {
      if (!dotsBox) return;
      dotsBox.innerHTML = '';
      for (var i = 0; i < pages; i++) {
        (function (index) {
          var dot = document.createElement('button');
          dot.type = 'button';
          dot.className = 'slider__dot';
          dot.setAttribute('aria-label', 'Слайд ' + (index + 1) + ' из ' + pages);
          dot.addEventListener('click', function () { goTo(index); });
          dotsBox.appendChild(dot);
        })(i);
      }
    }

    function measure() {
      // Меряем всегда в режиме карусели, иначе ширины карточек другие
      slider.classList.add('is-carousel');
      var overflows = track.scrollWidth - track.clientWidth > 4;
      slider.classList.toggle('is-carousel', overflows);

      if (overflows) {
        track.setAttribute('role', 'region');
        // Карточки за краем трека не должны оставаться невидимыми
        items.forEach(function (item) { item.classList.add('is-visible'); });

        // Сколько карточек видно одновременно — столько последних точек лишние
        var gap = gapSize();
        var itemW = items[0].getBoundingClientRect().width;
        var per = Math.max(1, Math.round((track.clientWidth + gap) / (itemW + gap)));
        var centered = (window.getComputedStyle(items[0]).scrollSnapAlign || '').indexOf('center') > -1;
        pages = centered ? items.length : Math.max(1, items.length - per + 1);

        if (dotsBox && dotsBox.children.length !== pages) buildDots();
        syncControls();
      } else {
        track.removeAttribute('role');
        track.scrollLeft = 0;
      }
    }

    arrows.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var step = btn.getAttribute('data-dir') === 'next' ? 1 : -1;
        goTo(currentIndex() + step);
      });
    });

    var syncTimer;
    track.addEventListener('scroll', function () {
      window.clearTimeout(syncTimer);
      syncTimer = window.setTimeout(syncControls, 80);
    }, { passive: true });

    var resizeTimer;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(measure, 150);
    });

    measure();
    // Пересчёт после подгрузки шрифтов и фотографий
    window.addEventListener('load', measure);
  });

  /* ------------------------------------------------------
     8. Аккордеон «Вопросы и ответы»
  ------------------------------------------------------ */
  var accButtons = document.querySelectorAll('.acc__btn');

  function closePanel(btn) {
    var panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) return;
    btn.setAttribute('aria-expanded', 'false');
    if (reduceMotion) {
      panel.hidden = true;
      return;
    }
    panel.style.height = panel.scrollHeight + 'px';
    requestAnimationFrame(function () {
      panel.style.transition = 'height .35s cubic-bezier(.25,.6,.25,1)';
      panel.style.height = '0px';
    });
    window.setTimeout(function () {
      panel.hidden = true;
      panel.style.transition = '';
      panel.style.height = '';
    }, 350);
  }

  function openPanel(btn) {
    var panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) return;
    btn.setAttribute('aria-expanded', 'true');
    panel.hidden = false;
    if (reduceMotion) return;
    var h = panel.scrollHeight;
    panel.style.height = '0px';
    requestAnimationFrame(function () {
      panel.style.transition = 'height .35s cubic-bezier(.25,.6,.25,1)';
      panel.style.height = h + 'px';
    });
    window.setTimeout(function () {
      panel.style.transition = '';
      panel.style.height = '';
    }, 350);
  }

  accButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var isOpen = btn.getAttribute('aria-expanded') === 'true';
      // Открыт только один ответ одновременно
      accButtons.forEach(function (other) {
        if (other !== btn && other.getAttribute('aria-expanded') === 'true') closePanel(other);
      });
      if (isOpen) closePanel(btn); else openPanel(btn);
    });
  });

  /* ------------------------------------------------------
     9. Карточки групп: раскрытие расписания и подробностей
        Внутри одного смыслового раздела открыта одна карточка:
        при открытии следующей предыдущая плавно закрывается.
  ------------------------------------------------------ */
  document.querySelectorAll('.gset__grid').forEach(function (grid) {
    var toggles = Array.prototype.slice.call(grid.querySelectorAll('.gcard__toggle'));
    if (!toggles.length) return;

    function panelOf(btn) {
      return document.getElementById(btn.getAttribute('aria-controls'));
    }

    function close(btn) {
      var panel = panelOf(btn);
      if (!panel || btn.getAttribute('aria-expanded') !== 'true') return;

      btn.setAttribute('aria-expanded', 'false');
      var card = btn.closest('.gcard');
      if (card) card.classList.remove('is-open');

      if (reduceMotion) { panel.hidden = true; return; }

      panel.style.height = panel.scrollHeight + 'px';
      requestAnimationFrame(function () {
        panel.style.transition = 'height .32s cubic-bezier(.25,.6,.25,1)';
        panel.style.height = '0px';
      });
      window.setTimeout(function () {
        panel.hidden = true;
        panel.style.transition = '';
        panel.style.height = '';
      }, 320);
    }

    function open(btn) {
      var panel = panelOf(btn);
      if (!panel) return;

      // В разделе остаётся раскрытой только одна карточка
      toggles.forEach(function (other) { if (other !== btn) close(other); });

      btn.setAttribute('aria-expanded', 'true');
      var card = btn.closest('.gcard');
      if (card) card.classList.add('is-open');
      panel.hidden = false;

      if (reduceMotion) return;

      var h = panel.scrollHeight;
      panel.style.height = '0px';
      requestAnimationFrame(function () {
        panel.style.transition = 'height .32s cubic-bezier(.25,.6,.25,1)';
        panel.style.height = h + 'px';
      });
      window.setTimeout(function () {
        panel.style.transition = '';
        panel.style.height = '';
      }, 320);
    }

    toggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.getAttribute('aria-expanded') === 'true') close(btn);
        else open(btn);
      });

      // Кнопка «Свернуть» внутри раскрытой части
      var panel = panelOf(btn);
      if (!panel) return;
      panel.querySelectorAll('[data-collapse]').forEach(function (link) {
        link.addEventListener('click', function () {
          close(btn);
          btn.focus();
        });
      });
    });
  });

  /* ------------------------------------------------------
     10. Выбор группы из карточки «Группы и абонементы»
         Кнопка ведёт к форме обычной якорной ссылкой,
         а название группы попадает в скрытое поле заявки.
  ------------------------------------------------------ */
  (function () {
    var field = document.querySelector('[data-group-field]');
    var chip = document.querySelector('[data-chosen]');
    var chipName = document.querySelector('[data-chosen-name]');
    var chipReset = document.querySelector('[data-chosen-reset]');
    if (!field) return;

    function setGroup(name) {
      field.value = name || '';
      if (!chip) return;
      if (name) {
        if (chipName) chipName.textContent = name;
        chip.hidden = false;
      } else {
        chip.hidden = true;
      }
    }

    document.querySelectorAll('a[data-group]').forEach(function (link) {
      link.addEventListener('click', function () {
        setGroup(link.getAttribute('data-group'));
      });
    });

    // «Изменить» сбрасывает выбор и возвращает к секции с группами.
    // Сама прокрутка — обычный якорь, её выполняет общий обработчик ссылок.
    if (chipReset) {
      chipReset.addEventListener('click', function () {
        setGroup('');
      });
    }
  })();

  /* ------------------------------------------------------
     10.1 Живой фотоколлаж «Жизнь школы»
          Плитки меняют снимки по очереди: одна смена раз
          в полторы секунды, каждая плитка обновляется раз
          в шесть секунд. Порядок случайный, при этом двух
          одинаковых кадров на экране одновременно не бывает.

          Список фотографий приходит с сервера (gallery.php
          читает папку assets/img/gallery). Если он недоступен,
          берётся запасной список из data-photos в разметке.
  ------------------------------------------------------ */
  (function () {
    var gallery = document.querySelector('[data-gallery]');
    if (!gallery) return;

    var fallback = [];
    try {
      fallback = JSON.parse(gallery.getAttribute('data-photos')) || [];
    } catch (e) {
      fallback = [];
    }

    var source = gallery.getAttribute('data-source');

    if (source && window.fetch) {
      fetch(source, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (list) {
          start(Array.isArray(list) && list.length ? list : fallback);
        })
        .catch(function () { start(fallback); });
    } else {
      start(fallback);
    }

    function start(photos) {
      if (!photos.length) return;

      var tiles = Array.prototype.slice.call(gallery.querySelectorAll('[data-slot]'));
      if (!tiles.length) return;

      var STEP = 1500;   // пауза между сменами соседних плиток
      var FADE = 1100;   // длительность растворения, совпадает с CSS
      var current = [];  // индексы снимков, которые сейчас на экране
      var queue = [];    // перемешанная очередь, откуда берём следующий
      var order = [];    // порядок обхода плиток: сначала мелкие, потом крупная
      var cursor = 0;
      var timer = null;

      tiles.forEach(function (t, i) { if (i > 0) order.push(i); });
      order.push(0);

      // Ниже 700 px плитки меняют форму, поэтому у кадра может быть
      // своя точка кадрирования для узкого экрана
      var narrow = window.matchMedia('(max-width: 700px)');

      function focusFor(photo) {
        if (narrow.matches && photo.focusMobile) return photo.focusMobile;
        return photo.focus || '';
      }

      // Соотношение сторон кадра. Приходит с сервера; если неизвестно,
      // считаем квадратом, а после первой загрузки уточняем по факту.
      function ratioOf(photo) {
        if (photo.r) return photo.r;
        if (photo.w && photo.h) { photo.r = photo.w / photo.h; return photo.r; }
        return 1;
      }

      // Насколько сильно кадр придётся обрезать в плитке: 0 — идеально
      function penalty(photo, target) {
        if (!target) return 0;
        return Math.abs(Math.log(ratioOf(photo) / target));
      }

      function tileRatio(tile) {
        var b = tile.getBoundingClientRect();
        return b.height > 0 ? b.width / b.height : 0;
      }

      // Перемешивание Фишера — Йетса
      function shuffled() {
        var a = [];
        for (var i = 0; i < photos.length; i++) a.push(i);
        for (var j = a.length - 1; j > 0; j--) {
          var k = Math.floor(Math.random() * (j + 1));
          var t = a[j]; a[j] = a[k]; a[k] = t;
        }
        return a;
      }

      // Следующий снимок: случайный, но не тот, что уже на экране,
      // и по возможности подходящий плитке по форме. Берём несколько
      // свободных кандидатов подряд и выбираем из них наименее обрезаемый —
      // так сохраняется случайность, но вертикальный кадр не попадает
      // в широкую ячейку.
      var LOOKAHEAD = 6;

      function takeNext(ratio) {
        var pool = [];
        for (var guard = 0; guard < photos.length * 2 + 8 && pool.length < LOOKAHEAD; guard++) {
          if (!queue.length) queue = shuffled();
          var cand = queue.shift();
          if (current.indexOf(cand) === -1) pool.push(cand);
          else queue.push(cand);   // занят — вернём в конец очереди
        }
        if (!pool.length) return -1;

        var best = 0;
        for (var i = 1; i < pool.length; i++) {
          if (penalty(photos[pool[i]], ratio) < penalty(photos[pool[best]], ratio)) best = i;
        }
        var chosen = pool.splice(best, 1)[0];
        queue = pool.concat(queue);   // остальных возвращаем в начало очереди
        return chosen;
      }

      function visible(tile) {
        return tile.offsetParent !== null;
      }

      function show(tile, index, animate) {
        var photo = photos[index];
        if (!photo) return;

        var img = document.createElement('img');
        img.alt = photo.alt || '';
        img.decoding = 'async';
        var focus = focusFor(photo);
        if (focus) img.style.objectPosition = focus;
        if (animate) img.className = 'is-entering';

        var old = tile.querySelector('img');

        img.addEventListener('load', function () {
          // уточняем пропорции по факту, если сервер их не прислал
          if (!photo.r && img.naturalWidth && img.naturalHeight) {
            photo.r = img.naturalWidth / img.naturalHeight;
          }
          if (!animate) return;
          // Кадр загружен — только теперь начинаем растворение
          requestAnimationFrame(function () { img.classList.remove('is-entering'); });
          window.setTimeout(function () {
            if (old && old.parentNode === tile) tile.removeChild(old);
          }, FADE + 60);
        });

        img.src = photo.src;
        tile.appendChild(img);
        tile.setAttribute('data-photo', index);

        if (!animate && old) tile.removeChild(old);
      }

      // Плитки получают кадры только когда секция подходит к экрану:
      // иначе фотографии качались бы, пока посетитель читает первый экран
      var filled = false;

      function fill() {
        if (filled) return;
        filled = true;
        queue = shuffled();
        tiles.forEach(function (tile, i) {
          var index = takeNext(tileRatio(tile));
          if (index === -1) index = i % photos.length;
          current[i] = index;
          show(tile, index, false);
        });
      }

      tiles.forEach(function (tile) {
        tile.setAttribute('tabindex', '0');
        tile.setAttribute('role', 'button');
        tile.setAttribute('aria-label', 'Открыть фотографии школы во весь экран');
      });

      function swapNext() {
        for (var attempt = 0; attempt < order.length; attempt++) {
          var slot = order[cursor];
          cursor = (cursor + 1) % order.length;
          if (!visible(tiles[slot])) continue;
          var next = takeNext(tileRatio(tiles[slot]));
          if (next === -1) return;
          current[slot] = next;
          show(tiles[slot], next, true);
          return;
        }
      }

      var onScreen = false;

      function play() {
        if (timer || reduceMotion || photos.length <= tiles.length) return;
        if (!onScreen || document.hidden) return;
        timer = window.setInterval(swapNext, STEP);
      }
      function pause() {
        window.clearInterval(timer);
        timer = null;
      }

      // При переходе между узким и широким экраном обновляем кадрирование
      // На скрытой вкладке смена не нужна
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) pause(); else play();
      });

      // При смене ширины пересчитываем точку кадрирования у видимых кадров
      function refocus() {
        tiles.forEach(function (tile) {
          var i = parseInt(tile.getAttribute('data-photo'), 10);
          var img = tile.querySelector('img');
          if (!img || isNaN(i) || !photos[i]) return;
          img.style.objectPosition = focusFor(photos[i]) || '';
        });
      }
      if (narrow.addEventListener) narrow.addEventListener('change', refocus);
      else if (narrow.addListener) narrow.addListener(refocus);

      // Секция ушла с экрана — смена кадров останавливается,
      // лишние фотографии не качаются
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          onScreen = entries[0].isIntersecting;
          if (onScreen) { fill(); play(); } else { pause(); }
        }, { rootMargin: '200px 0px' }).observe(gallery);
      } else {
        onScreen = true;
        fill();
        play();
      }

      /* ---- Полноэкранный просмотр всех снимков ---- */
      var viewer = document.getElementById('photo-viewer');
      var viewerImg = document.getElementById('viewer-img');
      var viewerCount = viewer && viewer.querySelector('[data-viewer-count]');
      var opener = null;
      var shown = 0;

      if (!viewer || !viewerImg) return;

      function render(index) {
        shown = (index + photos.length) % photos.length;
        viewerImg.src = photos[shown].full || photos[shown].src;
        viewerImg.alt = photos[shown].alt || '';
        if (viewerCount) viewerCount.textContent = (shown + 1) + ' / ' + photos.length;
      }

      function openViewer(index, from) {
        opener = from || null;
        pause();
        render(index);
        if (typeof viewer.showModal === 'function') viewer.showModal();
        else viewer.setAttribute('open', '');
        document.body.style.overflow = 'hidden';
      }

      function closeViewer() {
        if (typeof viewer.close === 'function' && viewer.open) viewer.close();
        else viewer.removeAttribute('open');
        document.body.style.overflow = '';
        viewerImg.removeAttribute('src');
        play();
        if (opener && opener.focus) opener.focus();
      }

      tiles.forEach(function (tile) {
        function open() {
          openViewer(parseInt(tile.getAttribute('data-photo'), 10) || 0, tile);
        }
        tile.addEventListener('click', open);
        tile.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
      });

      viewer.querySelectorAll('[data-viewer-close]').forEach(function (b) {
        b.addEventListener('click', closeViewer);
      });
      viewer.querySelectorAll('[data-viewer-prev]').forEach(function (b) {
        b.addEventListener('click', function () { render(shown - 1); });
      });
      viewer.querySelectorAll('[data-viewer-next]').forEach(function (b) {
        b.addEventListener('click', function () { render(shown + 1); });
      });
      // Клик по затемнённому фону закрывает просмотр
      viewer.addEventListener('click', function (e) {
        if (e.target === viewer) closeViewer();
      });
      viewer.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') { e.preventDefault(); render(shown + 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); render(shown - 1); }
      });
      viewer.addEventListener('close', function () {
        document.body.style.overflow = '';
        play();
      });
      viewer.addEventListener('cancel', function () { document.body.style.overflow = ''; });
    }
  })();

  /* ------------------------------------------------------
     11. Маска телефона: +7 (999) 123-45-67
  ------------------------------------------------------ */
  function formatPhone(value) {
    var digits = value.replace(/\D/g, '');

    // Приводим к российскому формату
    if (digits.charAt(0) === '8') digits = '7' + digits.slice(1);
    if (digits.charAt(0) === '9' && digits.length <= 10) digits = '7' + digits;
    if (digits.charAt(0) !== '7') digits = '7' + digits;
    digits = digits.slice(0, 11);

    var rest = digits.slice(1);
    var out = '+7';
    if (rest.length) out += ' (' + rest.slice(0, 3);
    if (rest.length >= 3) out += ')';
    if (rest.length > 3) out += ' ' + rest.slice(3, 6);
    if (rest.length > 6) out += '-' + rest.slice(6, 8);
    if (rest.length > 8) out += '-' + rest.slice(8, 10);
    return out;
  }

  /* ------------------------------------------------------
     12. Формы заявки
        Один и тот же код обслуживает форму на странице
        и форму в модальном окне: поля ищутся внутри
        своей формы по атрибуту name.
  ------------------------------------------------------ */
  function initForm(form) {
    var wrap = form.parentElement;
    var success = wrap.querySelector('[data-success]');
    var resetBtn = success ? success.querySelector('[data-reset]') : null;
    var submitBtn = form.querySelector('[type="submit"]');
    var submitLabel = submitBtn ? submitBtn.textContent.trim() : 'Оставить заявку';
    var failBox = form.querySelector('[data-fail]');
    var fields = Array.prototype.slice.call(form.querySelectorAll('[data-validate]'));
    var phoneInput = form.querySelector('[name="phone"]');

    function errorBox(input) {
      return form.querySelector('[data-error-for="' + input.name + '"]');
    }

    function showError(input, message) {
      var field = input.closest('.field');
      if (field) field.classList.add('has-error');
      var box = errorBox(input);
      if (box) box.textContent = message;
      input.setAttribute('aria-invalid', 'true');
    }

    function clearError(input) {
      var field = input.closest('.field');
      if (field) field.classList.remove('has-error');
      var box = errorBox(input);
      if (box) box.textContent = '';
      input.removeAttribute('aria-invalid');
    }

    function validate(input) {
      var value = (input.value || '').trim();

      if (input.type === 'checkbox') {
        if (!input.checked) {
          showError(input, 'Отметьте согласие на обработку персональных данных');
          return false;
        }
        clearError(input);
        return true;
      }

      if (!value) {
        showError(input, 'Заполните поле');
        return false;
      }

      if (input.name === 'parent' || input.name === 'child') {
        if (value.length < 2) {
          showError(input, 'Введите имя полностью');
          return false;
        }
      }

      if (input.name === 'age') {
        var age = parseInt(value, 10);
        if (isNaN(age) || age < 2 || age > 15) {
          showError(input, 'Возраст от 2 до 15');
          return false;
        }
      }

      if (input.name === 'phone' && value.replace(/\D/g, '').length !== 11) {
        showError(input, 'Введите номер полностью: +7 (999) 123-45-67');
        return false;
      }

      clearError(input);
      return true;
    }

    if (phoneInput) {
      phoneInput.addEventListener('focus', function () {
        if (!phoneInput.value) phoneInput.value = '+7 ';
      });
      phoneInput.addEventListener('input', function () {
        phoneInput.value = formatPhone(phoneInput.value);
        clearError(phoneInput);
      });
      phoneInput.addEventListener('blur', function () {
        if (phoneInput.value.replace(/\D/g, '').length < 2) phoneInput.value = '';
      });
    }

    fields.forEach(function (input) {
      input.addEventListener(input.type === 'checkbox' ? 'change' : 'blur', function () {
        validate(input);
      });
      input.addEventListener('input', function () {
        var field = input.closest('.field');
        if (field && field.classList.contains('has-error')) validate(input);
      });
    });

    function showFail(message) {
      if (!failBox) return;
      failBox.textContent = message || 'Не удалось отправить заявку. Попробуйте ещё раз.';
      failBox.hidden = false;
    }

    function done() {
      if (!submitBtn) return;
      submitBtn.disabled = false;
      submitBtn.textContent = submitLabel;
    }

    function showSuccess() {
      form.hidden = true;
      if (success) {
        success.hidden = false;
        success.focus();
      }
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var firstInvalid = null;
      fields.forEach(function (input) {
        if (!validate(input) && !firstInvalid) firstInvalid = input;
      });
      if (firstInvalid) {
        firstInvalid.focus();
        return;
      }

      if (failBox) failBox.hidden = true;
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Отправляем…';
      }

      if (DEMO_MODE) {
        window.setTimeout(function () { done(); showSuccess(); }, 500);
        return;
      }

      fetch(FORM_ENDPOINT, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) {
          return res.json().catch(function () { return { ok: res.ok }; });
        })
        .then(function (data) {
          done();
          if (data && data.ok) showSuccess();
          else showFail(data && data.message ? data.message + '.' : '');
        })
        .catch(function () {
          done();
          showFail('');
        });
    });

    if (resetBtn && success) {
      resetBtn.addEventListener('click', function () {
        form.reset();
        fields.forEach(clearError);
        if (failBox) failBox.hidden = true;
        success.hidden = true;
        form.hidden = false;
        if (fields[0]) fields[0].focus();
      });
    }
  }

  document.querySelectorAll('[data-lead-form]').forEach(initForm);

})();
