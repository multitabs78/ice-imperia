-- =========================================================
-- Империя льда — проверка боевой базы
-- ---------------------------------------------------------
-- Запросы только читают. Выполнять на копии действующей базы
-- ДО применения миграций 006–008: они показывают, что именно
-- те миграции будут чинить, и сколько записей это затронет.
--
-- Пустой ответ — хорошо: такой беды нет.
-- =========================================================

-- 1. Цена разового занятия. Миграция 002 удаляла настройку,
--    не перенеся значение. Если школа её меняла, в журнале
--    осталась запись: сравните с тем, что сейчас в услугах.
SELECT 'Цена разового: что в услугах' AS что,
       price AS значение, NULL AS когда
  FROM services WHERE code = 'single_lesson'
UNION ALL
SELECT 'Цена разового: правки в журнале',
       after_json, created_at
  FROM audit_log
 WHERE entity = 'setting' AND after_json LIKE '%single_lesson_price%'
 ORDER BY 3;

-- 2. Два занятия у одного спортсмена в одно время.
--    Миграция 008 оставит первое, если на втором нет отметок.
SELECT t.athlete_id, t.date, t.time_start, COUNT(*) AS занятий
  FROM trainings t
 WHERE t.athlete_id IS NOT NULL
 GROUP BY t.athlete_id, t.date, t.time_start
HAVING COUNT(*) > 1;

-- 3. Один пропуск отработан дважды.
SELECT athlete_id, makeup_for_training_id, COUNT(*) AS отработок
  FROM attendance
 WHERE makeup_for_training_id IS NOT NULL
 GROUP BY athlete_id, makeup_for_training_id
HAVING COUNT(*) > 1;

-- 4. Площадка и комплекс разошлись.
SELECT 'trainings' AS таблица, t.id, t.venue_id, s.venue_id AS по_площадке
  FROM trainings t JOIN spots s ON s.id = t.spot_id
 WHERE t.venue_id <> s.venue_id
UNION ALL
SELECT 'schedule_rules', r.id, r.venue_id, s.venue_id
  FROM schedule_rules r JOIN spots s ON s.id = r.spot_id
 WHERE r.venue_id <> s.venue_id;

-- 5. Отметка на чужом индивидуальном занятии.
SELECT a.id, a.athlete_id, t.athlete_id AS чьё_занятие, t.date
  FROM attendance a JOIN trainings t ON t.id = a.training_id
 WHERE t.athlete_id IS NOT NULL AND t.athlete_id <> a.athlete_id;

-- 6. Занятия не на льду, стоящие на ледовой площадке.
--    Это чинит миграция 007.
SELECT lk.name AS вид, s.name AS площадка, COUNT(*) AS занятий
  FROM trainings t
  JOIN lesson_kinds lk ON lk.id = t.kind_id
  JOIN spots s         ON s.id = t.spot_id
 WHERE lk.on_ice = 0 AND s.kind = 'ice'
 GROUP BY lk.name, s.name;

-- 7. Назначения: окончание раньше начала, пересечения,
--    открытые у отчисленных.
SELECT 'окончание раньше начала' AS беда, COUNT(*) AS записей
  FROM enrollments WHERE date_to IS NOT NULL AND date_to < date_from
UNION ALL
SELECT 'два открытых назначения', COUNT(*) FROM (
    SELECT athlete_id FROM enrollments WHERE date_to IS NULL
     GROUP BY athlete_id HAVING COUNT(*) > 1) x
UNION ALL
SELECT 'открытое у отчисленного', COUNT(*)
  FROM enrollments e JOIN athletes a ON a.id = e.athlete_id
 WHERE e.date_to IS NULL AND a.status = 'archived';

-- 8. Сроки действия правил расписания.
SELECT id, group_id, athlete_id, valid_from, valid_to
  FROM schedule_rules
 WHERE valid_to IS NOT NULL AND valid_to < valid_from;

-- 9. Занятие без группы и без спортсмена — ничьё.
SELECT id, date, time_start FROM trainings
 WHERE group_id IS NULL AND athlete_id IS NULL;

-- 10. Персональные данные в журнале. Это чистит миграция 006.
SELECT entity, COUNT(*) AS записей
  FROM audit_log
 WHERE entity IN ('athlete', 'parent', 'coach', 'user', 'admin')
   AND (before_json IS NOT NULL OR after_json IS NOT NULL)
 GROUP BY entity;

-- 11. Даты зачисления, выведенные из даты создания карточки.
--     Миграция 004 проставила joined_at = дата появления
--     записи в системе. Это не подтверждённая дата поступления
--     в школу: строки ниже стоит сверить с документами.
SELECT id, last_name, first_name, joined_at, DATE(created_at) AS заведён
  FROM athletes
 WHERE joined_at = DATE(created_at)
 ORDER BY joined_at;
