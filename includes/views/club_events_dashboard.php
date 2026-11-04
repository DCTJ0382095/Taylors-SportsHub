<?php
// Render after the controller verifies event-management permission.
// $events comes from listClubEvents(). The controller supplies $club, $csrfToken,
// $dashboardUrl, and $editorUrl. The dashboard POST handler verifies the token
// and dispatches the action for the authorized club.
$escapeEventDashboard = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$editClubEventUrl = static function (?int $eventId = null) use ($editorUrl, $club): string {
    $query = ['club_id' => (int) $club['id']];
    if ($eventId !== null) {
        $query['event_id'] = $eventId;
    }

    return $editorUrl . '?' . http_build_query($query);
};
?>
<main class="club-events">
    <header>
        <p><?= $escapeEventDashboard($club['name']) ?></p>
        <h1>Events</h1>
        <a href="<?= $escapeEventDashboard($editClubEventUrl()) ?>">Create event</a>
    </header>
    <?php if (!empty($error)): ?>
        <p role="alert"><?= $escapeEventDashboard($error) ?></p>
    <?php endif; ?>
    <?php if ($events === []): ?>
        <p>This club has no events yet.</p>
    <?php else: ?>
        <table>
            <caption>Club events</caption>
            <thead>
                <tr>
                    <th scope="col">Event</th>
                    <th scope="col">Starts</th>
                    <th scope="col">Limit</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <th scope="row">
                            <?= $escapeEventDashboard($event['title']) ?>
                            <small><?= $escapeEventDashboard(ucfirst($event['event_type'])) ?></small>
                        </th>
                        <td><?= $escapeEventDashboard($event['starts_at']) ?></td>
                        <td>
                            <?php if ($event['max_teams'] !== null): ?>
                                <?= (int) $event['max_teams'] ?> teams
                            <?php elseif ($event['capacity'] !== null): ?>
                                <?= (int) $event['capacity'] ?> people
                            <?php else: ?>
                                Unlimited
                            <?php endif; ?>
                        </td>
                        <td><?= $escapeEventDashboard(ucfirst($event['status'])) ?></td>
                        <td>
                            <?php if ($event['status'] !== 'cancelled'): ?>
                                <a href="<?= $escapeEventDashboard($editClubEventUrl((int) $event['id'])) ?>">Edit</a>
                                <form method="post" action="<?= $escapeEventDashboard($dashboardUrl) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $escapeEventDashboard($csrfToken) ?>">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                    <?php if ($event['status'] === 'published'): ?>
                                        <button type="submit" name="action" value="repeat">Repeat next week</button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="cancel">Cancel event</button>
                                </form>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
