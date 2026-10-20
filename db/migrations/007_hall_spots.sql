-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 007: вернуть занятия зала на свои места
-- ---------------------------------------------------------
-- Миграция 003 завела каждому комплексу одну площадку «Лёд»
-- и перевела на неё всё расписание разом. Зал и хореография
-- при этом оказались на льду: вида занятия она не смотрела.
--
-- Заводим в каждом комплексе площадку «Зал» и переносим
-- туда то, что льдом не является. Если школа уже развела
-- занятия по площадкам руками, ничего не произойдёт.
--
-- Запросы написаны подзапросами, а не через UPDATE ... JOIN:
-- так их понимает и MySQL, и SQLite, на котором гоняются
-- проверки.
-- =========================================================

INSERT INTO spots (venue_id, name, kind, note, sort, created_at)
  SELECT v.id, 'Зал', 'hall', 'Заведён при разделении площадок', 20, NOW()
    FROM venues v
   WHERE NOT EXISTS (SELECT 1 FROM spots s WHERE s.venue_id = v.id AND s.kind = 'hall');

UPDATE trainings
   SET spot_id = (SELECT s.id FROM spots s
                   WHERE s.venue_id = trainings.venue_id AND s.kind = 'hall'
                   ORDER BY s.id LIMIT 1)
 WHERE (SELECT lk.on_ice FROM lesson_kinds lk WHERE lk.id = trainings.kind_id) = 0
   AND (SELECT sp.kind FROM spots sp WHERE sp.id = trainings.spot_id) = 'ice'
   AND EXISTS (SELECT 1 FROM spots s
                WHERE s.venue_id = trainings.venue_id AND s.kind = 'hall');

UPDATE schedule_rules
   SET spot_id = (SELECT s.id FROM spots s
                   WHERE s.venue_id = schedule_rules.venue_id AND s.kind = 'hall'
                   ORDER BY s.id LIMIT 1)
 WHERE (SELECT lk.on_ice FROM lesson_kinds lk WHERE lk.id = schedule_rules.kind_id) = 0
   AND (SELECT sp.kind FROM spots sp WHERE sp.id = schedule_rules.spot_id) = 'ice'
   AND EXISTS (SELECT 1 FROM spots s
                WHERE s.venue_id = schedule_rules.venue_id AND s.kind = 'hall');
