<?php
// Initialize session state for user navigation and auth tracking
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Home</title>
    <!-- Dynamic cache-breaker ensures updated styles load instantly -->
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

    <!-- Navigation Bar -->
    <header class="navbar">
        <div class="logo">
            <a href="index.php"><img src="Logo.jpg" alt="JobiT Logo" class="brand-logo"></a>
        </div>
        <div class="nav-buttons" style="display: flex; gap: 10px; align-items: center;">
            <a href="auth.php?mode=login" class="btn-black nav-btn">LOGIN</a>
            <a href="auth.php?mode=signup" class="btn-blue nav-btn">SIGN UP</a>
            <!-- Lowkey Admin Access (Hidden in plain sight) -->
            <a href="admin-login.php" style="font-size: 0.7rem; color: #b0b0b0; text-decoration: none; margin-left: 15px; letter-spacing: 1px; transition: color 0.2s;" onmouseover="this.style.color='#111'" onmouseout="this.style.color='#b0b0b0'">ADMIN</a>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h1>May Gawa?<br> May Gagawa!</h1>
            <p>Find the right person for the job — or the right job for you. Post a task or browse opportunities, and let JobiT match people by skills, schedule, and location.</p>
            <div class="hero-buttons">
                <a href="auth.php?mode=login" class="btn-black hero-btn">LOGIN</a>
                <a href="auth.php?mode=signup" class="btn-blue hero-btn">SIGN UP</a>
            </div>
        </div>
        <div class="hero-image">
            <img src="MainPage.png" alt="JobiT Workers" onerror="this.src='Browse2.jpg';">
        </div>
    </section>

    <!-- How it Works Section -->
    <section class="how-it-works">
        <h2>How it Works?</h2>
        <div class="cards-container">
            <div class="card white-card">
                <img src="OneAccess.png" alt="One Account" class="feature-img">
                <h3>ONE ACCOUNT, TWO ROLES</h3>
                <p>Anyone can post a short gig, a part-time job or a full-time job, and anyone can respond to listings. No separate employer accounts.</p>
            </div>
            <div class="card yellow-card">
                <img src="MatchbyScore.png" alt="Match by Score" class="feature-img">
                <h3>MATCH BY SCORE</h3>
                <p>JobiT matches people with opportunities based on skills, schedule, and location. See your match score, understand what fits, and discover where you can improve.</p>
            </div>
            <div class="card white-card">
                <img src="Contact.png" alt="Contact" class="feature-img">
                <h3>CONTACT ONLY AFTER A MATCH</h3>
                <p>You tap Interested, the poster taps Invite. Only then can you message each other, and contact details show if they chose to share them.</p>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="team-section">
        <div class="team-title">
            <h2>GET<br>TO<br><span class="highlight">KNOW</span><br>OUR<br>TEAM.</h2>
        </div>
        <div class="team-members">
            <div class="member">
                <img src="hopePIC.jpg" alt="Hope" class="member-img">
                <div class="member-info">
                    <h4>Hope B. Calunsod</h4>
                    <p>STUDENT ID : 2517150</p>
                    <p>ROLE : DOCUMENT / PPT</p>
                </div>
            </div>
            <div class="member">
                <img src="carlosPIC.jpg" alt="Carlos" class="member-img">
                <div class="member-info">
                    <h4>Carlos John L. Rama</h4>
                    <p>STUDENT ID : 2211255</p>
                    <p>ROLE : DOCUMENT / BACK END</p>
                </div>
            </div>
            <div class="member">
                <img src="axelPIC.jpg" alt="Axel" class="member-img">
                <div class="member-info">
                    <h4>Dan Axel M. Tongol</h4>
                    <p>STUDENT ID : 251757</p>
                    <p>ROLE : FRONT END / BACK END</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Minimized Footer -->
    <footer>
        <div class="footer-container">
            <p class="footer-course">CITE 004-IT21S2 - Data Structures and Algorithm</p>
            <p class="footer-team">Hope B. Calunsod &nbsp;|&nbsp; Carlos John L. Rama &nbsp;|&nbsp; Dan Axel M. Tongol</p>
            <p class="copyright">&copy; 2026 JobiT. All Rights Reserved.</p>
        </div>
    </footer>

</body>
</html>