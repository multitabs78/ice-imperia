-- =========================================================
-- Империя льда — личный кабинет
-- Миграция 002: справочник услуг и цен
-- ---------------------------------------------------------
-- Цены расползлись по трём местам: разовое занятие лежало
-- в настройках, цена дополнительного занятия — в самой
-- таблице дополнительных занятий, а цен нужно больше.
-- Собираем всё в один справочник, который администратор
-- правит из кабинета.
--
-- Системные услуги отличаются непустым полем code: на него
-- ссылается код, и удалить такую услугу нельзя. Остальные
-- заводит школа — прокат, заточка, индивидуальное занятие.
-- =========================================================

CREATE TABLE IF NOT EXISTS services (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code        VARCHAR(64)   NULL,
  name        VARCHAR(190)  NOT NULL,
  price       DECIMAL(10,2) NULL,
  note        VARCHAR(255)  NULL,
  status      ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort        SMALLINT      NOT NULL DEFAULT 0,
  updated_by  INT UNSIGNED  NULL,
  created_at  DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_code (code),
  KEY ix_services_status (status, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- price пустой означает «цена не задана»: начисление по такой
-- услуге не пройдёт и прямо об этом скажет. Это лучше, чем ноль,
-- который молча спишет ничего.
INSERT INTO services (code, name, price, note, sort, created_at) VALUES
  ('single_lesson', 'Разовое занятие без абонемента', 2500,
   'По этой цене считается перерасчёт по болезни и долг за месяц, на который абонемент не выдан', 10, NOW()),
  ('extra_lesson', 'Разовое занятие при действующем абонементе', NULL,
   'Когда спортсмен приходит сверх своего варианта абонемента — например, в день, которого в его расписании нет', 20, NOW())
ON DUPLICATE KEY UPDATE note = VALUES(note);

-- Цена дополнительного занятия переезжает в справочник услуг
ALTER TABLE extra_classes ADD COLUMN service_id INT UNSIGNED NULL AFTER kind;

ALTER TABLE extra_classes ADD CONSTRAINT fk_extra_classes_service
  FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE SET NULL;

ALTER TABLE extra_classes DROP COLUMN drop_in_price;

-- Цена разового занятия больше не настройка, а услуга.
-- Сначала переносим значение: школа могла поменять его
-- в настройках, и подставлять вместо него 2500 из текста
-- миграции значило бы молча вернуть старую цену
UPDATE services
   SET price = (SELECT CAST(value AS DECIMAL(10,2)) FROM settings
                 WHERE name = 'single_lesson_price')
 WHERE code = 'single_lesson'
   AND EXISTS (SELECT 1 FROM settings WHERE name = 'single_lesson_price');

DELETE FROM settings WHERE name = 'single_lesson_price';
