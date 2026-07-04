<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>SBI-ABC - Smart Bite Care</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        /* =========================================
           LANDING PAGE - SBI-ABC
           Color Scheme: #2B3A8C (primary), #F21D2F (accent)
           ========================================= */
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1a235a;
            --primary-light: #3a4b9e;
            --accent: #F21D2F;
            --accent-hover: #c9182a;
            --bg-light: #f0f3fc;
            --text-dark: #1a2340;
            --text-muted: #5a6a8a;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            background: white;
            overflow-x: hidden;
        }

        /* ---- PAW DECORATIONS ---- */
        .paw-print {
            position: absolute;
            opacity: 0.06;
            font-size: 60px;
            color: var(--primary);
            pointer-events: none;
            transform: rotate(var(--rotation, 0deg));
            z-index: 1;
        }
        .paw-print-1 { top: 5%; left: 3%; --rotation: -15deg; }
        .paw-print-2 { bottom: 10%; right: 2%; --rotation: 25deg; font-size: 80px; }
        .paw-print-3 { top: 20%; right: 8%; --rotation: 10deg; font-size: 40px; }
        .paw-print-4 { bottom: 15%; left: 5%; --rotation: -5deg; font-size: 50px; }
        .paw-print-5 { top: 45%; left: 50%; --rotation: 45deg; font-size: 35px; }

        /* ---- BUTTONS ---- */
        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 40px;
            font-weight: 700;
            font-size: 16px;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(43, 58, 140, 0.35);
        }
        .btn-accent-custom {
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 40px;
            font-weight: 700;
            font-size: 16px;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-accent-custom:hover {
            background: var(--accent-hover);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(242, 29, 47, 0.35);
        }
        .btn-outline-custom {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 50px;
            padding: 12px 36px;
            font-weight: 600;
            font-size: 16px;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-outline-custom:hover {
            background: var(--primary);
            color: white;
        }
        .btn-white-custom {
            background: white;
            color: var(--primary);
            border: none;
            border-radius: 50px;
            padding: 14px 44px;
            font-weight: 700;
            font-size: 16px;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .btn-white-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.25);
        }

        /* ---- NAVBAR ---- */
        .navbar-custom {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(8px);
            padding: 12px 0;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
        }
        .navbar-custom .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
        }
        .navbar-custom .brand .logo-img {
            height: 48px;
            width: auto;
            border-radius: 8px;
        }
        .navbar-custom .brand .system-name {
            font-weight: 800;
            font-size: 24px;
            color: var(--primary);
            letter-spacing: -0.5px;
        }
        .navbar-custom .brand .system-name span {
            color: var(--accent);
        }
        .navbar-custom .brand .system-sub {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            display: block;
            margin-top: -2px;
        }
        .btn-login-nav {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 32px;
            font-weight: 600;
            transition: 0.15s;
            text-decoration: none;
        }
        .btn-login-nav:hover {
            background: var(--primary-dark);
            color: white;
        }

        /* ---- HERO WITH BACKGROUND IMAGE (more visible) ---- */
        .hero-section {
            padding: 130px 0 70px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            background: url('images/landingpage_image.png') center center / cover no-repeat;
        }
        /* Reduced overlay opacity for better background visibility */
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.65);
            z-index: 0;
        }
        .hero-section .container {
            position: relative;
            z-index: 1;
        }
        .hero-section .hero-badge {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 6px 22px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .hero-section .hero-title {
            font-size: 48px;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 8px;
        }
        .hero-section .hero-title .highlight {
            color: var(--accent);
        }
        .hero-section .hero-title .sub-line {
            font-size: 26px;
            font-weight: 600;
            color: var(--text-dark);
            display: block;
            margin-top: 4px;
        }
        .hero-section .hero-desc {
            font-size: 18px;
            color: var(--text-dark);
            max-width: 520px;
            line-height: 1.7;
            margin: 16px 0 28px;
            font-weight: 500;
        }

        /* ---- HERO STATS ---- */
        .hero-stats {
            display: flex;
            gap: 48px;
            margin-top: 30px;
        }
        .hero-stats .stat-item .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }
        .hero-stats .stat-item .stat-label {
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 600;
        }

        /* ---- FEATURE TAGS ---- */
        .feature-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            margin-top: 20px;
        }
        .feature-tags .tag {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: 14px;
            color: var(--primary);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(4px);
            padding: 5px 14px;
            border-radius: 30px;
            border: 1px solid rgba(43, 58, 140, 0.15);
        }
        .feature-tags .tag i {
            color: var(--accent);
            font-size: 16px;
        }

        /* ---- BENEFITS SECTION ---- */
        .benefits-section {
            padding: 60px 0;
            background: white;
            position: relative;
        }
        .benefit-item {
            text-align: center;
            padding: 20px;
        }
        .benefit-item .benefit-icon {
            width: 56px;
            height: 56px;
            background: var(--bg-light);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 26px;
            color: var(--primary);
        }
        .benefit-item .benefit-title {
            font-weight: 700;
            font-size: 20px;
            color: var(--primary);
        }
        .benefit-item .benefit-desc {
            color: var(--text-muted);
            font-size: 15px;
        }

        /* ---- FEATURES GRID ---- */
        .features-grid-section {
            padding: 80px 0;
            background: var(--bg-light);
            position: relative;
        }
        .features-grid-section .section-title {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
            text-align: center;
            margin-bottom: 12px;
        }
        .features-grid-section .section-sub {
            text-align: center;
            color: var(--text-muted);
            font-size: 18px;
            max-width: 600px;
            margin: 0 auto 48px;
        }
        .feature-grid-card {
            background: white;
            border-radius: 20px;
            padding: 28px 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
            transition: 0.2s;
            height: 100%;
            text-align: center;
            border-bottom: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }
        .feature-grid-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border-bottom-color: var(--accent);
        }
        .feature-grid-card .fg-icon {
            width: 60px;
            height: 60px;
            background: var(--bg-light);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 26px;
            color: var(--primary);
        }
        .feature-grid-card .fg-title {
            font-weight: 700;
            font-size: 18px;
            color: var(--primary);
            margin-bottom: 6px;
        }
        .feature-grid-card .fg-desc {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .feature-grid-card .fg-paw {
            position: absolute;
            font-size: 40px;
            opacity: 0.04;
            right: -5px;
            bottom: -5px;
            transform: rotate(15deg);
            color: var(--primary);
        }

        /* ---- CTA BANNER ---- */
        .cta-banner {
            padding: 60px 0;
            background: var(--primary);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .cta-banner .paw-bg {
            position: absolute;
            font-size: 150px;
            opacity: 0.20;
            right: 5%;
            bottom: -20px;
            transform: rotate(-10deg);
        }
        .cta-banner h2 {
            font-size: 36px;
            font-weight: 700;
        }
        .cta-banner p {
            opacity: 0.90;
            font-size: 18px;
            max-width: 500px;
            margin: 8px auto 24px;
        }

        /* ---- FOOTER ---- */
        .footer {
            background: var(--primary-dark);
            color: #cdd4f0;
            padding: 40px 0 28px;
        }
        .footer .brand {
            font-size: 22px;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .footer .brand span {
            color: var(--accent);
        }
        .footer .brand .logo-img {
            height: 36px;
            width: auto;
            border-radius: 6px;
        }
        .footer .brand-sub {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            opacity: 0.6;
        }
        .footer p {
            color: #8a96b8;
            font-size: 14px;
        }
        .footer .footer-links a {
            color: #8a96b8;
            text-decoration: none;
            font-size: 14px;
            margin: 0 12px;
            transition: 0.1s;
        }
        .footer .footer-links a:hover {
            color: white;
        }

        /* ---- responsive ---- */
        @media (max-width: 991px) {
            .hero-section .hero-title {
                font-size: 38px;
            }
            .hero-section .hero-title .sub-line {
                font-size: 22px;
            }
            .hero-stats {
                gap: 24px;
            }
            .hero-stats .stat-item .stat-number {
                font-size: 26px;
            }
        }

        @media (max-width: 576px) {
            .hero-section {
                padding: 100px 0 50px;
                text-align: center;
            }
            .hero-section .hero-title {
                font-size: 30px;
            }
            .hero-section .hero-title .sub-line {
                font-size: 18px;
            }
            .hero-section .hero-desc {
                font-size: 16px;
                max-width: 100%;
            }
            .btn-primary-custom,
            .btn-accent-custom,
            .btn-outline-custom {
                width: 100%;
                justify-content: center;
            }
            .feature-tags {
                justify-content: center;
            }
            .hero-stats {
                justify-content: center;
                gap: 16px;
                flex-wrap: wrap;
            }
            .hero-stats .stat-item .stat-number {
                font-size: 22px;
            }
            .features-grid-section .section-title {
                font-size: 28px;
            }
            .cta-banner h2 {
                font-size: 28px;
            }
            .navbar-custom .brand .system-name {
                font-size: 18px;
            }
            .navbar-custom .brand .system-sub {
                font-size: 10px;
            }
            .btn-login-nav {
                padding: 8px 18px;
                font-size: 14px;
            }
            .footer .footer-links a {
                margin: 0 8px;
                font-size: 13px;
            }
            .navbar-custom .brand .logo-img {
                height: 36px;
            }
            .paw-print {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- ============================================================ -->
<!-- NAVBAR -->
<!-- ============================================================ -->
<nav class="navbar-custom" id="mainNav">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="#" class="brand" onclick="showLanding()">
            <img src="logo.png" alt="SBI-ABC Logo" class="logo-img" />
            <div>
                <div class="system-name">SBI Medical-<span>ABC</span></div>
                <div class="system-sub">Smart Bite Care</div>
            </div>
        </a>
        <div>
            <a href="login.php" class="btn-login-nav">
                <i class="bi bi-box-arrow-in-right me-2"></i>Login
            </a>
        </div>
    </div>
</nav>

<!-- ============================================================ -->
<!-- LANDING PAGE -->
<!-- ============================================================ -->
<div id="landingPage">

    <!-- PAW DECORATIONS -->
    <div class="paw-print paw-print-1"><i class="bi bi- paw"></i></div>
    <div class="paw-print paw-print-2"><i class="bi bi- paw"></i></div>
    <div class="paw-print paw-print-3"><i class="bi bi- paw"></i></div>
    <div class="paw-print paw-print-4"><i class="bi bi- paw"></i></div>
    <div class="paw-print paw-print-5"><i class="bi bi- paw"></i></div>

    <!-- HERO WITH BACKGROUND IMAGE (more visible) -->
    <section class="hero-section">
        <div class="container">
            <div class="row">
                <div class="col-lg-7">
                    <div class="hero-badge">
                        <i class="bi bi-shield-check me-1"></i> SBI Medical-ABC Management System
                    </div>
                    <h1 class="hero-title">
                        Workflow Smarter.<br />
                        <span class="highlight">Outcomes. Better.</span>
                        <span class="sub-line">Modern SBI Medical-ABC Management</span>
                    </h1>
                    <p class="hero-desc">
                        A dedicated  platform for SBI Medical and Animal Bite Center and Vaccination Clinic. Manage patients,
                        track vaccinations, monitor supplies, and generate reports in seconds.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="login.php" class="btn-accent-custom">
                            <i class="bi bi-speedometer2"></i> Open Dashboard
                        </a>
                        <a href="#features" class="btn-outline-custom">
                            <i class="bi bi-eye"></i> See Features
                        </a>
                    </div>
                    <div class="feature-tags">
                        <span class="tag"><i class="bi bi-heart-pulse"></i> Patient Monitoring</span>
                        <span class="tag"><i class="bi bi-capsule"></i> Vaccine Tracking</span>
                        <span class="tag"><i class="bi bi-shield-lock"></i> Secure & Reliable</span>
                        <span class="tag"><i class="bi bi-box-seam"></i> Inventory Management</span>
                        <span class="tag"><i class="bi bi-graph-up"></i> AI Predictions</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- BENEFITS / STATS -->
    <section class="benefits-section">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="benefit-title">Save Time</div>
                        <div class="benefit-desc">Automate patient records and reporting.</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="benefit-item">
                        <div class="benefit-icon"><i class="bi bi-graph-up-arrow"></i></div>
                        <div class="benefit-title">Increase Efficiency</div>
                        <div class="benefit-desc">Streamline workflows and reduce errors.</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="benefit-item">
                        <div class="benefit-icon">
                            <i class="bi bi-lightbulb"></i>
                        </div>
                        <div class="benefit-title">Better Decision-Making</div>
                        <div class="benefit-desc">
                            Leverage predictive analytics and reports for smarter inventory and resource planning.
                        </div>
                    </div>
                </div>  
            </div>
            
        </div>
    </section>

    <!-- FEATURES GRID -->
    <section class="features-grid-section" id="features">
        <div class="container">
            <h2 class="section-title">Why Choose <span style="color: var(--accent);">SBI Medical-ABC</span></h2>
            <p class="section-sub">Everything you need to manage your Animal Bite Treatment Center efficiently.</p>
            <div class="row g-4">
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-people"></i></div>
                        <div class="fg-title">Patient Management</div>
                        <div class="fg-desc">Track patient records and case history.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-box-seam"></i></div>
                        <div class="fg-title">Inventory Management</div>
                        <div class="fg-desc">Monitor supplies and track stock levels.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-cpu"></i></div>
                        <div class="fg-title">AI-Powered Predictions</div>
                        <div class="fg-desc">AI forecasting for supply optimization.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-file-earmark-bar-graph"></i></div>
                        <div class="fg-title">Reports & Analytics</div>
                        <div class="fg-desc">Generate comprehensive  reports.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-shield-lock"></i></div>
                        <div class="fg-title">Secure & Reliable</div>
                        <div class="fg-desc">Role-based access and data protection.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-bell"></i></div>
                        <div class="fg-title">Real-Time Alerts</div>
                        <div class="fg-desc">Notifications for low stock and expiring items.</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="feature-grid-card">
                        <span class="fg-paw"><i class="bi bi- paw"></i></span>
                        <div class="fg-icon"><i class="bi bi-clock-history"></i></div>
                        <div class="fg-title">Audit Logs</div>
                        <div class="fg-desc">Track all system activities and changes.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA BANNER -->
    <section class="cta-banner">
        <span class="paw-bg"><i class="bi bi- paw"></i></span>
        <div class="container">
            <h2>Secure. Compliant. Reliable.</h2>
            <p>Start managing your Animal Bite Treatment Center today.</p>
            <a href="login.php" class="btn-white-custom">
                <i class="bi bi-box-arrow-in-right"></i> Get Started
            </a>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="brand">
                        <img src="logo.png" alt="SBI-ABC Logo" class="logo-img" />
                        SBI-<span>ABC</span>
                    </div>
                    <div class="brand-sub">Smart Bite Care · Modern ABTC Management</div>
                    <p class="mt-2">&copy; 2026 SBI Medical-ABC. All Rights Reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="footer-links">
                        <a href="login.php">Login</a>
                        <a href="#">Privacy</a>
                        <a href="#">Terms</a>
                        <a href="#">Support</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

</div>

<!-- ============================================================ -->
<!-- SCRIPTS -->
<!-- ============================================================ -->
<script>
    function showLanding() {
        document.getElementById('landingPage').style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Smooth scroll for "See Features" link
    document.querySelector('.btn-outline-custom').addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector('#features').scrollIntoView({ behavior: 'smooth' });
    });
</script>

</body>
</html>