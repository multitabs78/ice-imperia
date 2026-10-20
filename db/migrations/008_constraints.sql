-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 008: ограничения, которых не хватало
-- ---------------------------------------------------------
-- Проверки в коде спасают от ошибок в обработчиках, но
-- не от двух одновременных запросов: между SELECT и INSERT
-- успевает пройти чужая запись. Второй уровень защиты —
-- в самой базе.
--
-- Перед наложением ключей чистим то, что могло появиться
-- раньше: иначе ALTER просто не пройдёт.
-- =========================================================

-- --- Одно занятие на спортсмена в одно время -------------
-- Уникальный ключ uq_trainings_slot начинается с group_id,
-- а он у личных занятий пустой — база считает такие строки
-- разными, и дубль проходил.

DELETE FROM trainings
 WHERE athlete_id IS NOT NULL
   AND id > (SELECT MIN(k.id) FROM (SELECT * FROM trainings) k
              WHERE k.athlete_id = trainings.athlete_id
                AND k.date = trainings.date
                AND k.time_start = trainings.time_start)
   AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.training_id = trainings.id);

ALTER TABLE trainings
  ADD UNIQUE KEY uq_trainings_personal (athlete_id, date, time_start);

-- --- Один пропуск отрабатывается один раз ----------------

DELETE FROM attendance
 WHERE makeup_for_training_id IS NOT NULL
   AND id > (SELECT MIN(k.id) FROM (SELECT * FROM attendance) k
              WHERE k.athlete_id = attendance.athlete_id
                AND k.makeup_for_training_id = attendance.makeup_for_training_id);

ALTER TABLE attendance
  ADD UNIQUE KEY uq_attendance_makeup (athlete_id, makeup_for_training_id);

-- --- Площадка принадлежит своему комплексу ---------------
-- venue_id и spot_id по отдельности могут быть верными,
-- но указывать на разные здания. Приводим к согласию
-- по площадке: она главнее, комплекс следует за ней.

UPDATE trainings
   SET venue_id = (SELECT s.venue_id FROM spots s WHERE s.id = trainings.spot_id)
 WHERE spot_id IS NOT NULL
   AND venue_id <> (SELECT s.venue_id FROM spots s WHERE s.id = trainings.spot_id);

UPDATE schedule_rules
   SET venue_id = (SELECT s.venue_id FROM spots s WHERE s.id = schedule_rules.spot_id)
 WHERE spot_id IS NOT NULL
   AND venue_id <> (SELECT s.venue_id FROM spots s WHERE s.id = schedule_rules.spot_id);

-- --- Отметка относится к своему спортсмену ---------------
-- На личном занятии отметка может стоять только на том,
-- ради кого оно назначено.

DELETE FROM attendance
 WHERE EXISTS (SELECT 1 FROM trainings t
                WHERE t.id = attendance.training_id
                  AND t.athlete_id IS NOT NULL
                  AND t.athlete_id <> attendance.athlete_id);
