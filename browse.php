<?php
// ============================================================================
// JOBIT MARKETPLACE BROWSE ENGINE (Connected to Profile, Post-Listing & Matches)
// ============================================================================

session_start();
require_once 'db.php';
require_once __DIR__ . '/sorting.php';   // our own merge sort (replaces usort)

$userId = $_SESSION['user_id'] ?? null;

// Redirect unauthenticated users
if (!$userId && !isset($_SESSION['user_profile'])) {
    header("Location: auth.php?mode=login");
    exit();
}

// 1. FETCH LIVE USER PROFILE DATA FOR MATCH CALCULATIONS
$currentUser = null;
if ($userId) {
    try {
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $currentUser = $userStmt->fetch();
    } catch (PDOException $e) {}
}

$userName = $currentUser['full_name'] ?? ($_SESSION['user_profile']['name'] ?? 'Dan Axel M. Tongol');
$userInitials = strtoupper(substr($userName, 0, 2));
$userAvatar = $currentUser['avatar'] ?? ($_SESSION['user_profile']['avatar'] ?? '');
$userLocation = $currentUser['location'] ?? ($_SESSION['user_profile']['location'] ?? '');
$userSkillsStr = $currentUser['skills'] ?? '';   // no made-up skills: empty = 0 skill points
$userSlotsStr = $currentUser['available_slots'] ?? '';   // no made-up availability: empty = 0 availability points

// 2. HANDLE APPLICATION SUBMISSION (Connected to My Listings & Matches)
$applicationMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_gig') {
    $listingIdToApply = intval($_POST['listing_id'] ?? 0);
    if ($listingIdToApply > 0 && $userId) {
        try {
            $checkApp = $pdo->prepare("SELECT id FROM applications WHERE listing_id = ? AND applicant_id = ?");
            $checkApp->execute([$listingIdToApply, $userId]);
            if ($checkApp->rowCount() === 0) {
                $insApp = $pdo->prepare("INSERT INTO applications (listing_id, applicant_id, status, applied_at) VALUES (?, ?, 'pending', NOW())");
                $insApp->execute([$listingIdToApply, $userId]);
                $applicationMessage = 'Application submitted successfully! It is now visible in My Listings and Matches.';
            } else {
                $applicationMessage = 'You have already applied for this opportunity.';
            }
        } catch (Exception $e) {
            $applicationMessage = 'Error submitting application. Please try again.';
        }
    }
}

// 2b. SAVED LISTINGS (a HASH SET)
// "Which listings has this member saved?" is asked once for EVERY listing on the page.
// We keep the saved ids in a hash set: a PHP array where the id is the KEY, like [12 => true, 40 => true].
// PHP arrays are hash tables, so isset($savedSet[$id]) finds the answer in one step (O(1)) however many
// listings were saved. The same question with in_array() would have to walk through the whole list (O(k))
// for every listing on the page, so a member with many saved listings would make the page slower.
//   add to the set     $savedSet[$id] = true;
//   remove from it     unset($savedSet[$id]);
//   is it in the set?  isset($savedSet[$id])
// The database table saved_listings (user_id, listing_id, saved_at) keeps the set between visits.
function jobitLoadSavedSet($pdo, $userId) {
    $set = [];
    if (!$userId) {
        return $set;
    }
    try {
        $stmt = $pdo->prepare("SELECT listing_id FROM saved_listings WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        // The table does not exist yet (first visit after this update): create it, nothing is saved yet.
        // The same SQL is also in saved_listings.sql if you prefer to run it in phpMyAdmin.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS saved_listings (
                user_id INT NOT NULL,
                listing_id INT NOT NULL,
                saved_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, listing_id)
            )");
        } catch (PDOException $e2) {
            // if the host does not allow it, saving simply does nothing and the page still works
        }
        return $set;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $savedId) {
        $set[(int)$savedId] = true;
    }
    return $set;
}

$savedSet = jobitLoadSavedSet($pdo, $userId);

// Save / Unsave button. Two separate actions (not one "toggle"), so refreshing the page can never flip the heart by accident.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId && isset($_POST['action']) && in_array($_POST['action'], ['save_gig', 'unsave_gig'], true)) {
    $listingIdToSave = intval($_POST['listing_id'] ?? 0);
    if ($listingIdToSave > 0) {
        try {
            if ($_POST['action'] === 'save_gig') {
                if (isset($savedSet[$listingIdToSave])) {
                    $applicationMessage = 'This listing is already in your saved listings.';
                } else {
                    // only an active listing of someone else can be saved
                    $checkGig = $pdo->prepare("SELECT id FROM listings WHERE id = ? AND status = 'active' AND user_id != ?");
                    $checkGig->execute([$listingIdToSave, $userId]);
                    if ($checkGig->fetch()) {
                        $insSave = $pdo->prepare("INSERT INTO saved_listings (user_id, listing_id, saved_at) VALUES (?, ?, NOW())");
                        $insSave->execute([$userId, $listingIdToSave]);
                        $savedSet[$listingIdToSave] = true;          // add to the hash set
                        $applicationMessage = 'Listing saved successfully!';
                    } else {
                        $applicationMessage = 'Error: this listing is no longer available.';
                    }
                }
            } else {
                $delSave = $pdo->prepare("DELETE FROM saved_listings WHERE user_id = ? AND listing_id = ?");
                $delSave->execute([$userId, $listingIdToSave]);
                unset($savedSet[$listingIdToSave]);                  // remove from the hash set
                $applicationMessage = 'Removed from your saved listings.';
            }
        } catch (Exception $e) {
            $applicationMessage = 'Error saving this listing. Please try again.';
        }
    }
}

// 2c. LISTINGS YOU ALREADY APPLIED TO (another HASH SET: listing id => true)
// Read after the Apply code above, so a brand-new application already counts.
$appliedSet = [];
if ($userId) {
    try {
        $appliedStmt = $pdo->prepare("SELECT listing_id FROM applications WHERE applicant_id = ?");
        $appliedStmt->execute([$userId]);
        foreach ($appliedStmt->fetchAll(PDO::FETCH_COLUMN) as $appliedId) {
            $appliedSet[(int)$appliedId] = true;
        }
    } catch (Exception $e) {}
}

// 3. FETCH ACTIVE LISTINGS FROM DATABASE
try {
    $sql = "
        SELECT l.*, u.full_name as posted_by, 
               IFNULL(u.avatar, '') as poster_avatar 
        FROM listings l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.status = 'active'
    ";
    $params = [];
    if ($userId) {
        $sql .= " AND l.user_id != ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY l.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $dbListings = $stmt->fetchAll();
} catch (PDOException $e) {
    $dbListings = [];
}

// 3b. WORK TYPE (Short Gig / Part-Time / Full-Time)
// Finds which column of the listings table stores the work type, so the Short Gig / Part-Time / Full-Time
// filter works with the dashboard cards (browse.php?work_type=short-gig). It only FILTERS the list.
// It never changes the match score (that still comes from calculateMatchScore() in db.php).
$workTypeColumn = null;
if (!empty($dbListings)) {
    foreach (['work_type', 'job_type', 'employment_type', 'listing_type', 'type'] as $col) {
        if (array_key_exists($col, $dbListings[0])) {
            $workTypeColumn = $col;
            break;
        }
    }
}

// Turns "Short Gig", "short_gig", "Part Time", "fulltime"... into one of: short-gig, part-time, full-time
function normalizeWorkType($value) {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/[\s_]+/', '-', $v);
    if (in_array($v, ['short-gig', 'shortgig', 'short', 'gig', 'short-term', 'short-time'], true)) return 'short-gig';
    if (in_array($v, ['part-time', 'parttime', 'part'], true)) return 'part-time';
    if (in_array($v, ['full-time', 'fulltime', 'full'], true)) return 'full-time';
    return $v;
}
$workTypeLabels = ['short-gig' => 'Short Gig', 'part-time' => 'Part-Time', 'full-time' => 'Full-Time'];

// 4. VECTOR MATCHING ENGINE ALGORITHM
// Uses the shared getScoreBreakdown() and getSkillsBreakdown() functions from db.php.
// calculateMatchScore() (used by matches.php and my-listings.php) is the 'total' of that same breakdown,
// so the percent is always the same everywhere.
function computeJobMatchVector($gig, $userLocation, $userSkillsStr, $userSlotsStr) {
    $skillsBreakdown = getSkillsBreakdown($gig['skills_required'] ?? '', $userSkillsStr);

    // The score split into skills / location / availability (the 'total' is the match percent)
    $breakdown = getScoreBreakdown(
        $gig['skills_required'] ?? '',
        $gig['location'] ?? '',
        $gig['schedule'] ?? '',
        $userSkillsStr,
        $userLocation,
        $userSlotsStr
    );

    return [
        'score' => $breakdown['total'],
        'breakdown' => $breakdown,
        'matched_skills' => $skillsBreakdown['matched'],
        'missing_skills' => $skillsBreakdown['missing'],
        'required_skills' => !empty($gig['skills_required']) ? array_map('trim', explode(',', $gig['skills_required'])) : ['General']
    ];
}

$listingsDataset = [];
foreach ($dbListings as $row) {
    $res = computeJobMatchVector($row, $userLocation, $userSkillsStr, $userSlotsStr);
    
    $createdTime = strtotime($row['created_at']);
    $daysAgo = floor((time() - $createdTime) / 86400);
    // Never shows "-1 days ago" when the server and database clocks differ a little; also says "1 day ago"
    $postedDateStr = $daysAgo <= 0 ? 'Today' : ($daysAgo == 1 ? '1 day ago' : $daysAgo . ' days ago');

    $listingsDataset[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'category' => ucfirst(trim($row['category'])),
        'description' => $row['description'],
        'budget_min' => $row['budget_min'],
        'budget_max' => $row['budget_max'],
        'location' => $row['location'],
        'schedule' => $row['schedule'] ?? 'Weekdays',
        'posted_by' => $row['posted_by'],
        'poster_avatar' => $row['poster_avatar'],
        'posted_date' => $postedDateStr,
        'created_ts' => $createdTime ?: 0,   // posted time as a number (seconds), used by the Newest sort
        'saved' => isset($savedSet[(int)$row['id']]),   // hash set lookup: is this listing saved by the member?
        'applied' => isset($appliedSet[(int)$row['id']]),   // hash set lookup: did the member already apply?
        'work_type' => $workTypeColumn !== null ? normalizeWorkType($row[$workTypeColumn] ?? '') : '',
        'match_score' => $res['score'],
        'breakdown' => $res['breakdown'],
        'matched_skills' => $res['matched_skills'],
        'missing_skills' => $res['missing_skills'],
        'required_skills' => $res['required_skills']
    ];
}

// Filters & Search handling
$searchQuery = trim($_GET['search'] ?? '');
$selectedCategory = $_GET['category'] ?? 'all';
$selectedSort = $_GET['sort'] ?? 'match';
$savedOnly = (($_GET['saved'] ?? '') === '1');   // the "Saved" pill: only show the listings the member saved

// Work type from the dashboard cards or the type pills: all | short-gig | part-time | full-time
$selectedWorkType = $_GET['work_type'] ?? 'all';
if (!in_array($selectedWorkType, ['all', 'short-gig', 'part-time', 'full-time'], true)) {
    $selectedWorkType = 'all';
}

$filteredListings = array_filter($listingsDataset, function($item) use ($searchQuery, $selectedCategory, $selectedWorkType, $workTypeColumn, $savedOnly) {
    $matchSearch = empty($searchQuery) || (stripos($item['title'], $searchQuery) !== false || stripos($item['description'], $searchQuery) !== false);
    $matchCat = ($selectedCategory === 'all' || strcasecmp($item['category'], $selectedCategory) === 0);
    // Only applied when the listings table has a work type column
    $matchType = ($selectedWorkType === 'all' || $workTypeColumn === null || $item['work_type'] === $selectedWorkType);
    $matchSaved = (!$savedOnly || $item['saved']);
    return $matchSearch && $matchCat && $matchType && $matchSaved;
});

// How many of the listings on this page are saved (shown on the Saved pill)
$savedCount = 0;
foreach ($listingsDataset as $savedCheck) {
    if ($savedCheck['saved']) {
        $savedCount++;
    }
}

// Sorting
if ($selectedSort === 'match') {
    // Our own merge sort (sorting.php): highest match score first, equal scores keep their order
    $filteredListings = mergeSortByKey($filteredListings, 'match_score');
} elseif ($selectedSort === 'budget_desc') {
    // Same merge sort, this time by the highest budget
    $filteredListings = mergeSortByKey($filteredListings, 'budget_max');
} elseif ($selectedSort === 'newest') {
    // Same merge sort again, by the posted time: the biggest number (the latest post) comes first
    $filteredListings = mergeSortByKey($filteredListings, 'created_ts');
}

// Selected Gig for Inspector Panel
$selectedGigId = intval($_GET['inspect'] ?? ($filteredListings[0]['id'] ?? ($listingsDataset[0]['id'] ?? 0)));
$currentInspectGig = null;
foreach ($listingsDataset as $g) {
    if ($g['id'] === $selectedGigId) {
        $currentInspectGig = $g;
        break;
    }
}
if (!$currentInspectGig && count($listingsDataset) > 0) {
    $currentInspectGig = $listingsDataset[0];
}

// DISPLAY HELPERS (design only: picks a color tier from the match score)
function matchTier($score) {
    return $score >= 75 ? 'high' : ($score >= 50 ? 'mid' : 'low');
}
// Short label for the colour group
function tierLabel($score) {
    return $score >= 75 ? 'Great match' : ($score >= 50 ? 'Good match' : 'Partial match');
}
// Comma text -> clean list ("Design, , PHP" -> ["Design", "PHP"])
function cleanList($text) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$text)), 'strlen'));
}
// A person's current picture with a ?v= stamp (a new upload shows right away), or '' for initials
function picUrl($path) {
    $path = (string)$path;
    if ($path === '' || !file_exists($path)) return '';
    return $path . '?v=' . filemtime($path);
}
// The three parts of every score: css letter, name, key in the breakdown, maximum points
$scoreParts = [
    ['s', 'Skills', 'skills_points', 50],
    ['l', 'Location', 'location_points', 30],
    ['a', 'Availability', 'availability_points', 20],
];
// Points for the "Why this score?" box: 15.00 -> "15", 16.67 -> "16.67", 6.60 -> "6.6"
function formatPoints($points) {
    return rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
}
$pinIcon = '<svg class="ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4154f1">
    <title>JobiT - Browse Marketplace</title>
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
        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', Tahoma, sans-serif;
            background: var(--white); color: var(--ink); line-height: 1.55;
            min-height: 100vh; display: flex; flex-direction: column;
        }
        input, select, button { font: inherit; color: inherit; }
        a { color: inherit; text-decoration: none; }
        a:focus-visible, button:focus-visible, select:focus-visible { outline: 3px solid var(--blue); outline-offset: 2px; }

        /* Page: white nav, blue top, white results, blue footer */
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

        /* ---------- Application notice (shown only when the page was loaded the normal way) ---------- */
        .notice { background: var(--good-bg); border-bottom: 1px solid #b7ebc6; color: var(--good); font-weight: 700; padding: 14px 0; }

        /* ---------- Toast (small message that appears after applying, no page reload) ---------- */
        .toast {
            position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px);
            background: var(--ink); color: #fff; padding: 14px 24px; border-radius: 999px;
            font-weight: 700; font-size: 0.92rem; text-align: center; max-width: calc(100vw - 32px);
            box-shadow: 0 16px 36px rgba(10,16,70,0.3); opacity: 0; pointer-events: none;
            transition: opacity .25s, transform .25s; z-index: 200;
        }
        .toast.show { opacity: 1; transform: translate(-50%, 0); }
        .toast.ok { background: var(--good); }
        .toast.err { background: var(--bad); }

        /* ======================================================================
           BROWSE (same look as Matches): blue hero, score rings, 3-colour score bars
           The three score parts use the same colours everywhere:
           Skills = blue, Location = green, Availability = amber
           ====================================================================== */
        :root { --c-s: #4154f1; --c-l: #1e8e3e; --c-a: #f29900; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; flex-shrink: 0; }
        .dot.s { background: var(--c-s); } .dot.l { background: var(--c-l); } .dot.a { background: var(--c-a); }

        /* ---------- 1. Blue hero with the search ---------- */
        .pg-head { background: var(--blue); color: #fff; padding: 22px 0 24px; position: relative; overflow: hidden; }
        .pg-head::before { content: ""; position: absolute; width: 440px; height: 440px; border-radius: 50%; right: -130px; top: -230px; background: rgba(255,255,255,0.07); }
        .pg-head::after { content: ""; position: absolute; width: 280px; height: 280px; border-radius: 50%; left: 30%; bottom: -220px; background: rgba(255,255,255,0.05); }
        .pg-head .wrap { position: relative; z-index: 1; }
        .hero-row { display: flex; justify-content: space-between; align-items: center; gap: 28px; }
        .hero-copy { min-width: 0; }
        .crumbs { font-size: 0.82rem; color: #dfe3ff; margin-bottom: 10px; }
        .crumbs a:hover { color: #fff; text-decoration: underline; }
        .crumbs strong { color: #fff; font-weight: 700; }
        .eyebrow { display: inline-block; background: var(--yellow); color: var(--ink); font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 4px 11px; border-radius: 999px; margin-bottom: 10px; }
        .pg-head h1 { font-size: 2rem; line-height: 1.15; letter-spacing: -0.025em; font-weight: 800; color: #fff; }
        .pg-head .lead { color: #eef1ff; font-size: 0.95rem; max-width: 560px; margin-top: 6px; }

        .me { width: 330px; flex-shrink: 0; background: #fff; color: var(--ink); border-radius: 18px; padding: 14px 18px; box-shadow: 0 18px 40px rgba(10,16,70,0.28); }
        .me-head { display: flex; align-items: center; gap: 11px; padding-bottom: 10px; margin-bottom: 2px; border-bottom: 1px solid var(--line); }
        .me-label { display: block; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--blue); }
        .me-name { display: block; font-size: 0.95rem; font-weight: 800; }
        .me-row { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; }
        .me-k { width: 70px; flex-shrink: 0; font-size: 0.78rem; font-weight: 700; color: var(--muted); display: flex; align-items: center; padding-top: 2px; }
        .me-v { display: flex; flex-wrap: wrap; gap: 5px; font-size: 0.85rem; font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
        .me-v em { font-style: normal; color: #b0b6c6; font-weight: 600; }
        .me-chip { background: var(--soft); color: var(--blue); padding: 2px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 700; }
        .me-chip.more { background: #f1f2f6; color: var(--muted); }
        .me-warn { margin-top: 4px; background: var(--warn-bg); color: #8a5a00; font-size: 0.78rem; font-weight: 700; padding: 7px 10px; border-radius: 10px; }
        .me-link { display: inline-flex; gap: 6px; margin-top: 6px; font-size: 0.82rem; font-weight: 800; color: var(--blue); }
        .me-link:hover { text-decoration: underline; }

        /* Search bar: white bar on the blue hero */
        .filters {
            display: flex; align-items: center; gap: 8px; background: #fff; color: var(--ink);
            border-radius: 22px; padding: 8px; margin-top: 20px; box-shadow: 0 14px 34px rgba(10,16,70,0.25);
            transition: box-shadow .2s;
        }
        .filters:focus-within { box-shadow: 0 0 0 3px var(--yellow), 0 14px 34px rgba(10,16,70,0.25); }
        .search-wrap { flex: 1; display: flex; align-items: center; gap: 10px; padding: 0 14px; min-width: 240px; color: var(--muted); }
        .search-wrap input { flex: 1; min-width: 0; border: none; outline: none; background: none; font-size: 0.97rem; padding: 12px 0; color: var(--ink); }
        .sel { padding: 11px 16px; border-radius: 999px; border: none; background: var(--soft); font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .go { padding: 12px 30px; border-radius: 999px; border: none; background: var(--blue); color: #fff; font-weight: 700; cursor: pointer; transition: background .2s; }
        .go:hover { background: var(--deep); }

        /* Work type + Saved pills (on blue: see-through white, the chosen one is solid white) */
        .types { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .pill { position: relative; cursor: pointer; }
        .pill input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .pill span {
            display: inline-block; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: 0.88rem;
            background: rgba(255,255,255,0.14); color: #fff; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.28); transition: background .2s, color .2s;
        }
        .pill:hover span { background: rgba(255,255,255,0.24); }
        .pill input:checked + span { background: #fff; color: var(--blue); box-shadow: none; }
        .pill input:focus-visible + span { outline: 3px solid var(--yellow); outline-offset: 2px; }

        /* ---------- 2. Results (soft background so the white cards stand out) ---------- */
        .results { background: #f4f6fb; padding: 22px 0 72px; flex: 1; }
        main { flex: 1; display: flex; flex-direction: column; }
        #results-body { transition: opacity .2s; }
        #results.loading #results-body { opacity: 0.5; pointer-events: none; }
        .res-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px 20px; margin-bottom: 14px; }
        .res-order { font-size: 0.86rem; font-weight: 800; color: var(--ink); }
        .legend { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; font-size: 0.8rem; font-weight: 700; }
        .legend span { display: inline-flex; align-items: center; }
        .legend-k { color: var(--muted); font-weight: 600; }
        .split { display: grid; grid-template-columns: minmax(0, 1fr) 440px; gap: 24px; align-items: start; }
        .split.single { grid-template-columns: minmax(0, 1fr); }
        .feed { display: flex; flex-direction: column; gap: 14px; }
        .empty { background: #fff; border: 1.5px dashed #b9c1fb; border-radius: 22px; padding: 56px 30px; text-align: center; color: var(--muted); }
        .empty h3 { font-size: 1.2rem; color: var(--ink); margin-bottom: 6px; }
        .clear-link { display: inline-block; margin-top: 16px; padding: 9px 22px; border-radius: 999px; background: var(--blue); color: #fff; font-weight: 700; font-size: 0.88rem; transition: background .2s; }
        .clear-link:hover { background: var(--deep); }

        .avatar { width: 44px; height: 44px; border-radius: 50%; overflow: hidden; background: var(--blue); color: #fff; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar.sm { width: 36px; height: 36px; font-size: 0.8rem; }
        .tags { display: flex; flex-wrap: wrap; align-items: center; gap: 5px 6px; margin-bottom: 4px; }
        .chip { display: inline-block; background: var(--soft); color: var(--blue); padding: 3px 11px; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
        .chip-type { background: var(--blue); color: #fff; }
        .rank { background: var(--ink); color: #fff; padding: 3px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
.weak { background: #fff5da; color: #8a5a00; padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; white-space: nowrap; }
        .weak-box { background: #fff5da; border: 1px solid #f0d58a; color: #6b4600; border-radius: 14px; padding: 12px 14px; font-size: 0.86rem; line-height: 1.5; margin-bottom: 18px; }
        .weak-box b { display: block; color: #8a5a00; margin-bottom: 2px; }
        .applied { background: var(--good-bg); color: var(--good); padding: 3px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; }
        .ico { vertical-align: -2px; margin-right: 4px; }

        /* score rings (conic-gradient: --p = percent filled) */
        .ring { --p: 0; --c: var(--blue); --track: #e6e9f8; --hole: #fff; --w: 6px; position: relative; display: grid; place-items: center; border-radius: 50%; flex-shrink: 0;
                background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--track) 0); }
        .ring::before { content: ""; position: absolute; inset: var(--w); border-radius: 50%; background: var(--hole); }
        .ring b { position: relative; font-weight: 800; color: var(--ink); letter-spacing: -0.03em; }
        .ring.sm { width: 64px; height: 64px; }
        .ring.sm b { font-size: 0.74rem; }
        .ring.high { --c: var(--good); } .ring.mid { --c: #e8a100; } .ring.low { --c: #a3a9bb; }
        .ring.lg { width: 104px; height: 104px; --w: 10px; --c: var(--yellow); --track: rgba(255,255,255,0.22); --hole: var(--deep); }
        .ring.lg b { font-size: 1.2rem; color: #fff; }

        /* Listing cards */
        .gig {
            display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1.5px solid #dfe4fb; border-radius: 20px; padding: 18px 20px;
            transition: border-color .2s, transform .2s, box-shadow .2s;
        }
        .gig:hover { border-color: #9fabf7; transform: translateY(-2px); box-shadow: 0 14px 30px rgba(65,84,241,0.10); }
        .gig.selected { border-color: var(--blue); box-shadow: 0 0 0 4px var(--soft), 0 14px 30px rgba(65,84,241,0.10); }
        .gig-top { display: flex; align-items: flex-start; gap: 14px; }
        .gig-title { flex: 1; min-width: 0; }
        .gig h3 { font-size: 1.06rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.3; overflow-wrap: anywhere; }
        .gig-facts { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; font-size: 0.85rem; color: var(--muted); font-weight: 600; }
        .gig-facts > span + span::before { content: "\2022"; margin: 0 9px; color: #c3c8d8; }
        .budget { font-size: 1.02rem; font-weight: 800; color: var(--blue); }
        .splitbar { display: flex; height: 8px; border-radius: 999px; background: #e3e7f6; overflow: hidden; }
        .splitbar i { display: block; height: 100%; }
        .splitbar i.s { background: var(--c-s); } .splitbar i.l { background: var(--c-l); } .splitbar i.a { background: var(--c-a); }
        .split-keys { display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 0.74rem; color: var(--muted); font-weight: 700; margin-top: -4px; }
        .split-keys span { display: inline-flex; align-items: center; }

        /* Details panel: scrolls inside, Apply button always stays visible at the bottom */
        .inspector {
            position: sticky; top: 88px; max-height: calc(100vh - 108px); display: flex; flex-direction: column; overflow: hidden;
            background: #fff; border: 1.5px solid #cdd4fc; border-radius: 24px; box-shadow: 0 20px 50px rgba(65,84,241,0.12);
        }
        .insp-body { flex: 1; overflow-y: auto; scrollbar-width: thin; }
        .insp-hero { display: flex; align-items: center; gap: 18px; padding: 20px 24px; background: linear-gradient(160deg, var(--blue), var(--deep)); color: #fff; }
        .insp-hero-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
        .tier-pill { background: rgba(255,255,255,0.16); padding: 3px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; }
        .insp-head { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .insp-head .avatar { box-shadow: 0 0 0 2px rgba(255,255,255,0.6); }
        .insp-head .by { font-size: 0.86rem; color: #e6e9ff; overflow-wrap: anywhere; }
        .insp-head .by strong { color: #fff; }
        .insp-main { padding: 20px 24px 8px; }
        .insp-chips { margin-bottom: 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .insp-title { font-size: 1.3rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.25; margin-bottom: 16px; overflow-wrap: anywhere; }
        .insp-foot { padding: 14px 24px 20px; border-top: 1px solid var(--line); background: #fff; }
        .pay { background: var(--soft); border-radius: 16px; padding: 14px 18px; margin-bottom: 12px; }
        .pay b { display: block; font-size: 1.5rem; font-weight: 800; color: var(--blue); letter-spacing: -0.02em; line-height: 1.2; }
        .lbl { font-size: 0.82rem; font-weight: 700; color: var(--muted); margin-bottom: 4px; }
        .facts { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .fact { border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; }
        .fact div:last-child { font-weight: 700; font-size: 0.93rem; }
        .insp-sec { margin-bottom: 20px; }
        .insp-sec p { font-size: 0.95rem; color: #3d4358; line-height: 1.6; overflow-wrap: anywhere; }
        .skills { display: flex; flex-wrap: wrap; gap: 6px; }
        .skill { padding: 4px 12px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
        .skills.mini .skill { padding: 3px 10px; font-size: 0.76rem; }
        .skill.ok { background: var(--good-bg); color: var(--good); }
        .skill.no { background: #f1f2f6; color: #7a8196; }
        /* Save button (blue top of the details panel) and the small heart on saved cards */
        .save-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; border: none; background: #fff; color: var(--blue); font: inherit; font-weight: 800; font-size: 0.82rem; cursor: pointer; white-space: nowrap; transition: background .2s, color .2s, transform .2s; }
        .save-btn:hover { transform: translateY(-1px); }
        .save-btn:disabled { cursor: wait; opacity: 0.6; }
        .save-btn.on { background: var(--bad-bg); color: var(--bad); }
        .save-btn.on svg { fill: currentColor; }
        .saved-mark { display: inline-flex; color: var(--bad); line-height: 1; }
        .saved-mark svg { fill: currentColor; }
        .pill span svg { vertical-align: -2px; margin-right: 5px; }
        #saved-count { font-weight: inherit; }
        /* "Why this score?" box: one coloured bar per part (same colours as the card bars) */
        .why { border: 1px solid var(--line); border-radius: 16px; padding: 14px 16px; background: #fbfcff; }
        .why-row + .why-row { margin-top: 12px; }
        .why-top { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-weight: 700; font-size: 0.93rem; }
        .why-top > span:first-child { display: inline-flex; align-items: center; }
        .why-top .pts { color: var(--ink); font-weight: 800; white-space: nowrap; }
        .why-top .pts small { color: var(--muted); font-weight: 700; font-size: 0.82rem; }
        .why-bar { height: 7px; border-radius: 999px; background: #e3e7f6; margin: 6px 0 4px; overflow: hidden; }
        .why-bar span { display: block; height: 100%; border-radius: 999px; background: var(--blue); }
        .why-bar.s span { background: var(--c-s); } .why-bar.l span { background: var(--c-l); } .why-bar.a span { background: var(--c-a); }
        .why-note { font-size: 0.82rem; color: var(--muted); }
        .why-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--line); font-weight: 800; }
        .why-total b { font-size: 1.1rem; color: var(--blue); }
        .apply { width: 100%; padding: 15px; border: none; border-radius: 999px; background: var(--blue); color: #fff; font-weight: 800; font-size: 1rem; cursor: pointer; transition: background .2s, transform .2s; }
        .apply:hover { background: var(--deep); transform: translateY(-2px); }
        .apply:disabled { background: #aab0c4; cursor: wait; transform: none; }
        .apply.done, .apply.done:disabled { background: var(--good-bg); color: var(--good); cursor: default; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1100px) {
            .search-wrap { min-width: 120px; }   /* lets the search box shrink so the Search button never sticks out on tablets */
            .split { grid-template-columns: 1fr; }
            .inspector { position: static; max-height: none; overflow: visible; }
            .insp-body { overflow: visible; }
        }
        @media (max-width: 900px) {
            .hero-row { flex-direction: column; align-items: stretch; }
            .me { width: 100%; }
            /* tablets: the menu moves to its own row (it was too wide for the bar) */
            .jb-nav { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .jb-links { order: 3; width: 100%; overflow-x: auto; padding-bottom: 4px; }
            .jb-link { white-space: nowrap; }
        }
        @media (max-width: 760px) {
            .jb-nav { flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
            .jb-links { order: 3; width: 100%; overflow-x: auto; padding-bottom: 4px; }
            .jb-link { white-space: nowrap; }
            .wrap { padding: 0 16px; }
            .pg-head { padding: 18px 0 20px; }
            .pg-head h1 { font-size: 1.55rem; }
            .filters { flex-wrap: wrap; }
            .search-wrap { flex-basis: 100%; min-width: 0; }
            .sel { flex: 1; min-width: 0; }
            .go { flex-basis: 100%; }
            .results { padding: 18px 0 56px; }
            .insp-hero { padding: 16px 18px; gap: 14px; }
            .ring.lg { width: 84px; height: 84px; --w: 8px; }
            .ring.lg b { font-size: 1rem; }
            .insp-main { padding: 18px 18px 6px; }
            .insp-foot { padding: 14px 18px 18px; }
            .facts { grid-template-columns: 1fr; }
            .gig { padding: 16px; }
            .gig-top > .avatar { display: none; }      /* more room for the title; the poster is shown in the details panel */
            .ring.sm b { font-size: 0.7rem; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } html { scroll-behavior: auto; } }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="jb-nav">
        <a href="dashboard.php"><img src="Logo.jpg" alt="JobiT Logo" class="jb-logo"></a>

        <nav class="jb-links">
            <a href="dashboard.php" class="jb-link">Home</a>
            <a href="browse.php" class="jb-link active">Browse</a>
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

        <!-- Notice bar (only appears if the page was loaded the normal way after applying) -->
        <div id="notice-slot">
            <?php if (!empty($applicationMessage)): ?>
                <div class="notice"><div class="wrap"><?php echo htmlspecialchars($applicationMessage); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- 1. BLUE HERO: what this page is, your match profile, and the search -->
        <section class="pg-head">
            <div class="wrap">
                <div class="hero-row">
                    <div class="hero-copy">
                        <div class="crumbs"><a href="dashboard.php">Home</a> &rsaquo; <strong>Browse</strong></div>
                        <span class="eyebrow">&#9733; Marketplace</span>
                        <h1>Marketplace Opportunities</h1>
                        <p class="lead" id="hero-lead">Showing <?php echo count($filteredListings); ?><?php echo ($selectedWorkType !== 'all' && $workTypeColumn !== null) ? ' ' . htmlspecialchars($workTypeLabels[$selectedWorkType]) : ''; ?><?php echo $savedOnly ? ' saved' : ''; ?> opportunities available for application based on your profile.</p>
                    </div>

                    <?php
                        // What every score on this page is calculated from: your own profile
                        $mySkills = cleanList($userSkillsStr);
                        $mySlots = cleanList($userSlotsStr);
                        $myPic = picUrl($userAvatar);
                        $missingParts = [];
                        if (empty($mySkills)) $missingParts[] = 'skills';
                        if (trim((string)$userLocation) === '') $missingParts[] = 'city';
                        if (empty($mySlots)) $missingParts[] = 'schedule';
                    ?>
                    <aside class="me" aria-label="Your match profile">
                        <div class="me-head">
                            <div class="avatar sm"><?php if ($myPic !== ''): ?><img src="<?php echo htmlspecialchars($myPic, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars($userInitials); ?><?php endif; ?></div>
                            <div><span class="me-label">Scores are based on</span><b class="me-name">Your match profile</b></div>
                        </div>
                        <div class="me-row"><span class="me-k"><i class="dot s"></i>Skills</span><div class="me-v">
                            <?php if (empty($mySkills)): ?><em>Not set</em><?php else: ?>
                                <?php foreach (array_slice($mySkills, 0, 4) as $sk): ?><span class="me-chip"><?php echo htmlspecialchars($sk); ?></span><?php endforeach; ?>
                                <?php if (count($mySkills) > 4): ?><span class="me-chip more">+<?php echo count($mySkills) - 4; ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div></div>
                        <div class="me-row"><span class="me-k"><i class="dot l"></i>City</span><div class="me-v"><?php echo trim((string)$userLocation) === '' ? '<em>Not set</em>' : htmlspecialchars($userLocation); ?></div></div>
                        <div class="me-row"><span class="me-k"><i class="dot a"></i>Free on</span><div class="me-v">
                            <?php if (empty($mySlots)): ?><em>Not set</em><?php else: ?>
                                <?php foreach ($mySlots as $sl): ?><span class="me-chip"><?php echo htmlspecialchars($sl); ?></span><?php endforeach; ?>
                            <?php endif; ?>
                        </div></div>
                        <?php if (!empty($missingParts)): ?><p class="me-warn">Add your <?php echo htmlspecialchars(implode(', ', $missingParts)); ?> to get better matches.</p><?php endif; ?>
                        <a href="profile.php" class="me-link">Update profile <span aria-hidden="true">&rarr;</span></a>
                    </aside>
                </div>

                <!-- Search & Filter Bar -->
                <form action="browse.php" method="GET" class="filter-form" id="filterForm">
                    <div class="filters">
                        <label class="search-wrap">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search by job title or keyword...">
                        </label>
                        <select name="category" class="sel" aria-label="Category">
                            <option value="all">All Categories</option>
                            <option value="Corporate & BPO" <?php echo $selectedCategory === 'Corporate & BPO' ? 'selected' : ''; ?>>Corporate & BPO</option>
                            <option value="IT & Tech" <?php echo $selectedCategory === 'IT & Tech' ? 'selected' : ''; ?>>IT & Tech</option>
                            <option value="Tutoring & Education" <?php echo $selectedCategory === 'Tutoring & Education' ? 'selected' : ''; ?>>Tutoring & Education</option>
                            <option value="Trades & Labor" <?php echo $selectedCategory === 'Trades & Labor' ? 'selected' : ''; ?>>Trades & Labor</option>
                            <option value="Food & Culinary" <?php echo $selectedCategory === 'Food & Culinary' ? 'selected' : ''; ?>>Food & Culinary</option>
                            <option value="Design" <?php echo $selectedCategory === 'Design' ? 'selected' : ''; ?>>Design</option>
                            <option value="General Services" <?php echo $selectedCategory === 'General Services' ? 'selected' : ''; ?>>General Services</option>
                            <option value="Healthcare & Wellness" <?php echo $selectedCategory === 'Healthcare & Wellness' ? 'selected' : ''; ?>>Healthcare & Wellness</option>
                            <option value="Sales & Marketing" <?php echo $selectedCategory === 'Sales & Marketing' ? 'selected' : ''; ?>>Sales & Marketing</option>
                            <option value="Writing & Content" <?php echo $selectedCategory === 'Writing & Content' ? 'selected' : ''; ?>>Writing & Content</option>
                            <option value="Admin & Virtual Assistance" <?php echo $selectedCategory === 'Admin & Virtual Assistance' ? 'selected' : ''; ?>>Admin & Virtual Assistance</option>
                            <option value="Delivery & Driving" <?php echo $selectedCategory === 'Delivery & Driving' ? 'selected' : ''; ?>>Delivery & Driving</option>
                            <option value="Cleaning & Household" <?php echo $selectedCategory === 'Cleaning & Household' ? 'selected' : ''; ?>>Cleaning & Household</option>
                        </select>

                        <select name="sort" class="sel" aria-label="Sort by">
                            <option value="match" <?php echo $selectedSort === 'match' ? 'selected' : ''; ?>>Best Match Score</option>
                            <option value="newest" <?php echo $selectedSort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="budget_desc" <?php echo $selectedSort === 'budget_desc' ? 'selected' : ''; ?>>Highest Budget</option>
                        </select>

                        <button type="submit" class="go">Search</button>
                    </div>

                    <!-- Work type: Short Gig / Part-Time / Full-Time (the dashboard cards link here) -->
                    <?php if ($workTypeColumn !== null || empty($dbListings) || $userId): ?>
                    <div class="types" role="group" aria-label="Work type and saved listings">
                        <?php if ($workTypeColumn !== null || empty($dbListings)): ?>
                            <?php foreach (['all' => 'All Types'] + $workTypeLabels as $typeValue => $typeText): ?>
                                <label class="pill">
                                    <input type="radio" name="work_type" value="<?php echo $typeValue; ?>" <?php echo $selectedWorkType === $typeValue ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($typeText); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($userId): ?>
                            <!-- Saved: shows only the listings you saved with the Save button -->
                            <label class="pill">
                                <input type="checkbox" name="saved" value="1" <?php echo $savedOnly ? 'checked' : ''; ?>>
                                <span><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>Saved (<b id="saved-count"><?php echo $savedCount; ?></b>)</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <!-- 2. RESULTS: list on the left, details on the right (this block is refreshed in the background, no page reload) -->
        <section class="results" id="results">
            <div class="wrap" id="results-body">

                <div class="res-bar">
                    <span class="res-order">
                        <?php if ($selectedSort === 'match'): ?>Ranked best match first
                        <?php elseif ($selectedSort === 'newest'): ?>Newest posts first
                        <?php else: ?>Highest budget first<?php endif; ?>
                    </span>
                    <div class="legend" aria-label="How the match score works">
                        <span class="legend-k">How we score</span>
                        <span><i class="dot s"></i>Skills 50</span>
                        <span><i class="dot l"></i>Location 30</span>
                        <span><i class="dot a"></i>Availability 20</span>
                    </div>
                </div>

                <div class="split<?php echo empty($filteredListings) ? ' single' : ''; ?>">

                    <!-- Left Column: Feed of Gigs -->
                    <div class="feed">
                        <?php if (empty($filteredListings)): ?>
                            <div class="empty">
                                <?php if ($savedOnly && $savedCount === 0): ?>
                                    <h3>No saved listings yet</h3>
                                    <p>Open a listing and press Save to keep it here for later.</p>
                                <?php else: ?>
                                    <h3>No opportunities found</h3>
                                    <p>Try adjusting your search filters or check back later for new postings.</p>
                                <?php endif; ?>
                                <?php if ($searchQuery !== '' || $selectedCategory !== 'all' || $selectedWorkType !== 'all' || $savedOnly): ?>
                                    <a href="browse.php" class="clear-link" data-ajax>Clear all filters</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php $rank = 0; foreach ($filteredListings as $gig): $rank++; ?>
                                <?php
                                    $isSelected = ($currentInspectGig && $currentInspectGig['id'] === $gig['id']);
                                    $tier = matchTier($gig['match_score']);
                                    $typeLabel = $workTypeLabels[$gig['work_type']] ?? '';
                                    $posterPic = picUrl($gig['poster_avatar']);
                                    // Keeps your search, category, sort and work type when you pick a listing
                                    $inspectUrl = 'browse.php?inspect=' . $gig['id']
                                        . (!empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '')
                                        . ($selectedCategory !== 'all' ? '&category=' . urlencode($selectedCategory) : '')
                                        . ($selectedSort !== 'match' ? '&sort=' . urlencode($selectedSort) : '')
                                        . ($selectedWorkType !== 'all' ? '&work_type=' . urlencode($selectedWorkType) : '')
                                        . ($savedOnly ? '&saved=1' : '');
                                ?>
                                <a href="<?php echo htmlspecialchars($inspectUrl); ?>" class="gig <?php echo $tier; ?><?php echo $isSelected ? ' selected' : ''; ?>">

                                    <div class="gig-top">
                                        <div class="avatar">
                                            <?php if ($posterPic !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($posterPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(strtoupper(substr($gig['posted_by'], 0, 2))); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="gig-title">
                                            <div class="tags">
                                                <?php if ($selectedSort === 'match'): ?><span class="rank">#<?php echo $rank; ?></span><?php endif; ?>
                                                <span class="chip"><?php echo htmlspecialchars($gig['category']); ?></span>
                                                <?php if ($typeLabel !== ''): ?><span class="chip chip-type"><?php echo htmlspecialchars($typeLabel); ?></span><?php endif; ?>
                                                <?php if ($gig['applied']): ?><span class="applied">&#10003; Applied</span><?php endif; ?>
                                                <?php if (isWeakSkillMatch($gig['breakdown'])): ?><span class="weak" title="You have <?php echo $gig['breakdown']['skills_have']; ?> of <?php echo $gig['breakdown']['skills_total']; ?> required skills. Most of this score comes from city and schedule.">&#9888; Weak skill match</span><?php endif; ?>
                                                <?php if ($gig['saved']): ?><span class="saved-mark" title="Saved"><svg viewBox="0 0 24 24" width="15" height="15" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-label="Saved"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg></span><?php endif; ?>
                                            </div>
                                            <h3><?php echo htmlspecialchars($gig['title']); ?></h3>
                                        </div>
                                        <!-- score ring: the coloured part is the match percent -->
                                        <span class="ring sm <?php echo $tier; ?>" style="--p: <?php echo round($gig['match_score']); ?>;" title="<?php echo tierLabel($gig['match_score']); ?>"><b class="score-num"><?php echo $gig['match_score']; ?>%</b></span>
                                    </div>

                                    <div class="gig-facts">
                                        <span class="budget">&#8369;<?php echo number_format($gig['budget_min']); ?> - &#8369;<?php echo number_format($gig['budget_max']); ?></span>
                                        <span><?php echo $pinIcon . htmlspecialchars($gig['location']); ?></span>
                                        <span><?php echo htmlspecialchars($gig['posted_date']); ?></span>
                                    </div>

                                    <!-- one bar = the whole score, split into its three coloured parts -->
                                    <div class="splitbar" role="img" aria-label="Skills <?php echo formatPoints($gig['breakdown']['skills_points']); ?>, Location <?php echo formatPoints($gig['breakdown']['location_points']); ?>, Availability <?php echo formatPoints($gig['breakdown']['availability_points']); ?> out of 100">
                                        <?php foreach ($scoreParts as $p): ?><i class="<?php echo $p[0]; ?>" style="width: <?php echo $gig['breakdown'][$p[2]]; ?>%;"></i><?php endforeach; ?>
                                    </div>
                                    <div class="split-keys">
                                        <?php foreach ($scoreParts as $p): ?><span><i class="dot <?php echo $p[0]; ?>"></i><?php echo $p[1]; ?> <?php echo formatPoints($gig['breakdown'][$p[2]]); ?>/<?php echo $p[3]; ?></span><?php endforeach; ?>
                                    </div>

                                    <?php if (!empty($gig['matched_skills'])): ?>
                                        <div class="skills mini">
                                            <?php foreach (array_slice($gig['matched_skills'], 0, 3) as $ms): ?><span class="skill ok">&#10003; <?php echo htmlspecialchars($ms); ?></span><?php endforeach; ?>
                                            <?php if (count($gig['matched_skills']) > 3): ?><span class="skill ok">+<?php echo count($gig['matched_skills']) - 3; ?></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Details Panel (hidden when the filters match nothing) -->
                    <?php if ($currentInspectGig && !empty($filteredListings)): ?>
                        <?php
                            $inspectTypeLabel = $workTypeLabels[$currentInspectGig['work_type']] ?? '';
                            $inspectPic = picUrl($currentInspectGig['poster_avatar']);
                        ?>
                        <aside class="inspector" id="inspector">

                            <div class="insp-body">
                                <!-- blue top: big score ring + who posted it + Save -->
                                <div class="insp-hero">
                                    <span class="ring lg" style="--p: <?php echo round($currentInspectGig['match_score']); ?>;"><b class="score-num"><?php echo $currentInspectGig['match_score']; ?>%</b></span>
                                    <div class="insp-hero-txt">
                                        <span class="tier-pill"><?php echo tierLabel($currentInspectGig['match_score']); ?></span>
                                        <div class="insp-head">
                                            <div class="avatar sm">
                                                <?php if ($inspectPic !== ''): ?>
                                                    <img src="<?php echo htmlspecialchars($inspectPic, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars(strtoupper(substr($currentInspectGig['posted_by'], 0, 2))); ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="by">Posted by <strong><?php echo htmlspecialchars($currentInspectGig['posted_by']); ?></strong></span>
                                        </div>
                                        <?php if ($userId): ?>
                                            <form action="browse.php" method="POST" class="save-form">
                                                <input type="hidden" name="action" value="<?php echo $currentInspectGig['saved'] ? 'unsave_gig' : 'save_gig'; ?>">
                                                <input type="hidden" name="listing_id" value="<?php echo $currentInspectGig['id']; ?>">
                                                <button type="submit" class="save-btn<?php echo $currentInspectGig['saved'] ? ' on' : ''; ?>" aria-pressed="<?php echo $currentInspectGig['saved'] ? 'true' : 'false'; ?>">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg><span><?php echo $currentInspectGig['saved'] ? 'Saved' : 'Save'; ?></span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="insp-main">
                                    <div class="insp-chips">
                                        <span class="chip"><?php echo htmlspecialchars($currentInspectGig['category']); ?></span>
                                        <?php if ($inspectTypeLabel !== ''): ?><span class="chip chip-type"><?php echo htmlspecialchars($inspectTypeLabel); ?></span><?php endif; ?>
                                        <?php if ($currentInspectGig['applied']): ?><span class="applied">&#10003; Applied</span><?php endif; ?>
                                    </div>

                                    <h3 class="insp-title"><?php echo htmlspecialchars($currentInspectGig['title']); ?></h3>

                                    <div class="pay">
                                        <div class="lbl">Compensation</div>
                                        <b>&#8369;<?php echo number_format($currentInspectGig['budget_min']); ?> - &#8369;<?php echo number_format($currentInspectGig['budget_max']); ?></b>
                                    </div>

                                    <div class="facts">
                                        <div class="fact">
                                            <div class="lbl">Location</div>
                                            <div><?php echo $pinIcon . htmlspecialchars($currentInspectGig['location']); ?></div>
                                        </div>
                                        <div class="fact">
                                            <div class="lbl">Schedule</div>
                                            <div><?php echo htmlspecialchars($currentInspectGig['schedule']); ?></div>
                                        </div>
                                    </div>

                                    <div class="insp-sec">
                                        <div class="lbl">Description</div>
                                        <p><?php echo nl2br(htmlspecialchars($currentInspectGig['description'])); ?></p>
                                    </div>

                                    <div class="insp-sec">
                                        <div class="lbl">Required Skills &amp; Compatibility</div>
                                        <div class="skills">
                                            <?php foreach ($currentInspectGig['matched_skills'] as $ms): ?>
                                                <span class="skill ok">&#10003; <?php echo htmlspecialchars($ms); ?></span>
                                            <?php endforeach; ?>
                                            <?php foreach ($currentInspectGig['missing_skills'] as $mis): ?>
                                                <span class="skill no">Missing: <?php echo htmlspecialchars($mis); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <?php
                                        // The three parts of this listing's match score (same numbers as the percent at the top)
                                        $why = $currentInspectGig['breakdown'];
                                        if ($why['skills_total'] === 0) {
                                            $skillsNote = 'This job lists no required skills, so you get the full points.';
                                        } else {
                                            $skillsNote = 'You have ' . $why['skills_have'] . ' of ' . $why['skills_total'] . ' required skill' . ($why['skills_total'] === 1 ? '' : 's') . '.';
                                        }
                                        if ($why['location_result'] === 'same') {
                                            $locationNote = 'Same city as the job: full points.';
                                        } elseif ($why['location_result'] === 'different') {
                                            $locationNote = 'Different city: half points.';
                                        } else {
                                            $locationNote = 'Location is missing on the job or on your profile: no points.';
                                        }
                                        if ($why['availability_total'] === 0) {
                                            $availabilityNote = 'This job lists no schedule, so you get the full points.';
                                        } else {
                                            $availabilityNote = 'You are free for ' . $why['availability_have'] . ' of ' . $why['availability_total'] . ' schedule option' . ($why['availability_total'] === 1 ? '' : 's') . '.';
                                        }
                                        $whyRows = [
                                            ['s', 'Skills', $why['skills_points'], 50, $skillsNote],
                                            ['l', 'Location', $why['location_points'], 30, $locationNote],
                                            ['a', 'Availability', $why['availability_points'], 20, $availabilityNote]
                                        ];
                                    ?>
                                    <?php if (isWeakSkillMatch($why)): ?>
                                        <!-- warning only: the score stays the same -->
                                        <div class="weak-box"><b>&#9888; Weak skill match</b> You have only <?php echo $why['skills_have']; ?> of <?php echo $why['skills_total']; ?> required skills. Most of this score comes from your city and schedule, so check the job before you apply.</div>
                                    <?php endif; ?>
                                    <div class="insp-sec">
                                        <div class="lbl">Why this score?</div>
                                        <div class="why">
                                            <?php foreach ($whyRows as $whyRow): ?>
                                                <div class="why-row">
                                                    <div class="why-top">
                                                        <span><i class="dot <?php echo $whyRow[0]; ?>"></i><?php echo $whyRow[1]; ?></span>
                                                        <span class="pts"><?php echo formatPoints($whyRow[2]); ?> <small>/ <?php echo $whyRow[3]; ?></small></span>
                                                    </div>
                                                    <div class="why-bar <?php echo $whyRow[0]; ?>" aria-hidden="true"><span style="width: <?php echo round($whyRow[2] / $whyRow[3] * 100, 2); ?>%;"></span></div>
                                                    <div class="why-note"><?php echo htmlspecialchars($whyRow[4]); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="why-total"><span>Total match</span><b><?php echo $currentInspectGig['match_score']; ?>%</b></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Apply button stays visible at the bottom of the panel -->
                            <div class="insp-foot">
                                <form action="browse.php" method="POST" class="apply-form">
                                    <input type="hidden" name="action" value="apply_gig">
                                    <input type="hidden" name="listing_id" value="<?php echo $currentInspectGig['id']; ?>">
                                    <?php if ($currentInspectGig['applied']): ?>
                                        <button type="submit" class="apply done" disabled>&#10003; Already Applied</button>
                                    <?php else: ?>
                                        <button type="submit" class="apply">Apply / Submit Proposal</button>
                                    <?php endif; ?>
                                </form>
                            </div>

                        </aside>
                    <?php endif; ?>

                </div>
            </div>
        </section>

    </main>

    <!-- Small message that pops up after applying -->
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <!-- Dropdown Script -->
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

        // On phones/tablets the details panel sits below the list, so scroll to it after picking a gig
        if (window.innerWidth <= 1100 && location.search.indexOf('inspect=') !== -1) {
            var panel = document.getElementById('inspector');
            if (panel) { panel.scrollIntoView(); }
        }
    </script>

    <!-- Smooth updates: no full page reload -->
    <script>
        // ==========================================================================
        // Searching, filtering, picking a listing and applying now update the page
        // WITHOUT reloading it. PHP still does all the real work (filters, match score,
        // sorting, saving the application). JavaScript only asks PHP for the page in the
        // background and swaps in the part that changed. If anything goes wrong it falls
        // back to a normal page load, so nothing breaks.
        // ==========================================================================
        (function () {
            var form = document.getElementById('filterForm');
            var results = document.getElementById('results');
            var resultsBody = document.getElementById('results-body');
            var heroLead = document.getElementById('hero-lead');
            var toast = document.getElementById('toast');
            var latestRequest = 0;   // so an older, slower response never replaces a newer one
            var toastTimer = null;
            var searchTimer = null;

            // Small message at the bottom of the screen (green = success, red = error)
            function showToast(message) {
                toast.textContent = message;
                toast.className = 'toast show' + (/success/i.test(message) ? ' ok' : (/error/i.test(message) ? ' err' : ''));
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 4500);
            }

            // Put the new HTML from PHP into the page. Returns false if it is not the browse page
            // (for example the session expired and PHP sent the login page).
            function swapIn(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newBody = doc.getElementById('results-body');
                if (!newBody) { return false; }

                resultsBody.innerHTML = newBody.innerHTML;

                var newLead = doc.getElementById('hero-lead');
                if (newLead) { heroLead.textContent = newLead.textContent; }

                // The number on the Saved pill sits above the list, so it is refreshed here too
                var newSaved = doc.getElementById('saved-count');
                var oldSaved = document.getElementById('saved-count');
                if (newSaved && oldSaved) { oldSaved.textContent = newSaved.textContent; }

                // The top notice bar is replaced by the toast from now on
                var slot = document.getElementById('notice-slot');
                if (slot) { slot.innerHTML = ''; }
                var notice = doc.querySelector('.notice');
                if (notice) { showToast(notice.textContent.trim()); }
                return true;
            }

            // On phones/tablets the details panel is under the list, so bring it into view
            function scrollToDetails() {
                if (window.innerWidth <= 1100) {
                    var panel = document.getElementById('inspector');
                    if (panel) { panel.scrollIntoView({ behavior: 'smooth' }); }
                }
            }

            // Make the search box, dropdowns and pills match the address (used by Back/Forward and "Clear all filters")
            function syncFormFromUrl() {
                var p = new URLSearchParams(window.location.search);
                form.elements['search'].value = p.get('search') || '';
                form.elements['category'].value = p.get('category') || 'all';
                form.elements['sort'].value = p.get('sort') || 'match';
                if (form.elements['saved']) { form.elements['saved'].checked = (p.get('saved') === '1'); }
                if (form.elements['work_type']) { form.elements['work_type'].value = p.get('work_type') || 'all'; }
            }

            // Load a browse.php address in the background.
            // mode: 'push' = add to browser history (Back button works), 'replace' = update the address only, 'none' = leave it
            function loadPage(url, mode, goToDetails, syncForm) {
                var myRequest = ++latestRequest;
                results.classList.add('loading');

                fetch(url, { credentials: 'same-origin' })
                    .then(function (res) {
                        // Sent somewhere else (like the login page)? Do a normal page load instead.
                        if (res.redirected && res.url.indexOf('browse.php') === -1) { throw new Error('redirected'); }
                        return res.text();
                    })
                    .then(function (html) {
                        if (myRequest !== latestRequest) { return; }
                        if (!swapIn(html)) { throw new Error('unexpected page'); }
                        if (mode === 'push') { history.pushState(null, '', url); }
                        if (mode === 'replace') { history.replaceState(null, '', url); }
                        if (syncForm) { syncFormFromUrl(); }
                        if (goToDetails) { scrollToDetails(); }
                    })
                    .catch(function () {
                        if (myRequest === latestRequest) { window.location.href = url; }
                    })
                    .then(function () {
                        if (myRequest === latestRequest) { results.classList.remove('loading'); }
                    });
            }

            // Build the browse.php address from the search box, the two dropdowns and the type pills
            function formUrl() {
                var params = new URLSearchParams(new FormData(form));
                if (!params.get('search')) { params.delete('search'); }
                return 'browse.php?' + params.toString();
            }

            // Search button / Enter key
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearTimeout(searchTimer);
                loadPage(formUrl(), 'push', false, false);
            });

            // Category, sort and work type refresh right away
            form.addEventListener('change', function (e) {
                var name = e.target.name;
                if (name === 'category' || name === 'sort' || name === 'work_type' || name === 'saved') {
                    loadPage(formUrl(), 'push', false, false);
                }
            });

            // Search while typing (waits until you pause for a moment)
            form.elements['search'].addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { loadPage(formUrl(), 'replace', false, false); }, 350);
            });

            // Clicking a listing card shows its details without reloading.
            // Links marked data-ajax (like "Clear all filters") also load in the background.
            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a.gig, a[data-ajax]') : null;
                if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
                e.preventDefault();
                var isCard = link.classList.contains('gig');
                loadPage(link.href, 'push', isCard, !isCard);
            });

            // Apply / Submit Proposal and Save / Saved without reloading
            document.addEventListener('submit', function (e) {
                var applyForm = e.target;
                if (!applyForm.classList) { return; }
                var isApply = applyForm.classList.contains('apply-form');
                var isSave = applyForm.classList.contains('save-form');
                if (!isApply && !isSave) { return; }
                e.preventDefault();

                var button = applyForm.querySelector('button');
                button.disabled = true;
                if (isApply) { button.textContent = 'Submitting...'; }

                // Send it to the current address so the same filters and the same listing stay on screen
                fetch(window.location.href, { method: 'POST', body: new FormData(applyForm), credentials: 'same-origin' })
                    .then(function (res) {
                        if (res.redirected && res.url.indexOf('browse.php') === -1) { throw new Error('redirected'); }
                        return res.text();
                    })
                    .then(function (html) {
                        if (!swapIn(html)) { throw new Error('unexpected page'); }
                    })
                    .catch(function () {
                        button.disabled = false;
                        if (isApply) {
                            button.textContent = 'Apply / Submit Proposal';
                            showToast('Error submitting application. Please try again.');
                        } else {
                            showToast('Error saving this listing. Please try again.');
                        }
                    });
            });

            // Back / Forward buttons
            window.addEventListener('popstate', function () {
                syncFormFromUrl();
                loadPage(window.location.href, 'none', false, false);
            });

            // (Old "How matching works" popover: replaced by the score key above the list. Kept so nothing breaks if it comes back.)
            var how = document.querySelector('details.how');
            document.addEventListener('click', function (e) {
                if (how && how.open && !how.contains(e.target)) { how.open = false; }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && how && how.open) { how.open = false; }
            });
        })();
    </script>

</body>
</html>
