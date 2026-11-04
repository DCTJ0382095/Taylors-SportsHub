<?php
// The authenticated controller supplies $event, $editorUrl, $dashboardUrl,
// $csrfToken and $error. $editorUrl includes the authorized club and event IDs.
$escapeEventEditor = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$eventValue = static function (string $field, string $default = '') use ($event, $escapeEventEditor): string {
    return $escapeEventEditor($event[$field] ?? $default);
};
$eventDateValue = static function (string $field) use ($event, $escapeEventEditor): string {
    return $escapeEventEditor(str_replace(' ', 'T', substr($event[$field] ?? '', 0, 16)));
};
$eventType = $event['event_type'] ?? 'session';
$audience = $event['audience'] ?? 'open';
$isTeam = ($event['tournament_format'] ?? null) === 'team'
    || (!isset($event['tournament_format']) && isset($event['min_team_size']));
?>
<main class="club-event-editor">
    <h1><?= isset($event['id']) ? 'Edit club event' : 'Create club event' ?></h1>
    <?php if (!empty($error)): ?>
        <p role="alert"><?= $escapeEventEditor($error) ?></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= $escapeEventEditor($editorUrl) ?>">
        <input type="hidden" name="csrf_token" value="<?= $escapeEventEditor($csrfToken) ?>">
        <fieldset>
            <legend>Basics</legend>
            <label>Event type
                <select name="event_type">
                    <option value="session" <?= $eventType === 'session' ? 'selected' : '' ?>>Session</option>
                    <option value="tournament" <?= $eventType === 'tournament' ? 'selected' : '' ?>>Tournament</option>
                </select>
            </label>
            <label>Event title <input name="title" maxlength="150" value="<?= $eventValue('title') ?>" required></label>
            <label>Sport <input name="sport" maxlength="80" value="<?= $eventValue('sport') ?>" required></label>
            <label>Venue <input name="location" maxlength="150" value="<?= $eventValue('location') ?>" required></label>
            <label>Starts <input type="datetime-local" name="starts_at" value="<?= $eventDateValue('starts_at') ?>" required></label>
            <label>Ends <input type="datetime-local" name="ends_at" value="<?= $eventDateValue('ends_at') ?>"></label>
            <label>Description <textarea name="description"><?= $eventValue('description') ?></textarea></label>
            <label>Cover image <input type="file" name="cover_image" accept="image/jpeg,image/png"></label>
            <label>Individual participation limit
                <input type="number" name="capacity" min="1" max="2147483647" value="<?= $eventValue('capacity') ?>">
            </label>
            <p>Leave blank for unlimited places. Pending sign-ups count toward the limit.</p>
        </fieldset>
        <fieldset>
            <legend>Who can join</legend>
            <label><input type="radio" name="audience" value="open" <?= $audience === 'open' ? 'checked' : '' ?>> All Taylor’s students</label>
            <label><input type="radio" name="audience" value="members_only" <?= $audience === 'members_only' ? 'checked' : '' ?>> Members only</label>
            <label><input type="checkbox" name="allow_guests" value="1" <?= !empty($event['allow_guests']) ? 'checked' : '' ?>> Allow guests</label>
        </fieldset>
        <fieldset>
            <legend>Individual pricing</legend>
            <?php foreach (['student_price' => 'Student', 'member_price' => 'Member', 'guest_price' => 'Guest'] as $field => $label): ?>
                <label><?= $label ?> price (RM)
                    <input type="number" name="<?= $field ?>" min="0" max="999999.99" step="0.01" value="<?= $eventValue($field, '0.00') ?>">
                </label>
            <?php endforeach; ?>
        </fieldset>
        <fieldset>
            <legend>Tournament settings</legend>
            <label>Format
                <select name="tournament_format">
                    <option value="individual" <?= !$isTeam ? 'selected' : '' ?>>Individual</option>
                    <option value="team" <?= $isTeam ? 'selected' : '' ?>>Team</option>
                </select>
            </label>
            <p>For team tournaments, enter the roster range, team limit, and flat fee paid by the captain.</p>
            <label>Minimum team size <input type="number" name="min_team_size" min="2" max="65535" value="<?= $eventValue('min_team_size') ?>"></label>
            <label>Maximum team size <input type="number" name="max_team_size" min="2" max="65535" value="<?= $eventValue('max_team_size') ?>"></label>
            <label>Maximum teams <input type="number" name="max_teams" min="1" max="2147483647" value="<?= $eventValue('max_teams') ?>"></label>
            <label>Team fee (RM) <input type="number" name="team_fee" min="0" max="999999.99" step="0.01" value="<?= $eventValue('team_fee', '0.00') ?>"></label>
            <label>Registration closes <input type="datetime-local" name="registration_closes_at" value="<?= $eventDateValue('registration_closes_at') ?>"></label>
        </fieldset>
        <button type="submit" name="status" value="published">
            <?= ($event['status'] ?? '') === 'published' ? 'Save changes' : 'Publish event' ?>
        </button>
        <?php if (($event['status'] ?? '') !== 'published'): ?>
            <button type="submit" name="status" value="draft">Save as draft</button>
        <?php endif; ?>
        <a href="<?= $escapeEventEditor($dashboardUrl) ?>">Cancel</a>
    </form>
</main>
