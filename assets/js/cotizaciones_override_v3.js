/**
 * assets/js/cotizaciones_override_v3.js
 * ----------------------------------------------------------------------------
 * VERSIÓN 3 — OVERRIDE COMPLETO de la vista cotizacion-nueva
 *
 *  Problemas detectados en V1 y V2:
 *   - El patch no se aplicaba porque cotizaciones_v2.js se carga después
 *     y sobrescribía los cambios.
 *   - La cuenta bancaria seguía mostrándose como combo de selección única.
 *   - Zona de Pricing seguía visible.
 *   - El formato del código seguía siendo SOP-EMP000001-2026-????.
 *
 *  Solución V3:
 *   - REEMPLAZA COMPLETAMENTE App.views['cotizacion-nueva'] con una
 *     implementación nueva (no parchea).
 *   - Sin Zona de Pricing.
 *   - Cuentas bancarias como CHECKBOXES múltiples (las 3 marcadas
 *     por defecto). El usuario puede seleccionar más de una.
 *   - Las cuentas seleccionadas se envían como id_cuenta_bancaria
 *     (la primera) + cuentas_adicionales (las demás).
 *   - Banner de previsualización de código con formato SERV-2026-001.1.
 *   - Filtra tipos: solo muestra SERV y VENT.
 *   - Nueva vista de configuración de logo accesible desde el menú.
 *   - Nueva vista de seguimiento comercial con filtro por empresa.
 *
 *  Cargar este archivo DESPUÉS de todos los JS existentes:
 *    <script src="/assets/js/cotizaciones_v2.js"></script>
 *    <script src="/assets/js/views.js"></script>
 *    <script src="/assets/js/cotizaciones_override_v3.js"></script>
 *
 *  Importante: este archivo se ejecuta en DOMContentLoaded para garantizar
 *  que App.views ya existe cuando lo sobreescribimos.
 */

(function () {
    'use strict';

    function init() {
        if (!window.App) {
            console.error('[override v3] App no cargado');
            return;
        }

        /* ============================================================
         * Helper: cargar catálogos (igual que el original pero filtrando tipos)
         * ============================================================*/
        async function cargarCatalogos() {
            try {
                const r = await App.request('/cotizaciones_catalogos');
                let data = r.data || r;
                /* Filtrar tipos: solo SERV y VENT */
                if (data && Array.isArray(data.tipos)) {
                    data.tipos = data.tipos.filter(t =>
                        t.codigo === 'SERV' || t.codigo === 'VENT' ||
                        t.prefijo_codigo === 'SERV' || t.prefijo_codigo === 'VENT'
                    );
                }
                return data;
            } catch (e) {
                console.error('[override v3] cargarCatalogos:', e);
                return null;
            }
        }

        /* ============================================================
         * OVERRIDE COMPLETO: App.views['cotizacion-nueva']
         * ============================================================*/
        App.views['cotizacion-nueva'] = {
            state: { container: null, catalogos: null, selectedCuentas: [] },

            async render(container, params) {
                this.state = { container, catalogos: null, selectedCuentas: [] };
                container.innerHTML = `
                    <div class="card">
                        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                            <h2 style="margin:0;font-size:18px;color:#2c3e50;">
                                <i class="bi bi-file-earmark-plus"></i> Nueva Cotización
                            </h2>
                            <button class="btn btn-secondary" onclick="App.navigate('/cotizaciones')">
                                <i class="bi bi-arrow-left"></i> Volver
                            </button>
                        </div>
                        <div style="padding:18px;" id="cotFormWrap">
                            <div class="loading-container">
                                <div class="spinner spinner-lg"></div>
                                <p>Cargando catálogos...</p>
                            </div>
                        </div>
                    </div>`;

                const cat = await cargarCatalogos();
                if (!cat) {
                    container.querySelector('#cotFormWrap').innerHTML =
                        '<div class="empty-state"><div class="empty-state__icon">⚠️</div>' +
                        '<div class="empty-state__title">Error</div>' +
                        '<div class="empty-state__desc">No se pudieron cargar los catálogos.</div></div>';
                    return;
                }
                this.state.catalogos = cat;
                this.renderForm(cat);
            },

            renderForm(cat) {
                const c = this.state.container;
                const usr = App.state.user || {};

                /* Pre-mapear cuentas activas de la emisora principal */
                const emisoraPrincipal = (cat.emisoras || [])[0] || {};
                const idEmisoraInicial = emisoraPrincipal.id_emisora || '';
                const cuentasIniciales = (cat.cuentas || []).filter(cu =>
                    cu.activo !== 0 && (
                        !idEmisoraInicial ||
                        cu.id_emisora == idEmisoraInicial
                    )
                ).slice(0, 3);

                /* Pre-seleccionar todas las cuentas iniciales */
                this.state.selectedCuentas = cuentasIniciales.map(cu => cu.id_cuenta);

                c.querySelector('#cotFormWrap').innerHTML = `
                    <form id="formCotizacion" onsubmit="return false;">

                        <!-- BANNER DE CÓDIGO AUTOMÁTICO -->
                        <div id="codigoPreviewBannerV3" style="
                            padding:14px 18px;
                            background:linear-gradient(to right,#e3f2fd,#f3f9ff);
                            border-left:5px solid #1976d2;
                            border-radius:6px;
                            margin-bottom:18px;">
                            <div style="font-size:10px;color:#2c3e50;text-transform:uppercase;
                                        letter-spacing:0.5px;font-weight:600;">
                                Código que se generará automáticamente:
                            </div>
                            <div id="codigoPreviewValueV3" style="
                                font-family:'Consolas',monospace;
                                font-size:22px;font-weight:700;
                                color:#1976d2;letter-spacing:1px;margin-top:4px;">
                                (Seleccione empresa para ver el código)
                            </div>
                            <div id="codigoPreviewWarningV3" style="
                                font-size:11px;color:#f57c00;margin-top:6px;display:none;
                                background:#fff8e1;padding:6px 10px;border-radius:3px;
                                border-left:3px solid #ffc107;">
                            </div>
                        </div>

                        <!-- CLIENTE Y TIPO -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Cliente y tipo
                            </legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Tipo de cotización *</label>
                                    <select class="form-select" id="id_tipo" required>
                                        ${(cat.tipos || []).map(t => `
                                            <option value="${t.id_tipo}">
                                                ${App.escapeHtml(t.nombre)} (${App.escapeHtml(t.prefijo_codigo)})
                                            </option>
                                        `).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Empresa emisora *</label>
                                    <select class="form-select" id="id_emisora" required>
                                        ${(cat.emisoras || []).map(e => `
                                            <option value="${e.id_emisora}"
                                                data-tiene-logo="${e.tiene_logo || 0}">
                                                ${App.escapeHtml(e.razon_social)} (RUC: ${App.escapeHtml(e.ruc)})
                                                ${e.tiene_logo ? ' ✓' : ' ⚠ sin logo'}
                                            </option>
                                        `).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Empresa cliente *</label>
                                    <select class="form-select" id="id_empresa" required>
                                        <option value="">(seleccione)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Sucursal</label>
                                    <select class="form-select" id="id_sucursal">
                                        <option value="">(sin especificar)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Contacto</label>
                                    <select class="form-select" id="id_empresa_contacto">
                                        <option value="">(sin especificar)</option>
                                    </select>
                                </div>
                            </div>
                        </fieldset>

                        <!-- CONDICIONES COMERCIALES (sin Zona de Pricing) -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Condiciones comerciales
                            </legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Moneda</label>
                                    <select class="form-select" id="moneda">
                                        <option value="PEN">Soles (PEN)</option>
                                        <option value="USD">Dólares (USD)</option>
                                        <option value="EUR">Euros (EUR)</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">IGV %</label>
                                    <input type="number" class="form-input" id="igv_pct"
                                           value="18.00" step="0.01" min="0" max="100">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Descuento global %</label>
                                    <input type="number" class="form-input" id="descuento_global_pct"
                                           value="0.00" step="0.01" min="0" max="100">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Forma de pago</label>
                                    <select class="form-select" id="id_forma_pago">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.formas_pago || []).map(f => `
                                            <option value="${f.id_forma_pago}">${App.escapeHtml(f.descripcion)}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Garantía</label>
                                    <select class="form-select" id="id_garantia">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.garantias || []).map(g => `
                                            <option value="${g.id_garantia}">${App.escapeHtml(g.descripcion)}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Plazo de entrega</label>
                                    <select class="form-select" id="id_plazo_entrega">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.plazos || []).map(p => `
                                            <option value="${p.id_plazo}">${App.escapeHtml(p.descripcion)}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Validez</label>
                                    <select class="form-select" id="id_validez">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.valideces || []).map(v => `
                                            <option value="${v.id_validez}">${App.escapeHtml(v.descripcion)}</option>
                                        `).join('')}
                                    </select>
                                </div>
                                <!-- ZONA DE PRICING ELIMINADA -->
                            </div>
                        </fieldset>

                        <!-- CUENTAS BANCARIAS: CHECKBOXES MÚLTIPLES -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;background:#fafbfc;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Cuentas bancarias (selección múltiple — aparecerán en el PDF)
                            </legend>
                            <div style="font-size:11px;color:#7f8c8d;margin-bottom:10px;">
                                Seleccione las cuentas que aparecerán en la cotización.
                                Por defecto aparecen marcadas las 3 cuentas activas de la emisora.
                            </div>
                            <div id="cuentasCheckboxContainer" style="display:grid;gap:8px;">
                                <div class="loading-container"><div class="spinner"></div></div>
                            </div>
                            <div id="cuentasInfo" style="margin-top:10px;padding:8px;background:#e3f2fd;
                                                                  border-radius:4px;font-size:11px;color:#1976d2;">
                                <strong>Cuentas seleccionadas:</strong> <span id="cuentasCount">0</span>
                                · Estas cuentas aparecerán automáticamente en el PDF de la cotización.
                            </div>
                        </fieldset>

                        <!-- INFORMACIÓN DEL DOCUMENTO -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Información del documento
                            </legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Título del documento</label>
                                    <input type="text" class="form-input" id="titulo_documento"
                                           placeholder="COTIZACIÓN, PROPUESTA, etc. (vacío = usa el del tipo)">
                                </div>
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Motivo</label>
                                    <input type="text" class="form-input" id="motivo"
                                           placeholder="Breve descripción del motivo de la cotización">
                                </div>
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Observaciones</label>
                                    <textarea class="form-input" id="observaciones" rows="3"
                                              placeholder="Notas internas, condiciones especiales..."></textarea>
                                </div>
                            </div>
                        </fieldset>

                        <!-- DETALLE DE ITEMS -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Detalle de items
                            </legend>
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
                                            <button type="button" class="btn btn-sm btn-secondary"
                                                    onclick="App.views['cotizacion-nueva'].addItem()">
                                                <i class="bi bi-plus"></i> Agregar item
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </fieldset>

                        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
                            <button type="button" class="btn btn-secondary"
                                    onclick="App.navigate('/cotizaciones')">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="btnGuardarCot">
                                <i class="bi bi-save"></i> Guardar Borrador
                            </button>
                        </div>
                    </form>
                `;

                /* Cargar empresas */
                this.cargarEmpresas();

                /* Item inicial */
                this.addItem();

                /* Event listeners */
                c.querySelector('#btnGuardarCot').onclick = () => this.guardar();
                c.querySelector('#id_empresa').onchange = () => {
                    this.cargarSucursalesYContactos();
                    this.actualizarPreviewCodigo();
                };
                c.querySelector('#id_tipo').onchange = () => this.actualizarPreviewCodigo();
                c.querySelector('#id_emisora').onchange = () => this.cargarCuentasPorEmisora();

                /* Cargar cuentas iniciales */
                this.cargarCuentasPorEmisora();

                /* Preview inicial de código */
                setTimeout(() => this.actualizarPreviewCodigo(), 300);
            },

            /* === Cargar empresas para el select === */
            async cargarEmpresas() {
                try {
                    const r = await App.request('/empresas');
                    const rows = r.data || r;
                    const sel = this.state.container.querySelector('#id_empresa');
                    if (!Array.isArray(rows)) return;
                    sel.innerHTML = '<option value="">(seleccione)</option>' +
                        rows.map(e => `
                            <option value="${e.id_empresa}"
                                    data-codigo-empresa="${App.escapeHtml(e.codigo_empresa || '')}">
                                ${App.escapeHtml(e.razon_social)} (RUC: ${App.escapeHtml(e.ruc)})
                                ${e.codigo_empresa ? ' [' + App.escapeHtml(e.codigo_empresa) + ']' : ' [sin código]'}
                            </option>
                        `).join('');
                } catch (e) {
                    console.error('[override v3] cargarEmpresas:', e);
                }
            },

            /* === Cargar sucursales y contactos cuando cambia la empresa === */
            async cargarSucursalesYContactos() {
                const c = this.state.container;
                const idEmp = c.querySelector('#id_empresa').value;
                if (!idEmp) return;
                try {
                    const rSuc = await App.request(`/sucursales?id_empresa=${idEmp}`);
                    const sucursales = rSuc.data || rSuc;
                    const selSuc = c.querySelector('#id_sucursal');
                    selSuc.innerHTML = '<option value="">(sin especificar)</option>' +
                        (Array.isArray(sucursales) ? sucursales : []).map(s =>
                            `<option value="${s.id_sucursal}">${App.escapeHtml(s.nombre)}</option>`
                        ).join('');

                    const rCon = await App.request(`/contactos?id_empresa=${idEmp}`);
                    const contactos = rCon.data || rCon;
                    const selCon = c.querySelector('#id_empresa_contacto');
                    selCon.innerHTML = '<option value="">(sin especificar)</option>' +
                        (Array.isArray(contactos) ? contactos : []).map(co =>
                            `<option value="${co.id_empresa_contacto}">
                                ${App.escapeHtml((co.nombres || '') + ' ' + (co.apellido_paterno || ''))}
                                ${co.cargo ? ' (' + App.escapeHtml(co.cargo) + ')' : ''}
                            </option>`
                        ).join('');
                } catch (e) {
                    console.error('[override v3] cargarSucursalesYContactos:', e);
                }
            },

            /* === Cargar cuentas bancarias como CHECKBOXES === */
            async cargarCuentasPorEmisora() {
                const c = this.state.container;
                const idEmi = c.querySelector('#id_emisora').value;
                if (!idEmi) return;

                /* Buscar cuentas activas de esta emisora en los catálogos precargados */
                const cuentasEmisora = (this.state.catalogos.cuentas || []).filter(cu =>
                    cu.activo !== 0 && cu.id_emisora == idEmi
                );

                /* Si no hay en catálogos, intentar cargar desde el endpoint */
                let cuentas = cuentasEmisora;
                if (cuentas.length === 0) {
                    try {
                        const r = await App.request(`/cotizaciones_catalogos/cuentas?id_emisora=${idEmi}`);
                        cuentas = r.data || r || [];
                    } catch (e) {
                        cuentas = [];
                    }
                }

                /* Marcar las 3 primeras como seleccionadas por defecto */
                const cuentasTop3 = cuentas.slice(0, 3);
                this.state.selectedCuentas = cuentasTop3.map(cu => cu.id_cuenta);

                /* Renderizar checkboxes */
                const cont = c.querySelector('#cuentasCheckboxContainer');
                if (cuentas.length === 0) {
                    cont.innerHTML = `
                        <div style="padding:14px;text-align:center;color:#dc3545;background:#fff5f5;
                                    border-radius:4px;font-size:12px;">
                            ⚠ Esta emisora no tiene cuentas bancarias configuradas.
                        </div>`;
                } else {
                    cont.innerHTML = cuentas.map((cu, i) => {
                        const checked = i < 3 ? 'checked' : '';
                        return `
                            <label style="
                                display:flex;align-items:center;gap:10px;padding:10px 12px;
                                background:#fff;border:1px solid #dee2e6;border-radius:6px;
                                cursor:pointer;transition:all 0.2s;
                                ${i < 3 ? 'border-color:#28a745;background:#f0fff4;' : ''}"
                               onmouseover="this.style.borderColor='#1976d2'"
                               onmouseout="this.style.borderColor='${i < 3 ? '#28a745' : '#dee2e6'}'">
                                <input type="checkbox"
                                       class="cuenta-checkbox"
                                       value="${cu.id_cuenta}"
                                       ${checked}
                                       onchange="App.views['cotizacion-nueva'].toggleCuenta(${cu.id_cuenta}, this.checked)"
                                       style="width:18px;height:18px;cursor:pointer;">
                                <div style="flex:1;">
                                    <div style="font-weight:600;color:#2c3e50;font-size:13px;">
                                        ${App.escapeHtml(cu.banco)}
                                        ${i === 0 ? '<span style="background:#28a745;color:#fff;padding:1px 6px;border-radius:8px;font-size:9px;margin-left:6px;">PRINCIPAL</span>' : ''}
                                    </div>
                                    <div style="font-size:11px;color:#7f8c8d;font-family:monospace;">
                                        Cuenta: ${App.escapeHtml(cu.numero_cuenta)}
                                        ${cu.cci ? ' · CCI: ' + App.escapeHtml(cu.cci) : ''}
                                    </div>
                                    ${cu.titular ? `<div style="font-size:10px;color:#95a5a6;">Titular: ${App.escapeHtml(cu.titular)}</div>` : ''}
                                </div>
                                <div style="text-align:right;">
                                    <span style="background:${cu.moneda === 'PEN' ? '#28a745' : (cu.moneda === 'USD' ? '#007bff' : '#6c757d')};
                                                 color:#fff;padding:3px 8px;border-radius:12px;
                                                 font-size:10px;font-weight:600;">
                                        ${App.escapeHtml(cu.moneda || 'PEN')}
                                    </span>
                                </div>
                            </label>`;
                    }).join('');
                }

                this.actualizarCuentasCount();
            },

            /* === Toggle de cuenta (cuando se marca/desmarca checkbox) === */
            toggleCuenta(idCuenta, checked) {
                if (checked) {
                    if (!this.state.selectedCuentas.includes(idCuenta)) {
                        this.state.selectedCuentas.push(idCuenta);
                    }
                } else {
                    this.state.selectedCuentas = this.state.selectedCuentas.filter(id => id !== idCuenta);
                }
                this.actualizarCuentasCount();
            },

            actualizarCuentasCount() {
                const el = this.state.container.querySelector('#cuentasCount');
                if (el) el.textContent = this.state.selectedCuentas.length;
            },

            /* === Previsualizar código === */
            async actualizarPreviewCodigo() {
                const c = this.state.container;
                const idTipo = c.querySelector('#id_tipo').value;
                const idEmpresa = c.querySelector('#id_empresa').value;
                const valEl = c.querySelector('#codigoPreviewValueV3');
                const warnEl = c.querySelector('#codigoPreviewWarningV3');

                if (!idTipo || !idEmpresa) {
                    valEl.textContent = '(Seleccione empresa para ver el código)';
                    warnEl.style.display = 'none';
                    return;
                }

                try {
                    const r = await App.request(
                        `/cotizaciones/preview_codigo?id_empresa=${idEmpresa}&id_tipo=${idTipo}`);
                    const data = r.data || r;
                    if (data && data.codigo_proximo) {
                        valEl.textContent = data.codigo_proximo;
                        if (data.advertencia) {
                            warnEl.textContent = '⚠ ' + data.advertencia;
                            warnEl.style.display = 'block';
                        } else {
                            warnEl.style.display = 'none';
                        }
                    } else if (data && data.advertencia) {
                        valEl.textContent = '(no disponible)';
                        warnEl.textContent = '⚠ ' + data.advertencia;
                        warnEl.style.display = 'block';
                    } else {
                        valEl.textContent = '(no disponible)';
                        warnEl.style.display = 'none';
                    }
                } catch (e) {
                    valEl.textContent = '(error al obtener código)';
                    warnEl.style.display = 'none';
                }
            },

            /* === Agregar item al detalle === */
            addItem(prod = null) {
                const c = this.state.container;
                const tbody = c.querySelector('#detalleBody');
                const idx = tbody.children.length + 1;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="padding:6px;">${idx}</td>
                    <td style="padding:6px;">
                        <input type="text" class="form-input item-desc" style="width:100%;"
                               value="${App.escapeHtml(prod?.descripcion || '')}"
                               placeholder="Descripción del item">
                    </td>
                    <td style="padding:6px;">
                        <input type="number" class="form-input item-cant" style="width:70px;text-align:right;"
                               value="${prod?.cantidad || 1}" step="0.01" min="0">
                    </td>
                    <td style="padding:6px;">
                        <input type="number" class="form-input item-precio" style="width:100px;text-align:right;"
                               value="${prod?.precio_cliente || 0}" step="0.01" min="0">
                    </td>
                    <td style="padding:6px;">
                        <input type="number" class="form-input item-desc-pct" style="width:60px;text-align:right;"
                               value="0" step="0.01" min="0" max="100">
                    </td>
                    <td style="padding:6px;text-align:right;font-weight:600;" class="item-subtotal">0.00</td>
                    <td style="padding:6px;text-align:center;">
                        <button type="button" class="btn btn-sm btn-danger"
                                onclick="this.closest('tr').remove(); App.views['cotizacion-nueva'].recalcularTotales();">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);

                /* Recalcular subtotal cuando cambian los inputs */
                tr.querySelector('.item-cant').addEventListener('input', () => this.recalcItemSubtotal(tr));
                tr.querySelector('.item-precio').addEventListener('input', () => this.recalcItemSubtotal(tr));
                tr.querySelector('.item-desc-pct').addEventListener('input', () => this.recalcItemSubtotal(tr));
                this.recalcItemSubtotal(tr);
            },

            recalcItemSubtotal(tr) {
                const cant = parseFloat(tr.querySelector('.item-cant').value) || 0;
                const precio = parseFloat(tr.querySelector('.item-precio').value) || 0;
                const descPct = parseFloat(tr.querySelector('.item-desc-pct').value) || 0;
                const subtotal = cant * precio * (1 - descPct / 100);
                tr.querySelector('.item-subtotal').textContent = subtotal.toFixed(2);
                this.recalcularTotales();
            },

            recalcularTotales() {
                const c = this.state.container;
                const rows = c.querySelectorAll('#detalleBody tr');
                let subtotal = 0;
                rows.forEach(tr => {
                    const cant = parseFloat(tr.querySelector('.item-cant').value) || 0;
                    const precio = parseFloat(tr.querySelector('.item-precio').value) || 0;
                    const descPct = parseFloat(tr.querySelector('.item-desc-pct').value) || 0;
                    subtotal += cant * precio * (1 - descPct / 100);
                });
                const igvPct = parseFloat(c.querySelector('#igv_pct').value) || 0;
                const descGlobal = parseFloat(c.querySelector('#descuento_global_pct').value) || 0;
                const subtotalConDesc = subtotal * (1 - descGlobal / 100);
                const igv = subtotalConDesc * (igvPct / 100);
                const total = subtotalConDesc + igv;

                /* Mostrar totales (si existe un elemento) */
                let totEl = c.querySelector('#totalesResumen');
                if (!totEl) {
                    totEl = document.createElement('div');
                    totEl.id = 'totalesResumen';
                    totEl.style.cssText = 'margin-top:14px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;';
                    c.querySelector('#detalleBody').closest('fieldset').appendChild(totEl);
                }
                totEl.innerHTML = `
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>Subtotal:</span><strong>S/ ${subtotal.toFixed(2)}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>Descuento global (${descGlobal}%):</span>
                        <strong>- S/ ${(subtotal * descGlobal / 100).toFixed(2)}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:3px 0;">
                        <span>IGV (${igvPct}%):</span><strong>S/ ${igv.toFixed(2)}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0 0 0;
                                border-top:2px solid #2c3e50;margin-top:6px;font-size:14px;">
                        <strong>TOTAL:</strong><strong>S/ ${total.toFixed(2)}</strong>
                    </div>`;
            },

            obtenerDetalle() {
                const c = this.state.container;
                const rows = c.querySelectorAll('#detalleBody tr');
                const detalle = [];
                rows.forEach(tr => {
                    const desc = tr.querySelector('.item-desc').value.trim();
                    if (!desc) return;
                    const cant = parseFloat(tr.querySelector('.item-cant').value) || 0;
                    const prec = parseFloat(tr.querySelector('.item-precio').value) || 0;
                    const descPct = parseFloat(tr.querySelector('.item-desc-pct').value) || 0;
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

            /* === GUARDAR === */
            async guardar() {
                const c = this.state.container;
                const usr = App.state.user || {};

                /* Validar cuentas seleccionadas */
                if (this.state.selectedCuentas.length === 0) {
                    App.toast('Seleccione al menos una cuenta bancaria', 'warning');
                    return;
                }

                /* La primera cuenta es la principal, las demás son adicionales */
                const cuentasSeleccionadas = [...this.state.selectedCuentas];
                const idCuentaPrincipal = cuentasSeleccionadas[0];
                const cuentasAdicionales = cuentasSeleccionadas.slice(1);

                const body = {
                    id_tipo: parseInt(c.querySelector('#id_tipo').value, 10),
                    id_emisora: parseInt(c.querySelector('#id_emisora').value, 10),
                    id_empresa: parseInt(c.querySelector('#id_empresa').value, 10),
                    id_sucursal: c.querySelector('#id_sucursal').value ?
                        parseInt(c.querySelector('#id_sucursal').value, 10) : null,
                    id_empresa_contacto: c.querySelector('#id_empresa_contacto').value ?
                        parseInt(c.querySelector('#id_empresa_contacto').value, 10) : null,
                    id_vendedor: usr.id_persona || null,
                    id_usuario_creador: usr.id_persona,
                    id_forma_pago: c.querySelector('#id_forma_pago').value ?
                        parseInt(c.querySelector('#id_forma_pago').value, 10) : null,
                    id_garantia: c.querySelector('#id_garantia').value ?
                        parseInt(c.querySelector('#id_garantia').value, 10) : null,
                    id_plazo_entrega: c.querySelector('#id_plazo_entrega').value ?
                        parseInt(c.querySelector('#id_plazo_entrega').value, 10) : null,
                    id_validez: c.querySelector('#id_validez').value ?
                        parseInt(c.querySelector('#id_validez').value, 10) : null,
                    /* Cuentas bancarias múltiples */
                    id_cuenta_bancaria: idCuentaPrincipal,
                    cuentas_adicionales: cuentasAdicionales,
                    cuentas_adicionales_json: JSON.stringify(cuentasAdicionales),
                    /* Sin zona_pricing */
                    titulo_documento: c.querySelector('#titulo_documento').value || null,
                    motivo: c.querySelector('#motivo').value || null,
                    observaciones: c.querySelector('#observaciones').value || null,
                    moneda: c.querySelector('#moneda').value,
                    igv_pct: parseFloat(c.querySelector('#igv_pct').value) || 18.00,
                    descuento_global_pct: parseFloat(c.querySelector('#descuento_global_pct').value) || 0.00,
                    detalle: this.obtenerDetalle(),
                };

                if (!body.id_empresa) {
                    App.toast('Seleccione la empresa cliente', 'warning'); return;
                }
                if (body.detalle.length === 0) {
                    App.toast('Agregue al menos un item al detalle', 'warning'); return;
                }

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
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-save"></i> Guardar Borrador';
                }
            }
        };

        /* ============================================================
         * VISTA: Configuración de Logo
         * ============================================================*/
        App.views['config-logo'] = {
            async render(container) {
                container.innerHTML = `
                    <div class="card-header mb-4">
                        <h1 style="color:var(--c-primary)">
                            <i class="bi bi-image-fill" aria-hidden="true"></i>
                            Configuración de Logo de Cotizaciones
                        </h1>
                        <p style="color:#7f8c8d;font-size:13px;margin:6px 0 0 0;">
                            Suba o reemplace el logo que aparecerá en el encabezado de las cotizaciones
                            en PDF y en los reportes del sistema.
                        </p>
                    </div>
                    <div class="card" style="margin-bottom:14px;">
                        <div style="padding:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <div style="flex:1;min-width:240px;">
                                <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">
                                    Filtrar por empresa emisora
                                </label>
                                <input type="text" id="filtroEmisoraLogo" class="form-input"
                                       placeholder="Buscar por razón social o RUC..." style="width:100%;">
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
                    const r = await App.request('/cotizaciones_emisora');
                    const rows = r.data || r;
                    this.allEmisoras = Array.isArray(rows) ? rows : [];
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
                        || (e.ruc || '').toLowerCase().includes(q);
                });
                const cont = document.getElementById('emisorasListContainer');
                if (emisoras.length === 0) {
                    cont.innerHTML = App.emptyState('🔍', 'Sin resultados', 'No hay emisoras.');
                    return;
                }
                cont.innerHTML = `
                    <table class="table" style="width:100%;font-size:13px;">
                        <thead>
                            <tr style="background:#f9fafc;">
                                <th style="padding:10px;text-align:left;">Empresa emisora</th>
                                <th style="padding:10px;text-align:left;">RUC</th>
                                <th style="padding:10px;text-align:center;">Estado logo</th>
                                <th style="padding:10px;text-align:right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${emisoras.map(e => `
                                <tr style="border-bottom:1px solid #ecf0f1;">
                                    <td style="padding:10px;">
                                        <strong>${App.escapeHtml(e.razon_social)}</strong>
                                        ${e.es_principal ? '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:10px;font-size:9px;margin-left:6px;">PRINCIPAL</span>' : ''}
                                    </td>
                                    <td style="padding:10px;">${App.escapeHtml(e.ruc || '—')}</td>
                                    <td style="padding:10px;text-align:center;">
                                        ${e.tiene_logo
                                            ? '<span style="color:#28a745;font-weight:600;">✓ Tiene logo</span>'
                                            : '<span style="color:#dc3545;font-weight:600;">⚠ Sin logo</span>'}
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

            subirLogo(idEmisora, nombre) {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/png,image/jpeg,image/svg+xml,image/gif,image/webp';
                input.onchange = async (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (file.size > 2 * 1024 * 1024) {
                        App.toast('El archivo excede 2 MB', 'error'); return;
                    }
                    const reader = new FileReader();
                    reader.onload = async (ev) => {
                        try {
                            await App.request(`/cotizaciones_emisora/${idEmisora}/logo`, {
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
            },

            async eliminarLogo(idEmisora) {
                if (!confirm('¿Eliminar el logo de esta emisora?')) return;
                try {
                    await App.request(`/cotizaciones_emisora/${idEmisora}/logo`, { method: 'DELETE' });
                    App.toast('Logo eliminado', 'success');
                    this.load();
                } catch (e) {
                    App.toast('Error: ' + e.message, 'error');
                }
            }
        };

        /* ============================================================
         * VISTA: Seguimiento comercial con filtro por empresa
         * ============================================================*/
        App.views['seguimiento-comercial'] = {
            async render(container) {
                this.currentPage = 1;
                this.perPage = 25;
                this.filters = { id_empresa: null, query: '', estado: null };

                /* Pre-cargar empresas */
                try {
                    const rEmp = await App.request('/empresas');
                    this.empresas = rEmp.data || rEmp || [];
                    if (!Array.isArray(this.empresas)) this.empresas = [];
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
                            Lista de cotizaciones con su número de seguimientos, filtrable por empresa,
                            estado o búsqueda libre.
                        </p>
                    </div>
                    <div class="card" style="margin-bottom:14px;">
                        <div style="padding:12px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:flex-end;">
                            <div>
                                <label style="font-size:11px;color:#7f8c8d;display:block;margin-bottom:4px;">Empresa</label>
                                <select id="filtroIdEmpresa" class="form-select">
                                    <option value="">(todas)</option>
                                    ${this.empresas.map(e => `
                                        <option value="${e.id_empresa}">${App.escapeHtml(e.razon_social)}</option>
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
                                    <th style="padding:8px;text-align:left;">Estado</th>
                                    <th style="padding:8px;text-align:center;">Fecha</th>
                                    <th style="padding:8px;text-align:right;">Total</th>
                                    <th style="padding:8px;text-align:center;">Seguimientos</th>
                                    <th style="padding:8px;text-align:right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows.map(c => `
                                    <tr style="border-bottom:1px solid #ecf0f1;">
                                        <td style="padding:8px;">
                                            <strong style="font-family:monospace;">${App.escapeHtml(c.codigo)}</strong>
                                        </td>
                                        <td style="padding:8px;">${App.escapeHtml(c.empresa_razon_social)}</td>
                                        <td style="padding:8px;">${App.escapeHtml(c.estado)}</td>
                                        <td style="padding:8px;text-align:center;">${App.escapeHtml(c.fecha_creacion || '—')}</td>
                                        <td style="padding:8px;text-align:right;font-weight:600;">
                                            ${App.escapeHtml(c.moneda || '')} ${Number(c.total || 0).toFixed(2)}
                                        </td>
                                        <td style="padding:8px;text-align:center;">
                                            <span style="background:#e3f2fd;color:#1976d2;padding:3px 10px;
                                                         border-radius:12px;font-weight:600;">
                                                ${c.total_seguimientos || 0}
                                            </span>
                                        </td>
                                        <td style="padding:8px;text-align:right;">
                                            <button class="btn btn-sm btn-primary"
                                                    onclick="App.navigate('/cotizacion-detalle/${c.id_cotizacion}')">
                                                <i class="bi bi-eye"></i> Ver
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                        <div style="padding:10px;border-top:1px solid #ecf0f1;display:flex;
                                    justify-content:space-between;align-items:center;font-size:12px;color:#7f8c8d;">
                            <div>
                                ${rows.length} de ${total} cotización(es) ·
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
            }
        };

        /* ============================================================
         * AÑADIR ENTRADAS DE MENÚ
         * ============================================================*/
        function addMenuEntries() {
            /* Buscar el sidebar */
            const sidebar = document.querySelector('.sidebar, .menu, nav.menu, [role="navigation"], .nav-menu, .navigation');
            if (!sidebar) {
                /* Si no se encuentra, intentar de nuevo en 1s (la SPA puede tardar en renderizar) */
                setTimeout(addMenuEntries, 1000);
                return;
            }

            /* Solo añadir si no existen ya */
            if (!sidebar.querySelector('a[href="#/config-logo"]')) {
                /* Buscar cualquier enlace existente para clonar su estilo */
                const existingLink = sidebar.querySelector('a[href^="#/"]');
                const styleAttr = existingLink ? existingLink.getAttribute('style') : '';
                const classAttr = existingLink ? existingLink.className : '';

                /* Crear entrada "Configuración Logo" */
                const a1 = document.createElement('a');
                a1.href = '#/config-logo';
                a1.className = classAttr;
                a1.setAttribute('style', styleAttr);
                a1.innerHTML = '<i class="bi bi-image"></i> Logo Cotizaciones';
                const li1 = document.createElement('div');
                li1.style.cssText = 'display:block;';
                li1.appendChild(a1);
                sidebar.appendChild(li1);

                /* Crear entrada "Seguimiento Comercial" */
                const a2 = document.createElement('a');
                a2.href = '#/seguimiento-comercial';
                a2.className = classAttr;
                a2.setAttribute('style', styleAttr);
                a2.innerHTML = '<i class="bi bi-clipboard-check"></i> Seguimiento Comercial';
                const li2 = document.createElement('div');
                li2.style.cssText = 'display:block;';
                li2.appendChild(a2);
                sidebar.appendChild(li2);
            }
        }

        addMenuEntries();
        /* Re-ejecutar cuando cambia la ruta */
        window.addEventListener('hashchange', () => setTimeout(addMenuEntries, 200));

        console.log('[override v3] Vistas y menús registrados correctamente');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    /* Re-intentar después de 2 segundos por si App.views se carga tarde */
    setTimeout(init, 2000);
})();
