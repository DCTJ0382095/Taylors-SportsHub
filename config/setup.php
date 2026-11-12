<?php

// Initialize a fresh local installation without resetting existing application data.
function initialize_database(PDO $db, string $database): void {
    $quoted_name = '`' . str_replace('`', '``', $database) . '`';
    $lock_name = 'tu_sportshub_setup_' . substr(hash('sha256', $database), 0, 32);
    $lock = $db->prepare('SELECT GET_LOCK(:name, 30)');
    $lock->execute([':name' => $lock_name]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Database setup is busy. Please reload the page.');
    }

    try {
        try {
            $db->exec("USE $quoted_name");
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1049) throw $e;
            $db->exec("CREATE DATABASE IF NOT EXISTS $quoted_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->exec("USE $quoted_name");
        }

        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('app_setup', $tables, true)) {
            $required = ['users', 'clubs', 'club_managers', 'sessions', 'registrations', 'refund_records'];
            if (!array_diff($required, $tables)) {
                initialize_workflow_schema($db);
                return;
            }
            if ($tables) {
                throw new RuntimeException('Database has an incomplete or unrelated schema. Set DB_NAME in config/db.php to a new empty database.');
            }
            $db->exec('CREATE TABLE app_setup (id TINYINT PRIMARY KEY, completed TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
        }
        $db->exec('INSERT IGNORE INTO app_setup (id, completed) VALUES (1, 0)');
        if ((int)$db->query('SELECT completed FROM app_setup WHERE id = 1')->fetchColumn() === 1) {
            initialize_workflow_schema($db);
            return;
        }

        $scripts = [];
        foreach (['schema', 'seed'] as $name) {
            $sql = file_get_contents(__DIR__ . '/../database/' . $name . '.sql');
            if ($sql === false) throw new RuntimeException('Missing database/' . $name . '.sql. Restore bundled database files and reload.');
            $scripts[$name] = preg_replace('/^(?:CREATE DATABASE[^\r\n]*|USE[^\r\n]*);\r?\n/m', '', $sql);
        }
        $db->exec($scripts['schema']);
        $db->beginTransaction();
        try {
            $db->exec($scripts['seed']);
            $db->exec('UPDATE app_setup SET completed = 1 WHERE id = 1');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        initialize_workflow_schema($db);
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lock_name]);
    }
}

function initialize_workflow_schema(PDO $db): void {
    // The setup advisory lock serializes this additive upgrade for older databases.
    if (!$db->query("SHOW COLUMNS FROM refund_records LIKE 'submission_key'")->fetch()) {
        $db->exec('ALTER TABLE refund_records ADD COLUMN submission_key CHAR(32) NULL,
            ADD UNIQUE KEY unique_refund_submission (registration_id, submission_key)');
    }
    // Committee hierarchy: add role and permission columns to databases created before this feature.
    if (!$db->query("SHOW COLUMNS FROM club_managers LIKE 'role'")->fetch()) {
        $db->exec("ALTER TABLE club_managers
            ADD COLUMN role ENUM('president', 'committee') NOT NULL DEFAULT 'committee' AFTER appointed_by,
            ADD COLUMN can_manage_sessions TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
            ADD COLUMN can_manage_signups TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_sessions,
            ADD COLUMN can_edit_club TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_signups");
        // Older databases had one manager per club with full control, so they become president.
        $db->exec("UPDATE club_managers
            SET role = 'president', can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1");
    }
    // Committee titles: what the president calls each committee member, e.g. "Secretary".
    if (!$db->query("SHOW COLUMNS FROM club_managers LIKE 'title'")->fetch()) {
        $db->exec("ALTER TABLE club_managers ADD COLUMN title VARCHAR(50) NULL AFTER role");
    }
    // Club profile details that used to be hard-coded in club.php.
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'contact_email'")->fetch()) {
        $db->exec("ALTER TABLE clubs
            ADD COLUMN contact_email VARCHAR(150) NULL AFTER sport_type,
            ADD COLUMN instagram VARCHAR(100) NULL AFTER contact_email,
            ADD COLUMN training_schedule VARCHAR(150) NULL AFTER instagram");
    }
    // Memberships: each club's membership settings (open or closed, price, length, benefits).
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'membership_open'")->fetch()) {
        $db->exec("ALTER TABLE clubs
            ADD COLUMN membership_open TINYINT(1) NOT NULL DEFAULT 0 AFTER training_schedule,
            ADD COLUMN membership_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER membership_open,
            ADD COLUMN membership_months INT NOT NULL DEFAULT 6 AFTER membership_price,
            ADD COLUMN membership_benefits TEXT NULL AFTER membership_months");
    }
    // Memberships: the table of who is a member of which club.
    // Keep this the same as the club_memberships table in database/schema.sql.
    if (!$db->query("SHOW TABLES LIKE 'club_memberships'")->fetch()) {
        $db->exec("CREATE TABLE club_memberships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            club_id INT NOT NULL,
            user_id INT NOT NULL,
            status ENUM('awaiting_payment', 'awaiting_approval', 'payment_rejected', 'active') NOT NULL DEFAULT 'awaiting_payment',
            price_snapshot DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            months_snapshot INT NOT NULL DEFAULT 6,
            payment_instructions_snapshot TEXT NULL,
            receipt_path VARCHAR(255) NULL,
            receipt_uploaded_at DATETIME NULL,
            rejection_reason VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_club_member (club_id, user_id),
            CONSTRAINT fk_member_club FOREIGN KEY (club_id) REFERENCES clubs (id) ON DELETE CASCADE,
            CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_member_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Membership receipt history: every receipt ever sent, not just the latest one.
    // Keep this the same as the membership_receipts table in database/schema.sql.
    if (!$db->query("SHOW TABLES LIKE 'membership_receipts'")->fetch()) {
        $db->exec("CREATE TABLE membership_receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            membership_id INT NOT NULL,
            receipt_path VARCHAR(255) NOT NULL,
            submitted_at DATETIME NOT NULL,
            decision ENUM('awaiting_review', 'approved', 'rejected') NOT NULL DEFAULT 'awaiting_review',
            rejection_reason VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            CONSTRAINT fk_mreceipt_membership FOREIGN KEY (membership_id) REFERENCES club_memberships (id) ON DELETE CASCADE,
            CONSTRAINT fk_mreceipt_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Copy in the receipts that already exist, so the history starts complete.
        $db->exec("INSERT INTO membership_receipts
                       (membership_id, receipt_path, submitted_at, decision, rejection_reason, reviewed_by, reviewed_at)
                   SELECT id, receipt_path, COALESCE(receipt_uploaded_at, NOW()),
                          CASE status WHEN 'awaiting_approval' THEN 'awaiting_review'
                                      WHEN 'payment_rejected'  THEN 'rejected'
                                      ELSE 'approved' END,
                          IF(status = 'payment_rejected', rejection_reason, NULL),
                          IF(status = 'awaiting_approval', NULL, reviewed_by),
                          IF(status = 'awaiting_approval', NULL, reviewed_at)
                   FROM club_memberships
                   WHERE receipt_path IS NOT NULL");
    }
    // Sessions: an optional cheaper price for club members (empty = everyone pays the same).
    if (!$db->query("SHOW COLUMNS FROM sessions LIKE 'member_price'")->fetch()) {
        $db->exec("ALTER TABLE sessions ADD COLUMN member_price DECIMAL(10, 2) NULL AFTER price");
    }
    // Clubs: a note the president writes, shown next to the DuitNow QR when students pay.
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'payment_note'")->fetch()) {
        $db->exec("ALTER TABLE clubs ADD COLUMN payment_note TEXT NULL AFTER account_number");
    }
    // Clubs: new clubs used to get Badminton's bank account by default. Now they start empty,
    // and the president must add the club's own details before it can run sessions.
    $bank_column = $db->query("SHOW COLUMNS FROM clubs LIKE 'bank_name'")->fetch();
    if ($bank_column && $bank_column['Null'] === 'NO') {
        $db->exec("ALTER TABLE clubs
            MODIFY bank_name VARCHAR(100) NULL DEFAULT NULL,
            MODIFY account_holder VARCHAR(150) NULL DEFAULT NULL,
            MODIFY account_number VARCHAR(50) NULL DEFAULT NULL");
    }
    initialize_receipt_history($db);
}

function initialize_receipt_history(PDO $db): void {
    $sql = file_get_contents(__DIR__ . '/../database/receipt-history.sql');
    if ($sql === false) throw new RuntimeException('Missing database/receipt-history.sql. Restore it and reload.');
    $db->exec($sql);
}
