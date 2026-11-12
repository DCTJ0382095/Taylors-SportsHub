<?php
// My Memberships: every club you're a member of (or have applied to), with its status and expiry.
// Clubs where you're on the committee are included, because committee members always count as members.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// Clubs where you're on the committee.
$stmt = $db->prepare("SELECT c.id AS club_id, c.name, c.logo_path, cm.role
                      FROM club_managers cm
                      INNER JOIN clubs c ON c.id = cm.club_id
                      WHERE cm.user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$cards = [];
foreach ($stmt->fetchAll() as $row) {
    $row['state'] = 'committee';
    $cards[$row['club_id']] = $row;     // keyed by club id, so each club only gets one card
}

// Your paid memberships and applications. Today's club settings come too,
// because a renewal is charged at today's price and length.
$stmt = $db->prepare("SELECT m.id, m.club_id, m.status, m.price_snapshot, m.months_snapshot, m.expires_at,
                             c.name, c.logo_path, c.membership_open, c.membership_price, c.membership_months
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
foreach ($stmt->fetchAll() as $row) {
    if (isset($cards[$row['club_id']])) {
        continue;                       // being on the committee already counts as membership
    }
    $row['state'] = membership_state($row);
    $cards[$row['club_id']] = $row;
}

// A to Z by club name.
usort($cards, fn($a, $b) => strcmp($a['name'], $b['name']));

$page_title = 'My Memberships';
require_once __DIR__ . '/includes/header.php';
?>
<div class="memberships-page">
    <header class="memberships-header">
        <div class="memberships-eyebrow">SPORTSHUB / CLUBS</div>
        <h1 class="memberships-title">My Memberships</h1>
        <p class="memberships-subtitle">The clubs you belong to, when each membership ends, and renewals.</p>
    </header>

    <?php if (empty($cards)): ?>
        <section class="memberships-empty">
            <h2>You're not a member of any club yet</h2>
            <p>Open a club's page and choose Apply for membership.</p>
            <a class="membership-tile-btn primary" href="<?= app_url('/clubs.php') ?>">Browse clubs</a>
        </section>
    <?php else: ?>
        <div class="memberships-grid">
            <?php foreach ($cards as $card): ?>
                <?php
                    $state = $card['state'];
                    [$status_text, $status_tone] = membership_status_label($state);
                    if ($state === 'active') $status_text = 'Active';
                    $logo_url = get_club_logo_url($card);
                    $club_url = app_url('/club.php?id=' . $card['club_id']);
                ?>
                <article class="membership-tile">
                    <div class="membership-tile-top">
                        <a class="membership-tile-logo" href="<?= $club_url ?>" aria-hidden="true" tabindex="-1">
                            <?php if ($logo_url): ?>
                                <img src="<?= e($logo_url) ?>" alt="">
                            <?php else: ?>
                                <span><?= e(get_club_initials($card['name'])) ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="membership-tile-heading">
                            <h2><a href="<?= $club_url ?>"><?= e($card['name']) ?></a></h2>
                            <span class="member-status-pill tone-<?= $status_tone ?>"><?= $status_text ?></span>
                        </div>
                    </div>

                    <dl class="membership-tile-facts">
                        <?php if ($state === 'committee'): ?>
                            <dt>Role</dt><dd><?= $card['role'] === 'president' ? 'President' : 'Committee member' ?></dd>
                            <dt>Expires</dt><dd>Never, while you're on the committee</dd>
                        <?php elseif ($state === 'expired'): ?>
                            <dt>Expired on</dt><dd><?= e(format_membership_date($card['expires_at'])) ?></dd>
                            <dt>Renewal</dt><dd><?= e(format_price($card['membership_price'])) ?> · <?= e(format_membership_length((int) $card['membership_months'])) ?></dd>
                        <?php else: ?>
                            <dt>Price</dt><dd><?= e(format_price($card['price_snapshot'])) ?> · <?= e(format_membership_length((int) $card['months_snapshot'])) ?></dd>
                            <?php if ($state === 'active'): ?>
                                <dt>Expires</dt><dd><?= e(format_membership_date($card['expires_at'])) ?></dd>
                            <?php else: ?>
                                <dt>Starts</dt><dd>When the committee approves your payment</dd>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dl>

                    <div class="membership-tile-actions">
                        <?php if ($state === 'expired' && $card['membership_open']): ?>
                            <form method="POST" action="<?= app_url('/membership-join.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="club_id" value="<?= (int) $card['club_id'] ?>">
                                <button type="submit" class="membership-tile-btn primary">Renew</button>
                            </form>
                        <?php elseif ($state === 'expired'): ?>
                            <button type="button" class="membership-tile-btn" disabled>Renewals closed</button>
                        <?php elseif (in_array($state, ['active', 'committee'], true)): ?>
                            <!-- Greyed out: you can only renew once a membership has expired -->
                            <button type="button" class="membership-tile-btn" disabled title="You can renew once this membership has expired">Renew</button>
                        <?php else: ?>
                            <a class="membership-tile-btn primary" href="<?= app_url('/membership-payment.php?id=' . $card['id']) ?>">
                                <?= match ($state) {
                                    'awaiting_approval' => 'View application',
                                    'payment_rejected'  => 'Resubmit receipt',
                                    default             => 'Complete payment',
                                } ?>
                            </a>
                        <?php endif; ?>
                        <?php if (in_array($state, ['active', 'expired'], true)): ?>
                            <a class="membership-tile-link" href="<?= app_url('/membership-payment.php?id=' . $card['id']) ?>">Details</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>