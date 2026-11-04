<?php
// Render after checking membership-management permission. The controller
// supplies $club, $memberships, $reviewUrl, $csrfToken and $receiptUrl,
// a callable that builds a permission-checked receipt URL from a membership ID.
$escapeClubMembership = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<main class="club-memberships">
    <p><?= $escapeClubMembership($club['name']) ?></p>
    <h1>Memberships</h1>
    <?php if (!empty($error)): ?>
        <p role="alert"><?= $escapeClubMembership($error) ?></p>
    <?php endif; ?>
    <?php foreach (['Pending applications' => true, 'Membership history' => false] as $heading => $pending): ?>
        <?php $rows = array_filter($memberships, static function (array $membership) use ($pending): bool {
            return ($membership['status'] === 'pending') === $pending;
        }); ?>
        <section>
            <h2><?= $heading ?></h2>
            <?php if ($rows === []): ?>
                <p><?= $pending ? 'No pending applications.' : 'No reviewed applications.' ?></p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Applicant</th>
                            <th scope="col">Fee</th>
                            <th scope="col">Status</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Receipt</th>
                            <?php if ($pending): ?><th scope="col">Review</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $membership): ?>
                            <?php
                            $id = (int) $membership['id'];
                            $status = $membership['is_active'] ? 'Active' : ucfirst($membership['status']);
                            if (!$membership['is_active'] && $membership['status'] === 'approved') {
                                $status = 'Expired';
                            }
                            ?>
                            <tr>
                                <th scope="row">
                                    <?= $escapeClubMembership($membership['name']) ?>
                                    <small><?= $escapeClubMembership($membership['email']) ?></small>
                                </th>
                                <td>RM <?= $escapeClubMembership($membership['amount_due']) ?></td>
                                <td><?= $escapeClubMembership($status) ?></td>
                                <td><?= $escapeClubMembership($membership['expires_at'] ?? '—') ?></td>
                                <td>
                                    <?php if (!empty($membership['receipt_path'])): ?>
                                        <a href="<?= $escapeClubMembership($receiptUrl($id)) ?>">View receipt</a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <?php if ($pending): ?>
                                    <td>
                                        <form method="post" action="<?= $escapeClubMembership($reviewUrl) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $escapeClubMembership($csrfToken) ?>">
                                            <input type="hidden" name="membership_id" value="<?= $id ?>">
                                            <button type="submit" name="action" value="approve">Approve</button>
                                            <button type="submit" name="action" value="reject">Reject</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</main>
