<?php
// The controller passes $activities from getMyActivities() for the signed-in user.
$escapeActivity = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<main class="my-activities">
    <h1>My Activities</h1>
    <section>
        <h2>My sign-ups</h2>
        <?php if ($activities['signups'] === []): ?>
            <p>You haven’t signed up for any events.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($activities['signups'] as $signup): ?>
                    <li>
                        <strong><?= $escapeActivity($signup['title']) ?></strong>
                        <span><?= $escapeActivity($signup['starts_at']) ?></span>
                        <span><?= $signup['event_status'] === 'cancelled' ? 'Event cancelled' : $escapeActivity(ucfirst($signup['status'])) ?></span>
                        <?php if ($signup['team_name'] !== null): ?>
                            <span>Team <?= $escapeActivity($signup['team_name']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section>
        <h2>My memberships</h2>
        <?php if ($activities['memberships'] === []): ?>
            <p>You haven’t applied for any memberships.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($activities['memberships'] as $membership): ?>
                    <li>
                        <strong><?= $escapeActivity($membership['club_name']) ?></strong>
                        <span><?= $membership['is_active'] ? 'Active' : $escapeActivity(ucfirst($membership['status'])) ?></span>
                        <?php if ($membership['expires_at'] !== null): ?>
                            <span>Expiry <?= $escapeActivity($membership['expires_at']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section>
        <h2>Events I’m hosting</h2>
        <?php if ($activities['hosted_events'] === []): ?>
            <p>You haven’t hosted any events.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($activities['hosted_events'] as $event): ?>
                    <li><?= $escapeActivity($event['title']) ?> · <?= $escapeActivity($event['starts_at']) ?> · <?= $escapeActivity(ucfirst($event['status'])) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section>
        <h2>Clubs I manage</h2>
        <?php if ($activities['managed_clubs'] === []): ?>
            <p>You don’t manage any clubs.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($activities['managed_clubs'] as $club): ?>
                    <li><?= $escapeActivity($club['name']) ?> · <?= $club['is_president'] ? 'President' : 'Committee' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
