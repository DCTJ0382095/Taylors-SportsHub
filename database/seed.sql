-- Synthetic SportsHub demonstration data is prepared.
-- A shared demonstration password hash is assigned to the seeded accounts.
-- Demonstration dates are calculated relative to seed execution time.
--
-- Stable identifiers and fixture relationships are retained for automated checks.
-- Club 1 management is assigned to user 2 and the named open-play session is assigned ID 1.
-- Session 1 is seeded with sixteen places and one registration, and session 2 with twelve places and two registrations.
-- Session 4 is left unbooked and registration ID 2 is supplied.
USE `tu_sportshub`;

--
-- Existing fixture data is removed in child-before-parent order.
--
DELETE FROM `membership_receipts`;
-- Existing club_memberships records are removed before reseeding.
DELETE FROM `club_memberships`;
-- Existing payment_receipts records are removed before reseeding.
DELETE FROM `payment_receipts`;
-- Existing refund_records records are removed before reseeding.
DELETE FROM `refund_records`;
-- Existing registrations records are removed before reseeding.
DELETE FROM `registrations`;
-- Existing sessions records are removed before reseeding.
DELETE FROM `sessions`;
-- Existing club_managers records are removed before reseeding.
DELETE FROM `club_managers`;
-- Existing clubs records are removed before reseeding.
DELETE FROM `clubs`;
-- Existing users records are removed before reseeding.
DELETE FROM `users`;

-- Repeated password, image and payment values are assigned to SQL variables.
SET @hash = '$2y$10$OCr7hFX/AMZBn.PsFEehs.86WDY0A8U85s1hlaSZlLLiFUIKY42VO';
-- The @receipt seed value is assigned.
SET @receipt = 'assets/uploads/receipts/demo-receipt.png';
-- The @membership_receipt seed value is assigned.
SET @membership_receipt = 'assets/uploads/membership-receipts/demo-receipt.png';
-- The @qr seed value is assigned.
SET @qr = 'assets/images/payment/duitnow-qr.webp';
-- The @note seed value is assigned.
SET @note = 'Scan the DuitNow QR with any banking or e-wallet app. Put your full name as the payment reference, then upload a screenshot of the receipt.';
-- The @badminton_pay seed value is assigned.
SET @badminton_pay = CONCAT("Bank: Maybank\nAccount Name: Taylor's Badminton Club\nAccount Number: 514012345678\n\n", @note);
-- The @basketball_pay seed value is assigned.
SET @basketball_pay = CONCAT("Bank: CIMB Bank\nAccount Name: Taylor's Basketball Club\nAccount Number: 800987654321\n\n", @note);

--
-- Representative account roles and membership states are seeded.
--
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `status`, `phone`) VALUES
(1,  'SportsHub Admin', 'admin@taylors.edu.my',    @hash, 'admin',   'active',    '+60123456700'), -- Full club control is granted through the admin role.
(2,  'Alex Wong',       'alex@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456701'), -- Badminton presidency is assigned to Alex through the committee records below.
(3,  'Liam Tan',        'liam@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456702'), -- Badminton sign-ups access is assigned to Liam through the committee records below.
(4,  'Chloe Lee',       'chloe@sd.taylors.edu.my', @hash, 'student', 'active',    '+60123456703'), -- Basketball and Pickleball presidency is assigned to Chloe below.
(5,  'Danial Hakim',    'danial@sd.taylors.edu.my',@hash, 'student', 'active',    '+60123456704'), -- An active Badminton membership is assigned to Danial below.
(6,  'Sara Lim',        'sara@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456705'), -- Badminton sessions access is assigned to Sara below.
(7,  'Mei Ling',        'mei@sd.taylors.edu.my',   @hash, 'student', 'active',    '+60123456706'), -- An expired Badminton membership is assigned to Mei below.
(8,  'Ravi Kumar',      'ravi@sd.taylors.edu.my',  @hash, 'student', 'active',    '+60123456707'), -- Pending membership review is represented by Ravi's application below.
(9,  'Nur Aisyah',      'nur@sd.taylors.edu.my',   @hash, 'student', 'active',    '+60123456708'), -- Nur is seeded without bookings or membership applications.
(10, 'Jason Ng',        'jason@sd.taylors.edu.my', @hash, 'student', 'suspended', '+60123456709'); -- Login is blocked through suspended status.

--
-- Two payment-ready clubs and one incomplete club are seeded.
--
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
-- Pickleball payment details are left empty to exercise payment-readiness restrictions.
(3, "Taylor's Pickleball Club", 'taylors-pickleball-club',
    "Taylor's newest racket club: social rallies and coaching for beginners and regulars alike.",
    'Pickleball', 'pickleball.club@taylors.edu.my', '@taylorspickleball', 'Training every Wednesday, 7–9 PM',
    'assets/images/sports/pickleball-banner.webp', NULL, NULL, NULL, NULL, NULL,
    0, 35.00, 12, "Member price on every club session\nFree paddle loan at every session", 'active');

--
-- Committee appointments and scoped permissions are seeded.
--
INSERT INTO `club_managers` (`id`, `club_id`, `user_id`, `appointed_by`, `role`, `title`, `can_manage_sessions`, `can_manage_signups`, `can_edit_club`) VALUES
(1, 1, 2, 1, 'president', NULL,        1, 1, 1),  -- Alex is appointed Badminton president by the admin.
(2, 1, 3, 2, 'committee', 'Treasurer', 0, 1, 0),  -- Liam is appointed with Badminton sign-ups permission by Alex.
(3, 2, 4, 1, 'president', NULL,        1, 1, 1),  -- Chloe is appointed Basketball president by the admin.
(4, 3, 4, 1, 'president', NULL,        1, 1, 1),  -- Chloe is appointed Pickleball president by the admin.
(5, 1, 6, 2, 'committee', 'Secretary', 1, 0, 0);  -- Sara is appointed with Badminton sessions permission by Alex.

--
-- Representative session pricing and publication states are seeded.
--
INSERT INTO `sessions` (`id`, `club_id`, `title`, `description`, `sport_type`, `venue`, `image_path`,
                        `session_date`, `start_time`, `end_time`, `price`, `member_price`, `capacity`, `status`, `cancellation_reason`, `created_by`) VALUES
-- A free published session is supplied for automated checks.
(1, 1, 'Sunday Open Play & Social Smash', "Casual doubles and singles across three courts.\nShuttles provided. All levels welcome.",
    'Badminton', "Taylor's Sports Complex Court 1-3", 'assets/images/sports/badminton-open-play.webp',
    DATE_ADD(CURDATE(), INTERVAL 4 DAY), '14:00:00', '16:00:00', 0.00, NULL, 16, 'published', NULL, 2),
-- A paid session is supplied with a member discount.
(2, 1, 'Weekly Badminton Intensive & Footwork Clinic', "Two hours of footwork drills, net play and match tactics with senior players.",
    'Badminton', 'Multi-Purpose Hall Court A', 'assets/images/sports/badminton-footwork.webp',
    DATE_ADD(CURDATE(), INTERVAL 9 DAY), '18:00:00', '20:00:00', 15.00, 10.00, 12, 'published', NULL, 6),
-- An editable draft session is supplied.
(3, 1, 'Saturday Doubles Match Play', "Rotating doubles games across four courts, grouped by level so every game is close.",
    'Badminton', "Taylor's Sports Complex Court 1-4", 'assets/images/sports/sports-complex-banner.webp',
    DATE_ADD(CURDATE(), INTERVAL 16 DAY), '09:00:00', '13:00:00', 25.00, 20.00, 24, 'draft', NULL, 6),
-- A discounted paid session is supplied without registrations.
(4, 2, 'Friday Night 3v3 Half-Court Run', "3v3 runs with winner-stays-on rotation. Bring a light and a dark shirt.",
    'Basketball', 'Outdoor Basketball Court 2', 'assets/images/sports/basketball-run.webp',
    DATE_ADD(CURDATE(), INTERVAL 6 DAY), '19:00:00', '21:00:00', 10.00, 6.00, 18, 'published', NULL, 4),
-- Signup is closed and no member discount is assigned.
(5, 2, 'Basketball Shooting & Skills Clinic', "Form shooting, ball handling and finishing drills in small groups.",
    'Basketball', 'Indoor Court B', 'assets/images/sports/student-athletes-banner.webp',
    DATE_ADD(CURDATE(), INTERVAL 3 DAY), '17:00:00', '19:00:00', 12.00, NULL, 10, 'closed', NULL, 4),
-- Session cancellation is represented with refund records below.
(6, 1, 'Beginner Footwork Basics', "An easy-paced introduction to badminton footwork.",
    'Badminton', 'Court 3', 'assets/images/sports/badminton-footwork.webp',
    DATE_ADD(CURDATE(), INTERVAL 5 DAY), '18:00:00', '19:30:00', 15.00, 10.00, 12, 'cancelled',
    'Court 3 is closed for floor repairs that week.', 6),
-- A past published session is supplied.
(7, 1, 'Monday Night Smash', "A fast evening session of doubles games.",
    'Badminton', "Taylor's Sports Complex Court 1-3", 'assets/images/sports/badminton-open-play.webp',
    DATE_SUB(CURDATE(), INTERVAL 3 DAY), '20:00:00', '22:00:00', 8.00, 5.00, 16, 'published', NULL, 2),
-- A free two-place session is fully occupied by registrations below.
(8, 2, 'Lunchtime Shootaround', "A relaxed half hour of shooting. Just turn up and play.",
    'Basketball', 'Outdoor Basketball Court 1', 'assets/images/sports/basketball-run.webp',
    DATE_ADD(CURDATE(), INTERVAL 2 DAY), '12:30:00', '13:30:00', 0.00, NULL, 2, 'published', NULL, 4),
-- A Pickleball draft is supplied with publication blocked by incomplete payment setup.
(9, 3, 'Pickleball Social Rally', "Round-robin social games. Paddles and balls provided.",
    'Pickleball', "Taylor's Sports Complex Multi-court 2", 'assets/images/sports/pickleball-rally.webp',
    DATE_ADD(CURDATE(), INTERVAL 8 DAY), '19:00:00', '21:00:00', 10.00, NULL, 16, 'draft', NULL, 4);

--
-- Representative session registration states are seeded.
--
INSERT INTO `registrations` (`id`, `session_id`, `user_id`, `status`, `price_snapshot`, `payment_instructions_snapshot`,
                             `payment_deadline`, `receipt_path`, `receipt_uploaded_at`, `rejection_reason`,
                             `cancellation_requested_at`, `cancellation_reason`,
                             `recipient_bank_name`, `recipient_account_name`, `recipient_account_number`) VALUES
-- A free place is confirmed.
(1,  1, 5, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- A submitted payment receipt is retained for review.
(2,  2, 8, 'awaiting_approval',    15.00, @badminton_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), @receipt, DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL, NULL, NULL, NULL, NULL, NULL),
-- The normal price is reserved after membership expiry.
(3,  2, 7, 'awaiting_payment',     15.00, @badminton_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- An approved paid place is confirmed.
(4,  5, 7, 'confirmed',            12.00, @basketball_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL, NULL, NULL, NULL, NULL),
-- A rejected receipt is supplied with a resubmission deadline.
(5,  5, 5, 'payment_rejected',     12.00, @basketball_pay, DATE_ADD(NOW(), INTERVAL 1 DAY), @receipt, DATE_SUB(NOW(), INTERVAL 1 DAY),
     'The receipt shows RM 10, but this session costs RM 12.', NULL, NULL, NULL, NULL, NULL),
-- The booking is cancelled after a full refund.
(6,  6, 8, 'cancelled',            15.00, @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL,
     DATE_SUB(NOW(), INTERVAL 2 DAY), 'Court 3 is closed for floor repairs that week.', 'Maybank', 'Ravi Kumar', '164012345678'),
-- Cancellation is retained as pending after a partial refund.
(7,  6, 5, 'cancellation_pending', 10.00, @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL,
     DATE_SUB(NOW(), INTERVAL 2 DAY), 'Court 3 is closed for floor repairs that week.', 'CIMB Bank', 'Danial Hakim', '7012345678'),
-- A past confirmed booking is supplied at the member price.
(8,  7, 5, 'confirmed',            5.00,  @badminton_pay, NULL, @receipt, DATE_SUB(NOW(), INTERVAL 5 DAY), NULL, NULL, NULL, NULL, NULL, NULL),
-- A past unpaid booking is marked expired.
(9,  7, 8, 'expired',              8.00,  @badminton_pay, DATE_SUB(NOW(), INTERVAL 4 DAY), NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
-- Both places in the free session are confirmed.
(10, 8, 3, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 8, 8, 'confirmed',            0.00,  'Free session', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- Receipt decisions and reviewer attribution are seeded.
INSERT INTO `payment_receipts` (`registration_id`, `receipt_path`, `submitted_at`, `decision`, `rejection_reason`, `reviewed_by`, `reviewed_at`) VALUES
(2, @receipt, DATE_SUB(NOW(), INTERVAL 2 HOUR), 'awaiting_review', NULL, NULL, NULL),
(4, @receipt, DATE_SUB(NOW(), INTERVAL 3 DAY),  'approved', NULL, 4, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, @receipt, DATE_SUB(NOW(), INTERVAL 1 DAY),  'rejected', 'The receipt shows RM 10, but this session costs RM 12.', 4, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(6, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY),  'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(7, @receipt, DATE_SUB(NOW(), INTERVAL 4 DAY),  'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(8, @receipt, DATE_SUB(NOW(), INTERVAL 5 DAY),  'approved', NULL, 2, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- External refund transfers are represented through recorded amounts.
INSERT INTO `refund_records` (`registration_id`, `amount`, `reason`, `recorded_by`) VALUES
(6, 15.00, 'Session cancelled: full refund.', 2),
(7,  4.00, 'Session cancelled: first part of the refund.', 3);

--
-- Representative membership application and expiry states are seeded.
--
INSERT INTO `club_memberships` (`id`, `club_id`, `user_id`, `status`, `price_snapshot`, `months_snapshot`, `payment_instructions_snapshot`,
                                `receipt_path`, `receipt_uploaded_at`, `rejection_reason`, `reviewed_by`, `reviewed_at`, `starts_at`, `expires_at`) VALUES
-- An active Badminton membership is assigned to Danial.
(1, 1, 5, 'active',            30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 32 DAY), NULL, 2,
    DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH), DATE_ADD(NOW(), INTERVAL 5 MONTH)),
-- An expired membership is assigned to Mei for renewal checks.
(2, 1, 7, 'active',            30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 7 MONTH), NULL, 3,
    DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 7 MONTH), DATE_SUB(NOW(), INTERVAL 1 MONTH)),
-- A submitted membership receipt is retained for review.
(3, 1, 8, 'awaiting_approval', 30.00, 6, @badminton_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL, NULL,
    NULL, NULL, NULL),
-- A rejected membership receipt is supplied for replacement checks.
(4, 2, 7, 'payment_rejected',  25.00, 6, @basketball_pay, @membership_receipt, DATE_SUB(NOW(), INTERVAL 2 DAY),
    'Wrong amount: Basketball membership is RM 25.', 4, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, NULL),
-- An unpaid membership application is supplied.
(5, 2, 5, 'awaiting_payment',  25.00, 6, @basketball_pay, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- Seed records are inserted into membership_receipts.
INSERT INTO `membership_receipts` (`membership_id`, `receipt_path`, `submitted_at`, `decision`, `rejection_reason`, `reviewed_by`, `reviewed_at`) VALUES
(1, @membership_receipt, DATE_SUB(NOW(), INTERVAL 32 DAY), 'approved', NULL, 2, DATE_SUB(NOW(), INTERVAL 1 MONTH)),
(2, @membership_receipt, DATE_SUB(NOW(), INTERVAL 7 MONTH), 'approved', NULL, 3, DATE_SUB(NOW(), INTERVAL 7 MONTH)),
(3, @membership_receipt, DATE_SUB(NOW(), INTERVAL 3 HOUR), 'awaiting_review', NULL, NULL, NULL),
(4, @membership_receipt, DATE_SUB(NOW(), INTERVAL 2 DAY), 'rejected', 'Wrong amount: Basketball membership is RM 25.', 4, DATE_SUB(NOW(), INTERVAL 1 DAY));