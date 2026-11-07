<?php
// ============================================================================
// JOBIT LISTING DETAILS & APPLICATION ENGINE
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

$listingId = intval($_GET['id'] ?? 0);
// Where the Back buttons go: matches.php sends ?from=matches, my-listings.php sends ?from=my, anything else = Browse
$from = in_array($_GET['from'] ?? '', ['my', 'matches'], true) ? $_GET['from'] : '';
$fromMyListings = ($from === 'my');
if ($from === 'matches') {
    $backUrl = 'matches.php?tab=gigs';
    $backLabel = 'Matches';
} elseif ($from === 'my') {
    $backUrl = 'my-listings.php';
    $backLabel = 'My Listings';
} else {
    $backUrl = 'browse.php';
    $backLabel = 'Browse Listings';
}
// Keeps ?from= after "Submit Application Now" (the form reloads this page), so Back still goes to the right place
$fromParam = $from !== '' ? '&from=' . $from : '';
$successMessage = '';
$errorMessage = '';
$appStatus = null; // 'pending', 'accepted', 'rejected', or null

// Fetch listing details from database
$listing = null;
if ($listingId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*, u.full_name as poster_name, u.avatar as poster_avatar, u.location as poster_location 
            FROM listings l 
            JOIN users u ON l.user_id = u.id 
            WHERE l.id = ?
        ");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

if (!$listing) {
    header("Location: browse.php");
    exit();
}

$isOwner = ($userId && $listing['user_id'] == $userId);

// Handle Application Submission if user clicks "Submit Application Now"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_action']) && !$isOwner) {
    try {
        // Check existing application status
        $checkStmt = $pdo->prepare("SELECT id, status FROM applications WHERE listing_id = ? AND applicant_id = ?");
        $checkStmt->execute([$listingId, $userId]);
        $existingApp = $checkStmt->fetch();

        if ($existingApp) {
            $appStatus = $existingApp['status'];
            $errorMessage = "You have already applied to this listing.";
        } else {
            $insApp = $pdo->prepare("INSERT INTO applications (listing_id, applicant_id, status, applied_at) VALUES (?, ?, 'pending', NOW())");
            $insApp->execute([$listingId, $userId]);
            $successMessage = "Your application was submitted successfully!";
            $appStatus = 'pending';
        }
    } catch (Exception $e) {
        $errorMessage = "Error submitting application: " . $e->getMessage();
    }
} else if ($userId && !$isOwner) {
    try {
        $checkStmt = $pdo->prepare("SELECT status FROM applications WHERE listing_id = ? AND applicant_id = ?");
        $checkStmt->execute([$listingId, $userId]);
        $resApp = $checkStmt->fetch();
        if ($resApp) {
            $appStatus = $resApp['status'];
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Listing Details</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6fb; color: #111; font-size: 1rem; }
        h1, h2, h3, h4 { font-weight: bold; }

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
    </style>
</head>
<body style="background-color: #f4f6fb;">

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

    <!-- Main Content Area -->
    <main style="padding: 40px 30px; max-width: 1000px; margin: 0 auto;">
        
        <!-- Back Navigation -->
        <div style="margin-bottom: 20px;">
            <a href="<?php echo $backUrl; ?>" style="color: #4154f1; text-decoration: none; font-weight: bold; font-size: 0.95rem;">
                &larr; Back to <?php echo $backLabel; ?>
            </a>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div style="background: #eef9f0; color: #1e8e3e; padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; border: 1px solid #c8e6c9;">
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div style="background: #fdf2f2; color: #d93025; padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; border: 1px solid #fadbd8;">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <!-- Listing Details Card -->
        <div style="background: #fff; border-radius: 16px; border: 1px solid #eaeaea; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
            
            <!-- Poster Header Info -->
            <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 25px; border-bottom: 1px solid #eaeaea; padding-bottom: 20px;">
                <div style="width: 50px; height: 50px; border-radius: 50%; background: #4154f1; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; overflow: hidden;">
                    <?php if (!empty($listing['poster_avatar']) && file_exists($listing['poster_avatar'])): ?>
                        <img src="<?php echo htmlspecialchars($listing['poster_avatar']); ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <?php echo strtoupper(substr($listing['poster_name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 style="font-size: 1.05rem; font-weight: bold; color: #111;"><?php echo htmlspecialchars($listing['poster_name']); ?></h3>
                    <span style="font-size: 0.85rem; color: #666;"><?php echo htmlspecialchars($listing['poster_location'] ?? 'Quezon City, Metro Manila'); ?></span>
                </div>
            </div>

            <!-- Job Title & Budget -->
            <div style="margin-bottom: 20px;">
                <span style="background: #eef2ff; color: #4154f1; padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($listing['category']); ?></span>
                <h1 style="font-size: 2.2rem; font-weight: bold; color: #111; margin-top: 10px; margin-bottom: 6px;"><?php echo htmlspecialchars($listing['title']); ?></h1>
                <div style="font-size: 1.3rem; font-weight: bold; color: #4154f1;">₱<?php echo number_format($listing['budget_min']); ?> - ₱<?php echo number_format($listing['budget_max']); ?></div>
            </div>

            <!-- Full Listing Information Section -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; background: #f8f9fe; padding: 20px; border-radius: 12px; border: 1px solid #e1e7fc; margin-bottom: 25px;">
                <div>
                    <span style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px;">Location</span>
                    <span style="font-size: 0.95rem; font-weight: 600; color: #111;"><?php echo htmlspecialchars($listing['location']); ?></span>
                </div>
                <div>
                    <span style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px;">Work Type</span>
                    <span style="font-size: 0.95rem; font-weight: 600; color: #111;"><?php echo htmlspecialchars(ucfirst($listing['work_type'] ?? 'Part-time')); ?></span>
                </div>
                <div>
                    <span style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px;">Category</span>
                    <span style="font-size: 0.95rem; font-weight: 600; color: #111;"><?php echo htmlspecialchars($listing['category']); ?></span>
                </div>
                <div>
                    <span style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px;">Status</span>
                    <span style="font-size: 0.95rem; font-weight: 600; color: #1e8e3e; text-transform: uppercase;"><?php echo htmlspecialchars($listing['status']); ?></span>
                </div>
                <div style="grid-column: span 2; border-top: 1px solid #e1e7fc; padding-top: 10px; margin-top: 5px;">
                    <span style="font-size: 0.75rem; font-weight: bold; text-transform: uppercase; color: #888; display: block; margin-bottom: 6px;">Skills Required</span>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <?php 
                        $reqSkills = !empty($listing['skills_required']) ? explode(',', $listing['skills_required']) : ['General'];
                        foreach ($reqSkills as $sk): 
                        ?>
                            <span style="background: #fff; color: #4154f1; border: 1px solid #c7d2fe; padding: 3px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;"><?php echo htmlspecialchars(trim($sk)); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Job Description -->
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1.1rem; font-weight: bold; color: #111; margin-bottom: 10px;">Job Description</h3>
                <p style="color: #444; font-size: 1rem; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($listing['description']); ?></p>
            </div>

            <!-- Footer Action Buttons -->
            <div style="border-top: 1px solid #eaeaea; padding-top: 25px; display: flex; justify-content: space-between; align-items: center;">
                <a href="<?php echo $backUrl; ?>" style="background: #fff; color: #333; border: 1px solid #dcdfe8; padding: 10px 22px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.9rem;">Back</a>

                <?php if ($isOwner): ?>
                    <div style="background: #eef2ff; color: #4154f1; padding: 10px 18px; border-radius: 8px; font-weight: bold; font-size: 0.9rem; border: 1px solid #c7d2fe;">
                        This is your own job posting. You can manage it from My Listings.
                    </div>
                <?php else: ?>
                    <form action="listing-details.php?id=<?php echo $listingId . $fromParam; ?>" method="POST" style="margin: 0;">
                        <input type="hidden" name="apply_action" value="1">
                        <?php if ($appStatus === 'accepted'): ?>
                            <!-- 'accepted' in the database = the employer shortlisted you (JobIT helps employers find people, it does not hire) -->
                            <button type="button" disabled style="background: #1e8e3e; color: #fff; padding: 12px 28px; border-radius: 25px; border: none; font-weight: 700; font-size: 0.95rem; cursor: not-allowed; opacity: 0.9;">
                                &#10003; You're Shortlisted
                            </button>
                        <?php elseif ($appStatus === 'rejected'): ?>
                            <button type="button" disabled style="background: #8d94a8; color: #fff; padding: 12px 28px; border-radius: 25px; border: none; font-weight: 700; font-size: 0.95rem; cursor: not-allowed; opacity: 0.9;">
                                Not Selected
                            </button>
                        <?php elseif ($appStatus === 'pending'): ?>
                            <button type="button" disabled style="background: #f59e0b; color: #fff; padding: 12px 28px; border-radius: 25px; border: none; font-weight: 700; font-size: 0.95rem; cursor: not-allowed; opacity: 0.9;">
                                Application Submitted (Pending)
                            </button>
                        <?php else: ?>
                            <button type="submit" style="background: #4154f1; color: #fff; padding: 12px 28px; border-radius: 25px; border: none; font-weight: 700; font-size: 0.95rem; cursor: pointer; box-shadow: 0 4px 12px rgba(65,84,241,0.3);">
                                Submit Application Now
                            </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

        </div>

    </main>

    <script>
        function toggleDropdown() {
            document.getElementById("profileDropdown").classList.toggle("show-dropdown");
        }

        window.onclick = function(event) {
            if (!event.target.matches('#avatarBtn') && !event.target.closest('.profile-avatar-btn')) {
                var dropdowns = document.getElementsByClassName("dropdown-menu");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show-dropdown')) {
                        openDropdown.classList.remove('show-dropdown');
                    }
                }
            }
        }
    </script>
</body>
</html>