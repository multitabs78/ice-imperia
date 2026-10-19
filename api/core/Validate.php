<?php
/* =========================================================
   Проверка входящих данных
   ---------------------------------------------------------
   Собирает все ошибки сразу, а не падает на первой: чинить
   форму по одному полю за раз — мучение.
   ========================================================= */

class Validate
{
    private $data;
    private $errors = array();
    private $clean  = array();

    public function __construct($data)
    {
        $this->data = is_array($data) ? $data : array();
    }

    private function raw($field)
    {
        return array_key_exists($field, $this->data) ? $this->data[$field] : null;
    }

    /**
     * Обязательно ли поле.
     *
     * Кроме true и false есть третий случай — 'present': поле
     * можно не присылать, но если прислали, пустым оно быть
     * не может. Это про правку: отсутствие поля значит
     * «не меняем», а пустая строка значит «сотри», и стирать
     * фамилию нельзя. Без этого различия форма правки молча
     * не делает ничего, и человек думает, что сохранил.
     */
    private function needed($field, $required)
    {
        if ($required === 'present') {
            return array_key_exists($field, $this->data);
        }
        return (bool) $required;
    }

    private function fail($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function text($field, $label, $required = true, $max = 190, $min = 1)
    {
        $value = $this->raw($field);
        $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value)) : '';

        if ($value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (mb_strlen($value) < $min) {
            return $this->fail($field, $label . ': слишком коротко');
        }
        if (mb_strlen($value) > $max) {
            return $this->fail($field, $label . ': не длиннее ' . $max . ' символов');
        }

        $this->clean[$field] = $value;
        return $this;
    }

    public function int($field, $label, $required = true, $min = null, $max = null)
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!is_numeric($value) || (string) (int) $value !== (string) $value && !is_int($value)) {
            if (!preg_match('/^-?\d+$/', (string) $value)) {
                return $this->fail($field, $label . ': нужно целое число');
            }
        }

        $n = (int) $value;
        if ($min !== null && $n < $min) {
            return $this->fail($field, $label . ': не меньше ' . $min);
        }
        if ($max !== null && $n > $max) {
            return $this->fail($field, $label . ': не больше ' . $max);
        }

        $this->clean[$field] = $n;
        return $this;
    }

    /** Телефон: приводим к 11 цифрам с ведущей семёркой. */
    public function phone($field, $label, $required = true)
    {
        $value = (string) $this->raw($field);
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) !== 11 || $digits[0] !== '7') {
            return $this->fail($field, $label . ': нужен номер из 11 цифр, начиная с 7');
        }

        $this->clean[$field] = '+' . $digits;
        return $this;
    }

    public function email($field, $label, $required = true)
    {
        $value = trim((string) $this->raw($field));

        if ($value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190) {
            return $this->fail($field, $label . ': проверьте адрес почты');
        }

        $this->clean[$field] = mb_strtolower($value);
        return $this;
    }

    public function date($field, $label, $required = true, $notFuture = false)
    {
        $value = trim((string) $this->raw($field));

        if ($value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            return $this->fail($field, $label . ': дата в виде 2026-11-09');
        }
        if ($notFuture && $value > date('Y-m-d')) {
            return $this->fail($field, $label . ': дата в будущем');
        }

        $this->clean[$field] = $value;
        return $this;
    }

    /**
     * Время начала занятия: 09:30 или 09:30:00.
     * Приводим к виду с секундами — столбец в базе TIME,
     * и без этого сравнение «то же время» ломается
     * на ровном месте.
     */
    public function time($field, $label, $required = true)
    {
        $value = trim((string) $this->raw($field));

        if ($value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m)) {
            return $this->fail($field, $label . ': время в виде 09:30');
        }

        $this->clean[$field] = $m[1] . ':' . $m[2] . ':00';
        return $this;
    }

    /** Месяц абонемента: 2026-11. */    public function period($field, $label, $required = true)
    {
        $value = trim((string) $this->raw($field));

        if ($value === '') {
            if ($required) {
                $this->fail($field, $label . ': заполните поле');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return $this->fail($field, $label . ': месяц в виде 2026-11');
        }

        $this->clean[$field] = $value;
        return $this;
    }

    public function one_of($field, $label, $allowed, $required = true)
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            if ($this->needed($field, $required)) {
                $this->fail($field, $label . ': выберите значение');
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!in_array($value, $allowed, true)) {
            return $this->fail($field, $label . ': допустимо только ' . implode(', ', $allowed));
        }

        $this->clean[$field] = $value;
        return $this;
    }

    public function bool($field, $default = false)
    {
        $value = $this->raw($field);
        if ($value === null) {
            $this->clean[$field] = $default;
        } else {
            $this->clean[$field] = in_array($value, array(true, 1, '1', 'true', 'yes'), true);
        }
        return $this;
    }

    /** Бросает 422 со всеми ошибками сразу, иначе отдаёт чистые данные. */
    public function done()
    {
        if ($this->errors) {
            invalid($this->errors);
        }
        return $this->clean;
    }
}
