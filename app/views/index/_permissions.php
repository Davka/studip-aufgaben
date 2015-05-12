<form id="edit-permissions-form" data-task-user-id="<?= $task_user->id ?>">
    <label for="permissons">
        <span>Zugriff gew�hren</span>
    </label>

    <div id="permission_list">

    </div>

    <div class="three-columns clearfix" id="permissions">
        <div>
            <input name="search" data-placeholder="<?= _('Nach Vorname und/oder Nachname suchen...') ?>" style="width: 80%">
            <br>
            <span class="error" style="display: none;">
            </span>
        </div>

        <div>
            <select name="permission" data-placeholder="<?= _('Berechtigung w�hlen') ?>" style="width: 80%">
                <? foreach ($permissions as $perm => $name) : ?>
                <option value="<?= $perm ?>"><?= $name ?></option>
                <? endforeach ?>
            </select>
            <?= tooltipIcon(_('Kommilitone/in: Kann die komplette Aufgabe einsehen und �ndern')) ?>
        </div>

        <div>
            <?= \Studip\LinkButton::createAccept(_('Berechtigung hinzuf�gen'), 'javascript:', array('id' => 'add-permission')) ?>
        </div>
    </div>
</form>