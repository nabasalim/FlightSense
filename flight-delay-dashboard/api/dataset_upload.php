<?php
/** Authenticated, chunked dataset uploader. The browser sends 1 MiB slices to avoid php.ini upload limits. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ml.php';
auth_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function upload_response(bool $ok, array $other = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(['ok' => $ok] + $other, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}
function upload_fail(string $message, int $status = 400): void {
    upload_response(false, ['error' => $message], $status);
}
function upload_folder(): string {
    $folder = __DIR__ . '/../storage/datasets';
    if (!is_dir($folder) && !@mkdir($folder, 0700, true)) upload_fail('Cannot create the private datasets folder.', 500);
    return $folder;
}
function upload_cleanup(?array $state): void {
    if (is_array($state)) {
        $path = (string)($state['path'] ?? '');
        // Only unlink files generated in our own private folder, never client paths.
        $folder = realpath(upload_folder());
        if ($folder && preg_match('/^upload_[a-f0-9]{32}\.part$/', basename($path)) && dirname($path) === $folder) {
            @unlink($path);
        }
    }
    unset($_SESSION['dataset_upload']);
}
function upload_state(): array {
    $state = $_SESSION['dataset_upload'] ?? null;
    $id = (string)($_POST['upload_id'] ?? '');
    if (!is_array($state) || !preg_match('/^[a-f0-9]{32}$/', $id)
        || !hash_equals((string)$state['id'], $id)) upload_fail('Upload session not found. Select the CSV again.', 409);
    return $state;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') upload_fail('POST required.', 405);
if (!auth_current_user()) upload_fail('Session expired. Log in and select your CSV again.', 401);
if (!auth_csrf_valid($_POST['csrf'] ?? null)) upload_fail('Security token expired. Refresh and retry.', 403);
$action = (string)($_POST['action'] ?? '');

if ($action === 'start') {
    $name = trim((string)($_POST['filename'] ?? ''));
    $bytesStr = (string)($_POST['size'] ?? '');
    if ($name === '' || strlen($name) > 240 || !preg_match('/\.csv$/i', $name)) upload_fail('Please select an uncompressed .csv file from the Kaggle Flight Data 2024 dataset.');
    if (!preg_match('/^[1-9][0-9]*$/', $bytesStr) || strlen($bytesStr) > 14) upload_fail('Invalid CSV file size.');
    $bytes = (int)$bytesStr;
    if ($bytes < 100 || $bytes > 8 * 1024 * 1024 * 1024) upload_fail('CSV file must be between 100 bytes and 8 GB.');
    $folder = realpath(upload_folder());
    // Remove uploads abandoned for at least two days, but never touch active recent transfers.
    foreach (glob($folder . DIRECTORY_SEPARATOR . 'upload_*.part') ?: [] as $stale) {
        if (@filemtime($stale) < time() - 2 * 24 * 3600) @unlink($stale);
    }
    $free = @disk_free_space($folder);
    if ($free !== false && $free < $bytes + 30 * 1024 * 1024) upload_fail('Not enough free server disk space for this file.');
    upload_cleanup($_SESSION['dataset_upload'] ?? null);
    $id = bin2hex(random_bytes(16));
    $path = $folder . DIRECTORY_SEPARATOR . 'upload_' . $id . '.part';
    if (@file_put_contents($path, '') === false) upload_fail('Cannot write to private dataset storage.', 500);
    @chmod($path, 0600);
    $_SESSION['dataset_upload'] = ['id' => $id, 'name' => $name, 'size' => $bytes, 'received' => 0, 'path' => $path, 'started' => time()];
    upload_response(true, ['upload_id' => $id, 'received' => 0]);
}

if ($action === 'chunk') {
    $state = upload_state();
    $chunk = $_FILES['chunk'] ?? null;
    if (!is_array($chunk) || (int)($chunk['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($chunk['tmp_name'] ?? ''))) {
        upload_fail('Unable to receive a CSV chunk. Check PHP file uploads and try again.');
    }
    $size = (int)($chunk['size'] ?? 0);
    if ($size < 1 || $size > 1 * 1024 * 1024) upload_fail('Invalid chunk size.');
    if (!ctype_digit((string)($_POST['offset'] ?? '')) || (int)$_POST['offset'] !== (int)$state['received']) upload_fail('Upload chunks are out of order. Please select the CSV again.', 409);
    if ($state['received'] + $size > $state['size']) upload_fail('Chunk exceeds the selected CSV file size.');
    $out = @fopen($state['path'], 'c+b');
    $in = @fopen($chunk['tmp_name'], 'rb');
    if (!$out || !$in) {
        if ($out) fclose($out);
        if ($in) fclose($in);
        upload_fail('Unable to open dataset upload storage.', 500);
    }
    try {
        if (!flock($out, LOCK_EX) || fseek($out, 0, SEEK_END) !== 0 || ftell($out) !== (int)$state['received']) upload_fail('Upload state is inconsistent. Select the CSV again.', 409);
        $written = stream_copy_to_stream($in, $out, $size);
        if ($written !== $size || !fflush($out)) upload_fail('Could not save the full CSV chunk. Check disk space.', 500);
    } finally {
        flock($out, LOCK_UN);
        fclose($out);
        fclose($in);
    }
    $_SESSION['dataset_upload']['received'] += $size;
    upload_response(true, ['received' => $_SESSION['dataset_upload']['received']]);
}

if ($action === 'cancel') {
    upload_cleanup(upload_state());
    upload_response(true, ['message' => 'Partial upload removed.']);
}

if ($action === 'finish') {
    $state = upload_state();
    if ($state['received'] !== $state['size'] || !is_file($state['path']) || filesize($state['path']) !== $state['size']) upload_fail('The CSV was not uploaded completely. Please select the file again.', 409);
    // Validate the CSV header before starting a potentially long Python training job.
    $fh = @fopen($state['path'], 'rb');
    if (!$fh) upload_fail('Unable to read the uploaded CSV.', 500);
    $header = fgetcsv($fh, 8192, ',', '"', '');
    fclose($fh);
    if (!is_array($header)) { upload_cleanup($state); upload_fail('Empty or invalid CSV header.'); }
    $cols = array_map(static fn($v) => strtolower(trim((string)$v, " \t\r\n\0\x0B\xEF\xBB\xBF")), $header);
    $required = ['month', 'day_of_month', 'day_of_week', 'op_unique_carrier', 'origin', 'dest', 'crs_dep_time', 'arr_delay', 'cancelled', 'diverted', 'crs_elapsed_time', 'distance'];
    $missing = array_diff($required, $cols);
    if ($missing) { upload_cleanup($state); upload_fail('This CSV does not match the Kaggle Flight Data 2024 dataset. Missing: ' . implode(', ', $missing)); }
    $path = substr($state['path'], 0, -5) . '.csv';
    if (!@rename($state['path'], $path)) upload_fail('Could not finalize the uploaded CSV.', 500);
    unset($_SESSION['dataset_upload']);
    // Full CSV is saved and examined. Model fitting uses a representative sample to fit on typical computers.
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($state['name']));
    $result = ml_train_stored_dataset($path, $name !== '' ? $name : 'uploaded.csv');
    if (!($result['ok'] ?? false)) {
        @unlink($path);
        upload_response(false, ['error' => 'Upload completed, but model training failed: ' . ($result['error'] ?? 'Unknown training error')], 422);
    }
    foreach (glob(upload_folder() . '/upload_*.csv') ?: [] as $previous) {
        if ($previous !== $path) @unlink($previous);
    }
    $reportPath = __DIR__ . '/../data/validation_results.json';
    $report = is_file($reportPath) ? json_decode((string)file_get_contents($reportPath), true) : null;
    $accuracies = [];
    foreach (($report['models'] ?? []) as $slug => $model) {
        $accuracies[] = ['name' => (string)($model['name'] ?? $slug), 'accuracy' => $model['metrics']['accuracy'] ?? null];
    }
    upload_response(true, [
        'message' => 'CSV uploaded; stage-specific models retrained successfully. Review both accuracy and delay recall.',
        'dataset' => $result['dataset'] ?? $name,
        'eligible_rows' => $result['eligible_rows'] ?? 0,
        'sampled_rows' => $result['sampled_rows'] ?? 0,
        'validation_rows' => $result['validation_rows'] ?? 0,
        'accuracy' => $accuracies,
        'after_accuracy' => array_map(static fn($m) => ['name'=>$m['name'], 'accuracy'=>$m['metrics']['accuracy']], array_values($report['mode_reports']['after_departure']['models'] ?? [])),
    ]);
}
upload_fail('Unknown upload action.', 400);
