-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 003: спорткомплексы и площадки внутри них
-- ---------------------------------------------------------
-- Было одно плоское понятие «площадка», и его не хватало:
-- в одном комплексе бывает несколько льдов и несколько
-- залов с номерами, а занятие проходит на конкретном,
-- а не «в здании».
--
-- Стало два уровня. venues — спорткомплекс целиком, с одним
-- адресом. spots — то, где реально стоят люди: «Лёд»,
-- «Зал 2», «Хореография». Расписание ссылается на spot.
-- =========================================================

CREATE TABLE IF NOT EXISTS spots (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  venue_id    INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  kind        ENUM('ice','hall') NOT NULL DEFAULT 'ice',
  note        VARCHAR(255) NULL,
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort        SMALLINT     NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_spots_venue (venue_id, status, sort),
  CONSTRAINT fk_spots_venue FOREIGN KEY (venue_id)
    REFERENCES venues (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE schedule_rules ADD COLUMN spot_id INT UNSIGNED NULL AFTER venue_id;
ALTER TABLE trainings      ADD COLUMN spot_id INT UNSIGNED NULL AFTER venue_id;

ALTER TABLE schedule_rules ADD CONSTRAINT fk_schedule_rules_spot
  FOREIGN KEY (spot_id) REFERENCES spots (id) ON DELETE SET NULL;
ALTER TABLE trainings ADD CONSTRAINT fk_trainings_spot
  FOREIGN KEY (spot_id) REFERENCES spots (id) ON DELETE SET NULL;

-- У каждого уже заведённого комплекса появляется лёд: всё,
-- что внесено до этой миграции, проходило на льду
INSERT INTO spots (venue_id, name, kind, sort, created_at)
  SELECT id, 'Лёд', 'ice', 10, NOW() FROM venues;

-- И занятия переезжают на него, чтобы расписание не осиротело
UPDATE schedule_rules
   SET spot_id = (SELECT s.id FROM spots s WHERE s.venue_id = schedule_rules.venue_id
                   ORDER BY s.id LIMIT 1)
 WHERE spot_id IS NULL;

UPDATE trainings
   SET spot_id = (SELECT s.id FROM spots s WHERE s.venue_id = trainings.venue_id
                   ORDER BY s.id LIMIT 1)
 WHERE spot_id IS NULL;
