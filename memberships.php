<?php
// Club memberships, applications and committee memberships are collected for the membership overview.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_login();
$db = get_db_connection();

// Clubs where the student sits on the committee are added first.
$stmt = $db->prepare("SELECT c.id AS club_id, c.name, c.logo_path, cm.role
                      FROM club_managers cm
                      INNER JOIN clubs c ON c.id = cm.club_id
                      WHERE cm.user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
$cards = [];
// Committee clubs are added to the membership card collection.
foreach ($stmt->fetchAll() as $row) {
    $row['state'] = 'committee';
    $cards[$row['club_id']] = $row;     // Cards are keyed by club ID to avoid duplicate clubs.
}

// Paid memberships and applications are added next. The club's current settings are loaded too,
// because a renewal is charged at today's price and length.
$stmt = $db->prepare("SELECT m.id, m.club_id, m.status, m.price_snapshot, m.months_snapshot, m.expires_at,
                             c.name, c.logo_path, c.membership_open, c.membership_price, c.membership_months
                      FROM club_memberships m
                      INNER JOIN clubs c ON c.id = m.club_id
                      WHERE m.user_id = :user_id");
$stmt->execute([':user_id' => $user['id']]);
// Membership application records are added to the card collection.
foreach ($stmt->fetchAll() as $row) {
    // Membership rows already represented by committee membership are skipped.
    if (isset($cards[$row['club_id']])) {
        continue;                       // Committee membership is given precedence over the application record.
    }
    $row['state'] = membership_state($row);
    $cards[$row['club_id']] = $row;
}

// Cards are sorted A to Z by club name.
usort($cards, fn($a, $b) => strcmp($a['name'], $b['name']));

$page_title = 'My Memberships';
require_once __DIR__ . '/includes/header.php';
?>
<!-- The memberships page markup is rendered. --><div class="memberships-page">
    <!-- The membership overview heading is displayed. -->
    <header class="memberships-header">
        <!-- The memberships eyebrow markup is rendered. --><div class="memberships-eyebrow">SPORTSHUB / CLUBS</div>
        <h1 class="memberships-title">My Memberships</h1>
        <p class="memberships-subtitle">The clubs you belong to, when each membership ends, and renewals.</p>
    </header>

    <!-- Membership cards or an empty state are displayed. -->
    <?php /* An empty-membership message is displayed when no cards are available. */ if (empty($cards)): ?>
        <section class="memberships-empty">
            <h2>You're not a member of any club yet</h2>
            <p>Open a club's page and choose Apply for membership.</p>
            <a class="membership-tile-btn primary" href="<?= app_url('/clubs.php') ?>">Browse clubs</a>
        </section>
    <?php /* Membership and committee cards are displayed in the overview. */ else: ?>
        <!-- The memberships grid markup is rendered. --><div class="memberships-grid">
            <?php /* Each club membership is rendered as a card. */ foreach ($cards as $card): ?>
                <?php
                    $state = $card['state'];
                    [$status_text, $status_tone] = membership_status_label($state);
                    // Approved membership is labelled Active.
                    if ($state === 'active') $status_text = 'Active';
                    $logo_url = get_club_logo_url($card);
                    $club_url = app_url('/club.php?id=' . $card['club_id']);
                ?>
                <!-- Club identity, membership state and actions are displayed on the card. -->
                <article class="membership-tile">
                    <!-- The membership tile top markup is rendered. --><div class="membership-tile-top">
                        <a class="membership-tile-logo" href="<?= $club_url ?>" aria-hidden="true" tabindex="-1">
                            <?php /* The uploaded club logo is displayed. */ if ($logo_url): ?>
                                <img src="<?= e($logo_url) ?>" alt="">
                            <?php /* Club initials are displayed when no logo is available. */ else: ?>
                                <span><?= e(get_club_initials($card['name'])) ?></span>
                            <?php endif; ?>
                        </a>
                        <!-- The membership tile heading markup is rendered. --><div class="membership-tile-heading">
                            <h2><a href="<?= $club_url ?>"><?= e($card['name']) ?></a></h2>
                            <span class="member-status-pill tone-<?= $status_tone ?>"><?= $status_text ?></span>
                        </div>
                    </div>

                    <!-- Membership facts are selected according to committee, active, expired or pending state. -->
                    <dl class="membership-tile-facts">
                        <?php /* Committee membership is explained on the club card. */ if ($state === 'committee'): ?>
                            <dt>Role</dt><dd><?= $card['role'] === 'president' ? 'President' : 'Committee member' ?></dd>
                            <dt>Expires</dt><dd>Never, while you're on the committee</dd>
                        <?php /* Expired membership and renewal terms are displayed. */ elseif ($state === 'expired'): ?>
                            <dt>Expired on</dt><dd><?= e(format_membership_date($card['expires_at'])) ?></dd>
                            <dt>Renewal</dt><dd><?= e(format_price($card['membership_price'])) ?> · <?= e(format_membership_length((int) $card['membership_months'])) ?></dd>
                        <?php /* Pending or active membership details are displayed. */ else: ?>
                            <dt>Price</dt><dd><?= e(format_price($card['price_snapshot'])) ?> · <?= e(format_membership_length((int) $card['months_snapshot'])) ?></dd>
                            <?php /* The current membership expiry date is displayed for active membership. */ if ($state === 'active'): ?>
                                <dt>Expires</dt><dd><?= e(format_membership_date($card['expires_at'])) ?></dd>
                            <?php /* Pending payment guidance is displayed for unapproved membership. */ else: ?>
                                <dt>Starts</dt><dd>When the committee approves your payment</dd>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dl>

                    <!-- Renewal and payment actions are selected according to membership state. -->
                    <div class="membership-tile-actions">
                        <?php /* Renewal is offered for expired membership while applications are open. */ if ($state === 'expired' && $card['membership_open']): ?>
                            <!-- The form controls markup is rendered. --><form method="POST" action="<?= app_url('/membership-join.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="club_id" value="<?= (int) $card['club_id'] ?>">
                                <button type="submit" class="membership-tile-btn primary">Renew</button>
                            </form>
                        <?php /* Closed renewal is indicated for expired membership. */ elseif ($state === 'expired'): ?>
                            <button type="button" class="membership-tile-btn" disabled>Renewals closed</button>
                        <?php /* Renewal is disabled for active and committee memberships. */ elseif (in_array($state, ['active', 'committee'], true)): ?>
                            <!-- Renewal is disabled before membership expiry. -->
                            <button type="button" class="membership-tile-btn" disabled title="You can renew once this membership has expired">Renew</button>
                        <?php /* Pending applications are linked to the membership payment page. */ else: ?>
                            <a class="membership-tile-btn primary" href="<?= app_url('/membership-payment.php?id=' . $card['id']) ?>">
                                <?= /* A payment action label is selected from the membership state. */ match ($state) {
                                    'awaiting_approval' => 'View application',
                                    'payment_rejected'  => 'Resubmit receipt',
                                    // Payment completion is offered as the default pending action.
                                    default             => 'Complete payment',
                                } ?>
                            </a>
                        <?php endif; ?>
                        <?php /* Membership payment details are linked for active and expired terms. */ if (in_array($state, ['active', 'expired'], true)): ?>
                            <a class="membership-tile-link" href="<?= app_url('/membership-payment.php?id=' . $card['id']) ?>">Details</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>