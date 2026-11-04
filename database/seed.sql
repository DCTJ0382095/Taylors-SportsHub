-- Import once into an empty schema. Demo password for all accounts: password.
START TRANSACTION;

SET @demo_hash = '$2y$10$Qc8Kx8/aD/OeVaQX.taROuSJ/eRdycd6r.smZl88/chA2ydUGSnjS';
INSERT INTO users (id, name, email, password_hash, account_type) VALUES
    (1, 'Demo President', 'president@example.test', @demo_hash, 'student'),
    (2, 'Demo Member', 'member@example.test', @demo_hash, 'student'),
    (3, 'Demo Student', 'student@example.test', @demo_hash, 'student'),
    (4, 'Demo Guest', 'guest@example.test', @demo_hash, 'guest');

INSERT INTO clubs (id, name, description, membership_fee, allow_guest_members, president_id)
VALUES (1, 'Badminton Club', 'Social badminton and campus tournaments.', 30, TRUE, 1);

INSERT INTO club_staff (club_id, user_id, can_manage_events)
VALUES (1, 2, TRUE);

INSERT INTO memberships (club_id, user_id, amount_due, status, starts_at, expires_at,
    reviewed_by, reviewed_at)
VALUES (1, 2, 30, 'approved', NOW(), DATE_ADD(NOW(), INTERVAL 12 MONTH), 1, NOW());

SET @event_start = TIMESTAMP(DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY), '17:00:00');
SET @event_end = DATE_ADD(@event_start, INTERVAL 2 HOUR);

INSERT INTO events (id, club_id, host_user_id, title, description, sport, location,
    event_type, audience, allow_guests, starts_at, ends_at, capacity,
    member_price, student_price, guest_price, status) VALUES
    (1, 1, 1, 'Social Badminton', 'All skill levels welcome.', 'Badminton',
        'Campus sports hall', 'session', 'open', TRUE, @event_start, @event_end,
        20, 5, 10, 15, 'published'),
    (3, NULL, 3, 'Student Practice', 'Free practice hosted by a student.', 'Badminton',
        'Campus court', 'session', 'open', FALSE, DATE_ADD(@event_start, INTERVAL 2 DAY),
        NULL, NULL, 0, 0, 0, 'published');

INSERT INTO events (id, club_id, host_user_id, title, description, sport, location,
    event_type, audience, starts_at, ends_at, min_team_size, max_team_size,
    max_teams, team_fee, registration_closes_at, status)
VALUES (2, 1, 1, 'Club Doubles', 'Members-only doubles tournament.', 'Badminton',
    'Campus sports hall', 'tournament', 'members_only', DATE_ADD(@event_start, INTERVAL 1 DAY),
    DATE_ADD(@event_end, INTERVAL 1 DAY), 2, 2, 8, 20, @event_start, 'published');

INSERT INTO registrations (event_id, user_id, amount_due, status)
VALUES (3, 2, 0, 'approved');

COMMIT;
