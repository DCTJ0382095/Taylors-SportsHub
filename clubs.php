<?php
// Active clubs are listed with the number of upcoming published sessions for each club.
$page_title = "Sports Clubs";
require_once __DIR__ . '/includes/header.php';

// Clubs are sorted by name, and upcoming published sessions are counted for each club.
$db = get_db_connection();
$stmt = $db->query("SELECT c.*, 
                    (SELECT COUNT(*) FROM sessions s WHERE s.club_id = c.id AND s.status = 'published' AND CONCAT(s.session_date, ' ', s.start_time) >= NOW()) AS session_count
                    FROM clubs c 
                    WHERE c.status = 'active' 
                    ORDER BY c.name ASC");
$clubs = $stmt->fetchAll();
?>

<!-- The clubs page container markup is rendered. --><div class="clubs-page-container">
    <!-- The directory heading is displayed. -->
    <div class="clubs-header">
        <!-- The clubs eyebrow markup is rendered. --><div class="clubs-eyebrow">CAMPUS ORGANISATIONS</div>
        <h1 class="clubs-title">Taylor's Sports Clubs</h1>
        <p class="clubs-subtitle">
            Explore approved student sports clubs organizing regular campus sessions.
        </p>
    </div>

    <!-- Active club cards or an empty state are displayed. -->
    <?php /* An empty-directory message is displayed when no active clubs are found. */ if (empty($clubs)): ?>
        <div class="empty-state-box">
            <svg class="empty-state-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/></svg>
            <h3 class="empty-state-title">No clubs available</h3>
            <p class="empty-state-desc">
                Currently no sports clubs are active on Taylor's SportsHub.
            </p>
            <a href="<?= app_url('/sessions.php') ?>" class="btn-primary" style="display: inline-block; padding: 0.65rem 1.5rem; border-radius: 980px;">
                Explore Sessions →
            </a>
        </div>
    <?php /* Active clubs are displayed in the directory grid. */ else: ?>
        <!-- The clubs grid markup is rendered. --><div class="clubs-grid">
            <?php /* One directory card is rendered for each active club. */ foreach ($clubs as $club): 
                $club_banner = get_club_banner_url($club);
            ?>
                <!-- Club identity and upcoming session counts are displayed on each card. -->
                <a href="<?= app_url('/club.php?id=' . $club['id']) ?>" class="club-card-link">
                    <!-- The club card media markup is rendered. --><div class="club-card-media">
                        <img src="<?= e($club_banner) ?>" alt="<?= e($club['name']) ?>" loading="lazy">
                        <!-- The club card badge markup is rendered. --><div class="club-card-badge">
                            <?= e($club['sport_type']) ?>
                        </div>
                    </div>

                    <!-- The club card body markup is rendered. --><div class="club-card-body">
                        <!-- The content group markup is rendered. --><div>
                            <!-- The club card header markup is rendered. --><div class="club-card-header">
                                <h2 class="club-card-name">
                                    <?= e($club['name']) ?>
                                </h2>
                            </div>
                            <p class="club-card-desc">
                                <?= e($club['description']) ?>
                            </p>
                        </div>

                        <!-- The club card footer markup is rendered. --><div class="club-card-footer">
                            <span class="club-card-count">
                                <?= $club['session_count'] ?> active <?= $club['session_count'] === 1 ? 'session' : 'sessions' ?>
                            </span>
                            <span class="club-card-footer-arrow">
                                <span>View profile</span>
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
