-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 001: начальная схема
-- ---------------------------------------------------------
-- Применяется со страницы /db/migrate.php
--
-- Правила, по которым написан этот файл:
--   * все таблицы InnoDB и utf8mb4 — иначе не будет внешних
--     ключей и сломаются буквы вне основной латиницы;
--   * CREATE TABLE IF NOT EXISTS везде: DDL в MySQL
--     не откатывается, и повторный запуск после сбоя
--     не должен спотыкаться на уже созданном;
--   * деньги — DECIMAL(10,2), никогда не FLOAT;
--   * имена таблиц и столбцов не задевают зарезервированные
--     слова MySQL: training_groups вместо groups, name вместо
--     key, before_json вместо before;
--   * внутри строковых значений нет точки с запятой —
--     migrate.php делит файл на команды по ней.
-- =========================================================


-- ---------------------------------------------------------
-- Настройки: числа, которые школа меняет без правки кода
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(64)      NOT NULL,
  value       VARCHAR(255)     NOT NULL,
  note        VARCHAR(255)     NULL,
  updated_by  INT UNSIGNED     NULL,
  updated_at  DATETIME         NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value, note) VALUES
  ('single_lesson_price',    '2500', 'Цена разового занятия, рубли. По ней считается перерасчёт по болезни и долг за месяц без абонемента'),
  ('illness_days_threshold', '14',   'С какого числа календарных дней болезни подряд возникает право на перерасчёт'),
  ('coach_edit_window_days', '3',    'Сколько суток тренер может править свои отметки посещений'),
  ('schedule_horizon_weeks', '8',    'На сколько недель вперёд генерируются тренировки из постоянного расписания'),
  ('max_discount_percent',   '50',   'Предел суммарной скидки на один абонемент, проценты. Защита от случайного наложения скидок')
ON DUPLICATE KEY UPDATE note = VALUES(note);


-- ---------------------------------------------------------
-- Доступ
-- ---------------------------------------------------------

-- Всё, у чего есть вход. Телефон, почта и Telegram — каждый
-- может быть логином, поэтому уникальны по отдельности.
-- NULL в MySQL не нарушает уникальность, и это здесь кстати:
-- у родителя может не быть почты, у тренера — Telegram.
CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  last_name         VARCHAR(80)      NOT NULL,
  first_name        VARCHAR(80)      NOT NULL,
  middle_name       VARCHAR(80)      NULL,
  phone             VARCHAR(20)      NULL,
  email             VARCHAR(190)     NULL,
  telegram_chat_id  BIGINT           NULL,
  password_hash     VARCHAR(255)     NULL,
  must_change_password TINYINT(1)    NOT NULL DEFAULT 0,
  status            ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at        DATETIME         NOT NULL,
  last_login_at     DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_telegram (telegram_chat_id),
  KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Роли отдельной таблицей, а не полем: тренер, чей ребёнок
-- занимается в школе, одновременно родитель.
CREATE TABLE IF NOT EXISTS user_roles (
  user_id  INT UNSIGNED NOT NULL,
  role     ENUM('admin','coach','parent','athlete') NOT NULL,
  PRIMARY KEY (user_id, role),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Одноразовые коды входа. Код хранится хешем: утечка таблицы
-- не должна давать возможность войти.
CREATE TABLE IF NOT EXISTS auth_codes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  channel     ENUM('sms','email','telegram') NOT NULL,
  code_hash   CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at     DATETIME     NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_auth_codes_user (user_id, expires_at),
  CONSTRAINT fk_auth_codes_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Токены доступа к API. В браузере тот же токен лежит
-- в cookie, недоступной скриптам.
CREATE TABLE IF NOT EXISTS access_tokens (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  user_agent    VARCHAR(255) NULL,
  ip            VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  last_used_at  DATETIME     NULL,
  expires_at    DATETIME     NOT NULL,
  revoked_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_tokens_hash (token_hash),
  KEY ix_access_tokens_user (user_id, expires_at),
  CONSTRAINT fk_access_tokens_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Люди
-- ---------------------------------------------------------

-- Спортсмен существует без учётной записи: у ребёнка пяти лет
-- входа нет, а профиль есть. Поэтому user_id пустой.
CREATE TABLE IF NOT EXISTS athletes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  last_name    VARCHAR(80)  NOT NULL,
  first_name   VARCHAR(80)  NOT NULL,
  middle_name  VARCHAR(80)  NULL,
  birth_date   DATE         NULL,
  gender       ENUM('f','m') NULL,
  user_id      INT UNSIGNED NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  note         TEXT         NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_athletes_user (user_id),
  KEY ix_athletes_name (last_name, first_name),
  KEY ix_athletes_status (status),
  CONSTRAINT fk_athletes_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Многие ко многим: у ребёнка бывает двое родителей,
-- и оба видят один профиль.
CREATE TABLE IF NOT EXISTS parent_athlete (
  parent_user_id  INT UNSIGNED NOT NULL,
  athlete_id      INT UNSIGNED NOT NULL,
  relation        ENUM('mother','father','guardian') NOT NULL,
  is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (parent_user_id, athlete_id),
  KEY ix_parent_athlete_athlete (athlete_id),
  CONSTRAINT fk_parent_athlete_parent FOREIGN KEY (parent_user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_parent_athlete_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Рабочая учётная запись тренера. Публичные карточки тренеров
-- на сайте живут отдельно, в data/team.json.
CREATE TABLE IF NOT EXISTS coaches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  last_name    VARCHAR(80)  NOT NULL,
  first_name   VARCHAR(80)  NOT NULL,
  middle_name  VARCHAR(80)  NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coaches_user (user_id),
  CONSTRAINT fk_coaches_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Группы и расписание
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS venues (
  id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name     VARCHAR(190) NOT NULL,
  address  VARCHAR(255) NULL,
  status   ENUM('active','archived') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO venues (id, name, address) VALUES
  (1, 'Ледовая арена «Гололёд»', 'Санкт-Петербург, Красное Село, ул. Свободы, 15')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Дополнительные занятия: зал, хореография и прочее, что идёт
-- тем же днём, что и лёд, и в абонементе отдельным занятием
-- не считается.
--
-- Время и количество таких занятий администратор правит через
-- расписание (schedule_rules), название и стоимость — здесь.
-- drop_in_price — фиксированная цена для спортсменов, у которых
-- это занятие в абонемент не входит, но прийти они хотят.
CREATE TABLE IF NOT EXISTS extra_classes (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name           VARCHAR(190)  NOT NULL,
  kind           ENUM('ice','gym','choreo') NOT NULL,
  drop_in_price  DECIMAL(10,2) NOT NULL,
  status         ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at     DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_extra_classes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тарифы. price и sessions_count — не цена абонемента,
-- а норматив, из которого выводится цена одного занятия.
-- Тариф, по которому уже выдан абонемент, не меняется:
-- изменение цены создаёт новую запись, старая уходит в архив.
--
-- days_per_week — вариант абонемента: сколько занятий в неделю
-- он покрывает. У СОГ-2 два варианта, 3 и 4 занятия в неделю,
-- то есть 12 и 16 в месяц. Если вариант меньше, чем занятий
-- в расписании группы, администратор выбирает спортсмену дни
-- (таблица subscription_slots). Пусто — все занятия группы.
CREATE TABLE IF NOT EXISTS tariffs (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name            VARCHAR(190)  NOT NULL,
  price           DECIMAL(10,2) NOT NULL,
  sessions_count  SMALLINT UNSIGNED NULL,
  days_per_week   SMALLINT UNSIGNED NULL,
  kinds           VARCHAR(190)  NULL,
  status          ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED  NULL,
  created_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tariffs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Какие дополнительные занятия входят в тариф. Их стоимость
-- уже заложена в цену абонемента, поэтому суммы здесь нет.
-- Чего в этой таблице нет — спортсмен может посетить
-- по фиксированной цене из extra_classes.drop_in_price.
CREATE TABLE IF NOT EXISTS tariff_extras (
  tariff_id       INT UNSIGNED NOT NULL,
  extra_class_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (tariff_id, extra_class_id),
  KEY ix_tariff_extras_class (extra_class_id),
  CONSTRAINT fk_tariff_extras_tariff FOREIGN KEY (tariff_id)
    REFERENCES tariffs (id) ON DELETE CASCADE,
  CONSTRAINT fk_tariff_extras_class FOREIGN KEY (extra_class_id)
    REFERENCES extra_classes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Скидки. Справочник: новая скидка — это строка, а не правка кода.
--
-- condition определяет, как скидка находится:
--   manual       — применяет администратор руками;
--   second_child — автоматически, второму и следующим детям
--                  одного родителя. Ставится на более дешёвые
--                  абонементы месяца: самый дорогой идёт
--                  по полной цене.
-- Новое автоматическое условие — это одно значение здесь
-- и одна функция в коде; всё остальное настраивается строкой.
CREATE TABLE IF NOT EXISTS discounts (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(190)  NOT NULL,
  kind        ENUM('percent','amount') NOT NULL,
  value       DECIMAL(10,2) NOT NULL,
  cond        ENUM('manual','second_child') NOT NULL DEFAULT 'manual',
  stackable   TINYINT(1)    NOT NULL DEFAULT 1,
  sort        SMALLINT      NOT NULL DEFAULT 0,
  valid_from  DATE          NULL,
  valid_to    DATE          NULL,
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  note        VARCHAR(255)  NULL,
  created_by  INT UNSIGNED  NULL,
  created_at  DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_discounts_status (status, cond)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO discounts (id, name, kind, value, cond, sort, note, created_at) VALUES
  (1, 'Скидка на второго ребёнка', 'percent', 30, 'second_child', 10,
   'Применяется ко всем абонементам семьи за месяц, кроме самого дорогого', NOW())
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- groups — зарезервированное слово в MySQL 8, отсюда training_groups
CREATE TABLE IF NOT EXISTS training_groups (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(32)  NOT NULL,
  name        VARCHAR(190) NOT NULL,
  age_from    TINYINT UNSIGNED NULL,
  age_to      TINYINT UNSIGNED NULL,
  tariff_id   INT UNSIGNED NULL,
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort        SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_training_groups_code (code),
  KEY ix_training_groups_status (status),
  CONSTRAINT fk_training_groups_tariff FOREIGN KEY (tariff_id)
    REFERENCES tariffs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Где действует скидка. Нет ни одной строки — действует везде.
-- Отдельной таблицей, а не парой полей в discounts: скидка
-- на три группы должна быть одной скидкой, иначе их правят
-- по отдельности и они расходятся.
CREATE TABLE IF NOT EXISTS discount_scopes (
  discount_id  INT UNSIGNED NOT NULL,
  group_id     INT UNSIGNED NULL,
  tariff_id    INT UNSIGNED NULL,
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id),
  KEY ix_discount_scopes_discount (discount_id),
  KEY ix_discount_scopes_group (group_id),
  KEY ix_discount_scopes_tariff (tariff_id),
  CONSTRAINT fk_discount_scopes_discount FOREIGN KEY (discount_id)
    REFERENCES discounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_discount_scopes_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_discount_scopes_tariff FOREIGN KEY (tariff_id)
    REFERENCES tariffs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Именно эта таблица определяет, что тренер считает «своим»
CREATE TABLE IF NOT EXISTS group_coaches (
  group_id  INT UNSIGNED NOT NULL,
  coach_id  INT UNSIGNED NOT NULL,
  role      ENUM('main','assistant') NOT NULL DEFAULT 'main',
  PRIMARY KEY (group_id, coach_id),
  KEY ix_group_coaches_coach (coach_id),
  CONSTRAINT fk_group_coaches_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_group_coaches_coach FOREIGN KEY (coach_id)
    REFERENCES coaches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Назначение в группу с историей. Перевод не затирает прошлое:
-- закрывается одна запись, открывается другая. Иначе осенний
-- отчёт покажет, что ребёнок всегда был в той группе,
-- куда его перевели в мае.
CREATE TABLE IF NOT EXISTS enrollments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  athlete_id  INT UNSIGNED NOT NULL,
  group_id    INT UNSIGNED NOT NULL,
  date_from   DATE         NOT NULL,
  date_to     DATE         NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_enrollments_athlete (athlete_id, date_from),
  KEY ix_enrollments_group (group_id, date_from),
  CONSTRAINT fk_enrollments_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_enrollments_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Постоянное расписание. Срок действия у правила нужен, чтобы
-- смена расписания в середине сезона не переписывала
-- прошедшие недели.
CREATE TABLE IF NOT EXISTS schedule_rules (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id      INT UNSIGNED NOT NULL,
  weekday       TINYINT UNSIGNED NOT NULL,
  time_start    TIME         NOT NULL,
  duration_min  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  kind          ENUM('ice','gym','choreo') NOT NULL,
  extra_class_id INT UNSIGNED NULL,
  venue_id      INT UNSIGNED NOT NULL,
  coach_id      INT UNSIGNED NULL,
  valid_from    DATE         NOT NULL,
  valid_to      DATE         NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_schedule_rules_group (group_id, weekday),
  KEY ix_schedule_rules_extra (extra_class_id),
  CONSTRAINT fk_schedule_rules_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_schedule_rules_venue FOREIGN KEY (venue_id)
    REFERENCES venues (id),
  CONSTRAINT fk_schedule_rules_coach FOREIGN KEY (coach_id)
    REFERENCES coaches (id) ON DELETE SET NULL,
  CONSTRAINT fk_schedule_rules_extra FOREIGN KEY (extra_class_id)
    REFERENCES extra_classes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Конкретные тренировки. Правило — шаблон, тренировка — факт:
-- её отменяют из-за заливки льда, переносят, ведёт подменяющий
-- тренер, и к ней привязаны отметки посещений.
-- Уникальность по группе, дате и времени — защита от повторного
-- запуска генерации расписания.
--
-- extra_class_id пусто — основное занятие, оно считается
-- в абонементе. Заполнено — дополнительное: зал, хореография.
CREATE TABLE IF NOT EXISTS trainings (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id             INT UNSIGNED NOT NULL,
  date                 DATE         NOT NULL,
  time_start           TIME         NOT NULL,
  duration_min         SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  kind                 ENUM('ice','gym','choreo') NOT NULL,
  extra_class_id       INT UNSIGNED NULL,
  venue_id             INT UNSIGNED NOT NULL,
  coach_id             INT UNSIGNED NULL,
  status               ENUM('planned','held','cancelled','moved') NOT NULL DEFAULT 'planned',
  rule_id              INT UNSIGNED NULL,
  moved_to_training_id INT UNSIGNED NULL,
  cancel_reason        VARCHAR(255) NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trainings_slot (group_id, date, time_start),
  KEY ix_trainings_date (date),
  KEY ix_trainings_coach (coach_id, date),
  KEY ix_trainings_moved (moved_to_training_id),
  KEY ix_trainings_extra (extra_class_id),
  CONSTRAINT fk_trainings_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_trainings_extra FOREIGN KEY (extra_class_id)
    REFERENCES extra_classes (id) ON DELETE SET NULL,
  CONSTRAINT fk_trainings_venue FOREIGN KEY (venue_id)
    REFERENCES venues (id),
  CONSTRAINT fk_trainings_coach FOREIGN KEY (coach_id)
    REFERENCES coaches (id) ON DELETE SET NULL,
  CONSTRAINT fk_trainings_rule FOREIGN KEY (rule_id)
    REFERENCES schedule_rules (id) ON DELETE SET NULL,
  CONSTRAINT fk_trainings_moved FOREIGN KEY (moved_to_training_id)
    REFERENCES trainings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Абонементы и деньги
-- ---------------------------------------------------------

-- Абонемент всегда календарный месяц, поэтому даты начала
-- и окончания не хранятся — они однозначно выводятся
-- из period вида 2026-11.
--
-- lesson_price, price_base и price фиксируются при выдаче:
-- изменение тарифа в декабре не должно менять сумму ноябрьского
-- абонемента.
--
-- price_base — до скидок, price — после. Обе суммы нужны: без
-- первой в кабинете не объяснить, откуда взялась вторая.
--
-- Зачтённую перерасчётом сумму здесь не храним: она выводится
-- из recalculations.to_subscription_id, и второй источник
-- той же цифры рано или поздно разойдётся с первым.
CREATE TABLE IF NOT EXISTS subscriptions (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  athlete_id      INT UNSIGNED  NOT NULL,
  group_id        INT UNSIGNED  NOT NULL,
  tariff_id       INT UNSIGNED  NOT NULL,
  period          CHAR(7)       NOT NULL,
  date_from       DATE          NULL,
  sessions_total  SMALLINT UNSIGNED NOT NULL,
  sessions_bonus  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  lesson_price    DECIMAL(10,2) NOT NULL,
  price_base      DECIMAL(10,2) NOT NULL,
  price           DECIMAL(10,2) NOT NULL,
  status          ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
  comment         VARCHAR(255)  NULL,
  created_by      INT UNSIGNED  NULL,
  created_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscriptions_month (athlete_id, group_id, period),
  KEY ix_subscriptions_period (period),
  KEY ix_subscriptions_status (status),
  CONSTRAINT fk_subscriptions_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_subscriptions_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id),
  CONSTRAINT fk_subscriptions_tariff FOREIGN KEY (tariff_id)
    REFERENCES tariffs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Выбранные дни посещения. Пусто — абонемент покрывает все
-- основные занятия группы.
--
-- Нужна там, где у группы вариантов несколько: у СОГ-2
-- четыре занятия в неделю по расписанию, и спортсмен
-- с вариантом на три выбирает, в какие дни ходит. Выбор делает
-- администратор, родителю он виден только как результат.
--
-- От этой таблицы зависит и список группы на тренировку: иначе
-- тренер увидит на занятии тех, кто в этот день не ходит.
CREATE TABLE IF NOT EXISTS subscription_slots (
  subscription_id  INT UNSIGNED NOT NULL,
  rule_id          INT UNSIGNED NOT NULL,
  PRIMARY KEY (subscription_id, rule_id),
  KEY ix_subscription_slots_rule (rule_id),
  CONSTRAINT fk_subscription_slots_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_slots_rule FOREIGN KEY (rule_id)
    REFERENCES schedule_rules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Применённые скидки. kind, value и amount копируются
-- из справочника на момент выдачи: правка скидки в справочнике
-- не должна менять суммы выданных абонементов.
CREATE TABLE IF NOT EXISTS subscription_discounts (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  subscription_id  INT UNSIGNED  NOT NULL,
  discount_id      INT UNSIGNED  NULL,
  name             VARCHAR(190)  NOT NULL,
  kind             ENUM('percent','amount') NOT NULL,
  value            DECIMAL(10,2) NOT NULL,
  amount           DECIMAL(10,2) NOT NULL,
  applied_auto     TINYINT(1)    NOT NULL DEFAULT 0,
  comment          VARCHAR(255)  NULL,
  created_by       INT UNSIGNED  NULL,
  created_at       DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_discounts (subscription_id, discount_id),
  KEY ix_subscription_discounts_discount (discount_id),
  CONSTRAINT fk_subscription_discounts_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_discounts_discount FOREIGN KEY (discount_id)
    REFERENCES discounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Перерасчёты. Заморозок в школе нет.
--
-- Два вида в одной таблице: они различаются только способом
-- подсчёта, а всё прочее у них общее. Болезнь договор велит
-- считать деньгами (amount) по ставке разового занятия (rate),
-- неперенесённую тренировку возвращаем занятием (sessions).
--
-- rate хранится в записи, а не берётся из настроек при показе:
-- если школа изменит цену занятия, прошлые перерасчёты обязаны
-- остаться такими, какими их подписали.
--
-- «Не более одного перерасчёта по болезни на абонемент»
-- проверяется в коде: условную уникальность MySQL не умеет.
CREATE TABLE IF NOT EXISTS recalculations (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  athlete_id            INT UNSIGNED  NOT NULL,
  from_subscription_id  INT UNSIGNED  NOT NULL,
  to_subscription_id    INT UNSIGNED  NULL,
  kind                  ENUM('illness','cancelled_training') NOT NULL,
  amount                DECIMAL(10,2) NULL,
  sessions              SMALLINT UNSIGNED NULL,
  attended_sessions     SMALLINT UNSIGNED NULL,
  rate                  DECIMAL(10,2) NULL,
  illness_from          DATE          NULL,
  illness_to            DATE          NULL,
  days                  SMALLINT UNSIGNED NULL,
  has_medical_document  TINYINT(1)    NOT NULL DEFAULT 0,
  has_application       TINYINT(1)    NOT NULL DEFAULT 0,
  comment               VARCHAR(255)  NULL,
  created_by            INT UNSIGNED  NULL,
  created_at            DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_recalculations_from (from_subscription_id, kind),
  KEY ix_recalculations_to (to_subscription_id),
  KEY ix_recalculations_athlete (athlete_id),
  CONSTRAINT fk_recalculations_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_recalculations_from FOREIGN KEY (from_subscription_id)
    REFERENCES subscriptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_recalculations_to FOREIGN KEY (to_subscription_id)
    REFERENCES subscriptions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Начисления: всё, что школа выставила спортсмену.
--
-- Источников денег стало три — абонементы, посещения без
-- абонемента и дополнительные занятия по разовой цене, — и ещё
-- перерасчёты, которые уходят в минус. Считать задолженность
-- отдельной формулой по каждому источнику значит четыре раза
-- повторить одну и ту же арифметику и однажды ошибиться в одной
-- из них. Поэтому один реестр: долг = начисления минус оплаты.
--
-- Суммы здесь зафиксированы на момент начисления. Цена разового
-- занятия изменится — выставленное раньше не пересчитается.
--
-- reversed_by: сторнирование. Ошибочное начисление не удаляется,
-- а закрывается обратной записью, и обе остаются в истории.
CREATE TABLE IF NOT EXISTS charges (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  athlete_id       INT UNSIGNED  NOT NULL,
  kind             ENUM('subscription','no_subscription','extra_drop_in','recalculation','correction') NOT NULL,
  amount           DECIMAL(10,2) NOT NULL,
  period           CHAR(7)       NOT NULL,
  subscription_id  INT UNSIGNED  NULL,
  training_id      INT UNSIGNED  NULL,
  reversed_by      INT UNSIGNED  NULL,
  comment          VARCHAR(255)  NULL,
  created_by       INT UNSIGNED  NULL,
  created_at       DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_charges_athlete (athlete_id, period),
  KEY ix_charges_subscription (subscription_id),
  KEY ix_charges_training (training_id),
  KEY ix_charges_reversed (reversed_by),
  CONSTRAINT fk_charges_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_charges_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE SET NULL,
  CONSTRAINT fk_charges_training FOREIGN KEY (training_id)
    REFERENCES trainings (id) ON DELETE SET NULL,
  CONSTRAINT fk_charges_reversed FOREIGN KEY (reversed_by)
    REFERENCES charges (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Оплаты. Платёжного шлюза пока нет: администратор отмечает,
-- что деньги получены. Задолженность не хранится, а считается
-- как начисления минус оплаты.
--
-- charge_id пусто — оплата «в счёт будущего», аванс: деньги
-- получены раньше, чем выставлен абонемент. Так бывает, и терять
-- такую оплату нельзя.
CREATE TABLE IF NOT EXISTS payments (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  athlete_id  INT UNSIGNED  NOT NULL,
  charge_id   INT UNSIGNED  NULL,
  amount      DECIMAL(10,2) NOT NULL,
  method      ENUM('cash','transfer') NOT NULL,
  paid_at     DATE          NOT NULL,
  comment     VARCHAR(255)  NULL,
  created_by  INT UNSIGNED  NULL,
  created_at  DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_payments_athlete (athlete_id, paid_at),
  KEY ix_payments_charge (charge_id),
  CONSTRAINT fk_payments_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_charge FOREIGN KEY (charge_id)
    REFERENCES charges (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Посещения
-- ---------------------------------------------------------

-- Разделение status и charged — ключевое. Тренер отвечает
-- за факт: был ребёнок на льду или нет. Списывать ли за это
-- занятие — отдельное решение, которое админ может изменить,
-- не переписывая то, что видел тренер.
--
-- makeup_for_training_id: отработка, согласованная тренером.
-- Она не списывается, а закрывает уже списанный пропуск.
CREATE TABLE IF NOT EXISTS attendance (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  training_id             INT UNSIGNED NOT NULL,
  athlete_id              INT UNSIGNED NOT NULL,
  status                  ENUM('present','absent','absent_sick','cancelled') NOT NULL,
  charged                 TINYINT(1)   NOT NULL DEFAULT 0,
  subscription_id         INT UNSIGNED NULL,
  makeup_for_training_id  INT UNSIGNED NULL,
  comment                 VARCHAR(255) NULL,
  marked_by               INT UNSIGNED NULL,
  marked_at               DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_pair (training_id, athlete_id),
  KEY ix_attendance_athlete (athlete_id),
  KEY ix_attendance_subscription (subscription_id),
  KEY ix_attendance_makeup (makeup_for_training_id),
  CONSTRAINT fk_attendance_training FOREIGN KEY (training_id)
    REFERENCES trainings (id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_athlete FOREIGN KEY (athlete_id)
    REFERENCES athletes (id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE SET NULL,
  CONSTRAINT fk_attendance_makeup FOREIGN KEY (makeup_for_training_id)
    REFERENCES trainings (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Служебное
-- ---------------------------------------------------------

-- before и after — зарезервированные слова, отсюда _json.
-- Содержимое — JSON, но тип LONGTEXT: в MariaDB JSON всё равно
-- псевдоним LONGTEXT, а на старых MySQL типа JSON просто нет.
CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(64)  NOT NULL,
  entity       VARCHAR(64)  NOT NULL,
  entity_id    INT UNSIGNED NULL,
  before_json  LONGTEXT     NULL,
  after_json   LONGTEXT     NULL,
  reason       VARCHAR(255) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity, entity_id),
  KEY ix_audit_created (created_at),
  KEY ix_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Очередь, а не прямая отправка: падение почтового сервера
-- не должно уронить операцию, которая его вызвала.
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(64)  NOT NULL,
  payload     LONGTEXT     NULL,
  channel     ENUM('email','telegram','sms','cabinet') NOT NULL,
  status      ENUM('queued','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  send_after  DATETIME     NOT NULL,
  sent_at     DATETIME     NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error       VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_notifications_queue (status, send_after),
  KEY ix_notifications_user (user_id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
