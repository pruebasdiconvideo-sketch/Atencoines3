/*=============================================================================
  versiones_view.js  — VERSIÓN 7.0.0
  -----------------------------------------------------------------------------
  Vista SPA para mostrar el panel de versiones del sistema.
  Permite ver:
    - La versión actual instalada
    - El historial de versiones
    - El detalle de cada versión (scripts SQL ejecutados, archivos modificados,
      cambios realizados)
=============================================================================*/

App.views.versiones = function(container, params) {
    container.innerHTML = `
    <div class="versiones-view">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 class="h4 mb-1">Control de Versiones del Sistema</h2>
          <p class="text-muted small mb-0">Historial de actualizaciones instaladas en la base de datos</p>
        </div>
        <span class="badge bg-success" id="version-actual-badge">—</span>
      </div>

      <!-- Tarjeta de versión actual -->
      <div class="card mb-4" id="version-actual-card">
        <div class="card-body">
          <div class="d-flex align-items-center">
            <div class="me-3">
              <i class="bi bi-code-square text-primary" style="font-size:2.5rem"></i>
            </div>
            <div class="flex-grow-1">
              <div class="text-muted small">Versión actual</div>
              <h3 class="mb-0" id="va-version">—</h3>
              <div class="text-muted" id="va-nombre">—</div>
              <div class="text-muted small" id="va-fecha">—</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="d-flex gap-2 mb-3">
        <input type="text" class="form-control form-control-sm" id="ver-search" placeholder="Buscar versión..." style="max-width:300px">
        <select class="form-select form-select-sm" id="ver-modulo" style="max-width:200px">
          <option value="">Todos los módulos</option>
          <option value="GLOBAL">GLOBAL</option>
          <option value="DASHBOARD">DASHBOARD</option>
          <option value="COTIZACIONES">COTIZACIONES</option>
          <option value="TICKETS">TICKETS</option>
          <option value="SEGURIDAD">SEGURIDAD</option>
        </select>
      </div>

      <!-- Tabla de versiones -->
      <div class="card">
        <div class="card-header">
          <strong>Historial de versiones</strong>
          <button class="btn btn-sm btn-outline-primary float-end" id="ver-refresh">
            <i class="bi bi-arrow-clockwise"></i> Actualizar
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:120px">Versión</th>
                <th>Nombre</th>
                <th style="width:100px">Tipo</th>
                <th style="width:120px">Módulo</th>
                <th style="width:160px">Fecha</th>
                <th style="width:80px"></th>
              </tr>
            </thead>
            <tbody id="ver-tbody">
              <tr><td colspan="6" class="text-center text-muted py-4">
                <span class="spinner-border spinner-border-sm"></span> Cargando...
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal de detalle -->
      <div class="modal fade" id="ver-modal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Detalle de versión <span id="md-version" class="text-primary"></span></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="md-body">
              <div class="text-center py-4"><span class="spinner-border"></span></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
          </div>
        </div>
      </div>
    </div>`;

    let allRows = [];

    function renderVersionActual() {
        App.api.get('/versiones/actual').then(res => {
            const v = res.data?.data || res.data || {};
            container.querySelector('#va-version').textContent = v.version_codigo || '—';
            container.querySelector('#va-nombre').textContent = v.nombre_release || '—';
            container.querySelector('#va-fecha').textContent = v.fecha_instalacion || '—';
            container.querySelector('#version-actual-badge').textContent = 'v' + (v.version_codigo || '—');
        }).catch(err => {
            container.querySelector('#va-version').textContent = 'Error al cargar';
            console.error(err);
        });
    }

    function renderTabla() {
        const tbody = container.querySelector('#ver-tbody');
        const q = container.querySelector('#ver-search').value.toLowerCase();
        const m = container.querySelector('#ver-modulo').value;

        const filtered = allRows.filter(r => {
            const matchQ = !q
                || (r.version_codigo || '').toLowerCase().includes(q)
                || (r.nombre_release || '').toLowerCase().includes(q)
                || (r.descripcion_corta || '').toLowerCase().includes(q);
            const matchM = !m || (r.modulo_afectado || '') === m;
            return matchQ && matchM;
        });

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No hay versiones</td></tr>';
            return;
        }

        const tipoBadge = {
            'MAJOR':   'bg-danger',
            'MINOR':   'bg-primary',
            'PATCH':   'bg-success',
            'HOTFIX':  'bg-warning text-dark',
        };

        tbody.innerHTML = filtered.map(r => `
            <tr>
                <td><strong>${r.version_codigo || '—'}</strong></td>
                <td>
                    <div>${r.nombre_release || '—'}</div>
                    ${r.descripcion_corta ? `<small class="text-muted">${r.descripcion_corta.substring(0,80)}${r.descripcion_corta.length > 80 ? '…' : ''}</small>` : ''}
                </td>
                <td><span class="badge ${tipoBadge[r.tipo_release] || 'bg-secondary'}">${r.tipo_release || '—'}</span></td>
                <td><code>${r.modulo_afectado || '—'}</code></td>
                <td><small>${r.fecha_instalacion || '—'}</small></td>
                <td><button class="btn btn-sm btn-outline-info ver-detalle" data-id="${r.id_version}"><i class="bi bi-eye"></i></button></td>
            </tr>
        `).join('');

        tbody.querySelectorAll('.ver-detalle').forEach(btn => {
            btn.addEventListener('click', () => mostrarDetalle(btn.dataset.id));
        });
    }

    function mostrarDetalle(idVersion) {
        const modal = new bootstrap.Modal(container.querySelector('#ver-modal'));
        container.querySelector('#md-body').innerHTML = '<div class="text-center py-4"><span class="spinner-border"></span></div>';
        modal.show();

        App.api.get('/versiones/' + idVersion).then(res => {
            const v = res.data?.data || res.data || {};
            container.querySelector('#md-version').textContent = v.version_codigo || '—';

            const renderList = (title, items, icon) => {
                if (!items || items.length === 0) return '';
                const lis = items.map(i => `<li class="mb-1"><code>${i}</code></li>`).join('');
                return `<div class="mb-3"><h6 class="text-muted"><i class="bi ${icon}"></i> ${title} (${items.length})</h6><ul class="small">${lis}</ul></div>`;
            };

            const renderCambios = (cambios) => {
                if (!cambios || cambios.length === 0) return '';
                const items = cambios.map(c => `
                    <div class="d-flex align-items-start mb-2 p-2 border rounded">
                        <span class="badge ${c.tipo === 'bugfix' ? 'bg-danger' : c.tipo === 'feature' ? 'bg-success' : 'bg-info'} me-2">${c.tipo}</span>
                        <div class="flex-grow-1">
                            <strong>${c.modulo}</strong>
                            <div class="small text-muted">${c.descripcion}</div>
                        </div>
                    </div>
                `).join('');
                return `<div class="mb-3"><h6 class="text-muted">Cambios (${cambios.length})</h6>${items}</div>`;
            };

            container.querySelector('#md-body').innerHTML = `
                <div class="mb-3">
                    <h5>${v.nombre_release || '—'}</h5>
                    <div class="text-muted small">Versión: <strong>${v.version_codigo}</strong> · Tipo: <strong>${v.tipo_release}</strong> · Módulo: <code>${v.modulo_afectado}</code></div>
                    <div class="text-muted small">Instalada: ${v.fecha_instalacion} por ${v.usuario_instalo_nombre || 'Sistema'}</div>
                </div>
                ${v.descripcion ? `<div class="alert alert-light border"><pre class="mb-0 small" style="white-space:pre-wrap">${v.descripcion}</pre></div>` : ''}
                ${v.requiere_migracion === 1 ? '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Esta versión requiere migración de datos</div>' : ''}
                ${v.rompe_compatibilidad === 1 ? '<div class="alert alert-danger"><i class="bi bi-x-octagon"></i> Esta versión rompe compatibilidad</div>' : ''}
                ${renderList('Scripts SQL ejecutados', v.scripts_sql_json, 'bi-database')}
                ${renderList('Archivos PHP modificados', v.archivos_php_json, 'bi-file-code')}
                ${renderList('Archivos JS modificados', v.archivos_js_json, 'bi-filetype-js')}
                ${renderList('Archivos CSS modificados', v.archivos_css_json, 'bi-palette')}
                ${renderCambios(v.cambios_json)}
            `;
        }).catch(err => {
            container.querySelector('#md-body').innerHTML =
                '<div class="alert alert-danger">Error al cargar detalle: ' + (err.message || 'desconocido') + '</div>';
        });
    }

    function loadAll() {
        renderVersionActual();
        App.api.get('/versiones').then(res => {
            allRows = res.data?.data || res.data || [];
            renderTabla();
        }).catch(err => {
            container.querySelector('#ver-tbody').innerHTML =
                '<tr><td colspan="6" class="text-center text-danger py-4">Error: ' + (err.message || 'desconocido') + '</td></tr>';
        });
    }

    container.querySelector('#ver-search').addEventListener('input', renderTabla);
    container.querySelector('#ver-modulo').addEventListener('change', renderTabla);
    container.querySelector('#ver-refresh').addEventListener('click', loadAll);

    loadAll();
};
