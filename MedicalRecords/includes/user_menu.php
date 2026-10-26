<form class="user-menu" method="post" action="logout.php">
    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
    <span class="user-menu-name"><i class="bi bi-person-circle"></i> <?= escape($currentUser['display_name']) ?></span>
    <a class="btn btn-sm btn-outline-secondary member-action-button" href="activity.php"><i class="bi bi-clock-history"></i><span>Activity</span></a>
    <a class="btn btn-sm btn-outline-secondary member-action-button" href="settings.php"><i class="bi bi-gear"></i><span>Settings</span></a>
    <button class="btn btn-sm btn-outline-secondary member-action-button" type="submit">
        <i class="bi bi-box-arrow-right"></i><span>Sign out</span>
    </button>
</form>
