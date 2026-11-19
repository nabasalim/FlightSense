<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/ml.php';
auth_start();
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '.') $baseUrl = '';
$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'search', 'algorithms', 'analytics', 'flights', 'training', 'upload'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';
$authError = '';
$authInfo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    if (!auth_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $authError = 'Security token expired. Refresh this page and try again.';
    } else {
        $action = (string)$_POST['auth_action'];
        if ($action === 'logout' && auth_current_user()) {
            auth_logout();
            header('Location: ' . $baseUrl . '/index.php'); exit;
        }
        if ($action === 'setup' && !auth_has_accounts()) {
            $authError = auth_create_first_admin((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
            if ($authError === '' && auth_login((string)$_POST['username'], (string)$_POST['password'])) {
                header('Location: ' . $baseUrl . '/index.php'); exit;
            }
        }
        if ($action === 'login' && auth_has_accounts()) {
            if (time() < (int)($_SESSION['login_lock_until'] ?? 0)) {
                $authError = 'Too many attempts. Try again in a few minutes.';
            } elseif (auth_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
                header('Location: ' . $baseUrl . '/index.php'); exit;
            } else {
                $_SESSION['failed_logins'] = (int)($_SESSION['failed_logins'] ?? 0) + 1;
                if ($_SESSION['failed_logins'] >= 5) {
                    $_SESSION['login_lock_until'] = time() + 15 * 60;
                    $_SESSION['failed_logins'] = 0;
                    $authError = 'Too many attempts. Try again in 15 minutes.';
                } else $authError = 'Incorrect username or password.';
            }
        }
    }
}
if (!auth_current_user()) {
    header('Cache-Control: no-store, private');
    define('FLIGHTSENSE_LOGIN_SCREEN', true);
    require __DIR__ . '/pages/login.php';
    exit;
}
header('Cache-Control: no-store, private');
define('FLIGHTSENSE_AUTHENTICATED', true);
require_once __DIR__ . '/config/database.php';
$flight = null; $prediction = null; $searchMessage = ''; $adminMessage = '';
$livePrediction = null; $trainingMessage = ''; $trainingOk = false;
$searchForm = [
 'flight_number'=>'', 'op_unique_carrier'=>'AA', 'origin'=>'JFK', 'dest'=>'LAX',
 'flight_date'=>date('Y-m-d'), 'departure_time'=>'14:00', 'crs_elapsed_time'=>'360', 'distance'=>'2475',
 'mode'=>'predeparture', 'dep_delay'=>'',
];
if ($page === 'search' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['predict_flight'])) {
    $searchForm = array_merge($searchForm, array_intersect_key($_POST, $searchForm));
    if (!auth_csrf_valid($_POST['csrf'] ?? null)) { http_response_code(403); $searchMessage='Security token expired. Refresh and retry.'; }
    else {
        $livePrediction = ml_predict_input($searchForm);
        if (!($livePrediction['ok'] ?? false)) { $searchMessage = (string)($livePrediction['error'] ?? 'Prediction failed.'); $livePrediction = null; }
    }
}
if ($page === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['lookup_flight'])) {
    $lookup = strtoupper(trim((string)$_GET['lookup_flight']));
    if (preg_match('/^[A-Z0-9-]{2,20}$/', $lookup)) {
        $found = lookup_predeparture_schedule($lookup);
        if ($found) { $searchForm = array_merge($searchForm, $found); $searchMessage='Historical schedule loaded. Confirm all fields before predicting. This is not a real-time flight schedule.'; }
        else $searchMessage = 'No schedule found in your MySQL Kaggle table. Enter flight details manually or import the dataset into MySQL.';
    }
}
$report = validation_report();
if ($page === 'flights' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_flight'])) {
    if (!auth_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $adminMessage = 'Security token expired. Refresh this page and try again.';
    } else {
        $result = add_flight($_POST);
        $adminMessage = $result['message'];
    }
}
require __DIR__ . '/includes/header.php';
require __DIR__ . '/pages/' . $page . '.php';
require __DIR__ . '/includes/footer.php';
