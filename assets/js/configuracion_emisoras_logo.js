/**
 * assets/js/configuracion_emisoras_logo.js
 *
 * Parche auto-inyectable: añade una card "Empresas Emisoras y Logo" a la
 * vista /#/configuracion. No requiere modificar views.js.
 *
 * Carga en index.php después de views.js:
 *   <script src="assets/js/views.js" defer></script>
 *   <script src="assets/js/cotizaciones_form_patch.js" defer></script>
 *   <script src="assets/js/configuracion_emisoras_logo.js" defer></script>
 *
 * Esa card muestra:
 *   - Listado de emisoras (razón social + RUC)
 *   - Logo actual (preview)
 *   - Botón "Cambiar logo" → abre un input file para subir uno nuevo
 *   - Botón "Quitar logo" (DELETE)
 *
 * Endpoints consumidos:
 *   GET    /cotizaciones/catalogos               (result set 2: emisoras)
 *   POST   /cotizaciones/emisora/{id}/logo       (multipart: campo "logo")
 *   GET    /cotizaciones/emisora/{id}/logo       (data URI)
 *   DELETE /cotizaciones/emisora/{id}/logo       (limpiar)
 */
(function () {
    'use strict';
    if (!window.App || !window.App.views || !window.App.views.configuracion) {
        console.warn('[emisoras-logo] App.views.configuracion no encontrado. Cargar después de views.js');
        return;
    }
    const App = window.App;
    const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

    /* ============================================================
     * Cargar lista de emisoras (vía /cotizaciones/catalogos,
     * resultset #2). También se puede usar un endpoint directo
     * /cotizaciones/emisoras si existe, pero catálogos ya lo trae.
     * ============================================================ */
    async function cargarEmisoras() {
        try {
            const res = await App.request('/cotizaciones/catalogos', { background: true, silent: true });
            // res = { tipos, emisoras, zonas, ... }
            const emisoras = Array.isArray(res?.emisoras) ? res.emisoras
                          : (Array.isArray(res?.data?.emisoras) ? res.data.emisoras : []);
            return emisoras;
        } catch (e) {
            console.warn('[emisoras-logo] No se pudieron cargar emisoras:', e.message);
            return [];
        }
    }

    /* ============================================================
     * Cargar logo actual de una emisora como data URI.
     * Si falla o no tiene, devuelve ''.
     * ============================================================ */
    async function cargarLogo(idEmisora) {
        try {
            const r = await fetch(`${App.config.apiBase}/cotizaciones/emisora/${idEmisora}/logo`, {
                credentials: 'include',
            });
            if (!r.ok) return '';
            const text = await r.text();
            const t = text.trim();
            // El endpoint devuelve data URI directa o JSON de error
            if (t.startsWith('data:image/')) return t;
            // Si es JSON (404 dentro del body), ignorar
            try { JSON.parse(t); return ''; } catch { return t.startsWith('data:') ? t : ''; }
        } catch (e) {
            return '';
        }
    }

    /* ============================================================
     * Renderizar la card con la lista de emisoras y sus logos.
     * ============================================================ */
    async function renderCardEmisorasLogo(container) {
        // Buscar o crear el contenedor de la card
        let card = $('#emisorasLogoCard', container);
        if (!card) {
            const grid = $('.card-grid', container);
            if (!grid) {
                console.warn('[emisoras-logo] No se encontró .card-grid en configuracion');
                return;
            }
            // Insertar al principio del grid
            const newCard = document.createElement('div');
            newCard.className = 'card';
            newCard.id = 'emisorasLogoCard';
            newCard.innerHTML = `
                <div class="card-header">
                    <div class="card-title">
                        <i class="bi bi-building" aria-hidden="true"></i>
                        Empresas Emisoras y Logo
                    </div>
                </div>
                <div id="emisorasLogoBody" style="padding:12px">
                    <div class="text-muted" style="font-size:12px;margin-bottom:8px">
                        Selecciona la empresa emisora y sube su logo. El logo
                        aparecerá en las cotizaciones, archivos impresos y exportables.
                    </div>
                    <div id="emisorasList">Cargando emisoras...</div>
                </div>`;
            grid.insertBefore(newCard, grid.firstChild);
        }

        const body = $('#emisorasList', container);
        if (!body) return;

        const emisoras = await cargarEmisoras();
        if (emisoras.length === 0) {
            body.innerHTML = '<div class="alert alert-warning" style="font-size:12px">'
                + '<i class="bi bi-exclamation-triangle"></i> No hay empresas emisoras configuradas. '
                + 'Crea al menos una emisora en la base de datos '
                + '(tabla <code>cotizacion_empresa_emisora</code>) o ejecuta el script SQL de seeds.'
                + '</div>';
            return;
        }

        // Renderizar la lista con cada emisora y su logo (se cargará en paralelo)
        body.innerHTML = emisoras.map(e => `
            <div class="emisora-row" data-id="${e.id_emisora}"
                 style="display:flex;align-items:center;gap:12px;padding:8px;border:1px solid #e0e0e0;border-radius:4px;margin-bottom:8px">
                <div class="emisora-logo" id="logo-${e.id_emisora}"
                     style="width:80px;height:60px;display:flex;align-items:center;justify-content:center;border:1px solid #ddd;border-radius:4px;background:#fafafa">
                    <span style="font-size:10px;color:#888">Cargando...</span>
                </div>
                <div style="flex:1">
                    <div style="font-weight:600">${esc(e.razon_social)}</div>
                    <div style="font-size:11px;color:#666">RUC: ${esc(e.ruc || '—')} ${e.es_principal == 1 ? '· <span class="badge badge-success">Principal</span>' : ''}</div>
                    ${e.nombre_comercial ? `<div style="font-size:11px;color:#666">"${esc(e.nombre_comercial)}"</div>` : ''}
                    ${e.correo ? `<div style="font-size:11px;color:#666">${esc(e.correo)}</div>` : ''}
                </div>
                <div style="display:flex;flex-direction:column;gap:4px">
                    <button type="button" class="btn btn-sm btn-primary btn-cambiar-logo"
                            data-id="${e.id_emisora}" data-razon="${esc(e.razon_social)}"
                            ${App.can('COTIZACIONES','modificar') ? '' : 'disabled'}>
                        <i class="bi bi-upload"></i> Cambiar logo
                    </button>
                    <button type="button" class="btn btn-sm btn-light btn-quitar-logo"
                            data-id="${e.id_emisora}"
                            ${App.can('COTIZACIONES','modificar') ? '' : 'disabled'}>
                        <i class="bi bi-trash"></i> Quitar
                    </button>
                </div>
            </div>`).join('');

        // Cargar cada logo en paralelo
        emisoras.forEach(async (e) => {
            const slot = $(`#logo-${e.id_emisora}`, container);
            if (!slot) return;
            const logo = await cargarLogo(e.id_emisora);
            if (logo) {
                slot.innerHTML = `<img src="${esc(logo)}" alt="logo"
                    style="max-width:100%;max-height:100%;object-fit:contain">`;
            } else {
                slot.innerHTML = '<span style="font-size:10px;color:#888">Sin logo</span>';
            }
        });

        // Listeners para los botones
        $$('.btn-cambiar-logo', body).forEach(btn => {
            btn.addEventListener('click', () => abrirSubidaLogo(btn.dataset.id, btn.dataset.razon));
        });
        $$('.btn-quitar-logo', body).forEach(btn => {
            btn.addEventListener('click', () => quitarLogo(btn.dataset.id));
        });
    }

    /* ============================================================
     * Abrir diálogo para seleccionar y subir un logo nuevo.
     * Crea un <input type="file"> invisible y dispara el click.
     * ============================================================ */
    function abrirSubidaLogo(idEmisora, razonSocial) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/png,image/jpeg,image/gif,image/svg+xml,image/webp';
        input.style.display = 'none';
        document.body.appendChild(input);

        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            if (!file) { document.body.removeChild(input); return; }
            if (file.size > 2 * 1024 * 1024) {
                App.toast('El logo no debe pesar más de 2 MB', 'danger');
                document.body.removeChild(input);
                return;
            }
            await subirLogo(idEmisora, file, razonSocial);
            document.body.removeChild(input);
        });

        input.click();
    }

    /* ============================================================
     * Subir el archivo al endpoint POST /cotizaciones/emisora/{id}/logo
     * ============================================================ */
    async function subirLogo(idEmisora, file, razonSocial) {
        const fd = new FormData();
        fd.append('logo', file);
        App.toast(`Subiendo logo para "${razonSocial}"...`, 'info', 2000);
        try {
            const r = await fetch(`${App.config.apiBase}/cotizaciones/emisora/${idEmisora}/logo`, {
                method: 'POST', credentials: 'include', body: fd,
            });
            const j = await r.json();
            if (r.ok && j.ok) {
                App.toast(`Logo de "${razonSocial}" actualizado`, 'success');
                // Refrescar solo el slot de esa emisora
                const slot = $(`#logo-${idEmisora}`);
                if (slot) {
                    slot.innerHTML = `<img src="${esc(j.data.logo_url)}" alt="logo"
                        style="max-width:100%;max-height:100%;object-fit:contain">`;
                }
            } else {
                throw new Error(j.message || 'Error al subir el logo');
            }
        } catch (err) {
            App.toast('Error al subir logo: ' + err.message, 'danger', 6000);
        }
    }

    async function quitarLogo(idEmisora) {
        if (!confirm('¿Quitar el logo de esta emisora?')) return;
        try {
            const r = await fetch(`${App.config.apiBase}/cotizaciones/emisora/${idEmisora}/logo`, {
                method: 'DELETE', credentials: 'include',
            });
            if (r.ok) {
                App.toast('Logo quitado', 'success');
                const slot = $(`#logo-${idEmisora}`);
                if (slot) slot.innerHTML = '<span style="font-size:10px;color:#888">Sin logo</span>';
            } else {
                const j = await r.json().catch(() => ({}));
                throw new Error(j.message || `HTTP ${r.status}`);
            }
        } catch (err) {
            App.toast('Error: ' + err.message, 'warning');
        }
    }

    /* ============================================================
     * Wrapper del render de /#/configuracion para inyectar la card
     * automáticamente después de que se renderice la vista.
     * Intenta aplicarlo inmediatamente; si App.views.configuracion
     * aún no existe (views.js cargándose), reintenta cada 100ms hasta
     * 10 segundos.
     * ============================================================ */
    let installed = false;

    function installWrapper() {
        if (installed) return true;
        if (!window.App || !window.App.views || !window.App.views.configuracion) {
            return false;
        }
        const originalRender = App.views.configuracion.render.bind(App.views.configuracion);
        App.views.configuracion.render = async function (container, ...rest) {
            try {
                await originalRender(container, ...rest);
            } catch (e) {
                console.error('[emisoras-logo] Error en render original:', e);
            }
            // Esperar al próximo tick para asegurar que el DOM está completo
            setTimeout(() => {
                try {
                    renderCardEmisorasLogo(container);
                } catch (e) {
                    console.error('[emisoras-logo] Error al inyectar card:', e);
                }
            }, 100);
        };
        installed = true;
        console.info('[emisoras-logo] Wrapper instalado en App.views.configuracion. '
                   + 'La card aparecerá en /#/configuracion.');
        return true;
    }

    // Intentar instalar de inmediato, y si no se puede, reintentar
    if (!installWrapper()) {
        let attempts = 0;
        const interval = setInterval(() => {
            if (installWrapper() || ++attempts > 100) {
                clearInterval(interval);
                if (attempts > 100 && !installed) {
                    console.error('[emisoras-logo] No se pudo instalar el wrapper '
                                + 'tras 10s. Verifica que views.js esté cargado antes '
                                + 'que este archivo.');
                }
            }
        }, 100);
    }

    // Exponer helpers por si los necesitas desde otra vista
    window.ConfiguracionEmisorasLogo = {
        renderCardEmisorasLogo,
        cargarEmisoras,
        cargarLogo,
        subirLogo,
        quitarLogo,
        installWrapper,
    };
})();
