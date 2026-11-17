(() => {
  'use strict';

  const config = window.CEA_CONFIG || {};
  const state = {
    defaults: null,
    page: 1,
    perPage: 25,
    totalPages: 1,
    search: '',
    requestToken: 0,
    dashboard: null,
    dashboardFilters: null,
    chart: null,
    chartResizeObserver: null,
  };

  const el = (id) => document.getElementById(id);
  const els = {
    datePreset: el('cea-date-preset'), start: el('cea-start'), end: el('cea-end'), form: el('cea-form'), status: el('cea-status'),
    apply: el('cea-apply'), reset: el('cea-reset'), exportPdf: el('cea-export-pdf'), exportCsv: el('cea-export-csv'), loading: el('cea-loading'), error: el('cea-error'),
    total: el('cea-total'), read: el('cea-read'), unread: el('cea-unread'), readPercent: el('cea-read-percent'), unreadPercent: el('cea-unread-percent'),
    line: el('cea-line-chart'), lineCaption: el('cea-timeline-caption'), legend: el('cea-series-legend'), trend: el('cea-trend-toggle'),
    formCards: el('cea-form-cards'), comparisonCaption: el('cea-comparison-caption'), heatmap: el('cea-heatmap'), timezone: el('cea-timezone'),
    search: el('cea-search'), searchButton: el('cea-search-button'), body: el('cea-leads-body'), tableCount: el('cea-table-count'),
    prev: el('cea-prev'), next: el('cea-next'), pageInfo: el('cea-page-info'), perPage: el('cea-per-page'),
    modal: el('cea-modal'), modalContent: el('cea-modal-content'),
  };

  if (!els.start || !config.restUrl) return;

  const requestedLocale = String(config.locale || document.documentElement.lang || 'es-ES').replace(/_/g, '-');
  let locale = 'es-ES';
  try {
    locale = new Intl.NumberFormat(requestedLocale).resolvedOptions().locale;
  } catch (error) {
    // Keep a valid fallback so an unexpected WordPress locale cannot stop the dashboard.
  }

  const api = async (path, params = {}) => {
    const url = new URL(path, config.restUrl);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, value);
    });
    const response = await fetch(url.toString(), { headers: { 'X-WP-Nonce': config.nonce } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || 'No se han podido cargar los datos.');
    return payload;
  };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  const number = new Intl.NumberFormat(locale);
  const decimal = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
  const percent = (value, total) => total ? `${number.format((value / total) * 100)} % del total` : '0 % del total';
  const echartsApi = window.echarts || null;
  const seriesColors = ['#1063ff', '#ef4444', '#16a34a', '#f0b138', '#8b5cf6', '#06b6d4', '#ec4899', '#64748b', '#f97316', '#14b8a6', '#a855f7', '#84cc16'];
  const seriesColor = (index) => seriesColors[index] || `hsl(${Math.round(index * 137.508) % 360} 58% 42%)`;
  const formatDate = (iso) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  };
  const formatPeriodDate = (value) => {
    if (!value) return '—';
    const date = new Date(`${value}T12:00:00`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(date);
  };
  const shiftInputDate = (value, days) => {
    const parts = String(value).split('-').map(Number);
    if (parts.length !== 3 || parts.some(part => !Number.isFinite(part))) return value;
    const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };

  const applyDatePreset = (value) => {
    const days = Number(value);
    if (!Number.isFinite(days) || days < 1 || !state.defaults?.end) return false;
    els.end.value = state.defaults.end;
    els.start.value = shiftInputDate(state.defaults.end, -(days - 1));
    return true;
  };

  const showLoading = (show) => els.loading.classList.toggle('cea-hidden', !show);
  const showError = (message = '') => {
    els.error.classList.toggle('cea-hidden', !message);
    const p = els.error.querySelector('p');
    if (p) p.textContent = message;
  };

  const currentFilters = (includeSearch = false) => ({
    start: els.start.value,
    end: els.end.value,
    form: els.form.value,
    read_status: els.status.value,
    ...(includeSearch ? { search: state.search } : {}),
  });

  const downloadReport = () => {
    if (!config.reportUrl || !config.reportNonce) return;
    const originalLabel = els.exportPdf.textContent;
    els.exportPdf.disabled = true;
    els.exportPdf.textContent = 'Preparando informe…';

    const params = {
      action: 'cea_export_pdf',
      _wpnonce: config.reportNonce,
      ...currentFilters(false),
      trend: els.trend.checked ? '1' : '0',
      chart_image: createReportChartImage(),
    };

    const form = document.createElement('form');
    form.method = 'post';
    form.action = config.reportUrl;
    form.target = '_blank';
    form.style.display = 'none';

    Object.entries(params).forEach(([key, value]) => {
      const field = 'chart_image' === key ? document.createElement('textarea') : document.createElement('input');
      field.name = key;
      field.value = value ?? '';
      form.appendChild(field);
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
    window.setTimeout(() => {
      els.exportPdf.disabled = false;
      els.exportPdf.textContent = originalLabel;
    }, 500);
  };

  const openCsvExport = () => {
    if (!config.exportUrl) return;
    const url = new URL(config.exportUrl, window.location.href);
    Object.entries(currentFilters(true)).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, value);
    });
    window.location.href = url.toString();
  };

  const init = async () => {
    try {
      showLoading(true);
      const data = await api('filters');
      state.defaults = data.defaults;
      els.start.value = data.defaults.start;
      els.end.value = data.defaults.end;
      els.datePreset.value = '30';
      els.timezone.textContent = data.timezone ? `Zona horaria: ${data.timezone}.` : '';
      data.forms.forEach((form) => {
        const option = document.createElement('option');
        option.value = form.id;
        option.textContent = form.label;
        els.form.appendChild(option);
      });
      await loadAll();
    } catch (error) {
      showError(error.message);
    } finally {
      showLoading(false);
    }
  };

  const loadAll = async () => {
    const token = ++state.requestToken;
    const dashboardFilters = currentFilters(false);
    showLoading(true);
    showError('');
    try {
      const [dashboard, leads] = await Promise.all([
        api('dashboard', dashboardFilters),
        api('leads', { ...dashboardFilters, search: state.search, page: state.page, per_page: state.perPage }),
      ]);
      if (token !== state.requestToken) return;
      state.dashboardFilters = dashboardFilters;
      renderDashboard(dashboard);
      renderLeads(leads);
    } catch (error) {
      if (token === state.requestToken) showError(error.message);
    } finally {
      if (token === state.requestToken) showLoading(false);
    }
  };

  const loadLeads = async () => {
    const token = ++state.requestToken;
    showLoading(true);
    showError('');
    try {
      const leads = await api('leads', { ...currentFilters(true), page: state.page, per_page: state.perPage });
      if (token !== state.requestToken) return;
      renderLeads(leads);
    } catch (error) {
      if (token === state.requestToken) showError(error.message);
    } finally {
      if (token === state.requestToken) showLoading(false);
    }
  };

  const renderDashboard = (data) => {
    state.dashboard = data;
    const { stats } = data;
    els.total.textContent = number.format(stats.total);
    els.read.textContent = number.format(stats.read);
    els.unread.textContent = number.format(stats.unread);
    els.readPercent.textContent = percent(stats.read, stats.total);
    els.unreadPercent.textContent = percent(stats.unread, stats.total);
    const intervalLabels = { day: 'Agrupación diaria', week: 'Agrupación semanal', month: 'Agrupación mensual' };
    els.lineCaption.textContent = intervalLabels[data.interval] || '';
    renderLineChart(data.timeline, data.series, els.trend.checked);
    renderFormCards(data.formCards, data.previousPeriod);
    renderHeatmap(data.heatmap);
  };

  const linearTrend = (values) => {
    const length = values.length;
    if (length < 2) return values.map((value) => Number(value) || 0);
    const sumX = ((length - 1) * length) / 2;
    const sumY = values.reduce((sum, value) => sum + (Number(value) || 0), 0);
    const sumXY = values.reduce((sum, value, index) => sum + index * (Number(value) || 0), 0);
    const sumXX = values.reduce((sum, value, index) => sum + index * index, 0);
    const denominator = length * sumXX - sumX * sumX;
    const slope = denominator ? (length * sumXY - sumX * sumY) / denominator : 0;
    const intercept = (sumY - slope * sumX) / length;
    return values.map((value, index) => Math.max(0, intercept + slope * index));
  };

  const getChartSeries = (points, series) => (
    Array.isArray(series) && series.length
      ? series
      : [{ id: 'total', label: 'Total', data: points.map(point => point.count) }]
  );

  const buildLineChartOption = (points, chartSeries, showTrend, forExport = false) => {
    const totalSeries = chartSeries.find(item => item.id === 'total') || chartSeries[0];
    const trendValues = showTrend ? linearTrend(totalSeries.data || []) : [];
    const canShowSymbols = points.length * chartSeries.length <= 180;
    const lineSeries = chartSeries.map((item, seriesIndex) => {
      const color = seriesColor(seriesIndex);
      const isTotal = item.id === 'total';
      return {
        id: String(item.id),
        name: String(item.label),
        type: 'line',
        data: (item.data || []).map(value => Number(value) || 0),
        smooth: 0.22,
        smoothMonotone: 'x',
        showSymbol: canShowSymbols,
        symbol: 'circle',
        symbolSize: value => Number(value) > 0 ? (isTotal ? 8 : 6) : 0,
        itemStyle: {
          color,
          borderColor: '#ffffff',
          borderWidth: isTotal ? 2 : 1.5,
        },
        lineStyle: {
          color,
          width: isTotal ? 3.5 : 2,
          opacity: isTotal ? 1 : 0.82,
          cap: 'round',
          join: 'round',
        },
        areaStyle: isTotal ? {
          color: new echartsApi.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(16,99,255,0.22)' },
            { offset: 0.72, color: 'rgba(16,99,255,0.035)' },
            { offset: 1, color: 'rgba(16,99,255,0)' },
          ]),
        } : undefined,
        emphasis: {
          focus: 'series',
          lineStyle: { width: isTotal ? 4.5 : 3.25, opacity: 1 },
        },
        z: isTotal ? 10 : 4,
      };
    });

    if (showTrend) {
      lineSeries.push({
        id: 'cea-total-trend',
        name: 'Tendencia del total',
        type: 'line',
        data: trendValues.map(value => Number(value.toFixed(2))),
        smooth: false,
        showSymbol: false,
        silent: forExport,
        lineStyle: { color: '#667085', width: 2, type: 'dashed', opacity: 0.9 },
        emphasis: { disabled: true },
        z: 3,
      });
    }

    const legendNames = lineSeries.map(item => item.name);
    return {
      animation: !forExport,
      animationDuration: 650,
      animationEasing: 'cubicOut',
      backgroundColor: '#ffffff',
      color: seriesColors,
      aria: {
        enabled: !forExport,
        label: { description: 'Evolución temporal de leads por formulario.' },
      },
      grid: {
        left: forExport ? 30 : 24,
        right: forExport ? 24 : 20,
        top: forExport ? 24 : 20,
        bottom: forExport ? 22 : 20,
        containLabel: true,
      },
      legend: {
        show: false,
        data: legendNames,
      },
      tooltip: {
        show: !forExport,
        trigger: 'axis',
        backgroundColor: 'rgba(17,24,39,0.96)',
        borderWidth: 0,
        padding: [11, 13],
        textStyle: { color: '#ffffff', fontSize: 12 },
        axisPointer: { type: 'line', lineStyle: { color: '#98a2b3', type: 'dashed' } },
        formatter: params => {
          const rows = Array.isArray(params) ? params : [params];
          const heading = escapeHtml(rows[0]?.axisValueLabel || '');
          return `<div class="cea-chart-tooltip"><strong>${heading}</strong>${rows.map(item => (
            `<div>${item.marker}${escapeHtml(item.seriesName)}<b>${decimal.format(Number(item.value) || 0)}</b></div>`
          )).join('')}</div>`;
        },
      },
      xAxis: {
        type: 'category',
        boundaryGap: false,
        data: points.map(point => point.label),
        axisLine: { lineStyle: { color: '#d0d5dd' } },
        axisTick: { show: false },
        axisLabel: {
          color: '#667085',
          fontSize: forExport ? 11 : 12,
          margin: 14,
          hideOverlap: true,
        },
      },
      yAxis: {
        type: 'value',
        min: 0,
        minInterval: 1,
        splitNumber: 4,
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: {
          color: '#667085',
          fontSize: forExport ? 11 : 12,
          formatter: value => number.format(value),
        },
        splitLine: {
          lineStyle: { color: '#e9edf3', width: 1, type: 'dashed' },
        },
      },
      series: lineSeries,
    };
  };

  const syncLegendButtons = (selected = {}) => {
    els.legend.querySelectorAll('[data-series-name]').forEach(button => {
      const active = selected[button.dataset.seriesName] !== false;
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };

  const renderSeriesLegend = (chartSeries, showTrend) => {
    const items = chartSeries.map((item, index) => ({
      name: String(item.label),
      label: String(item.label),
      color: seriesColor(index),
      total: item.id === 'total',
      trend: false,
    }));
    if (showTrend) {
      items.push({ name: 'Tendencia del total', label: 'Tendencia del total', color: '#667085', total: false, trend: true });
    }

    els.legend.innerHTML = items.map(item => (
      `<button type="button" class="cea-legend-item${item.total ? ' cea-legend-total' : ''}" data-series-name="${escapeHtml(item.name)}" aria-pressed="true">`
      + `<i${item.trend ? ' class="cea-legend-trend"' : ` style="background:${item.color}"`}></i>${escapeHtml(item.label)}</button>`
    )).join('');

    els.legend.querySelectorAll('[data-series-name]').forEach(button => {
      button.addEventListener('click', () => {
        if (!state.chart) return;
        state.chart.dispatchAction({ type: 'legendToggleSelect', name: button.dataset.seriesName });
      });
    });
  };

  const renderLineChart = (points, series, showTrend) => {
    if (!points || !points.length) {
      if (state.chart) {
        state.chart.dispose();
        state.chart = null;
      }
      els.line.innerHTML = '<div class="cea-chart-empty">No hay datos en este periodo.</div>';
      els.legend.innerHTML = '';
      return;
    }
    if (!echartsApi) {
      els.line.innerHTML = '<div class="cea-chart-empty">No se ha podido cargar el motor de gráficas.</div>';
      els.legend.innerHTML = '';
      return;
    }

    const chartSeries = getChartSeries(points, series);
    if (!state.chart) {
      els.line.innerHTML = '';
      state.chart = echartsApi.init(els.line, null, { renderer: 'canvas' });
      state.chart.on('legendselectchanged', event => syncLegendButtons(event.selected || {}));
      if (window.ResizeObserver) {
        state.chartResizeObserver = new ResizeObserver(() => state.chart?.resize());
        state.chartResizeObserver.observe(els.line);
      } else {
        window.addEventListener('resize', () => state.chart?.resize());
      }
    }

    state.chart.setOption(buildLineChartOption(points, chartSeries, showTrend), { notMerge: true });
    renderSeriesLegend(chartSeries, showTrend);
  };

  const createReportChartImage = () => {
    if (!echartsApi || !state.dashboard?.timeline?.length) return '';
    const filters = currentFilters(false);
    const matchesDashboard = state.dashboardFilters
      && Object.keys(filters).every(key => String(filters[key] ?? '') === String(state.dashboardFilters[key] ?? ''));
    if (!matchesDashboard) return '';

    const node = document.createElement('div');
    node.style.cssText = 'position:fixed;left:-10000px;top:0;width:1200px;height:380px;background:#fff;';
    document.body.appendChild(node);
    let exportChart = null;

    try {
      const chartSeries = getChartSeries(state.dashboard.timeline, state.dashboard.series);
      exportChart = echartsApi.init(node, null, { renderer: 'canvas' });
      exportChart.setOption(buildLineChartOption(state.dashboard.timeline, chartSeries, els.trend.checked, true));
      const image = exportChart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#ffffff' });
      return image.length <= 7000000 ? image : '';
    } catch (error) {
      return '';
    } finally {
      if (exportChart) exportChart.dispose();
      node.remove();
    }
  };

  const renderFormCards = (forms, previousPeriod) => {
    const periodLabel = previousPeriod
      ? `${formatPeriodDate(previousPeriod.start)} – ${formatPeriodDate(previousPeriod.end)}`
      : '';
    els.comparisonCaption.textContent = periodLabel ? `Comparativa con el periodo anterior: ${periodLabel}.` : 'Comparativa con el periodo anterior equivalente.';
    if (!forms || !forms.length) {
      els.formCards.innerHTML = '<div class="cea-card cea-form-card cea-form-card-empty">No hay formularios disponibles.</div>';
      return;
    }
    els.formCards.innerHTML = forms.map((item) => {
      const difference = Number(item.difference) || 0;
      const direction = difference > 0 ? 'positive' : difference < 0 ? 'negative' : 'neutral';
      const arrow = difference > 0 ? '↑' : difference < 0 ? '↓' : '→';
      let changeLabel = 'Sin cambios';
      if (difference === 0) {
        changeLabel = 'Sin cambios';
      } else if (Number(item.previous) === 0 && Number(item.current) > 0) {
        changeLabel = 'Nuevo en este periodo';
      } else if (item.changePercent !== null && item.changePercent !== undefined) {
        changeLabel = `${arrow} ${decimal.format(Math.abs(Number(item.changePercent) || 0))} %`;
      }
      const differenceLabel = `${difference > 0 ? '+' : ''}${number.format(difference)} lead${Math.abs(difference) === 1 ? '' : 's'}`;
      return `<article class="cea-card cea-form-card">
        <div class="cea-form-card-head"><h3 title="${escapeHtml(item.label)}">${escapeHtml(item.label)}</h3><span>${escapeHtml(item.id)}</span></div>
        <div class="cea-form-card-value"><strong>${number.format(item.current)}</strong><span>leads en el periodo actual</span></div>
        <div class="cea-form-card-comparison">
          <div><span>Periodo anterior</span><strong>${number.format(item.previous)}</strong></div>
          <div class="cea-change cea-change-${direction}"><strong>${escapeHtml(changeLabel)}</strong><span>${escapeHtml(differenceLabel)}</span></div>
        </div>
      </article>`;
    }).join('');
  };

  const renderHeatmap = (matrix) => {
    const days = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
    const values = (matrix || []).flat();
    const max = Math.max(1, ...values);
    let html = '<div class="cea-heatmap"><div></div>';
    for (let hour = 0; hour < 24; hour++) html += `<div class="cea-heatmap-hour">${String(hour).padStart(2,'0')}</div>`;
    days.forEach((day, dayIndex) => {
      html += `<div class="cea-heatmap-day">${day}</div>`;
      for (let hour = 0; hour < 24; hour++) {
        const count = matrix?.[dayIndex]?.[hour] || 0;
        const opacity = count ? 0.14 + (count / max) * 0.82 : 0.04;
        const title = `${day}, ${String(hour).padStart(2,'0')}:00–${String((hour+1)%24).padStart(2,'0')}:00: ${number.format(count)} leads`;
        html += `<div class="cea-heatmap-cell" tabindex="0" title="${escapeHtml(title)}" style="background:rgba(34,113,177,${opacity.toFixed(2)})"></div>`;
      }
    });
    html += '</div>';
    els.heatmap.innerHTML = html;
  };

  const renderLeads = (data) => {
    state.totalPages = data.totalPages || 1;
    state.page = data.page || 1;
    els.tableCount.textContent = `${number.format(data.total)} resultados`;
    els.pageInfo.textContent = `Página ${number.format(state.page)} de ${number.format(state.totalPages)}`;
    els.prev.disabled = state.page <= 1;
    els.next.disabled = state.page >= state.totalPages;
    if (!data.items.length) {
      els.body.innerHTML = '<tr><td colspan="7"><div class="cea-table-empty">No se han encontrado leads con estos filtros.</div></td></tr>';
      return;
    }
    els.body.innerHTML = data.items.map((lead) => {
      const status = lead.isRead ? '<span class="cea-status cea-status-read">Leído</span>' : '<span class="cea-status cea-status-unread">No leído</span>';
      const source = lead.url ? `<a class="cea-source-link" href="${escapeHtml(lead.url)}" target="_blank" rel="noopener" title="${escapeHtml(lead.url)}">${escapeHtml(lead.url)}</a>` : '<span class="cea-muted">—</span>';
      const email = lead.email ? `<a href="mailto:${escapeHtml(lead.email)}">${escapeHtml(lead.email)}</a>` : '<span class="cea-muted">—</span>';
      return `<tr class="${lead.isRead ? '' : 'cea-lead-unread'}"><td>${status}</td><td>${escapeHtml(formatDate(lead.created))}</td><td>${escapeHtml(lead.name || '—')}</td><td>${email}</td><td>${escapeHtml(lead.formName)}</td><td>${source}</td><td><div class="cea-actions"><button type="button" class="button button-small" data-lead-id="${lead.id}">Ver</button><a class="button button-small" href="${escapeHtml(lead.crmUrl)}">CRM</a></div></td></tr>`;
    }).join('');
    els.body.querySelectorAll('[data-lead-id]').forEach((button) => button.addEventListener('click', () => openLead(button.dataset.leadId)));
  };

  const openLead = async (id) => {
    els.modal.classList.remove('cea-hidden');
    document.body.style.overflow = 'hidden';
    els.modalContent.innerHTML = '<div class="cea-loading"><span class="spinner is-active"></span>Cargando lead…</div>';
    try {
      const lead = await api(`lead/${encodeURIComponent(id)}`);
      const fields = lead.fields.length ? lead.fields.map((field) => `<div class="cea-detail-label">${escapeHtml(field.label)}</div><div class="cea-detail-value">${escapeHtml(field.value || '—')}</div>`).join('') : '<p>No hay campos disponibles.</p>';
      els.modalContent.innerHTML = `<h2 class="cea-modal-title" id="cea-modal-title">${escapeHtml(lead.formName)}</h2><div class="cea-modal-meta"><span>${escapeHtml(formatDate(lead.created))}</span><span>·</span><span>${lead.isRead ? 'Leído' : 'No leído'}</span></div><div class="cea-detail-grid">${fields}</div><div class="cea-modal-footer"><a class="button button-primary" href="${escapeHtml(lead.crmUrl)}">Abrir en CRM Entries</a></div>`;
    } catch (error) {
      els.modalContent.innerHTML = `<p class="cea-error-text">${escapeHtml(error.message)}</p>`;
    }
  };

  const closeModal = () => {
    els.modal.classList.add('cea-hidden');
    document.body.style.overflow = '';
  };

  els.apply.addEventListener('click', () => { state.page = 1; loadAll(); });
  els.exportPdf.addEventListener('click', downloadReport);
  if (els.exportCsv) els.exportCsv.addEventListener('click', openCsvExport);
  els.reset.addEventListener('click', () => {
    if (!state.defaults) return;
    els.start.value = state.defaults.start;
    els.end.value = state.defaults.end;
    els.datePreset.value = '30';
    els.form.value = '';
    els.status.value = 'all';
    els.search.value = '';
    state.search = '';
    state.page = 1;
    loadAll();
  });
  els.searchButton.addEventListener('click', () => { state.search = els.search.value.trim(); state.page = 1; loadLeads(); });
  els.datePreset.addEventListener('change', () => {
    if (!applyDatePreset(els.datePreset.value)) return;
    state.page = 1;
    loadAll();
  });
  [els.start, els.end].forEach(node => node.addEventListener('change', () => { els.datePreset.value = 'custom'; }));
  els.search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); els.searchButton.click(); } });
  els.prev.addEventListener('click', () => { if (state.page > 1) { state.page--; loadLeads(); } });
  els.next.addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; loadLeads(); } });
  els.perPage.addEventListener('change', () => { state.perPage = Number(els.perPage.value) || 25; state.page = 1; loadLeads(); });
  els.trend.addEventListener('change', () => {
    if (state.dashboard) renderLineChart(state.dashboard.timeline, state.dashboard.series, els.trend.checked);
  });
  els.modal.querySelectorAll('[data-cea-close]').forEach((node) => node.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !els.modal.classList.contains('cea-hidden')) closeModal(); });

  init();
})();
