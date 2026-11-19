<?php if (!defined('FLIGHTSENSE_AUTHENTICATED')) { http_response_code(403); exit('Access denied'); } ?>
<div class="page-intro"><p>Upload the complete <strong>Flight Data 2024</strong> CSV from Kaggle in one step. Select your file below and FlightSense automatically uploads it in small pieces, then retrains all three flight delay algorithms.</p></div>
<div class="upload-hero panel" data-csv-uploader data-csrf="<?= htmlspecialchars(auth_csrf(), ENT_QUOTES, 'UTF-8') ?>" data-endpoint="api/dataset_upload.php">
  <div class="upload-heading"><span class="upload-symbol" aria-hidden="true">↥</span><div><h3>Upload full CSV dataset</h3><p>No command-line steps or server upload-size configuration required. Choose one .csv file.</p></div></div>
  <label class="csv-picker" for="fullCsvFile" id="csvPicker">
    <span class="picker-icon" aria-hidden="true">⇧</span>
    <strong>Choose CSV file</strong>
    <span id="csvPickerHint">Click to browse your computer or drag and drop a CSV here</span>
    <input type="file" id="fullCsvFile" name="csv" accept=".csv,text/csv" aria-describedby="csvInstructions">
  </label>
  <p class="field-hint" id="csvInstructions">The upload begins automatically after you select a file. Supports uncompressed CSV files up to 8 GB, provided your server has enough available storage. Please keep this tab open until training finishes.</p>
  <div class="csv-upload-status" id="csvUploadStatus" role="status" aria-live="polite" hidden>
    <div class="csv-progress-top"><strong id="csvStage">Preparing upload...</strong><span id="csvPercent">0%</span></div>
    <div class="csv-progress-track" role="progressbar" aria-label="CSV upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="csvProgressTrack"><div id="csvProgressBar"></div></div>
    <p id="csvProgressDetails">Your selected file will appear here.</p>
    <button type="button" class="btn csv-cancel-btn" id="csvCancel">Cancel upload</button>
  </div>
  <div id="csvUploadResult" class="csv-upload-result" role="status" aria-live="polite" hidden></div>
</div>
<div class="training-overview">
  <div class="panel"><small>Current dataset</small><h3><?= htmlspecialchars((string)($report['dataset'] ?? 'No trained data'), ENT_QUOTES, 'UTF-8') ?></h3><p>The active model remains unchanged if upload or training fails.</p></div>
  <div class="panel"><small>Rows scanned in previous training</small><h3><?= number_format((int)($report['dataset_rows'] ?? 0)) ?></h3><p><?= number_format((int)($report['eligible_rows'] ?? 0)) ?> eligible flight records</p></div>
  <div class="panel"><small>Rows used for model training and validation</small><h3><?= number_format((int)($report['sampled_rows'] ?? 0)) ?></h3><p>Up to 50,000 randomly sampled eligible flights (80% train / 20% test)</p></div>
</div>
<section class="panel"><h3>What happens when you upload?</h3><p>FlightSense saves the <strong>entire CSV</strong> in protected storage, reads all its records in streaming batches, and trains the Decision Tree, Random Forest and Logistic Regression models for both stages on a uniformly sampled set of up to <strong>50,000 eligible flights</strong>. Accuracy and graphs are recalculated using separate held-out test sets for each prediction mode. The uploaded CSV is <strong>not</strong> imported into MySQL, so the original database and its columns remain unchanged.</p><p class="field-hint">Need the source file? <a target="_blank" rel="noopener noreferrer" href="https://www.kaggle.com/datasets/hrishitpatil/flight-data-2024?resource=download">Open the Flight Data 2024 Kaggle dataset</a> and extract its .csv file before selecting it. Uploading a .zip directly is not supported.</p></section>
<script src="assets/js/csv-upload.js?v=1" defer></script>
