<?php
session_start();
require_once 'db.php';

$mode = $_GET['mode'] ?? 'signup';
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth_action = $_POST['auth_action'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        if ($auth_action === 'signup') {
            // Check if user already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errorMessage = "An account with this email already exists.";
            } else {
                // Generate default name and hash password
                $nameParts = explode('@', $email);
                $fullName = ucfirst($nameParts[0]);
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, 'user')");
                if ($stmt->execute([$fullName, $email, $hashedPassword])) {
                    $userId = $pdo->lastInsertId();
                    
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_profile'] = [
                        'user_id' => $userId,
                        'name' => $fullName,
                        'initials' => strtoupper(substr($fullName, 0, 2)),
                        'email' => $email,
                        'location' => 'Quezon City, Metro Manila',
                        'skills' => ['PHP', 'HTML/CSS'],
                        'avatar' => ''
                    ];

                    header("Location: dashboard.php");
                    exit();
                } else {
                    $errorMessage = "Error creating account. Please try again.";
                }
            }
        } elseif ($auth_action === 'login') {
            // Retrieve user from database
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $initials = strtoupper(substr($user['full_name'], 0, 2));
                $skills = !empty($user['skills']) ? explode(',', $user['skills']) : ['PHP', 'HTML/CSS'];

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_profile'] = [
                    'user_id' => $user['id'],
                    'name' => $user['full_name'],
                    'initials' => $initials,
                    'email' => $user['email'],
                    'location' => $user['location'] ?? 'Quezon City',
                    'skills' => $skills,
                    'avatar' => $user['avatar'] ?? ''
                ];

                // Check if an admin accidentally used the regular login page
                if (($user['role'] ?? 'user') === 'admin') {
                    header("Location: admin.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                $errorMessage = "Invalid email or password.";
            }
        }
    } else {
        $errorMessage = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Login / Sign Up</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        /* CLEANED UP AUTH LAYOUT CSS */
        .auth-page { display: flex; align-items: center; justify-content: flex-start; height: calc(100vh - 60px); padding-left: 60px; background: #fff; position: relative; overflow: hidden; }
        .auth-left { width: 42%; max-width: 520px; z-index: 2; }
        
        .auth-title-tabs { display: flex; gap: 30px; font-size: 1.8rem; font-weight: 900; margin-bottom: 25px; position: relative; padding-bottom: 8px; border-bottom: 2px solid #e0e0e0; max-width: 440px; }
        .title-tab { cursor: pointer; color: #000; opacity: 0.3; transition: opacity 0.2s ease; user-select: none; }
        .title-tab.active { opacity: 1; }
        .tab-underline { position: absolute; bottom: -2px; left: 0; width: 100px; height: 4px; background-color: #4154f1; border-radius: 2px; transition: all 0.3s ease-in-out; }
        
        .auth-main-title { font-size: 2.2rem; font-weight: 800; color: #000; margin-bottom: 8px; line-height: 1.2; }
        .auth-description { font-size: 1.1rem; color: #666; margin-bottom: 30px; line-height: 1.5; max-width: 440px; }
        
        .auth-message-error { background: #fff0f0; color: #d9534f; border: 1px solid #f5c6cb; padding: 10px 14px; border-radius: 8px; margin-bottom: 15px; font-weight: 600; max-width: 440px; }
        
        /* Unified Input Fields */
        .field-group { margin-bottom: 22px; max-width: 440px; }
        .field-group label { display: block; font-weight: 700; font-size: 1.05rem; margin-bottom: 8px; color: #000; }
        .field-group input { width: 100%; padding: 14px 16px; border: 1.5px solid #ccc; border-radius: 10px; font-size: 1.05rem; outline: none; background: #fff; transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box;}
        .field-group input:focus { border-color: #4154f1; box-shadow: 0 0 0 3px rgba(65, 84, 241, 0.15); }
        
        /* Repositioned Links & Buttons */
        .forgot-link { color: #4154f1; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: color 0.2s; }
        .forgot-link:hover { color: #2938b7; text-decoration: underline; }

        .form-actions { margin-top: 10px; margin-bottom: 20px; max-width: 440px; }
        .pill-submit { width: 100%; padding: 14px; border-radius: 30px; font-size: 1.1rem; font-weight: 700; border: none; cursor: pointer; transition: 0.2s; }
        .pill-submit:hover { opacity: 0.9; }
        
        .auth-switch-text { font-size: 1rem; color: #555; text-align: center; margin-top: 15px; max-width: 440px; }
        .auth-link { color: #4154f1; font-weight: 700; cursor: pointer; text-decoration: none; }
        .auth-link:hover { color: #2938b7; text-decoration: underline; }
        
        .back-home-container { max-width: 440px; margin-top: 35px; padding-top: 20px; border-top: 1px solid #eaeaea; }
        .back-home-link { display: inline-flex; align-items: center; color: #666; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: color 0.2s; }
        .back-home-link:hover { color: #111; }
        
        .auth-right { position: absolute; right: 0; bottom: 0; width: 66%; height: 98%; display: flex; justify-content: flex-end; align-items: flex-end; z-index: 1; pointer-events: none; }
        .auth-hero-img { height: 100%; width: auto; max-width: 100%; object-fit: contain; object-position: bottom right; }
        .auth-page-form { display: none; }
        .auth-page-form.active { display: block; }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="logo"><a href="index.php"><img src="Logo.jpg" alt="JobiT Logo" class="brand-logo" style="height: 40px;"></a></div>
        <div class="nav-buttons">
            <a href="auth.php?mode=login" class="btn-black nav-btn">LOGIN</a>
            <a href="auth.php?mode=signup" class="btn-blue nav-btn">SIGN UP</a>
        </div>
    </header>

    <main class="auth-page">
        <div class="auth-left">
            <div class="auth-title-tabs">
                <span id="headerLogin" class="title-tab <?php echo $mode === 'login' ? 'active' : ''; ?>">LOG IN</span>
                <span id="headerSignup" class="title-tab <?php echo $mode === 'signup' ? 'active' : ''; ?>">SIGN UP</span>
                <div id="tabUnderline" class="tab-underline"></div>
            </div>

            <div class="auth-form-container">
                <?php if (!empty($errorMessage)): ?>
                    <div class="auth-message-error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <!-- SIGNUP FORM -->
                <form id="pageSignupForm" class="auth-page-form <?php echo $mode === 'signup' ? 'active' : ''; ?>" action="auth.php?mode=signup" method="POST">
                    <input type="hidden" name="auth_action" value="signup">
                    <h1 class="auth-main-title">Create your JobiT account</h1>
                    <p class="auth-description">Discover gigs, showcase your skills, and connect with the right people.</p>

                    <div class="field-group">
                        <label for="emailSign">Email</label>
                        <input type="email" id="emailSign" name="email" placeholder="Enter your email" required>
                    </div>
                    <div class="field-group">
                        <label for="passSign">Password</label>
                        <input type="password" id="passSign" name="password" placeholder="Create a password" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-blue pill-submit">Create Account</button>
                    </div>

                    <p class="auth-switch-text">Already have an account? <span id="switchToLogin" class="auth-link">Log in</span></p>
                </form>

                <!-- LOGIN FORM -->
                <form id="pageLoginForm" class="auth-page-form <?php echo $mode === 'login' ? 'active' : ''; ?>" action="auth.php?mode=login" method="POST">
                    <input type="hidden" name="auth_action" value="login">
                    <h1 class="auth-main-title">Welcome back to JobiT</h1>
                    <p class="auth-description">Find gigs, post tasks, and manage your account.</p>

                    <div class="field-group">
                        <label for="emailLog">Email</label>
                        <input type="email" id="emailLog" name="email" placeholder="Enter your email" required>
                    </div>
                    
                    <div class="field-group">
                        <!-- Grouping Label and Forgot Password Link -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label for="passLog" style="margin-bottom: 0;">Password</label>
                            <a href="reset-password.php" class="forgot-link">Forgot Password?</a>
                        </div>
                        <input type="password" id="passLog" name="password" placeholder="Enter your password" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-black pill-submit">Log in</button>
                    </div>

                    <p class="auth-switch-text">Don't have an account? <span id="switchToSignup" class="auth-link">Sign up</span></p>
                </form>
            </div>

            <!-- Cleanly separated Back Button -->
            <div class="back-home-container">
                <a href="index.php" class="back-home-link">
                    <span style="margin-right: 8px; font-size: 1.1rem;">&larr;</span> Back to Main Page
                </a>
            </div>
        </div>

        <div class="auth-right">
            <img src="LogInSignIn.png" alt="JobiT Login Workers" class="auth-hero-img" onerror="this.src='Browse2.jpg';">
        </div>
    </main>

    <script>
        const headerLogin = document.getElementById('headerLogin');
        const headerSignup = document.getElementById('headerSignup');
        const signupForm = document.getElementById('pageSignupForm');
        const loginForm = document.getElementById('pageLoginForm');
        const switchToLogin = document.getElementById('switchToLogin');
        const switchToSignup = document.getElementById('switchToSignup');
        const underline = document.getElementById('tabUnderline');

        function showLogin() {
            if(signupForm) signupForm.classList.remove('active');
            if(loginForm) loginForm.classList.add('active');
            if(headerSignup) headerSignup.classList.remove('active');
            if(headerLogin) headerLogin.classList.add('active');
            if(underline) { underline.style.left = '0'; }
        }

        function showSignup() {
            if(loginForm) loginForm.classList.remove('active');
            if(signupForm) signupForm.classList.add('active');
            if(headerLogin) headerLogin.classList.remove('active');
            if(headerSignup) headerSignup.classList.add('active');
            if(underline) { underline.style.left = '120px'; }
        }

        if (headerLogin) headerLogin.addEventListener('click', showLogin);
        if (headerSignup) headerSignup.addEventListener('click', showSignup);
        if (switchToLogin) switchToLogin.addEventListener('click', showLogin);
        if (switchToSignup) switchToSignup.addEventListener('click', showSignup);
    </script>
</body>
</html>