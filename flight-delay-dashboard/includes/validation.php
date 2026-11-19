<?php
/** Offline results of a reproducible sklearn holdout evaluation. */
function validation_report(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $path = __DIR__ . '/../data/validation_results.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    $cached = is_array($data) && isset($data['models'], $data['validation_rows']) ? $data : null;
    return $cached;
}
function validation_best_model(?array $report): ?array {
    if (!$report || empty($report['models'])) return null;
    $models = array_values($report['models']);
    usort($models, fn($a,$b) => ($b['metrics']['f1'] ?? -1) <=> ($a['metrics']['f1'] ?? -1));
    return $models[0];
}
function validation_pct(?float $number): string {
    return $number === null ? 'N/A' : number_format($number * 100, 1) . '%';
}
function validation_decimal(?float $number): string {
    return $number === null ? 'N/A' : number_format($number, 3);
}
