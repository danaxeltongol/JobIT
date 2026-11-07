<?php
// ============================================================================
// JOBIT POST LISTING ENGINE (FULLY ALIGNED TO THE LEFT EDGE)
// ============================================================================

session_start();
require_once 'db.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId && !isset($_SESSION['user_profile'])) {
    header("Location: auth.php?mode=login");
    exit();
}

$currentUserProfile = $_SESSION['user_profile'] ?? [];
$userName = $currentUserProfile['name'] ?? 'User';
$userInitials = $currentUserProfile['initials'] ?? 'U';
$userAvatar = $currentUserProfile['avatar'] ?? '';

if ($userId) {
    try {
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $dbUser = $userStmt->fetch();
        if ($dbUser) {
            $userName = $dbUser['full_name'];
            $words = explode(' ', $dbUser['full_name']);
            $initials = '';
            foreach ($words as $w) {
                if (!empty($w)) $initials .= strtoupper($w[0]);
            }
            $userInitials = substr($initials, 0, 2) ?: 'U';
            $userAvatar = $dbUser['avatar'] ?? '';
        }
    } catch (Exception $e) {}
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $workType = trim($_POST['work_type'] ?? 'Short-Gig');
    $budgetMin = floatval($_POST['budget_min'] ?? 0);
    $budgetMax = floatval($_POST['budget_max'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    // Schedule: the employer ticks every availability option the job needs (Weekdays, Mornings, ...).
    // Only the 5 known options are kept, in a fixed order, and saved as comma text like skills ("Weekdays, Evenings").
    $allowedSlots = ['Weekdays', 'Mornings', 'Afternoons', 'Evenings', 'Weekends'];
    $pickedSlots = $_POST['schedule'] ?? [];
    if (!is_array($pickedSlots)) { $pickedSlots = [$pickedSlots]; }   // an old single value still works
    $scheduleList = array_values(array_intersect($allowedSlots, $pickedSlots));
    $schedule = implode(', ', $scheduleList);
    $description = trim($_POST['description'] ?? '');
    
    $skillsRequired = trim($_POST['skills_required'] ?? '');

    // Budget & Field Validation Rules
    if (empty($title) || empty($category) || empty($description)) {
        $error = "Please fill in all required fields.";
    } elseif (count($scheduleList) === 0) {
        $error = "Please pick at least one schedule option.";
    } elseif ($budgetMin < 1 || $budgetMax < 1) {
        $error = "Budget amounts must be at least ₱1.";
    } elseif ($budgetMin > $budgetMax) {
        $error = "Minimum budget cannot be higher than the maximum budget!";
    } elseif ($budgetMax > 100000000) {
        $error = "Please enter a realistic budget amount (maximum allowed limit is ₱100,000,000).";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO listings (user_id, title, category, work_type, budget_min, budget_max, location, schedule, description, skills_required, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$userId, $title, $category, $workType, $budgetMin, $budgetMax, $location, $schedule, $description, $skillsRequired]);
            
            header("Location: my-listings.php?tab=active");
            exit();
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Post a Listing</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6fb; color: #161b3a; font-size: 1rem; overflow-x: hidden; }
        h1, h2, h3, h4 { font-weight: bold; }

        /* Top Navigation Bar */
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; background: #fff; border-bottom: 1px solid #eaeaea; }
        .logo img { height: 36px; }
        .nav-links-center { display: flex; gap: 25px; align-items: center; }
        .nav-link-item { color: #333; text-decoration: none; font-weight: 600; font-size: 0.95rem; }
        .nav-link-item:hover { color: #4154f1; }

        .profile-dropdown-container { position: relative; display: inline-block; }
        .profile-avatar-btn {
            background: #4154f1; color: #fff; width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-weight: bold;
            font-size: 0.95rem; border: none; cursor: pointer; text-decoration: none; overflow: hidden;
        }
        .profile-avatar-btn img { width: 100%; height: 100%; object-fit: cover; }
        .dropdown-menu {
            display: none; position: absolute; right: 0; top: 50px; background-color: #ffffff;
            min-width: 180px; box-shadow: 0px 8px 24px rgba(0,0,0,0.12); border-radius: 12px;
            border: 1px solid #eaeaea; z-index: 1000; overflow: hidden;
        }
        .dropdown-menu a { color: #333333; padding: 12px 18px; text-decoration: none; display: block; font-size: 0.95rem; font-weight: 600; }
        .dropdown-menu a:hover { background-color: #f0f4ff; color: #4154f1; }
        .dropdown-menu .dropdown-logout { color: #ff4d4d; border-top: 1px solid #eee; }
        .show-dropdown { display: block !important; }

        /* ====================================================================
           PAGE LAYOUT: picture on the left, form card on the right.
           The picture keeps its size and place. If it is taller than the screen, only its bottom edge
           is trimmed (with a soft fade) so the page never needs scrolling. See fitPicture() at the bottom.
           ==================================================================== */
        .pl-main { padding: 20px 0 20px 20px; width: 100%; max-width: 100%; background-color: #f4f6fb; }
        /* grid columns are fixed widths and start from the left, so the form sits right after the image */
        .pl-grid { display: grid; grid-template-columns: minmax(0, 1120px) minmax(0, 600px); gap: 20px; align-items: center; justify-content: start; width: 100%; }

        /* Left side: post.png (Canva image), pushed to the leftmost screen edge */
        .pl-pic { text-align: left; margin-left: -20px; overflow: hidden; }
        /* translateX(-90px) moves the image left. Lower the number to move it further left, raise it to move it right */
        .pl-pic img { width: 100%; max-width: 1120px; height: auto; display: block; margin-left: 0; transform: translateX(-90px); }
        .pl-pic.is-trimmed { -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 56px), transparent); mask-image: linear-gradient(to bottom, #000 calc(100% - 56px), transparent); }

        /* Right side: the form card.
           margin-left makes the card wider toward the empty space next to the picture (it never touches the picture).
           translateX(-56px) moves the whole card left. First number = left/right. */
        .pl-card {
            background: #fff; padding: 16px 26px 18px; border-radius: 20px; border: 1px solid #e4e8f7;
            box-shadow: 0 12px 36px rgba(22,27,58,0.07);
            margin-left: -105px; margin-right: 25px; position: relative; z-index: 2; transform: translateX(-56px);
        }

        /* Card heading */
        .pl-head { margin-bottom: clamp(12px, 1.6vh, 18px); }
        .pl-eyebrow { display: inline-block; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #4154f1; background: #eef1ff; padding: 3px 10px; border-radius: 999px; }
        .pl-head h2 { font-size: 1.3rem; font-weight: 800; letter-spacing: -0.02em; color: #161b3a; margin-top: 6px; line-height: 1.2; }
        .pl-head p { color: #667085; font-size: 0.83rem; margin-top: 2px; }

        /* Error message (shown inside the card, right above the fields) */
        .pl-error { background: #fff0f0; border: 1px solid #f5c6cb; color: #c0392b; padding: 8px 12px; border-radius: 12px; margin-bottom: 10px; font-weight: 700; font-size: 0.84rem; }

        /* Fields: label on top, control below. Rows put 2 or 3 fields side by side to keep the form short */
        .pl-field { margin-bottom: clamp(8px, 1.2vh, 12px); }   /* a little more air on tall screens */
        .pl-row { display: grid; gap: 0 12px; }
        .pl-row.two { grid-template-columns: 1fr 1fr; }
        .pl-row.three { grid-template-columns: 1fr 1fr 2fr; }   /* Budget Min | Budget Max | Location (wider) */
        .pl-field > label, .pl-label { display: block; font-weight: 700; font-size: 0.78rem; color: #3b4160; margin-bottom: 3px; }
        .pl-lblrow { display: flex; justify-content: space-between; align-items: baseline; gap: 4px 12px; flex-wrap: wrap; margin-bottom: 3px; }
        .pl-lblrow .pl-label { margin-bottom: 0; }
        .pl-hint { font-size: 0.72rem; color: #667085; }

        .pl-control {
            width: 100%; padding: clamp(7px, 1vh, 9px) 12px; border: 1.5px solid #dfe4f7; border-radius: 12px; background: #fbfcff;
            font-size: 0.88rem; color: #161b3a; outline: none; transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .pl-control::placeholder { color: #8a92ab; }
        .pl-control:hover { border-color: #c5cdf5; }
        .pl-control:focus { border-color: #4154f1; background: #fff; box-shadow: 0 0 0 3px rgba(65,84,241,0.14); }
        select.pl-control {
            -webkit-appearance: none; appearance: none; cursor: pointer; padding-right: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5l5 5 5-5' fill='none' stroke='%234154f1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 12px 8px;
        }
        textarea.pl-control { resize: vertical; line-height: 1.4; min-height: 52px; }
        input[type=number].pl-control { -moz-appearance: textfield; }
        input[type=number].pl-control::-webkit-outer-spin-button, input[type=number].pl-control::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        /* Tag Picker Styles (skills) */
        .skill-tag-container {
            border: 1.5px solid #dfe4f7; border-radius: 12px; padding: 3px 8px; background: #fbfcff;
            display: flex; flex-wrap: wrap; gap: 5px; align-items: center; min-height: 34px; position: relative; cursor: text;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .skill-tag-container:hover { border-color: #c5cdf5; }
        .skill-tag-container:focus-within { border-color: #4154f1; background: #fff; box-shadow: 0 0 0 3px rgba(65,84,241,0.14); }
        .skill-pill {
            background: #eef1ff; color: #4154f1; padding: 2px 9px; border-radius: 999px; font-size: 0.78rem;
            font-weight: 700; display: inline-flex; align-items: center; gap: 5px;
        }
        .skill-pill .remove-tag { cursor: pointer; font-weight: bold; color: #4154f1; font-size: 0.95rem; line-height: 1; }
        .skill-pill .remove-tag:hover { color: #d9534f; }
        .skill-input-box { border: none; outline: none; flex: 1; min-width: 140px; font-size: 0.88rem; padding: 3px; background: transparent; color: #161b3a; }
        .skill-input-box::placeholder { color: #8a92ab; }

        .suggestions-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #dfe4f7;
            border-radius: 12px; margin-top: 4px; max-height: 180px; overflow-y: auto; z-index: 999;
            box-shadow: 0 10px 28px rgba(22,27,58,0.12); display: none;
        }
        .suggestion-item { padding: 8px 12px; cursor: pointer; font-size: 0.88rem; color: #333; font-weight: 600; }
        .suggestion-item:hover { background: #f0f4ff; color: #4154f1; }

        /* Schedule options: tick as many as the job needs */
        #scheduleBox { display: flex; flex-wrap: wrap; gap: 6px; }
        .slot-pill { cursor: pointer; position: relative; }
        .slot-pill input { position: absolute; opacity: 0; pointer-events: none; }
        .slot-pill span { display: inline-block; padding: 4px 12px; border: 1.5px solid #dfe4f7; border-radius: 999px; background: #fbfcff; color: #3b4160; font-size: 0.78rem; font-weight: 700; transition: background .15s, color .15s, border-color .15s; }
        .slot-pill:hover span { border-color: #4154f1; color: #4154f1; }
        .slot-pill input:checked + span { background: #4154f1; border-color: #4154f1; color: #fff; }
        .slot-pill input:focus-visible + span { outline: 3px solid rgba(65,84,241,0.35); outline-offset: 1px; }

        /* Buttons */
        .pl-actions { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-top: clamp(12px, 1.6vh, 18px); }
        .pl-btn { padding: 9px 24px; border-radius: 999px; font-weight: 800; font-size: 0.86rem; cursor: pointer; text-decoration: none; transition: background .15s, border-color .15s, transform .15s, box-shadow .15s; }
        .pl-btn:hover { transform: translateY(-1px); }
        .pl-btn:focus-visible { outline: 3px solid rgba(65,84,241,0.45); outline-offset: 2px; }
        .pl-btn-ghost { border: 1.5px solid #c9d0fb; background: #fff; color: #4154f1; }
        .pl-btn-ghost:hover { background: #eef1ff; border-color: #4154f1; }
        .pl-btn-primary { border: none; background: #4154f1; color: #fff; box-shadow: 0 6px 16px rgba(65,84,241,0.25); }
        .pl-btn-primary:hover { background: #2938b7; box-shadow: 0 8px 20px rgba(65,84,241,0.32); }

        /* ---------- Screens ---------- */
        /* Narrower laptops: the card does not reach as far toward the picture */
        @media (max-width: 1279px) { .pl-card { margin-left: -50px; } }
        /* Small screens: picture hidden, the form takes the whole width */
        @media (max-width: 999px) {
            .pl-main { padding: 16px 16px 28px; }
            .pl-grid { grid-template-columns: 1fr; }
            .pl-pic { display: none; }
            .pl-card { margin: 0; transform: none; }
        }
        @media (max-width: 760px) {
            .navbar { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .nav-links-center { order: 3; width: 100%; overflow-x: auto; gap: 18px; padding-bottom: 4px; }
            .nav-link-item { white-space: nowrap; }
        }
        @media (max-width: 560px) {
            .pl-card { padding: 16px 16px 18px; }
            .pl-row.two, .pl-row.three { grid-template-columns: 1fr; }
            .pl-actions .pl-btn { flex: 1; text-align: center; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="navbar">
        <div class="logo">
            <a href="dashboard.php"><img src="Logo.jpg" alt="JobiT Logo"></a>
        </div>

        <nav class="nav-links-center">
            <a href="dashboard.php" class="nav-link-item">Home</a>
            <a href="browse.php" class="nav-link-item">Browse</a>
            <a href="my-listings.php" class="nav-link-item">My Listings</a>
            <a href="matches.php" class="nav-link-item">Matches</a>
            <a href="profile.php" class="nav-link-item">Profile</a>
            <a href="post-listing.php" class="nav-link-item" style="background: #4154f1; color: #fff; font-weight: 700; padding: 8px 18px; border-radius: 20px; box-shadow: 0 4px 12px rgba(65,84,241,0.2);">+ Post a Listing</a>
        </nav>

        <div class="nav-right" style="display: flex; align-items: center; gap: 20px;">
            <div class="profile-dropdown-container">
                <button onclick="toggleDropdown()" class="profile-avatar-btn" id="avatarBtn">
                    <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                        <img src="<?php echo htmlspecialchars($userAvatar); ?>">
                    <?php else: ?>
                        <?php echo htmlspecialchars($userInitials); ?>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu" id="profileDropdown">
                    <a href="profile.php">My Profile</a>
                    <a href="index.php" class="dropdown-logout">Log Out</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content: picture on the left, form card on the right -->
    <main class="pl-main">

        <div class="pl-grid">

            <!-- Left Side: post.png Canva Image Asset -->
            <div class="pl-pic" id="plPic">
                <img src="post.png" alt="Post Listing Banner">
            </div>

            <!-- Right Side: Form Card Container -->
            <div class="pl-card">

                <div class="pl-head">
                    <span class="pl-eyebrow">EMPLOYER HUB</span>
                    <h2>Post a new job or gig.</h2>
                    <p>Publish an opportunity to connect with skilled workers across JobiT.</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="pl-error" role="alert">⚠️ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form action="post-listing.php" method="POST" onsubmit="return validateSchedule() && validateBudgetRange()">

                    <div class="pl-field">
                        <label for="plTitle">Job / Task Title</label>
                        <input type="text" id="plTitle" name="title" class="pl-control" required placeholder="e.g. Corporate BPO - Customer Support Representative">
                    </div>

                    <div class="pl-row two">
                        <div class="pl-field">
                            <label for="plCategory">Category</label>
                            <select id="plCategory" name="category" class="pl-control" required>
                                <option value="">Select Category</option>
                                    <option value="Corporate & BPO">Corporate & BPO</option>
                                    <option value="IT & Tech">IT & Tech</option>
                                    <option value="Tutoring & Education">Tutoring & Education</option>
                                    <option value="Trades & Labor">Trades & Labor</option>
                                    <option value="Food & Culinary">Food & Culinary</option>
                                    <option value="Design">Design</option>
                                    <option value="General Services">General Services</option>
                                    <option value="Healthcare & Wellness">Healthcare & Wellness</option>
                                    <option value="Sales & Marketing">Sales & Marketing</option>
                                    <option value="Writing & Content">Writing & Content</option>
                                    <option value="Admin & Virtual Assistance">Admin & Virtual Assistance</option>
                                    <option value="Delivery & Driving">Delivery & Driving</option>
                                    <option value="Cleaning & Household">Cleaning & Household</option>
                            </select>
                        </div>
                        <div class="pl-field">
                            <label for="plWorkType">Work Type</label>
                            <select id="plWorkType" name="work_type" class="pl-control" required>
                                <option value="Part-Time">Part-Time</option>
                                <option value="Short-Gig">Short-Gig</option>
                                <option value="Full-Time">Full-Time</option>
                            </select>
                        </div>
                    </div>

                    <div class="pl-row three">
                        <div class="pl-field">
                            <label for="budgetMin">Budget Min (₱)</label>
                            <input type="number" id="budgetMin" name="budget_min" class="pl-control" required min="1" max="100000000" placeholder="e.g. 500">
                        </div>
                        <div class="pl-field">
                            <label for="budgetMax">Budget Max (₱)</label>
                            <input type="number" id="budgetMax" name="budget_max" class="pl-control" required min="1" max="100000000" placeholder="e.g. 1500">
                        </div>
                        <div class="pl-field">
                            <label for="plLocation">Location</label>
                            <input type="text" id="plLocation" name="location" class="pl-control" required placeholder="Quezon City, Metro Manila">
                        </div>
                    </div>

                    <div class="pl-field">
                        <div class="pl-lblrow">
                            <span class="pl-label">Schedule Preference</span>
                            <span id="scheduleHint" class="pl-hint">Tick every option the job needs.</span>
                        </div>
                        <div id="scheduleBox">
                            <label class="slot-pill"><input type="checkbox" name="schedule[]" value="Weekdays"><span>Weekdays</span></label>
                            <label class="slot-pill"><input type="checkbox" name="schedule[]" value="Mornings"><span>Mornings</span></label>
                            <label class="slot-pill"><input type="checkbox" name="schedule[]" value="Afternoons"><span>Afternoons</span></label>
                            <label class="slot-pill"><input type="checkbox" name="schedule[]" value="Evenings"><span>Evenings</span></label>
                            <label class="slot-pill"><input type="checkbox" name="schedule[]" value="Weekends"><span>Weekends</span></label>
                        </div>
                    </div>

                    <!-- Interactive Smart Skill Tag Picker -->
                    <div class="pl-field">
                        <div class="pl-lblrow">
                            <label class="pl-label" for="skillInput">Skills Required</label>
                            <span class="pl-hint">Type a skill, pick a suggestion or press Enter.</span>
                        </div>
                        <div class="skill-tag-container" id="skillTagContainer" onclick="document.getElementById('skillInput').focus();">
                            <input type="text" id="skillInput" class="skill-input-box" placeholder="e.g. Communication, English, Customer Support...">
                            <div id="suggestionsDropdown" class="suggestions-dropdown"></div>
                        </div>
                        <input type="hidden" name="skills_required" id="skillsHiddenInput" value="">
                    </div>

                    <div class="pl-field">
                        <label for="plDescription">Job Description</label>
                        <textarea id="plDescription" name="description" rows="2" class="pl-control" required placeholder="Describe the job duties, requirements, and interview expectations..."></textarea>
                    </div>

                    <div class="pl-actions">
                        <a href="my-listings.php" class="pl-btn pl-btn-ghost">Cancel</a>
                        <button type="submit" class="pl-btn pl-btn-primary">Publish Listing</button>
                    </div>

                </form>
            </div>

        </div>

    </main>

    <script>
        // The availability score needs at least one schedule option to compare
        function validateSchedule() {
            const ticked = document.querySelectorAll('#scheduleBox input:checked').length;
            const hint = document.getElementById('scheduleHint');
            if (ticked === 0) {
                hint.textContent = 'Please pick at least one schedule option.';
                hint.style.color = '#d9534f';
                hint.style.fontWeight = '700';
                document.getElementById('scheduleBox').scrollIntoView({ block: 'center', behavior: 'smooth' });
                return false;
            }
            return true;
        }

        function validateBudgetRange() {
            const min = parseFloat(document.getElementById('budgetMin').value);
            const max = parseFloat(document.getElementById('budgetMax').value);

            if (min > max) {
                alert("⚠️ Error: Minimum budget cannot be higher than the maximum budget!");
                return false;
            }
            if (max > 100000000) {
                alert("⚠️ Error: Please enter a realistic budget amount (maximum allowed limit is ₱100,000,000).");
                return false;
            }
            return true;
        }

        const masterSkillsList = [
            "Communication", "English", "Customer Support", "Problem Solving", "Teaching", "Tutoring", "Math Tutoring", 
            "English Tutoring", "Science Tutoring", "ESL Teaching", "Academic Coaching", "Test Prep", "Homework Help", 
            "PHP", "JavaScript", "HTML/CSS", "Python", "Java", "C++", "React.js", "Node.js", "SQL", "MySQL",
            "Graphic Design", "Adobe Photoshop", "Adobe Illustrator", "Canva", "CP Repair", "Mobile Phone Repair", 
            "Hardware Repair", "PC Troubleshooting", "Networking", "Electrical Wiring", "Plumbing", "Carpentry", 
            "Masonry", "Welding", "AC Repair", "Appliance Repair", "Automotive Mechanic", "Construction Labor", 
            "General Labor", "Painting", "Heavy Lifting", "Cooking", "Chef", "Line Cook", "Bakery", "Baking", 
            "Barista", "Coffee Brewing", "Bartending", "Server", "Waitstaff", "Catering", "Kitchen Assistant", 
            "Food Preparation", "Dishwashing", "Mathematics", "Algebra", "Calculus", "Statistics", "Content Writing", 
            "Copywriting", "SEO Optimization", "Video Editing", "Premiere Pro", "Data Entry", "Microsoft Office", 
            "Excel", "Word", "PowerPoint", "Project Management", "UI/UX Design", "Figma", "Virtual Assistance", 
            "Bookkeeping", "Driving", "Delivery", "Cleaning", "Housekeeping", "Laundry", "Babysitting", "Pet Care"
        ];

        let selectedSkills = [];

        const skillInput = document.getElementById('skillInput');
        const skillTagContainer = document.getElementById('skillTagContainer');
        const suggestionsDropdown = document.getElementById('suggestionsDropdown');
        const skillsHiddenInput = document.getElementById('skillsHiddenInput');

        function renderTags() {
            const existingPills = skillTagContainer.querySelectorAll('.skill-pill');
            existingPills.forEach(p => p.remove());

            selectedSkills.forEach(skill => {
                const pill = document.createElement('div');
                pill.className = 'skill-pill';
                pill.innerHTML = `${skill} <span class="remove-tag" onclick="removeSkill('${skill}')">&times;</span>`;
                skillTagContainer.insertBefore(pill, skillInput);
            });

            skillsHiddenInput.value = selectedSkills.join(', ');
        }

        function addSkill(skill) {
            const trimmed = skill.trim();
            if (trimmed && !selectedSkills.map(s => s.toLowerCase()).includes(trimmed.toLowerCase())) {
                selectedSkills.push(trimmed);
                renderTags();
            }
            skillInput.value = '';
            suggestionsDropdown.style.display = 'none';
            skillInput.focus();
        }

        function removeSkill(skill) {
            selectedSkills = selectedSkills.filter(s => s !== skill);
            renderTags();
        }

        skillInput.addEventListener('input', function() {
            const val = this.value.trim().toLowerCase();
            if (val === '') {
                suggestionsDropdown.style.display = 'none';
                return;
            }

            const filtered = masterSkillsList.filter(s => 
                s.toLowerCase().includes(val) && !selectedSkills.map(x => x.toLowerCase()).includes(s.toLowerCase())
            );

            if (filtered.length === 0) {
                suggestionsDropdown.style.display = 'none';
                return;
            }

            suggestionsDropdown.innerHTML = '';
            filtered.forEach(item => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.innerText = item;
                div.onclick = () => addSkill(item);
                suggestionsDropdown.appendChild(div);
            });

            suggestionsDropdown.style.display = 'block';
        });

        skillInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = this.value.trim();
                if (val) {
                    addSkill(val);
                }
            }
        });

        document.addEventListener('click', function(e) {
            if (!skillTagContainer.contains(e.target)) {
                suggestionsDropdown.style.display = 'none';
            }
        });

        function toggleDropdown() {
            document.getElementById("profileDropdown").classList.toggle("show-dropdown");
        }

        // The picture keeps its size and place. If it is taller than the screen, it would force the page to scroll,
        // so its box ends exactly at the bottom of the screen and the bottom edge fades out softly.
        // (Only on wide screens, where the picture is shown next to the form.)
        function fitPicture() {
            const box = document.getElementById('plPic');
            if (!box) return;
            if (window.innerWidth < 1000) {            // small screens: the picture is hidden, nothing to trim
                box.style.maxHeight = '';
                box.classList.remove('is-trimmed');
                return;
            }
            const top = box.parentElement.getBoundingClientRect().top + window.scrollY;   // where the picture/form row starts on the page
            const room = window.innerHeight - top - 20;                       // 20px = the space kept under it
            box.style.maxHeight = Math.max(420, room) + 'px';
            box.classList.toggle('is-trimmed', box.scrollHeight > box.clientHeight + 1);
        }
        // As soon as an option is ticked, the red warning goes back to the normal hint
        document.getElementById('scheduleBox').addEventListener('change', function () {
            const hint = document.getElementById('scheduleHint');
            hint.textContent = 'Tick every option the job needs.';
            hint.style.color = '';
            hint.style.fontWeight = '';
        });

        window.addEventListener('load', fitPicture);
        window.addEventListener('resize', fitPicture);
        fitPicture();
    </script>
</body>
</html>