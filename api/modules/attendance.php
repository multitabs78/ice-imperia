<?php
/* =========================================================
   Посещаемость
   ---------------------------------------------------------
   То, ради чего кабинет затевался: тренер отмечает группу
   у борта с телефона, а не вечером в таблице по памяти.

   Отметка — это факт. Она записывается независимо
   от состояния абонемента: ребёнок на льду был, и школа
   должна видеть это сразу, а не через месяц. Деньги считает
   отдельный модуль, по полю charged.

   Кто и когда может отмечать, решает сервер. Тренеру дано
   окно в несколько суток после занятия, дальше правит
   только администратор — иначе смысл в сроках пропадает.
   ========================================================= */

class Marks
{
    public static $labels = array(
        'present'     => 'был',
        'absent'      => 'пропуск',
        'absent_sick' => 'пропуск по болезни',
        'cancelled'   => 'отменено школой',
    );

    /**
     * Списывается ли занятие.
     *
     * Одно место на все случаи, чтобы правило не разъехалось
     * по модулю. Пропуск списывает независимо от того,
     * предупредили или нет: правило одно. Болезнь тоже
     * списывает — право на перерасчёт разбирается деньгами
     * и отдельно. Не списывают только отмена школой (услуга
     * не оказана) и отработка (пропуск уже списан раньше).
     */
    public static function charged($status, $isMakeup)
    {
        if ($isMakeup) {
            return 0;
        }
        return $status === 'cancelled' ? 0 : 1;
    }

    /** Сколько суток у тренера на правку после занятия. */
    public static function window()
    {
        $days = (int) Settings::num('coach_edit_window_days', 3);
        return $days > 0 ? $days : 3;
    }

    /**
     * Можно ли сейчас отмечать это занятие.
     * Возвращает текст отказа или null.
     */
    public static function closed($training)
    {
        if ($training['status'] === 'cancelled') {
            return 'Занятие отменено, отмечать нечего';
        }
        if ($training['status'] === 'moved') {
            return 'Занятие перенесено — отмечайте то, на которое оно перенесено';
        }
        if ($training['date'] > date('Y-m-d')) {
            return 'Занятие ещё не прошло';
        }
        if (Auth::has('admin')) {
            return null;
        }

        $days = self::window();
        $edge = date('Y-m-d', strtotime($training['date'] . ' +' . $days . ' days'));
        if (date('Y-m-d') > $edge) {
            return 'Прошло больше ' . $days . ' суток. Изменить отметки может администратор';
        }

        return null;
    }

    /** Состав группы на день занятия. */
    public static function roster($groupId, $date)
    {
        return Db::all(
            'SELECT a.id, a.last_name, a.first_name, a.middle_name, a.birth_date
               FROM enrollments e
               JOIN athletes a ON a.id = e.athlete_id
              WHERE e.group_id = ? AND e.date_from <= ?
                AND (e.date_to IS NULL OR e.date_to >= ?)
              ORDER BY a.last_name, a.first_name',
            array((int) $groupId, $date, $date)
        );
    }

    /** Отметки занятия, по спортсменам. */
    public static function of($trainingId)
    {
        $rows = Db::all('SELECT * FROM attendance WHERE training_id = ?',
                        array((int) $trainingId));
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row['athlete_id']] = $row;
        }
        return $out;
    }

    /** Отметка наружу. */
    public static function mark($row)
    {
        if (!$row) {
            return null;
        }
        return array(
            'id'        => (int) $row['id'],
            'status'    => $row['status'],
            'label'     => isset(self::$labels[$row['status']])
                           ? self::$labels[$row['status']] : $row['status'],
            'charged'   => (int) $row['charged'] === 1,
            'makeup_for' => $row['makeup_for_training_id'] === null
                            ? null : (int) $row['makeup_for_training_id'],
            'comment'   => $row['comment'],
            'marked_at' => $row['marked_at'],
        );
    }
};


return function (Router $router) {

    /* =====================================================
       Отметки на тренировке
       ===================================================== */

    /**
     * Лист отметок: состав на день занятия и то, что уже
     * отмечено. Состав берётся на дату тренировки, а не
     * на сегодня: кто занимался тогда, тот и должен быть
     * в списке, даже если потом ушёл.
     */
    $router->get('/trainings/{id}/attendance', function (Request $request, $params) {
        Auth::require_login();
        Auth::require_training($params['id']);

        $training = Repo::find('trainings', $params['id'], 'Тренировка');
        $marks    = Marks::of($training['id']);

        $items = array();
        foreach (Marks::roster($training['group_id'], $training['date']) as $one) {
            $id = (int) $one['id'];
            $items[] = array(
                'athlete_id' => $id,
                'name'       => Repo::fullName($one),
                'age'        => Repo::age($one['birth_date']),
                'makeup'     => false,
                'mark'       => Marks::mark(isset($marks[$id]) ? $marks[$id] : null),
            );
            unset($marks[$id]);
        }

        // Отмеченные, но не из состава — отработки из других
        // групп. Их нельзя потерять: занятие им уже проведено
        foreach ($marks as $id => $row) {
            $who = Db::row('SELECT last_name, first_name, middle_name, birth_date
                              FROM athletes WHERE id = ?', array($id));
            $items[] = array(
                'athlete_id' => $id,
                'name'       => $who ? Repo::fullName($who) : 'Спортсмен ' . $id,
                'age'        => $who ? Repo::age($who['birth_date']) : null,
                'makeup'     => $row['makeup_for_training_id'] !== null,
                'mark'       => Marks::mark($row),
            );
        }

        $closed = Marks::closed($training);

        Response::ok(array(
            'training_id' => (int) $training['id'],
            'date'        => $training['date'],
            'time_start'  => substr($training['time_start'], 0, 5),
            'status'      => $training['status'],
            'can_mark'    => $closed === null,
            'closed_why'  => $closed,
            'window_days' => Marks::window(),
            'items'       => $items,
        ));
    });

    /**
     * Отметить занятие целиком.
     *
     * Принимает весь лист разом, а не по одному человеку:
     * тренер у борта отмечает группу одним действием,
     * и половина сохранённого листа хуже, чем ничего.
     */
    $router->post('/trainings/{id}/attendance', function (Request $request, $params) {
        Auth::require_login();
        Auth::require_training($params['id']);

        $training = Repo::find('trainings', $params['id'], 'Тренировка');

        $closed = Marks::closed($training);
        if ($closed !== null) {
            conflict('marking_closed', $closed);
        }

        $list = $request->input('marks');
        if (!is_array($list) || !$list) {
            invalid(array('marks' => 'Не переданы отметки'));
        }

        $already = Marks::of($training['id']);
        $roster  = array();
        foreach (Marks::roster($training['group_id'], $training['date']) as $one) {
            $roster[(int) $one['id']] = true;
        }

        $errors = array();
        $clean  = array();

        foreach ($list as $i => $one) {
            if (!is_array($one) || !isset($one['athlete_id'])) {
                $errors['marks'] = 'Отметка без спортсмена';
                continue;
            }

            $athleteId = (int) $one['athlete_id'];
            $status    = isset($one['status']) ? $one['status'] : '';

            if (!isset(Marks::$labels[$status])) {
                $errors['marks'] = 'Неизвестная отметка: ' . $status;
                continue;
            }
            // Отработку из другой группы заводит отдельный вызов:
            // он проверяет, какой именно пропуск она закрывает
            if (!isset($roster[$athleteId]) && !isset($already[$athleteId])) {
                $errors['marks'] = 'Спортсмен ' . $athleteId . ' не занимался в этой группе в тот день';
                continue;
            }

            $clean[] = array(
                'athlete_id' => $athleteId,
                'status'     => $status,
                'comment'    => isset($one['comment']) && is_string($one['comment'])
                                ? mb_substr(trim($one['comment']), 0, 255) : null,
            );
        }

        if ($errors) {
            invalid($errors);
        }

        $reason = $request->input('reason');
        $now    = Db::now();

        Db::begin();

        $saved   = 0;
        $changed = 0;

        foreach ($clean as $one) {
            $was = isset($already[$one['athlete_id']]) ? $already[$one['athlete_id']] : null;

            // Отработка остаётся отработкой: её признак ставится
            // при добавлении и правкой статуса не теряется
            $isMakeup = $was && $was['makeup_for_training_id'] !== null;

            if ($was) {
                if ($was['status'] === $one['status'] && (string) $was['comment'] === (string) $one['comment']) {
                    $saved++;
                    continue;
                }
                // Исправление уже сохранённого требует причины:
                // иначе через месяц не понять, почему пропуск
                // стал посещением
                if ($reason === null || trim((string) $reason) === '') {
                    Db::rollback();
                    invalid(array('reason' => 'Укажите причину изменения отметок'));
                }

                Db::update('attendance', $was['id'], array(
                    'status'    => $one['status'],
                    'charged'   => Marks::charged($one['status'], $isMakeup),
                    'comment'   => $one['comment'],
                    'marked_by' => Auth::id(),
                    'marked_at' => $now,
                ));
                Audit::write('update', 'attendance', $was['id'],
                             array('status' => $was['status']),
                             array('status' => $one['status']), $reason);
                $changed++;
            } else {
                Db::insert('attendance', array(
                    'training_id' => $training['id'],
                    'athlete_id'  => $one['athlete_id'],
                    'status'      => $one['status'],
                    'charged'     => Marks::charged($one['status'], false),
                    'comment'     => $one['comment'],
                    'marked_by'   => Auth::id(),
                    'marked_at'   => $now,
                ));
                $changed++;
            }
            $saved++;
        }

        // Отмеченное занятие считается проведённым: с этого
        // момента его нельзя отменить или перенести
        if ($training['status'] === 'planned') {
            Db::update('trainings', $training['id'], array('status' => 'held'));
            Audit::write('held', 'training', $training['id'], null,
                         array('marked' => $saved));
        }

        Db::commit();

        Response::ok(array('saved' => $saved, 'changed' => $changed, 'status' => 'held'));
    });

    /**
     * Отработка: спортсмен из другой группы приходит закрыть
     * свой пропуск. Занятие ему не списывается — пропуск уже
     * списан, и списывать второй раз значило бы брать дважды.
     */
    $router->post('/trainings/{id}/makeup', function (Request $request, $params) {
        Auth::require_login();
        Auth::require_training($params['id']);

        $training = Repo::find('trainings', $params['id'], 'Тренировка');

        $closed = Marks::closed($training);
        if ($closed !== null) {
            conflict('marking_closed', $closed);
        }

        $data = (new Validate($request->body))
            ->int('athlete_id', 'Спортсмен', true, 1)
            ->int('missed_training_id', 'Пропущенное занятие', true, 1)
            ->text('comment', 'Пояснение', false, 255)
            ->done();

        $athlete = Repo::find('athletes', $data['athlete_id'], 'Спортсмен');
        $missed  = Repo::find('trainings', $data['missed_training_id'], 'Пропущенное занятие');

        $gap = Db::row('SELECT * FROM attendance WHERE training_id = ? AND athlete_id = ?',
                       array($missed['id'], $athlete['id']));
        if (!$gap) {
            conflict('no_miss', 'На том занятии у спортсмена нет отметки о пропуске');
        }
        if ($gap['status'] !== 'absent' && $gap['status'] !== 'absent_sick') {
            conflict('not_a_miss', 'То занятие не пропущено: отмечено «'
                     . Marks::$labels[$gap['status']] . '»');
        }

        $twice = Db::value('SELECT id FROM attendance WHERE makeup_for_training_id = ? AND athlete_id = ?',
                           array($missed['id'], $athlete['id']));
        if ($twice) {
            conflict('already_made_up', 'Этот пропуск уже отработан');
        }

        $here = Db::value('SELECT id FROM attendance WHERE training_id = ? AND athlete_id = ?',
                          array($training['id'], $athlete['id']));
        if ($here) {
            conflict('already_marked', 'У спортсмена уже есть отметка на этом занятии');
        }

        $id = Db::insert('attendance', array(
            'training_id'            => $training['id'],
            'athlete_id'             => $athlete['id'],
            'status'                 => 'present',
            'charged'                => Marks::charged('present', true),
            'makeup_for_training_id' => $missed['id'],
            'comment'                => $data['comment'],
            'marked_by'              => Auth::id(),
            'marked_at'              => Db::now(),
        ));
        Audit::write('makeup', 'attendance', $id, null,
                     array('athlete_id' => (int) $athlete['id'],
                           'missed_training_id' => (int) $missed['id']),
                     $data['comment']);

        Response::ok(Marks::mark(Repo::find('attendance', $id)), 201);
    });

    /** Снять отработку — если добавили не того. */
    $router->delete('/attendance/{id}', function (Request $request, $params) {
        Auth::require_login();
        $mark = Repo::find('attendance', $params['id'], 'Отметка');
        Auth::require_training($mark['training_id']);

        if ($mark['makeup_for_training_id'] === null) {
            conflict('not_a_makeup',
                'Снять можно только отработку. Обычная отметка исправляется, а не удаляется');
        }

        $training = Repo::find('trainings', $mark['training_id']);
        $closed   = Marks::closed($training);
        if ($closed !== null) {
            conflict('marking_closed', $closed);
        }

        $reason = $request->input('reason');
        if ($reason === null || trim((string) $reason) === '') {
            invalid(array('reason' => 'Укажите причину'));
        }

        Db::run('DELETE FROM attendance WHERE id = ?', array($mark['id']));
        Audit::write('delete', 'attendance', $mark['id'],
                     array('athlete_id' => (int) $mark['athlete_id']), null, $reason);

        Response::ok(array('deleted' => true));
    });

    /* =====================================================
       Журнал
       ===================================================== */

    /**
     * Журнал группы за месяц — то, что сейчас ведётся
     * в таблице: занятия по столбцам, спортсмены по строкам.
     */
    $router->get('/groups/{id}/attendance', function (Request $request, $params) {
        Auth::require_group($params['id']);

        $month = $request->input('month', date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            invalid(array('month' => 'Месяц в виде 2026-11'));
        }

        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));

        $trainings = Db::all(
            'SELECT id, date, time_start, kind, status
               FROM trainings
              WHERE group_id = ? AND date BETWEEN ? AND ?
                AND status <> ?
              ORDER BY date, time_start',
            array((int) $params['id'], $from, $to, 'moved')
        );

        $marks = array();
        if ($trainings) {
            $ids = array();
            foreach ($trainings as $one) { $ids[] = (int) $one['id']; }
            $holes = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all('SELECT * FROM attendance WHERE training_id IN (' . $holes . ')',
                             $ids) as $row) {
                $marks[(int) $row['athlete_id']][(int) $row['training_id']] = $row;
            }
        }

        // Состав за месяц: кто занимался хоть один день
        $people = Db::all(
            'SELECT DISTINCT a.id, a.last_name, a.first_name, a.middle_name
               FROM enrollments e
               JOIN athletes a ON a.id = e.athlete_id
              WHERE e.group_id = ? AND e.date_from <= ?
                AND (e.date_to IS NULL OR e.date_to >= ?)
              ORDER BY a.last_name, a.first_name',
            array((int) $params['id'], $to, $from)
        );

        $columns = array();
        foreach ($trainings as $one) {
            $columns[] = array(
                'training_id' => (int) $one['id'],
                'date'        => $one['date'],
                'day'         => (int) date('j', strtotime($one['date'])),
                'time_start'  => substr($one['time_start'], 0, 5),
                'kind'        => $one['kind'],
                'status'      => $one['status'],
            );
        }

        $rows = array();
        foreach ($people as $one) {
            $id    = (int) $one['id'];
            $cells = array();
            $been  = 0;
            $gone  = 0;
            $paid  = 0;

            foreach ($trainings as $t) {
                $row = isset($marks[$id][(int) $t['id']]) ? $marks[$id][(int) $t['id']] : null;
                $cells[] = Marks::mark($row);

                if (!$row) { continue; }
                if ($row['status'] === 'present') { $been++; }
                if ($row['status'] === 'absent' || $row['status'] === 'absent_sick') { $gone++; }
                if ((int) $row['charged'] === 1) { $paid++; }
            }

            $rows[] = array(
                'athlete_id' => $id,
                'name'       => Repo::fullName($one),
                'cells'      => $cells,
                'present'    => $been,
                'absent'     => $gone,
                'charged'    => $paid,
            );
        }

        Response::ok(array(
            'month'   => $month,
            'columns' => $columns,
            'rows'    => $rows,
        ));
    });

    /** История посещений одного спортсмена. */
    $router->get('/athletes/{id}/attendance', function (Request $request, $params) {
        Auth::require_athlete($params['id']);

        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-t'));
        foreach (array($from, $to) as $one) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $one)) {
                invalid(array('from' => 'Даты в виде 2026-11-09'));
            }
        }

        $rows = Db::all(
            'SELECT at.*, t.date, t.time_start, t.kind, g.code AS group_code
               FROM attendance at
               JOIN trainings t       ON t.id = at.training_id
               JOIN training_groups g ON g.id = t.group_id
              WHERE at.athlete_id = ? AND t.date BETWEEN ? AND ?
              ORDER BY t.date, t.time_start',
            array((int) $params['id'], $from, $to)
        );

        $items   = array();
        $present = 0;
        $charged = 0;

        foreach ($rows as $row) {
            $mark = Marks::mark($row);
            $mark['date']        = $row['date'];
            $mark['time_start']  = substr($row['time_start'], 0, 5);
            $mark['kind']        = $row['kind'];
            $mark['group_code']  = $row['group_code'];
            $mark['training_id'] = (int) $row['training_id'];
            $items[] = $mark;

            if ($row['status'] === 'present') { $present++; }
            if ((int) $row['charged'] === 1)  { $charged++; }
        }

        Response::ok(array(
            'from'    => $from,
            'to'      => $to,
            'present' => $present,
            'charged' => $charged,
            'items'   => $items,
        ));
    });
};
