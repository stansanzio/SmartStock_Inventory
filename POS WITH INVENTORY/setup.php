<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$message = '';

try {
    $pdo = getDatabaseConnection();
  ensureAdminAccountRequestSchema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['setup_csrf'], $csrf)) {
            http_response_code(403);
            $message = 'The setup form expired. Reload this page and try again.';
        } else {
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');

            if ($displayName === '' || mb_strlen($displayName) > 100) {
                $message = 'Enter a name up to 100 characters long.';
            } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                $message = 'Username must be 3–50 characters and use only letters, numbers, dots, underscores, or hyphens.';
            } elseif (!in_array($role, ['admin', 'employee'], true)) {
              $message = 'Choose an administrator or employee account.';
            } elseif (strlen($password) < 10 || strlen($password) > 4096) {
                $message = 'Password must be at least 10 characters long.';
            } else {
              $requestExists = $pdo->prepare('SELECT 1 FROM admin_account_requests WHERE username = :username LIMIT 1');
              $requestExists->execute(['username' => $username]);
              if ($requestExists->fetchColumn()) {
                $message = 'That username is reserved by a pending administrator request.';
              }
              if ($message === '' && $role === 'admin') {
                $hasAdmin = (bool) $pdo->query(
                  "SELECT 1 FROM users WHERE role IN ('admin', 'owner') LIMIT 1"
                )->fetchColumn();
                if ($hasAdmin) {
                  $userExists = $pdo->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
                  $userExists->execute(['username' => $username]);
                  if ($userExists->fetchColumn()) {
                    $message = 'That username is already in use.';
                  } else {
                    $statement = $pdo->prepare(
                      'INSERT INTO admin_account_requests (display_name, username, password_hash) VALUES (:display_name, :username, :password_hash)'
                    );
                    $statement->execute([
                      'display_name' => $displayName,
                      'username' => $username,
                      'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    session_regenerate_id(true);
                    header('Location: adminpage/Login.html?setup=pending');
                    exit;
                  }
                }
              }
              if ($message === '') {
              $statement = $pdo->prepare(
                'INSERT INTO users (display_name, username, password_hash, role) VALUES (:display_name, :username, :password_hash, :role)'
              );
              $statement->execute([
                'display_name' => $displayName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
              ]);
              session_regenerate_id(true);
              $loginPage = $role === 'employee' ? 'adminpage/employee-login.html' : 'adminpage/Login.html';
              header('Location: ' . $loginPage . '?setup=complete');
              exit;
              }
            }
        }
    }
} catch (Throwable $exception) {
    error_log($exception->__toString());
    $message = 'Could not connect to the database. Import database.sql in phpMyAdmin and check the settings in config.php.';
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set up SmartStock POS</title>
  <link rel="stylesheet" href="style.css?v=20261004">
  <style>
    body.setup-page {
      position: relative;
      min-height: 100vh;
      min-height: 100svh;
      overflow-x: hidden;
      display: grid;
      place-items: center;
      padding: 28px 18px;
      background:
        radial-gradient(ellipse at 12% 12%, rgba(61, 119, 174, .26), transparent 36%),
        radial-gradient(ellipse at 88% 90%, rgba(40, 118, 168, .25), transparent 38%),
        linear-gradient(135deg, #101b2b 0%, #1a2940 52%, #244362 100%);
      color: #f4f8ff;
      font-family: Inter, "Segoe UI", Arial, sans-serif;
    }
    body.setup-page::before,
    body.setup-page::after {
      position: fixed;
      z-index: 0;
      border: 1px solid rgba(202, 229, 255, .1);
      border-radius: 50%;
      content: "";
      pointer-events: none;
    }
    body.setup-page::before {
      width: 360px;
      height: 360px;
      top: -220px;
      left: -145px;
      box-shadow: 0 0 0 34px rgba(202, 229, 255, .025), 0 0 0 76px rgba(202, 229, 255, .018);
    }
    body.setup-page::after {
      width: 270px;
      height: 270px;
      right: -125px;
      bottom: -150px;
      box-shadow: 0 0 0 35px rgba(81, 198, 236, .035), 0 0 0 78px rgba(81, 198, 236, .02);
    }
    body.setup-page #container {
      position: relative;
      z-index: 1;
      display: flex;
      width: min(100%, 430px);
      padding: 32px clamp(22px, 6vw, 38px) 28px;
      border: 1px solid rgba(222, 237, 255, .2);
      border-radius: 24px;
      background: linear-gradient(145deg, rgba(44, 62, 83, .96), rgba(34, 49, 68, .96));
      box-shadow: 0 28px 70px rgba(4, 11, 23, .38), inset 0 1px rgba(255, 255, 255, .12);
      backdrop-filter: blur(18px);
    }
    .setup-logo {
      width: 132px;
      margin: 0 auto 12px;
    }
    .setup-logo img {
      display: block;
      width: 100%;
      height: auto;
      filter: drop-shadow(0 8px 15px rgba(4, 12, 27, .24));
    }
    .setup-heading {
      width: 100%;
      margin-bottom: 23px;
      text-align: center;
    }
    .setup-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 7px;
      color: #9bdcff;
      font-size: .76rem;
      font-weight: 750;
      letter-spacing: .17em;
      text-transform: uppercase;
    }
    .setup-eyebrow::before {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #2b9a79;
      content: "";
    }
    body.setup-page h1 {
      margin: 0;
      padding: 0;
      color: #ffffff;
      font-family: Inter, "Segoe UI", Arial, sans-serif;
      font-size: clamp(1.5rem, 6vw, 1.85rem);
      font-weight: 750;
      line-height: 1.2;
      letter-spacing: -.04em;
      text-align: center;
      text-shadow: none;
    }
    .setup-description {
      width: 100%;
      margin: 0 0 23px;
      color: rgba(233, 242, 255, .76);
      font-size: .9rem;
      line-height: 1.5;
      text-align: center;
    }
    .setup-form {
      display: grid;
      width: 100%;
      gap: 15px;
    }
    .setup-form label {
      display: grid;
      gap: 7px;
      color: #e6effc;
      font-size: .82rem;
      font-weight: 650;
    }
    .setup-form input,
    .setup-form select {
      box-sizing: border-box;
      width: 100%;
      min-height: 49px;
      padding: 12px 14px;
      border: 1px solid rgba(219, 232, 250, .23);
      border-radius: 12px;
      background: rgba(14, 27, 46, .32);
      color: #ffffff;
      font: inherit;
      font-size: .94rem;
      transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .setup-form input:focus,
    .setup-form select:focus {
      border-color: #71ccff;
      outline: none;
      background: rgba(14, 27, 46, .48);
      box-shadow: 0 0 0 4px rgba(93, 192, 255, .15);
    }
    .setup-form select option {
      background: #1a2940;
      color: #ffffff;
    }
    .setup-form button {
      min-height: 50px;
      margin-top: 4px;
      border-radius: 12px;
      background: linear-gradient(120deg, #39c4f1, #3187ef);
      font-size: 1rem;
      letter-spacing: .01em;
      box-shadow: 0 10px 24px rgba(36, 138, 237, .3);
    }
    .setup-back-link {
      width: 100%;
      min-height: 42px;
      margin-top: 10px;
      border-radius: 11px;
      font-size: .76rem;
    }
    .setup-message {
      margin: 0 0 18px;
      padding: 12px 14px;
      border: 1px solid rgba(255, 140, 130, .35);
      border-radius: 11px;
      background: rgba(130, 37, 37, .18);
      color: #ffd8d4;
      font-size: .92rem;
      line-height: 1.55;
    }
    .setup-link {
      display: flex;
      min-height: 50px;
      align-items: center;
      justify-content: center;
      margin-top: 22px;
      border-radius: 12px;
      background: linear-gradient(135deg, #279ed1, #327ce0);
      box-shadow: 0 8px 18px rgba(50, 124, 224, .22);
      color: #fff;
      font-size: .96rem;
      font-weight: 700;
      text-decoration: none;
      transition: filter .18s ease, transform .18s ease, box-shadow .18s ease;
    }
    .setup-link:hover {
      transform: translateY(-1px);
      filter: brightness(1.05);
      box-shadow: 0 11px 22px rgba(50, 124, 224, .28);
    }
    .setup-link:focus-visible {
      outline: 3px solid #398bd0;
      outline-offset: 3px;
    }
    @media (max-width: 480px) {
      body.setup-page { padding: 18px 14px; }
      body.setup-page #container { border-radius: 19px; }
    }
  </style>
</head>
<body class="setup-page">
  <main id="container">
    <div class="setup-logo">
      <img src="images/image.png" alt="SmartStock POS Logo">
    </div>
    <header class="setup-heading">
      <span class="setup-eyebrow">SmartStock POS</span>
      <h1>Account setup</h1>
    </header>
    <p class="setup-description">Admin accounts require approval from an existing admin. The first admin can be created during initial setup.</p>
    <?php if ($message !== ''): ?>
      <p class="setup-message" role="alert"><?= escapeHtml($message) ?></p>
    <?php endif; ?>
    <form class="setup-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= escapeHtml((string) $_SESSION['setup_csrf']) ?>">
      <label>Name<input name="display_name" maxlength="100" autocomplete="name" required></label>
      <label>Account type<select name="role" required><option value="admin">Administrator</option><option value="employee">Employee</option></select></label>
      <label>Username<input name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]+" autocomplete="username" required></label>
      <label>Password (10+ characters)<input name="password" type="password" minlength="10" maxlength="4096" autocomplete="new-password" required></label>
      <button type="submit">Create account</button>
    </form>
    <a class="back-link setup-back-link" href="adminpage/Login.html">
      <span aria-hidden="true">←</span>
      <span>BACK TO ADMIN LOGIN</span>
    </a>
  </main>
</body>
</html>
