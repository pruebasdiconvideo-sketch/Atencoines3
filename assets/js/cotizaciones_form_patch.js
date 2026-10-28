/**
 * assets/js/cotizaciones_form_patch.js
 *
 * Parche para integrar en views.js (o cargar después de views.js):
 *   1. Reemplaza el <select> de cuentas bancarias por una LISTA DE CHECKBOXES.
 *   2. Añade UI para SUBIR EL LOGO de la empresa emisora.
 *   3. Lee la respuesta nueva del API: res.items o res.data (ambos válidos).
 *
 * Forma de integrar:
 *   - Pegar este contenido al final de assets/js/views.js, o
 *   - Cargar como <script src="assets/js/cotizaciones_form_patch.js" defer></script>
 *     después de views.js en index.php.
 */
(function () {
    'use strict';
    if (!window.App || !window.App.views) {
        console.warn('[cotizaciones_form_patch] Falta App.views. Cargar después de views.js');
        return;
    }
    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');

    /* ============================================================
     * 1) Cuentas bancarias como LISTA DE CHECKBOXES
     *    Recibe: array de cuentas (de /cotizaciones/cuentas?id_emisora=X
     *    o del resultset cuentas_emisora del GET /cotizaciones/{id}).
     *    Devuelve HTML con checkboxes para seleccionar la principal
     *    (radio) y las adicionales (checkboxes).
     * ============================================================ */
    function renderCuentasLista(cuentas, seleccionadas = {}) {
        // seleccionadas = { principal: id_cuenta, adicionales: [id_cuenta, ...] }
        if (!Array.isArray(cuentas) || cuentas.length === 0) {
            return '<div class="text-muted" style="padding:8px;border:1px dashed #ccc;border-radius:4px">'
                + 'Esta emisora no tiene cuentas bancarias configuradas. '
                + 'Agregue cuentas desde la administración de empresas emisoras.'
                + '</div>';
        }
        const principal = seleccionadas.principal || null;
        const adicionales = Array.isArray(seleccionadas.adicionales) ? seleccionadas.adicionales : [];

        const items = cuentas.map(c => {
            const idC = parseInt(c.id_cuenta);
            const esPrincipal = principal == idC;
            const esAdicional = adicionales.includes(idC);
            const label = `${esc(c.banco)} · Cuenta: ${esc(c.numero_cuenta)}`
                + (c.cci ? ` · CCI: ${esc(c.cci)}` : '')
                + ` · ${esc(c.moneda || 'PEN')}`
                + (c.titular ? ` · Titular: ${esc(c.titular)}` : '')
                + (c.acepta_tarjeta == 1 ? ' · Acepta tarjeta' : '');

            return `
                <div style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid #eee">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;width:100%">
                        <input type="radio" name="id_cuenta_bancaria"
                               value="${idC}" ${esPrincipal ? 'checked' : ''}
                               style="margin-top:4px">
                        <input type="checkbox" name="cuentas_adicionales[]" value="${idC}"
                               ${esAdicional ? 'checked' : ''}
                               style="margin-top:4px">
                        <span style="flex:1">${label}</span>
                    </label>
                </div>`;
        }).join('');

        return `
            <div style="border:1px solid #ccc;border-radius:4px;padding:8px;margin-top:4px">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:6px;border-bottom:1px dotted #ccc;padding-bottom:4px">
                    <span><i class="bi bi-info-circle"></i> Marca <strong>●</strong> la cuenta principal y <strong>☑</strong> las adicionales a mostrar en la cotización.</span>
                </div>
                ${items}
            </div>`;
    }

    /* ============================================================
     * 2) Logo uploader para la emisora
     *    Muestra el logo actual + input para subir uno nuevo.
     *    Llama a POST /cotizaciones/emisora/{id}/logo con FormData.
     * ============================================================ */
    function renderLogoUploader(idEmisora, logoActual = '') {
        const preview = logoActual
            ? `<img src="${esc(logoActual)}" alt="logo" style="max-height:80px;max-width:200px;object-fit:contain;border:1px solid #ccc;padding:4px;border-radius:4px">`
            : '<div style="padding:20px;border:2px dashed #ccc;color:#888;text-align:center;font-size:11px">Sin logo</div>';

        return `
            <div id="logoUploader" data-id-emisora="${idEmisora || ''}" style="display:flex;gap:12px;align-items:flex-start;padding:8px;border:1px solid #e0e0e0;border-radius:4px">
                <div id="logoPreview">${preview}</div>
                <div style="flex:1">
                    <input type="file" id="logoFile" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp" style="font-size:11px">
                    <button type="button" class="btn btn-sm btn-primary" id="btnUploadLogo" ${idEmisora ? '' : 'disabled'}>
                        <i class="bi bi-upload"></i> Subir logo
                    </button>
                    <button type="button" class="btn btn-sm btn-light" id="btnRemoveLogo" ${logoActual ? '' : 'disabled'}>
                        <i class="bi bi-trash"></i> Quitar
                    </button>
                    <div id="logoMsg" style="font-size:11px;margin-top:4px"></div>
                </div>
            </div>`;
    }

    async function subirLogo(idEmisora, fileInput) {
        const file = fileInput.files?.[0];
        if (!file) { App.toast('Selecciona un archivo primero', 'warning'); return; }
        if (file.size > 2 * 1024 * 1024) { App.toast('El logo no debe pesar más de 2 MB', 'danger'); return; }

        const fd = new FormData();
        fd.append('logo', file);
        const msg = document.getElementById('logoMsg');
        if (msg) msg.textContent = 'Subiendo...';
        try {
            const r = await fetch(`${App.config.apiBase}/cotizaciones/emisora/${idEmisora}/logo`, {
                method: 'POST', credentials: 'include', body: fd,
            });
            const j = await r.json();
            if (r.ok && j.ok) {
                App.toast('Logo actualizado', 'success');
                // Refrescar preview
                const prev = document.getElementById('logoPreview');
                if (prev) prev.innerHTML = `<img src="${esc(j.data.logo_url)}" alt="logo" style="max-height:80px;max-width:200px;object-fit:contain;border:1px solid #ccc;padding:4px;border-radius:4px">`;
                if (msg) msg.textContent = '';
            } else {
                throw new Error(j.message || 'Error al subir logo');
            }
        } catch (err) {
            if (msg) msg.textContent = 'Error: ' + err.message;
            App.toast('Error: ' + err.message, 'danger');
        }
    }

    async function quitarLogo(idEmisora) {
        if (!idEmisora) return;
        // No hay endpoint DELETE en la API; hacemos POST con base64 vacío
        // (el SP sp_cotizacion_emisora_logo_save con logo_base64=NULL no borra,
        //  pero un UPDATE directo en PHP sí). Por simplicidad, llamamos al SP
        //  con una cadena vacía que efectivamente "limpia" el logo.
        try {
            const r = await fetch(`${App.config.apiBase}/cotizaciones/emisora/${idEmisora}/logo`, {
                method: 'DELETE', credentials: 'include',
            });
            if (r.ok) {
                App.toast('Logo quitado', 'success');
                const prev = document.getElementById('logoPreview');
                if (prev) prev.innerHTML = '<div style="padding:20px;border:2px dashed #ccc;color:#888;text-align:center;font-size:11px">Sin logo</div>';
            } else {
                App.toast('No se pudo quitar el logo', 'warning');
            }
        } catch (err) {
            App.toast('Error: ' + err.message, 'danger');
        }
    }

    /* ============================================================
     * 3) Helpers de parseo de respuesta (res.items o res.data o array)
     *    Para que el frontend sea robusto a los cambios de formato.
     * ============================================================ */
    function extractItems(res) {
        if (Array.isArray(res)) return res;
        if (Array.isArray(res?.items)) return res.items;
        if (Array.isArray(res?.data)) return res.data;
        if (Array.isArray(res?.data?.items)) return res.data.items;
        return [];
    }
    function extractTotal(res, fallback = 0) {
        if (typeof res?.total === 'number') return res.total;
        if (typeof res?.data?.total === 'number') return res.data.total;
        return fallback;
    }

    /* ============================================================
     * 4) Exponer helpers globalmente para que views.js los use
     * ============================================================ */
    window.CotizacionesForm = {
        renderCuentasLista,
        renderLogoUploader,
        subirLogo,
        quitarLogo,
        extractItems,
        extractTotal,
    };

    // Auto-binding cuando el DOM del form está listo
    document.addEventListener('click', (e) => {
        const up = e.target.closest('#btnUploadLogo');
        if (up) {
            const container = up.closest('#logoUploader');
            const idE = parseInt(container?.dataset.idEmisora || 0);
            const fi = document.getElementById('logoFile');
            if (idE && fi) subirLogo(idE, fi);
        }
        const rm = e.target.closest('#btnRemoveLogo');
        if (rm) {
            const container = rm.closest('#logoUploader');
            const idE = parseInt(container?.dataset.idEmisora || 0);
            if (idE) quitarLogo(idE);
        }
    });
})();

/* ============================================================
 * EJEMPLO de uso desde views.js (form de cotización):
 *
 *   async function cargarCuentasParaForm(idEmisora, container) {
 *       const res = await App.request(`/cotizaciones/cuentas?id_emisora=${idEmisora}`);
 *       const cuentas = window.CotizacionesForm.extractItems(res);
 *       container.innerHTML = window.CotizacionesForm.renderCuentasLista(cuentas, {
 *           principal: cotizacionActual.id_cuenta_bancaria,
 *           adicionales: cotizacionActual.cuentas_adicionales || [],
 *       });
 *   }
 *
 *   async function cargarLogoParaForm(idEmisora, container, logoActual) {
 *       container.innerHTML = window.CotizacionesForm.renderLogoUploader(idEmisora, logoActual);
 *   }
 * ============================================================

 * ============================================================
 * SQL DE DIAGNÓSTICO (ejecutar en SSMS sobre MonosyncERP):
 *
 *   -- Verificar cotizaciones creadas:
 *   SELECT id_cotizacion, codigo, estado, eliminado,
 *          id_empresa, id_tipo, id_emisora,
 *          fecha_creacion, total, moneda
 *   FROM dbo.cotizacion
 *   ORDER BY id_cotizacion DESC;
 *
 *   -- Verificar productos en catálogo:
 *   SELECT id_producto, codigo, descripcion, id_tipo_producto, activo,
 *          precio_cliente, moneda
 *   FROM dbo.cotizacion_producto_catalogo
 *   ORDER BY id_producto DESC;
 *
 *   -- Verificar valores únicos de id_tipo_producto en catálogo:
 *   SELECT DISTINCT id_tipo_producto, COUNT(*) AS n
 *   FROM dbo.cotizacion_producto_catalogo
 *   GROUP BY id_tipo_producto
 *   ORDER BY id_tipo_producto;
 *
 *   -- Verificar cuentas bancarias:
 *   SELECT ce.razon_social, cb.id_cuenta, cb.banco, cb.numero_cuenta,
 *          cb.moneda, cb.activo
 *   FROM dbo.cotizacion_cuenta_bancaria cb
 *   INNER JOIN dbo.cotizacion_empresa_emisora ce ON cb.id_emisora = ce.id_emisora
 *   ORDER BY ce.razon_social, cb.orden;
 *
 *   -- Verificar que el módulo COTIZACIONES existe y tiene permisos:
 *   SELECT codigo, nombre, activo FROM dbo.modulo_catalogo WHERE codigo = 'COTIZACIONES';
 *   SELECT id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
 *   FROM dbo.role_permissions WHERE modulo = 'COTIZACIONES';
 * ============================================================ */
