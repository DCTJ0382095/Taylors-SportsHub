<?php
// The controller supplies $formUrl, $csrfToken, $input and an optional $error.
$escapeStudentForm = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<main class="student-event-form">
    <h1>Host a sports event</h1>
    <p>Student-hosted events are free.</p>
    <?php if (!empty($error)): ?>
        <p role="alert"><?= $escapeStudentForm($error) ?></p>
    <?php endif; ?>
    <form action="<?= $escapeStudentForm($formUrl) ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $escapeStudentForm($csrfToken) ?>">
        <?php foreach (['title' => ['Event title', 150], 'sport' => ['Sport', 80], 'location' => ['Venue', 150]] as $field => [$label, $max]): ?>
            <label><?= $label ?>
                <input name="<?= $field ?>" maxlength="<?= $max ?>" value="<?= $escapeStudentForm($input[$field] ?? '') ?>" required>
            </label>
        <?php endforeach; ?>
        <label>Starts
            <input type="datetime-local" name="starts_at" value="<?= $escapeStudentForm($input['starts_at'] ?? '') ?>" required>
        </label>
        <label>Ends
            <input type="datetime-local" name="ends_at" value="<?= $escapeStudentForm($input['ends_at'] ?? '') ?>">
        </label>
        <label>Description <textarea name="description"><?= $escapeStudentForm($input['description'] ?? '') ?></textarea></label>
        <label>Cover image <input type="file" name="cover_image" accept="image/jpeg,image/png"></label>
        <label>Participation limit
            <input type="number" name="capacity" min="1" max="2147483647" value="<?= $escapeStudentForm($input['capacity'] ?? '') ?>">
        </label>
        <p>Leave the participation limit blank for unlimited places.</p>
        <label>
            <input type="checkbox" name="allow_guests" value="1" <?= ($input['allow_guests'] ?? '') === '1' ? 'checked' : '' ?>>
            Allow guests
        </label>
        <button type="submit">Publish event</button>
    </form>
</main>
