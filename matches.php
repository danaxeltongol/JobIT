<?php
// ============================================================================
// JOBIT MATCHES ENGINE (APPLICANT QUEUE WITH SYNCHRONIZED SCORING)
// ============================================================================

session_start();
require_once 'db.php';
require_once __DIR__ . '/sorting.php';   // our own merge sort (replaces usort)

$userId = $_SESSION['user_id'] ?? null;
if (!$userId && !isset($_SESSION['user_profile'])) {
    header("Location: auth.php?mode=login");
    exit();
}

$currentUserProfile = $_SESSION['user_profile'] ?? [];
$userName = $currentUserProfile['name'] ?? 'User';
$userInitials = $currentUserProfile['initials'] ?? 'U';
$userAvatar = $currentUserProfile['avatar'] ?? '';
// No made-up defaults: if the user has not filled these in, they stay empty and earn 0 points for that part of the score
$userSkillsStr = '';
$userLocation = $currentUserProfile['location'] ?? '';
$userSlotsStr = '';

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
            if (!empty($dbUser['skills'])) {
                $userSkillsStr = $dbUser['skills'];
            }
            if (!empty($dbUser['location'])) {
                $userLocation = $dbUser['location'];
            }
            if (!empty($dbUser['available_slots'])) {
                $userSlotsStr = $dbUser['available_slots'];
            }
        }
    } catch (Exception $e) {}
}

// A secret code for this login session. Every Shortlist / Reject must send it back,
// so another website (or a link someone sends you) can never change an applicant's status.
if (empty($_SESSION['jobit_csrf'])) {
    $_SESSION['jobit_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['jobit_csrf'];

// Handle Status Updates (Shortlist / Reject) in database
// Only a POST from this page (with the secret code) changes anything. The old link
// matches.php?action=accept&app_id=5 does nothing any more.
// Safety: only the two known actions, and only for applicants on listings that belong to the logged-in user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['app_id']) && in_array($_POST['action'], ['accept', 'reject'], true)) {
    $action = $_POST['action'];
    $appId = intval($_POST['app_id']);
    $newStatus = ($action === 'accept') ? 'accepted' : 'rejected';   // 'accepted' = shortlisted (the database word stays the same)

    if (!hash_equals($csrfToken, (string)($_POST['csrf'] ?? ''))) {
        header("Location: matches.php?tab=applicants&msg=expired");
        exit();
    }
    try {
        $updStmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ? AND listing_id IN (SELECT id FROM listings WHERE user_id = ?)");
        $updStmt->execute([$newStatus, $appId, $userId]);
        // Post / Redirect / Get: a page refresh can never send the decision twice
        header("Location: matches.php?tab=applicants&msg=" . $action);
        exit();
    } catch (Exception $e) {}
}

// Fetch user's ACTIVE listings only (completed jobs are completely removed from Matches applicant queue)
$userListings = [];
$applicantQueueByJob = [];
$totalApplicantsCount = 0;

if ($userId) {
    try {
        $listStmt = $pdo->prepare("SELECT id, title, category, location, skills_required, schedule, created_at FROM listings WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC");
        $listStmt->execute([$userId]);
        $userListings = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($userListings as $listing) {
            $listingId = $listing['id'];
            $jobLocation = $listing['location'];
            $jobSchedule = $listing['schedule'] ?? '';

            // Fetch only pending applicants, including their skills, location and available schedule
            $appStmt = $pdo->prepare("
                SELECT a.*, u.full_name as applicant_name, u.email, u.phone, u.bio, u.skills, u.location, u.avatar, u.available_slots 
                FROM applications a 
                JOIN users u ON a.applicant_id = u.id 
                WHERE a.listing_id = ? AND a.status = 'pending'
                ORDER BY a.applied_at ASC
            ");
            $appStmt->execute([$listingId]);
            $rawApplicants = $appStmt->fetchAll(PDO::FETCH_ASSOC);

            $applicants = [];
            foreach ($rawApplicants as $app) {
                // UNIFIED MATCH SCORING, same calculateMatchScore() function used in browse.php and my-listings.php
                $app['match_percentage'] = calculateMatchScore(
                    $listing['skills_required'] ?? '',
                    $jobLocation,
                    $jobSchedule,
                    $app['skills'] ?? '',
                    $app['location'] ?? '',
                    $app['available_slots'] ?? ''
                );

                $app['parsed_skills'] = !empty($app['skills']) ? array_map('trim', explode(',', $app['skills'])) : ['General'];
                $applicants[] = $app;
            }

            // Our own merge sort (sorting.php): best applicant first, equal scores keep their order
            $applicants = mergeSortByKey($applicants, 'match_percentage');

            $applicantQueueByJob[$listingId] = [
                'title' => $listing['title'],
                'category' => $listing['category'],
                'applicants' => $applicants
            ];
            $totalApplicantsCount += count($applicants);
        }
    } catch (Exception $e) {}
}

// Fetch Recommended Gigs from DB excluding user's own listings
$recommendedGigs = [];
try {
    // employer_avatar = the poster's CURRENT profile picture, read from the database on every request
    $sql = "SELECT l.*, u.full_name as employer_name, u.avatar as employer_avatar FROM listings l JOIN users u ON l.user_id = u.id WHERE l.status = 'active'";
    $params = [];

    if ($userId) {
        $sql .= " AND l.user_id != ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY l.created_at DESC";

    $gigStmt = $pdo->prepare($sql);
    $gigStmt->execute($params);
    $allGigs = $gigStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allGigs as $gig) {
        // UNIFIED MATCH SCORING, same formula as browse.php and the applicant queue above.
        // This is why the percent for the same job will now match browse.php exactly.
        // getScoreBreakdown() gives the three parts; its 'total' is exactly what calculateMatchScore() returns
        $gig['why'] = getScoreBreakdown(
            $gig['skills_required'] ?? '',
            $gig['location'] ?? '',
            $gig['schedule'] ?? '',
            $userSkillsStr,
            $userLocation,
            $userSlotsStr
        );
        $gig['match_score'] = $gig['why']['total'];
        // Which of the job's skills you already have / still miss (for the green and grey chips)
        $gig['skill_chips'] = getSkillsBreakdown($gig['skills_required'] ?? '', $userSkillsStr);
        $recommendedGigs[] = $gig;
    }

    // Our own merge sort (sorting.php): best match first, equal scores keep their order
    $recommendedGigs = mergeSortByKey($recommendedGigs, 'match_score');

} catch (Exception $e) {}

// Gigs you already applied to, as a HASH SET: listing id => true (isset() check is O(1) per card)
$appliedSet = [];
if ($userId) {
    try {
        $appliedStmt = $pdo->prepare("SELECT listing_id FROM applications WHERE applicant_id = ?");
        $appliedStmt->execute([$userId]);
        foreach ($appliedStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $appliedSet[(int)$r['listing_id']] = true;
        }
    } catch (Exception $e) {}
}

// MY APPLICATIONS: every gig this member applied to, with what the employer decided
// status in the database: 'pending' = waiting, 'accepted' = shortlisted, 'rejected' = not selected
$myApplications = [];
$myAppCounts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
if ($userId) {
    try {
        $myAppStmt = $pdo->prepare("
            SELECT a.id AS application_id, a.status AS app_status, a.applied_at,
                   l.id, l.title, l.category, l.budget_min, l.budget_max, l.location, l.schedule, l.skills_required, l.status AS listing_status,
                   u.full_name AS employer_name, u.avatar AS employer_avatar
            FROM applications a
            JOIN listings l ON a.listing_id = l.id
            JOIN users u ON l.user_id = u.id
            WHERE a.applicant_id = ?
            ORDER BY a.applied_at DESC
        ");
        $myAppStmt->execute([$userId]);
        foreach ($myAppStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['app_status'] = in_array($row['app_status'], ['accepted', 'rejected'], true) ? $row['app_status'] : 'pending';
            $myAppCounts[$row['app_status']]++;
            // your match score for that gig (same formula as everywhere else)
            $row['match_score'] = calculateMatchScore($row['skills_required'] ?? '', $row['location'] ?? '', $row['schedule'] ?? '', $userSkillsStr, $userLocation, $userSlotsStr);
            $myApplications[] = $row;
        }
    } catch (Exception $e) {}
}

$activeTab = $_GET['tab'] ?? 'gigs';
if (!in_array($activeTab, ['gigs', 'applicants', 'applied'], true)) {
    $activeTab = 'gigs';
}
$actionMsg = $_GET['msg'] ?? '';

// ---------------- Profile picture helpers (display only) ----------------
// Two-letter initials, shown when a person has no picture
if (!function_exists('mtInitials')) {
    function mtInitials($name) {
        $initials = '';
        foreach (explode(' ', trim((string)$name)) as $w) {
            if ($w !== '') $initials .= strtoupper(mb_substr($w, 0, 1));
        }
        return mb_substr($initials, 0, 2) ?: 'U';
    }
}

// The person's CURRENT picture address, or '' if they have none.
// The ?v= stamp changes whenever the file changes, so a newly uploaded picture shows up right away (no old cached copy).
if (!function_exists('mtPicUrl')) {
    function mtPicUrl($path) {
        $path = (string)$path;
        if ($path === '' || !file_exists($path)) return '';
        return $path . '?v=' . filemtime($path);
    }
}

// ---------------- Display helpers for the Recommended cards (design only, no scoring here) ----------------
// Colour group + short label for a score (same 75 / 50 cut-offs as browse.php)
if (!function_exists('mtTier')) {
    function mtTier($score) {
        if ($score >= 75) return ['high', 'Great match'];
        if ($score >= 50) return ['mid', 'Good match'];
        return ['low', 'Partial match'];
    }
}
// 12.50 -> "12.5", 50.00 -> "50" (points look clean but stay exact)
if (!function_exists('mtPts')) {
    function mtPts($n) {
        $text = number_format((float)$n, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }
}
// Comma text -> clean list ("Design, , PHP" -> ["Design", "PHP"])
if (!function_exists('mtList')) {
    function mtList($text) {
        return array_values(array_filter(array_map('trim', explode(',', (string)$text)), 'strlen'));
    }
}

// Packs one applicant's details into a safe data attribute for the View button
// (the old way pasted the text straight into JavaScript, which broke on names, bios or skills with quotes or line breaks)
// NOTE: 'id' must stay the first field, the Accept/Reject check in the script looks for "id":N,
function applicantData($app, $jobTitle, $jobSchedule = '') {
    $d = [
        'id'       => (int)($app['id'] ?? 0),
        'name'     => (string)($app['applicant_name'] ?? ''),
        'email'    => (string)($app['email'] ?? ''),
        'phone'    => (string)($app['phone'] ?? ''),
        'location' => (string)($app['location'] ?? ''),
        'skills'   => (string)($app['skills'] ?? ''),
        'bio'      => (string)($app['bio'] ?? ''),
        'match'    => $app['match_percentage'] ?? 0,
        'job'      => (string)$jobTitle,
        'slots'    => (string)($app['available_slots'] ?? ''),   // availability (20% of the match score)
        'jobSlots' => (string)$jobSchedule,                       // the schedule the job asks for
        'pic'      => mtPicUrl($app['avatar'] ?? ''),             // live profile picture ('' = show initials)
        'initials' => mtInitials($app['applicant_name'] ?? ''),
    ];
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return htmlspecialchars($json === false ? '{}' : $json, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Matches & Applicant Queue</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-weight: normal; }
        body { background-color: #f4f6fb; color: #111; font-size: 1rem; }
        h1, h2, h3, h4 { font-weight: bold; }

        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; background: #fff; border-bottom: 1px solid #e2e5eb; }
        .logo img { height: 40px; }
        .nav-links-center { display: flex; gap: 20px; align-items: center; }
        .nav-link-item { color: #333; text-decoration: none; font-weight: normal; font-size: 1rem; padding: 6px 12px; border-radius: 8px; transition: background 0.2s, color 0.2s; }
        .nav-link-item:hover { background: #f0f4ff; color: #4154f1; }

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
        .dropdown-menu a { color: #333333; padding: 12px 18px; text-decoration: none; display: block; font-size: 0.95rem; }
        .dropdown-menu a:hover { background-color: #f0f4ff; color: #4154f1; }
        .dropdown-menu .dropdown-logout { color: #ff4d4d; border-top: 1px solid #eee; }
        .show-dropdown { display: block !important; }

        .nav-btn { display: block; padding: 12px 18px; color: #333; font-weight: 600; text-decoration: none; border-radius: 8px; font-size: 0.95rem; transition: 0.2s; }
        .nav-btn:hover { background: #f0f4ff; color: #4154f1; }
        .nav-btn.active { background: #4154f1; color: #fff; font-weight: 700; box-shadow: 0 4px 12px rgba(65,84,241,0.2); }

        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 2000; justify-content: center; align-items: center;
        }
        .modal-card {
            background: #fff; width: 100%; max-width: 550px; border-radius: 16px; padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15); position: relative;
            max-height: 92vh; overflow-y: auto;
        }

        /* ---- Added: no-reload updates ---- */
        #mt-content { transition: opacity .2s .12s; }
        #mt-content.mt-loading { opacity: 0.5; pointer-events: none; }
        .mt-toast {
            position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px); background: #111; color: #fff;
            padding: 13px 24px; border-radius: 999px; font-weight: bold; font-size: 0.9rem; text-align: center;
            max-width: calc(100vw - 32px); box-shadow: 0 10px 30px rgba(0,0,0,0.25); opacity: 0; pointer-events: none;
            transition: opacity .25s, transform .25s; z-index: 3000;
        }
        .mt-toast.show { opacity: 1; transform: translate(-50%, 0); }
        .mt-toast.ok { background: #1e8e3e; }
        .mt-toast.err { background: #d93025; }
        #modalAcceptBtn:disabled, #modalRejectBtn:disabled { opacity: 0.6; cursor: wait; }

        /* ---- Added: email that is ready after Shortlist & Email ---- */
        .mt-mail-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #eaeaea; }
        .mt-mail-ok { width: 36px; height: 36px; border-radius: 50%; background: #1e8e3e; color: #fff; font-weight: bold; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .mt-mail-head b { display: block; font-size: 1.1rem; font-weight: bold; color: #111; }
        .mt-mail-head span { font-size: 0.85rem; color: #666; }
        #mailStep label { display: block; font-size: 0.78rem; text-transform: uppercase; font-weight: bold; color: #888; margin: 12px 0 4px; letter-spacing: 0.3px; }
        #mailStep input, #mailStep textarea { width: 100%; padding: 10px 12px; border: 1px solid #dcdfe8; border-radius: 8px; font-size: 0.92rem; outline: none; background: #fff; }
        #mailStep input:focus, #mailStep textarea:focus { border-color: #4154f1; }
        #mailStep textarea { resize: vertical; line-height: 1.5; }
        .mt-mail-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 18px; padding-top: 15px; border-top: 1px solid #eaeaea; }
        .mt-btn { padding: 9px 18px; border-radius: 8px; font-weight: bold; font-size: 0.88rem; cursor: pointer; border: 1px solid transparent; }
        .mt-btn-blue { background: #4154f1; color: #fff; box-shadow: 0 2px 6px rgba(65,84,241,0.2); }
        .mt-btn-line { background: #fff; color: #4154f1; border-color: #c9d0fb; }
        .mt-skip { margin-left: auto; background: none; border: none; color: #666; font-weight: bold; font-size: 0.88rem; cursor: pointer; }

        /* ---- Added: same page size as My Listings (1200px wide, 32px sides, slim title, same navbar height) ---- */
        .navbar { padding: 12px 40px; }
        .nav-links-center { gap: 4px; }
        .nav-link-item { font-size: 0.93rem; font-weight: 600; padding: 8px 16px; border-radius: 999px; }
        .mt-main { flex: 1; display: flex; flex-direction: column; }
        .mt-page { width: 100%; max-width: 1200px; margin: 0 auto; padding: 22px 32px 72px; flex: 1; }
        .mt-gigs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }

        /* ---- Added: live profile pictures (photo when the person has one, initials when not) ---- */
        .mt-pfp { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: #4154f1; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; overflow: hidden; }
        .mt-pfp-40 { width: 40px; height: 40px; font-size: 0.9rem; }
        .mt-pfp-48 { width: 48px; height: 48px; font-size: 1rem; }
        .mt-by { display: flex; align-items: center; gap: 9px; min-width: 0; font-size: 0.8rem; color: #888; }
        .mt-by span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mt-slot { background: #eef9f0; color: #1e8e3e; padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; }

        .logo img { display: block; }                    /* no extra gap under the logo, so the bar is exactly as tall as on My Listings */
        .mt-layout > * { min-width: 0; }                 /* lets the list shrink on small screens instead of pushing the page wider */
        .mt-gigs > * { min-width: 0; }
        #mt-content strong, #mt-content h3 { overflow-wrap: anywhere; }   /* long names / titles wrap instead of overflowing */

        /* ======================================================================
           MATCHES REDESIGN: blue hero, pill tabs, top-match spotlight, ranked cards
           Colours of the three score parts are used everywhere on the page:
           Skills = blue, Location = green, Availability = amber
           ====================================================================== */
        :root { --blue: #4154f1; --deep: #2938b7; --soft: #eef1ff; --ink: #161b3a; --muted: #667085; --line: #e4e8f7; --yellow: #ffe600;
                --c-s: #4154f1; --c-l: #1e8e3e; --c-a: #f29900; --good: #1e8e3e; --good-bg: #e6f9ed; }
        body, body * { font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: 1px; flex-shrink: 0; }
        .dot.s { background: var(--c-s); } .dot.l { background: var(--c-l); } .dot.a { background: var(--c-a); }

        /* 1. Hero */
        .mt-hero { background: var(--blue); color: #fff; position: relative; overflow: hidden; }
        .mt-hero::before { content: ""; position: absolute; width: 420px; height: 420px; border-radius: 50%; right: -120px; top: -210px; background: rgba(255,255,255,0.07); }
        .mt-hero::after { content: ""; position: absolute; width: 260px; height: 260px; border-radius: 50%; left: 38%; bottom: -190px; background: rgba(255,255,255,0.05); }
        .mt-hero-in { position: relative; z-index: 1; max-width: 1200px; margin: 0 auto; padding: 22px 32px 26px; display: flex; justify-content: space-between; align-items: center; gap: 28px; }
        .mt-hero-copy { min-width: 0; }
        .mt-crumbs { font-size: 0.82rem; color: #dfe3ff; margin-bottom: 10px; }
        .mt-crumbs a { color: #dfe3ff; text-decoration: none; }
        .mt-crumbs a:hover { color: #fff; text-decoration: underline; }
        .mt-crumbs strong { color: #fff; font-weight: 700; }
        .mt-eyebrow { display: inline-block; background: var(--yellow); color: var(--ink); font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 11px; border-radius: 999px; margin-bottom: 10px; }
        .mt-title { font-size: 2rem; line-height: 1.15; letter-spacing: -0.025em; font-weight: 800; color: #fff; }
        .mt-lead { color: #eef1ff; font-size: 0.95rem; max-width: 560px; margin-top: 6px; }
        .mt-stats { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .mt-stat { display: flex; align-items: baseline; gap: 8px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); padding: 8px 14px; border-radius: 12px; }
        .mt-stat b { font-size: 1.25rem; font-weight: 800; color: #fff; }
        .mt-stat span { font-size: 0.8rem; color: #e6e9ff; font-weight: 600; }

        .mt-me { width: 340px; flex-shrink: 0; background: #fff; color: var(--ink); border-radius: 18px; padding: 16px 18px; box-shadow: 0 18px 40px rgba(10,16,70,0.28); }
        .mt-me-head { display: flex; align-items: center; gap: 11px; padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid var(--line); }
        .mt-me-label { display: block; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--blue); }
        .mt-me-name { display: block; font-size: 0.98rem; font-weight: 800; overflow-wrap: anywhere; }
        .mt-me-row { display: flex; align-items: flex-start; gap: 10px; padding: 7px 0; }
        .mt-me-k { width: 72px; flex-shrink: 0; font-size: 0.78rem; font-weight: 700; color: var(--muted); display: flex; align-items: center; padding-top: 2px; }
        .mt-me-v { display: flex; flex-wrap: wrap; gap: 5px; font-size: 0.85rem; font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
        .mt-me-v em { font-style: normal; color: #b0b6c6; font-weight: 600; }
        .mt-me-chip { background: var(--soft); color: var(--blue); padding: 2px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 700; }
        .mt-me-chip.more { background: #f1f2f6; color: var(--muted); }
        .mt-me-warn { margin-top: 6px; background: #fff5da; color: #8a5a00; font-size: 0.78rem; font-weight: 700; padding: 7px 10px; border-radius: 10px; }
        .mt-me-link { display: inline-flex; gap: 6px; margin-top: 8px; font-size: 0.82rem; font-weight: 800; color: var(--blue); text-decoration: none; }
        .mt-me-link:hover { text-decoration: underline; }

        /* 2. Tabs + legend */
        .mt-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px 20px; margin-bottom: 18px; }
        .mt-tabs { display: inline-flex; gap: 4px; background: #fff; border-radius: 999px; padding: 4px; box-shadow: inset 0 0 0 1.5px #cdd4fc; }
        .mt-tab { display: inline-flex; align-items: center; gap: 8px; padding: 9px 20px; border-radius: 999px; font-weight: 700; font-size: 0.9rem; color: var(--blue); text-decoration: none; white-space: nowrap; transition: background .2s, color .2s; }
        .mt-tab:hover { background: var(--soft); }
        .mt-tab span { background: var(--soft); color: var(--blue); min-width: 22px; text-align: center; padding: 1px 7px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
        .mt-tab.active { background: var(--blue); color: #fff; }
        .mt-tab.active span { background: rgba(255,255,255,0.22); color: #fff; }
        .mt-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; font-size: 0.8rem; font-weight: 700; color: var(--ink); }
        .mt-legend-k { color: var(--muted); font-weight: 600; }

        /* score rings (conic-gradient: --p = percent filled) */
        .mt-ring { --p: 0; --c: var(--blue); --track: #e6e9f8; --hole: #fff; position: relative; display: grid; place-items: center; border-radius: 50%; flex-shrink: 0;
                   background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--track) 0); }
        .mt-ring::before { content: ""; position: absolute; inset: var(--w, 7px); border-radius: 50%; background: var(--hole); }
        .mt-ring b { position: relative; font-weight: 800; color: var(--ink); }
        .mt-ring-sm { width: 66px; height: 66px; --w: 6px; }
        .mt-ring-sm b { font-size: 0.74rem; letter-spacing: -0.03em; }
        .mt-ring-sm.high { --c: var(--good); } .mt-ring-sm.mid { --c: #e8a100; } .mt-ring-sm.low { --c: #a3a9bb; }
        .mt-ring-lg { width: 128px; height: 128px; --w: 12px; --c: var(--yellow); --track: rgba(255,255,255,0.22); --hole: var(--deep); }
        .mt-ring-lg b { font-size: 1.45rem; color: #fff; letter-spacing: -0.02em; }

        /* small shared bits */
        .mt-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
        .mt-chip { background: var(--soft); color: var(--blue); padding: 3px 11px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .weak { background: #fff5da; color: #8a5a00; padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; white-space: nowrap; }
        .mt-applied { background: var(--good-bg); color: var(--good); padding: 3px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; }
        .mt-desc { color: #4b5263; font-size: 0.9rem; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; }
        .mt-facts { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; font-size: 0.85rem; color: var(--muted); font-weight: 600; }
        .mt-facts > span + span::before { content: "\2022"; margin: 0 9px; color: #c3c8d8; }
        .mt-price { color: var(--blue); font-weight: 800; font-size: 0.98rem; }
        .mt-skills { display: flex; flex-wrap: wrap; gap: 6px; }
        .mt-sk { padding: 3px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .mt-sk.ok { background: var(--good-bg); color: var(--good); }
        .mt-sk.no { background: #f1f2f6; color: #8d94a8; }
        .mt-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; border-top: 1px solid var(--line); padding-top: 14px; margin-top: auto; }
        .mt-cta { display: inline-flex; align-items: center; gap: 8px; background: var(--blue); color: #fff; padding: 11px 20px; border-radius: 999px; font-weight: 800; font-size: 0.88rem; text-decoration: none; white-space: nowrap; transition: background .2s, transform .2s; }
        .mt-cta:hover { background: var(--deep); transform: translateY(-1px); }
        .mt-cta-sm { padding: 9px 16px; font-size: 0.84rem; }

        /* 3. Top match spotlight */
        .mt-top { display: grid; grid-template-columns: 220px 1fr; background: #fff; border: 1.5px solid #cdd4fc; border-radius: 20px; overflow: hidden; margin-bottom: 26px; box-shadow: 0 14px 34px rgba(65,84,241,0.10); }
        .mt-top-score { background: linear-gradient(160deg, var(--blue), var(--deep)); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px 16px; text-align: center; }
        .mt-top-rank { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #dfe3ff; }
        .mt-top-tier { background: rgba(255,255,255,0.16); padding: 4px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 800; }
        .mt-top-body { padding: 22px 26px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
        .mt-ribbon { background: var(--yellow); color: var(--ink); padding: 3px 11px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.02em; }
        .mt-top-title { font-size: 1.4rem; font-weight: 800; letter-spacing: -0.015em; line-height: 1.25; color: var(--ink); }
        .mt-why { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; background: #f6f8ff; border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; }
        .mt-why-top { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px; }
        .mt-why-top span { display: inline-flex; align-items: center; }
        .mt-why-top b { font-weight: 800; color: var(--ink); font-size: 0.9rem; white-space: nowrap; }
        .mt-why-top small { color: var(--muted); font-weight: 600; font-size: 0.75rem; }
        .mt-meter { height: 7px; border-radius: 999px; background: #e3e7f6; overflow: hidden; }
        .mt-meter i { display: block; height: 100%; border-radius: 999px; }
        .mt-meter.s i { background: var(--c-s); } .mt-meter.l i { background: var(--c-l); } .mt-meter.a i { background: var(--c-a); }

        /* 4. Ranked cards */
        .mt-sec-head { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px 14px; margin-bottom: 14px; }
        .mt-sec-head h2 { font-size: 1.15rem; font-weight: 800; color: var(--ink); }
        .mt-sec-head span { font-size: 0.84rem; color: var(--muted); font-weight: 600; }
        .mt-card { background: #fff; border: 1.5px solid #dfe4fb; border-radius: 18px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px; transition: border-color .2s, box-shadow .2s, transform .2s; }
        .mt-card:hover { border-color: var(--blue); box-shadow: 0 12px 28px rgba(65,84,241,0.12); transform: translateY(-2px); }
        .mt-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .mt-rank { background: var(--ink); color: #fff; padding: 3px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
        .mt-card-title { font-size: 1.08rem; font-weight: 800; line-height: 1.3; color: var(--ink); }
        .mt-split { display: flex; height: 8px; border-radius: 999px; background: #e3e7f6; overflow: hidden; gap: 0; }
        .mt-split i { display: block; height: 100%; }
        .mt-split i.s { background: var(--c-s); } .mt-split i.l { background: var(--c-l); } .mt-split i.a { background: var(--c-a); }
        .mt-split-keys { display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 0.74rem; color: var(--muted); font-weight: 700; margin-top: -4px; }
        .mt-split-keys span { display: inline-flex; align-items: center; }

        /* My Applications */
        .ma-summary { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .ma-sum { padding: 6px 14px; border-radius: 999px; font-size: 0.84rem; font-weight: 700; background: #fff; border: 1px solid var(--line); }
        .ma-sum b { font-weight: 800; margin-right: 3px; }
        .ma-sum.wait b { color: #a86b00; } .ma-sum.ok b { color: var(--good); } .ma-sum.no b { color: #7a8196; }
        .ma-list { display: flex; flex-direction: column; gap: 12px; }
        .ma-card { display: flex; align-items: center; gap: 18px; background: #fff; border: 1.5px solid #dfe4fb; border-radius: 18px; padding: 16px 20px; box-shadow: inset 4px 0 0 var(--ma, #e8a100); }
        .ma-card.ok { --ma: var(--good); } .ma-card.no { --ma: #c3c8d8; }
        .ma-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
        .ma-status { padding: 3px 11px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; }
        .ma-status.wait { background: #fff5da; color: #8a5a00; }
        .ma-status.ok { background: var(--good-bg); color: var(--good); }
        .ma-status.no { background: #f1f2f6; color: #6b7287; }
        .ma-closed { padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 700; background: #f1f2f6; color: #6b7287; }
        .ma-note { font-size: 0.82rem; color: var(--muted); }
        .ma-side { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; flex-shrink: 0; }
        .mt-empty { background: #fff; border: 1.5px dashed #b9c1fb; border-radius: 18px; padding: 50px 28px; text-align: center; color: var(--muted); }
        .mt-empty h3 { font-size: 1.2rem; font-weight: 800; color: var(--ink); margin-bottom: 6px; }
        .mt-empty .mt-cta { margin-top: 16px; }

        @media (max-width: 900px) {
            .mt-gigs { grid-template-columns: minmax(0, 1fr); }
            .mt-hero-in { flex-direction: column; align-items: stretch; }
            .mt-me { width: 100%; }
        }
        @media (max-width: 760px) {
            .navbar { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .nav-links-center { order: 3; width: 100%; overflow-x: auto; justify-content: flex-start; padding-bottom: 4px; }
            .nav-link-item { white-space: nowrap; }
        }
        @media (max-width: 640px) {
            .mt-page { padding: 18px 16px 56px; }
            .mt-hero-in { padding: 18px 16px 20px; }
            .mt-title { font-size: 1.55rem; }
            .mt-tabs { width: 100%; }
            .mt-tab { flex: 1; justify-content: center; padding: 9px 8px; font-size: 0.84rem; }
            .mt-top { grid-template-columns: minmax(0, 1fr); }
            .mt-top-score { flex-direction: row; justify-content: flex-start; padding: 16px 18px; gap: 14px; text-align: left; }
            .mt-top-score .mt-top-rank { order: 2; }
            .mt-ring-lg { width: 84px; height: 84px; --w: 9px; }
            .mt-ring-lg b { font-size: 1rem; }
            .mt-top-body { padding: 18px; }
            .mt-top-title { font-size: 1.2rem; }
            .mt-why { grid-template-columns: minmax(0, 1fr); gap: 10px; }
            .mt-foot { flex-wrap: wrap; }
            .mt-foot .mt-cta { flex: 1; justify-content: center; }
            .ma-card { flex-wrap: wrap; align-items: flex-start; }
            .ma-main { flex-basis: calc(100% - 90px); }
            .ma-side { flex-basis: 100%; flex-direction: row; justify-content: space-between; align-items: center; }
        }
        @media (max-width: 400px) {
            .mt-tab { min-width: 0; white-space: normal; text-align: center; line-height: 1.2; font-size: 0.8rem; padding: 8px 6px; gap: 5px; }
            .mt-tab span { padding: 1px 5px; min-width: 18px; }
        }
    </style>
</head>
<body style="background-color: #f4f6fb; display: flex; flex-direction: column; min-height: 100vh;" data-me="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>" data-csrf="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <header class="navbar">
        <div class="logo">
            <a href="dashboard.php"><img src="Logo.jpg" alt="JobiT Logo" class="brand-logo" style="height: 40px;"></a>
        </div>

        <nav class="nav-links-center">
            <a href="dashboard.php" class="nav-link-item">Home</a>
            <a href="browse.php" class="nav-link-item">Browse</a>
            <a href="my-listings.php" class="nav-link-item">My Listings</a>
            <a href="matches.php" class="nav-link-item" style="color: #4154f1; font-weight: bold; background: #f0f4ff;">Matches</a>
            <a href="profile.php" class="nav-link-item">Profile</a>
            <a href="post-listing.php" class="nav-link-item" style="background: #4154f1; color: #fff; font-weight: bold;">+ Post a Listing</a>
        </nav>

        <div class="nav-right" style="display: flex; align-items: center; gap: 20px;">
            <div class="profile-dropdown-container">
                <button onclick="toggleDropdown()" class="profile-avatar-btn" id="avatarBtn">
                    <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                        <img src="<?php echo htmlspecialchars($userAvatar); ?>" style="width:100%; height:100%; object-fit:cover;">
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

    <main class="mt-main">

        <!-- 1. BLUE HERO: tells the user at first sight what this page is -->
        <section class="mt-hero">
            <div class="mt-hero-in">
                <div class="mt-hero-copy">
                    <div class="mt-crumbs"><a href="dashboard.php">Home</a> &rsaquo; <strong>Matches</strong></div>
                    <span class="mt-eyebrow">&#9733; Matched for you</span>
                    <h1 class="mt-title">Your Best Matches</h1>
                    <p class="mt-lead">JobIT scores every open gig against your skills, city and schedule, then ranks them best match first.</p>
                    <div id="mt-stats" class="mt-stats">
                        <div class="mt-stat"><b><?php echo count($recommendedGigs); ?></b><span>gigs ranked for you</span></div>
                        <div class="mt-stat"><b><?php echo $totalApplicantsCount; ?></b><span>applicants on your jobs</span></div>
                        <div class="mt-stat"><b><?php echo count($myApplications); ?></b><span>applications you sent</span></div>
                    </div>
                </div>

                <?php
                    // What the score is calculated from: the logged-in user's own profile
                    $mySkills = mtList($userSkillsStr);
                    $mySlots  = mtList($userSlotsStr);
                    $myPic    = mtPicUrl($userAvatar);
                    $missingParts = [];
                    if (empty($mySkills)) $missingParts[] = 'skills';
                    if (trim($userLocation) === '') $missingParts[] = 'city';
                    if (empty($mySlots)) $missingParts[] = 'schedule';
                ?>
                <aside class="mt-me" aria-label="Your match profile">
                    <div class="mt-me-head">
                        <?php if ($myPic !== ''): ?>
                            <img class="mt-pfp mt-pfp-40" src="<?php echo htmlspecialchars($myPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php else: ?>
                            <span class="mt-pfp mt-pfp-40"><?php echo htmlspecialchars($userInitials); ?></span>
                        <?php endif; ?>
                        <div>
                            <span class="mt-me-label">Your match profile</span>
                            <b class="mt-me-name"><?php echo htmlspecialchars($userName); ?></b>
                        </div>
                    </div>
                    <div class="mt-me-row">
                        <span class="mt-me-k"><i class="dot s"></i>Skills</span>
                        <div class="mt-me-v">
                            <?php if (empty($mySkills)): ?><em>Not set</em><?php else: ?>
                                <?php foreach (array_slice($mySkills, 0, 5) as $sk): ?><span class="mt-me-chip"><?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                <?php if (count($mySkills) > 5): ?><span class="mt-me-chip more">+<?php echo count($mySkills) - 5; ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-me-row">
                        <span class="mt-me-k"><i class="dot l"></i>City</span>
                        <div class="mt-me-v"><?php echo trim($userLocation) === '' ? '<em>Not set</em>' : htmlspecialchars($userLocation); ?></div>
                    </div>
                    <div class="mt-me-row">
                        <span class="mt-me-k"><i class="dot a"></i>Free on</span>
                        <div class="mt-me-v">
                            <?php if (empty($mySlots)): ?><em>Not set</em><?php else: ?>
                                <?php foreach ($mySlots as $sl): ?><span class="mt-me-chip"><?php echo htmlspecialchars($sl); ?></span><?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($missingParts)): ?>
                        <p class="mt-me-warn">Add your <?php echo htmlspecialchars(implode(', ', $missingParts)); ?> to get better matches.</p>
                    <?php endif; ?>
                    <a href="profile.php" class="mt-me-link">Update profile <span aria-hidden="true">&rarr;</span></a>
                </aside>
            </div>
        </section>

        <div class="mt-page">

        <div id="mt-flash">
        <?php if ($actionMsg === 'accept'): ?>
            <div class="mt-flash" style="background: #eef9f0; color: #1e8e3e; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #c8e6c9;">
                Applicant shortlisted and removed from the queue!
            </div>
        <?php elseif ($actionMsg === 'reject'): ?>
            <div class="mt-flash" style="background: #fdf2f2; color: #d93025; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #fadbd8;">
                Applicant moved to Rejected and removed from the queue.
            </div>
        <?php elseif ($actionMsg === 'expired'): ?>
            <div class="mt-flash" style="background: #fdf2f2; color: #d93025; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #fadbd8;">
                Error: this page was open too long. Please try again.
            </div>
        <?php endif; ?>
        </div>

        <!-- 2. TABS + how the score works (same colours as the bars on the cards) -->
        <div class="mt-bar">
            <nav id="mt-nav" class="mt-tabs" aria-label="Matches">
                <a href="matches.php?tab=gigs" data-ajax class="mt-tab <?php echo $activeTab === 'gigs' ? 'active' : ''; ?>">Recommended for You <span><?php echo count($recommendedGigs); ?></span></a>
                <a href="matches.php?tab=applicants" data-ajax class="mt-tab <?php echo $activeTab === 'applicants' ? 'active' : ''; ?>">Applicant Queue <span><?php echo $totalApplicantsCount; ?></span></a>
                <a href="matches.php?tab=applied" data-ajax class="mt-tab <?php echo $activeTab === 'applied' ? 'active' : ''; ?>">My Applications <span><?php echo count($myApplications); ?></span></a>
            </nav>
            <div class="mt-legend" aria-label="How the match score works">
                <span class="mt-legend-k">How we score</span>
                <span><i class="dot s"></i>Skills 50</span>
                <span><i class="dot l"></i>Location 30</span>
                <span><i class="dot a"></i>Availability 20</span>
            </div>
        </div>

            <div id="mt-content" data-tab="<?php echo htmlspecialchars($activeTab); ?>">
                <?php if ($activeTab === 'gigs'): ?>
                    <?php if (empty($recommendedGigs)): ?>
                        <div class="mt-empty">
                            <h3>No recommendations yet</h3>
                            <p>There are no open gigs from other people right now. Check back soon, or make sure your profile is complete.</p>
                            <a href="browse.php" class="mt-cta">Browse all gigs</a>
                        </div>
                    <?php else: ?>
                        <?php
                            $topGig = $recommendedGigs[0];                 // the list is already sorted by our merge sort
                            $moreGigs = array_slice($recommendedGigs, 1);
                            $totalGigs = count($recommendedGigs);
                            $parts = [
                                ['s', 'Skills', 'skills_points', 50],
                                ['l', 'Location', 'location_points', 30],
                                ['a', 'Availability', 'availability_points', 20],
                            ];
                        ?>

                        <!-- 3. TOP MATCH SPOTLIGHT (#1) -->
                        <?php
                            $g = $topGig;
                            $tier = mtTier($g['match_score']);
                            $applied = isset($appliedSet[(int)$g['id']]);
                            $posterPic = mtPicUrl($g['employer_avatar'] ?? '');
                            $schedule = mtList($g['schedule'] ?? '');
                        ?>
                        <article class="mt-top">
                            <div class="mt-top-score">
                                <span class="mt-top-rank">#1 of <?php echo $totalGigs; ?></span>
                                <div class="mt-ring mt-ring-lg" style="--p: <?php echo round($g['match_score']); ?>;">
                                    <b><?php echo $g['match_score']; ?>%</b>
                                </div>
                                <span class="mt-top-tier"><?php echo $tier[1]; ?></span>
                            </div>

                            <div class="mt-top-body">
                                <div class="mt-tags">
                                    <span class="mt-ribbon">&#9733; Top match for you</span>
                                    <span class="mt-chip"><?php echo htmlspecialchars($g['category']); ?></span>
                                    <?php if ($applied): ?><span class="mt-applied">&#10003; Applied</span><?php endif; ?>
                                    <?php if (isWeakSkillMatch($g['why'])): ?><span class="weak" title="You have <?php echo $g['why']['skills_have']; ?> of <?php echo $g['why']['skills_total']; ?> required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span><?php endif; ?>
                                </div>
                                <h3 class="mt-top-title"><?php echo htmlspecialchars($g['title']); ?></h3>
                                <p class="mt-desc"><?php echo htmlspecialchars($g['description']); ?></p>

                                <div class="mt-facts">
                                    <span class="mt-price">&#8369;<?php echo number_format($g['budget_min']); ?> - &#8369;<?php echo number_format($g['budget_max']); ?></span>
                                    <span><?php echo htmlspecialchars($g['location']); ?></span>
                                    <?php if (!empty($schedule)): ?><span><?php echo htmlspecialchars(implode(', ', $schedule)); ?></span><?php endif; ?>
                                </div>

                                <div class="mt-why">
                                    <?php foreach ($parts as $p): $pts = $g['why'][$p[2]]; ?>
                                        <div class="mt-why-item">
                                            <div class="mt-why-top"><span><i class="dot <?php echo $p[0]; ?>"></i><?php echo $p[1]; ?></span><b><?php echo mtPts($pts); ?><small> / <?php echo $p[3]; ?></small></b></div>
                                            <div class="mt-meter <?php echo $p[0]; ?>"><i style="width: <?php echo round($pts / $p[3] * 100, 1); ?>%;"></i></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php $chips = $g['skill_chips']; ?>
                                <?php if (!empty($chips['matched']) || !empty($chips['missing'])): ?>
                                    <div class="mt-skills">
                                        <?php foreach ($chips['matched'] as $sk): ?><span class="mt-sk ok">&#10003; <?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                        <?php foreach ($chips['missing'] as $sk): ?><span class="mt-sk no"><?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-foot">
                                    <span class="mt-by">
                                        <?php if ($posterPic !== ''): ?>
                                            <img class="mt-pfp" src="<?php echo htmlspecialchars($posterPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                        <?php else: ?>
                                            <span class="mt-pfp"><?php echo htmlspecialchars(mtInitials($g['employer_name'])); ?></span>
                                        <?php endif; ?>
                                        <span>Posted by <?php echo htmlspecialchars($g['employer_name']); ?></span>
                                    </span>
                                    <a href="listing-details.php?id=<?php echo $g['id']; ?>&from=matches" class="mt-cta"><?php echo $applied ? 'View Details' : 'View &amp; Apply'; ?> <span aria-hidden="true">&rarr;</span></a>
                                </div>
                            </div>
                        </article>

                        <!-- 4. THE REST, ranked best match first -->
                        <?php if (!empty($moreGigs)): ?>
                            <div class="mt-sec-head">
                                <h2>More matches for you</h2>
                                <span><?php echo count($moreGigs); ?> more, ranked best match first</span>
                            </div>
                            <div class="mt-gigs">
                                <?php foreach ($moreGigs as $i => $g):
                                    $tier = mtTier($g['match_score']);
                                    $applied = isset($appliedSet[(int)$g['id']]);
                                    $posterPic = mtPicUrl($g['employer_avatar'] ?? '');
                                    $chips = $g['skill_chips'];
                                ?>
                                    <article class="mt-card">
                                        <div class="mt-card-top">
                                            <div class="mt-tags">
                                                <span class="mt-rank">#<?php echo $i + 2; ?></span>
                                                <span class="mt-chip"><?php echo htmlspecialchars($g['category']); ?></span>
                                                <?php if ($applied): ?><span class="mt-applied">&#10003; Applied</span><?php endif; ?>
                                                <?php if (isWeakSkillMatch($g['why'])): ?><span class="weak" title="You have <?php echo $g['why']['skills_have']; ?> of <?php echo $g['why']['skills_total']; ?> required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span><?php endif; ?>
                                            </div>
                                            <div class="mt-ring mt-ring-sm <?php echo $tier[0]; ?>" style="--p: <?php echo round($g['match_score']); ?>;" title="<?php echo $tier[1]; ?>">
                                                <b><?php echo $g['match_score']; ?>%</b>
                                            </div>
                                        </div>

                                        <h3 class="mt-card-title"><?php echo htmlspecialchars($g['title']); ?></h3>
                                        <p class="mt-desc"><?php echo htmlspecialchars($g['description']); ?></p>
                                        <div class="mt-facts">
                                            <span class="mt-price">&#8369;<?php echo number_format($g['budget_min']); ?> - &#8369;<?php echo number_format($g['budget_max']); ?></span>
                                            <span><?php echo htmlspecialchars($g['location']); ?></span>
                                        </div>

                                        <!-- one bar = the whole score, split into its three coloured parts -->
                                        <div class="mt-split" role="img" aria-label="Skills <?php echo mtPts($g['why']['skills_points']); ?>, Location <?php echo mtPts($g['why']['location_points']); ?>, Availability <?php echo mtPts($g['why']['availability_points']); ?> out of 100">
                                            <?php foreach ($parts as $p): ?><i class="<?php echo $p[0]; ?>" style="width: <?php echo $g['why'][$p[2]]; ?>%;"></i><?php endforeach; ?>
                                        </div>
                                        <div class="mt-split-keys">
                                            <?php foreach ($parts as $p): ?><span><i class="dot <?php echo $p[0]; ?>"></i><?php echo $p[1]; ?> <?php echo mtPts($g['why'][$p[2]]); ?>/<?php echo $p[3]; ?></span><?php endforeach; ?>
                                        </div>

                                        <?php if (!empty($chips['matched'])): ?>
                                            <div class="mt-skills">
                                                <?php foreach (array_slice($chips['matched'], 0, 3) as $sk): ?><span class="mt-sk ok">&#10003; <?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                                <?php if (count($chips['matched']) > 3): ?><span class="mt-sk ok">+<?php echo count($chips['matched']) - 3; ?></span><?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-foot">
                                            <span class="mt-by">
                                                <?php if ($posterPic !== ''): ?>
                                                    <img class="mt-pfp" src="<?php echo htmlspecialchars($posterPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                                <?php else: ?>
                                                    <span class="mt-pfp"><?php echo htmlspecialchars(mtInitials($g['employer_name'])); ?></span>
                                                <?php endif; ?>
                                                <span>By <?php echo htmlspecialchars($g['employer_name']); ?></span>
                                            </span>
                                            <a href="listing-details.php?id=<?php echo $g['id']; ?>&from=matches" class="mt-cta mt-cta-sm">View Details</a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php elseif ($activeTab === 'applied'): ?>
                    <!-- MY APPLICATIONS: what happened to each gig you applied to -->
                    <?php
                        $statusInfo = [
                            'pending'  => ['wait', 'Waiting for review', 'The employer has not decided yet.'],
                            'accepted' => ['ok',   '&#10003; Shortlisted', 'The employer picked you. Watch your email.'],
                            'rejected' => ['no',   'Not selected', 'The employer chose other applicants this time.'],
                        ];
                    ?>
                    <div class="ma-summary">
                        <span class="ma-sum wait"><b><?php echo $myAppCounts['pending']; ?></b> waiting</span>
                        <span class="ma-sum ok"><b><?php echo $myAppCounts['accepted']; ?></b> shortlisted</span>
                        <span class="ma-sum no"><b><?php echo $myAppCounts['rejected']; ?></b> not selected</span>
                    </div>
                    <?php if (empty($myApplications)): ?>
                        <div class="mt-empty">
                            <h3>No applications yet</h3>
                            <p>When you apply to a gig, you can follow it here: waiting, shortlisted or not selected.</p>
                            <a href="matches.php?tab=gigs" class="mt-cta" data-ajax>See gigs picked for you</a>
                        </div>
                    <?php else: ?>
                        <div class="ma-list">
                            <?php foreach ($myApplications as $ap):
                                $info = $statusInfo[$ap['app_status']];
                                $tier = mtTier($ap['match_score']);
                                $posterPic = mtPicUrl($ap['employer_avatar'] ?? '');
                            ?>
                                <article class="ma-card <?php echo $info[0]; ?>">
                                    <div class="mt-ring mt-ring-sm <?php echo $tier[0]; ?>" style="--p: <?php echo round($ap['match_score']); ?>;" title="Your match score"><b><?php echo $ap['match_score']; ?>%</b></div>
                                    <div class="ma-main">
                                        <div class="mt-tags">
                                            <span class="ma-status <?php echo $info[0]; ?>"><?php echo $info[1]; ?></span>
                                            <span class="mt-chip"><?php echo htmlspecialchars($ap['category']); ?></span>
                                            <?php if ($ap['listing_status'] === 'completed'): ?><span class="ma-closed">Job closed</span><?php endif; ?>
                                        </div>
                                        <h3 class="mt-card-title"><?php echo htmlspecialchars($ap['title']); ?></h3>
                                        <div class="mt-facts">
                                            <span class="mt-price">&#8369;<?php echo number_format($ap['budget_min']); ?> - &#8369;<?php echo number_format($ap['budget_max']); ?></span>
                                            <span><?php echo htmlspecialchars($ap['location']); ?></span>
                                            <span>Applied <?php echo date('M d, Y', strtotime($ap['applied_at'])); ?></span>
                                        </div>
                                        <p class="ma-note"><?php echo $info[2]; ?></p>
                                    </div>
                                    <div class="ma-side">
                                        <span class="mt-by">
                                            <?php if ($posterPic !== ''): ?>
                                                <img class="mt-pfp" src="<?php echo htmlspecialchars($posterPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                            <?php else: ?>
                                                <span class="mt-pfp"><?php echo htmlspecialchars(mtInitials($ap['employer_name'])); ?></span>
                                            <?php endif; ?>
                                            <span>By <?php echo htmlspecialchars($ap['employer_name']); ?></span>
                                        </span>
                                        <a href="listing-details.php?id=<?php echo (int)$ap['id']; ?>&from=matches" class="mt-cta mt-cta-sm">View Listing</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="background: #fff; padding: 25px 30px; border-radius: 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                        <h2 style="font-size: 1.3rem; font-weight: bold; color: #111; margin-bottom: 6px;">Applicant Queue by Your Created Jobs</h2>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 25px;">Candidates who applied to your active posted job listings, rated and categorized by role.</p>

                        <?php if (empty($userListings)): ?>
                            <div style="text-align: center; padding: 40px; color: #888;">
                                <p style="font-size: 0.95rem; margin-bottom: 10px;">You haven't posted any active jobs or gigs yet.</p>
                                <a href="post-listing.php" style="background: #4154f1; color: #fff; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.85rem;">Post a New Listing</a>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 25px;">
                                <?php foreach ($userListings as $listing): 
                                    $lId = $listing['id'];
                                    $jobData = $applicantQueueByJob[$lId] ?? ['title' => $listing['title'], 'category' => $listing['category'], 'applicants' => []];
                                    $applicantsList = $jobData['applicants'];
                                ?>
                                    <div style="border: 1px solid #e1e7fc; border-radius: 12px; padding: 20px; background: #fafbfc;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid #eaeaea; padding-bottom: 10px;">
                                            <div>
                                                <span style="background: #eef2ff; color: #4154f1; padding: 3px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: bold;"><?php echo htmlspecialchars($jobData['category']); ?></span>
                                                <h3 style="font-size: 1.05rem; font-weight: bold; color: #111; margin-top: 4px;"><?php echo htmlspecialchars($jobData['title']); ?></h3>
                                            </div>
                                            <span style="background: #eef9f0; color: #1e8e3e; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; flex-shrink: 0; margin-left: 12px;"><?php echo count($applicantsList); ?> Pending Applicant(s)</span>
                                        </div>

                                        <?php if (empty($applicantsList)): ?>
                                            <p style="color: #888; font-size: 0.85rem; font-style: italic; padding: 8px 0;">No pending applicants in the queue for this job.</p>
                                        <?php else: ?>
                                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                                <?php foreach ($applicantsList as $idx => $app): ?>
                                                    <div style="background: #fff; padding: 15px 20px; border-radius: 10px; border: 1px solid #eaeaea; display: flex; justify-content: space-between; align-items: center;">
                                                        <div style="display: flex; align-items: center; gap: 14px;">
                                                            <?php $applicantPic = mtPicUrl($app['avatar'] ?? ''); ?>
                                                            <?php if ($applicantPic !== ''): ?>
                                                                <img class="mt-pfp mt-pfp-40" src="<?php echo htmlspecialchars($applicantPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                                            <?php else: ?>
                                                                <div class="mt-pfp mt-pfp-40"><?php echo htmlspecialchars(mtInitials($app['applicant_name'])); ?></div>
                                                            <?php endif; ?>
                                                            <div>
                                                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2px;">
                                                                    <strong style="font-size: 0.95rem; color: #111; font-weight: bold;">#<?php echo ($idx + 1); ?> <?php echo htmlspecialchars($app['applicant_name']); ?></strong>
                                                                    <span style="background: #eef2ff; color: #4154f1; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;"><?php echo $app['match_percentage']; ?>% Match</span>
                                                                </div>
                                                                <span style="font-size: 0.8rem; color: #666;"><?php echo htmlspecialchars($app['email']); ?> • Applied: <?php echo date('M d, Y', strtotime($app['applied_at'])); ?></span>
                                                            </div>
                                                        </div>

                                                        <div>
                                                            <button type="button" class="js-view-applicant" data-applicant="<?php echo applicantData($app, $jobData['title'], $listing['schedule'] ?? ''); ?>" style="background: #4154f1; color: #fff; padding: 8px 18px; border-radius: 8px; border: none; font-weight: bold; font-size: 0.85rem; cursor: pointer; box-shadow: 0 2px 6px rgba(65,84,241,0.2);">
                                                                View
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </main>

    <div id="applicantModal" class="modal-overlay">
        <div class="modal-card">
          <div id="mailStep" style="display: none;"></div>
          <div id="candidateStep">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eaeaea; padding-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                    <span id="modalPic" class="mt-pfp mt-pfp-48"></span>
                    <h3 id="modalName" style="font-size: 1.3rem; font-weight: bold; color: #111;">Candidate Profile</h3>
                </div>
                <span onclick="closeApplicantModal()" role="button" aria-label="Close" style="cursor: pointer; font-size: 1.4rem; font-weight: bold; color: #666;">&times;</span>
            </div>

            <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span id="modalMatchBadge" style="background: #eef2ff; color: #4154f1; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85rem;"></span>
                    <span id="modalLocation" style="font-size: 0.85rem; color: #666;"></span>
                </div>

                <div>
                    <strong style="font-size: 0.82rem; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px; font-weight: bold;">Email Address</strong>
                    <p id="modalEmail" style="font-size: 0.95rem; color: #111; font-weight: bold;"></p>
                </div>

                <div>
                    <strong style="font-size: 0.82rem; text-transform: uppercase; color: #888; display: block; margin-bottom: 2px; font-weight: bold;">Phone Number</strong>
                    <p id="modalPhone" style="font-size: 0.95rem; color: #111; font-weight: bold;"></p>
                </div>

                <div>
                    <strong style="font-size: 0.82rem; text-transform: uppercase; color: #888; display: block; margin-bottom: 6px; font-weight: bold;">Availability <span style="text-transform: none; font-weight: normal; letter-spacing: 0;">(20% of the match score)</span></strong>
                    <div id="modalSlots" style="display: flex; gap: 6px; flex-wrap: wrap;"></div>
                    <p id="modalJobSlots" style="font-size: 0.8rem; color: #666; margin-top: 6px;"></p>
                </div>

                <div>
                    <strong style="font-size: 0.82rem; text-transform: uppercase; color: #888; display: block; margin-bottom: 6px; font-weight: bold;">Professional Skills</strong>
                    <div id="modalSkills" style="display: flex; gap: 6px; flex-wrap: wrap;"></div>
                </div>

                <div>
                    <strong style="font-size: 0.82rem; text-transform: uppercase; color: #888; display: block; margin-bottom: 4px; font-weight: bold;">Professional Bio</strong>
                    <p id="modalBio" style="font-size: 0.9rem; color: #444; background: #fafbfc; padding: 12px; border-radius: 8px; border: 1px solid #eaeaea; line-height: 1.4;"></p>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #eaeaea; padding-top: 15px;">
                <button type="button" id="modalRejectBtn" style="background: #fff; color: #d93025; border: 1px solid #d93025; padding: 9px 20px; border-radius: 8px; font-weight: bold; font-size: 0.88rem; cursor: pointer;">Reject</button>
                <button type="button" id="modalAcceptBtn" style="background: #1e8e3e; color: #fff; padding: 9px 22px; border: none; border-radius: 8px; font-weight: bold; font-size: 0.88rem; box-shadow: 0 2px 6px rgba(30,142,62,0.2); cursor: pointer;">Shortlist &amp; Email</button>
            </div>
          </div>
        </div>
    </div>

    <!-- Small message that pops up after an action (no page reload) -->
    <div class="mt-toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        // ==========================================================================
        // Switching between Recommended Gigs / Applicant Queue and pressing Shortlist or
        // Reject now update the page WITHOUT reloading it. PHP still does all the real
        // work (saving, scoring, sorting). JavaScript only asks PHP in the background
        // and swaps in the part of the page that changed. If anything goes wrong it
        // falls back to a normal page load.
        // ==========================================================================
        var PAGE = 'matches.php';
        var currentApplicant = null;   // the applicant shown in the popup
        var mailDraft = null;          // the email that is ready after Shortlist & Email
        var latestRequest = 0;
        var toastTimer = null;

        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
            });
        }

        function showToast(message, kind) {
            var toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'mt-toast show' + (kind ? ' ' + kind : '');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4500);
        }

        function toggleDropdown() {
            document.getElementById("profileDropdown").classList.toggle("show-dropdown");
        }

        // ---------------- Put new HTML from PHP into the page ----------------
        // Returns null if it is not the Matches page (for example the session expired and PHP sent the login page).
        function swapIn(html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newContent = doc.getElementById('mt-content');
            if (!newContent) { return null; }

            var flash = doc.querySelector('#mt-flash .mt-flash');
            document.getElementById('mt-content').innerHTML = newContent.innerHTML;
            document.getElementById('mt-content').setAttribute('data-tab', newContent.getAttribute('data-tab') || 'gigs');

            var newStats = doc.getElementById('mt-stats');
            if (newStats) { document.getElementById('mt-stats').innerHTML = newStats.innerHTML; }
            var newNav = doc.getElementById('mt-nav');
            if (newNav) { document.getElementById('mt-nav').innerHTML = newNav.innerHTML; }

            // The green / red bar from PHP becomes a toast, so clear it from the page
            document.getElementById('mt-flash').innerHTML = '';
            return { flash: flash ? flash.textContent.trim() : '' };
        }

        // mode: 'push' = add to browser history (Back button works), 'replace' = update the address only, 'none' = leave it
        function loadPage(url, mode) {
            var myRequest = ++latestRequest;
            var content = document.getElementById('mt-content');
            content.classList.add('mt-loading');

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
                    if (myRequest === latestRequest) { content.classList.remove('mt-loading'); }
                });
        }

        // Tabs (Recommended Gigs / Applicant Queue)
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a[data-ajax]') : null;
            if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();

            var links = document.querySelectorAll('#mt-nav a');   // show the clicked tab as selected right away
            for (var i = 0; i < links.length; i++) { links[i].classList.toggle('active', links[i] === link); }
            loadPage(link.href, link.href === window.location.href ? 'replace' : 'push');
        });

        // Back / Forward buttons
        window.addEventListener('popstate', function () {
            closeApplicantModal();
            loadPage(window.location.href, 'none');
        });

        // ---------------- Applicant popup ----------------
        function openApplicantModal(a) {
            currentApplicant = a;
            mailDraft = null;
            document.getElementById('modalName').innerText = a.name;
            document.getElementById('modalEmail').innerText = a.email;
            document.getElementById('modalPhone').innerText = a.phone || 'Not provided';
            document.getElementById('modalLocation').innerText = 'Location: ' + (a.location || 'Quezon City');
            document.getElementById('modalMatchBadge').innerText = a.match + '% Match Score';
            document.getElementById('modalBio').innerText = a.bio || 'No bio provided.';

            // Live profile picture (initials when the applicant has none)
            var pic = document.getElementById('modalPic');
            pic.innerHTML = '';
            if (a.pic) {
                var img = document.createElement('img');
                img.setAttribute('src', a.pic);
                img.setAttribute('alt', '');
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                pic.appendChild(img);
            } else {
                pic.innerText = a.initials || 'U';
            }

            // Availability (one of the three scoring parts) + the schedule the job asks for
            var slotsBox = document.getElementById('modalSlots');
            slotsBox.innerHTML = '';
            var slotList = (a.slots || '').split(',');
            var shown = 0;
            slotList.forEach(function (s) {
                if (!s.trim()) { return; }
                var span = document.createElement('span');
                span.className = 'mt-slot';
                span.innerText = s.trim();
                slotsBox.appendChild(span);
                shown++;
            });
            if (!shown) {
                slotsBox.innerHTML = '<span style="color: #888; font-size: 0.85rem; font-style: italic;">No availability set.</span>';
            }
            document.getElementById('modalJobSlots').innerText = a.jobSlots ? 'This job needs: ' + a.jobSlots : '';

            var skillsContainer = document.getElementById('modalSkills');
            skillsContainer.innerHTML = '';
            var skills = (a.skills || 'General');
            if (skills.trim()) {
                skills.split(',').forEach(function (s) {
                    var span = document.createElement('span');
                    span.style.cssText = 'background: #f0f4ff; color: #4154f1; padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: bold;';
                    span.innerText = s.trim();
                    skillsContainer.appendChild(span);
                });
            } else {
                skillsContainer.innerHTML = '<span style="color: #888; font-size: 0.85rem; font-style: italic;">No skills listed.</span>';
            }

            document.getElementById('candidateStep').style.display = 'block';
            document.getElementById('mailStep').style.display = 'none';
            document.getElementById('modalRejectBtn').disabled = false;
            document.getElementById('modalAcceptBtn').disabled = false;
            document.getElementById('applicantModal').style.display = 'flex';
        }

        function closeApplicantModal() {
            document.getElementById('applicantModal').style.display = 'none';
            currentApplicant = null;
            mailDraft = null;
        }

        // View button on each applicant row (the details travel in a data attribute, so names with quotes or line breaks are safe)
        document.addEventListener('click', function (e) {
            var viewBtn = e.target.closest ? e.target.closest('.js-view-applicant') : null;
            if (!viewBtn) { return; }
            try { openApplicantModal(JSON.parse(viewBtn.getAttribute('data-applicant'))); } catch (err) {}
        });

        // ---------------- Shortlist / Reject without reloading ----------------
        function setStatus(action) {
            if (!currentApplicant) { return; }
            var applicant = currentApplicant;
            var acceptBtn = document.getElementById('modalAcceptBtn');
            var rejectBtn = document.getElementById('modalRejectBtn');
            acceptBtn.disabled = true;
            rejectBtn.disabled = true;
            var myRequest = ++latestRequest;

            // POST (not a link) with the secret code of this session; PHP saves the change and sends back the updated page
            var data = new FormData();
            data.append('action', action);
            data.append('app_id', applicant.id);
            data.append('csrf', document.body.getAttribute('data-csrf') || '');
            fetch(PAGE + '?tab=applicants', { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (res) {
                    if (res.redirected && res.url.indexOf(PAGE) === -1) { throw new Error('redirected'); }
                    return res.text();
                })
                .then(function (html) {
                    var result = swapIn(html);
                    if (!result) { throw new Error('unexpected page'); }

                    // If the applicant is still in the queue, PHP did not save the change
                    if (document.querySelector('.js-view-applicant[data-applicant*=\'"id":' + applicant.id + ',\']')) { throw new Error('not saved'); }

                    if (action === 'accept') {
                        showToast(result.flash || 'Applicant shortlisted and removed from the queue!', 'ok');
                        showMailStep(applicant);
                    } else {
                        showToast(result.flash || 'Applicant moved to Rejected and removed from the queue.');
                        closeApplicantModal();
                    }
                })
                .catch(function (err) {
                    if (err && err.message === 'redirected') { window.location.href = PAGE; return; }
                    acceptBtn.disabled = false;
                    rejectBtn.disabled = false;
                    showToast('Error: could not save that. Please try again.', 'err');
                })
                .then(function () {
                    if (myRequest === latestRequest) { document.getElementById('mt-content').classList.remove('mt-loading'); }
                });
        }
        document.getElementById('modalAcceptBtn').addEventListener('click', function () { setStatus('accept'); });
        document.getElementById('modalRejectBtn').addEventListener('click', function () { setStatus('reject'); });

        // ---------------- Email that is ready after Shortlist & Email ----------------
        // Works on any computer: "Open in Gmail" needs no mail app, "Open mail app" uses the default one.
        function showMailStep(a) {
            var me = document.body.getAttribute('data-me') || '';
            mailDraft = { email: a.email };
            var subject = 'JobiT - Your application for ' + a.job;
            var text = 'Hi ' + a.name + ',\n\nWe reviewed your application for "' + a.job + '" on JobiT and we are interested in you!\n\n' +
                       'Please reply to this email so we can talk about the next steps.\n\nBest regards,\n' + me;

            document.getElementById('mailStep').innerHTML =
                '<div class="mt-mail-head"><div class="mt-mail-ok">&#10003;</div><div><b>' + esc(a.name) + ' shortlisted</b><span>Your email is ready. Check it, then send.</span></div></div>' +
                '<label for="mailTo">To</label><input id="mailTo" type="text" value="' + esc(a.email) + '" readonly>' +
                '<label for="mailSubject">Subject</label><input id="mailSubject" type="text" value="' + esc(subject) + '">' +
                '<label for="mailBody">Message</label><textarea id="mailBody" rows="6">' + esc(text) + '</textarea>' +
                '<div class="mt-mail-actions">' +
                    '<button type="button" class="mt-btn mt-btn-blue" data-mail-do="gmail">Open in Gmail</button>' +
                    '<button type="button" class="mt-btn mt-btn-line" data-mail-do="app">Open mail app</button>' +
                    '<button type="button" class="mt-btn mt-btn-line" data-mail-do="copy">Copy</button>' +
                    '<button type="button" class="mt-skip" data-mail-do="skip">Done</button>' +
                '</div>';
            document.getElementById('candidateStep').style.display = 'none';
            document.getElementById('mailStep').style.display = 'block';
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-mail-do]') : null;
            if (!btn || !mailDraft) { return; }
            var what = btn.getAttribute('data-mail-do');
            var to = mailDraft.email;
            var subject = document.getElementById('mailSubject').value;
            var text = document.getElementById('mailBody').value;

            if (what === 'gmail') {
                window.open('https://mail.google.com/mail/?view=cm&fs=1&to=' + encodeURIComponent(to) + '&su=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(text), '_blank', 'noopener');
            } else if (what === 'app') {
                window.location.href = 'mailto:' + to + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(text);
            } else if (what === 'copy') {
                var all = 'To: ' + to + '\nSubject: ' + subject + '\n\n' + text;
                var done = function () { showToast('Email copied. Paste it into your email app.', 'ok'); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(all).then(done, function () { showToast('Error: could not copy. Select the text and copy it yourself.', 'err'); });
                } else {
                    var box = document.getElementById('mailBody'); box.select(); document.execCommand('copy'); done();
                }
            } else if (what === 'skip') {
                closeApplicantModal();
            }
        });

        // ---------------- Keep it live ----------------
        // Every 30 seconds (and when you come back to this tab) the page quietly asks PHP for the
        // newest data. New profile pictures, new applicants and new gigs then show up by themselves.
        // It only swaps the page when something really changed, never while the popup is open,
        // and it never jumps over a click you just made.
        function quietRefresh() {
            var content = document.getElementById('mt-content');
            var modal = document.getElementById('applicantModal');
            if (document.hidden || content.classList.contains('mt-loading') || modal.style.display === 'flex') { return; }

            var startedAt = latestRequest;
            var tab = content.getAttribute('data-tab') || 'gigs';
            fetch(PAGE + '?tab=' + encodeURIComponent(tab), { credentials: 'same-origin' })
                .then(function (res) {
                    if (res.redirected && res.url.indexOf(PAGE) === -1) { throw new Error('redirected'); }
                    return res.text();
                })
                .then(function (html) {
                    if (startedAt !== latestRequest || modal.style.display === 'flex') { return; }   // you clicked something meanwhile
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('mt-content');
                    if (!fresh || fresh.getAttribute('data-tab') !== content.getAttribute('data-tab')) { return; }
                    var freshStats = doc.getElementById('mt-stats');
                    var freshNav = doc.getElementById('mt-nav');
                    var same = fresh.innerHTML === content.innerHTML
                        && (!freshStats || freshStats.innerHTML === document.getElementById('mt-stats').innerHTML)
                        && (!freshNav || freshNav.innerHTML === document.getElementById('mt-nav').innerHTML);
                    if (same) { return; }                                                            // nothing changed, leave the page alone
                    swapIn(html);
                })
                .catch(function () { /* stay quiet, the next round will try again */ });
        }
        setInterval(quietRefresh, 30000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { quietRefresh(); } });

        // Click outside the popup closes it; click elsewhere closes the profile menu; Escape closes the popup
        window.addEventListener('click', function (event) {
            var modal = document.getElementById('applicantModal');
            if (event.target === modal) { closeApplicantModal(); }

            if (!event.target.matches('#avatarBtn') && !event.target.closest('.profile-avatar-btn')) {
                var dropdowns = document.getElementsByClassName("dropdown-menu");
                for (var i = 0; i < dropdowns.length; i++) {
                    dropdowns[i].classList.remove('show-dropdown');
                }
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeApplicantModal(); }
        });
    </script>
</body>
</html>
