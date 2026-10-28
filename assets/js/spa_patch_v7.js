/**
 * spa_patch_v7.js — PATCH AGRESIVO DE LA SPA
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
 *    1. Copiar este archivo a: assets/js/spa_patch_v7.js
 *    2. Agregar en index.php (raíz del proyecto), AL FINAL del <body>:
 *         <script src="/assets/js/spa_patch_v7.js?v=7"></script>
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
        console.log('%c[SPA Patch V7] ' + msg, 'color:#1976d2;font-weight:bold;');
    }

    function esperarApp(callback, intento) {
        intento = intento || 0;
        if (intento > 50) {  // 50 intentos x 100ms = 5 segundos máximo
            console.error('[SPA Patch V7] App no disponible después de 5s');
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
                console.error('[SPA Patch V7] cargarCatalogos:', e);
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
                            <div id="cuentasInfo" style="margin-top:10px;padding:8px;background:#e3f2fd;border-radius:4px;font-size:11px;color:#1976d2;">
                                <strong>Cuentas seleccionadas:</strong> <span id="cuentasCount">0</span>
                                · Estas cuentas aparecerán automáticamente en el PDF de la cotización.
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
                    console.error('[SPA Patch V7] cargarEmpresas:', e);
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
                    console.error('[SPA Patch V7] cargarSucursalesYContactos:', e);
                }
            },

            cargarCuentasPorEmisora() {
                const c = this.state.container;
                const idEmi = c.querySelector('#id_emisora').value;
                if (!idEmi) return;

                const cuentas = (this.state.catalogos.cuentas || []).filter(function (cu) {
                    return cu.activo !== 0 && cu.id_emisora == idEmi;
                });

                const cuentasTop3 = cuentas.slice(0, 3);
                this.state.selectedCuentas = cuentasTop3.map(function (cu) { return cu.id_cuenta; });

                const cont = c.querySelector('#cuentasCheckboxContainer');
                if (cuentas.length === 0) {
                    cont.innerHTML = '<div style="padding:14px;text-align:center;color:#dc3545;background:#fff5f5;border-radius:4px;font-size:12px;">⚠ Esta emisora no tiene cuentas bancarias configuradas.</div>';
                } else {
                    cont.innerHTML = cuentas.map(function (cu, i) {
                        const checked = i < 3 ? 'checked' : '';
                        const principalClass = i === 0 ? 'principal' : '';
                        const monedaColor = cu.moneda === 'PEN' ? '#28a745' : (cu.moneda === 'USD' ? '#007bff' : '#6c757d');
                        return '<label class="cuenta-item-v7 ' + principalClass + '" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid ' + (i < 3 ? '#28a745' : '#dee2e6') + ';border-radius:6px;cursor:pointer;' + (i < 3 ? 'background:#f0fff4;' : '') + '">' +
                            '<input type="checkbox" class="cuenta-checkbox-v7" value="' + cu.id_cuenta + '" ' + checked + ' style="width:18px;height:18px;cursor:pointer;">' +
                            '<div style="flex:1;">' +
                                '<div style="font-weight:600;color:#2c3e50;font-size:13px;">' + App.escapeHtml(cu.banco) +
                                    (i === 0 ? ' <span style="background:#28a745;color:#fff;padding:1px 6px;border-radius:8px;font-size:9px;margin-left:6px;">PRINCIPAL</span>' : '') +
                                '</div>' +
                                '<div style="font-size:11px;color:#7f8c8d;font-family:monospace;">Cuenta: ' + App.escapeHtml(cu.numero_cuenta) +
                                    (cu.cci ? ' · CCI: ' + App.escapeHtml(cu.cci) : '') + '</div>' +
                                (cu.titular ? '<div style="font-size:10px;color:#95a5a6;">Titular: ' + App.escapeHtml(cu.titular) + '</div>' : '') +
                            '</div>' +
                            '<div><span style="background:' + monedaColor + ';color:#fff;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;">' + App.escapeHtml(cu.moneda || 'PEN') + '</span></div>' +
                        '</label>';
                    }).join('');

                    /* Bind change events */
                    const self = this;
                    cont.querySelectorAll('.cuenta-checkbox-v7').forEach(function (chk) {
                        chk.onchange = function () {
                            const idCuenta = parseInt(chk.value, 10);
                            if (chk.checked) {
                                if (self.state.selectedCuentas.indexOf(idCuenta) === -1) {
                                    self.state.selectedCuentas.push(idCuenta);
                                }
                            } else {
                                self.state.selectedCuentas = self.state.selectedCuentas.filter(function (id) { return id !== idCuenta; });
                            }
                            self.actualizarCuentasCount();
                        };
                    });
                }
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
            if (sidebar.querySelector('a[href="#/configuracion-logo"]')) return;

            /* Clonar el estilo de un enlace existente */
            const existingLink = sidebar.querySelector('a[href^="#/"]');
            const styleAttr = existingLink ? existingLink.getAttribute('style') || '' : '';
            const classAttr = existingLink ? existingLink.className : '';

            /* Buscar un enlace a configuracion para insertar cerca */
            const configLink = sidebar.querySelector('a[href="#/configuracion"]');
            const insertAfter = configLink || existingLink;

            if (insertAfter) {
                const a = document.createElement('a');
                a.href = '#/configuracion-logo';
                a.className = classAttr;
                a.setAttribute('style', styleAttr);
                a.innerHTML = '<i class="bi bi-image"></i> Logo Cotizaciones';

                const wrapper = insertAfter.parentNode;
                if (wrapper && wrapper.nextSibling) {
                    wrapper.parentNode.insertBefore(a, wrapper.nextSibling);
                } else if (wrapper) {
                    wrapper.parentNode.appendChild(a);
                } else {
                    sidebar.appendChild(a);
                }
                log('Entrada "Logo Cotizaciones" añadida al menú');
            }
        }

        addMenuEntry();
        window.addEventListener('hashchange', function () { setTimeout(addMenuEntry, 200); });

        log('Todos los patches aplicados correctamente');
    });

})();
