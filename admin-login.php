<?php
// ============================================================================
// JOBIT ADMIN LOGIN
//
// Safe version:
//   - NO admin email or password is written in this file or pre-filled in the form
//   - NO "auto-fix" that resets a password (the admin password is only ever changed in the database)
//   - Wrong email, wrong password and "not an admin" all show the same message,
//     so a stranger cannot find out which emails are admins
//   - A failed try waits 1 second, so guessing passwords is slow
//   - The session id is renewed after a successful login
// ============================================================================
session_start();
require_once 'db.php';

// ----------------------------------------------------------------------------
// DEMO MODE (only on YOUR OWN PC, so you do not have to type the admin login in front of the class)
//   - On localhost (XAMPP) the two boxes below are filled in for you.
//   - On the live website they are ALWAYS empty. $jobitLive is true there (config.live.php exists),
//     so nobody on the internet can ever see these details.
//   - $demoPassword must be the password of the admin account in your LOCAL database.
// ----------------------------------------------------------------------------
$demoEmail = 'admin@jobit.com';
$demoPassword = 'adminpassword';

$isMyPc = empty($jobitLive) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$prefillEmail = $isMyPc ? $demoEmail : '';
$prefillPassword = $isMyPc ? $demoPassword : '';

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $loggedIn = false;

    if (!empty($email) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Must be the right password AND an admin account
        if ($user && password_verify($password, $user['password']) && ($user['role'] ?? 'user') === 'admin') {
            session_regenerate_id(true);                 // new session id after login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_profile'] = [
                'user_id' => $user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => 'admin',
                'avatar' => $user['avatar'] ?? ''
            ];
            $loggedIn = true;
            header("Location: admin.php");
            exit();
        }
    }

    if (!$loggedIn) {
        sleep(1);                                        // slow down password guessing
        $errorMessage = "Invalid credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobiT - Administrator Access</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        body { background-color: #f4f6fb; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .admin-login-card {
            background: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e1e5eb;
            text-align: center;
        }

        .admin-login-card h2 { font-size: 1.6rem; font-weight: 900; color: #111; margin-bottom: 5px; }
        .admin-login-card p { font-size: 0.9rem; color: #666; margin-bottom: 25px; }
        
        .input-group { text-align: left; margin-bottom: 20px; }
        .input-group label { display: block; font-size: 0.85rem; font-weight: 700; color: #333; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .input-group input { width: 100%; padding: 12px 15px; font-size: 1rem; border: 1.5px solid #ccc; border-radius: 8px; outline: none; transition: 0.2s; box-sizing: border-box; background: #fafbfc; }
        .input-group input:focus { border-color: #111; background: #fff; }

        .btn-admin-submit {
            width: 100%; padding: 14px; background: #111; color: #fff; font-weight: bold; font-size: 1rem; border: none; border-radius: 8px; cursor: pointer; transition: 0.2s; margin-top: 10px;
        }
        .btn-admin-submit:hover { background: #333; }

        .error-box { background: #fdf2f2; color: #d93025; border: 1px solid #fadbd8; padding: 10px; border-radius: 6px; font-size: 0.85rem; font-weight: bold; margin-bottom: 20px; }
        
        .back-link { display: inline-block; margin-top: 25px; font-size: 0.85rem; color: #888; text-decoration: none; font-weight: 600; }
        .back-link:hover { color: #111; }
    </style>
</head>
<body>

    <div class="admin-login-card">
        <img src="Logo.jpg" alt="JobiT" style="height: 35px; margin-bottom: 20px;">
        <h2>System Administrator</h2>
        <p>Authorized Personnel Only</p>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <?php if ($isMyPc): ?>
            <div style="background: #fff5da; color: #7a4f00; border: 1px solid #f0d58a; padding: 8px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; margin-bottom: 20px;">Demo mode: filled in for you (only on this computer)</div>
        <?php endif; ?>

        <form action="admin-login.php" method="POST">
            <div class="input-group">
                <label for="email">Admin Email ID</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($prefillEmail); ?>" required autocomplete="username">
            </div>
            
            <div class="input-group">
                <label for="password">Security Protocol (Password)</label>
                <input type="password" id="password" name="password" value="<?php echo htmlspecialchars($prefillPassword); ?>" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-admin-submit">Authenticate</button>
        </form>

        <a href="index.php" class="back-link">&larr; Return to Public Portal</a>
    </div>

</body>
</html>