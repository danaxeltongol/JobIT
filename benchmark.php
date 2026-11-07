<?php
// ============================================================================
// JOBIT SORTING BENCHMARK: our merge sort vs bubble sort
//
// Both sorts get the SAME random match scores (0 to 100, highest first).
// We count the comparisons and measure the time, then check that both gave
// the same, correctly sorted list. Open this page: benchmark.php
// (add ?seed=123 to the address to get exactly the same numbers again)
// ============================================================================

session_start();
require_once 'db.php';
require_once __DIR__ . '/sorting.php';

// Same login rule as the other pages
$userId = $_SESSION['user_id'] ?? null;
if (!$userId && !isset($_SESSION['user_profile'])) {
    header("Location: auth.php?mode=login");
    exit();
}

$currentUserProfile = $_SESSION['user_profile'] ?? [];
$userInitials = $currentUserProfile['initials'] ?? 'U';
$userAvatar = $currentUserProfile['avatar'] ?? '';

if ($userId) {
    try {
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $dbUser = $userStmt->fetch();
        if ($dbUser) {
            $initials = '';
            foreach (explode(' ', $dbUser['full_name']) as $w) {
                if (!empty($w)) $initials .= strtoupper($w[0]);
            }
            $userInitials = substr($initials, 0, 2) ?: 'U';
            $userAvatar = $dbUser['avatar'] ?? '';
        }
    } catch (Exception $e) {}
}

// Checks that a list is sorted from highest to lowest
function isSortedHighestFirst($rows, $key) {
    for ($i = 0; $i < count($rows) - 1; $i++) {
        if ($rows[$i][$key] < $rows[$i + 1][$key]) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Run the benchmark
// ---------------------------------------------------------------------------
$sizes = [10, 50, 100, 500, 1000, 2000, 5000];                 // how many listings to sort in each round
$seed = isset($_GET['seed']) ? (int)$_GET['seed'] : mt_rand(1, 999999);
mt_srand($seed);                                               // same seed = same random numbers

$results = [];
foreach ($sizes as $n) {
    // Make $n fake listings with a random match score like 58.33
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = ['id' => $i, 'match_score' => round(mt_rand(0, 10000) / 100, 2)];
    }

    // Merge sort
    $start = microtime(true);
    $mergeSorted = mergeSortByKey($rows, 'match_score', $mergeComparisons);
    $mergeMs = (microtime(true) - $start) * 1000;

    // Bubble sort (same data)
    $start = microtime(true);
    $bubbleSorted = bubbleSortByKey($rows, 'match_score', $bubbleComparisons);
    $bubbleMs = (microtime(true) - $start) * 1000;

    // Min-heap top 3 (what the Home page uses): must equal the first 3 of the merge sort
    $start = microtime(true);
    $heapTop = topKByKey($rows, 'match_score', 3, $heapComparisons);
    $heapMs = (microtime(true) - $start) * 1000;
    $heapOk = (array_column($heapTop, 'id') === array_column(array_slice($mergeSorted, 0, 3), 'id'));

    // Both must be sorted and must be the very same order
    $sameOrder = (array_column($mergeSorted, 'id') === array_column($bubbleSorted, 'id'));
    $sortedOk = isSortedHighestFirst($mergeSorted, 'match_score') && isSortedHighestFirst($bubbleSorted, 'match_score');

    $results[] = [
        'n' => $n,
        'merge_comparisons' => $mergeComparisons,
        'bubble_comparisons' => $bubbleComparisons,
        'merge_ms' => $mergeMs,
        'bubble_ms' => $bubbleMs,
        'times_more' => $mergeComparisons > 0 ? $bubbleComparisons / $mergeComparisons : 1,
        'heap_comparisons' => $heapComparisons,
        'ok' => ($sameOrder && $sortedOk && $heapOk),
    ];
}
$allOk = true;
foreach ($results as $r) { if (!$r['ok']) $allOk = false; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Sorting Benchmark</title>
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
        .profile-avatar-btn { background: #4154f1; color: #fff; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.95rem; border: none; cursor: pointer; overflow: hidden; }
        .profile-avatar-btn img { width: 100%; height: 100%; object-fit: cover; }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 50px; background-color: #ffffff; min-width: 180px; box-shadow: 0px 8px 24px rgba(0,0,0,0.12); border-radius: 12px; border: 1px solid #eaeaea; z-index: 1000; overflow: hidden; }
        .dropdown-menu a { color: #333333; padding: 12px 18px; text-decoration: none; display: block; font-size: 0.95rem; font-weight: 600; }
        .dropdown-menu a:hover { background-color: #f0f4ff; color: #4154f1; }
        .dropdown-menu .dropdown-logout { color: #ff4d4d; border-top: 1px solid #eee; }
        .show-dropdown { display: block !important; }

        .card { background: #fff; border-radius: 16px; border: 1px solid #eaeaea; padding: 28px 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); margin-bottom: 22px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        th { text-align: right; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.4px; color: #888; padding: 10px 12px; border-bottom: 2px solid #eaeaea; }
        td { text-align: right; padding: 12px; border-bottom: 1px solid #f0f0f4; font-variant-numeric: tabular-nums; }
        th:first-child, td:first-child { text-align: left; }
        td.merge { color: #4154f1; font-weight: bold; }
        td.bubble { color: #d9534f; font-weight: bold; }
        .ok { color: #1e8e3e; font-weight: bold; }
        .bad { color: #d93025; font-weight: bold; }
        .btn { display: inline-block; background: #4154f1; color: #fff; padding: 9px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.88rem; }
        .btn-line { background: #fff; color: #4154f1; border: 1px solid #c9d0fb; }
        .note { background: #f8f9fe; border: 1px solid #e1e7fc; border-left: 4px solid #4154f1; border-radius: 10px; padding: 14px 18px; font-size: 0.9rem; color: #444; line-height: 1.55; }
        .table-scroll { overflow-x: auto; }
    </style>
</head>
<body>

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
                        <img src="<?php echo htmlspecialchars($userAvatar); ?>" alt="">
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

    <main style="padding: 32px 30px 60px; max-width: 1100px; margin: 0 auto;">

        <div style="margin-bottom: 22px;">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: bold; color: #4154f1; letter-spacing: 0.5px;">DATA STRUCTURES &amp; ALGORITHMS</span>
            <h1 style="font-size: 1.7rem; margin-top: 2px;">Sorting Benchmark: Merge Sort vs Bubble Sort</h1>
            <p style="color: #666; font-size: 0.92rem; margin-top: 4px;">Both sorts get the same random match scores (0 to 100) and sort them from highest to lowest.</p>
        </div>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Listings sorted</th>
                            <th>Merge sort comparisons</th>
                            <th>Bubble sort comparisons</th>
                            <th>Merge sort time</th>
                            <th>Bubble sort time</th>
                            <th>Bubble needs</th>
                            <th>Heap top 3 comparisons</th>
                            <th>Correct?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <td><strong><?php echo number_format($r['n']); ?></strong></td>
                                <td class="merge"><?php echo number_format($r['merge_comparisons']); ?></td>
                                <td class="bubble"><?php echo number_format($r['bubble_comparisons']); ?></td>
                                <td><?php echo $r['merge_ms'] < 0.01 ? '&lt; 0.01' : number_format($r['merge_ms'], 2); ?> ms</td>
                                <td><?php echo $r['bubble_ms'] < 0.01 ? '&lt; 0.01' : number_format($r['bubble_ms'], 2); ?> ms</td>
                                <td><?php echo number_format($r['times_more'], 1); ?>x more comparisons</td>
                                <td class="merge"><?php echo number_format($r['heap_comparisons']); ?></td>
                                <td class="<?php echo $r['ok'] ? 'ok' : 'bad'; ?>"><?php echo $r['ok'] ? 'Yes, same order' : 'NO'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <a href="benchmark.php" class="btn">Run again with new random numbers</a>
                <a href="benchmark.php?seed=<?php echo $seed; ?>" class="btn btn-line">Same numbers again (seed <?php echo $seed; ?>)</a>
                <?php if ($allOk): ?>
                    <span class="ok" style="font-size: 0.88rem;">Both sorts were correct in every round.</span>
                <?php else: ?>
                    <span class="bad" style="font-size: 0.88rem;">A sort gave a wrong result, check sorting.php.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3 style="font-size: 1.1rem; margin-bottom: 10px;">What this shows</h3>
            <div class="note">
                <strong>Merge sort</strong> splits the list in half again and again, then merges the sorted halves.
                It needs about <strong>n &times; log<sub>2</sub>(n)</strong> comparisons (Big O: <strong>O(n log n)</strong>).<br>
                <strong>Bubble sort</strong> compares neighbours again and again and needs up to
                <strong>n &times; (n &minus; 1) / 2</strong> comparisons (Big O: <strong>O(n&sup2;)</strong>).<br>
                When the list gets 10 times longer, merge sort needs only a little more than 10 times the work, but bubble sort needs about 100 times more.
                That is why JobIT ranks its listings and applicants with merge sort.
            </div>
            <p style="color: #666; font-size: 0.85rem; margin-top: 14px; line-height: 1.5;">
                JobIT uses the merge sort from <code>sorting.php</code> in:
                Browse (best match first and highest budget first), Matches (recommended gigs and the applicant queue)
                and My Listings (applicants of each listing). Rows with the same score keep the order they already had (for listings that is newest first).
            </p>
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
                    dropdowns[i].classList.remove('show-dropdown');
                }
            }
        }
    </script>
</body>
</html>
