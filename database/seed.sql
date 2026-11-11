-- Taylor's SportsHub demo data
-- A small cast that covers every feature. Every account uses the same demo password as before (@hash below).
-- Dates are relative to today (CURDATE() and NOW()), so the demo never goes out of date.
--
-- The automated tests rely on a few things here, so keep them if you edit this file:
--   user 2 manages club 1 but not club 2; session 1 is "Sunday Open Play & Social Smash"
--   with 16 places and 1 taken; session 2 has 12 places and 2 taken; session 4 has no bookings;
--   registration 2 exists.
USE `tu_sportshub`;

-- ---------------------------------------------------------------------------
-- 1. Start empty (children before parents, because of the foreign keys)
-- ---------------------------------------------------------------------------
DELETE FROM `membership_receipts`;
DELETE FROM `club_memberships`;
DELETE FROM `payment_receipts`;
DELETE FROM `refund_records`;
DELETE FROM `registrations`;
DELETE FROM `sessions`;
DELETE FROM `club_managers`;
DELETE FROM `clubs`;
DELETE FROM `users`;

-- Values used many times below, so they're written once.
SET @hash = '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO';
SET @receipt = 'assets/uploads/receipts/demo-receipt.png';
SET @membership_receipt = 'assets/uploads/membership-receipts/demo-receipt.png';
SET @qr = 'assets/images/payment/duitnow-qr.webp';
SET @note = 'Scan the DuitNow QR with any banking or e-wallet app. Put your full name as the payment reference, then upload a screenshot of the receipt.';
SET @badminton_pay = CONCAT("Bank: Maybank\nAccount Name: Taylor's Badminton Club\nAccount Number: 514012345678\n\n", @note);
SET @basketball_pay = CONCAT("Bank: CIMB Bank\nAccount Name: Taylor's Basketball Club\nAccount Number: 800987654321\n\n", @note);

-- ---------------------------------------------------------------------------
-- 2. Accounts: one for each kind of person who uses SportsHub
-- ---------------------------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `status`, `phone`) VALUES
(1,  'SportsHub Admin', 'admin@taylors.edu.my',    @hash, 'admin',   'active',    '+60123456700'), -- full control of every club
(2,  'Alex Wong',       'alex@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456701'), -- president of Badminton
(3,  'Liam Tan',        'liam@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456702'), -- Badminton committee: sign-ups and payments only
(4,  'Chloe Lee',       'chloe@sd.taylors.edu.my', @hash, 'student', 'active',    '+60123456703'), -- president of Basketball AND Pickleball
(5,  'Danial Hakim',    'danial@sd.taylors.edu.my',@hash, 'student', 'active',    '+60123456704'), -- active Badminton member
(6,  'Sara Lim',        'sara@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456705'), -- Badminton committee: sessions only
(7,  'Mei Ling',        'mei@sd.taylors.edu.my',   @hash, 'student', 'active',    '+60123456706'), -- expired Badminton member
(8,  'Ravi Kumar',      'ravi@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456707'), -- not a member of any club
(9,  'Nur Aisyah',      'nur@sd.taylors.edu.my',   @hash, 'student', 'active',    '+60123456708'), -- brand new: nothing booked yet
(10, 'Jason Ng',        'jason@sd.taylors.edu.my', @hash, 'student', 'suspended', '+60123456709'); -- suspended: cannot log in

-- ---------------------------------------------------------------------------
-- 3. Clubs: two fully set up, one missing its payment details
-- ---------------------------------------------------------------------------
INSERT INTO `clubs` (`id`, `name`, `slug`, `description`, `sport_type`, `contact_email`, `instagram`, `training_schedule`,
                     `banner_path`, `bank_name`, `account_holder`, `account_number`, `payment_note`, `duitnow_qr_path`,
                     `membership_open`, `membership_price`, `membership_months`, `membership_benefits`, `status`) VALUES
(1, "Taylor's Badminton Club", 'taylors-badminton-club',
    "The official Taylor's University Badminton Club, running weekly open play and footwork clinics for every level.",
    'Badminton', 'badminton.club@taylors.edu.my', '@taylorsbadminton', 'Training every Tuesday, 8–10 PM',
    'assets/images/sports/badminton-banner.webp', 'Maybank', "Taylor's Badminton Club", '514012345678', @note, @qr,
    1, 30.00, 6, "Member price on every club session\nMembers-only footwork drills\nClub jersey at a member price", 'active'),
(2, "Taylor's Basketball Club", 'taylors-basketball-club',
    "Taylor's University Basketball Club, bringing campus hoopers together for open runs and skills clinics.",
    'Basketball', 'basketball.club@taylors.edu.my', '@taylorsbasketball', 'Training every Friday, 7–10 PM',
    'assets/images/sports/basketball-banner.webp', 'CIMB Bank', "Taylor's Basketball Club", '800987654321', @note, @qr,
    1, 25.00, 6, "Member price on every club session\nWeekly members-only open runs", 'active'),
-- Pickleball has NO QR or bank details yet: it can't run sessions or paid memberships until its president adds them.
(3, "Taylor's Pickleball Club", 'taylors-pickleball-club',
    "Taylor's newest racket club: social rallies and coaching for beginners and regulars alike.",
    'Pickleball', 'pickleball.club@taylors.edu.my', '@taylorspickleball', 'Training every Wednesday, 7–9 PM',
    'assets/images/sports/pickleball-banner.webp', NULL, NULL, NULL, NULL, NULL,
    0, 35.00, 12, "Member price on every club session\nFree paddle loan at every session", 'active');

-- ---------------------------------------------------------------------------
-- 4. Committees
-- ---------------------------------------------------------------------------
INSERT INTO `club_managers` (`id`, `club_id`, `user_id`, `appointed_by`, `role`, `can_manage_sessions`, `can_manage_signups`, `can_edit_club`) VALUES
(1, 1, 2, 1, 'president', 1, 1, 1),  -- Alex: president of Badminton (appointed by the admin)
(2, 1, 3, 2, 'committee', 0, 1, 0),  -- Liam: Badminton, sign-ups and payments only (appointed by Alex)
(3, 2, 4, 1, 'president', 1, 1, 1),  -- Chloe: president of Basketball
(4, 3, 4, 1, 'president', 1, 1, 1),  -- Chloe: also president of Pickleball
(5, 1, 6, 2, 'committee', 1, 0, 0);  -- Sara: Badminton, sessions only (appointed by Alex)

-- ---------------------------------------------------------------------------
-- 5. Sessions: one of each kind
-- ---------------------------------------------------------------------------
INSERT INTO `sessions` (`id`, `club_id`, `title`, `description`, `sport_type`, `venue`, `image_path`,
                        `session_date`, `start_time`, `end_time`, `price`, `member_price`, `capacity`, `status`, `cancellation_reason`, `created_by`) VALUES
-- free, published (the tests use this one)
(1, 1, 'Sunday Open Play & Social Smash', "Casual doubles and singles across three courts.\nShuttles provided. All levels welcome.",
    'Badminton', "Taylor's Sports Complex Court 1-3", 'assets/images/sports/badminton-open-play.webp',
    DATE_ADD(CURDATE(), INTERVAL 4 DAY), '14:00:00', '16:00:00', 0.00, NULL, 16, 'published', NULL, 2),
-- paid with a member price
(2, 1, 'Weekly Badminton Intensive & Footwork Clinic', "Two hours of footwork drills, net play and match tactics with senior players.",
    'Badminton', 'Multi-Purpose Hall Court A', 'assets/images/sports/badminton-footwork.webp',
    DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00', '20:00:00', 15.00, 10.00, 12, 'published', NULL, 6),
-- draft
(3, 1, 'Saturday Doubles Match Play', "Rotating doubles games across four courts, grouped by level so every game is close.",
    'Badminton', "Taylor's Sports Complex Court 1-4", 'assets/images/sports/sports-complex-banner.webp',
    DATE_ADD(CURDATE(), INTERVAL 16 DAY), '09:00:00', '13:00:00', 25.00, 20.00, 24, 'draft', NULL, 6),
-- paid with a member price, no bookings (the tests add their own)
(4, 2, 'Friday Night 3v3 Half-Court Run', "3v3 runs with winner-stays-on rotation. Bring a light and a dark shirt.",
    'Basketball', 'Outdoor Basketball Court 2', 'assets/images/sports/basketball-run.webp',
    DATE_ADD(CURDATE(), INTERVAL 6 DAY), '19:00:00', '21:00:00', 10.00, 6.00, 18, 'published', NULL, 4),
-- sign-up closed, no member price
(5, 2, 'Basketball Shooting & Skills Clinic', "Form shooting, ball handling and finishing drills in small groups.",
    'Basketball', 'Indoor Court B', 'assets/images/sports/student-athletes-banner.webp',
    DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:00:00', '19:00:00', 12.00, NULL, 10, 'closed', NULL, 4),
-- cancelled by the club, with refunds
(6, 1, 'Beginner Footwork Basics', "An easy-paced introduction to badminton footwork.",
    'Badminton', 'Court 3', 'assets/images/sports/badminton-footwork.webp',
    DATE_ADD(CURDATE(), INTERVAL 5 DAY), '18:00:00', '19:30:00', 15.00, 10.00, 12, 'cancelled',
    'Court 3 is closed for floor repairs that week.', 6),
-- already happened
(7, 1, 'Monday Night Smash', "A fast evening session of doubles games.",
    'Badminton', "Taylor's Sports Complex Court 1-3", 'assets/images/sports/badminton-open-play.webp',
    DATE_SUB(CURDATE(), INTERVAL 3 DAY), '20:00:00', '22:00:00', 8.00, 5.00, 16, 'published', NULL, 2),
-- free and full (2 places, 2 taken)
(8, 2, 'Lunchtime Shootaround', "A relaxed half hour of shooting. Just turn up and play.",
    'Basketball', 'Outdoor Basketball Court 1', 'assets/images/sports/basketball-run.webp',
    DATE_ADD(CURDATE(), INTERVAL 2 DAY), '12:30:00', '13:30:00', 0.00, NULL, 2, 'published', NULL, 4),
-- draft that can't be published until Pickleball adds its payment details
(9, 3, 'Pickleball Social Rally', "Round-robin social games. Paddles and balls provided.",
    'Pickleball', "Taylor's Sports Complex Multi-court 2", 'assets/images/sports/pickleball-rally.webp',
    DATE_ADD(CURDATE(), INTERVAL 8 DAY), '19:00:00', '21:00:00', 10.00, NULL, 16, 'draft', NULL, 4);

-- ---------------------------------------------------------------------------
-- 6. Session bookings: every status once
-- ---------------------------------------------------------------------------
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `status`, `price_snapshot`, `payment_instructions_snapshot`,
                             `payment_deadline`, `receipt_path`, `receipt_uploaded_at`, `rejection_reason`,
                             `cancellation_requested_at`, `cancellation_reason`,
                             `recipient_bank_name`, `recipient_account_name`, `recipient_account_number`) VALUES
-- confirmed, free
(1,  1, 5, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- receipt waiting for review (Liam or Alex can review it)
(2,  2, 8, 'awaiting_approval',    15.00, @badminton_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), @receipt, DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, NULL, NULL, NULL, NULL, NULL),
-- reserved, not paid yet (normal price: Mei's membership has expired)
(3,  2, 7, 'awaiting_payment',     15.00, @badminton_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- confirmed, paid and approved
(4,  5, 7, 'confirmed',            12.00, @basketball_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL, NULL, NULL, NULL, NULL),
-- receipt rejected, can resubmit
(5,  5, 5, 'payment_rejected',     12.00, @basketball_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), @receipt, DATE_SUB(NOW(), INTERVAL 1 DAY),
     'The receipt shows RM 10, but this session costs RM 12.', NULL, NULL, NULL, NULL, NULL),
-- session cancelled: refunded in full, so the booking is cancelled
(6,  6, 8, 'cancelled',            15.00, @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL,
     DATE_SUB(NOW(), INTERVAL 2 DAY), 'Court 3 is closed for floor repairs that week.', 'Maybank', 'Ravi Kumar', '164012345678'),
-- session cancelled: RM 4 of RM 10 refunded so far, so still pending
(7,  6, 5, 'cancellation_pending', 10.00, @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL,
     DATE_SUB(NOW(), INTERVAL 2 DAY), 'Court 3 is closed for floor repairs that week.', 'CIMB Bank', 'Danial Hakim', '7012345678'),
-- a past session Danial attended at the member price (History tab)
(8,  7, 5, 'confirmed',            5.00,  @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 5 DAY), NULL, NULL, NULL, NULL, NULL, NULL),
-- a past session Ravi reserved but never paid for
(9,  7, 8, 'expired',              8.00,  @badminton_pay, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- the full free session
(10, 8, 3, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 8, 8, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- Who reviewed each receipt, and what they decided
INSERT INTO `payment_receipts` (`registration_id`, `receipt_path`, `submitted_at`, `decision`, `rejection_reason`, `reviewed_by`, `reviewed_at`) VALUES
(2, @receipt, DATE_SUB(NOW(), INTERVAL 2 HOUR), 'awaiting_review', NULL, NULL, NULL),
(4, @receipt, DATE_SUB(NOW(), INTERVAL 3 DAY),  'approved', NULL, 4, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, @receipt, DATE_SUB(NOW(), INTERVAL 1 DAY),  'rejected', 'The receipt shows RM 10, but this session costs RM 12.', 4, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(6, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY),  'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY),  'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(8, @receipt, DATE_SUB(NOW(), INTERVAL 5 DAY),  'approved', NULL, 2, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- Refunds paid back outside SportsHub and recorded by the committee
INSERT INTO `refund_records` (`registration_id`, `amount`, `reason`, `recorded_by`) VALUES
(6, 15.00, 'Session cancelled: full refund.', 2),
(7,  4.00, 'Session cancelled: first part of the refund.', 3);

-- ---------------------------------------------------------------------------
-- 7. Club memberships: every status once
-- ---------------------------------------------------------------------------
INSERT INTO `club_memberships` (`id`, `club_id`, `user_id`, `status`, `price_snapshot`, `months_snapshot`, `payment_instructions_snapshot`,
                                `receipt_path`, `receipt_uploaded_at`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `starts_at`, `expires_at`) VALUES
-- active: Danial pays member prices at Badminton
(1, 1, 5, 'active',            30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 32 DAY), NULL, 2,
    DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_ADD(NOW(), INTERVAL 5 MONTH)),
-- expired last month: Mei can renew
(2, 1, 7, 'active',            30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 7 MONTH), NULL, 3,
    DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH)),
-- receipt waiting for review (Membership Sign-ups tab)
(3, 1, 8, 'awaiting_approval', 30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL, NULL,
    NULL, NULL, NULL),
-- receipt rejected: Mei can upload a new one
(4, 2, 7, 'payment_rejected',  25.00, 6, @basketball_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 2 DAY),
    'Wrong amount: Basketball membership is RM 25.', 4, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL),
-- applied, not paid yet
(5, 2, 5, 'awaiting_payment',  25.00, 6, @basketball_pay, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

INSERT INTO `membership_receipts` (`membership_id`, `receipt_path`, `submitted_at`, `decision`, `rejection_reason`, `reviewed_by`, `reviewed_at`) VALUES
(1, @membership_receipt, DATE_SUB(NOW(), INTERVAL 32 DAY), 'approved', NULL, 2, DATE_SUB(NOW(), INTERVAL 1 MONTH)),
(2, @membership_receipt, DATE_SUB(NOW(), INTERVAL 7 MONTH), 'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 7 MONTH)),
(3, @membership_receipt, DATE_SUB(NOW(), INTERVAL 3 HOUR), 'awaiting_review', NULL, NULL, NULL),
(4, @membership_receipt, DATE_SUB(NOW(), INTERVAL 2 DAY), 'rejected', 'Wrong amount: Basketball membership is RM 25.', 4, DATE_SUB(NOW(), INTERVAL 1 DAY));