-- The Taylor's SportsHub database is created with Unicode support.
CREATE DATABASE IF NOT EXISTS `tu_sportshub` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- The target database is selected.
USE `tu_sportshub`;

-- Account identities, authentication and profile details are stored.
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `preferred_name` VARCHAR(100) NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('student', 'admin') NOT NULL DEFAULT 'student',
  `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `phone` VARCHAR(30) NULL,
  `faculty` VARCHAR(150) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Club identity, membership terms and payment details are stored.
CREATE TABLE IF NOT EXISTS `clubs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL UNIQUE,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `description` TEXT NOT NULL,
  `sport_type` VARCHAR(50) NOT NULL,
  `contact_email` VARCHAR(150) NULL,
  `instagram` VARCHAR(100) NULL,
  `training_schedule` VARCHAR(150) NULL,
  `membership_open` TINYINT(1) NOT NULL DEFAULT 0,
  `membership_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `membership_months` INT NOT NULL DEFAULT 6,
  `membership_benefits` TEXT NULL,
  `logo_path` VARCHAR(255) NULL,
  `banner_path` VARCHAR(255) NULL,
  `bank_name` VARCHAR(100) NULL,
  `account_holder` VARCHAR(150) NULL,
  `account_number` VARCHAR(50) NULL,
  `payment_note` TEXT NULL,
  `duitnow_qr_path` VARCHAR(255) NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Committee appointments, titles and permissions are stored.
CREATE TABLE IF NOT EXISTS `club_managers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `club_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `appointed_by` INT NOT NULL,
  `role` ENUM('president', 'committee') NOT NULL DEFAULT 'committee',
  `title` VARCHAR(50) NULL,
  `can_manage_sessions` TINYINT(1) NOT NULL DEFAULT 0, 
  `can_manage_signups` TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit_club` TINYINT(1) NOT NULL DEFAULT 0, 
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_club_manager` (`club_id`, `user_id`),
  CONSTRAINT `fk_cm_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_appointer` FOREIGN KEY (`appointed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sports session schedules, prices and publication states are stored.
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `club_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `sport_type` VARCHAR(50) NOT NULL,
  `venue` VARCHAR(255) NOT NULL,
  `image_path` VARCHAR(255) NULL,
  `session_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `member_price` DECIMAL(10, 2) NULL,
  `capacity` INT NOT NULL,
  `status` ENUM('draft', 'published', 'closed', 'cancelled') NOT NULL DEFAULT 'draft',
  `cancellation_reason` VARCHAR(255) NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_session_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_session_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservations, payment snapshots and cancellation details are stored.
CREATE TABLE IF NOT EXISTS `registrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `status` ENUM('awaiting_payment', 'awaiting_approval', 'payment_rejected', 'confirmed', 'cancellation_pending', 'cancelled', 'expired') NOT NULL DEFAULT 'confirmed',
  `price_snapshot` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `payment_instructions_snapshot` TEXT NULL,
  `payment_deadline` DATETIME NULL,
  `receipt_path` VARCHAR(255) NULL,
  `receipt_uploaded_at` DATETIME NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `cancellation_requested_at` DATETIME NULL,
  `cancellation_reason` VARCHAR(255) NULL,
  `recipient_bank_name` VARCHAR(100) NULL,
  `recipient_account_name` VARCHAR(150) NULL,
  `recipient_account_number` VARCHAR(50) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_session_user` (`session_id`, `user_id`),
  CONSTRAINT `fk_reg_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reg_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Externally completed refunds and optional proof are recorded.
CREATE TABLE IF NOT EXISTS `refund_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `registration_id` INT NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `proof_path` VARCHAR(255) NULL,
  `recorded_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `submission_key` CHAR(32) NULL,
  UNIQUE KEY `unique_refund_submission` (`registration_id`, `submission_key`),
  CONSTRAINT `fk_refund_reg` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refund_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session receipt submissions and review decisions are retained.
-- The receipt-history structure is shared with the upgrade migration.
CREATE TABLE IF NOT EXISTS `payment_receipts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `registration_id` INT NOT NULL,
  `receipt_path` VARCHAR(255) NOT NULL,
  `submitted_at` DATETIME NULL,
  `decision` ENUM('awaiting_review', 'approved', 'rejected', 'legacy') NOT NULL DEFAULT 'awaiting_review',
  `rejection_reason` VARCHAR(255) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  UNIQUE KEY `unique_registration_receipt` (`registration_id`, `receipt_path`),
  CONSTRAINT `fk_receipt_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One membership row is permitted per student and club.
-- The existing membership row is reused for renewal.
-- Expired state is derived from approved status and the recorded expiry date.
CREATE TABLE IF NOT EXISTS `club_memberships` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `club_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `status` ENUM('awaiting_payment', 'awaiting_approval', 'payment_rejected', 'active') NOT NULL DEFAULT 'awaiting_payment',
  `price_snapshot` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `months_snapshot` INT NOT NULL DEFAULT 6,
  `payment_instructions_snapshot` TEXT NULL,
  `receipt_path` VARCHAR(255) NULL,
  `receipt_uploaded_at` DATETIME NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_club_member` (`club_id`, `user_id`),
  CONSTRAINT `fk_member_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_member_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_member_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership receipt submissions and review decisions are retained.
-- Historical receipt records are preserved across membership renewals.
CREATE TABLE IF NOT EXISTS `membership_receipts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `membership_id` INT NOT NULL,
  `receipt_path` VARCHAR(255) NOT NULL,
  `submitted_at` DATETIME NOT NULL,
  `decision` ENUM('awaiting_review', 'approved', 'rejected') NOT NULL DEFAULT 'awaiting_review',
  `rejection_reason` VARCHAR(255) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  CONSTRAINT `fk_mreceipt_membership` FOREIGN KEY (`membership_id`) REFERENCES `club_memberships` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mreceipt_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;