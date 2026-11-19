# FlightSense — Kaggle-trained flight delay prediction

FlightSense is a PHP 8+/MySQL website for XAMPP. The application now has a login, live scikit-learn predictions from three algorithms in two prediction stages, a Kaggle CSV dataset upload/retraining page, model comparison graphs, and expanded validation reports.

## Database compatibility (IMPORTANT)

**The database name is still `flight_delay_db`.** No SQL migrations or column changes are required from the original uploaded FlightSense project. The original `database/schema.sql` is unchanged, including all three original tables: `flights`, `predictions`, and `flight_data_2024`.

The uploaded training CSV, trained model artifact, accuracy report, and administrator login account are stored in protected application files, **not** in new MySQL tables:

- `storage/models.joblib` — trained scikit-learn models (one pipeline per algorithm).
- `data/validation_results.json` — current model accuracy, all validation metrics and graph data.
- `storage/accounts.php` — first-admin account with a one-way PHP password hash (created on first run).
- `storage/datasets/` — most recent successfully uploaded CSV (if trained using the web form).

## Install in XAMPP

1. Extract the `flight-delay-dashboard` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP. **If you already imported `flight_delay_db` for the original system, keep it as-is.** On a fresh install, import `database/schema.sql` using phpMyAdmin.
3. Change MySQL credentials in `config/database.php` only if your own MySQL installation requires them.
4. Install Python 3.10+ and, from the project directory, run:

   ```bat
   python -m pip install -r tools\requirements.txt
   ```

   On Windows, ensure `python` is in the web-server PATH, or set environment variable `FLIGHTSENSE_PYTHON` to the full path to `python.exe` and restart Apache. PHP's `proc_open` must be enabled for website-based predictions and retraining.
5. Visit `http://localhost/flight-delay-dashboard/` and create the first administrator account (12+ character password). **No default password is provided.** Subsequent visits require logging in.
6. The installation comes with models already trained and evaluated for both stages on the **bundled 10,000-row Kaggle sample**. You can predict immediately, subject to Python dependencies being installed. The validation report and saved models originate from the same training run.

The `storage/` directory must be writable by the web server. The project's `.htaccess` files deny web access to internal code, database scripts, upload files, model files, and reports under the Apache server. Do not deploy on the public Internet without HTTPS and additional hardening.

## Dataset upload, retraining and accuracy

The source is [Hrishit Patil — Flight Data 2024 on Kaggle](https://www.kaggle.com/datasets/hrishitpatil/flight-data-2024). Download and extract the CSV.

**Website (full CSV, no command line):** log in → **Upload CSV** → click **Choose CSV file** → select the extracted `flight_data_2024.csv`. Uploading begins **automatically**. A progress bar shows transferred bytes, then indicates when the three algorithms are being trained for both prediction stages. Please keep the browser tab open until training finishes. The entire selected file is uploaded in **1 MiB chunks** (so you do not need to increase PHP's usual `upload_max_filesize` or `post_max_size` limits). The page accepts files up to **8 GB**, subject to server disk space and browser/network reliability. The `.zip` download must be extracted first; only uncompressed `.csv` files are accepted. If uploading or training fails, the previously trained models remain active. A successful run updates predictions, accuracy, validation and graphs.

**Full Kaggle dataset (millions of flights):** the *entire CSV* is uploaded and read in streaming batches, but **a uniformly selected maximum of 50,000 eligible flight records is used to train and evaluate**. This avoids memory issues with large files. It does **not** use every eligible row for fitting, and does **not** insert the CSV records into your MySQL tables. To train with a larger random sample, you can optionally run the Python command line (not required for uploading):

```bat
cd C:\xampp\htdocs\flight-delay-dashboard
python tools\model_pipeline.py train --dataset "C:\path\to\flight_data_2024.csv" --max-rows 150000
```

This scans the CSV in chunks, builds a **random sample of up to 50,000 eligible flights**, trains each stage-specific model on 80% and validates it on the remaining untouched 20%. It **does not train on all millions of flights**. Increase `--max-rows` up to 150000 if your computer can support it. The actual eligible, scanned, sampled, and held-out row counts are shown on the Upload CSV / Dataset & Training pages. The original `python tools\evaluate_models.py` command still works and now also saves usable trained models.

Training uses the following **scheduled / predeparture features**: flight month, day of month, weekday, airline code, origin, destination, scheduled departure clock time, scheduled flight duration, and distance, plus engineered route, departure period, scheduled arrival period, and weekend indicators. The **after-departure stage** additionally uses `dep_delay`, the actual observed departure delay, available only after the plane departs. No arrival outcomes are used as model inputs. If the CSV lacks actual departure delay values, only the before-departure models are trained. It excludes cancelled/diverted flights and flights without a known arrival delay. A delay means arrival delay **15 minutes or more**. Flight outcome variables, weather delay, and causes reported after arrival are **not** input features, avoiding direct outcome leakage.

The **ML Algorithms** page shows both prediction stages separately and clearly displays the no-skill majority-class accuracy baseline. It reports 19 validation-table columns (including accuracy, balanced accuracy, precision, recall, specificity, F1, ROC AUC, NPV, FPR, FNR, MCC, Cohen's kappa, Brier score, confusion counts, and validation count). It has accuracy comparison, ROC curves, separate metric charts for each algorithm, and confusion matrices. These **19 display columns are not database columns**.

## Predict a flight

Log in → **Predict Flight**. Enter airline code, origin airport, destination airport, flight date, scheduled departure time, expected duration in minutes, and distance in miles. Flight number is an optional display label.

Select **Predict with all three algorithms**. Select **Before departure** for scheduled-only predictions, or **After departure** when you know the actual departure delay in minutes. Each trained pipeline runs against the fields available at the selected stage and returns its own estimated probability of arrival delay >=15 minutes, plus an on-time/delayed classification at the 50% threshold. The model with the **highest held-out F1 score for that prediction stage** is highlighted as the primary result. The displayed accuracy for each algorithm is its *separate validation-set score*, not the confidence of the entered flight.

If you previously imported flight records to MySQL's original `flight_data_2024` table, you can also use the **historical flight-number lookup** to prefill scheduled fields. Uploading/retraining a CSV **does not import CSV records into MySQL**, so lookup requires the optional MySQL import. The training CSV itself is sufficient for ML prediction when the user manually enters the scheduled flight details.

```bat
C:\xampp\php\php.exe database\import_kaggle.php data\flight_data_2024_sample.csv
```

The underlying models are trained on historical 2024 US flights. Their probability estimates are **not calibrated** or guarantees of an individual flight's future outcome; unknown airline/airport codes and changed future operating conditions can reduce reliability. The system does **not** query live airline schedules or current weather.

## Compatibility and account notes

- Existing `flights` and `predictions` SQL records remain intact; these tables are not migrated or cleared.
- The separate **Flight Records** page remains available for adding and viewing MySQL flight records. This list is independent of the CSV-trained model accuracy and flight prediction form.
- On a local, disconnected development installation only, a lost administrator account can be reset by deleting `storage/accounts.php` while the site is offline. Protect the site from public access before re-enabling first-time setup.
- Application backup should include `storage/models.joblib`, `data/validation_results.json`, and `storage/accounts.php` (if you want to preserve accounts); otherwise the included models or new first-time login setup will be used.

## Full CSV uploader troubleshooting

- Use the **Upload CSV** sidebar item. Simply select the `.csv` file; no separate Upload button is needed.
- Keep the browser open during upload and training. Large files are uploaded piece by piece, and then scanned; the training stage has no fabricated percentage because runtime varies.
- Ensure the drive containing `storage/datasets/` has enough free space. The app checks this before accepting the file.
- Ensure XAMPP Apache can write to `storage/`, `storage/datasets/`, and `data/validation_results.json`.
- Python 3 and the packages listed in `tools/requirements.txt` must be installed; `proc_open` must be available.
- If the connection is interrupted, select the file again to retry. A normal cancel removes the partially uploaded file. Abandoned partial files may need manual removal from `storage/datasets/` after confirming no upload is active.
- The first-time admin login and **database `flight_delay_db`** work exactly as before. No database changes are needed.


## Improved training and interpreting accuracy

The original version trained all three classifiers with `class_weight='balanced'`, which produced numerous false alarms and depressed overall accuracy. The revised version uses unweighted, regularized models, engineered schedule/route features, and keeps the test set untouched. This can raise reported accuracy but **may greatly reduce recall of actual delays**. The algorithms are evaluated at a fixed 50% probability cutoff (no test-set threshold tuning). Always compare model accuracy, precision, recall, F1, and ROC AUC with the baseline of predicting that every flight is on time.

There are **two genuinely different prediction tasks**:

1. **Before departure:** only known scheduled fields are used. Results on the included 2024 sample are about 78% accuracy, close to the ~78.5% always-on-time baseline; delay recall is low. This is *not* evidence of reliable delay detection. Uploading more flights may change measured performance but does not guarantee high forecast accuracy.
2. **After departure:** the actual observed departure-delay minutes are used as an extra predictor. Results on the same bundled historical sample are around 92% accuracy; this information is not available before departure. Never use a guessed `dep_delay` to artificially raise reported accuracy.

Neither mode has a live weather or real-time airport status feed. The existing MySQL database remains unchanged (`flight_delay_db`); the two model groups and reports are stored on disk under `storage/` and `data/` respectively. Before deploying on new environments, retrain using your complete CSV through the Upload CSV page.

**Environment compatibility:** The bundled scikit-learn model artifact was created using a modern scikit-learn version. If your installed Python packages differ and model loading fails, upload the Kaggle CSV and retrain locally to rebuild the model files for your installation.
