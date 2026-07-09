<?php
require_once __DIR__ . '/includes/config.php';

if (logged_in()) { header('Location: /index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND active = TRUE");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
      if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ];
            header('Location: /index.php'); exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login —  Finance</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
</head>
<body>
<div class="login-wrap">
 <div class="login-card">
        <!-- Centered Logo Container -->
        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; margin-bottom: 1.5rem;">
            <img src="assets/image/logo.jpg" alt="Logo" style="height: 80px; width: auto; max-width: 120px; border-radius: 50%; object-fit: contain; margin-bottom: 10px;">
            <h1 style="text-align: center; margin: 0;">FMS</h1>
            <p style="text-align: center; margin: 5px 0 0 0;">Sign in to your account</p>
        </div>

    <?php if ($error): ?>
    <div class="flash flash-error" style="margin-bottom:1rem;">
      <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
      <div class="form-group mb-4">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required
               value="<?= e($_POST['username'] ?? '') ?>" placeholder="Enter your username" autofocus/>
      </div>
      <div class="form-group mb-6">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required placeholder="••••••••"/>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px;">
        Sign In
      </button>
    </form>
  </div>
</div>
</body>
</html>
