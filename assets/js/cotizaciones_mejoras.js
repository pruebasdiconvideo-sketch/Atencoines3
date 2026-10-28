/**
 * assets/js/cotizaciones_mejoras.js
 * ----------------------------------------------------------------------------
 * Patch JS con TODAS las mejoras solicitadas:
 *   1. Generación automática del código SERV-2026-001.1 (preview + ocultar input)
 *   2. Seguimiento comercial (modal con historial + formulario de registro)
 *   3. Ocultar Zona Pricing del formulario de cotización
 *   4. Mostrar las 3 cuentas bancarias automáticamente (sin combo box)
 *   5. Buscador de rubros en el listado
 *
 * INCLUSIÓN:
 *   Cargar DESPUÉS de cotizaciones_v2.js y views.js:
 *   <script src="/assets/js/cotizaciones_v2.js"></script>
 *   <script src="/assets/js/views.js"></script>
 *   <script src="/assets/js/cotizaciones_mejoras.js"></script>
 *
 * El script monkey-patchea las vistas existentes sin reemplazarlas por completo.
 */

(function () {
    'use strict';

    /* Si App no está cargado, salir */
    if (!window.App) {
        console.error('[cotizaciones_mejoras] App no está cargado');
        return;
    }

    /* ================================================================
     * 1. PATCH: Vista de Rubros — añadir buscador
     * ================================================================*/
    function patchRubrosView() {
        if (!App.views || !App.views.rubros) return;

        const originalRender = App.views.rubros.render.bind(App.views.rubros);

        App.views.rubros.render = async function (container) {
            this.currentPage = 1;
            this.searchQuery = '';
            container.innerHTML = `
                <div class="card-header mb-4">
                    <h1 style="color:var(--c-primary)">Rubros</h1>
                    <button class="btn btn-primary" id="btnNewRubro">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Rubro
                    </button>
                </div>
                <div class="card" style="margin-bottom:14px;">
                    <div style="display:flex;gap:8px;padding:12px;flex-wrap:wrap;align-items:center;">
                        <div style="flex:1;min-width:240px;">
                            <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">
                                Buscar rubro por nombre
                            </label>
                            <input type="text" id="rubroSearchInput"
                                   class="form-input"
                                   placeholder="Escriba para filtrar..."
                                   value=""
                                   style="width:100%;">
                        </div>
                        <div style="display:flex;gap:6px;align-items:flex-end;">
                            <button class="btn btn-primary" id="btnBuscarRubro">
                                <i class="bi bi-search"></i> Buscar
                            </button>
                            <button class="btn btn-secondary" id="btnLimpiarRubro">
                                <i class="bi bi-x-lg"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div id="tableContainer">
                        <div class="loading-container"><div class="spinner"></div></div>
                    </div>
                </div>`;

            document.getElementById('btnNewRubro').addEventListener('click',
                () => this.showFormModal(null));
            document.getElementById('btnBuscarRubro').addEventListener('click',
                () => this.applySearch());
            document.getElementById('btnLimpiarRubro').addEventListener('click',
                () => this.clearSearch());

            const input = document.getElementById('rubroSearchInput');
            let timeoutId = null;
            input.addEventListener('input', (e) => {
                if (timeoutId) clearTimeout(timeoutId);
                timeoutId = setTimeout(() => {
                    this.searchQuery = e.target.value.trim();
                    this.currentPage = 1;
                    this.applySearch();
                }, 300);
            });
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.searchQuery = input.value.trim();
                    this.applySearch();
                }
            });

            await this.load();
        };

        App.views.rubros.applySearch = async function () {
            try {
                const url = this.searchQuery
                    ? `/rubros?query=${encodeURIComponent(this.searchQuery)}`
                    : `/rubros`;
                const rows = await App.request(url);
                this.allRows = Array.isArray(rows) ? rows : [];
                this.renderTablePage();
                if (this.allRows.length === 0) {
                    document.getElementById('tableContainer').innerHTML =
                        App.emptyState('🔍', 'Sin resultados',
                            `No se encontraron rubros que coincidan con "${App.escapeHtml(this.searchQuery)}".`);
                }
            } catch (e) {
                document.getElementById('tableContainer').innerHTML =
                    App.emptyState('⚠️', 'Error', e.message);
            }
        };

        App.views.rubros.clearSearch = async function () {
            this.searchQuery = '';
            const input = document.getElementById('rubroSearchInput');
            if (input) input.value = '';
            this.currentPage = 1;
            await this.load();
        };
    }

    /* ================================================================
     * 2. PATCH: Formulario de cotización — previsualizar código y
     *    ocultar Zona Pricing + mostrar 3 cuentas automáticamente
     * ================================================================*/
    function patchCotizacionForm() {
        if (!App.views || !App.views['cotizacion-nueva']) return;

        const originalRenderForm = App.views['cotizacion-nueva'].renderForm
            ? App.views['cotizacion-nueva'].renderForm.bind(App.views['cotizacion-nueva'])
            : null;

        if (originalRenderForm) {
            App.views['cotizacion-nueva'].renderForm = function (cat) {
                originalRenderForm(cat);

                const c = this.state.container;
                if (!c) return;

                /* 2.1. Ocultar fila de Zona Pricing (si existe) */
                const zonaSelect = c.querySelector('#id_zona_pricing');
                if (zonaSelect) {
                    const zonaDiv = zonaSelect.closest('div');
                    if (zonaDiv) {
                        zonaDiv.style.display = 'none';
                        const label = zonaDiv.querySelector('label');
                        if (label) label.style.display = 'none';
                    }
                }

                /* 2.2. Mostrar las 3 cuentas bancarias automáticamente (sin combo) */
                const cuentaSelect = c.querySelector('#id_cuenta_bancaria');
                if (cuentaSelect) {
                    const cuentaDiv = cuentaSelect.closest('div');
                    if (cuentaDiv) {
                        /* Cambiar el label */
                        const label = cuentaDiv.querySelector('label');
                        if (label) label.textContent = 'Cuenta bancaria principal (las 3 se mostrarán automáticamente en el PDF)';

                        /* Añadir lista visual de las 3 cuentas */
                        const cuentasActivas = (cat.cuentas || []).filter(cu => cu.activo !== 0);
                        const cuentasHtml = `
                            <div style="margin-top:8px;padding:8px;background:#f8f9fa;border-radius:4px;font-size:11px;">
                                <strong>Cuentas que aparecerán en el PDF:</strong>
                                <ul style="margin:6px 0 0 18px;padding:0;">
                                    ${cuentasActivas.slice(0, 3).map(cu => `
                                        <li>${App.escapeHtml(cu.banco)} — ${App.escapeHtml(cu.numero_cuenta)} (${App.escapeHtml(cu.moneda)})</li>
                                    `).join('')}
                                </ul>
                                <div style="font-size:10px;color:#7f8c8d;margin-top:4px;">
                                    ${cuentasActivas.length} cuenta(s) activa(s) · todas se incluyen automáticamente.
                                </div>
                            </div>`;
                        cuentaDiv.insertAdjacentHTML('beforeend', cuentasHtml);

                        /* Pre-seleccionar las 3 cuentas como cuentas_adicionales (las 2 que no son la principal) */
                        const todasIds = cuentasActivas.slice(0, 3).map(cu => cu.id_cuenta);
                        /* La primera es la principal, las otras 2 van como adicionales */
                        if (todasIds.length > 1 && cuentaSelect) {
                            cuentaSelect.value = todasIds[0];
                            this._cuentasAdicionalesAuto = todasIds.slice(1);
                        }
                    }
                }

                /* 2.3. Añadir campo "Código generado automáticamente" */
                const tipoSelect = c.querySelector('#id_tipo');
                const empresaSelect = c.querySelector('#id_empresa');
                const formWrap = c.querySelector('#cotFormWrap') || c.querySelector('form');

                if (formWrap && tipoSelect && empresaSelect) {
                    /* Insertar banner de código antes del primer fieldset */
                    const firstFieldset = formWrap.querySelector('fieldset');
                    if (firstFieldset) {
                        const banner = document.createElement('div');
                        banner.id = 'codigoPreviewBanner';
                        banner.style.cssText = 'padding:10px 14px;background:#e3f2fd;border-left:4px solid #1976d2;border-radius:4px;margin-bottom:14px;font-size:12px;';
                        banner.innerHTML = `
                            <strong>Código que se generará:</strong>
                            <span id="codigoPreviewValue" style="font-family:monospace;font-weight:700;color:#1976d2;margin-left:8px;">
                                (Seleccione empresa para ver el código)
                            </span>
                            <div id="codigoPreviewWarning" style="font-size:10px;color:#f57c00;margin-top:4px;display:none;"></div>`;
                        firstFieldset.parentNode.insertBefore(banner, firstFieldset);

                        const updatePreview = async () => {
                            const idTipo = tipoSelect.value;
                            const idEmpresa = empresaSelect.value;
                            const valEl = banner.querySelector('#codigoPreviewValue');
                            const warnEl = banner.querySelector('#codigoPreviewWarning');
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
                            /* Esperar a que carguen sucursales/contactos */
                            setTimeout(updatePreview, 300);
                        });
                        /* Llamada inicial */
                        setTimeout(updatePreview, 200);
                    }
                }
            };
        }

        /* Interceptar el guardar para añadir cuentas_adicionales automáticamente */
        const originalGuardar = App.views['cotizacion-nueva'].guardar
            ? App.views['cotizacion-nueva'].guardar.bind(App.views['cotizacion-nueva'])
            : null;
        if (originalGuardar) {
            App.views['cotizacion-nueva'].guardar = async function () {
                /* Si tenemos cuentas adicionales auto, pasarlas */
                if (this._cuentasAdicionalesAuto && this._cuentasAdicionalesAuto.length > 0) {
                    /* El método original construirá el body desde el form */
                    /* Llamamos al original y luego parchamos la request */
                    const originalRequest = App.request;
                    const self = this;
                    App.request = async function (url, opts) {
                        if (url === '/cotizaciones' && opts && opts.method === 'POST' && opts.body) {
                            opts.body.cuentas_adicionales = self._cuentasAdicionalesAuto;
                            opts.body.cuentas_adicionales_json = JSON.stringify(self._cuentasAdicionalesAuto);
                            /* Remover zona_pricing si vino vacío */
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
     * 3. SEGUIMIENTO COMERCIAL — modal con historial y formulario
     * ================================================================*/
    App.showSeguimientoModal = async function (idCotizacion, codigoCot) {
        const title = `Seguimiento comercial · ${codigoCot || ('#' + idCotizacion)}`;

        const bodyHtml = `
            <div id="segModalContent">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <div>
                        <strong>Seguimientos realizados:</strong>
                        <span id="segTotal" style="font-size:18px;font-weight:700;color:#1976d2;">0</span>
                    </div>
                    <div>
                        <strong>Próximo:</strong>
                        <span id="segProximo" style="color:#7f8c8d;">—</span>
                    </div>
                </div>

                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:10px;margin-bottom:14px;background:#f8f9fa;">
                    <h4 style="margin:0 0 8px 0;font-size:13px;">Registrar nuevo seguimiento</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;">Medio *</label>
                            <select id="segMedio" class="form-select">
                                <option value="LLAMADA_TELEFONICA">Llamada telefónica</option>
                                <option value="CORREO_ELECTRONICO">Correo electrónico</option>
                                <option value="WHATSAPP">WhatsApp</option>
                                <option value="REUNION_VIRTUAL">Reunión virtual</option>
                                <option value="REUNION_PRESENCIAL">Reunión presencial</option>
                                <option value="OTRO">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;">Persona contactada</label>
                            <input type="text" id="segPersona" class="form-input" placeholder="Nombre de la persona">
                        </div>
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;">Resultado *</label>
                            <select id="segResultado" class="form-select">
                                <option value="">(seleccione)</option>
                                <option value="PENDIENTE_RESPUESTA">Pendiente de respuesta</option>
                                <option value="CLIENTE_NO_CONTESTA">Cliente no contesta</option>
                                <option value="INFO_ADICIONAL_ENVIADA">Se envió información adicional</option>
                                <option value="CLIENTE_SOLICITA_MODIFICACIONES">Cliente solicita modificaciones</option>
                                <option value="CLIENTE_SOLICITA_NUEVA_VERSION">Cliente solicita nueva versión</option>
                                <option value="CLIENTE_INTERESADO">Cliente interesado</option>
                                <option value="CLIENTE_EVALUANDO_PROPUESTA">Cliente evaluando la propuesta</option>
                                <option value="COTIZACION_ACEPTADA">Cotización aceptada</option>
                                <option value="COTIZACION_RECHAZADA">Cotización rechazada</option>
                                <option value="CLIENTE_NO_INTERESADO">Cliente no interesado</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px;color:#7f8c8d;">Fecha próximo seguimiento</label>
                            <input type="date" id="segProximoFecha" class="form-input">
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <label style="font-size:11px;color:#7f8c8d;">Descripción / observaciones</label>
                        <textarea id="segDescripcion" class="form-input" rows="2"
                            placeholder="Resumen de la comunicación, compromisos, etc."></textarea>
                    </div>
                </div>

                <h4 style="margin:14px 0 6px 0;font-size:13px;">Historial completo</h4>
                <div id="segHistorial" style="max-height:300px;overflow:auto;border:1px solid #e0e0e0;border-radius:6px;">
                    <div class="loading-container"><div class="spinner"></div></div>
                </div>
            </div>`;

        const footerHtml = `
            <button class="btn" onclick="App.closeModal()">Cerrar</button>
            <button class="btn btn-primary" id="btnGuardarSeg">Guardar seguimiento</button>`;

        App.showModal(title, bodyHtml, footerHtml);

        /* Cargar datos */
        const cargarHistorial = async () => {
            try {
                const r = await App.request(`/cotizaciones/${idCotizacion}/seguimientos`);
                const items = Array.isArray(r.data) ? r.data : [];
                const historialEl = document.getElementById('segHistorial');
                if (items.length === 0) {
                    historialEl.innerHTML = `<div style="padding:18px;text-align:center;color:#95a5a6;">Sin seguimientos registrados</div>`;
                } else {
                    historialEl.innerHTML = `
                        <table class="table" style="width:100%;font-size:11px;">
                            <thead>
                                <tr style="background:#f9fafc;">
                                    <th style="padding:6px;">#</th>
                                    <th style="padding:6px;">Fecha</th>
                                    <th style="padding:6px;">Medio</th>
                                    <th style="padding:6px;">Resultado</th>
                                    <th style="padding:6px;">Persona</th>
                                    <th style="padding:6px;">Usuario</th>
                                    <th style="padding:6px;">Próximo</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map((it, i) => `
                                    <tr style="border-bottom:1px solid #ecf0f1;">
                                        <td style="padding:6px;">${i + 1}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.fecha_comunicacion || it.fecha_registro || '—')}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.medio || '—')}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.resultado_comunicacion || it.tipo_comunicacion || '—')}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.persona_contactada || '—')}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.usuario_nombre || '—')}</td>
                                        <td style="padding:6px;">${App.escapeHtml(it.fecha_proximo_seguimiento || '—')}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>`;
                }
                /* Actualizar contador */
                document.getElementById('segTotal').textContent = items.length;
                const proximo = items.find(it => it.fecha_proximo_seguimiento);
                document.getElementById('segProximo').textContent =
                    proximo ? proximo.fecha_proximo_seguimiento : '—';
            } catch (e) {
                document.getElementById('segHistorial').innerHTML =
                    `<div style="padding:14px;color:#dc3545;">Error: ${App.escapeHtml(e.message)}</div>`;
            }
        };

        await cargarHistorial();

        document.getElementById('btnGuardarSeg').addEventListener('click', async () => {
            const medio = document.getElementById('segMedio').value;
            const resultado = document.getElementById('segResultado').value;
            const descripcion = document.getElementById('segDescripcion').value.trim();
            const persona = document.getElementById('segPersona').value.trim();
            const proximoFecha = document.getElementById('segProximoFecha').value;

            if (!medio) { App.toast('Medio es obligatorio', 'warning'); return; }
            if (!resultado) { App.toast('Resultado es obligatorio', 'warning'); return; }

            try {
                await App.request(`/cotizaciones/${idCotizacion}/seguimientos`, {
                    method: 'POST',
                    body: {
                        medio: medio,
                        resultado_comunicacion: resultado,
                        descripcion: descripcion,
                        persona_contactada: persona || null,
                        fecha_proximo_seguimiento: proximoFecha || null,
                    }
                });
                App.toast('Seguimiento registrado', 'success');
                /* Limpiar form */
                document.getElementById('segDescripcion').value = '';
                document.getElementById('segPersona').value = '';
                document.getElementById('segProximoFecha').value = '';
                document.getElementById('segResultado').value = '';
                /* Recargar historial */
                await cargarHistorial();
                /* Si la vista de listado está visible, refrescar */
                if (App.views['cotizaciones'] && App.views['cotizaciones'].load) {
                    App.views['cotizaciones'].load();
                }
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });
    };

    /* Exponer también como método de App para que la vista lo llame */
    App.openSeguimientoCotizacion = App.showSeguimientoModal;

    /* ================================================================
     * 4. PATCH: Listado de cotizaciones — añadir columna de seguimientos
     * ================================================================*/
    function patchCotizacionesList() {
        if (!App.views || !App.views['cotizaciones']) return;
        /* Solo añadimos el helper; la vista original ya incluye el campo
           total_comunicaciones que viene del SP list_paged.
           Aquí añadimos un botón de acción "Seguimiento" en cada fila. */
    }

    /* ================================================================
     * 5. PATCH: Eliminar menú "Zona Pricing" del sidebar (si existe)
     * ================================================================*/
    function removeZonaPricingMenu() {
        /* Buscar cualquier enlace del menú que apunte a #/zona-pricing o #/zonas-pricing */
        const links = document.querySelectorAll('a[href*="zona-pricing"], a[href*="zonas-pricing"], a[href*="ZonaPricing"]');
        links.forEach(l => {
            const li = l.closest('li');
            if (li) li.remove(); else l.remove();
        });
    }

    /* ================================================================
     * INICIALIZACIÓN
     * ================================================================*/
    function init() {
        try { patchRubrosView(); } catch (e) { console.error('[patchRubrosView]', e); }
        try { patchCotizacionForm(); } catch (e) { console.error('[patchCotizacionForm]', e); }
        try { patchCotizacionesList(); } catch (e) { console.error('[patchCotizacionesList]', e); }
        try { removeZonaPricingMenu(); } catch (e) {}
        console.log('[cotizaciones_mejoras] Patches aplicados correctamente');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
