<?php if (!defined('FLIGHTSENSE_AUTHENTICATED')) { http_response_code(403); exit('Access denied'); }
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="page-intro"><p><strong>Real machine-learning prediction:</strong> enter a flight's scheduled information and the three trained algorithms calculate their estimated probability of an <strong>arrival delay of at least 15 minutes</strong>. Choose <strong>Before departure</strong> with scheduled information, or <strong>After departure</strong> when the actual departure delay is known. These modes are trained and evaluated separately.</p></div>
<?php if (!$report || !is_file(__DIR__ . '/../storage/models.joblib')): ?>
<div class="alert danger-alert">A live model is not installed. Open <a href="index.php?page=training">Dataset &amp; Training</a> to train on the Kaggle CSV before predicting.</div>
<?php else: ?>
<div class="dataset-banner"><div><small>ACTIVE TRAINING DATASET</small><strong><?= $e($report['dataset']) ?></strong><span><?= number_format((int)($report['sampled_rows'] ?? $report['eligible_rows'])) ?> training + validation records sampled · <?= $e(substr((string)$report['created_utc'],0,10)) ?></span></div><a class="btn btn-soft" href="index.php?page=training">Change dataset</a></div>
<?php endif; ?>
<section class="panel prediction-panel"><div class="panel-head"><div><h3>Predict a flight</h3><p>Use scheduled details. Optionally add the actual departure delay once the flight has departed. Arrival outcomes and delay causes are never used as inputs.</p></div></div>
<form method="post" action="index.php?page=search" class="prediction-form">
<input type="hidden" name="predict_flight" value="1"><input type="hidden" name="csrf" value="<?= $e(auth_csrf()) ?>">
<div class="prediction-mode-options">
<label for="predictionMode"><strong>Prediction stage</strong></label>
<select name="mode" id="predictionMode">
<option value="predeparture" <?= ($searchForm['mode'] ?? 'predeparture') === 'predeparture' ? 'selected' : '' ?>>Before departure — scheduled information only</option>
<option value="after_departure" <?= ($searchForm['mode'] ?? '') === 'after_departure' ? 'selected' : '' ?>>After departure — actual departure delay known</option>
</select>
<p class="field-hint">After-departure models can be much more accurate because they know when the aircraft actually departed. Do not enter a guessed departure delay.</p>
</div>
<div class="field-grid">
<label>Flight number <small>(optional display label; not a model feature)</small><input name="flight_number" maxlength="20" value="<?= $e($searchForm['flight_number']) ?>" placeholder="AA148"></label>
<label>Airline/carrier code <small>Example: AA, DL, WN</small><input required name="op_unique_carrier" maxlength="4" value="<?= $e($searchForm['op_unique_carrier']) ?>" placeholder="AA"></label>
<label>Origin airport (IATA) <small>Departure airport</small><input required name="origin" maxlength="4" value="<?= $e($searchForm['origin']) ?>" placeholder="JFK"></label>
<label>Destination airport (IATA) <small>Arrival airport</small><input required name="dest" maxlength="4" value="<?= $e($searchForm['dest']) ?>" placeholder="LAX"></label>
<label>Flight date <small>Determines month and weekday</small><input required type="date" name="flight_date" value="<?= $e($searchForm['flight_date']) ?>"></label>
<label>Scheduled departure <small>Local airport time</small><input required type="time" name="departure_time" value="<?= $e($searchForm['departure_time']) ?>"></label>
<label>Scheduled duration (minutes) <small>Example: 360 = 6 hours</small><input required type="number" step="1" min="20" max="1500" name="crs_elapsed_time" value="<?= $e($searchForm['crs_elapsed_time']) ?>"></label>
<label>Flight distance (miles) <small>Great-circle/route distance</small><input required type="number" step="1" min="1" max="20000" name="distance" value="<?= $e($searchForm['distance']) ?>"></label>
</div>
<div class="prediction-actual-delay" id="actualDelayField" <?= ($searchForm['mode'] ?? '') !== 'after_departure' ? 'hidden' : '' ?>>
<label>Actual departure delay, minutes <small>(required only after the aircraft has departed; negative = early)</small><input id="actualDelayInput" name="dep_delay" type="number" step="1" min="-120" max="1440" value="<?= $e($searchForm['dep_delay'] ?? '') ?>" placeholder="For example 45"></label>
</div>
<div class="form-actions"><button class="btn btn-primary" type="submit">Predict with all three algorithms <span>→</span></button><span>The same saved classifiers are used in this page and the accuracy graphs.</span></div>
</form></section>
<script>
(()=>{const mode=document.getElementById('predictionMode'), row=document.getElementById('actualDelayField'), input=document.getElementById('actualDelayInput');
if(!mode||!row||!input)return;
function update(){const active=mode.value==='after_departure';row.hidden=!active;input.required=active;input.disabled=!active;}
mode.addEventListener('change',update);update();})();
</script>
<div class="panel quick-schedule"><h3>Optional: fill from historical flight number</h3><p>If you have imported the Kaggle CSV into MySQL, enter a historical carrier/flight number to fill the available schedule data. Otherwise enter the fields above manually.</p>
<form method="get" action="index.php" class="lookup-line"><input type="hidden" name="page" value="search"><input name="lookup_flight" maxlength="20" placeholder="For example AA148" required><button class="btn btn-soft">Load historical schedule</button></form></div>
<?php if($searchMessage): ?><div class="alert <?= $livePrediction ? 'success-alert' : 'danger-alert' ?>"><?= $e($searchMessage) ?></div><?php endif; ?>
<?php if($livePrediction):
    $activeMode = $livePrediction['mode'] ?? 'predeparture';
    $activeReport = $report['mode_reports'][$activeMode] ?? $report;
    $bestId = $livePrediction['best_model'];
    $winner = $livePrediction['models'][$bestId] ?? null;
    $score = $winner ? 100*(float)$winner['probability'] : null;
    $delay = $winner && $winner['delay_prediction'];
    $flightDetails = $livePrediction['flight'];
?>
<section class="prediction-results" id="prediction-results">
<div class="result-header"><div><p class="eyebrow accent"><?= $activeMode==='after_departure' ? 'AFTER-DEPARTURE ML PREDICTION' : 'PRE-DEPARTURE ML PREDICTION' ?></p><h2><?= $e($livePrediction['flight_number'] ?: $flightDetails['op_unique_carrier'].' flight') ?> <span class="muted">· <?= $e($flightDetails['origin']) ?> → <?= $e($flightDetails['dest']) ?></span></h2><p>Flight date <?= $e($searchForm['flight_date']) ?> · <?= $e($searchForm['departure_time']) ?> departure · trained on <?= $e($livePrediction['dataset']) ?></p></div><span class="status large <?= $delay?'danger':'success' ?>"><?= $delay ? 'Delayed predicted' : 'On-time predicted' ?></span></div>
<div class="panel winner-panel"><small>BEST HOLDOUT F1 MODEL · <?= $e($livePrediction['models'][$bestId]['name'] ?? $bestId) ?></small><div class="winner-main"><strong><?= $score===null?'N/A':number_format($score,1).'%' ?></strong><div><h3>Estimated chance of arrival delay ≥15 minutes</h3><p><?= $delay ? 'This model predicts the flight may be delayed.' : 'This model predicts the flight may arrive on time.' ?> Classification threshold: 50%. Compare accuracy with the on-time baseline and examine recall.</p></div></div><div class="risk-meter"><i style="width:<?= $score===null?0:number_format($score,1,'.','') ?>%"></i></div></div>
<div class="model-prediction-grid">
<?php foreach($livePrediction['models'] as $id=>$model): $riskPct=max(0,min(100,100*(float)$model['probability'])); ?>
<div class="panel prediction-model <?= $id===$bestId?'highlight-model':'' ?>"><div class="prediction-model-head"><h3><?= $e($model['name']) ?></h3><?php if($id===$bestId): ?><span class="badge success">Highest F1</span><?php endif; ?></div><strong class="prediction-percentage"><?= number_format($riskPct,1) ?>%</strong><span class="prediction-label">Estimated delay probability</span><div class="risk-meter"><i style="width:<?= number_format($riskPct,1,'.','') ?>%"></i></div><p><?= $model['delay_prediction'] ? 'Predicts delayed' : 'Predicts on time' ?> · Holdout accuracy <?=validation_pct($activeReport['models'][$id]['metrics']['accuracy'] ?? null)?></p></div>
<?php endforeach; ?>
</div>
<div class="explain-box"><strong>How this prediction was produced</strong><span>Each classifier was trained on the selected Kaggle CSV. Flight date, carrier, origin, destination, scheduled departure, duration and distance are used. <?= $activeMode === 'after_departure' ? 'The actual observed departure delay is ALSO used, because the flight has already departed.' : 'No actual departure information is used for this scheduled-only prediction.' ?> Their probabilities are estimates and may not be well calibrated. The highlighted model had the highest held-out F1 score for this prediction stage, not necessarily the highest accuracy.</span></div>
<?php if (!empty($livePrediction['warnings'])): ?><div class="panel model-warnings"><h3>Prediction limitations</h3><?php foreach($livePrediction['warnings'] as $warning): ?><p>• <?= $e($warning) ?></p><?php endforeach; ?></div><?php endif; ?>
</section>
<?php endif; ?>
