-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 004: карточка спортсмена, виды занятий,
--               индивидуальные занятия
-- =========================================================

-- --- Разряды ---------------------------------------------
-- Список задан Единой всероссийской спортивной
-- классификацией, но отдельной таблицей: школа добавит
-- МСМК или уберёт лишнее сама, без правки кода.

CREATE TABLE IF NOT EXISTS ranks (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name   VARCHAR(80)  NOT NULL,
  sort   SMALLINT     NOT NULL DEFAULT 0,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_ranks_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ranks (name, sort) VALUES
  ('Юный фигурист',  10),
  ('3 юношеский',    20),
  ('2 юношеский',    30),
  ('1 юношеский',    40),
  ('III спортивный', 50),
  ('II спортивный',  60),
  ('I спортивный',   70),
  ('КМС',            80),
  ('МС',             90)
ON DUPLICATE KEY UPDATE sort = VALUES(sort);

-- --- Карточка спортсмена ---------------------------------
-- Даты зачисления и отчисления нужны отдельно от назначений
-- в группы: ребёнок может за год сменить три группы, а в школу
-- зачислен один раз.

ALTER TABLE athletes ADD COLUMN joined_at      DATE         NULL AFTER status;
ALTER TABLE athletes ADD COLUMN left_at        DATE         NULL AFTER joined_at;
ALTER TABLE athletes ADD COLUMN rank_id        INT UNSIGNED NULL AFTER left_at;
ALTER TABLE athletes ADD COLUMN rank_date      DATE         NULL AFTER rank_id;
ALTER TABLE athletes ADD COLUMN rank_doc       VARCHAR(120) NULL AFTER rank_date;
ALTER TABLE athletes ADD COLUMN medical_until  DATE         NULL AFTER rank_doc;
ALTER TABLE athletes ADD COLUMN insurance_until DATE        NULL AFTER medical_until;

ALTER TABLE athletes ADD CONSTRAINT fk_athletes_rank
  FOREIGN KEY (rank_id) REFERENCES ranks (id) ON DELETE SET NULL;

ALTER TABLE athletes ADD KEY ix_athletes_medical (medical_until);

-- Кто уже заведён, тот зачислен в день появления в системе:
-- другой даты у нас нет, а пустая графа хуже приблизительной
UPDATE athletes SET joined_at = DATE(created_at) WHERE joined_at IS NULL;

-- --- Виды занятий ----------------------------------------
-- Был жёсткий перечень из трёх значений. Школа добавляет
-- ОФП, растяжку, прыжковую — и каждый раз это была бы правка
-- кода. Становится обычным справочником.

CREATE TABLE IF NOT EXISTS lesson_kinds (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name    VARCHAR(80)  NOT NULL,
  on_ice  TINYINT(1)   NOT NULL DEFAULT 0,
  sort    SMALLINT     NOT NULL DEFAULT 0,
  status  ENUM('active','archived') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_lesson_kinds_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lesson_kinds (name, on_ice, sort) VALUES
  ('Лёд',          1, 10),
  ('Зал',          0, 20),
  ('Хореография',  0, 30)
ON DUPLICATE KEY UPDATE sort = VALUES(sort);

ALTER TABLE schedule_rules ADD COLUMN kind_id INT UNSIGNED NULL AFTER kind;
ALTER TABLE trainings      ADD COLUMN kind_id INT UNSIGNED NULL AFTER kind;

ALTER TABLE schedule_rules ADD CONSTRAINT fk_schedule_rules_kind
  FOREIGN KEY (kind_id) REFERENCES lesson_kinds (id);
ALTER TABLE trainings ADD CONSTRAINT fk_trainings_kind
  FOREIGN KEY (kind_id) REFERENCES lesson_kinds (id);

UPDATE schedule_rules SET kind_id =
  (SELECT id FROM lesson_kinds WHERE name =
     CASE schedule_rules.kind WHEN 'ice' THEN 'Лёд'
                              WHEN 'gym' THEN 'Зал'
                              ELSE 'Хореография' END)
 WHERE kind_id IS NULL;

UPDATE trainings SET kind_id =
  (SELECT id FROM lesson_kinds WHERE name =
     CASE trainings.kind WHEN 'ice' THEN 'Лёд'
                         WHEN 'gym' THEN 'Зал'
                         ELSE 'Хореография' END)
 WHERE kind_id IS NULL;

-- Старый столбец убираем совсем: два источника одной и той же
-- истины однажды разъедутся, и разбираться будет поздно
ALTER TABLE schedule_rules DROP COLUMN kind;
ALTER TABLE trainings      DROP COLUMN kind;

-- --- Индивидуальные занятия ------------------------------
-- Занятие с одним спортсменом вместо группы. Заводить ради
-- этого «группу из одного человека» значило бы засорить
-- справочник групп и сломать отчёты по ним.

ALTER TABLE trainings      MODIFY COLUMN group_id INT UNSIGNED NULL;
ALTER TABLE schedule_rules MODIFY COLUMN group_id INT UNSIGNED NULL;

ALTER TABLE trainings      ADD COLUMN athlete_id INT UNSIGNED NULL AFTER group_id;
ALTER TABLE schedule_rules ADD COLUMN athlete_id INT UNSIGNED NULL AFTER group_id;

ALTER TABLE trainings ADD CONSTRAINT fk_trainings_athlete
  FOREIGN KEY (athlete_id) REFERENCES athletes (id) ON DELETE CASCADE;
ALTER TABLE schedule_rules ADD CONSTRAINT fk_schedule_rules_athlete
  FOREIGN KEY (athlete_id) REFERENCES athletes (id) ON DELETE CASCADE;

ALTER TABLE trainings      ADD KEY ix_trainings_athlete (athlete_id, date);
ALTER TABLE schedule_rules ADD KEY ix_schedule_rules_athlete (athlete_id);
