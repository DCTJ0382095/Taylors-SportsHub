-- Draft for team review. Import into the sportshub database.
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    account_type ENUM('student', 'guest') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clubs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    duitnow_qr_path VARCHAR(255),
    membership_fee DECIMAL(8,2) UNSIGNED NOT NULL DEFAULT 0,
    membership_months SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    allow_guest_members BOOLEAN NOT NULL DEFAULT FALSE,
    president_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (president_id) REFERENCES users(id),
    CHECK (membership_months > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE club_staff (
    club_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    can_manage_events BOOLEAN NOT NULL DEFAULT FALSE,
    can_manage_memberships BOOLEAN NOT NULL DEFAULT FALSE,
    can_review_registrations BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (club_id, user_id),
    FOREIGN KEY (club_id) REFERENCES clubs(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED,
    host_user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    sport VARCHAR(80) NOT NULL,
    location VARCHAR(150) NOT NULL,
    cover_image_path VARCHAR(255),
    event_type ENUM('session', 'tournament') NOT NULL,
    audience ENUM('open', 'members_only') NOT NULL DEFAULT 'open',
    allow_guests BOOLEAN NOT NULL DEFAULT FALSE,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME,
    capacity INT UNSIGNED,
    min_team_size SMALLINT UNSIGNED,
    max_team_size SMALLINT UNSIGNED,
    max_teams INT UNSIGNED,
    team_fee DECIMAL(8,2) UNSIGNED,
    registration_closes_at DATETIME,
    member_price DECIMAL(8,2) UNSIGNED NOT NULL DEFAULT 0,
    student_price DECIMAL(8,2) UNSIGNED NOT NULL DEFAULT 0,
    guest_price DECIMAL(8,2) UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'cancelled') NOT NULL DEFAULT 'draft',
    FOREIGN KEY (club_id) REFERENCES clubs(id),
    FOREIGN KEY (host_user_id) REFERENCES users(id),
    CHECK (ends_at IS NULL OR ends_at > starts_at),
    CHECK (capacity IS NULL OR capacity > 0),
    CHECK (registration_closes_at IS NULL OR registration_closes_at <= starts_at),
    -- A roster minimum identifies a team tournament. Capacity then counts teams.
    CHECK (
        (min_team_size IS NULL AND max_team_size IS NULL
            AND max_teams IS NULL AND team_fee IS NULL)
        OR
        (club_id IS NOT NULL AND event_type = 'tournament' AND capacity IS NULL
            AND min_team_size IS NOT NULL AND min_team_size >= 2
            AND max_team_size IS NOT NULL AND max_team_size >= min_team_size
            AND max_teams IS NOT NULL AND max_teams > 0 AND team_fee IS NOT NULL)
    ),
    CHECK (club_id IS NOT NULL OR (audience = 'open' AND member_price = 0
        AND student_price = 0 AND guest_price = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE memberships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    amount_due DECIMAL(8,2) UNSIGNED NOT NULL,
    receipt_path VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    starts_at DATETIME,
    expires_at DATETIME,
    reviewed_by INT UNSIGNED,
    reviewed_at DATETIME,
    INDEX (club_id, user_id, status, expires_at),
    FOREIGN KEY (club_id) REFERENCES clubs(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CHECK (status <> 'approved' OR (starts_at IS NOT NULL
        AND expires_at IS NOT NULL AND expires_at > starts_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    captain_id INT UNSIGNED NOT NULL,
    UNIQUE (event_id, id),
    UNIQUE (event_id, name),
    FOREIGN KEY (event_id) REFERENCES events(id),
    FOREIGN KEY (captain_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    team_id INT UNSIGNED,
    amount_due DECIMAL(8,2) UNSIGNED NOT NULL,
    receipt_path VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED,
    reviewed_at DATETIME,
    UNIQUE (event_id, user_id),
    FOREIGN KEY (event_id) REFERENCES events(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (event_id, team_id) REFERENCES teams(event_id, id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
