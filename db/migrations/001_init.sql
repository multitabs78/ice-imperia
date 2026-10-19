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
  ('schedule_horizon_weeks', '8',    'На сколько недель вперёд генерируются тренировки из постоянного расписания')
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

-- Тарифы. price и sessions_count — не цена абонемента,
-- а норматив, из которого выводится цена одного занятия.
-- Тариф, по которому уже выдан абонемент, не меняется:
-- изменение цены создаёт новую запись, старая уходит в архив.
CREATE TABLE IF NOT EXISTS tariffs (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name            VARCHAR(190)  NOT NULL,
  price           DECIMAL(10,2) NOT NULL,
  sessions_count  SMALLINT UNSIGNED NULL,
  kinds           VARCHAR(190)  NULL,
  status          ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by      INT UNSIGNED  NULL,
  created_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_tariffs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  venue_id      INT UNSIGNED NOT NULL,
  coach_id      INT UNSIGNED NULL,
  valid_from    DATE         NOT NULL,
  valid_to      DATE         NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_schedule_rules_group (group_id, weekday),
  CONSTRAINT fk_schedule_rules_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
  CONSTRAINT fk_schedule_rules_venue FOREIGN KEY (venue_id)
    REFERENCES venues (id),
  CONSTRAINT fk_schedule_rules_coach FOREIGN KEY (coach_id)
    REFERENCES coaches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Конкретные тренировки. Правило — шаблон, тренировка — факт:
-- её отменяют из-за заливки льда, переносят, ведёт подменяющий
-- тренер, и к ней привязаны отметки посещений.
-- Уникальность по группе, дате и времени — защита от повторного
-- запуска генерации расписания.
CREATE TABLE IF NOT EXISTS trainings (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id             INT UNSIGNED NOT NULL,
  date                 DATE         NOT NULL,
  time_start           TIME         NOT NULL,
  duration_min         SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  kind                 ENUM('ice','gym','choreo') NOT NULL,
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
  CONSTRAINT fk_trainings_group FOREIGN KEY (group_id)
    REFERENCES training_groups (id) ON DELETE CASCADE,
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
-- lesson_price и price фиксируются при выдаче: изменение
-- тарифа в декабре не должно менять сумму ноябрьского
-- абонемента.
CREATE TABLE IF NOT EXISTS subscriptions (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  athlete_id      INT UNSIGNED  NOT NULL,
  group_id        INT UNSIGNED  NOT NULL,
  tariff_id       INT UNSIGNED  NOT NULL,
  period          CHAR(7)       NOT NULL,
  sessions_total  SMALLINT UNSIGNED NOT NULL,
  sessions_bonus  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  lesson_price    DECIMAL(10,2) NOT NULL,
  price           DECIMAL(10,2) NOT NULL,
  credit_in       DECIMAL(10,2) NOT NULL DEFAULT 0,
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

-- Оплаты. Платёжного шлюза пока нет: администратор отмечает,
-- что деньги получены. Задолженность не хранится, а считается.
CREATE TABLE IF NOT EXISTS payments (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  subscription_id  INT UNSIGNED  NOT NULL,
  amount           DECIMAL(10,2) NOT NULL,
  method           ENUM('cash','transfer') NOT NULL,
  paid_at          DATE          NOT NULL,
  comment          VARCHAR(255)  NULL,
  created_by       INT UNSIGNED  NULL,
  created_at       DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY ix_payments_subscription (subscription_id),
  KEY ix_payments_date (paid_at),
  CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE CASCADE
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
