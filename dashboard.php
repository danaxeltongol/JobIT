<?php
// ============================================================================
// DATA STRUCTURES & ALGORITHM (DSA) BACKEND ENGINE FOR JOBIT DASHBOARD
// ============================================================================

session_start();
require_once 'db.php';
require_once __DIR__ . '/sorting.php';   // our own heap top-k (picks the top 3 recommendations)

// Check logged in user ID from Session
$userId = $_SESSION['user_id'] ?? null;

// Redirect unauthenticated users back to login
if (!$userId && !isset($_SESSION['user_profile'])) {
    header("Location: auth.php?mode=login");
    exit();
}

// FETCH & REAL-TIME SYNC USER PROFILE FROM DB
$currentUserProfile = $_SESSION['user_profile'] ?? [];
if ($userId) {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $dbUser = $userStmt->fetch();
    if ($dbUser) {
        $currentUserProfile['name'] = $dbUser['full_name'];
        $currentUserProfile['initials'] = strtoupper(substr($dbUser['full_name'], 0, 2));
        $currentUserProfile['location'] = $dbUser['location'];
        $currentUserProfile['skills'] = !empty($dbUser['skills']) ? array_map('trim', explode(',', $dbUser['skills'])) : ['PHP', 'HTML/CSS', 'Hardware Repair'];
        $currentUserProfile['avatar'] = $dbUser['avatar'] ?? '';
        
        $_SESSION['user_profile']['avatar'] = $currentUserProfile['avatar'];
        $_SESSION['user_profile']['name'] = $currentUserProfile['name'];
    }
}

$userName = $currentUserProfile['name'] ?? 'Dan Axel M. Tongol';
$userInitials = $currentUserProfile['initials'] ?? 'DT';
$userAvatar = $currentUserProfile['avatar'] ?? '';
$userLocation = $currentUserProfile['location'] ?? 'Quezon City';

// ============================================================================
// PROFILE STRENGTH CALCULATOR (real data, not a fixed number anymore)
// ============================================================================
function calculateProfileStrength($user) {
    $score = 0;

    // Core info, 30 points total
    if (!empty($user['full_name'])) $score += 10;
    if (!empty($user['email'])) $score += 10;
    if (!empty($user['phone'])) $score += 10;

    // Location, 15 points
    if (!empty($user['location'])) $score += 15;

    // Bio, 15 points
    if (!empty($user['bio'])) $score += 15;

    // Avatar, 10 points
    if (!empty($user['avatar'])) $score += 10;

    // Skills, up to 15 points. Full points once they have 3 or more skills
    $skillsCount = 0;
    if (!empty($user['skills'])) {
        $skillsList = array_filter(array_map('trim', explode(',', $user['skills'])));
        $skillsCount = count($skillsList);
    }
    $score += min($skillsCount, 3) / 3 * 15;

    // Available Schedule, 15 points. This is the same field that now powers the real match score
    if (!empty($user['available_slots'])) $score += 15;

    return (int) min(100, round($score));
}

$profileStrength = !empty($dbUser) ? calculateProfileStrength($dbUser) : 0;

// FETCH USER STATS (Active listings count, applicants count)
$activeListingsCount = 0;
$totalApplicantsCount = 0;
$completedJobsCount = 0;
try {
    if ($userId) {
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'active'");
        $cntStmt->execute([$userId]);
        $activeListingsCount = $cntStmt->fetchColumn();

        // Count real pending applicants on this user's active listings
        $appCntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM applications a
            JOIN listings l ON a.listing_id = l.id
            WHERE l.user_id = ? AND l.status = 'active' AND a.status = 'pending'
        ");
        $appCntStmt->execute([$userId]);
        $totalApplicantsCount = $appCntStmt->fetchColumn();

        // Count real completed listings
        $compStmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'completed'");
        $compStmt->execute([$userId]);
        $completedJobsCount = $compStmt->fetchColumn();
    }
} catch (Exception $e) {}

// ============================================================================
// RECOMMENDED FOR YOU (live): the same score as Browse and Matches, ranked by our merge sort
// ============================================================================
$scoreSkills = $dbUser['skills'] ?? '';            // the REAL profile values (no made-up defaults in the score)
$scoreLocation = $dbUser['location'] ?? '';
$scoreSlots = $dbUser['available_slots'] ?? '';
$recommended = [];
$greatMatchCount = 0;
$appliedSet = [];                                   // HASH SET: listing id => true for gigs you applied to
try {
    $gigSql = "SELECT l.*, u.full_name AS employer_name, u.avatar AS employer_avatar FROM listings l JOIN users u ON l.user_id = u.id WHERE l.status = 'active'";
    $gigParams = [];
    if ($userId) {
        $gigSql .= " AND l.user_id != ?";
        $gigParams[] = $userId;
    }
    $gigSql .= " ORDER BY l.created_at DESC";      // newest first, so equal scores show the newer gig first
    $gigStmt = $pdo->prepare($gigSql);
    $gigStmt->execute($gigParams);
    foreach ($gigStmt->fetchAll(PDO::FETCH_ASSOC) as $gig) {
        $gig['why'] = getScoreBreakdown($gig['skills_required'] ?? '', $gig['location'] ?? '', $gig['schedule'] ?? '', $scoreSkills, $scoreLocation, $scoreSlots);
        $gig['match_score'] = $gig['why']['total'];
        $gig['skill_chips'] = getSkillsBreakdown($gig['skills_required'] ?? '', $scoreSkills);
        if ($gig['match_score'] >= 75) $greatMatchCount++;
        $recommended[] = $gig;
    }

    if ($userId) {
        $apStmt = $pdo->prepare("SELECT listing_id FROM applications WHERE applicant_id = ?");
        $apStmt->execute([$userId]);
        foreach ($apStmt->fetchAll(PDO::FETCH_COLUMN) as $apId) {
            $appliedSet[(int)$apId] = true;
        }
    }
} catch (Exception $e) {}
$openGigCount = count($recommended);
// Only the best 3 are shown here, so we use the MIN-HEAP top-k (sorting.php) instead of sorting every gig:
// about n x log2(3) comparisons instead of n x log2(n). It gives the very same top 3 as the merge sort on Matches.
$nextMatches = topKByKey($recommended, 'match_score', 3);
$topMatch = $nextMatches[0] ?? null;

// ---------- display helpers (design only) ----------
function dbTier($score) { return $score >= 75 ? 'high' : ($score >= 50 ? 'mid' : 'low'); }
function dbTierLabel($score) { return $score >= 75 ? 'Great match' : ($score >= 50 ? 'Good match' : 'Partial match'); }
function dbPts($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function dbPic($path) {
    $path = (string)$path;
    if ($path === '' || !file_exists($path)) return '';
    return $path . '?v=' . filemtime($path);
}
function dbInitials($name) {
    $out = '';
    foreach (explode(' ', trim((string)$name)) as $w) { if ($w !== '') $out .= strtoupper(mb_substr($w, 0, 1)); }
    return mb_substr($out, 0, 2) ?: 'U';
}
$scoreParts = [['s', 'Skills', 'skills_points', 50], ['l', 'Location', 'location_points', 30], ['a', 'Availability', 'availability_points', 20]];
$firstName = trim(explode(' ', trim($userName))[0] ?? '') ?: $userName;
$userPic = dbPic($userAvatar);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4154f1">
    <title>JobiT - Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ---------- Brand tokens: blue + white, yellow only as a small accent ---------- */
        :root {
            --blue: #4154f1; --deep: #2938b7; --soft: #eef1ff; --tint: #f6f8ff; --yellow: #ffe600;
            --ink: #161b3a; --muted: #667085; --line: #e4e8f7; --white: #ffffff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
            background: var(--white); color: var(--ink); line-height: 1.55;
            min-height: 100vh; display: flex; flex-direction: column;
        }
        a { color: inherit; text-decoration: none; }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--blue); outline-offset: 2px; }

        /* ---------- Page bands: blue, white, blue, white ---------- */
        .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 32px; }
        .band { padding: 80px 0; }
        .band--white { background: var(--white); color: var(--ink); }
        .band--blue { background: var(--blue); color: #fff; }

        .section-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 36px; }
        .eyebrow { display: block; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: var(--blue); margin-bottom: 8px; }
        .band--blue .eyebrow { color: var(--yellow); }
        .section-head h2 { font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; }
        .section-head .sub { margin-top: 8px; font-size: 1rem; color: var(--muted); max-width: 520px; }
        .band--blue .section-head .sub { color: #d9defe; }
        .link { color: var(--blue); font-weight: 700; font-size: 0.92rem; white-space: nowrap; }
        .link:hover { text-decoration: underline; }
        .band--blue .link { color: #fff; }

        /* ---------- Navbar (white) ---------- */
        .jb-nav {
            position: sticky; top: 0; z-index: 50; display: flex; align-items: center;
            justify-content: space-between; gap: 24px; padding: 12px 40px;
            background: rgba(255,255,255,0.94); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        .jb-logo { height: 40px; display: block; }
        .jb-links { display: flex; align-items: center; gap: 4px; }
        .jb-link {
            padding: 8px 16px; border-radius: 999px; font-weight: 600; font-size: 0.93rem;
            color: #3b4160; transition: background .2s, color .2s;
        }
        .jb-link:hover, .jb-link.active { background: var(--soft); color: var(--blue); }
        .jb-link.cta { background: var(--blue); color: #fff; margin-left: 8px; }
        .jb-link.cta:hover { background: var(--deep); }

        .profile-dropdown-container { position: relative; }
        .profile-avatar-btn {
            width: 40px; height: 40px; border-radius: 50%; border: 2px solid var(--soft);
            background: var(--blue); color: #fff; font-weight: 700; font-size: 0.95rem;
            display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden;
        }
        .profile-avatar-btn img { width: 100%; height: 100%; object-fit: cover; }
        .dropdown-menu {
            display: none; position: absolute; right: 0; top: 50px; min-width: 190px;
            background: #fff; border: 1px solid var(--line); border-radius: 14px;
            box-shadow: 0 16px 36px rgba(22,27,58,0.14); overflow: hidden; z-index: 1000;
        }
        .dropdown-menu a { display: block; padding: 12px 18px; font-size: 0.93rem; color: #333; }
        .dropdown-menu a:hover { background: var(--soft); color: var(--blue); }
        .dropdown-menu .dropdown-logout { color: #e5484d; border-top: 1px solid var(--line); }
        .show-dropdown { display: block !important; }

        /* ---------- Buttons ---------- */
        .btn { display: inline-block; padding: 13px 28px; border-radius: 999px; font-weight: 700; font-size: 0.95rem; transition: transform .2s, background .2s; }
        .btn:hover { transform: translateY(-2px); }
        .btn-yellow { background: var(--yellow); color: var(--ink); }
        .btn-ghost { border: 1.5px solid rgba(255,255,255,0.6); color: #fff; margin-left: 10px; }
        .btn-ghost:hover { background: rgba(255,255,255,0.12); }
        a.btn:focus-visible { outline-color: var(--yellow); }

        /* ======================================================================
           HOME (same look as Browse and Matches): blue hero with your #1 match,
           score rings and the 3 score colours: Skills blue, Location green, Availability amber
           ====================================================================== */
        :root { --c-s: #4154f1; --c-l: #1e8e3e; --c-a: #f29900; --good: #1e8e3e; --good-bg: #e6f9ed; --warn-bg: #fff5da; }
        main { flex: 1; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; flex-shrink: 0; }
        .dot.s { background: var(--c-s); } .dot.l { background: var(--c-l); } .dot.a { background: var(--c-a); }

        /* score rings (--p = percent filled) */
        .ring { --p: 0; --c: var(--blue); --track: #e6e9f8; --hole: #fff; --w: 6px; position: relative; display: grid; place-items: center; border-radius: 50%; flex-shrink: 0;
                background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--track) 0); }
        .ring::before { content: ""; position: absolute; inset: var(--w); border-radius: 50%; background: var(--hole); }
        .ring b { position: relative; font-weight: 800; color: var(--ink); letter-spacing: -0.03em; }
        .ring.sm { width: 64px; height: 64px; } .ring.sm b { font-size: 0.74rem; }
        .ring.high { --c: var(--good); } .ring.mid { --c: #e8a100; } .ring.low { --c: #a3a9bb; }
        .ring.lg { width: 112px; height: 112px; --w: 11px; --c: var(--yellow); --track: rgba(255,255,255,0.22); --hole: var(--deep); }
        .ring.lg b { font-size: 1.3rem; color: #fff; }
        .ring.md { width: 76px; height: 76px; --w: 8px; } .ring.md b { font-size: 0.95rem; }

        /* ---------- 1. Hero (blue) ---------- */
        .hero { position: relative; overflow: hidden; padding: 40px 0 44px; }
        .hero::before { content: ""; position: absolute; right: -140px; top: -200px; width: 520px; height: 520px; border-radius: 50%; background: rgba(255,255,255,0.06); }
        .hero::after { content: ""; position: absolute; left: 30%; bottom: -260px; width: 420px; height: 420px; border-radius: 50%; background: rgba(255,255,255,0.05); }
        .hero-grid { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(0, 1fr) 420px; align-items: center; gap: 40px; }
        .hero-tag { display: inline-block; background: var(--yellow); color: var(--ink); font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 11px; border-radius: 999px; margin-bottom: 14px; }
        .hero h1 { font-size: 2.6rem; line-height: 1.1; letter-spacing: -0.03em; font-weight: 800; margin-bottom: 14px; }
        .hero h1 span { color: var(--yellow); }
        .hero p { color: #dfe3ff; font-size: 1.02rem; max-width: 520px; margin-bottom: 24px; }
        .hero-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        .hero-stat { display: flex; align-items: baseline; gap: 8px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22); padding: 8px 14px; border-radius: 12px; }
        .hero-stat b { font-size: 1.2rem; font-weight: 800; }
        .hero-stat span { font-size: 0.8rem; color: #e6e9ff; font-weight: 600; }

        /* #1 match card in the hero */
        .topcard { background: #fff; color: var(--ink); border-radius: 22px; overflow: hidden; box-shadow: 0 22px 50px rgba(10,16,70,0.32); }
        .topcard-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; background: linear-gradient(160deg, var(--blue), var(--deep)); color: #fff; border-bottom: 1px solid rgba(255,255,255,0.15); }
        .topcard-head small { display: block; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--yellow); }
        .topcard-head .tier { display: inline-block; margin-top: 6px; background: rgba(255,255,255,0.16); padding: 3px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; }
        .topcard-body { padding: 16px 20px 18px; display: flex; flex-direction: column; gap: 10px; }
        .topcard h3 { font-size: 1.15rem; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
        .topcard .go-btn { align-self: stretch; text-align: center; }
        .topcard-empty { padding: 26px 22px; text-align: center; color: var(--muted); }
        .topcard-empty b { display: block; color: var(--ink); font-size: 1.05rem; margin-bottom: 4px; }

        /* shared small parts */
        .tags { display: flex; flex-wrap: wrap; align-items: center; gap: 5px 6px; }
        .chip { display: inline-block; background: var(--soft); color: var(--blue); padding: 3px 11px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .rank { background: var(--ink); color: #fff; padding: 3px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
        .weak { background: #fff5da; color: #8a5a00; padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; white-space: nowrap; }
        .applied { background: var(--good-bg); color: var(--good); padding: 3px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; }
        .facts { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; font-size: 0.85rem; color: var(--muted); font-weight: 600; }
        .facts > span + span::before { content: "\2022"; margin: 0 9px; color: #c3c8d8; }
        .price { color: var(--blue); font-weight: 800; font-size: 1rem; }
        .splitbar { display: flex; height: 8px; border-radius: 999px; background: #e3e7f6; overflow: hidden; }
        .splitbar i { display: block; height: 100%; }
        .splitbar i.s { background: var(--c-s); } .splitbar i.l { background: var(--c-l); } .splitbar i.a { background: var(--c-a); }
        .split-keys { display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 0.74rem; color: var(--muted); font-weight: 700; margin-top: -4px; }
        .split-keys span { display: inline-flex; align-items: center; }
        .sk { padding: 3px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; background: var(--good-bg); color: var(--good); }
        .skills { display: flex; flex-wrap: wrap; gap: 6px; }
        .go-btn { display: inline-block; padding: 11px 20px; border-radius: 999px; background: var(--blue); color: #fff; font-weight: 800; font-size: 0.88rem; transition: background .2s, transform .2s; }
        .go-btn:hover { background: var(--deep); transform: translateY(-1px); }

        /* ---------- 2. Overview (soft) ---------- */
        .band--soft { background: #f4f6fb; }
        .band.tight { padding: 44px 0 52px; }
        .overview { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 20px; align-items: stretch; }
        .card { background: #fff; border: 1.5px solid #dfe4fb; border-radius: 22px; padding: 24px; }
        .profile-row { display: flex; align-items: center; gap: 16px; }
        .profile-row .who { flex: 1; min-width: 0; }
        .avatar {
            width: 60px; height: 60px; border-radius: 50%; overflow: hidden; background: var(--blue); flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 1.05rem;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar.xs { width: 28px; height: 28px; font-size: 0.66rem; }
        .profile-row h4 { font-size: 1.15rem; font-weight: 800; overflow-wrap: anywhere; }
        .sub { font-size: 0.9rem; color: var(--muted); }
        .strength { display: flex; align-items: center; gap: 12px; }
        .strength-txt { font-size: 0.8rem; font-weight: 700; color: var(--muted); line-height: 1.3; }
        .strength-txt a { display: block; color: var(--blue); font-weight: 800; margin-top: 2px; }
        .strength-txt a:hover { text-decoration: underline; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 20px; }
        .stat { background: var(--tint); border: 1px solid var(--line); border-radius: 16px; padding: 14px 8px; text-align: center; transition: border-color .2s, transform .2s; }
        a.stat:hover { border-color: var(--blue); transform: translateY(-2px); }
        .stat b { display: block; font-size: 1.7rem; font-weight: 800; color: var(--blue); line-height: 1.1; }
        .stat.good b { color: var(--good); }
        .stat span { font-size: 0.76rem; color: var(--muted); font-weight: 700; }

        .basis { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 8px; }
        .basis-row { display: flex; align-items: flex-start; gap: 10px; }
        .basis-k { width: 76px; flex-shrink: 0; display: flex; align-items: center; font-size: 0.8rem; font-weight: 700; color: var(--muted); padding-top: 2px; }
        .basis-v { display: flex; flex-wrap: wrap; gap: 5px; min-width: 0; }
        .basis-v em { font-style: normal; color: #b0b6c6; font-weight: 600; font-size: 0.85rem; }
        .me-chip { background: var(--soft); color: var(--blue); padding: 2px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; overflow-wrap: anywhere; }
        .me-chip.more { background: #f1f2f6; color: var(--muted); }
        .actions h4 { font-size: 1.1rem; font-weight: 800; margin-bottom: 4px; }
        .actions a {
            display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 14px 2px;
            border-bottom: 1px solid var(--line); font-weight: 700; font-size: 0.95rem; transition: color .2s;
        }
        .actions a:last-child { border-bottom: none; }
        .actions a small { display: block; font-size: 0.78rem; color: var(--muted); font-weight: 600; }
        .actions a > span:last-child {
            width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; background: var(--soft); color: var(--blue);
            display: flex; align-items: center; justify-content: center; transition: background .2s, color .2s;
        }
        .actions a:hover { color: var(--blue); }
        .actions a:hover > span:last-child { background: var(--blue); color: #fff; }

        /* ---------- 3. Recommended for you (live top 3) ---------- */
        .legend { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; font-size: 0.8rem; font-weight: 700; margin-bottom: 16px; }
        .legend span { display: inline-flex; align-items: center; }
        .legend-k { color: var(--muted); font-weight: 600; }
        .recs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .rec { display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1.5px solid #dfe4fb; border-radius: 20px; padding: 18px 20px; transition: border-color .2s, transform .2s, box-shadow .2s; }
        .rec:hover { border-color: var(--blue); transform: translateY(-3px); box-shadow: 0 14px 30px rgba(65,84,241,0.12); }
        .rec-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .rec h3 { font-size: 1.04rem; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
        .rec-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: auto; padding-top: 12px; border-top: 1px solid var(--line); font-size: 0.8rem; color: var(--muted); font-weight: 600; }
        .rec-by { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .rec-by span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rec-more { color: var(--blue); font-weight: 800; white-space: nowrap; }
        .recs-empty { background: #fff; border: 1.5px dashed #b9c1fb; border-radius: 20px; padding: 40px 24px; text-align: center; color: var(--muted); }
        .recs-empty b { display: block; color: var(--ink); font-size: 1.1rem; margin-bottom: 4px; }

        /* browse by type (your three pictures) */
        .types-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin: 40px 0 16px; }
        .types-head h3 { font-size: 1.2rem; font-weight: 800; }
        .cat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .cat { display: flex; background: #fff; color: var(--ink); border: 1.5px solid #dfe4fb; border-radius: 20px; overflow: hidden; transition: transform .25s, box-shadow .25s, border-color .25s; }
        .cat:hover { transform: translateY(-4px); border-color: var(--blue); box-shadow: 0 16px 34px rgba(65,84,241,0.14); }
        .cat-img { width: 120px; flex-shrink: 0; background: var(--soft); }
        .cat-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cat-body { display: flex; flex-direction: column; flex: 1; padding: 16px 18px; min-width: 0; }
        .cat h4 { font-size: 1.05rem; font-weight: 800; margin-bottom: 4px; }
        .cat p { flex: 1; font-size: 0.84rem; color: var(--muted); margin-bottom: 10px; }
        .cat-btn { font-size: 0.84rem; font-weight: 800; color: var(--blue); }

        /* ---------- 4. How it works (white) ---------- */
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 28px; }
        .step { position: relative; }
        .step:not(:last-child)::after {
            content: ""; position: absolute; top: 24px; left: 64px; right: -14px; height: 2px;
            background: repeating-linear-gradient(90deg, var(--line) 0 6px, transparent 6px 12px);
        }
        .step-num {
            width: 48px; height: 48px; border-radius: 50%; background: var(--blue); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 1.15rem; font-weight: 800; margin-bottom: 16px;
        }
        .step:nth-child(2) .step-num { background: var(--yellow); color: var(--ink); }
        .step h4 { font-size: 1.05rem; font-weight: 800; margin-bottom: 4px; }
        .step p { font-size: 0.9rem; color: var(--muted); }

        /* ---------- 5. Call to action (blue) ---------- */
        .cta-band { text-align: center; padding: 64px 0; }
        .cta-band h2 { font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; margin-bottom: 10px; }
        .cta-band p { color: #dfe3ff; font-size: 1.02rem; margin-bottom: 26px; }

        /* ---------- Footer (white) ---------- */
        .jb-footer { background: #fff; border-top: 1px solid var(--line); padding: 36px 20px; text-align: center; margin-top: auto; }
        .jb-footer strong { display: block; font-size: 1.02rem; margin-bottom: 6px; }
        .jb-footer .team { color: #444; font-size: 0.93rem; margin-bottom: 14px; }
        .jb-footer hr { border: none; border-top: 1px solid var(--line); width: 60%; margin: 14px auto; }
        .jb-footer small { color: var(--muted); }

        /* ---------- Responsive ---------- */
        @media (max-width: 1000px) {
            .hero-grid { grid-template-columns: minmax(0, 1fr); }
            .overview { grid-template-columns: minmax(0, 1fr); }
            .recs, .cat-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
            .steps { grid-template-columns: 1fr 1fr; }
            .step::after { display: none; }
            /* tablets: the menu moves to its own row */
            .jb-nav { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .jb-links { order: 3; width: 100%; overflow-x: auto; padding-bottom: 4px; }
            .jb-link { white-space: nowrap; }
        }
        @media (max-width: 760px) {
            .wrap { padding: 0 16px; }
            .band { padding: 48px 0; }
            .band.tight { padding: 32px 0 40px; }
            .hero { padding: 28px 0 32px; }
            .hero h1 { font-size: 1.9rem; }
            .section-head { flex-direction: column; align-items: flex-start; }
            .section-head h2 { font-size: 1.6rem; }
            .recs, .cat-grid { grid-template-columns: minmax(0, 1fr); }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .profile-row { flex-wrap: wrap; }
            .cta-band h2 { font-size: 1.6rem; }
            .btn-ghost { margin-left: 0; margin-top: 10px; }
            .ring.lg { width: 88px; height: 88px; --w: 9px; } .ring.lg b { font-size: 1.05rem; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } html { scroll-behavior: auto; } }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="jb-nav">
        <a href="dashboard.php"><img src="Logo.jpg" alt="JobiT Logo" class="jb-logo"></a>

        <nav class="jb-links">
            <a href="dashboard.php" class="jb-link active">Home</a>
            <a href="browse.php" class="jb-link">Browse</a>
            <a href="my-listings.php" class="jb-link">My Listings</a>
            <a href="matches.php" class="jb-link">Matches</a>
            <a href="profile.php" class="jb-link">Profile</a>
            <a href="post-listing.php" class="jb-link cta">+ Post a Listing</a>
        </nav>

        <div class="profile-dropdown-container">
            <button onclick="toggleDropdown()" class="profile-avatar-btn" id="avatarBtn" aria-label="Open profile menu">
                <?php if (!empty($userAvatar) && file_exists($userAvatar)): ?>
                    <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitials); ?>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu" id="profileDropdown">
                <a href="profile.php">My Profile</a>
                <a href="profile.php?section=settings">Account Settings</a>
                <a href="index.php" class="dropdown-logout">Log Out</a>
            </div>
        </div>
    </header>

    <main>

        <!-- 1. BLUE HERO: hello + your #1 match right now -->
        <section class="hero band--blue">
            <div class="wrap hero-grid">
                <div class="hero-text">
                    <span class="hero-tag">&#9733; Welcome back</span>
                    <h1>Good to see you, <span><?php echo htmlspecialchars($firstName); ?></span>!</h1>
                    <p>JobIT matches local gigs, part-time and full-time jobs to your skills, schedule and city, best match first.</p>
                    <a href="browse.php" class="btn btn-yellow">Browse Jobs &rarr;</a>
                    <a href="post-listing.php" class="btn btn-ghost">Post a Listing</a>
                    <div class="hero-stats">
                        <div class="hero-stat"><b><?php echo $openGigCount; ?></b><span>open gigs for you</span></div>
                        <div class="hero-stat"><b><?php echo $greatMatchCount; ?></b><span>great matches (75%+)</span></div>
                    </div>
                </div>

                <div class="topcard" aria-label="Your number one match">
                    <?php if ($topMatch): ?>
                        <?php $tApplied = isset($appliedSet[(int)$topMatch['id']]); ?>
                        <div class="topcard-head">
                            <span class="ring lg" style="--p: <?php echo round($topMatch['match_score']); ?>;"><b><?php echo $topMatch['match_score']; ?>%</b></span>
                            <div>
                                <small>&#9733; Your #1 match today</small>
                                <span class="tier"><?php echo dbTierLabel($topMatch['match_score']); ?></span>
                            </div>
                        </div>
                        <div class="topcard-body">
                            <div class="tags">
                                <span class="chip"><?php echo htmlspecialchars($topMatch['category']); ?></span>
                                <?php if ($tApplied): ?><span class="applied">&#10003; Applied</span><?php endif; ?>
                                <?php if (isWeakSkillMatch($topMatch['why'])): ?><span class="weak" title="You have <?php echo $topMatch['why']['skills_have']; ?> of <?php echo $topMatch['why']['skills_total']; ?> required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span><?php endif; ?>
                            </div>
                            <h3><?php echo htmlspecialchars($topMatch['title']); ?></h3>
                            <div class="facts">
                                <span class="price">&#8369;<?php echo number_format($topMatch['budget_min']); ?> - &#8369;<?php echo number_format($topMatch['budget_max']); ?></span>
                                <span><?php echo htmlspecialchars($topMatch['location']); ?></span>
                            </div>
                            <div class="splitbar" role="img" aria-label="Skills <?php echo dbPts($topMatch['why']['skills_points']); ?>, Location <?php echo dbPts($topMatch['why']['location_points']); ?>, Availability <?php echo dbPts($topMatch['why']['availability_points']); ?> out of 100">
                                <?php foreach ($scoreParts as $p): ?><i class="<?php echo $p[0]; ?>" style="width: <?php echo $topMatch['why'][$p[2]]; ?>%;"></i><?php endforeach; ?>
                            </div>
                            <div class="split-keys">
                                <?php foreach ($scoreParts as $p): ?><span><i class="dot <?php echo $p[0]; ?>"></i><?php echo $p[1]; ?> <?php echo dbPts($topMatch['why'][$p[2]]); ?>/<?php echo $p[3]; ?></span><?php endforeach; ?>
                            </div>
                            <a href="browse.php?inspect=<?php echo (int)$topMatch['id']; ?>" class="go-btn"><?php echo $tApplied ? 'View Details' : 'View &amp; Apply'; ?> &rarr;</a>
                        </div>
                    <?php else: ?>
                        <div class="topcard-empty">
                            <b>No open gigs yet</b>
                            New listings from other people will be matched to you here.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 2. SOFT: your profile, numbers and shortcuts -->
        <section class="band band--soft tight">
            <div class="wrap">
                <div class="overview">
                    <div class="card">
                        <div class="profile-row">
                            <div class="avatar">
                                <?php if ($userPic !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($userPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($userInitials); ?>
                                <?php endif; ?>
                            </div>
                            <div class="who">
                                <h4><?php echo htmlspecialchars($userName); ?></h4>
                                <p class="sub">IT Student &bull; <?php echo htmlspecialchars($userLocation); ?></p>
                            </div>
                            <div class="strength">
                                <span class="ring md <?php echo $profileStrength >= 75 ? 'high' : ($profileStrength >= 50 ? 'mid' : 'low'); ?>" style="--p: <?php echo $profileStrength; ?>;"><b><?php echo $profileStrength; ?>%</b></span>
                                <div class="strength-txt">Profile<br>strength<a href="profile.php">Improve &rarr;</a></div>
                            </div>
                        </div>

                        <div class="stats">
                            <a href="my-listings.php" class="stat"><b><?php echo $activeListingsCount; ?></b><span>Active Listings</span></a>
                            <a href="matches.php?tab=applicants" class="stat"><b><?php echo $totalApplicantsCount; ?></b><span>Pending Applicants</span></a>
                            <a href="my-listings.php?tab=completed" class="stat"><b><?php echo $completedJobsCount; ?></b><span>Completed</span></a>
                            <a href="matches.php" class="stat good"><b><?php echo $greatMatchCount; ?></b><span>Great Matches</span></a>
                        </div>

                        <?php
                            // What your scores are based on (your real profile, no made-up values)
                            $mySkillList = array_values(array_filter(array_map('trim', explode(',', (string)$scoreSkills)), 'strlen'));
                            $mySlotList = array_values(array_filter(array_map('trim', explode(',', (string)$scoreSlots)), 'strlen'));
                        ?>
                        <div class="basis">
                            <div class="basis-row"><span class="basis-k"><i class="dot s"></i>Skills</span><div class="basis-v">
                                <?php if (empty($mySkillList)): ?><em>Not set</em><?php else: ?>
                                    <?php foreach (array_slice($mySkillList, 0, 6) as $sk): ?><span class="me-chip"><?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                    <?php if (count($mySkillList) > 6): ?><span class="me-chip more">+<?php echo count($mySkillList) - 6; ?></span><?php endif; ?>
                                <?php endif; ?>
                            </div></div>
                            <div class="basis-row"><span class="basis-k"><i class="dot a"></i>Free on</span><div class="basis-v">
                                <?php if (empty($mySlotList)): ?><em>Not set</em><?php else: ?>
                                    <?php foreach ($mySlotList as $sl): ?><span class="me-chip"><?php echo htmlspecialchars($sl); ?></span><?php endforeach; ?>
                                <?php endif; ?>
                            </div></div>
                        </div>
                    </div>

                    <div class="card actions">
                        <h4>Quick Actions</h4>
                        <a href="post-listing.php"><span>Post a Listing<small>Find people for your gig</small></span><span>&rarr;</span></a>
                        <a href="matches.php"><span>View Matches<small>Jobs ranked for you</small></span><span>&rarr;</span></a>
                        <a href="browse.php"><span>Browse All Jobs<small>Search and filter everything</small></span><span>&rarr;</span></a>
                        <a href="matches.php?tab=applied"><span>My Applications<small>See if you were shortlisted</small></span><span>&rarr;</span></a>
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. WHITE: Recommended for You (live top 3) + browse by type -->
        <section class="band band--white">
            <div class="wrap">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Recommended</span>
                        <h2>Recommended for You</h2>
                        <p class="sub">Your top 3 matches right now, scored on your skills, city and schedule and picked with our min-heap.</p>
                    </div>
                    <a href="matches.php" class="link">See all matches &rarr;</a>
                </div>

                <div class="legend" aria-label="How the match score works">
                    <span class="legend-k">How we score</span>
                    <span><i class="dot s"></i>Skills 50</span>
                    <span><i class="dot l"></i>Location 30</span>
                    <span><i class="dot a"></i>Availability 20</span>
                </div>

                <?php if (empty($nextMatches)): ?>
                    <div class="recs-empty"><b>No recommendations yet</b>When other people post gigs, your best matches will show up here.</div>
                <?php else: ?>
                    <div class="recs">
                        <?php foreach ($nextMatches as $i => $g):
                            $gPic = dbPic($g['employer_avatar'] ?? '');
                            $gApplied = isset($appliedSet[(int)$g['id']]);
                        ?>
                            <a href="browse.php?inspect=<?php echo (int)$g['id']; ?>" class="rec">
                                <div class="rec-top">
                                    <div class="tags">
                                        <span class="rank">#<?php echo $i + 1; ?></span>
                                        <span class="chip"><?php echo htmlspecialchars($g['category']); ?></span>
                                        <?php if ($gApplied): ?><span class="applied">&#10003; Applied</span><?php endif; ?>
                                        <?php if (isWeakSkillMatch($g['why'])): ?><span class="weak" title="You have <?php echo $g['why']['skills_have']; ?> of <?php echo $g['why']['skills_total']; ?> required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span><?php endif; ?>
                                    </div>
                                    <span class="ring sm <?php echo dbTier($g['match_score']); ?>" style="--p: <?php echo round($g['match_score']); ?>;" title="<?php echo dbTierLabel($g['match_score']); ?>"><b><?php echo $g['match_score']; ?>%</b></span>
                                </div>
                                <h3><?php echo htmlspecialchars($g['title']); ?></h3>
                                <div class="facts">
                                    <span class="price">&#8369;<?php echo number_format($g['budget_min']); ?> - &#8369;<?php echo number_format($g['budget_max']); ?></span>
                                    <span><?php echo htmlspecialchars($g['location']); ?></span>
                                </div>
                                <div class="splitbar" role="img" aria-label="Skills <?php echo dbPts($g['why']['skills_points']); ?>, Location <?php echo dbPts($g['why']['location_points']); ?>, Availability <?php echo dbPts($g['why']['availability_points']); ?> out of 100">
                                    <?php foreach ($scoreParts as $p): ?><i class="<?php echo $p[0]; ?>" style="width: <?php echo $g['why'][$p[2]]; ?>%;"></i><?php endforeach; ?>
                                </div>
                                <div class="split-keys">
                                    <?php foreach ($scoreParts as $p): ?><span><i class="dot <?php echo $p[0]; ?>"></i><?php echo $p[1]; ?> <?php echo dbPts($g['why'][$p[2]]); ?>/<?php echo $p[3]; ?></span><?php endforeach; ?>
                                </div>
                                <?php if (!empty($g['skill_chips']['matched'])): ?>
                                    <div class="skills"><?php foreach (array_slice($g['skill_chips']['matched'], 0, 3) as $sk): ?><span class="sk">&#10003; <?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?></div>
                                <?php endif; ?>
                                <div class="rec-foot">
                                    <span class="rec-by">
                                        <span class="avatar xs"><?php if ($gPic !== ''): ?><img src="<?php echo htmlspecialchars($gPic, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars(dbInitials($g['employer_name'])); ?><?php endif; ?></span>
                                        <span>By <?php echo htmlspecialchars($g['employer_name']); ?></span>
                                    </span>
                                    <span class="rec-more">View &rarr;</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="types-head">
                    <h3>Browse by type</h3>
                    <a href="browse.php" class="link">All jobs &rarr;</a>
                </div>
                <div class="cat-grid">
                    <a href="browse.php?work_type=short-gig" class="cat">
                        <div class="cat-img"><img src="ShortGig.png" alt="Short Gig" onerror="this.src='Logo.jpg'"></div>
                        <div class="cat-body">
                            <h4>Short Gig</h4>
                            <p>Quick tasks and flexible side gigs.</p>
                            <span class="cat-btn">Browse Short Gigs &rarr;</span>
                        </div>
                    </a>
                    <a href="browse.php?work_type=part-time" class="cat">
                        <div class="cat-img"><img src="PartTime.png" alt="Part-Time" onerror="this.src='Logo.jpg'"></div>
                        <div class="cat-body">
                            <h4>Part-Time</h4>
                            <p>Regular weekly hours.</p>
                            <span class="cat-btn">Browse Part-Time &rarr;</span>
                        </div>
                    </a>
                    <a href="browse.php?work_type=full-time" class="cat">
                        <div class="cat-img"><img src="FullTime.png" alt="Full-Time" onerror="this.src='Logo.jpg'"></div>
                        <div class="cat-body">
                            <h4>Full-Time</h4>
                            <p>Long-term positions.</p>
                            <span class="cat-btn">Browse Full-Time &rarr;</span>
                        </div>
                    </a>
                </div>
            </div>
        </section>

        <!-- 4. SOFT: How JobiT Works -->
        <section class="band band--soft">
            <div class="wrap">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Simple process</span>
                        <h2>How JobiT Works</h2>
                        <p class="sub">JobIT helps employers find the right people, and helps you find the right gig.</p>
                    </div>
                </div>
                <div class="steps">
                    <div class="step"><div class="step-num">1</div><h4>Search</h4><p>Browse gigs and jobs in your area.</p></div>
                    <div class="step"><div class="step-num">2</div><h4>Match</h4><p>Every gig gets a score from your skills, city and schedule.</p></div>
                    <div class="step"><div class="step-num">3</div><h4>Apply</h4><p>Send your application in one click.</p></div>
                    <div class="step"><div class="step-num">4</div><h4>Get Shortlisted</h4><p>Employers shortlist the best fits and email them directly.</p></div>
                </div>
            </div>
        </section>

        <!-- 5. BLUE: Promo / Call to action -->
        <section class="band band--blue cta-band">
            <div class="wrap">
                <h2>Better profile, better matches.</h2>
                <p>Add your skills, city and free days so JobIT can rank the right gigs for you.</p>
                <a href="profile.php" class="btn btn-yellow">Update Your Profile &rarr;</a>
            </div>
        </section>

    </main>

    <!-- Official Project Footer (white) -->
    <footer class="jb-footer">
        <strong>CITE 004-IT21S2 - Data Structures and Algorithm</strong>
        <p class="team">Hope B. Calunsod &nbsp;|&nbsp; Carlos John L. Rama &nbsp;|&nbsp; Dan Axel M. Tongol</p>
        <hr>
        <small>&copy; 2026 JobiT. All Rights Reserved.</small>
    </footer>

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
