/**
 * assets/js/cotizaciones_view.js
 *
 * Vista compacta del módulo de Cotizaciones.
 * Lista paginada + detalle + impresión (incluye logo de la emisora).
 * Diseñado para integrarse con App ya existente en el repo (assets/js/app.js).
 * Cargar en index.php (o layout) después de app.js:
 *   <script src="assets/js/cotizaciones_view.js" defer></script>
 */
(function () {
    'use strict';

    if (!window.App) { console.error('CotizacionesView: falta App global'); return; }
    const App = window.App;
    const $ = (s, ctx = document) => ctx.querySelector(s);
    const $$ = (s, ctx = document) => Array.from(ctx.querySelectorAll(s));

    const state = {
        page: 1, perPage: 25, total: 0,
        filters: { estado: '', id_empresa: '', search: '', incluir_eliminados: 0 },
    };

    async function api(path, opts = {}) {
        const cfg = Object.assign({ method: 'GET', background: true }, opts);
        return App.request('/cotizaciones' + path, cfg);
    }

    function fmt(s) { return s == null ? '' : String(s); }
    function money(n, cur) {
        const v = Number(n || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return cur ? v + ' ' + cur : v;
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    async function renderList(container) {
        const f = state.filters;
        const qs = new URLSearchParams({
            page: state.page, per_page: state.perPage,
            estado: f.estado || '', id_empresa: f.id_empresa || '',
            search: f.search || '',
            incluir_eliminados: f.incluir_eliminados ? 1 : 0,
        }).toString();
        const res = await api('?' + qs);
        const items = res.items || res.data?.items || [];
        state.total = res.total || items.length;

        container.innerHTML = `
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                    <h3 style="margin:0">Cotizaciones</h3>
                    ${App.can('COTIZACIONES', 'registrar') ? '<button class="btn btn-primary" id="btnNuevaCot">+ Nueva cotización</button>' : ''}
                </div>
                <div class="card-body">
                    <form class="form-inline" id="cotFilter" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                        <input type="text" name="search" placeholder="Buscar código, cliente, RUC..." value="${esc(f.search)}" class="form-control" style="min-width:260px">
                        <select name="estado" class="form-control">
                            <option value="">Todos los estados</option>
                            ${['BORRADOR','PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO','EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','CERRADA']
                                .map(e => `<option value="${e}" ${f.estado === e ? 'selected' : ''}>${e.replace('_', ' ')}</option>`).join('')}
                        </select>
                        <label class="checkbox"><input type="checkbox" name="eliminados" ${f.incluir_eliminados ? 'checked' : ''}> Incluir eliminadas</label>
                        <button class="btn btn-secondary" type="submit">Filtrar</button>
                        ${App.can('COTIZACIONES', 'exportar') ? '<button class="btn btn-success" type="button" id="btnExportCsv">Exportar CSV</button>' : ''}
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr>
                                <th>Código</th><th>Cliente</th><th>Tipo</th><th>Estado</th>
                                <th style="text-align:right">Total</th><th>Fecha</th><th>Acciones</th>
                            </tr></thead>
                            <tbody>
                                ${items.length === 0
                                    ? '<tr><td colspan="7" class="text-center text-muted">Sin cotizaciones que mostrar.</td></tr>'
                                    : items.map(it => `
                                        <tr>
                                            <td><strong>${esc(it.codigo)}</strong><br><small>v.${it.version_numero}</small></td>
                                            <td>${esc(it.empresa_razon_social)}<br><small>${esc(it.empresa_ruc)}</small></td>
                                            <td>${esc(it.tipo_nombre)}</td>
                                            <td>${estadoBadge(it.estado)}</td>
                                            <td style="text-align:right">${money(it.total, it.moneda)}</td>
                                            <td>${esc((it.fecha_creacion || '').substring(0, 10))}</td>
                                            <td>
                                                ${App.can('COTIZACIONES', 'ver') ? `<button class="btn btn-sm btn-info" data-act="view" data-id="${it.id_cotizacion}">Ver</button>` : ''}
                                                ${App.can('COTIZACIONES', 'imprimir') ? `<button class="btn btn-sm btn-secondary" data-act="print" data-id="${it.id_cotizacion}">Imprimir</button>` : ''}
                                                ${App.can('COTIZACIONES', 'modificar') ? `<button class="btn btn-sm btn-warning" data-act="edit" data-id="${it.id_cotizacion}">Editar</button>` : ''}
                                            </td>
                                        </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span class="text-muted">Total: ${state.total} · Página ${state.page}</span>
                        <div style="display:flex;gap:8px">
                            <button class="btn btn-sm" id="btnPrev" ${state.page <= 1 ? 'disabled' : ''}>Anterior</button>
                            <button class="btn btn-sm" id="btnNext" ${items.length < state.perPage ? 'disabled' : ''}>Siguiente</button>
                        </div>
                    </div>
                </div>
            </div>`;

        $('#cotFilter').addEventListener('submit', (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            state.filters.search = fd.get('search') || '';
            state.filters.estado = fd.get('estado') || '';
            state.filters.incluir_eliminados = !!fd.get('eliminados');
            state.page = 1;
            renderList(container);
        });
        $('#btnPrev').addEventListener('click', () => { if (state.page > 1) { state.page--; renderList(container); } });
        $('#btnNext').addEventListener('click', () => { state.page++; renderList(container); });
        $('#btnNuevaCot')?.addEventListener('click', () => renderForm(container, null));
        $('#btnExportCsv')?.addEventListener('click', () => exportCsv(items));

        $$('#cotizaciones-list button[data-act]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                const act = btn.dataset.act;
                if (act === 'view' || act === 'edit') await renderDetail(container, id, act === 'edit');
                else if (act === 'print') openPrint(id);
            });
        });
    }

    function estadoBadge(e) {
        const map = {
            BORRADOR:        'badge-secondary',
            PENDIENTE_ENVIO: 'badge-info',
            ENVIADA:         'badge-primary',
            EN_SEGUIMIENTO:  'badge-info',
            EN_NEGOCIACION:  'badge-warning',
            APROBADA:        'badge-success',
            RECHAZADA:       'badge-danger',
            CANCELADA:       'badge-danger',
            CERRADA:         'badge-dark',
        };
        return `<span class="badge ${map[e] || 'badge-secondary'}">${esc(e.replace('_', ' '))}</span>`;
    }

    function exportCsv(items) {
        const head = ['Código', 'Cliente', 'RUC', 'Tipo', 'Estado', 'Total', 'Moneda', 'Fecha'];
        const lines = [head.join(';')];
        items.forEach(it => {
            lines.push([
                it.codigo, it.empresa_razon_social, it.empresa_ruc, it.tipo_nombre,
                it.estado, it.total, it.moneda, (it.fecha_creacion || '').substring(0, 10)
            ].map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(';'));
        });
        const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'cotizaciones_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }

    async function renderDetail(container, id, editable) {
        try {
            const res = await api('/' + id);
            const c = res.cabecera || res.data?.cabecera;
            if (!c) throw new Error('No se pudo obtener la cotización');
            const detalle = res.detalle || res.data?.detalle || [];

            const logo = (c.emisora_logo || '').trim();
            const logoHtml = logo
                ? `<img src="${esc(logo)}" alt="logo" style="max-height:80px;max-width:220px;object-fit:contain">`
                : '<div class="text-muted">[sin logo configurado]</div>';

            const rowsHtml = detalle.map((d, i) => `
                <tr>
                    <td style="text-align:center">${i + 1}</td>
                    <td>${esc(d.codigo)}</td>
                    <td>${esc(d.descripcion)}</td>
                    <td style="text-align:right">${Number(d.cantidad).toFixed(2)}</td>
                    <td style="text-align:center">${esc(d.unidad_medida)}</td>
                    <td style="text-align:right">${Number(d.precio_unitario).toFixed(2)}</td>
                    <td style="text-align:right">${Number(d.subtotal).toFixed(2)}</td>
                </tr>`).join('');

            container.innerHTML = `
                <div class="card">
                    <div class="card-header" style="display:flex;justify-content:space-between">
                        <h3 style="margin:0">Cotización ${esc(c.codigo)} ${estadoBadge(c.estado)}</h3>
                        <div>
                            ${App.can('COTIZACIONES', 'imprimir') ? `<button class="btn btn-secondary" id="btnPrint">Imprimir / Exportar</button>` : ''}
                            ${editable && App.can('COTIZACIONES', 'modificar') ? `<button class="btn btn-warning" id="btnEdit">Editar</button>` : ''}
                            <button class="btn btn-light" id="btnBack">Volver</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #333;padding-bottom:12px;gap:24px">
                            <div>${logoHtml}</div>
                            <div style="text-align:right">
                                <h2 style="margin:0 0 4px 0">${esc(c.tipo_titulo_default || c.titulo_documento)}</h2>
                                <div><strong>Código:</strong> ${esc(c.codigo)} · <strong>Versión:</strong> ${c.version_numero}</div>
                                <div><strong>Fecha:</strong> ${esc((c.fecha_creacion || '').substring(0, 10))}</div>
                                <div><strong>${esc(c.emisora_razon_social)}</strong> · RUC ${esc(c.emisora_ruc)}</div>
                                <div>${esc(c.emisora_direccion || '')} · ${esc(c.emisora_correo || '')}</div>
                            </div>
                        </div>

                        <div style="margin:14px 0">
                            <strong>Cliente:</strong> ${esc(c.empresa_razon_social)} · RUC ${esc(c.empresa_ruc)}<br>
                            <strong>Contacto:</strong> ${esc((c.contacto_nombres || '') + ' ' + (c.contacto_apellidos || ''))}
                            ${c.contacto_cargo ? ' · ' + esc(c.contacto_cargo) : ''}
                            ${c.contacto_correo ? ' · ' + esc(c.contacto_correo) : ''}
                        </div>

                        <table class="table table-bordered">
                            <thead><tr>
                                <th>#</th><th>Código</th><th>Descripción</th>
                                <th style="text-align:right">Cant.</th><th style="text-align:center">U.M.</th>
                                <th style="text-align:right">P. Unit.</th><th style="text-align:right">Subtotal</th>
                            </tr></thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>

                        <div style="margin-left:55%">
                            <table class="table table-sm">
                                <tr><td>Subtotal</td><td style="text-align:right">${money(c.subtotal, c.moneda)}</td></tr>
                                <tr><td>Descuento (${Number(c.descuento_global_pct || 0).toFixed(2)}%)</td>
                                    <td style="text-align:right">-${money(c.descuento_global_monto, c.moneda)}</td></tr>
                                <tr><td>Base imponible</td><td style="text-align:right">${money(c.base_imponible, c.moneda)}</td></tr>
                                <tr><td>IGV (${Number(c.igv_pct || 0).toFixed(2)}%)</td>
                                    <td style="text-align:right">${money(c.igv_monto, c.moneda)}</td></tr>
                                <tr class="table-active" style="font-weight:bold">
                                    <td>TOTAL (${esc(c.moneda)})</td><td style="text-align:right">${money(c.total, c.moneda)}</td>
                                </tr>
                            </table>
                        </div>

                        <div style="margin-top:18px;font-size:12px">
                            <p><strong>Forma de pago:</strong> ${esc(c.snapshot_forma_pago || '-')}</p>
                            <p><strong>Garantía:</strong> ${esc(c.snapshot_garantia || '-')}</p>
                            <p><strong>Plazo entrega:</strong> ${esc(c.snapshot_plazo_entrega || '-')}</p>
                            <p><strong>Validez:</strong> ${esc(c.snapshot_validez || '-')}</p>
                            <p><strong>Cuenta bancaria:</strong> ${esc(c.snapshot_cuenta_bancaria || '-')}</p>
                            ${c.observaciones ? `<p><strong>Observaciones:</strong><br>${esc(c.observaciones)}</p>` : ''}
                        </div>
                    </div>
                </div>`;

            $('#btnBack').addEventListener('click', () => renderList(container));
            $('#btnPrint')?.addEventListener('click', () => openPrint(id));
            $('#btnEdit')?.addEventListener('click', () => renderForm(container, c));
        } catch (e) {
            container.innerHTML = `<div class="alert alert-danger">Error: ${esc(e.message)}</div>`;
        }
    }

    async function renderForm(container, existing) {
        try {
            const cat = await api('/catalogos');
            const c = existing || { moneda: 'PEN', igv_pct: 18, descuento_global_pct: 0, detalle: [] };

            container.innerHTML = `
                <div class="card">
                    <div class="card-header"><h3 style="margin:0">${existing ? 'Editar' : 'Nueva'} cotización</h3></div>
                    <div class="card-body">
                        <form id="cotForm">
                            <div class="row">
                                <div class="col-md-6"><label>Empresa cliente *</label>
                                    <input type="number" name="id_empresa" value="${c.id_empresa || ''}" class="form-control" required></div>
                                <div class="col-md-6"><label>Tipo *</label>
                                    <select name="id_tipo" class="form-control" required>
                                        ${(cat.tipos || []).map(t => `<option value="${t.id_tipo}" ${c.id_tipo == t.id_tipo ? 'selected' : ''}>${esc(t.nombre)} (${esc(t.prefijo_codigo)})</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Empresa emisora *</label>
                                    <select name="id_emisora" class="form-control" required>
                                        ${(cat.emisoras || []).map(e => `<option value="${e.id_emisora}" ${c.id_emisora == e.id_emisora ? 'selected' : ''}>${esc(e.razon_social)} (RUC ${esc(e.ruc)})</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Forma de pago</label>
                                    <select name="id_forma_pago" class="form-control">
                                        <option value="">—</option>
                                        ${(cat.formas_pago || []).map(f => `<option value="${f.id_forma_pago}" ${c.id_forma_pago == f.id_forma_pago ? 'selected' : ''}>${esc(f.descripcion)}</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Garantía</label>
                                    <select name="id_garantia" class="form-control">
                                        <option value="">—</option>
                                        ${(cat.garantias || []).map(g => `<option value="${g.id_garantia}" ${c.id_garantia == g.id_garantia ? 'selected' : ''}>${esc(g.descripcion)}</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Plazo de entrega</label>
                                    <select name="id_plazo_entrega" class="form-control">
                                        <option value="">—</option>
                                        ${(cat.plazos || []).map(p => `<option value="${p.id_plazo}" ${c.id_plazo_entrega == p.id_plazo ? 'selected' : ''}>${esc(p.descripcion)}</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Validez</label>
                                    <select name="id_validez" class="form-control">
                                        <option value="">—</option>
                                        ${(cat.valideces || []).map(v => `<option value="${v.id_validez}" ${c.id_validez == v.id_validez ? 'selected' : ''}>${esc(v.descripcion)} (${v.dias}d)</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Zona pricing</label>
                                    <select name="id_zona_pricing" class="form-control">
                                        <option value="">—</option>
                                        ${(cat.zonas || []).map(z => `<option value="${z.id_zona}" ${c.id_zona_pricing == z.id_zona ? 'selected' : ''}>${esc(z.nombre)} (${esc(z.codigo)})</option>`).join('')}
                                    </select></div>
                                <div class="col-md-6"><label>Título</label>
                                    <input type="text" name="titulo_documento" value="${esc(c.titulo_documento || '')}" class="form-control"></div>
                                <div class="col-md-6"><label>Motivo</label>
                                    <input type="text" name="motivo" value="${esc(c.motivo || '')}" class="form-control"></div>
                                <div class="col-md-12"><label>Observaciones</label>
                                    <textarea name="observaciones" class="form-control" rows="2">${esc(c.observaciones || '')}</textarea></div>
                                <div class="col-md-4"><label>Moneda</label>
                                    <select name="moneda" class="form-control">
                                        ${['PEN','USD','EUR'].map(m => `<option value="${m}" ${c.moneda === m ? 'selected' : ''}>${m}</option>`).join('')}
                                    </select></div>
                                <div class="col-md-4"><label>IGV %</label>
                                    <input type="number" step="0.01" name="igv_pct" value="${c.igv_pct ?? 18}" class="form-control"></div>
                                <div class="col-md-4"><label>Descuento global %</label>
                                    <input type="number" step="0.01" name="descuento_global_pct" value="${c.descuento_global_pct ?? 0}" class="form-control"></div>
                            </div>

                            <h4 style="margin-top:18px">Detalle</h4>
                            <div id="detalleRows"></div>
                            <button type="button" class="btn btn-sm btn-success" id="btnAddRow">+ Agregar item</button>

                            <div style="margin-top:18px">
                                <button type="submit" class="btn btn-primary">Guardar</button>
                                <button type="button" class="btn btn-light" id="btnCancelForm">Cancelar</button>
                            </div>
                        </form>
                    </div>
                </div>`;

            const detalleRows = $('#detalleRows');
            const drawRow = (d = {}) => `
                <div class="row" style="margin-bottom:6px;gap:6px">
                    <input type="text" class="form-control col-2" name="d_codigo[]" value="${esc(d.codigo || '')}" placeholder="Código">
                    <input type="text" class="form-control col-4" name="d_desc[]" value="${esc(d.descripcion || '')}" placeholder="Descripción" required>
                    <input type="number" step="0.01" class="form-control col-1" name="d_cant[]" value="${d.cantidad ?? 1}" placeholder="Cant.">
                    <input type="text" class="form-control col-1" name="d_um[]" value="${esc(d.unidad_medida || 'UND')}" placeholder="U.M.">
                    <input type="number" step="0.01" class="form-control col-2" name="d_pu[]" value="${d.precio_unitario ?? 0}" placeholder="P. Unit.">
                    <input type="number" step="0.01" class="form-control col-1" name="d_desc_pct[]" value="${d.descuento_pct ?? 0}" placeholder="Desc.%">
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()">×</button>
                </div>`;

            (c.detalle?.length ? c.detalle : [{}]).forEach(d => detalleRows.insertAdjacentHTML('beforeend', drawRow(d)));
            $('#btnAddRow').addEventListener('click', () => detalleRows.insertAdjacentHTML('beforeend', drawRow()));
            $('#btnCancelForm').addEventListener('click', () => renderList(container));

            $('#cotForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                const detalle = [];
                const cods = fd.getAll('d_codigo[]');
                const descs = fd.getAll('d_desc[]');
                const cants = fd.getAll('d_cant[]');
                const ums = fd.getAll('d_um[]');
                const pus = fd.getAll('d_pu[]');
                const dcts = fd.getAll('d_desc_pct[]');
                for (let i = 0; i < descs.length; i++) {
                    if (!descs[i]) continue;
                    detalle.push({
                        codigo: cods[i] || null,
                        descripcion: descs[i],
                        cantidad: parseFloat(cants[i] || 1),
                        unidad_medida: ums[i] || 'UND',
                        precio_unitario: parseFloat(pus[i] || 0),
                        descuento_pct: parseFloat(dcts[i] || 0),
                        afecto_igv: 1,
                    });
                }

                const body = {
                    id_empresa: parseInt(fd.get('id_empresa')),
                    id_tipo: parseInt(fd.get('id_tipo')),
                    id_emisora: parseInt(fd.get('id_emisora')),
                    id_forma_pago: parseInt(fd.get('id_forma_pago')) || null,
                    id_garantia: parseInt(fd.get('id_garantia')) || null,
                    id_plazo_entrega: parseInt(fd.get('id_plazo_entrega')) || null,
                    id_validez: parseInt(fd.get('id_validez')) || null,
                    id_zona_pricing: parseInt(fd.get('id_zona_pricing')) || null,
                    titulo_documento: fd.get('titulo_documento') || null,
                    motivo: fd.get('motivo') || null,
                    observaciones: fd.get('observaciones') || null,
                    moneda: fd.get('moneda'),
                    igv_pct: parseFloat(fd.get('igv_pct')),
                    descuento_global_pct: parseFloat(fd.get('descuento_global_pct')),
                    detalle,
                };

                try {
                    if (existing) {
                        body.forzar_version = fd.get('forzar_version') ? 1 : 0;
                        await api('/' + existing.id_cotizacion, { method: 'PUT', body: JSON.stringify(body) });
                    } else {
                        await api('', { method: 'POST', body: JSON.stringify(body) });
                    }
                    App.toast && App.toast('Cotización guardada', 'success');
                    renderList(container);
                } catch (err) {
                    App.toast && App.toast('Error: ' + err.message, 'danger');
                }
            });
        } catch (e) {
            container.innerHTML = `<div class="alert alert-danger">No se pudo cargar catálogos: ${esc(e.message)}</div>`;
        }
    }

    function openPrint(id) {
        const url = (App.config?.apiBase || '') + '/cotizaciones/' + id + '/print';
        // como el endpoint devuelve HTML, lo abrimos en ventana nueva
        window.open(url, '_blank', 'width=900,height=700,scrollbars=yes');
    }

    // === EXPORT: registrar vista en App ===
    window.CotizacionesView = {
        renderList,
        renderDetail,
        renderForm,
        openPrint,
    };

    // Registrar la vista en el router de views (si existe)
    if (window.Views && typeof window.Views.register === 'function') {
        window.Views.register('cotizaciones', {
            render: (container) => renderList(container),
            title: 'Cotizaciones',
        });
    } else {
        // fallback: exponer función global para que index.php la llame
        window.renderCotizacionesView = renderList;
    }
})();
