<?php
/** File-backed account authentication, independent of MySQL/demo mode. */
function auth_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
    session_start();
}
function auth_file(): string { return __DIR__ . '/../storage/accounts.php'; }
function auth_accounts(): array {
    if (!is_file(auth_file())) return [];
    $contents = @file_get_contents(auth_file());
    $prefix = "<?php exit; ?>\n";
    if (!is_string($contents) || substr($contents, 0, strlen($prefix)) !== $prefix) return [];
    $users = json_decode(substr($contents, strlen($prefix)), true);
    return is_array($users) ? $users : [];
}
function auth_has_accounts(): bool { return count(auth_accounts()) > 0; }
function auth_current_user(): ?string { return is_string($_SESSION['username'] ?? null) ? $_SESSION['username'] : null; }
function auth_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function auth_csrf_valid($candidate): bool {
    return is_string($candidate) && hash_equals(auth_csrf(), $candidate);
}
function auth_create_first_admin(string $username, string $password, string $confirm): string {
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $username)) return 'Username must be 3–30 characters (letters, digits, dot, underscore or hyphen).';
    if (strlen($password) < 12) return 'Password must contain at least 12 characters.';
    if ($password !== $confirm) return 'Passwords do not match.';
    $dir = dirname(auth_file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return 'Unable to create the storage folder. Check folder permissions.';
    $lock = @fopen($dir . '/.account-setup.lock', 'c');
    if (!$lock) return 'Cannot write to the storage folder. Check permissions.';
    try {
        if (!flock($lock, LOCK_EX)) return 'Account setup is temporarily unavailable.';
        if (auth_has_accounts()) return 'An administrator account already exists. Please sign in.';
        $users = [strtolower($username) => ['username'=>$username, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'role'=>'admin']];
        $data = "<?php exit; ?>\n" . json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents(auth_file(), $data, LOCK_EX) === false) return 'Could not save your account. Check folder permissions.';
        @chmod(auth_file(), 0600);
        return '';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function auth_login(string $username, string $password): bool {
    $account = auth_accounts()[strtolower(trim($username))] ?? null;
    if (!$account || !password_verify($password, $account['password_hash'] ?? '')) return false;
    session_regenerate_id(true);
    $_SESSION['username'] = $account['username'];
    $_SESSION['role'] = $account['role'];
    unset($_SESSION['failed_logins'], $_SESSION['login_lock_until']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}
function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
