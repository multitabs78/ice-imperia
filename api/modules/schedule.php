<?php
/* =========================================================
   Расписание
   ---------------------------------------------------------
   Две разные вещи, и это главное в модуле.

   Правило — шаблон: «СОГ-1, по вторникам в 17:00, лёд,
   Гололёд, с 1 сентября». Тренировка — факт: конкретное
   занятие конкретного дня, которое можно отменить, перенести
   и на котором тренер отмечает посещения.

   Поэтому правка правила никогда не переписывает уже
   созданные тренировки задним числом: иначе смена расписания
   в январе переписала бы сентябрь, а вместе с ним
   и отмеченные посещения.
   ========================================================= */

class Sched
{
    /** Дни недели: 1 — понедельник, 7 — воскресенье. */
    public static $days = array(
        1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг',
        5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье',
    );

    public static $kinds = array('ice' => 'лёд', 'gym' => 'зал', 'choreo' => 'хореография');

    /** Правило для выдачи наружу. */
    public static function rule($row)
    {
        return array(
            'id'           => (int) $row['id'],
            'group_id'     => (int) $row['group_id'],
            'group_code'   => isset($row['group_code']) ? $row['group_code'] : null,
            'weekday'      => (int) $row['weekday'],
            'weekday_name' => isset(self::$days[(int) $row['weekday']])
                              ? self::$days[(int) $row['weekday']] : '',
            'time_start'   => substr($row['time_start'], 0, 5),
            'duration_min' => (int) $row['duration_min'],
            'kind'         => $row['kind'],
            'kind_name'    => isset(self::$kinds[$row['kind']]) ? self::$kinds[$row['kind']] : $row['kind'],
            'venue_id'     => (int) $row['venue_id'],
            'venue_name'   => isset($row['venue_name']) ? $row['venue_name'] : null,
            'coach_id'     => $row['coach_id'] === null ? null : (int) $row['coach_id'],
            'coach_name'   => isset($row['coach_name']) ? $row['coach_name'] : null,
            'valid_from'   => $row['valid_from'],
            'valid_to'     => $row['valid_to'],
            'open'         => $row['valid_to'] === null || $row['valid_to'] >= date('Y-m-d'),
        );
    }

    /** Тренировка для выдачи наружу. */
    public static function training($row)
    {
        return array(
            'id'           => (int) $row['id'],
            'group_id'     => (int) $row['group_id'],
            'group_code'   => isset($row['group_code']) ? $row['group_code'] : null,
            'group_name'   => isset($row['group_name']) ? $row['group_name'] : null,
            'date'         => $row['date'],
            'weekday'      => (int) date('N', strtotime($row['date'])),
            'time_start'   => substr($row['time_start'], 0, 5),
            'duration_min' => (int) $row['duration_min'],
            'kind'         => $row['kind'],
            'kind_name'    => isset(self::$kinds[$row['kind']]) ? self::$kinds[$row['kind']] : $row['kind'],
            'venue_id'     => (int) $row['venue_id'],
            'venue_name'   => isset($row['venue_name']) ? $row['venue_name'] : null,
            'coach_id'     => $row['coach_id'] === null ? null : (int) $row['coach_id'],
            'coach_name'   => isset($row['coach_name']) ? $row['coach_name'] : null,
            'substitute'   => isset($row['substitute']) ? (bool) $row['substitute'] : false,
            'status'       => $row['status'],
            'rule_id'      => $row['rule_id'] === null ? null : (int) $row['rule_id'],
            'moved_to'     => $row['moved_to_training_id'] === null
                              ? null : (int) $row['moved_to_training_id'],
            'cancel_reason' => $row['cancel_reason'],
            'past'         => $row['date'] < date('Y-m-d'),
        );
    }

    /** Запрос тренировок со всеми названиями разом. */
    public static function query($where, $params, $order = 't.date, t.time_start', $limit = 500)
    {
        return Db::all(
            'SELECT t.*, g.code AS group_code, g.name AS group_name, v.name AS venue_name,
                    c.last_name AS c_last, c.first_name AS c_first, c.middle_name AS c_middle,
                    (SELECT COUNT(*) FROM group_coaches gc
                      WHERE gc.group_id = t.group_id AND gc.coach_id = t.coach_id) AS own_coach
               FROM trainings t
               JOIN training_groups g ON g.id = t.group_id
               JOIN venues v          ON v.id = t.venue_id
          LEFT JOIN coaches c         ON c.id = t.coach_id
             WHERE ' . $where . '
             ORDER BY ' . $order . '
             LIMIT ' . (int) $limit,
            $params
        );
    }

    /** Достраивает имя тренера и признак замены. */
    public static function decorate($row)
    {
        $row['coach_name'] = $row['c_last']
            ? Repo::fullName(array(
                'last_name'   => $row['c_last'],
                'first_name'  => $row['c_first'],
                'middle_name' => $row['c_middle'],
              ))
            : null;

        // Ведёт не закреплённый за группой — значит, замена
        $row['substitute'] = $row['coach_id'] !== null && (int) $row['own_coach'] === 0;

        return self::training($row);
    }

    /** Условие видимости тренировок для текущей роли. */
    public static function visible()
    {
        if (Auth::has('admin')) {
            return array('1=1', array());
        }

        $coachId = Auth::coach_id();
        if ($coachId) {
            // Свои группы плюс тренировки, на которые назначили заменять
            return array(
                '(t.coach_id = ? OR t.group_id IN
                    (SELECT group_id FROM group_coaches WHERE coach_id = ?))',
                array($coachId, $coachId)
            );
        }

        $userId = Auth::id();
        if (Auth::has('parent')) {
            return array(
                't.group_id IN (SELECT e.group_id FROM enrollments e
                                  JOIN parent_athlete pa ON pa.athlete_id = e.athlete_id
                                 WHERE pa.parent_user_id = ?
                                   AND (e.date_to IS NULL OR e.date_to >= ?))',
                array($userId, date('Y-m-d'))
            );
        }

        if (Auth::has('athlete')) {
            return array(
                't.group_id IN (SELECT e.group_id FROM enrollments e
                                  JOIN athletes a ON a.id = e.athlete_id
                                 WHERE a.user_id = ?
                                   AND (e.date_to IS NULL OR e.date_to >= ?))',
                array($userId, date('Y-m-d'))
            );
        }

        return array('1=0', array());
    }

    /**
     * Занято ли место: тот же лёд или тот же тренер в то же
     * время. Для зала и хореографии лёд не проверяем — в одном
     * здании это разные помещения.
     *
     * Возвращает текст помехи или null.
     */
    public static function busy($date, $start, $duration, $kind, $venueId, $coachId, $exceptId = null)
    {
        $from = strtotime($date . ' ' . $start);
        $to   = $from + $duration * 60;

        $rows = Db::all(
            'SELECT t.id, t.time_start, t.duration_min, t.kind, t.venue_id, t.coach_id,
                    g.code AS group_code
               FROM trainings t
               JOIN training_groups g ON g.id = t.group_id
              WHERE t.date = ? AND t.status IN (?, ?) AND t.id <> ?',
            array($date, 'planned', 'held', (int) $exceptId)
        );

        foreach ($rows as $row) {
            $otherFrom = strtotime($date . ' ' . $row['time_start']);
            $otherTo   = $otherFrom + ((int) $row['duration_min']) * 60;

            if ($from >= $otherTo || $to <= $otherFrom) {
                continue;
            }

            if ($kind === 'ice' && $row['kind'] === 'ice'
                && (int) $row['venue_id'] === (int) $venueId) {
                return 'В это время на льду уже занимается ' . $row['group_code'];
            }
            if ($coachId && (int) $row['coach_id'] === (int) $coachId) {
                return 'Этот тренер в это время уже ведёт ' . $row['group_code'];
            }
        }

        return null;
    }
}


return function (Router $router) {

    /* =====================================================
       Постоянное расписание группы
       ===================================================== */

    $router->get('/groups/{id}/schedule', function (Request $request, $params) {
        Auth::require_group($params['id']);

        $all  = $request->input('all');
        $rows = Db::all(
            'SELECT r.*, v.name AS venue_name, g.code AS group_code,
                    c.last_name AS c_last, c.first_name AS c_first, c.middle_name AS c_middle
               FROM schedule_rules r
               JOIN venues v          ON v.id = r.venue_id
               JOIN training_groups g ON g.id = r.group_id
          LEFT JOIN coaches c         ON c.id = r.coach_id
              WHERE r.group_id = ?' . ($all ? '' : ' AND (r.valid_to IS NULL OR r.valid_to >= ?)') . '
              ORDER BY r.weekday, r.time_start',
            $all ? array((int) $params['id']) : array((int) $params['id'], date('Y-m-d'))
        );

        $items = array();
        foreach ($rows as $row) {
            $row['coach_name'] = $row['c_last'] ? Repo::fullName(array(
                'last_name' => $row['c_last'], 'first_name' => $row['c_first'],
                'middle_name' => $row['c_middle'])) : null;
            $items[] = Sched::rule($row);
        }
        Response::items($items);
    });

    $router->post('/groups/{id}/schedule', function (Request $request, $params) {
        Auth::require_admin();
        $group = Repo::find('training_groups', $params['id'], 'Группа');

        $data = (new Validate($request->body))
            ->int('weekday', 'День недели', true, 1, 7)
            ->time('time_start', 'Время начала', true)
            ->int('duration_min', 'Длительность', false, 15, 480)
            ->one_of('kind', 'Вид занятия', array('ice', 'gym', 'choreo'), true)
            ->int('venue_id', 'Площадка', true, 1)
            ->int('coach_id', 'Тренер', false, 1)
            ->int('extra_class_id', 'Дополнительное занятие', false, 1)
            ->date('valid_from', 'Действует с', true)
            ->date('valid_to', 'Действует по', false)
            ->done();

        Repo::find('venues', $data['venue_id'], 'Площадка');
        if ($data['coach_id']) {
            Repo::find('coaches', $data['coach_id'], 'Тренер');
        }
        if ($data['valid_to'] && $data['valid_to'] < $data['valid_from']) {
            invalid(array('valid_to' => 'Конец срока раньше начала'));
        }

        // Два одинаковых правила в одно время — почти наверняка
        // случайный повтор, и разгребать его потом в тренировках
        $twin = Db::value(
            'SELECT id FROM schedule_rules
              WHERE group_id = ? AND weekday = ? AND time_start = ?
                AND (valid_to IS NULL OR valid_to >= ?) LIMIT 1',
            array($group['id'], $data['weekday'], $data['time_start'], $data['valid_from'])
        );
        if ($twin) {
            conflict('rule_exists', 'У группы уже есть занятие в этот день и время');
        }

        $id = Repo::create('schedule_rules', array(
            'group_id'       => $group['id'],
            'weekday'        => $data['weekday'],
            'time_start'     => $data['time_start'],
            'duration_min'   => $data['duration_min'] === null ? 60 : $data['duration_min'],
            'kind'           => $data['kind'],
            'extra_class_id' => $data['extra_class_id'],
            'venue_id'       => $data['venue_id'],
            'coach_id'       => $data['coach_id'],
            'valid_from'     => $data['valid_from'],
            'valid_to'       => $data['valid_to'],
            'created_by'     => Auth::id(),
            'created_at'     => Db::now(),
        ), 'schedule_rule');

        Response::ok(Sched::rule(Repo::find('schedule_rules', $id)), 201);
    });

    $router->patch('/schedule-rules/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $rule = Repo::find('schedule_rules', $params['id'], 'Правило расписания');

        $data = (new Validate($request->body))
            ->int('weekday', 'День недели', false, 1, 7)
            ->time('time_start', 'Время начала', false)
            ->int('duration_min', 'Длительность', false, 15, 480)
            ->int('venue_id', 'Площадка', false, 1)
            ->int('coach_id', 'Тренер', false, 1)
            ->date('valid_to', 'Действует по', false)
            ->done();

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }
        if (!$changes) {
            Response::ok(Sched::rule($rule));
        }
        if (isset($changes['valid_to']) && $changes['valid_to'] < $rule['valid_from']) {
            invalid(array('valid_to' => 'Конец срока раньше начала'));
        }

        Repo::save('schedule_rules', $rule['id'], $changes, 'schedule_rule',
                   $request->input('reason'));

        // Уже созданные тренировки правило задним числом
        // не трогает. Чтобы новое время попало в расписание,
        // нужно убрать будущие занятия и сгенерировать заново —
        // это делает отдельный вызов, осознанно.
        Response::ok(Sched::rule(Repo::find('schedule_rules', $rule['id'])));
    });

    /**
     * Закрыть правило. Физически не удаляем: на него ссылаются
     * прошедшие тренировки, и по ним потом смотрят, откуда
     * взялось занятие.
     */
    $router->delete('/schedule-rules/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $rule = Repo::find('schedule_rules', $params['id'], 'Правило расписания');

        $from = $request->input('from', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            invalid(array('from' => 'Дата в виде 2026-11-09'));
        }

        Db::begin();

        $closeAt = date('Y-m-d', strtotime($from . ' -1 day'));
        Db::update('schedule_rules', $rule['id'], array('valid_to' => $closeAt));

        // Запланированные занятия по этому правилу с даты
        // отказа убираем: они ещё не состоялись и никого
        // не касаются. Проведённые и отменённые не трогаем.
        $future = Db::all(
            'SELECT id FROM trainings
              WHERE rule_id = ? AND date >= ? AND status = ?',
            array($rule['id'], $from, 'planned')
        );
        foreach ($future as $row) {
            Db::run('DELETE FROM trainings WHERE id = ?', array($row['id']));
        }

        Audit::write('close', 'schedule_rule', $rule['id'], null,
                     array('valid_to' => $closeAt, 'removed_trainings' => count($future)),
                     $request->input('reason'));

        Db::commit();

        Response::ok(array('valid_to' => $closeAt, 'removed' => count($future)));
    });

    /* =====================================================
       Генерация тренировок
       ===================================================== */

    /**
     * Раскрывает правила в конкретные занятия на обозримый
     * срок вперёд.
     *
     * Повторный запуск безопасен: уже существующее занятие
     * в тот же день и время пропускается, что бы с ним
     * ни делали — перенесли, отменили, провели. Иначе кнопка
     * «обновить расписание» однажды затёрла бы отмеченные
     * посещения.
     */
    $router->post('/schedule/generate', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->int('group_id', 'Группа', false, 1)
            ->int('weeks', 'На сколько недель', false, 1, 52)
            ->date('from', 'С какого числа', false)
            ->done();

        $weeks = $data['weeks'] === null
            ? (int) Settings::num('schedule_horizon_weeks', 8)
            : $data['weeks'];
        if ($weeks < 1) { $weeks = 8; }

        $from = $data['from'] === null ? date('Y-m-d') : $data['from'];
        $to   = date('Y-m-d', strtotime($from . ' +' . ($weeks * 7 - 1) . ' days'));

        $where  = '(r.valid_to IS NULL OR r.valid_to >= ?) AND r.valid_from <= ?';
        $params = array($from, $to);
        if ($data['group_id']) {
            Repo::find('training_groups', $data['group_id'], 'Группа');
            $where .= ' AND r.group_id = ?';
            $params[] = $data['group_id'];
        }

        $rules = Db::all('SELECT r.*, g.code AS group_code
                            FROM schedule_rules r
                            JOIN training_groups g ON g.id = r.group_id
                           WHERE ' . $where . '
                           ORDER BY r.group_id, r.weekday, r.time_start', $params);

        $created = 0;
        $existed = 0;
        $skipped = array();

        Db::begin();

        foreach ($rules as $rule) {
            $day = $from;
            while ($day <= $to) {
                $matches = (int) date('N', strtotime($day)) === (int) $rule['weekday']
                    && $day >= $rule['valid_from']
                    && ($rule['valid_to'] === null || $day <= $rule['valid_to']);

                if (!$matches) {
                    $day = date('Y-m-d', strtotime($day . ' +1 day'));
                    continue;
                }

                $taken = Db::value(
                    'SELECT id FROM trainings WHERE group_id = ? AND date = ? AND time_start = ?',
                    array($rule['group_id'], $day, $rule['time_start'])
                );

                if ($taken) {
                    $existed++;
                } else {
                    $busy = Sched::busy($day, $rule['time_start'], (int) $rule['duration_min'],
                                        $rule['kind'], $rule['venue_id'], $rule['coach_id']);
                    if ($busy) {
                        // Не прерываем всю генерацию из-за одной
                        // накладки: создаём остальное и показываем,
                        // что именно не поместилось
                        $skipped[] = array(
                            'group'  => $rule['group_code'],
                            'date'   => $day,
                            'time'   => substr($rule['time_start'], 0, 5),
                            'reason' => $busy,
                        );
                    } else {
                        Db::insert('trainings', array(
                            'group_id'       => $rule['group_id'],
                            'date'           => $day,
                            'time_start'     => $rule['time_start'],
                            'duration_min'   => $rule['duration_min'],
                            'kind'           => $rule['kind'],
                            'extra_class_id' => $rule['extra_class_id'],
                            'venue_id'       => $rule['venue_id'],
                            'coach_id'       => $rule['coach_id'],
                            'status'         => 'planned',
                            'rule_id'        => $rule['id'],
                            'created_by'     => Auth::id(),
                            'created_at'     => Db::now(),
                        ));
                        $created++;
                    }
                }

                $day = date('Y-m-d', strtotime($day . ' +7 days'));
            }
        }

        if ($created || $skipped) {
            Audit::write('generate', 'schedule', null, null,
                         array('from' => $from, 'to' => $to,
                               'created' => $created, 'skipped' => count($skipped)));
        }

        Db::commit();

        Response::ok(array(
            'from'    => $from,
            'to'      => $to,
            'created' => $created,
            'existed' => $existed,
            'skipped' => $skipped,
        ));
    });

    /* =====================================================
       Тренировки
       ===================================================== */

    $router->get('/trainings', function (Request $request) {
        Auth::require_login();

        list($where, $params) = Sched::visible();

        $from = $request->input('from', date('Y-m-d'));
        $to   = $request->input('to', date('Y-m-d', strtotime('+13 days')));
        foreach (array($from, $to) as $one) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $one)) {
                invalid(array('from' => 'Даты в виде 2026-11-09'));
            }
        }

        $where   .= ' AND t.date BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;

        if ($request->input('group_id')) {
            Auth::require_group($request->input('group_id'));
            $where   .= ' AND t.group_id = ?';
            $params[] = (int) $request->input('group_id');
        }
        if ($request->input('status')) {
            $where   .= ' AND t.status = ?';
            $params[] = $request->input('status');
        }
        if ($request->input('mine') && Auth::coach_id()) {
            $where   .= ' AND t.coach_id = ?';
            $params[] = Auth::coach_id();
        }

        $items = array();
        foreach (Sched::query($where, $params) as $row) {
            $items[] = Sched::decorate($row);
        }
        Response::items($items);
    });

    $router->get('/trainings/{id}', function (Request $request, $params) {
        Auth::require_login();
        Auth::require_training($params['id']);

        $rows = Sched::query('t.id = ?', array((int) $params['id']));
        if (!$rows) {
            not_found('Тренировка не найдена');
        }

        $training = Sched::decorate($rows[0]);

        // Состав группы на день тренировки: кто был назначен
        // тогда, а не кто числится сейчас
        $training['athletes'] = array();
        $roster = Db::all(
            'SELECT a.id, a.last_name, a.first_name, a.middle_name, a.birth_date
               FROM enrollments e
               JOIN athletes a ON a.id = e.athlete_id
              WHERE e.group_id = ? AND e.date_from <= ?
                AND (e.date_to IS NULL OR e.date_to >= ?)
              ORDER BY a.last_name, a.first_name',
            array($training['group_id'], $training['date'], $training['date'])
        );
        foreach ($roster as $one) {
            $training['athletes'][] = array(
                'id'   => (int) $one['id'],
                'name' => Repo::fullName($one),
                'age'  => Repo::age($one['birth_date']),
            );
        }

        Response::ok($training);
    });

    /** Разовая тренировка вне постоянного расписания. */
    $router->post('/trainings', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->int('group_id', 'Группа', true, 1)
            ->date('date', 'Дата', true)
            ->time('time_start', 'Время начала', true)
            ->int('duration_min', 'Длительность', false, 15, 480)
            ->one_of('kind', 'Вид занятия', array('ice', 'gym', 'choreo'), true)
            ->int('venue_id', 'Площадка', true, 1)
            ->int('coach_id', 'Тренер', false, 1)
            ->done();

        Repo::find('training_groups', $data['group_id'], 'Группа');
        Repo::find('venues', $data['venue_id'], 'Площадка');
        if ($data['coach_id']) {
            Repo::find('coaches', $data['coach_id'], 'Тренер');
        }

        $duration = $data['duration_min'] === null ? 60 : $data['duration_min'];

        $taken = Db::value('SELECT id FROM trainings WHERE group_id = ? AND date = ? AND time_start = ?',
                           array($data['group_id'], $data['date'], $data['time_start']));
        if ($taken) {
            conflict('training_exists', 'У этой группы уже есть занятие в это время');
        }

        $busy = Sched::busy($data['date'], $data['time_start'], $duration,
                            $data['kind'], $data['venue_id'], $data['coach_id']);
        if ($busy) {
            conflict('slot_busy', $busy);
        }

        $id = Repo::create('trainings', array(
            'group_id'     => $data['group_id'],
            'date'         => $data['date'],
            'time_start'   => $data['time_start'],
            'duration_min' => $duration,
            'kind'         => $data['kind'],
            'venue_id'     => $data['venue_id'],
            'coach_id'     => $data['coach_id'],
            'status'       => 'planned',
            'created_by'   => Auth::id(),
            'created_at'   => Db::now(),
        ), 'training');

        $rows = Sched::query('t.id = ?', array($id));
        Response::ok(Sched::decorate($rows[0]), 201);
    });

    /**
     * Отмена. Причина обязательна: отменённое занятие школа
     * должна либо перенести, либо вернуть бонусным — и через
     * месяц нужно понимать, почему его не было.
     */
    $router->post('/trainings/{id}/cancel', function (Request $request, $params) {
        Auth::require_admin();
        $training = Repo::find('trainings', $params['id'], 'Тренировка');

        $data = (new Validate($request->body))
            ->text('reason', 'Причина', true, 255, 3)
            ->done();

        if ($training['status'] === 'held') {
            conflict('training_held',
                'Занятие уже проведено и отмечено. Исправление — через правку посещений');
        }
        if ($training['status'] !== 'planned') {
            conflict('training_not_planned', 'Это занятие уже отменено или перенесено');
        }

        Repo::save('trainings', $training['id'],
                   array('status' => 'cancelled', 'cancel_reason' => $data['reason']),
                   'training', $data['reason']);

        $rows = Sched::query('t.id = ?', array($training['id']));
        Response::ok(Sched::decorate($rows[0]));
    });

    /**
     * Перенос. Создаёт новое занятие и связывает со старым:
     * в расписании и у родителя видно, куда оно ушло. Старое
     * не удаляется — иначе пропадёт след, что школа перенесла,
     * а не отменила.
     */
    $router->post('/trainings/{id}/move', function (Request $request, $params) {
        Auth::require_admin();
        $training = Repo::find('trainings', $params['id'], 'Тренировка');

        $data = (new Validate($request->body))
            ->date('date', 'Новая дата', true)
            ->time('time_start', 'Новое время', true)
            ->int('venue_id', 'Площадка', false, 1)
            ->int('coach_id', 'Тренер', false, 1)
            ->text('reason', 'Причина', true, 255, 3)
            ->done();

        if ($training['status'] === 'held') {
            conflict('training_held', 'Проведённое занятие не переносится');
        }
        if ($training['status'] !== 'planned') {
            conflict('training_not_planned', 'Это занятие уже отменено или перенесено');
        }
        if ($data['date'] === $training['date']
            && $data['time_start'] === $training['time_start']) {
            invalid(array('date' => 'Это то же самое время'));
        }

        $venueId = $data['venue_id'] === null ? (int) $training['venue_id'] : $data['venue_id'];
        $coachId = $data['coach_id'] === null
            ? ($training['coach_id'] === null ? null : (int) $training['coach_id'])
            : $data['coach_id'];

        $taken = Db::value('SELECT id FROM trainings WHERE group_id = ? AND date = ? AND time_start = ?',
                           array($training['group_id'], $data['date'], $data['time_start']));
        if ($taken) {
            conflict('training_exists', 'У этой группы уже есть занятие в новое время');
        }

        $busy = Sched::busy($data['date'], $data['time_start'], (int) $training['duration_min'],
                            $training['kind'], $venueId, $coachId, $training['id']);
        if ($busy) {
            conflict('slot_busy', $busy);
        }

        Db::begin();

        $newId = Db::insert('trainings', array(
            'group_id'       => $training['group_id'],
            'date'           => $data['date'],
            'time_start'     => $data['time_start'],
            'duration_min'   => $training['duration_min'],
            'kind'           => $training['kind'],
            'extra_class_id' => $training['extra_class_id'],
            'venue_id'       => $venueId,
            'coach_id'       => $coachId,
            'status'         => 'planned',
            'rule_id'        => $training['rule_id'],
            'created_by'     => Auth::id(),
            'created_at'     => Db::now(),
        ));

        Db::update('trainings', $training['id'], array(
            'status'               => 'moved',
            'moved_to_training_id' => $newId,
            'cancel_reason'        => $data['reason'],
        ));

        Audit::write('move', 'training', $training['id'],
                     array('date' => $training['date'],
                           'time_start' => substr($training['time_start'], 0, 5)),
                     array('date' => $data['date'],
                           'time_start' => substr($data['time_start'], 0, 5),
                           'new_id' => $newId),
                     $data['reason']);

        Db::commit();

        $rows = Sched::query('t.id = ?', array($newId));
        Response::ok(array(
            'moved_from' => (int) $training['id'],
            'training'   => Sched::decorate($rows[0]),
        ), 201);
    });

    /**
     * Назначить на тренировку другого тренера — замену.
     *
     * Может администратор и тренер самой группы: когда он
     * понимает, что не придёт, ему незачем дожидаться
     * администратора. Занятие потом считается тому, кто вёл.
     */
    $router->post('/trainings/{id}/coach', function (Request $request, $params) {
        Auth::require_login();
        $training = Repo::find('trainings', $params['id'], 'Тренировка');

        if (!Auth::has('admin')) {
            $coachId = Auth::coach_id();
            $own = $coachId && Db::value(
                'SELECT 1 FROM group_coaches WHERE group_id = ? AND coach_id = ? LIMIT 1',
                array($training['group_id'], $coachId));
            if (!$own) {
                forbidden('Заменять тренера может администратор или тренер этой группы');
            }
        }

        $data = (new Validate($request->body))
            ->int('coach_id', 'Тренер', true, 1)
            ->text('reason', 'Причина', false, 255)
            ->done();

        if ($training['status'] !== 'planned') {
            conflict('training_not_planned',
                'Заменить тренера можно только у запланированного занятия');
        }

        $coach = Repo::find('coaches', $data['coach_id'], 'Тренер');
        if ($coach['status'] !== 'active') {
            conflict('coach_archived', 'Этот тренер в архиве');
        }
        if ((int) $training['coach_id'] === (int) $coach['id']) {
            conflict('same_coach', 'Этот тренер уже ведёт занятие');
        }

        $busy = Sched::busy($training['date'], $training['time_start'],
                            (int) $training['duration_min'], $training['kind'],
                            (int) $training['venue_id'], (int) $coach['id'], $training['id']);
        if ($busy) {
            conflict('slot_busy', $busy);
        }

        Repo::save('trainings', $training['id'], array('coach_id' => $coach['id']),
                   'training', $data['reason'] === null ? 'Замена тренера' : $data['reason']);

        $rows = Sched::query('t.id = ?', array($training['id']));
        Response::ok(Sched::decorate($rows[0]));
    });
};
