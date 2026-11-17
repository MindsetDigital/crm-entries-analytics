(() => {
  'use strict';

  const form = document.getElementById('cea-csv-form');
  const scope = document.getElementById('cea-csv-scope');
  const separator = document.getElementById('cea-csv-separator');
  const customWrap = document.getElementById('cea-csv-custom-wrap');
  const custom = document.getElementById('cea-csv-custom');
  if (!form || !separator) return;

  const sync = () => {
    scope.classList.toggle('cea-hidden', form.value !== '');
    const isCustom = separator.value === 'custom';
    customWrap.classList.toggle('cea-hidden', !isCustom);
    custom.required = isCustom;
  };

  form.addEventListener('change', sync);
  separator.addEventListener('change', () => {
    sync();
    if (separator.value === 'custom') custom.focus();
  });
  sync();
})();
