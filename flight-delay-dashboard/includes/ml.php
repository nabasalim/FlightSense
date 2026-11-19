<?php
/** Train and predict through Python/scikit-learn without accepting executable uploads. */
function ml_python(): string {
    // On XAMPP Windows, set FLIGHTSENSE_PYTHON to full python.exe path if not in PATH.
    $configured = trim((string)(getenv('FLIGHTSENSE_PYTHON') ?: ''));
    return $configured !== '' ? $configured : (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
}
function ml_run(array $args): array {
    if (!function_exists('proc_open')) return ['ok'=>false, 'error'=>'PHP proc_open is disabled. Enable it in php.ini or train with the Python command line.'];
    $command = array_merge([ml_python(), __DIR__ . '/../tools/model_pipeline.py'], $args);
    $pipes = [];
    $spec = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
    $proc = @proc_open($command, $spec, $pipes, __DIR__ . '/..');
    if (!is_resource($proc)) return ['ok'=>false, 'error'=>'Cannot run Python. Install Python 3 and requirements, or configure FLIGHTSENSE_PYTHON.'];
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 150000);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2], 8000);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $json = json_decode(trim((string)$output), true);
    if ($code !== 0 || !is_array($json) || ($json['ok'] ?? false) !== true) {
        $message = is_array($json) ? ($json['error'] ?? '') : '';
        if (!$message) $message = trim((string)$error);
        if (!$message) $message = 'Python prediction/training failed (exit ' . $code . ').';
        return ['ok'=>false, 'error'=>substr($message, 0, 650)];
    }
    return $json;
}
/** Train on a private server-side CSV; full file is scanned in chunks by Python. */
function ml_train_stored_dataset(string $path, string $label): array {
    if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
        return ['ok'=>false, 'error'=>'Uploaded CSV is not available.'];
    }
    if (function_exists('set_time_limit')) @set_time_limit(0);
    // 50,000 uniformly sampled eligible records keep training practical on typical XAMPP PCs.
    return ml_run(['train', '--dataset', $path, '--max-rows', '50000', '--label', $label]);
}
function ml_clean_input(array $input): array {
    $values = [];
    $values['flight_number'] = strtoupper(trim((string)($input['flight_number'] ?? '')));
    $values['op_unique_carrier'] = strtoupper(trim((string)($input['op_unique_carrier'] ?? '')));
    $values['origin'] = strtoupper(trim((string)($input['origin'] ?? '')));
    $values['dest'] = strtoupper(trim((string)($input['dest'] ?? '')));
    $values['flight_date'] = trim((string)($input['flight_date'] ?? ''));
    $values['departure_time'] = trim((string)($input['departure_time'] ?? ''));
    $values['crs_elapsed_time'] = trim((string)($input['crs_elapsed_time'] ?? ''));
    $values['distance'] = trim((string)($input['distance'] ?? ''));
    $values['mode'] = trim((string)($input['mode'] ?? 'predeparture'));
    if (!in_array($values['mode'], ['predeparture', 'after_departure'], true)) throw new InvalidArgumentException('Choose a valid prediction mode.');
    if ($values['mode'] === 'after_departure') {
        $values['dep_delay'] = trim((string)($input['dep_delay'] ?? ''));
        if (!is_numeric($values['dep_delay']) || (float)$values['dep_delay'] < -120 || (float)$values['dep_delay'] > 1440) throw new InvalidArgumentException('After-departure mode requires a real departure delay between -120 and 1440 minutes.');
    }
    if ($values['flight_number'] !== '' && !preg_match('/^[A-Z0-9-]{2,20}$/', $values['flight_number'])) throw new InvalidArgumentException('Flight number must be 2–20 letters/numbers.');
    if (!preg_match('/^[A-Z0-9]{2,4}$/', $values['op_unique_carrier'])) throw new InvalidArgumentException('Enter a valid airline code (such as AA).');
    if (!preg_match('/^[A-Z]{3,4}$/', $values['origin']) || !preg_match('/^[A-Z]{3,4}$/', $values['dest'])) throw new InvalidArgumentException('Use three-letter airport codes such as JFK and LAX.');
    if ($values['origin'] === $values['dest']) throw new InvalidArgumentException('Origin and destination airports must differ.');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $values['flight_date']);
    if (!$parsed || $parsed->format('Y-m-d') !== $values['flight_date']) throw new InvalidArgumentException('Provide a valid flight date.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values['departure_time'])) throw new InvalidArgumentException('Enter a scheduled departure time in HH:MM format.');
    if (!is_numeric($values['crs_elapsed_time']) || (float)$values['crs_elapsed_time'] < 20 || (float)$values['crs_elapsed_time'] > 1500) throw new InvalidArgumentException('Scheduled duration must be 20–1500 minutes.');
    if (!is_numeric($values['distance']) || (float)$values['distance'] < 1 || (float)$values['distance'] > 20000) throw new InvalidArgumentException('Distance must be 1–20000 miles.');
    return $values;
}
function ml_predict_input(array $input): array {
    try { $values = ml_clean_input($input); }
    catch (InvalidArgumentException $e) { return ['ok'=>false, 'error'=>$e->getMessage()]; }
    return ml_run(['predict', '--input-json', json_encode($values, JSON_THROW_ON_ERROR)]);
}
