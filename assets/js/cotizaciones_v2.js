/* ============================================================================
 *  cotizaciones_v2.js — Versión corregida del módulo de Cotizaciones
 * ============================================================================
 *
 *  CAMBIOS RESPECTO A LA VERSIÓN 1:
 *  - Usa `/cotizaciones_catalogos` (sin slash) como URL principal.
 *  - Si la URL falla (400/404), automáticamente reintenta con
 *    `/cotizaciones/catalogos` (con slash, alias de compatibilidad).
 *  - El botón "Subir Logo" aparece TAMBIÉN en:
 *      · El listado de cotizaciones (botón flotante derecho "🏢 Logo")
 *      · El formulario de Nueva Cotización (junto al select de emisora)
 *      · La vista de detalle de cotización (si la emisora no tiene logo,
 *        muestra un warning clickeable "Subir logo ahora")
 *  - El menú lateral ahora incluye "Empresas Emisoras" como acceso directo.
 *  - Mensajes de error más descriptivos.
 *
 *  INSTALACIÓN:
 *    1. Sustituye el contenido de cotizaciones.js por este archivo, O
 *    2. Carga este archivo DESPUÉS de cotizaciones.js para sobrescribir vistas.
 *
 *  Requiere: app.js (App global + App.request + App.navigate)
 * ========================================================================== */

(function () {
  'use strict';

  /* ===================== HELPERS INTERNOS ===================== */

  const _catalogosCache = { data: null, ts: 0 };
  const _CATALOGOS_TTL_MS = 5 * 60 * 1000;

  /**
   * Carga los catálogos de cotizaciones, con fallback automático de URL.
   * Intenta primero `/cotizaciones_catalogos` (recurso dedicado).
   * Si falla, reintenta con `/cotizaciones/catalogos` (alias de compatibilidad).
   */
  async function cargarCatalogos(force = false) {
    const ahora = Date.now();
    if (!force && _catalogosCache.data && (ahora - _catalogosCache.ts) < _CATALOGOS_TTL_MS) {
      return _catalogosCache.data;
    }

    let r = await App.request('/cotizaciones_catalogos');
    if (!r.ok) {
      console.warn('[Cotizaciones] URL /cotizaciones_catalogos falló, reintentando con /cotizaciones/catalogos...', r.message);
      r = await App.request('/cotizaciones/catalogos');
    }

    if (!r.ok) {
      App.toast('Error al cargar catálogos: ' + (r.message || ''), 'error', 8000);
      return null;
    }
    _catalogosCache.data = r.data;
    _catalogosCache.ts = ahora;
    return r.data;
  }

  function fmtMoneda(val, moneda) {
    const simbolo = { PEN: 'S/', USD: '$', EUR: '€' };
    const s = simbolo[moneda] || (moneda + ' ');
    const n = Number(val || 0);
    return s + ' ' + n.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtFecha(f) {
    if (!f) return '—';
    try {
      const d = new Date(f);
      return d.toLocaleDateString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch (e) { return String(f); }
  }

  function fmtFechaHora(f) {
    if (!f) return '—';
    try {
      const d = new Date(f);
      return d.toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' });
    } catch (e) { return String(f); }
  }

  function fmtBytes(b) {
    b = Number(b || 0);
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
  }

  function estadoColor(estado) {
    return {
      BORRADOR:        '#6c757d',
      PENDIENTE_ENVIO: '#17a2b8',
      ENVIADA:         '#007bff',
      EN_SEGUIMIENTO:  '#ffc107',
      EN_NEGOCIACION:  '#fd7e14',
      APROBADA:        '#28a745',
      RECHAZADA:       '#dc3545',
      CANCELADA:       '#343a40',
      CERRADA:         '#198754',
    }[estado] || '#6c757d';
  }

  function badgeEstado(estado) {
    const color = estadoColor(estado);
    const txt = ['#ffc107', '#fd7e14'].includes(color) ? '#212529' : '#fff';
    return `<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:${color};color:${txt};">${estado}</span>`;
  }

  /* ======================================================================
   *  MODAL GLOBAL: Subir logo de emisora
   *  (accesible desde cualquier vista — App.views.cotizaciones.subirLogo())
   * ====================================================================== */
  function modalSubirLogo(idEmisora, nombreEmisora) {
    if (!idEmisora) {
      App.toast('Debe seleccionar una emisora primero', 'warning');
      return;
    }

    const body = `
      <div style="text-align:center;margin-bottom:14px;">
        <div style="display:inline-block;width:160px;height:160px;border:2px dashed #ccc;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9fafc;">
          <img id="logoPreview" src="${App.config.apiBase}/cotizaciones_emisora/${idEmisora}/logo?t=${Date.now()}" style="max-width:100%;max-height:100%;object-fit:contain;" alt="logo actual" onerror="this.style.display='none';document.getElementById('logoPlaceholder').style.display='block';">
          <div id="logoPlaceholder" style="display:none;color:#95a5a6;font-size:11px;text-align:center;padding:14px;">Sin logo cargado<br>actualmente</div>
        </div>
        <div style="margin-top:6px;font-size:11px;color:#95a5a6;">Logo actual de: <strong>${App.escapeHtml(nombreEmisora)}</strong></div>
      </div>
      <div style="margin-bottom:10px;">
        <label style="display:block;font-size:11px;color:#95a5a6;margin-bottom:4px;">Seleccionar archivo (PNG, JPG, GIF, WebP, SVG — máx 2 MB)</label>
        <input type="file" id="fileLogo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" class="form-input" style="width:100%;">
      </div>
      <div style="padding:8px 10px;background:#eaf4ff;border-left:3px solid #007bff;font-size:11px;color:#2c3e50;">
        <strong>Recomendado:</strong> logo horizontal, máximo 400×100px, fondo transparente (PNG o SVG).
      </div>
    `;
    const footer = `
      <button class="btn btn-danger" id="btnEliminarLogo" style="float:left;"><i class="bi bi-trash"></i> Eliminar logo</button>
      <button class="btn btn-secondary" data-modal-close>Cancelar</button>
      <button class="btn btn-primary" id="btnSubirLogo"><i class="bi bi-upload"></i> Subir logo</button>
    `;
    App.showModal('Subir logo — ' + nombreEmisora, body, footer);

    // Preview al seleccionar archivo
    document.getElementById('fileLogo').onchange = (e) => {
      const f = e.target.files[0];
      if (!f) return;
      if (f.size > 2 * 1024 * 1024) {
        App.toast('El archivo supera 2 MB', 'warning');
        e.target.value = '';
        return;
      }
      const reader = new FileReader();
      reader.onload = (ev) => {
        const img = document.getElementById('logoPreview');
        img.src = ev.target.result;
        img.style.display = '';
        document.getElementById('logoPlaceholder').style.display = 'none';
      };
      reader.readAsDataURL(f);
    };

    document.getElementById('btnSubirLogo').onclick = async () => {
      const file = document.getElementById('fileLogo').files[0];
      if (!file) { App.toast('Seleccione un archivo', 'warning'); return; }
      const fd = new FormData();
      fd.append('logo', file);
      fd.append('id_usuario', App.state.user.id_persona);
      const r = await App.request('/cotizaciones_emisora/' + idEmisora + '/logo', {
        method: 'POST', body: fd,
      });
      if (r.ok) {
        App.toast('Logo actualizado correctamente', 'success');
        App.closeModal();
        // Recargar la vista actual si tiene método load
        if (App.views['cotizaciones-emisora'] && App.state.currentRoute?.viewName === 'cotizaciones-emisora') {
          App.views['cotizaciones-emisora'].load();
        }
      } else {
        App.toast('Error: ' + (r.message || ''), 'error', 8000);
      }
    };

    document.getElementById('btnEliminarLogo').onclick = async () => {
      if (!confirm('¿Eliminar el logo de esta emisora?')) return;
      const r = await App.request('/cotizaciones_emisora/' + idEmisora + '/logo', { method: 'DELETE' });
      if (r.ok) {
        App.toast('Logo eliminado', 'success');
        App.closeModal();
        if (App.views['cotizaciones-emisora'] && App.state.currentRoute?.viewName === 'cotizaciones-emisora') {
          App.views['cotizaciones-emisora'].load();
        }
      } else {
        App.toast('Error: ' + (r.message || ''), 'error');
      }
    };
  }

  /* ======================================================================
   *  VISTA: Listado de Cotizaciones
   * ====================================================================== */
  App.views.cotizaciones = {
    state: { page: 1, perPage: 25, total: 0, filtros: {}, rows: [] },

    async render(container, params) {
      this.state.container = container;
      container.innerHTML = `
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
              <h2 style="margin:0;font-size:18px;color:var(--c-text,#2c3e50);">Cotizaciones</h2>
              <div style="font-size:12px;color:#95a5a6;margin-top:2px;">Gestione sus cotizaciones y propuestas comerciales</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn btn-secondary" id="btnCotFiltros"><i class="bi bi-funnel"></i> Filtros</button>
              <div class="dropdown" style="position:relative;">
                <button class="btn btn-secondary" id="btnCotExport"><i class="bi bi-download"></i> Exportar</button>
                <div id="exportMenu" style="display:none;position:absolute;right:0;top:100%;background:#fff;border:1px solid #e0e0e0;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.15);min-width:200px;z-index:1000;">
                  <a href="#" data-export="csv"  style="display:block;padding:8px 14px;color:#2c3e50;text-decoration:none;font-size:13px;border-bottom:1px solid #f0f0f0;">📄 CSV (Excel)</a>
                  <a href="#" data-export="xlsx" style="display:block;padding:8px 14px;color:#2c3e50;text-decoration:none;font-size:13px;border-bottom:1px solid #f0f0f0;">📊 XLSX (Excel)</a>
                  <a href="#" data-export="html" style="display:block;padding:8px 14px;color:#2c3e50;text-decoration:none;font-size:13px;">🌐 HTML (imprimible)</a>
                </div>
              </div>
              <button class="btn btn-warning" id="btnCotLogo" title="Subir logo de empresa emisora" style="background:#ffc107;border-color:#ffc107;"><i class="bi bi-image"></i> 🏢 Subir Logo</button>
              <button class="btn btn-primary" id="btnCotNueva"><i class="bi bi-plus-lg"></i> Nueva Cotización</button>
              <button class="btn btn-secondary" id="btnCotEmisora"><i class="bi bi-building"></i> Empresas Emisoras</button>
            </div>
          </div>

          <div id="cotFiltrosPanel" style="display:none;padding:14px 18px;background:#f9fafc;border-bottom:1px solid #e0e0e0;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
              <div><label style="font-size:11px;color:#95a5a6;display:block;margin-bottom:3px;">Estado</label>
                <select class="form-select" id="filtroEstado" style="width:100%;">
                  <option value="">Todos</option>
                  <option value="BORRADOR">Borrador</option>
                  <option value="PENDIENTE_ENVIO">Pendiente envío</option>
                  <option value="ENVIADA">Enviada</option>
                  <option value="EN_SEGUIMIENTO">En seguimiento</option>
                  <option value="EN_NEGOCIACION">En negociación</option>
                  <option value="APROBADA">Aprobada</option>
                  <option value="RECHAZADA">Rechazada</option>
                  <option value="CANCELADA">Cancelada</option>
                  <option value="CERRADA">Cerrada</option>
                </select>
              </div>
              <div><label style="font-size:11px;color:#95a5a6;display:block;margin-bottom:3px;">Tipo</label>
                <select class="form-select" id="filtroTipo" style="width:100%;"><option value="">Todos</option></select></div>
              <div><label style="font-size:11px;color:#95a5a6;display:block;margin-bottom:3px;">Buscar</label>
                <input type="text" id="filtroSearch" class="form-input" placeholder="código, motivo, título, empresa..." style="width:100%;"></div>
              <div><label style="font-size:11px;color:#95a5a6;display:block;margin-bottom:3px;">Fecha desde</label>
                <input type="date" id="filtroDesde" class="form-input" style="width:100%;"></div>
              <div><label style="font-size:11px;color:#95a5a6;display:block;margin-bottom:3px;">Fecha hasta</label>
                <input type="date" id="filtroHasta" class="form-input" style="width:100%;"></div>
            </div>
            <div style="margin-top:10px;text-align:right;">
              <button class="btn btn-sm btn-secondary" id="btnLimpiarFiltros">Limpiar</button>
              <button class="btn btn-sm btn-primary" id="btnAplicarFiltros">Aplicar</button>
            </div>
          </div>

          <div id="cotTableWrap" style="padding:8px 18px;min-height:300px;"></div>

          <div id="cotPagination" style="padding:12px 18px;border-top:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;font-size:12px;color:#7f8c8d;"></div>
        </div>
      `;

      // Cargar tipos para el filtro (con fallback de URL)
      const cat = await cargarCatalogos();
      if (cat) {
        const sel = container.querySelector('#filtroTipo');
        cat.tipos.forEach(t => {
          const o = document.createElement('option');
          o.value = t.id_tipo; o.textContent = t.nombre;
          sel.appendChild(o);
        });
      }

      this.bindEvents();
      await this.load();
    },

    bindEvents() {
      const c = this.state.container;
      c.querySelector('#btnCotFiltros').onclick = () => {
        const p = c.querySelector('#cotFiltrosPanel');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
      };
      c.querySelector('#btnCotNueva').onclick = () => App.navigate('/cotizacion-nueva');
      c.querySelector('#btnCotEmisora').onclick = () => App.navigate('/cotizaciones-emisora');
      c.querySelector('#btnCotLogo').onclick = async () => {
        // Mostrar un modal para seleccionar la emisora primero
        const cat = await cargarCatalogos();
        if (!cat || !cat.emisoras.length) {
          App.toast('No hay empresas emisoras. Cree una primero en "Empresas Emisoras".', 'warning');
          return;
        }
        if (cat.emisoras.length === 1) {
          modalSubirLogo(cat.emisoras[0].id_emisora, cat.emisoras[0].razon_social);
        } else {
          // Selector de emisora
          const body = `
            <p style="font-size:13px;color:#555;margin-bottom:10px;">Seleccione la empresa emisora cuyo logo desea actualizar:</p>
            <select class="form-select" id="selEmisoraLogo" style="width:100%;margin-bottom:10px;">
              ${cat.emisoras.map(e => `<option value="${e.id_emisora}" data-nombre="${App.escapeHtml(e.razon_social)}">${App.escapeHtml(e.razon_social)} (RUC: ${App.escapeHtml(e.ruc)})${e.tiene_logo ? ' — ✓ con logo' : ' — sin logo'}</option>`).join('')}
            </select>
          `;
          const footer = `
            <button class="btn btn-secondary" data-modal-close>Cancelar</button>
            <button class="btn btn-primary" id="btnContinuarLogo"><i class="bi bi-arrow-right"></i> Continuar</button>
          `;
          App.showModal('Seleccionar empresa emisora', body, footer);
          document.getElementById('btnContinuarLogo').onclick = () => {
            const sel = document.getElementById('selEmisoraLogo');
            const idEmi = parseInt(sel.value, 10);
            const nombre = sel.options[sel.selectedIndex].dataset.nombre;
            App.closeModal();
            modalSubirLogo(idEmi, nombre);
          };
        }
      };

      c.querySelector('#btnAplicarFiltros').onclick = () => {
        this.state.page = 1;
        this.load();
      };
      c.querySelector('#btnLimpiarFiltros').onclick = () => {
        c.querySelector('#filtroEstado').value = '';
        c.querySelector('#filtroTipo').value = '';
        c.querySelector('#filtroDesde').value = '';
        c.querySelector('#filtroHasta').value = '';
        c.querySelector('#filtroSearch').value = '';
        this.state.page = 1;
        this.load();
      };

      // Dropdown exportar
      const btnExport = c.querySelector('#btnCotExport');
      const menu = c.querySelector('#exportMenu');
      btnExport.onclick = (e) => {
        e.stopPropagation();
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
      };
      document.addEventListener('click', () => menu.style.display = 'none', { once: true });
      menu.querySelectorAll('a').forEach(a => {
        a.onclick = (e) => {
          e.preventDefault();
          this.exportar(a.dataset.export);
        };
      });
    },

    getFiltros() {
      const c = this.state.container;
      return {
        estado:      c.querySelector('#filtroEstado').value || undefined,
        id_tipo:     c.querySelector('#filtroTipo').value || undefined,
        search:      c.querySelector('#filtroSearch').value || undefined,
        fecha_desde: c.querySelector('#filtroDesde').value || undefined,
        fecha_hasta: c.querySelector('#filtroHasta').value || undefined,
      };
    },

    async load() {
      const wrap = this.state.container.querySelector('#cotTableWrap');
      wrap.innerHTML = '<div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando cotizaciones...</p></div>';

      const filtros = this.getFiltros();
      const params = new URLSearchParams({
        page: String(this.state.page),
        per_page: String(this.state.perPage),
        ...Object.fromEntries(Object.entries(filtros).filter(([_, v]) => v)),
      });

      const r = await App.request('/cotizaciones?' + params.toString());
      if (!r.ok) {
        wrap.innerHTML = `<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__title">Error</div><div class="empty-state__desc">${App.escapeHtml(r.message || '')}</div></div>`;
        return;
      }

      const { data, pagination } = r;
      this.state.rows = data;
      this.state.total = pagination.total;

      if (!data.length) {
        wrap.innerHTML = `
          <div class="empty-state" style="padding:40px;">
            <div class="empty-state__icon">📄</div>
            <div class="empty-state__title">No hay cotizaciones</div>
            <div class="empty-state__desc">Cree su primera cotización o ajuste los filtros de búsqueda.</div>
            <button class="btn btn-primary" onclick="App.navigate('/cotizacion-nueva')"><i class="bi bi-plus-lg"></i> Nueva Cotización</button>
          </div>`;
        this.renderPagination();
        return;
      }

      wrap.innerHTML = `
        <table class="table" style="width:100%;font-size:13px;border-collapse:collapse;">
          <thead>
            <tr style="background:#f9fafc;">
              <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e0e0e0;">Código</th>
              <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e0e0e0;">Fecha</th>
              <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e0e0e0;">Empresa</th>
              <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e0e0e0;">Tipo</th>
              <th style="padding:10px 8px;text-align:left;border-bottom:2px solid #e0e0e0;">Estado</th>
              <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e0e0e0;">Total</th>
              <th style="padding:10px 8px;text-align:right;border-bottom:2px solid #e0e0e0;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            ${data.map(c => `
              <tr style="border-bottom:1px solid #f0f0f0;" onmouseover="this.style.background='#fafbfc'" onmouseout="this.style.background=''">
                <td style="padding:8px;">
                  <a href="#/cotizacion-detalle/${c.id_cotizacion}" style="color:#007bff;text-decoration:none;font-weight:600;">${App.escapeHtml(c.codigo)}</a>
                  <div style="font-size:10px;color:#95a5a6;">v${c.version_numero} · ${App.escapeHtml(c.titulo_documento || '')}</div>
                </td>
                <td style="padding:8px;font-size:11px;color:#555;">${fmtFecha(c.fecha_creacion)}</td>
                <td style="padding:8px;">${App.escapeHtml(c.empresa_razon_social || '—')}
                  ${c.empresa_ruc ? `<div style="font-size:10px;color:#95a5a6;">RUC: ${App.escapeHtml(c.empresa_ruc)}</div>` : ''}
                </td>
                <td style="padding:8px;font-size:11px;">${App.escapeHtml(c.tipo_nombre || '—')}</td>
                <td style="padding:8px;">${badgeEstado(c.estado)}</td>
                <td style="padding:8px;text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">${fmtMoneda(c.total, c.moneda)}</td>
                <td style="padding:8px;text-align:right;white-space:nowrap;">
                  <button class="btn btn-sm btn-secondary" title="Imprimir" onclick="App.views.cotizaciones.imprimir(${c.id_cotizacion})"><i class="bi bi-printer"></i></button>
                  <button class="btn btn-sm btn-primary" title="Ver detalle" onclick="App.navigate('/cotizacion-detalle/${c.id_cotizacion}')"><i class="bi bi-eye"></i></button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;

      this.renderPagination();
    },

    renderPagination() {
      const c = this.state.container.querySelector('#cotPagination');
      const totalPages = Math.max(1, Math.ceil(this.state.total / this.state.perPage));
      c.innerHTML = `
        <div>Mostrando <strong>${((this.state.page - 1) * this.state.perPage) + 1}</strong> a <strong>${Math.min(this.state.page * this.state.perPage, this.state.total)}</strong> de <strong>${this.state.total}</strong> cotizaciones</div>
        <div style="display:flex;gap:4px;">
          <button class="btn btn-sm btn-secondary" ${this.state.page === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.changePage(${this.state.page - 1})">« Anterior</button>
          <span style="padding:6px 12px;background:#f0f0f0;border-radius:4px;">${this.state.page} / ${totalPages}</span>
          <button class="btn btn-sm btn-secondary" ${this.state.page === totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.changePage(${this.state.page + 1})">Siguiente »</button>
        </div>
      `;
    },

    changePage(p) {
      if (p < 1) return;
      this.state.page = p;
      this.load();
    },

    exportar(formato) {
      const filtros = this.getFiltros();
      const params = new URLSearchParams(
        Object.fromEntries(Object.entries(filtros).filter(([_, v]) => v))
      );
      const url = App.config.apiBase + '/cotizaciones_export.' + formato + '?' + params.toString();
      window.open(url, '_blank');
    },

    imprimir(idCot) {
      const url = App.config.apiBase + '/cotizaciones_print/' + idCot;
      window.open(url, '_blank');
    },

    // Método público para subir logo desde cualquier sitio
    subirLogo(idEmisora, nombre) {
      modalSubirLogo(idEmisora, nombre);
    },
  };

  /* ======================================================================
   *  VISTA: Nueva cotización (con botón Subir Logo junto a emisora)
   * ====================================================================== */
  App.views['cotizacion-nueva'] = {
    async render(container, params) {
      this.state = { container };
      container.innerHTML = `
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:18px;color:#2c3e50;">Nueva Cotización</h2>
            <button class="btn btn-secondary" onclick="App.navigate('/cotizaciones')"><i class="bi bi-arrow-left"></i> Volver</button>
          </div>
          <div style="padding:18px;" id="cotFormWrap">
            <div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando catálogos...</p></div>
          </div>
        </div>
      `;

      const cat = await cargarCatalogos();
      if (!cat) {
        container.querySelector('#cotFormWrap').innerHTML = '<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__title">Error</div><div class="empty-state__desc">No se pudieron cargar los catálogos. Verifique que el endpoint <code>/api/v1/cotizaciones_catalogos</code> funcione.</div></div>';
        return;
      }
      this.renderForm(cat);
    },

    renderForm(cat) {
      const c = this.state.container;
      const usr = App.state.user || {};
      c.querySelector('#cotFormWrap').innerHTML = `
        <form id="formCotizacion" onsubmit="return false;">

          <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Cliente y tipo</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
              <div>
                <label style="font-size:11px;color:#95a5a6;">Tipo de cotización *</label>
                <select class="form-select" id="id_tipo" required>
                  ${cat.tipos.map(t => `<option value="${t.id_tipo}">${App.escapeHtml(t.nombre)} (${App.escapeHtml(t.prefijo_codigo)})</option>`).join('')}
                </select>
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Empresa emisora *</label>
                <div style="display:flex;gap:6px;">
                  <select class="form-select" id="id_emisora" required style="flex:1;">
                    ${cat.emisoras.map(e => `<option value="${e.id_emisora}" data-nombre="${App.escapeHtml(e.razon_social)}" data-tiene-logo="${e.tiene_logo || 0}">${App.escapeHtml(e.razon_social)} (RUC: ${App.escapeHtml(e.ruc)})${e.tiene_logo ? ' ✓' : ' ⚠ sin logo'}</option>`).join('')}
                  </select>
                  <button type="button" class="btn btn-warning" id="btnSubirLogoForm" title="Subir / gestionar logo de esta emisora" style="background:#ffc107;border-color:#ffc107;white-space:nowrap;"><i class="bi bi-image"></i> Logo</button>
                </div>
                <div id="logoStatus" style="font-size:10px;margin-top:3px;color:#95a5a6;"></div>
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Empresa cliente *</label>
                <select class="form-select" id="id_empresa" required>
                  <option value="">Seleccione...</option>
                </select>
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Sucursal</label>
                <select class="form-select" id="id_sucursal"><option value="">(sin sucursal)</option></select>
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Contacto</label>
                <select class="form-select" id="id_empresa_contacto"><option value="">(sin contacto)</option></select>
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Vendedor</label>
                <input type="text" class="form-input" id="vendedor_nombre" value="${App.escapeHtml(usr.nombre || '')}" disabled>
              </div>
            </div>
          </fieldset>

          <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Condiciones comerciales</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
              <div><label style="font-size:11px;color:#95a5a6;">Moneda</label>
                <select class="form-select" id="moneda">
                  <option value="PEN">Soles (PEN)</option>
                  <option value="USD">Dólares (USD)</option>
                  <option value="EUR">Euros (EUR)</option>
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">IGV %</label>
                <input type="number" step="0.01" class="form-input" id="igv_pct" value="18.00"></div>
              <div><label style="font-size:11px;color:#95a5a6;">Descuento global %</label>
                <input type="number" step="0.01" class="form-input" id="descuento_global_pct" value="0.00"></div>
              <div><label style="font-size:11px;color:#95a5a6;">Forma de pago</label>
                <select class="form-select" id="id_forma_pago">
                  <option value="">(sin especificar)</option>
                  ${cat.formas_pago.map(f => `<option value="${f.id_forma_pago}">${App.escapeHtml(f.descripcion)}</option>`).join('')}
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">Garantía</label>
                <select class="form-select" id="id_garantia">
                  <option value="">(sin especificar)</option>
                  ${cat.garantias.map(g => `<option value="${g.id_garantia}">${App.escapeHtml(g.descripcion)}</option>`).join('')}
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">Plazo de entrega</label>
                <select class="form-select" id="id_plazo_entrega">
                  <option value="">(sin especificar)</option>
                  ${cat.plazos.map(p => `<option value="${p.id_plazo}">${App.escapeHtml(p.descripcion)}</option>`).join('')}
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">Validez</label>
                <select class="form-select" id="id_validez">
                  <option value="">(sin especificar)</option>
                  ${cat.valideces.map(v => `<option value="${v.id_validez}">${App.escapeHtml(v.descripcion)}</option>`).join('')}
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">Zona de pricing</label>
                <select class="form-select" id="id_zona_pricing">
                  <option value="">(sin especificar)</option>
                  ${cat.zonas.map(z => `<option value="${z.id_zona}">${App.escapeHtml(z.nombre)}</option>`).join('')}
                </select></div>
              <div><label style="font-size:11px;color:#95a5a6;">Cuenta bancaria principal</label>
                <select class="form-select" id="id_cuenta_bancaria">
                  <option value="">(sin especificar)</option>
                  ${cat.cuentas.map(cu => `<option value="${cu.id_cuenta}">${App.escapeHtml(cu.banco + ' - ' + cu.numero_cuenta)}</option>`).join('')}
                </select></div>
            </div>
          </fieldset>

          <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Información del documento</legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
              <div style="grid-column:span 2;">
                <label style="font-size:11px;color:#95a5a6;">Título del documento</label>
                <input type="text" class="form-input" id="titulo_documento" placeholder="COTIZACIÓN, PROPUESTA, etc. (dejar vacío = usa el del tipo)">
              </div>
              <div style="grid-column:span 2;">
                <label style="font-size:11px;color:#95a5a6;">Motivo</label>
                <input type="text" class="form-input" id="motivo" placeholder="Breve descripción del motivo de la cotización">
              </div>
              <div style="grid-column:span 2;">
                <label style="font-size:11px;color:#95a5a6;">Observaciones</label>
                <textarea class="form-input" id="observaciones" rows="3" placeholder="Notas internas, condiciones especiales..."></textarea>
              </div>
            </div>
          </fieldset>

          <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Detalle de items</legend>
            <table class="table" style="width:100%;font-size:12px;">
              <thead>
                <tr style="background:#f9fafc;">
                  <th style="padding:6px;text-align:left;width:30px;">#</th>
                  <th style="padding:6px;text-align:left;">Descripción</th>
                  <th style="padding:6px;text-align:right;width:70px;">Cant.</th>
                  <th style="padding:6px;text-align:right;width:100px;">P. Unit.</th>
                  <th style="padding:6px;text-align:right;width:70px;">Desc.%</th>
                  <th style="padding:6px;text-align:right;width:110px;">Subtotal</th>
                  <th style="padding:6px;width:30px;"></th>
                </tr>
              </thead>
              <tbody id="detalleBody"></tbody>
              <tfoot>
                <tr>
                  <td colspan="7" style="padding:8px;text-align:right;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="App.views['cotizacion-nueva'].addItem()"><i class="bi bi-plus"></i> Agregar item</button>
                  </td>
                </tr>
              </tfoot>
            </table>
          </fieldset>

          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
            <button type="button" class="btn btn-secondary" onclick="App.navigate('/cotizaciones')">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btnGuardarCot"><i class="bi bi-save"></i> Guardar Borrador</button>
          </div>
        </form>
      `;

      // Cargar empresas
      this.cargarEmpresas();

      // Item inicial
      this.addItem();

      // Event listeners
      c.querySelector('#btnGuardarCot').onclick = () => this.guardar();
      c.querySelector('#id_empresa').onchange = () => this.cargarSucursalesYContactos();

      // Botón Subir Logo (junto al select de emisora)
      c.querySelector('#btnSubirLogoForm').onclick = () => {
        const sel = c.querySelector('#id_emisora');
        const idEmi = parseInt(sel.value, 10);
        const opt = sel.options[sel.selectedIndex];
        const nombre = opt ? opt.dataset.nombre : '';
        if (!idEmi) { App.toast('Seleccione una emisora', 'warning'); return; }
        modalSubirLogo(idEmi, nombre);
      };

      // Al cambiar la emisora, actualizar status de logo
      this.actualizarLogoStatus();
      c.querySelector('#id_emisora').onchange = () => this.actualizarLogoStatus();
    },

    actualizarLogoStatus() {
      const sel = this.state.container.querySelector('#id_emisora');
      const status = this.state.container.querySelector('#logoStatus');
      const opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      const tieneLogo = opt.dataset.tieneLogo === '1';
      if (tieneLogo) {
        status.innerHTML = '✓ <span style="color:#28a745;">Esta emisora tiene logo cargado.</span>';
      } else {
        status.innerHTML = '⚠ <span style="color:#dc3545;">Esta emisora NO tiene logo. Haga clic en el botón "Logo" para subirlo.</span>';
      }
    },

    async cargarEmpresas() {
      try {
        const r = await App.request('/empresas?per_page=200');
        if (r.ok && r.data) {
          const sel = this.state.container.querySelector('#id_empresa');
          sel.innerHTML = '<option value="">Seleccione...</option>' +
            r.data.map(e => `<option value="${e.id_empresa}">${App.escapeHtml(e.razon_social)} (RUC: ${App.escapeHtml(e.ruc)})</option>`).join('');
        }
      } catch (e) { /* ignore */ }
    },

    async cargarSucursalesYContactos() {
      const idEmp = this.state.container.querySelector('#id_empresa').value;
      const selSuc = this.state.container.querySelector('#id_sucursal');
      const selCon = this.state.container.querySelector('#id_empresa_contacto');
      selSuc.innerHTML = '<option value="">(sin sucursal)</option>';
      selCon.innerHTML = '<option value="">(sin contacto)</option>';
      if (!idEmp) return;
      try {
        const r = await App.request('/sucursales?id_empresa=' + idEmp);
        if (r.ok && r.data) {
          r.data.forEach(s => {
            const o = document.createElement('option');
            o.value = s.id_sucursal; o.textContent = s.nombre;
            selSuc.appendChild(o);
          });
        }
        const rc = await App.request('/contactos?id_empresa=' + idEmp);
        if (rc.ok && rc.data) {
          rc.data.forEach(ct => {
            const o = document.createElement('option');
            o.value = ct.id_empresa_contacto;
            o.textContent = `${ct.nombres || ''} ${ct.apellido_paterno || ''} (${ct.cargo || '—'})`;
            selCon.appendChild(o);
          });
        }
      } catch (e) { /* ignore */ }
    },

    itemIdx: 0,
    addItem(prod = null) {
      const body = this.state.container.querySelector('#detalleBody');
      const idx = ++this.itemIdx;
      const tr = document.createElement('tr');
      tr.dataset.idx = idx;
      tr.innerHTML = `
        <td style="padding:6px;">${idx}</td>
        <td style="padding:6px;">
          <input type="text" class="form-input detalle-desc" placeholder="Descripción del producto/servicio" value="${App.escapeHtml(prod?.descripcion || '')}" style="width:100%;">
        </td>
        <td style="padding:6px;"><input type="number" step="0.01" class="form-input detalle-cant" value="${prod?.cantidad || 1}" style="width:70px;text-align:right;"></td>
        <td style="padding:6px;"><input type="number" step="0.01" class="form-input detalle-precio" value="${prod?.precio_unitario || 0}" style="width:100px;text-align:right;"></td>
        <td style="padding:6px;"><input type="number" step="0.01" class="form-input detalle-desc-pct" value="${prod?.descuento_pct || 0}" style="width:70px;text-align:right;"></td>
        <td style="padding:6px;text-align:right;font-weight:600;" class="detalle-subtotal">0.00</td>
        <td style="padding:6px;text-align:center;">
          <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button>
        </td>
      `;
      body.appendChild(tr);
      const calc = () => {
        const c = parseFloat(tr.querySelector('.detalle-cant').value) || 0;
        const p = parseFloat(tr.querySelector('.detalle-precio').value) || 0;
        const d = parseFloat(tr.querySelector('.detalle-desc-pct').value) || 0;
        const sub = c * p * (1 - d / 100);
        tr.querySelector('.detalle-subtotal').textContent = sub.toFixed(2);
      };
      tr.querySelectorAll('input').forEach(i => i.oninput = calc);
      calc();
    },

    obtenerDetalle() {
      const rows = this.state.container.querySelectorAll('#detalleBody tr');
      const detalle = [];
      rows.forEach(tr => {
        const desc = tr.querySelector('.detalle-desc').value.trim();
        if (!desc) return;
        const cant = parseFloat(tr.querySelector('.detalle-cant').value) || 0;
        const prec = parseFloat(tr.querySelector('.detalle-precio').value) || 0;
        const descPct = parseFloat(tr.querySelector('.detalle-desc-pct').value) || 0;
        detalle.push({
          descripcion: desc,
          cantidad: cant,
          precio_unitario: prec,
          descuento_pct: descPct,
          unidad_medida: 'UND',
          afecto_igv: 1,
        });
      });
      return detalle;
    },

    async guardar() {
      const c = this.state.container;
      const usr = App.state.user || {};
      const body = {
        id_tipo: parseInt(c.querySelector('#id_tipo').value, 10),
        id_emisora: parseInt(c.querySelector('#id_emisora').value, 10),
        id_empresa: parseInt(c.querySelector('#id_empresa').value, 10),
        id_sucursal: c.querySelector('#id_sucursal').value ? parseInt(c.querySelector('#id_sucursal').value, 10) : null,
        id_empresa_contacto: c.querySelector('#id_empresa_contacto').value ? parseInt(c.querySelector('#id_empresa_contacto').value, 10) : null,
        id_vendedor: usr.id_persona || null,
        id_usuario_creador: usr.id_persona,
        id_forma_pago: c.querySelector('#id_forma_pago').value ? parseInt(c.querySelector('#id_forma_pago').value, 10) : null,
        id_garantia: c.querySelector('#id_garantia').value ? parseInt(c.querySelector('#id_garantia').value, 10) : null,
        id_plazo_entrega: c.querySelector('#id_plazo_entrega').value ? parseInt(c.querySelector('#id_plazo_entrega').value, 10) : null,
        id_validez: c.querySelector('#id_validez').value ? parseInt(c.querySelector('#id_validez').value, 10) : null,
        id_zona_pricing: c.querySelector('#id_zona_pricing').value ? parseInt(c.querySelector('#id_zona_pricing').value, 10) : null,
        id_cuenta_bancaria: c.querySelector('#id_cuenta_bancaria').value ? parseInt(c.querySelector('#id_cuenta_bancaria').value, 10) : null,
        titulo_documento: c.querySelector('#titulo_documento').value || null,
        motivo: c.querySelector('#motivo').value || null,
        observaciones: c.querySelector('#observaciones').value || null,
        moneda: c.querySelector('#moneda').value,
        igv_pct: parseFloat(c.querySelector('#igv_pct').value) || 18.00,
        descuento_global_pct: parseFloat(c.querySelector('#descuento_global_pct').value) || 0.00,
        detalle: this.obtenerDetalle(),
      };

      if (!body.id_empresa) { App.toast('Seleccione la empresa cliente', 'warning'); return; }
      if (body.detalle.length === 0) { App.toast('Agregue al menos un item al detalle', 'warning'); return; }

      const btn = c.querySelector('#btnGuardarCot');
      btn.disabled = true; btn.textContent = 'Guardando...';

      try {
        const r = await App.request('/cotizaciones', { method: 'POST', body });
        if (r.ok) {
          App.toast('Cotización creada: ' + r.data.codigo, 'success');
          App.navigate('/cotizacion-detalle/' + r.data.id_cotizacion);
        } else {
          App.toast('Error: ' + (r.message || 'desconocido'), 'error', 8000);
        }
      } catch (e) {
        App.toast('Error inesperado: ' + e.message, 'error');
      } finally {
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-save"></i> Guardar Borrador';
      }
    },
  };

  /* ======================================================================
   *  VISTA: Detalle de cotización
   * ====================================================================== */
  App.views['cotizacion-detalle'] = {
    async render(container, params) {
      this.state = { container };
      const idCot = parseInt(params[0], 10);
      if (!idCot || isNaN(idCot)) {
        container.innerHTML = `<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__title">ID inválido</div><div class="empty-state__desc">No se especificó un ID de cotización válido.</div>
          <button class="btn btn-primary" onclick="App.navigate('/cotizaciones')">Volver al listado</button></div>`;
        return;
      }
      this.state.idCot = idCot;

      container.innerHTML = '<div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando cotización...</p></div>';

      const r = await App.request('/cotizaciones/' + idCot);
      if (!r.ok) {
        container.innerHTML = `<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__title">No se pudo cargar</div><div class="empty-state__desc">${App.escapeHtml(r.message || '')}</div>
          <button class="btn btn-primary" onclick="App.navigate('/cotizaciones')">Volver</button></div>`;
        return;
      }
      this.renderDetalle(r.data);
    },

    renderDetalle(data) {
      const c = this.state.container;
      const cab = data.cabecera;
      const det = data.detalle || [];
      const hist = data.historial || [];
      const coms = data.comunicaciones || [];
      const docs = data.documentos || [];

      c.innerHTML = `
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
              <h2 style="margin:0;font-size:18px;color:#2c3e50;">${App.escapeHtml(cab.codigo)} ${badgeEstado(cab.estado)}</h2>
              <div style="font-size:12px;color:#95a5a6;margin-top:2px;">v${cab.version_numero} · Creada ${fmtFecha(cab.fecha_creacion)} · ${App.escapeHtml(cab.empresa_razon_social || '')}</div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <button class="btn btn-secondary" onclick="App.navigate('/cotizaciones')"><i class="bi bi-arrow-left"></i> Volver</button>
              <button class="btn btn-secondary" id="btnCambiarEstado"><i class="bi bi-arrow-repeat"></i> Cambiar Estado</button>
              <button class="btn btn-secondary" id="btnComunicacion"><i class="bi bi-chat-dots"></i> Comunicación</button>
              <button class="btn btn-secondary" id="btnDocumentos"><i class="bi bi-paperclip"></i> Documentos (${docs.length})</button>
              <button class="btn btn-warning" id="btnSubirLogoDet" title="Subir logo de la emisora" style="background:#ffc107;border-color:#ffc107;"><i class="bi bi-image"></i> Subir Logo</button>
              <button class="btn btn-primary" id="btnImprimir"><i class="bi bi-printer"></i> Imprimir / PDF</button>
            </div>
          </div>

          <div style="padding:18px;">
            <!-- Resumen -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-bottom:18px;">
              <div style="background:#f9fafc;padding:14px;border-radius:6px;border-left:3px solid #007bff;">
                <div style="font-size:11px;text-transform:uppercase;color:#95a5a6;letter-spacing:.5px;">Cliente</div>
                <div style="font-weight:600;color:#2c3e50;margin-top:2px;">${App.escapeHtml(cab.empresa_razon_social || '—')}</div>
                <div style="font-size:11px;color:#555;margin-top:4px;">RUC: ${App.escapeHtml(cab.empresa_ruc || '—')}</div>
                ${cab.contacto_nombres ? `<div style="font-size:11px;color:#555;">Contacto: ${App.escapeHtml(cab.contacto_nombres + ' ' + (cab.contacto_apellidos || ''))}</div>` : ''}
                ${cab.contacto_correo ? `<div style="font-size:11px;color:#555;">📧 ${App.escapeHtml(cab.contacto_correo)}</div>` : ''}
              </div>
              <div style="background:#f9fafc;padding:14px;border-radius:6px;border-left:3px solid #28a745;">
                <div style="font-size:11px;text-transform:uppercase;color:#95a5a6;letter-spacing:.5px;">Empresa emisora</div>
                <div style="font-weight:600;color:#2c3e50;margin-top:2px;">${App.escapeHtml(cab.emisora_razon_social || '—')}</div>
                <div style="font-size:11px;color:#555;">RUC: ${App.escapeHtml(cab.emisora_ruc || '—')}</div>
                ${cab.emisora_telefono ? `<div style="font-size:11px;color:#555;">📞 ${App.escapeHtml(cab.emisora_telefono)}</div>` : ''}
                ${cab.emisora_correo ? `<div style="font-size:11px;color:#555;">📧 ${App.escapeHtml(cab.emisora_correo)}</div>` : ''}
                ${cab.emisora_logo
                  ? `<div style="margin-top:6px;"><img src="${cab.emisora_logo}" style="max-height:50px;max-width:150px;object-fit:contain;" alt="logo"></div>`
                  : `<div style="margin-top:6px;padding:6px 10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:10px;color:#856404;cursor:pointer;" onclick="App.views['cotizacion-detalle'].subirLogoEmisora(${cab.id_emisora}, '${App.escapeHtml(cab.emisora_razon_social).replace(/'/g, "\\'")}')">⚠ Sin logo cargado. Haga clic aquí para subirlo.</div>`}
              </div>
              <div style="background:#f9fafc;padding:14px;border-radius:6px;border-left:3px solid #ffc107;">
                <div style="font-size:11px;text-transform:uppercase;color:#95a5a6;letter-spacing:.5px;">Condiciones</div>
                <div style="font-size:11px;color:#2c3e50;margin-top:2px;">
                  <strong>Moneda:</strong> ${App.escapeHtml(cab.moneda)} · <strong>IGV:</strong> ${Number(cab.igv_pct).toFixed(2)}%<br>
                  ${cab.snapshot_forma_pago ? `<strong>Forma de pago:</strong> ${App.escapeHtml(cab.snapshot_forma_pago)}<br>` : ''}
                  ${cab.snapshot_validez ? `<strong>Validez:</strong> ${App.escapeHtml(cab.snapshot_validez)}<br>` : ''}
                  ${cab.snapshot_plazo_entrega ? `<strong>Plazo:</strong> ${App.escapeHtml(cab.snapshot_plazo_entrega)}<br>` : ''}
                  ${cab.snapshot_garantia ? `<strong>Garantía:</strong> ${App.escapeHtml(cab.snapshot_garantia)}<br>` : ''}
                </div>
              </div>
            </div>

            <!-- Detalle -->
            <h3 style="font-size:14px;color:#2c3e50;margin:18px 0 8px;">Detalle</h3>
            <table class="table" style="width:100%;font-size:12px;border-collapse:collapse;">
              <thead>
                <tr style="background:#2c3e50;color:#fff;">
                  <th style="padding:8px;text-align:left;width:30px;">#</th>
                  <th style="padding:8px;text-align:left;">Descripción</th>
                  <th style="padding:8px;text-align:right;width:70px;">Cant.</th>
                  <th style="padding:8px;text-align:right;width:100px;">P. Unit.</th>
                  <th style="padding:8px;text-align:right;width:60px;">Desc.%</th>
                  <th style="padding:8px;text-align:right;width:110px;">Subtotal</th>
                </tr>
              </thead>
              <tbody>
                ${det.length ? det.map((d, i) => `
                  <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px;">${i + 1}</td>
                    <td style="padding:8px;">${App.escapeHtml(d.descripcion || '')}<div style="font-size:10px;color:#95a5a6;">${App.escapeHtml(d.codigo || '')} · ${App.escapeHtml(d.unidad_medida || '')}</div></td>
                    <td style="padding:8px;text-align:right;">${Number(d.cantidad).toFixed(2)}</td>
                    <td style="padding:8px;text-align:right;">${fmtMoneda(d.precio_unitario, cab.moneda)}</td>
                    <td style="padding:8px;text-align:right;">${Number(d.descuento_pct).toFixed(2)}</td>
                    <td style="padding:8px;text-align:right;font-weight:600;">${fmtMoneda(d.subtotal, cab.moneda)}</td>
                  </tr>`).join('') : '<tr><td colspan="6" style="padding:18px;text-align:center;color:#95a5a6;">Sin items</td></tr>'}
              </tbody>
            </table>

            <!-- Totales -->
            <div style="display:flex;justify-content:flex-end;margin-top:14px;">
              <div style="width:300px;border-top:2px solid #2c3e50;padding-top:10px;">
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#555;"><span>Subtotal</span><span>${fmtMoneda(cab.subtotal, cab.moneda)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#555;"><span>Descuento global (${Number(cab.descuento_global_pct).toFixed(2)}%)</span><span>- ${fmtMoneda(cab.descuento_global_monto, cab.moneda)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#555;"><span>Base imponible</span><span>${fmtMoneda(cab.base_imponible, cab.moneda)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;color:#555;"><span>IGV (${Number(cab.igv_pct).toFixed(2)}%)</span><span>${fmtMoneda(cab.igv_monto, cab.moneda)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:10px 0 0;border-top:2px solid #2c3e50;font-size:16px;font-weight:700;color:#2c3e50;"><span>TOTAL</span><span>${fmtMoneda(cab.total, cab.moneda)}</span></div>
              </div>
            </div>

            ${cab.observaciones ? `<div style="margin-top:18px;padding:10px 14px;background:#fff8e1;border-left:3px solid #ffc107;font-size:11px;"><strong>Observaciones:</strong><br>${App.escapeHtml(cab.observaciones)}</div>` : ''}

            <!-- Historial -->
            <h3 style="font-size:14px;color:#2c3e50;margin:24px 0 8px;">Historial de estados</h3>
            <div style="font-size:12px;">
              ${hist.length ? hist.map(h => `
                <div style="padding:6px 0;border-bottom:1px dashed #f0f0f0;">
                  <span style="display:inline-block;width:160px;color:#7f8c8d;">${fmtFechaHora(h.fecha_cambio)}</span>
                  ${h.estado_anterior ? `<span style="color:#95a5a6;">${App.escapeHtml(h.estado_anterior)} → </span>` : ''}
                  <strong style="color:#2c3e50;">${App.escapeHtml(h.estado_nuevo)}</strong>
                  <span style="color:#555;"> · ${App.escapeHtml(h.motivo || '')}</span>
                  ${h.usuario_nombre ? `<span style="color:#95a5a6;"> · ${App.escapeHtml(h.usuario_nombre)}</span>` : ''}
                </div>`).join('') : '<div style="color:#95a5a6;padding:6px 0;">Sin historial</div>'}
            </div>
          </div>
        </div>
      `;

      // Bind acciones
      c.querySelector('#btnImprimir').onclick = () => {
        window.open(App.config.apiBase + '/cotizaciones_print/' + this.state.idCot, '_blank');
      };
      c.querySelector('#btnSubirLogoDet').onclick = () => {
        this.subirLogoEmisora(cab.id_emisora, cab.emisora_razon_social);
      };
      c.querySelector('#btnCambiarEstado').onclick = () => this.modalCambiarEstado(cab);
      c.querySelector('#btnComunicacion').onclick = () => this.modalComunicacion();
      c.querySelector('#btnDocumentos').onclick = () => this.modalDocumentos(docs);
    },

    subirLogoEmisora(idEmisora, nombre) {
      modalSubirLogo(idEmisora, nombre);
    },

    modalCambiarEstado(cab) {
      const opciones = ['PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO','EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','CERRADA','BORRADOR']
        .filter(e => e !== cab.estado);
      const body = `
        <div style="font-size:12px;margin-bottom:10px;">Estado actual: ${badgeEstado(cab.estado)}</div>
        <label style="font-size:11px;color:#95a5a6;">Nuevo estado *</label>
        <select class="form-select" id="nuevoEstado" style="width:100%;margin-bottom:10px;">
          ${opciones.map(e => `<option value="${e}">${e}</option>`).join('')}
        </select>
        <label style="font-size:11px;color:#95a5a6;">Motivo</label>
        <textarea class="form-input" id="motivoEstado" rows="3" style="width:100%;" placeholder="Motivo del cambio de estado"></textarea>
      `;
      const footer = `
        <button class="btn btn-secondary" data-modal-close>Cancelar</button>
        <button class="btn btn-primary" id="btnConfirmarEstado">Aplicar</button>
      `;
      App.showModal('Cambiar estado', body, footer);
      document.getElementById('btnConfirmarEstado').onclick = async () => {
        const nuevo = document.getElementById('nuevoEstado').value;
        const motivo = document.getElementById('motivoEstado').value;
        const r = await App.request('/cotizaciones/' + this.state.idCot + '/status', {
          method: 'PATCH', body: { nuevo_estado: nuevo, motivo, id_usuario: App.state.user.id_persona }
        });
        if (r.ok) {
          App.toast('Estado actualizado a ' + nuevo, 'success');
          App.closeModal();
          this.render(this.state.container, [this.state.idCot]);
        } else {
          App.toast('Error: ' + (r.message || ''), 'error');
        }
      };
    },

    modalComunicacion() {
      const body = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <div><label style="font-size:11px;color:#95a5a6;">Medio *</label>
            <select class="form-select" id="medioCom" style="width:100%;">
              <option value="EMAIL">Email</option>
              <option value="TELEFONO">Teléfono</option>
              <option value="WHATSAPP">WhatsApp</option>
              <option value="PRESENCIAL">Presencial</option>
              <option value="ZOOM">Zoom/Meet</option>
              <option value="OTRO">Otro</option>
            </select></div>
          <div><label style="font-size:11px;color:#95a5a6;">Tipo *</label>
            <select class="form-select" id="tipoCom" style="width:100%;">
              <option value="SEGUIMIENTO">Seguimiento</option>
              <option value="ENVIO">Envío de cotización</option>
              <option value="REUNION">Reunión</option>
              <option value="PROPUESTA">Propuesta</option>
              <option value="NEGOCIACION">Negociación</option>
              <option value="RECHAZO_CLIENTE">Rechazo del cliente</option>
              <option value="APROBACION_CLIENTE">Aprobación del cliente</option>
              <option value="OTRO">Otro</option>
            </select></div>
        </div>
        <label style="font-size:11px;color:#95a5a6;">Descripción *</label>
        <textarea class="form-input" id="descCom" rows="3" style="width:100%;" placeholder="Resumen de la comunicación..."></textarea>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
          <div><label style="font-size:11px;color:#95a5a6;">Próximo seguimiento</label>
            <input type="date" class="form-input" id="fechaProx" style="width:100%;"></div>
          <div><label style="font-size:11px;color:#95a5a6;">Fecha comunicación</label>
            <input type="date" class="form-input" id="fechaCom" style="width:100%;"></div>
        </div>
      `;
      const footer = `
        <button class="btn btn-secondary" data-modal-close>Cancelar</button>
        <button class="btn btn-primary" id="btnConfirmarCom">Registrar</button>
      `;
      App.showModal('Registrar comunicación', body, footer);
      document.getElementById('btnConfirmarCom').onclick = async () => {
        const r = await App.request('/cotizaciones/' + this.state.idCot + '/comunicacion', {
          method: 'POST',
          body: {
            medio: document.getElementById('medioCom').value,
            tipo_comunicacion: document.getElementById('tipoCom').value,
            descripcion: document.getElementById('descCom').value,
            fecha_proximo_seguimiento: document.getElementById('fechaProx').value || null,
            fecha_comunicacion: document.getElementById('fechaCom').value || null,
            id_usuario: App.state.user.id_persona,
          }
        });
        if (r.ok) {
          App.toast('Comunicación registrada', 'success');
          App.closeModal();
          this.render(this.state.container, [this.state.idCot]);
        } else {
          App.toast('Error: ' + (r.message || ''), 'error');
        }
      };
    },

    modalDocumentos(docs) {
      const body = `
        <div style="margin-bottom:10px;padding:10px;background:#f9fafc;border-radius:4px;">
          <label style="display:block;font-size:11px;color:#95a5a6;margin-bottom:4px;">Subir nuevo documento (máx 10 MB)</label>
          <input type="file" id="fileDoc" class="form-input" style="margin-bottom:6px;">
          <div style="display:flex;gap:6px;align-items:center;">
            <select id="tipoDoc" class="form-select" style="width:200px;">
              <option value="ADJUNTO_GENERAL">Adjunto general</option>
              <option value="COTIZACION_FIRMADA">Cotización firmada</option>
              <option value="ORDEN_COMPRA">Orden de compra</option>
              <option value="EVIDENCIA">Evidencia</option>
              <option value="OTRO">Otro</option>
            </select>
            <button class="btn btn-primary btn-sm" id="btnUploadDoc">Subir</button>
          </div>
        </div>
        <div id="docsList">
          ${docs.length ? docs.map(d => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid #f0f0f0;">
              <div>
                <div style="font-weight:600;font-size:12px;">${App.escapeHtml(d.nombre_original)}</div>
                <div style="font-size:10px;color:#95a5a6;">${App.escapeHtml(d.tipo_mime)} · ${fmtBytes(d.tamano_bytes)} · ${fmtFechaHora(d.fecha_registro)}</div>
              </div>
              <div style="display:flex;gap:4px;">
                <a href="${App.config.apiBase}/cotizaciones_documentos/${this.state.idCot}/${d.id_documento}" target="_blank" class="btn btn-sm btn-secondary">Descargar</a>
                <button class="btn btn-sm btn-danger" onclick="App.views['cotizacion-detalle'].borrarDoc(${d.id_documento}, '${App.escapeHtml(d.nombre_original).replace(/'/g, "\\'")}')"><i class="bi bi-trash"></i></button>
              </div>
            </div>
          `).join('') : '<div style="padding:18px;text-align:center;color:#95a5a6;">Sin documentos</div>'}
        </div>
      `;
      App.showModal('Documentos de la cotización', body, '');
      document.getElementById('btnUploadDoc').onclick = async () => {
        const fileInput = document.getElementById('fileDoc');
        if (!fileInput.files.length) { App.toast('Seleccione un archivo', 'warning'); return; }
        const file = fileInput.files[0];
        const formData = new FormData();
        formData.append('archivo', file);
        formData.append('tipo_documento', document.getElementById('tipoDoc').value);
        formData.append('id_usuario', App.state.user.id_persona);

        const r = await App.request('/cotizaciones_documentos/' + this.state.idCot, {
          method: 'POST',
          body: formData,
        });
        if (r.ok) {
          App.toast('Documento adjuntado', 'success');
          App.closeModal();
          this.render(this.state.container, [this.state.idCot]);
        } else {
          App.toast('Error: ' + (r.message || ''), 'error');
        }
      };
    },

    async borrarDoc(idDoc, nombre) {
      if (!confirm('¿Eliminar el documento "' + nombre + '"?')) return;
      const r = await App.request('/cotizaciones_documentos/' + this.state.idCot + '/' + idDoc, { method: 'DELETE' });
      if (r.ok) {
        App.toast('Documento eliminado', 'success');
        this.render(this.state.container, [this.state.idCot]);
      } else {
        App.toast('Error: ' + (r.message || ''), 'error');
      }
    },
  };

  /* ======================================================================
   *  VISTA: Empresas Emisoras (con gestión de logo)
   * ====================================================================== */
  App.views['cotizaciones-emisora'] = {
    async render(container, params) {
      this.state = { container };
      container.innerHTML = `
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:18px;color:#2c3e50;">Empresas Emisoras</h2>
            <div>
              <button class="btn btn-secondary" onclick="App.navigate('/cotizaciones')"><i class="bi bi-arrow-left"></i> Volver</button>
              <button class="btn btn-primary" id="btnNuevaEmisora"><i class="bi bi-plus-lg"></i> Nueva Emisora</button>
            </div>
          </div>
          <div id="emisoraList" style="padding:14px 18px;min-height:300px;">
            <div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando...</p></div>
          </div>
        </div>
      `;
      container.querySelector('#btnNuevaEmisora').onclick = () => this.modalForm(null);
      await this.load();
    },

    async load() {
      const r = await App.request('/cotizaciones_emisora');
      const wrap = this.state.container.querySelector('#emisoraList');
      if (!r.ok) {
        wrap.innerHTML = `<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__desc">${App.escapeHtml(r.message || '')}</div></div>`;
        return;
      }
      if (!r.data.length) {
        wrap.innerHTML = `<div class="empty-state" style="padding:40px;">
          <div class="empty-state__icon">🏢</div>
          <div class="empty-state__title">No hay empresas emisoras</div>
          <div class="empty-state__desc">Cree al menos una empresa emisora para poder generar cotizaciones.</div>
          <button class="btn btn-primary" onclick="App.views['cotizaciones-emisora'].modalForm(null)"><i class="bi bi-plus-lg"></i> Crear Emisora</button>
        </div>`;
        return;
      }
      wrap.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;">
          ${r.data.map(e => `
            <div style="border:1px solid #e0e0e0;border-radius:8px;padding:14px;background:#fff;">
              <div style="display:flex;align-items:flex-start;gap:12px;">
                <div style="width:80px;height:80px;border:1px dashed #ccc;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9fafc;">
                  ${e.tiene_logo
                    ? `<img src="${App.config.apiBase}/cotizaciones_emisora/${e.id_emisora}/logo?t=${Date.now()}" style="max-width:100%;max-height:100%;object-fit:contain;" alt="logo">`
                    : '<span style="font-size:10px;color:#95a5a6;text-align:center;">Sin logo</span>'}
                </div>
                <div style="flex:1;">
                  ${e.es_principal ? '<span style="display:inline-block;padding:1px 6px;background:#28a745;color:#fff;border-radius:3px;font-size:9px;font-weight:600;margin-bottom:4px;">PRINCIPAL</span>' : ''}
                  <div style="font-weight:600;color:#2c3e50;font-size:13px;">${App.escapeHtml(e.razon_social)}</div>
                  ${e.nombre_comercial ? `<div style="font-size:11px;color:#7f8c8d;">${App.escapeHtml(e.nombre_comercial)}</div>` : ''}
                  <div style="font-size:11px;color:#555;margin-top:4px;">RUC: ${App.escapeHtml(e.ruc)}</div>
                  ${e.telefono ? `<div style="font-size:11px;color:#555;">📞 ${App.escapeHtml(e.telefono)}</div>` : ''}
                  ${e.correo ? `<div style="font-size:11px;color:#555;">📧 ${App.escapeHtml(e.correo)}</div>` : ''}
                </div>
              </div>
              <div style="display:flex;gap:4px;margin-top:12px;border-top:1px solid #f0f0f0;padding-top:8px;flex-wrap:wrap;">
                <button class="btn btn-sm btn-warning" onclick="App.views['cotizaciones'].subirLogo(${e.id_emisora}, '${App.escapeHtml(e.razon_social).replace(/'/g, "\\'")}')"><i class="bi bi-image"></i> ${e.tiene_logo ? 'Cambiar logo' : '⚠ Subir logo'}</button>
                <button class="btn btn-sm btn-primary" onclick="App.views['cotizaciones-emisora'].modalForm(${e.id_emisora})"><i class="bi bi-pencil"></i> Editar</button>
                <button class="btn btn-sm btn-danger" onclick="App.views['cotizaciones-emisora'].desactivar(${e.id_emisora}, '${App.escapeHtml(e.razon_social).replace(/'/g, "\\'")}')"><i class="bi bi-archive"></i></button>
              </div>
            </div>
          `).join('')}
        </div>
      `;
    },

    modalForm(idEmisora) {
      const esNuevo = !idEmisora;
      let data = {};
      const render = (data) => {
        const body = `
          <form id="formEmisora" onsubmit="return false;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
              <div style="grid-column:span 2;">
                <label style="font-size:11px;color:#95a5a6;">Razón social *</label>
                <input type="text" class="form-input" id="razon_social" value="${App.escapeHtml(data.razon_social || '')}" required style="width:100%;">
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Nombre comercial</label>
                <input type="text" class="form-input" id="nombre_comercial" value="${App.escapeHtml(data.nombre_comercial || '')}" style="width:100%;">
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">RUC *</label>
                <input type="text" class="form-input" id="ruc" value="${App.escapeHtml(data.ruc || '')}" required style="width:100%;" maxlength="20">
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Teléfono</label>
                <input type="text" class="form-input" id="telefono" value="${App.escapeHtml(data.telefono || '')}" style="width:100%;">
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Correo</label>
                <input type="email" class="form-input" id="correo" value="${App.escapeHtml(data.correo || '')}" style="width:100%;">
              </div>
              <div>
                <label style="font-size:11px;color:#95a5a6;">Web</label>
                <input type="text" class="form-input" id="web" value="${App.escapeHtml(data.web || '')}" style="width:100%;">
              </div>
              <div style="grid-column:span 2;">
                <label style="font-size:11px;color:#95a5a6;">Dirección</label>
                <input type="text" class="form-input" id="direccion" value="${App.escapeHtml(data.direccion || '')}" style="width:100%;">
              </div>
              <div style="display:flex;align-items:center;gap:14px;grid-column:span 2;">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;">
                  <input type="checkbox" id="es_principal" ${data.es_principal ? 'checked' : ''}>
                  Marcar como emisora principal
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;">
                  <input type="checkbox" id="activo" ${data.activo === null || data.activo ? 'checked' : ''}>
                  Activa
                </label>
              </div>
            </div>
          </form>
        `;
        const footer = `
          <button class="btn btn-secondary" data-modal-close>Cancelar</button>
          <button class="btn btn-primary" id="btnGuardarEmisora"><i class="bi bi-save"></i> ${esNuevo ? 'Crear' : 'Guardar'}</button>
        `;
        App.showModal(esNuevo ? 'Nueva empresa emisora' : 'Editar: ' + (data.razon_social || ''), body, footer);
        document.getElementById('btnGuardarEmisora').onclick = () => this.guardarForm(idEmisora);
      };

      if (esNuevo) {
        render({});
      } else {
        App.request('/cotizaciones_emisora/' + idEmisora).then(r => {
          render(r.ok ? r.data : {});
        });
      }
    },

    async guardarForm(idEmisora) {
      const body = {
        razon_social: document.getElementById('razon_social').value,
        nombre_comercial: document.getElementById('nombre_comercial').value || null,
        ruc: document.getElementById('ruc').value,
        telefono: document.getElementById('telefono').value || null,
        correo: document.getElementById('correo').value || null,
        web: document.getElementById('web').value || null,
        direccion: document.getElementById('direccion').value || null,
        es_principal: document.getElementById('es_principal').checked ? 1 : 0,
        activo: document.getElementById('activo').checked ? 1 : 0,
        id_usuario: App.state.user.id_persona,
      };
      if (!body.razon_social || !body.ruc) { App.toast('Razón social y RUC son obligatorios', 'warning'); return; }

      const url = idEmisora ? '/cotizaciones_emisora/' + idEmisora : '/cotizaciones_emisora';
      const method = idEmisora ? 'PUT' : 'POST';
      const r = await App.request(url, { method, body });
      if (r.ok) {
        App.toast(idEmisora ? 'Emisora actualizada' : 'Emisora creada', 'success');
        App.closeModal();
        // Invalidar caché de catálogos (porque emisoras cambiaron)
        _catalogosCache.data = null;
        this.load();
      } else {
        App.toast('Error: ' + (r.message || ''), 'error');
      }
    },

    async desactivar(idEmisora, nombre) {
      if (!confirm('¿Desactivar la empresa emisora "' + nombre + '"? Podrá reactivarla después.')) return;
      const r = await App.request('/cotizaciones_emisora/' + idEmisora, { method: 'DELETE' });
      if (r.ok) {
        App.toast('Emisora desactivada', 'success');
        _catalogosCache.data = null;
        this.load();
      } else {
        App.toast('Error: ' + (r.message || ''), 'error');
      }
    },
  };

  // Exponer helper global para subir logo
  App.modalSubirLogo = modalSubirLogo;

})();
