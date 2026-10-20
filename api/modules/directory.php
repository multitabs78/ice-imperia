<?php
/* =========================================================
   Справочники
   ---------------------------------------------------------
   Спортсмены, родители, тренеры, группы, назначения,
   площадки, тарифы, настройки.

   Создаёт и правит всё это администратор. Тренер видит
   только свои группы и их спортсменов, родитель — только
   своих детей. Проверки стоят на сервере: кабинет — лишь
   один из клиентов.
   ========================================================= */

class Dir
{
    /** Карточка спортсмена для списка и для профиля. */
    public static function athlete($row, $full = false)
    {
        $out = array(
            'id'         => (int) $row['id'],
            'name'       => Repo::fullName($row),
            'last_name'  => $row['last_name'],
            'first_name' => $row['first_name'],
            'birth_date' => $row['birth_date'],
            'age'        => Repo::age($row['birth_date']),
            'status'     => $row['status'],
        );

        if (!$full) {
            return $out;
        }

        $out['middle_name'] = $row['middle_name'];
        $out['gender']      = $row['gender'];
        $out['note']        = $row['note'];
        $out['has_login']   = !empty($row['user_id']);

        $out['joined_at']  = $row['joined_at'];
        $out['left_at']    = $row['left_at'];
        $out['rank_id']    = $row['rank_id'] === null ? null : (int) $row['rank_id'];
        $out['rank_name']  = $row['rank_id']
            ? Db::value('SELECT name FROM ranks WHERE id = ?', array($row['rank_id']))
            : null;
        $out['rank_date']  = $row['rank_date'];
        $out['rank_doc']   = $row['rank_doc'];

        // Сроки отдаём вместе с запасом в днях: решение
        // «пора напоминать или нет» принимает сервер, иначе
        // каждый клиент посчитает его по-своему
        $out['medical_until']   = $row['medical_until'];
        $out['medical_left']    = self::daysLeft($row['medical_until']);
        $out['insurance_until'] = $row['insurance_until'];
        $out['insurance_left']  = self::daysLeft($row['insurance_until']);

        $out['groups']      = self::groupsOf((int) $row['id']);
        $out['parents']     = self::parentsOf((int) $row['id']);

        return $out;
    }

    /**
     * Сколько суток осталось до даты. Отрицательное — срок
     * уже вышел, null — дата не заполнена.
     */
    public static function daysLeft($date)
    {
        if (!$date) {
            return null;
        }
        $left = (strtotime($date) - strtotime(date('Y-m-d'))) / 86400;
        return (int) round($left);
    }

    /** Группы спортсмена: текущие и прошлые. */
    public static function groupsOf($athleteId)
    {
        $rows = Db::all(
            'SELECT e.id, e.group_id, e.date_from, e.date_to, g.code, g.name
               FROM enrollments e
               JOIN training_groups g ON g.id = e.group_id
              WHERE e.athlete_id = ?
              ORDER BY e.date_from DESC',
            array((int) $athleteId)
        );

        // Отчисленный не занимается «сейчас» ни в одной группе,
        // даже если его последний день — сегодня
        $here = Db::value('SELECT status FROM athletes WHERE id = ?',
                          array((int) $athleteId)) === 'active';

        $today = date('Y-m-d');
        $out   = array();
        foreach ($rows as $row) {
            $out[] = array(
                'enrollment_id' => (int) $row['id'],
                'group_id'      => (int) $row['group_id'],
                'code'          => $row['code'],
                'name'          => $row['name'],
                'date_from'     => $row['date_from'],
                'date_to'       => $row['date_to'],
                'current'       => $here
                                   && ($row['date_to'] === null || $row['date_to'] >= $today)
                                   && $row['date_from'] <= $today,
            );
        }
        return $out;
    }

    public static function parentsOf($athleteId)
    {
        $rows = Db::all(
            'SELECT u.id, u.last_name, u.first_name, u.middle_name, u.phone, u.email,
                    pa.relation, pa.is_primary
               FROM parent_athlete pa
               JOIN users u ON u.id = pa.parent_user_id
              WHERE pa.athlete_id = ?
              ORDER BY pa.is_primary DESC, u.last_name',
            array((int) $athleteId)
        );

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id'         => (int) $row['id'],
                'name'       => Repo::fullName($row),
                'phone'      => $row['phone'],
                'email'      => $row['email'],
                'relation'   => $row['relation'],
                'is_primary' => (bool) $row['is_primary'],
            );
        }
        return $out;
    }

    public static function person($row)
    {
        return array(
            'id'          => (int) $row['id'],
            'name'        => Repo::fullName($row),
            'last_name'   => $row['last_name'],
            'first_name'  => $row['first_name'],
            'middle_name' => isset($row['middle_name']) ? $row['middle_name'] : null,
            'phone'       => isset($row['phone']) ? $row['phone'] : null,
            'email'       => isset($row['email']) ? $row['email'] : null,
            'status'      => $row['status'],
        );
    }

    public static function group($row, $full = false)
    {
        $out = array(
            'id'       => (int) $row['id'],
            'code'     => $row['code'],
            'name'     => $row['name'],
            'age_from' => $row['age_from'] === null ? null : (int) $row['age_from'],
            'age_to'   => $row['age_to'] === null ? null : (int) $row['age_to'],
            'status'   => $row['status'],
        );

        if (!$full) {
            return $out;
        }

        $out['tariff_id'] = $row['tariff_id'] === null ? null : (int) $row['tariff_id'];
        $out['coaches']   = self::coachesOf((int) $row['id']);
        // Считаем тех же, кого показываем в составе: отчисленный
        // спортсмен с незакрытым назначением раньше продолжал
        // числиться, и в карточке группы значился один
        // занимающийся при пустом списке
        $out['athletes']  = (int) Db::value(
            'SELECT COUNT(*) FROM enrollments e
               JOIN athletes a ON a.id = e.athlete_id
              WHERE e.group_id = ? AND a.status = ?
                AND e.date_from <= ?
                AND (e.date_to IS NULL OR e.date_to >= ?)',
            array((int) $row['id'], 'active', date('Y-m-d'), date('Y-m-d'))
        );
        return $out;
    }

    public static function coachesOf($groupId)
    {
        $rows = Db::all(
            'SELECT c.id, c.last_name, c.first_name, c.middle_name, gc.role
               FROM group_coaches gc
               JOIN coaches c ON c.id = gc.coach_id
              WHERE gc.group_id = ?
              ORDER BY gc.role, c.last_name',
            array((int) $groupId)
        );

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'id'   => (int) $row['id'],
                'name' => Repo::fullName($row),
                'role' => $row['role'],
            );
        }
        return $out;
    }

    /**
     * Условие «только мои» для тренера.
     * Администратору возвращает пустое условие.
     */
    public static function visibleAthletes()
    {
        if (Auth::has('admin')) {
            return array('', array());
        }

        $coachId = Auth::coach_id();
        if ($coachId) {
            return array(
                ' AND a.id IN (SELECT e.athlete_id FROM enrollments e
                                 JOIN group_coaches gc ON gc.group_id = e.group_id
                                WHERE gc.coach_id = ?
                                  AND (e.date_to IS NULL OR e.date_to >= ?))',
                array($coachId, date('Y-m-d'))
            );
        }

        if (Auth::has('parent')) {
            return array(
                ' AND a.id IN (SELECT athlete_id FROM parent_athlete WHERE parent_user_id = ?)',
                array(Auth::id())
            );
        }

        forbidden('Нет доступа к списку спортсменов');
    }

    /** Телефон или почта уже заняты другим пользователем. */
    public static function assertFree($phone, $email, $exceptUserId = null)
    {
        foreach (array('phone' => $phone, 'email' => $email) as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $sql = 'SELECT id FROM users WHERE ' . $field . ' = ?';
            $params = array($value);
            if ($exceptUserId) {
                $sql .= ' AND id <> ?';
                $params[] = (int) $exceptUserId;
            }
            if (Db::value($sql . ' LIMIT 1', $params)) {
                conflict('contact_taken', $field === 'phone'
                    ? 'Этот телефон уже привязан к другому пользователю'
                    : 'Эта почта уже привязана к другому пользователю');
            }
        }
    }

    /**
     * Закрывает открытые назначения в группы.
     *
     * Ушёл из школы — ушёл и из группы. Без этого он остаётся
     * в составе на будущих тренировках и в счётчике группы,
     * хотя его давно нет.
     */
    /**
     * Закрывает все открытые назначения, кроме одного.
     *
     * Дата окончания не может быть раньше начала: при переводе
     * задним числом назначение, начавшееся позже, закрывается
     * днём своего же начала, а не отрицательным промежутком.
     */
    public static function closeOthers($athleteId, $keepId, $closeAt, $reason)
    {
        $open = Db::all('SELECT id, group_id, date_from FROM enrollments
                          WHERE athlete_id = ? AND date_to IS NULL',
                        array((int) $athleteId));

        $n = 0;
        foreach ($open as $row) {
            if ($keepId !== null && (int) $row['id'] === (int) $keepId) {
                continue;
            }
            $end = $row['date_from'] > $closeAt ? $row['date_from'] : $closeAt;
            Db::update('enrollments', $row['id'], array('date_to' => $end));
            Audit::write('close_enrollment', 'enrollment', $row['id'], null,
                         array('date_to' => $end, 'group_id' => (int) $row['group_id']),
                         $reason);
            $n++;
        }
        return $n;
    }

    public static function leaveGroups($athleteId, $date, $reason = null)
    {
        $open = Db::all('SELECT id, group_id, date_from FROM enrollments
                          WHERE athlete_id = ? AND (date_to IS NULL OR date_to > ?)',
                        array((int) $athleteId, $date));

        foreach ($open as $one) {
            // Назначение не может кончиться раньше, чем началось
            $end = $one['date_from'] > $date ? $one['date_from'] : $date;
            Db::update('enrollments', $one['id'], array('date_to' => $end));
            Audit::write('close_enrollment', 'enrollment', $one['id'], null,
                         array('date_to' => $end, 'group_id' => (int) $one['group_id']),
                         $reason === null ? 'Отчисление' : $reason);
        }
        return count($open);
    }

    /** Заводит пользователю роль, если её ещё нет. */
    /**
     * Последнего администратора трогать нельзя: без него
     * в кабинет не войдёт никто и чинить это придётся
     * руками в базе.
     */
    public static function assertNotLastAdmin($userId)
    {
        $others = (int) Db::value(
            'SELECT COUNT(*) FROM user_roles r
               JOIN users u ON u.id = r.user_id
              WHERE r.role = ? AND u.status = ? AND r.user_id <> ?',
            array('admin', 'active', (int) $userId));

        if ($others === 0) {
            conflict('last_admin',
                'Это единственный администратор. Сначала назначьте другого');
        }
    }

    public static function addRole($userId, $role)
    {
        $has = Db::value('SELECT 1 FROM user_roles WHERE user_id = ? AND role = ?',
                         array((int) $userId, $role));
        if (!$has) {
            Db::run('INSERT INTO user_roles (user_id, role) VALUES (?, ?)',
                    array((int) $userId, $role));
        }
    }
}


return function (Router $router) {

    /* =====================================================
       Спортсмены
       ===================================================== */

    $router->get('/athletes', function (Request $request) {
        Auth::require_login();
        list($limit, $offset) = Repo::page($request);

        list($scope, $params) = Dir::visibleAthletes();
        $where = ' WHERE 1=1' . $scope;

        $status = $request->input('status', 'active');
        if ($status !== 'all') {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }

        list($find, $findParams) = Repo::search($request->input('q'),
                                                array('a.last_name', 'a.first_name'));
        $where  .= $find;
        $params  = array_merge($params, $findParams);

        $groupId = (int) $request->input('group_id', 0);
        if ($groupId) {
            Auth::require_group($groupId);
            // Назначенный со следующего месяца — ещё не состав:
            // счётчик и список должны показывать одно и то же
            $where .= ' AND a.id IN (SELECT athlete_id FROM enrollments
                                      WHERE group_id = ? AND date_from <= ?
                                        AND (date_to IS NULL OR date_to >= ?))';
            $params[] = $groupId;
            $params[] = date('Y-m-d');
            $params[] = date('Y-m-d');
        }

        $total = (int) Db::value('SELECT COUNT(*) FROM athletes a' . $where, $params);

        $rows = Db::all(
            'SELECT a.* FROM athletes a' . $where
            . ' ORDER BY a.last_name, a.first_name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );

        $items = array();
        foreach ($rows as $row) {
            $items[] = Dir::athlete($row);
        }
        Response::items($items, $total, $limit, $offset);
    });

    $router->post('/athletes', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', true, 80)
            ->text('first_name', 'Имя', true, 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->date('birth_date', 'Дата рождения', false, true)
            ->one_of('gender', 'Пол', array('f', 'm'), false)
            ->text('note', 'Заметка', false, 500)
            ->date('joined_at', 'Дата зачисления', false)
            ->done();

        $id = Repo::create('athletes', array(
            'last_name'   => $data['last_name'],
            'first_name'  => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'birth_date'  => $data['birth_date'],
            'gender'      => $data['gender'],
            'note'        => $data['note'],
            // Не указали — значит, зачислен сегодня: пустая
            // графа хуже разумного умолчания
            'joined_at'   => $data['joined_at'] === null ? date('Y-m-d') : $data['joined_at'],
            'status'      => 'active',
            'created_at'  => Db::now(),
        ), 'athlete');

        Response::ok(Dir::athlete(Repo::find('athletes', $id), true), 201);
    });

    $router->get('/athletes/{id}', function (Request $request, $params) {
        Auth::require_athlete($params['id']);
        Response::ok(Dir::athlete(Repo::find('athletes', $params['id'], 'Спортсмен'), true));
    });

    $router->patch('/athletes/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $athlete = Repo::find('athletes', $params['id'], 'Спортсмен');

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', 'present', 80)
            ->text('first_name', 'Имя', 'present', 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->date('birth_date', 'Дата рождения', false, true)
            ->one_of('gender', 'Пол', array('f', 'm'), false)
            ->text('note', 'Заметка', false, 500)
            ->date('joined_at', 'Дата зачисления', false)
            ->date('left_at', 'Дата отчисления', false)
            ->int('rank_id', 'Разряд', false, 1)
            ->date('rank_date', 'Дата присвоения', false, true)
            ->text('rank_doc', 'Номер документа', false, 120)
            ->date('medical_until', 'Медицинский допуск до', false)
            ->date('insurance_until', 'Страховка до', false)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        if ($data['rank_id'] !== null) {
            $rank = Repo::find('ranks', $data['rank_id'], 'Разряд');
            if ($rank['status'] !== 'active') {
                conflict('rank_archived', 'Этот разряд убран из справочника');
            }
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }

        // Пустая строка — это «очистить», а не «не трогать».
        // Без этого ошибочно введённый номер документа или
        // срок допуска нельзя было убрать совсем
        foreach (array('middle_name', 'birth_date', 'rank_id', 'rank_date', 'rank_doc',
                       'medical_until', 'insurance_until', 'note', 'gender') as $field) {
            if (array_key_exists($field, $request->body)
                && ($request->body[$field] === '' || $request->body[$field] === null)) {
                $changes[$field] = null;
            }
        }

        // Даты сверяем с тем, что получится после правки,
        // а не с тем, что пришло: половина значений может
        // остаться прежней
        $joined = array_key_exists('joined_at', $changes)
                  ? $changes['joined_at'] : $athlete['joined_at'];
        $left   = array_key_exists('left_at', $changes)
                  ? $changes['left_at'] : $athlete['left_at'];
        $born   = array_key_exists('birth_date', $changes)
                  ? $changes['birth_date'] : $athlete['birth_date'];

        if ($joined && $left && $left < $joined) {
            invalid(array('left_at' => 'Отчисление раньше зачисления'));
        }
        if ($born && $joined && $joined < $born) {
            invalid(array('joined_at' => 'Зачисление раньше дня рождения'));
        }
        $rankId   = array_key_exists('rank_id', $changes)
                    ? $changes['rank_id'] : $athlete['rank_id'];
        $rankDate = array_key_exists('rank_date', $changes)
                    ? $changes['rank_date'] : $athlete['rank_date'];
        if ($rankDate !== null && $rankId === null) {
            invalid(array('rank_id' => 'Сначала выберите разряд'));
        }

        // Отчислили — значит, в архив; вернули — значит, обратно.
        // Два поля об одном и том же не должны расходиться
        if (isset($changes['left_at'])) {
            $changes['status'] = 'archived';
        }
        if (isset($changes['status']) && $changes['status'] === 'active'
            && !isset($changes['left_at'])) {
            $changes['left_at'] = null;
        }
        // Перевели в архив без даты — считаем, что ушёл сегодня
        if (isset($changes['status']) && $changes['status'] === 'archived'
            && !isset($changes['left_at']) && $athlete['left_at'] === null) {
            $changes['left_at'] = date('Y-m-d');
        }

        Db::begin();

        $saved = Repo::save('athletes', $athlete['id'], $changes, 'athlete',
                            $request->input('reason'));

        if (isset($changes['status']) && $changes['status'] === 'archived') {
            // Дата могла быть проставлена раньше: повторное
            // сохранение уже отчисленной карточки не должно
            // обращаться к значению, которого в правке нет
            $leaveAt = isset($changes['left_at']) ? $changes['left_at']
                     : ($athlete['left_at'] === null ? date('Y-m-d') : $athlete['left_at']);
            Dir::leaveGroups($athlete['id'], $leaveAt, $request->input('reason'));
        }

        Db::commit();

        Response::ok(Dir::athlete(Repo::find('athletes', $athlete['id']), true));
    });

    /**
     * Удаление по требованию о персональных данных.
     * Не DELETE: статистика посещений остаётся обезличенной,
     * иначе рассыплются прошлые отчёты.
     */
    $router->post('/athletes/{id}/anonymize', function (Request $request, $params) {
        Auth::require_admin();
        $athlete = Repo::find('athletes', $params['id'], 'Спортсмен');

        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            invalid(array('reason' => 'Укажите основание: удаление данных — необратимое действие'));
        }

        $leftAt = $athlete['left_at'] === null ? date('Y-m-d') : $athlete['left_at'];

        Db::begin();
        Db::run('DELETE FROM parent_athlete WHERE athlete_id = ?', array($athlete['id']));
        Dir::leaveGroups($athlete['id'], $leftAt, $reason);
        Repo::save('athletes', $athlete['id'], array(
            'last_name'   => 'Удалённый',
            'first_name'  => 'профиль',
            'middle_name' => null,
            'birth_date'  => null,
            'gender'      => null,
            'note'        => null,
            'user_id'     => null,
            'status'      => 'archived',
            // Дата отчисления проставляется сама: иначе
            // в отчёте останется человек, зачисленный
            // и никогда не отчисленный
            'left_at'     => $leftAt,
            'rank_doc'        => null,
            'medical_until'   => null,
            'insurance_until' => null,
        ), 'athlete', $reason);
        Audit::write('anonymize', 'athlete', $athlete['id'], null, null, $reason);
        Db::commit();

        Response::ok(array('ok' => true));
    });

    /* --- Родители ребёнка --------------------------------- */

    $router->get('/athletes/{id}/parents', function (Request $request, $params) {
        Auth::require_athlete($params['id']);
        Response::items(Dir::parentsOf($params['id']));
    });

    $router->post('/athletes/{id}/parents', function (Request $request, $params) {
        Auth::require_admin();
        $athlete = Repo::find('athletes', $params['id'], 'Спортсмен');

        $data = (new Validate($request->body))
            ->int('parent_user_id', 'Родитель', true, 1)
            ->one_of('relation', 'Кем приходится', array('mother', 'father', 'guardian'))
            ->bool('is_primary', false)
            ->done();

        $parent = Repo::find('users', $data['parent_user_id'], 'Родитель');

        $exists = Db::value('SELECT 1 FROM parent_athlete
                              WHERE parent_user_id = ? AND athlete_id = ?',
                            array($parent['id'], $athlete['id']));
        if ($exists) {
            conflict('already_linked', 'Этот родитель уже привязан к ребёнку');
        }

        Db::begin();
        Dir::addRole($parent['id'], 'parent');

        if ($data['is_primary']) {
            Db::run('UPDATE parent_athlete SET is_primary = 0 WHERE athlete_id = ?',
                    array($athlete['id']));
        }

        Db::insert('parent_athlete', array(
            'parent_user_id' => $parent['id'],
            'athlete_id'     => $athlete['id'],
            'relation'       => $data['relation'],
            'is_primary'     => $data['is_primary'] ? 1 : 0,
            'created_at'     => Db::now(),
        ));
        Audit::write('link_parent', 'athlete', $athlete['id'], null,
                     array('parent_user_id' => $parent['id'], 'relation' => $data['relation']));
        Db::commit();

        Response::items(Dir::parentsOf($athlete['id']));
    });

    $router->delete('/athletes/{id}/parents/{userId}', function (Request $request, $params) {
        Auth::require_admin();

        $removed = Db::run('DELETE FROM parent_athlete WHERE athlete_id = ? AND parent_user_id = ?',
                           array((int) $params['id'], (int) $params['userId']))->rowCount();
        if (!$removed) {
            not_found('Такой привязки нет');
        }

        Audit::write('unlink_parent', 'athlete', $params['id'], null,
                     array('parent_user_id' => (int) $params['userId']),
                     $request->input('reason'));

        Response::ok(array('ok' => true));
    });

    /* =====================================================
       Родители
       ===================================================== */

    $router->get('/parents', function (Request $request) {
        Auth::require_admin();
        list($limit, $offset) = Repo::page($request);

        list($find, $params) = Repo::search($request->input('q'),
            array('u.last_name', 'u.first_name', 'u.phone', 'u.email'));

        $where = ' WHERE u.id IN (SELECT user_id FROM user_roles WHERE role = ?)' . $find;
        array_unshift($params, 'parent');

        $total = (int) Db::value('SELECT COUNT(*) FROM users u' . $where, $params);
        $rows  = Db::all('SELECT u.* FROM users u' . $where
                       . ' ORDER BY u.last_name, u.first_name LIMIT ' . (int) $limit
                       . ' OFFSET ' . (int) $offset, $params);

        $items = array();
        foreach ($rows as $row) {
            $person = Dir::person($row);
            $person['children'] = Db::all(
                'SELECT a.id, a.last_name, a.first_name
                   FROM parent_athlete pa JOIN athletes a ON a.id = pa.athlete_id
                  WHERE pa.parent_user_id = ?', array($row['id'])
            );
            $items[] = $person;
        }
        Response::items($items, $total, $limit, $offset);
    });

    $router->post('/parents', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', true, 80)
            ->text('first_name', 'Имя', true, 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->done();

        if ($data['phone'] === null && $data['email'] === null) {
            invalid(array('phone' => 'Нужен телефон или почта — по ним родитель входит в кабинет'));
        }
        Dir::assertFree($data['phone'], $data['email']);

        Db::begin();
        $id = Repo::create('users', array(
            'last_name'   => $data['last_name'],
            'first_name'  => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'phone'       => $data['phone'],
            'email'       => $data['email'],
            'status'      => 'active',
            'created_at'  => Db::now(),
        ), 'parent');
        Dir::addRole($id, 'parent');
        Db::commit();

        Response::ok(Dir::person(Repo::find('users', $id)), 201);
    });

    $router->patch('/parents/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $parent = Repo::find('users', $params['id'], 'Родитель');

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', 'present', 80)
            ->text('first_name', 'Имя', 'present', 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->one_of('status', 'Состояние', array('active', 'blocked'), false)
            ->done();

        Dir::assertFree($data['phone'], $data['email'], $parent['id']);

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }

        Response::ok(Dir::person(Repo::save('users', $parent['id'], $changes, 'parent',
                                            $request->input('reason'))));
    });

    /* =====================================================
       Тренеры
       ===================================================== */

    $router->get('/coaches', function (Request $request) {
        Auth::require_admin();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? '' : ' WHERE status = ?';
        $params = $status === 'all' ? array() : array($status);

        // Контакты лежат в учётной записи, а не в карточке
        // тренера: без этого в списке у всех было пусто,
        // и администратор не видел, какую почту сам же задал
        $rows  = Db::all(
            'SELECT c.*, u.phone AS u_phone, u.email AS u_email, u.status AS u_status,
                    u.password_hash AS u_hash
               FROM coaches c
          LEFT JOIN users u ON u.id = c.user_id'
            . ($status === 'all' ? '' : ' WHERE c.status = ?')
            . ' ORDER BY c.last_name, c.first_name', $params);

        $items = array();
        foreach ($rows as $row) {
            $row['phone'] = $row['u_phone'];
            $row['email'] = $row['u_email'];

            $person = Dir::person($row);
            $person['has_login']  = !empty($row['user_id']);
            $person['has_password'] = !empty($row['u_hash']);
            $person['blocked']    = $row['u_status'] === 'blocked';
            $person['groups']    = Db::all(
                'SELECT g.id, g.code, g.name FROM group_coaches gc
                   JOIN training_groups g ON g.id = gc.group_id
                  WHERE gc.coach_id = ? ORDER BY g.sort, g.code', array($row['id'])
            );
            $items[] = $person;
        }
        Response::items($items);
    });

    /**
     * Создаёт тренера.
     *
     * Передали user_id — карточка привязывается
     * к существующей учётной записи: один человек бывает
     * и администратором, и тренером, и заводить ему второй
     * вход незачем.
     *
     * Передали телефон или почту — заводится учётная запись.
     * Пароль при этом необязателен: без него тренер входит
     * по разовому коду. Раньше контакты в этом случае просто
     * пропадали, а администратор, задавший почту, был уверен,
     * что вход открыт.
     *
     * Не передали ничего — карточка без входа. Так тоже бывает:
     * тренер, который кабинетом не пользуется.
     */
    $router->post('/coaches', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', true, 80)
            ->text('first_name', 'Имя', true, 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->text('password', 'Временный пароль', false, 200, 8)
            ->int('user_id', 'Учётная запись', false, 1)
            ->done();

        Db::begin();

        $userId = null;

        if ($data['user_id'] !== null) {
            $user = Repo::find('users', $data['user_id'], 'Учётная запись');

            $taken = Db::value('SELECT id FROM coaches WHERE user_id = ? LIMIT 1',
                               array($user['id']));
            if ($taken) {
                conflict('user_is_coach', 'К этой учётной записи уже привязана карточка тренера');
            }

            Dir::addRole($user['id'], 'coach');
            $userId = (int) $user['id'];

        } elseif ($data['phone'] !== null || $data['email'] !== null) {
            Dir::assertFree($data['phone'], $data['email']);

            $fields = array(
                'last_name'   => $data['last_name'],
                'first_name'  => $data['first_name'],
                'middle_name' => $data['middle_name'],
                'phone'       => $data['phone'],
                'email'       => $data['email'],
                'status'      => 'active',
                'created_at'  => Db::now(),
            );
            if ($data['password'] !== null) {
                $fields['password_hash']        = password_hash($data['password'], PASSWORD_DEFAULT);
                $fields['must_change_password'] = 1;
            }

            $userId = Repo::create('users', $fields, 'user');
            Dir::addRole($userId, 'coach');

        } elseif ($data['password'] !== null) {
            // Пароль без контакта бесполезен: входить не по чему
            invalid(array('email' => 'Для входа нужен телефон или почта'));
        }

        $id = Repo::create('coaches', array(
            'user_id'     => $userId,
            'last_name'   => $data['last_name'],
            'first_name'  => $data['first_name'],
            'middle_name' => $data['middle_name'],
            'status'      => 'active',
            'created_at'  => Db::now(),
        ), 'coach');

        Db::commit();

        $coach = Dir::person(Repo::find('coaches', $id));
        $coach['has_login'] = $userId !== null;
        Response::ok($coach, 201);
    });

    $router->patch('/coaches/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $coach = Repo::find('coaches', $params['id'], 'Тренер');

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', 'present', 80)
            ->text('first_name', 'Имя', 'present', 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        $changes = array();
        foreach (array('last_name', 'first_name', 'middle_name', 'status') as $field) {
            if (isset($data[$field])) {
                $changes[$field] = $data[$field];
            }
        }

        Db::begin();

        $saved = Repo::save('coaches', $coach['id'], $changes, 'coach',
                            $request->input('reason'));

        // Контакты живут в учётной записи. Если её нет,
        // а контакт задали — заводим: раньше такая правка
        // молча ничего не меняла и отвечала «сохранено»
        if ($data['phone'] !== null || $data['email'] !== null) {
            Dir::assertFree($data['phone'], $data['email'], $coach['user_id']);

            if ($coach['user_id']) {
                $touch = array();
                if ($data['phone'] !== null) { $touch['phone'] = $data['phone']; }
                if ($data['email'] !== null) { $touch['email'] = $data['email']; }
                Repo::save('users', $coach['user_id'], $touch, 'user',
                           $request->input('reason'));
            } else {
                $userId = Repo::create('users', array(
                    'last_name'   => isset($changes['last_name'])
                                     ? $changes['last_name'] : $coach['last_name'],
                    'first_name'  => isset($changes['first_name'])
                                     ? $changes['first_name'] : $coach['first_name'],
                    'middle_name' => $coach['middle_name'],
                    'phone'       => $data['phone'],
                    'email'       => $data['email'],
                    'status'      => 'active',
                    'created_at'  => Db::now(),
                ), 'user');
                Dir::addRole($userId, 'coach');
                Db::update('coaches', $coach['id'], array('user_id' => $userId));
                $coach['user_id'] = $userId;
            }
        }

        // Архивный тренер не должен продолжать входить в кабинет
        if (isset($changes['status']) && $changes['status'] === 'archived' && $coach['user_id']) {
            Db::update('users', $coach['user_id'], array('status' => 'blocked'));
            Auth::revoke_all($coach['user_id']);
        }
        // А возвращённый из архива — должен
        if (isset($changes['status']) && $changes['status'] === 'active' && $coach['user_id']) {
            Db::update('users', $coach['user_id'], array('status' => 'active'));
        }

        Db::commit();

        $out = Dir::person(Repo::find('coaches', $coach['id']));
        if ($coach['user_id']) {
            $user = Repo::find('users', $coach['user_id']);
            $out['phone'] = $user['phone'];
            $out['email'] = $user['email'];
        }
        $out['has_login'] = !empty($coach['user_id']);
        Response::ok($out);
    });

    /**
     * Задать или сбросить пароль тренеру.
     *
     * Без этого тренер, заведённый без пароля или забывший
     * его, оставался снаружи навсегда: сменить пароль можно
     * было только зная старый.
     */
    $router->post('/coaches/{id}/password', function (Request $request, $params) {
        Auth::require_admin();
        $coach = Repo::find('coaches', $params['id'], 'Тренер');

        $data = (new Validate($request->body))
            ->text('password', 'Временный пароль', true, 200, 8)
            ->done();

        if (!$coach['user_id']) {
            conflict('no_login',
                'У этого тренера нет учётной записи. Сначала укажите телефон или почту');
        }

        $user = Repo::find('users', $coach['user_id'], 'Учётная запись');
        if (!$user['phone'] && !$user['email']) {
            conflict('no_contact',
                'Не задан телефон или почта — входить будет не по чему');
        }

        Db::begin();
        Db::update('users', $user['id'], array(
            'password_hash'        => password_hash($data['password'], PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'status'               => 'active',
        ));
        // Старые входы закрываем: пароль меняют и тогда,
        // когда он попал не в те руки
        Auth::revoke_all($user['id']);
        Audit::write('set_password', 'coach', $coach['id'], null, null,
                     $request->input('reason'));
        Db::commit();

        Response::ok(array(
            'login' => $user['email'] ? $user['email'] : $user['phone'],
            'must_change_password' => true,
        ));
    });

    /**
     * Пользователи — для выбора, к кому привязать карточку
     * тренера. Отдаёт только то, что нужно для списка.
     */
    $router->get('/users', function (Request $request) {
        Auth::require_admin();

        list($find, $params) = Repo::search($request->input('q'),
            array('u.last_name', 'u.first_name', 'u.phone', 'u.email'));

        $where = ' WHERE u.status = ?' . $find;
        array_unshift($params, 'active');

        if ($request->input('free_coach')) {
            $where .= ' AND u.id NOT IN (SELECT user_id FROM coaches WHERE user_id IS NOT NULL)';
        }

        $rows  = Db::all('SELECT u.* FROM users u' . $where
                       . ' ORDER BY u.last_name, u.first_name LIMIT 200', $params);
        $items = array();
        foreach ($rows as $row) {
            $person = Dir::person($row);
            $person['roles'] = Auth::rolesOf((int) $row['id']);
            $items[] = $person;
        }
        Response::items($items);
    });

    /* =====================================================
       Администраторы
       ---------------------------------------------------
       Первого заводит db/seed-admin.php, пока входить
       в кабинет некому. Остальных — отсюда: держать
       на сервере скрипт, умеющий создавать администратора,
       дольше необходимого незачем.
       ===================================================== */

    $router->get('/admins', function (Request $request) {
        Auth::require_admin();

        $rows = Db::all(
            'SELECT u.* FROM users u
               JOIN user_roles r ON r.user_id = u.id AND r.role = ?
              ORDER BY u.last_name, u.first_name',
            array('admin'));

        $items = array();
        foreach ($rows as $row) {
            $one = Dir::person($row);
            $one['roles'] = Auth::rolesOf((int) $row['id']);
            $one['self']  = (int) $row['id'] === (int) Auth::id();
            $items[] = $one;
        }
        Response::items($items);
    });

    /**
     * Новый администратор — либо отдельный человек, либо роль
     * тому, кто уже заведён: тренер школы вполне может быть
     * и администратором, и второй вход ему ни к чему.
     */
    $router->post('/admins', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->int('user_id', 'Учётная запись', false, 1)
            ->text('last_name', 'Фамилия', false, 80)
            ->text('first_name', 'Имя', false, 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->text('password', 'Временный пароль', false, 200, 8)
            ->done();

        Db::begin();

        if ($data['user_id'] !== null) {
            $user = Repo::find('users', $data['user_id'], 'Учётная запись');
            if (Auth::rolesOf((int) $user['id']) && in_array('admin', Auth::rolesOf((int) $user['id']))) {
                conflict('already_admin', 'Этот человек уже администратор');
            }
            Dir::addRole($user['id'], 'admin');
            $id = (int) $user['id'];

        } else {
            if ($data['last_name'] === null || $data['first_name'] === null) {
                invalid(array('last_name' => 'Укажите фамилию и имя'));
            }
            if ($data['phone'] === null && $data['email'] === null) {
                invalid(array('email' => 'Нужен телефон или почта — по ним администратор входит'));
            }
            Dir::assertFree($data['phone'], $data['email']);

            $fields = array(
                'last_name'   => $data['last_name'],
                'first_name'  => $data['first_name'],
                'middle_name' => $data['middle_name'],
                'phone'       => $data['phone'],
                'email'       => $data['email'],
                'status'      => 'active',
                'created_at'  => Db::now(),
            );
            if ($data['password'] !== null) {
                $fields['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                // Временный пароль знает не только он: при первом
                // входе система потребует задать свой
                $fields['must_change_password'] = 1;
            }

            $id = Repo::create('users', $fields, 'admin');
            Dir::addRole($id, 'admin');
        }

        Db::commit();

        $out = Dir::person(Repo::find('users', $id));
        $out['roles'] = Auth::rolesOf($id);
        Response::ok($out, 201);
    });

    $router->patch('/admins/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $user = Repo::find('users', $params['id'], 'Администратор');

        $data = (new Validate($request->body))
            ->text('last_name', 'Фамилия', 'present', 80)
            ->text('first_name', 'Имя', 'present', 80)
            ->text('middle_name', 'Отчество', false, 80)
            ->phone('phone', 'Телефон', false)
            ->email('email', 'Почта', false)
            ->one_of('status', 'Состояние', array('active', 'blocked'), false)
            ->done();

        Dir::assertFree($data['phone'], $data['email'], $user['id']);

        // Закрыть вход самому себе — верный способ остаться
        // снаружи с открытой вкладкой и без пути обратно
        if (isset($data['status']) && $data['status'] === 'blocked') {
            if ((int) $user['id'] === (int) Auth::id()) {
                conflict('self_block', 'Нельзя закрыть вход самому себе');
            }
            Dir::assertNotLastAdmin($user['id']);
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) { $changes[$key] = $value; }
        }

        $saved = Repo::save('users', $user['id'], $changes, 'admin',
                            $request->input('reason'));

        if (isset($changes['status']) && $changes['status'] === 'blocked') {
            Auth::revoke_all($user['id']);
        }

        $out = Dir::person($saved);
        $out['roles'] = Auth::rolesOf((int) $user['id']);
        Response::ok($out);
    });

    /**
     * Снять права администратора. Сама учётная запись
     * остаётся: человек мог быть ещё и тренером.
     *
     * С себя снять можно, если останется кто-то ещё: уход
     * с передачей дел — обычное дело, а вот школа без
     * единственного администратора чинится только руками
     * в базе.
     */
    $router->delete('/admins/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $user = Repo::find('users', $params['id'], 'Администратор');

        $reason = trim((string) $request->input('reason'));
        if ($reason === '') {
            invalid(array('reason' => 'Укажите основание'));
        }
        // Снять права с себя можно — человек вправе уйти,
        // передав дела. Нельзя остаться вовсе без
        // администраторов: это чинится только руками в базе
        Dir::assertNotLastAdmin($user['id']);

        Db::run('DELETE FROM user_roles WHERE user_id = ? AND role = ?',
                array($user['id'], 'admin'));
        Audit::write('revoke_admin', 'admin', $user['id'], array('role' => 'admin'),
                     null, $reason);

        Response::ok(array('roles' => Auth::rolesOf((int) $user['id'])));
    });

    /* =====================================================
       Разряды
       ===================================================== */

    $router->get('/ranks', function (Request $request) {
        Auth::require_login();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? '' : ' WHERE status = ?';
        $params = $status === 'all' ? array() : array($status);

        Response::items(Db::all('SELECT * FROM ranks' . $where . ' ORDER BY sort, name',
                                $params));
    });

    $router->post('/ranks', function (Request $request) {
        Auth::require_admin();
        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 80)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->done();

        $twin = Db::value('SELECT id FROM ranks WHERE name = ?', array($data['name']));
        if ($twin) {
            conflict('rank_exists', 'Такой разряд уже есть');
        }

        $id = Repo::create('ranks', array(
            'name'   => $data['name'],
            'sort'   => $data['sort'] === null ? 100 : $data['sort'],
            'status' => 'active',
        ), 'rank');
        Response::ok(Repo::find('ranks', $id), 201);
    });

    $router->patch('/ranks/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $rank = Repo::find('ranks', $params['id'], 'Разряд');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 80)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) { $changes[$key] = $value; }
        }
        Response::ok(Repo::save('ranks', $rank['id'], $changes, 'rank',
                                $request->input('reason')));
    });

    /* =====================================================
       Виды занятий
       ===================================================== */

    $router->get('/lesson-kinds', function (Request $request) {
        Auth::require_login();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? '' : ' WHERE status = ?';
        $params = $status === 'all' ? array() : array($status);

        $rows  = Db::all('SELECT * FROM lesson_kinds' . $where . ' ORDER BY sort, name', $params);
        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'id'     => (int) $row['id'],
                'name'   => $row['name'],
                'on_ice' => (int) $row['on_ice'] === 1,
                'sort'   => (int) $row['sort'],
                'status' => $row['status'],
                'in_use' => (bool) Db::value(
                    'SELECT 1 FROM trainings WHERE kind_id = ? LIMIT 1', array($row['id'])),
            );
        }
        Response::items($items);
    });

    $router->post('/lesson-kinds', function (Request $request) {
        Auth::require_admin();
        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 80)
            ->bool('on_ice', false)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->done();

        $twin = Db::value('SELECT id FROM lesson_kinds WHERE name = ?', array($data['name']));
        if ($twin) {
            conflict('kind_exists', 'Такой вид занятия уже есть');
        }

        $id = Repo::create('lesson_kinds', array(
            'name'   => $data['name'],
            'on_ice' => $data['on_ice'] ? 1 : 0,
            'sort'   => $data['sort'] === null ? 100 : $data['sort'],
            'status' => 'active',
        ), 'lesson_kind');
        Response::ok(Repo::find('lesson_kinds', $id), 201);
    });

    $router->patch('/lesson-kinds/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $kind = Repo::find('lesson_kinds', $params['id'], 'Вид занятия');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 80)
            ->bool('on_ice', (int) $kind['on_ice'] === 1)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        // Вид, по которому уже стоят будущие занятия, в архив
        // не убираем: расписание осталось бы без вида
        if (isset($data['status']) && $data['status'] === 'archived') {
            $busy = Db::value(
                'SELECT COUNT(*) FROM trainings WHERE kind_id = ? AND date >= ? AND status = ?',
                array($kind['id'], date('Y-m-d'), 'planned'));
            if ($busy) {
                conflict('kind_in_use', 'По этому виду ещё стоят занятия: ' . $busy
                         . '. Сначала переведите их на другой вид');
            }
        }

        $changes = array('on_ice' => $data['on_ice'] ? 1 : 0);
        foreach ($data as $key => $value) {
            if ($key !== 'on_ice' && $value !== null) { $changes[$key] = $value; }
        }
        Response::ok(Repo::save('lesson_kinds', $kind['id'], $changes, 'lesson_kind',
                                $request->input('reason')));
    });

    /* =====================================================
       Услуги и цены
       ===================================================== */

    $router->get('/services', function (Request $request) {
        Auth::require_admin();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? '' : ' WHERE status = ?';
        $params = $status === 'all' ? array() : array($status);

        $rows = Db::all('SELECT * FROM services' . $where . ' ORDER BY sort, name', $params);

        $items = array();
        foreach ($rows as $row) {
            $items[] = array(
                'id'        => (int) $row['id'],
                'code'      => $row['code'],
                'name'      => $row['name'],
                'price'     => $row['price'],
                'price_set' => $row['price'] !== null && $row['price'] !== '',
                'note'      => $row['note'],
                'status'    => $row['status'],
                'sort'      => (int) $row['sort'],
                'system'    => $row['code'] !== null && $row['code'] !== '',
            );
        }
        Response::items($items);
    });

    $router->post('/services', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 190)
            ->int('price', 'Цена', false, 0, 1000000)
            ->text('note', 'Пояснение', false, 255)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->done();

        // code задают только миграции: на него ссылается код
        $id = Repo::create('services', array(
            'code'       => null,
            'name'       => $data['name'],
            'price'      => $data['price'],
            'note'       => $data['note'],
            'sort'       => $data['sort'] === null ? 100 : $data['sort'],
            'status'     => 'active',
            'updated_by' => Auth::id(),
            'created_at' => Db::now(),
        ), 'service');

        Response::ok(Repo::find('services', $id), 201);
    });

    $router->patch('/services/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $service = Repo::find('services', $params['id'], 'Услуга');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 190)
            ->int('price', 'Цена', false, 0, 1000000)
            ->text('note', 'Пояснение', false, 255)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        // Системную услугу убрать нельзя: на неё ссылается расчёт
        if ($service['code'] && isset($data['status']) && $data['status'] === 'archived') {
            conflict('service_is_system',
                'Эта услуга нужна для расчётов и в архив не убирается. Можно изменить название и цену');
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }
        $changes['updated_by'] = Auth::id();

        Response::ok(Repo::save('services', $service['id'], $changes, 'service',
                                $request->input('reason')));
    });

    /* =====================================================
       Группы
       ===================================================== */

    $router->get('/groups', function (Request $request) {
        Auth::require_login();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? ' WHERE 1=1' : ' WHERE g.status = ?';
        $params = $status === 'all' ? array() : array($status);

        if (!Auth::has('admin')) {
            $coachId = Auth::coach_id();
            if (!$coachId) {
                forbidden('Нет доступа к списку групп');
            }
            $where   .= ' AND g.id IN (SELECT group_id FROM group_coaches WHERE coach_id = ?)';
            $params[] = $coachId;
        }

        $rows  = Db::all('SELECT g.* FROM training_groups g' . $where
                       . ' ORDER BY g.sort, g.code', $params);
        $items = array();
        foreach ($rows as $row) {
            $items[] = Dir::group($row, true);
        }
        Response::items($items);
    });

    $router->post('/groups', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('code', 'Короткое название', true, 32)
            ->text('name', 'Полное название', true, 190)
            ->int('age_from', 'Возраст с', false, 2, 99)
            ->int('age_to', 'Возраст до', false, 2, 99)
            ->int('tariff_id', 'Тариф', false, 1)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->done();

        if ($data['age_from'] !== null && $data['age_to'] !== null
            && $data['age_from'] > $data['age_to']) {
            invalid(array('age_to' => 'Верхняя граница возраста меньше нижней'));
        }
        if (Db::value('SELECT 1 FROM training_groups WHERE code = ?', array($data['code']))) {
            conflict('code_taken', 'Группа с таким коротким названием уже есть');
        }

        $id = Repo::create('training_groups', array(
            'code'       => $data['code'],
            'name'       => $data['name'],
            'age_from'   => $data['age_from'],
            'age_to'     => $data['age_to'],
            'tariff_id'  => $data['tariff_id'],
            'sort'       => $data['sort'] === null ? 0 : $data['sort'],
            'status'     => 'active',
            'created_at' => Db::now(),
        ), 'group');

        Response::ok(Dir::group(Repo::find('training_groups', $id), true), 201);
    });

    $router->get('/groups/{id}', function (Request $request, $params) {
        Auth::require_group($params['id']);
        Response::ok(Dir::group(Repo::find('training_groups', $params['id'], 'Группа'), true));
    });

    $router->patch('/groups/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $group = Repo::find('training_groups', $params['id'], 'Группа');

        $data = (new Validate($request->body))
            ->text('code', 'Короткое название', 'present', 32)
            ->text('name', 'Полное название', 'present', 190)
            ->int('age_from', 'Возраст с', false, 2, 99)
            ->int('age_to', 'Возраст до', false, 2, 99)
            ->int('tariff_id', 'Тариф', false, 1)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        if ($data['code'] !== null && $data['code'] !== $group['code']
            && Db::value('SELECT 1 FROM training_groups WHERE code = ? AND id <> ?',
                         array($data['code'], $group['id']))) {
            conflict('code_taken', 'Группа с таким коротким названием уже есть');
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }

        $saved = Repo::save('training_groups', $group['id'], $changes, 'group',
                            $request->input('reason'));
        Response::ok(Dir::group($saved, true));
    });

    /* --- Тренеры группы ----------------------------------- */

    $router->post('/groups/{id}/coaches', function (Request $request, $params) {
        Auth::require_admin();
        $group = Repo::find('training_groups', $params['id'], 'Группа');

        $data = (new Validate($request->body))
            ->int('coach_id', 'Тренер', true, 1)
            ->one_of('role', 'Роль', array('main', 'assistant'), false)
            ->done();

        $coach = Repo::find('coaches', $data['coach_id'], 'Тренер');
        $role  = $data['role'] === null ? 'main' : $data['role'];

        $exists = Db::value('SELECT 1 FROM group_coaches WHERE group_id = ? AND coach_id = ?',
                            array($group['id'], $coach['id']));
        if ($exists) {
            Db::run('UPDATE group_coaches SET role = ? WHERE group_id = ? AND coach_id = ?',
                    array($role, $group['id'], $coach['id']));
        } else {
            Db::insert('group_coaches', array(
                'group_id' => $group['id'],
                'coach_id' => $coach['id'],
                'role'     => $role,
            ));
        }

        Audit::write('set_coach', 'group', $group['id'], null,
                     array('coach_id' => $coach['id'], 'role' => $role));

        Response::items(Dir::coachesOf($group['id']));
    });

    $router->delete('/groups/{id}/coaches/{coachId}', function (Request $request, $params) {
        Auth::require_admin();

        $removed = Db::run('DELETE FROM group_coaches WHERE group_id = ? AND coach_id = ?',
                           array((int) $params['id'], (int) $params['coachId']))->rowCount();
        if (!$removed) {
            not_found('Этот тренер не закреплён за группой');
        }

        Audit::write('unset_coach', 'group', $params['id'], null,
                     array('coach_id' => (int) $params['coachId']));

        Response::items(Dir::coachesOf($params['id']));
    });

    /* =====================================================
       Назначения в группы
       ===================================================== */

    $router->get('/enrollments', function (Request $request) {
        Auth::require_login();

        $athleteId = (int) $request->input('athlete_id', 0);
        if (!$athleteId) {
            bad_request('Укажите athlete_id');
        }
        Auth::require_athlete($athleteId);

        Response::items(Dir::groupsOf($athleteId));
    });

    /**
     * Назначение в группу. Прошлое назначение закрывается
     * датой перехода, а не стирается: иначе осенний отчёт
     * покажет, что ребёнок всегда был в той группе, куда его
     * перевели в мае.
     */
    $router->post('/enrollments', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->int('athlete_id', 'Спортсмен', true, 1)
            ->int('group_id', 'Группа', true, 1)
            ->date('date_from', 'Дата начала', true)
            ->done();

        $athlete = Repo::find('athletes', $data['athlete_id'], 'Спортсмен');
        $group   = Repo::find('training_groups', $data['group_id'], 'Группа');

        // Отчисленного в группу не ставим: иначе он снова
        // окажется в составе, которого быть не должно
        if ($athlete['status'] !== 'active') {
            conflict('athlete_archived',
                'Этот спортсмен отчислен. Сначала верните его в работу');
        }

        // Недавнее назначение в ту же группу: либо он всё ещё
        // в ней — тогда это повтор, либо вернулся — тогда
        // продолжаем прежнее, а не плодим вторую запись
        $edge   = date('Y-m-d', strtotime($data['date_from'] . ' -1 day'));
        $recent = Db::row(
            'SELECT id, date_to FROM enrollments
              WHERE athlete_id = ? AND group_id = ?
                AND (date_to IS NULL OR date_to >= ?)
              ORDER BY date_from DESC LIMIT 1',
            array($athlete['id'], $group['id'], $edge)
        );

        if ($recent && $recent['date_to'] === null) {
            conflict('already_enrolled', 'Спортсмен уже занимается в этой группе');
        }
        if ($recent) {
            Db::begin();

            // Открытые назначения в другие группы закрываем
            // и здесь: иначе человек числился бы сразу в двух
            $closeAt = date('Y-m-d', strtotime($data['date_from'] . ' -1 day'));
            $closed  = Dir::closeOthers($athlete['id'], $recent['id'], $closeAt,
                                        'Вернулся в прежнюю группу');

            Db::update('enrollments', $recent['id'], array('date_to' => null));
            Audit::write('reopen_enrollment', 'enrollment', $recent['id'],
                         array('date_to' => $recent['date_to']), array('date_to' => null),
                         'Вернулся в группу');

            Db::commit();

            Response::ok(array(
                'enrollment_id' => (int) $recent['id'],
                'closed'        => $closed,
                'reopened'      => true,
                'groups'        => Dir::groupsOf($athlete['id']),
            ));
        }

        Db::begin();

        // Закрываем прошлые открытые назначения днём раньше перехода
        $closeAt = date('Y-m-d', strtotime($data['date_from'] . ' -1 day'));
        $closed  = Dir::closeOthers($athlete['id'], null, $closeAt,
                                    'Перевод в другую группу');

        $id = Repo::create('enrollments', array(
            'athlete_id' => $athlete['id'],
            'group_id'   => $group['id'],
            'date_from'  => $data['date_from'],
            'created_by' => Auth::id(),
            'created_at' => Db::now(),
        ), 'enrollment');

        Db::commit();

        Response::ok(array(
            'enrollment_id' => $id,
            'closed'        => $closed,
            'groups'        => Dir::groupsOf($athlete['id']),
        ), 201);
    });

    $router->patch('/enrollments/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $enrollment = Repo::find('enrollments', $params['id'], 'Назначение');

        $data = (new Validate($request->body))
            ->date('date_to', 'Дата окончания', true)
            ->done();

        if ($data['date_to'] < $enrollment['date_from']) {
            invalid(array('date_to' => 'Окончание раньше начала'));
        }

        Repo::save('enrollments', $enrollment['id'], array('date_to' => $data['date_to']),
                   'enrollment', $request->input('reason'));

        Response::items(Dir::groupsOf($enrollment['athlete_id']));
    });

    /* =====================================================
       Площадки
       ===================================================== */

    /**
     * Спорткомплексы вместе с их площадками.
     *
     * Два уровня, потому что занятие проходит не «в здании»,
     * а на конкретном льду или в зале с номером.
     */
    $router->get('/venues', function () {
        Auth::require_login();

        $venues = Db::all('SELECT * FROM venues ORDER BY name');
        $spots  = Db::all('SELECT * FROM spots ORDER BY venue_id, sort, name');

        $byVenue = array();
        foreach ($spots as $spot) {
            $byVenue[(int) $spot['venue_id']][] = array(
                'id'     => (int) $spot['id'],
                'name'   => $spot['name'],
                'kind'   => $spot['kind'],
                'note'   => $spot['note'],
                'status' => $spot['status'],
            );
        }

        $items = array();
        foreach ($venues as $one) {
            $id = (int) $one['id'];
            $items[] = array(
                'id'      => $id,
                'name'    => $one['name'],
                'address' => $one['address'],
                'status'  => $one['status'],
                'spots'   => isset($byVenue[$id]) ? $byVenue[$id] : array(),
            );
        }
        Response::items($items);
    });

    $router->post('/venues', function (Request $request) {
        Auth::require_admin();
        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 190)
            ->text('address', 'Адрес', false, 255)
            ->done();

        $id = Repo::create('venues', array(
            'name'    => $data['name'],
            'address' => $data['address'],
            'status'  => 'active',
        ), 'venue');

        Response::ok(Repo::find('venues', $id), 201);
    });

    $router->patch('/venues/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $venue = Repo::find('venues', $params['id'], 'Спорткомплекс');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 190)
            ->text('address', 'Адрес', false, 255)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) { $changes[$key] = $value; }
        }
        Response::ok(Repo::save('venues', $venue['id'], $changes, 'venue',
                                $request->input('reason')));
    });

    $router->post('/venues/{id}/spots', function (Request $request, $params) {
        Auth::require_admin();
        $venue = Repo::find('venues', $params['id'], 'Спорткомплекс');

        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 120)
            ->one_of('kind', 'Что это', array('ice', 'hall'), true)
            ->text('note', 'Пояснение', false, 255)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->done();

        $twin = Db::value('SELECT id FROM spots WHERE venue_id = ? AND name = ? LIMIT 1',
                          array($venue['id'], $data['name']));
        if ($twin) {
            conflict('spot_exists', 'В этом комплексе уже есть площадка с таким названием');
        }

        $id = Repo::create('spots', array(
            'venue_id'   => $venue['id'],
            'name'       => $data['name'],
            'kind'       => $data['kind'],
            'note'       => $data['note'],
            'sort'       => $data['sort'] === null ? 100 : $data['sort'],
            'status'     => 'active',
            'created_at' => Db::now(),
        ), 'spot');

        Response::ok(Repo::find('spots', $id), 201);
    });

    $router->patch('/spots/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $spot = Repo::find('spots', $params['id'], 'Площадка');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 120)
            ->one_of('kind', 'Что это', array('ice', 'hall'), false)
            ->text('note', 'Пояснение', false, 255)
            ->int('sort', 'Порядок', false, 0, 9999)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        // Площадку, на которой ещё стоят будущие занятия,
        // в архив не убираем: расписание осталось бы без места
        if (isset($data['status']) && $data['status'] === 'archived') {
            $busy = Db::value(
                'SELECT COUNT(*) FROM trainings
                  WHERE spot_id = ? AND date >= ? AND status = ?',
                array($spot['id'], date('Y-m-d'), 'planned'));
            if ($busy) {
                conflict('spot_in_use', 'На этой площадке ещё стоят занятия: ' . $busy
                         . '. Сначала перенесите их или уберите из расписания');
            }
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) { $changes[$key] = $value; }
        }
        Response::ok(Repo::save('spots', $spot['id'], $changes, 'spot',
                                $request->input('reason')));
    });

    /* =====================================================
       Тарифы
       ===================================================== */

    $router->get('/tariffs', function (Request $request) {
        Auth::require_admin();

        $status = $request->input('status', 'active');
        $where  = $status === 'all' ? '' : ' WHERE status = ?';
        $params = $status === 'all' ? array() : array($status);

        $rows = Db::all('SELECT * FROM tariffs' . $where . ' ORDER BY name', $params);

        $items = array();
        foreach ($rows as $row) {
            $row['in_use'] = (bool) Db::value(
                'SELECT 1 FROM subscriptions WHERE tariff_id = ? LIMIT 1', array($row['id'])
            );
            $row['lesson_price'] = empty($row['sessions_count']) ? null
                : number_format($row['price'] / $row['sessions_count'], 2, '.', '');
            $items[] = $row;
        }
        Response::items($items);
    });

    $router->post('/tariffs', function (Request $request) {
        Auth::require_admin();

        $data = (new Validate($request->body))
            ->text('name', 'Название', true, 190)
            ->int('price', 'Цена', true, 0, 1000000)
            ->int('sessions_count', 'Занятий в месяц', false, 1, 200)
            ->int('days_per_week', 'Занятий в неделю', false, 1, 7)
            ->text('kinds', 'Что входит', false, 190)
            ->done();

        $id = Repo::create('tariffs', array(
            'name'           => $data['name'],
            'price'          => $data['price'],
            'sessions_count' => $data['sessions_count'],
            'days_per_week'  => $data['days_per_week'],
            'kinds'          => $data['kinds'],
            'status'         => 'active',
            'created_by'     => Auth::id(),
            'created_at'     => Db::now(),
        ), 'tariff');

        Response::ok(Repo::find('tariffs', $id), 201);
    });

    /**
     * Правка тарифа. Если по нему уже выданы абонементы,
     * цену и число занятий менять нельзя: прошлый отчёт
     * пересчитается по сегодняшним цифрам, а у родителя
     * в кабинете вырастет сумма давно оплаченного абонемента.
     * Для таких изменений есть /tariffs/{id}/revise.
     */
    $router->patch('/tariffs/{id}', function (Request $request, $params) {
        Auth::require_admin();
        $tariff = Repo::find('tariffs', $params['id'], 'Тариф');

        $data = (new Validate($request->body))
            ->text('name', 'Название', 'present', 190)
            ->int('price', 'Цена', false, 0, 1000000)
            ->int('sessions_count', 'Занятий в месяц', false, 1, 200)
            ->int('days_per_week', 'Занятий в неделю', false, 1, 7)
            ->text('kinds', 'Что входит', false, 190)
            ->one_of('status', 'Состояние', array('active', 'archived'), false)
            ->done();

        $inUse = (bool) Db::value('SELECT 1 FROM subscriptions WHERE tariff_id = ? LIMIT 1',
                                  array($tariff['id']));

        if ($inUse) {
            foreach (array('price', 'sessions_count', 'days_per_week') as $field) {
                // Сравниваем числа, а не строки: в базе цена
                // лежит как 14400.00, из формы приходит 14400,
                // и строковое сравнение запрещало правку,
                // в которой цена вовсе не менялась
                if ($data[$field] !== null
                    && abs((float) $data[$field] - (float) $tariff[$field]) > 0.004) {
                    conflict('tariff_in_use',
                        'По этому тарифу уже выданы абонементы, цену и число занятий менять нельзя. '
                        . 'Создайте новую версию тарифа');
                }
            }
        }

        $changes = array();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $changes[$key] = $value;
            }
        }

        Response::ok(Repo::save('tariffs', $tariff['id'], $changes, 'tariff',
                                $request->input('reason')));
    });

    /** Новая версия тарифа: старая уходит в архив. */
    $router->post('/tariffs/{id}/revise', function (Request $request, $params) {
        Auth::require_admin();
        $tariff = Repo::find('tariffs', $params['id'], 'Тариф');

        $data = (new Validate($request->body))
            ->int('price', 'Цена', true, 0, 1000000)
            ->int('sessions_count', 'Занятий в месяц', false, 1, 200)
            ->int('days_per_week', 'Занятий в неделю', false, 1, 7)
            ->text('name', 'Название', false, 190)
            ->text('reason', 'Основание', false, 255)
            ->done();

        Db::begin();

        $id = Repo::create('tariffs', array(
            'name'           => $data['name'] === null ? $tariff['name'] : $data['name'],
            'price'          => $data['price'],
            'sessions_count' => $data['sessions_count'] === null
                                ? $tariff['sessions_count'] : $data['sessions_count'],
            'days_per_week'  => $data['days_per_week'] === null
                                ? $tariff['days_per_week'] : $data['days_per_week'],
            'kinds'          => $tariff['kinds'],
            'status'         => 'active',
            'created_by'     => Auth::id(),
            'created_at'     => Db::now(),
        ), 'tariff', $data['reason']);

        Repo::save('tariffs', $tariff['id'], array('status' => 'archived'), 'tariff',
                   'Заменён версией № ' . $id);

        // Что входило в тариф, входит и в новую версию:
        // иначе смена цены молча убирала бы из абонемента
        // зал и хореографию
        $extras = Db::all('SELECT extra_class_id FROM tariff_extras WHERE tariff_id = ?',
                          array($tariff['id']));
        foreach ($extras as $one) {
            Db::insert('tariff_extras', array(
                'tariff_id'      => $id,
                'extra_class_id' => $one['extra_class_id'],
            ));
        }

        // Группы, которые ссылались на старый тариф, переводим на новый
        Db::run('UPDATE training_groups SET tariff_id = ? WHERE tariff_id = ?',
                array($id, $tariff['id']));

        Db::commit();

        Response::ok(Repo::find('tariffs', $id), 201);
    });

    /* =====================================================
       Настройки
       ===================================================== */

    $router->get('/settings', function () {
        Auth::require_admin();
        Response::items(Db::all('SELECT name, value, note FROM settings ORDER BY name'));
    });

    $router->patch('/settings', function (Request $request) {
        Auth::require_admin();

        $body = is_array($request->body) ? $request->body : array();
        $known = array();
        foreach (Db::all('SELECT name FROM settings') as $row) {
            $known[$row['name']] = true;
        }

        $saved = array();
        foreach ($body as $name => $value) {
            if (!isset($known[$name]) || $name === 'reason') {
                continue;
            }
            if (!is_scalar($value) || trim((string) $value) === '') {
                invalid(array($name => 'Пустое значение не сохраняется'));
            }

            $before = Db::value('SELECT value FROM settings WHERE name = ?', array($name));
            if ((string) $before === (string) $value) {
                continue;
            }

            Db::run('UPDATE settings SET value = ?, updated_by = ?, updated_at = ? WHERE name = ?',
                    array((string) $value, Auth::id(), Db::now(), $name));
            Audit::write('update', 'setting', null, array($name => $before),
                         array($name => $value), $request->input('reason'));
            $saved[] = $name;
        }

        Settings::forget();
        Response::ok(array('saved' => $saved));
    });
};
