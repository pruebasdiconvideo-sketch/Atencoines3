/**
 * assets/js/cotizaciones_mejoras_v2.js
 * ----------------------------------------------------------------------------
 * VERSIÓN 2 — Patches con todas las correcciones solicitadas:
 *
 *   1. Mostrar 3 cuentas bancarias en UN SOLO bloque visual (no combo).
 *   2. Botón en Configuraciones para subir el logo.
 *   3. Solo 2 tipos de cotización: SERV (Soporte) y VENT (Ventas).
 *      (el script SQL los filtra en el backend, aquí ocultamos los demás).
 *   4. Al editar cotización, mostrar la info previamente registrada
 *      (no regenerar el código, no limpiar campos).
 *   5. Quitar Zona de Pricing del formulario.
 *   6. Filtro por empresa y búsqueda en la sección comercial.
 *
 * Cargar DESPUÉS de cotizaciones_v2.js, views.js y cotizaciones_mejoras.js:
 *   <script src="/assets/js/cotizaciones_mejoras.js"></script>
 *   <script src="/assets/js/cotizaciones_mejoras_v2.js"></script>
 */

(function () {
    'use strict';
    if (!window.App) {
        console.error('[cotizaciones_mejoras_v2] App no está cargado');
        return;
    }

    /* ================================================================
     * 1. PATCH: Formulario de Nueva Cotización
     *    - Banner de preview de código (sin EMP)
     *    - Quitar Zona Pricing
     *    - Mostrar 3 cuentas en un solo bloque visual (no combo)
     *    - Filtrar tipos: solo SERV y VENT
     * ================================================================*/
    function patchFormularioNuevaCotizacion() {
        if (!App.views || !App.views['cotizacion-nueva']) return;

        const originalRenderForm = App.views['cotizacion-nueva'].renderForm
            ? App.views['cotizacion-nueva'].renderForm.bind(App.views['cotizacion-nueva'])
            : null;

        if (!originalRenderForm) return;

        App.views['cotizacion-nueva'].renderForm = function (cat) {
            /* Filtrar tipos: solo SERV y VENT */
            const tiposFiltrados = (cat.tipos || []).filter(t =>
                t.codigo === 'SERV' || t.codigo === 'VENT' ||
                t.prefijo_codigo === 'SERV' || t.prefijo_codigo === 'VENT'
            );
            if (tiposFiltrados.length > 0) {
                cat = Object.assign({}, cat, { tipos: tiposFiltrados });
            }

            originalRenderForm(cat);

            const c = this.state.container;
            if (!c) return;

            /* 1.1. Quitar Zona Pricing del formulario */
            const zonaSelect = c.querySelector('#id_zona_pricing');
            if (zonaSelect) {
                const zonaDiv = zonaSelect.closest('div');
                if (zonaDiv) {
                    zonaDiv.style.display = 'none';
                    const label = zonaDiv.querySelector('label');
                    if (label) label.style.display = 'none';
                }
            }

            /* 1.2. Reemplazar combo de cuenta bancaria por un bloque visual
             *      con las 3 cuentas automáticas */
            const cuentaSelect = c.querySelector('#id_cuenta_bancaria');
            if (cuentaSelect) {
                const cuentaDiv = cuentaSelect.closest('div');
                if (cuentaDiv) {
                    const cuentasActivas = (cat.cuentas || []).filter(cu => cu.activo !== 0);
                    const cuentasTop3 = cuentasActivas.slice(0, 3);

                    /* Pre-seleccionar la primera como principal */
                    if (cuentasTop3.length > 0) {
                        cuentaSelect.value = cuentasTop3[0].id_cuenta;
                    }

                    /* Las otras 2 como adicionales automáticas */
                    this._cuentasAdicionalesAuto = cuentasTop3.slice(1).map(cu => cu.id_cuenta);

                    /* Construir bloque visual único */
                    const label = cuentaDiv.querySelector('label');
                    if (label) {
                        label.textContent = 'Cuentas bancarias (se mostrarán las 3 en el PDF automáticamente)';
                        label.style.fontWeight = '600';
                    }

                    /* Ocultar el combo pero mantenerlo para enviar el valor */
                    cuentaSelect.style.display = 'none';

                    /* Insertar bloque visual */
                    const bloqHtml = `
                        <div id="cuentasBloqueVisual" style="
                            margin-top:6px;padding:8px;background:#f8f9fa;
                            border:1px solid #dee2e6;border-radius:6px;">
                            <div style="font-size:11px;color:#2c3e50;font-weight:600;margin-bottom:6px;">
                                Cuentas que aparecerán en el PDF:
                            </div>
                            <div style="display:grid;gap:4px;">
                                ${cuentasTop3.map((cu, i) => `
                                    <div style="
                                        display:flex;justify-content:space-between;align-items:center;
                                        padding:6px 8px;background:#fff;border-radius:4px;
                                        border:1px solid #e9ecef;font-size:11px;">
                                        <div>
                                            <strong>${App.escapeHtml(cu.banco)}</strong>
                                            <div style="color:#7f8c8d;font-size:10px;">
                                                Cuenta: ${App.escapeHtml(cu.numero_cuenta)}
                                                ${cu.cci ? ' · CCI: ' + App.escapeHtml(cu.cci) : ''}
                                            </div>
                                        </div>
                                        <div style="text-align:right;">
                                            <span style="
                                                background:#28a745;color:#fff;padding:2px 6px;
                                                border-radius:10px;font-size:10px;font-weight:600;">
                                                ${cu.moneda || 'PEN'}
                                            </span>
                                            ${i === 0 ? '<div style="font-size:9px;color:#1976d2;margin-top:2px;">PRINCIPAL</div>' : ''}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                            ${cuentasActivas.length === 0 ? `
                                <div style="color:#dc3545;font-size:11px;text-align:center;padding:8px;">
                                    ⚠ Esta emisora no tiene cuentas bancarias configuradas.
                                </div>
                            ` : ''}
                        </div>`;

                    cuentaDiv.insertAdjacentHTML('beforeend', bloqHtml);
                }
            }

            /* 1.3. Añadir banner de previsualización de código (sin EMP) */
            const tipoSelect = c.querySelector('#id_tipo');
            const empresaSelect = c.querySelector('#id_empresa');
            const formWrap = c.querySelector('#cotFormWrap') || c.querySelector('form');

            if (formWrap && tipoSelect && empresaSelect) {
                /* Eliminar banner previo si existe (por si se renderiza 2 veces) */
                const existingBanner = formWrap.querySelector('#codigoPreviewBannerV2');
                if (existingBanner) existingBanner.remove();

                const firstFieldset = formWrap.querySelector('fieldset');
                if (firstFieldset) {
                    const banner = document.createElement('div');
                    banner.id = 'codigoPreviewBannerV2';
                    banner.style.cssText = 'padding:12px 16px;background:linear-gradient(to right,#e3f2fd,#f3f9ff);border-left:4px solid #1976d2;border-radius:6px;margin-bottom:14px;font-size:12px;';
                    banner.innerHTML = `
                        <strong style="font-size:10px;color:#2c3e50;text-transform:uppercase;letter-spacing:0.5px;">Código que se generará automáticamente:</strong>
                        <div id="codigoPreviewValueV2" style="font-family:Consolas,monospace;font-size:20px;font-weight:700;color:#1976d2;letter-spacing:1px;margin-top:4px;">
                            (Seleccione empresa para ver el código)
                        </div>
                        <div id="codigoPreviewWarningV2" style="font-size:11px;color:#f57c00;margin-top:4px;display:none;"></div>`;
                    firstFieldset.parentNode.insertBefore(banner, firstFieldset);

                    const updatePreview = async () => {
                        const idTipo = tipoSelect.value;
                        const idEmpresa = empresaSelect.value;
                        const valEl = banner.querySelector('#codigoPreviewValueV2');
                        const warnEl = banner.querySelector('#codigoPreviewWarningV2');
                        if (!idTipo || !idEmpresa) {
                            valEl.textContent = '(Seleccione empresa para ver el código)';
                            warnEl.style.display = 'none';
                            return;
                        }
                        try {
                            const r = await App.request(
                                `/cotizaciones/preview_codigo?id_empresa=${idEmpresa}&id_tipo=${idTipo}`);
                            if (r.data && r.data.codigo_proximo) {
                                valEl.textContent = r.data.codigo_proximo;
                                if (r.data.advertencia) {
                                    warnEl.textContent = '⚠ ' + r.data.advertencia;
                                    warnEl.style.display = 'block';
                                } else {
                                    warnEl.style.display = 'none';
                                }
                            } else if (r.data && r.data.advertencia) {
                                valEl.textContent = '(no disponible)';
                                warnEl.textContent = '⚠ ' + r.data.advertencia;
                                warnEl.style.display = 'block';
                            } else {
                                valEl.textContent = '(no disponible)';
                                warnEl.style.display = 'none';
                            }
                        } catch (e) {
                            valEl.textContent = '(error al obtener código)';
                            warnEl.style.display = 'none';
                        }
                    };

                    tipoSelect.addEventListener('change', updatePreview);
                    empresaSelect.addEventListener('change', () => {
                        setTimeout(updatePreview, 300);
                    });
                    setTimeout(updatePreview, 200);
                }
            }
        };

        /* Interceptar el guardar para enviar las cuentas adicionales y no zona_pricing */
        const originalGuardar = App.views['cotizacion-nueva'].guardar
            ? App.views['cotizacion-nueva'].guardar.bind(App.views['cotizacion-nueva'])
            : null;
        if (originalGuardar) {
            App.views['cotizacion-nueva'].guardar = async function () {
                if (this._cuentasAdicionalesAuto) {
                    const originalRequest = App.request;
                    const self = this;
                    App.request = async function (url, opts) {
                        if (url === '/cotizaciones' && opts && opts.method === 'POST' && opts.body) {
                            opts.body.cuentas_adicionales = self._cuentasAdicionalesAuto;
                            opts.body.cuentas_adicionales_json = JSON.stringify(self._cuentasAdicionalesAuto);
                            if (opts.body.id_zona_pricing === '' || opts.body.id_zona_pricing === null) {
                                delete opts.body.id_zona_pricing;
                            }
                        }
                        const r = await originalRequest.call(App, url, opts);
                        App.request = originalRequest;
                        return r;
                    };
                }
                return originalGuardar();
            };
        }
    }

    /* ================================================================
     * 2. PATCH: Vista de edición de cotización
     *    Mostrar SOLO la info previamente registrada (no regenerar).
     * ================================================================*/
    function patchVistaEditarCotizacion() {
        if (!App.views || !App.views['cotizacion-editar']) return;

        const originalRender = App.views['cotizacion-editar'].render
            ? App.views['cotizacion-editar'].render.bind(App.views['cotizacion-editar'])
            : null;
        if (!originalRender) return;

        /* Solo añadimos un mensaje claro arriba que diga "Modo edición:
         * los datos mostrados son los registrados originalmente." */
        App.views['cotizacion-editar'].render = async function (container, params) {
            await originalRender(container, params);

            /* Después de renderizar, añadir banner informativo */
            const banner = document.createElement('div');
            banner.style.cssText = 'padding:10px 14px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;margin-bottom:14px;font-size:12px;color:#856404;';
            banner.innerHTML = `
                <strong>Modo edición:</strong> Se muestra la información registrada originalmente.
                El código no se modifica. Los cambios se guardan al hacer clic en "Guardar".
            `;

            const firstChild = container.querySelector('.card') || container.firstChild;
            if (firstChild && firstChild.parentNode) {
                firstChild.parentNode.insertBefore(banner, firstChild);
            } else {
                container.insertBefore(banner, container.firstChild);
            }

            /* Ocultar Zona Pricing si está visible */
            const zonaSelect = container.querySelector('#id_zona_pricing, [name="id_zona_pricing"]');
            if (zonaSelect) {
                const zonaDiv = zonaSelect.closest('div');
                if (zonaDiv) zonaDiv.style.display = 'none';
            }

            /* Hacer el campo de código READ-ONLY (no se puede modificar) */
            const codigoInput = container.querySelector('#codigo, [name="codigo"]');
            if (codigoInput) {
                codigoInput.readOnly = true;
                codigoInput.style.backgroundColor = '#f5f5f5';
                codigoInput.style.color = '#7f8c8d';
            }
        };
    }

    /* ================================================================
     * 3. NUEVA VISTA: Configuración de Logo (en módulo Configuraciones)
     * ================================================================*/
    App.views['config-logo'] = {
        async render(container) {
            container.innerHTML = `
                <div class="card-header mb-4">
                    <h1 style="color:var(--c-primary)">
                        <i class="bi bi-image" aria-hidden="true"></i>
                        Configuración de Logo de Cotizaciones
                    </h1>
                    <p style="color:#7f8c8d;font-size:13px;margin:6px 0 0 0;">
                        Suba o reemplace el logo que aparecerá en el encabezado de las cotizaciones en PDF.
                    </p>
                </div>
                <div class="card" style="margin-bottom:14px;">
                    <div style="padding:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div style="flex:1;min-width:240px;">
                            <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">
                                Filtrar por empresa emisora
                            </label>
                            <input type="text" id="filtroEmisoraLogo"
                                   class="form-input"
                                   placeholder="Buscar por razón social o RUC..."
                                   style="width:100%;">
                        </div>
                        <button class="btn btn-primary" id="btnRecargarEmisoras">
                            <i class="bi bi-arrow-clockwise"></i> Recargar
                        </button>
                    </div>
                </div>
                <div class="card">
                    <div id="emisorasListContainer">
                        <div class="loading-container"><div class="spinner"></div></div>
                    </div>
                </div>`;

            document.getElementById('btnRecargarEmisoras').onclick = () => this.load();

            const input = document.getElementById('filtroEmisoraLogo');
            let timeoutId = null;
            input.addEventListener('input', (e) => {
                if (timeoutId) clearTimeout(timeoutId);
                timeoutId = setTimeout(() => this.renderList(e.target.value.trim()), 300);
            });

            await this.load();
        },

        async load() {
            try {
                const rows = await App.request('/cotizaciones_config_logo');
                this.allEmisoras = Array.isArray(rows.data) ? rows.data : [];
                this.renderList('');
            } catch (e) {
                document.getElementById('emisorasListContainer').innerHTML =
                    App.emptyState('⚠️', 'Error', e.message);
            }
        },

        renderList(filter) {
            const emisoras = (this.allEmisoras || []).filter(e => {
                if (!filter) return true;
                const q = filter.toLowerCase();
                return (e.razon_social || '').toLowerCase().includes(q)
                    || (e.ruc || '').toLowerCase().includes(q)
                    || (e.nombre_comercial || '').toLowerCase().includes(q);
            });

            const cont = document.getElementById('emisorasListContainer');
            if (emisoras.length === 0) {
                cont.innerHTML = App.emptyState('🔍', 'Sin resultados',
                    filter ? `No se encontraron emisoras que coincidan con "${App.escapeHtml(filter)}".`
                           : 'No hay emisoras configuradas.');
                return;
            }

            cont.innerHTML = `
                <table class="table" style="width:100%;font-size:13px;">
                    <thead>
                        <tr style="background:#f9fafc;">
                            <th style="padding:10px;text-align:left;">Empresa emisora</th>
                            <th style="padding:10px;text-align:left;">RUC</th>
                            <th style="padding:10px;text-align:center;">Estado logo</th>
                            <th style="padding:10px;text-align:center;">Última modificación</th>
                            <th style="padding:10px;text-align:right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${emisoras.map(e => `
                            <tr style="border-bottom:1px solid #ecf0f1;">
                                <td style="padding:10px;">
                                    <strong>${App.escapeHtml(e.razon_social)}</strong>
                                    ${e.es_principal ? '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:10px;font-size:9px;margin-left:6px;">PRINCIPAL</span>' : ''}
                                    ${e.nombre_comercial ? `<div style="font-size:11px;color:#7f8c8d;">${App.escapeHtml(e.nombre_comercial)}</div>` : ''}
                                </td>
                                <td style="padding:10px;">${App.escapeHtml(e.ruc || '—')}</td>
                                <td style="padding:10px;text-align:center;">
                                    ${e.tiene_logo
                                        ? '<span style="color:#28a745;font-weight:600;">✓ Tiene logo</span>'
                                        : '<span style="color:#dc3545;font-weight:600;">⚠ Sin logo</span>'}
                                </td>
                                <td style="padding:10px;text-align:center;font-size:11px;color:#7f8c8d;">
                                    ${App.escapeHtml(e.fecha_modificacion || '—')}
                                </td>
                                <td style="padding:10px;text-align:right;">
                                    <button class="btn btn-sm btn-primary"
                                            onclick="App.views['config-logo'].subirLogo(${e.id_emisora}, '${App.escapeHtml(e.razon_social)}')">
                                        <i class="bi bi-image"></i> ${e.tiene_logo ? 'Reemplazar' : 'Subir'} logo
                                    </button>
                                    ${e.tiene_logo ? `
                                        <button class="btn btn-sm btn-danger"
                                                onclick="App.views['config-logo'].eliminarLogo(${e.id_emisora})">
                                            <i class="bi bi-trash"></i> Eliminar
                                        </button>
                                    ` : ''}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;
        },

        async subirLogo(idEmisora, nombre) {
            /* Reutiliza el modal del logo mejorado */
            if (App.modalSubirLogoV2) {
                App.modalSubirLogoV2(idEmisora, nombre);
                /* Después de cerrar el modal, recargar la lista */
                const checkInterval = setInterval(() => {
                    const modal = document.querySelector('.modal-overlay, .modal');
                    if (!modal) {
                        clearInterval(checkInterval);
                        this.load();
                    }
                }, 1000);
            } else {
                /* Fallback: subir directo con prompt */
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/png,image/jpeg,image/svg+xml,image/gif,image/webp';
                input.onchange = async (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (file.size > 2 * 1024 * 1024) {
                        App.toast('El archivo excede 2 MB', 'error');
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = async (ev) => {
                        try {
                            await App.request(`/cotizaciones_config_logo/${idEmisora}`, {
                                method: 'POST',
                                body: { logo_base64: ev.target.result }
                            });
                            App.toast('Logo actualizado', 'success');
                            this.load();
                        } catch (err) {
                            App.toast('Error: ' + err.message, 'error');
                        }
                    };
                    reader.readAsDataURL(file);
                };
                input.click();
            }
        },

        async eliminarLogo(idEmisora) {
            if (!confirm('¿Eliminar el logo de esta emisora?')) return;
            try {
                await App.request(`/cotizaciones_config_logo/${idEmisora}`, { method: 'DELETE' });
                App.toast('Logo eliminado', 'success');
                this.load();
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        }
    };

    /* ================================================================
     * 4. NUEVA VISTA: Seguimiento comercial por empresa (con filtro)
     * ================================================================*/
    App.views['seguimiento-comercial'] = {
        async render(container) {
            this.currentPage = 1;
            this.perPage = 25;
            this.filters = { id_empresa: null, query: '', estado: null };

            /* Pre-cargar empresas para el select */
            try {
                const rEmp = await App.request('/empresas');
                this.empresas = Array.isArray(rEmp.data) ? rEmp.data : (Array.isArray(rEmp) ? rEmp : []);
            } catch (e) {
                this.empresas = [];
            }

            container.innerHTML = `
                <div class="card-header mb-4">
                    <h1 style="color:var(--c-primary)">
                        <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                        Seguimiento Comercial por Empresa
                    </h1>
                    <p style="color:#7f8c8d;font-size:13px;margin:6px 0 0 0;">
                        Lista de cotizaciones con su número de seguimientos, filtrable por empresa.
                    </p>
                </div>

                <div class="card" style="margin-bottom:14px;">
                    <div style="padding:12px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:flex-end;">
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">Empresa</label>
                            <select id="filtroIdEmpresa" class="form-select">
                                <option value="">(todas)</option>
                                ${(this.empresas || []).map(e => `
                                    <option value="${e.id_empresa}">${App.escapeHtml(e.razon_social)} (${App.escapeHtml(e.ruc)})</option>
                                `).join('')}
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">Estado</label>
                            <select id="filtroEstado" class="form-select">
                                <option value="">(todos)</option>
                                <option value="BORRADOR">Borrador</option>
                                <option value="PENDIENTE_ENVIO">Pendiente de envío</option>
                                <option value="ENVIADA">Enviada</option>
                                <option value="EN_SEGUIMIENTO">En seguimiento</option>
                                <option value="EN_NEGOCIACION">En negociación</option>
                                <option value="APROBADA">Aprobada</option>
                                <option value="RECHAZADA">Rechazada</option>
                                <option value="CANCELADA">Cancelada</option>
                                <option value="CERRADA">Cerrada</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">Buscar</label>
                            <input type="text" id="filtroQuery" class="form-input"
                                   placeholder="Código, RUC, razón social...">
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn btn-primary" id="btnBuscarSeg">
                                <i class="bi bi-search"></i> Buscar
                            </button>
                            <button class="btn btn-secondary" id="btnLimpiarSeg">
                                <i class="bi bi-x-lg"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div id="segComercialContainer">
                        <div class="loading-container"><div class="spinner"></div></div>
                    </div>
                </div>`;

            document.getElementById('btnBuscarSeg').onclick = () => {
                this.filters.id_empresa = document.getElementById('filtroIdEmpresa').value || null;
                this.filters.estado     = document.getElementById('filtroEstado').value || null;
                this.filters.query      = document.getElementById('filtroQuery').value.trim();
                this.currentPage = 1;
                this.load();
            };
            document.getElementById('btnLimpiarSeg').onclick = () => {
                document.getElementById('filtroIdEmpresa').value = '';
                document.getElementById('filtroEstado').value = '';
                document.getElementById('filtroQuery').value = '';
                this.filters = { id_empresa: null, query: '', estado: null };
                this.currentPage = 1;
                this.load();
            };

            await this.load();
        },

        async load() {
            const cont = document.getElementById('segComercialContainer');
            if (!cont) return;
            try {
                let url = `/cotizaciones/filtro_empresa?page=${this.currentPage}&per_page=${this.perPage}`;
                if (this.filters.id_empresa) url += `&id_empresa=${this.filters.id_empresa}`;
                if (this.filters.estado)     url += `&estado=${this.filters.estado}`;
                if (this.filters.query)      url += `&query=${encodeURIComponent(this.filters.query)}`;

                const r = await App.request(url);
                const data = r.data || r;
                const rows = data.rows || [];
                const total = data.total || 0;
                const totalPages = data.pages || 0;

                if (rows.length === 0) {
                    cont.innerHTML = App.emptyState('🔍', 'Sin resultados',
                        'No se encontraron cotizaciones con los filtros seleccionados.');
                    return;
                }

                cont.innerHTML = `
                    <table class="table" style="width:100%;font-size:12px;">
                        <thead>
                            <tr style="background:#f9fafc;">
                                <th style="padding:8px;text-align:left;">Código</th>
                                <th style="padding:8px;text-align:left;">Empresa</th>
                                <th style="padding:8px;text-align:left;">Tipo</th>
                                <th style="padding:8px;text-align:left;">Estado</th>
                                <th style="padding:8px;text-align:center;">Fecha</th>
                                <th style="padding:8px;text-align:right;">Total</th>
                                <th style="padding:8px;text-align:center;">Seguimientos</th>
                                <th style="padding:8px;text-align:center;">Próximo</th>
                                <th style="padding:8px;text-align:right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(c => `
                                <tr style="border-bottom:1px solid #ecf0f1;">
                                    <td style="padding:8px;">
                                        <strong style="font-family:monospace;">${App.escapeHtml(c.codigo)}</strong>
                                        <div style="font-size:10px;color:#7f8c8d;">v.${c.version_numero}</div>
                                    </td>
                                    <td style="padding:8px;">
                                        ${App.escapeHtml(c.empresa_razon_social)}
                                        <div style="font-size:10px;color:#7f8c8d;">RUC: ${App.escapeHtml(c.empresa_ruc || '—')}</div>
                                    </td>
                                    <td style="padding:8px;">${App.escapeHtml(c.tipo_nombre || '—')}</td>
                                    <td style="padding:8px;">${this.estadoBadge(c.estado)}</td>
                                    <td style="padding:8px;text-align:center;">${App.escapeHtml(c.fecha_creacion || '—')}</td>
                                    <td style="padding:8px;text-align:right;font-weight:600;">
                                        ${App.escapeHtml(c.moneda || '')} ${Number(c.total || 0).toFixed(2)}
                                    </td>
                                    <td style="padding:8px;text-align:center;">
                                        <button class="seg-counter-badge ${(c.total_seguimientos || 0) === 0 ? 'zero' : ''}"
                                                onclick="App.openSeguimientoCotizacion(${c.id_cotizacion}, '${App.escapeHtml(c.codigo)}')">
                                            ${c.total_seguimientos || 0}
                                        </button>
                                    </td>
                                    <td style="padding:8px;text-align:center;font-size:11px;">
                                        ${App.escapeHtml(c.fecha_proximo_seguimiento || '—')}
                                    </td>
                                    <td style="padding:8px;text-align:right;">
                                        <button class="btn btn-sm btn-primary"
                                                onclick="App.openSeguimientoCotizacion(${c.id_cotizacion}, '${App.escapeHtml(c.codigo)}')">
                                            <i class="bi bi-chat-dots"></i> Seguimiento
                                        </button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    <div style="padding:10px;border-top:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#7f8c8d;">
                        <div>
                            Mostrando ${rows.length} de ${total} cotización(es) ·
                            Página ${this.currentPage} de ${totalPages || 1}
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn btn-sm btn-secondary"
                                    ${this.currentPage <= 1 ? 'disabled' : ''}
                                    onclick="App.views['seguimiento-comercial'].cambiarPagina(${this.currentPage - 1})">
                                ← Anterior
                            </button>
                            <button class="btn btn-sm btn-secondary"
                                    ${this.currentPage >= totalPages ? 'disabled' : ''}
                                    onclick="App.views['seguimiento-comercial'].cambiarPagina(${this.currentPage + 1})">
                                Siguiente →
                            </button>
                        </div>
                    </div>`;
            } catch (e) {
                cont.innerHTML = App.emptyState('⚠️', 'Error', e.message);
            }
        },

        cambiarPagina(n) {
            this.currentPage = n;
            this.load();
        },

        estadoBadge(estado) {
            const colors = {
                'BORRADOR': '#6c757d', 'PENDIENTE_ENVIO': '#17a2b8',
                'ENVIADA': '#007bff', 'EN_SEGUIMIENTO': '#ffc107',
                'EN_NEGOCIACION': '#fd7e14', 'APROBADA': '#28a745',
                'RECHAZADA': '#dc3545', 'CANCELADA': '#343a40', 'CERRADA': '#198754'
            };
            const c = colors[estado] || '#6c757d';
            const txtColor = ['EN_SEGUIMIENTO'].includes(estado) ? '#212529' : '#fff';
            return `<span style="background:${c};color:${txtColor};padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">${App.escapeHtml(estado)}</span>`;
        }
    };

    /* ================================================================
     * 5. Añadir entradas de menú para las nuevas vistas
     * ================================================================*/
    function addMenuEntries() {
        /* Buscar el sidebar (depende de la estructura HTML del proyecto) */
        const sidebar = document.querySelector('.sidebar, .menu, nav.menu, [role="navigation"]');
        if (!sidebar) return;

        /* Solo añadir si no existen ya */
        if (sidebar.querySelector('a[href="#/config-logo"]')) return;
        if (sidebar.querySelector('a[href="#/seguimiento-comercial"]')) return;

        /* Buscar una sección de Cotizaciones o Configuraciones */
        const existingLinks = sidebar.querySelectorAll('a[href^="#/"]');
        let cotizacionesLink = null;
        let configLink = null;
        existingLinks.forEach(l => {
            const href = l.getAttribute('href') || '';
            if (href.includes('cotizac')) cotizacionesLink = l;
            if (href.includes('config'))  configLink = l;
        });

        /* Insertar "Seguimiento comercial" cerca de Cotizaciones */
        if (cotizacionesLink) {
            const li = cotizacionesLink.closest('li') || cotizacionesLink.parentNode;
            const newLi = document.createElement('li');
            newLi.innerHTML = `<a href="#/seguimiento-comercial" style="display:block;padding:8px 12px;color:inherit;text-decoration:none;font-size:13px;">
                <i class="bi bi-clipboard-check"></i> Seguimiento Comercial
            </a>`;
            li.parentNode.insertBefore(newLi, li.nextSibling);
        }

        /* Insertar "Configuración Logo" cerca de Configuraciones */
        if (configLink) {
            const li = configLink.closest('li') || configLink.parentNode;
            const newLi = document.createElement('li');
            newLi.innerHTML = `<a href="#/config-logo" style="display:block;padding:8px 12px;color:inherit;text-decoration:none;font-size:13px;">
                <i class="bi bi-image"></i> Logo Cotizaciones
            </a>`;
            li.parentNode.insertBefore(newLi, li.nextSibling);
        }
    }

    /* ================================================================
     * 6. Asegurar que el router SPA maneje las nuevas rutas
     * ================================================================*/
    function patchRouter() {
        /* Si App.routes existe como objeto, añadir las rutas nuevas */
        if (App.routes && typeof App.routes === 'object') {
            App.routes['config-logo'] = 'config-logo';
            App.routes['seguimiento-comercial'] = 'seguimiento-comercial';
        }

        /* Si App.navigate existe, las rutas se resuelven automáticamente
         * buscando App.views[routeName] */
    }

    /* ================================================================
     * INICIALIZACIÓN
     * ================================================================*/
    function init() {
        try { patchFormularioNuevaCotizacion(); } catch (e) { console.error('[patchFormNueva]', e); }
        try { patchVistaEditarCotizacion(); } catch (e) { console.error('[patchEditar]', e); }
        try { patchRouter(); } catch (e) { console.error('[patchRouter]', e); }
        try { addMenuEntries(); } catch (e) { console.error('[addMenu]', e); }
        console.log('[cotizaciones_mejoras_v2] Patches aplicados');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* Re-ejecutar addMenuEntries cuando cambia la ruta (por si el sidebar
       se regenera dinámicamente) */
    window.addEventListener('hashchange', () => {
        setTimeout(addMenuEntries, 200);
    });

})();
