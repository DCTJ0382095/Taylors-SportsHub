<?php

require_once __DIR__ . '/db.php';

// Call after authentication and CSRF verification. The receipt path must
// come from the server's secure upload handler.
function applyForClubMembership(int $clubId, int $userId, ?string $receiptPath): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Serialize applications and decisions for the same club.
        $clubQuery = $pdo->prepare(
            'SELECT membership_fee, allow_guest_members FROM clubs WHERE id = ? FOR UPDATE'
        );
        $clubQuery->execute([$clubId]);
        $club = $clubQuery->fetch();

        $userQuery = $pdo->prepare('SELECT account_type FROM users WHERE id = ?');
        $userQuery->execute([$userId]);
        $accountType = $userQuery->fetchColumn();

        if (!$club || $accountType === false
            || ($accountType === 'guest' && !$club['allow_guest_members'])) {
            throw new DomainException('You cannot apply for membership in this club.');
        }

        $existing = $pdo->prepare(
            "SELECT 1 FROM memberships WHERE club_id = ? AND user_id = ?
             AND (status = 'pending' OR (status = 'approved' AND expires_at > NOW()))
             LIMIT 1"
        );
        $existing->execute([$clubId, $userId]);
        if ($existing->fetchColumn() !== false) {
            throw new DomainException('You already have a pending application or current membership.');
        }

        if ((float) $club['membership_fee'] > 0 && ($receiptPath === null || $receiptPath === '')) {
            throw new InvalidArgumentException('Upload your membership payment receipt.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO memberships (club_id, user_id, amount_due, receipt_path) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$clubId, $userId, $club['membership_fee'], $receiptPath]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();

        return $id;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

// Call after verifying the actor's membership-management permission and CSRF.
function reviewClubMembership(int $clubId, int $membershipId, int $actorId, bool $approve): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $clubQuery = $pdo->prepare('SELECT membership_months FROM clubs WHERE id = ? FOR UPDATE');
        $clubQuery->execute([$clubId]);
        $months = $clubQuery->fetchColumn();
        if ($months === false) {
            throw new DomainException('Club not found.');
        }

        $statement = $pdo->prepare(
            "UPDATE memberships
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                 starts_at = IF(? = 1, NOW(), NULL),
                 expires_at = IF(? = 1, DATE_ADD(NOW(), INTERVAL ? MONTH), NULL)
             WHERE id = ? AND club_id = ? AND status = 'pending'"
        );
        $statement->execute([
            $approve ? 'approved' : 'rejected',
            $actorId, (int) $approve, (int) $approve, $months, $membershipId, $clubId,
        ]);
        $changed = $statement->rowCount() === 1;
        $pdo->commit();

        return $changed;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

// Call after verifying the actor's membership-management permission.
function listClubMemberships(int $clubId): array
{
    $statement = db()->prepare(
        "SELECT m.*, u.name, u.email, u.account_type,
                (m.status = 'approved' AND m.starts_at <= NOW() AND m.expires_at > NOW()) AS is_active
         FROM memberships m JOIN users u ON u.id = m.user_id
         WHERE m.club_id = ?
         ORDER BY (m.status = 'pending') DESC, m.applied_at DESC, m.id DESC"
    );
    $statement->execute([$clubId]);

    return $statement->fetchAll();
}
