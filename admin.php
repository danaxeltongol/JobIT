<?php
// ============================================================================
// JOBIT ADMIN OVERSIGHT & MODERATION DASHBOARD
//
// What this page does:
//   - Lists every listing and every user, with SEARCH, FILTERS and PAGE NUMBERS (10 per page)
//   - Lets the admin delete a listing or ban (delete) a user
//   - The admin only moderates here: there is no "switch to user view" any more
//
// How the lists work (simple version):
//   1. The database finds the rows that match the search and filters (WHERE ... LIKE ...)
//   2. OUR OWN merge sort (sorting.php) puts them newest first
//   3. We cut out the 10 rows of the page the admin is on
// ============================================================================

session_start();
require_once 'db.php';
require_once __DIR__ . '/sorting.php';          // our own merge sort (no ORDER BY, no usort)

$userId = $_SESSION['user_id'] ?? null;

// 1. Strict Security Block: Only authorized users can load this page
if (!$userId) {
    header("Location: auth.php?mode=login");
    exit();
}

$currentUser = null;
try {
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $currentUser = $userStmt->fetch();
} catch (PDOException $e) {}

// If user is NOT an admin, banish them back to user dashboard immediately
if (!$currentUser || ($currentUser['role'] ?? 'user') !== 'admin') {
    header("Location: dashboard.php?error=unauthorized_access");
    exit();
}

// Log out: really end the session (the old link only went to the home page)
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit();
}

// 2. Setup Admin Profile details for top right
$userName = $currentUser['full_name'] ?? 'System Admin';
$userInitials = 'AD';
$userAvatar = $currentUser['avatar'] ?? '';

// A secret code that every delete button carries, so a delete can only come from THIS page
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['admin_csrf'];

// The filters a page can have (so we can keep them when the page reloads or after a delete)
$filterKeys = ['q', 'category', 'wtype', 'status', 'role', 'page'];

// 3. Handle Admin Moderation Actions (Delete listing or delete user)
//    Deletes are sent with POST (not a link), with the secret code, then we come back to the same page of the list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $action = $_POST['action'];
    $targetId = intval($_POST['id']);
    $message = '';

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = "ERROR: This request could not be verified. Please try again.";
    } else {
        try {
            if ($action === 'delete_listing') {
                $del = $pdo->prepare("DELETE FROM listings WHERE id = ?");
                $del->execute([$targetId]);
                $message = "SUCCESS: Listing #{$targetId} has been permanently deleted from JobiT.";
            } elseif ($action === 'delete_user' && $targetId != $userId) {
                // Never delete an admin account (and an admin cannot delete themselves)
                $roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $roleStmt->execute([$targetId]);
                if ($roleStmt->fetchColumn() === 'admin') {
                    $message = "ERROR: Admin accounts are protected and cannot be deleted here.";
                } else {
                    $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$targetId]);
                    $message = "SUCCESS: User account #{$targetId} and all associated data have been permanently removed.";
                }
            }
        } catch (Exception $e) {
            $message = "ERROR: Failed to perform moderation action.";
        }
    }

    // Remember the message for the next page load, then go back to the same tab, filters and page
    $_SESSION['admin_flash'] = $message;
    parse_str((string)($_POST['return'] ?? ''), $back);
    $backParams = [];
    foreach (array_merge(['tab'], $filterKeys) as $k) {
        if (isset($back[$k]) && is_string($back[$k]) && $back[$k] !== '') {
            $backParams[$k] = $back[$k];
        }
    }
    header("Location: admin.php" . ($backParams ? '?' . http_build_query($backParams) : ''));
    exit();
}

$actionMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

// 4. Read the tab, the search and the filters from the address
$activeTab = (($_GET['tab'] ?? 'listings') === 'users') ? 'users' : 'listings';
$perPage = 10;

$q         = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$fCategory = trim((string)($_GET['category'] ?? ''));
$fType     = trim((string)($_GET['wtype'] ?? ''));
$fStatus   = trim((string)($_GET['status'] ?? ''));
$fRole     = (($_GET['role'] ?? '') === 'admin' || ($_GET['role'] ?? '') === 'user') ? $_GET['role'] : '';
$page      = max(1, intval($_GET['page'] ?? 1));

// The words typed in the search box, made safe for LIKE ("%" and "_" count as normal letters)
// "|" is the escape character, written the same way for MySQL and every other database.
$like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';

// Builds a link to the admin page with the current search/filters, changing only what we say
// Example: adminUrl('listings', $state, ['page' => 3])
function adminUrl($tab, $state, $changes = []) {
    $params = array_merge($state, $changes);
    $out = ['tab' => $tab];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'page' && $value <= 1)) {
            continue;                                   // empty filters and page 1 are left out of the link
        }
        $out[$key] = $value;
    }
    return 'admin.php?' . http_build_query($out);
}

// The page numbers to show: 1 ... 4 5 6 ... 12  (first, last and the ones next to the current page)
function adminPageNumbers($current, $last) {
    $numbers = [];
    for ($p = 1; $p <= $last; $p++) {
        if ($p == 1 || $p == $last || abs($p - $current) <= 1) {
            $numbers[] = $p;
        } elseif (end($numbers) !== '...') {
            $numbers[] = '...';
        }
    }
    return $numbers;
}

// The different values of one column of the listings table, for example every category in use.
// If the column does not exist yet, we just return an empty list (that drop-down is then hidden or empty).
function adminDistinct($pdo, $column) {
    try {
        $result = $pdo->query("SELECT DISTINCT $column FROM listings WHERE $column IS NOT NULL AND $column <> ''");
        return $result->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

// 5. Fetch Global Platform Statistics & Data
$totalUsers = 0;
$totalListings = 0;
$totalApplications = 0;
$rows = [];              // the rows of the page we are showing
$matchCount = 0;         // how many rows match the search and filters (all pages)
$categoryOptions = [];
$typeOptions = [];
$statusOptions = [];

try {
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalListings = $pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn();
    $totalApplications = $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
} catch (Exception $e) {}

try {
    if ($activeTab === 'listings') {
        // The choices inside the three drop-downs come from what is really in the database
        $categoryOptions = adminDistinct($pdo, 'category');
        $typeOptions     = adminDistinct($pdo, 'work_type');
        $statusOptions   = adminDistinct($pdo, 'status');

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(l.title LIKE ? ESCAPE '|' OR u.full_name LIKE ? ESCAPE '|' OR l.location LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like);
        }
        if ($fCategory !== '') { $where[] = "l.category = ?";  $params[] = $fCategory; }
        if ($fType !== '')     { $where[] = "l.work_type = ?"; $params[] = $fType; }
        if ($fStatus !== '')   { $where[] = "l.status = ?";    $params[] = $fStatus; }

        $sql = "SELECT l.*, u.full_name AS posted_by FROM listings l JOIN users u ON l.user_id = u.id";
        if ($where) { $sql .= " WHERE " . implode(" AND ", $where); }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(full_name LIKE ? ESCAPE '|' OR email LIKE ? ESCAPE '|' OR location LIKE ? ESCAPE '|' OR skills LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like, $like);
        }
        if ($fRole === 'admin') { $where[] = "role = 'admin'"; }
        if ($fRole === 'user')  { $where[] = "(role IS NULL OR role <> 'admin')"; }

        $sql = "SELECT id, full_name, email, location, role, created_at FROM users";
        if ($where) { $sql .= " WHERE " . implode(" AND ", $where); }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Newest first, using OUR merge sort on the time the row was created (as a number)
foreach ($rows as $i => $row) {
    $rows[$i]['created_ts'] = strtotime($row['created_at'] ?? '') ?: 0;
}
$rows = mergeSortByKey($rows, 'created_ts');

// Cut out the rows of the current page
$matchCount = count($rows);
$lastPage = max(1, (int)ceil($matchCount / $perPage));
$page = min($page, $lastPage);                          // a page number that is too big becomes the last page
$firstShown = $matchCount === 0 ? 0 : ($page - 1) * $perPage + 1;
$rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
$lastShown = $matchCount === 0 ? 0 : $firstShown + count($rows) - 1;

// The search and filters of this tab, used to build links and to come back after a delete
$state = ($activeTab === 'listings')
    ? ['q' => $q, 'category' => $fCategory, 'wtype' => $fType, 'status' => $fStatus]
    : ['q' => $q, 'role' => $fRole];
$isFiltered = ($q !== '' || $fCategory !== '' || $fType !== '' || $fStatus !== '' || $fRole !== '');
$returnQuery = http_build_query(array_merge(['tab' => $activeTab], array_filter($state, 'strlen'), $page > 1 ? ['page' => $page] : []));
$allCount = ($activeTab === 'listings') ? $totalListings : $totalUsers;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>JobiT - Admin Control Center</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-weight: normal; }
        body { background-color: #f4f6fb; color: #111; font-size: 1rem; }
        h1, h2, h3, h4 { font-weight: bold; }

        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; background: #fff; border-bottom: 1px solid #e2e5eb; }
        .logo img { height: 40px; }

        .profile-dropdown-container { position: relative; display: inline-block; }
        .profile-avatar-btn {
            background: #d93025; color: #fff; width: 42px; height: 42px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-weight: bold;
            font-size: 1rem; border: none; cursor: pointer; text-decoration: none; overflow: hidden;
            box-shadow: 0 4px 10px rgba(217, 48, 37, 0.3);
        }
        .dropdown-menu {
            display: none; position: absolute; right: 0; top: 55px; background-color: #ffffff;
            min-width: 180px; box-shadow: 0px 8px 24px rgba(0,0,0,0.12); border-radius: 12px;
            border: 1px solid #eaeaea; z-index: 1000; overflow: hidden;
        }
        .dropdown-menu a { color: #333333; padding: 12px 18px; text-decoration: none; display: block; font-size: 0.95rem; font-weight: 600; }
        .dropdown-menu a:hover { background-color: #fdf2f2; color: #d93025; }
        .show-dropdown { display: block !important; }

        .admin-nav-btn { display: block; padding: 14px 20px; color: #333; font-weight: 600; text-decoration: none; border-radius: 8px; font-size: 0.95rem; transition: 0.2s; }
        .admin-nav-btn:hover { background: #fdf2f2; color: #d93025; }
        .admin-nav-btn.active { background: #d93025; color: #fff; font-weight: 700; box-shadow: 0 4px 12px rgba(217, 48, 37, 0.2); }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8f9fe; padding: 14px 18px; font-weight: bold; color: #444; border-bottom: 2px solid #eaeaea; }
        td { padding: 16px 18px; border-bottom: 1px solid #eaeaea; color: #333; }
        tr:hover { background: #fcfdff; }

        .badge { padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
        .badge-green { background: #eef9f0; color: #1e8e3e; }
        .badge-blue { background: #eef2ff; color: #4154f1; }
        .badge-red { background: #fdf2f2; color: #d93025; }

        /* Search and filters */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 20px; }
        .filter-bar input[type="search"], .filter-bar select {
            height: 42px; padding: 0 14px; font-size: 0.9rem; color: #333; background: #fafbfc;
            border: 1.5px solid #dfe3ec; border-radius: 8px; outline: none; transition: 0.2s;
        }
        .filter-bar input[type="search"] { flex: 1 1 260px; min-width: 200px; }
        .filter-bar select { cursor: pointer; max-width: 190px; }
        .filter-bar input[type="search"]:focus, .filter-bar select:focus { border-color: #d93025; background: #fff; }
        .filter-btn { height: 42px; padding: 0 22px; background: #d93025; color: #fff; font-weight: bold; font-size: 0.9rem; border: none; border-radius: 8px; cursor: pointer; transition: 0.2s; }
        .filter-btn:hover { background: #b9271d; }
        .filter-reset { font-size: 0.85rem; color: #888; font-weight: 600; text-decoration: none; padding: 0 6px; }
        .filter-reset:hover { color: #d93025; }

        /* Page numbers under the table */
        .pager { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .pager-info { font-size: 0.85rem; color: #666; font-weight: 600; }
        .pager-links { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .pager-links a, .pager-links span { min-width: 36px; height: 36px; padding: 0 12px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 0.85rem; font-weight: 700; text-decoration: none; border: 1px solid #eaeaea; color: #333; background: #fff; transition: 0.2s; }
        .pager-links a:hover { background: #fdf2f2; color: #d93025; border-color: #f5c6cb; }
        .pager-links .current { background: #d93025; color: #fff; border-color: #d93025; box-shadow: 0 4px 12px rgba(217, 48, 37, 0.2); }
        .pager-links .gap { border: none; background: none; color: #aaa; min-width: 20px; padding: 0; }
        .pager-links .off { color: #bbb; background: #fafbfc; }

        .delete-btn { display: inline-block; padding: 6px 14px; background: #fff0f0; color: #d9534f; border: 1px solid #f5c6cb; border-radius: 6px; font-weight: bold; font-size: 0.8rem; transition: 0.2s; cursor: pointer; }
        .delete-btn:hover { background: #fde4e4; }
    </style>
</head>
<body style="display: flex; flex-direction: column; min-height: 100vh;">

    <!-- Admin Top Navigation Bar -->
    <header class="navbar">
        <div class="logo">
            <a href="admin.php"><img src="Logo.jpg" alt="JobiT Logo" class="brand-logo" style="height: 40px;"></a>
        </div>

        <div style="font-weight: 800; font-size: 1.1rem; letter-spacing: 1px; color: #d93025;">
            ADMINISTRATOR CONTROL PANEL
        </div>

        <div class="nav-right" style="display: flex; align-items: center; gap: 20px;">
            <div class="profile-dropdown-container">
                <button onclick="toggleDropdown()" class="profile-avatar-btn" id="avatarBtn">
                    AD
                </button>
                <div class="dropdown-menu" id="profileDropdown">
                    <a href="admin.php?logout=1">Log Out System</a>
                </div>
            </div>
        </div>
    </header>

    <main style="padding: 40px 50px; max-width: 1500px; margin: 0 auto; flex: 1; width: 100%;">

        <?php if (!empty($actionMessage)): ?>
            <?php $isError = (strpos($actionMessage, 'ERROR') === 0); ?>
            <div style="background: <?php echo $isError ? '#fdf2f2' : '#eef9f0'; ?>; color: <?php echo $isError ? '#d93025' : '#1e8e3e'; ?>; padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; border: 1px solid <?php echo $isError ? '#fadbd8' : '#c8e6c9'; ?>; font-size: 0.95rem;">
                <?php echo htmlspecialchars($actionMessage); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header & Stats -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 35px; flex-wrap: wrap; gap: 20px;">
            <div>
                <h1 style="font-size: 2.4rem; font-weight: 900; color: #111; margin-bottom: 6px;">Platform Oversight</h1>
                <p style="color: #666; font-size: 1.05rem;">Moderate active job listings, enforce policies, and manage user accounts globally.</p>
            </div>

            <div style="display: flex; gap: 15px;">
                <div style="background: #fff; padding: 15px 25px; border-radius: 12px; border: 1px solid #eaeaea; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                    <div style="font-size: 1.5rem; font-weight: 900; color: #4154f1;"><?php echo $totalUsers; ?></div>
                    <div style="font-size: 0.75rem; color: #888; font-weight: bold; text-transform: uppercase;">Total Users</div>
                </div>
                <div style="background: #fff; padding: 15px 25px; border-radius: 12px; border: 1px solid #eaeaea; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                    <div style="font-size: 1.5rem; font-weight: 900; color: #1e8e3e;"><?php echo $totalListings; ?></div>
                    <div style="font-size: 0.75rem; color: #888; font-weight: bold; text-transform: uppercase;">Total Listings</div>
                </div>
            </div>
        </div>

        <!-- Layout Grid -->
        <div style="display: grid; grid-template-columns: 260px 1fr; gap: 30px; align-items: start;">

            <!-- Sidebar Navigation -->
            <div style="background: #fff; padding: 25px 20px; border-radius: 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 900; color: #888; display: block; margin-bottom: 15px; letter-spacing: 0.5px;">Moderation Tools</span>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="admin.php?tab=listings" class="admin-nav-btn <?php echo $activeTab === 'listings' ? 'active' : ''; ?>">Manage All Listings</a>
                    <a href="admin.php?tab=users" class="admin-nav-btn <?php echo $activeTab === 'users' ? 'active' : ''; ?>">Manage User Accounts</a>
                </div>
            </div>

            <!-- Content Panel -->
            <div style="background: #fff; padding: 30px 40px; border-radius: 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 20px rgba(0,0,0,0.02);">

                <?php if ($activeTab === 'listings'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h2 style="font-size: 1.4rem; font-weight: 900; color: #111;">Active Listings Database</h2>
                        <span style="font-size: 0.9rem; color: #666; font-weight: bold;">Displaying <?php echo $matchCount; ?><?php echo $isFiltered ? ' of ' . (int)$allCount : ''; ?> records</span>
                    </div>

                    <!-- Search and filters -->
                    <form method="GET" action="admin.php" class="filter-bar">
                        <input type="hidden" name="tab" value="listings">
                        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search title, poster or location..." aria-label="Search listings" maxlength="60">
                        <select name="category" aria-label="Filter by category" onchange="this.form.requestSubmit()">
                            <option value="">All categories</option>
                            <?php foreach ($categoryOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $fCategory ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($typeOptions)): ?>
                        <select name="wtype" aria-label="Filter by work type" onchange="this.form.requestSubmit()">
                            <option value="">All work types</option>
                            <?php foreach ($typeOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $fType ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <select name="status" aria-label="Filter by status" onchange="this.form.requestSubmit()">
                            <option value="">All statuses</option>
                            <?php foreach ($statusOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $fStatus ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="filter-btn">Search</button>
                        <?php if ($isFiltered): ?><a href="admin.php?tab=listings" class="filter-reset">Reset</a><?php endif; ?>
                    </form>

                    <?php if (empty($rows)): ?>
                        <div style="text-align: center; padding: 50px; color: #888; font-style: italic; background: #fafbfc; border-radius: 10px;">
                            <?php echo $isFiltered ? 'No listings match your search or filters.' : 'No job listings found in the database.'; ?>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto; border: 1px solid #eaeaea; border-radius: 12px;">
                            <table>
                               <thead>
                                   <tr>
                                       <th>ID</th>
                                       <th>Job Title</th>
                                       <th>Category</th>
                                       <th>Posted By</th>
                                       <th>Status</th>
                                       <th style="text-align: right;">Action</th>
                                   </tr>
                               </thead>
                               <tbody>
                                   <?php foreach ($rows as $list): ?>
                                       <tr>
                                           <td style="color: #666; font-weight: bold;">#<?php echo (int)$list['id']; ?></td>
                                           <td><strong><?php echo htmlspecialchars($list['title']); ?></strong></td>
                                           <td><?php echo htmlspecialchars($list['category']); ?></td>
                                           <td style="color: #444;"><?php echo htmlspecialchars($list['posted_by']); ?></td>
                                           <td><span class="badge badge-green"><?php echo htmlspecialchars($list['status']); ?></span></td>
                                           <td style="text-align: right;">
                                               <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete this listing?');">
                                                   <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                   <input type="hidden" name="action" value="delete_listing">
                                                   <input type="hidden" name="id" value="<?php echo (int)$list['id']; ?>">
                                                   <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnQuery); ?>">
                                                   <button type="submit" class="delete-btn">Delete Post</button>
                                               </form>
                                           </td>
                                       </tr>
                                   <?php endforeach; ?>
                               </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h2 style="font-size: 1.4rem; font-weight: 900; color: #111;">Registered User Database</h2>
                        <span style="font-size: 0.9rem; color: #666; font-weight: bold;">Displaying <?php echo $matchCount; ?><?php echo $isFiltered ? ' of ' . (int)$allCount : ''; ?> accounts</span>
                    </div>

                    <!-- Search and filters -->
                    <form method="GET" action="admin.php" class="filter-bar">
                        <input type="hidden" name="tab" value="users">
                        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search name, email, location or skills..." aria-label="Search users" maxlength="60">
                        <select name="role" aria-label="Filter by role" onchange="this.form.requestSubmit()">
                            <option value="">All roles</option>
                            <option value="user" <?php echo $fRole === 'user' ? 'selected' : ''; ?>>Users</option>
                            <option value="admin" <?php echo $fRole === 'admin' ? 'selected' : ''; ?>>Admins</option>
                        </select>
                        <button type="submit" class="filter-btn">Search</button>
                        <?php if ($isFiltered): ?><a href="admin.php?tab=users" class="filter-reset">Reset</a><?php endif; ?>
                    </form>

                    <?php if (empty($rows)): ?>
                        <div style="text-align: center; padding: 50px; color: #888; font-style: italic; background: #fafbfc; border-radius: 10px;">
                            <?php echo $isFiltered ? 'No users match your search or filters.' : 'No users registered yet.'; ?>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x: auto; border: 1px solid #eaeaea; border-radius: 12px;">
                            <table>
                               <thead>
                                   <tr>
                                       <th>ID</th>
                                       <th>Full Name</th>
                                       <th>Email Address</th>
                                       <th>Role</th>
                                       <th>Date Joined</th>
                                       <th style="text-align: right;">Action</th>
                                   </tr>
                               </thead>
                               <tbody>
                                   <?php foreach ($rows as $usr): ?>
                                       <tr>
                                           <td style="color: #666; font-weight: bold;">#<?php echo (int)$usr['id']; ?></td>
                                           <td><strong><?php echo htmlspecialchars($usr['full_name']); ?></strong></td>
                                           <td style="color: #444;"><?php echo htmlspecialchars($usr['email']); ?></td>
                                           <td>
                                               <?php if (($usr['role'] ?? 'user') === 'admin'): ?>
                                                   <span class="badge badge-red">Admin</span>
                                               <?php else: ?>
                                                   <span class="badge badge-blue">User</span>
                                               <?php endif; ?>
                                           </td>
                                           <td style="color: #666; font-size: 0.85rem;"><?php echo $usr['created_ts'] ? date('M d, Y', $usr['created_ts']) : '-'; ?></td>
                                           <td style="text-align: right;">
                                               <?php if ($usr['id'] != $userId && ($usr['role'] ?? 'user') !== 'admin'): ?>
                                                   <form method="POST" action="admin.php" style="display: inline;" onsubmit="return confirm('WARNING: Banning this user will delete all their posts and applications forever. Proceed?');">
                                                       <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                                                       <input type="hidden" name="action" value="delete_user">
                                                       <input type="hidden" name="id" value="<?php echo (int)$usr['id']; ?>">
                                                       <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnQuery); ?>">
                                                       <button type="submit" class="delete-btn">Ban User</button>
                                                   </form>
                                               <?php else: ?>
                                                   <span style="color: #aaa; font-size: 0.8rem; font-weight: bold; font-style: italic;">Protected</span>
                                               <?php endif; ?>
                                           </td>
                                       </tr>
                                   <?php endforeach; ?>
                               </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Page numbers (same for both tabs) -->
                <?php if ($matchCount > 0): ?>
                    <div class="pager">
                        <div class="pager-info">Showing <?php echo $firstShown; ?>-<?php echo $lastShown; ?> of <?php echo $matchCount; ?></div>
                        <?php if ($lastPage > 1): ?>
                        <div class="pager-links">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo htmlspecialchars(adminUrl($activeTab, $state, ['page' => $page - 1])); ?>">&larr; Prev</a>
                            <?php else: ?>
                                <span class="off">&larr; Prev</span>
                            <?php endif; ?>

                            <?php foreach (adminPageNumbers($page, $lastPage) as $num): ?>
                                <?php if ($num === '...'): ?>
                                    <span class="gap">...</span>
                                <?php elseif ($num == $page): ?>
                                    <span class="current"><?php echo $num; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo htmlspecialchars(adminUrl($activeTab, $state, ['page' => $num])); ?>"><?php echo $num; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if ($page < $lastPage): ?>
                                <a href="<?php echo htmlspecialchars(adminUrl($activeTab, $state, ['page' => $page + 1])); ?>">Next &rarr;</a>
                            <?php else: ?>
                                <span class="off">Next &rarr;</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

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

        // When the admin comes back with the browser's Back button, switch the fields on again
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('.filter-bar [disabled]').forEach(function (field) { field.disabled = false; });
        });

        // Keep the address short: empty search boxes and "All ..." filters are not sent
        document.querySelectorAll('.filter-bar').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('input[name], select[name]').forEach(function (field) {
                    if (field.value === '') { field.disabled = true; }
                });
            });
        });
    </script>
</body>
</html>
