(() => {
  'use strict';

  const config = window.CEA_MIGRATION_CONFIG || {};
  const form = document.getElementById('cea-import-upload');
  const file = document.getElementById('cea-import-file');
  const preview = document.getElementById('cea-import-preview');
  const errorBox = document.getElementById('cea-import-error');
  if (!form || !file || !preview || !config.restUrl) return;

  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const number = new Intl.NumberFormat(document.documentElement.lang || 'es-ES');
  const showError = message => {
    errorBox.classList.toggle('cea-hidden', !message);
    errorBox.querySelector('p').textContent = message || '';
  };
  const request = async (path, options = {}) => {
    const response = await fetch(new URL(path, config.restUrl), {
      ...options,
      headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || 'No se ha podido completar la operación.');
    return payload;
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!file.files[0]) return;
    showError('');
    preview.classList.remove('cea-hidden');
    preview.innerHTML = '<div class="cea-loading"><span class="spinner is-active"></span>Validando el paquete…</div>';
    const data = new FormData();
    data.append('package', file.files[0]);
    try {
      renderPreview(await request('preview', { method: 'POST', body: data }));
    } catch (error) {
      preview.classList.add('cea-hidden');
      showError(error.message);
    }
  });

  const renderPreview = data => {
    const source = data.manifest.source || {};
    const targets = data.targets || [];
    const targetOptions = (sourceId) => [
      `<option value="">Conservar ${escapeHtml(sourceId)}</option>`,
      ...targets.map(target => `<option value="${escapeHtml(target.id)}">${escapeHtml(target.label)} (${escapeHtml(target.id)})</option>`),
    ].join('');
    const mappings = (data.manifest.forms || []).map(sourceForm => `
      <div class="cea-form-map-row" data-source-form="${escapeHtml(sourceForm.id)}">
        <div><strong>${escapeHtml(sourceForm.label)}</strong><small>${escapeHtml(sourceForm.id)}</small></div>
        <span aria-hidden="true">→</span>
        <select aria-label="Formulario de destino para ${escapeHtml(sourceForm.label)}">${targetOptions(sourceForm.id)}</select>
      </div>`).join('');

    preview.innerHTML = `
      <div class="cea-preview-head"><h3>Paquete validado</h3><span>${number.format(data.manifest.entryCount)} registros</span></div>
      <div class="cea-preview-grid">
        <div><span>Nuevos estimados</span><strong>${number.format(data.summary.new)}</strong></div>
        <div><span>Coincidencias estimadas</span><strong>${number.format(data.summary.existing)}</strong></div>
        <div><span>Adjuntos incluidos</span><strong>${number.format(data.manifest.fileCount)}</strong></div>
        <div><span>Registros inválidos</span><strong>${number.format(data.summary.invalid)}</strong></div>
      </div>
      <p class="cea-package-meta">Origen: <strong>${escapeHtml(source.siteUrl || 'No indicado')}</strong> · CEA ${escapeHtml(source.ceaVersion || '—')} · CRM Entries ${escapeHtml(source.crmVersion || '—')}</p>
      ${mappings ? `<div class="cea-mapping"><h3>Asociación de formularios</h3><p>Conserva el identificador original o asígnalo a un formulario existente.</p>${mappings}</div>` : ''}
      <fieldset class="cea-strategies"><legend>Estrategia de importación</legend>
        <label><input type="radio" name="cea-strategy" value="upsert" checked><span><strong>Actualizar y crear</strong><small>Actualiza coincidencias y crea los registros nuevos.</small></span></label>
        <label><input type="radio" name="cea-strategy" value="create_only"><span><strong>Crear solamente nuevos</strong><small>Omite coincidencias sin modificarlas.</small></span></label>
        <label class="cea-danger-option"><input type="radio" name="cea-strategy" value="replace"><span><strong>Sustituir datos actuales</strong><small>Crea una copia automática, borra el alcance elegido e importa el paquete.</small></span></label>
      </fieldset>
      <div id="cea-replace-options" class="cea-replace-options cea-hidden">
        <label><input type="radio" name="cea-replace-scope" value="all" checked> Sustituir todos los registros de CRM Entries</label>
        <label><input type="radio" name="cea-replace-scope" value="forms"> Sustituir solo los formularios incluidos en el paquete</label>
        <label class="cea-confirm"><input type="checkbox" id="cea-replace-confirm"> Confirmo que se borrarán los registros actuales del alcance seleccionado.</label>
      </div>
      <div id="cea-import-progress" class="cea-hidden"></div>
      <p><button type="button" class="button button-primary" id="cea-run-import">Iniciar importación</button></p>`;

    const replaceOptions = document.getElementById('cea-replace-options');
    preview.querySelectorAll('[name="cea-strategy"]').forEach(input => input.addEventListener('change', () => {
      replaceOptions.classList.toggle('cea-hidden', input.value !== 'replace' || !input.checked);
    }));
    document.getElementById('cea-run-import').addEventListener('click', () => runImport(data.token));
  };

  const runImport = async token => {
    const strategy = preview.querySelector('[name="cea-strategy"]:checked').value;
    if (strategy === 'replace' && !document.getElementById('cea-replace-confirm').checked) {
      showError('Confirma expresamente el borrado antes de sustituir los datos.');
      return;
    }
    const formMapping = {};
    preview.querySelectorAll('[data-source-form]').forEach(row => { formMapping[row.dataset.sourceForm] = row.querySelector('select').value; });
    const replaceScope = preview.querySelector('[name="cea-replace-scope"]:checked')?.value || 'all';
    const button = document.getElementById('cea-run-import');
    const progress = document.getElementById('cea-import-progress');
    button.disabled = true;
    showError('');
    progress.classList.remove('cea-hidden');

    try {
      let result;
      do {
        result = await request('import', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, strategy, formMapping, replaceScope }),
        });
        const percent = result.total ? Math.min(100, Math.round((result.processed / result.total) * 100)) : 100;
        progress.innerHTML = `<div class="cea-progress"><i style="width:${percent}%"></i></div><p>${percent}% · ${number.format(result.processed)} de ${number.format(result.total)}</p>`;
      } while (!result.done);
      progress.innerHTML = `<div class="notice notice-success inline"><p><strong>Importación completada.</strong> Creados: ${number.format(result.created)} · Actualizados: ${number.format(result.updated)} · Omitidos: ${number.format(result.skipped)} · Fallidos: ${number.format(result.failed)}${result.backup ? ` · Copia automática: ${escapeHtml(result.backup)}` : ''}</p></div>`;
      button.remove();
    } catch (error) {
      button.disabled = false;
      showError(error.message);
    }
  };
})();
