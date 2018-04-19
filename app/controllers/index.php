<?php

/**
 * IndexController - main controller for the plugin
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 3 of
 * the License, or (at your option) any later version.
 * @author      Till Glöggler <tgloeggl@uos.de>
 * @author      Ramus Fuhse <fuhse@data-quest.de>
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GPL version 3
 * @category    Stud.IP
 */
class IndexController extends \EPP\Controller
{
    function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);

        // set default layout
        $this->set_layout('layouts/layout');
        Navigation::activateItem('/course/aufgaben');

        $this->seminar_id = $this->getSeminarId();

        $this->permissions = [
            'student' => _('Kommilitone/in'),
        ];

        // set up hidden folder for files to store (if not already present)
        $aufgaben_folder = null;

        $root_folder = \Folder::findTopFolder($this->seminar_id)->getTypedFolder();
        foreach ($root_folder->subfolders as $folder) {
            if ($folder['data_content']['aufgabenplugin']) {
                $aufgaben_folder = $folder;
            }
        }

        if (!$aufgaben_folder) {
            $aufgaben_folder = \Folder::create([
                'parent_id'    => $root_folder->getId(),
                'range_id'     => $this->seminar_id,
                'range_type'   => Context::getType(),
                'description'  => 'Dateiablage des Aufgabenplugins',
                'name'         => 'Aufgaben-Plugin',
                'data_content' => ['aufgabenplugin' => '1'],
                'folder_type'  => 'TaskFolder',
                'user_id'      => $this->seminar_id
            ]);
        }

        $this->folder = $aufgaben_folder->getTypedFolder();
    }

    public function index_action()
    {
        if (!Request::option('sort_by')
            || in_array(Request::option('sort_by'), words('title startdate enddate')) === false
        ) {
            $this->sort  = 'enddate';
            $this->order = 'desc';
        } else {
            $this->sort  = Request::option('sort_by');
            $this->order = Request::option('asc') ? 'asc' : 'desc';
        }

        if (EPP\Perm::has('new_task', $this->seminar_id)) {
            $this->tasks = EPP\Tasks::findBySQL("seminar_id = ?
                ORDER BY {$this->sort} {$this->order}, startdate DESC", [$this->seminar_id]);
        } else {
            $this->tasks = EPP\Tasks::findBySQL("seminar_id = ? /* AND startdate <= UNIX_TIMESTAMP() */
                ORDER BY {$this->sort} {$this->order}, startdate DESC", [$this->seminar_id]);

            // reorder all running tasks if necessary - the task with the shortest time frame shall be first
            if ($this->sort == 'enddate') {
                foreach ($this->tasks as $task) {
                    $reorder[$task->getStatus()][] = $task;
                }

                if (is_array($reorder['running'])) {
                    $reorder['running'] = array_reverse($reorder['running']);
                }

                $new_order = [];

                foreach (words('future running past') as $status) {
                    if (!empty($reorder[$status])) {
                        $new_order = array_merge($new_order, $reorder[$status]);
                    }
                }

                $this->tasks = $new_order;
            }
        }

        $this->accessible_tasks = EPP\Helper::getForeignTasksForUser($GLOBALS['user']->id);

        if (EPP\Perm::has('new_task', $this->seminar_id)) {
            $actions = new ActionsWidget();
            $actions->addLink(
                _('Neue Aufgabe anlegen'),
                $this->url_for('index/new_task'),
                Icon::create('add')
            )->asDialog('size=50%');

            Sidebar::Get()->addWidget($actions);
        }
    }

    public function new_task_action()
    {
        PageLayout::setTitle(_('Neue Aufgabe anlegen'));
        EPP\Perm::check('new_task', $this->seminar_id);

        $this->destination = 'index/add_task';

        if (Request::isXHR()) {
            $this->render_template('index/edit_task', null);
        } else {
            $this->render_template(
                'index/edit_task',
                $GLOBALS['template_factory']->open('layouts/base')
            );
        }

    }

    public function add_task_action()
    {
        EPP\Perm::check('new_task', $this->seminar_id);

        $data = [
            'seminar_id'  => $this->seminar_id,
            'user_id'     => $GLOBALS['user']->id,
            'title'       => Request::get('title'),
            'content'     => Request::get('content'),
            'allow_text'  => Request::int('allow_text'),
            'allow_files' => Request::int('allow_files'),
            'startdate'   => strtotime(Request::get('startdate')),
            'enddate'     => strtotime(Request::get('enddate')),
            'send_mail'   => Request::int('send_mail'),
        ];

        if (\EPP\Tasks::create($data)) {
            PageLayout::postSuccess(sprintf(_('Die Aufgabe %s wurde erfolgreich angelegt!'), Request::get('title')));
        } else {
            PageLayout::postError(_('Beim Anlegen der Aufgabe ist etwas schief gelaufen.
            Versuchen Sie es noch einmal oder wenden Sie sich an einen Systemadministrator'));
        }

        $this->redirect('index/index');
    }

    public function update_task_action($id)
    {
        CSRFProtection::verifyUnsafeRequest();
        EPP\Perm::check('new_task', $this->seminar_id);
        $task = new EPP\Tasks($id);

        if ($task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        $data = [
            'seminar_id'  => $this->seminar_id,
            'user_id'     => $GLOBALS['user']->id,
            'title'       => Request::get('title'),
            'content'     => Request::get('content'),
            'allow_text'  => Request::int('allow_text'),
            'allow_files' => Request::int('allow_files'),
            'startdate'   => strtotime(Request::get('startdate')),
            'enddate'     => strtotime(Request::get('enddate')),
            'send_mail'   => Request::int('send_mail'),
        ];

        $task->setData($data);
        if ($task->store()) {
            PageLayout::postSuccess(sprintf(_('Die Aufgabe %s wurde erfolgreich bearbeitet!'), Request::get('title')));
        } else {
            PageLayout::postError(_('Beim Bearbeiten der Aufgabe ist etwas schief gelaufen.
            Versuchen Sie es noch einmal oder wenden Sie sich an einen Systemadministrator'));
        }

        $this->redirect('index/view_task/' . $id);
    }

    public function delete_task_action($id)
    {
        EPP\Perm::check('new_task', $this->seminar_id);

        $task = new EPP\Tasks($id);

        if ($task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        $task->delete();
        $this->redirect('index/index');
    }

    public function edit_task_action($id)
    {
        PageLayout::setTitle(_('Aufgabe bearbeiten'));
        EPP\Perm::check('new_task', $this->seminar_id);

        $this->task = new EPP\Tasks($id);

        if ($this->task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        $this->destination = 'index/update_task/' . $id;
        $this->render_template('index/edit_task', null);
    }

    public function view_dozent_action($task_user_id, $edit_field = null)
    {
        EPP\Perm::check('new_task', $this->seminar_id);

        // if the second parameter is present, the passed field shall be edited
        if ($edit_field) {
            $this->edit[$edit_field] = true;
        }

        $this->task_user = new \EPP\TaskUsers($task_user_id);
        $this->task      = new \EPP\Tasks($this->task_user->ep_tasks_id);

        if ($this->task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }
    }

    public function update_dozent_action($task_user_id)
    {
        EPP\Perm::check('new_task', $this->seminar_id);

        $task_user = new \EPP\TaskUsers($task_user_id);
        $task      = new \EPP\Tasks($task_user->ep_tasks_id);

        if ($task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        if (Request::get('feedback') !== null && $task->startdate <= time()) {
            $task_user->feedback = Request::get('feedback');
            $task_user->store();
        } else if (Request::get('hint') !== null && $task->startdate > time()) {
            $task_user->hint = Request::get('hint');
            $task_user->store();
        }

        $this->redirect('index/view_dozent/' . $task_user_id);
    }


    public function view_task_action($id)
    {
        EPP\Perm::check('new_task', $this->seminar_id);

        $this->task         = new EPP\Tasks($id);
        $this->participants = CourseMember::findByCourse($this->seminar_id);

        if ($this->task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }
        $actions = new ActionsWidget();
        $actions->addLink(
            _('Aufgabe Bearbeiten'),
            $this->url_for('index/edit_task/' . $id),
            Icon::create('edit')
        )->asDialog('size=50%');
        $actions->addLink(
            _('Aufgabe Löschen'),
            $this->url_for('index/delete_task/' . $id),
            Icon::create('trash'),
            ['data-confirm' => _('Sind Sie sicher, dass Sie die komplette Aufgabe löschen möchten?')]
        );

        Sidebar::Get()->addWidget($actions);

        $infos = new SidebarWidget();
        $infos->setTitle(_('Legende'));
        $infos->addElement(
            new WidgetElement(
                sprintf('%s' . _('Aufgabe bearbeitbar: <br>%s - %s Uhr'),
                    Icon::create('date'),
                    strftime($this->timeformat, $this->task['startdate']),
                    strftime($this->timeformat, $this->task['enddate']))
            )
        );

        if ($this->task->allow_text && $this->task->allow_files) {
            $infos->addElement(
                new WidgetElement(sprintf('<hr>%s %s', Icon::create('info-circle'), _('Texteingabe und Dateiupload erlaubt')))
            );
        } else if ($this->task->allow_text) {
            $infos->addElement(
                new WidgetElement(sprintf('<hr>%s %s', Icon::create('file-text+add'), _('Texteingabe erlaubt')))
            );
        } else if ($this->task->allow_files) {
            $infos->addElement(
                new WidgetElement(sprintf('<hr>%s %s', Icon::create('upload'), _('Dateiupload erlaubt')))
            );
        }

        Sidebar::Get()->addWidget($infos);
    }

    public function view_student_action($id, $edit_field = null)
    {
        // if the second parameter is present, the passed field shall be edited
        if ($edit_field) {
            $this->edit[$edit_field] = true;
        }

        $this->task = new EPP\Tasks($id);

        if ($this->task->startdate > time() || $this->task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        if ($task_user_id = Request::get('task_user_id')) {

            $this->task_user    = EPP\TaskUsers::find($task_user_id);
            $this->task_user_id = $task_user_id;
        } else {
            $this->task_user = $this->task->task_users->findOneBy('user_id', $GLOBALS['user']->id);
        }

        if (!$this->task_user) {
            $data = [
                'ep_tasks_id' => $id,
                'user_id'     => $GLOBALS['user']->id
            ];

            $this->task_user = EPP\TaskUsers::create($data);
        }

        $this->perms = EPP\Perm::get($GLOBALS['user']->id, $this->task_user);

        if (!$this->perms['edit_answer']) {
            throw new AccessDeniedException(_('Sie haben keine Rechte zum Bearbeiten dieser Aufgabe.'));
        }
    }

    public function update_student_action($task_id, $task_user_id)
    {
        $task = new EPP\Tasks($task_id);

        if ($task->startdate > time() || $task->enddate < time()) {
            throw new AccessDeniedException(_('Sie dürfen diese Aufgabe nicht bearbeiten!'));
        }

        if ($task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        $data = [
            'ep_tasks_id' => $task_id,
            'answer'      => Request::get('answer')
        ];

        $task_user = new EPP\TaskUsers($task_user_id);
        $task_user->setData($data);
        $task_user->store();

        if ($task_user->user_id != $GLOBALS['user']->id) {
            $this->redirect('index/view_student/' . $task_id . '?task_user_id=' . $task_user_id);
        } else {
            $this->redirect('index/view_student/' . $task_id);
        }
    }

    public function set_ready_action($task_id)
    {
        $task = new EPP\Tasks($task_id);

        if ($task->startdate > time() || $task->enddate < time()) {
            throw new AccessDeniedException(_('Sie dürfen diese Aufgabe nicht bearbeiten!'));
        }

        if ($task->seminar_id != $this->seminar_id) {
            throw new AccessDeniedException(_('Die Aufgabe wurde nicht gefunden!'));
        }

        $task_user        = reset(\EPP\TaskUsers::findBySQL('user_id = ? AND ep_tasks_id = ?', [$GLOBALS['user']->id, $task->getId()]));
        $task_user->ready = 1;
        $task_user->store();

        $this->redirect('index/view_student/' . $task->getId());
    }

    /**
     * add a permission for an user-instance of a task
     * @param int $task_user_id
     */
    public function add_permission_action($task_user_id)
    {
        $this->render_nothing();

        $current_user_id = $GLOBALS['user']->id;
        ## $task_user = EPP\TaskUsers::find($task_user_id);
        $task_user = new \EPP\TaskUsers($task_user_id);

        $perms = EPP\Perm::get($current_user_id, $task_user);
        if (!$perms['edit_settings']) {
            throw new AccessDeniedException();
        }

        $user_id = get_userid(Request::get('user'));

        // the user ist not allowed to store a perm for himself
        if ($user_id == $current_user_id) {
            $this->response->set_status(400, _('Sie dürfen sich nicht selbst für eine Berechtigung eintragen!'));
            return;
        }

        // check that the submitted user has not another perm already
        foreach ($task_user->perms as $key => $perm) {
            if ($perm->user_id == $user_id) {
                $this->response->set_status(400, _('Für diesen Nutzer existiert bereits eine andere Berechtigung!'));
                return;
            }
        }

        $perm = new EPP\Permissions();

        // add new permission entry
        $perm->setData([
            'user_id' => $user_id,
            'role'    => Request::option('perm')
        ]);

        $task_user->perms[] = $perm;

        $task_user->store();
    }

    /**
     * delete a permission for an user-instance of a task
     * @param int $task_user_id
     */
    public function delete_permission_action($task_user_id)
    {
        $current_user_id = $GLOBALS['user']->id;
        $task_user       = EPP\TaskUsers::find($task_user_id);

        $perms = EPP\Perm::get($current_user_id, $task_user);
        if (!$perms['edit_settings']) {
            throw new AccessDeniedException();
        }

        $user_id = get_userid(Request::get('user'));

        foreach ($task_user->perms as $key => $perm) {
            if ($perm->user_id == $user_id) {
                unset($task_user->perms[$key]);
            }
        }

        $task_user->store();

        $this->render_nothing();
    }

    /**
     * create a pdf from all answers to the solutions of the submitted task
     * @param int $task_id
     */
    public function pdf_action($task_id)
    {
        $task = new EPP\Tasks($task_id);
        $this->render_nothing();
        $document = new ExportPDF();
        $document->SetTitle($task->title);
        $document->setHeaderTitle($task->title);
        foreach ($task->task_users as $task_user) {
            if ($task_user->answer) {
                $document->addPage();
                $content  = sprintf('<h1>Antwort von %s</h1>', get_fullname($task_user->user_id));
                $content  .= sprintf('<p><strong>Username:</strong> %s</p>', get_username($task_user->user_id));
                $matrikel = $this->getMatrikel($task_user->user_id);

                if ($matrikel) {
                    $content .= sprintf('<p><strong>Matrikelnummer:</strong> %s</p>', $matrikel);
                }
                $content .= sprintf('<p>%s</p>', formatReady($task_user->answer));
                $document->writeHTMLCell(0, 0, '', '', $content, 0, 1, 0, true, '', true);
            }
        }
        $pdf_name = prepareFilename($task->title . '-' . _('Abgaben der Studierenden') . '.pdf');
        $document->Output($pdf_name, 'D');
    }

    /**
     * Returns the matriculation number if exists
     * @param $user_id
     * @return string
     */
    protected function getMatrikel($user_id)
    {
        return DBManager::get()->fetchColumn("SELECT DISTINCT `content` FROM `datafields` df
            JOIN `datafields_entries` dfe ON dfe.`datafield_id` = df.`datafield_id`
            where lower(`name`) LIKE '%matrikel%' AND dfe.`range_id` = ?", [$user_id]);
    }

    /**
     * create a zip from all files attached to the solutions of the submitted task
     * @param int $task_id
     */
    public function zip_action($task_id)
    {
        $task = new EPP\Tasks($task_id);
        // create zip
        $file_ids = [];

        foreach ($task->task_users as $tu) {
            foreach ($tu->files as $file) {
                $file_ids[] = $file->document->id;
            }
        }

        $zip_file_id = createSelectedZip($file_ids, false, false);

        if ($zip_file_id) {
            $zip_name = prepareFilename($task->title . '-' . _('Abgaben der Studierenden') . '.zip');
            header('Location: ' . getDownloadLink($zip_file_id, $zip_name, 4));
            page_close();
            die;
        }

        throw new Exception('could not create zip file');
    }
}
