<?php if (!defined('FLIGHTSENSE_AUTHENTICATED')) { http_response_code(403); exit('Access denied'); }

$models = $report['models'] ?? [];
$best = validation_best_model($report);
$descriptions = [
 'decision_tree' => ['num'=>'01', 'purpose'=>'A single tree of split decisions. Easy to inspect and explain, but may overfit.', 'best'=>'Explainable baseline', 'flow'=>'Features → Splits → Classification'],
 'random_forest' => ['num'=>'02', 'purpose'=>'Combines predictions from many trees. Can capture complex interactions among flight features.', 'best'=>'Ensemble comparison', 'flow'=>'Trees × 150 → Votes → Classification'],
 'logistic_regression' => ['num'=>'03', 'purpose'=>'A linear probability baseline. Provides a simple comparison for more complex models.', 'best'=>'Probability baseline', 'flow'=>'Weighted features → Probability'],
];
?>
<div class="page-intro"><p>Measured performance for <strong>three trained classifiers</strong> using the active uploaded Kaggle 2024 CSV. The graphs and main table below use <strong>scheduled-only features</strong>. A separate after-departure evaluation appears further down if the CSV includes observed departure delays. These are <strong>not invented accuracy estimates</strong>.</p></div>
<?php if (!$report): ?>
<div class="alert danger-alert">No validation report found. Run <code>python tools/evaluate_models.py</code> after installing the Python requirements to generate the metrics.</div>
<?php else: ?>
<section class="validation-summary">
 <div class="validation-summary-stat"><small>Eligible flights</small><strong><?=number_format((int)$report['eligible_rows'])?></strong><span>From <?=number_format((int)$report['dataset_rows'])?> CSV records; <?=number_format((int)($report['sampled_rows']??$report['eligible_rows']))?> sampled</span></div>
 <div class="validation-summary-stat"><small>Training rows</small><strong><?=number_format((int)$report['train_rows'])?></strong><span>80% stratified training</span></div>
 <div class="validation-summary-stat"><small>Validation rows</small><strong><?=number_format((int)$report['validation_rows'])?></strong><span><?=number_format((int)$report['validation_positive'])?> delayed / <?=number_format((int)$report['validation_negative'])?> on time</span></div>
 <div class="validation-summary-stat"><small>Best validation F1</small><strong><?= $best ? validation_pct($best['metrics']['f1']) : 'N/A' ?></strong><span><?=htmlspecialchars($best['name']??'Not available')?></span></div>
</section>
<div class="panel"><h3>Accuracy baseline: predict every flight is on time</h3>
<p>On this test set, predicting <strong>every flight on time</strong> gets <strong><?=validation_pct($report['majority_baseline_accuracy']??null)?></strong> accuracy without detecting a single delay. Compare each algorithm with this baseline and with its <strong>delay recall</strong> and <strong>F1</strong>—accuracy alone can be misleading.</p></div>
<div class="algorithm-grid">
<?php foreach ($models as $id=>$model): $meta=$descriptions[$id]??['num'=>'--','purpose'=>'Classification model.','best'=>'Evaluation','flow'=>'Features → Prediction']; $isBest=$best && $best['name']===$model['name']; ?>
 <article class="algorithm-card <?= $isBest ? 'featured' : '' ?>">
  <?php if ($isBest): ?><div class="recommended">★ HIGHEST F1</div><?php endif; ?>
  <div class="algo-top"><span class="algo-number"><?=htmlspecialchars($meta['num'])?></span><span class="badge <?= $isBest?'success':'' ?>"><?=validation_pct($model['metrics']['accuracy'])?> accuracy</span></div>
  <h3><?=htmlspecialchars($model['name'])?></h3><p><?=htmlspecialchars($meta['purpose'])?></p>
  <div class="model-mini-metrics">
   <?php foreach (['accuracy'=>'Accuracy','precision'=>'Precision','recall'=>'Recall','f1'=>'F1 score'] as $key=>$label): $value=(float)$model['metrics'][$key]; ?>
    <div class="mini-metric-line"><span><?=htmlspecialchars($label)?></span><strong><?=validation_pct($value)?></strong><div class="mini-metric-track"><i style="width:<?=number_format(max(0,min(100,$value*100)),1,'.','')?>%"></i></div></div>
   <?php endforeach; ?>
  </div>
  <div class="algo-footer"><span>Role</span><strong><?=htmlspecialchars($meta['best'])?></strong></div><div class="algorithm-flow"><?=htmlspecialchars($meta['flow'])?></div>
 </article>
<?php endforeach; ?>
</div>
<div class="grid-2 algorithm-charts">
 <section class="panel"><div class="panel-head"><div><h3>Accuracy comparison</h3><p>Held-out validation accuracy; higher is better, but accuracy alone can hide missed delays.</p></div></div><div class="chart-wrap"><canvas id="algorithmAccuracyChart" aria-label="Validation accuracy comparison"></canvas></div></section>
 <section class="panel"><div class="panel-head"><div><h3>ROC curves by algorithm</h3><p>True-positive rate versus false-positive rate at different thresholds. AUC summarizes ranking performance.</p></div></div><div class="chart-wrap"><canvas id="algorithmRocChart" aria-label="ROC comparison"></canvas></div></section>
</div>
<div class="panel"><div class="panel-head"><div><h3>Performance graph for each algorithm</h3><p>Accuracy, precision, sensitivity/recall, specificity, F1 and balanced accuracy, plotted on a 0–100% scale.</p></div></div><div class="model-chart-grid">
 <?php foreach($models as $id=>$model): ?>
 <section class="model-chart-card"><h4><?=htmlspecialchars($model['name'])?></h4><p>Accuracy <?=validation_pct($model['metrics']['accuracy'])?> · AUC <?=validation_decimal($model['metrics']['roc_auc'])?></p><div class="chart-wrap"><canvas id="metricChart-<?=htmlspecialchars($id)?>"></canvas></div></section>
 <?php endforeach; ?>
 </div>
</div>
<div class="panel comparison"><div class="panel-head"><div><h3>Expanded validation table</h3><p>Scroll horizontally for all metrics, confusion-matrix counts, and validation sample size. All values come from the same held-out split.</p></div></div><div class="table-wrap advanced-table"><table><thead><tr>
<th>Algorithm</th><th>Accuracy</th><th>Balanced acc.</th><th>Precision (PPV)</th><th>Recall (TPR)</th><th>Specificity (TNR)</th><th>F1 score</th><th>ROC AUC</th><th>NPV</th><th>FPR</th><th>FNR</th><th>MCC</th><th>Cohen’s κ</th><th>Brier ↓</th><th>True +</th><th>False +</th><th>True −</th><th>False −</th><th>Validation n</th></tr></thead><tbody>
<?php foreach($models as $model): $m=$model['metrics'];$c=$model['confusion']; ?>
<tr><td><strong><?=htmlspecialchars($model['name'])?></strong><?= $best && $best['name']===$model['name'] ? '<small>Highest F1</small>' : '' ?></td><td><strong><?=validation_pct($m['accuracy'])?></strong></td><td><?=validation_pct($m['balanced_accuracy'])?></td><td><?=validation_pct($m['precision'])?></td><td><?=validation_pct($m['recall'])?></td><td><?=validation_pct($m['specificity'])?></td><td><?=validation_pct($m['f1'])?></td><td><?=validation_decimal($m['roc_auc'])?></td><td><?=validation_pct($m['npv'])?></td><td><?=validation_pct($m['fpr'])?></td><td><?=validation_pct($m['fnr'])?></td><td><?=validation_decimal($m['mcc'])?></td><td><?=validation_decimal($m['cohen_kappa'])?></td><td><?=validation_decimal($m['brier_score'])?></td><td><?=number_format($c['tp'])?></td><td><?=number_format($c['fp'])?></td><td><?=number_format($c['tn'])?></td><td><?=number_format($c['fn'])?></td><td><?=number_format($report['validation_rows'])?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="panel"><div class="panel-head"><div><h3>Confusion matrices</h3><p>Rows represent actual results; columns represent predictions. Delayed means arrival delay of at least 15 minutes.</p></div></div><div class="confusion-grid">
<?php foreach($models as $model): $c=$model['confusion']; ?>
<div class="confusion-card"><h4><?=htmlspecialchars($model['name'])?></h4><div class="confusion-matrix"><span class="matrix-corner">Actual ↓ / Predicted →</span><span class="matrix-axis">On time</span><span class="matrix-axis">Delayed</span><span class="matrix-axis">On time</span><div class="matrix-cell matrix-good"><strong><?=number_format($c['tn'])?></strong><small>TN · correct on time</small></div><div class="matrix-cell matrix-bad"><strong><?=number_format($c['fp'])?></strong><small>FP · false alarm</small></div><span class="matrix-axis">Delayed</span><div class="matrix-cell matrix-bad"><strong><?=number_format($c['fn'])?></strong><small>FN · missed delay</small></div><div class="matrix-cell matrix-good"><strong><?=number_format($c['tp'])?></strong><small>TP · delay detected</small></div></div></div>
<?php endforeach; ?>
</div></div>
<div class="explain-box validation-notes"><strong>Validation methodology &amp; limitations</strong><span>Active dataset: <?=htmlspecialchars($report['dataset'])?>. Run ID: <?=htmlspecialchars(substr($report['run_id']??'',0,10))?>. Target: <?=htmlspecialchars($report['target'])?>. Split: <?=htmlspecialchars($report['split'])?>. Inputs include carrier, airports, month, day of month, weekday, planned departure, scheduled duration and distance; no observed departure or arrival outcomes or delay-cause columns were used in the <strong>pre-departure</strong> models. The separate post-departure models use only real observed departure delay, never arrival outcome. Results reflect the <strong><?=number_format((int)($report['sampled_rows']??$report['eligible_rows']))?> sampled eligible flights</strong> from this CSV; full CSV size is <?=number_format((int)($report['dataset_rows']??0))?> records. Models now use an unweighted 0.50 decision threshold instead of the previous class-weighted setting. This improves measured overall accuracy in some datasets but can reduce sensitivity to delays. Always compare with the majority-class baseline and check recall. <strong>The Predict Flight page calls these same three saved models for new flights.</strong> To use new data, upload a CSV in <a href="index.php?page=training">Dataset &amp; Training</a> or train from the Python command line, then refresh this page.</span></div>
<?php $post = $report['mode_reports']['after_departure'] ?? null; ?>
<?php if ($post): ?>
<section class="panel"><div class="panel-head"><div><h3>After-departure validation — actual departure delay is known</h3><p>This mode is only valid <strong>after a flight has actually departed</strong>. It uses the real departure delay as an additional predictor. It is not an improvement you can claim for future flights before departure.</p></div></div>
<div class="training-overview">
 <div><small>Test flights</small><h3><?=number_format($post['validation_rows'])?></h3></div>
 <div><small>Always-on-time baseline</small><h3><?=validation_pct($post['majority_baseline_accuracy'])?></h3></div>
 <div><small>Highest after-departure F1</small><h3><?=validation_pct($post['models'][$post['best_model']]['metrics']['f1'])?></h3></div>
</div>
<div class="table-wrap advanced-table"><table><thead><tr><th>Algorithm</th><th>Accuracy</th><th>Precision</th><th>Recall</th><th>F1</th><th>ROC AUC</th><th>Balanced accuracy</th><th>True +</th><th>False +</th><th>True −</th><th>False −</th></tr></thead><tbody>
<?php foreach ($post['models'] as $model): $m=$model['metrics'];$c=$model['confusion']; ?>
<tr><td><strong><?=htmlspecialchars($model['name'])?></strong></td><td><?=validation_pct($m['accuracy'])?></td><td><?=validation_pct($m['precision'])?></td><td><?=validation_pct($m['recall'])?></td><td><?=validation_pct($m['f1'])?></td><td><?=validation_decimal($m['roc_auc'])?></td><td><?=validation_pct($m['balanced_accuracy'])?></td><td><?=number_format($c['tp'])?></td><td><?=number_format($c['fp'])?></td><td><?=number_format($c['tn'])?></td><td><?=number_format($c['fn'])?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<div class="grid-2 algorithm-charts">
<section class="panel"><h3>After-departure accuracy comparison</h3><p>Evaluated on held-out flights for which the departure delay was recorded.</p><div class="chart-wrap"><canvas id="postAccuracyChart"></canvas></div></section>
<section class="panel"><h3>After-departure ROC curves</h3><p>Predictive discrimination after actual departure delay becomes known.</p><div class="chart-wrap"><canvas id="postRocChart"></canvas></div></section>
</div>
<?php endif; ?>
<script>window.FLIGHT_VALIDATION = <?=json_encode($report, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>;</script>
<?php endif; ?>
