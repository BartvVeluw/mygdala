<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::start();

if (AdminAuth::isLoggedIn()) {
    header('Location: /admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!Csrf::validate($token)) {
        $error = 'Je sessie is verlopen. Probeer het opnieuw.';
    } elseif ($username === '' || $password === '') {
        $error = 'Vul gebruikersnaam of e-mailadres en wachtwoord in.';
    } elseif (AdminAuth::attemptLogin($username, $password)) {
        // Straight to the first section this account may actually open — a
        // user without dashboard.view must not land on the dashboard.
        header('Location: ' . AdminAuth::landingUrl());
        exit;
    } else {
        usleep(300000); // slow down brute-force guessing
        // Deliberately one message for "no such account", "wrong password"
        // and "account is deactivated" — see App\Service\AdminAuth.
        $error = 'Onjuiste inloggegevens, of dit account is gedeactiveerd.';
    }
}

$csrfToken = Csrf::token();

// The login screen is the one admin page nobody is signed in to yet, so it
// carries the site's own name rather than a hardcoded one — the same value
// the sidebar shows once you are in.
$loginSiteName = \App\Service\SiteSettings::get('site_name');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin login — <?= htmlspecialchars($loginSiteName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body class="admin-login-page"<?= \App\Service\AdminTheme::bodyAttribute() ?>>
  <main class="admin-login">
    <h1><?= htmlspecialchars($loginSiteName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="admin-login__sub">Admin login</p>
    <?php if ($error !== null): ?>
      <p class="admin-alert admin-alert--error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login.php" class="admin-login__form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <label>Gebruikersnaam of e-mailadres
        <input type="text" name="username" autocomplete="username" required autofocus>
      </label>
      <label>Wachtwoord
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">Inloggen</button>
    </form>
  </main>
</body>
</html>
