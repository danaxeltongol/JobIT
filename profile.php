<?php
// ============================================================================
// JOBIT PROFILE ENGINE (VIEW MODE + EDIT TOGGLE + PHONE & BIO SUPPORT)
// ============================================================================

session_start();
require_once 'db.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header("Location: auth.php?mode=login");
    exit();
}

$message = '';
$error = '';
$editMode = (isset($_GET['edit']) && $_GET['edit'] === 'true') || (($_GET['section'] ?? '') === 'settings');

// Fetch current user data from DB
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        header("Location: auth.php?mode=login");
        exit();
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle Profile Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $avatarPath = $user['avatar'];

    // Available Schedule, comes in as checkbox array, saved as comma text just like skills
    $availableSlots = !empty($_POST['available_slots']) ? implode(', ', $_POST['available_slots']) : '';   // nothing ticked = nothing saved (no made-up "Weekdays")

    // Handle Profile Picture Upload if provided
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['avatar']['tmp_name'];
        $fileName = $_FILES['avatar']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = 'avatar_' . $userId . '_' . time() . '.' . $fileExtension;
            $uploadFileDir = './uploads/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            $dest_path = $uploadFileDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $avatarPath = $dest_path;
            }
        }
    }

    if (empty($fullName) || empty($email)) {
        $error = "Full Name and Email Address are required fields.";
    } else {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET full_name = ?, email = ?, phone = ?, location = ?, skills = ?, bio = ?, avatar = ?, available_slots = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$fullName, $email, $phone, $location, $skills, $bio, $avatarPath, $availableSlots, $userId]);
            
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            $message = "Profile updated successfully!";
            $editMode = false; // Switch back to view mode after saving
        } catch (Exception $e) {
            $error = "Error updating profile: " . $e->getMessage();
        }
    }

    // If saving failed, stay on the form and keep what was typed
    if (!empty($error)) {
        $editMode = true;
        $user = array_merge($user, [
            'full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'location' => $location,
            'skills' => $skills, 'bio' => $bio, 'available_slots' => $availableSlots,
        ]);
    }
}

// Compute Initials for Avatar Fallback
$words = explode(' ', $user['full_name'] ?? 'User');
$initials = '';
foreach ($words as $w) {
    if (!empty($w)) $initials .= strtoupper($w[0]);
}
$userInitials = substr($initials, 0, 2) ?: 'U';
$userAvatar = $user['avatar'] ?? '';
$userSkillsArray = !empty($user['skills']) ? array_map('trim', explode(',', $user['skills'])) : [];
$userSlotsArray = !empty($user['available_slots']) ? array_map('trim', explode(',', $user['available_slots'])) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - My Profile</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue: #4154f1; --deep: #2938b7; --soft: #eef1ff; --yellow: #ffe600;
            --ink: #161b3a; --muted: #6b7280; --line: #e8ebf5; --bg: #f7f8fc;
            --good: #1e8e3e; --good-bg: #e6f9ed; --bad: #d9534f; --bad-bg: #fff0f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body {
            background-color: #4154f1;
            background-image:
                radial-gradient(circle at 10% 6%, rgba(255,255,255,0.16) 0, transparent 36%),
                radial-gradient(circle at 94% 34%, rgba(255,230,0,0.12) 0, transparent 28%),
                linear-gradient(180deg, #4154f1 0%, #3446db 55%, #2938b7 100%);
            background-attachment: fixed;
            color: var(--ink); font-size: 1rem; min-height: 100vh;
        }
        /* soft dot texture that fades out toward the bottom */
        body::before {
            content: ""; position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background-image: radial-gradient(rgba(255,255,255,0.16) 1.2px, transparent 1.2px);
            background-size: 26px 26px;
            -webkit-mask-image: linear-gradient(180deg, #000 0%, transparent 65%);
            mask-image: linear-gradient(180deg, #000 0%, transparent 65%);
        }
        .navbar, .page { position: relative; z-index: 1; }
        h1, h2, h3, h4 { font-weight: bold; }
        input, select, button, textarea { font: inherit; }

        /* ---------- Top Navigation Bar ---------- */
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; background: #fff; border-bottom: 1px solid #eaeaea; }
        .logo img { height: 36px; }
        .nav-links-center { display: flex; gap: 8px; align-items: center; }
        .nav-link-item { color: #3b4160; text-decoration: none; font-weight: 600; font-size: 0.95rem; padding: 8px 14px; border-radius: 999px; transition: background 0.2s, color 0.2s; }
        .nav-link-item:hover { background: var(--soft); color: var(--blue); }
        .nav-link-item.active-page { background: var(--soft); color: var(--blue); font-weight: 700; }

        .profile-dropdown-container { position: relative; display: inline-block; }
        .profile-avatar-btn {
            background: var(--blue); color: #fff; width: 38px; height: 38px; border-radius: 50%;
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
        .dropdown-menu a:hover { background-color: var(--soft); color: var(--blue); }
        .dropdown-menu .dropdown-logout { color: #ff4d4d; border-top: 1px solid #eee; }
        .show-dropdown { display: block !important; }

        /* ---------- Page ---------- */
        .page { max-width: 1100px; margin: 0 auto; padding: 36px 30px 64px; }
        .page-head { margin-bottom: 26px; }
        .crumbs { font-size: 0.82rem; color: rgba(255,255,255,0.72); margin-bottom: 10px; font-weight: 600; }
        .crumbs a { color: rgba(255,255,255,0.72); text-decoration: none; }
        .crumbs a:hover { color: #fff; }
        .crumbs strong { color: var(--yellow); font-weight: 700; }
        .page-head h1 { font-size: 1.9rem; font-weight: 800; letter-spacing: -0.02em; color: #fff; line-height: 1.25; }
        .page-head p { color: rgba(255,255,255,0.82); font-size: 0.97rem; margin-top: 4px; }

        .flash { display: flex; align-items: center; gap: 10px; padding: 13px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 700; font-size: 0.93rem; box-shadow: 0 10px 24px rgba(14,22,90,0.2); }
        .flash::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
        .flash.ok { background: var(--good-bg); color: var(--good); }
        .flash.err { background: var(--bad-bg); color: var(--bad); }

        /* ---------- Two column layout ---------- */
        .layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 24px; align-items: start; }

        /* Left: identity card with a small blue cover */
        .side { background: #fff; border: none; border-radius: 22px; overflow: hidden; position: sticky; top: 24px; box-shadow: 0 20px 44px rgba(14,22,90,0.28); }
        .side-body { padding: 0 24px 26px; text-align: center; }
        .avatar-xl { width: 104px; height: 104px; border-radius: 50%; background: var(--blue); color: #fff; border: 5px solid #fff; display: flex; align-items: center; justify-content: center; font-size: 2.1rem; font-weight: 800; overflow: hidden; margin: 30px auto 14px; box-shadow: 0 6px 18px rgba(22,27,58,0.12); }
        .avatar-xl img { width: 100%; height: 100%; object-fit: cover; }
        .side-body h2 { font-size: 1.25rem; font-weight: 800; letter-spacing: -0.01em; color: var(--ink); word-break: break-word; }
        .side-email { color: var(--muted); font-size: 0.88rem; margin-top: 3px; word-break: break-all; }
        .side-btn { margin-top: 18px; display: block; }
        .quick { text-align: left; margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 14px; }
        .quick .lbl { margin-bottom: 3px; }
        .quick .val { font-size: 0.95rem; }
        .upload-box { margin-top: 18px; text-align: left; border: 1.5px dashed #c9d0fb; background: #fafbff; border-radius: 14px; padding: 14px 16px; }
        .upload-box label { font-weight: 700; display: block; margin-bottom: 8px; font-size: 0.85rem; color: var(--ink); }
        .upload-box input[type=file] { font-size: 0.82rem; width: 100%; }

        /* Right: sections */
        .main-card { background: #fff; border: none; border-radius: 22px; padding: 8px 34px; box-shadow: 0 20px 44px rgba(14,22,90,0.28); }
        .sec { padding: 26px 0; border-bottom: 1px solid var(--line); }
        .sec:last-child { border-bottom: none; }
        .sec-title { display: flex; align-items: center; gap: 10px; font-size: 1rem; font-weight: 800; color: var(--ink); margin-bottom: 16px; }
        .sec-title::before { content: ""; width: 4px; height: 18px; border-radius: 2px; background: var(--blue); }

        .lbl { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.8px; color: var(--muted); display: block; margin-bottom: 6px; }
        .val { font-size: 1.02rem; font-weight: 600; color: var(--ink); }
        .none { color: #aab0c0; font-style: italic; font-weight: 400; }
        .grid2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 22px; }
        .badges { display: flex; flex-wrap: wrap; gap: 8px; }
        .view-skill-badge { background: var(--soft); color: var(--blue); padding: 6px 14px; border-radius: 999px; font-size: 0.85rem; font-weight: 700; display: inline-block; }
        .bio-text { font-size: 0.97rem; color: #3d4358; line-height: 1.7; }

        .btn { display: inline-block; padding: 11px 26px; border-radius: 999px; font-weight: 700; font-size: 0.9rem; text-decoration: none; cursor: pointer; border: 1.5px solid transparent; transition: background 0.2s, border-color 0.2s, color 0.2s; text-align: center; }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: var(--deep); }
        .btn-ghost { background: #fff; color: #3b4160; border-color: #d5daf0; }
        .btn-ghost:hover { background: var(--soft); color: var(--blue); border-color: var(--blue); }

        /* ---------- Edit form ---------- */
        .field { margin-bottom: 18px; }
        .field:last-child { margin-bottom: 0; }
        .field label.t { font-weight: 700; display: block; margin-bottom: 7px; font-size: 0.88rem; color: var(--ink); }
        .inp { width: 100%; padding: 11px 14px; border: 1.5px solid #dfe3f3; border-radius: 12px; font-size: 0.95rem; outline: none; background: #fff; transition: border-color 0.2s, box-shadow 0.2s; }
        .inp:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(65,84,241,0.12); }
        textarea.inp { resize: vertical; }
        .help { font-size: 0.78rem; color: var(--muted); margin-top: 6px; display: block; }
        .form-actions { display: flex; justify-content: flex-end; gap: 12px; padding: 22px 0 22px; border-top: 1px solid var(--line); }

        .slot-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .slot-check-label { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; font-weight: 600; color: #3b4160; padding: 9px 16px; border: 1.5px solid #dfe3f3; border-radius: 999px; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
        .slot-check-label:hover { border-color: var(--blue); background: var(--soft); }
        .slot-check-label input { accent-color: var(--blue); width: 15px; height: 15px; }

        /* Tag Picker Styles for Edit Mode */
        .skill-tag-container {
            border: 1.5px solid #dfe3f3; border-radius: 12px; padding: 7px 10px; background: #fff;
            display: flex; flex-wrap: wrap; gap: 6px; align-items: center; min-height: 46px; position: relative;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .skill-tag-container:focus-within { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(65,84,241,0.12); }
        .skill-pill {
            background: var(--soft); color: var(--blue); padding: 4px 10px; border-radius: 999px; font-size: 0.82rem;
            font-weight: 700; display: inline-flex; align-items: center; gap: 6px;
        }
        .skill-pill .remove-tag { cursor: pointer; font-weight: bold; color: var(--blue); font-size: 0.95rem; line-height: 1; }
        .skill-pill .remove-tag:hover { color: #ff4d4d; }
        .skill-input-box { border: none; outline: none; flex: 1; min-width: 140px; font-size: 0.9rem; padding: 4px; background: transparent; }
        
        .suggestions-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #dfe3f3;
            border-radius: 12px; margin-top: 4px; max-height: 200px; overflow-y: auto; z-index: 999;
            box-shadow: 0 8px 24px rgba(22,27,58,0.1); display: none;
        }
        .suggestion-item { padding: 9px 14px; cursor: pointer; font-size: 0.9rem; color: #333; font-weight: 600; }
        .suggestion-item:hover { background: var(--soft); color: var(--blue); }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .side { position: static; }
        }
        @media (max-width: 700px) {
            .navbar { padding: 12px 16px; }
            .page { padding: 24px 16px 48px; }
            .main-card { padding: 4px 20px; }
            .grid2 { grid-template-columns: 1fr; }
            .page-head h1 { font-size: 1.55rem; }
            .form-actions { flex-direction: column-reverse; }
        }

        /* ---- Added: no-reload updates ---- */
        #profile-root { transition: opacity .2s .12s; }
        #profile-root.pf-loading { opacity: 0.6; pointer-events: none; }
        .btn:disabled { opacity: 0.6; cursor: wait; }
        .pf-toast {
            position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px); background: var(--ink); color: #fff;
            padding: 13px 24px; border-radius: 999px; font-weight: 700; font-size: 0.92rem; text-align: center;
            max-width: calc(100vw - 32px); box-shadow: 0 16px 36px rgba(10,16,70,0.35); opacity: 0; pointer-events: none;
            transition: opacity .25s, transform .25s; z-index: 3000;
        }
        .pf-toast.show { opacity: 1; transform: translate(-50%, 0); }
        .pf-toast.ok { background: var(--good); }
        .pf-toast.err { background: var(--bad); }
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
            <a href="profile.php" class="nav-link-item active-page">Profile</a>
            <a href="post-listing.php" class="nav-link-item" style="background: #4154f1; color: #fff; font-weight: 700;">+ Post a Listing</a>
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

    <!-- Main Profile Container -->
    <main class="page">

        <div class="page-head">
            <div class="crumbs"><a href="dashboard.php">Home</a> &rsaquo; <strong>Profile</strong></div>
            <h1>Manage Profile &amp; Skills</h1>
            <p>Update your professional credentials to power JobiT's matching engine.</p>
        </div>

        <div id="profile-root">

        <?php if (!empty($message)): ?>
            <div class="flash ok"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="flash err"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($editMode): ?>
        <form action="profile.php" method="POST" enctype="multipart/form-data">
        <?php endif; ?>

        <div class="layout">

            <!-- ================= LEFT: IDENTITY CARD ================= -->
            <aside class="side">
                <div class="side-body">
                    <div class="avatar-xl">
                        <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                            <img src="<?php echo htmlspecialchars($userAvatar); ?>">
                        <?php else: ?>
                            <?php echo htmlspecialchars($userInitials); ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!$editMode): ?>
                        <!-- VIEW MODE -->
                        <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                        <p class="side-email"><?php echo htmlspecialchars($user['email']); ?></p>
                        <a href="profile.php?edit=true" class="btn btn-primary side-btn" data-ajax>Edit Profile</a>

                        <div class="quick">
                            <div>
                                <span class="lbl">Phone Number</span>
                                <p class="val"><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="none">Not provided</span>'; ?></p>
                            </div>
                            <div>
                                <span class="lbl">Location</span>
                                <p class="val"><?php echo !empty($user['location']) ? htmlspecialchars($user['location']) : '<span class="none">Not provided</span>'; ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- EDIT MODE -->
                        <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                        <p class="side-email">Editing your profile</p>

                        <div class="upload-box">
                            <label>Profile Picture</label>
                            <input type="file" name="avatar" accept="image/*">
                        </div>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ================= RIGHT: DETAILS ================= -->
            <div>
                <div class="main-card">

                    <?php if (!$editMode): ?>
                        <!-- ================= VIEW PROFILE MODE ================= -->
                        <div class="sec">
                            <div class="sec-title">Available Schedule</div>
                            <div class="badges">
                                <?php if (!empty($userSlotsArray)): ?>
                                    <?php foreach ($userSlotsArray as $slot): ?>
                                        <span class="view-skill-badge"><?php echo htmlspecialchars($slot); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="none">No availability added yet.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sec">
                            <div class="sec-title">Professional Skills</div>
                            <div class="badges">
                                <?php if (!empty($userSkillsArray)): ?>
                                    <?php foreach ($userSkillsArray as $s): ?>
                                        <span class="view-skill-badge"><?php echo htmlspecialchars($s); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="none">No skills added yet.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sec">
                            <div class="sec-title">Bio / Professional Summary</div>
                            <p class="bio-text"><?php echo !empty($user['bio']) ? nl2br(htmlspecialchars($user['bio'])) : '<span class="none">No bio added yet.</span>'; ?></p>
                        </div>

                    <?php else: ?>
                        <!-- ================= EDIT PROFILE MODE ================= -->
                        <div class="sec">
                            <div class="sec-title">Basic Information</div>
                            <div class="grid2" style="margin-bottom: 18px;">
                                <div class="field">
                                    <label class="t">Full Name</label>
                                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required class="inp">
                                </div>
                                <div class="field">
                                    <label class="t">Email Address</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="inp">
                                </div>
                            </div>
                            <div class="grid2">
                                <div class="field">
                                    <label class="t">Phone Number</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="09123456789" class="inp">
                                </div>
                                <div class="field">
                                    <label class="t">Location</label>
                                    <input type="text" name="location" value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>" placeholder="Quezon City, Metro Manila" class="inp">
                                </div>
                            </div>
                        </div>

                        <!-- Available Schedule, this now powers the real match score instead of a fixed default -->
                        <div class="sec">
                            <div class="sec-title">Available Schedule</div>
                            <div class="slot-row">
                                <label class="slot-check-label">
                                    <input type="checkbox" name="available_slots[]" value="Weekdays" <?php echo in_array('Weekdays', $userSlotsArray) ? 'checked' : ''; ?>>
                                    Weekdays
                                </label>
                                <label class="slot-check-label">
                                    <input type="checkbox" name="available_slots[]" value="Mornings" <?php echo in_array('Mornings', $userSlotsArray) ? 'checked' : ''; ?>>
                                    Mornings
                                </label>
                                <label class="slot-check-label">
                                    <input type="checkbox" name="available_slots[]" value="Afternoons" <?php echo in_array('Afternoons', $userSlotsArray) ? 'checked' : ''; ?>>
                                    Afternoons
                                </label>
                                <label class="slot-check-label">
                                    <input type="checkbox" name="available_slots[]" value="Evenings" <?php echo in_array('Evenings', $userSlotsArray) ? 'checked' : ''; ?>>
                                    Evenings
                                </label>
                                <label class="slot-check-label">
                                    <input type="checkbox" name="available_slots[]" value="Weekends" <?php echo in_array('Weekends', $userSlotsArray) ? 'checked' : ''; ?>>
                                    Weekends
                                </label>
                            </div>
                            <span class="help">Pick every time you are free to work. This now powers the Schedule part of your match score.</span>
                        </div>

                        <!-- Interactive Smart Skill Tag Picker -->
                        <div class="sec">
                            <div class="sec-title">Professional Skills</div>
                            <div class="field">
                                <label class="t">Type &amp; select suggestions</label>
                                <div class="skill-tag-container" id="skillTagContainer" onclick="document.getElementById('skillInput').focus();">
                                    <input type="text" id="skillInput" class="skill-input-box" placeholder="Type skill (e.g. PHP, HTML/CSS)...">
                                    <div id="suggestionsDropdown" class="suggestions-dropdown"></div>
                                </div>
                                <input type="hidden" name="skills" id="skillsHiddenInput" value="<?php echo htmlspecialchars($user['skills'] ?? ''); ?>">
                                <span class="help">Type a skill and click suggestion or press Enter to add tag.</span>
                            </div>
                        </div>

                        <div class="sec">
                            <div class="sec-title">Bio / Professional Summary</div>
                            <textarea name="bio" rows="4" placeholder="Write a short summary about yourself..." class="inp"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-actions">
                            <a href="profile.php" class="btn btn-ghost" data-ajax>Cancel</a>
                            <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <?php if ($editMode): ?>
        </form>
        <?php endif; ?>

        </div><!-- /#profile-root -->

    </main>

    <!-- Small message that pops up after saving (no page reload) -->
    <div class="pf-toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        // ==========================================================================
        // Edit Profile, Cancel and Save Changes now update the page WITHOUT reloading
        // it. PHP still does all the real work (checking, saving the picture, saving
        // the profile). JavaScript only sends the request in the background and swaps
        // in the part of the page that changed. If anything goes wrong it falls back
        // to a normal page load.
        // ==========================================================================
        var PAGE = 'profile.php';
        var latestRequest = 0;
        var toastTimer = null;

        function showToast(message, kind) {
            var toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'pf-toast show' + (kind ? ' ' + kind : '');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4500);
        }

        function toggleDropdown() {
            document.getElementById("profileDropdown").classList.toggle("show-dropdown");
        }

        // ---------------- Put new HTML from PHP into the page ----------------
        // Returns null if it is not the profile page (for example the session expired and PHP sent the login page).
        function swapIn(html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newRoot = doc.getElementById('profile-root');
            if (!newRoot) { return null; }

            // The green "saved" bar becomes a toast; an error bar stays on the page next to the form
            var okFlash = newRoot.querySelector('.flash.ok');
            var okText = okFlash ? okFlash.textContent.trim() : '';
            if (okFlash) { okFlash.remove(); }

            document.getElementById('profile-root').innerHTML = newRoot.innerHTML;

            // The little round picture in the top bar can change after saving
            var newAvatar = doc.getElementById('avatarBtn');
            if (newAvatar) { document.getElementById('avatarBtn').innerHTML = newAvatar.innerHTML; }

            initSkillPicker();   // the skill picker only exists in edit mode, so set it up again
            return { saved: okText, editing: !!newRoot.querySelector('form') };
        }

        // mode: 'push' = add to browser history (Back button works), 'replace' = update the address only, 'none' = leave it
        function loadPage(url, mode) {
            var myRequest = ++latestRequest;
            var root = document.getElementById('profile-root');
            root.classList.add('pf-loading');

            fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (res.redirected && res.url.indexOf(PAGE) === -1) { throw new Error('redirected'); }
                    return res.text();
                })
                .then(function (html) {
                    if (myRequest !== latestRequest) { return; }
                    if (!swapIn(html)) { throw new Error('unexpected page'); }
                    if (mode === 'push') { history.pushState(null, '', url); }
                    if (mode === 'replace') { history.replaceState(null, '', url); }
                })
                .catch(function () {
                    if (myRequest === latestRequest) { window.location.href = url; }
                })
                .then(function () {
                    if (myRequest === latestRequest) { root.classList.remove('pf-loading'); }
                });
        }

        // Edit Profile / Cancel
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a[data-ajax]') : null;
            if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            loadPage(link.href, link.href === window.location.href ? 'replace' : 'push');
        });

        // Back / Forward buttons
        window.addEventListener('popstate', function () {
            loadPage(window.location.href, 'none');
        });

        // Save Changes (the picture upload goes along too)
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.closest || !form.closest('#profile-root')) { return; }
            e.preventDefault();

            var button = form.querySelector('button[type="submit"]');
            var label = button ? button.textContent : '';
            if (button) { button.disabled = true; button.textContent = 'Saving...'; }

            var data = new FormData(form);
            data.append('update_profile', '1');   // PHP looks for this to know it is a save
            var myRequest = ++latestRequest;

            fetch(form.getAttribute('action') || PAGE, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (res) {
                    if (res.redirected && res.url.indexOf(PAGE) === -1) { throw new Error('redirected'); }
                    return res.text();
                })
                .then(function (html) {
                    var result = swapIn(html);
                    if (!result) { throw new Error('unexpected page'); }
                    if (result.saved) {
                        showToast(result.saved, 'ok');
                        history.replaceState(null, '', PAGE);   // back to the normal view address
                    }
                    var root = document.getElementById('profile-root');
                    if (root.getBoundingClientRect().top < 0) { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                })
                .catch(function (err) {
                    if (err && err.message === 'redirected') { window.location.href = PAGE; return; }
                    if (button) { button.disabled = false; button.textContent = label; }
                    showToast('Error: could not save your profile. Please try again.', 'err');
                })
                .then(function () {
                    if (myRequest === latestRequest) { document.getElementById('profile-root').classList.remove('pf-loading'); }
                });
        });

        // ---------------- Skill tag picker (edit mode) ----------------
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
        let skillInput = null, skillTagContainer = null, suggestionsDropdown = null, skillsHiddenInput = null;

        // Sets up the picker. It runs when the page opens and again every time edit mode is swapped in.
        function initSkillPicker() {
            skillInput = document.getElementById('skillInput');
            if (!skillInput) { return; }
            skillTagContainer = document.getElementById('skillTagContainer');
            suggestionsDropdown = document.getElementById('suggestionsDropdown');
            skillsHiddenInput = document.getElementById('skillsHiddenInput');

            // The saved skills come from the hidden field PHP filled in
            selectedSkills = skillsHiddenInput.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            renderTags();

            skillInput.addEventListener('input', function () {
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

            skillInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const val = this.value.trim();
                    if (val) {
                        addSkill(val);
                    }
                }
            });
        }

        function renderTags() {
            skillTagContainer.querySelectorAll('.skill-pill').forEach(p => p.remove());

            selectedSkills.forEach(skill => {
                const pill = document.createElement('div');
                pill.className = 'skill-pill';
                pill.appendChild(document.createTextNode(skill + ' '));
                const x = document.createElement('span');
                x.className = 'remove-tag';
                x.innerHTML = '&times;';
                x.addEventListener('click', function () { removeSkill(skill); });
                pill.appendChild(x);
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

        initSkillPicker();

        // Click elsewhere: hide the skill suggestions and the profile menu
        document.addEventListener('click', function (e) {
            if (skillTagContainer && suggestionsDropdown && !skillTagContainer.contains(e.target)) {
                suggestionsDropdown.style.display = 'none';
            }
            if (!e.target.closest('.profile-dropdown-container')) {
                document.getElementById('profileDropdown').classList.remove('show-dropdown');
            }
        });
    </script>
</body>
</html>