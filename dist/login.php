<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'Email dan password wajib diisi.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, role, kab_kode FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'kab_kode' => $user['kab_kode'],
            ];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Email atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Login</title>
    <link rel="stylesheet" href="assets/vendors/mdi/css/materialdesignicons.min.css">
    <link rel="stylesheet" href="assets/vendors/css/vendor.bundle.base.css">
    <link rel="shortcut icon" href="assets/images/favicon.png" />
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
      body {
        margin: 0;
        font-family: "Poppins", sans-serif;
        background: radial-gradient(1200px 600px at 30% 0%, #f7f0f3 0%, #f7f5f6 50%, #f1eaee 100%);
        color: #374151;
        display: grid;
        place-items: center;
        min-height: 100vh;
        padding: 20px;
      }
      .card {
        width: 100%;
        max-width: 360px;
        background: #fff;
        border-radius: 18px;
        padding: 24px;
        box-shadow: 0 20px 50px rgba(56, 65, 80, 0.12);
      }
      .logo {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        background: linear-gradient(135deg, #ff7ab6, #ffb36b);
        color: #fff;
        font-weight: 700;
        margin-bottom: 12px;
      }
      h1 { font-size: 18px; margin: 0 0 12px; }
      .field { margin-bottom: 12px; }
      input {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13px;
      }
      button {
        width: 100%;
        border: 0;
        border-radius: 10px;
        padding: 10px 12px;
        background: #f28b2b;
        color: #fff;
        font-weight: 700;
      }
      .error {
        color: #b91c1c;
        font-size: 12px;
        margin-bottom: 10px;
      }
    </style>
  </head>
  <body>
    <div class="card">
      <div class="logo">RH</div>
      <h1>Login Rekon Harga</h1>
      <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="field">
          <input type="email" name="email" placeholder="Email" required>
        </div>
        <div class="field">
          <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit">Masuk</button>
      </form>
    </div>
  </body>
</html>
