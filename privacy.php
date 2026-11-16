<?php
// Privacy policy page. The content is static and written around Malaysia's PDPA 2010.
$page_title = "Privacy Policy";
require_once __DIR__ . '/includes/header.php';
?>

<!-- The app container markup is rendered. --><div class="app-container">
    <!-- The legal layout markup is rendered. --><div class="legal-layout">
        <!-- The policy title, effective date and version are displayed. -->
        <header class="legal-header">
            <!-- The section label markup is rendered. --><div class="section-label">LEGAL & COMPLIANCE / PDPA 2010</div>
            <h1 class="legal-title">Privacy Policy</h1>
            <!-- The legal meta markup is rendered. --><div class="legal-meta">
                Taylor's University Lakeside Campus · Effective Date: November 2026 · Version 1.0
            </div>
        </header>

        <!-- The privacy summary and numbered policy sections are displayed. -->
        <article class="legal-card">
            <!-- The legal callout markup is rendered. --><div class="legal-callout">
                <strong>Summary for students:</strong> We only collect minimal personal information necessary to manage campus sports session registrations, coordinate court access, process DuitNow verification, and ensure student safety at Lakeside Campus Sports Centre. We never sell your personal data.
            </div>

            <!-- Privacy commitments and the referenced legal framework are described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">01.</span> Commitment to your privacy
                </h2>
                <p class="legal-text">
                    Taylor's University ("Taylor's", "we", "us", or "our") values the privacy of our students, athletes, club committees, and campus sports community. This Privacy Policy outlines how your personal data is collected, stored, processed, and safeguarded when using the <strong>Taylor's SportsHub</strong> platform.
                </p>
                <p class="legal-text">
                    This policy is formulated in accordance with the <strong>Personal Data Protection Act 2010 (PDPA)</strong> of Malaysia and relevant institutional policies of Taylor's Higher Education.
                </p>
            </section>

            <!-- Collected personal data is described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">02.</span> Personal data we collect
                </h2>
                <p class="legal-text">
                    When you create an account, register for sports sessions, or upload payment verification receipts on this platform, we may collect:
                </p>
                <!-- The legal list markup is rendered. --><ul class="legal-list">
                    <!-- The list entry markup is rendered. --><li><strong>Account identification:</strong> Full name, official Taylor's student email address (<code>@sd.taylors.edu.my</code>), student ID number, and secure password hash.</li>
                    <!-- The list entry markup is rendered. --><li><strong>Contact information:</strong> Contact phone number for court notifications, weather updates, and waitlist allocations.</li>
                    <!-- The list entry markup is rendered. --><li><strong>Session records:</strong> Registration history, attendance confirmations, voluntary cancellations, and refund records.</li>
                    <!-- The list entry markup is rendered. --><li><strong>Payment evidence:</strong> Transfer receipts, transaction reference numbers, and private bank refund details submitted exclusively for organizer settlement.</li>
                </ul>
            </section>

            <!-- Data-processing purposes are described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">03.</span> Purpose of data processing
                </h2>
                <p class="legal-text">
                    Your personal information is used exclusively for sports operations:
                </p>
                <!-- The legal list markup is rendered. --><ul class="legal-list">
                    <!-- The list entry markup is rendered. --><li>Validating enrolled student status for campus sports facilities access.</li>
                    <!-- The list entry markup is rendered. --><li>Managing session capacities, court allocation limits, and reservation windows.</li>
                    <!-- The list entry markup is rendered. --><li>Enabling club managers to verify DuitNow transfers and authorize player rosters.</li>
                    <!-- The list entry markup is rendered. --><li>Facilitating direct refunds when sessions are cancelled by organizers or students voluntarily cancel within refund windows.</li>
                </ul>
            </section>

            <!-- Privacy contact details are provided. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">04.</span> Contact & inquiries
                </h2>
                <p class="legal-text">
                    For inquiries regarding data protection or personal data access requests, please contact Taylor's Lakeside Sports Centre Administration or the Student Experience Department at <code>sportscentre@taylors.edu.my</code>.
                </p>
            </section>
        </article>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
