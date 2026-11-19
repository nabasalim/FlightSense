/* Full CSV browser uploader: 1 MiB sequential chunks bypass PHP's standard upload size limit. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-csv-uploader]');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const input = $('fullCsvFile'), picker = $('csvPicker'), status = $('csvUploadStatus');
    const bar = $('csvProgressBar'), track = $('csvProgressTrack'), percent = $('csvPercent');
    const details = $('csvProgressDetails'), stage = $('csvStage'), result = $('csvUploadResult');
    const cancelButton = $('csvCancel');
    const endpoint = root.dataset.endpoint, csrf = root.dataset.csrf;
    const CHUNK_SIZE = 1 * 1024 * 1024, MAX_SIZE = 8 * 1024 * 1024 * 1024;
    let cancelled = false, busy = false, canCancel = false, uploadId = null;
    const formatSize = b => `${(b / (1024 * 1024)).toLocaleString(undefined, {maximumFractionDigits: 1})} MB`;
    function showResult(message, success = false, html = '') {
      result.hidden = false;
      result.className = `csv-upload-result ${success ? 'upload-success' : 'upload-error'}`;
      result.replaceChildren();
      const p = document.createElement('p'); p.textContent = message; result.append(p);
      if (html) {
        // Only developer-authored HTML; server-provided filename and metrics are added as text separately.
        const link = document.createElement('a'); link.href = 'index.php?page=algorithms';
        link.textContent = 'View accuracy, validation and graphs'; result.append(link);
      }
    }
    function setProgress(n) {
      const pct = Math.max(0, Math.min(100, Math.floor(n)));
      bar.style.width = `${pct}%`; percent.textContent = `${pct}%`; track.setAttribute('aria-valuenow', String(pct));
    }
    function working(yes) {
      busy = yes; input.disabled = yes; picker.classList.toggle('is-disabled', yes);
      cancelButton.hidden = !yes || !canCancel;
    }
    async function request(action, data = {}, chunk = null) {
      const body = new FormData(); body.append('csrf', csrf); body.append('action', action);
      for (const [key, value] of Object.entries(data)) body.append(key, String(value));
      if (chunk) body.append('chunk', chunk, 'part.csv');
      const response = await fetch(endpoint, {method: 'POST', body, credentials: 'same-origin'});
      let json;
      try { json = await response.json(); } catch (_) { throw new Error('Server returned an invalid response. Check Apache/PHP settings and log in again.'); }
      if (!response.ok || !json.ok) throw new Error(json.error || `Server returned HTTP ${response.status}.`);
      return json;
    }
    async function upload(file) {
      if (busy || !file) return;
      if (!/\.csv$/i.test(file.name)) return showResult('Select a .csv file (not a .zip file).');
      if (file.size < 100 || file.size > MAX_SIZE) return showResult('CSV must be between 100 bytes and 8 GB.');
      cancelled = false; canCancel = true; uploadId = null; cancelButton.disabled = false;
      result.hidden = true; status.hidden = false; setProgress(0);
      stage.textContent = 'Preparing upload...'; details.textContent = `${file.name} · ${formatSize(file.size)}`;
      working(true);
      try {
        const started = await request('start', {filename: file.name, size: file.size});
        uploadId = started.upload_id;
        let received = 0;
        while (received < file.size) {
          if (cancelled) throw new Error('Upload cancelled.');
          const part = file.slice(received, Math.min(received + CHUNK_SIZE, file.size));
          const response = await request('chunk', {upload_id: uploadId, offset: received}, part);
          received = Number(response.received);
          if (!Number.isFinite(received) || received < 1 || received > file.size) throw new Error('Server returned an invalid upload offset.');
          const pct = received / file.size * 100;
          setProgress(pct); stage.textContent = 'Uploading full CSV...';
          details.textContent = `${file.name} · ${formatSize(received)} of ${formatSize(file.size)} transferred`;
        }
        if (cancelled) throw new Error('Upload cancelled.');
        // A full CSV can take time to scan; do not display pretend training percentage.
        canCancel = false; working(true);
        stage.textContent = 'Upload complete — training six stage-specific models...';
        details.textContent = 'Reading the full dataset, checking records and calculating new accuracy. Keep this page open.';
        percent.textContent = 'Uploaded'; track.removeAttribute('aria-valuenow');
        const finished = await request('finish', {upload_id: uploadId});
        uploadId = null;
        stage.textContent = 'Training complete';
        details.textContent = `${Number(finished.eligible_rows).toLocaleString()} eligible flights scanned; ${Number(finished.sampled_rows).toLocaleString()} used for training/validation.`;
        showResult(finished.message, true, 'link');
        if (Array.isArray(finished.accuracy)) {
          const ul = document.createElement('ul'); ul.className = 'csv-accuracy-list';
          finished.accuracy.forEach(model => {
            const li = document.createElement('li');
            const name = document.createElement('span'); name.textContent = String(model.name);
            const acc = document.createElement('strong');
            acc.textContent = typeof model.accuracy === 'number' ? `${(model.accuracy * 100).toFixed(1)}% accuracy` : 'N/A';
            li.append(name, acc); ul.append(li);
          });
          const heading=document.createElement('h4'); heading.textContent='Before departure (scheduled inputs)';
          result.append(heading, ul);
        }
        if (Array.isArray(finished.after_accuracy) && finished.after_accuracy.length) {
          const heading=document.createElement('h4'); heading.textContent='After departure (actual departure delay required)';
          const ul=document.createElement('ul'); ul.className='csv-accuracy-list';
          finished.after_accuracy.forEach(model=>{
            const li=document.createElement('li'); const name=document.createElement('span');
            name.textContent=String(model.name); const acc=document.createElement('strong');
            acc.textContent=typeof model.accuracy==='number'?`${(model.accuracy*100).toFixed(1)}% accuracy`:'N/A';
            li.append(name,acc);ul.append(li);
          });
          result.append(heading,ul);
        }
      } catch (err) {
        if (uploadId) {
          try { await request('cancel', {upload_id: uploadId}); } catch (_) { /* Expired uploads can be cleaned up server-side. */ }
          uploadId = null;
        }
        stage.textContent = 'Upload not completed';
        showResult(err instanceof Error ? err.message : 'Upload failed.');
      } finally {
        canCancel = false; working(false);
        input.value = '';
      }
    }
    input.addEventListener('change', () => upload(input.files && input.files[0]));
    for (const type of ['dragenter', 'dragover']) picker.addEventListener(type, event => {
      event.preventDefault(); if (!busy) picker.classList.add('is-dragging');
    });
    for (const type of ['dragleave', 'drop']) picker.addEventListener(type, event => {
      event.preventDefault(); picker.classList.remove('is-dragging');
    });
    picker.addEventListener('drop', event => { if (!busy && event.dataTransfer?.files?.length) upload(event.dataTransfer.files[0]); });
    cancelButton.addEventListener('click', () => { if (canCancel) { cancelled = true; cancelButton.disabled = true; stage.textContent = 'Cancelling after the current chunk...'; } });
  });
})();
