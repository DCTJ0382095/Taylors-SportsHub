<?php
// Terms of use page. The content is static.
$page_title = "Terms of Use";
require_once __DIR__ . '/includes/header.php';
?>

<!-- The app container markup is rendered. --><div class="app-container">
    <!-- The legal layout markup is rendered. --><div class="legal-layout">
        <!-- The terms title, effective date and version are displayed. -->
        <header class="legal-header">
            <!-- The section label markup is rendered. --><div class="section-label">COMMUNITY STANDARDS & ATHLETICS CODE</div>
            <h1 class="legal-title">Terms of Use</h1>
            <!-- The legal meta markup is rendered. --><div class="legal-meta">
                Taylor's University Lakeside Campus · Effective Date: November 2026 · Version 1.0
            </div>
        </header>

        <!-- The community agreement and numbered terms are displayed. -->
        <article class="legal-card">
            <!-- The legal callout markup is rendered. --><div class="legal-callout">
                <strong>Campus sports community agreement:</strong> Taylor's SportsHub is designed to promote active lifestyles, sportsmanship, and student club activities. By using this portal, you agree to honor your session bookings, adhere to sports complex rules, and treat fellow athletes and organizers with mutual respect.
            </div>

            <!-- Acceptance of the terms is described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">01.</span> Acceptance of terms
                </h2>
                <p class="legal-text">
                    By accessing or registering through the <strong>Taylor's SportsHub</strong> platform, you agree to be bound by these Terms of Use, the Taylor's University Student Code of Conduct, and Sports Complex facility rules.
                </p>
            </section>

            <!-- Account eligibility is described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">02.</span> Eligibility & account authenticity
                </h2>
                <p class="legal-text">
                    Registration and booking privileges are reserved exclusively for currently enrolled students with valid <code>@sd.taylors.edu.my</code> email accounts. Students must provide accurate personal details and must not share credentials or impersonate other participants.
                </p>
            </section>

            <!-- Booking, payment and cancellation rules are described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">03.</span> Session bookings & payment proofs
                </h2>
                <!-- The legal list markup is rendered. --><ul class="legal-list">
                    <!-- The list entry markup is rendered. --><li><strong>Capacity limits:</strong> Session capacities are strictly enforced to ensure fair court play and participant safety.</li>
                    <!-- The list entry markup is rendered. --><li><strong>Payment deadlines:</strong> Paid session reservations require proof of transfer (DuitNow QR receipt) uploaded within the specified deadline. Failure to upload valid proof will result in automatic release of the reserved place.</li>
                    <!-- The list entry markup is rendered. --><li><strong>Cancellations:</strong> Unpaid reservations may be cancelled immediately. Paid reservations request a refund pending organizer review in accordance with published club refund policies.</li>
                </ul>
            </section>

            <!-- Sports-centre conduct and safety expectations are described. -->
            <section class="legal-section">
                <h2 class="legal-section-title">
                    <span class="legal-section-num">04.</span> Sports centre safety & etiquette
                </h2>
                <p class="legal-text">
                    All participants must wear appropriate non-marking sports footwear and athletic attire when utilizing Taylor's Lakeside Sports Centre courts and studios. Organizers reserve the right to deny court entry to participants who do not comply with safety standards.
                </p>
            </section>
        </article>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
