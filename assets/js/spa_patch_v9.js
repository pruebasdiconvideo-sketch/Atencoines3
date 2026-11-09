/**
 * spa_patch_v9.js — PATCH AGRESIVO DE LA SPA
 * ----------------------------------------------------------------------------
 *  Este archivo SOBREESCRIBE las vistas de la SPA DESPUÉS de que views.js
 *  y cotizaciones_v2.js se hayan cargado.
 *
 *  CAMBIOS:
 *    1. Sobrescribe App.views['cotizacion-nueva'] para:
 *       - Eliminar Zona de Pricing
 *       - Reemplazar combo de cuenta bancaria por CHECKBOXES múltiples
 *       - Solo mostrar tipos SERV y VENT
 *       - Banner con código previsualizado SERV-2026-001.1 (sin EMP)
 *       - Usar siempre el SP v5 al guardar
 *
 *    2. Añade la vista App.views['configuracion-logo'] accesible vía:
 *       http://localhost:8036/#/configuracion-logo
 *
 *    3. Añade un botón en App.views.configuracion que enlaza a la nueva vista
 *       de configuración de logos.
 *
 *    4. Añade entrada de menú lateral "Logo Cotizaciones".
 *
 *  CÓMO INSTALAR:
 *    1. Copiar este archivo a: assets/js/spa_patch_v9.js
 *    2. Agregar en index.php (raíz del proyecto), AL FINAL del <body>:
 *         <script src="/assets/js/spa_patch_v9.js?v=7"></script>
 *       (el ?v=7 evita cache del navegador)
 *    3. Limpiar caché del navegador (Ctrl+Shift+R)
 *
 *  IMPORTANTE: Este archivo debe cargarse DESPUÉS de:
 *    - assets/js/app.js
 *    - assets/js/views.js
 *    - assets/js/cotizaciones_v2.js
 *    - assets/js/cotizaciones_view.js
 *    - Cualquier otro JS de cotizaciones
 */

(function () {
    'use strict';

    function log(msg) {
        console.log('%c[SPA Patch V9] ' + msg, 'color:#1976d2;font-weight:bold;');
    }

    function esperarApp(callback, intento) {
        intento = intento || 0;
        if (intento > 50) {  // 50 intentos x 100ms = 5 segundos máximo
            console.error('[SPA Patch V9] App no disponible después de 5s');
            return;
        }
        if (window.App && window.App.views && window.App.navigate) {
            callback();
        } else {
            setTimeout(function() { esperarApp(callback, intento + 1); }, 100);
        }
    }

    esperarApp(function () {
        log('App detectada. Aplicando patches...');

        /* ================================================================
         * Helper: cargar catálogos (filtrando tipos SERV/VENT)
         * ================================================================*/
        async function cargarCatalogosFiltrados() {
            try {
                const r = await App.request('/cotizaciones_catalogos');
                let data = r.data || r;
                if (data && Array.isArray(data.tipos)) {
                    const antes = data.tipos.length;
                    data.tipos = data.tipos.filter(function (t) {
                        return t.codigo === 'SERV' || t.codigo === 'VENT' ||
                               t.prefijo_codigo === 'SERV' || t.prefijo_codigo === 'VENT';
                    });
                    log('Tipos filtrados: ' + antes + ' → ' + data.tipos.length);
                }
                return data;
            } catch (e) {
                console.error('[SPA Patch V9] cargarCatalogos:', e);
                return null;
            }
        }

        /* ================================================================
         * 1. SOBREESCRIBIR App.views['cotizacion-nueva']
         * ================================================================*/
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

                const cat = await cargarCatalogosFiltrados();
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
                const usr = App.state.user || window.AppUser || {};

                /* Pre-mapear cuentas de la primera emisora */
                const emisoraPrincipal = (cat.emisoras || [])[0] || {};
                const idEmisoraInicial = emisoraPrincipal.id_emisora || '';
                const cuentasIniciales = (cat.cuentas || []).filter(function (cu) {
                    return cu.activo !== 0 && (!idEmisoraInicial || cu.id_emisora == idEmisoraInicial);
                }).slice(0, 3);

                this.state.selectedCuentas = cuentasIniciales.map(function (cu) { return cu.id_cuenta; });

                c.querySelector('#cotFormWrap').innerHTML = `
                    <form id="formCotizacion" onsubmit="return false;">

                        <!-- BANNER DE CÓDIGO AUTOMÁTICO -->
                        <div id="codigoPreviewBannerV7" style="padding:14px 18px;background:linear-gradient(to right,#e3f2fd,#f3f9ff);border-left:5px solid #1976d2;border-radius:6px;margin-bottom:18px;">
                            <div style="font-size:10px;color:#2c3e50;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">
                                Código que se generará automáticamente:
                            </div>
                            <div id="codigoPreviewValueV7" style="font-family:'Consolas',monospace;font-size:22px;font-weight:700;color:#1976d2;letter-spacing:1px;margin-top:4px;">
                                (Seleccione empresa para ver el código)
                            </div>
                            <div id="codigoPreviewWarningV7" style="font-size:11px;color:#f57c00;margin-top:6px;display:none;background:#fff8e1;padding:6px 10px;border-radius:3px;border-left:3px solid #ffc107;"></div>
                        </div>

                        <!-- CLIENTE Y TIPO -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Cliente y tipo</legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Tipo de cotización *</label>
                                    <select class="form-select" id="id_tipo" required>
                                        ${(cat.tipos || []).map(function (t) {
                                            return '<option value="' + t.id_tipo + '">' +
                                                App.escapeHtml(t.nombre) + ' (' + App.escapeHtml(t.prefijo_codigo) + ')</option>';
                                        }).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Empresa emisora *</label>
                                    <select class="form-select" id="id_emisora" required>
                                        ${(cat.emisoras || []).map(function (e) {
                                            return '<option value="' + e.id_emisora + '" data-tiene-logo="' + (e.tiene_logo || 0) + '">' +
                                                App.escapeHtml(e.razon_social) + ' (RUC: ' + App.escapeHtml(e.ruc) + ')' +
                                                (e.tiene_logo ? ' ✓' : ' ⚠ sin logo') + '</option>';
                                        }).join('')}
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

                        <!-- CONDICIONES COMERCIALES (SIN ZONA DE PRICING) -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Condiciones comerciales</legend>
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
                                    <input type="number" class="form-input" id="igv_pct" value="18.00" step="0.01" min="0" max="100">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Descuento global %</label>
                                    <input type="number" class="form-input" id="descuento_global_pct" value="0.00" step="0.01" min="0" max="100">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Forma de pago</label>
                                    <select class="form-select" id="id_forma_pago">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.formas_pago || []).map(function (f) {
                                            return '<option value="' + f.id_forma_pago + '">' + App.escapeHtml(f.descripcion) + '</option>';
                                        }).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Garantía</label>
                                    <select class="form-select" id="id_garantia">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.garantias || []).map(function (g) {
                                            return '<option value="' + g.id_garantia + '">' + App.escapeHtml(g.descripcion) + '</option>';
                                        }).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Plazo de entrega</label>
                                    <select class="form-select" id="id_plazo_entrega">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.plazos || []).map(function (p) {
                                            return '<option value="' + p.id_plazo + '">' + App.escapeHtml(p.descripcion) + '</option>';
                                        }).join('')}
                                    </select>
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#95a5a6;">Validez</label>
                                    <select class="form-select" id="id_validez">
                                        <option value="">(sin especificar)</option>
                                        ${(cat.valideces || []).map(function (v) {
                                            return '<option value="' + v.id_validez + '">' + App.escapeHtml(v.descripcion) + '</option>';
                                        }).join('')}
                                    </select>
                                </div>
                                <!-- ZONA DE PRICING ELIMINADA DEL FORMULARIO -->
                            </div>
                        </fieldset>

                        <!-- CUENTAS BANCARIAS: MULTI-SELECT LIST BOX -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;background:#fafbfc;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">
                                Cuentas bancarias (Multi-select - Ctrl+Click para seleccionar varias)
                            </legend>
                            <div style="font-size:11px;color:#7f8c8d;margin-bottom:10px;">
                                Mantenga presionado <strong>Ctrl</strong> (o Cmd en Mac) y haga clic para
                                seleccionar múltiples cuentas. Las cuentas seleccionadas aparecerán
                                todas en la cotización y en el PDF.
                            </div>
                            <select id="cuentasMultiSelect" multiple size="6"
                                    style="width:100%;min-height:160px;padding:8px;border:2px solid #1976d2;border-radius:6px;font-size:12px;background:#fff;">
                                <option value="">Cargando cuentas...</option>
                            </select>
                            <div style="margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                <button type="button" class="btn btn-sm btn-primary" onclick="App.views['cotizacion-nueva'].seleccionarTodasCuentas(true)">
                                    Seleccionar todas
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="App.views['cotizacion-nueva'].seleccionarTodasCuentas(false)">
                                    Quitar seleccion
                                </button>
                                <div id="cuentasInfo" style="margin-left:auto;padding:6px 10px;background:#e3f2fd;border-radius:4px;font-size:11px;color:#1976d2;font-weight:600;">
                                    Seleccionadas: <span id="cuentasCount">0</span>
                                </div>
                            </div>
                        </fieldset>

                        <!-- INFORMACIÓN DEL DOCUMENTO -->
                        <fieldset style="margin-bottom:18px;border:1px solid #e0e0e0;padding:14px;border-radius:6px;">
                            <legend style="padding:0 6px;font-size:13px;font-weight:600;color:#2c3e50;">Información del documento</legend>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Título del documento</label>
                                    <input type="text" class="form-input" id="titulo_documento" placeholder="COTIZACIÓN, PROPUESTA, etc.">
                                </div>
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Motivo</label>
                                    <input type="text" class="form-input" id="motivo" placeholder="Breve descripción">
                                </div>
                                <div style="grid-column:span 2;">
                                    <label style="font-size:11px;color:#95a5a6;">Observaciones</label>
                                    <textarea class="form-input" id="observaciones" rows="3" placeholder="Notas internas..."></textarea>
                                </div>
                            </div>
                        </fieldset>

                        <!-- DETALLE DE ITEMS -->
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
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="App.views['cotizacion-nueva'].addItem()">
                                                <i class="bi bi-plus"></i> Agregar item
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div id="totalesResumen" style="margin-top:14px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;"></div>
                        </fieldset>

                        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:18px;">
                            <button type="button" class="btn btn-secondary" onclick="App.navigate('/cotizaciones')">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="btnGuardarCot">
                                <i class="bi bi-save"></i> Guardar Borrador
                            </button>
                        </div>
                    </form>
                `;

                /* Listeners */
                this.cargarEmpresas();
                this.addItem();

                const self = this;
                c.querySelector('#btnGuardarCot').onclick = function () { self.guardar(); };
                c.querySelector('#id_empresa').onchange = function () {
                    self.cargarSucursalesYContactos();
                    self.actualizarPreviewCodigo();
                };
                c.querySelector('#id_tipo').onchange = function () { self.actualizarPreviewCodigo(); };
                c.querySelector('#id_emisora').onchange = function () { self.cargarCuentasPorEmisora(); };

                this.cargarCuentasPorEmisora();
                setTimeout(function () { self.actualizarPreviewCodigo(); }, 300);
            },

            async cargarEmpresas() {
                try {
                    const r = await App.request('/empresas');
                    const rows = r.data || r;
                    const sel = this.state.container.querySelector('#id_empresa');
                    if (!Array.isArray(rows)) return;
                    sel.innerHTML = '<option value="">(seleccione)</option>' +
                        rows.map(function (e) {
                            return '<option value="' + e.id_empresa + '" data-codigo-empresa="' + App.escapeHtml(e.codigo_empresa || '') + '">' +
                                App.escapeHtml(e.razon_social) + ' (RUC: ' + App.escapeHtml(e.ruc) + ')' +
                                (e.codigo_empresa ? ' [' + App.escapeHtml(e.codigo_empresa) + ']' : ' [sin código]') +
                                '</option>';
                        }).join('');
                } catch (e) {
                    console.error('[SPA Patch V9] cargarEmpresas:', e);
                }
            },

            async cargarSucursalesYContactos() {
                const c = this.state.container;
                const idEmp = c.querySelector('#id_empresa').value;
                if (!idEmp) return;
                try {
                    const rSuc = await App.request('/sucursales?id_empresa=' + idEmp);
                    const sucursales = rSuc.data || rSuc;
                    const selSuc = c.querySelector('#id_sucursal');
                    selSuc.innerHTML = '<option value="">(sin especificar)</option>' +
                        (Array.isArray(sucursales) ? sucursales : []).map(function (s) {
                            return '<option value="' + s.id_sucursal + '">' + App.escapeHtml(s.nombre) + '</option>';
                        }).join('');

                    const rCon = await App.request('/contactos?id_empresa=' + idEmp);
                    const contactos = rCon.data || rCon;
                    const selCon = c.querySelector('#id_empresa_contacto');
                    selCon.innerHTML = '<option value="">(sin especificar)</option>' +
                        (Array.isArray(contactos) ? contactos : []).map(function (co) {
                            return '<option value="' + co.id_empresa_contacto + '">' +
                                App.escapeHtml((co.nombres || '') + ' ' + (co.apellido_paterno || '')) +
                                (co.cargo ? ' (' + App.escapeHtml(co.cargo) + ')' : '') +
                                '</option>';
                        }).join('');
                } catch (e) {
                    console.error('[SPA Patch V9] cargarSucursalesYContactos:', e);
                }
            },

            cargarCuentasPorEmisora() {
                const c = this.state.container;
                const idEmi = c.querySelector('#id_emisora').value;
                const sel = c.querySelector('#cuentasMultiSelect');
                if (!sel) return;
                if (!idEmi) {
                    sel.innerHTML = '<option value="">(seleccione emisora)</option>';
                    this.actualizarCuentasCount();
                    return;
                }

                const cuentas = (this.state.catalogos.cuentas || []).filter(function (cu) {
                    return cu.activo !== 0 && cu.id_emisora == idEmi;
                });

                if (cuentas.length === 0) {
                    sel.innerHTML = '<option value="" disabled>Esta emisora no tiene cuentas bancarias configuradas</option>';
                    this.state.selectedCuentas = [];
                    this.actualizarCuentasCount();
                    return;
                }

                /* Llenar el Multi-select con TODAS las cuentas (3 marcadas por defecto) */
                sel.innerHTML = cuentas.map(function (cu, i) {
                    const selected = i < 3 ? 'selected' : '';
                    const label = cu.banco + ' - Cuenta: ' + cu.numero_cuenta +
                        (cu.cci ? ' - CCI: ' + cu.cci : '') +
                        ' (' + (cu.moneda || 'PEN') + ')';
                    return '<option value="' + cu.id_cuenta + '" ' + selected + ' style="padding:6px;">' +
                        App.escapeHtml(label) + '</option>';
                }).join('');

                /* Pre-seleccionar las 3 primeras */
                this.state.selectedCuentas = cuentas.slice(0, 3).map(function (cu) { return cu.id_cuenta; });

                /* Listener para cambios */
                const self = this;
                sel.onchange = function () {
                    const seleccionadas = Array.from(sel.selectedOptions).map(function (opt) {
                        return parseInt(opt.value, 10);
                    });
                    self.state.selectedCuentas = seleccionadas;
                    self.actualizarCuentasCount();
                };

                this.actualizarCuentasCount();
            },

            seleccionarTodasCuentas(seleccionar) {
                const sel = this.state.container.querySelector('#cuentasMultiSelect');
                if (!sel) return;
                Array.from(sel.options).forEach(function (opt) {
                    opt.selected = seleccionar;
                });
                const seleccionadas = Array.from(sel.selectedOptions).map(function (opt) {
                    return parseInt(opt.value, 10);
                });
                this.state.selectedCuentas = seleccionadas;
                this.actualizarCuentasCount();
            },

            actualizarCuentasCount() {
                const el = this.state.container.querySelector('#cuentasCount');
                if (el) el.textContent = this.state.selectedCuentas.length;
            },

            async actualizarPreviewCodigo() {
                const c = this.state.container;
                const idTipo = c.querySelector('#id_tipo').value;
                const idEmpresa = c.querySelector('#id_empresa').value;
                const valEl = c.querySelector('#codigoPreviewValueV7');
                const warnEl = c.querySelector('#codigoPreviewWarningV7');

                if (!idTipo || !idEmpresa) {
                    valEl.textContent = '(Seleccione empresa para ver el código)';
                    warnEl.style.display = 'none';
                    return;
                }

                try {
                    const r = await App.request('/cotizaciones/preview_codigo?id_empresa=' + idEmpresa + '&id_tipo=' + idTipo);
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

            addItem(prod) {
                const c = this.state.container;
                const tbody = c.querySelector('#detalleBody');
                const idx = tbody.children.length + 1;
                const tr = document.createElement('tr');
                tr.innerHTML = '<td style="padding:6px;">' + idx + '</td>' +
                    '<td style="padding:6px;"><input type="text" class="form-input item-desc-v7" style="width:100%;" placeholder="Descripción"></td>' +
                    '<td style="padding:6px;"><input type="number" class="form-input item-cant-v7" style="width:70px;text-align:right;" value="1" step="0.01" min="0"></td>' +
                    '<td style="padding:6px;"><input type="number" class="form-input item-precio-v7" style="width:100px;text-align:right;" value="0" step="0.01" min="0"></td>' +
                    '<td style="padding:6px;"><input type="number" class="form-input item-desc-pct-v7" style="width:60px;text-align:right;" value="0" step="0.01" min="0" max="100"></td>' +
                    '<td style="padding:6px;text-align:right;font-weight:600;" class="item-subtotal-v7">0.00</td>' +
                    '<td style="padding:6px;text-align:center;"><button type="button" class="btn btn-sm btn-danger" style="padding:2px 6px;font-size:10px;" onclick="this.closest(\'tr\').remove(); App.views[\'cotizacion-nueva\'].recalcularTotales();">✗</button></td>';
                tbody.appendChild(tr);

                const self = this;
                tr.querySelector('.item-cant-v7').addEventListener('input', function () { self.recalcItemSubtotal(tr); });
                tr.querySelector('.item-precio-v7').addEventListener('input', function () { self.recalcItemSubtotal(tr); });
                tr.querySelector('.item-desc-pct-v7').addEventListener('input', function () { self.recalcItemSubtotal(tr); });
                this.recalcItemSubtotal(tr);
            },

            recalcItemSubtotal(tr) {
                const cant = parseFloat(tr.querySelector('.item-cant-v7').value) || 0;
                const precio = parseFloat(tr.querySelector('.item-precio-v7').value) || 0;
                const descPct = parseFloat(tr.querySelector('.item-desc-pct-v7').value) || 0;
                const subtotal = cant * precio * (1 - descPct / 100);
                tr.querySelector('.item-subtotal-v7').textContent = subtotal.toFixed(2);
                this.recalcularTotales();
            },

            recalcularTotales() {
                const c = this.state.container;
                const rows = c.querySelectorAll('#detalleBody tr');
                let subtotal = 0;
                rows.forEach(function (tr) {
                    const cant = parseFloat(tr.querySelector('.item-cant-v7').value) || 0;
                    const precio = parseFloat(tr.querySelector('.item-precio-v7').value) || 0;
                    const descPct = parseFloat(tr.querySelector('.item-desc-pct-v7').value) || 0;
                    subtotal += cant * precio * (1 - descPct / 100);
                });
                const igvPct = parseFloat(c.querySelector('#igv_pct').value) || 0;
                const descGlobal = parseFloat(c.querySelector('#descuento_global_pct').value) || 0;
                const subtotalConDesc = subtotal * (1 - descGlobal / 100);
                const igv = subtotalConDesc * (igvPct / 100);
                const total = subtotalConDesc + igv;

                const totEl = c.querySelector('#totalesResumen');
                if (totEl) {
                    totEl.innerHTML =
                        '<div style="display:flex;justify-content:space-between;padding:3px 0;"><span>Subtotal:</span><strong>S/ ' + subtotal.toFixed(2) + '</strong></div>' +
                        '<div style="display:flex;justify-content:space-between;padding:3px 0;"><span>Descuento global (' + descGlobal + '%):</span><strong>- S/ ' + (subtotal * descGlobal / 100).toFixed(2) + '</strong></div>' +
                        '<div style="display:flex;justify-content:space-between;padding:3px 0;"><span>IGV (' + igvPct + '%):</span><strong>S/ ' + igv.toFixed(2) + '</strong></div>' +
                        '<div style="display:flex;justify-content:space-between;padding:6px 0 0 0;border-top:2px solid #2c3e50;margin-top:6px;font-size:14px;"><strong>TOTAL:</strong><strong>S/ ' + total.toFixed(2) + '</strong></div>';
                }
            },

            obtenerDetalle() {
                const c = this.state.container;
                const rows = c.querySelectorAll('#detalleBody tr');
                const detalle = [];
                rows.forEach(function (tr) {
                    const desc = tr.querySelector('.item-desc-v7').value.trim();
                    if (!desc) return;
                    const cant = parseFloat(tr.querySelector('.item-cant-v7').value) || 0;
                    const prec = parseFloat(tr.querySelector('.item-precio-v7').value) || 0;
                    const descPct = parseFloat(tr.querySelector('.item-desc-pct-v7').value) || 0;
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
                const usr = App.state.user || window.AppUser || {};

                if (this.state.selectedCuentas.length === 0) {
                    App.toast('Seleccione al menos una cuenta bancaria', 'warning');
                    return;
                }

                const cuentasSeleccionadas = this.state.selectedCuentas.slice();
                const idCuentaPrincipal = cuentasSeleccionadas[0];
                const cuentasAdicionales = cuentasSeleccionadas.slice(1);

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
                    id_cuenta_bancaria: idCuentaPrincipal,
                    cuentas_adicionales: cuentasAdicionales,
                    cuentas_adicionales_json: JSON.stringify(cuentasAdicionales),
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
                    const r = await App.request('/cotizaciones', { method: 'POST', body: body });
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

        log('Vista cotizacion-nueva sobreescrita');

        /* ================================================================
         * 2. AÑADIR VISTA configuracion-logo
         * ================================================================*/
        App.views['configuracion-logo'] = {
            async render(container) {
                container.innerHTML = `
                    <div class="card-header mb-4">
                        <h1 style="color:var(--c-primary)">
                            <i class="bi bi-image-fill"></i> Configuración de Logo de Cotizaciones
                        </h1>
                        <p style="color:#7f8c8d;font-size:13px;margin:6px 0 0 0;">
                            Suba o reemplace el logo que aparecerá en el encabezado de las cotizaciones en PDF y reportes.
                        </p>
                    </div>
                    <div class="card">
                        <div id="emisorasLogoList" style="padding:14px;">
                            <div class="loading-container"><div class="spinner"></div></div>
                        </div>
                    </div>`;

                await this.load();
            },

            async load() {
                const cont = document.getElementById('emisorasLogoList');
                if (!cont) return;
                try {
                    const r = await App.request('/cotizaciones_emisora');
                    const rows = r.data || r;
                    const emisoras = Array.isArray(rows) ? rows : [];

                    if (emisoras.length === 0) {
                        cont.innerHTML = '<div class="empty-state"><div class="empty-state__icon">📭</div><div class="empty-state__title">Sin emisoras</div></div>';
                        return;
                    }

                    cont.innerHTML = emisoras.map(function (e) {
                        return '<div style="border:1px solid #e0e0e0;border-radius:8px;padding:14px;margin-bottom:12px;display:grid;grid-template-columns:160px 1fr;gap:14px;' + (e.es_principal ? 'border-color:#1976d2;background:#f3f9ff;' : '') + '">' +
                            '<div style="width:160px;height:90px;border:2px dashed #bdc3c7;display:flex;align-items:center;justify-content:center;background:#fafbfc;border-radius:4px;overflow:hidden;">' +
                                (e.tiene_logo
                                    ? '<img src="/api/v1/cotizaciones_emisora/' + e.id_emisora + '/logo" style="max-width:100%;max-height:100%;object-fit:contain;">'
                                    : '<span style="color:#bdc3c7;font-size:11px;">(Sin logo)</span>') +
                            '</div>' +
                            '<div>' +
                                '<h3 style="margin:0 0 4px 0;color:#2c3e50;">' + App.escapeHtml(e.razon_social) +
                                    (e.es_principal ? ' <span style="background:#1976d2;color:#fff;padding:2px 6px;border-radius:10px;font-size:9px;">PRINCIPAL</span>' : '') +
                                    (e.tiene_logo
                                        ? ' <span style="color:#28a745;font-size:11px;">✓ Con logo</span>'
                                        : ' <span style="color:#dc3545;font-size:11px;">⚠ Sin logo</span>') +
                                '</h3>' +
                                '<div style="font-size:12px;color:#7f8c8d;margin-bottom:10px;">RUC: ' + App.escapeHtml(e.ruc || '—') + '</div>' +
                                '<button class="btn btn-sm btn-primary" onclick="App.views[\'configuracion-logo\'].subirLogo(' + e.id_emisora + ', \'' + App.escapeHtml(e.razon_social).replace(/'/g, "\\'") + '\')">' +
                                    '<i class="bi bi-image"></i> ' + (e.tiene_logo ? 'Reemplazar' : 'Subir') + ' logo' +
                                '</button>' +
                                (e.tiene_logo
                                    ? ' <button class="btn btn-sm btn-danger" onclick="App.views[\'configuracion-logo\'].eliminarLogo(' + e.id_emisora + ')"><i class="bi bi-trash"></i> Eliminar</button>'
                                    : '') +
                            '</div>' +
                        '</div>';
                    }).join('');
                } catch (e) {
                    cont.innerHTML = '<div class="empty-state"><div class="empty-state__icon">⚠️</div><div class="empty-state__title">Error</div><div class="empty-state__desc">' + App.escapeHtml(e.message) + '</div></div>';
                }
            },

            subirLogo(idEmisora, nombre) {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/png,image/jpeg,image/svg+xml,image/gif,image/webp';
                input.onchange = function (e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    if (file.size > 2 * 1024 * 1024) {
                        App.toast('El archivo excede 2 MB', 'error');
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = async function (ev) {
                        try {
                            await App.request('/cotizaciones_emisora/' + idEmisora + '/logo', {
                                method: 'POST',
                                body: { logo_base64: ev.target.result }
                            });
                            App.toast('Logo actualizado', 'success');
                            App.views['configuracion-logo'].load();
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
                    await App.request('/cotizaciones_emisora/' + idEmisora + '/logo', { method: 'DELETE' });
                    App.toast('Logo eliminado', 'success');
                    this.load();
                } catch (e) {
                    App.toast('Error: ' + e.message, 'error');
                }
            }
        };

        log('Vista configuracion-logo añadida');

        /* ================================================================
         * 3. PATCH: App.views.configuracion — añadir botón "Configurar Logo"
         * ================================================================*/
        const originalConfigRender = App.views.configuracion.render
            ? App.views.configuracion.render.bind(App.views.configuracion)
            : null;

        if (originalConfigRender) {
            App.views.configuracion.render = async function (container) {
                await originalConfigRender(container);

                /* Buscar la tarjeta "Catálogos del Sistema" y añadir el botón */
                setTimeout(function () {
                    const cards = container.querySelectorAll('.card');
                    cards.forEach(function (card) {
                        const title = card.querySelector('.card-title');
                        if (title && title.textContent.includes('Catálogos')) {
                            const existingLink = card.querySelector('a[href="#/configuracion-logo"]');
                            if (!existingLink) {
                                const btn = document.createElement('a');
                                btn.href = '#/configuracion-logo';
                                btn.className = 'btn btn-secondary';
                                btn.style.cssText = 'text-align:left;background:#1976d2;color:#fff;';
                                btn.innerHTML = '<i class="bi bi-image"></i> Logo de Cotizaciones';
                                card.querySelector('div[style*="grid"]')?.appendChild(btn);
                            }
                        }
                    });
                }, 100);
            };
            log('Vista configuracion parcheada con botón "Logo Cotizaciones"');
        }

        /* ================================================================
         * 4. AÑADIR entrada de menú lateral
         * ================================================================*/
        function addMenuEntry() {
            const sidebar = document.querySelector('.sidebar, .menu, nav.menu, [role="navigation"], .nav-menu, .navigation, .app-sidebar');
            if (!sidebar) {
                setTimeout(addMenuEntry, 1000);
                return;
            }
            if (sidebar.querySelector('a[href="#/configuracion-logo"]') &&
                sidebar.querySelector('a[href="#/dashboard-cotizaciones"]')) return;

            /* Clonar el estilo de un enlace existente */
            const existingLink = sidebar.querySelector('a[href^="#/"]');
            const styleAttr = existingLink ? existingLink.getAttribute('style') || '' : '';
            const classAttr = existingLink ? existingLink.className : '';

            /* Buscar enlace a cotizaciones para insertar el dashboard cerca */
            const cotiLink = sidebar.querySelector('a[href="#/cotizaciones"]');
            /* Buscar enlace a tickets para insertar el dashboard de atenciones cerca */
            const ticketsLink = sidebar.querySelector('a[href="#/tickets"]');

            /* Añadir entrada Dashboard de Atenciones (antes de Tickets) */
            if (ticketsLink && !sidebar.querySelector('a[href="#/dashboard-atenciones"]')) {
                const aDashAte = document.createElement('a');
                aDashAte.href = '#/dashboard-atenciones';
                aDashAte.className = classAttr;
                aDashAte.setAttribute('style', styleAttr);
                aDashAte.innerHTML = '<i class="bi bi-clipboard-data"></i> Dashboard Atenciones';
                ticketsLink.parentNode.insertBefore(aDashAte, ticketsLink);
                log('Entrada "Dashboard Atenciones" añadida al menú');
            }

            /* Añadir entrada Dashboard de Cotizaciones (antes de Cotizaciones) */
            if (cotiLink && !sidebar.querySelector('a[href="#/dashboard-cotizaciones"]')) {
                const aDash = document.createElement('a');
                aDash.href = '#/dashboard-cotizaciones';
                aDash.className = classAttr;
                aDash.setAttribute('style', styleAttr);
                aDash.innerHTML = '<i class="bi bi-graph-up"></i> Dashboard Cotizaciones';
                cotiLink.parentNode.insertBefore(aDash, cotiLink);
                log('Entrada "Dashboard Cotizaciones" añadida al menú');
            }

            /* Añadir entrada Auditoría por Usuario (después de Auditoría) */
            if (!sidebar.querySelector('a[href="#/auditoria-usuarios"]')) {
                const audLink = sidebar.querySelector('a[href="#/auditoria"]');
                if (audLink) {
                    const aAud = document.createElement('a');
                    aAud.href = '#/auditoria-usuarios';
                    aAud.className = classAttr;
                    aAud.setAttribute('style', styleAttr);
                    aAud.innerHTML = '<i class="bi bi-person-check"></i> Auditoría por Usuario';
                    const wAud = audLink.parentNode;
                    if (wAud && wAud.nextSibling) {
                        wAud.parentNode.insertBefore(aAud, wAud.nextSibling);
                    } else if (wAud) {
                        wAud.parentNode.appendChild(aAud);
                    }
                    log('Entrada "Auditoría por Usuario" añadida al menú');
                }
            }

            /* Añadir entrada Logo Cotizaciones (después de Configuración) */
            if (!sidebar.querySelector('a[href="#/configuracion-logo"]')) {
                const configLink = sidebar.querySelector('a[href="#/configuracion"]');
                if (configLink) {
                    const a = document.createElement('a');
                    a.href = '#/configuracion-logo';
                    a.className = classAttr;
                    a.setAttribute('style', styleAttr);
                    a.innerHTML = '<i class="bi bi-image"></i> Logo Cotizaciones';
                    const wrapper = configLink.parentNode;
                    if (wrapper && wrapper.nextSibling) {
                        wrapper.parentNode.insertBefore(a, wrapper.nextSibling);
                    } else if (wrapper) {
                        wrapper.parentNode.appendChild(a);
                    }
                    log('Entrada "Logo Cotizaciones" añadida al menú');
                }
            }
        }

        addMenuEntry();
        window.addEventListener('hashchange', function () { setTimeout(addMenuEntry, 200); });


        /* ================================================================
         * 5. PATCH: App.views['cotizacion-detalle'] - Override del boton Imprimir
         *    para usar el endpoint V8 (cotizaciones_print.php modificado con logo)
         * ================================================================*/
        const originalDetalleRender = App.views['cotizacion-detalle'] && App.views['cotizacion-detalle'].render
            ? App.views['cotizacion-detalle'].render.bind(App.views['cotizacion-detalle'])
            : null;

        if (originalDetalleRender) {
            App.views['cotizacion-detalle'].render = async function (container, params) {
                await originalDetalleRender(container, params);

                /* Esperar a que el DOM se renderice */
                setTimeout(function () {
                    /* Override del boton Imprimir para usar el endpoint con logo */
                    const btnImprimir = container.querySelector('#btnImprimir');
                    if (btnImprimir) {
                        btnImprimir.onclick = function () {
                            const idCot = (App.views['cotizacion-detalle'].state || {}).idCot || params[0];
                            window.open(App.config.apiBase + '/cotizaciones_print/' + idCot, '_blank');
                        };
                    }

                    /* Anadir botones de exportacion con logo si no existen */
                    const header = container.querySelector('.card-header');
                    if (header && !header.querySelector('#btnExportV9Xlsx')) {
                        const btnXlsx = document.createElement('button');
                        btnXlsx.id = 'btnExportV9Xlsx';
                        btnXlsx.className = 'btn btn-success';
                        btnXlsx.style.cssText = 'background:#28a745;border-color:#28a745;';
                        btnXlsx.innerHTML = '<i class="bi bi-file-excel"></i> Excel';
                        btnXlsx.onclick = function () {
                            const idCot = (App.views['cotizacion-detalle'].state || {}).idCot || params[0];
                            window.open(App.config.apiBase + '/cotizaciones/' + idCot + '/export/xlsx', '_blank');
                        };
                        header.querySelector('div[style*="display:flex;gap:6px"]')?.appendChild(btnXlsx);

                        const btnCsv = document.createElement('button');
                        btnCsv.id = 'btnExportV9Csv';
                        btnCsv.className = 'btn btn-secondary';
                        btnCsv.innerHTML = '<i class="bi bi-file-text"></i> CSV';
                        btnCsv.onclick = function () {
                            const idCot = (App.views['cotizacion-detalle'].state || {}).idCot || params[0];
                            window.open(App.config.apiBase + '/cotizaciones/' + idCot + '/export/csv', '_blank');
                        };
                        header.querySelector('div[style*="display:flex;gap:6px"]')?.appendChild(btnCsv);
                    }

                    /* Ocultar Zona de Pricing si esta visible */
                    const zonaPricing = container.querySelector('#id_zona_pricing, [name="id_zona_pricing"]');
                    if (zonaPricing) {
                        const zonaDiv = zonaPricing.closest('div');
                        if (zonaDiv) zonaDiv.style.display = 'none';
                    }
                }, 500);
            };
            log('Vista cotizacion-detalle parcheada (boton Imprimir usa endpoint con logo)');
        }

        /* ================================================================
         * 6. VISTA: Dashboard de Cotizaciones (V15)
         * ================================================================*/
        App.views['dashboard-cotizaciones'] = {
            async render(container) {
                container.innerHTML = `
                    <div class="view-header">
                        <h2><i class="bi bi-graph-up"></i> Dashboard de Cotizaciones</h2>
                        <div class="view-actions">
                            <select id="dash-anio" class="form-select" style="width:120px;" onchange="App.views['dashboard-cotizaciones'].load()">
                                <option value="">Todos los años</option>
                            </select>
                            <button class="btn btn-primary" onclick="App.navigate('/cotizaciones')">
                                <i class="bi bi-list"></i> Ver listado
                            </button>
                        </div>
                    </div>
                    <div id="dash-content">
                        <div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando dashboard...</p></div>
                    </div>`;

                /* Cargar años disponibles */
                try {
                    const r = await App.request('/cotizaciones_dashboard', { background: true });
                    const data = r.data || r;
                    if (data && data.anios_disponibles) {
                        const sel = document.getElementById('dash-anio');
                        const anioActual = new Date().getFullYear();
                        sel.innerHTML = '<option value="">Todos los años</option>' +
                            data.anios_disponibles.map(a =>
                                `<option value="${a}" ${a == anioActual ? 'selected' : ''}>${a}</option>`
                            ).join('');
                    }
                } catch (e) {}

                await this.load();
            },

            async load() {
                const cont = document.getElementById('dash-content');
                if (!cont) return;
                const anio = document.getElementById('dash-anio')?.value || '';

                try {
                    let url = '/cotizaciones_dashboard';
                    if (anio) url += '?anio=' + anio;
                    const r = await App.request(url, { background: true });
                    const data = r.data || r;
                    if (!data) {
                        cont.innerHTML = '<div class="card-body text-center text-muted">Sin datos</div>';
                        return;
                    }

                    const resumen = data.resumen || {};
                    const porEstado = data.por_estado || [];
                    const porMes = data.por_mes || [];

                    /* KPIs */
                    const fmtMoneda = (v) => 'S/ ' + Number(v || 0).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    const kpis = [
                        { label: 'Total Cotizaciones', value: resumen.total_cotizaciones || 0, icon: 'bi-file-earmark-text', color: '#1976d2' },
                        { label: 'Monto Total', value: fmtMoneda(resumen.total_monto), icon: 'bi-currency-dollar', color: '#28a745' },
                        { label: 'Aprobadas', value: resumen.aprobadas || 0, icon: 'bi-check-circle', color: '#28a745' },
                        { label: 'Pendientes', value: resumen.pendientes || 0, icon: 'bi-clock', color: '#ffc107' },
                        { label: 'Rechazadas', value: resumen.rechazadas || 0, icon: 'bi-x-circle', color: '#dc3545' },
                        { label: 'Tasa Aprobación', value: (resumen.tasa_aprobacion || 0) + '%', icon: 'bi-percent', color: '#17a2b8' },
                    ];

                    cont.innerHTML = `
                        <!-- KPIs -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
                            ${kpis.map(k => `
                                <div class="card" style="padding:16px;">
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <div style="width:48px;height:48px;border-radius:8px;background:${k.color}20;display:flex;align-items:center;justify-content:center;">
                                            <i class="bi ${k.icon}" style="font-size:24px;color:${k.color};"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:11px;color:#7f8c8d;text-transform:uppercase;">${k.label}</div>
                                            <div style="font-size:20px;font-weight:700;color:#2c3e50;">${k.value}</div>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>

                        <!-- GRAFICOS -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                            <div class="card">
                                <div class="card-header"><h5>Cotizaciones por Estado</h5></div>
                                <div class="card-body">
                                    <canvas id="chart-estado" height="250"></canvas>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header"><h5>Cotizaciones por Mes ${anio || '(año actual)'}</h5></div>
                                <div class="card-body">
                                    <canvas id="chart-mes" height="250"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- TABLA DETALLADA POR ESTADO -->
                        <div class="card">
                            <div class="card-header"><h5>Detalle por Estado</h5></div>
                            <div class="card-body" style="padding:0;">
                                <table class="table table-sm" style="margin:0;">
                                    <thead>
                                        <tr>
                                            <th>Estado</th>
                                            <th style="text-align:center;">Cantidad</th>
                                            <th style="text-align:right;">Monto Total</th>
                                            <th style="text-align:center;">% del Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${porEstado.length > 0 ? porEstado.map(e => {
                                            const pct = resumen.total_cotizaciones > 0
                                                ? ((e.total / resumen.total_cotizaciones) * 100).toFixed(1) : 0;
                                            const colores = {
                                                'BORRADOR': '#6c757d', 'PENDIENTE_ENVIO': '#17a2b8',
                                                'ENVIADA': '#007bff', 'EN_SEGUIMIENTO': '#ffc107',
                                                'EN_NEGOCIACION': '#fd7e14', 'APROBADA': '#28a745',
                                                'RECHAZADA': '#dc3545', 'CANCELADA': '#343a40', 'CERRADA': '#198754'
                                            };
                                            const color = colores[e.estado] || '#6c757d';
                                            return `<tr>
                                                <td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};margin-right:6px;"></span>${e.estado}</td>
                                                <td style="text-align:center;font-weight:600;">${e.total}</td>
                                                <td style="text-align:right;">${fmtMoneda(e.monto)}</td>
                                                <td style="text-align:center;">${pct}%</td>
                                            </tr>`;
                                        }).join('') : '<tr><td colspan="4" style="text-align:center;color:#95a5a6;padding:16px;">Sin datos</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;

                    /* Renderizar graficos con Chart.js */
                    if (typeof Chart !== 'undefined') {
                        /* Grafico de estados (doughnut) */
                        const ctxEstado = document.getElementById('chart-estado');
                        if (ctxEstado) {
                            const colores = {
                                'BORRADOR': '#6c757d', 'PENDIENTE_ENVIO': '#17a2b8',
                                'ENVIADA': '#007bff', 'EN_SEGUIMIENTO': '#ffc107',
                                'EN_NEGOCIACION': '#fd7e14', 'APROBADA': '#28a745',
                                'RECHAZADA': '#dc3545', 'CANCELADA': '#343a40', 'CERRADA': '#198754'
                            };
                            new Chart(ctxEstado, {
                                type: 'doughnut',
                                data: {
                                    labels: porEstado.map(e => e.estado),
                                    datasets: [{
                                        data: porEstado.map(e => e.total),
                                        backgroundColor: porEstado.map(e => colores[e.estado] || '#6c757d'),
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: { position: 'right', labels: { font: { size: 11 } } }
                                    }
                                }
                            });
                        }

                        /* Grafico de meses (bar) */
                        const ctxMes = document.getElementById('chart-mes');
                        if (ctxMes) {
                            new Chart(ctxMes, {
                                type: 'bar',
                                data: {
                                    labels: porMes.map(m => m.mes_nombre.substring(0, 3)),
                                    datasets: [{
                                        label: 'Cotizaciones',
                                        data: porMes.map(m => m.total),
                                        backgroundColor: '#1976d2',
                                        borderColor: '#1565c0',
                                        borderWidth: 1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        y: { beginAtZero: true, ticks: { precision: 0 } }
                                    },
                                    plugins: {
                                        legend: { display: false }
                                    }
                                }
                            });
                        }
                    }

                } catch (e) {
                    cont.innerHTML = '<div class="card-body text-center text-danger">Error: ' + App.escapeHtml(e.message) + '</div>';
                }
            }
        };
        log('Vista dashboard-cotizaciones añadida');

        /* ================================================================
         * 7. VISTA: Dashboard de Atenciones (V16)
         * ================================================================*/
        App.views['dashboard-atenciones'] = {
            async render(container) {
                container.innerHTML = `
                    <div class="view-header">
                        <h2><i class="bi bi-clipboard-data"></i> Dashboard de Atenciones</h2>
                        <div class="view-actions">
                            <select id="dash-ate-anio" class="form-select" style="width:120px;" onchange="App.views['dashboard-atenciones'].load()">
                                <option value="">Todos los años</option>
                            </select>
                            <button class="btn btn-primary" onclick="App.navigate('/tickets')">
                                <i class="bi bi-list"></i> Ver tickets
                            </button>
                        </div>
                    </div>
                    <div id="dash-ate-content">
                        <div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando dashboard...</p></div>
                    </div>`;

                /* Cargar años disponibles */
                try {
                    const r = await App.request('/atenciones_dashboard', { background: true });
                    const data = r.data || r;
                    if (data && data.anios_disponibles) {
                        const sel = document.getElementById('dash-ate-anio');
                        const anioActual = new Date().getFullYear();
                        sel.innerHTML = '<option value="">Todos los años</option>' +
                            data.anios_disponibles.map(a =>
                                `<option value="${a}" ${a == anioActual ? 'selected' : ''}>${a}</option>`
                            ).join('');
                    }
                } catch (e) {}

                await this.load();
            },

            async load() {
                const cont = document.getElementById('dash-ate-content');
                if (!cont) return;
                const anio = document.getElementById('dash-ate-anio')?.value || '';

                try {
                    let url = '/atenciones_dashboard';
                    if (anio) url += '?anio=' + anio;
                    const r = await App.request(url, { background: true });
                    const data = r.data || r;
                    if (!data) {
                        cont.innerHTML = '<div class="card-body text-center text-muted">Sin datos</div>';
                        return;
                    }

                    const resumen = data.resumen || {};
                    const porEstado = data.por_estado || [];
                    const porMes = data.por_mes || [];
                    const porPrioridad = data.por_prioridad || [];

                    /* KPIs */
                    const kpis = [
                        { label: 'Total Tickets', value: resumen.total_tickets || 0, icon: 'bi-ticket-detailed', color: '#1976d2' },
                        { label: 'Pendientes', value: resumen.pendientes || 0, icon: 'bi-clock', color: '#ffc107' },
                        { label: 'En Atención', value: resumen.en_atencion || 0, icon: 'bi-arrow-repeat', color: '#17a2b8' },
                        { label: 'Resueltos', value: resumen.resueltos || 0, icon: 'bi-check-circle', color: '#28a745' },
                        { label: 'Cerrados', value: resumen.cerrados || 0, icon: 'bi-lock', color: '#198754' },
                        { label: 'Críticos', value: resumen.criticos || 0, icon: 'bi-exclamation-triangle', color: '#dc3545' },
                        { label: 'Tiempo Prom.', value: resumen.tiempo_promedio_formato || '—', icon: 'bi-stopwatch', color: '#6c757d' },
                    ];

                    cont.innerHTML = `
                        <!-- KPIs -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
                            ${kpis.map(k => `
                                <div class="card" style="padding:14px;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:42px;height:42px;border-radius:8px;background:${k.color}20;display:flex;align-items:center;justify-content:center;">
                                            <i class="bi ${k.icon}" style="font-size:20px;color:${k.color};"></i>
                                        </div>
                                        <div>
                                            <div style="font-size:10px;color:#7f8c8d;text-transform:uppercase;">${k.label}</div>
                                            <div style="font-size:18px;font-weight:700;color:#2c3e50;">${k.value}</div>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>

                        <!-- GRAFICOS -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                            <div class="card">
                                <div class="card-header"><h5>Tickets por Estado</h5></div>
                                <div class="card-body">
                                    <canvas id="chart-ate-estado" height="250"></canvas>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-header"><h5>Tickets por Mes ${anio || '(año actual)'}</h5></div>
                                <div class="card-body">
                                    <canvas id="chart-ate-mes" height="250"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- GRAFICO PRIORIDAD -->
                        <div class="card" style="margin-bottom:20px;">
                            <div class="card-header"><h5>Tickets por Prioridad</h5></div>
                            <div class="card-body">
                                <canvas id="chart-ate-prioridad" height="100"></canvas>
                            </div>
                        </div>

                        <!-- TABLA DETALLADA -->
                        <div class="card">
                            <div class="card-header"><h5>Detalle por Estado</h5></div>
                            <div class="card-body" style="padding:0;">
                                <table class="table table-sm" style="margin:0;">
                                    <thead>
                                        <tr>
                                            <th>Estado</th>
                                            <th style="text-align:center;">Cantidad</th>
                                            <th style="text-align:center;">% del Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${porEstado.length > 0 ? porEstado.map(e => {
                                            const pct = resumen.total_tickets > 0
                                                ? ((e.total / resumen.total_tickets) * 100).toFixed(1) : 0;
                                            const colores = {
                                                'PENDIENTE': '#ffc107', 'EN_ATENCION': '#17a2b8',
                                                'RESUELTO': '#28a745', 'CERRADO': '#198754',
                                                'ANULADO': '#dc3545', 'REABIERTO': '#fd7e14'
                                            };
                                            const color = colores[e.estado] || '#6c757d';
                                            return `<tr>
                                                <td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};margin-right:6px;"></span>${e.estado}</td>
                                                <td style="text-align:center;font-weight:600;">${e.total}</td>
                                                <td style="text-align:center;">${pct}%</td>
                                            </tr>`;
                                        }).join('') : '<tr><td colspan="3" style="text-align:center;color:#95a5a6;padding:16px;">Sin datos</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;

                    /* Renderizar graficos */
                    if (typeof Chart !== 'undefined') {
                        /* Grafico de estados (doughnut) */
                        const ctxEstado = document.getElementById('chart-ate-estado');
                        if (ctxEstado) {
                            const colores = {
                                'PENDIENTE': '#ffc107', 'EN_ATENCION': '#17a2b8',
                                'RESUELTO': '#28a745', 'CERRADO': '#198754',
                                'ANULADO': '#dc3545', 'REABIERTO': '#fd7e14'
                            };
                            new Chart(ctxEstado, {
                                type: 'doughnut',
                                data: {
                                    labels: porEstado.map(e => e.estado),
                                    datasets: [{
                                        data: porEstado.map(e => e.total),
                                        backgroundColor: porEstado.map(e => colores[e.estado] || '#6c757d'),
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } }
                                }
                            });
                        }

                        /* Grafico de meses (bar) - total vs resueltos */
                        const ctxMes = document.getElementById('chart-ate-mes');
                        if (ctxMes) {
                            new Chart(ctxMes, {
                                type: 'bar',
                                data: {
                                    labels: porMes.map(m => m.mes_nombre.substring(0, 3)),
                                    datasets: [
                                        {
                                            label: 'Total',
                                            data: porMes.map(m => m.total),
                                            backgroundColor: '#1976d2',
                                        },
                                        {
                                            label: 'Resueltos',
                                            data: porMes.map(m => m.resueltos),
                                            backgroundColor: '#28a745',
                                        }
                                    ]
                                },
                                options: {
                                    responsive: true,
                                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                                    plugins: { legend: { position: 'top' } }
                                }
                            });
                        }

                        /* Grafico de prioridad (horizontal bar) */
                        const ctxPrio = document.getElementById('chart-ate-prioridad');
                        if (ctxPrio) {
                            const coloresPrio = {
                                'CRITICA': '#dc3545', 'ALTA': '#fd7e14',
                                'MEDIA': '#ffc107', 'BAJA': '#28a745'
                            };
                            new Chart(ctxPrio, {
                                type: 'bar',
                                data: {
                                    labels: porPrioridad.map(p => p.prioridad),
                                    datasets: [{
                                        label: 'Tickets',
                                        data: porPrioridad.map(p => p.total),
                                        backgroundColor: porPrioridad.map(p => coloresPrio[p.prioridad] || '#6c757d'),
                                    }]
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                                    plugins: { legend: { display: false } }
                                }
                            });
                        }
                    }

                } catch (e) {
                    cont.innerHTML = '<div class="card-body text-center text-danger">Error: ' + App.escapeHtml(e.message) + '</div>';
                }
            }
        };
        log('Vista dashboard-atenciones añadida');

        /* ================================================================
         * 8. VISTA: Auditoría por Usuario (V17)
         * ================================================================*/
        App.views['auditoria-usuarios'] = {
            state: { page: 1, perPage: 50, filters: {} },

            async render(container) {
                container.innerHTML = `
                    <div class="view-header">
                        <h2><i class="bi bi-shield-check"></i> Auditoría por Usuario</h2>
                        <div class="view-actions">
                            <button class="btn btn-success" onclick="App.views['auditoria-usuarios'].exportar('csv')">
                                <i class="bi bi-file-excel"></i> CSV
                            </button>
                            <button class="btn btn-success" onclick="App.views['auditoria-usuarios'].exportar('xlsx')">
                                <i class="bi bi-file-excel"></i> Excel
                            </button>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom:12px;">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Usuario</label>
                                    <select id="aud-id-usuario" class="form-select" onchange="App.views['auditoria-usuarios'].applyFilters()">
                                        <option value="">Todos</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Módulo</label>
                                    <select id="aud-modulo" class="form-select" onchange="App.views['auditoria-usuarios'].applyFilters()">
                                        <option value="">Todos</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Desde</label>
                                    <input type="date" id="aud-desde" class="form-input" onchange="App.views['auditoria-usuarios'].applyFilters()">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Hasta</label>
                                    <input type="date" id="aud-hasta" class="form-input" onchange="App.views['auditoria-usuarios'].applyFilters()">
                                </div>
                                <div class="form-group" style="display:flex;align-items:flex-end;gap:4px;">
                                    <button class="btn btn-secondary" onclick="App.views['auditoria-usuarios'].applyFilters()">
                                        <i class="bi bi-funnel"></i> Filtrar
                                    </button>
                                    <button class="btn btn-link" onclick="App.views['auditoria-usuarios'].clearFilters()">Limpiar</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="aud-table-wrapper" class="card">
                        <div class="card-body">
                            <div class="loading-container">Cargando...</div>
                        </div>
                    </div>
                    <div id="aud-pagination-wrapper"></div>`;

                await this.load();
            },

            async applyFilters() {
                this.state.page = 1;
                this.state.filters = {};
                const idU = document.getElementById('aud-id-usuario')?.value;
                const mod = document.getElementById('aud-modulo')?.value;
                const des = document.getElementById('aud-desde')?.value;
                const has = document.getElementById('aud-hasta')?.value;
                if (idU) this.state.filters.id_usuario = idU;
                if (mod) this.state.filters.modulo = mod;
                if (des) this.state.filters.fecha_desde = des;
                if (has) this.state.filters.fecha_hasta = has;
                await this.load();
            },

            async clearFilters() {
                ['aud-id-usuario','aud-modulo','aud-desde','aud-hasta'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                this.state.filters = {};
                this.state.page = 1;
                await this.load();
            },

            async load() {
                const wrapper = document.getElementById('aud-table-wrapper');
                if (!wrapper) return;
                try {
                    const params = new URLSearchParams({
                        page: this.state.page,
                        per_page: this.state.perPage,
                        ...this.state.filters,
                    });
                    const r = await App.request('/auditoria_usuarios?' + params.toString(), { background: true });
                    const data = r.data || r;
                    if (!data) { wrapper.innerHTML = '<div class="card-body text-center text-muted">Sin datos</div>'; return; }

                    const rows = data.rows || [];
                    const total = data.total || 0;
                    const totalPages = data.pages || 0;

                    /* Llenar filtros de usuario y modulo (solo la primera vez) */
                    if (data.usuarios && document.getElementById('aud-id-usuario').options.length <= 1) {
                        document.getElementById('aud-id-usuario').innerHTML = '<option value="">Todos</option>' +
                            data.usuarios.map(u => `<option value="${u.id_usuario}">${App.escapeHtml(u.nombre || u.correo_usuario || 'ID ' + u.id_usuario)}</option>`).join('');
                    }
                    if (data.modulos && document.getElementById('aud-modulo').options.length <= 1) {
                        document.getElementById('aud-modulo').innerHTML = '<option value="">Todos</option>' +
                            data.modulos.map(m => `<option value="${m}">${App.escapeHtml(m)}</option>`).join('');
                    }

                    if (rows.length === 0) {
                        wrapper.innerHTML = '<div class="card-body text-center text-muted">No hay registros de auditoría.</div>';
                        document.getElementById('aud-pagination-wrapper').innerHTML = '';
                        return;
                    }

                    wrapper.innerHTML = `
                        <div class="card-body" style="padding:0;overflow-x:auto;">
                            <table class="table table-sm table-hover" style="margin:0;font-size:12px;">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Módulo</th>
                                        <th>Acción</th>
                                        <th>Descripción</th>
                                        <th>Tabla</th>
                                        <th>IP</th>
                                        <th>Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rows.map(r => `
                                        <tr>
                                            <td>${App.escapeHtml(r.fecha_hora || '')}</td>
                                            <td>${App.escapeHtml(r.nombre_completo || r.correo_usuario || '—')}</td>
                                            <td><span class="badge badge-info">${App.escapeHtml(r.modulo || '')}</span></td>
                                            <td>${App.escapeHtml(r.accion || '')}</td>
                                            <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;">${App.escapeHtml(r.descripcion || '')}</td>
                                            <td>${App.escapeHtml(r.tabla_afectada || '—')}</td>
                                            <td>${App.escapeHtml(r.ip_address || '—')}</td>
                                            <td>${r.resultado === 'EXITO'
                                                ? '<span class="badge badge-success">ÉXITO</span>'
                                                : '<span class="badge badge-danger">' + App.escapeHtml(r.resultado || '') + '</span>'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>`;

                    document.getElementById('aud-pagination-wrapper').innerHTML = `
                        <div class="pagination" style="padding:8px;">
                            <button class="btn btn-link" ${this.state.page === 1 ? 'disabled' : ''} onclick="App.views['auditoria-usuarios'].goPage(${this.state.page - 1})">← Anterior</button>
                            <span>Página ${this.state.page} de ${totalPages} (total: ${total})</span>
                            <button class="btn btn-link" ${this.state.page >= totalPages ? 'disabled' : ''} onclick="App.views['auditoria-usuarios'].goPage(${this.state.page + 1})">Siguiente →</button>
                        </div>`;
                } catch (e) {
                    wrapper.innerHTML = '<div class="card-body text-danger">Error: ' + App.escapeHtml(e.message) + '</div>';
                }
            },

            async goPage(p) {
                this.state.page = p;
                await this.load();
            },

            exportar(formato) {
                const params = new URLSearchParams({
                    export: formato,
                    ...this.state.filters,
                });
                window.open(App.config.apiBase + '/auditoria_usuarios?' + params.toString(), '_blank');
            }
        };
        log('Vista auditoria-usuarios añadida');

        log('Todos los patches aplicados correctamente');
    });

})();
