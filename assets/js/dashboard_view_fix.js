/*=============================================================================
  dashboard_view_fix.js  — VERSIÓN CORREGIDA 7.0.0
  -----------------------------------------------------------------------------
  BUG CORREGIDO:
  La vista del Dashboard llamaba al endpoint /dashboard/series y /dashboard/kpis
  SIN pasarle los parámetros de fecha correctos. El date-range picker del header
  del dashboard no estaba conectado a las llamadas AJAX, así que los KPIs y las
  gráficas siempre mostraban los datos del último mes por defecto y NO se
  actualizaban al cambiar las fechas.

  CAMBIOS:
    1. La vista ahora lee los valores del date-range picker (#dash-from, #dash-to)
       y los pasa como `fecha_desde` / `fecha_hasta` al endpoint.
    2. Se añade soporte para filtrar por empresa (#dash-empresa) y por usuario
       (#dash-usuario) — útil cuando el admin quiere ver el dashboard de una
       empresa o técnico concreto.
    3. Cada card de KPI ahora muestra un spinner mientras carga y un mensaje
       de error si la llamada falla.
    4. Las 4 series (Estado, Prioridad, Empresa, Atenciones por Técnico) se
       recargan cuando cambian los filtros, con debounce de 350 ms.
    5. Se añade un botón "Hoy" y "Últimos 30 días" para rangos rápidos.
    6. Manejo robusto de errores: si una serie falla, se muestra el error
       dentro de la card correspondiente, sin tirar toda la vista.

  USO:
    Reemplazar el bloque `App.views.dashboard = function(...){...}` dentro de
    assets/js/views.js por este código, manteniendo el nombre de la función.

  NOTA: Asume que App.api.get() está disponible y que Chart.js está cargado.
=============================================================================*/

App.views.dashboard = function(container, params) {
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

    container.innerHTML = `
    <div class="dashboard-v7">
      <!-- Barra de filtros -->
      <div class="dash-filters card mb-3">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
          <div class="me-2">
            <label class="form-label small text-muted mb-1">Desde</label>
            <input type="date" id="dash-from" class="form-control form-control-sm" value="${monthAgo}">
          </div>
          <div class="me-2">
            <label class="form-label small text-muted mb-1">Hasta</label>
            <input type="date" id="dash-to" class="form-control form-control-sm" value="${today}">
          </div>
          <div class="me-2">
            <label class="form-label small text-muted mb-1">Empresa</label>
            <select id="dash-empresa" class="form-select form-select-sm" style="min-width:200px">
              <option value="">Todas</option>
            </select>
          </div>
          <div class="me-2">
            <label class="form-label small text-muted mb-1">Técnico</label>
            <select id="dash-usuario" class="form-select form-select-sm" style="min-width:180px">
              <option value="">Todos</option>
            </select>
          </div>
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-range="today">Hoy</button>
            <button type="button" class="btn btn-outline-secondary" data-range="7">7d</button>
            <button type="button" class="btn btn-outline-secondary active" data-range="30">30d</button>
            <button type="button" class="btn btn-outline-secondary" data-range="90">90d</button>
          </div>
          <button type="button" id="dash-refresh" class="btn btn-primary btn-sm ms-auto">
            <i class="bi bi-arrow-clockwise"></i> Actualizar
          </button>
        </div>
      </div>

      <!-- KPIs -->
      <div class="row g-3 mb-3" id="dash-kpis">
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Tickets Totales</div><div class="kpi-value" data-kpi="tickets_total">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Pendientes</div><div class="kpi-value text-warning" data-kpi="tickets_pendientes">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">En Atención</div><div class="kpi-value text-info" data-kpi="tickets_en_atencion">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Resueltos</div><div class="kpi-value text-success" data-kpi="tickets_resueltos">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Críticos</div><div class="kpi-value text-danger" data-kpi="tickets_criticos">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Atenciones</div><div class="kpi-value" data-kpi="atenciones_total">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Tiempo Promedio (min)</div><div class="kpi-value" data-kpi="tiempo_promedio_minutos">—</div></div></div></div>
        <div class="col-md-3"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small">Técnicos Activos</div><div class="kpi-value" data-kpi="tecnicos_activos">—</div></div></div></div>
      </div>

      <!-- Gráficas -->
      <div class="row g-3">
        <div class="col-md-6"><div class="card h-100"><div class="card-header">Tickets por Estado</div><div class="card-body"><canvas id="chart-estado" height="180"></canvas></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-header">Tickets por Prioridad</div><div class="card-body"><canvas id="chart-prioridad" height="180"></canvas></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-header">Top 15 Empresas</div><div class="card-body"><canvas id="chart-empresa" height="180"></canvas></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-header">Atenciones por Técnico</div><div class="card-body"><canvas id="chart-tecnico" height="180"></canvas></div></div></div>
      </div>
    </div>`;

    // Cache de gráficas para poder destruirlas al recargar
    const charts = {};

    // Cargar catálogo de empresas para el filtro
    App.api.get('/empresas', { per_page: 100 }).then(r => {
        const sel = container.querySelector('#dash-empresa');
        (r.data || r || []).forEach(e => {
            const opt = document.createElement('option');
            opt.value = e.id_empresa;
            opt.textContent = e.razon_social;
            sel.appendChild(opt);
        });
    }).catch(() => {});

    // Cargar lista de técnicos para el filtro
    App.api.get('/personas', { rol: 'TECNICO', per_page: 100 }).then(r => {
        const sel = container.querySelector('#dash-usuario');
        (r.data || r || []).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id_persona;
            opt.textContent = (p.nombres || '') + ' ' + (p.apellido_paterno || '');
            sel.appendChild(opt);
        });
    }).catch(() => {});

    // Construye querystring con los filtros actuales
    function getQueryParams() {
        const q = new URLSearchParams();
        const desde = container.querySelector('#dash-from').value;
        const hasta = container.querySelector('#dash-to').value;
        const emp   = container.querySelector('#dash-empresa').value;
        const usr   = container.querySelector('#dash-usuario').value;
        if (desde) q.set('fecha_desde', desde);
        if (hasta) q.set('fecha_hasta', hasta);
        if (emp)   q.set('id_empresa', emp);
        if (usr)   q.set('id_usuario', usr);
        return q.toString();
    }

    // Carga los KPIs
    function loadKpis() {
        const qs = getQueryParams();
        // Mostrar spinners
        container.querySelectorAll('.kpi-value').forEach(el => {
            el.innerHTML = '<span class="spinner-border spinner-border-sm text-muted"></span>';
        });
        App.api.get('/dashboard/kpis', { _qs: qs }).then(res => {
            const data = (res.data && res.data.data) ? res.data.data : (res.data || res || {});
            Object.keys(data).forEach(k => {
                const el = container.querySelector(`[data-kpi="${k}"]`);
                if (el) el.textContent = Number(data[k] || 0).toLocaleString('es-PE');
            });
        }).catch(err => {
            container.querySelectorAll('.kpi-value').forEach(el => {
                el.innerHTML = '<span class="text-danger small">Error</span>';
            });
            console.error('[Dashboard] Error KPIs:', err);
        });
    }

    // Carga las 4 series y renderiza los charts
    function loadSeries() {
        const qs = getQueryParams();
        const series = [
            { tipo: 'TICKETS_POR_ESTADO',      chartId: 'chart-estado',    kind: 'doughnut' },
            { tipo: 'TICKETS_POR_PRIORIDAD',    chartId: 'chart-prioridad', kind: 'bar' },
            { tipo: 'TICKETS_POR_EMPRESA',      chartId: 'chart-empresa',   kind: 'barh' },
            { tipo: 'ATENCIONES_POR_TECNICO',   chartId: 'chart-tecnico',   kind: 'bar' },
        ];
        series.forEach(s => {
            App.api.get('/dashboard/series', { _qs: qs + '&tipo=' + s.tipo, background: true, silent: true })
                .then(res => {
                    const rows = (res.data && res.data.data) ? res.data.data : (res.data || res || []);
                    renderChart(s.chartId, s.kind, rows);
                })
                .catch(err => {
                    const canvas = container.querySelector('#' + s.chartId);
                    if (canvas && canvas.parentElement) {
                        canvas.parentElement.innerHTML =
                            '<div class="text-danger small p-3">No se pudo cargar: ' +
                            (err.message || 'error desconocido') + '</div>';
                    }
                });
        });
    }

    // Renderiza un chart con Chart.js
    function renderChart(canvasId, kind, rows) {
        const canvas = container.querySelector('#' + canvasId);
        if (!canvas) return;
        if (charts[canvasId]) { charts[canvasId].destroy(); delete charts[canvasId]; }

        const labels = rows.map(r => r.estado || r.prioridad || r.empresa || r.tecnico || r.fecha || '');
        const data   = rows.map(r => Number(r.total || r.atenciones || r.promedio_minutos || 0));

        const palette = ['#0d6efd','#6610f2','#6f42c1','#d63384','#dc3545','#fd7e14','#ffc107','#198754','#20c997','#0dcaf0','#adb5bd','#0d6efd','#6610f2','#6f42c1','#d63384'];

        let config;
        if (kind === 'doughnut') {
            config = { type: 'doughnut', data: { labels, datasets: [{ data, backgroundColor: palette }] } };
        } else if (kind === 'barh') {
            config = {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Total', data, backgroundColor: '#0d6efd' }] },
                options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } }
            };
        } else {
            config = {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Total', data, backgroundColor: palette[0] }] },
                options: { responsive: true, plugins: { legend: { display: false } } }
            };
        }
        try {
            charts[canvasId] = new Chart(canvas, config);
        } catch (e) {
            console.error('[Dashboard] Chart error:', e);
        }
    }

    // Recarga todo (KPIs + series)
    function reloadAll() { loadKpis(); loadSeries(); }

    // Debounce para no saturar al server con cambios rápidos
    let debounceTimer = null;
    function debouncedReload() {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(reloadAll, 350);
    }

    // Event listeners
    container.querySelector('#dash-from').addEventListener('change', debouncedReload);
    container.querySelector('#dash-to').addEventListener('change', debouncedReload);
    container.querySelector('#dash-empresa').addEventListener('change', debouncedReload);
    container.querySelector('#dash-usuario').addEventListener('change', debouncedReload);
    container.querySelector('#dash-refresh').addEventListener('click', reloadAll);

    container.querySelectorAll('[data-range]').forEach(btn => {
        btn.addEventListener('click', () => {
            container.querySelectorAll('[data-range]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const r = btn.dataset.range;
            const f = container.querySelector('#dash-from');
            const t = container.querySelector('#dash-to');
            t.value = today;
            if (r === 'today') {
                f.value = today;
            } else {
                f.value = new Date(Date.now() - parseInt(r) * 86400000).toISOString().slice(0, 10);
            }
            debouncedReload();
        });
    });

    // Carga inicial
    reloadAll();
};
