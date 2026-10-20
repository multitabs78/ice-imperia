-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 005: починка назначений отчисленных спортсменов
-- ---------------------------------------------------------
-- Отчисление не закрывало назначение в группу, поэтому
-- ушедший продолжал числиться в составе: в карточке группы
-- значился один занимающийся при пустом списке.
--
-- Код это больше не допускает, но записи, сделанные раньше,
-- надо поправить здесь: сами они не исправятся.
-- =========================================================

UPDATE enrollments
   SET date_to = (
        SELECT CASE
                 WHEN a.left_at IS NULL THEN CURDATE()
                 WHEN a.left_at < enrollments.date_from THEN enrollments.date_from
                 ELSE a.left_at
               END
          FROM athletes a WHERE a.id = enrollments.athlete_id)
 WHERE date_to IS NULL
   AND athlete_id IN (SELECT id FROM athletes WHERE status = 'archived');
