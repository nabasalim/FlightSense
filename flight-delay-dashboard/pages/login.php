<?php if (!defined('FLIGHTSENSE_LOGIN_SCREEN')) { http_response_code(403); exit('Access denied'); }
 $setup = !auth_has_accounts(); ?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <title><?= $setup ? 'Create administrator' : 'Sign in' ?> · FlightSense</title>
 <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/assets/css/style.css?v=4">
</head>
<body class="login-body">
 <div class="auth-layout">
  <section class="auth-art"><div class="auth-art-content"><div class="auth-logo"><span class="brand-mark">✈</span> FlightSense</div><span class="auth-eyebrow">FLIGHT DELAY PREDICTION</span><h1>Smarter flight decisions<br>start here.</h1><p>Explore flight records, compare real holdout validation results, and visualize model performance in one dashboard.</p><div class="auth-bullets"><div><span>✓</span> Three evaluated algorithms</div><div><span>✓</span> Accuracy, precision, recall &amp; more</div><div><span>✓</span> Secure administration</div></div></div><div class="auth-art-bottom">FLIGHTSENSE ANALYTICS · PHP + MYSQL</div></section>
  <main class="auth-form-container"><div class="auth-form-card"><span class="auth-mini-logo">✈</span><p class="eyebrow accent"><?= $setup ? 'FIRST-TIME SETUP' : 'WELCOME BACK' ?></p><h2><?= $setup ? 'Create your administrator account' : 'Sign in to FlightSense' ?></h2><p class="auth-subtitle"><?= $setup ? 'No default password is included. Create the account that will protect this installation.' : 'Enter your administrator credentials to access the dashboard.' ?></p>
   <?php if ($authError): ?><div class="alert danger-alert" role="alert"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
   <form method="post" class="auth-form" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(auth_csrf()) ?>">
    <input type="hidden" name="auth_action" value="<?= $setup ? 'setup' : 'login' ?>">
    <label for="username">Username</label><input id="username" name="username" value="<?= htmlspecialchars((string)($_POST['username'] ?? '')) ?>" placeholder="Enter username" minlength="3" maxlength="30" autocomplete="username" required autofocus>
    <label for="password">Password</label><input type="password" id="password" name="password" placeholder="<?= $setup ? 'At least 12 characters' : 'Enter your password' ?>" <?= $setup ? 'minlength="12" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required>
    <?php if ($setup): ?><label for="confirm_password">Confirm password</label><input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" minlength="12" autocomplete="new-password" required><?php endif; ?>
    <button class="btn btn-primary auth-submit" type="submit"><?= $setup ? 'Create account & start' : 'Sign in' ?> <span>→</span></button>
   </form>
   <p class="auth-hint"><?= $setup ? 'The account is stored as a one-way password hash inside the protected storage directory.' : 'For account recovery, see the local administrator instructions in README_XAMPP.md.' ?></p>
  </div></main>
 </div>
</body>
</html>
