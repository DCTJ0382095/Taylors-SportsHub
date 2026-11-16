<?php

// Missing databases are initialized from the schema and seed files. Existing recognized installations
// are upgraded without reseeding.
function initialize_database(PDO $db, string $database): void {
    // The database identifier is quoted, and setup requests are serialized through a named MySQL advisory
    // lock.
    $quoted_name = '`' . str_replace('`', '``', $database) . '`';
    $lock_name = 'tu_sportshub_setup_' . substr(hash('sha256', $database), 0, 32);
    $lock = $db->prepare('SELECT GET_LOCK(:name, 30)');
    $lock->execute([':name' => $lock_name]);
    // Setup is aborted when the advisory lock cannot be acquired.
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Database setup is busy. Please reload the page.');
    }

    // Database initialization is protected by guaranteed lock release.
    try {
        // The database is selected, or created after MySQL error 1049 is received.
        try {
            $db->exec("USE $quoted_name");
        } /* Database-selection failures are inspected before database creation is attempted. */ catch (PDOException $e) {
            // Errors other than a missing database are rethrown.
            if ((int)($e->errorInfo[1] ?? 0) !== 1049) throw $e;
            $db->exec("CREATE DATABASE IF NOT EXISTS $quoted_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->exec("USE $quoted_name");
        }

        // Application-managed installations are identified through the app_setup marker table.
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        // Existing tables are inspected when the setup marker is absent.
        if (!in_array('app_setup', $tables, true)) {
            // An older complete install (from before app_setup existed) is upgraded in place instead of being re-seeded.
            $required = ['users', 'clubs', 'club_managers', 'sessions', 'registrations', 'refund_records'];
            // Recognized older schemas are upgraded and marked as initialized.
            if (!array_diff($required, $tables)) {
                initialize_workflow_schema($db);
                return;
            }
            // Unrecognized non-empty databases are rejected before schema or seed statements are executed.
            if ($tables) {
                throw new RuntimeException('Database has an incomplete or unrelated schema. Set DB_NAME in config/db.php to a new empty database.');
            }
            // An empty database is given the app_setup marker table first.
            $db->exec('CREATE TABLE app_setup (id TINYINT PRIMARY KEY, completed TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
        }
        // When setup has already been completed, only the upgrades are run.
        $db->exec('INSERT IGNORE INTO app_setup (id, completed) VALUES (1, 0)');
        // Completed installations are upgraded without reseeding.
        if ((int)$db->query('SELECT completed FROM app_setup WHERE id = 1')->fetchColumn() === 1) {
            initialize_workflow_schema($db);
            return;
        }

        // schema.sql and seed.sql are read, and the CREATE DATABASE / USE lines in both files are stripped,
        // since the right database is already selected.
        $scripts = [];
        // The schema and seed scripts are loaded for initial setup.
        foreach (['schema', 'seed'] as $name) {
            $sql = file_get_contents(__DIR__ . '/../database/' . $name . '.sql');
            // Missing bundled SQL files are reported as setup failures.
            if ($sql === false) throw new RuntimeException('Missing database/' . $name . '.sql. Restore bundled database files and reload.');
            $scripts[$name] = preg_replace('/^(?:CREATE DATABASE[^\r\n]*|USE[^\r\n]*);\r?\n/m', '', $sql);
        }
        // Tables are created first. Demo data and the completion marker are saved in a transaction, and failed
        // seed writes are rolled back.
        $db->exec($scripts['schema']);
        $db->beginTransaction();
        // Seed data and the completion marker are committed together.
        try {
            $db->exec($scripts['seed']);
            $db->exec('UPDATE app_setup SET completed = 1 WHERE id = 1');
            $db->commit();
        } /* Failed seed transactions are rolled back before the error is rethrown. */ catch (Throwable $e) {
            // An active transaction is rolled back after setup failure.
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        initialize_workflow_schema($db);
    } /* The advisory lock is released after success or failure. */ finally {
        // The setup lock is released through the finally block.
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lock_name]);
    }
}

// Additive upgrades are applied to older installations. Existing columns and tables are checked before
// the corresponding changes are attempted.
function initialize_workflow_schema(PDO $db): void {
    // Upgrades are applied under the setup lock. A unique submission key is added to refund records for
    // duplicate-submission detection.
    if (!$db->query("SHOW COLUMNS FROM refund_records LIKE 'submission_key'")->fetch()) {
        $db->exec('ALTER TABLE refund_records ADD COLUMN submission_key CHAR(32) NULL,
            ADD UNIQUE KEY unique_refund_submission (registration_id, submission_key)');
    }
    // Committee hierarchy: role and permission columns are added to databases created before this feature.
    if (!$db->query("SHOW COLUMNS FROM club_managers LIKE 'role'")->fetch()) {
        $db->exec("ALTER TABLE club_managers
            ADD COLUMN role ENUM('president', 'committee') NOT NULL DEFAULT 'committee' AFTER appointed_by,
            ADD COLUMN can_manage_sessions TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
            ADD COLUMN can_manage_signups TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_sessions,
            ADD COLUMN can_edit_club TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_signups");
        // Older databases had one manager per club with full control, so every existing manager is made president.
        $db->exec("UPDATE club_managers
            SET role = 'president', can_manage_sessions = 1, can_manage_signups = 1, can_edit_club = 1");
    }
    // A committee title column is added for titles such as Secretary.
    if (!$db->query("SHOW COLUMNS FROM club_managers LIKE 'title'")->fetch()) {
        $db->exec("ALTER TABLE club_managers ADD COLUMN title VARCHAR(50) NULL AFTER role");
    }
    // Editable contact, social and training fields are added to club profiles.
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'contact_email'")->fetch()) {
        $db->exec("ALTER TABLE clubs
            ADD COLUMN contact_email VARCHAR(150) NULL AFTER sport_type,
            ADD COLUMN instagram VARCHAR(100) NULL AFTER contact_email,
            ADD COLUMN training_schedule VARCHAR(150) NULL AFTER instagram");
    }
    // Memberships: per-club membership settings are added (open or closed, price, length, benefits).
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'membership_open'")->fetch()) {
        $db->exec("ALTER TABLE clubs
            ADD COLUMN membership_open TINYINT(1) NOT NULL DEFAULT 0 AFTER training_schedule,
            ADD COLUMN membership_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER membership_open,
            ADD COLUMN membership_months INT NOT NULL DEFAULT 6 AFTER membership_price,
            ADD COLUMN membership_benefits TEXT NULL AFTER membership_months");
    }
    // The club_memberships table is created when missing, with the structure defined in the main schema.
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
    // Membership receipt history is stored in a separate table, with the structure defined in the main
    // schema.
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
        // Existing receipt paths are copied into history, and decisions are inferred from current membership
        // statuses.
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
    // An optional member price is added to sessions. The normal price is used when no member price is set.
    if (!$db->query("SHOW COLUMNS FROM sessions LIKE 'member_price'")->fetch()) {
        $db->exec("ALTER TABLE sessions ADD COLUMN member_price DECIMAL(10, 2) NULL AFTER price");
    }
    // Clubs: a payment note written by the president is added, shown next to the DuitNow QR at payment time.
    if (!$db->query("SHOW COLUMNS FROM clubs LIKE 'payment_note'")->fetch()) {
        $db->exec("ALTER TABLE clubs ADD COLUMN payment_note TEXT NULL AFTER account_number");
    }
    // Empty defaults are assigned to new club bank details, so a demo club's bank account is no longer
    // assigned automatically.
    $bank_column = $db->query("SHOW COLUMNS FROM clubs LIKE 'bank_name'")->fetch();
    // Required legacy bank columns are changed to nullable columns.
    if ($bank_column && $bank_column['Null'] === 'NO') {
        $db->exec("ALTER TABLE clubs
            MODIFY bank_name VARCHAR(100) NULL DEFAULT NULL,
            MODIFY account_holder VARCHAR(150) NULL DEFAULT NULL,
            MODIFY account_number VARCHAR(50) NULL DEFAULT NULL");
    }
    // Session receipt history is upgraded last through a separate SQL file.
    initialize_receipt_history($db);
}

// database/receipt-history.sql is run to add the session receipt history table and copy in existing receipts.
function initialize_receipt_history(PDO $db): void {
    $sql = file_get_contents(__DIR__ . '/../database/receipt-history.sql');
    // A missing receipt-history migration is reported as a setup failure.
    if ($sql === false) throw new RuntimeException('Missing database/receipt-history.sql. Restore it and reload.');
    $db->exec($sql);
}
