<?php
// ============================================================================
// JOBIT MY LISTINGS & APPLICANT MANAGEMENT ENGINE
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

// A message left by another page (for example "Listing updated successfully!" after editing a listing)
if (!empty($_SESSION['ml_flash'])) {
    $message = $_SESSION['ml_flash'];
    unset($_SESSION['ml_flash']);
}

// HANDLE POST ACTIONS (Delete Listing, Shortlist/Reject Applicant, Complete Job)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $userId) {
    $action = $_POST['action'];

    if ($action === 'delete') {
        $listingId = intval($_POST['listing_id'] ?? 0);
        if ($listingId > 0) {
            $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ? AND user_id = ?");
            $stmt->execute([$listingId, $userId]);
            $message = "Listing and associated applicants deleted successfully!";
        }
    } elseif ($action === 'complete') {
        $listingId = intval($_POST['listing_id'] ?? 0);
        if ($listingId > 0) {
            $stmt = $pdo->prepare("UPDATE listings SET status = 'completed' WHERE id = ? AND user_id = ?");
            $stmt->execute([$listingId, $userId]);
            $message = "Listing marked as completed!";
        }
    } elseif ($action === 'update_application') {
        $appId = intval($_POST['application_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'pending';
        // Safety: only known statuses, and only for applications on listings that belong to the logged-in user
        if ($appId > 0 && in_array($newStatus, ['pending', 'accepted', 'rejected'], true)) {
            $stmt = $pdo->prepare("UPDATE applications SET status = ? WHERE id = ? AND listing_id IN (SELECT id FROM listings WHERE user_id = ?)");
            $stmt->execute([$newStatus, $appId, $userId]);
            // JobIT only helps employers FIND candidates, so the messages talk about shortlisting, not hiring
            $messages = ['accepted' => 'Applicant shortlisted!', 'rejected' => 'Applicant moved to Rejected.', 'pending' => 'Applicant moved back to Pending.'];
            $message = $messages[$newStatus];
        }
    }
}

// FETCH USER'S LISTINGS & APPLICANTS WITH SYNCHRONIZED SCORING ALGORITHM
$myListings = [];
$activeCount = 0;
$completedCount = 0;
$totalApplicantsCount = 0;

if ($userId) {
    try {
        $listStmt = $pdo->prepare("SELECT * FROM listings WHERE user_id = ? ORDER BY created_at DESC");
        $listStmt->execute([$userId]);
        $dbListings = $listStmt->fetchAll();

        foreach ($dbListings as $row) {
            // ALL applicants of this listing (pending, shortlisted and rejected), so the popup can show every group.
            // (A shortlisted applicant is saved in the database with the status 'accepted'.)
            // The popup has Pending / Shortlisted / Rejected tabs and filters this one list.
            $appStmt = $pdo->prepare("
                SELECT a.*, u.full_name as applicant_name, u.email as applicant_email, u.location as applicant_location, u.skills as applicant_skills, u.available_slots as applicant_available_slots, u.avatar as applicant_avatar 
                FROM applications a 
                JOIN users u ON a.applicant_id = u.id 
                WHERE a.listing_id = ?
                ORDER BY a.applied_at DESC
            ");
            $appStmt->execute([$row['id']]);
            $applicants = $appStmt->fetchAll();

            // UNIFIED MATCH SCORING: the same formula as browse.php and matches.php (db.php).
            // getScoreBreakdown() returns the three parts; its 'total' is exactly what calculateMatchScore() returns.
            $statusCounts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
            foreach ($applicants as &$app) {
                $app['skills_array'] = !empty($app['applicant_skills']) ? array_map('trim', explode(',', $app['applicant_skills'])) : ['General'];

                $app['why'] = getScoreBreakdown(
                    $row['skills_required'] ?? '',
                    $row['location'] ?? '',
                    $row['schedule'] ?? '',
                    $app['applicant_skills'] ?? '',
                    $app['applicant_location'] ?? '',
                    $app['applicant_available_slots'] ?? ''
                );
                $app['match_score'] = $app['why']['total'];
                $app['skill_chips'] = getSkillsBreakdown($row['skills_required'] ?? '', $app['applicant_skills'] ?? '');

                // Only three statuses exist; anything else is treated as pending
                $app['status'] = in_array($app['status'] ?? '', ['accepted', 'rejected'], true) ? $app['status'] : 'pending';
                $statusCounts[$app['status']]++;
            }
            unset($app);

            // Our own merge sort (sorting.php): best applicant first, equal scores keep their order.
            // Every tab of the popup is filtered from this one sorted list, so each tab is also best match first.
            $applicants = mergeSortByKey($applicants, 'match_score');

            $row['applicants_list'] = $applicants;
            $row['pending_count'] = $statusCounts['pending'];
            $row['accepted_count'] = $statusCounts['accepted'];
            $row['rejected_count'] = $statusCounts['rejected'];
            // The number on the card: pending applicants for an active listing, shortlisted candidates for a completed one
            $row['applicant_count'] = ($row['status'] === 'completed') ? $statusCounts['accepted'] : $statusCounts['pending'];
            
            if ($row['status'] === 'active') {
                $activeCount++;
                $totalApplicantsCount += $row['applicant_count'];
            } else {
                $completedCount++;
            }

            $myListings[] = $row;
        }
    } catch (Exception $e) {}
}

$currentTab = $_GET['tab'] ?? 'active';
$filteredListings = array_filter($myListings, function($item) use ($currentTab) {
    if ($currentTab === 'active') return $item['status'] === 'active';
    if ($currentTab === 'completed') return $item['status'] === 'completed';
    return true;
});

// ============================================================================
// DISPLAY HELPERS (design only: nothing here changes the data or the scoring)
// ============================================================================

// Color tier for a match score (same rule as browse.php)
if (!function_exists('matchTier')) {
    function matchTier($score) {
        return $score >= 75 ? 'high' : ($score >= 50 ? 'mid' : 'low');
    }
}

// Two-letter initials for the applicant avatar
if (!function_exists('initialsOf')) {
    function initialsOf($name) {
        $initials = '';
        foreach (explode(' ', trim((string)$name)) as $w) {
            if ($w !== '') $initials .= strtoupper(mb_substr($w, 0, 1));
        }
        return mb_substr($initials, 0, 2) ?: 'U';
    }
}

// The applicant's CURRENT profile picture (read from the database every time, '' = show initials instead)
// The ?v=time part makes the browser load the new picture right after the applicant changes it.
if (!function_exists('mlPicUrl')) {
    function mlPicUrl($path) {
        $path = (string)$path;
        if ($path === '' || !file_exists($path)) return '';
        return $path . '?v=' . filemtime($path);
    }
}

// Applicant list -> small JSON packet the "Applicants" popup reads (only the fields the popup needs)
if (!function_exists('applicantsPayload')) {
    function applicantsPayload($applicants) {
        $out = [];
        foreach ($applicants as $a) {
            $out[] = [
                'id'       => (int)($a['id'] ?? 0),
                'status'   => (string)($a['status'] ?? 'pending'),
                'name'     => (string)($a['applicant_name'] ?? ''),
                'email'    => (string)($a['applicant_email'] ?? ''),
                'location' => (string)($a['applicant_location'] ?? ''),
                'pic'      => mlPicUrl($a['applicant_avatar'] ?? ''),   // profile picture ('' = show initials)
                'skills'   => array_values($a['skills_array'] ?? []),
                'score'    => round($a['match_score'] ?? 0, 2),   // same 2-decimal percent as the other pages
                'why'      => $a['why'] ?? null,                    // the three parts of the score (for "Why this score?")
                'matched'  => array_values($a['skill_chips']['matched'] ?? []),
                'missing'  => array_values($a['skill_chips']['missing'] ?? []),
                'applied'  => !empty($a['applied_at']) ? date('M d, Y', strtotime($a['applied_at'])) : '',
            ];
        }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return htmlspecialchars($json === false ? '[]' : $json, ENT_QUOTES, 'UTF-8');
    }
}

// Short description for the card (safe for accented / emoji text too)
if (!function_exists('shortText')) {
    function shortText($text, $max = 140) {
        $text = trim((string)$text);
        if (mb_strlen($text) <= $max) return $text;
        return rtrim(mb_substr($text, 0, $max)) . '...';
    }
}

// Keeps the same tab after Mark Completed / Delete / Shortlist / Reject (also works without JavaScript)
$formAction = 'my-listings.php?tab=' . rawurlencode($currentTab);
$pinIcon = '<svg class="ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4154f1">
    <title>JobiT - My Listings</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ---------- Brand tokens: blue + white, yellow only as a small accent ---------- */
        :root {
            --blue: #4154f1; --deep: #2938b7; --soft: #eef1ff; --tint: #f6f8ff; --yellow: #ffe600;
            --ink: #161b3a; --muted: #667085; --line: #e4e8f7; --white: #ffffff;
            --good: #1e8e3e; --good-bg: #e6f9ed; --warn: #a86b00; --warn-bg: #fff5da; --bad: #d9534f; --bad-bg: #fff0f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        html.ml-lock, html.ml-lock body { overflow: hidden; }
        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
            background: var(--white); color: var(--ink); line-height: 1.55;
            min-height: 100vh; display: flex; flex-direction: column;
        }
        input, select, button { font: inherit; color: inherit; }
        a { color: inherit; text-decoration: none; }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--blue); outline-offset: 2px; }

        /* Page: white nav, blue title header, soft listings area (no footer) */
        .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 32px; }

        /* ---------- Navbar (white) ---------- */
        .jb-nav {
            position: sticky; top: 0; z-index: 50; display: flex; align-items: center;
            justify-content: space-between; gap: 24px; padding: 12px 40px;
            background: rgba(255,255,255,0.94); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        .jb-logo { height: 40px; display: block; }
        .jb-links { display: flex; align-items: center; gap: 4px; }
        .jb-link { padding: 8px 16px; border-radius: 999px; font-weight: 600; font-size: 0.93rem; color: #3b4160; transition: background .2s, color .2s; }
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
            display: none; position: absolute; right: 0; top: 50px; min-width: 190px; background: #fff;
            border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 16px 36px rgba(22,27,58,0.14); overflow: hidden; z-index: 1000;
        }
        .dropdown-menu a { display: block; padding: 12px 18px; font-size: 0.93rem; color: #333; }
        .dropdown-menu a:hover { background: var(--soft); color: var(--blue); }
        .dropdown-menu .dropdown-logout { color: #e5484d; border-top: 1px solid var(--line); }
        .show-dropdown { display: block !important; }

        /* ---------- Toast (small message after an action, no page reload) ---------- */
        .toast {
            position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px);
            background: var(--ink); color: #fff; padding: 14px 24px; border-radius: 999px;
            font-weight: 700; font-size: 0.92rem; text-align: center; max-width: calc(100vw - 32px);
            box-shadow: 0 16px 36px rgba(10,16,70,0.3); opacity: 0; pointer-events: none;
            transition: opacity .25s, transform .25s; z-index: 3000;
        }
        .toast.show { opacity: 1; transform: translate(-50%, 0); }
        .toast.ok { background: var(--good); }
        .toast.err { background: var(--bad); }

        /* ---------- 1. Header (JobiT brand blue: soft round shapes + a small yellow accent, like the logo) ---------- */
        .pg-head { position: relative; overflow: hidden; background: var(--blue); color: #fff; padding: 22px 0 28px; }
        .pg-head::before, .pg-head::after { content: ""; position: absolute; border-radius: 50%; pointer-events: none; }
        .pg-head::before { width: 340px; height: 340px; right: -90px; top: -150px; background: rgba(255,255,255,0.08); }
        .pg-head::after { width: 210px; height: 210px; right: 170px; bottom: -130px; border: 2px solid rgba(255,255,255,0.16); }
        .pg-head .wrap { position: relative; z-index: 1; }
        .pg-head a:focus-visible { outline-color: var(--yellow); }
        .ml-crumbs { font-size: 0.8rem; font-weight: 600; color: #e9ecff; margin-bottom: 6px; }
        .ml-crumbs a:hover { color: #fff; text-decoration: underline; }
        .ml-crumbs strong { color: #fff; font-weight: 700; }
        .top-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px 24px; }
        .pg-head h1 { font-size: 2rem; line-height: 1.15; letter-spacing: -0.025em; font-weight: 800; margin-bottom: 8px; }
        .pg-head h1::after { content: ""; display: block; width: 44px; height: 4px; border-radius: 4px; background: var(--yellow); margin-top: 8px; }
        .pg-head .lead { color: #eef1ff; font-size: 0.95rem; max-width: 760px; }
        .jb-btn-white { display: inline-block; padding: 10px 22px; border-radius: 999px; background: #fff; color: var(--blue); font-weight: 800; font-size: 0.9rem; transition: transform .2s, box-shadow .2s; }
        .jb-btn-white:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(10,16,70,0.28); }

        /* main fills the whole screen height, so the soft background reaches the bottom even with only a few listings */
        main { flex: 1; display: flex; flex-direction: column; }

        /* ---------- 2. Listings (very light blue-white background, white cards) ---------- */
        .ml-main { background: var(--tint); padding: 24px 0 72px; flex: 1; }
        #ml-body { transition: opacity .2s .12s; }
        #ml-body.loading { opacity: 0.5; pointer-events: none; }

        .ml-flash { display: flex; align-items: center; gap: 10px; background: var(--good-bg); color: var(--good); padding: 13px 18px; border-radius: 14px; margin-bottom: 18px; font-weight: 700; font-size: 0.93rem; }
        .ml-flash::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--good); flex-shrink: 0; }

        .ml-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 20px; margin-bottom: 18px; }
        .ml-tabs { display: inline-flex; gap: 4px; background: #fff; border-radius: 999px; padding: 4px; box-shadow: inset 0 0 0 1.5px #cdd4fc; }
        .ml-tab { padding: 9px 22px; border-radius: 999px; font-weight: 700; font-size: 0.9rem; color: var(--blue); transition: background .2s, color .2s; white-space: nowrap; }
        .ml-tab:hover { background: var(--soft); }
        .ml-tab.on { background: var(--blue); color: #fff; }
        .ml-count { color: var(--muted); font-size: 0.88rem; font-weight: 600; }
        .ml-count b { color: var(--blue); font-weight: 800; }

        .ml-list { display: flex; flex-direction: column; gap: 14px; }
        .ml-card {
            --accent: var(--blue);
            background: #fff; border: 1.5px solid #cdd4fc; border-radius: 16px; padding: 22px 26px;
            display: flex; justify-content: space-between; align-items: center; gap: 28px;
            box-shadow: inset 4px 0 0 var(--accent);
            transition: border-color .2s, box-shadow .2s, opacity .2s;
        }
        .ml-card:has(.ml-status.completed) { --accent: #c3c8d8; }
        .ml-card:hover { border-color: var(--blue); box-shadow: inset 4px 0 0 var(--accent), 0 12px 28px rgba(65,84,241,0.10); }
        .ml-card.is-busy { opacity: 0.55; pointer-events: none; }
        .ml-info { flex: 1; min-width: 0; }
        .ml-top { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-bottom: 8px; }
        .chip { display: inline-block; background: var(--soft); color: var(--blue); padding: 3px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .ml-date { font-size: 0.82rem; color: var(--muted); }
        .ml-status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
        .ml-status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .ml-status.active { color: var(--good); }
        .ml-status.completed { color: var(--muted); }
        .ml-title { font-size: 1.2rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.3; margin-bottom: 6px; }
        .ml-title a:hover { color: var(--blue); }
        .ml-desc { color: #4b5263; font-size: 0.93rem; line-height: 1.55; margin-bottom: 12px; overflow-wrap: anywhere; }
        .ml-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 22px; font-size: 0.86rem; color: var(--muted); }
        .ml-price { color: var(--blue); font-weight: 800; font-size: 1rem; }
        .ml-who { color: var(--ink); font-weight: 700; }
        .ico { vertical-align: -2px; margin-right: 4px; }

        .ml-actions { display: flex; flex-direction: column; gap: 10px; min-width: 232px; }
        .ml-act-row { display: flex; gap: 8px; }
        .ml-btn {
            flex: 1; display: inline-flex; align-items: center; justify-content: center; padding: 11px 18px; border-radius: 999px;
            border: 1.5px solid transparent; font-weight: 800; font-size: 0.86rem; cursor: pointer; white-space: nowrap;
            transition: background .2s, color .2s, border-color .2s, transform .2s;
        }
        .ml-btn:hover { transform: translateY(-1px); }
        .ml-btn-primary { background: var(--blue); color: #fff; }
        .ml-btn-primary:hover { background: var(--deep); }
        .ml-btn-ghost { background: #fff; color: var(--blue); border-color: var(--blue); }
        .ml-btn-ghost:hover { background: var(--soft); border-color: var(--blue); }
        .ml-btn-good { background: var(--good); color: #fff; }
        .ml-btn-good:hover { background: #17722f; }
        .ml-btn-danger { background: #fff; color: var(--bad); border-color: #f1c4c2; }
        .ml-btn-danger:hover { background: var(--bad-bg); border-color: var(--bad); }
        .ml-btn-solid-danger { background: var(--bad); color: #fff; }
        .ml-btn-solid-danger:hover { background: #bf3f3b; }
        .ml-btn:disabled { opacity: 0.6; cursor: wait; transform: none; }
        /* Second row (Edit / Mark Completed / Delete): one soft grouped bar, the same width as the buttons above.
           Every item is the same height and centred, so nothing floats higher or lower than the others. */
        .ml-act-links { display: flex; align-items: stretch; gap: 3px; padding: 3px; background: var(--tint); border: 1px solid var(--line); border-radius: 12px; }
        .ml-act-links > * { flex: 1 1 auto; }
        .ml-form { display: flex; margin: 0; }
        .ml-link {
            display: flex; align-items: center; justify-content: center; width: 100%; height: 32px; margin: 0; padding: 0 12px;
            background: none; border: none; border-radius: 9px; font-family: inherit; font-size: 0.8rem; font-weight: 700;
            line-height: 1; white-space: nowrap; cursor: pointer; text-decoration: none; vertical-align: middle;
            transition: background .2s, color .2s;
        }
        .ml-link:hover { background: #fff; text-decoration: none; box-shadow: 0 1px 3px rgba(22,27,58,0.08); }
        .ml-link.good { color: var(--good); }
        .ml-link.bad { color: #8d94a8; }
        .ml-link.edit { color: var(--blue); text-decoration: none; }
        .ml-link.bad:hover { color: var(--bad); background: var(--bad-bg); }
        .ml-link:disabled { opacity: 0.5; cursor: wait; }

        .ml-empty { background: #fff; border: 1.5px dashed #b9c1fb; border-radius: 16px; padding: 56px 30px; text-align: center; color: var(--muted); }
        .ml-empty h3 { font-size: 1.2rem; color: var(--ink); margin-bottom: 6px; }
        .ml-empty .ml-btn { flex: none; margin-top: 18px; }

        /* match score tiers (same colors as browse.php) */
        .score { padding: 4px 12px; border-radius: 999px; font-weight: 800; font-size: 0.8rem; white-space: nowrap; }
        .score.high { background: var(--good-bg); color: var(--good); }
        .score.mid { background: var(--warn-bg); color: var(--warn); }
        .score.low { background: #eef0f5; color: var(--muted); }

        /* ---------- Popups (applicants + confirm) ---------- */
        .ml-overlay {
            display: none; position: fixed; inset: 0; z-index: 2000; padding: 20px;
            background: rgba(22,27,58,0.55); justify-content: center; align-items: center;
        }
        .ml-overlay.open { display: flex; }
        #confirmModal { z-index: 2500; }
        .ml-modal {
            background: #fff; border-radius: 24px; width: 100%; max-width: 720px; max-height: calc(100vh - 40px);
            display: flex; flex-direction: column; overflow: hidden; box-shadow: inset 0 4px 0 var(--blue), 0 30px 70px rgba(10,16,70,0.35);
        }
        .ml-modal.small { max-width: 420px; padding: 30px; text-align: center; }
        .ml-modal.small h3 { font-size: 1.2rem; font-weight: 800; margin-bottom: 8px; }
        .ml-modal.small p { color: var(--muted); font-size: 0.93rem; margin-bottom: 22px; }
        .ml-modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 24px 28px 18px; border-bottom: 1px solid var(--line); }
        .ml-eyebrow { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--blue); margin-bottom: 2px; }
        .ml-modal-head h3 { font-size: 1.25rem; font-weight: 800; line-height: 1.3; letter-spacing: -0.01em; overflow-wrap: anywhere; }
        .ml-x { flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%; border: none; background: var(--soft); color: var(--blue); font-size: 1.4rem; line-height: 1; cursor: pointer; transition: background .2s; }
        .ml-x:hover { background: #dfe5ff; }
        .ml-modal-body { padding: 20px 28px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
        .ml-modal-foot { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 14px 28px 20px; border-top: 1px solid var(--line); }
        .ml-hint { font-size: 0.8rem; color: var(--muted); }
        .ml-modal-foot .ml-btn, .ml-confirm-row .ml-btn { flex: none; }
        .ml-confirm-row { display: flex; justify-content: center; gap: 10px; }
        .ml-confirm-row .ml-btn { min-width: 110px; }
        .ml-none { text-align: center; color: var(--muted); padding: 28px 10px; }
        .ml-none b { display: block; color: var(--ink); font-size: 1.05rem; margin-bottom: 4px; }

        /* Email draft shown right after Shortlist & Email */
        .ml-mail { border: 1.5px solid #b7ebc6; background: #f4fcf6; border-radius: 18px; padding: 18px 20px; }
        .ml-mail-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .ml-mail-ok { width: 34px; height: 34px; border-radius: 50%; background: var(--good); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ml-mail-head b { display: block; font-size: 1rem; }
        .ml-mail-head span { font-size: 0.85rem; color: var(--muted); }
        .ml-mail label { display: block; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin: 10px 0 4px; }
        .ml-mail input, .ml-mail textarea { width: 100%; padding: 10px 14px; border: 1.5px solid #cfe9d6; border-radius: 12px; background: #fff; font-size: 0.92rem; outline: none; }
        .ml-mail input:focus, .ml-mail textarea:focus { border-color: var(--blue); }
        .ml-mail textarea { resize: vertical; line-height: 1.5; }
        .ml-mail-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 14px; }
        .ml-mail-actions .ml-btn { flex: none; padding: 9px 18px; }
        .ml-mail-actions .ml-link { margin-left: auto; width: auto; color: var(--muted); }

        .ap { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border: 1.5px solid #d5dbfd; border-radius: 16px; background: #fff; }
        .ap.is-busy { opacity: 0.55; pointer-events: none; }
        .ap-avatar { width: 46px; height: 46px; border-radius: 50%; background: var(--blue); color: #fff; font-weight: 800; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .ap-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ap-main { flex: 1; min-width: 0; }
        .ap-name { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; margin-bottom: 2px; }
        .weak { background: #fff5da; color: #8a5a00; padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; white-space: nowrap; }
        .ap-name h4 { font-size: 1.02rem; font-weight: 800; overflow-wrap: anywhere; }
        .ap-meta { font-size: 0.85rem; color: var(--muted); overflow-wrap: anywhere; }
        .ap-skills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .ap-skills span { font-size: 0.75rem; font-weight: 700; background: var(--soft); color: var(--blue); padding: 3px 10px; border-radius: 999px; }
        .ap-actions { display: flex; flex-direction: column; gap: 8px; flex-shrink: 0; }
        .ap-actions form { margin: 0; }
        .ap-actions .ml-btn { width: 100%; padding: 9px 16px; }
        .ap-short { background: var(--good-bg); color: var(--good); border: 1px solid #b7ebc6; padding: 6px 16px; border-radius: 999px; font-weight: 800; font-size: 0.85rem; }
        .ap-tag { background: #eef0f5; color: var(--muted); border: 1px solid #dfe3ee; padding: 6px 16px; border-radius: 999px; font-weight: 800; font-size: 0.85rem; white-space: nowrap; text-align: center; }
        .ap-tag.no { background: var(--bad-bg); color: var(--bad); border-color: #f1c4c2; }

        /* Pending / Shortlisted / Rejected tabs inside the applicants popup */
        .ml-subtabs-wrap { padding: 14px 28px 0; }
        .ml-subtabs { display: inline-flex; gap: 4px; background: #fff; border-radius: 999px; padding: 4px; box-shadow: inset 0 0 0 1.5px #cdd4fc; max-width: 100%; }
        .ml-subtab { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 7px 16px; border: none; border-radius: 999px; background: transparent; color: var(--blue); font: inherit; font-weight: 700; font-size: 0.86rem; cursor: pointer; white-space: nowrap; transition: background .2s, color .2s; }
        .ml-subtab:hover { background: var(--soft); }
        .ml-subtab.on { background: var(--blue); color: #fff; }
        .ml-subtab b { min-width: 20px; padding: 1px 7px; border-radius: 999px; background: var(--soft); color: var(--blue); font-size: 0.74rem; font-weight: 800; text-align: center; }
        .ml-subtab.on b { background: rgba(255,255,255,0.22); color: #fff; }

        /* "Why this score?" under each applicant */
        .ap { flex-wrap: wrap; row-gap: 14px; }
        .ap-why-btn { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; padding: 0; border: none; background: none; color: var(--blue); font: inherit; font-weight: 800; font-size: 0.82rem; cursor: pointer; }
        .ap-why-btn:hover { text-decoration: underline; }
        .ap-why-btn svg { transition: transform .2s; }
        .ap-why-btn[aria-expanded="true"] svg { transform: rotate(180deg); }
        .ap-why { flex: 1 1 100%; background: var(--tint); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; }
        .ap-why[hidden] { display: none; }
        .aw-row + .aw-row { margin-top: 12px; }
        .aw-top { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-weight: 700; font-size: 0.93rem; }
        .aw-pts { color: var(--blue); white-space: nowrap; }
        .aw-pts small { color: var(--muted); font-weight: 700; font-size: 0.82rem; }
        .aw-bar { height: 6px; border-radius: 999px; background: #e4e8f7; margin: 6px 0 4px; overflow: hidden; }
        .aw-bar span { display: block; height: 100%; border-radius: 999px; background: var(--blue); }
        .aw-note { font-size: 0.82rem; color: var(--muted); }
        .aw-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .aw-chip { font-size: 0.75rem; font-weight: 700; padding: 2px 10px; border-radius: 999px; }
        .aw-chip.ok { background: var(--good-bg); color: var(--good); }
        .aw-chip.no { background: var(--bad-bg); color: var(--bad); }
        .aw-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--line); font-weight: 800; }
        .aw-total b { font-size: 1.1rem; color: var(--blue); }
        .ml-sub { font-size: 0.82rem; color: var(--muted); font-weight: 600; }


        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            .ml-card { flex-direction: column; align-items: stretch; gap: 18px; }
            .ml-actions { min-width: 0; }
        }
        @media (max-width: 760px) {
            .jb-nav { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .jb-links { order: 3; width: 100%; overflow-x: auto; padding-bottom: 4px; }
            .jb-link { white-space: nowrap; }
            .wrap { padding: 0 20px; }
            .pg-head { padding: 18px 0 22px; }
            .pg-head::before { width: 220px; height: 220px; right: -90px; top: -110px; }
            .pg-head::after { display: none; }
            .pg-head h1 { font-size: 1.55rem; }
            .ml-main { padding: 22px 0 56px; }
            .ml-card { padding: 18px 18px; }
            .ml-tabs { width: 100%; }
            .ml-tab { flex: 1; text-align: center; padding: 9px 10px; }
            .ml-modal-head, .ml-modal-body, .ml-modal-foot { padding-left: 18px; padding-right: 18px; }
            .ap { flex-direction: column; align-items: stretch; }
            .ap-avatar { display: none; }
            .ap-actions { flex-direction: row; flex-wrap: wrap; }
            .ap-actions form, .ap-actions > .ml-btn, .ap-actions > .ap-tag, .ap-actions > .ap-short { flex: 1; }
            .ml-subtabs-wrap { padding: 14px 18px 0; }
            .ml-subtabs { width: 100%; }
            .ml-subtab { flex: 1 1 0; min-width: 0; padding: 7px 4px; gap: 5px; font-size: 0.8rem; }
            .ml-subtab b { min-width: 18px; padding: 1px 6px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } html { scroll-behavior: auto; } }
    </style>
</head>
<body data-me="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Top Navigation Bar -->
    <header class="jb-nav">
        <a href="dashboard.php"><img src="Logo.jpg" alt="JobiT Logo" class="jb-logo"></a>

        <nav class="jb-links">
            <a href="dashboard.php" class="jb-link">Home</a>
            <a href="browse.php" class="jb-link">Browse</a>
            <a href="my-listings.php" class="jb-link active">My Listings</a>
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

        <!-- 1. BLUE: title header with the Post button -->
        <section class="pg-head">
            <div class="wrap">
                <div class="ml-crumbs"><a href="dashboard.php">Home</a> &rsaquo; <strong>My Listings</strong></div>

                <div class="top-row">
                    <div>
                        <h1>Your listings, all in one place</h1>
                        <p class="lead">Track every gig you have posted, review applicants ranked by match score, and find the best fit faster.</p>
                    </div>
                    <a href="post-listing.php" class="jb-btn-white">+ Post New Listing</a>
                </div>
            </div>
        </section>

        <!-- 2. SOFT: Tabs + listings (this part is swapped in place, no page reload) -->
        <section class="ml-main">
            <div class="wrap" id="ml-body" data-tab="<?php echo htmlspecialchars($currentTab); ?>">

                <?php if (!empty($message)): ?>
                    <div class="ml-flash" role="status"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="ml-bar">
                    <nav class="ml-tabs" aria-label="Listing status">
                        <a href="my-listings.php?tab=active" class="ml-tab<?php echo $currentTab === 'active' ? ' on' : ''; ?>" data-ajax<?php echo $currentTab === 'active' ? ' aria-current="page"' : ''; ?>>
                            Active Listings (<?php echo $activeCount; ?>)
                        </a>
                        <a href="my-listings.php?tab=completed" class="ml-tab<?php echo $currentTab === 'completed' ? ' on' : ''; ?>" data-ajax<?php echo $currentTab === 'completed' ? ' aria-current="page"' : ''; ?>>
                            Completed Jobs (<?php echo $completedCount; ?>)
                        </a>
                    </nav>
                    <p class="ml-count"><b><?php echo $totalApplicantsCount; ?></b> pending <?php echo $totalApplicantsCount === 1 ? 'applicant' : 'applicants'; ?> across your active listings</p>
                </div>

                <div class="ml-list" id="ml-list">
                    <?php if (empty($filteredListings)): ?>
                        <div class="ml-empty">
                            <?php if ($currentTab === 'completed'): ?>
                                <h3>No completed jobs yet</h3>
                                <p>Once you have found the people you need, mark the listing as completed and it will show up here.</p>
                                <a href="my-listings.php?tab=active" class="ml-btn ml-btn-primary" data-ajax>Back to active listings</a>
                            <?php else: ?>
                                <h3>No listings found in this tab</h3>
                                <p>Post a new gig to start receiving candidates.</p>
                                <a href="post-listing.php" class="ml-btn ml-btn-primary">+ Post New Listing</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($filteredListings as $item): ?>
                            <?php $isDone = ($item['status'] === 'completed'); ?>
                            <article class="ml-card">
                                <div class="ml-info">
                                    <div class="ml-top">
                                        <span class="chip"><?php echo htmlspecialchars(ucfirst($item['category'])); ?></span>
                                        <span class="ml-date">Posted on <?php echo date('M d, Y', strtotime($item['created_at'])); ?></span>
                                        <span class="ml-status <?php echo $isDone ? 'completed' : 'active'; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                                    </div>

                                    <h3 class="ml-title"><a href="listing-details.php?id=<?php echo (int)$item['id']; ?>&amp;from=my"><?php echo htmlspecialchars($item['title']); ?></a></h3>
                                    <p class="ml-desc"><?php echo htmlspecialchars(shortText($item['description'], 140)); ?></p>

                                    <div class="ml-meta">
                                        <span class="ml-price">₱<?php echo number_format($item['budget_min']); ?> - ₱<?php echo number_format($item['budget_max']); ?></span>
                                        <span><?php echo $pinIcon; ?><?php echo htmlspecialchars($item['location']); ?></span>
                                        <span class="ml-who"><?php echo $isDone ? 'Shortlisted:' : 'Pending Applicants:'; ?> <?php echo intval($item['applicant_count']); ?></span>
                                        <?php
                                            // A quiet line with the other groups, so you can see at a glance who you already decided on
                                            $otherCounts = [];
                                            if ($isDone && $item['pending_count'] > 0) { $otherCounts[] = intval($item['pending_count']) . ' not reviewed'; }
                                            if (!$isDone && $item['accepted_count'] > 0) { $otherCounts[] = intval($item['accepted_count']) . ' shortlisted'; }
                                            if ($item['rejected_count'] > 0) { $otherCounts[] = intval($item['rejected_count']) . ' rejected'; }
                                        ?>
                                        <?php if (!empty($otherCounts)): ?><span class="ml-sub"><?php echo htmlspecialchars(implode(' · ', $otherCounts)); ?></span><?php endif; ?>
                                    </div>
                                </div>

                                <div class="ml-actions">
                                    <div class="ml-act-row">
                                        <a href="listing-details.php?id=<?php echo (int)$item['id']; ?>&amp;from=my" class="ml-btn ml-btn-ghost">View</a>

                                        <button type="button" class="ml-btn ml-btn-primary"
                                                data-open-applicants="<?php echo (int)$item['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="<?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-applicants="<?php echo applicantsPayload($item['applicants_list']); ?>">
                                            <?php echo $isDone ? 'View Applicants' : 'Applicants (' . intval($item['applicant_count']) . ')'; ?>
                                        </button>
                                    </div>

                                    <div class="ml-act-links">
                                        <?php if (!$isDone): ?>
                                            <a href="post-listing.php?edit=<?php echo (int)$item['id']; ?>" class="ml-link edit">Edit</a>
                                            <form action="<?php echo htmlspecialchars($formAction); ?>" method="POST" class="ml-form" data-ajax>
                                                <input type="hidden" name="listing_id" value="<?php echo (int)$item['id']; ?>">
                                                <input type="hidden" name="action" value="complete">
                                                <button type="submit" class="ml-link good">Mark Completed</button>
                                            </form>
                                        <?php endif; ?>

                                        <form action="<?php echo htmlspecialchars($formAction); ?>" method="POST" class="ml-form" data-ajax
                                              data-confirm-title="Delete this listing?"
                                              data-confirm-text="All associated applicants will also be removed. This can't be undone."
                                              data-confirm-ok="Delete">
                                            <input type="hidden" name="listing_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="ml-link bad">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </section>

    </main>

    <!-- Applicants popup (filled by JavaScript from the data on the button) -->
    <div id="applicantsModal" class="ml-overlay" aria-hidden="true">
        <div class="ml-modal" role="dialog" aria-modal="true" aria-labelledby="modalJobTitle">
            <div class="ml-modal-head">
                <div>
                    <p class="ml-eyebrow" id="modalEyebrow">Applicants</p>
                    <h3 id="modalJobTitle"></h3>
                </div>
                <button type="button" class="ml-x" data-close-modal aria-label="Close">&times;</button>
            </div>
            <!-- Pending / Shortlisted / Rejected (filled by JavaScript) -->
            <div class="ml-subtabs-wrap"><div class="ml-subtabs" id="modalTabs" role="group" aria-label="Applicant status"></div></div>
            <div class="ml-modal-body" id="modalApplicantsContainer"></div>
            <div class="ml-modal-foot">
                <span class="ml-hint" id="modalHint">Best match first. Shortlist &amp; Email also opens a ready-to-send email.</span>
                <button type="button" class="ml-btn ml-btn-ghost" data-close-modal>Close</button>
            </div>
        </div>
    </div>

    <!-- "Are you sure?" popup -->
    <div id="confirmModal" class="ml-overlay" aria-hidden="true">
        <div class="ml-modal small" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmText">
            <h3 id="confirmTitle">Are you sure?</h3>
            <p id="confirmText"></p>
            <div class="ml-confirm-row">
                <button type="button" class="ml-btn ml-btn-ghost" id="confirmCancel">Cancel</button>
                <button type="button" class="ml-btn ml-btn-solid-danger" id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Small message that pops up after an action -->
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        function toggleDropdown() {
            document.getElementById("profileDropdown").classList.toggle("show-dropdown");
        }

        // ==========================================================================
        // Switching tabs, Mark Completed, Delete, Shortlist and Reject now update the
        // page WITHOUT reloading it. PHP still does all the real work (saving the
        // change, counting, match scores, sorting). JavaScript only sends the request
        // in the background, then swaps in the part of the page that changed.
        // If anything goes wrong it falls back to a normal page load.
        // ==========================================================================
        (function () {
            var PAGE = 'my-listings.php';
            var body = document.getElementById('ml-body');
            var toast = document.getElementById('toast');
            var overlay = document.getElementById('applicantsModal');
            var modalList = document.getElementById('modalApplicantsContainer');
            var modalTitle = document.getElementById('modalJobTitle');
            var modalEyebrow = document.getElementById('modalEyebrow');
            var modalHint = document.getElementById('modalHint');
            var confirmBox = document.getElementById('confirmModal');
            var confirmOk = document.getElementById('confirmOk');
            var confirmCancel = document.getElementById('confirmCancel');
            var latestRequest = 0;      // so an older, slower response never replaces a newer one
            var toastTimer = null;
            var openListingId = null;   // which listing's applicants popup is open
            var lastFocus = null;
            var confirmResolve = null;
            var mailDraft = null;       // the email that is ready after pressing Shortlist & Email
            var modalTab = null;        // which group the popup shows: 'pending', 'accepted' (= shortlisted) or 'rejected' (null = pick the first one when it opens)
            var whyOpen = {};           // hash set of the applicant ids whose "Why this score?" is open, like { 12: true, 40: true }

            // Make text safe to put inside HTML
            function esc(value) {
                return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
                });
            }

            // Small message at the bottom of the screen (green = success, red = error)
            function showToast(message, kind) {
                toast.textContent = message;
                toast.className = 'toast show' + (kind ? ' ' + kind : '');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4500);
            }

            function currentTab() { return body.getAttribute('data-tab') || 'active'; }

            // Put the new HTML from PHP into the page.
            // Returns null if it is not the My Listings page (for example the session expired and PHP sent the login page).
            function swapIn(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newBody = doc.getElementById('ml-body');
                if (!newBody) { return null; }

                // The green bar from PHP becomes a toast instead
                var flash = newBody.querySelector('.ml-flash');
                var flashText = flash ? flash.textContent.trim() : '';
                if (flash) { flash.remove(); }

                body.innerHTML = newBody.innerHTML;
                body.setAttribute('data-tab', newBody.getAttribute('data-tab') || 'active');

                return { flash: flashText };
            }

            // ---------------- Tabs (and the three number shortcuts) ----------------
            // mode: 'push' = add to browser history (Back button works), 'replace' = update the address only, 'none' = leave it
            function loadPage(url, mode) {
                var myRequest = ++latestRequest;
                body.classList.add('loading');

                fetch(url, { credentials: 'same-origin' })
                    .then(function (res) {
                        // Sent somewhere else (like the login page)? Do a normal page load instead.
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
                        if (myRequest === latestRequest) { body.classList.remove('loading'); }
                    });
            }

            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a[data-ajax]') : null;
                if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
                e.preventDefault();

                // Show the clicked tab as selected right away
                if (link.classList.contains('ml-tab')) {
                    var tabs = body.querySelectorAll('.ml-tab');
                    for (var i = 0; i < tabs.length; i++) { tabs[i].classList.toggle('on', tabs[i] === link); }
                }
                loadPage(link.href, link.href === window.location.href ? 'replace' : 'push');
            });

            // Back / Forward buttons
            window.addEventListener('popstate', function () {
                closeApplicants();
                loadPage(window.location.href, 'none');
            });

            // ---------------- Mark Completed / Delete / Shortlist / Reject ----------------
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form.matches || !form.matches('form[data-ajax]')) { return; }
                e.preventDefault();

                var needsConfirm = form.hasAttribute('data-confirm-title');
                var ask = needsConfirm
                    ? confirmDialog(form.getAttribute('data-confirm-title'), form.getAttribute('data-confirm-text'), form.getAttribute('data-confirm-ok') || 'Confirm')
                    : Promise.resolve(true);
                ask.then(function (yes) { if (yes) { sendAction(form); } });
            });

            function sendAction(form) {
                var button = form.querySelector('button[type="submit"]');
                var card = form.closest('.ml-card, .ap');
                if (button) { button.disabled = true; }
                if (card) { card.classList.add('is-busy'); }
                var myRequest = ++latestRequest;

                // getAttribute (not form.action) because the hidden field called "action" hides the form's own action property
                fetch(form.getAttribute('action') || PAGE, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
                    .then(function (res) {
                        if (res.redirected && res.url.indexOf(PAGE) === -1) { throw new Error('redirected'); }
                        return res.text();
                    })
                    .then(function (html) {
                        var result = swapIn(html);
                        if (!result) { throw new Error('unexpected page'); }
                        showToast(result.flash || 'Done.', 'ok');
                        // Shortlist & Email: the status is saved, so get the email ready
                        if (form.getAttribute('data-mail')) {
                            try { mailDraft = JSON.parse(form.getAttribute('data-mail')); } catch (err) { mailDraft = null; }
                        }
                        refreshApplicants();
                    })
                    .catch(function (err) {
                        if (err && err.message === 'redirected') { window.location.href = PAGE; return; }
                        if (button) { button.disabled = false; }
                        if (card) { card.classList.remove('is-busy'); }
                        showToast('Error: could not save that. Please try again.', 'err');
                    })
                    .then(function () {
                        if (myRequest === latestRequest) { body.classList.remove('loading'); }
                    });
            }

            // ---------------- "Are you sure?" popup ----------------
            function confirmDialog(title, text, okLabel) {
                document.getElementById('confirmTitle').textContent = title || 'Are you sure?';
                document.getElementById('confirmText').textContent = text || '';
                confirmOk.textContent = okLabel;
                confirmBox.classList.add('open');
                confirmBox.setAttribute('aria-hidden', 'false');
                confirmCancel.focus();
                return new Promise(function (resolve) { confirmResolve = resolve; });
            }
            function closeConfirm(answer) {
                if (!confirmResolve) { return; }
                confirmBox.classList.remove('open');
                confirmBox.setAttribute('aria-hidden', 'true');
                var done = confirmResolve;
                confirmResolve = null;
                done(answer);
            }
            confirmOk.addEventListener('click', function () { closeConfirm(true); });
            confirmCancel.addEventListener('click', function () { closeConfirm(false); });
            confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) { closeConfirm(false); } });

            // ---------------- Applicants popup ----------------
            function scoreTier(score) { return score >= 75 ? 'high' : (score >= 50 ? 'mid' : 'low'); }

            function initials(name) {
                var letters = String(name || '').trim().split(/\s+/).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
                return letters.slice(0, 2) || 'U';
            }

            // 15.00 -> "15", 16.67 -> "16.67", 6.60 -> "6.6" (same rule as the Browse page)
            function fmtPoints(n) { return String(Math.round(Number(n) * 100) / 100); }

            // One row of the "Why this score?" box: label, points, bar and a short note
            function whyRow(label, points, max, note, extraHtml) {
                var width = max ? Math.round(Number(points) / max * 10000) / 100 : 0;
                return '<div class="aw-row">' +
                    '<div class="aw-top"><span>' + label + '</span><span class="aw-pts">' + esc(fmtPoints(points)) + ' <small>/ ' + max + '</small></span></div>' +
                    '<div class="aw-bar" aria-hidden="true"><span style="width: ' + width + '%;"></span></div>' +
                    '<div class="aw-note">' + esc(note) + '</div>' + (extraHtml || '') +
                '</div>';
            }

            // The three parts of one applicant's score (the numbers come from PHP, the same formula as everywhere else)
            function whyPanel(app, open) {
                var w = app.why;
                if (!w) { return ''; }

                var skillsNote = w.skills_total === 0
                    ? 'This listing asks for no specific skills, so the full points are given.'
                    : 'Has ' + w.skills_have + ' of ' + w.skills_total + ' required skill' + (w.skills_total === 1 ? '' : 's') + '.';
                var locationNote = w.location_result === 'same' ? 'Same city as your listing: full points.'
                    : (w.location_result === 'different' ? 'Different city: half points.'
                    : 'Location is missing on your listing or on their profile: no points.');
                var availabilityNote = w.availability_total === 0
                    ? 'This listing needs no specific schedule, so the full points are given.'
                    : 'Is free for ' + w.availability_have + ' of ' + w.availability_total + ' schedule option' + (w.availability_total === 1 ? '' : 's') + ' you need.';

                var chips = '';
                var chipList = (app.matched || []).map(function (s) { return '<span class="aw-chip ok">Matched: ' + esc(s) + '</span>'; })
                    .concat((app.missing || []).map(function (s) { return '<span class="aw-chip no">Missing: ' + esc(s) + '</span>'; }));
                if (chipList.length) { chips = '<div class="aw-chips">' + chipList.join('') + '</div>'; }

                return '<div class="ap-why" id="why-' + esc(app.id) + '"' + (open ? '' : ' hidden') + '>' +
                    whyRow('Skills', w.skills_points, 50, skillsNote, chips) +
                    whyRow('Location', w.location_points, 30, locationNote) +
                    whyRow('Availability', w.availability_points, 20, availabilityNote) +
                    '<div class="aw-total"><span>Total match</span><b>' + esc(app.score) + '%</b></div>' +
                '</div>';
            }

            // A form that changes one applicant's status (the same update_application action as before)
            function statusForm(app, status, label, cssClass, mailInfo, confirmText) {
                var action = PAGE + '?tab=' + encodeURIComponent(currentTab());
                return '<form action="' + esc(action) + '" method="POST" data-ajax' +
                    (mailInfo ? ' data-mail="' + esc(mailInfo) + '"' : '') +
                    (confirmText ? ' data-confirm-title="Reject this applicant?" data-confirm-text="' + esc(confirmText) + '" data-confirm-ok="Reject"' : '') + '>' +
                    '<input type="hidden" name="action" value="update_application">' +
                    '<input type="hidden" name="application_id" value="' + esc(app.id) + '">' +
                    '<input type="hidden" name="status" value="' + status + '">' +
                    '<button type="submit" class="ml-btn ' + cssClass + '">' + label + '</button>' +
                '</form>';
            }

            // The buttons on the right of a row depend on the group (and on whether the job is already completed)
            function rowActions(app, jobTitle, isDone) {
                if (isDone) {
                    // A completed job is read-only: you can look at everyone, but not change decisions
                    if (app.status === 'accepted') { return '<span class="ap-short">Shortlisted</span>'; }
                    if (app.status === 'rejected') { return '<span class="ap-tag no">Rejected</span>'; }
                    return '<span class="ap-tag">Not reviewed</span>';
                }
                var draftInfo = JSON.stringify({ name: app.name, email: app.email, title: jobTitle });
                if (app.status === 'accepted') {
                    return '<button type="button" class="ml-btn ml-btn-primary" data-mail-again="' + esc(app.id) + '">Email again</button>' +
                           statusForm(app, 'pending', 'Back to pending', 'ml-btn-ghost');
                }
                if (app.status === 'rejected') {
                    return statusForm(app, 'accepted', 'Shortlist &amp; Email', 'ml-btn-good', draftInfo) +
                           statusForm(app, 'pending', 'Reconsider', 'ml-btn-ghost');
                }
                return statusForm(app, 'accepted', 'Shortlist &amp; Email', 'ml-btn-good', draftInfo) +
                       statusForm(app, 'rejected', 'Reject', 'ml-btn-danger', null, 'They will move to the Rejected tab. You can change your mind there.');
            }

            // Weak skill match: same rule as isWeakSkillMatch() in db.php (warning only, the score stays the same)
            function isWeakSkillMatch(w) {
                if (!w || !w.skills_total) { return false; }
                return (w.skills_have * 2) <= w.skills_total && w.total >= 50;
            }

            function applicantRow(app, jobTitle, isDone) {
                var skills = '';
                if (app.skills && app.skills.length) {
                    skills = '<div class="ap-skills">' + app.skills.map(function (s) { return '<span>' + esc(s) + '</span>'; }).join('') + '</div>';
                }

                var meta = [esc(app.email), esc(app.location || 'Metro Manila')];
                if (app.applied) { meta.push('Applied ' + esc(app.applied)); }

                var open = !!whyOpen[app.id];   // hash set lookup: was this breakdown left open?

                // The applicant's profile picture; their initials only when they have no picture
                var avatar = app.pic
                    ? '<div class="ap-avatar"><img src="' + esc(app.pic) + '" alt=""></div>'
                    : '<div class="ap-avatar">' + esc(initials(app.name)) + '</div>';

                return '<div class="ap">' +
                    avatar +
                    '<div class="ap-main">' +
                        '<div class="ap-name"><h4>' + esc(app.name) + '</h4><span class="score ' + scoreTier(app.score) + '">' + esc(app.score) + '% Match</span>' +
                            (isWeakSkillMatch(app.why) ? '<span class="weak" title="Has ' + esc(app.why.skills_have) + ' of ' + esc(app.why.skills_total) + ' required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span>' : '') + '</div>' +
                        '<p class="ap-meta">' + meta.join(' &middot; ') + '</p>' +
                        skills +
                        '<button type="button" class="ap-why-btn" data-why="' + esc(app.id) + '" aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="why-' + esc(app.id) + '">' +
                            '<span>' + (open ? 'Hide breakdown' : 'Why this score?') + '</span>' +
                            '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="ap-actions">' + rowActions(app, jobTitle, isDone) + '</div>' +
                    whyPanel(app, open) +
                '</div>';
            }

            // ---------------- Email that is ready after Shortlist & Email ----------------
            // Works on any computer: "Open in Gmail" needs no mail app, "Open mail app" uses the default one.
            function mailPanel(d) {
                var me = document.body.getAttribute('data-me') || '';
                var subject = 'JobiT - Your application for ' + d.title;
                var text = 'Hi ' + d.name + ',\n\nWe reviewed your application for "' + d.title + '" on JobiT and we are interested in you!\n\n' +
                           'Please reply to this email so we can talk about the next steps.\n\nBest regards,\n' + me;
                return '<div class="ml-mail" id="mailPanel">' +
                    '<div class="ml-mail-head"><div class="ml-mail-ok">&#10003;</div><div><b>' + (d.again ? 'Email ' + esc(d.name) : esc(d.name) + ' shortlisted') + '</b><span>Your email is ready. Check it, then send.</span></div></div>' +
                    '<label for="mailTo">To</label><input id="mailTo" type="text" value="' + esc(d.email) + '" readonly>' +
                    '<label for="mailSubject">Subject</label><input id="mailSubject" type="text" value="' + esc(subject) + '">' +
                    '<label for="mailBody">Message</label><textarea id="mailBody" rows="6">' + esc(text) + '</textarea>' +
                    '<div class="ml-mail-actions">' +
                        '<button type="button" class="ml-btn ml-btn-primary" data-mail-do="gmail">Open in Gmail</button>' +
                        '<button type="button" class="ml-btn ml-btn-ghost" data-mail-do="app">Open mail app</button>' +
                        '<button type="button" class="ml-btn ml-btn-ghost" data-mail-do="copy">Copy</button>' +
                        '<button type="button" class="ml-link" data-mail-do="skip">Skip</button>' +
                    '</div></div>';
            }

            function mailAction(what) {
                var to = mailDraft ? mailDraft.email : '';
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
                    mailDraft = null;
                    var panel = document.getElementById('mailPanel');
                    if (panel) { panel.remove(); }
                }
            }

            // What each group says when it is empty, and the small hint at the bottom of the popup
            var EMPTY = {
                pending:  { active: ['You\'re all caught up', 'No pending applicants in the queue for this listing.'], done: ['Nobody was left unreviewed', 'Every applicant of this job was shortlisted or rejected.'] },
                accepted: { active: ['Nobody shortlisted yet', 'Shortlist a pending applicant and they will show up here.'], done: ['Nobody was shortlisted', 'No candidate was shortlisted for this completed job.'] },
                rejected: { active: ['Nobody rejected', 'Applicants you reject are kept here, so you can change your mind.'], done: ['Nobody was rejected', 'No applicant was rejected for this job.'] }
            };
            var HINT = {
                active: {
                    pending: 'Best match first. Shortlist & Email saves your decision and gets an email ready to send.',
                    accepted: 'People you shortlisted, best match first. Email them again or move them back to pending.',
                    rejected: 'People you rejected, best match first. You can reconsider anyone.'
                },
                done: {
                    pending: 'This job is completed. These applicants were never reviewed.',
                    accepted: 'This job is completed. These are the candidates you shortlisted.',
                    rejected: 'This job is completed. These applicants were not selected.'
                }
            };

            function currentApplicantsButton() {
                return openListingId === null ? null : body.querySelector('[data-open-applicants="' + openListingId + '"]');
            }

            function renderApplicants(button) {
                var jobTitle = button.getAttribute('data-title') || '';
                var isDone = button.getAttribute('data-status') === 'completed';
                var apps = [];
                try { apps = JSON.parse(button.getAttribute('data-applicants') || '[]'); } catch (err) { apps = []; }

                // How many people are in each group
                var counts = { pending: 0, accepted: 0, rejected: 0 };
                apps.forEach(function (a) { counts[a.status in counts ? a.status : 'pending']++; });

                // First time this popup opens: an active job starts on Pending, a completed job on Shortlisted
                if (modalTab === null) { modalTab = isDone ? 'accepted' : 'pending'; }
                var kind = isDone ? 'done' : 'active';

                modalEyebrow.textContent = isDone ? 'Completed job · Applicants' : 'Applicants';
                modalTitle.textContent = jobTitle;
                modalHint.textContent = HINT[kind][modalTab];

                // The three tabs, each with its number
                var labels = { pending: 'Pending', accepted: 'Shortlisted', rejected: 'Rejected' };
                document.getElementById('modalTabs').innerHTML = ['pending', 'accepted', 'rejected'].map(function (key) {
                    return '<button type="button" class="ml-subtab' + (key === modalTab ? ' on' : '') + '" data-subtab="' + key + '" aria-pressed="' + (key === modalTab ? 'true' : 'false') + '">' +
                        labels[key] + ' <b>' + counts[key] + '</b></button>';
                }).join('');

                var mailHtml = mailDraft ? mailPanel(mailDraft) : '';

                // Only the people of the chosen group (the list from PHP is already sorted best match first)
                var shown = apps.filter(function (a) { return (a.status in counts ? a.status : 'pending') === modalTab; });

                if (!shown.length) {
                    var text = EMPTY[modalTab][kind];
                    modalList.innerHTML = mailHtml + '<div class="ml-none"><b>' + esc(text[0]) + '</b>' + esc(text[1]) + '</div>';
                } else {
                    modalList.innerHTML = mailHtml + shown.map(function (a) { return applicantRow(a, jobTitle, isDone); }).join('');
                }
            }

            function openApplicants(button) {
                lastFocus = button;
                openListingId = button.getAttribute('data-open-applicants');
                mailDraft = null;
                modalTab = null;       // pick the first tab again for this listing
                whyOpen = {};
                renderApplicants(button);
                overlay.classList.add('open');
                overlay.setAttribute('aria-hidden', 'false');
                document.documentElement.classList.add('ml-lock');
                var closeBtn = overlay.querySelector('.ml-x');
                if (closeBtn) { closeBtn.focus(); }
            }

            function closeApplicants() {
                if (!overlay.classList.contains('open')) { return; }
                overlay.classList.remove('open');
                overlay.setAttribute('aria-hidden', 'true');
                document.documentElement.classList.remove('ml-lock');
                openListingId = null;
                mailDraft = null;
                if (lastFocus && document.contains(lastFocus)) { lastFocus.focus(); }
            }

            // After Shortlist / Reject the page data changed, so redraw the popup with the fresh list
            function refreshApplicants() {
                if (openListingId === null) { return; }
                var button = body.querySelector('[data-open-applicants="' + openListingId + '"]');
                if (!button) { closeApplicants(); return; }   // that listing is gone from this tab
                lastFocus = button;
                renderApplicants(button);
            }

            document.addEventListener('click', function (e) {
                var opener = e.target.closest ? e.target.closest('[data-open-applicants]') : null;
                if (opener) { openApplicants(opener); return; }
                var mailBtn = e.target.closest ? e.target.closest('[data-mail-do]') : null;
                if (mailBtn) { mailAction(mailBtn.getAttribute('data-mail-do')); return; }

                // Pending / Shortlisted / Rejected tabs inside the popup
                var tabBtn = e.target.closest ? e.target.closest('[data-subtab]') : null;
                if (tabBtn) {
                    modalTab = tabBtn.getAttribute('data-subtab');
                    var listButton = currentApplicantsButton();
                    if (listButton) { mailDraft = null; renderApplicants(listButton); modalList.scrollTop = 0; }
                    return;
                }

                // "Why this score?" opens / closes the breakdown without redrawing the list (so the scroll position stays)
                var whyBtn = e.target.closest ? e.target.closest('[data-why]') : null;
                if (whyBtn) {
                    var id = whyBtn.getAttribute('data-why');
                    var panel = document.getElementById('why-' + id);
                    if (panel) {
                        panel.hidden = !panel.hidden;
                        whyBtn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                        whyBtn.firstElementChild.textContent = panel.hidden ? 'Why this score?' : 'Hide breakdown';
                        if (panel.hidden) { delete whyOpen[id]; } else { whyOpen[id] = true; }   // remember it in the hash set
                    }
                    return;
                }

                // "Email again" for someone you shortlisted earlier
                var againBtn = e.target.closest ? e.target.closest('[data-mail-again]') : null;
                if (againBtn) {
                    var listBtn = currentApplicantsButton();
                    if (listBtn) {
                        var all = [];
                        try { all = JSON.parse(listBtn.getAttribute('data-applicants') || '[]'); } catch (err) { all = []; }
                        var wanted = String(againBtn.getAttribute('data-mail-again'));
                        for (var i = 0; i < all.length; i++) {
                            if (String(all[i].id) === wanted) {
                                mailDraft = { name: all[i].name, email: all[i].email, title: listBtn.getAttribute('data-title') || '', again: true };
                                renderApplicants(listBtn);
                                modalList.scrollTop = 0;
                                break;
                            }
                        }
                    }
                    return;
                }
                if (e.target.closest && e.target.closest('[data-close-modal]')) { closeApplicants(); return; }
                if (e.target === overlay) { closeApplicants(); }
            });

            // Escape closes the top-most popup; clicking elsewhere closes the profile menu
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') { return; }
                if (confirmResolve) { closeConfirm(false); } else { closeApplicants(); }
            });
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.profile-dropdown-container')) {
                    document.getElementById('profileDropdown').classList.remove('show-dropdown');
                }
            });
        })();
    </script>

</body>
</html>
