<?php
session_start();
require_once 'db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    if (!empty($email) && !empty($new_password)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $hashedPassword = password_hash($new_password, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $updateStmt->execute([$hashedPassword, $email]);

            $message = "Password successfully updated! You can now log in.";
        } else {
            $error = "No account found with that email address.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JobiT - Reset Password</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body style="background:#f8f9fa; display:flex; justify-content:center; align-items:center; min-height:100vh;">
    <div style="background:#fff; padding:40px; border-radius:16px; width:100%; max-width:420px; box-shadow:0 4px 20px rgba(0,0,0,0.08);">
        <h2 style="margin-bottom:10px; font-weight:800;">Reset Password</h2>
        <p style="color:#666; margin-bottom:20px; font-size:0.95rem;">Enter your email and new password to update your account.</p>

        <?php if (!empty($message)): ?>
            <p style="color:#1e8e3e; background:#e6f9ed; padding:10px; border-radius:8px; font-weight:600;"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:#d9534f; background:#fff0f0; padding:10px; border-radius:8px; font-weight:600;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="POST" action="reset-password.php">
            <div style="margin-bottom:15px;">
                <label style="display:block; font-weight:700; margin-bottom:5px;">Email</label>
                <input type="email" name="email" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; box-sizing:border-box;">
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:700; margin-bottom:5px;">New Password</label>
                <input type="password" name="new_password" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc; box-sizing:border-box;">
            </div>
            <button type="submit" style="width:100%; padding:12px; background:#4154f1; color:#fff; border:none; border-radius:25px; font-weight:700; cursor:pointer;">Update Password</button>
        </form>
        <p style="text-align:center; margin-top:20px;"><a href="auth.php?mode=login" style="color:#333; font-weight:600; text-decoration:none;">&larr; Back to Login</a></p>
    </div>
</body>
</html> 