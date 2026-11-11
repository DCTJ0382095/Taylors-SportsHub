-- Taylor's University SportsHub Seed Data
USE `tu_sportshub`;

-- Clear demo tables in dependency order using transactional statements.
DELETE FROM `club_memberships`;
DELETE FROM `refund_records`;
DELETE FROM `registrations`;
DELETE FROM `sessions`;
DELETE FROM `club_managers`;
DELETE FROM `clubs`;
DELETE FROM `users`;

-- Seed Users (Password for all: Password123!)
-- Hash: $2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `status`, `phone`) VALUES
(1, 'SportsHub Admin', 'admin@taylors.edu.my', '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO', 'admin', 'active', '+60123456700'),
(2, 'Alex Wong', 'alex@sd.taylors.edu.my', '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO', 'student', 'active', '+60123456701'),
(3, 'Liam Tan', 'liam@sd.taylors.edu.my', '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO', 'student', 'active', '+60123456702'),
(4, 'Chloe Lee', 'chloe@sd.taylors.edu.my', '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO', 'student', 'active', '+60123456703'),
(5, 'Danial Hakim', 'danial@sd.taylors.edu.my', '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO', 'student', 'active', '+60123456704');

-- Seed Clubs
INSERT INTO `clubs` (`id`, `name`, `slug`, `description`, `sport_type`, `logo_path`, `banner_path`, `bank_name`, `account_holder`, `account_number`, `duitnow_qr_path`, `status`) VALUES
(1, "Taylor's Badminton Club", 'taylors-badminton-club', "The official Taylor's University Badminton Club organizing weekly sparring sessions, footwork clinics, and campus tournaments.", 'Badminton', 'assets/images/logo/logo.webp', 'assets/images/sports/badminton-banner.webp', 'Maybank', "Taylor's Badminton Club", '514012345678', 'assets/images/payment/duitnow-qr.webp', 'active'),
(2, "Taylor's Basketball Club", 'taylors-basketball-club', "Taylor's University Basketball Club connecting campus hoopers for regular open runs, league matches, and skill development.", 'Basketball', 'assets/images/logo/logo.webp', 'assets/images/sports/basketball-banner.webp', 'CIMB Bank', "Taylor's Basketball Club", '800987654321', 'assets/images/payment/duitnow-qr.webp', 'active'),
(3, "Taylor's Pickleball Club", 'taylors-pickleball-club', "Taylor's premier racket community for pickleball enthusiasts. Weekly social rallies, coaching clinics, and doubles ladder shootouts on multi-courts.", 'Pickleball', 'assets/images/logo/logo.webp', 'assets/images/sports/pickleball-banner.webp', 'Maybank', "Taylor's Pickleball Club", '514088239912', 'assets/images/payment/duitnow-qr.webp', 'active');

-- Appoint Club Manager (Alex Wong manages Badminton Club)
INSERT INTO `club_managers` (`id`, `club_id`, `user_id`, `appointed_by`, `role`, `can_manage_sessions`, `can_manage_signups`, `can_edit_club`) VALUES
(1, 1, 2, 1, 'president', 1, 1, 1),   -- Alex Wong: president of Badminton (appointed by Admin)
(2, 1, 3, 2, 'committee', 0, 1, 0),   -- Liam Tan: Badminton committee, sign-ups and payments only (appointed by Alex)
(3, 2, 4, 1, 'president', 1, 1, 1),   -- Chloe Lee: president of Basketball (appointed by Admin)
(4, 3, 5, 1, 'president', 1, 1, 1);   -- Danial Hakim: president of Pickleball (appointed by Admin)

-- Seed Sessions
INSERT INTO `sessions` (`id`, `club_id`, `title`, `description`, `sport_type`, `venue`, `image_path`, `session_date`, `start_time`, `end_time`, `price`, `capacity`, `status`, `created_by`) VALUES
(1, 1, 'Sunday Open Play & Social Smash', 'Casual doubles and singles open play across 3 indoor courts. Shuttles provided. All skill levels welcome.', 'Badminton', "Taylor's Sports Complex Court 1-3", 'assets/images/sports/badminton-open-play.webp', '2026-11-15', '14:00:00', '16:00:00', 0.00, 16, 'published', 2),
(2, 1, 'Weekly Badminton Intensive & Footwork Clinic', 'Structured 2-hour coaching session focusing on shadow footwork drills, net play, and match strategy with tournament seniors.', 'Badminton', 'Multi-Purpose Hall Court A', 'assets/images/sports/badminton-footwork.webp', '2026-11-20', '18:00:00', '20:00:00', 15.00, 12, 'published', 2),
(3, 1, 'Doubles Mini-Tournament (Semester Cup)', 'Internal club tournament with group stages and knockout playoffs. Trophies and grip bundles for top 3 pairs.', 'Badminton', "Taylor's Sports Complex Court 1-4", 'assets/images/sports/rally-poster.webp', '2026-12-05', '09:00:00', '13:00:00', 25.00, 24, 'draft', 2),
(4, 2, "Friday Night 3v3 Half-Court Run", 'Weekly 3v3 basketball runs with king-of-the-court rotation. Bring both light and dark jerseys.', 'Basketball', 'Outdoor Basketball Court 2', 'assets/images/sports/basketball-run.webp', '2026-11-22', '19:00:00', '21:00:00', 10.00, 18, 'published', 1),
(5, 3, 'Weekly Pickleball Social Rally', 'Casual round-robin social games. Paddles and balls provided. All skill levels welcome.', 'Pickleball', "Taylor's Sports Complex Multi-court 2", 'assets/images/sports/pickleball-rally.webp', '2026-11-18', '19:00:00', '21:00:00', 10.00, 16, 'published', 1),
(6, 3, 'Pickleball Beginners Coaching Clinic', 'Fundamentals covering dinking, third-shot drops, court positioning, and non-volley zone rules with certified student instructors.', 'Pickleball', "Taylor's Sports Complex Multi-court 2", 'assets/images/sports/pickleball-clinic.webp', '2026-11-25', '18:00:00', '20:00:00', 15.00, 12, 'published', 1),
(7, 3, 'Pickleball Doubles Ladder Shootout', 'Competitive doubles ladder tournament with dynamic court rotations. Top pairs advance to the semester playoffs.', 'Pickleball', "Taylor's Sports Complex Multi-court 1 & 2", 'assets/images/sports/pickleball-ladder.webp', '2026-12-02', '18:30:00', '21:30:00', 20.00, 16, 'published', 1);

-- Seed Registrations
-- Liam registered for free session -> confirmed
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `status`, `price_snapshot`, `payment_instructions_snapshot`) VALUES
(1, 1, 3, 'confirmed', 0.00, 'Free session. No payment required.');

-- Chloe registered for paid session -> awaiting review
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `status`, `price_snapshot`, `payment_instructions_snapshot`, `payment_deadline`, `receipt_uploaded_at`, `receipt_path`) VALUES
(2, 2, 4, 'awaiting_approval', 15.00, 'Maybank 514012345678 (Taylor\'s Badminton Club)', '2026-11-20 17:00:00', '2026-11-04 12:30:00', 'assets/uploads/receipts/sample_chloe_receipt.png');

-- Danial registered for paid session -> awaiting payment
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `status`, `price_snapshot`, `payment_instructions_snapshot`, `payment_deadline`) VALUES
(3, 2, 5, 'awaiting_payment', 15.00, 'Maybank 514012345678 (Taylor\'s Badminton Club)', '2026-11-20 17:00:00');

-- Club contact details (previously hard-coded in club.php)
UPDATE `clubs` SET `contact_email` = 'badminton.club@taylors.edu.my', `instagram` = '@taylorsbadminton', `training_schedule` = 'Training every Tuesday, 8–10 PM' WHERE `id` = 1;
UPDATE `clubs` SET `contact_email` = 'basketball.club@taylors.edu.my', `instagram` = '@taylorsbasketball', `training_schedule` = 'Training every Friday, 7–10 PM' WHERE `id` = 2;
UPDATE `clubs` SET `contact_email` = 'pickleball.club@taylors.edu.my', `instagram` = '@taylorspickleball', `training_schedule` = 'Training every Wednesday, 7–9 PM' WHERE `id` = 3;

-- Membership settings for each club (the committee changes these in Club settings)
UPDATE `clubs` SET `membership_open` = 1, `membership_price` = 30.00, `membership_months` = 6,
  `membership_benefits` = "Members-only sparring nights and team ladders\nPriority sign-up for the Semester Cup\nClub jersey at a member price" WHERE `id` = 1;
UPDATE `clubs` SET `membership_open` = 1, `membership_price` = 25.00, `membership_months` = 6,
  `membership_benefits` = "Weekly members-only scrimmages\nInter-varsity team trials" WHERE `id` = 2;
UPDATE `clubs` SET `membership_open` = 1, `membership_price` = 35.00, `membership_months` = 12,
  `membership_benefits` = "Free paddle loan at every session\nDoubles ladder ranking" WHERE `id` = 3;

-- Demo memberships
-- Liam: active member of Basketball (approved by Chloe a month ago, 5 months left)
-- Danial: Badminton membership that expired last month, so he can try Renew
-- Chloe: applied to Pickleball but hasn't paid yet, so she can try uploading a receipt
INSERT INTO `club_memberships` (`club_id`, `user_id`, `status`, `price_snapshot`, `months_snapshot`, `payment_instructions_snapshot`, `reviewed_by`, `reviewed_at`, `starts_at`, `expires_at`) VALUES
(2, 3, 'active', 25.00, 6, "Bank: CIMB Bank\nAccount Name: Taylor's Basketball Club\nAccount Number: 800987654321", 4, DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_ADD(NOW(), INTERVAL 5 MONTH)),
(1, 5, 'active', 30.00, 6, "Bank: Maybank\nAccount Name: Taylor's Badminton Club\nAccount Number: 514012345678", 2, DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH)),
(3, 4, 'awaiting_payment', 35.00, 12, "Bank: Maybank\nAccount Name: Taylor's Pickleball Club\nAccount Number: 514088239912", NULL, NULL, NULL, NULL);