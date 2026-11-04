<?php
// The authenticated controller supplies $club, $formUrl, $csrfToken,
// and a public DuitNow QR URL or null. $error is optional.
$escapeMembership = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$isPaid = (float) $club['membership_fee'] > 0;
?>
<main class="membership-application">
    <h1>Join <?= $escapeMembership($club['name']) ?></h1>
    <p>Membership fee RM <?= $escapeMembership($club['membership_fee']) ?></p>
    <p>Membership lasts <?= (int) $club['membership_months'] ?> month(s) from approval.</p>
    <?php if (!empty($error)): ?>
        <p role="alert"><?= $escapeMembership($error) ?></p>
    <?php endif; ?>
    <?php if ($isPaid && $qrUrl === null): ?>
        <p>Payment is unavailable until the club adds its DuitNow QR code.</p>
    <?php else: ?>
        <?php if ($isPaid): ?>
            <h2>Pay with DuitNow</h2>
            <img src="<?= $escapeMembership($qrUrl) ?>" alt="DuitNow payment QR code">
            <p>Pay the membership fee, then upload your receipt for committee review.</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?= $escapeMembership($formUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $escapeMembership($csrfToken) ?>">
            <?php if ($isPaid): ?>
                <label>Payment receipt <input type="file" name="receipt" required></label>
            <?php endif; ?>
            <button type="submit">Submit application</button>
        </form>
    <?php endif; ?>
</main>
