<?php
// ============================================================================
// CENTRAL DATABASE CONNECTION (jobit_db)
//
// ONE db.php FOR BOTH PLACES (your PC and the live website):
//   - On your PC (XAMPP) there is no config.live.php, so the settings below are used (localhost / root / no password).
//   - On InfinityFree you create a file named config.live.php on the SERVER ONLY, with the live settings.
//     When that file exists, it replaces the settings below. So db.php never needs to be edited again,
//     and you can re-upload db.php (or any page) as many times as you want without breaking the live site.
//   - Never keep config.live.php in your XAMPP project folder, or your PC would try to use the live database.
// ============================================================================

$host = 'localhost';$dbname = 'jobit_db';
$username = 'root';$password = '';
$jobitLive = false;                                   // true only on the live website

if (file_exists(__DIR__ . '/config.live.php')) {
    require __DIR__ . '/config.live.php';             // sets $host, $dbname, $username and $password
    $jobitLive = true;
}

// Philippine time for everything (PHP dates), whatever time zone the hosting server uses
date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username,$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    if ($jobitLive) {
        // On the live site visitors must never see server details. The real reason goes to the server's error log.
        error_log("JobiT database connection failed: " . $e->getMessage());
        die("Sorry, JobiT cannot reach its database right now. Please try again in a few minutes.");
    }
    die("Database Connection Error: " . $e->getMessage());
}

// Same Philippine time inside the database, so NOW() (used for "Posted on" and "Applied on") matches the clock in the Philippines
try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    // if a host does not allow this, we simply keep its default time zone
}

// ============================================================================
// KEEP ADMINS OUT OF THE MEMBER PAGES
// An admin account only moderates (admin.php). It must not post listings or apply for jobs,
// so if an admin opens one of the member pages we send them back to admin.php.
// This runs for every page that includes db.php, but it only acts on the pages listed below.
// ============================================================================
function jobitKeepAdminsOut($pdo) {
    $memberPages = ['dashboard.php', 'browse.php', 'matches.php', 'my-listings.php',
                    'post-listing.php', 'listing-details.php', 'profile.php'];
    if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $memberPages, true)) {
        return;                                          // not a member page, nothing to check
    }
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
        return;                                          // nobody is logged in
    }
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetchColumn() === 'admin') {
            header("Location: admin.php");
            exit();
        }
    } catch (PDOException $e) {
        // if the role column cannot be read, we simply let the page load as before
    }
}
jobitKeepAdminsOut($pdo);

// ============================================================================
// UNIFIED MATCH SCORE FORMULA
// Used by browse.php, matches.php, and my-listings.php (so the same job always shows the same percent)
//
//   Skills        50 points = (required skills the person has / required skills) x 50
//   Location      30 points = same city 30, different city 15, location missing 0
//   Availability  20 points = (required options the person picked / required options) x 20
//
//   Example: skills 3 of 5 = 30, different city = 15, availability 2 of 3 = 13.33
//            30 + 15 + 13.33 = 58.33%
//
//   SYNONYMS: different words for the same thing count as the same thing, so "JS" matches "JavaScript"
//   and "QC" matches "Quezon City". The word lists are just below.
// ============================================================================

// ---------------------------------------------------------------------------
// SYNONYM MAPS (a hash map / dictionary:  nickname => official name)
// Before two skills (or two cities) are compared, each one is replaced by its official name,
// so "JS" and "JavaScript" both become "javascript" and are then simply equal.
// Looking a word up in the map is one step (O(1)), no matter how long the list gets.
//
// HOW TO ADD ONE:  add a line   'nickname' => 'official name',
//   - both sides in lowercase, one official name per meaning
//   - the official name must NOT also be used as a nickname (no chains)
//   - do not add a nickname that can mean two different things (like 'ai' or 'ps')
// ---------------------------------------------------------------------------
const JOBIT_SKILL_SYNONYMS = [
    // IT & Tech
    'js' => 'javascript', 'java script' => 'javascript',
    'ts' => 'typescript',
    'py' => 'python',
    'node' => 'node.js', 'nodejs' => 'node.js', 'node js' => 'node.js',
    'reactjs' => 'react', 'react js' => 'react', 'react.js' => 'react',
    'vuejs' => 'vue', 'vue js' => 'vue', 'vue.js' => 'vue',
    'csharp' => 'c#', 'c sharp' => 'c#',
    'cpp' => 'c++', 'c plus plus' => 'c++',
    'wp' => 'wordpress',
    'postgres' => 'postgresql',
    'mongo' => 'mongodb',
    'pc repair' => 'hardware repair', 'computer repair' => 'hardware repair',
    // Office, admin and customer support
    'ms excel' => 'excel', 'microsoft excel' => 'excel',
    'ms word' => 'microsoft word',
    'ms powerpoint' => 'powerpoint', 'microsoft powerpoint' => 'powerpoint', 'ppt' => 'powerpoint',
    'ms office' => 'microsoft office',
    'va' => 'virtual assistant', 'virtual assistance' => 'virtual assistant',
    'encoding' => 'data entry', 'data encoding' => 'data entry',
    'book keeping' => 'bookkeeping', 'bookkeeper' => 'bookkeeping',
    'accountant' => 'accounting',
    'customer service' => 'customer support', 'csr' => 'customer support', 'customer care' => 'customer support',
    'telemarketer' => 'telemarketing', 'tele marketing' => 'telemarketing',
    // Communication and languages
    'comm skills' => 'communication', 'communication skills' => 'communication',
    'good communication' => 'communication', 'verbal communication' => 'communication',
    'english speaking' => 'english', 'spoken english' => 'english',
    'english proficiency' => 'english', 'fluent english' => 'english',
    'tagalog' => 'filipino',
    // Design, writing and marketing
    'graphic designer' => 'graphic design', 'graphics design' => 'graphic design', 'graphics designer' => 'graphic design',
    'video editor' => 'video editing',
    'photographer' => 'photography',
    'writer' => 'writing',
    'content writer' => 'content writing',
    'copywriter' => 'copywriting',
    'smm' => 'social media marketing', 'social media manager' => 'social media marketing',
    'social media management' => 'social media marketing',
    // Food, cleaning and household
    'cook' => 'cooking',
    'baker' => 'baking',
    'f&b' => 'food service', 'food and beverage' => 'food service', 'food & beverage' => 'food service',
    'food prep' => 'food preparation',
    'housekeeping' => 'cleaning', 'housekeeper' => 'cleaning', 'house cleaning' => 'cleaning',
    'labandera' => 'laundry',
    'yaya' => 'childcare', 'nanny' => 'childcare', 'babysitter' => 'childcare', 'babysitting' => 'childcare',
    'caregiver' => 'caregiving',
    // Trades, driving and teaching
    'plumber' => 'plumbing',
    'electrician' => 'electrical', 'electrical work' => 'electrical',
    'carpenter' => 'carpentry',
    'welder' => 'welding',
    'mason' => 'masonry',
    'painter' => 'painting',
    'driver' => 'driving',
    'tutor' => 'tutoring',
    'teacher' => 'teaching'
];

const JOBIT_CITY_SYNONYMS = [
    // Metro Manila
    'qc' => 'quezon city', 'q.c.' => 'quezon city', 'q.c' => 'quezon city',
    'manila city' => 'manila', 'city of manila' => 'manila', 'mnl' => 'manila',
    'makati city' => 'makati', 'city of makati' => 'makati',
    'pasig city' => 'pasig', 'city of pasig' => 'pasig',
    'taguig city' => 'taguig', 'bgc' => 'taguig', 'bonifacio global city' => 'taguig',
    'mandaluyong city' => 'mandaluyong',
    'caloocan city' => 'caloocan',
    // (the letter n with a tilde is written \u{00F1} so this file stays plain ASCII)
    "para\u{00F1}aque" => 'paranaque', 'paranaque city' => 'paranaque', "para\u{00F1}aque city" => 'paranaque',
    "las pi\u{00F1}as" => 'las pinas', 'las pinas city' => 'las pinas', "las pi\u{00F1}as city" => 'las pinas',
    'muntinlupa city' => 'muntinlupa',
    'marikina city' => 'marikina',
    'pasay city' => 'pasay',
    'valenzuela city' => 'valenzuela',
    'malabon city' => 'malabon',
    'navotas city' => 'navotas',
    // Other big cities
    'antipolo city' => 'antipolo',
    'angeles city' => 'angeles',
    'baguio city' => 'baguio',
    'cebu city' => 'cebu',
    'davao city' => 'davao',
    'iloilo city' => 'iloilo',
    'bacolod city' => 'bacolod',
    'cdo' => 'cagayan de oro', 'cagayan de oro city' => 'cagayan de oro',
    'zamboanga city' => 'zamboanga'
];

// Cleans ONE word (trim, lowercase, one space between words) and swaps a nickname for its official name.
//   jobitNormalize(' JS ', JOBIT_SKILL_SYNONYMS)            -> 'javascript'
//   jobitNormalize('Customer   Service', JOBIT_SKILL_SYNONYMS) -> 'customer support'
//   jobitNormalize('QC', JOBIT_CITY_SYNONYMS)               -> 'quezon city'
function jobitNormalize($item, $synonyms) {
    $item = mb_strtolower(trim((string)$item));
    $oneSpace = preg_replace('/\s+/u', ' ', $item);   // "customer    service" -> "customer service"
    if ($oneSpace !== null) {
        $item = $oneSpace;
    }
    return $synonyms[$item] ?? $item;                 // one hash map lookup; no nickname = keep the word
}

// Turns "PHP,  html/css , PHP, JS" into ['php', 'html/css', 'javascript'].
// Trimmed, lowercase, nicknames replaced by official names, no blanks, no repeats,
// so "Php", " php " and "PHP" are one skill, and "JS" and "JavaScript" are one skill.
function jobitList($text) {
    $list = [];
    $seen = [];                                       // hash set of the items already in $list (no repeats)
    foreach (explode(',', (string)$text) as $item) {
        $item = jobitNormalize($item, JOBIT_SKILL_SYNONYMS);
        if ($item !== '' && !isset($seen[$item])) {
            $seen[$item] = true;
            $list[] = $item;
        }
    }
    return $list;
}

// The city is the text before the first comma: "Quezon City, Metro Manila" -> "quezon city"
// A nickname becomes the official name: "QC" -> "quezon city", "Pasig City" -> "pasig"
function jobitCity($location) {
    $parts = explode(',', (string)$location);
    return jobitNormalize($parts[0], JOBIT_CITY_SYNONYMS);
}

// How many of the REQUIRED items does the person have? Returns [have, total].
// "PHP, MySQL, Figma" required and the person has "php, figma" -> [2, 3]
function jobitCountMatches($required, $has) {
    $requiredList = jobitList($required);
    // What the person has, as a hash set: asking "do they have it?" is one isset() per required item
    $haveSet = [];
    foreach (jobitList($has) as $item) {
        $haveSet[$item] = true;
    }
    $hits = 0;
    foreach ($requiredList as $item) {
        if (isset($haveSet[$item])) {
            $hits++;
        }
    }
    return [$hits, count($requiredList)];
}

// What share (0 to 1) of the REQUIRED items does the person have? 3 of 5 -> 0.6
// If the job requires nothing, nothing can be missing, so it counts as 1 (full points).
function jobitCoverage($required, $has) {
    list($hits, $total) = jobitCountMatches($required, $has);
    if ($total === 0) {
        return 1.0;
    }
    return $hits / $total;
}

// The score split into its three parts (this is the ONE place where the formula lives).
// calculateMatchScore() below only returns the 'total', and the "Why this score?" box on the
// Browse page shows the parts, so the box and the big percent can never disagree.
function getScoreBreakdown($requiredSkillsStr, $jobLocation, $jobSchedule, $candidateSkillsStr, $candidateLocation, $candidateSlotsStr) {
    // 1. Skills, up to 50 points
    list($skillsHave, $skillsTotal) = jobitCountMatches($requiredSkillsStr, $candidateSkillsStr);
    $skillScore = 50 * jobitCoverage($requiredSkillsStr, $candidateSkillsStr);

    // 2. Location, up to 30 points
    $jobCity = jobitCity($jobLocation);
    $candidateCity = jobitCity($candidateLocation);
    if ($jobCity === '' || $candidateCity === '') {
        $locationScore = 0;       // location missing
        $locationResult = 'missing';
    } elseif ($jobCity === $candidateCity) {
        $locationScore = 30;      // same city
        $locationResult = 'same';
    } else {
        $locationScore = 15;      // different city
        $locationResult = 'different';
    }

    // 3. Availability, up to 20 points (the job's schedule is the list of options the employer requires)
    list($slotsHave, $slotsTotal) = jobitCountMatches($jobSchedule, $candidateSlotsStr);
    $scheduleScore = 20 * jobitCoverage($jobSchedule, $candidateSlotsStr);

    // The total is rounded to 2 decimals once, at the end (for example 58.33)
    $total = round($skillScore + $locationScore + $scheduleScore, 2);

    // For the screen the parts must add up to the total exactly. Each part rounded on its own can be
    // 0.01 off (16.67 + 15 + 6.67 = 38.34, but the total is 38.33), so the last part takes what is left.
    $skillShown = round($skillScore, 2);
    $scheduleShown = round($total - $skillShown - $locationScore, 2);

    return [
        'total'               => $total,
        'skills_points'       => $skillShown,       // out of 50
        'skills_have'         => $skillsHave,
        'skills_total'        => $skillsTotal,
        'location_points'     => $locationScore,    // out of 30
        'location_result'     => $locationResult,   // 'same', 'different' or 'missing'
        'availability_points' => $scheduleShown,    // out of 20
        'availability_have'   => $slotsHave,
        'availability_total'  => $slotsTotal
    ];
}

// ----------------------------------------------------------------------------
// WEAK SKILL MATCH (a warning label only, it never changes the score)
// A score can look good mostly because of city and schedule, for example
// 1 of 2 skills (25) + same city (30) + free on the day (20) = 75%.
// We warn when: the job lists skills, the person has HALF OR FEWER of them,
// and the total still reaches 50% or more.
// The same rule is copied in the JavaScript of my-listings.php (popup).
// ----------------------------------------------------------------------------
function isWeakSkillMatch($breakdown) {
    if (empty($breakdown) || ($breakdown['skills_total'] ?? 0) === 0) {
        return false;                                   // the job lists no skills: nothing to warn about
    }
    $halfOrLess = ($breakdown['skills_have'] * 2) <= $breakdown['skills_total'];
    return $halfOrLess && $breakdown['total'] >= 50;
}

// Final match score from 0 to 100 with 2 decimals (for example 58.33)
function calculateMatchScore($requiredSkillsStr, $jobLocation, $jobSchedule, $candidateSkillsStr, $candidateLocation, $candidateSlotsStr) {
    $parts = getScoreBreakdown($requiredSkillsStr, $jobLocation, $jobSchedule, $candidateSkillsStr, $candidateLocation, $candidateSlotsStr);
    return $parts['total'];
}

// Helper, returns which required skills the candidate has and which they are missing.
// Uses the same matching rule as the score above, so the chips and the percent can never disagree.
function getSkillsBreakdown($requiredSkillsStr, $candidateSkillsStr) {
    // What the candidate has, as a hash set of official names (so "JS" is stored as "javascript")
    $haveSet = [];
    foreach (jobitList($candidateSkillsStr) as $item) {
        $haveSet[$item] = true;
    }

    $matched = [];
    $missing = [];
    $seen = [];
    foreach (explode(',', (string)$requiredSkillsStr) as $skill) {
        $skill = trim($skill);
        $key = jobitNormalize($skill, JOBIT_SKILL_SYNONYMS);   // official name, only used for comparing
        if ($skill === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (isset($haveSet[$key])) {
            $matched[] = $skill;       // shown with the employer's own spelling
        } else {
            $missing[] = $skill;
        }
    }
    return ['matched' => $matched, 'missing' => $missing];
}
