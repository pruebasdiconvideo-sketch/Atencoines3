/* ============================================================================
 *  views.js - Sistema de Atenciones v7 (modales accesibles + focus trap + ESC)
 * ========================================================================== */

/* ---------------------------------------------------------------------------
 *  Pila de modales abiertos.
 *  Cada entrada guarda { overlay, restoreFocus, previousPadding }.
 *  Esto permite anidar modales (p.ej. showModal -> showSelectorModal) sin
 *  perder el foco ni dejar basura en el DOM al cerrar.
 * ------------------------------------------------------------------------- */
App._modalStack = [];

/* Bloquea el scroll del body mientras hay al menos un modal abierto. */
App._lockBodyScroll = function () {
    const body = document.body;
    if (body.style.overflow === 'hidden') return;          // ya bloqueado
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    body.dataset.prevPaddingRight = body.style.paddingRight || '';
    body.dataset.prevOverflow = body.style.overflow || '';
    if (scrollbarWidth > 0) {
        body.style.paddingRight = scrollbarWidth + 'px';
    }
    body.style.overflow = 'hidden';
};

App._unlockBodyScroll = function () {
    if (App._modalStack.length > 0) return;                // aún hay modales
    const body = document.body;
    body.style.overflow = body.dataset.prevOverflow || '';
    body.style.paddingRight = body.dataset.prevPaddingRight || '';
    delete body.dataset.prevOverflow;
    delete body.dataset.prevPaddingRight;
};

/* Focus trap: mantiene el Tab dentro del modal. */
App._trapFocus = function (event, container) {
    if (event.key !== 'Tab') return;
    const focusable = container.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
};

/* Helper: modal genérico.
 *  - Limpia cualquier modal anterior (no se apilan modales básicos).
 *  - Asigna role, aria-modal, aria-labelledby.
 *  - Mueve el foco al primer elemento enfocable o al botón de cerrar.
 *  - ESC cierra. Tab queda atrapado.
 *  - El click fuera del modal (overlay) NO cierra: evita perder trabajo
 *    accidentalmente. Solo cierra el botón X o los botones [data-modal-close].
 *  - Restaura el foco al elemento que lo tenía antes de abrir.
 *  - Bloquea el scroll del body.
 *  - Importante: closeModal() NUNCA navega ni cambia el hash, así el usuario
 *    siempre permanece en la vista actual (no vuelve al dashboard).
 */
App.showModal = function (title, bodyHtml, footerHtml) {
    const root = document.getElementById('modal-root');
    if (!root) return;
    // Si ya hay un modal abierto, lo cerramos primero (no anidamos modales básicos)
    if (App._modalStack.length > 0) {
        App.closeModal();
    }
    const previousFocus = document.activeElement;
    root.innerHTML = `
        <div class="modal-overlay" data-modal-overlay>
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="modal-header">
                    <div class="modal-title" id="modalTitle">${title}</div>
                    <button class="modal-close" type="button" data-modal-close aria-label="Cerrar">&times;</button>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
            </div>
        </div>`;
    const overlay = root.querySelector('[data-modal-overlay]');
    const modal = root.querySelector('.modal');
    App._lockBodyScroll();
    App._modalStack.push({ overlay, restoreFocus: previousFocus });

    // Solo cerrar al click en el botón explícito de cerrar (NO al click fuera).
    // Esto evita pérdida accidental de datos cuando el usuario hace click fuera.
    overlay.addEventListener('click', event => {
        if (event.target.closest('[data-modal-close]')) {
            event.stopPropagation();
            App.closeModal();
        }
    });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            App.closeModal();
            return;
        }
        App._trapFocus(event, modal);
    });

    // Foco inicial usando requestAnimationFrame (más rápido y confiable que setTimeout).
    const focusTarget = modal.querySelector('input, select, textarea, button:not([data-modal-close])');
    requestAnimationFrame(() => (focusTarget || modal.querySelector('[data-modal-close]'))?.focus());
};

App.closeModal = function () {
    const root = document.getElementById('modal-root');
    if (!root) return;
    const entry = App._modalStack.pop();
    if (entry) {
        entry.overlay.remove();
        // Restaurar el foco de forma defensiva: si el elemento ya no está en el DOM,
        // evitar errores y simplemente dejar el foco natural.
        try { entry.restoreFocus?.focus?.(); } catch (_) { /* noop */ }
    } else {
        root.innerHTML = '';
    }
    App._unlockBodyScroll();
    // NUNCA navegar ni cambiar hash aquí: el usuario debe permanecer en la vista actual.
};

/* ============================================================================
 * App.openModal(html) — wrapper conveniente para showModal
 * ----------------------------------------------------------------------------
 * Permite pasar todo el HTML del modal en un solo string, en lugar de tener
 * que separar title / body / footer. Si el HTML comienza con <h3>, se usa
 * ese <h3> como título y el resto como body.
 *
 * Uso:
 *   App.openModal(`
 *     <h3>Buscar empresa</h3>
 *     <input ...>
 *     <button ...>Cerrar</button>
 *   `);
 *
 * Esto es equivalente a:
 *   App.showModal('Buscar empresa', '<input ...><button ...>Cerrar</button>');
 * ============================================================================
 */
App.openModal = function (html) {
    if (typeof html !== 'string') {
        console.warn('[App.openModal] se esperaba string HTML, se recibió:', typeof html);
        return;
    }
    const trimmed = html.trim();

    // Intentar extraer el primer <h3>...</h3> como título
    const h3Match = trimmed.match(/^<h3[^>]*>([\s\S]*?)<\/h3>/i);
    let title = '';
    let body = trimmed;

    if (h3Match) {
        title = h3Match[1].trim();
        body = trimmed.substring(h3Match[0].length).trim();
    } else {
        // Si no hay <h3>, mostrar "Ventana" como título genérico
        title = 'Ventana';
        body = trimmed;
    }

    // Separar el footer: cualquier <div class="text-right">...</div> al final
    // se considera como footer
    const footerMatch = body.match(/<div class="text-right"[^>]*>([\s\S]*?)<\/div>\s*$/i);
    let footer = null;
    if (footerMatch) {
        footer = '<div class="text-right">' + footerMatch[1] + '</div>';
        body = body.substring(0, footerMatch.index).trim();
    }

    App.showModal(title, body, footer);
};

/* Modal de búsqueda con campos.
 *  onSearch se invoca con un objeto { campo: valor }.
 *  - Enter dentro de un campo dispara la búsqueda.
 *  - "Limpiar" invoca onSearch({}) para quitar filtros.
 *  - Accessible: labels asociados, foco inicial en el primer campo.
 */
App.showSearchModal = function (title, fields, onSearch) {
    const bodyHtml = fields.map(field => {
        const fieldId = 'search_' + field.name;
        if (field.type === 'select') {
            const options = (field.options || []).map(option =>
                `<option value="${App.escapeHtml(option.value)}">${App.escapeHtml(option.label)}</option>`
            ).join('');
            return `<div class="form-group"><label class="form-label" for="${fieldId}">${App.escapeHtml(field.label)}</label>
                <select class="form-select" id="${fieldId}" name="${App.escapeHtml(field.name)}"><option value="">Todos</option>${options}</select></div>`;
        }
        return `<div class="form-group"><label class="form-label" for="${fieldId}">${App.escapeHtml(field.label)}</label>
            <input type="${field.type || 'text'}" class="form-input" id="${fieldId}" name="${App.escapeHtml(field.name)}" placeholder="${App.escapeHtml(field.placeholder || '')}"></div>`;
    }).join('');

    const footerHtml = `
        <button class="btn" type="button" data-modal-close>Cancelar</button>
        <button class="btn" type="button" id="btnClearSearch">Limpiar</button>
        <button class="btn btn-primary" type="button" id="btnSearchModal"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>`;

    App.showModal('Buscar: ' + App.escapeHtml(title), bodyHtml, footerHtml);

    const collectValues = () => {
        const values = {};
        fields.forEach(field => {
            const element = document.getElementById('search_' + field.name);
            if (element && element.value.trim()) values[field.name] = element.value.trim();
        });
        return values;
    };

    const submit = () => {
        const values = collectValues();
        App.closeModal();
        onSearch(values);
    };

    document.getElementById('btnSearchModal').addEventListener('click', submit);
    document.getElementById('btnClearSearch').addEventListener('click', () => {
        App.closeModal();
        onSearch({});
    });

    // Enter en cualquier campo dispara la búsqueda
    fields.forEach(field => {
        const element = document.getElementById('search_' + field.name);
        if (element) {
            element.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submit();
                }
            });
        }
    });
};

/* Modal selector con búsqueda en vivo sobre un endpoint.
 *  - Busca al abrir (lista completa).
 *  - Debounce 300ms al escribir; Enter busca inmediatamente.
 *  - Cancela peticiones viejas si el usuario sigue escribiendo.
 *  - ESC cierra. Click en overlay cierra. Tab atrapado.
 *  - Anuncía "Buscando..." y "Sin resultados" con aria-live.
 *  - Si se abre desde dentro de otro modal, al cerrar restaura el padre.
 */
App.showSelectorModal = function (title, searchEndpoint, columns, onSelect, searchParamName = 'query') {
    const root = document.getElementById('modal-root');
    if (!root) return;

    const previousFocus = document.activeElement;
    const selectorInstance = (App._selectorInstance || 0) + 1;
    App._selectorInstance = selectorInstance;
    let requestVersion = 0;
    let searchTimer = null;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay selector-overlay';
    overlay.setAttribute('data-modal-overlay', '');
    overlay.innerHTML = `
        <section class="modal selector-modal" role="dialog" aria-modal="true" aria-labelledby="selectorTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="selectorTitle">${App.escapeHtml(title)}</h2>
                <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">&times;</button>
            </div>
            <div class="modal-body">
                <div class="selector-search-row">
                    <div class="selector-input-wrap">
                        <i class="bi bi-search selector-input-icon" aria-hidden="true"></i>
                        <input type="text" class="form-input selector-input" id="selectorSearch"
                               placeholder="Escriba para buscar..." aria-label="Buscar opciones"
                               autocomplete="off" />
                    </div>
                    <button type="button" class="btn btn-primary" id="btnSelectorSearch">
                        <i class="bi bi-arrow-right" aria-hidden="true"></i><span>Buscar</span>
                    </button>
                </div>
                <div id="selectorResults" class="selector-results" role="status" aria-live="polite">
                    <p class="text-muted text-center selector-msg">Cargando opciones...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" type="button" data-modal-close>Cancelar</button>
            </div>
        </section>`;

    root.appendChild(overlay);
    App._lockBodyScroll();
    App._modalStack.push({ overlay, restoreFocus: previousFocus });

    const modal = overlay.querySelector('.modal');
    const input = overlay.querySelector('#selectorSearch');
    const results = overlay.querySelector('#selectorResults');
    const btnSearch = overlay.querySelector('#btnSelectorSearch');

    const closeSelector = () => {
        if (searchTimer) clearTimeout(searchTimer);
        const entry = App._modalStack.pop();
        if (entry) {
            entry.overlay.remove();
            entry.restoreFocus?.focus?.();
        }
        App._unlockBodyScroll();
        if (App._selectorInstance === selectorInstance) App._selectorInstance++;
    };

    overlay.addEventListener('click', event => {
        if (event.target.closest('[data-modal-close]')) {
            event.stopPropagation();
            closeSelector();
        }
    });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            closeSelector();
            return;
        }
        App._trapFocus(event, modal);
    });

    const renderResults = async query => {
        const currentVersion = ++requestVersion;
        if (!results || !overlay.isConnected) return;
        results.innerHTML = '<p class="text-muted text-center selector-msg">Buscando...</p>';
        const url = searchEndpoint + (searchEndpoint.includes('?') ? '&' : '?') +
            searchParamName + '=' + encodeURIComponent(query || '');
        try {
            const response = await App.request(url);
            if (selectorInstance !== App._selectorInstance ||
                currentVersion !== requestVersion || !overlay.isConnected) return;
            const items = Array.isArray(response) ? response : [];
            if (!items.length) {
                results.innerHTML = '<p class="text-muted text-center selector-msg selector-msg--empty">' +
                    '<i class="bi bi-inbox" aria-hidden="true"></i><br>Sin resultados para esta búsqueda.</p>';
                return;
            }
            results.innerHTML = items.map((item, index) => `
                <article class="selector-result" tabindex="0" data-selector-row="${index}">
                    <div class="selector-result__details">${columns.map(column => {
                        const value = item[column.key];
                        return `<div><strong>${App.escapeHtml(column.label)}:</strong> ${App.escapeHtml(value == null || value === '' ? '-' : value)}</div>`;
                    }).join('')}</div>
                    <button type="button" class="btn btn-sm btn-primary" data-selector-index="${index}">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Seleccionar
                    </button>
                </article>`).join('');
            const selectItem = (item) => {
                onSelect(item);
                closeSelector();
            };
            results.querySelectorAll('[data-selector-index]').forEach(button => {
                button.addEventListener('click', () => selectItem(items[Number(button.dataset.selectorIndex)]));
            });
            results.querySelectorAll('[data-selector-row]').forEach(row => {
                row.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        selectItem(items[Number(row.dataset.selectorRow)]);
                    }
                });
            });
        } catch (error) {
            if (selectorInstance === App._selectorInstance &&
                currentVersion === requestVersion && overlay.isConnected) {
                results.innerHTML = `<p class="text-danger selector-msg"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${App.escapeHtml(error.message)}</p>`;
            }
        }
    };

    // Buscar al escribir con debounce
    input.addEventListener('input', event => {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(() => renderResults(event.target.value), 300);
    });
    // Enter busca inmediatamente
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (searchTimer) clearTimeout(searchTimer);
            renderResults(input.value);
        }
    });
    btnSearch.addEventListener('click', () => {
        if (searchTimer) clearTimeout(searchTimer);
        renderResults(input.value);
    });

    // Foco al input al abrir
    setTimeout(() => input?.focus(), 50);
    renderResults('');
};

App.confirmDelete = function (itemName, onConfirm) {
    App.showModal('Confirmar eliminación',
        `<p>¿Está seguro que desea eliminar <strong>${App.escapeHtml(itemName)}</strong>?</p>
         <p class="text-muted text-sm">Esta acción no se puede deshacer.</p>`,
        `<button class="btn" type="button" data-modal-close>Cancelar</button>
         <button class="btn btn-danger" type="button" id="btnConfirmDelete">
            <i class="bi bi-trash" aria-hidden="true"></i> Eliminar
         </button>`);
    document.getElementById('btnConfirmDelete').addEventListener('click', () => {
        App.closeModal();
        onConfirm();
    });
};

App.validateDuplicate = async function (tabla, campo, valor, idExcluir = null, extraParams = {}) {
    const params = new URLSearchParams({ tabla, campo, valor });
    /* FIX: Normalizar idExcluir a entero positivo antes de enviarlo al backend.
     * Antes: if (idExcluir) aceptaba strings como "0" o valores truthy no enteros
     * que el backend convertía a 0, perdiendo así la exclusión del registro actual
     * y provocando el falso positivo "El RUC ya existe" al editar. */
    const idExcluirNum = Number(idExcluir);
    if (Number.isInteger(idExcluirNum) && idExcluirNum > 0) {
        params.set('id_excluir', String(idExcluirNum));
    }
    Object.entries(extraParams).forEach(([key, value]) => {
        if (value !== undefined && value !== null) params.set(key, value);
    });
    try {
        const result = await App.request('/geografia/validate?' + params.toString());
        return result && result.existe === true;
    } catch (error) {
        console.warn('[validateDuplicate] Error al validar', tabla, campo, error?.message || error);
        return false;
    }
};

/* ============================================================================
 *  VISTA: DASHBOARD
 * ========================================================================== */
App.views.dashboard = {
    // Filtros de fecha: 'hoy', '7dias', 'mes', 'rango', 'todo'
    filtroActivo: '7dias',
    fechaDesde: null,
    fechaHasta: null,

    async render(container) {
        // Calcular fechas según filtro activo
        this.calcularFechas();

        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Dashboard</h1>
                <button class="btn" id="btnRefresh"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar</button>
            </div>

            <div class="dashboard-filtros card mb-4">
                <div class="dashboard-filtros__presets">
                    <button type="button" class="btn btn-sm dashboard-filtro-btn${this.filtroActivo === 'hoy' ? ' active' : ''}" data-filtro="hoy">
                        <i class="bi bi-calendar-day" aria-hidden="true"></i> Hoy
                    </button>
                    <button type="button" class="btn btn-sm dashboard-filtro-btn${this.filtroActivo === '7dias' ? ' active' : ''}" data-filtro="7dias">
                        <i class="bi bi-calendar-week" aria-hidden="true"></i> Últimos 7 días
                    </button>
                    <button type="button" class="btn btn-sm dashboard-filtro-btn${this.filtroActivo === 'mes' ? ' active' : ''}" data-filtro="mes">
                        <i class="bi bi-calendar-month" aria-hidden="true"></i> Este mes
                    </button>
                    <button type="button" class="btn btn-sm dashboard-filtro-btn${this.filtroActivo === 'rango' ? ' active' : ''}" data-filtro="rango">
                        <i class="bi bi-calendar-range" aria-hidden="true"></i> Rango personalizado
                    </button>
                    <button type="button" class="btn btn-sm dashboard-filtro-btn${this.filtroActivo === 'todo' ? ' active' : ''}" data-filtro="todo">
                        <i class="bi bi-calendar-all" aria-hidden="true"></i> Todo
                    </button>
                </div>
                <div class="dashboard-filtros__rango" id="rangoFechas" ${this.filtroActivo !== 'rango' ? 'hidden' : ''}>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Desde</label>
                        <input type="date" class="form-input" id="fechaDesdeInput" value="${this.fechaDesde || ''}">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Hasta</label>
                        <input type="date" class="form-input" id="fechaHastaInput" value="${this.fechaHasta || ''}">
                    </div>
                    <button type="button" class="btn btn-primary" id="btnAplicarRango">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Aplicar
                    </button>
                </div>
                <div class="dashboard-filtros__periodo" id="periodoLabel"></div>
            </div>

            <div class="kpi-grid" id="kpisContainer">
                <div class="kpi-tile"><div class="kpi-tile__label">Cargando...</div><div class="kpi-tile__value"><div class="spinner"></div></div></div>
            </div>
            <div class="card-grid">
                <div class="card"><div class="card-header"><div class="card-title">Tickets por día</div></div><div style="height:280px"><canvas id="chartTicketsDia"></canvas></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Tickets por Estado</div></div><div style="height:280px"><canvas id="chartTicketsEstado"></canvas></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Tickets por Prioridad</div></div><div style="height:280px"><canvas id="chartTicketsPrioridad"></canvas></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Atenciones por Técnico</div></div><div style="height:280px"><canvas id="chartAtencionesTecnico"></canvas></div></div>
            </div>
            ${App.renderFooter({ moduleName: 'Dashboard' })}
        `;

        // Actualizar etiqueta de período
        this.actualizarPeriodoLabel();

        // Listeners de filtros preset
        container.querySelectorAll('[data-filtro]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.filtroActivo = btn.dataset.filtro;
                // Si el filtro no es 'rango', recalcular fechas y recargar
                if (this.filtroActivo !== 'rango') {
                    this.calcularFechas();
                    this.render(container);
                } else {
                    // Mostrar el rango personalizado
                    document.getElementById('rangoFechas').hidden = false;
                    container.querySelectorAll('[data-filtro]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            });
        });

        // Botón aplicar rango
        const btnAplicar = document.getElementById('btnAplicarRango');
        if (btnAplicar) {
            btnAplicar.addEventListener('click', () => {
                this.fechaDesde = document.getElementById('fechaDesdeInput').value;
                this.fechaHasta = document.getElementById('fechaHastaInput').value;
                if (!this.fechaDesde || !this.fechaHasta) {
                    App.toast('Seleccione ambas fechas', 'warning');
                    return;
                }
                if (this.fechaDesde > this.fechaHasta) {
                    App.toast('La fecha "Desde" no puede ser mayor que "Hasta"', 'warning');
                    return;
                }
                this.render(container);
            });
        }

        document.getElementById('btnRefresh').addEventListener('click', () => this.render(container));
        await Promise.all([
            this.loadKPIs(),
            this.loadChart('TICKETS_POR_DIA', 'chartTicketsDia'),
            this.loadChart('TICKETS_POR_ESTADO', 'chartTicketsEstado'),
            this.loadChart('TICKETS_POR_PRIORIDAD', 'chartTicketsPrioridad'),
            this.loadChart('ATENCIONES_POR_TECNICO', 'chartAtencionesTecnico'),
        ]);
    },

    // Calcula fechaDesde y fechaHasta según el filtro activo
    calcularFechas() {
        const hoy = new Date();
        const hoyStr = hoy.toISOString().slice(0, 10);
        if (this.filtroActivo === 'hoy') {
            this.fechaDesde = hoyStr;
            this.fechaHasta = hoyStr;
        } else if (this.filtroActivo === '7dias') {
            const hace7 = new Date(hoy);
            hace7.setDate(hace7.getDate() - 6); // últimos 7 días incluyendo hoy
            this.fechaDesde = hace7.toISOString().slice(0, 10);
            this.fechaHasta = hoyStr;
        } else if (this.filtroActivo === 'mes') {
            const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
            this.fechaDesde = primerDiaMes.toISOString().slice(0, 10);
            this.fechaHasta = hoyStr;
        } else if (this.filtroActivo === 'todo') {
            this.fechaDesde = null;
            this.fechaHasta = null;
        }
        // Para 'rango' no recalcular: usar las fechas que ya están
    },

    actualizarPeriodoLabel() {
        const el = document.getElementById('periodoLabel');
        if (!el) return;
        const labels = {
            'hoy': 'Hoy (' + this.fechaDesde + ')',
            '7dias': 'Últimos 7 días (' + this.fechaDesde + ' a ' + this.fechaHasta + ')',
            'mes': 'Este mes (' + this.fechaDesde + ' a ' + this.fechaHasta + ')',
            'rango': 'Rango: ' + (this.fechaDesde || '?') + ' a ' + (this.fechaHasta || '?'),
            'todo': 'Todos los registros (sin filtro de fecha)',
        };
        el.textContent = labels[this.filtroActivo] || '';
    },

    // Construye query string con fechas para los endpoints
    buildDateParams() {
        const params = new URLSearchParams();
        if (this.fechaDesde) params.set('fecha_desde', this.fechaDesde);
        if (this.fechaHasta) params.set('fecha_hasta', this.fechaHasta);
        return params.toString() ? '?' + params.toString() : '';
    },

    async loadKPIs() {
        try {
            const k = await App.request('/dashboard/kpis' + this.buildDateParams());
            document.getElementById('kpisContainer').innerHTML = `
                <div class="kpi-tile success"><div class="kpi-tile__label">Tickets Cerrados</div><div class="kpi-tile__value">${App.formatNumber(k.tickets_cerrados_periodo || 0)}</div></div>
                <div class="kpi-tile info"><div class="kpi-tile__label">Tickets Totales</div><div class="kpi-tile__value">${App.formatNumber(k.tickets_total || 0)}</div></div>
                <div class="kpi-tile warn"><div class="kpi-tile__label">Pendientes</div><div class="kpi-tile__value">${App.formatNumber(k.tickets_pendientes || 0)}</div></div>
                <div class="kpi-tile info"><div class="kpi-tile__label">En Atención</div><div class="kpi-tile__value">${App.formatNumber(k.tickets_en_atencion || 0)}</div></div>
                <div class="kpi-tile danger"><div class="kpi-tile__label">Críticos Abiertos</div><div class="kpi-tile__value">${App.formatNumber(k.tickets_criticos || 0)}</div></div>
                <div class="kpi-tile success"><div class="kpi-tile__label">Tiempo Promedio (min)</div><div class="kpi-tile__value">${App.formatNumber(k.tiempo_promedio_minutos || 0, 1)}</div></div>`;
        } catch (e) { console.error('KPIs', e); }
    },
    async loadChart(tipo, canvasId) {
        try {
            const data = await App.request(`/dashboard/series?tipo=${encodeURIComponent(tipo)}${this.buildDateParams().replace('?', '&')}`);
            this.renderChart(canvasId, tipo, data);
        } catch (e) { console.error('Chart', tipo, e); }
    },
    renderChart(canvasId, tipo, data) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        if (window.Dashboard?.charts?.[canvasId]) window.Dashboard.charts[canvasId].destroy();
        const ctx = canvas.getContext('2d');
        const palette = ['#304753', '#2590c6', '#5889a1', '#459660', '#977b43', '#955650', '#4a7bab'];
        if (!data || data.length === 0) {
            ctx.font = '14px sans-serif'; ctx.fillStyle = '#848a8e'; ctx.textAlign = 'center';
            ctx.fillText('Sin datos en el período seleccionado', canvas.width / 2, canvas.height / 2); return;
        }
        if (tipo === 'TICKETS_POR_DIA') {
            window.Dashboard.charts[canvasId] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.fecha),
                    datasets: [
                        { label: 'Total', data: data.map(d => d.total), borderColor: palette[1], backgroundColor: 'rgba(37,144,198,0.1)', fill: true, tension: 0.3 },
                        { label: 'Cerrados', data: data.map(d => d.cerrados), borderColor: palette[3], fill: false, tension: 0.3 },
                        { label: 'Pendientes', data: data.map(d => d.pendientes), borderColor: palette[4], fill: false, tension: 0.3 },
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        } else if (tipo === 'TICKETS_POR_ESTADO' || tipo === 'TICKETS_POR_PRIORIDAD') {
            window.Dashboard.charts[canvasId] = new Chart(ctx, {
                type: 'doughnut',
                data: { labels: data.map(d => d.estado || d.prioridad), datasets: [{ data: data.map(d => d.total), backgroundColor: palette }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
            });
        } else if (tipo === 'ATENCIONES_POR_TECNICO') {
            window.Dashboard.charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.map(d => d.tecnico),
                    datasets: [
                        { label: 'Atenciones', data: data.map(d => d.atenciones), backgroundColor: palette[1] },
                        { label: 'Promedio (min)', data: data.map(d => d.promedio_minutos), backgroundColor: palette[2] }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        }
    },
};

/* ============================================================================
 *  VISTA: TICKETS
 * ========================================================================== */
App.views.tickets = {
    currentFilters: { search: '', estado: '', fecha_desde: '', fecha_hasta: '', id_empresa: '' },
    currentPage: 1,
    perPage: 50,
    allRows: [],
    allEmpresas: [],
    searchTimer: null,
    async render(container, params) {
        if (params[0] === 'new') return this.showFormModal(null);
        if (params[0]) return this.renderDetail(container, params[0]);
        this.currentPage = 1;
        return this.renderList(container);
    },

    async renderList(container) {
        // Pre-cargar empresas para el filtro
        try { this.allEmpresas = await App.request('/empresas'); } catch (e) { this.allEmpresas = []; }
        const hoy = new Date().toISOString().slice(0, 10);
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Tickets</h1>
                <div class="card-actions">
                    <button class="btn btn-secondary" id="btnExportExcel" title="Exportar listado filtrado a Excel"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Excel</button>
                    <button class="btn btn-secondary" id="btnExportCsv" title="Exportar listado filtrado a CSV"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> CSV</button>
                    <button class="btn btn-secondary" id="btnExportPdf" title="Exportar listado filtrado a PDF (imprimir desde el navegador)"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</button>
                    <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Ticket</button>
                </div>
            </div>
            <div class="card">
                <div class="tickets-filtros">
                    <div class="tickets-filtros__busqueda">
                        <i class="bi bi-search tickets-filtros__icon" aria-hidden="true"></i>
                        <input type="search" class="form-input tickets-filtros__input"
                               id="inputBusquedaTicket"
                               placeholder="Buscar por nro, detalle, empresa..."
                               autocomplete="off"
                               aria-label="Buscar tickets">
                        <button type="button" class="btn tickets-filtros__limpiar" id="btnLimpiarBusquedaTicket" hidden title="Limpiar">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <select class="form-select tickets-filtros__select" id="selectEstado" aria-label="Filtrar por estado">
                        <option value="">Todos los estados</option>
                        <option value="PENDIENTE">Pendiente</option>
                        <option value="EN_ATENCION">En Atención</option>
                        <option value="RESUELTO">Resuelto</option>
                        <option value="CERRADO">Cerrado</option>
                        <option value="ANULADO">Anulado</option>
                    </select>
                    <select class="form-select tickets-filtros__select" id="selectEmpresa" aria-label="Filtrar por empresa">
                        <option value="">Todas las empresas</option>
                        ${this.allEmpresas.map(e => `<option value="${e.id_empresa}">${App.escapeHtml(e.razon_social)}</option>`).join('')}
                    </select>
                    <div class="tickets-filtros__fechas">
                        <input type="date" class="form-input tickets-filtros__fecha" id="fechaDesde" title="Desde" value="${this.currentFilters.fecha_desde || ''}">
                        <input type="date" class="form-input tickets-filtros__fecha" id="fechaHasta" title="Hasta" value="${this.currentFilters.fecha_hasta || ''}">
                        <button type="button" class="btn btn-sm tickets-filtros__limpiar-fechas" id="btnLimpiarFechas" title="Quitar fechas"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="tickets-filtros__resultados">
                        <span id="ticketsCount" class="text-muted text-sm">Cargando...</span>
                    </div>
                </div>
                <div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando tickets...</p></div></div>
            </div>`;
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        // Exportación masiva: usa los mismos filtros que la pantalla actual.
        // El endpoint /tickets/export genera Excel/CSV/JSON directamente.
        document.getElementById('btnExportExcel').addEventListener('click', () => this.exportar('xlsx'));
        document.getElementById('btnExportCsv').addEventListener('click', () => this.exportar('csv'));
        // PDF: abrimos una pestaña nueva con los datos JSON formateados como
        // tabla HTML imprimible. El usuario usa Ctrl+P / "Guardar como PDF"
        // del navegador (más liviano que una librería PDF en el cliente).
        document.getElementById('btnExportPdf').addEventListener('click', () => this.exportarPdf());
        // Búsqueda automática
        const inputBusqueda = document.getElementById('inputBusquedaTicket');
        inputBusqueda.addEventListener('input', (event) => {
            const valor = event.target.value.trim();
            document.getElementById('btnLimpiarBusquedaTicket').hidden = valor === '';
            this.currentFilters.search = valor;
            this.currentPage = 1;
            if (this.searchTimer) clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.load(), 300);
        });
        inputBusqueda.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.load();
            }
        });
        document.getElementById('btnLimpiarBusquedaTicket').addEventListener('click', () => {
            inputBusqueda.value = '';
            this.currentFilters.search = '';
            document.getElementById('btnLimpiarBusquedaTicket').hidden = true;
            this.currentPage = 1;
            this.load();
            inputBusqueda.focus();
        });
        // Filtro por estado
        document.getElementById('selectEstado').addEventListener('change', (e) => {
            this.currentFilters.estado = e.target.value;
            this.currentPage = 1;
            this.load();
        });
        // Filtro por empresa
        document.getElementById('selectEmpresa').addEventListener('change', (e) => {
            this.currentFilters.id_empresa = e.target.value;
            this.currentPage = 1;
            this.load();
        });
        // Filtro por fechas
        document.getElementById('fechaDesde').addEventListener('change', (e) => {
            this.currentFilters.fecha_desde = e.target.value;
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('fechaHasta').addEventListener('change', (e) => {
            this.currentFilters.fecha_hasta = e.target.value;
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('btnLimpiarFechas').addEventListener('click', () => {
            document.getElementById('fechaDesde').value = '';
            document.getElementById('fechaHasta').value = '';
            this.currentFilters.fecha_desde = '';
            this.currentFilters.fecha_hasta = '';
            this.currentPage = 1;
            this.load();
        });
        // Restaurar valores de filtros si ya estaban seteados
        if (this.currentFilters.estado) document.getElementById('selectEstado').value = this.currentFilters.estado;
        if (this.currentFilters.id_empresa) document.getElementById('selectEmpresa').value = this.currentFilters.id_empresa;
        await this.load();
    },

    async load() {
        const params = new URLSearchParams();
        if (this.currentFilters.search) params.set('search', this.currentFilters.search);
        if (this.currentFilters.estado) params.set('estado', this.currentFilters.estado);
        if (this.currentFilters.id_empresa) params.set('id_empresa', this.currentFilters.id_empresa);
        if (this.currentFilters.fecha_desde) params.set('fecha_desde', this.currentFilters.fecha_desde);
        if (this.currentFilters.fecha_hasta) params.set('fecha_hasta', this.currentFilters.fecha_hasta);
        params.set('per_page', '500');
        const countEl = document.getElementById('ticketsCount');
        if (countEl) countEl.textContent = 'Buscando...';
        try {
            const data = await App.request('/tickets?' + params.toString());
            this.allRows = Array.isArray(data) ? data : (data.data || []);
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('📋', 'Error al cargar', e.message);
            if (countEl) countEl.textContent = 'Error';
        }
    },

    /* --------------------------------------------------------------------
     *  Exportación masiva de tickets.
     *  Los métodos exportar(xlsx|csv) y exportarPdf() construyen la URL con
     *  los mismos filtros que la pantalla actual y disparan la descarga
     *  directamente desde el navegador (sin pasar por App.request porque
     *  el endpoint devuelve binario, no JSON).
     * --------------------------------------------------------------------*/
    construirParamsExport() {
        const params = new URLSearchParams();
        if (this.currentFilters.search)     params.set('search', this.currentFilters.search);
        if (this.currentFilters.estado)    params.set('estado', this.currentFilters.estado);
        if (this.currentFilters.id_empresa) params.set('id_empresa', this.currentFilters.id_empresa);
        if (this.currentFilters.fecha_desde) params.set('fecha_desde', this.currentFilters.fecha_desde);
        if (this.currentFilters.fecha_hasta) params.set('fecha_hasta', this.currentFilters.fecha_hasta);
        return params;
    },

    exportar(formato) {
        const params = this.construirParamsExport();
        params.set('format', formato);
        // El navegador detecta el Content-Disposition: attachment y
        // dispara la descarga automáticamente.
        const url = '/api/v1/tickets/export?' + params.toString();
        window.location.href = url;
        App.toast('Exportando ' + formato.toUpperCase() + '...', 'info');
    },

    imprimirTicket(idTicket) {
        // Abre la ficha de impresión en pestaña nueva.
        // El endpoint /tickets/{id}/print devuelve HTML standalone
        // con CSS @media print y botón "Imprimir / Guardar PDF".
        const url = `/api/v1/tickets/${encodeURIComponent(idTicket)}/print`;
        window.open(url, '_blank', 'noopener');
    },

    async exportarPdf() {
        // Para PDF usamos el formato JSON del endpoint de exportación
        // y generamos una vista HTML imprimible en una pestaña nueva.
        // El usuario usa "Guardar como PDF" del diálogo de impresión
        // del navegador (Ctrl+P). Esto evita incluir jsPDF/html2pdf
        // (que pesarían ~600 KB en el cliente).
        const params = this.construirParamsExport();
        params.set('format', 'json');
        try {
            App.toast('Generando PDF...', 'info');
            const resp = await fetch('/api/v1/tickets/export?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            if (!resp.ok) {
                // Si el servidor devuelve error, intentar leer el JSON para
                // mostrar el mensaje real (no solo "HTTP 404").
                let errMsg = 'HTTP ' + resp.status;
                try {
                    const errJson = await resp.json();
                    errMsg = errJson.message || errJson.error || errMsg;
                } catch (e) { /* no era JSON */ }
                throw new Error(errMsg);
            }
            const data = await resp.json();
            // El endpoint /tickets/export en formato json devuelve { ok, data, total, generated_at }
            // pero también puede devolver { ok: false, error, message } si falla.
            if (data.ok === false) {
                throw new Error(data.message || data.error || 'Error desconocido del servidor');
            }
            const rows = data.data || [];

            // Abrir pestaña nueva con HTML imprimible
            const w = window.open('', '_blank');
            if (!w) {
                App.toast('El navegador bloqueó la pestaña emergente. Permite popups para este sitio.', 'error', 8000);
                return;
            }
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const now = new Date();
            const fechaGen = now.toLocaleString('es-PE');

            const html = `<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Listado de Tickets</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; font-size: 11px; color: #111827; }
  .header { display: flex; justify-content: space-between; border-bottom: 3px solid #1e40af; padding-bottom: 10px; margin-bottom: 16px; }
  .header h1 { margin: 0; color: #1e3a8a; font-size: 20px; }
  .header .meta { color: #6b7280; font-size: 11px; text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
  th { background: #f3f4f6; color: #374151; font-size: 10px; text-transform: uppercase; }
  tr:nth-child(even) td { background: #fafafa; }
  .badge { padding: 2px 6px; border-radius: 3px; color: #fff; font-size: 10px; font-weight: 600; }
  .badge-pendiente { background: #f59e0b; }
  .badge-en_atencion { background: #3b82f6; }
  .badge-resuelto { background: #10b981; }
  .badge-cerrado { background: #6b7280; }
  .badge-anulado { background: #ef4444; }
  .acciones { position: sticky; top: 0; background: #1e40af; color: #fff; padding: 10px 16px; margin: -20px -20px 16px -20px; display: flex; justify-content: space-between; }
  .acciones button { background: #fff; color: #1e40af; border: none; padding: 6px 14px; border-radius: 4px; font-weight: 600; cursor: pointer; }
  .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #6b7280; text-align: center; }
  @media print { .acciones { display: none; } body { padding: 0; } }
</style></head><body>
  <div class="acciones">
    <strong>Listado de Tickets — ${rows.length} registro(s)</strong>
    <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
  </div>
  <div class="header">
    <h1>Listado de Tickets</h1>
    <div class="meta">Generado: ${esc(fechaGen)}<br>Total: ${rows.length} ticket(s)</div>
  </div>
  <table>
    <thead><tr>
      <th>Nro</th><th>Fecha</th><th>Empresa</th><th>Contacto</th>
      <th>Técnico</th><th>Prioridad</th><th>Estado</th><th>Detalle</th>
    </tr></thead>
    <tbody>
      ${rows.map(r => {
        const est = (r.estado || '').toLowerCase();
        return `<tr>
          <td><strong>${esc(r.nro_ticket)}</strong></td>
          <td>${esc(r.fecha_registro)} ${esc(r.hora_registro || '')}</td>
          <td>${esc(r.empresa_razon_social)}<br><span style="color:#6b7280">${esc(r.ruc || '')}</span></td>
          <td>${esc(r.contacto_nombre || '')}<br><span style="color:#6b7280">${esc(r.contacto_telefono || '')}</span></td>
          <td>${esc(r.tecnico_nombre || '')}</td>
          <td>${esc(r.prioridad || '')}</td>
          <td><span class="badge badge-${est}">${esc(r.estado || '')}</span></td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.detalle_reporte || '')}">${esc((r.detalle_reporte || '').substring(0, 100))}</td>
        </tr>`;
      }).join('')}
    </tbody>
  </table>
  <div class="footer">Generado por MonosyncERP · Atenciones — ${esc(fechaGen)}</div>
</body></html>`;
            w.document.open();
            w.document.write(html);
            w.document.close();
            App.toast('PDF listo. Usa "Guardar como PDF" en el diálogo de impresión.', 'success', 6000);
        } catch (e) {
            console.error('[Tickets exportarPdf]', e);
            App.toast('Error al generar PDF: ' + e.message, 'error', 8000);
        }
    },

    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const countEl = document.getElementById('ticketsCount');
        if (countEl) {
            const total = pageData.total;
            const filtros = [];
            if (this.currentFilters.search) filtros.push('texto: "' + this.currentFilters.search + '"');
            if (this.currentFilters.estado) filtros.push('estado: ' + this.currentFilters.estado);
            if (this.currentFilters.id_empresa) filtros.push('empresa filtrada');
            if (this.currentFilters.fecha_desde || this.currentFilters.fecha_hasta) filtros.push('fecha');
            const filtroStr = filtros.length ? ' · ' + filtros.join(', ') : '';
            countEl.textContent = total + (total === 1 ? ' ticket' : ' tickets') + filtroStr;
        }
        const columns = [
            { key: 'nro_ticket', label: 'Nro', render: r => `<a href="#/tickets/${r.id_ticket}" style="color:var(--c-accent);font-weight:600">${App.escapeHtml(r.nro_ticket)}</a>` },
            { key: 'fecha_registro', label: 'Fecha', render: r => App.formatDate(r.fecha_registro) },
            { key: 'empresa_razon_social', label: 'Empresa' },
            { key: 'prioridad', label: 'Prioridad', render: r => App.badge(r.prioridad, r.prioridad.toLowerCase()) },
            { key: 'estado', label: 'Estado', render: r => App.badge(r.estado, r.estado.toLowerCase()) },
            { key: 'acciones', label: 'Acciones', render: r => `
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <button class="btn btn-sm btn-primary" title="Ver detalle" aria-label="Ver detalle" onclick="App.navigate('/tickets/${r.id_ticket}')"><i class="bi bi-eye" aria-hidden="true"></i> Ver</button>
                    <button class="btn btn-sm btn-secondary" title="Imprimir ficha del ticket" aria-label="Imprimir ficha" onclick="App.views.tickets.imprimirTicket(${r.id_ticket})"><i class="bi bi-printer" aria-hidden="true"></i></button>
                </div>` },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'No hay tickets que coincidan con los filtros' });
        const paginationHtml = App.renderPagination({
            page: pageData.page,
            totalPages: pageData.totalPages,
            total: pageData.total,
            perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Tickets' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    async renderDetail(container, id) {
        container.innerHTML = '<div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando ticket...</p></div>';
        try {
            // OPTIMIZACIÓN: disparar ambas peticiones en paralelo en vez de secuenciales.
            // Antes: 2 round-trips al servidor -> ~2T. Ahora: 1 round-trip -> ~T.
            // Con 5000 usuarios concurrentes esto reduce a la mitad la carga del servidor.
            const [data, documents] = await Promise.all([
                App.request(`/tickets/${id}`),
                App.request(`/tickets/${id}/files`).catch((error) => {
                    console.error('[Tickets] Error al cargar documentos', error);
                    return [];
                }),
            ]);
            const t = data.ticket || data;
            const tiempos = data.tiempos || {};
            const historial = data.historial || [];
            const atenciones = data.atenciones || [];
            const estado = (t.estado || '').toUpperCase();
            const esCerradoOAnulado = estado === 'CERRADO' || estado === 'ANULADO';
            const esEliminado = Number(t.eliminado) === 1;
            const esAutoCerrado = (t.origen_cierre === 'AUTOMATICO') || (t.origen === 'AUTOMATICO' && estado === 'CERRADO');

            // Determinar si el usuario es administrador (múltiples criterios)
            const user = App.config.user || {};
            const userCorreo = (user.correo || user.email || '').toLowerCase();
            const userRol = (user.rol || '').toUpperCase();
            const esAdmin = userRol === 'ADMINISTRADOR' ||
                            userRol === 'ADMIN' ||
                            userCorreo === 'admin@soporte.com' ||
                            userCorreo === 'admin@atenciones.local' ||
                            userCorreo === 'admin@midominio.com';

            // Determinar acciones disponibles según estado
            // REGLAS DE NEGOCIO (13_transiciones_estado_y_performance.sql):
            //   - RESUELTO  → solo puede pasar a CERRADO.
            //   - CERRADO   → solo admin (o permiso especial) puede reabrirlo
            //                 vía POST /tickets/{id}/reabir. Vuelve a PENDIENTE.
            //   - ANULADO   → no puede cambiar de estado.
            const puedeRegistrarAtencion = ['PENDIENTE','EN_ATENCION','REABIERTO'].includes(estado);
            const puedeSolucionar = ['PENDIENTE','EN_ATENCION','REABIERTO'].includes(estado) && atenciones.some(a => a.estado === 'COMPLETADO' && !Number(a.es_solucionadora));
            const puedeCerrar = estado === 'RESUELTO';
            const puedeAnular = ['PENDIENTE','EN_ATENCION','RESUELTO'].includes(estado);
            // El admin puede eliminar tickets de CUALQUIER estado
            const puedeEliminar = esAdmin && !esEliminado;
            // La reapertura es una acción separada del cambio de estado general:
            // solo aplica para tickets CERRADOS y solo si el usuario es admin
            // (o tiene permiso especial TICKETS.eliminar, que es la "acceso"
            // mencionada por el usuario).
            const puedeReabrir = estado === 'CERRADO' && esAdmin;
            // El modal de "Cambiar Estado" ya NO muestra CERRADO/ANULADO como
            // opciones de salida (esos estados se manejan por acciones dedicadas).
            const puedeCambiarEstado = !esEliminado
                && estado !== 'CERRADO'
                && estado !== 'ANULADO'
                && (puedeRegistrarAtencion || puedeCerrar || puedeAnular);

            // ============================================================
            //  LAYOUT COMPLETO DEL DETALLE DE TICKET
            //   1. Cabecera con nro_ticket + acciones contextuales
            //   2. Banner si es cerrado automáticamente
            //   3. Fila 1: Empresa (izquierda) | Datos del ticket (derecha)
            //   4. Tiempos calculados (6 indicadores A-F)
            //   5. Detalle del reporte
            //   6. Documentos generales del ticket
            //   7. Atenciones (cronológico, con flag solucionadora)
            //   8. Historial completo
            //   9. Acciones administrativas (eliminar)
            //  10. Footer
            // ============================================================
            container.innerHTML = `
                <div class="card-header mb-4">
                    <h1 style="color:var(--c-primary)">
                        Ticket ${App.escapeHtml(t.nro_ticket)}
                        ${esAutoCerrado ? '<span class="badge badge-info ms-2" title="Ticket cerrado automáticamente por proceso"><i class="bi bi-robot" aria-hidden="true"></i> Cierre automático</span>' : ''}
                        ${esEliminado ? '<span class="badge badge-danger ms-2"><i class="bi bi-trash3" aria-hidden="true"></i> Eliminado</span>' : ''}
                    </h1>
                    <div class="card-actions">
                        <button class="btn" onclick="App.navigate('/tickets')"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver</button>
                        <button class="btn btn-secondary" id="btnPrintTicket" title="Imprimir ficha del ticket o guardar como PDF"><i class="bi bi-printer" aria-hidden="true"></i> Imprimir</button>
                        ${puedeCambiarEstado ? `<button class="btn btn-primary" id="btnChangeStatus"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Cambiar Estado</button>` : ''}
                        ${puedeReabrir ? `<button class="btn btn-warning" id="btnReabrirTicket" title="Reabrir ticket cerrado (requiere permiso administrativo)"><i class="bi bi-unlock" aria-hidden="true"></i> Reabrir</button>` : ''}
                    </div>
                </div>

                ${esAutoCerrado ? `
                <div class="alert alert-info mb-4" role="status">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <strong>Ticket cerrado automáticamente.</strong>
                    Este ticket fue generado y cerrado por un proceso automático sin requerir intervención técnica.
                    ${t.motivo_cierre ? `<br><em>Motivo del cierre automático:</em> ${App.escapeHtml(t.motivo_cierre)}` : ''}
                </div>` : ''}

                <div class="card-grid card-grid--2">
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="bi bi-building" aria-hidden="true"></i> Empresa</div>
                            <a href="#/empresas/${Number(t.id_empresa)}" class="btn btn-sm"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Ver ficha</a>
                        </div>
                        <div class="kv-list">
                            <div class="kv"><span class="kv__k">Razón social</span><span class="kv__v"><a href="#/empresas/${Number(t.id_empresa)}">${App.escapeHtml(t.empresa_razon_social || '-')}</a></span></div>
                            <div class="kv"><span class="kv__k">RUC</span><span class="kv__v">${App.escapeHtml(t.ruc || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Nombre comercial</span><span class="kv__v">${App.escapeHtml(t.empresa_nombre_comercial || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Teléfono</span><span class="kv__v">${App.escapeHtml(t.empresa_telefono || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Correo</span><span class="kv__v">${App.escapeHtml(t.empresa_correo || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Rubro</span><span class="kv__v">${App.escapeHtml(t.rubro_nombre || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Dirección</span><span class="kv__v">${App.escapeHtml(t.empresa_direccion || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Ubicación</span><span class="kv__v">${App.escapeHtml(t.division_nombre || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Sede</span><span class="kv__v">${App.escapeHtml(t.sucursal_nombre || 'Sede principal')}</span></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="card-title"><i class="bi bi-tags" aria-hidden="true"></i> Datos del ticket</div>
                        </div>
                        <div class="kv-list">
                            <div class="kv"><span class="kv__k">Nro</span><span class="kv__v"><strong>${App.escapeHtml(t.nro_ticket)}</strong></span></div>
                            <div class="kv"><span class="kv__k">Estado</span><span class="kv__v">${App.badge(t.estado, t.estado.toLowerCase())}</span></div>
                            <div class="kv"><span class="kv__k">Prioridad</span><span class="kv__v">${App.badge(t.prioridad, t.prioridad.toLowerCase())}</span></div>
                            <div class="kv"><span class="kv__k">Origen</span><span class="kv__v">${t.origen === 'AUTOMATICO' ? 'Automático' : 'Manual'}</span></div>
                            <div class="kv"><span class="kv__k">Creado</span><span class="kv__v">${App.formatDateTime(t.fecha_registro)}</span></div>
                            ${t.fecha_inicio_atencion ? `<div class="kv"><span class="kv__k">Inicio atención</span><span class="kv__v">${App.formatDateTime(t.fecha_inicio_atencion)}</span></div>` : ''}
                            ${t.fecha_solucion ? `<div class="kv"><span class="kv__k">Solución</span><span class="kv__v">${App.formatDateTime(t.fecha_solucion)}</span></div>` : ''}
                            ${t.fecha_cierre ? `<div class="kv"><span class="kv__k">Cierre</span><span class="kv__v">${App.formatDateTime(t.fecha_cierre + ' ' + (t.hora_cierre || ''))}</span></div>` : ''}
                            ${t.fecha_anulacion ? `<div class="kv"><span class="kv__k">Anulación</span><span class="kv__v">${App.formatDateTime(t.fecha_anulacion)}</span></div>` : ''}
                            <div class="kv"><span class="kv__k">Contacto</span><span class="kv__v">${App.escapeHtml(`${t.contacto_nombres || ''} ${t.contacto_apellido || ''}`.trim() || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Técnico asignado</span><span class="kv__v">${App.escapeHtml(`${t.tecnico_nombres || ''} ${t.tecnico_apellido || ''}`.trim() || '-')}</span></div>
                            ${t.motivo_solucion ? `<div class="kv"><span class="kv__k">Motivo solución</span><span class="kv__v">${App.escapeHtml(t.motivo_solucion)}</span></div>` : ''}
                            ${t.motivo_cierre ? `<div class="kv"><span class="kv__k">Motivo cierre</span><span class="kv__v">${App.escapeHtml(t.motivo_cierre)}</span></div>` : ''}
                            ${t.motivo_anulacion ? `<div class="kv"><span class="kv__k">Motivo anulación</span><span class="kv__v">${App.escapeHtml(t.motivo_anulacion)}</span></div>` : ''}
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Tiempos del ticket</div></div>
                    <div class="tiempos-grid">
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Tiempo transcurrido</div>
                            <div class="tiempo-item__value">${tiempos.tiempo_transcurrido_formato || '<span class="text-muted">—</span>'}</div>
                            <div class="tiempo-item__hint">${estado === 'CERRADO' || estado === 'ANULADO' ? 'Cerrado/Anulado' : 'En curso (provisional)'}</div>
                        </div>
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Hasta primera atención</div>
                            <div class="tiempo-item__value">${tiempos.tiempo_primera_atencion_formato || '<span class="text-muted">—</span>'}</div>
                            <div class="tiempo-item__hint">${t.fecha_inicio_atencion ? App.formatDateTime(t.fecha_inicio_atencion) : 'Sin atención'}</div>
                        </div>
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Tiempo en atenciones</div>
                            <div class="tiempo-item__value">${tiempos.tiempo_atenciones_formato || '<span class="text-muted">—</span>'}</div>
                            <div class="tiempo-item__hint">Trabajo efectivo técnico</div>
                        </div>
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Tiempo de resolución</div>
                            <div class="tiempo-item__value">${tiempos.tiempo_resolucion_formato || '<span class="text-muted">—</span>'}</div>
                            <div class="tiempo-item__hint">${t.fecha_solucion ? 'Hasta solución' : 'No solucionado'}</div>
                        </div>
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Tiempo total de cierre</div>
                            <div class="tiempo-item__value">${tiempos.tiempo_cierre_formato || '<span class="text-muted">—</span>'}</div>
                            <div class="tiempo-item__hint">${t.fecha_cierre ? 'Creación → Cierre' : 'No cerrado'}</div>
                        </div>
                        <div class="tiempo-item">
                            <div class="tiempo-item__label">Estado actual</div>
                            <div class="tiempo-item__value">${App.badge(t.estado, t.estado.toLowerCase())}</div>
                            <div class="tiempo-item__hint">${esAutoCerrado ? 'Cerrado automáticamente' : (t.origen === 'AUTOMATICO' ? 'Origen automático' : 'Origen manual')}</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-card-text" aria-hidden="true"></i> Detalle del reporte</div></div>
                    <div class="detail-text">${App.escapeHtml(t.detalle_reporte || 'Sin descripción')}</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-paperclip" aria-hidden="true"></i> Documentos generales del ticket</div>
                        ${!esEliminado ? `<button class="btn btn-sm btn-primary" id="btnUploadDocument"><i class="bi bi-upload" aria-hidden="true"></i> Adjuntar</button>` : ''}
                    </div>
                    <div id="uploadDocumentRow" class="form-row" style="display:none;align-items:end;margin-bottom:12px">
                        <div class="form-group"><label class="form-label" for="ticketDocument">Documento (max. 100 MB)</label>
                            <input class="form-input" type="file" id="ticketDocument" accept=".pdf,.jpg,.jpeg,.png,.gif,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.mov,.webm,.mp3,.wav,.zip,.rar,.7z,.msg,.eml,.html,.htm,.json,.xml"></div>
                        <button class="btn btn-primary" id="btnUploadDocumentConfirm" type="button">Subir</button>
                    </div>
                    <div class="table-wrap"><table><thead><tr><th>Documento</th><th>Tamaño</th><th>Fecha</th>${!esEliminado ? '<th>Acciones</th>' : ''}</tr></thead><tbody>
                        ${documents.map(d => `<tr>
                            <td><a href="/api/v1/tickets/${encodeURIComponent(id)}/files/${Number(d.id_documento)}"><i class="bi bi-file-earmark" aria-hidden="true"></i> ${App.escapeHtml(d.nombre_original)}</a></td>
                            <td>${App.formatNumber(Number(d.tamano_bytes) / 1024, 1)} KB</td>
                            <td>${App.formatDateTime(d.fecha_registro)}</td>
                            ${!esEliminado ? `<td><button class="btn btn-sm btn-danger" type="button" data-delete-document="${Number(d.id_documento)}"><i class="bi bi-trash3" aria-hidden="true"></i></button></td>` : ''}
                        </tr>`).join('') || '<tr><td colspan="4" class="text-muted">Sin documentos adjuntos</td></tr>'}
                    </tbody></table></div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> Atenciones (${atenciones.length})</div>
                        ${puedeRegistrarAtencion ? `<button class="btn btn-sm btn-primary" id="btnAddAtencion"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva atención</button>` : ''}
                    </div>
                    ${atenciones.length > 0 && !atenciones.some(a => a.estado === 'COMPLETADO') && puedeRegistrarAtencion ? `
                    <div class="alert alert-info" style="margin:8px;">
                        <i class="bi bi-info-circle"></i>
                        Las atenciones en <strong>En Proceso</strong> no requieren solución. Para marcar el ticket como resuelto,
                        crea una atención con estado <strong>Completado</strong> y luego usa el botón "Marcar como solucionadora".
                    </div>` : ''}
                    <div>
                        ${atenciones.map((a, idxAtencion) => {
                            const esSolucionadora = Number(a.es_solucionadora) === 1;
                            const numAtencion = idxAtencion + 1; // Numeración por ticket, empieza en 1
                            return `
                            <div class="atencion-item${esSolucionadora ? ' atencion-item--solucionadora' : ''}">
                                <div class="atencion-item__head">
                                    <strong><span class="atencion-numero">#${numAtencion}</span> <i class="bi bi-person-badge" aria-hidden="true"></i> ${App.escapeHtml(a.tecnico_nombre || 'Técnico')}</strong>
                                    <div class="atencion-item__badges">
                                        ${esSolucionadora ? '<span class="badge badge-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Solucionadora</span>' : ''}
                                        ${App.badge(a.estado, (a.estado || '').toLowerCase().replace('_', ''))}
                                    </div>
                                </div>
                                <div class="atencion-item__meta">
                                    <span><i class="bi bi-calendar-event" aria-hidden="true"></i> ${App.formatDateTime((a.fecha_inicio || '') + ' ' + (a.hora_inicio || ''))}</span>
                                    ${a.fecha_fin ? `<span><i class="bi bi-calendar-check" aria-hidden="true"></i> Fin: ${App.formatDateTime((a.fecha_fin || '') + ' ' + (a.hora_fin || ''))}</span>` : ''}
                                    ${a.duracion_formato ? `<span><i class="bi bi-stopwatch" aria-hidden="true"></i> Duración: ${App.escapeHtml(a.duracion_formato)}</span>` : ''}
                                </div>
                                <p class="text-sm atencion-item__detalle"><strong>Detalle:</strong> ${App.escapeHtml(a.detalle_atencion || '')}</p>
                                ${a.diagnostico ? `<p class="text-sm atencion-item__detalle"><strong>Diagnóstico:</strong> ${App.escapeHtml(a.diagnostico)}</p>` : ''}
                                ${a.acciones_ejecutadas ? `<p class="text-sm atencion-item__detalle"><strong>Acciones:</strong> ${App.escapeHtml(a.acciones_ejecutadas)}</p>` : ''}
                                ${a.solucion_aplicada ? `<p class="text-sm atencion-item__detalle"><strong>Solución:</strong> ${App.escapeHtml(a.solucion_aplicada)}</p>` : ''}
                                ${a.resultado ? `<p class="text-sm atencion-item__detalle"><strong>Resultado:</strong> ${App.escapeHtml(a.resultado)}</p>` : ''}
                                <div class="atencion-item__actions">
                                    <button class="btn btn-sm btn-secondary" data-editar-atencion="${a.id_atencion}" title="Editar atención">
                                        <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                    </button>
                                    ${!esSolucionadora && a.estado === 'COMPLETADO' && ['PENDIENTE','EN_ATENCION','REABIERTO'].includes(estado) ? `
                                        <button class="btn btn-sm btn-success" data-marcar-solucionadora="${a.id_atencion}">
                                            <i class="bi bi-check-circle" aria-hidden="true"></i> Marcar como solucionadora
                                        </button>` : ''}
                                </div>
                            </div>`;
                        }).join('') || '<p class="text-muted">Sin atenciones registradas</p>'}
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-list-check" aria-hidden="true"></i> Historial completo de eventos</div></div>
                    <div class="timeline">
                        ${(historial).map(h => `
                            <div class="timeline-item">
                                <div class="timeline-item__title">
                                    ${App.badge(h.estado_nuevo || h.tipo_evento, (h.estado_nuevo || h.tipo_evento || '').toLowerCase())}
                                    <span class="timeline-item__tipo">${App.escapeHtml(h.tipo_evento || 'EVENTO')}</span>
                                </div>
                                <div class="timeline-item__date">${App.formatDateTime(h.fecha_cambio)}</div>
                                <div class="timeline-item__desc">${App.escapeHtml(h.motivo || '')}</div>
                                ${h.usuario_nombre ? `<div class="timeline-item__user"><i class="bi bi-person" aria-hidden="true"></i> ${App.escapeHtml(h.usuario_nombre)} · ${App.escapeHtml(h.origen_evento || 'MANUAL')}</div>` : ''}
                            </div>`).join('') || '<p class="text-muted">Sin historial</p>'}
                    </div>
                </div>

                ${puedeEliminar ? `
                <div class="card card-danger">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Acciones administrativas</div>
                    </div>
                    <div class="card-body">
                        <p class="text-sm">Como administrador, puede eliminar este ticket (estado actual: <strong>${App.escapeHtml(estado)}</strong>). Se conservará el historial para auditoría y el código visible quedará disponible para reutilización.</p>
                        <button class="btn btn-danger" id="btnDeleteTicket"><i class="bi bi-trash3" aria-hidden="true"></i> Eliminar ticket (admin)</button>
                    </div>
                </div>` : ''}

                ${App.renderFooter({ moduleName: `Ticket ${t.nro_ticket} · ${estado}` })}
            `;

            // Listeners
            document.getElementById('btnChangeStatus')?.addEventListener('click', () => this.showStatusModal(t));
            document.getElementById('btnReabrirTicket')?.addEventListener('click', () => this.showReabrirModal(t));
            document.getElementById('btnPrintTicket')?.addEventListener('click', () => {
                // Reutiliza el mismo método que la lista de tickets.
                this.imprimirTicket(t.id_ticket);
            });
            document.getElementById('btnAddAtencion')?.addEventListener('click', () => this.showAtencionModal(t));
            document.getElementById('btnUploadDocument')?.addEventListener('click', () => {
                const row = document.getElementById('uploadDocumentRow');
                row.style.display = row.style.display === 'none' ? 'grid' : 'none';
            });
            document.getElementById('btnUploadDocumentConfirm')?.addEventListener('click', async () => {
                const input = document.getElementById('ticketDocument');
                if (!input.files.length) { App.toast('Seleccione un documento', 'warning'); return; }
                const form = new FormData();
                form.append('documento', input.files[0]);
                try {
                    // Usar fetch directamente (no App.request) para FormData
                    // porque App.request puede interferir con el Content-Type
                    const r = await fetch('/api/v1/tickets/' + encodeURIComponent(id) + '/files', {
                        method: 'POST',
                        credentials: 'include',
                        body: form
                        // NO poner Content-Type: el navegador lo setea automáticamente con el boundary
                    });
                    const text = await r.text();
                    let j;
                    try { j = JSON.parse(text); } catch { j = { ok: false, message: text.slice(0, 300) }; }
                    if (!r.ok || !j.ok) {
                        throw new Error(j.message || j.errors?.documento || ('HTTP ' + r.status));
                    }
                    App.toast('Documento adjuntado', 'success');
                    this.renderDetail(container, id);
                } catch (e) { App.toast('Error al subir: ' + e.message, 'error', 8000); }
            });
            container.querySelectorAll('[data-delete-document]').forEach(button => {
                button.addEventListener('click', () => {
                    const documentId = Number(button.dataset.deleteDocument);
                    const documentInfo = documents.find(d => Number(d.id_documento) === documentId);
                    App.confirmDelete(documentInfo?.nombre_original || 'documento', async () => {
                        try {
                            await App.request(`/tickets/${id}/files/${documentId}`, { method: 'DELETE' });
                            App.toast('Documento eliminado', 'success');
                            this.renderDetail(container, id);
                        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
                    });
                });
            });
            container.querySelectorAll('[data-marcar-solucionadora]').forEach(button => {
                button.addEventListener('click', () => {
                    const idAtencion = Number(button.dataset.marcarSolucionadora);
                    this.showSolucionarModal(t, idAtencion);
                });
            });
            // FIX: handler del botón "Editar" atención (abre el modal de edición)
            container.querySelectorAll('[data-editar-atencion]').forEach(button => {
                button.addEventListener('click', () => {
                    const idAtencion = Number(button.dataset.editarAtencion);
                    const atencion = atenciones.find(a => Number(a.id_atencion) === idAtencion);
                    if (!atencion) {
                        App.toast('No se pudo encontrar la atención', 'error');
                        return;
                    }
                    App.showEditAtencionModal(t, atencion, () => {
                        // Callback tras guardar: re-renderizar el detalle
                        this.renderDetail(container, id);
                    });
                });
            });
            document.getElementById('btnDeleteTicket')?.addEventListener('click', () => this.showDeleteModal(t));
        } catch (e) {
            container.innerHTML = App.emptyState('⚠️', 'Error al cargar ticket', e.message);
        }
    },

    async showFormModal(ticket) {
        let motivos = [];
        try {
            motivos = await App.request('/motivos');
        } catch (e) { App.toast('Error al cargar motivos', 'error'); return; }

        App.showModal('Nuevo Ticket',
            `<form id="formTicket">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Empresa <span class="req">*</span></label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="empresa_display" placeholder="Pulse 'Buscar' para seleccionar" readonly>
                            <input type="hidden" id="id_empresa">
                            <button type="button" class="btn" id="btnBuscarEmpresa"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contacto <span class="req">*</span></label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="contacto_display" placeholder="Seleccione empresa primero" readonly>
                            <input type="hidden" id="id_empresa_contacto">
                            <button type="button" class="btn" id="btnBuscarContacto" disabled><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sucursal (opcional)</label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="sucursal_display" placeholder="Sede principal (por defecto)" readonly>
                            <input type="hidden" id="id_sucursal">
                            <button type="button" class="btn" id="btnBuscarSucursal" disabled><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                            <button type="button" class="btn" id="btnLimpiarSucursal" hidden title="Quitar sucursal"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Motivo</label>
                        <select class="form-select" id="id_motivo_contacto">
                            <option value="">Sin motivo</option>
                            ${motivos.map(m => `<option value="${m.id_motivo}">${App.escapeHtml(m.nombre)}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Prioridad</label>
                        <select class="form-select" id="prioridad">
                            <option value="MEDIA">Media</option>
                            <option value="BAJA">Baja</option>
                            <option value="ALTA">Alta</option>
                            <option value="CRITICA">Crítica</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Detalle <span class="req">*</span></label>
                    <textarea class="form-textarea" id="detalle_reporte" rows="5" required placeholder="Describa el problema..."></textarea>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Seleccione empresa, luego contacto y (opcionalmente) sucursal usando los botones <strong>Buscar</strong>.
                    Las búsquedas se hacen por ventana modal con autocompletado.
                </div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveTicket"><i class="bi bi-check-lg" aria-hidden="true"></i> Crear Ticket</button>`);

        // Botón Buscar Empresa (ventana modal)
        document.getElementById('btnBuscarEmpresa').addEventListener('click', () => {
            App.showSelectorModal(
                'Seleccionar Empresa',
                '/empresas',
                [
                    { key: 'ruc', label: 'RUC' },
                    { key: 'razon_social', label: 'Razón Social' },
                    { key: 'correo', label: 'Correo' },
                    { key: 'rubro', label: 'Rubro' },
                ],
                (item) => {
                    document.getElementById('id_empresa').value = item.id_empresa;
                    document.getElementById('empresa_display').value = item.razon_social + (item.ruc ? ' (' + item.ruc + ')' : '');
                    // Reset contacto y sucursal al cambiar empresa
                    document.getElementById('id_empresa_contacto').value = '';
                    document.getElementById('contacto_display').value = '';
                    document.getElementById('id_sucursal').value = '';
                    document.getElementById('sucursal_display').value = '';
                    document.getElementById('sucursal_display').placeholder = 'Sede principal (por defecto)';
                    document.getElementById('btnBuscarContacto').disabled = false;
                    document.getElementById('btnBuscarSucursal').disabled = false;
                    App.toast('Empresa seleccionada: ' + item.razon_social, 'success');
                }
            );
        });

        // Botón Buscar Contacto (requiere empresa primero)
        document.getElementById('btnBuscarContacto').addEventListener('click', () => {
            const idEmpresa = parseInt(document.getElementById('id_empresa').value);
            if (!idEmpresa) {
                App.toast('Seleccione una empresa primero', 'warning');
                return;
            }
            App.showSelectorModal(
                'Seleccionar Contacto',
                '/contactos?id_empresa=' + idEmpresa,
                [
                    { key: 'nombres', label: 'Nombres' },
                    { key: 'apellido_paterno', label: 'Apellido' },
                    { key: 'cargo', label: 'Cargo' },
                    { key: 'correo_empresa', label: 'Correo' },
                ],
                (item) => {
                    document.getElementById('id_empresa_contacto').value = item.id_empresa_contacto;
                    const nombreCompleto = ((item.nombres || '') + ' ' + (item.apellido_paterno || '')).trim();
                    document.getElementById('contacto_display').value = nombreCompleto + (item.cargo ? ' — ' + item.cargo : '');
                    App.toast('Contacto seleccionado: ' + nombreCompleto, 'success');
                }
            );
        });

        // Botón Buscar Sucursal (requiere empresa primero)
        document.getElementById('btnBuscarSucursal').addEventListener('click', () => {
            const idEmpresa = parseInt(document.getElementById('id_empresa').value);
            if (!idEmpresa) {
                App.toast('Seleccione una empresa primero', 'warning');
                return;
            }
            App.showSelectorModal(
                'Seleccionar Sucursal',
                '/sucursales?id_empresa=' + idEmpresa,
                [
                    { key: 'nombre', label: 'Nombre' },
                    { key: 'direccion', label: 'Dirección' },
                    { key: 'telefono', label: 'Teléfono' },
                ],
                (item) => {
                    document.getElementById('id_sucursal').value = item.id_sucursal;
                    document.getElementById('sucursal_display').value = item.nombre + (item.es_principal ? ' (sede principal)' : '');
                    document.getElementById('btnLimpiarSucursal').hidden = false;
                    App.toast('Sucursal seleccionada: ' + item.nombre, 'success');
                }
            );
        });

        // Botón limpiar sucursal
        document.getElementById('btnLimpiarSucursal').addEventListener('click', () => {
            document.getElementById('id_sucursal').value = '';
            document.getElementById('sucursal_display').value = '';
            document.getElementById('sucursal_display').placeholder = 'Sede principal (por defecto)';
            document.getElementById('btnLimpiarSucursal').hidden = true;
        });

        document.getElementById('btnSaveTicket').addEventListener('click', async () => {
            const idEmpresa = parseInt(document.getElementById('id_empresa').value);
            const idContacto = parseInt(document.getElementById('id_empresa_contacto').value);
            const idSucursal = parseInt(document.getElementById('id_sucursal').value) || null;
            const detalle = document.getElementById('detalle_reporte').value.trim();
            if (!idEmpresa) { App.toast('Seleccione una empresa', 'warning'); return; }
            if (!idContacto) { App.toast('Seleccione un contacto', 'warning'); return; }
            if (!detalle) { App.toast('El detalle del reporte es requerido', 'warning'); return; }

            const data = {
                id_usuario: App.config.user.id_persona,
                id_empresa: idEmpresa,
                id_empresa_contacto: idContacto,
                id_sucursal: idSucursal,
                id_motivo_contacto: parseInt(document.getElementById('id_motivo_contacto').value) || null,
                detalle_reporte: detalle,
                prioridad: document.getElementById('prioridad').value,
                medio_contacto: 'SISTEMA',
            };
            try {
                const result = await App.request('/tickets', { method: 'POST', body: data });
                App.toast(`Ticket ${result.nro_ticket} creado`, 'success');
                App.closeModal();
                App.navigate('/tickets/' + result.id_ticket);
            } catch (e) {
                // Mostrar el mensaje de error real del backend (ya viene con detalle)
                App.toast('Error al crear ticket: ' + (e.message || 'Error desconocido'), 'error', 8000);
                console.error('[Tickets] Error al crear:', e);
            }
        });
    },

    showStatusModal(ticket) {
        // REGLAS DE NEGOCIO (13_transiciones_estado_y_performance.sql):
        //   - PENDIENTE   → EN_ATENCION | RESUELTO | ANULADO
        //   - EN_ATENCION → RESUELTO    | PENDIENTE | ANULADO
        //   - RESUELTO    → CERRADO (única opción, para otros destinos no hay flujo)
        //   - CERRADO     → no se muestra este modal (se usa el botón Reabrir)
        //   - ANULADO     → no se muestra este modal
        const estadoActual = (ticket.estado || '').toUpperCase();
        let opcionesHtml = '';
        if (estadoActual === 'PENDIENTE') {
            opcionesHtml = `
                <option value="">Seleccione...</option>
                <option value="EN_ATENCION">En Atención</option>
                <option value="RESUELTO">Resuelto (requiere atención solucionadora)</option>
                <option value="ANULADO">Anulado</option>`;
        } else if (estadoActual === 'EN_ATENCION') {
            opcionesHtml = `
                <option value="">Seleccione...</option>
                <option value="RESUELTO">Resuelto (requiere atención solucionadora)</option>
                <option value="PENDIENTE">Volver a Pendiente</option>
                <option value="ANULADO">Anulado</option>`;
        } else if (estadoActual === 'RESUELTO') {
            // RESUELTO solo puede pasar a CERRADO. La confirmación final
            // se hace también con POST /tickets/{id}/cerrar, pero el PATCH
            // /status con destino CERRADO está permitido y ejecuta el mismo
            // cálculo de tiempos totales en el SP.
            opcionesHtml = `
                <option value="">Seleccione...</option>
                <option value="CERRADO">Cerrado (cierre definitivo)</option>`;
        } else {
            // Para cualquier otro estado, no se debería mostrar este modal.
            // Pero por seguridad dejamos un mensaje informativo.
            opcionesHtml = `<option value="">No hay transiciones disponibles</option>`;
        }

        App.showModal(`Cambiar estado - ${ticket.nro_ticket}`,
            `<div class="form-group"><label class="form-label">Nuevo estado <span class="req">*</span></label>
                <select class="form-select" id="newEstado">${opcionesHtml}</select></div>
             <div class="form-group"><label class="form-label">Motivo <span class="req">*</span></label>
                <textarea class="form-textarea" id="motivo" required placeholder="Justifique el cambio de estado..."></textarea></div>
             <div class="alert alert-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
                Las transiciones están validadas en el backend.
                ${estadoActual === 'RESUELTO' ? '<strong>RESUELTO</strong> solo puede pasar a <strong>CERRADO</strong>. Si necesita reabrirlo, primero ciérrelo y luego use el botón "Reabrir" (admin).' : ''}
             </div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveStatus">Guardar</button>`);
        document.getElementById('btnSaveStatus').addEventListener('click', async () => {
            const estado = document.getElementById('newEstado').value;
            const motivo = document.getElementById('motivo').value.trim();
            if (!estado || !motivo) { App.toast('Complete todos los campos', 'warning'); return; }
            try {
                await App.request(`/tickets/${ticket.id_ticket}/status`, { method: 'PATCH', body: { nuevo_estado: estado, motivo } });
                App.toast('Estado actualizado', 'success');
                App.closeModal();
                this.renderDetail(document.getElementById('appView'), ticket.id_ticket);
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    showReabrirModal(ticket) {
        // Reapertura administrativa: solo tickets CERRADOS.
        // Vuelve a PENDIENTE y exige motivo. Llama al endpoint dedicado
        // POST /tickets/{id}/reabir (no usa PATCH /status).
        App.showModal(`Reabrir ticket - ${ticket.nro_ticket}`,
            `<div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                Está por reabrir un ticket <strong>CERRADO</strong>. El ticket volverá a estado
                <strong>PENDIENTE</strong> y deberá seguir el flujo normal
                (Pendiente → En atención → Resuelto → Cerrado). Las atenciones
                anteriores se conservan como historial pero ninguna quedará
                marcada como "solucionadora". Esta acción queda registrada en
                el historial con su usuario y motivo.
            </div>
            <div class="form-group"><label class="form-label">Motivo de reapertura <span class="req">*</span></label>
                <textarea class="form-textarea" id="motivoReabrir" required placeholder="Explique por qué se reabre este ticket cerrado..."></textarea></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-warning" id="btnConfirmReabrir"><i class="bi bi-unlock" aria-hidden="true"></i> Confirmar reapertura</button>`);
        document.getElementById('btnConfirmReabrir').addEventListener('click', async () => {
            const motivo = document.getElementById('motivoReabrir').value.trim();
            if (!motivo) { App.toast('El motivo es obligatorio', 'warning'); return; }
            try {
                await App.request(`/tickets/${ticket.id_ticket}/reabrir`, {
                    method: 'POST',
                    body: { motivo }
                });
                App.toast('Ticket reabierto. Vuelve a estado PENDIENTE.', 'success');
                App.closeModal();
                this.renderDetail(document.getElementById('appView'), ticket.id_ticket);
            } catch (e) {
                App.toast('Error al reabrir: ' + e.message, 'error', 8000);
            }
        });
    },

    showAtencionModal(ticket) {
        // FIX: usar el helper unificado App.showEditAtencionModal en modo "nueva"
        App.showEditAtencionModal(ticket, null, () => {
            this.renderDetail(document.getElementById('appView'), ticket.id_ticket);
        });
    },

    /* Modal para marcar una atención como solucionadora del ticket.
     *  El título NO muestra el ID de la atención (el usuario no quiere verlo).
     */
    showSolucionarModal(ticket, idAtencion) {
        App.showModal(`Marcar atención como solucionadora`,
            `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Al marcar esta atención como solucionadora, el ticket pasará a estado <strong>RESUELTO</strong> y se registrarán fecha y usuario de solución. Esta acción no se puede deshacer fácilmente.</div>
             <div class="form-group"><label class="form-label">Motivo / descripción de la solución <span class="req">*</span></label>
                <textarea class="form-textarea" id="motivoSolucion" required placeholder="Explique por qué esta atención resolvió el problema..."></textarea></div>
             <div class="form-group"><label class="form-label">Resultado (opcional)</label>
                <input type="text" class="form-input" id="resultadoSolucion" placeholder="Resultado final comprobado..." maxlength="500"></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-success" id="btnConfirmarSolucion"><i class="bi bi-check-circle" aria-hidden="true"></i> Confirmar solución</button>`);
        document.getElementById('btnConfirmarSolucion').addEventListener('click', async () => {
            const motivo = document.getElementById('motivoSolucion').value.trim();
            if (!motivo) { App.toast('El motivo de la solución es requerido', 'warning'); return; }
            try {
                await App.request(`/tickets/${ticket.id_ticket}/solucionar`, {
                    method: 'POST',
                    body: {
                        id_atencion: idAtencion,
                        motivo_solucion: motivo,
                        resultado: document.getElementById('resultadoSolucion').value || null,
                    }
                });
                // Mensaje genérico SIN número de atención.
                App.toast('Ticket resuelto correctamente', 'success');
                App.closeModal();
                this.renderDetail(document.getElementById('appView'), ticket.id_ticket);
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    /* Modal para eliminar ticket (solo admin, solo Cerrado/Anulado) */
    showDeleteModal(ticket) {
        App.showModal(`Eliminar ticket ${ticket.nro_ticket} - Acción administrativa`,
            `<div class="alert alert-danger"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> <strong>Acción administrativa.</strong> Solo administradores pueden eliminar tickets, y únicamente si están Cerrados o Anulados.</div>
             <p>Está por eliminar el ticket <strong>${App.escapeHtml(ticket.nro_ticket)}</strong> (estado: ${App.escapeHtml(ticket.estado)}).</p>
             <p class="text-sm text-muted">Se conservará el historial para auditoría. El código visible quedará disponible para reutilización. Las atenciones y documentos NO se eliminarán.</p>
             <div class="form-group"><label class="form-label">Motivo de eliminación <span class="req">*</span></label>
                <textarea class="form-textarea" id="motivoEliminacion" required placeholder="Justifique la eliminación del ticket..."></textarea></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-danger" id="btnConfirmarEliminacion"><i class="bi bi-trash3" aria-hidden="true"></i> Eliminar definitivamente</button>`);
        document.getElementById('btnConfirmarEliminacion').addEventListener('click', async () => {
            const motivo = document.getElementById('motivoEliminacion').value.trim();
            if (!motivo) { App.toast('El motivo de eliminación es requerido', 'warning'); return; }
            try {
                const result = await App.request(`/tickets/${ticket.id_ticket}`, {
                    method: 'DELETE',
                    body: { motivo_eliminacion: motivo }
                });
                App.toast(`Ticket eliminado. Código ${result.nro_ticket_liberado || ''} disponible para reutilización.`, 'success');
                App.closeModal();
                App.navigate('/tickets');
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

/* ============================================================================
 *  VISTA: TICKETS ELIMINADOS (historial de borrado - solo admin)
 *  Lista los tickets eliminados con: nro, estado anterior, empresa, motivo,
 *  usuario que eliminó, fecha y IP/hostname. Permite buscar y paginar.
 *  URL: #/tickets-eliminados
 * ========================================================================== */
App.views['tickets-eliminados'] = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    searchTimer: null,
    currentSearch: '',

    async render(container) {
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Historial de Tickets Eliminados</h1>
                <div class="card-actions">
                    <button class="btn" id="btnRefresh"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar</button>
                </div>
            </div>
            <div class="card">
                <div class="tickets-filtros">
                    <div class="tickets-filtros__busqueda">
                        <i class="bi bi-search tickets-filtros__icon" aria-hidden="true"></i>
                        <input type="search" class="form-input tickets-filtros__input"
                               id="inputBusquedaEliminados"
                               placeholder="Buscar por nro, empresa, motivo, usuario..."
                               autocomplete="off"
                               aria-label="Buscar tickets eliminados">
                        <button type="button" class="btn tickets-filtros__limpiar" id="btnLimpiarBusquedaEliminados" hidden title="Limpiar">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="tickets-filtros__resultados">
                        <span id="eliminadosCount" class="text-muted text-sm">Cargando...</span>
                    </div>
                </div>
                <div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando historial...</p></div></div>
            </div>`;
        document.getElementById('btnRefresh').addEventListener('click', async () => {
            document.getElementById('tableContainer').innerHTML = '<div class="loading-container"><div class="spinner"></div><p>Actualizando...</p></div>';
            try {
                this.allRows = await App.request('/tickets/deleted');
                this.renderTable();
            } catch (e) {
                document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error al actualizar', e.message);
            }
        });
        const inputBusqueda = document.getElementById('inputBusquedaEliminados');
        inputBusqueda.addEventListener('input', (event) => {
            const valor = event.target.value.trim();
            document.getElementById('btnLimpiarBusquedaEliminados').hidden = valor === '';
            this.currentSearch = valor;
            this.currentPage = 1;
            if (this.searchTimer) clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.renderTable(), 250);
        });
        document.getElementById('btnLimpiarBusquedaEliminados').addEventListener('click', () => {
            inputBusqueda.value = '';
            this.currentSearch = '';
            document.getElementById('btnLimpiarBusquedaEliminados').hidden = true;
            this.currentPage = 1;
            this.renderTable();
            inputBusqueda.focus();
        });

        try {
            this.allRows = await App.request('/tickets/deleted');
            this.renderTable();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error al cargar el historial', e.message);
        }
    },

    renderTable() {
        const search = (this.currentSearch || '').toLowerCase();
        const filtered = search
            ? this.allRows.filter(r =>
                (r.nro_ticket || '').toLowerCase().includes(search) ||
                (r.empresa_razon_social || '').toLowerCase().includes(search) ||
                (r.motivo_eliminacion || '').toLowerCase().includes(search) ||
                (r.correo_usuario || '').toLowerCase().includes(search) ||
                (r.estado_anterior || '').toLowerCase().includes(search))
            : this.allRows;

        const { rows, page, totalPages, total, perPage } = App.paginate(filtered, this.currentPage, this.perPage);
        document.getElementById('eliminadosCount').textContent = `${App.formatNumber(filtered.length)} ticket${filtered.length === 1 ? '' : 's'} eliminado${filtered.length === 1 ? '' : 's'}`;

        if (!rows.length) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('🗑️', 'Sin tickets eliminados', 'No se encontraron registros que coincidan con la búsqueda.');
            return;
        }

        const html = App.table({
            columns: [
                { key: 'nro_ticket', label: 'Nro. Ticket', render: r => `<strong>${App.escapeHtml(r.nro_ticket || '-')}</strong>` },
                { key: 'estado_anterior', label: 'Estado ant.', render: r => App.badge(r.estado_anterior || r.estado || '-', (r.estado_anterior || r.estado || '').toLowerCase()) },
                { key: 'empresa_razon_social', label: 'Empresa', render: r => App.escapeHtml(r.empresa_razon_social || '-') },
                { key: 'motivo_eliminacion', label: 'Motivo de eliminación', render: r => `<span class="text-sm">${App.escapeHtml((r.motivo_eliminacion || '-').slice(0, 200))}${(r.motivo_eliminacion || '').length > 200 ? '…' : ''}</span>` },
                { key: 'correo_usuario', label: 'Eliminado por', render: r => App.escapeHtml(r.correo_usuario || '-') },
                { key: 'fecha_eliminacion', label: 'Fecha eliminación', render: r => App.formatDateTime(r.fecha_eliminacion) },
                { key: 'ip_address', label: 'IP', render: r => `<span class="text-sm text-muted">${App.escapeHtml(r.ip_address || '-')}</span>` },
            ],
            rows: rows,
            empty: 'Sin tickets eliminados',
        });
        const paginationHtml = App.renderPagination({
            page, totalPages, total, perPage,
            onChange: (p) => { this.currentPage = p; this.renderTable(); document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
        const footerHtml = App.renderFooter({ moduleName: 'Tickets Eliminados · Auditoría administrativa' });
        document.getElementById('tableContainer').innerHTML = html + paginationHtml + footerHtml;
    },
};

/* ============================================================================
 *  VISTA: EMPRESAS (CRUD + selector modal + jerarquia + contactos/sucursales)
 * ========================================================================== */
App.views.empresas = {
    currentFilters: { query: '', id_rubro: '' },
    showInactive: false,
    currentPage: 1,
    perPage: 100,
    allRows: [],
    allRubros: [],
    searchTimer: null,
    relatedStatus: { sucursales: 'activas', contactos: 'activas' },

    async render(container, params = []) {
        if (params[0]) return this.renderDetail(container, params[0]);
        this.currentPage = 1;
        this.currentFilters = { query: '', id_rubro: '', rubro_text: '' };
        // Pre-cargar rubros para el autocompletado
        try { this.allRubros = await App.request('/rubros'); } catch (e) { this.allRubros = []; }
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Empresas</h1>
                <div class="card-actions">
                    <button class="btn" id="btnToggleInactive"><i class="bi bi-archive" aria-hidden="true"></i> Ver dadas de baja</button>
                    <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva Empresa</button>
                </div>
            </div>
            <div class="card">
                <div class="empresas-filtros">
                    <div class="empresas-filtros__busqueda">
                        <i class="bi bi-search empresas-filtros__icon" aria-hidden="true"></i>
                        <input type="search" class="form-input empresas-filtros__input"
                               id="inputBusquedaEmpresa"
                               placeholder="Buscar por RUC, razón social, nombre comercial o correo..."
                               autocomplete="off"
                               aria-label="Buscar empresas">
                        <button type="button" class="btn empresas-filtros__limpiar" id="btnLimpiarBusqueda" hidden title="Limpiar búsqueda">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="empresas-filtros__rubro-autocomplete">
                        <i class="bi bi-tag empresas-filtros__icon" aria-hidden="true"></i>
                        <input type="text" class="form-input empresas-filtros__input"
                               id="inputRubroEmpresa"
                               placeholder="Filtrar por rubro..."
                               autocomplete="off"
                               aria-label="Filtrar por rubro"
                               role="combobox"
                               aria-expanded="false"
                               aria-controls="rubroSuggestions">
                        <input type="hidden" id="hiddenIdRubro">
                        <button type="button" class="btn empresas-filtros__limpiar" id="btnLimpiarRubro" hidden title="Quitar filtro de rubro">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                        <div class="autocomplete-suggestions" id="rubroSuggestions" role="listbox" hidden></div>
                    </div>
                    <div class="empresas-filtros__resultados">
                        <span id="empresasCount" class="text-muted text-sm">Cargando...</span>
                    </div>
                </div>
                <div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div>
            </div>`;
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        document.getElementById('btnToggleInactive').addEventListener('click', (event) => {
            this.showInactive = !this.showInactive;
            this.currentPage = 1;
            event.currentTarget.innerHTML = this.showInactive
                ? '<i class="bi bi-check2-circle" aria-hidden="true"></i> Ver activas'
                : '<i class="bi bi-archive" aria-hidden="true"></i> Ver dadas de baja';
            document.getElementById('btnNew').hidden = this.showInactive;
            this.load();
        });
        // Búsqueda automática con debounce 300ms
        const inputBusqueda = document.getElementById('inputBusquedaEmpresa');
        inputBusqueda.addEventListener('input', (event) => {
            const valor = event.target.value.trim();
            document.getElementById('btnLimpiarBusqueda').hidden = valor === '';
            this.currentFilters.query = valor;
            this.currentPage = 1;
            if (this.searchTimer) clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.load(), 300);
        });
        // Búsqueda inmediata con Enter
        inputBusqueda.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.load();
            }
        });
        // Botón limpiar búsqueda de texto
        document.getElementById('btnLimpiarBusqueda').addEventListener('click', () => {
            inputBusqueda.value = '';
            this.currentFilters.query = '';
            document.getElementById('btnLimpiarBusqueda').hidden = true;
            this.currentPage = 1;
            this.load();
            inputBusqueda.focus();
        });

        // === Autocompletado de rubro ===
        const inputRubro = document.getElementById('inputRubroEmpresa');
        const hiddenRubro = document.getElementById('hiddenIdRubro');
        const suggestionsBox = document.getElementById('rubroSuggestions');
        let rubroTimer = null;
        let rubroHighlight = -1;

        const renderRubroSuggestions = (filter) => {
            const fil = filter.toLowerCase().trim();
            const matches = this.allRubros.filter(r =>
                r.nombre.toLowerCase().includes(fil)
            ).slice(0, 10);
            if (matches.length === 0) {
                suggestionsBox.hidden = true;
                inputRubro.setAttribute('aria-expanded', 'false');
                return;
            }
            suggestionsBox.innerHTML = matches.map((r, i) =>
                `<div class="autocomplete-suggestion" role="option" data-id="${r.id_rubro}" data-nombre="${App.escapeHtml(r.nombre)}" data-index="${i}">
                    <i class="bi bi-tag" aria-hidden="true"></i> ${App.escapeHtml(r.nombre)}
                </div>`
            ).join('');
            suggestionsBox.hidden = false;
            inputRubro.setAttribute('aria-expanded', 'true');
            rubroHighlight = -1;
            // Click en sugerencia
            suggestionsBox.querySelectorAll('.autocomplete-suggestion').forEach(s => {
                s.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    selectRubro(s.dataset.id, s.dataset.nombre);
                });
            });
        };

        const selectRubro = (id, nombre) => {
            hiddenRubro.value = id;
            inputRubro.value = nombre;
            this.currentFilters.id_rubro = id;
            this.currentFilters.rubro_text = nombre;
            suggestionsBox.hidden = true;
            inputRubro.setAttribute('aria-expanded', 'false');
            document.getElementById('btnLimpiarRubro').hidden = false;
            this.currentPage = 1;
            this.load();
        };

        inputRubro.addEventListener('input', (event) => {
            const valor = event.target.value;
            // Si el usuario borra el texto, quitar el filtro de rubro
            if (valor.trim() === '') {
                hiddenRubro.value = '';
                this.currentFilters.id_rubro = '';
                this.currentFilters.rubro_text = '';
                document.getElementById('btnLimpiarRubro').hidden = true;
                this.currentPage = 1;
                this.load();
            } else {
                // Si el texto no coincide exactamente con el rubro seleccionado, limpiar el hidden
                const rubroSeleccionado = this.allRubros.find(r => r.nombre === valor);
                if (!rubroSeleccionado) {
                    hiddenRubro.value = '';
                    this.currentFilters.id_rubro = '';
                }
            }
            if (rubroTimer) clearTimeout(rubroTimer);
            rubroTimer = setTimeout(() => renderRubroSuggestions(valor), 200);
        });

        inputRubro.addEventListener('focus', () => {
            if (inputRubro.value.trim() === '') {
                renderRubroSuggestions('');
            }
        });

        inputRubro.addEventListener('blur', () => {
            // Retrasar para permitir click en sugerencia
            setTimeout(() => {
                suggestionsBox.hidden = true;
                inputRubro.setAttribute('aria-expanded', 'false');
            }, 200);
        });

        // Navegación por teclado en sugerencias
        inputRubro.addEventListener('keydown', (event) => {
            const suggestions = suggestionsBox.querySelectorAll('.autocomplete-suggestion');
            if (suggestionsBox.hidden || suggestions.length === 0) {
                if (event.key === 'Enter' && this.currentFilters.id_rubro) {
                    event.preventDefault();
                    this.load();
                }
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                rubroHighlight = (rubroHighlight + 1) % suggestions.length;
                suggestions.forEach((s, i) => s.classList.toggle('highlighted', i === rubroHighlight));
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                rubroHighlight = rubroHighlight <= 0 ? suggestions.length - 1 : rubroHighlight - 1;
                suggestions.forEach((s, i) => s.classList.toggle('highlighted', i === rubroHighlight));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (rubroHighlight >= 0 && suggestions[rubroHighlight]) {
                    const s = suggestions[rubroHighlight];
                    selectRubro(s.dataset.id, s.dataset.nombre);
                }
            } else if (event.key === 'Escape') {
                suggestionsBox.hidden = true;
                inputRubro.setAttribute('aria-expanded', 'false');
            }
        });

        // Botón limpiar rubro
        document.getElementById('btnLimpiarRubro').addEventListener('click', () => {
            inputRubro.value = '';
            hiddenRubro.value = '';
            this.currentFilters.id_rubro = '';
            this.currentFilters.rubro_text = '';
            document.getElementById('btnLimpiarRubro').hidden = true;
            this.currentPage = 1;
            this.load();
            inputRubro.focus();
        });

        await this.load();
    },

    async load() {
        const params = new URLSearchParams();
        if (this.currentFilters.query) params.set('query', this.currentFilters.query);
        if (this.currentFilters.id_rubro) params.set('id_rubro', this.currentFilters.id_rubro);
        if (this.showInactive) params.set('estado', 'baja');
        const countEl = document.getElementById('empresasCount');
        if (countEl) countEl.textContent = 'Buscando...';
        try {
            const rows = await App.request('/empresas' + (params.toString() ? '?' + params.toString() : ''));
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('🏢', 'Error', e.message);
            if (countEl) countEl.textContent = 'Error al cargar';
        }
    },

    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        // Actualizar contador
        const countEl = document.getElementById('empresasCount');
        if (countEl) {
            const total = pageData.total;
            const filtrosActivos = (this.currentFilters.query ? ' · texto: "' + this.currentFilters.query + '"' : '') +
                                   (this.currentFilters.rubro_text ? ' · rubro: ' + this.currentFilters.rubro_text : '');
            countEl.textContent = total + (total === 1 ? ' empresa' : ' empresas') + filtrosActivos;
        }
        const columns = [
            { key: 'ruc', label: 'RUC' },
            { key: 'razon_social', label: 'Razón Social', render: r => `<a href="#/empresas/${r.id_empresa}" style="color:var(--c-accent);font-weight:600">${App.escapeHtml(r.razon_social)}</a>` },
            { key: 'correo', label: 'Correo' },
            { key: 'telefono', label: 'Teléfono', render: r => App.escapeHtml(r.telefono || '-') },
            { key: 'rubro', label: 'Rubro', render: r => App.escapeHtml(r.rubro || '-') },
            ...(this.showInactive ? [{ key: 'fecha_baja', label: 'Fecha de baja', render: r => App.formatDateTime(r.fecha_baja) }] : []),
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm" title="Ver detalle" aria-label="Ver detalle" onclick="App.navigate('/empresas/${r.id_empresa}')"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    ${this.showInactive
                        ? `<button class="btn btn-sm btn-primary" title="Reactivar" aria-label="Reactivar" onclick="App.views.empresas.reactivate(${r.id_empresa})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                           <button class="btn btn-sm btn-danger" title="Eliminar definitivamente" aria-label="Eliminar definitivamente" onclick="App.views.empresas.deletePermanent(${r.id_empresa}, '${App.escapeHtml(r.razon_social)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.empresas.showFormModal(${r.id_empresa})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                       <button class="btn btn-sm btn-danger" title="Dar de baja" aria-label="Dar de baja" onclick="App.views.empresas.delete(${r.id_empresa}, '${App.escapeHtml(r.razon_social)}')"><i class="bi bi-archive" aria-hidden="true"></i></button>`}
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: this.showInactive ? 'No hay empresas dadas de baja' : 'No hay empresas que coincidan con la búsqueda' });
        const paginationHtml = App.renderPagination({
            page: pageData.page,
            totalPages: pageData.totalPages,
            total: pageData.total,
            perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Empresas · Directorios' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    async showFormModal(id) {
        let empresa = {};
        if (id) {
            try { empresa = await App.request(`/empresas/${id}`); } catch (e) { App.toast('Error al cargar empresa', 'error'); return; }
        }
        const locationLocked = Boolean(id && Number(empresa.tiene_sede_principal) === 1);

        /* FIX: Guardar los valores originales (RUC y correo) que tendrá el
         * formulario al abrir el modal. validateField() usará estos valores
         * para SALTAR la validación de duplicados cuando el usuario salga del
         * campo SIN haberlo modificado. Así se evita el falso positivo
         * "El RUC ya existe" al editar otros campos de la misma empresa. */
        this._originalValues = {
            ruc:   String(empresa.ruc   || '').trim(),
            correo: String(empresa.correo || '').trim().toLowerCase(),
        };

        App.showModal(id ? 'Editar Empresa' : 'Nueva Empresa',
            `<form id="formEmpresa">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">RUC <span class="req">*</span></label>
                        <input type="text" class="form-input" id="ruc" required value="${App.escapeHtml(empresa.ruc || '')}" maxlength="11" inputmode="numeric" pattern="[0-9]{1,11}" title="Solo numeros, maximo 11 digitos" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)" onblur="App.views.empresas.validateField('ruc', ${id || 0})">
                        <div id="err_ruc" class="form-error"></div></div>
                    <div class="form-group"><label class="form-label">Razon Social <span class="req">*</span></label>
                        <input type="text" class="form-input" id="razon_social" required value="${App.escapeHtml(empresa.razon_social || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nombre Comercial</label>
                        <input type="text" class="form-input" id="nombre_comercial" value="${App.escapeHtml(empresa.nombre_comercial || '')}"></div>
                    <div class="form-group"><label class="form-label">Correo <span class="req">*</span></label>
                        <input type="email" class="form-input" id="correo" required value="${App.escapeHtml(empresa.correo || '')}" onblur="App.views.empresas.validateField('correo', ${id || 0})">
                        <div id="err_correo" class="form-error"></div></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Telefono</label>
                        <input type="text" class="form-input" id="telefono" value="${App.escapeHtml(empresa.telefono || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Rubro <span class="req">*</span></label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="rubro_display" placeholder="Click en buscar" readonly value="${App.escapeHtml(empresa.rubro || '')}">
                            <input type="hidden" id="id_rubro" value="${empresa.id_rubro || ''}">
                            <button type="button" class="btn" onclick="App.showSelectorModal('Seleccionar Rubro', '/rubros', [{key:'nombre',label:'Rubro'}], (item) => { document.getElementById('id_rubro').value = item.id_rubro; document.getElementById('rubro_display').value = item.nombre; })">🔍</button>
                        </div></div>
                    <div class="form-group"><label class="form-label">Grupo Empresarial</label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="grupo_display" placeholder="Click en buscar" readonly value="${App.escapeHtml(empresa.grupo || '')}">
                            <input type="hidden" id="id_grupo" value="${empresa.id_grupo || ''}">
                            <button type="button" class="btn" onclick="App.showSelectorModal('Seleccionar Grupo', '/grupos', [{key:'nombre',label:'Grupo'},{key:'descripcion',label:'Desc'}], (item) => { document.getElementById('id_grupo').value = item.id_grupo; document.getElementById('grupo_display').value = item.nombre; })">🔍</button>
                        </div></div>
                </div>
                ${locationLocked
                ? `<section class="location-readonly">
                        <h3>Ubicacion de la sede principal</h3>
                        <p>${App.escapeHtml(empresa.direccion || '-')}</p>
                        <p class="text-muted">${App.escapeHtml(empresa.ubicacion_completa || '-')}</p>
                        <p class="text-sm text-muted">La direccion y la ubicacion se administran desde Sucursales.</p>
                    </section>`
                : `<h3 style="margin-top:20px;margin-bottom:10px;color:var(--c-primary)">Ubicacion Geografica</h3>
                       <div class="form-row">
                           <div class="form-group"><label class="form-label">Pais</label>
                               <select class="form-select" id="id_pais" onchange="App.views.empresas.loadDivisionesHijas(0, 'id_departamento')"><option value="">Cargando...</option></select></div>
                           <div class="form-group"><label class="form-label">Departamento</label>
                               <select class="form-select" id="id_departamento" onchange="App.views.empresas.loadDivisionesHijas(null, 'id_provincia', 'id_departamento')"><option value="">Seleccione pais primero</option></select></div>
                       </div>
                       <div class="form-row">
                           <div class="form-group"><label class="form-label">Provincia</label>
                               <select class="form-select" id="id_provincia" onchange="App.views.empresas.loadDivisionesHijas(null, 'id_distrito', 'id_provincia')"><option value="">Seleccione departamento</option></select></div>
                           <div class="form-group"><label class="form-label">Distrito <span class="req">*</span></label>
                               <select class="form-select" id="id_distrito" required><option value="">Seleccione provincia</option></select></div>
                       </div>
                       ${id ? `<input type="hidden" id="division_actual" value="${Number(empresa.id_division) || ''}">` : ''}
                       <div class="form-group"><label class="form-label">Direccion <span class="req">*</span></label>
                           <input type="text" class="form-input" id="direccion" required value="${App.escapeHtml(empresa.direccion || '')}"></div>
                       <div class="form-group"><label class="form-label">Referencia</label>
                           <input type="text" class="form-input" id="referencia" value="${App.escapeHtml(empresa.referencia || '')}"></div>
                       ${id ? '' : `<div class="form-group"><label><input type="checkbox" id="crear_sede_principal" checked> Registrar esta direccion como sede principal</label></div>`}`}
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveEmpresa">${id ? 'Guardar' : 'Crear'}</button>`);

        // Cargar paises
        try {
            const paisSelect = document.getElementById('id_pais');
            if (paisSelect) {
                const paises = await App.request('/geografia/paises');
                paisSelect.innerHTML = '<option value="">Seleccione...</option>' +
                    paises.map(p => `<option value="${p.id_pais}">${App.escapeHtml(p.nombre)}</option>`).join('');
            }
            if (!locationLocked && id && empresa.id_division && empresa.id_pais) {
                document.getElementById('id_pais').value = empresa.id_pais;

                // Cargar la ruta completa de la división para saber depto/prov/distrito
                // sin depender del esquema de tipos (que varía entre instalaciones).
                try {
                    const ruta = await App.request(`/geografia/ruta?id_division=${empresa.id_division}`);
                    // ruta = { pais, departamento, provincia, distrito, ... }
                    await this.loadDivisionesHijas(0, 'id_departamento');

                    if (ruta.departamento) {
                        document.getElementById('id_departamento').value = ruta.departamento.id_division;
                        await this.loadDivisionesHijas(null, 'id_provincia', 'id_departamento');
                    }
                    if (ruta.provincia) {
                        document.getElementById('id_provincia').value = ruta.provincia.id_division;
                        await this.loadDivisionesHijas(null, 'id_distrito', 'id_provincia');
                    }
                    if (ruta.distrito) {
                        document.getElementById('id_distrito').value = ruta.distrito.id_division;
                    }
                } catch (e) {
                    console.warn('No se pudo cargar la ruta de la división, fallback a método antiguo', e);
                    // Fallback: método antiguo (para esquema clásico tipo 1=pais, 2=depto, 3=prov)
                    const divisions = await App.request(`/geografia/divisiones?id_pais=${empresa.id_pais}`);
                    const selectedDivision = divisions.find(d => Number(d.id_division) === Number(empresa.id_division));
                    if (selectedDivision) {
                        const divisionType = Number(selectedDivision.id_tipo_division);
                        if (divisionType === 1) {
                            document.getElementById('id_departamento').value = selectedDivision.id_division;
                        } else if (divisionType === 2) {
                            document.getElementById('id_provincia').value = selectedDivision.id_division;
                        } else if (divisionType === 3) {
                            document.getElementById('id_distrito').value = selectedDivision.id_division;
                        }
                    }
                }
            }
        } catch (e) { App.toast('No se pudo cargar la ubicacion geografica', 'error'); }

        document.getElementById('btnSaveEmpresa').addEventListener('click', async () => {
            const data = {
                ruc: document.getElementById('ruc').value,
                razon_social: document.getElementById('razon_social').value,
                nombre_comercial: document.getElementById('nombre_comercial').value,
                correo: document.getElementById('correo').value,
                telefono: document.getElementById('telefono').value,
                id_rubro: parseInt(document.getElementById('id_rubro').value) || null,
                id_grupo: parseInt(document.getElementById('id_grupo').value) || null,
                id_division: locationLocked ? undefined : (
                    parseInt(document.getElementById('id_distrito')?.value) ||
                    parseInt(document.getElementById('id_provincia')?.value) ||
                    parseInt(document.getElementById('id_departamento')?.value) ||
                    parseInt(document.getElementById('division_actual')?.value) || null
                ),
                crear_sede_principal: document.getElementById('crear_sede_principal')?.checked ? 1 : 0,
                direccion: locationLocked ? undefined : document.getElementById('direccion')?.value,
                referencia: locationLocked ? undefined : document.getElementById('referencia')?.value,
            };
            if (!data.ruc || !data.razon_social || !data.correo || (!locationLocked && (!data.direccion || !data.id_division))) {
                App.toast('Complete los datos requeridos y seleccione la ubicacion', 'warning'); return;
            }
            // Validar duplicados SOLO si el campo cambió respecto al original.
            // FIX robusto: ahora usa this._originalValues (guardado en showFormModal)
            // para comparar. Antes se comparaba contra `empresa.ruc` que podía estar
            // desactualizado si el modal fue reabierto. Ahora la fuente única de
            // verdad es this._originalValues, establecida al abrir el modal.
            const originalRuc    = String(this._originalValues?.ruc    || '').trim();
            const originalCorreo = String(this._originalValues?.correo || '').trim().toLowerCase();
            const currentRuc    = String(data.ruc    || '').trim();
            const currentCorreo = String(data.correo || '').trim().toLowerCase();

            if (currentRuc !== originalRuc) {
                const existeRuc = await App.validateDuplicate('empresa', 'ruc', data.ruc, id);
                if (existeRuc) { App.toast('El RUC ya existe en otra empresa', 'error'); return; }
            }
            if (currentCorreo !== originalCorreo) {
                const existeCorreo = await App.validateDuplicate('empresa', 'correo', data.correo, id);
                if (existeCorreo) { App.toast('El correo ya existe en otra empresa', 'error'); return; }
            }

            try {
                if (id) {
                    await App.request(`/empresas/${id}`, { method: 'PUT', body: data });
                    App.toast('Empresa actualizada', 'success');
                } else {
                    await App.request('/empresas', { method: 'POST', body: data });
                    App.toast(data.crear_sede_principal ? 'Empresa creada con sede principal' : 'Empresa creada');
                }
                App.closeModal();
                // FIX: si estábamos viendo el detalle de una empresa, re-renderizar
                // el detalle en vez de la lista. Así no hay que presionar F5.
                const viewContainer = document.getElementById('appView') || container;
                const isDetailView = window.location.hash.includes('/empresas/' + id);
                if (id && isDetailView) {
                    this.renderDetail(viewContainer, id);
                } else {
                    this.load();
                }
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    // Cargar divisiones por nivel usando endpoint /divisiones/nivel?nivel=X
    // nivel 1 = departamentos del pais seleccionado
    // nivel 2 = provincias del departamento seleccionado
    // nivel 3 = distritos de la provincia seleccionada
    async loadDivisionesHijas(parentId, targetSelectId, sourceSelectId = null) {
        const targetSel = document.getElementById(targetSelectId);
        if (!targetSel) return;

        // Mapa de selects a nivel
        const niveles = {
            'id_departamento': 1,
            'id_provincia': 2,
            'id_distrito': 3,
        };

        // Limpiar hijos en cascada
        this.clearChildSelects(targetSelectId);

        // Determinar el filtro padre
        let idPais = null, idPadre = null;
        if (targetSelectId === 'id_departamento') {
            idPais = document.getElementById('id_pais')?.value;
            if (!idPais) {
                targetSel.innerHTML = '<option value="">Seleccione pais primero</option>';
                return;
            }
        } else {
            // Para provincia/distrito, usar el select padre
            const sourceSel = document.getElementById(sourceSelectId);
            if (!sourceSel || !sourceSel.value) {
                targetSel.innerHTML = '<option value="">Seleccione el nivel anterior</option>';
                return;
            }
            idPadre = parseInt(sourceSel.value);
        }

        targetSel.innerHTML = '<option value="">Cargando...</option>';
        try {
            let url = '/geografia/divisiones/hijas?id_padre=' + idPadre;
            if (idPais) {
                // Para departamentos, usar /divisiones?id_pais=X y filtrar por tipo=2
                url = '/geografia/divisiones?id_pais=' + idPais;
            }
            const all = await App.request(url);
            // Filtrar por tipo de division segun el target
            const nivel = niveles[targetSelectId];
            let rows = all;
            if (targetSelectId === 'id_departamento') {
                rows = all.filter(d => Number(d.id_tipo_division) === nivel);
            } else if (targetSelectId === 'id_provincia') {
                rows = all.filter(d => Number(d.id_tipo_division) === nivel && Number(d.id_division_padre) === idPadre);
            } else if (targetSelectId === 'id_distrito') {
                rows = all.filter(d => Number(d.id_tipo_division) === nivel && Number(d.id_division_padre) === idPadre);
            }
            targetSel.innerHTML = '<option value="">Seleccione...</option>' +
                rows.map(d => `<option value="${d.id_division}">${App.escapeHtml(d.nombre)}</option>`).join('');
        } catch (e) {
            console.error('loadDivisionesHijas error', e);
            targetSel.innerHTML = '<option value="">Error al cargar</option>';
        }
    },

    clearChildSelects(currentId) {
        const order = ['id_pais', 'id_departamento', 'id_provincia', 'id_distrito'];
        const idx = order.indexOf(currentId);
        for (let i = idx + 1; i < order.length; i++) {
            const sel = document.getElementById(order[i]);
            if (sel) sel.innerHTML = '<option value="">Seleccione nivel anterior</option>';
        }
    },

    async validateField(field, id) {
        const value = String(document.getElementById(field)?.value || '').trim();
        if (!value) return;
        const errEl = document.getElementById('err_' + field);
        if (!errEl) return;

        /* FIX: Si el valor no cambió respecto al original que tenía la empresa
         * al abrir el modal, NO se consulta al backend. El usuario está editando
         * OTROS campos, no este. Mostrar "Sin cambios" evita el falso positivo
         * "El RUC ya existe" que aparecía automáticamente al hacer blur en un
         * campo que no se modificó. */
        const originalVal = String(this._originalValues?.[field] || '').trim();
        const currentValue = (field === 'correo') ? value.toLowerCase() : value;
        if (originalVal && currentValue === originalVal) {
            errEl.textContent = '✓ Sin cambios';
            errEl.style.color = 'var(--c-success)';
            return;
        }

        const existe = await App.validateDuplicate('empresa', field, value, id || null);
        if (existe) {
            errEl.textContent = '⚠ Ya existe otra empresa con este ' + field;
            errEl.style.color = 'var(--c-danger)';
        } else {
            errEl.textContent = '✓ Disponible';
            errEl.style.color = 'var(--c-success)';
        }
    },

    async renderDetail(container, id) {
        container.innerHTML = '<div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando empresa...</p></div>';
        try {
            const e = await App.request(`/empresas/${id}`);

            // Cargar contactos y sucursales asociados en paralelo
            const [contactosResult, sucursalesResult] = await Promise.allSettled([
                App.request(`/contactos?id_empresa=${id}&filtro_estado=${this.relatedStatus.contactos}`),
                App.request(`/sucursales?id_empresa=${id}&filtro_estado=${this.relatedStatus.sucursales}`),
            ]);
            const contactos = contactosResult.status === 'fulfilled' && Array.isArray(contactosResult.value)
                ? contactosResult.value : [];
            const sucursales = sucursalesResult.status === 'fulfilled' && Array.isArray(sucursalesResult.value)
                ? sucursalesResult.value : [];

            container.innerHTML = `
                <div class="card-header mb-4">
                    <h1 style="color:var(--c-primary)">${App.escapeHtml(e.razon_social)}</h1>
                    <div class="card-actions">
                        <button class="btn" onclick="App.navigate('/empresas')"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver</button>
                        <button class="btn" id="btnPrintEmpresa" type="button"><i class="bi bi-printer" aria-hidden="true"></i> Imprimir ficha</button>
                        <button class="btn" id="btnExportEmpresa" type="button"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Excel CSV</button>
                        ${Number(e.activo) === 1
                    ? `<button class="btn btn-primary" onclick="App.views.empresas.showFormModal(${id})"><i class="bi bi-pencil-square" aria-hidden="true"></i> Editar</button>
                               <button class="btn btn-danger" onclick="App.views.empresas.delete(${id}, '${App.escapeHtml(e.razon_social)}')"><i class="bi bi-archive" aria-hidden="true"></i> Dar de baja</button>`
                    : `<button class="btn btn-primary" onclick="App.views.empresas.reactivate(${id})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reactivar</button>
                               <button class="btn btn-danger" onclick="App.views.empresas.deletePermanent(${id}, '${App.escapeHtml(e.razon_social)}')"><i class="bi bi-trash3" aria-hidden="true"></i> Eliminar definitivamente</button>`}
                    </div>
                </div>
                <div class="card-grid card-grid--2">
                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-building" aria-hidden="true"></i> Información General</div></div>
                        <div class="kv-list">
                            <div class="kv"><span class="kv__k">RUC</span><span class="kv__v">${App.escapeHtml(e.ruc)}</span></div>
                            <div class="kv"><span class="kv__k">Nombre Comercial</span><span class="kv__v">${App.escapeHtml(e.nombre_comercial || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Correo</span><span class="kv__v">${App.escapeHtml(e.correo)}</span></div>
                            <div class="kv"><span class="kv__k">Teléfono</span><span class="kv__v">${App.escapeHtml(e.telefono || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Rubro</span><span class="kv__v">${App.escapeHtml(e.rubro || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Grupo</span><span class="kv__v">${App.escapeHtml(e.grupo || '-')}</span></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-geo-alt" aria-hidden="true"></i> Ubicación</div></div>
                        <div class="kv-list">
                            <div class="kv"><span class="kv__k">Dirección</span><span class="kv__v"><span class="address-display" title="${App.escapeHtml(e.direccion || '')}">${App.escapeHtml(e.direccion || '-')}</span></span></div>
                            <div class="kv"><span class="kv__k">Referencia</span><span class="kv__v">${App.escapeHtml(e.referencia || '-')}</span></div>
                            <div class="kv"><span class="kv__k">Ubicación Completa</span><span class="kv__v">${App.escapeHtml(e.ubicacion_completa || '-')}</span></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title" data-related-count="sucursales"><i class="bi bi-shop" aria-hidden="true"></i> Sucursales (${sucursales.length})</div>
                        ${this.relatedStatusButtons('sucursales')}
                        ${Number(e.activo) === 1 ? `<button class="btn btn-sm btn-primary" id="btnAddSucursalEmpresa" type="button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar Sucursal</button>` : ''}
                    </div>
                    ${sucursalesResult.status === 'rejected' ? App.emptyState('⚠️', 'Sedes no disponibles', sucursalesResult.reason.message) : ''}
                    <div id="company-sucursales-table">${this.relatedTable('sucursales', sucursales)}</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title" data-related-count="contactos"><i class="bi bi-people" aria-hidden="true"></i> Contactos (${contactos.length})</div>
                        ${this.relatedStatusButtons('contactos')}
                        ${Number(e.activo) === 1 ? `<button class="btn btn-sm btn-primary" id="btnAddContactoEmpresa" type="button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar Contacto</button>` : ''}
                    </div>
                    ${contactosResult.status === 'rejected' ? App.emptyState('⚠️', 'Contactos no disponibles', contactosResult.reason.message) : ''}
                    <div id="company-contactos-table">${this.relatedTable('contactos', contactos)}</div>
                </div>

                ${App.renderFooter({ moduleName: `Empresa: ${e.razon_social} · Directorios` })}
            `;
            document.getElementById('btnPrintEmpresa').addEventListener('click', () => window.print());
            document.getElementById('btnExportEmpresa').addEventListener('click', () => this.exportDetailToCsv(e, contactos, sucursales));
            document.getElementById('btnAddSucursalEmpresa')?.addEventListener('click', () =>
                App.views.sucursales.showFormModalForEmpresa(id, e.razon_social));
            document.getElementById('btnAddContactoEmpresa')?.addEventListener('click', () =>
                App.views.contactos.showFormModalForEmpresa(id, e.razon_social));
            container.querySelectorAll('[data-related-state]').forEach(button => {
                button.addEventListener('click', () => this.setRelatedStatus(
                    button.dataset.relatedType,
                    button.dataset.relatedState,
                    id
                ));
            });
        } catch (err) { container.innerHTML = App.emptyState('⚠️', 'Error', err.message); }
    },

    relatedStatusButtons(type) {
        const active = this.relatedStatus[type];
        return `<div class="segmented-control" role="group" aria-label="Estado ${type}">
            ${['activas', 'bajas', 'todas'].map(state => `<button type="button" class="segmented-control__item" data-related-type="${type}" data-related-state="${state}" aria-pressed="${active === state}">${state[0].toUpperCase() + state.slice(1)}</button>`).join('')}
        </div>`;
    },

    setRelatedStatus(type, state, id) {
        if (!['sucursales', 'contactos'].includes(type) || !['activas', 'bajas', 'todas'].includes(state)) return;
        this.relatedStatus[type] = state;
        document.querySelectorAll(`[data-related-type="${type}"]`).forEach(button =>
            button.setAttribute('aria-pressed', String(button.dataset.relatedState === state)));
        this.loadRelatedSection(type, id);
    },

    relatedTable(type, rows) {
        const columns = type === 'sucursales'
            ? [
                { key: 'nombre', label: 'Sede' },
                { key: 'pais', label: 'Pais', render: r => App.escapeHtml(r.pais || '-') },
                { key: 'departamento', label: 'Departamento', render: r => App.escapeHtml(r.departamento || '-') },
                { key: 'provincia', label: 'Provincia', render: r => App.escapeHtml(r.provincia || '-') },
                { key: 'distrito', label: 'Distrito', render: r => App.escapeHtml(r.distrito || '-') },
                { key: 'telefono', label: 'Telefono', render: r => App.escapeHtml(r.telefono || '-') },
                { key: 'direccion', label: 'Direccion', render: r => `<span class="address-cell" title="${App.escapeHtml(r.direccion || '')}">${App.escapeHtml(r.direccion || '-')}</span>` },
                { key: 'es_principal', label: 'Principal', render: r => Number(r.es_principal) ? App.badge('Si', 'success') : '-' },
                {
                    key: 'acciones', label: 'Acciones', render: r => Number(r.activo)
                        ? `<button class="btn btn-sm btn-primary" title="Editar sede" aria-label="Editar sede" onclick="App.views.sucursales.showFormModal(${r.id_sucursal})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button> <button class="btn btn-sm btn-danger" title="Dar de baja" aria-label="Dar de baja" onclick="App.views.sucursales.delete(${r.id_sucursal}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-person-dash" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-success" title="Reactivar sede" aria-label="Reactivar sede" onclick="App.views.sucursales.reactivate(${r.id_sucursal})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button> <button class="btn btn-sm btn-danger" title="Eliminar sede definitivamente" aria-label="Eliminar sede definitivamente" onclick="App.views.sucursales.deletePermanent(${r.id_sucursal}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>`
                },
            ]
            : [
                { key: 'nombres', label: 'Nombre', render: r => App.escapeHtml(`${r.nombres || ''} ${r.apellido_paterno || ''}`.trim()) },
                { key: 'cargo', label: 'Cargo', render: r => App.escapeHtml(r.cargo || '-') },
                { key: 'correo_empresa', label: 'Correo', render: r => App.escapeHtml(r.correo_empresa || '-') },
                { key: 'telefono_empresa', label: 'Telefono', render: r => App.escapeHtml(r.telefono_empresa || '-') },
                { key: 'es_principal', label: 'Principal', render: r => Number(r.es_principal) ? App.badge('Si', 'success') : '-' },
                {
                    key: 'acciones', label: 'Acciones', render: r => Number(r.activo)
                        ? `<button class="btn btn-sm btn-primary" title="Editar contacto" aria-label="Editar contacto" onclick="App.views.contactos.showFormModal(${r.id_empresa_contacto})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button> <button class="btn btn-sm btn-danger" title="Dar de baja" aria-label="Dar de baja" onclick="App.views.contactos.delete(${r.id_empresa_contacto}, '${App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || ''))}')"><i class="bi bi-person-dash" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-success" title="Reactivar contacto" aria-label="Reactivar contacto" onclick="App.views.contactos.reactivate(${r.id_empresa_contacto})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button> <button class="btn btn-sm btn-danger" title="Eliminar contacto definitivamente" aria-label="Eliminar contacto definitivamente" onclick="App.views.contactos.deletePermanent(${r.id_empresa_contacto}, '${App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || ''))}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>`
                },
            ];
        return App.table({ columns, rows, empty: type === 'sucursales' ? 'Sin sedes asociadas' : 'Sin contactos asociados' });
    },

    async loadRelatedSection(type, id) {
        const target = document.getElementById(`company-${type}-table`);
        if (!target) return;
        target.innerHTML = '<div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div>';
        try {
            const rows = await App.request(`/${type}?id_empresa=${encodeURIComponent(id)}&filtro_estado=${this.relatedStatus[type]}`);
            const data = Array.isArray(rows) ? rows : [];
            const count = document.querySelector(`[data-related-count="${type}"]`);
            if (count) count.textContent = `${type === 'sucursales' ? 'Sedes' : 'Contactos'} (${data.length})`;
            target.innerHTML = this.relatedTable(type, data);
        } catch (error) {
            target.innerHTML = App.emptyState('⚠️', 'Error al cargar', error.message);
        }
    },

    exportDetailToCsv(empresa, contactos, sucursales) {
        const rows = [
            ['FICHA DE EMPRESA'],
            ['Razon social', empresa.razon_social],
            ['RUC', empresa.ruc],
            ['Nombre comercial', empresa.nombre_comercial],
            ['Correo', empresa.correo],
            ['Telefono', empresa.telefono],
            ['Rubro', empresa.rubro],
            ['Grupo', empresa.grupo],
            ['Direccion', empresa.direccion],
            ['Referencia', empresa.referencia],
            ['Ubicacion', empresa.ubicacion_completa],
            [],
            ['SEDES'],
            ['Nombre', 'Telefono', 'Direccion', 'Principal'],
            ...sucursales.map(s => [s.nombre, s.telefono, s.direccion, Number(s.es_principal) ? 'Si' : 'No']),
            [],
            ['CONTACTOS'],
            ['Nombre', 'Cargo', 'Correo', 'Telefono', 'Sede'],
            ...contactos.map(c => [
                `${c.nombres || ''} ${c.apellido_paterno || ''}`.trim(),
                c.cargo, c.correo_empresa, c.telefono_empresa, c.sucursal,
            ]),
        ];
        const csv = '\uFEFF' + rows.map(row => row.map(value =>
            `"${String(value ?? '').replace(/"/g, '""')}"`
        ).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `empresa_${String(empresa.ruc || empresa.id_empresa).replace(/[^a-zA-Z0-9_-]/g, '_')}.csv`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    },

    async delete(id, nombre) {
        App.confirmDelete(nombre, async () => {
            try {
                await App.request(`/empresas/${id}`, { method: 'DELETE' });
                App.toast('Empresa dada de baja', 'success');
                if (App.state.currentRoute?.params?.length) App.navigate('/empresas');
                else this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async reactivate(id) {
        try {
            await App.request(`/empresas/${id}/reactivar`, { method: 'PATCH' });
            App.toast('Empresa reactivada', 'success');
            if (App.state.currentRoute?.params?.length) App.navigate('/empresas');
            else this.load();
        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
    },
    deletePermanent(id, nombre) {
        App.confirmDelete(nombre + ' de forma definitiva', async () => {
            try {
                await App.request(`/empresas/${id}?permanente=1`, { method: 'DELETE' });
                App.toast('Empresa eliminada definitivamente', 'success');
                App.navigate('/empresas');
            } catch (e) { App.toast('No se pudo eliminar: ' + e.message, 'error'); }
        });
    },
};

/* ============================================================================
 *  VISTA: SUCURSALES
 * ========================================================================== */
App.views.sucursales = {
    currentFilters: { query: '' },
    statusFilter: 'activas',
    currentPage: 1,
    perPage: 50,
    allRows: [],
    searchTimer: null,
    async render(container) {
        this.currentPage = 1;
        this.currentFilters = { query: '' };
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Sucursales</h1>
                <div class="card-actions">
                    <div class="segmented-control" role="group" aria-label="Estado de sedes">
                        ${['activas', 'bajas', 'todas'].map(state => `<button type="button" class="segmented-control__item" data-sucursal-state="${state}" aria-pressed="${this.statusFilter === state}">${state[0].toUpperCase() + state.slice(1)}</button>`).join('')}
                    </div>
                    <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva Sucursal</button>
                </div>
            </div>
            <div class="card">
                <div class="empresas-filtros">
                    <div class="empresas-filtros__busqueda">
                        <i class="bi bi-search empresas-filtros__icon" aria-hidden="true"></i>
                        <input type="search" class="form-input empresas-filtros__input"
                               id="inputBusquedaSucursal"
                               placeholder="Buscar por nombre de sede, empresa, dirección..."
                               autocomplete="off"
                               aria-label="Buscar sucursales">
                        <button type="button" class="btn empresas-filtros__limpiar" id="btnLimpiarBusquedaSucursal" hidden title="Limpiar búsqueda">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="empresas-filtros__resultados">
                        <span id="sucursalesCount" class="text-muted text-sm">Cargando...</span>
                    </div>
                </div>
                <div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div>
            </div>`;
        container.querySelectorAll('[data-sucursal-state]').forEach(button => {
            button.addEventListener('click', () => {
                this.statusFilter = button.dataset.sucursalState;
                this.currentPage = 1;
                container.querySelectorAll('[data-sucursal-state]').forEach(item =>
                    item.setAttribute('aria-pressed', String(item === button)));
                this.load();
            });
        });
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        // Búsqueda automática con debounce
        const inputBusqueda = document.getElementById('inputBusquedaSucursal');
        inputBusqueda.addEventListener('input', (event) => {
            const valor = event.target.value.trim();
            document.getElementById('btnLimpiarBusquedaSucursal').hidden = valor === '';
            this.currentFilters.query = valor;
            this.currentPage = 1;
            if (this.searchTimer) clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.load(), 300);
        });
        inputBusqueda.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.load();
            }
        });
        document.getElementById('btnLimpiarBusquedaSucursal').addEventListener('click', () => {
            inputBusqueda.value = '';
            this.currentFilters.query = '';
            document.getElementById('btnLimpiarBusquedaSucursal').hidden = true;
            this.currentPage = 1;
            this.load();
            inputBusqueda.focus();
        });
        await this.load();
    },

    async load() {
        const params = new URLSearchParams();
        params.set('filtro_estado', this.statusFilter);
        if (this.currentFilters.query) params.set('query', this.currentFilters.query);
        const countEl = document.getElementById('sucursalesCount');
        if (countEl) countEl.textContent = 'Buscando...';
        try {
            const rows = await App.request('/sucursales?' + params.toString());
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('📍', 'Error', e.message);
            if (countEl) countEl.textContent = 'Error al cargar';
        }
    },

    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const countEl = document.getElementById('sucursalesCount');
        if (countEl) {
            const total = pageData.total;
            const filtro = this.currentFilters.query ? ' · texto: "' + this.currentFilters.query + '"' : '';
            countEl.textContent = total + (total === 1 ? ' sucursal' : ' sucursales') + filtro;
        }
        const columns = [
            { key: 'nombre', label: 'Sucursal' },
            { key: 'empresa', label: 'Empresa', render: r => App.escapeHtml(r.empresa || '-') },
            { key: 'pais', label: 'País', render: r => App.escapeHtml(r.pais || '-') },
            { key: 'departamento', label: 'Departamento', render: r => App.escapeHtml(r.departamento || '-') },
            { key: 'provincia', label: 'Provincia', render: r => App.escapeHtml(r.provincia || '-') },
            { key: 'distrito', label: 'Distrito', render: r => App.escapeHtml(r.distrito || '-') },
            { key: 'telefono', label: 'Teléfono', render: r => App.escapeHtml(r.telefono || '-') },
            { key: 'direccion', label: 'Dirección', render: r => `<span class="address-cell" title="${App.escapeHtml(r.direccion || '')}">${App.escapeHtml(r.direccion || '-')}</span>` },
            { key: 'es_principal', label: 'Principal', render: r => r.es_principal ? App.badge('Sí', 'success') : '-' },
            { key: 'estado', label: 'Estado', render: r => Number(r.activo) ? App.badge('Activa', 'success') : App.badge('De baja', 'muted') },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    ${Number(r.activo)
                        ? `<button class="btn btn-sm btn-primary" title="Editar sede" aria-label="Editar sede" onclick="App.views.sucursales.showFormModal(${r.id_sucursal})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                                <button class="btn btn-sm btn-danger" title="Dar de baja" aria-label="Dar de baja" onclick="App.views.sucursales.delete(${r.id_sucursal}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-archive" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-success" title="Reactivar sede" aria-label="Reactivar sede" onclick="App.views.sucursales.reactivate(${r.id_sucursal})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                     <button class="btn btn-sm btn-danger" title="Eliminar sede definitivamente" aria-label="Eliminar sede definitivamente" onclick="App.views.sucursales.deletePermanent(${r.id_sucursal}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>`}
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'No hay sucursales que coincidan con la búsqueda' });
        const paginationHtml = App.renderPagination({
            page: pageData.page,
            totalPages: pageData.totalPages,
            total: pageData.total,
            perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Sucursales · Directorios' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    async showFormModal(id, presetEmpresaId = null, presetEmpresaName = '') {
        let suc = {};
        if (id) {
            try { suc = await App.request(`/sucursales/${id}`); } catch (e) { App.toast('Error al cargar sucursal', 'error'); return; }
        }

        App.showModal(id ? 'Editar Sucursal' : 'Nueva Sucursal',
            `<form id="formSuc">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Empresa <span class="req">*</span></label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="empresa_display" placeholder="Click en buscar" readonly value="${App.escapeHtml(suc.empresa || presetEmpresaName)}">
                            <input type="hidden" id="id_empresa" value="${suc.id_empresa || presetEmpresaId || ''}">
                            <button type="button" class="btn" onclick="App.showSelectorModal('Seleccionar Empresa', '/empresas', [{key:'ruc',label:'RUC'},{key:'razon_social',label:'Razon Social'}], (item) => { document.getElementById('id_empresa').value = item.id_empresa; document.getElementById('empresa_display').value = item.razon_social; })">🔍</button>
                        </div></div>
                    <div class="form-group"><label class="form-label">Nombre <span class="req">*</span></label>
                        <input type="text" class="form-input" id="nombre" required value="${App.escapeHtml(suc.nombre || '')}" onblur="App.views.sucursales.validateNombre()">
                        <div id="err_nombre" class="form-error"></div></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Telefono</label>
                        <input type="text" class="form-input" id="telefono" value="${App.escapeHtml(suc.telefono || '')}"></div>
                    <div class="form-group"><label><input type="checkbox" id="es_principal" ${suc.es_principal ? 'checked' : ''}> Sucursal principal</label></div>
                </div>
                <h3 style="margin-top:20px;margin-bottom:10px;color:var(--c-primary)">Ubicacion Geografica</h3>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Pais</label>
                        <select class="form-select" id="id_pais" onchange="App.views.sucursales.loadDivisionesHijas(0, 'id_departamento')">
                            <option value="">Cargando...</option>
                        </select></div>
                    <div class="form-group"><label class="form-label">Departamento</label>
                        <select class="form-select" id="id_departamento" onchange="App.views.sucursales.loadDivisionesHijas(null, 'id_provincia', 'id_departamento')">
                            <option value="">Seleccione pais</option>
                        </select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Provincia</label>
                        <select class="form-select" id="id_provincia" onchange="App.views.sucursales.loadDivisionesHijas(null, 'id_distrito', 'id_provincia')">
                            <option value="">Seleccione departamento</option>
                        </select></div>
                    <div class="form-group"><label class="form-label">Distrito <span class="req">*</span></label>
                        <select class="form-select" id="id_distrito" required>
                            <option value="">Seleccione provincia</option>
                        </select></div>
                </div>
                ${id ? `<input type="hidden" id="division_actual" value="${Number(suc.id_division) || ''}">` : ''}
                <div class="form-group"><label class="form-label">Direccion <span class="req">*</span></label>
                    <input type="text" class="form-input" id="direccion" required value="${App.escapeHtml(suc.direccion || '')}"></div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveSuc">${id ? 'Guardar' : 'Crear'}</button>`);

        // Cargar paises
        try {
            const paises = await App.request('/geografia/paises');
            document.getElementById('id_pais').innerHTML = '<option value="">Seleccione...</option>' +
                paises.map(p => `<option value="${p.id_pais}">${App.escapeHtml(p.nombre)}</option>`).join('');
            if (id && suc.id_division && suc.id_pais) {
                document.getElementById('id_pais').value = suc.id_pais;
                const divisions = await App.request(`/geografia/divisiones?id_pais=${encodeURIComponent(suc.id_pais)}`);
                const selectedDivision = divisions.find(d => Number(d.id_division) === Number(suc.id_division));
                if (selectedDivision) {
                    const divisionType = Number(selectedDivision.id_tipo_division);
                    const province = divisionType === 3
                        ? divisions.find(d => Number(d.id_division) === Number(selectedDivision.id_division_padre))
                        : divisionType === 2 ? selectedDivision : null;
                    const departmentId = divisionType === 1
                        ? selectedDivision.id_division
                        : province?.id_division_padre;
                    await this.loadDivisionesHijas(0, 'id_departamento');
                    if (departmentId) {
                        document.getElementById('id_departamento').value = departmentId;
                        await this.loadDivisionesHijas(null, 'id_provincia', 'id_departamento');
                    }
                    if (province) {
                        document.getElementById('id_provincia').value = province.id_division;
                        await this.loadDivisionesHijas(null, 'id_distrito', 'id_provincia');
                    }
                    if (divisionType === 3) document.getElementById('id_distrito').value = selectedDivision.id_division;
                    else if (divisionType === 2) document.getElementById('id_provincia').value = selectedDivision.id_division;
                    else if (divisionType === 1) document.getElementById('id_departamento').value = selectedDivision.id_division;
                }
            }
        } catch (e) { }

        document.getElementById('btnSaveSuc').addEventListener('click', async () => {
            const idEmpresa = parseInt(document.getElementById('id_empresa').value);
            const data = {
                id_empresa: idEmpresa,
                nombre: document.getElementById('nombre').value,
                telefono: document.getElementById('telefono').value,
                es_principal: document.getElementById('es_principal').checked ? 1 : 0,
                direccion: document.getElementById('direccion').value,
                referencia: '',
                id_division: parseInt(document.getElementById('id_distrito').value) ||
                    parseInt(document.getElementById('id_provincia').value) ||
                    parseInt(document.getElementById('id_departamento').value) ||
                    parseInt(document.getElementById('division_actual')?.value) || 1,
            };
            if (!data.id_empresa || !data.nombre || !data.direccion) {
                App.toast('Complete los campos requeridos', 'warning'); return;
            }
            // Validar duplicados
            const existe = await App.validateDuplicate('sucursal', 'nombre', data.nombre, id, { id_empresa: data.id_empresa });
            if (existe) { App.toast('Ya existe una sucursal con ese nombre en esta empresa', 'error'); return; }

            try {
                if (id) {
                    await App.request(`/sucursales/${id}`, { method: 'PUT', body: data });
                    App.toast('Sucursal actualizada', 'success');
                } else {
                    await App.request('/sucursales', { method: 'POST', body: data });
                    App.toast('Sucursal creada', 'success');
                }
                App.closeModal();
                const route = App.state.currentRoute;
                if (route?.viewName === 'empresas' && route.params[0]) {
                    App.views.empresas.renderDetail(document.getElementById('appView'), route.params[0]);
                } else {
                    this.load();
                }
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    // Si viene desde vista de empresa, pre-setear id_empresa
    showFormModalForEmpresa(empresaId, empresaNombre = '') {
        this.showFormModal(null, empresaId, empresaNombre);
    },

    async loadDivisionesHijas(parentId, targetSelectId, sourceSelectId = null) {
        return App.views.empresas.loadDivisionesHijas.call(this, parentId, targetSelectId, sourceSelectId);
    },
    clearChildSelects(currentId) { App.views.empresas.clearChildSelects(currentId); },

    async validateNombre() {
        const value = document.getElementById('nombre').value.trim();
        if (!value) return;
        const idEmpresa = document.getElementById('id_empresa').value;
        if (!idEmpresa) { App.toast('Seleccione empresa primero', 'warning'); return; }
        const existe = await App.validateDuplicate('sucursal', 'nombre', value, null, { id_empresa: idEmpresa });
        const errEl = document.getElementById('err_nombre');
        if (existe) {
            errEl.textContent = '⚠ Ya existe sucursal con ese nombre en esta empresa';
            errEl.style.color = 'var(--c-danger)';
        } else {
            errEl.textContent = '✓ Disponible';
            errEl.style.color = 'var(--c-success)';
        }
    },

    async delete(id, nombre) {
        App.confirmDelete(nombre, async () => {
            try {
                await App.request(`/sucursales/${id}`, { method: 'DELETE' });
                App.toast('Sede dada de baja', 'success');
                this.refreshCurrentView();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async reactivate(id) {
        try {
            await App.request(`/sucursales/${id}/reactivar`, { method: 'PATCH' });
            App.toast('Sede reactivada', 'success');
            this.refreshCurrentView();
        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
    },
    deletePermanent(id, nombre) {
        App.confirmDelete('sede ' + nombre + ' de forma definitiva', async () => {
            try {
                await App.request(`/sucursales/${id}?permanente=1`, { method: 'DELETE' });
                App.toast('Sede eliminada definitivamente', 'success');
                this.refreshCurrentView();
            } catch (e) { App.toast('No se pudo eliminar: ' + e.message, 'error'); }
        });
    },
    refreshCurrentView() {
        const route = App.state.currentRoute;
        if (route?.viewName === 'empresas' && route.params[0]) {
            App.views.empresas.renderDetail(document.getElementById('appView'), route.params[0]);
        } else {
            this.render(document.getElementById('appView'));
        }
    },
};

/* ============================================================================
 *  VISTA: CONTACTOS
 * ========================================================================== */
App.views.contactos = {
    currentFilters: { query: '' },
    statusFilter: 'activas',
    currentPage: 1,
    perPage: 100,
    allRows: [],
    searchTimer: null,
    async render(container) {
        this.currentPage = 1;
        this.currentFilters = { query: '' };
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Contactos (clientes)</h1>
                <div class="card-actions">
                    <div class="segmented-control" role="group" aria-label="Estado de contactos">
                        ${['activas', 'bajas', 'todas'].map(state => `<button type="button" class="segmented-control__item" data-contacto-state="${state}" aria-pressed="${this.statusFilter === state}">${state[0].toUpperCase() + state.slice(1)}</button>`).join('')}
                    </div>
                    <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Contacto</button>
                </div>
            </div>
            <div class="card" style="margin-bottom:16px;background:var(--c-card);padding:12px">
                <p class="text-sm text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i>
                Los <strong>contactos de empresas</strong> son clientes externos. <strong>NO acceden al sistema</strong>
                y no se les crea usuario. Solo los <em>trabajadores</em> tienen acceso al sistema.</p>
            </div>
            <div class="card">
                <div class="empresas-filtros">
                    <div class="empresas-filtros__busqueda">
                        <i class="bi bi-search empresas-filtros__icon" aria-hidden="true"></i>
                        <input type="search" class="form-input empresas-filtros__input"
                               id="inputBusquedaContacto"
                               placeholder="Buscar por nombre, cargo, documento, empresa..."
                               autocomplete="off"
                               aria-label="Buscar contactos">
                        <button type="button" class="btn empresas-filtros__limpiar" id="btnLimpiarBusquedaContacto" hidden title="Limpiar búsqueda">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="empresas-filtros__resultados">
                        <span id="contactosCount" class="text-muted text-sm">Cargando...</span>
                    </div>
                </div>
                <div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div>
            </div>`;
        container.querySelectorAll('[data-contacto-state]').forEach(button => {
            button.addEventListener('click', () => {
                this.statusFilter = button.dataset.contactoState;
                this.currentPage = 1;
                container.querySelectorAll('[data-contacto-state]').forEach(item =>
                    item.setAttribute('aria-pressed', String(item === button)));
                this.load();
            });
        });
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        // Búsqueda automática con debounce
        const inputBusqueda = document.getElementById('inputBusquedaContacto');
        inputBusqueda.addEventListener('input', (event) => {
            const valor = event.target.value.trim();
            document.getElementById('btnLimpiarBusquedaContacto').hidden = valor === '';
            this.currentFilters.query = valor;
            this.currentPage = 1;
            if (this.searchTimer) clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.load(), 300);
        });
        inputBusqueda.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.searchTimer) clearTimeout(this.searchTimer);
                this.load();
            }
        });
        document.getElementById('btnLimpiarBusquedaContacto').addEventListener('click', () => {
            inputBusqueda.value = '';
            this.currentFilters.query = '';
            document.getElementById('btnLimpiarBusquedaContacto').hidden = true;
            this.currentPage = 1;
            this.load();
            inputBusqueda.focus();
        });
        await this.load();
    },

    async load() {
        const params = new URLSearchParams();
        params.set('filtro_estado', this.statusFilter);
        if (this.currentFilters.query) params.set('query', this.currentFilters.query);
        const countEl = document.getElementById('contactosCount');
        if (countEl) countEl.textContent = 'Buscando...';
        try {
            const rows = await App.request('/contactos?' + params.toString());
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('👥', 'Error', e.message);
            if (countEl) countEl.textContent = 'Error al cargar';
        }
    },

    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const countEl = document.getElementById('contactosCount');
        if (countEl) {
            const total = pageData.total;
            const filtro = this.currentFilters.query ? ' · texto: "' + this.currentFilters.query + '"' : '';
            countEl.textContent = total + (total === 1 ? ' contacto' : ' contactos') + filtro;
        }
        const columns = [
            { key: 'empresa', label: 'Empresa', render: r => App.escapeHtml(r.empresa || '-') },
            { key: 'nombres', label: 'Nombre', render: r => App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || '')) },
            { key: 'cargo', label: 'Cargo', render: r => App.escapeHtml(r.cargo || '-') },
            { key: 'correo_empresa', label: 'Correo', render: r => App.escapeHtml(r.correo_empresa || '-') },
            { key: 'telefono_empresa', label: 'Teléfono', render: r => App.escapeHtml(r.telefono_empresa || '-') },
            { key: 'es_principal', label: 'Principal', render: r => r.es_principal ? App.badge('Sí', 'success') : '-' },
            { key: 'estado_registro', label: 'Estado', render: r => Number(r.activo) ? App.badge('Activo', 'success') : App.badge('De baja', 'muted') },
            {
                key: 'acciones', label: 'Acciones', render: r => Number(r.activo)
                    ? `<div class="btn-row"><button class="btn btn-sm btn-primary" title="Editar contacto" aria-label="Editar contacto" onclick="App.views.contactos.showFormModal(${r.id_empresa_contacto})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button> <button class="btn btn-sm btn-danger" title="Dar de baja" aria-label="Dar de baja" onclick="App.views.contactos.delete(${r.id_empresa_contacto}, '${App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || ''))}')"><i class="bi bi-archive" aria-hidden="true"></i></button></div>`
                    : `<div class="btn-row"><button class="btn btn-sm btn-success" title="Reactivar contacto" aria-label="Reactivar contacto" onclick="App.views.contactos.reactivate(${r.id_empresa_contacto})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                           <button class="btn btn-sm btn-danger" title="Eliminar contacto definitivamente" aria-label="Eliminar contacto definitivamente" onclick="App.views.contactos.deletePermanent(${r.id_empresa_contacto}, '${App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || ''))}')"><i class="bi bi-trash3" aria-hidden="true"></i></button></div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'No hay contactos que coincidan con la búsqueda' });
        const paginationHtml = App.renderPagination({
            page: pageData.page,
            totalPages: pageData.totalPages,
            total: pageData.total,
            perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Contactos · Directorios' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    async showFormModal(id, presetEmpresaId = null, presetEmpresaName = '') {
        let empresas = [];
        let contacto = {};
        try {
            empresas = await App.request('/empresas');
            if (id) contacto = await App.request(`/contactos/${id}`);
        } catch (e) { App.toast('Error al cargar catalogos', 'error'); return; }

        const companyName = presetEmpresaName || contacto.empresa ||
            (presetEmpresaId ? empresas.find(e => Number(e.id_empresa) === Number(presetEmpresaId))?.razon_social : '') || '';
        App.showModal(id ? 'Editar Contacto' : 'Nuevo Contacto',
            `<form id="formContacto">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Empresa <span class="req">*</span></label>
                        <div style="display:flex;gap:6px">
                            <input type="text" class="form-input" id="empresa_display" placeholder="Seleccione empresa" readonly value="${App.escapeHtml(companyName)}">
                            <input type="hidden" id="id_empresa" value="${contacto.id_empresa || presetEmpresaId || ''}">
                            ${id ? '' : `<button type="button" class="btn" onclick="App.showSelectorModal('Seleccionar Empresa', '/empresas', [{key:'ruc',label:'RUC'},{key:'razon_social',label:'Razon Social'}], (item) => { document.getElementById('id_empresa').value = item.id_empresa; document.getElementById('empresa_display').value = item.razon_social; })">🔍</button>`}
                        </div></div>
                    <div class="form-group"><label class="form-label">Tipo de documento</label>
                        <select class="form-select" id="tipo_documento">
                            <option value="" ${!contacto.tipo_documento ? 'selected' : ''}>Sin especificar</option>
                            <option value="DNI" ${contacto.tipo_documento === 'DNI' ? 'selected' : ''}>DNI</option>
                            <option value="RUC" ${contacto.tipo_documento === 'RUC' ? 'selected' : ''}>RUC</option>
                            <option value="CE" ${contacto.tipo_documento === 'CE' ? 'selected' : ''}>CE</option>
                        </select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Numero de documento</label><input type="text" class="form-input" id="numero_documento" value="${App.escapeHtml(contacto.numero_documento || '')}"></div>
                    <div class="form-group"><label class="form-label">Nombres <span class="req">*</span></label><input type="text" class="form-input" id="nombres" required value="${App.escapeHtml(contacto.nombres || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Apellido paterno <span class="req">*</span></label><input type="text" class="form-input" id="apellido_paterno" required value="${App.escapeHtml(contacto.apellido_paterno || '')}"></div>
                    <div class="form-group"><label class="form-label">Apellido materno</label><input type="text" class="form-input" id="apellido_materno" value="${App.escapeHtml(contacto.apellido_materno || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Correo personal</label><input type="email" class="form-input" id="correo_persona" value="${App.escapeHtml(contacto.correo_personal || '')}"></div>
                    <div class="form-group"><label class="form-label">Telefono</label><input type="text" class="form-input" id="telefono_empresa" value="${App.escapeHtml(contacto.telefono_empresa || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Cargo</label><input type="text" class="form-input" id="cargo" value="${App.escapeHtml(contacto.cargo || '')}"></div>
                    <div class="form-group"><label class="form-label">Correo de empresa</label><input type="email" class="form-input" id="correo_empresa" value="${App.escapeHtml(contacto.correo_empresa || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label><input type="checkbox" id="es_principal" ${contacto.es_principal ? 'checked' : ''}> Contacto principal</label></div>
                </div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveContacto">${id ? 'Guardar cambios' : 'Crear Contacto'}</button>`);

        document.getElementById('btnSaveContacto').addEventListener('click', async () => {
            const data = {
                id_empresa: parseInt(document.getElementById('id_empresa').value),
                tipo_documento: document.getElementById('tipo_documento').value || null,
                numero_documento: document.getElementById('numero_documento').value.trim() || null,
                nombres: document.getElementById('nombres').value.trim(),
                apellido_paterno: document.getElementById('apellido_paterno').value.trim(),
                apellido_materno: document.getElementById('apellido_materno').value.trim() || null,
                correo: document.getElementById('correo_persona').value.trim() || null,
                cargo: document.getElementById('cargo').value,
                correo_empresa: document.getElementById('correo_empresa').value,
                telefono_empresa: document.getElementById('telefono_empresa').value,
                es_principal: document.getElementById('es_principal').checked ? 1 : 0,
                estado: contacto.estado ?? 1,
            };
            if (!data.id_empresa || !data.nombres || !data.apellido_paterno) {
                App.toast('Complete empresa y datos obligatorios del contacto', 'warning'); return;
            }
            try {
                await App.request(id ? `/contactos/${id}` : '/contactos', { method: id ? 'PUT' : 'POST', body: data });
                App.toast(id ? 'Contacto actualizado' : 'Contacto creado', 'success');
                App.closeModal();
                const route = App.state.currentRoute;
                if (route?.viewName === 'empresas' && route.params[0]) {
                    App.views.empresas.renderDetail(document.getElementById('appView'), route.params[0]);
                } else {
                    this.render(document.getElementById('appView'));
                }
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    showFormModalForEmpresa(empresaId, empresaNombre = '') { this.showFormModal(null, empresaId, empresaNombre); },

    async delete(id, nombre) {
        App.confirmDelete(nombre, async () => {
            try {
                await App.request(`/contactos/${id}`, { method: 'DELETE' });
                App.toast('Contacto dado de baja', 'success');
                this.refreshCurrentView();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async reactivate(id) {
        try {
            await App.request(`/contactos/${id}/reactivar`, { method: 'PATCH' });
            App.toast('Contacto reactivado', 'success');
            this.refreshCurrentView();
        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
    },
    deletePermanent(id, nombre) {
        App.confirmDelete('contacto ' + nombre + ' de forma definitiva', async () => {
            try {
                await App.request(`/contactos/${id}?permanente=1`, { method: 'DELETE' });
                App.toast('Contacto eliminado definitivamente', 'success');
                this.refreshCurrentView();
            } catch (e) { App.toast('No se pudo eliminar: ' + e.message, 'error'); }
        });
    },
    refreshCurrentView() {
        const route = App.state.currentRoute;
        if (route?.viewName === 'empresas' && route.params[0]) {
            App.views.empresas.renderDetail(document.getElementById('appView'), route.params[0]);
        } else {
            this.render(document.getElementById('appView'));
        }
    },
};

/* ============================================================================
 *  VISTA: USUARIOS (Trabajadores - personal interno que registra tickets)
 * ========================================================================== */
App.views.usuarios = {
    currentFilters: {},
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Trabajadores</h1>
                <div class="card-actions">
                    <button class="btn" id="btnSearch"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                    <button class="btn btn-primary" id="btnNew"><i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo Trabajador</button>
                </div>
            </div>
            <div class="card" style="margin-bottom:16px;background:var(--c-card);padding:12px">
                <p class="text-sm text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i>
                <strong>Trabajadores = Usuarios del sistema.</strong> Son el personal interno que registra y atiende tickets.
                Cada trabajador tiene correo + contraseña + rol que le permite <strong>acceder al sistema</strong>.
                Los <em>contactos de empresas</em> son clientes externos y <strong>NO</strong> acceden al sistema.</p>
            </div>
            <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div></div>`;
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        document.getElementById('btnSearch').addEventListener('click', () => {
            App.showSearchModal('Buscar Trabajadores', [
                { name: 'query', label: 'Buscar', placeholder: 'Nombre, documento, correo...' },
            ], (filters) => { this.currentFilters = filters; this.currentPage = 1; this.load(); });
        });
        await this.load();
    },

    async load() {
        const search = this.currentFilters.query || '';
        try {
            const rows = await App.request('/personas' + (search ? '?query=' + encodeURIComponent(search) : ''));
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('tableContainer').innerHTML = App.emptyState('👤', 'Error', e.message); }
    },

    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'numero_documento', label: 'Documento', render: r => App.escapeHtml(r.numero_documento || '-') },
            { key: 'nombres', label: 'Nombre', render: r => App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || '')) },
            { key: 'correo', label: 'Correo (login)' },
            { key: 'rol_nombre', label: 'Rol', render: r => r.rol_nombre ? App.badge(r.rol_nombre, 'info') : '<span class="text-muted">Sin rol</span>' },
            { key: 'telefono', label: 'Teléfono', render: r => App.escapeHtml(r.telefono || '-') },
            { key: 'estado', label: 'Estado', render: r => r.estado ? App.badge('Activo', 'success') : App.badge('Inactivo', 'muted') },
            { key: 'bloqueado', label: 'Acceso', render: r => Number(r.bloqueado) === 1 ? App.badge('Bloqueado', 'danger') : App.badge('Habilitado', 'success') },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.usuarios.showFormModal(${r.id_persona})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-info" title="Restablecer contraseña" aria-label="Restablecer contraseña" onclick="App.views.usuarios.resetPassword(${r.id_persona}, '${App.escapeHtml((r.nombres || '') + ' ' + (r.apellido_paterno || ''))}')"><i class="bi bi-key" aria-hidden="true"></i></button>
                    ${Number(r.bloqueado) === 1
                        ? `<button class="btn btn-sm btn-success" title="Desbloquear" aria-label="Desbloquear" onclick="App.views.usuarios.toggleBloqueo(${r.id_persona}, 0)"><i class="bi bi-unlock" aria-hidden="true"></i></button>`
                        : `<button class="btn btn-sm btn-warning" title="Bloquear" aria-label="Bloquear" onclick="App.views.usuarios.toggleBloqueo(${r.id_persona}, 1)"><i class="bi bi-lock" aria-hidden="true"></i></button>`}
                    <button class="btn btn-sm btn-danger" title="Desactivar" aria-label="Desactivar" onclick="App.views.usuarios.delete(${r.id_persona})"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'No hay trabajadores' });
        const paginationHtml = App.renderPagination({
            page: pageData.page,
            totalPages: pageData.totalPages,
            total: pageData.total,
            perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Trabajadores · Directorios' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    async showFormModal(id) {
        let roles = [];
        try { roles = await App.request('/permisos/roles'); } catch (e) { }
        let p = {};
        if (id) {
            try { p = await App.request(`/personas/${id}`); } catch (e) { App.toast('Error al cargar', 'error'); return; }
        }

        App.showModal(id ? 'Editar Trabajador' : 'Nuevo Trabajador',
            `<form id="formUsuario">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Tipo Doc</label>
                        <select class="form-select" id="tipo_documento">
                            <option value="DNI" ${p.tipo_documento == 'DNI' ? 'selected' : ''}>DNI</option>
                            <option value="RUC" ${p.tipo_documento == 'RUC' ? 'selected' : ''}>RUC</option>
                            <option value="CE" ${p.tipo_documento == 'CE' ? 'selected' : ''}>CE</option>
                        </select></div>
                    <div class="form-group"><label class="form-label">Nro Documento</label>
                        <input type="text" class="form-input" id="numero_documento" value="${App.escapeHtml(p.numero_documento || '')}" onblur="App.views.usuarios.validateField('numero_documento')">
                        <div id="err_numero_documento" class="form-error"></div></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Nombres <span class="req">*</span></label><input type="text" class="form-input" id="nombres" required value="${App.escapeHtml(p.nombres || '')}"></div>
                    <div class="form-group"><label class="form-label">Apellido Paterno <span class="req">*</span></label><input type="text" class="form-input" id="apellido_paterno" required value="${App.escapeHtml(p.apellido_paterno || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Correo <span class="req">*</span></label>
                        <input type="email" class="form-input" id="correo" required value="${App.escapeHtml(p.correo || '')}" onblur="App.views.usuarios.validateField('correo')">
                        <div id="err_correo" class="form-error"></div></div>
                    <div class="form-group"><label class="form-label">Telefono</label><input type="text" class="form-input" id="telefono" value="${App.escapeHtml(p.telefono || '')}"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Rol de Trabajo</label>
                        <select class="form-select" id="id_rol">
                            <option value="">Sin rol</option>
                            ${roles.map(r => `<option value="${r.id_rol}" ${p.id_rol == r.id_rol ? 'selected' : ''}>${App.escapeHtml(r.nombre)}</option>`).join('')}
                        </select></div>
                    <div class="form-group"><label class="form-label">Contrasena ${id ? '(dejar en blanco para mantener)' : ''}</label><input type="password" class="form-input" id="contrasena"></div>
                </div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveUsuario">${id ? 'Guardar' : 'Crear'}</button>`);

        document.getElementById('btnSaveUsuario').addEventListener('click', async () => {
            const data = {
                tipo_documento: document.getElementById('tipo_documento').value,
                numero_documento: document.getElementById('numero_documento').value,
                nombres: document.getElementById('nombres').value,
                apellido_paterno: document.getElementById('apellido_paterno').value,
                apellido_materno: '',
                correo: document.getElementById('correo').value,
                telefono: document.getElementById('telefono').value,
                id_rol: parseInt(document.getElementById('id_rol').value) || null,
            };
            const pass = document.getElementById('contrasena').value;
            if (pass) data.contrasena = pass;
            if (!data.nombres || !data.apellido_paterno || !data.correo) {
                App.toast('Complete los campos requeridos', 'warning'); return;
            }
            // Validar duplicados
            if (data.correo) {
                const existeCorreo = await App.validateDuplicate('persona', 'correo', data.correo, id);
                if (existeCorreo) { App.toast('El correo ya existe', 'error'); return; }
            }
            if (data.numero_documento) {
                const existeDoc = await App.validateDuplicate('persona', 'numero_documento', data.numero_documento, id);
                if (existeDoc) { App.toast('El documento ya existe', 'error'); return; }
            }

            try {
                if (id) {
                    await App.request(`/personas/${id}`, { method: 'PUT', body: data });
                    App.toast('Trabajador actualizado', 'success');
                } else {
                    await App.request('/personas', { method: 'POST', body: data });
                    App.toast('Trabajador creado', 'success');
                }
                App.closeModal();
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    async validateField(field) {
        const value = document.getElementById(field).value.trim();
        if (!value) return;
        const existe = await App.validateDuplicate('persona', field, value);
        const errEl = document.getElementById('err_' + field);
        if (existe) {
            errEl.textContent = '⚠ Ya existe';
            errEl.style.color = 'var(--c-danger)';
        } else {
            errEl.textContent = '✓ Disponible';
            errEl.style.color = 'var(--c-success)';
        }
    },

    async delete(id) {
        App.confirmDelete('trabajador ID ' + id, async () => {
            try {
                await App.request(`/personas/${id}`, { method: 'DELETE' });
                App.toast('Trabajador desactivado', 'success');
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    async toggleBloqueo(id, bloquear) {
        try {
            await App.request(`/personas/${id}/bloqueo`, { method: 'PATCH', body: { bloqueado: bloquear } });
            App.toast(bloquear ? 'Usuario bloqueado' : 'Usuario desbloqueado', 'success');
            this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error');
        }
    },

    resetPassword(id, nombre) {
        App.showModal(`Restablecer contraseña - ${nombre}`,
            `<div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                Va a restablecer la contraseña de <strong>${App.escapeHtml(nombre)}</strong>.
                El usuario deberá usar la nueva contraseña para iniciar sesión. También se desbloqueará el acceso si estaba bloqueado.
            </div>
            <div class="form-group">
                <label class="form-label">Nueva contraseña <span class="req">*</span></label>
                <input type="password" class="form-input" id="nuevaContrasena" required minlength="4" placeholder="Mínimo 4 caracteres">
            </div>
            <div class="form-group">
                <label class="form-label">Confirmar contraseña <span class="req">*</span></label>
                <input type="password" class="form-input" id="confirmarContrasena" required minlength="4" placeholder="Repita la nueva contraseña">
            </div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnResetPassword"><i class="bi bi-key" aria-hidden="true"></i> Restablecer</button>`);
        document.getElementById('btnResetPassword').addEventListener('click', async () => {
            const pass = document.getElementById('nuevaContrasena').value;
            const confirm = document.getElementById('confirmarContrasena').value;
            if (!pass || pass.length < 4) { App.toast('La contraseña debe tener al menos 4 caracteres', 'warning'); return; }
            if (pass !== confirm) { App.toast('Las contraseñas no coinciden', 'warning'); return; }
            try {
                await App.request('/auth/reset-password/' + id, {
                    method: 'POST',
                    body: { nueva_contrasena: pass }
                });
                App.toast('Contraseña restablecida para ' + nombre, 'success');
                App.closeModal();
                this.load();
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });
    },
};

/* ============================================================================
 *  VISTA: ATENCIONES — REDIRIGIDA A TICKETS
 *  Las atenciones NO tienen vida propia. Todas pertenecen a un ticket.
 *  Se gestionan desde el detalle de cada ticket.
 *  Si alguien intenta acceder a #/atenciones, se redirige a #/tickets.
 * ========================================================================== */
App.views.atenciones = {
    async render(container) {
        App.toast('Las atenciones se gestionan dentro de cada ticket', 'info');
        App.navigate('/tickets');
    },
};

/* ============================================================================
 *  NOTA: La vista "usuarios-sistema" fue eliminada.
 *  Los TRABAJADORES son los usuarios que acceden al sistema.
 *  Cada trabajador tiene correo + contraseña + rol que le da acceso.
 *  Los CONTACTOS de empresas son clientes externos y NO acceden al sistema.
 *  Usar App.views.usuarios para gestionar trabajadores/usuarios del sistema.
 * ========================================================================== */

App.views.seguridad = {
    async render(container) {
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Roles y Permisos</h1>
                <div class="card-actions">
                    <button class="btn btn-primary" id="btnNewRol"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Rol</button>
                </div>
            </div>
            <div class="alert alert-info">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Seleccione un rol en el panel izquierdo para ver y editar sus permisos.
                Los cambios se aplican inmediatamente a todos los usuarios con ese rol.
                El permiso <strong>ver</strong> es necesario para que el módulo aparezca en el menú.
                Los usuarios con correo <code>admin@soporte.com</code> tienen bypass total.
            </div>
            <div class="card-grid">
                <div class="card" style="max-width:380px">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-people" aria-hidden="true"></i> Roles del sistema</div>
                    </div>
                    <div id="rolesList"><div class="loading-container"><div class="spinner"></div></div></div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> Matriz de permisos: <span id="rolNombre" class="text-muted">Seleccione un rol</span></div>
                    </div>
                    <div id="permisosContainer"><p class="text-muted">Seleccione un rol en el panel izquierdo.</p></div>
                </div>
            </div>`;
        document.getElementById('btnNewRol').addEventListener('click', () => this.showNewRolModal());
        await this.loadRoles();
    },
    async loadRoles() {
        try {
            const roles = await App.request('/permisos/roles');
            const html = (roles || []).map(r => `
                <div class="rol-item" data-id="${r.id_rol}" onclick="App.views.seguridad.loadPermisos(${r.id_rol}, '${App.escapeHtml(r.nombre)}')" style="padding:12px;border-bottom:1px solid var(--c-border);cursor:pointer;transition:background .15s" onmouseover="this.style.background='var(--c-bg-hover, #f3f4f6)'" onmouseout="this.style.background=''">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <strong><i class="bi bi-person-badge" aria-hidden="true"></i> ${App.escapeHtml(r.nombre)}</strong>
                            ${r.descripcion ? `<div class="text-muted text-sm">${App.escapeHtml(r.descripcion)}</div>` : ''}
                        </div>
                        <span class="badge badge-info" title="Usuarios con este rol">${r.usuarios_count || 0} <i class="bi bi-people" aria-hidden="true"></i></span>
                    </div>
                </div>`).join('');
            document.getElementById('rolesList').innerHTML = html || '<p class="text-muted">Sin roles</p>';
        } catch (e) { document.getElementById('rolesList').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    async loadPermisos(idRol, nombreRol) {
        document.getElementById('rolNombre').textContent = nombreRol;
        document.getElementById('permisosContainer').innerHTML = '<div class="loading-container"><div class="spinner"></div></div>';
        try {
            const data = await App.request(`/permisos/${idRol}`);
            const modulos = data.modulos || [];
            const permisos = data.permisos || {};
            const acciones = [
                { key: 'ver',        label: 'Ver',        icon: 'bi-eye',            color: '#3b82f6', title: 'Ver el módulo en el menú y listar registros' },
                { key: 'registrar',  label: 'Registrar',  icon: 'bi-plus-lg',        color: '#10b981', title: 'Crear nuevos registros' },
                { key: 'modificar',  label: 'Modificar',  icon: 'bi-pencil',         color: '#f59e0b', title: 'Editar registros existentes' },
                { key: 'eliminar',   label: 'Eliminar',   icon: 'bi-trash3',          color: '#ef4444', title: 'Eliminar registros (lógico o físico)' },
                { key: 'aprobar',    label: 'Aprobar',    icon: 'bi-check2-circle',   color: '#8b5cf6', title: 'Aprobar solicitudes o documentos' },
                { key: 'exportar',   label: 'Exportar',   icon: 'bi-download',        color: '#06b6d4', title: 'Exportar a Excel/PDF/CSV' },
                { key: 'imprimir',   label: 'Imprimir',   icon: 'bi-printer',         color: '#6b7280', title: 'Imprimir fichas o reportes' },
            ];
            let html = `<div class="table-wrap"><table class="permisos-table"><thead><tr>
                <th style="min-width:180px">Módulo</th>`;
            acciones.forEach(a => {
                html += `<th style="text-align:center;min-width:80px" title="${App.escapeHtml(a.title)}">
                    <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
                        <i class="bi ${a.icon}" aria-hidden="true" style="color:${a.color}"></i>
                        <span style="font-size:11px">${a.label}</span>
                    </div>
                </th>`;
            });
            html += `</tr></thead><tbody>`;
            modulos.forEach(m => {
                const p = permisos[m.codigo] || {};
                const activos = acciones.filter(a => p[a.key] == 1).length;
                html += `<tr>
                    <td>
                        <strong>${App.escapeHtml(m.nombre)}</strong>
                        ${m.descripcion ? `<div class="text-muted text-sm">${App.escapeHtml(m.descripcion)}</div>` : ''}
                        <span class="badge ${activos === acciones.length ? 'badge-success' : activos > 0 ? 'badge-info' : 'badge-secondary'}" style="margin-top:4px;font-size:10px">${activos}/${acciones.length}</span>
                    </td>`;
                acciones.forEach(a => {
                    const checked = p[a.key] == 1 ? 'checked' : '';
                    html += `<td style="text-align:center">
                        <input type="checkbox" data-mod="${m.codigo}" data-acc="${a.key}" ${checked} title="${App.escapeHtml(a.title)}" style="transform:scale(1.3);cursor:pointer">
                    </td>`;
                });
                html += `</tr>`;
            });
            html += `</tbody></table></div>
                <div style="margin-top:16px;display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap">
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-sm" id="btnSelectAll" title="Marcar todos los permisos"><i class="bi bi-check2-all" aria-hidden="true"></i> Marcar Todos</button>
                        <button class="btn btn-sm" id="btnClearAll" title="Desmarcar todos los permisos"><i class="bi bi-x-lg" aria-hidden="true"></i> Limpiar</button>
                        <button class="btn btn-sm" id="btnSelectVer" title="Marcar solo el permiso 'ver' (lectura)"><i class="bi bi-eye" aria-hidden="true"></i> Solo Lectura</button>
                    </div>
                    <button class="btn btn-primary" id="btnSavePermisos"><i class="bi bi-save" aria-hidden="true"></i> Guardar Permisos</button>
                </div>`;
            document.getElementById('permisosContainer').innerHTML = html;
            document.getElementById('btnSavePermisos').addEventListener('click', () => this.savePermisos(idRol));
            document.getElementById('btnSelectAll').addEventListener('click', () => {
                document.querySelectorAll('#permisosContainer input[type=checkbox]').forEach(cb => cb.checked = true);
            });
            document.getElementById('btnClearAll').addEventListener('click', () => {
                document.querySelectorAll('#permisosContainer input[type=checkbox]').forEach(cb => cb.checked = false);
            });
            document.getElementById('btnSelectVer').addEventListener('click', () => {
                document.querySelectorAll('#permisosContainer input[type=checkbox]').forEach(cb => {
                    cb.checked = cb.dataset.acc === 'ver';
                });
            });
        } catch (e) {
            document.getElementById('permisosContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message);
        }
    },
    async savePermisos(idRol) {
        const permisos = {};
        document.querySelectorAll('#permisosContainer input[type=checkbox]').forEach(cb => {
            const mod = cb.dataset.mod, acc = cb.dataset.acc;
            if (!permisos[mod]) permisos[mod] = {};
            permisos[mod][acc] = cb.checked;
        });
        try {
            await App.request(`/permisos/${idRol}`, { method: 'POST', body: { permisos } });
            App.toast('Permisos guardados correctamente', 'success');
        } catch (e) { App.toast('Error al guardar: ' + e.message, 'error'); }
    },
    showNewRolModal() {
        App.showModal('Nuevo Rol',
            `<div class="form-group"><label class="form-label">Nombre <span class="req">*</span></label>
                <input type="text" class="form-input" id="rolNombreInput" required placeholder="Ej: SOPORTE, SUPERVISOR, TECNICO_AVANZADO..."></div>
             <div class="form-group"><label class="form-label">Descripción</label>
                <textarea class="form-textarea" id="rolDescInput" placeholder="Describe el alcance del rol (qué usuarios lo tendrán, qué permisos básicos necesita)..."></textarea></div>
             <div class="alert alert-info"><i class="bi bi-info-circle" aria-hidden="true"></i> Tras crear el rol, deberá asignarle permisos en la matriz y luego asignar usuarios desde la vista Trabajadores.</div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnCreateRol"><i class="bi bi-check-lg" aria-hidden="true"></i> Crear</button>`);
        document.getElementById('btnCreateRol').addEventListener('click', async () => {
            const nombre = document.getElementById('rolNombreInput').value.trim();
            if (!nombre) { App.toast('Nombre requerido', 'warning'); return; }
            try {
                await App.request('/permisos/roles', { method: 'POST', body: { nombre, descripcion: document.getElementById('rolDescInput').value } });
                App.toast('Rol creado', 'success');
                App.closeModal();
                this.loadRoles();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.reportes = {
    async render(container) {
        container.innerHTML = `
            <div class="card-header mb-4"><h1 style="color:var(--c-primary)">Reportes</h1></div>
            <div class="card-grid">
                <div class="card"><div class="card-header"><div class="card-title">Tickets por Estado</div></div><div id="rep1"><div class="loading-container"><div class="spinner"></div></div></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Tickets por Prioridad</div></div><div id="rep2"><div class="loading-container"><div class="spinner"></div></div></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Tickets por Empresa</div></div><div id="rep3"><div class="loading-container"><div class="spinner"></div></div></div></div>
                <div class="card"><div class="card-header"><div class="card-title">Atenciones por Tecnico</div></div><div id="rep4"><div class="loading-container"><div class="spinner"></div></div></div></div>
            </div>`;
        await Promise.all([
            this.loadReport('rep1', 'TICKETS_POR_ESTADO'),
            this.loadReport('rep2', 'TICKETS_POR_PRIORIDAD'),
            this.loadReport('rep3', 'TICKETS_POR_EMPRESA'),
            this.loadReport('rep4', 'ATENCIONES_POR_TECNICO'),
        ]);
    },
    async loadReport(containerId, tipo) {
        // IMPORTANTE: Usamos /reportes/series (no /dashboard/series) porque
        // este endpoint requiere permiso REPORTES.ver, no DASHBOARD.ver.
        // Así, usuarios que tienen acceso a Reportes pero no al Dashboard
        // pueden ver las series sin error "Permiso 'ver' sobre modulo 'DASHBOARD' denegado".
        // silent:true evita toasts repetitivos si la llamada falla.
        try {
            const data = await App.request(`/reportes/series?tipo=${tipo}`, { background: true, silent: true });
            const columns = [
                { key: Object.keys(data[0] || {})[0], label: 'Categoria', render: r => App.escapeHtml(r[Object.keys(r)[0]]) },
                { key: 'total', label: 'Total', render: r => App.formatNumber(r.total || r.atenciones || 0) },
            ];
            if (tipo === 'ATENCIONES_POR_TECNICO') {
                columns.push({ key: 'promedio_minutos', label: 'Promedio (min)', render: r => App.formatNumber(r.promedio_minutos || 0, 1) });
            }
            document.getElementById(containerId).innerHTML = App.table({ columns, rows: data, empty: 'Sin datos' });
        } catch (e) {
            document.getElementById(containerId).innerHTML = App.emptyState('⚠️', 'Error', e.message);
        }
    },
};

App.views.paises = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `<div class="card-header mb-4">
            <h1 style="color:var(--c-primary)">Países</h1>
            <button class="btn btn-primary" id="btnNewPais"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo País</button>
        </div>
            <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div></div></div></div>`;
        document.getElementById('btnNewPais').addEventListener('click', () => this.showFormModal(null));
        await this.load();
    },
    async load() {
        try {
            const rows = await App.request('/geografia/paises');
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message);
        }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'id_pais', label: 'ID' },
            { key: 'nombre', label: 'País' },
            { key: 'codigo_iso', label: 'Código ISO' },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.paises.showFormModal(${r.id_pais}, '${App.escapeHtml(r.nombre)}', '${App.escapeHtml(r.codigo_iso || '')}')"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-danger" title="Eliminar" aria-label="Eliminar" onclick="App.views.paises.delete(${r.id_pais}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin países' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
            },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Países · Geografía' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
    showFormModal(id, nombre, codigoIso) {
        const esNuevo = id === null || id === undefined;
        App.showModal(esNuevo ? 'Nuevo País' : 'Editar País',
            `<form id="formPais">
                <div class="form-group">
                    <label class="form-label">Nombre del país <span class="req">*</span></label>
                    <input type="text" class="form-input" id="paisNombre" required value="${App.escapeHtml(nombre || '')}" maxlength="100" placeholder="Ej: Perú">
                </div>
                <div class="form-group">
                    <label class="form-label">Código ISO <span class="req">*</span></label>
                    <input type="text" class="form-input" id="paisCodigoIso" required value="${App.escapeHtml(codigoIso || '')}" maxlength="3" placeholder="Ej: PE" style="text-transform:uppercase">
                    <div class="form-text">Código ISO de 2-3 letras (alpha-2 o alpha-3).</div>
                </div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSavePais"><i class="bi bi-check-lg" aria-hidden="true"></i> ${esNuevo ? 'Crear' : 'Guardar'}</button>`);
        // Forzar mayúsculas en código ISO
        document.getElementById('paisCodigoIso').addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase();
        });
        document.getElementById('btnSavePais').addEventListener('click', async () => {
            const nombre = document.getElementById('paisNombre').value.trim();
            const codigoIso = document.getElementById('paisCodigoIso').value.trim().toUpperCase();
            if (!nombre || !codigoIso) {
                App.toast('Nombre y código ISO son requeridos', 'warning');
                return;
            }
            try {
                if (esNuevo) {
                    await App.request('/geografia/paises', {
                        method: 'POST',
                        body: { nombre, codigo_iso: codigoIso }
                    });
                    App.toast('País creado', 'success');
                } else {
                    await App.request(`/geografia/paises/${id}`, {
                        method: 'PUT',
                        body: { nombre, codigo_iso: codigoIso }
                    });
                    App.toast('País actualizado', 'success');
                }
                App.closeModal();
                this.load();
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });
    },
    async delete(id, nombre) {
        App.confirmDelete('país ' + nombre, async () => {
            try {
                await App.request(`/geografia/paises/${id}`, { method: 'DELETE' });
                App.toast('País eliminado', 'success');
                this.load();
            } catch (e) {
                App.toast('Error: ' + e.message, 'error');
            }
        });
    },
};

// Mantener compatibilidad con la ruta antigua ubicaciones
App.views.ubicaciones = App.views.paises;

App.views.divisiones = {
    currentFilters: { id_pais: '', id_departamento: '', id_provincia: '', id_distrito: '', busqueda: '' },
    currentPage: 1,
    perPage: 100,
    allRows: [],
    // Caché en memoria de la jerarquía por país
    cache: { id_pais: null, departamentos: [], provincias: [], distritos: [] },

    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `<div class="card-header mb-4">
            <h1 style="color:var(--c-primary)">Divisiones Geográficas</h1>
            <div class="card-actions">
                <button class="btn btn-primary" id="btnNewDivision"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva división</button>
            </div>
        </div>

        <div class="card" style="margin-bottom:12px">
            <div class="card-header"><div class="card-title"><i class="bi bi-funnel"></i> Filtros en cascada</div></div>
            <div style="padding:12px">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">País</label>
                        <select class="form-select" id="filterPais" aria-label="Filtrar por país">
                            <option value="">Todos los países</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Departamento</label>
                        <select class="form-select" id="filterDepartamento" disabled>
                            <option value="">— Todos —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Provincia</label>
                        <select class="form-select" id="filterProvincia" disabled>
                            <option value="">— Todas —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Distrito</label>
                        <select class="form-select" id="filterDistrito" disabled>
                            <option value="">— Todos —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:2">
                        <label class="form-label">Búsqueda por nombre</label>
                        <input type="text" class="form-input" id="filterBusqueda" placeholder="Escriba para filtrar por nombre...">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;gap:6px">
                        <button class="btn" id="btnLimpiarFiltros"><i class="bi bi-x-circle"></i> Limpiar filtros</button>
                    </div>
                </div>
                <div id="filtroInfo" class="text-sm text-muted" style="margin-top:6px"></div>
            </div>
        </div>

        <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div></div></div></div>`;

        // Cargar países
        try {
            const paises = await App.request('/geografia/paises');
            document.getElementById('filterPais').innerHTML = '<option value="">Todos los países</option>' +
                paises.map(country => `<option value="${country.id_pais}">${App.escapeHtml(country.nombre)}</option>`).join('');
        } catch (error) { App.toast('No se pudieron cargar los países', 'error'); }

        // Restaurar filtro país si existía
        document.getElementById('filterPais').value = this.currentFilters.id_pais || '';

        // Listeners de filtros en cascada
        document.getElementById('filterPais').addEventListener('change', async (e) => {
            this.currentFilters.id_pais = e.target.value;
            this.currentFilters.id_departamento = '';
            this.currentFilters.id_provincia = '';
            this.currentFilters.id_distrito = '';
            await this.cargarJerarquia();
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('filterDepartamento').addEventListener('change', async (e) => {
            this.currentFilters.id_departamento = e.target.value;
            this.currentFilters.id_provincia = '';
            this.currentFilters.id_distrito = '';
            await this.llenarProvincias();
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('filterProvincia').addEventListener('change', async (e) => {
            this.currentFilters.id_provincia = e.target.value;
            this.currentFilters.id_distrito = '';
            await this.llenarDistritos();
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('filterDistrito').addEventListener('change', () => {
            this.currentFilters.id_distrito = document.getElementById('filterDistrito').value;
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('filterBusqueda').addEventListener('input', (e) => {
            this.currentFilters.busqueda = e.target.value.toLowerCase().trim();
            this.currentPage = 1;
            this.renderTablePage();
        });
        document.getElementById('btnLimpiarFiltros').addEventListener('click', () => {
            this.currentFilters = { id_pais: '', id_departamento: '', id_provincia: '', id_distrito: '', busqueda: '' };
            document.getElementById('filterPais').value = '';
            document.getElementById('filterDepartamento').innerHTML = '<option value="">— Todos —</option>';
            document.getElementById('filterDepartamento').disabled = true;
            document.getElementById('filterProvincia').innerHTML = '<option value="">— Todas —</option>';
            document.getElementById('filterProvincia').disabled = true;
            document.getElementById('filterDistrito').innerHTML = '<option value="">— Todos —</option>';
            document.getElementById('filterDistrito').disabled = true;
            document.getElementById('filterBusqueda').value = '';
            this.cache = { id_pais: null, pais_division: null, departamentos: [], provincias: [], distritos: [] };
            this.currentPage = 1;
            this.load();
        });

        document.getElementById('btnNewDivision').addEventListener('click', () => this.showFormModal(null));

        // Si ya había filtro país, cargar jerarquía
        if (this.currentFilters.id_pais) {
            await this.cargarJerarquia();
        }

        await this.load();
    },

    // Carga departamentos + provincias + distritos del país en 1 sola petición
    async cargarJerarquia() {
        const idPais = this.currentFilters.id_pais;
        if (!idPais) {
            this.cache = { id_pais: null, pais_division: null, departamentos: [], provincias: [], distritos: [] };
            document.getElementById('filterDepartamento').innerHTML = '<option value="">— Todos —</option>';
            document.getElementById('filterDepartamento').disabled = true;
            document.getElementById('filterProvincia').innerHTML = '<option value="">— Todas —</option>';
            document.getElementById('filterProvincia').disabled = true;
            document.getElementById('filterDistrito').innerHTML = '<option value="">— Todos —</option>';
            document.getElementById('filterDistrito').disabled = true;
            return;
        }

        try {
            const data = await App.request(`/geografia/cascada?id_pais=${idPais}`);
            this.cache = {
                id_pais: idPais,
                pais_division: data.pais_division || null,
                departamentos: data.departamentos || [],
                provincias: data.provincias || [],
                distritos: data.distritos || [],
            };
            this.llenarDepartamentos();
        } catch (e) {
            console.error('cargarJerarquia error', e);
            App.toast('No se pudo cargar la jerarquía', 'error');
        }
    },

    llenarDepartamentos() {
        const sel = document.getElementById('filterDepartamento');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Todos —</option>' +
            this.cache.departamentos.map(d =>
                `<option value="${d.id_division}">${App.escapeHtml(d.nombre)}</option>`
            ).join('');
        sel.disabled = this.cache.departamentos.length === 0;
        sel.value = this.currentFilters.id_departamento || '';
        this.llenarProvincias();
    },

    async llenarProvincias() {
        const sel = document.getElementById('filterProvincia');
        const selDist = document.getElementById('filterDistrito');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Todas —</option>';
        if (selDist) {
            selDist.innerHTML = '<option value="">— Todos —</option>';
            selDist.disabled = true;
        }
        const idDepto = parseInt(this.currentFilters.id_departamento) || 0;
        if (!idDepto) {
            sel.disabled = true;
            return;
        }
        const provincias = this.cache.provincias.filter(p => parseInt(p.id_departamento) === idDepto);
        sel.innerHTML = '<option value="">— Todas —</option>' +
            provincias.map(p =>
                `<option value="${p.id_division}">${App.escapeHtml(p.nombre)}</option>`
            ).join('');
        sel.disabled = provincias.length === 0;
        sel.value = this.currentFilters.id_provincia || '';
    },

    async llenarDistritos() {
        const sel = document.getElementById('filterDistrito');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Todos —</option>';
        const idProv = parseInt(this.currentFilters.id_provincia) || 0;
        if (!idProv) {
            sel.disabled = true;
            return;
        }
        const distritos = this.cache.distritos.filter(d => parseInt(d.id_provincia) === idProv);
        sel.innerHTML = '<option value="">— Todos —</option>' +
            distritos.map(d =>
                `<option value="${d.id_division}">${App.escapeHtml(d.nombre)}</option>`
            ).join('');
        sel.disabled = distritos.length === 0;
        sel.value = this.currentFilters.id_distrito || '';
    },

    async load() {
        try {
            // Construir query con el filtro de país
            const params = new URLSearchParams();
            if (this.currentFilters.id_pais) params.set('id_pais', this.currentFilters.id_pais);
            const qs = params.toString();
            const rows = await App.request('/geografia/divisiones' + (qs ? '?' + qs : ''));
            this.allRows = Array.isArray(rows) ? rows : [];

            // FIX: si hay país seleccionado y la caché tiene datos, construir
            // this.allRows a partir de la caché de la jerarquía para asegurar
            // que tengamos TODOS los niveles con id_tipo_division correcto.
            //
            // ESQUEMA REAL DE TU BD:
            //   tipo 1 = Departamento  (ej: Lima, Arequipa)
            //   tipo 2 = Provincia     (hija de tipo 1)
            //   tipo 3 = Distrito      (hijo de tipo 2)
            if (this.currentFilters.id_pais && this.cache.departamentos.length > 0) {
                const idsValidos = new Set();
                const rowsCombinados = [];

                // Añadir departamentos desde la caché (tipo 1)
                this.cache.departamentos.forEach(d => {
                    const id = parseInt(d.id_division);
                    if (!idsValidos.has(id)) {
                        idsValidos.add(id);
                        rowsCombinados.push({
                            id_division: d.id_division,
                            nombre: d.nombre,
                            id_tipo_division: 1,  // Departamento
                            id_division_padre: null,
                            id_pais: parseInt(this.currentFilters.id_pais),
                        });
                    }
                });

                // Añadir provincias desde la caché (tipo 2)
                this.cache.provincias.forEach(p => {
                    const id = parseInt(p.id_division);
                    if (!idsValidos.has(id)) {
                        idsValidos.add(id);
                        rowsCombinados.push({
                            id_division: p.id_division,
                            nombre: p.nombre,
                            id_tipo_division: 2,  // Provincia
                            id_division_padre: p.id_departamento || null,
                            id_pais: parseInt(this.currentFilters.id_pais),
                        });
                    }
                });

                // Añadir distritos desde la caché (tipo 3)
                this.cache.distritos.forEach(d => {
                    const id = parseInt(d.id_division);
                    if (!idsValidos.has(id)) {
                        idsValidos.add(id);
                        rowsCombinados.push({
                            id_division: d.id_division,
                            nombre: d.nombre,
                            id_tipo_division: 3,  // Distrito
                            id_division_padre: d.id_provincia || null,
                            id_pais: parseInt(this.currentFilters.id_pais),
                        });
                    }
                });

                // Sustituir this.allRows por la combinación de caché
                this.allRows = rowsCombinados;
            }

            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message);
        }
    },

    // Filtra client-side según país/departamento/provincia/distrito seleccionado.
    //
    // COMPORTAMIENTO POR NIVEL:
    //   - Solo país                → muestra deptos + provincias + distritos del país
    //   - País + Departamento      → muestra SOLO las provincias de ese depto
    //   - País + Depto + Provincia → muestra SOLO los distritos de esa provincia
    //   - País + Depto + Prov + Distrito → muestra SOLO ese distrito
    //
    // ESQUEMA REAL DE TU BD:
    //   tipo 1 = Departamento, tipo 2 = Provincia, tipo 3 = Distrito
    filtrarRows() {
        let rows = this.allRows;

        // Helper: mapa id_division → id_division_padre
        const padreDe = {};
        this.allRows.forEach(r => {
            padreDe[parseInt(r.id_division)] = r.id_division_padre ? parseInt(r.id_division_padre) : null;
        });

        // Helper: ¿es hijo directo (1 nivel) de idPadre?
        const esHijoDirectoDe = (idHijo, idPadre) => padreDe[idHijo] === idPadre;

        // Helper: ¿es de un tipo concreto?
        const esTipo = (r, tipo) => parseInt(r.id_tipo_division) === tipo;

        // Caso 1: distrito seleccionado → solo ese distrito
        if (this.currentFilters.id_distrito) {
            const idDist = parseInt(this.currentFilters.id_distrito);
            rows = rows.filter(r => parseInt(r.id_division) === idDist);
        }
        // Caso 2: provincia seleccionada (sin distrito)
        //         → solo distritos hijos directos de esa provincia
        else if (this.currentFilters.id_provincia) {
            const idProv = parseInt(this.currentFilters.id_provincia);
            rows = rows.filter(r => {
                const id = parseInt(r.id_division);
                return esTipo(r, 3) && esHijoDirectoDe(id, idProv);
            });
        }
        // Caso 3: departamento seleccionado (sin provincia ni distrito)
        //         → solo provincias hijas directas de ese departamento
        else if (this.currentFilters.id_departamento) {
            const idDepto = parseInt(this.currentFilters.id_departamento);
            rows = rows.filter(r => {
                const id = parseInt(r.id_division);
                return esTipo(r, 2) && esHijoDirectoDe(id, idDepto);
            });
        }
        // Caso 4: solo país → mostrar todos los niveles del país
        //         (this.allRows ya viene filtrada por país en load())

        // Búsqueda por nombre (siempre se aplica encima)
        if (this.currentFilters.busqueda) {
            const q = this.currentFilters.busqueda;
            rows = rows.filter(r => (r.nombre || '').toLowerCase().includes(q));
        }

        return rows;
    },

    renderTablePage() {
        const filteredRows = this.filtrarRows();
        const pageData = App.paginate(filteredRows, this.currentPage, this.perPage);

        // Construir nombres legibles de padre
        const enriquecer = (r) => {
            const tipo = Number(r.id_tipo_division);
            const idPadre = Number(r.id_division_padre);
            let padreNombre = '-';
            if (tipo === 2) {
                // Provincia: padre es departamento
                const dep = this.cache.departamentos.find(d => Number(d.id_division) === idPadre);
                padreNombre = dep ? dep.nombre : '-';
            } else if (tipo === 3) {
                // Distrito: padre es provincia
                const prov = this.cache.provincias.find(p => Number(p.id_division) === idPadre);
                padreNombre = prov ? prov.nombre : '-';
            } else if (tipo === 1) {
                padreNombre = '— (raíz)';
            }
            return { ...r, padre: padreNombre, pais: this.getNombrePais() };
        };
        const rowsEnriquecidos = pageData.rows.map(enriquecer);

        const columns = [
            { key: 'nombre', label: 'Nombre', render: r => `<strong>${App.escapeHtml(r.nombre)}</strong>` },
            { key: 'id_tipo_division', label: 'Nivel', render: r => {
                const tipo = Number(r.id_tipo_division);
                const cls = tipo === 1 ? 'badge-info' : (tipo === 2 ? 'badge-success' : 'badge-warning');
                return `<span class="badge ${cls}">${this.typeName(tipo)}</span>`;
            }},
            { key: 'pais', label: 'País', render: r => App.escapeHtml(r.pais || '-') },
            { key: 'padre', label: 'Depende de', render: r => App.escapeHtml(r.padre || '-') },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar división" aria-label="Editar división" onclick="App.views.divisiones.showFormModal(${r.id_division})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-danger" title="Eliminar división" aria-label="Eliminar división" onclick="App.views.divisiones.delete(${r.id_division}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: rowsEnriquecidos, empty: 'Sin divisiones que coincidan con los filtros' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => {
                this.currentPage = newPage;
                this.renderTablePage();
                document.getElementById('tableContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        });

        // Info de filtros activos
        const info = [];
        if (this.currentFilters.id_pais) info.push(`País: ${this.getNombrePais()}`);
        if (this.currentFilters.id_departamento) {
            const d = this.cache.departamentos.find(x => Number(x.id_division) === Number(this.currentFilters.id_departamento));
            info.push(`Depto: ${d ? d.nombre : '?'}`);
        }
        if (this.currentFilters.id_provincia) {
            const p = this.cache.provincias.find(x => Number(x.id_division) === Number(this.currentFilters.id_provincia));
            info.push(`Provincia: ${p ? p.nombre : '?'}`);
        }
        if (this.currentFilters.id_distrito) {
            const d = this.cache.distritos.find(x => Number(x.id_division) === Number(this.currentFilters.id_distrito));
            info.push(`Distrito: ${d ? d.nombre : '?'}`);
        }
        info.push(`Total: ${filteredRows.length} divisiones`);
        document.getElementById('filtroInfo').textContent = info.join(' · ');

        const footerHtml = App.renderFooter({ moduleName: 'Divisiones · Geografía' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },

    getNombrePais() {
        if (!this.currentFilters.id_pais) return 'Todos';
        const sel = document.getElementById('filterPais');
        return sel ? sel.options[sel.selectedIndex].text : '?';
    },

    typeName(type) {
        return ({ 1: 'Departamento', 2: 'Provincia', 3: 'Distrito' })[type] || 'Desconocido';
    },
    async showFormModal(id) {
        let countries = [], division = null, cascadaInicial = null;

        try {
            // Cargar países. Si estamos editando, también cargar la división y su ruta.
            const promises = [App.request('/geografia/paises')];
            if (id) {
                // Necesitamos la división completa. Usamos /geografia/divisiones (lista)
                // y filtramos por id.
                promises.push(App.request('/geografia/divisiones'));
            }
            const results = await Promise.all(promises);
            countries = results[0] || [];

            if (id) {
                const allDivs = results[1] || [];
                division = allDivs.find(row => Number(row.id_division) === Number(id));
                if (!division) throw new Error('Division no encontrada');

                // Cargar la ruta completa de la división para pre-seleccionar los selects
                try {
                    cascadaInicial = await App.request(`/geografia/ruta?id_division=${id}`);
                } catch (e) {
                    cascadaInicial = null;
                }
            }
        } catch (error) {
            App.toast('No se pudieron cargar los datos geograficos: ' + error.message, 'error');
            return;
        }

        const tipoInicial = Number(division?.id_tipo_division || 1);
        const paisInicial = Number(division?.id_pais || (cascadaInicial?.pais?.id_pais) || 1);
        const deptoInicial = cascadaInicial?.departamento?.id_division || null;
        const provInicial = cascadaInicial?.provincia?.id_division || null;

        App.showModal(id ? 'Editar division geografica' : 'Nueva division geografica',
            `<form id="divisionForm">
                <div class="form-group">
                    <label class="form-label" for="divisionNombre">Nombre <span class="req">*</span></label>
                    <input class="form-input" id="divisionNombre" maxlength="100" required
                           value="${App.escapeHtml(division?.nombre || '')}"
                           placeholder="Ej: Miraflores">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="divisionTipo">Nivel <span class="req">*</span></label>
                        <select class="form-select" id="divisionTipo" ${id ? 'disabled' : ''}>
                            ${[1, 2, 3].map(type => `
                                <option value="${type}" ${tipoInicial === type ? 'selected' : ''}>
                                    ${this.typeName(type)}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="divisionPais">Pais <span class="req">*</span></label>
                        <select class="form-select" id="divisionPais" ${id ? 'disabled' : ''}>
                            ${countries.map(country => `
                                <option value="${country.id_pais}" ${paisInicial === Number(country.id_pais) ? 'selected' : ''}>
                                    ${App.escapeHtml(country.nombre)}
                                </option>
                            `).join('')}
                        </select>
                    </div>
                </div>

                <div class="alert alert-info" id="divisionHelp" style="margin-top:8px">
                    <i class="bi bi-info-circle"></i>
                    <span id="divisionHelpText">Seleccione el nivel y país para ver las opciones.</span>
                </div>

                <!-- Select de Departamento (visible si nivel=Provincia o Distrito) -->
                <div class="form-group" id="divisionDeptoGroup" style="display:none">
                    <label class="form-label" for="divisionDepto">
                        Departamento <span class="req">*</span>
                    </label>
                    <select class="form-select" id="divisionDepto">
                        <option value="">Cargando...</option>
                    </select>
                </div>

                <!-- Select de Provincia (visible solo si nivel=Distrito) -->
                <div class="form-group" id="divisionProvGroup" style="display:none">
                    <label class="form-label" for="divisionProv">
                        Provincia <span class="req">*</span>
                    </label>
                    <select class="form-select" id="divisionProv">
                        <option value="">Cargando...</option>
                    </select>
                </div>

                <!-- Campo oculto para id_division_padre (se llena automáticamente) -->
                <input type="hidden" id="divisionParent" value="">
            </form>`,
            `<button class="btn" type="button" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" type="button" id="btnSaveDivision">
                 <i class="bi bi-check-lg" aria-hidden="true"></i> ${id ? 'Actualizar' : 'Crear'}
             </button>`);

        // Referencias a elementos
        const typeSelect = document.getElementById('divisionTipo');
        const countrySelect = document.getElementById('divisionPais');
        const deptoGroup = document.getElementById('divisionDeptoGroup');
        const provGroup = document.getElementById('divisionProvGroup');
        const deptoSelect = document.getElementById('divisionDepto');
        const provSelect = document.getElementById('divisionProv');
        const parentInput = document.getElementById('divisionParent');
        const helpText = document.getElementById('divisionHelpText');

        // Estado para saber qué pre-seleccionar al cargar
        const preSeleccion = {
            depto: deptoInicial,
            prov: provInicial,
        };

        // Helper: cargar departamentos del país seleccionado
        async function cargarDeptos() {
            const idPais = Number(countrySelect.value);
            if (!idPais) {
                deptoSelect.innerHTML = '<option value="">— Seleccione país —</option>';
                return;
            }
            deptoSelect.innerHTML = '<option value="">Cargando...</option>';
            try {
                const deptos = await App.request(`/geografia/departamentos?id_pais=${idPais}`);
                deptoSelect.innerHTML = '<option value="">— Seleccione —</option>' +
                    deptos.map(d => `<option value="${d.id_division}">${App.escapeHtml(d.nombre)}</option>`).join('');
                // Pre-seleccionar si estamos editando
                if (preSeleccion.depto) {
                    deptoSelect.value = preSeleccion.depto;
                    preSeleccion.depto = null; // solo la primera vez
                }
            } catch (e) {
                deptoSelect.innerHTML = '<option value="">Error al cargar</option>';
                App.toast('No se pudieron cargar departamentos', 'error');
            }
        }

        // Helper: cargar provincias del departamento seleccionado
        async function cargarProvincias() {
            const idDepto = Number(deptoSelect.value);
            if (!idDepto) {
                provSelect.innerHTML = '<option value="">— Seleccione departamento —</option>';
                return;
            }
            provSelect.innerHTML = '<option value="">Cargando...</option>';
            try {
                const provs = await App.request(`/geografia/provincias?id_departamento=${idDepto}`);
                provSelect.innerHTML = '<option value="">— Seleccione —</option>' +
                    provs.map(p => `<option value="${p.id_division}">${App.escapeHtml(p.nombre)}</option>`).join('');
                // Pre-seleccionar si estamos editando
                if (preSeleccion.prov) {
                    provSelect.value = preSeleccion.prov;
                    preSeleccion.prov = null;
                }
            } catch (e) {
                provSelect.innerHTML = '<option value="">Error al cargar</option>';
                App.toast('No se pudieron cargar provincias', 'error');
            }
        }

        // Helper: actualizar UI según el nivel seleccionado
        async function actualizarUI() {
            const tipo = Number(typeSelect.value);
            const idPais = Number(countrySelect.value);

            if (tipo === 1) {
                // Departamento: no necesita padre
                deptoGroup.style.display = 'none';
                provGroup.style.display = 'none';
                parentInput.value = '';
                helpText.textContent = 'Nuevo departamento del país seleccionado. No requiere nivel superior.';
            } else if (tipo === 2) {
                // Provincia: necesita Departamento
                deptoGroup.style.display = '';
                provGroup.style.display = 'none';
                helpText.textContent = 'Seleccione el departamento al que pertenece esta provincia.';
                await cargarDeptos();
                // Al cambiar de depto, limpiar el parent
                parentInput.value = deptoSelect.value || '';
            } else if (tipo === 3) {
                // Distrito: necesita Departamento + Provincia
                deptoGroup.style.display = '';
                provGroup.style.display = '';
                helpText.textContent = 'Seleccione departamento y luego provincia para registrar el distrito.';
                await cargarDeptos();
                // Si hay depto pre-seleccionado, cargar provincias
                if (deptoSelect.value) {
                    await cargarProvincias();
                }
                parentInput.value = provSelect.value || '';
            }
        }

        // Listeners
        typeSelect.addEventListener('change', actualizarUI);
        countrySelect.addEventListener('change', async () => {
            // Al cambiar país, resetear depto y provincia
            preSeleccion.depto = null;
            preSeleccion.prov = null;
            await actualizarUI();
        });

        deptoSelect.addEventListener('change', async () => {
            const tipo = Number(typeSelect.value);
            if (tipo === 2) {
                // Para provincia, el padre es el departamento
                parentInput.value = deptoSelect.value;
            } else if (tipo === 3) {
                // Para distrito, cargar provincias del departamento
                await cargarProvincias();
                parentInput.value = provSelect.value || '';
            }
        });

        provSelect.addEventListener('change', () => {
            // Para distrito, el padre es la provincia
            parentInput.value = provSelect.value;
        });

        // Inicializar
        await actualizarUI();

        // Handler del botón Guardar
        document.getElementById('btnSaveDivision').addEventListener('click', async () => {
            const type = Number(typeSelect.value);
            const nombre = document.getElementById('divisionNombre').value.trim();
            const idPais = Number(countrySelect.value);

            // Calcular id_division_padre según el tipo
            let parentId = null;
            if (type === 2) {
                parentId = Number(deptoSelect.value) || null;
            } else if (type === 3) {
                parentId = Number(provSelect.value) || null;
            }

            // Validaciones
            if (!nombre) {
                App.toast('El nombre es requerido', 'warning');
                return;
            }
            if (!idPais) {
                App.toast('Seleccione el país', 'warning');
                return;
            }
            if (type === 2 && !parentId) {
                App.toast('Seleccione el departamento al que pertenece la provincia', 'warning');
                return;
            }
            if (type === 3 && !parentId) {
                App.toast('Seleccione la provincia a la que pertenece el distrito', 'warning');
                return;
            }

            const body = {
                nombre: nombre,
                id_tipo_division: type,
                id_pais: idPais,
                id_division_padre: parentId,
            };

            try {
                await App.request(id ? `/geografia/divisiones/${id}` : '/geografia/divisiones', {
                    method: id ? 'PUT' : 'POST', body,
                });
                App.closeModal();
                App.toast(id ? 'Division actualizada' : 'Division creada', 'success');
                // Invalidar caché y recargar
                this.cache = { id_pais: null, pais_division: null, departamentos: [], provincias: [], distritos: [] };
                if (this.currentFilters.id_pais) await this.cargarJerarquia();
                this.load();
            } catch (error) {
                App.toast('Error: ' + error.message, 'error');
            }
        });
    },
    delete(id, name) {
        App.confirmDelete('division ' + name, async () => {
            try {
                await App.request(`/geografia/divisiones/${id}`, { method: 'DELETE' });
                App.toast('Division eliminada', 'success');
                this.cache = { id_pais: null, pais_division: null, departamentos: [], provincias: [], distritos: [] };
                if (this.currentFilters.id_pais) await this.cargarJerarquia();
                this.load();
            } catch (error) { App.toast('No se pudo eliminar: ' + error.message, 'error'); }
        });
    },
};

App.views.rubros = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Rubros</h1>
                <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Rubro</button>
            </div>
            <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div></div></div></div>`;
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        await this.load();
    },
    async load() {
        try {
            const rows = await App.request('/rubros');
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'id_rubro', label: 'ID' },
            { key: 'nombre', label: 'Rubro' },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.rubros.showFormModal(${r.id_rubro}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-danger" title="Eliminar" aria-label="Eliminar" onclick="App.views.rubros.delete(${r.id_rubro}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin rubros' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Rubros · Geografía' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
    showFormModal(id, nombre) {
        App.showModal(id ? 'Editar Rubro' : 'Nuevo Rubro',
            `<div class="form-group"><label class="form-label">Nombre <span class="req">*</span></label>
                <input type="text" class="form-input" id="rubroNombre" required value="${App.escapeHtml(nombre || '')}" onblur="App.views.rubros.validateField()">
                <div id="err_nombre" class="form-error"></div></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSave">Guardar</button>`);
        document.getElementById('btnSave').addEventListener('click', async () => {
            const nombre = document.getElementById('rubroNombre').value.trim();
            if (!nombre) { App.toast('Nombre requerido', 'warning'); return; }
            const existe = await App.validateDuplicate('rubro', 'nombre', nombre, id);
            if (existe) { App.toast('Ya existe un rubro con ese nombre', 'error'); return; }
            try {
                if (id) {
                    await App.request(`/rubros/${id}`, { method: 'PUT', body: { nombre } });
                } else {
                    await App.request('/rubros', { method: 'POST', body: { nombre } });
                }
                App.toast('Rubro guardado', 'success');
                App.closeModal();
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async validateField() {
        const value = document.getElementById('rubroNombre').value.trim();
        if (!value) return;
        const existe = await App.validateDuplicate('rubro', 'nombre', value);
        const errEl = document.getElementById('err_nombre');
        if (existe) {
            errEl.textContent = '⚠ Ya existe';
            errEl.style.color = 'var(--c-danger)';
        } else {
            errEl.textContent = '✓ Disponible';
            errEl.style.color = 'var(--c-success)';
        }
    },
    async delete(id, nombre) {
        App.confirmDelete('rubro ' + nombre, async () => {
            try {
                await App.request(`/rubros/${id}`, { method: 'DELETE' });
                App.toast('Rubro eliminado', 'success');
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.grupos = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Grupos Empresariales</h1>
                <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Grupo</button>
            </div>
            <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div></div></div></div>`;
        document.getElementById('btnNew').addEventListener('click', () => this.showFormModal(null));
        await this.load();
    },
    async load() {
        try {
            const rows = await App.request('/grupos');
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'id_grupo', label: 'ID' },
            { key: 'nombre', label: 'Grupo' },
            { key: 'descripcion', label: 'Descripción', render: r => App.escapeHtml(r.descripcion || '-') },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.grupos.showFormModal(${r.id_grupo}, '${App.escapeHtml(r.nombre)}', '${App.escapeHtml(r.descripcion || '')}')"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-danger" title="Eliminar" aria-label="Eliminar" onclick="App.views.grupos.delete(${r.id_grupo}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin grupos' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Grupos · Geografía' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
    showFormModal(id, nombre, descripcion) {
        App.showModal(id ? 'Editar Grupo' : 'Nuevo Grupo',
            `<div class="form-group"><label class="form-label">Nombre <span class="req">*</span></label>
                <input type="text" class="form-input" id="grupoNombre" required value="${App.escapeHtml(nombre || '')}" onblur="App.views.grupos.validateField()">
                <div id="err_nombre" class="form-error"></div></div>
             <div class="form-group"><label class="form-label">Descripcion</label>
                <textarea class="form-textarea" id="grupoDesc">${App.escapeHtml(descripcion || '')}</textarea></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSave">Guardar</button>`);
        document.getElementById('btnSave').addEventListener('click', async () => {
            const nombre = document.getElementById('grupoNombre').value.trim();
            const descripcion = document.getElementById('grupoDesc').value;
            if (!nombre) { App.toast('Nombre requerido', 'warning'); return; }
            const existe = await App.validateDuplicate('grupo', 'nombre', nombre, id);
            if (existe) { App.toast('Ya existe un grupo con ese nombre', 'error'); return; }
            try {
                if (id) {
                    await App.request(`/grupos/${id}`, { method: 'PUT', body: { nombre, descripcion } });
                } else {
                    await App.request('/grupos', { method: 'POST', body: { nombre, descripcion } });
                }
                App.toast('Grupo guardado', 'success');
                App.closeModal();
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async validateField() {
        const value = document.getElementById('grupoNombre').value.trim();
        if (!value) return;
        const existe = await App.validateDuplicate('grupo', 'nombre', value);
        const errEl = document.getElementById('err_nombre');
        if (existe) {
            errEl.textContent = '⚠ Ya existe';
            errEl.style.color = 'var(--c-danger)';
        } else {
            errEl.textContent = '✓ Disponible';
            errEl.style.color = 'var(--c-success)';
        }
    },
    async delete(id, nombre) {
        App.confirmDelete('grupo ' + nombre, async () => {
            try {
                await App.request(`/grupos/${id}`, { method: 'DELETE' });
                App.toast('Grupo eliminado', 'success');
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.motivos = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `<div class="card-header mb-4">
            <h1 style="color:var(--c-primary)">Motivos de Contacto</h1>
            <button class="btn btn-primary" id="btnNewMotivo"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo Motivo</button>
        </div>
            <div class="card"><div id="tableContainer"><div class="loading-container"><div class="spinner"></div></div></div></div>`;
        document.getElementById('btnNewMotivo').addEventListener('click', () => this.showFormModal(null));
        await this.load();
    },
    async load() {
        try {
            const rows = await App.request('/motivos');
            this.allRows = Array.isArray(rows) ? rows : [];
            this.renderTablePage();
        } catch (e) {
            document.getElementById('tableContainer').innerHTML = App.emptyState('⚠️', 'Error', e.message);
        }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'id_motivo', label: 'ID' },
            { key: 'nombre', label: 'Motivo' },
            { key: 'descripcion', label: 'Descripción', render: r => App.escapeHtml(r.descripcion || '-') },
            { key: 'orden', label: 'Orden' },
            { key: 'activo', label: 'Estado', render: r => r.activo !== false ? App.badge('Activo', 'success') : App.badge('Inactivo', 'muted') },
            {
                key: 'acciones', label: 'Acciones', render: r => `
                <div class="btn-row">
                    <button class="btn btn-sm btn-primary" title="Editar" aria-label="Editar" onclick="App.views.motivos.showFormModal(${r.id_motivo}, '${App.escapeHtml(r.nombre)}', '${App.escapeHtml(r.descripcion || '')}', ${r.orden || 0})"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                    <button class="btn btn-sm btn-danger" title="Eliminar" aria-label="Eliminar" onclick="App.views.motivos.delete(${r.id_motivo}, '${App.escapeHtml(r.nombre)}')"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                </div>`
            },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin motivos' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Motivos de Contacto · Tickets' });
        document.getElementById('tableContainer').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
    showFormModal(id, nombre, descripcion, orden) {
        const esNuevo = id === null || id === undefined;
        App.showModal(esNuevo ? 'Nuevo Motivo de Contacto' : 'Editar Motivo de Contacto',
            `<form id="formMotivo">
                <div class="form-group">
                    <label class="form-label">Nombre del motivo <span class="req">*</span></label>
                    <input type="text" class="form-input" id="motivoNombre" required value="${App.escapeHtml(nombre || '')}" maxlength="100" placeholder="Ej: Soporte técnico">
                </div>
                <div class="form-group">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-textarea" id="motivoDescripcion" placeholder="Descripción del motivo...">${App.escapeHtml(descripcion || '')}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Orden</label>
                    <input type="number" class="form-input" id="motivoOrden" value="${orden || 0}" min="0" placeholder="0">
                    <div class="form-text">Los motivos se ordenan de menor a mayor.</div>
                </div>
            </form>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSaveMotivo"><i class="bi bi-check-lg" aria-hidden="true"></i> ${esNuevo ? 'Crear' : 'Guardar'}</button>`);
        document.getElementById('btnSaveMotivo').addEventListener('click', async () => {
            const nombre = document.getElementById('motivoNombre').value.trim();
            const descripcion = document.getElementById('motivoDescripcion').value.trim();
            const orden = parseInt(document.getElementById('motivoOrden').value) || 0;
            if (!nombre) { App.toast('El nombre es requerido', 'warning'); return; }
            try {
                if (esNuevo) {
                    // Usar el endpoint de geografía para crear
                    await App.request('/geografia/motivos', {
                        method: 'POST',
                        body: { nombre, descripcion, orden }
                    });
                    App.toast('Motivo creado', 'success');
                } else {
                    await App.request('/geografia/motivos/' + id, {
                        method: 'PUT',
                        body: { nombre, descripcion, orden }
                    });
                    App.toast('Motivo actualizado', 'success');
                }
                App.closeModal();
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
    async delete(id, nombre) {
        App.confirmDelete('motivo ' + nombre, async () => {
            try {
                await App.request('/geografia/motivos/' + id, { method: 'DELETE' });
                App.toast('Motivo eliminado', 'success');
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.configuracion = {
    async render(container) {
        const user = window.AppUser || {};
        const userCorreo = (user.correo || '').toLowerCase();
        const esAdmin = ['admin@soporte.com','admin@atenciones.local','admin@midominio.com'].includes(userCorreo);

        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Configuración del Sistema</h1>
            </div>

            <div class="card-grid">
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-info-circle" aria-hidden="true"></i> Información del Sistema</div></div>
                    <div id="sysInfoCard" style="display:grid;gap:10px;font-size:14px">
                        <div class="kv"><span class="kv__k">Aplicación:</span><span class="kv__v">Atenciones</span></div>
                        <div class="kv"><span class="kv__k">Versión:</span><span class="kv__v">2.1.0</span></div>
                        <div class="kv"><span class="kv__k">PHP:</span><span class="kv__v" id="phpVersion">Detectando...</span></div>
                        <div class="kv"><span class="kv__k">Base de datos:</span><span class="kv__v" id="dbVersion">Detectando...</span></div>
                        <div class="kv"><span class="kv__k">Servidor:</span><span class="kv__v" id="serverInfo">Detectando...</span></div>
                        <div class="kv"><span class="kv__k">Zona horaria:</span><span class="kv__v">${Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'}</span></div>
                        <div class="kv"><span class="kv__k">Hora del servidor:</span><span class="kv__v" id="serverTime">Detectando...</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-database" aria-hidden="true"></i> Estado de la Base de Datos</div></div>
                    <div id="dbStatsCard" style="display:grid;gap:8px;font-size:14px">
                        <div class="kv"><span class="kv__k">Tickets:</span><span class="kv__v" id="statTickets">—</span></div>
                        <div class="kv"><span class="kv__k">Empresas:</span><span class="kv__v" id="statEmpresas">—</span></div>
                        <div class="kv"><span class="kv__k">Personas:</span><span class="kv__v" id="statPersonas">—</span></div>
                        <div class="kv"><span class="kv__k">Atenciones:</span><span class="kv__v" id="statAtenciones">—</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-gear" aria-hidden="true"></i> Catálogos del Sistema</div></div>
                    <div style="display:grid;gap:10px">
                        <a href="#/rubros" class="btn btn-secondary" style="text-align:left"><i class="bi bi-tags" aria-hidden="true"></i> Rubros</a>
                        <a href="#/grupos" class="btn btn-secondary" style="text-align:left"><i class="bi bi-collection" aria-hidden="true"></i> Grupos Empresariales</a>
                        <a href="#/motivos" class="btn btn-secondary" style="text-align:left"><i class="bi bi-chat-left-text" aria-hidden="true"></i> Motivos de Contacto</a>
                        <a href="#/paises" class="btn btn-secondary" style="text-align:left"><i class="bi bi-globe-americas" aria-hidden="true"></i> Países y Divisiones Geográficas</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> Seguridad</div></div>
                    <div style="display:grid;gap:10px">
                        <a href="#/seguridad" class="btn btn-secondary" style="text-align:left"><i class="bi bi-people" aria-hidden="true"></i> Roles y Permisos</a>
                        <a href="#/usuarios" class="btn btn-secondary" style="text-align:left"><i class="bi bi-person-badge" aria-hidden="true"></i> Trabajadores (usuarios)</a>
                        <a href="#/auditoria" class="btn btn-secondary" style="text-align:left"><i class="bi bi-clock-history" aria-hidden="true"></i> Auditoría</a>
                        <a href="#/notificaciones" class="btn btn-secondary" style="text-align:left"><i class="bi bi-bell" aria-hidden="true"></i> Notificaciones</a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-palette" aria-hidden="true"></i> Apariencia</div></div>
                    <div style="display:grid;gap:10px;font-size:14px">
                        <div class="kv"><span class="kv__k">Tema:</span><span class="kv__v">
                            <button class="btn btn-sm" id="btnToggleTheme"><i class="bi bi-moon" aria-hidden="true"></i> Cambiar tema claro/oscuro</button>
                        </span></div>
                        <div class="kv"><span class="kv__k">Idioma:</span><span class="kv__v">Español (es-PE)</span></div>
                        <div class="kv"><span class="kv__k">Formato fecha:</span><span class="kv__v">dd/mm/yyyy HH:mm</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-bell" aria-hidden="true"></i> Notificaciones Push</div></div>
                    <div style="display:grid;gap:10px;font-size:14px">
                        <div class="kv"><span class="kv__k">Estado PWA:</span><span class="kv__v" id="pwaStatus">Verificando...</span></div>
                        <div class="kv"><span class="kv__k">Suscripción:</span><span class="kv__v" id="pwaSub">Verificando...</span></div>
                        <button class="btn btn-sm btn-primary" id="btnTestNotif"><i class="bi bi-bell" aria-hidden="true"></i> Enviar notificación de prueba</button>
                    </div>
                </div>

                ${esAdmin ? `
                <div class="card">
                    <div class="card-header"><div class="card-title"><i class="bi bi-tools" aria-hidden="true"></i> Herramientas Administrativas</div></div>
                    <div style="display:grid;gap:10px">
                        <a href="#/tickets-eliminados" class="btn btn-secondary" style="text-align:left"><i class="bi bi-trash3" aria-hidden="true"></i> Tickets Eliminados (papelera)</a>
                        <a href="#/reportes" class="btn btn-secondary" style="text-align:left"><i class="bi bi-bar-chart" aria-hidden="true"></i> Reportes y Métricas</a>
                        <button class="btn btn-secondary" id="btnClearCache" style="text-align:left"><i class="bi bi-eraser" aria-hidden="true"></i> Limpiar caché del navegador</button>
                    </div>
                </div>` : ''}
            </div>
            ${App.renderFooter({ moduleName: 'Configuración del Sistema' })}`;

        // Cargar información dinámica
        this.loadSystemInfo();
        document.getElementById('btnToggleTheme')?.addEventListener('click', () => {
            const t = document.getElementById('themeToggle');
            if (t) t.click();
        });
        document.getElementById('btnClearCache')?.addEventListener('click', () => {
            if (navigator.serviceWorker) {
                navigator.serviceWorker.getRegistrations().then(regs => {
                    regs.forEach(r => r.unregister());
                });
            }
            caches.keys().then(keys => keys.forEach(k => caches.delete(k))).then(() => {
                App.toast('Caché limpiado. Recargando...', 'success');
                setTimeout(() => location.reload(), 1000);
            });
        });
        document.getElementById('btnTestNotif')?.addEventListener('click', async () => {
            try {
                await App.request('/notificaciones/test', { method: 'POST' });
                App.toast('Notificación enviada', 'success');
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },

    async loadSystemInfo() {
        // PHP, BD y servidor (vía endpoint /dashboard/kpis con info incrustada)
        // Usamos silent:true para no mostrar toasts de error si el usuario
        // no tiene permiso DASHBOARD.ver (la card simplemente mostrará "—").
        try {
            const k = await App.request('/dashboard/kpis', { background: true, silent: true });
            const phpEl = document.getElementById('phpVersion');
            const dbEl = document.getElementById('dbVersion');
            const srvEl = document.getElementById('serverInfo');
            // Como el endpoint KPI no devuelve info técnica, mostramos la que conocemos
            if (phpEl) phpEl.textContent = '8.3+';
            if (dbEl) dbEl.textContent = 'SQL Server 2025 (MonosyncERP)';
            if (srvEl) srvEl.textContent = 'IIS / FastCGI';
            if (document.getElementById('statTickets'))   document.getElementById('statTickets').textContent   = App.formatNumber(k.tickets_total || 0);
            if (document.getElementById('statEmpresas'))  document.getElementById('statEmpresas').textContent  = App.formatNumber(k.tecnicos_activos || 0);
            if (document.getElementById('statPersonas'))  document.getElementById('statPersonas').textContent  = '—';
            if (document.getElementById('statAtenciones')) document.getElementById('statAtenciones').textContent = App.formatNumber(k.atenciones_total || 0);
        } catch (e) {
            // Si no tiene permiso DASHBOARD.ver, mostrar "—" en todos los stats
            ['statTickets','statEmpresas','statPersonas','statAtenciones'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = '—';
            });
        }

        // Hora del servidor (vía Date header del fetch)
        try {
            const resp = await fetch('/ping.php', { cache: 'no-store' });
            const dateHeader = resp.headers.get('Date');
            if (dateHeader) {
                const d = new Date(dateHeader);
                document.getElementById('serverTime').textContent = d.toLocaleString('es-PE');
            }
        } catch (e) {}

        // PWA status
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration().then(reg => {
                const statusEl = document.getElementById('pwaStatus');
                if (statusEl) statusEl.textContent = reg ? 'Instalada' : 'No instalada';
            });
        }
        if (navigator.serviceWorker) {
            const reg = await navigator.serviceWorker.getRegistration();
            const subEl = document.getElementById('pwaSub');
            if (subEl) subEl.textContent = reg ? 'Activa' : 'Inactiva';
        }
    },
};

App.views.notificaciones = {
    currentPage: 1,
    perPage: 30,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4"><h1 style="color:var(--c-primary)">Notificaciones</h1>
                <div class="card-actions">
                    <button class="btn" id="btnMarkAllRead"><i class="bi bi-check2-all" aria-hidden="true"></i> Marcar todo leído</button>
                    <button class="btn btn-primary" id="btnRefresh"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Actualizar</button>
                </div></div>
            <div class="card"><div id="notifList"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div></div>`;
        document.getElementById('btnRefresh').addEventListener('click', () => this.render(container));
        document.getElementById('btnMarkAllRead').addEventListener('click', () => this.markAllRead());
        await this.load();
    },
    async load() {
        try {
            const list = await App.request('/notificaciones?limit=200');
            this.allRows = Array.isArray(list) ? list : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('notifList').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    renderTablePage() {
        const container = document.getElementById('notifList');
        if (!this.allRows.length) {
            container.innerHTML = App.emptyState('🔔', 'Sin notificaciones', 'No tienes notificaciones en este momento');
            return;
        }
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const listHtml = pageData.rows.map(n => `
            <div class="notif-item${n.leida == 0 ? ' notif-item--unread' : ''}">
                <div class="notif-item__main">
                    <div class="notif-item__head">
                        <strong>${App.escapeHtml(n.titulo)}</strong>
                        ${n.leida == 0 ? '<span class="badge badge-info">Nueva</span>' : ''}
                    </div>
                    <p class="text-sm text-muted">${App.escapeHtml(n.mensaje)}</p>
                    <p class="text-xs text-muted mt-2"><i class="bi bi-clock" aria-hidden="true"></i> ${App.formatDateTime(n.fecha)}</p>
                </div>
                ${n.leida == 0 ? `<button class="btn btn-sm" title="Marcar leída" aria-label="Marcar leída" onclick="App.views.notificaciones.markRead(${n.id_notificacion_usuario})"><i class="bi bi-check2" aria-hidden="true"></i></button>` : ''}
            </div>`).join('');
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Notificaciones · Sistema' });
        container.innerHTML = listHtml + paginationHtml + footerHtml;
    },
    async markRead(id) {
        try {
            await App.request(`/notificaciones/${id}/read`, { method: 'POST' });
            this.load();
            App.state.notificationsBadge = Math.max(0, App.state.notificationsBadge - 1);
            App.updateNotificationsBadge();
        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
    },
    async markAllRead() {
        try {
            const list = await App.request('/notificaciones?limit=50');
            for (const n of list) {
                if (n.leida == 0) await App.request(`/notificaciones/${n.id_notificacion_usuario}/read`, { method: 'POST' });
            }
            App.toast('Todas marcadas como leidas', 'success');
            this.load();
            App.state.notificationsBadge = 0;
            App.updateNotificationsBadge();
        } catch (e) { App.toast('Error: ' + e.message, 'error'); }
    },
};

App.views.auditoria = {
    currentFilters: {},
    currentPage: 1,
    perPage: 100,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4"><h1 style="color:var(--c-primary)">Auditoría</h1>
                <button class="btn" id="btnSearch"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button></div>
            <div class="card"><div id="auditTable"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div></div>`;
        document.getElementById('btnSearch').addEventListener('click', () => {
            App.showSearchModal('Buscar en Auditoría', [
                { name: 'tabla', label: 'Tabla', placeholder: 'ticket, persona...' },
                {
                    name: 'operacion', label: 'Operación', type: 'select', options: [
                        { value: 'INSERT', label: 'Insert' },
                        { value: 'UPDATE', label: 'Update' },
                        { value: 'DELETE', label: 'Delete' },
                    ]
                },
            ], (filters) => { this.currentFilters = filters; this.currentPage = 1; this.load(); });
        });
        await this.load();
    },
    async load() {
        const params = new URLSearchParams();
        if (this.currentFilters.tabla) params.set('tabla', this.currentFilters.tabla);
        if (this.currentFilters.operacion) params.set('operacion', this.currentFilters.operacion);
        params.set('limite', '500');
        try {
            const list = await App.request('/auditoria/cambios?' + params.toString());
            this.allRows = Array.isArray(list) ? list : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('auditTable').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'fecha_hora', label: 'Fecha', render: r => App.formatDateTime(r.fecha_hora) },
            { key: 'tabla', label: 'Tabla' },
            { key: 'operacion', label: 'Op', render: r => App.badge(r.operacion, r.operacion === 'DELETE' ? 'danger' : r.operacion === 'INSERT' ? 'success' : 'info') },
            { key: 'pk_tabla', label: 'Registro' },
            { key: 'campo', label: 'Campo' },
            { key: 'usuario_nombre', label: 'Usuario' },
            { key: 'ip_origen', label: 'IP' },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin registros' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); document.getElementById('auditTable').scrollIntoView({ behavior:'smooth', block:'start' }); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Auditoría · Sistema' });
        document.getElementById('auditTable').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
};

App.views.exportaciones = {
    currentPage: 1,
    perPage: 50,
    allRows: [],
    async render(container) {
        this.currentPage = 1;
        container.innerHTML = `
            <div class="card-header mb-4"><h1 style="color:var(--c-primary)">Exportaciones</h1>
                <button class="btn btn-primary" id="btnNewExport"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva exportación</button></div>
            <div class="card"><div id="exportsList"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div></div>`;
        document.getElementById('btnNewExport').addEventListener('click', () => this.showNewModal());
        await this.load();
    },
    async load() {
        try {
            const list = await App.request('/exportaciones');
            this.allRows = Array.isArray(list) ? list : [];
            this.renderTablePage();
        } catch (e) { document.getElementById('exportsList').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
    renderTablePage() {
        const pageData = App.paginate(this.allRows, this.currentPage, this.perPage);
        const columns = [
            { key: 'id_job', label: 'ID' },
            { key: 'modulo', label: 'Módulo' },
            { key: 'formato', label: 'Formato' },
            { key: 'estado', label: 'Estado', render: r => App.badge(r.estado, r.estado === 'COMPLETADO' ? 'success' : r.estado === 'FALLIDO' ? 'danger' : 'warning') },
            { key: 'total_registros', label: 'Registros' },
            { key: 'fecha_solicitud', label: 'Solicitado', render: r => App.formatDateTime(r.fecha_solicitud) },
            { key: 'acciones', label: 'Acciones', render: r => r.estado === 'COMPLETADO' && r.ruta_archivo ? `<a class="btn btn-sm btn-primary" title="Descargar" aria-label="Descargar" href="/api/v1/exportaciones/${r.id_job}/download"><i class="bi bi-download" aria-hidden="true"></i> Descargar</a>` : '-' },
        ];
        const tableHtml = App.table({ columns, rows: pageData.rows, empty: 'Sin exportaciones' });
        const paginationHtml = App.renderPagination({
            page: pageData.page, totalPages: pageData.totalPages, total: pageData.total, perPage: pageData.perPage,
            onChange: (newPage) => { this.currentPage = newPage; this.renderTablePage(); },
        });
        const footerHtml = App.renderFooter({ moduleName: 'Exportaciones · Reportes' });
        document.getElementById('exportsList').innerHTML = tableHtml + paginationHtml + footerHtml;
    },
    showNewModal() {
        App.showModal('Nueva Exportacion',
            `<div class="form-group"><label class="form-label">Modulo</label>
                <select class="form-select" id="expModulo">
                    <option value="TICKETS">Tickets</option>
                    <option value="ATENCIONES">Atenciones</option>
                    <option value="AUDITORIA">Auditoria</option>
                </select></div>
             <div class="form-group"><label class="form-label">Formato</label>
                <select class="form-select" id="expFormato">
                    <option value="XLSX">Excel (.xlsx)</option>
                    <option value="CSV">CSV (.csv)</option>
                    <option value="JSON">JSON (.json)</option>
                </select></div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnCreateExport">Crear</button>`);
        document.getElementById('btnCreateExport').addEventListener('click', async () => {
            try {
                await App.request('/exportaciones', {
                    method: 'POST', body: {
                        modulo: document.getElementById('expModulo').value,
                        formato: document.getElementById('expFormato').value,
                    }
                });
                App.toast('Exportacion encolada', 'success');
                App.closeModal();
                this.load();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.encuestas = {
    async render(container) {
        container.innerHTML = `
            <div class="card-header mb-4"><h1 style="color:var(--c-primary)">Encuestas de Satisfaccion</h1></div>
            <div class="card"><div class="card-header"><div class="card-title">Reporte</div></div>
                <div id="encuestaReporte"><div class="loading-container"><div class="spinner"></div><p>Cargando...</p></div></div></div>`;
        try {
            const list = await App.request('/encuestas/reporte');
            if (!list || list.length === 0) {
                document.getElementById('encuestaReporte').innerHTML = App.emptyState('📝', 'Sin datos', 'Aun no hay respuestas de encuestas'); return;
            }
            const html = list.map(r => `
                <div style="padding:12px;border-bottom:1px solid var(--c-border)">
                    <div style="font-weight:600;margin-bottom:4px">${App.escapeHtml(r.pregunta)}</div>
                    <div style="display:flex;gap:16px;font-size:14px">
                        <span>Promedio: <strong>${App.formatNumber(r.promedio, 2)}</strong></span>
                        <span>Respuestas: <strong>${r.respuestas}</strong></span>
                    </div>
                </div>`).join('');
            document.getElementById('encuestaReporte').innerHTML = html;
        } catch (e) { document.getElementById('encuestaReporte').innerHTML = App.emptyState('⚠️', 'Error', e.message); }
    },
};

App.views.perfil = {
    async render(container) {
        const user = window.AppUser || {};
        container.innerHTML = `
            <div class="card-header mb-4">
                <h1 style="color:var(--c-primary)">Mi Perfil</h1>
                <div class="card-actions">
                    <button class="btn btn-primary" id="btnEditPerfil"><i class="bi bi-pencil" aria-hidden="true"></i> Editar Datos</button>
                    <button class="btn btn-secondary" id="btnChangePass"><i class="bi bi-key" aria-hidden="true"></i> Cambiar Contraseña</button>
                </div>
            </div>
            <div id="perfilContainer"><div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando datos...</p></div></div>
        `;
        document.getElementById('btnEditPerfil').addEventListener('click', () => this.showEditModal());
        document.getElementById('btnChangePass').addEventListener('click', () => this.showChangePassModal());
        await this.load();
    },

    async load() {
        const cont = document.getElementById('perfilContainer');
        try {
            // Intentar cargar datos frescos del backend; si falla, usar window.AppUser
            let me = window.AppUser || {};
            try {
                const fresh = await App.request('/auth/me', { background: true, silent: true });
                if (fresh) me = { ...me, ...fresh };
            } catch (e) { /* fallback al embebido en window.AppUser */ }

            const userCorreo = (me.correo || '').toLowerCase();
            const esAdmin = ['admin@soporte.com','admin@atenciones.local','admin@midominio.com'].includes(userCorreo)
                         || ['ADMINISTRADOR','ADMIN'].includes((me.rol || '').toUpperCase());
            const iniciales = ((me.nombres || '').charAt(0) + (me.apellido_paterno || '').charAt(0)).toUpperCase() || '?';

            cont.innerHTML = `
                <div class="card-grid">
                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-person-circle" aria-hidden="true"></i> Identidad</div></div>
                        <div style="text-align:center;padding:20px 0">
                            <div style="width:96px;height:96px;border-radius:50%;background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:700;margin:0 auto 12px">${App.escapeHtml(iniciales)}</div>
                            <h2 style="margin:0;color:var(--c-primary)">${App.escapeHtml(me.nombres || '')} ${App.escapeHtml(me.apellido_paterno || '')} ${App.escapeHtml(me.apellido_materno || '')}</h2>
                            <p style="margin:4px 0 0;color:#6b7280">${App.escapeHtml(me.correo || '')}</p>
                            <div style="margin-top:8px">
                                <span class="badge ${esAdmin ? 'badge-success' : 'badge-info'}" title="Rol del usuario">
                                    <i class="bi bi-shield-check" aria-hidden="true"></i> ${App.escapeHtml(me.rol || 'Sin rol asignado')}
                                </span>
                                ${esAdmin ? '<span class="badge badge-warning" style="margin-left:4px">Administrador</span>' : ''}
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-card-list" aria-hidden="true"></i> Datos Personales</div></div>
                        <div style="display:grid;gap:10px;font-size:14px">
                            <div class="kv"><span class="kv__k">ID:</span><span class="kv__v">#${App.escapeHtml(me.id_persona || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Documento:</span><span class="kv__v">${App.escapeHtml(me.tipo_documento || '')} ${App.escapeHtml(me.numero_documento || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Nombres:</span><span class="kv__v">${App.escapeHtml(me.nombres || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Apellido paterno:</span><span class="kv__v">${App.escapeHtml(me.apellido_paterno || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Apellido materno:</span><span class="kv__v">${App.escapeHtml(me.apellido_materno || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Correo:</span><span class="kv__v">${App.escapeHtml(me.correo || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Teléfono:</span><span class="kv__v">${App.escapeHtml(me.telefono || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Sexo:</span><span class="kv__v">${me.sexo === 'M' ? 'Masculino' : me.sexo === 'F' ? 'Femenino' : '—'}</span></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-briefcase" aria-hidden="true"></i> Asignación Laboral</div></div>
                        <div style="display:grid;gap:10px;font-size:14px">
                            <div class="kv"><span class="kv__k">Rol:</span><span class="kv__v"><span class="badge badge-info">${App.escapeHtml(me.rol || 'Sin asignar')}</span></span></div>
                            <div class="kv"><span class="kv__k">Empresa:</span><span class="kv__v">${App.escapeHtml(me.empresa_nombre || 'Sin empresa asignada')}</span></div>
                            <div class="kv"><span class="kv__k">Sucursal:</span><span class="kv__v">${App.escapeHtml(me.sucursal_nombre || '—')}</span></div>
                            <div class="kv"><span class="kv__k">Cargo:</span><span class="kv__v">${App.escapeHtml(me.cargo || '—')}</span></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Actividad Reciente</div></div>
                        <div id="perfilActividad" style="display:grid;gap:8px;font-size:13px">
                            <p class="text-muted">Cargando actividad...</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-shield-check" aria-hidden="true"></i> Permisos del Rol</div></div>
                        <div id="perfilPermisos" style="display:grid;gap:6px;font-size:13px">
                            <p class="text-muted">Cargando permisos...</p>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><div class="card-title"><i class="bi bi-gear" aria-hidden="true"></i> Acciones</div></div>
                        <div style="display:grid;gap:8px">
                            <button class="btn btn-secondary" onclick="App.navigate('/configuracion')"><i class="bi bi-gear" aria-hidden="true"></i> Ir a Configuración</button>
                            <button class="btn btn-secondary" onclick="App.navigate('/notificaciones')"><i class="bi bi-bell" aria-hidden="true"></i> Ver Notificaciones</button>
                            <a href="/logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
                ${App.renderFooter({ moduleName: `Perfil de ${me.nombres || 'usuario'}` })}
            `;

            // Cargar permisos del usuario
            this.loadPermisos();
            // Cargar actividad reciente (tickets asignados, atenciones)
            this.loadActividad();
        } catch (e) {
            cont.innerHTML = App.emptyState('⚠️', 'Error al cargar perfil', e.message);
        }
    },

    async loadPermisos() {
        const cont = document.getElementById('perfilPermisos');
        if (!cont) return;
        try {
            // Si App.config.permissions ya está cargado al iniciar la app,
            // usarlo directamente (evita otra llamada al backend).
            const user = window.AppUser || {};
            const userCorreo = (user.correo || '').toLowerCase();
            const esSuperAdmin = ['admin@soporte.com','admin@atenciones.local','admin@midominio.com'].includes(userCorreo)
                              || App.config.isSuperAdmin === true;

            if (esSuperAdmin) {
                cont.innerHTML = `
                    <div class="alert alert-warning" style="margin:0">
                        <i class="bi bi-star-fill" aria-hidden="true"></i>
                        <strong>Super Administrador</strong> — Tiene bypass total de permisos.
                        Puede acceder a todos los módulos sin restricciones.
                    </div>`;
                return;
            }

            // Usar los permisos que ya se cargaron al iniciar (en App.config.permissions)
            const permisos = App.config.permissions || {};
            const modulos = App.config.modulos || [];
            const acciones = [
                { key: 'ver',        label: 'Ver',        icon: 'bi-eye' },
                { key: 'registrar',  label: 'Crear',      icon: 'bi-plus-lg' },
                { key: 'modificar',  label: 'Editar',     icon: 'bi-pencil' },
                { key: 'eliminar',   label: 'Eliminar',   icon: 'bi-trash3' },
                { key: 'aprobar',    label: 'Aprobar',    icon: 'bi-check2-circle' },
                { key: 'exportar',   label: 'Exportar',   icon: 'bi-download' },
                { key: 'imprimir',   label: 'Imprimir',   icon: 'bi-printer' },
            ];

            // Si no hay permisos cargados, mostrar mensaje
            if (Object.keys(permisos).length === 0) {
                cont.innerHTML = '<p class="text-muted">No tiene permisos asignados. Contacte al administrador.</p>';
                return;
            }

            // Construir lista a partir de los permisos cargados
            const modulosNombres = {
                'DASHBOARD': 'Dashboard',
                'TICKETS': 'Tickets',
                'EMPRESAS': 'Empresas',
                'DIRECTORIOS': 'Directorios (Rubros, Grupos, Motivos)',
                'GEOGRAFIA': 'Geografía',
                'USUARIOS': 'Trabajadores',
                'SEGURIDAD': 'Roles y Permisos',
                'NOTIFICACIONES': 'Notificaciones',
                'AUDITORIA': 'Auditoría',
                'REPORTES': 'Reportes',
                'EXPORTACIONES': 'Exportaciones',
                'ENCUESTAS': 'Encuestas',
            };
            const html = Object.keys(permisos).map(codigo => {
                const p = permisos[codigo];
                const activos = acciones.filter(a => Number(p[a.key] || 0) === 1);
                if (activos.length === 0) return '';
                const nombre = modulosNombres[codigo] || codigo;
                return `<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--c-border)">
                    <span><i class="bi bi-folder" aria-hidden="true"></i> ${App.escapeHtml(nombre)}</span>
                    <span style="display:flex;gap:4px;flex-wrap:wrap">
                        ${activos.map(a => `<span class="badge badge-info" title="${a.label}"><i class="bi ${a.icon}" aria-hidden="true"></i></span>`).join('')}
                    </span>
                </div>`;
            }).join('');
            cont.innerHTML = html || '<p class="text-muted">No tiene permisos asignados.</p>';
        } catch (e) {
            const cont2 = document.getElementById('perfilPermisos');
            if (cont2) cont2.innerHTML = `<p class="text-muted">No se pudieron cargar los permisos: ${App.escapeHtml(e.message)}</p>`;
        }
    },

    async loadActividad() {
        const cont = document.getElementById('perfilActividad');
        if (!cont) return;
        // Si el usuario no tiene permiso TICKETS.ver, no intentar cargar
        if (!App.can('TICKETS', 'ver')) {
            cont.innerHTML = '<p class="text-muted">No tiene permiso para ver tickets.</p>';
            return;
        }
        try {
            const user = window.AppUser || {};
            // Cargar tickets donde el usuario es el técnico asignado (últimos 5)
            const data = await App.request(`/tickets?id_usuario=${user.id_persona}&per_page=5`, { background: true, silent: true });
            const rows = Array.isArray(data) ? data : (data.data || []);
            if (rows.length === 0) {
                cont.innerHTML = '<p class="text-muted">No tienes tickets asignados recientemente.</p>';
                return;
            }
            const html = rows.map(r => `
                <div style="padding:8px 0;border-bottom:1px solid var(--c-border);cursor:pointer" onclick="App.navigate('/tickets/${r.id_ticket}')">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <strong>${App.escapeHtml(r.nro_ticket)}</strong>
                            <div class="text-muted text-sm">${App.escapeHtml(r.empresa_razon_social || '')}</div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px">
                            ${App.badge(r.estado, r.estado.toLowerCase())}
                            <span class="text-muted text-sm">${App.formatDate(r.fecha_registro)}</span>
                        </div>
                    </div>
                </div>
            `).join('');
            cont.innerHTML = html;
        } catch (e) {
            const cont2 = document.getElementById('perfilActividad');
            if (cont2) cont2.innerHTML = `<p class="text-muted">No se pudo cargar la actividad: ${App.escapeHtml(e.message)}</p>`;
        }
    },

    showEditModal() {
        const me = window.AppUser || {};
        App.showModal('Editar Mis Datos',
            `<div class="form-row">
                <div class="form-group"><label class="form-label">Nombres <span class="req">*</span></label>
                    <input type="text" class="form-input" id="perfilNombres" value="${App.escapeHtml(me.nombres || '')}"></div>
                <div class="form-group"><label class="form-label">Apellido paterno <span class="req">*</span></label>
                    <input type="text" class="form-input" id="perfilApPaterno" value="${App.escapeHtml(me.apellido_paterno || '')}"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Apellido materno</label>
                    <input type="text" class="form-input" id="perfilApMaterno" value="${App.escapeHtml(me.apellido_materno || '')}"></div>
                <div class="form-group"><label class="form-label">Teléfono</label>
                    <input type="text" class="form-input" id="perfilTelefono" value="${App.escapeHtml(me.telefono || '')}"></div>
            </div>
            <div class="form-group"><label class="form-label">Correo (no editable)</label>
                <input type="email" class="form-input" value="${App.escapeHtml(me.correo || '')}" disabled></div>
            <div class="alert alert-info"><i class="bi bi-info-circle" aria-hidden="true"></i> Los cambios se aplican a tu perfil y se reflejan en todos los módulos donde aparezcas (tickets, atenciones, auditoría).</div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSavePerfil"><i class="bi bi-save" aria-hidden="true"></i> Guardar Cambios</button>`);
        document.getElementById('btnSavePerfil').addEventListener('click', async () => {
            const body = {
                nombres: document.getElementById('perfilNombres').value.trim(),
                apellido_paterno: document.getElementById('perfilApPaterno').value.trim(),
                apellido_materno: document.getElementById('perfilApMaterno').value.trim(),
                telefono: document.getElementById('perfilTelefono').value.trim(),
            };
            if (!body.nombres || !body.apellido_paterno) {
                App.toast('Nombres y apellido paterno son obligatorios', 'warning');
                return;
            }
            try {
                // Reusar el endpoint de edición de personas existente
                const me = window.AppUser;
                await App.request(`/personas/${me.id_persona}`, { method: 'PUT', body });
                App.toast('Datos actualizados', 'success');
                App.closeModal();
                // Actualizar window.AppUser en memoria
                window.AppUser = { ...me, ...body };
                this.load();
            } catch (e) {
                App.toast('Error al actualizar: ' + e.message, 'error');
            }
        });
    },

    showChangePassModal() {
        App.showModal('Cambiar Contraseña',
            `<div class="form-group"><label class="form-label">Contraseña actual <span class="req">*</span></label>
                <input type="password" class="form-input" id="passActual" required autocomplete="current-password"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Nueva contraseña <span class="req">*</span></label>
                    <input type="password" class="form-input" id="passNueva" required autocomplete="new-password"></div>
                <div class="form-group"><label class="form-label">Confirmar <span class="req">*</span></label>
                    <input type="password" class="form-input" id="passConfirm" required autocomplete="new-password"></div>
            </div>
            <div class="alert alert-info"><i class="bi bi-info-circle" aria-hidden="true"></i> Use al menos 8 caracteres, combinando mayúsculas, minúsculas, números y símbolos.</div>`,
            `<button class="btn" onclick="App.closeModal()">Cancelar</button>
             <button class="btn btn-primary" id="btnSavePass"><i class="bi bi-key" aria-hidden="true"></i> Cambiar</button>`);
        document.getElementById('btnSavePass').addEventListener('click', async () => {
            const actual = document.getElementById('passActual').value;
            const nueva = document.getElementById('passNueva').value;
            const confirm = document.getElementById('passConfirm').value;
            if (!actual || !nueva || !confirm) { App.toast('Complete todos los campos', 'warning'); return; }
            if (nueva !== confirm) { App.toast('Las contraseñas nuevas no coinciden', 'warning'); return; }
            if (nueva.length < 8) { App.toast('La contraseña debe tener al menos 8 caracteres', 'warning'); return; }
            try {
                await App.request('/auth/password', { method: 'POST', body: { actual, nueva } });
                App.toast('Contraseña actualizada', 'success');
                App.closeModal();
            } catch (e) { App.toast('Error: ' + e.message, 'error'); }
        });
    },
};

App.views.notFound = {
    render(container, params) {
        const path = params.join('/');
        container.innerHTML = `<div class="card">${App.emptyState('🔍', 'Pagina no encontrada',
            `La ruta <code>${App.escapeHtml(path)}</code> no existe.`,
            '<button class="btn btn-primary" onclick="App.navigate(\'/dashboard\')">Ir al Dashboard</button>')}</div>`;
    },
};

/* ============================================================================
 * HELPER: App.showEditAtencionModal
 *
 * Modal unificado para CREAR (atencion=null) o EDITAR (atencion=objeto)
 * una atención. Llama a POST /atenciones o PUT /atenciones/{id}.
 *
 * Parámetros:
 *   ticket     — objeto ticket (de renderDetail)
 *   atencion   — objeto atención (null para crear nueva)
 *   onSuccess  — callback a ejecutar tras guardar exitosamente
 * ========================================================================== */
App.showEditAtencionModal = function(ticket, atencion, onSuccess) {
    const isNew = !atencion;
    const title = isNew
        ? `Nueva atención - ${ticket.nro_ticket}`
        : `Editar atención #${atencion.id_atencion} - ${ticket.nro_ticket}`;

    const a = atencion || {};

    App.showModal(title,
        `<form id="formAtencion">
            <div class="alert alert-info">
                <i class="bi bi-clock-history"></i>
                ${isNew
                    ? 'La fecha y hora de inicio se registrarán automáticamente con la hora del servidor.'
                    : 'Si cambia el estado a <strong>Completado</strong>, se recalculará la duración automáticamente.'}
            </div>

            <div class="form-group">
                <label class="form-label">Detalle del trabajo <span class="req">*</span></label>
                <textarea class="form-textarea" id="detalleAtencion" required
                    placeholder="Describa el trabajo realizado...">${App.escapeHtml(a.detalle_atencion || '')}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Diagnóstico</label>
                <textarea class="form-textarea" id="diagnosticoAtencion"
                    placeholder="Análisis técnico del problema...">${App.escapeHtml(a.diagnostico || '')}</textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Acciones ejecutadas</label>
                <textarea class="form-textarea" id="accionesAtencion"
                    placeholder="Pasos realizados durante la intervención...">${App.escapeHtml(a.acciones_ejecutadas || '')}</textarea>
            </div>

            ${a.solucion_aplicada || !isNew ? `
            <div class="form-group">
                <label class="form-label">Solución aplicada</label>
                <textarea class="form-textarea" id="solucionAtencion"
                    placeholder="Solución técnica aplicada...">${App.escapeHtml(a.solucion_aplicada || '')}</textarea>
            </div>` : ''}

            <div class="form-group">
                <label class="form-label">Resultado</label>
                <input type="text" class="form-input" id="resultadoAtencion"
                    value="${App.escapeHtml(a.resultado || '')}"
                    placeholder="Resultado final comprobado..." maxlength="500">
            </div>

            <div class="form-group">
                <label class="form-label">Estado de la atención</label>
                <select class="form-select" id="estadoAtencion">
                    <option value="EN_PROCESO"  ${a.estado === 'EN_PROCESO' ? 'selected' : ''}>En Proceso</option>
                    <option value="COMPLETADO"  ${a.estado === 'COMPLETADO' ? 'selected' : ''}>Completado</option>
                    <option value="PAUSADO"     ${a.estado === 'PAUSADO' ? 'selected' : ''}>Pausado</option>
                </select>
                <div class="alert alert-info" id="alertCompletado" style="display:none;margin-top:8px">
                    <i class="bi bi-info-circle"></i>
                    Al guardar con estado <strong>Completado</strong>, se calculará la duración
                    ${isNew ? 'y el ticket pasará a <strong>EN ATENCIÓN</strong>' : ''}.
                </div>
            </div>
        </form>`,
        `<button class="btn" onclick="App.closeModal()">Cancelar</button>
         <button class="btn btn-primary" id="btnSaveAtencion">
             <i class="bi bi-check-lg"></i> ${isNew ? 'Guardar' : 'Actualizar'}
         </button>`);

    // Mostrar/ocultar alerta informativa al cambiar estado
    const estadoSel = document.getElementById('estadoAtencion');
    const alerta = document.getElementById('alertCompletado');
    estadoSel.addEventListener('change', (e) => {
        alerta.style.display = e.target.value === 'COMPLETADO' ? 'block' : 'none';
    });
    alerta.style.display = estadoSel.value === 'COMPLETADO' ? 'block' : 'none';

    // Handler del botón Guardar
    document.getElementById('btnSaveAtencion').addEventListener('click', async () => {
        const detalle = document.getElementById('detalleAtencion').value.trim();
        const estado = document.getElementById('estadoAtencion').value;
        if (!detalle) {
            App.toast('El detalle del trabajo es requerido', 'warning');
            return;
        }

        try {
            const body = {
                detalle_atencion: detalle,
                diagnostico: document.getElementById('diagnosticoAtencion').value || null,
                acciones_ejecutadas: document.getElementById('accionesAtencion').value || null,
                resultado: document.getElementById('resultadoAtencion').value || null,
                estado: estado,
            };

            // Solución solo si el campo existe en el modal
            const solInput = document.getElementById('solucionAtencion');
            if (solInput) body.solucion_aplicada = solInput.value || null;

            let result;
            if (isNew) {
                // POST /atenciones (crear nueva)
                body.id_ticket = ticket.id_ticket;
                body.fecha_inicio = null;
                body.hora_inicio  = null;
                body.fecha_fin    = null;
                body.hora_fin     = null;
                result = await App.request('/atenciones', { method: 'POST', body });
            } else {
                // PUT /atenciones/{id} (editar)
                result = await App.request(`/atenciones/${atencion.id_atencion}`, {
                    method: 'PUT', body
                });
            }

            App.closeModal();
            App.toast(isNew ? 'Atención registrada' : 'Atención actualizada', 'success');

            // Si es nueva y COMPLETADO, abrir modal de solución
            if (isNew && estado === 'COMPLETADO' && result.id_atencion) {
                setTimeout(() => {
                    App.views.tickets.showSolucionarModal(ticket, result.id_atencion);
                }, 200);
            } else if (onSuccess) {
                onSuccess();
            }
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    });
};

/* ============================================================================
 * MÓDULO DE COTIZACIONES COMERCIALES
 * ----------------------------------------------------------------------------
 * Vistas SPA para el módulo de cotizaciones.
 *   - App.views.cotizaciones           (listado, detalle, form, comunicaciones)
 *   - App.views.cotizaciones_productos (catálogo de productos/servicios)
 *
 * Reutiliza todos los helpers de App (request, toast, modal, escapeHtml,
 * applyPermissionsToDynamic).
 *
 * Estados: BORRADOR, PENDIENTE_ENVIO, ENVIADA, EN_SEGUIMIENTO,
 *          EN_NEGOCIACION, APROBADA, RECHAZADA, CANCELADA, CERRADA
 * ============================================================================
 */

App.views.cotizaciones = {
    state: {
        currentPage: 1,
        perPage: 25,
        filters: {},
        catalogos: null,  // cache en memoria de catálogos
        productosCache: null,
    },

    /* ====== RENDER PRINCIPAL — gestiona la subvista según hash ====== */
    async render(container, params) {
        const subview = params[0] || 'list';
        if (subview === 'list')    return this.renderList(container);
        if (subview === 'detalle') return this.renderDetail(container, parseInt(params[1] || 0));
        if (subview === 'nuevo')   return this.renderForm(container, null);
        if (subview === 'editar')  return this.renderForm(container, parseInt(params[1] || 0));
        return this.renderList(container);
    },

    /* ====== LISTADO ====== */
    async renderList(container) {
        container.innerHTML = `
            <div class="view-header">
                <h2><i class="bi bi-file-earmark-text"></i> Cotizaciones</h2>
                <div class="view-actions">
                    <button class="btn btn-primary" data-requires-permission="COTIZACIONES:registrar"
                            onclick="App.navigate('/cotizaciones/nuevo')">
                        <i class="bi bi-plus-lg"></i> Nueva Cotización
                    </button>
                </div>
            </div>

            <div class="card" style="margin-bottom: 12px;">
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Buscar</label>
                            <input type="text" id="f-search" class="form-input" placeholder="Código o empresa..." oninput="App.views.cotizaciones.applyFiltersDebounced()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estado</label>
                            <select id="f-estado" class="form-select" onchange="App.views.cotizaciones.applyFilters()">
                                <option value="">Todos los estados</option>
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
                        <div class="form-group">
                            <label class="form-label">Año</label>
                            <select id="f-anio" class="form-select" onchange="App.views.cotizaciones.applyFilters()">
                                <option value="">Todos los años</option>
                                <option value="2024">2024</option>
                                <option value="2025">2025</option>
                                <option value="2026">2026</option>
                                <option value="2027">2027</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Desde</label>
                            <input type="date" id="f-desde" class="form-input" onchange="App.views.cotizaciones.applyFilters()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Hasta</label>
                            <input type="date" id="f-hasta" class="form-input" onchange="App.views.cotizaciones.applyFilters()">
                        </div>
                        <div class="form-group" style="display:flex;align-items:flex-end;gap:4px;">
                            <button class="btn btn-secondary" onclick="App.views.cotizaciones.applyFilters()">
                                <i class="bi bi-funnel"></i> Filtrar
                            </button>
                            <button class="btn btn-link" onclick="App.views.cotizaciones.clearFilters()">
                                Limpiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="cotizaciones-table-wrapper" class="card">
                <div class="card-body">
                    <div class="table-loading">Cargando cotizaciones...</div>
                </div>
            </div>

            <div id="cotizaciones-pagination-wrapper"></div>
        `;
        App.applyPermissionsToDynamic(container);
        await this.loadList();
    },

    async applyFilters() {
        this.state.currentPage = 1;
        this.state.filters = {
            search:    document.getElementById('f-search')?.value || '',
            estado:    document.getElementById('f-estado')?.value || '',
            anio:      document.getElementById('f-anio')?.value || '',
            fecha_desde: document.getElementById('f-desde')?.value || '',
            fecha_hasta: document.getElementById('f-hasta')?.value || '',
        };
        await this.loadList();
    },

    // Debounced version para no sobrecargar el backend al escribir
    applyFiltersDebounced() {
        clearTimeout(this._filtersTimer);
        this._filtersTimer = setTimeout(() => this.applyFilters(), 300);
    },

    async clearFilters() {
        document.getElementById('f-search').value = '';
        document.getElementById('f-estado').value = '';
        document.getElementById('f-anio').value = '';
        document.getElementById('f-desde').value = '';
        document.getElementById('f-hasta').value = '';
        this.state.filters = {};
        this.state.currentPage = 1;
        await this.loadList();
    },

    async loadList() {
        const wrapper = document.getElementById('cotizaciones-table-wrapper');
        if (!wrapper) return;

        try {
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: this.state.perPage,
                ...this.state.filters,
            });
            const resp = await App.request('/cotizaciones?' + params.toString(), { background: true });
            if (!resp) {
                // Sesión expirada en background: App.request devolvió null/undefined.
                wrapper.innerHTML = '<div class="card-body text-center text-muted">Sesión expirada. <a href="/login.php">Inicia sesión de nuevo</a>.</div>';
                return;
            }
            // IMPORTANTE: App.request desempaqueta j.data (línea 556 de app.js).
            // Cuando el backend usa ApiResponse::paginated(), el JSON es:
            //   { ok:true, data:[...filas...], pagination:{total,...} }
            // App.request retorna j.data (el array de filas), por lo que aquí
            // `resp` ya ES el array. Acceder a resp.data / resp.pagination era
            // un bug que hacía que el listado quedara SIEMPRE vacío.
            const rows = Array.isArray(resp) ? resp : (resp.data || resp.items || []);
            const total = (resp && resp.pagination && typeof resp.pagination.total === 'number')
                ? resp.pagination.total
                : rows.length;

            if (rows.length === 0) {
                wrapper.innerHTML = `
                    <div class="card-body text-center">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #aaa;"></i>
                        <p style="margin-top: 10px;">No hay cotizaciones que coincidan con los filtros.</p>
                        <p style="margin-top: 10px; font-size: 12px;">
                            <a href="/api/v1/cotizaciones/diagnostico" target="_blank" class="btn btn-link">
                                <i class="bi bi-stethoscope"></i> Ejecutar diagnóstico del módulo
                            </a>
                        </p>
                    </div>`;
                document.getElementById('cotizaciones-pagination-wrapper').innerHTML = '';
                return;
            }

            const estadoClass = (e) => ({
                BORRADOR: 'badge-secondary',
                PENDIENTE_ENVIO: 'badge-info',
                ENVIADA: 'badge-primary',
                EN_SEGUIMIENTO: 'badge-warning',
                EN_NEGOCIACION: 'badge-warning',
                APROBADA: 'badge-success',
                RECHAZADA: 'badge-danger',
                CANCELADA: 'badge-danger',
                CERRADA: 'badge-secondary',
            })[e] || 'badge-secondary';

            const estadoLabel = (e) => ({
                BORRADOR: 'Borrador',
                PENDIENTE_ENVIO: 'Pendiente envío',
                ENVIADA: 'Enviada',
                EN_SEGUIMIENTO: 'En seguimiento',
                EN_NEGOCIACION: 'En negociación',
                APROBADA: 'Aprobada',
                RECHAZADA: 'Rechazada',
                CANCELADA: 'Cancelada',
                CERRADA: 'Cerrada',
            })[e] || e;

            wrapper.innerHTML = `
                <div class="card-body">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Fecha</th>
                                <th>Empresa</th>
                                <th>Tipo</th>
                                <th>Vendedor</th>
                                <th class="text-right">Total</th>
                                <th>Estado</th>
                                <th>Próx. seg.</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(r => `
                                <tr>
                                    <td><strong>${App.escapeHtml(r.codigo)}</strong>${r.version_numero > 1 ? `<span class="badge badge-info" style="margin-left:4px;">v${r.version_numero}</span>` : ''}</td>
                                    <td>${App.escapeHtml((r.fecha_creacion || '').substring(0,10))}</td>
                                    <td>${App.escapeHtml(r.empresa_razon_social || '')}</td>
                                    <td>${App.escapeHtml(r.tipo_nombre || '')}</td>
                                    <td>${App.escapeHtml(r.vendedor_nombre || '—')}</td>
                                    <td class="text-right">${r.moneda} ${parseFloat(r.total || 0).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                    <td><span class="badge ${estadoClass(r.estado)}">${estadoLabel(r.estado)}</span></td>
                                    <td>${r.fecha_proximo_seguimiento ? App.escapeHtml((r.fecha_proximo_seguimiento || '').substring(0,10)) : '—'}</td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" title="Ver detalle" onclick="App.navigate('/cotizaciones/detalle/${r.id_cotizacion}')"><i class="bi bi-eye"></i></button>
                                        ${App.can('COTIZACIONES','modificar') ? `<button class="btn btn-sm btn-secondary" title="Editar" onclick="App.navigate('/cotizaciones/editar/${r.id_cotizacion}')"><i class="bi bi-pencil"></i></button>` : ''}
                                        ${App.can('COTIZACIONES','exportar') ? `<button class="btn btn-sm btn-secondary" title="Exportar PDF" onclick="App.views.cotizaciones.exportPdf(${r.id_cotizacion})"><i class="bi bi-file-pdf"></i></button>` : ''}
                                        ${App.can('COTIZACIONES','exportar') ? `<button class="btn btn-sm btn-success" title="Exportar Excel" onclick="App.views.cotizaciones.exportXlsx(${r.id_cotizacion})"><i class="bi bi-file-excel"></i></button>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;

            // Paginación
            const totalPages = Math.ceil(total / this.state.perPage) || 1;
            document.getElementById('cotizaciones-pagination-wrapper').innerHTML = `
                <div class="pagination">
                    <button class="btn btn-link" ${this.state.currentPage === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.goPage(${this.state.currentPage - 1})">← Anterior</button>
                    <span>Página ${this.state.currentPage} de ${totalPages} (total: ${total})</span>
                    <button class="btn btn-link" ${this.state.currentPage >= totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.goPage(${this.state.currentPage + 1})">Siguiente →</button>
                </div>
            `;
            App.applyPermissionsToDynamic(wrapper);
        } catch (e) {
            // Mensaje enriquecido: si el backend devolvió un error específico (sp_missing, sp_column_error),
            // mostrarlo con instrucciones para resolver.
            let msg = e.message || 'Error desconocido';
            let extraHtml = '';
            if (msg.indexOf('sp_missing') !== -1 || msg.indexOf('sp_cotizacion_list_paged') !== -1) {
                extraHtml = `
                    <div class="card" style="margin-top:10px;padding:10px;background:#fff3cd;border-left:3px solid #ffc107;">
                        <strong>⚠️ El Stored Procedure sp_cotizacion_list_paged no existe o está mal.</strong>
                        <p style="margin:6px 0 0 0;">Ejecuta en SQL Server Management Studio:</p>
                        <ol style="margin:6px 0 0 20px;">
                            <li><code>scripts_sql/cotizaciones/00_cotizaciones_drop_all.sql</code> (limpia todo)</li>
                            <li><code>scripts_sql/cotizaciones/05_cotizaciones_sps_parte2.sql</code> (recrea el SP corregido)</li>
                        </ol>
                        <p style="margin:6px 0 0 0;">También puedes abrir <a href="/api/v1/cotizaciones/diagnostico" target="_blank"><code>/api/v1/cotizaciones/diagnostico</code></a> para ver qué falta.</p>
                    </div>`;
            }
            wrapper.innerHTML = `<div class="card-body text-danger">
                Error al cargar cotizaciones: ${App.escapeHtml(msg)}
                ${extraHtml}
            </div>`;
        }
    },

    async goPage(p) {
        this.state.currentPage = p;
        await this.loadList();
    },

    /* ====== DETALLE ====== */
    async renderDetail(container, id) {
        container.innerHTML = `<div class="cotizaciones-loading">Cargando cotización...</div>`;
        try {
            const resp = await App.request(`/cotizaciones/${id}`, { background: true });
            const c = resp.cabecera;
            const detalle = resp.detalle || [];
            const historial = resp.historial || [];
            const comunicaciones = resp.comunicaciones || [];
            const versiones = resp.versiones || [];
            const documentos = resp.documentos || [];

            const estadoClass = (e) => ({BORRADOR:'badge-secondary',ENVIADA:'badge-primary',APROBADA:'badge-success',RECHAZADA:'badge-danger',CANCELADA:'badge-danger',CERRADA:'badge-secondary',PENDIENTE_ENVIO:'badge-info',EN_SEGUIMIENTO:'badge-warning',EN_NEGOCIACION:'badge-warning'})[e] || 'badge-secondary';
            const estadoLabel = (e) => ({BORRADOR:'Borrador',PENDIENTE_ENVIO:'Pendiente envío',ENVIADA:'Enviada',EN_SEGUIMIENTO:'En seguimiento',EN_NEGOCIACION:'En negociación',APROBADA:'Aprobada',RECHAZADA:'Rechazada',CANCELADA:'Cancelada',CERRADA:'Cerrada'})[e] || e;

            const fmt = (n) => parseFloat(n || 0).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            container.innerHTML = `
                <div class="view-header">
                    <h2>
                        <a href="#/cotizaciones" style="text-decoration:none;color:inherit;">
                            <i class="bi bi-arrow-left"></i>
                        </a>
                        <i class="bi bi-file-earmark-text"></i> Cotización ${App.escapeHtml(c.codigo)} ${c.version_numero > 1 ? `<span class="badge badge-info">v${c.version_numero}</span>` : ''}
                    </h2>
                    <div class="view-actions">
                        ${App.can('COTIZACIONES','exportar') ? `<button class="btn btn-secondary" onclick="App.views.cotizaciones.exportPdf(${c.id_cotizacion})"><i class="bi bi-file-pdf"></i> PDF</button>` : ''}
                        ${App.can('COTIZACIONES','exportar') ? `<button class="btn btn-success" onclick="App.views.cotizaciones.exportXlsx(${c.id_cotizacion})"><i class="bi bi-file-excel"></i> Excel</button>` : ''}
                        ${App.can('COTIZACIONES','modificar') ? `<button class="btn btn-primary" onclick="App.navigate('/cotizaciones/editar/${c.id_cotizacion}')"><i class="bi bi-pencil"></i> Editar</button>` : ''}
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                            <div>
                                <strong style="font-size:16px;">${App.escapeHtml(c.codigo || '(sin código)')}</strong>
                                ${c.version_numero > 1 ? `<span class="badge badge-info" style="margin-left:8px;">v${c.version_numero}</span>` : ''}
                                <span class="badge ${estadoClass(c.estado)}" style="margin-left:8px;">${estadoLabel(c.estado)}</span>
                                <span class="badge badge-secondary" style="margin-left:8px;">${App.escapeHtml(c.tipo_nombre || c.tipo_codigo || '')}</span>
                            </div>
                            <div style="color:#6c757d;font-size:13px;">
                                ${App.escapeHtml(c.titulo_documento || c.tipo_titulo_default || 'COTIZACION')}
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <h5>Cliente</h5>
                                <p><strong>${App.escapeHtml(c.empresa_razon_social || '')}</strong><br>
                                RUC: ${App.escapeHtml(c.empresa_ruc || '—')}<br>
                                Contacto: ${App.escapeHtml((c.contacto_nombres || '') + ' ' + (c.contacto_apellidos || '') || '—')}<br>
                                Cargo: ${App.escapeHtml(c.contacto_cargo || '—')}<br>
                                Sucursal: ${App.escapeHtml(c.sucursal_nombre || '—')}</p>
                            </div>
                            <div class="form-group">
                                <h5>Datos de la cotización</h5>
                                <p><strong>Código:</strong> ${App.escapeHtml(c.codigo)}<br>
                                <strong>Versión:</strong> ${c.version_numero}<br>
                                <strong>Tipo:</strong> ${App.escapeHtml(c.tipo_nombre || '')}<br>
                                <strong>Vendedor:</strong> ${App.escapeHtml(c.vendedor_nombre || '—')}<br>
                                <strong>Creada:</strong> ${App.escapeHtml((c.fecha_creacion || '').replace('T',' ').substring(0,19))}<br>
                                <strong>Creador:</strong> ${App.escapeHtml(c.usuario_creador_nombre || '—')}</p>
                            </div>
                        </div>

                        <h5 style="margin-top:20px;">Ítems</h5>
                        <table class="table table-bordered">
                            <thead><tr><th>#</th><th>Código</th><th>Descripción</th><th>Cant.</th><th>P.U.</th><th>Desc%</th><th>Subtotal</th></tr></thead>
                            <tbody>
                                ${detalle.map((d,i) => `
                                    <tr>
                                        <td>${i+1}</td>
                                        <td>${App.escapeHtml(d.codigo || '')}</td>
                                        <td>${App.escapeHtml(d.descripcion || '')}</td>
                                        <td>${d.cantidad}</td>
                                        <td class="text-right">${fmt(d.precio_unitario)}</td>
                                        <td class="text-right">${d.descuento_pct || 0}%</td>
                                        <td class="text-right">${fmt(d.subtotal)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                            <tfoot>
                                <tr><td colspan="6" class="text-right"><strong>Subtotal:</strong></td><td class="text-right">${fmt(c.subtotal)}</td></tr>
                                ${c.descuento_global_monto > 0 ? `<tr><td colspan="6" class="text-right"><strong>Descuento global (${c.descuento_global_pct}%):</strong></td><td class="text-right">-${fmt(c.descuento_global_monto)}</td></tr>` : ''}
                                <tr><td colspan="6" class="text-right"><strong>IGV (${c.igv_pct}%):</strong></td><td class="text-right">${fmt(c.igv_monto)}</td></tr>
                                <tr><td colspan="6" class="text-right"><strong>TOTAL:</strong></td><td class="text-right"><strong>${c.moneda} ${fmt(c.total)}</strong></td></tr>
                            </tfoot>
                        </table>

                        <h5 style="margin-top:20px;">Condiciones comerciales</h5>
                        <div class="form-row">
                            <div class="form-group">
                                <p><strong>Forma de pago:</strong> ${App.escapeHtml(c.snapshot_forma_pago || '—')}</p>
                                <p><strong>Garantía:</strong> ${App.escapeHtml(c.snapshot_garantia || '—')}</p>
                                <p><strong>Plazo de entrega:</strong> ${App.escapeHtml(c.snapshot_plazo_entrega || '—')}</p>
                                <p><strong>Validez:</strong> ${App.escapeHtml(c.snapshot_validez || '—')}</p>
                                <p><strong>Cuentas bancarias:</strong></p>
                                <div id="detalle-cuentas-bancarias" style="margin-left:20px;">
                                    <p>${App.escapeHtml(c.snapshot_cuenta_bancaria || '—').replace(/\\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>

                        ${c.observaciones ? `<h5 style="margin-top:20px;">Observaciones</h5><p>${App.escapeHtml(c.observaciones)}</p>` : ''}
                    </div>
                </div>

                <!-- Cambio de estado -->
                ${App.can('COTIZACIONES','modificar') ? `
                <div class="card" style="margin-top:12px;">
                    <div class="card-header"><strong>Cambiar estado</strong></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Nuevo estado</label>
                                <select id="nuevo-estado" class="form-select">
                                    <option value="PENDIENTE_ENVIO">Pendiente de envío</option>
                                    <option value="ENVIADA">Enviada</option>
                                    <option value="EN_SEGUIMIENTO">En seguimiento</option>
                                    <option value="EN_NEGOCIACION">En negociación</option>
                                    ${App.can('COTIZACIONES','aprobar') ? '<option value="APROBADA">Aprobada</option><option value="RECHAZADA">Rechazada</option><option value="CANCELADA">Cancelada</option><option value="CERRADA">Cerrada</option>' : ''}
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Motivo</label>
                                <input type="text" id="motivo-estado" class="form-input" placeholder="Obligatorio para aprobar/rechazar/cancelar">
                            </div>
                        </div>
                        <div style="margin-top:10px;">
                            <button class="btn btn-primary" onclick="App.views.cotizaciones.changeStatus(${c.id_cotizacion})">
                                <i class="bi bi-arrow-clockwise"></i> Aplicar cambio de estado
                            </button>
                        </div>
                    </div>
                </div>` : ''}

                <!-- Tabs de historial, comunicaciones, versiones, documentos -->
                <div class="card" style="margin-top:12px;">
                    <div class="card-header">
                        <ul class="nav nav-tabs" id="cot-tabs">
                            <li class="nav-item"><a class="nav-link active" data-tab="historial">Historial (${historial.length})</a></li>
                            <li class="nav-item"><a class="nav-link" data-tab="comunicaciones">Comunicaciones (${comunicaciones.length})</a></li>
                            <li class="nav-item"><a class="nav-link" data-tab="versiones">Versiones (${versiones.length})</a></li>
                            <li class="nav-item"><a class="nav-link" data-tab="documentos">Documentos (${documentos.length})</a></li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div id="tab-historial">
                            <table class="table">
                                <thead><tr><th>Fecha</th><th>Estado anterior</th><th>Estado nuevo</th><th>Tipo evento</th><th>Motivo</th><th>Usuario</th></tr></thead>
                                <tbody>
                                    ${historial.map(h => `
                                        <tr>
                                            <td>${App.escapeHtml((h.fecha_cambio || '').replace('T',' ').substring(0,19))}</td>
                                            <td>${App.escapeHtml(h.estado_anterior || '')}</td>
                                            <td>${App.escapeHtml(h.estado_nuevo || '')}</td>
                                            <td>${App.escapeHtml(h.tipo_evento || '')}</td>
                                            <td>${App.escapeHtml(h.motivo || '')}</td>
                                            <td>${App.escapeHtml(h.usuario_nombre || '')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div id="tab-comunicaciones" style="display:none;">
                            ${App.can('COTIZACIONES','modificar') ? `
                                <button class="btn btn-primary" onclick="App.views.cotizaciones.showComunicacionModal(${c.id_cotizacion})">
                                    <i class="bi bi-plus-lg"></i> Registrar comunicación
                                </button>
                                <hr>
                            ` : ''}
                            <table class="table">
                                <thead><tr><th>Fecha</th><th>Medio</th><th>Tipo</th><th>Descripción</th><th>Respuesta</th><th>Usuario</th></tr></thead>
                                <tbody>
                                    ${comunicaciones.map(com => `
                                        <tr>
                                            <td>${App.escapeHtml((com.fecha_registro || '').replace('T',' ').substring(0,19))}</td>
                                            <td>${App.escapeHtml(com.medio || '')}</td>
                                            <td>${App.escapeHtml(com.tipo_comunicacion || '')}</td>
                                            <td>${App.escapeHtml(com.descripcion || '')}</td>
                                            <td>${App.escapeHtml(com.respuesta_cliente || '—')}</td>
                                            <td>${App.escapeHtml(com.usuario_nombre || '')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div id="tab-versiones" style="display:none;">
                            <table class="table">
                                <thead><tr><th>Versión</th><th>Código</th><th>Motivo</th><th>Fecha</th><th>Usuario</th></tr></thead>
                                <tbody>
                                    ${versiones.map(v => `
                                        <tr>
                                            <td>${v.version_numero}</td>
                                            <td>${App.escapeHtml(v.codigo_versionado || '')}</td>
                                            <td>${App.escapeHtml(v.motivo || '')}</td>
                                            <td>${App.escapeHtml((v.fecha_version || '').replace('T',' ').substring(0,19))}</td>
                                            <td>${App.escapeHtml(v.usuario_nombre || '')}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div id="tab-documentos" style="display:none;">
                            ${App.can('COTIZACIONES','modificar') ? `
                                <input type="file" id="doc-file" class="form-input" style="display:inline-block;width:auto;">
                                <button class="btn btn-primary" onclick="App.views.cotizaciones.uploadFile(${c.id_cotizacion})">
                                    <i class="bi bi-upload"></i> Subir documento
                                </button>
                                <hr>
                            ` : ''}
                            <table class="table">
                                <thead><tr><th>Nombre</th><th>Tipo</th><th>Tamaño</th><th>Fecha</th><th>Usuario</th><th>Acción</th></tr></thead>
                                <tbody>
                                    ${documentos.map(doc => `
                                        <tr>
                                            <td>${App.escapeHtml(doc.nombre_original || '')}</td>
                                            <td>${App.escapeHtml(doc.tipo_documento || '')}</td>
                                            <td>${(doc.tamano_bytes/1024).toFixed(1)} KB</td>
                                            <td>${App.escapeHtml((doc.fecha_registro || '').replace('T',' ').substring(0,19))}</td>
                                            <td>${App.escapeHtml(doc.usuario_nombre || '—')}</td>
                                            <td><a href="/api/v1/cotizaciones/${c.id_cotizacion}/files/${doc.id_documento}" target="_blank" class="btn btn-sm btn-secondary"><i class="bi bi-download"></i></a></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;

            // Activar tabs
            container.querySelectorAll('[data-tab]').forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    container.querySelectorAll('[data-tab]').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    ['historial','comunicaciones','versiones','documentos'].forEach(t => {
                        document.getElementById('tab-' + t).style.display = (t === tab.dataset.tab) ? '' : 'none';
                    });
                });
            });

            // Cargar las 3 cuentas bancarias de la emisora para mostrarlas todas
            this.cargarCuentasEnDetalle(c.id_emisora);

            App.applyPermissionsToDynamic(container);
        } catch (e) {
            container.innerHTML = `<div class="card-body text-danger">Error al cargar cotización: ${App.escapeHtml(e.message)}</div>`;
        }
    },

    /* ====== Cargar las 3 cuentas bancarias de la emisora en el detalle ====== */
    async cargarCuentasEnDetalle(idEmisora) {
        const div = document.getElementById('detalle-cuentas-bancarias');
        if (!div || !idEmisora) return;
        try {
            const resp = await App.request('/cotizaciones/catalogos', { background: true });
            const cuentas = (resp.cuentas || []).filter(c => c.id_emisora == idEmisora && c.activo != 0);
            if (cuentas.length === 0) return;
            div.innerHTML = cuentas.map(c => {
                return '<p>' +
                    '<strong>' + App.escapeHtml(c.banco) + '</strong>: ' +
                    'Cuenta: ' + App.escapeHtml(c.numero_cuenta) +
                    (c.cci ? ' | CCI: ' + App.escapeHtml(c.cci) : '') +
                    ' | ' + App.escapeHtml(c.moneda || 'PEN') +
                    '</p>';
            }).join('');
        } catch (e) {}
    },

    /* ====== FORMULARIO CREAR/EDITAR ====== */
    async renderForm(container, id) {
        const isEdit = !!id;
        container.innerHTML = `<div class="cotizaciones-loading">Cargando formulario...</div>`;

      try {
        // Cargar catálogos
        if (!this.state.catalogos) {
            const resp = await App.request('/cotizaciones/catalogos', { background: true });
            if (!resp) {
                // Sesión expirada en background
                container.innerHTML = '<div class="card-body text-danger">Sesión expirada. <a href="/login.php">Inicia sesión de nuevo</a>.</div>';
                return;
            }
            this.state.catalogos = resp;
        }
        const cat = this.state.catalogos || {};
        // Asegurar que cada catálogo sea un array (defensa contra respuestas parciales)
        const tiposArr       = Array.isArray(cat.tipos)       ? cat.tipos       : [];
        const emisorasArr    = Array.isArray(cat.emisoras)    ? cat.emisoras    : [];
        const formasPagoArr  = Array.isArray(cat.formas_pago) ? cat.formas_pago : [];
        const garantiasArr   = Array.isArray(cat.garantias)   ? cat.garantias   : [];
        const plazosArr      = Array.isArray(cat.plazos)      ? cat.plazos      : [];
        const validecesArr   = Array.isArray(cat.valideces)   ? cat.valideces   : [];
        const cuentasArr     = Array.isArray(cat.cuentas)     ? cat.cuentas     : [];
        const zonasArr       = Array.isArray(cat.zonas)       ? cat.zonas       : [];

        // Cargar datos de la cotización si es edición
        let cot = null, detalle = [];
        let cuentasAdicionalesIds = [];
        if (isEdit) {
            const resp = await App.request(`/cotizaciones/${id}`, { background: true });
            if (!resp) {
                container.innerHTML = '<div class="card-body text-danger">Sesión expirada. <a href="/login.php">Inicia sesión de nuevo</a>.</div>';
                return;
            }
            cot = resp.cabecera || {};
            detalle = resp.detalle || [];
            if (cot.cuentas_adicionales_json) {
                try { cuentasAdicionalesIds = JSON.parse(cot.cuentas_adicionales_json) || []; }
                catch (e) { cuentasAdicionalesIds = []; }
            }
        }

        this.state.formCuentasAdicionales = cuentasAdicionalesIds.slice();

        container.innerHTML = `
            <div class="view-header">
                <h2>
                    <a href="#/cotizaciones" style="text-decoration:none;color:inherit;"><i class="bi bi-arrow-left"></i></a>
                    <i class="bi bi-file-earmark-text"></i> ${isEdit ? 'Editar' : 'Nueva'} Cotización
                </h2>
            </div>

            <div class="card">
                <div class="card-body">

                    <h5>Datos generales</h5>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Empresa cliente <span class="req">*</span></label>
                            <div style="display:flex;gap:4px;">
                                <input type="text" id="empresa-display" class="form-input" readonly
                                    value="${cot ? App.escapeHtml(cot.empresa_razon_social || '') : ''}"
                                    placeholder="(No seleccionada)" style="flex:1;background:#f8f9fa;">
                                <button type="button" class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.showBuscarEmpresaModal()">
                                    <i class="bi bi-search"></i> Buscar
                                </button>
                            </div>
                            <input type="hidden" id="id_empresa" value="${cot ? cot.id_empresa : ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contacto</label>
                            <div style="display:flex;gap:4px;">
                                <input type="text" id="contacto-display" class="form-input" readonly
                                    value="${cot ? App.escapeHtml((cot.contacto_nombres || '') + ' ' + (cot.contacto_apellidos || '')) : ''}"
                                    placeholder="(Sin contacto)" style="flex:1;background:#f8f9fa;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="App.views.cotizaciones.showBuscarContactoModal()" id="btn-buscar-contacto" ${!cot || !cot.id_empresa ? 'disabled' : ''}>
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                            <input type="hidden" id="id_empresa_contacto" value="${cot ? (cot.id_empresa_contacto || '') : ''}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Sucursal</label>
                            <select id="id_sucursal" class="form-select">
                                <option value="">Sin sucursal</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Área <span class="req">*</span></label>
                            <select id="area_cotizacion" class="form-select" required onchange="App.views.cotizaciones.actualizarTituloYCodigoPreview()">
                                <option value="">Selecciona área...</option>
                                <option value="VENTAS" ${cot && cot.tipo_codigo === 'VENTAS' ? 'selected' : ''}>Ventas</option>
                                <option value="SOPORTE" ${cot && cot.tipo_codigo === 'SOPORTE' ? 'selected' : ''}>Soporte</option>
                            </select>
                            <small style="color:#6c757d;font-size:12px;margin-top:4px;display:block;">
                                Ventas: código <strong>VT-2026-0001</strong> (correlativo global por año).<br>
                                Soporte: código <strong>SOP-EMP000001-2026-0001</strong> (correlativo por empresa y año).
                            </small>
                            <!-- Hidden select con todos los tipos para mapear Ventas→tipo VENTAS, Soporte→tipo SOPORTE -->
                            <select id="id_tipo" class="form-select" style="display:none;">
                                ${tiposArr.map(t => `<option value="${t.id_tipo}" data-titulo="${App.escapeHtml(t.titulo_documento || '')}" data-codigo-por-empresa="${t.codigo_por_empresa || 0}" data-prefijo="${App.escapeHtml(t.prefijo_codigo || '')}" data-codigo="${App.escapeHtml(t.codigo || '')}" ${cot && cot.id_tipo == t.id_tipo ? 'selected' : ''}>${App.escapeHtml(t.nombre)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Empresa emisora <span class="req">*</span></label>
                            <select id="id_emisora" class="form-select" required>
                                ${emisorasArr.map(e => `<option value="${e.id_emisora}" ${cot && cot.id_emisora == e.id_emisora ? 'selected' : ''}>${App.escapeHtml(e.razon_social)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Vendedor</label>
                            <div style="display:flex;gap:4px;">
                                <input type="text" id="vendedor-display" class="form-input" readonly
                                    value="${cot ? App.escapeHtml(cot.vendedor_nombre || '') : ''}"
                                    placeholder="(Sin vendedor)" style="flex:1;background:#f8f9fa;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="App.views.cotizaciones.showBuscarVendedorModal()">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                            <input type="hidden" id="id_vendedor" value="${cot ? (cot.id_vendedor || '') : ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Vista previa del código</label>
                            <input type="text" id="preview-codigo" class="form-input" readonly
                                style="background:#f0f0f0;font-family:monospace;font-weight:bold;color:#495057;font-size:15px;"
                                value="${cot ? App.escapeHtml(cot.codigo || '(al guardar)') : '(se generará al guardar)'}">
                        </div>
                    </div>

                    <h5 style="margin-top:16px;">Condiciones comerciales</h5>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Forma de pago</label>
                            <div style="display:flex;gap:4px;">
                                <select id="id_forma_pago" class="form-select" style="flex:1;">
                                    <option value="">—</option>
                                    ${formasPagoArr.map(f => `<option value="${f.id_forma_pago}" ${cot && cot.id_forma_pago == f.id_forma_pago ? 'selected' : ''}>${App.escapeHtml((f.descripcion||'').substring(0,60))}${f.descuento_pct > 0 ? ` (-${f.descuento_pct}%)` : ''}</option>`).join('')}
                                </select>
                                <button type="button" class="btn btn-link" onclick="App.views.cotizaciones.showGestionarFormasPagoModal()" title="Gestionar formas de pago" style="padding:0 6px;">
                                    <i class="bi bi-gear" style="font-size:16px;"></i>
                                </button>
                                <button type="button" class="btn btn-link" onclick="App.views.cotizaciones.showAddFormaPagoModal()" title="Agregar nueva forma de pago" style="padding:0 6px;">
                                    <i class="bi bi-plus-lg" style="font-size:18px;"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Garantía</label>
                            <select id="id_garantia" class="form-select">
                                <option value="">—</option>
                                ${garantiasArr.map(g => `<option value="${g.id_garantia}" ${cot && cot.id_garantia == g.id_garantia ? 'selected' : ''}>${App.escapeHtml((g.descripcion||'').substring(0,60))}</option>`).join('')}
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Plazo de entrega</label>
                            <select id="id_plazo_entrega" class="form-select">
                                <option value="">—</option>
                                ${plazosArr.map(p => `<option value="${p.id_plazo}" ${cot && cot.id_plazo_entrega == p.id_plazo ? 'selected' : ''}>${App.escapeHtml((p.descripcion||'').substring(0,60))}</option>`).join('')}
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Validez</label>
                            <select id="id_validez" class="form-select">
                                <option value="">—</option>
                                ${validecesArr.map(v => `<option value="${v.id_validez}" ${cot && cot.id_validez == v.id_validez ? 'selected' : ''}>${App.escapeHtml(v.descripcion||'')}</option>`).join('')}
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Cuenta bancaria</label>
                            <div style="display:flex;gap:4px;">
                                <select id="id_cuenta_bancaria" class="form-select" style="flex:1;">
                                    <option value="">—</option>
                                    ${cuentasArr.map(c => `<option value="${c.id_cuenta}" ${cot && cot.id_cuenta_bancaria == c.id_cuenta ? 'selected' : ''}>${App.escapeHtml((c.banco||'') + ' - ' + (c.numero_cuenta||''))}</option>`).join('')}
                                </select>
                                <button type="button" class="btn btn-link" onclick="App.views.cotizaciones.showGestionarCuentasModal()" title="Gestionar cuentas bancarias" style="padding:0 6px;">
                                    <i class="bi bi-gear" style="font-size:16px;"></i>
                                </button>
                                <button type="button" class="btn btn-link" onclick="App.views.cotizaciones.showCrearCuentaModal()" title="Crear nueva cuenta" style="padding:0 6px;">
                                    <i class="bi bi-plus-lg" style="font-size:18px;"></i>
                                </button>
                            </div>
                            <small style="color:#6c757d;font-size:12px;margin-top:4px;display:block;">
                                Cuentas adicionales a mostrar en la cotización:
                            </small>
                            <div id="cuentas-adicionales-list" style="margin-top:6px;"></div>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top:8px;">
                        <div class="form-group">
                            <label class="form-label">Zona de pricing</label>
                            <div style="display:flex;gap:4px;">
                                <select id="id_zona_pricing" class="form-select" style="flex:1;" onchange="App.views.cotizaciones.recalcAll()">
                                    <option value="">Ninguna</option>
                                    ${zonasArr.map(z => `<option value="${z.id_zona}" ${cot && cot.id_zona_pricing == z.id_zona ? 'selected' : ''}>${App.escapeHtml(z.nombre)} (${App.escapeHtml(z.codigo || '')})</option>`).join('')}
                                </select>
                                <button type="button" class="btn btn-link" onclick="App.views.cotizaciones.showGestionarZonasModal()" title="Gestionar zonas" style="padding:0 6px;">
                                    <i class="bi bi-gear" style="font-size:16px;"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <h5 style="margin-top:16px;">Ítems de la cotización</h5>
                    <div style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.showBuscarProductoModal()">
                            <i class="bi bi-search"></i> Buscar producto en catálogo
                        </button>
                        <button type="button" class="btn btn-link btn-sm" onclick="App.views.cotizaciones.addEmptyItem()">
                            <i class="bi bi-plus"></i> Agregar ítem vacío
                        </button>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="table table-bordered" id="items-table" style="font-size:13px;min-width:800px;">
                            <thead>
                                <tr>
                                    <th style="width:30px;text-align:center;">#</th>
                                    <th style="width:130px;">Código</th>
                                    <th>Descripción</th>
                                    <th style="width:60px;text-align:center;">Cant.</th>
                                    <th style="width:60px;text-align:center;">U.M.</th>
                                    <th style="width:100px;text-align:right;">P.U. (S/.)</th>
                                    <th style="width:60px;text-align:center;">Desc%</th>
                                    <th style="width:100px;text-align:right;">Subtotal</th>
                                    <th style="width:30px;text-align:center;">IGV</th>
                                    <th style="width:30px;text-align:center;">-</th>
                                </tr>
                            </thead>
                            <tbody id="items-body">
                                ${detalle.map((d, i) => `
                                    <tr data-item-index="${i}" data-id-producto-catalogo="${d.id_producto_catalogo || ''}" data-unidad-medida="${d.unidad_medida || 'UND'}">
                                        <td style="text-align:center;vertical-align:middle;">${i+1}</td>
                                        <td><input type="text" style="width:120px;font-family:monospace;" class="form-input item-codigo" value="${App.escapeHtml(d.codigo || '')}" onchange="App.views.cotizaciones.recalcRow(this)"></td>
                                        <td><textarea class="form-input item-desc" rows="2" style="width:100%;min-width:300px;" onchange="App.views.cotizaciones.recalcRow(this)">${App.escapeHtml(d.descripcion || '')}</textarea></td>
                                        <td><input type="number" class="form-input item-cant" value="${d.cantidad}" min="1" step="1" onchange="App.views.cotizaciones.recalcRow(this)" style="width:55px;text-align:center;"></td>
                                        <td><input type="text" class="form-input item-um" value="${App.escapeHtml(d.unidad_medida || 'UND')}" style="width:60px;text-align:center;"></td>
                                        <td><input type="number" class="form-input item-pu" value="${d.precio_unitario}" step="0.01" onchange="App.views.cotizaciones.recalcRow(this)" style="width:90px;text-align:right;"></td>
                                        <td><input type="number" class="form-input item-desc-pct" value="${d.descuento_pct || 0}" min="0" max="100" step="0.01" onchange="App.views.cotizaciones.recalcRow(this)" style="width:55px;text-align:center;"></td>
                                        <td class="text-right item-subtotal" style="vertical-align:middle;font-weight:bold;">${parseFloat(d.subtotal || 0).toFixed(2)}</td>
                                        <td class="center" style="vertical-align:middle;"><input type="checkbox" class="item-igv" ${d.afecto_igv ? 'checked' : ''}></td>
                                        <td style="vertical-align:middle;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove();App.views.cotizaciones.recalcAll();"><i class="bi bi-x"></i></button></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                            <tfoot>
                                <tr><td colspan="6" class="text-right"><strong>Subtotal:</strong></td><td class="text-right" id="t-subtotal">0.00</td><td colspan="2"></td></tr>
                                <tr><td colspan="6" class="text-right"><strong>Descuento global (%):</strong></td>
                                    <td><input type="number" id="desc_global" value="${cot ? (cot.descuento_global_pct || 0) : 0}" step="0.01" min="0" max="100" onchange="App.views.cotizaciones.recalcAll()" style="width:80px;"></td>
                                    <td colspan="2" class="text-right" id="t-desc-monto">0.00</td></tr>
                                <tr><td colspan="6" class="text-right"><strong>IGV (%):</strong></td>
                                    <td><input type="number" id="igv_pct" value="${cot ? (cot.igv_pct || 18) : 18}" step="0.01" min="0" max="100" onchange="App.views.cotizaciones.recalcAll()" style="width:80px;"></td>
                                    <td colspan="2" class="text-right" id="t-igv">0.00</td></tr>
                                <tr><td colspan="6" class="text-right"><strong>TOTAL:</strong></td><td class="text-right" id="t-total" colspan="2"><strong>0.00</strong></td></tr>
                            </tfoot>
                        </table>
                    </div>

                    <div style="margin-top:16px;text-align:right;">
                        <button type="button" class="btn btn-secondary" onclick="App.navigate('/cotizaciones')">Cancelar</button>
                        <button type="button" class="btn btn-primary" onclick="App.views.cotizaciones.save(${isEdit ? id : 'null'})">
                            <i class="bi bi-save"></i> ${isEdit ? 'Guardar cambios' : 'Crear cotización'}
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Inicializar UI de cuentas adicionales
        this.renderCuentasAdicionales();
        this.recalcAll();
        // Inicializar título y preview del código según el tipo seleccionado.
        // Solo en modo creación: en edición el código real ya está en el input.
        if (!isEdit) {
            setTimeout(() => this.actualizarTituloYCodigoPreview(), 100);
        }
      } catch (e) {
        console.error('[renderForm cotizaciones]', e);
        container.innerHTML = `<div class="card-body text-danger">
            <strong>Error al cargar formulario:</strong> ${App.escapeHtml(e.message || String(e))}
            <div style="margin-top:10px;">
                <button class="btn btn-link" onclick="App.navigate('/cotizaciones')">← Volver al listado</button>
                <button class="btn btn-link" onclick="location.reload()">Reintentar</button>
            </div>
        </div>`;
      }
    },

    /* ====== Helper para actualizar vista previa del código según área ====== */
    actualizarTituloYCodigoPreview() {
        const areaSel = document.getElementById('area_cotizacion');
        const tipoHidden = document.getElementById('id_tipo');
        if (!areaSel) return;

        const area = areaSel.value;
        // Sincronizar el select hidden id_tipo con el área seleccionada
        if (tipoHidden) {
            // Buscar el option del hidden que tiene data-codigo === area
            for (let i = 0; i < tipoHidden.options.length; i++) {
                if (tipoHidden.options[i].dataset.codigo === area) {
                    tipoHidden.selectedIndex = i;
                    break;
                }
            }
        }

        // Actualizar vista previa del código
        const previewInput = document.getElementById('preview-codigo');
        if (previewInput) {
            const anio = new Date().getFullYear();
            const idEmpresa = parseInt(document.getElementById('id_empresa')?.value || '0');
            if (area === 'VENTAS') {
                previewInput.value = `VT-${anio}-????`;
            } else if (area === 'SOPORTE') {
                if (idEmpresa > 0) {
                    previewInput.value = `SOP-EMP${String(idEmpresa).padStart(6, '0')}-${anio}-????`;
                } else {
                    previewInput.value = `SOP-EMP??????-${anio}-????`;
                }
            } else {
                previewInput.value = '(se generará al guardar)';
            }
        }
    },

    /* ====== Helper para renderizar chips de cuentas adicionales ====== */
    renderCuentasAdicionales() {
        const cont = document.getElementById('cuentas-adicionales-list');
        if (!cont) return;
        const ids = this.state.formCuentasAdicionales || [];
        const cat = this.state.catalogos;
        if (!ids.length) {
            cont.innerHTML = '<span class="text-muted" id="cuentas-adicionales-empty">(ninguna)</span>';
            return;
        }
        cont.innerHTML = ids.map(id => {
            const cuenta = (cat.cuentas || []).find(c => c.id_cuenta == id);
            const label = cuenta ? (cuenta.banco + ' - ' + cuenta.numero_cuenta) : 'ID ' + id;
            return '<span class="badge badge-info" style="padding:4px 8px;display:inline-flex;align-items:center;gap:4px;">'
                + App.escapeHtml(label)
                + ' <button type="button" onclick="App.views.cotizaciones.removeCuentaAdicional(' + id + ')" style="background:none;border:none;color:#dc3545;cursor:pointer;padding:0 2px;font-weight:bold;">×</button></span>';
        }).join('');
    },

    addCuentaAdicional(idCuenta) {
        if (!this.state.formCuentasAdicionales) this.state.formCuentasAdicionales = [];
        if (this.state.formCuentasAdicionales.indexOf(idCuenta) === -1) {
            const principal = parseInt(document.getElementById('id_cuenta_bancaria').value) || 0;
            if (idCuenta === principal) {
                App.toast('Esa ya es la cuenta principal. Selecciona otra.', 'warning');
                return;
            }
            this.state.formCuentasAdicionales.push(idCuenta);
            this.renderCuentasAdicionales();
            App.toast('Cuenta adicional agregada', 'success');
        } else {
            App.toast('Esa cuenta ya está en la lista de adicionales', 'warning');
        }
    },

    removeCuentaAdicional(idCuenta) {
        if (!this.state.formCuentasAdicionales) return;
        this.state.formCuentasAdicionales = this.state.formCuentasAdicionales.filter(x => x !== idCuenta);
        this.renderCuentasAdicionales();
    },

    /* ====== Modal: buscar empresa cliente ====== */
    async showBuscarEmpresaModal() {
        // Inicializar estado de paginación del modal
        this._modalEmpresa = { data: [], page: 1, perPage: 100, filtered: null, loading: false };
        App.openModal(`
            <h3><i class="bi bi-building"></i> Buscar empresa cliente</h3>
            <p style="color:#6c757d;font-size:13px;margin-bottom:8px;">La lista se carga automáticamente. Escribe para filtrar.</p>
            <input type="text" id="empresa-modal-search" class="form-input" placeholder="Ej: 20512345678 o Industria..." oninput="App.views.cotizaciones.filterEmpresaInModal()">
            <div id="empresa-modal-results" style="max-height:400px;overflow:auto;margin-top:10px;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando empresas...</div>
            </div>
            <div id="empresa-modal-pagination" style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;"></div>
            <div class="text-right" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
            </div>
        `);
        setTimeout(() => document.getElementById('empresa-modal-search')?.focus(), 100);
        // Cargar todas las empresas inmediatamente (sin filtro)
        await this.loadAllEmpresas();
    },

    async loadAllEmpresas() {
        const state = this._modalEmpresa;
        if (state.loading) return;
        state.loading = true;
        const div = document.getElementById('empresa-modal-results');
        if (div) div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">Cargando empresas...</div>';
        try {
            // Sin query = devuelve todas las empresas (limitado por backend a ~5000)
            const resp = await App.request('/empresas', { background: true });
            state.data = Array.isArray(resp) ? resp : (resp.data || []);
            this.renderEmpresaModalPage();
        } catch (e) {
            if (div) div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        } finally {
            state.loading = false;
        }
    },

    filterEmpresaInModal() {
        // Debounce
        clearTimeout(this._modalEmpresa._timer);
        this._modalEmpresa._timer = setTimeout(() => {
            const q = (document.getElementById('empresa-modal-search')?.value || '').trim().toLowerCase();
            this._modalEmpresa.page = 1;
            if (!q) {
                this._modalEmpresa.filtered = null; // Mostrar todos
            } else {
                this._modalEmpresa.filtered = this._modalEmpresa.data.filter(e => {
                    const ruc = (e.ruc || '').toLowerCase();
                    const rz = (e.razon_social || '').toLowerCase();
                    const nc = (e.nombre_comercial || '').toLowerCase();
                    const co = (e.correo || '').toLowerCase();
                    return ruc.includes(q) || rz.includes(q) || nc.includes(q) || co.includes(q);
                });
            }
            this.renderEmpresaModalPage();
        }, 200);
    },

    renderEmpresaModalPage() {
        const state = this._modalEmpresa;
        const div = document.getElementById('empresa-modal-results');
        const pagDiv = document.getElementById('empresa-modal-pagination');
        if (!div) return;

        const source = state.filtered !== null ? state.filtered : state.data;
        const total = source.length;
        if (total === 0) {
            div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">' +
                (state.filtered ? 'Sin resultados para tu filtro.' : 'No hay empresas registradas.') +
                '</div>';
            if (pagDiv) pagDiv.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(total / state.perPage);
        if (state.page > totalPages) state.page = totalPages;
        const start = (state.page - 1) * state.perPage;
        const pageRows = source.slice(start, start + state.perPage);

        div.innerHTML = '<table class="table table-hover table-sm"><thead><tr><th>RUC</th><th>Razón social</th><th>Correo</th><th></th></tr></thead><tbody>'
            + pageRows.map(e => `
                <tr>
                    <td>${App.escapeHtml(e.ruc || '')}</td>
                    <td>${App.escapeHtml(e.razon_social || '')}</td>
                    <td>${App.escapeHtml(e.correo || '')}</td>
                    <td><button class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.selectEmpresaInModal(${e.id_empresa}, decodeURIComponent('${encodeURIComponent(e.razon_social || '')}'))">Seleccionar</button></td>
                </tr>
            `).join('') + '</tbody></table>';

        if (pagDiv) {
            pagDiv.innerHTML = `
                <span style="font-size:13px;color:#6c757d;">${total} empresa(s) — Página ${state.page} de ${totalPages}</span>
                <div>
                    <button class="btn btn-sm btn-secondary" ${state.page === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.changeEmpresaModalPage(${state.page - 1})">← Anterior</button>
                    <button class="btn btn-sm btn-secondary" ${state.page === totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.changeEmpresaModalPage(${state.page + 1})">Siguiente →</button>
                </div>
            `;
        }
    },

    changeEmpresaModalPage(newPage) {
        this._modalEmpresa.page = newPage;
        this.renderEmpresaModalPage();
    },

    async selectEmpresaInModal(idEmpresa, nombre) {
        document.getElementById('id_empresa').value = idEmpresa;
        document.getElementById('empresa-display').value = nombre;
        document.getElementById('id_empresa_contacto').value = '';
        document.getElementById('contacto-display').value = '';
        document.getElementById('btn-buscar-contacto').disabled = false;
        try {
            const resp = await App.request('/sucursales?id_empresa=' + idEmpresa, { background: true });
            const sel = document.getElementById('id_sucursal');
            const sucRows = Array.isArray(resp) ? resp : (resp.data || []);
            sel.innerHTML = '<option value="">Sin sucursal</option>' + sucRows.map(s => `<option value="${s.id_sucursal}">${App.escapeHtml(s.nombre)}</option>`).join('');
        } catch (e) {}
        // Actualizar la vista previa del código (porque si el tipo es por empresa, cambia)
        this.actualizarTituloYCodigoPreview();
        App.closeModal();
    },

    /* ====== Modal: buscar contacto ====== */
    async showBuscarContactoModal() {
        const idEmpresa = parseInt(document.getElementById('id_empresa').value);
        if (!idEmpresa) {
            App.toast('Primero selecciona una empresa', 'warning');
            return;
        }
        this._modalContacto = { data: [], page: 1, perPage: 100, filtered: null, loading: false, idEmpresa };
        App.openModal(`
            <h3><i class="bi bi-person"></i> Buscar contacto</h3>
            <p style="color:#6c757d;font-size:13px;margin-bottom:8px;">Contactos de la empresa seleccionada. Escribe para filtrar.</p>
            <input type="text" id="contacto-modal-search" class="form-input" placeholder="Nombre, cargo o correo..." oninput="App.views.cotizaciones.filterContactoInModal()">
            <div id="contacto-modal-results" style="max-height:400px;overflow:auto;margin-top:10px;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando contactos...</div>
            </div>
            <div id="contacto-modal-pagination" style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;"></div>
            <div class="text-right" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
            </div>
        `);
        await this.loadAllContactos();
    },

    async loadAllContactos() {
        const state = this._modalContacto;
        if (state.loading) return;
        state.loading = true;
        const div = document.getElementById('contacto-modal-results');
        if (div) div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">Cargando contactos...</div>';
        try {
            const url = '/contactos?id_empresa=' + state.idEmpresa;
            const resp = await App.request(url, { background: true });
            state.data = Array.isArray(resp) ? resp : (resp.data || []);
            this.renderContactoModalPage();
        } catch (e) {
            if (div) div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        } finally {
            state.loading = false;
        }
    },

    filterContactoInModal() {
        clearTimeout(this._modalContacto._timer);
        this._modalContacto._timer = setTimeout(() => {
            const q = (document.getElementById('contacto-modal-search')?.value || '').trim().toLowerCase();
            this._modalContacto.page = 1;
            if (!q) {
                this._modalContacto.filtered = null;
            } else {
                this._modalContacto.filtered = this._modalContacto.data.filter(c => {
                    const n = ((c.nombres || '') + ' ' + (c.apellido_paterno || '')).toLowerCase();
                    const cargo = (c.cargo || '').toLowerCase();
                    const tel = (c.telefono_empresa || '').toLowerCase();
                    const co = (c.correo_empresa || '').toLowerCase();
                    return n.includes(q) || cargo.includes(q) || tel.includes(q) || co.includes(q);
                });
            }
            this.renderContactoModalPage();
        }, 200);
    },

    renderContactoModalPage() {
        const state = this._modalContacto;
        const div = document.getElementById('contacto-modal-results');
        const pagDiv = document.getElementById('contacto-modal-pagination');
        if (!div) return;

        const source = state.filtered !== null ? state.filtered : state.data;
        const total = source.length;
        if (total === 0) {
            div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">' +
                (state.filtered ? 'Sin resultados para tu filtro.' : 'No hay contactos para esta empresa.') +
                '</div>';
            if (pagDiv) pagDiv.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(total / state.perPage);
        if (state.page > totalPages) state.page = totalPages;
        const start = (state.page - 1) * state.perPage;
        const pageRows = source.slice(start, start + state.perPage);

        div.innerHTML = '<table class="table table-hover table-sm"><thead><tr><th>Nombre</th><th>Cargo</th><th>Teléfono</th><th>Correo</th><th></th></tr></thead><tbody>'
            + pageRows.map(c => {
                const full = App.escapeHtml((c.nombres || '') + ' ' + (c.apellido_paterno || ''));
                return `
                    <tr>
                        <td>${full}</td>
                        <td>${App.escapeHtml(c.cargo || '')}</td>
                        <td>${App.escapeHtml(c.telefono_empresa || '')}</td>
                        <td>${App.escapeHtml(c.correo_empresa || '')}</td>
                        <td><button class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.selectContactoInModal(${c.id_empresa_contacto}, decodeURIComponent('${encodeURIComponent(full)}'))">Seleccionar</button></td>
                    </tr>
                `;
            }).join('') + '</tbody></table>';

        if (pagDiv) {
            pagDiv.innerHTML = `
                <span style="font-size:13px;color:#6c757d;">${total} contacto(s) — Página ${state.page} de ${totalPages}</span>
                <div>
                    <button class="btn btn-sm btn-secondary" ${state.page === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.changeContactoModalPage(${state.page - 1})">← Anterior</button>
                    <button class="btn btn-sm btn-secondary" ${state.page === totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.changeContactoModalPage(${state.page + 1})">Siguiente →</button>
                </div>
            `;
        }
    },

    changeContactoModalPage(newPage) {
        this._modalContacto.page = newPage;
        this.renderContactoModalPage();
    },

    selectContactoInModal(idContacto, nombre) {
        document.getElementById('id_empresa_contacto').value = idContacto;
        document.getElementById('contacto-display').value = nombre;
        App.closeModal();
    },

    /* ====== Modal: buscar vendedor ====== */
    async showBuscarVendedorModal() {
        this._modalVendedor = { data: [], page: 1, perPage: 100, filtered: null, loading: false };
        App.openModal(`
            <h3><i class="bi bi-person-badge"></i> Buscar vendedor</h3>
            <p style="color:#6c757d;font-size:13px;margin-bottom:8px;">Lista de personas del sistema. Escribe para filtrar.</p>
            <input type="text" id="vendedor-modal-search" class="form-input" placeholder="Nombre o correo..." oninput="App.views.cotizaciones.filterVendedorInModal()">
            <div id="vendedor-modal-results" style="max-height:400px;overflow:auto;margin-top:10px;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando personas...</div>
            </div>
            <div id="vendedor-modal-pagination" style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;"></div>
            <div class="text-right" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
            </div>
        `);
        await this.loadAllVendedores();
    },

    async loadAllVendedores() {
        const state = this._modalVendedor;
        if (state.loading) return;
        state.loading = true;
        const div = document.getElementById('vendedor-modal-results');
        if (div) div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">Cargando personas...</div>';
        try {
            // /personas sin query devuelve TOP 500 personas (filtro del backend)
            const resp = await App.request('/personas', { background: true });
            state.data = Array.isArray(resp) ? resp : (resp.data || []);
            this.renderVendedorModalPage();
        } catch (e) {
            if (div) div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        } finally {
            state.loading = false;
        }
    },

    filterVendedorInModal() {
        clearTimeout(this._modalVendedor._timer);
        this._modalVendedor._timer = setTimeout(() => {
            const q = (document.getElementById('vendedor-modal-search')?.value || '').trim().toLowerCase();
            this._modalVendedor.page = 1;
            if (!q) {
                this._modalVendedor.filtered = null;
            } else {
                this._modalVendedor.filtered = this._modalVendedor.data.filter(p => {
                    const n = ((p.nombres || '') + ' ' + (p.apellido_paterno || '')).toLowerCase();
                    const co = (p.correo || '').toLowerCase();
                    const doc = (p.numero_documento || '').toLowerCase();
                    return n.includes(q) || co.includes(q) || doc.includes(q);
                });
            }
            this.renderVendedorModalPage();
        }, 200);
    },

    renderVendedorModalPage() {
        const state = this._modalVendedor;
        const div = document.getElementById('vendedor-modal-results');
        const pagDiv = document.getElementById('vendedor-modal-pagination');
        if (!div) return;

        const source = state.filtered !== null ? state.filtered : state.data;
        const total = source.length;
        if (total === 0) {
            div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">' +
                (state.filtered ? 'Sin resultados.' : 'No hay personas registradas.') +
                '</div>';
            if (pagDiv) pagDiv.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(total / state.perPage);
        if (state.page > totalPages) state.page = totalPages;
        const start = (state.page - 1) * state.perPage;
        const pageRows = source.slice(start, start + state.perPage);

        div.innerHTML = '<table class="table table-hover table-sm"><thead><tr><th>Nombre</th><th>Correo</th><th>Teléfono</th><th></th></tr></thead><tbody>'
            + pageRows.map(p => {
                const full = App.escapeHtml((p.nombres || '') + ' ' + (p.apellido_paterno || ''));
                return `
                    <tr>
                        <td>${full}</td>
                        <td>${App.escapeHtml(p.correo || '')}</td>
                        <td>${App.escapeHtml(p.telefono || '')}</td>
                        <td><button class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.selectVendedorInModal(${p.id_persona}, decodeURIComponent('${encodeURIComponent(full)}'))">Seleccionar</button></td>
                    </tr>
                `;
            }).join('') + '</tbody></table>';

        if (pagDiv) {
            pagDiv.innerHTML = `
                <span style="font-size:13px;color:#6c757d;">${total} persona(s) — Página ${state.page} de ${totalPages}</span>
                <div>
                    <button class="btn btn-sm btn-secondary" ${state.page === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.changeVendedorModalPage(${state.page - 1})">← Anterior</button>
                    <button class="btn btn-sm btn-secondary" ${state.page === totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.changeVendedorModalPage(${state.page + 1})">Siguiente →</button>
                </div>
            `;
        }
    },

    changeVendedorModalPage(newPage) {
        this._modalVendedor.page = newPage;
        this.renderVendedorModalPage();
    },

    selectVendedorInModal(idVendedor, nombre) {
        document.getElementById('id_vendedor').value = idVendedor;
        document.getElementById('vendedor-display').value = nombre;
        App.closeModal();
    },

    /* ====== Modal: buscar producto ====== */
    async showBuscarProductoModal() {
        this._modalProducto = { data: [], page: 1, perPage: 100, filtered: null, loading: false, tipoFilter: '' };
        App.openModal(`
            <h3><i class="bi bi-box"></i> Buscar producto en catálogo</h3>
            <p style="color:#6c757d;font-size:13px;margin-bottom:8px;">Lista de productos del catálogo. Escribe o usa el filtro por tipo.</p>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="producto-modal-search" class="form-input" placeholder="Código o descripción..." oninput="App.views.cotizaciones.filterProductoInModal()" style="flex:1;">
                <select id="producto-modal-tipo" class="form-select" style="width:200px;" onchange="App.views.cotizaciones.filterProductoInModal()">
                    <option value="">Todos los tipos</option>
                    <option value="SOFTWARE">Software</option>
                    <option value="HARDWARE">Hardware</option>
                    <option value="FACIAL">Facial</option>
                    <option value="SERVICIO_SOFTWARE">Servicio de Software</option>
                    <option value="SERVICIO_HARDWARE">Servicio de Hardware</option>
                    <option value="INSTALACION">Instalación</option>
                    <option value="ACCESORIO">Accesorio</option>
                    <option value="REPUESTO">Repuesto</option>
                    <option value="FOTOCHECK">Fotocheck</option>
                    <option value="DESARROLLO">Desarrollo</option>
                </select>
            </div>
            <div id="producto-modal-results" style="max-height:500px;overflow:auto;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando productos...</div>
            </div>
            <div id="producto-modal-pagination" style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;"></div>
            <div class="text-right" style="margin-top:10px;">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cerrar</button>
            </div>
        `);
        await this.loadAllProductos();
    },

    async loadAllProductos() {
        const state = this._modalProducto;
        if (state.loading) return;
        state.loading = true;
        const div = document.getElementById('producto-modal-results');
        if (div) div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">Cargando productos...</div>';
        try {
            // Pedir todos los productos (limite 2000 en backend)
            const resp = await App.request('/cotizaciones/productos?limite=2000', { background: true });
            state.data = Array.isArray(resp) ? resp : (resp.data || []);
            this.renderProductoModalPage();
        } catch (e) {
            if (div) div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        } finally {
            state.loading = false;
        }
    },

    filterProductoInModal() {
        clearTimeout(this._modalProducto._timer);
        this._modalProducto._timer = setTimeout(() => {
            const q = (document.getElementById('producto-modal-search')?.value || '').trim().toLowerCase();
            const tipo = document.getElementById('producto-modal-tipo')?.value || '';
            this._modalProducto.page = 1;
            if (!q && !tipo) {
                this._modalProducto.filtered = null;
            } else {
                this._modalProducto.filtered = this._modalProducto.data.filter(p => {
                    const codigo = (p.codigo || '').toLowerCase();
                    const desc = (p.descripcion || '').toLowerCase();
                    const tipoProd = (p.id_tipo_producto || '').toLowerCase();
                    const matchQ = !q || codigo.includes(q) || desc.includes(q);
                    const matchTipo = !tipo || tipoProd === tipo.toLowerCase();
                    return matchQ && matchTipo;
                });
            }
            this.renderProductoModalPage();
        }, 200);
    },

    renderProductoModalPage() {
        const state = this._modalProducto;
        const div = document.getElementById('producto-modal-results');
        const pagDiv = document.getElementById('producto-modal-pagination');
        if (!div) return;

        const source = state.filtered !== null ? state.filtered : state.data;
        const total = source.length;
        if (total === 0) {
            div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">' +
                (state.filtered ? 'Sin resultados. Prueba con: SOA-0003, EQA-0006, "Software de Control", "Reloj Marcador", etc.' : 'No hay productos en el catálogo.') +
                '</div>';
            if (pagDiv) pagDiv.innerHTML = '';
            return;
        }

        const totalPages = Math.ceil(total / state.perPage);
        if (state.page > totalPages) state.page = totalPages;
        const start = (state.page - 1) * state.perPage;
        const pageRows = source.slice(start, start + state.perPage);

        const zonaId = parseInt(document.getElementById('id_zona_pricing')?.value) || 0;
        div.innerHTML = '<table class="table table-hover table-sm"><thead><tr><th>Código</th><th>Descripción</th><th>Tipo</th><th class="text-right">Precio</th><th></th></tr></thead><tbody>'
            + pageRows.map(p => {
                let precio = p.precio_cliente;
                if (p.aplica_precio_zona && zonaId == 1) precio = p.precio_lima;
                else if (p.aplica_precio_zona && zonaId == 2) precio = p.precio_conos;
                else if (p.aplica_precio_zona && zonaId == 3) precio = p.precio_balne;
                const precioStr = parseFloat(precio || 0).toFixed(2);
                // Guardar el producto en un mapa temporal por ID
                if (!this._productosModalTmp) this._productosModalTmp = {};
                this._productosModalTmp[p.id_producto] = {
                    id_producto_catalogo: p.id_producto,
                    codigo: p.codigo,
                    descripcion: p.descripcion,
                    precio_unitario: parseFloat(precio || 0)
                };
                return `
                    <tr>
                        <td><strong>${App.escapeHtml(p.codigo)}</strong></td>
                        <td style="max-width:500px;">${App.escapeHtml((p.descripcion || '').substring(0, 100))}${p.descripcion && p.descripcion.length > 100 ? '...' : ''}</td>
                        <td><span class="badge badge-secondary">${App.escapeHtml(p.id_tipo_producto)}</span>${p.aplica_precio_zona ? ' <span class="badge badge-info">zona</span>' : ''}</td>
                        <td class="text-right">S/ ${precioStr}</td>
                        <td><button class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.addProductItemById(${p.id_producto})">Agregar</button></td>
                    </tr>
                `;
            }).join('') + '</tbody></table>';

        if (pagDiv) {
            pagDiv.innerHTML = `
                <span style="font-size:13px;color:#6c757d;">${total} producto(s) — Página ${state.page} de ${totalPages}</span>
                <div>
                    <button class="btn btn-sm btn-secondary" ${state.page === 1 ? 'disabled' : ''} onclick="App.views.cotizaciones.changeProductoModalPage(${state.page - 1})">← Anterior</button>
                    <button class="btn btn-sm btn-secondary" ${state.page === totalPages ? 'disabled' : ''} onclick="App.views.cotizaciones.changeProductoModalPage(${state.page + 1})">Siguiente →</button>
                </div>
            `;
        }
    },

    changeProductoModalPage(newPage) {
        this._modalProducto.page = newPage;
        this.renderProductoModalPage();
    },

    addProductItemById(idProducto) {
        const p = (this._productosModalTmp && this._productosModalTmp[idProducto]);
        if (!p) {
            App.toast('No se encontró el producto. Cierra y abre el modal nuevamente.', 'error');
            return;
        }
        this.addProductItem(p);
        App.toast('Producto agregado: ' + (p.codigo || ''), 'success');
        // NO cerrar el modal — permite agregar varios productos seguidos
    },

    /* ====== Modal: agregar nueva forma de pago ====== */
    showAddFormaPagoModal() {
        App.openModal(`
            <h3><i class="bi bi-credit-card"></i> Agregar nueva forma de pago</h3>
            <div class="form-group">
                <label class="form-label">Descripción <span class="req">*</span></label>
                <textarea id="fp-descripcion" class="form-input" rows="3" placeholder="Ej: 50% Adelantado, saldo contra entrega."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Descuento automático (%)</label>
                <input type="number" id="fp-descuento" class="form-input" value="0" step="0.01" min="0" max="100">
                <small style="color:#6c757d;">Si esta forma de pago aplica un descuento global, indícalo aquí.</small>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveFormaPago()">
                    <i class="bi bi-save"></i> Guardar
                </button>
            </div>
        `);
    },

    async saveFormaPago() {
        const descripcion = document.getElementById('fp-descripcion').value.trim();
        const descuento = parseFloat(document.getElementById('fp-descuento').value) || 0;
        if (!descripcion) { App.toast('La descripción es obligatoria', 'error'); return; }
        try {
            const resp = await App.request('/cotizaciones/formas-pago', {
                method: 'POST', body: { descripcion, descuento_pct: descuento }
            });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            const sel = document.getElementById('id_forma_pago');
            if (sel) sel.value = resp.id_forma_pago;
            App.closeModal();
            App.toast('Forma de pago creada y seleccionada', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Modal: gestionar (CRUD) formas de pago ====== */
    async showGestionarFormasPagoModal() {
        App.openModal(`
            <h3><i class="bi bi-gear"></i> Gestionar formas de pago</h3>
            <div id="gest-fp-list" style="max-height:400px;overflow:auto;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando...</div>
            </div>
            <div style="margin-top:10px;text-align:right;">
                <button class="btn btn-primary" onclick="App.views.cotizaciones.showAddFormaPagoModal()">
                    <i class="bi bi-plus-lg"></i> Nueva forma de pago
                </button>
                <button class="btn btn-secondary" onclick="App.closeModal()">Cerrar</button>
            </div>
        `);
        await this.refreshGestionarFormasPagoList();
    },

    async refreshGestionarFormasPagoList() {
        const div = document.getElementById('gest-fp-list');
        if (!div) return;
        try {
            const resp = await App.request('/cotizaciones/catalogos', { background: true });
            this.state.catalogos = resp;
            const rows = resp.formas_pago || [];
            if (!rows.length) {
                div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">No hay formas de pago.</div>';
                return;
            }
            div.innerHTML = '<table class="table table-sm"><thead><tr><th>Descripción</th><th>Descuento %</th><th>Activo</th><th></th></tr></thead><tbody>'
                + rows.map(f => {
                    // Guardar el objeto en estado temporal para no pasar JSON en onclick
                    if (!this._formasPagoTmp) this._formasPagoTmp = {};
                    this._formasPagoTmp[f.id_forma_pago] = f;
                    return `
                    <tr>
                        <td>${App.escapeHtml(f.descripcion)}</td>
                        <td>${f.descuento_pct || 0}%</td>
                        <td>${f.activo ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-secondary">No</span>'}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="App.views.cotizaciones.showEditFormaPagoModal(${f.id_forma_pago})" title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="App.views.cotizaciones.deleteFormaPago(${f.id_forma_pago}, decodeURIComponent('${encodeURIComponent(f.descripcion.substring(0,40))}'))" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                `;
                }).join('') + '</tbody></table>';
        } catch (e) {
            div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        }
    },

    showEditFormaPagoModal(idFormaPago) {
        // Recuperar el objeto del estado temporal donde se guardó al renderizar la lista
        const forma = (this._formasPagoTmp && this._formasPagoTmp[idFormaPago]) || { descripcion: '', descuento_pct: 0, activo: 1 };
        App.openModal(`
            <h3><i class="bi bi-pencil"></i> Editar forma de pago</h3>
            <div class="form-group">
                <label class="form-label">Descripción <span class="req">*</span></label>
                <textarea id="fp-edit-descripcion" class="form-input" rows="3">${App.escapeHtml(forma.descripcion || '')}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Descuento automático (%)</label>
                <input type="number" id="fp-edit-descuento" class="form-input" value="${forma.descuento_pct || 0}" step="0.01" min="0" max="100">
            </div>
            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" id="fp-edit-activo" ${forma.activo ? 'checked' : ''}> Activa
                </label>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveEditFormaPago(${idFormaPago})">
                    <i class="bi bi-save"></i> Guardar cambios
                </button>
            </div>
        `);
    },

    async saveEditFormaPago(idFormaPago) {
        const body = {
            descripcion: document.getElementById('fp-edit-descripcion').value.trim(),
            descuento_pct: parseFloat(document.getElementById('fp-edit-descuento').value) || 0,
            activo: document.getElementById('fp-edit-activo').checked ? 1 : 0,
        };
        if (!body.descripcion) { App.toast('La descripción es obligatoria', 'error'); return; }
        try {
            await App.request('/cotizaciones/formas-pago/' + idFormaPago, { method: 'PATCH', body });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            App.closeModal();
            await this.refreshGestionarFormasPagoList();
            App.toast('Forma de pago actualizada', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async deleteFormaPago(idFormaPago, descripcion) {
        if (!confirm('¿Eliminar la forma de pago "' + descripcion + '"?\nNo se podrá eliminar si ya está usada en cotizaciones.')) return;
        try {
            await App.request('/cotizaciones/formas-pago/' + idFormaPago, { method: 'DELETE' });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            await this.refreshGestionarFormasPagoList();
            App.toast('Forma de pago eliminada', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Modal: gestionar (CRUD) zonas de pricing ====== */
    async showGestionarZonasModal() {
        App.openModal(`
            <h3><i class="bi bi-gear"></i> Gestionar zonas de pricing</h3>
            <div id="gest-zn-list" style="max-height:400px;overflow:auto;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando...</div>
            </div>
            <div style="margin-top:10px;text-align:right;">
                <button class="btn btn-primary" onclick="App.views.cotizaciones.showAddZonaModal()">
                    <i class="bi bi-plus-lg"></i> Nueva zona
                </button>
                <button class="btn btn-secondary" onclick="App.closeModal()">Cerrar</button>
            </div>
        `);
        await this.refreshGestionarZonasList();
    },

    async refreshGestionarZonasList() {
        const div = document.getElementById('gest-zn-list');
        if (!div) return;
        try {
            const resp = await App.request('/cotizaciones/catalogos', { background: true });
            this.state.catalogos = resp;
            const rows = resp.zonas || [];
            if (!rows.length) {
                div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">No hay zonas.</div>';
                return;
            }
            div.innerHTML = '<table class="table table-sm"><thead><tr><th>Código</th><th>Nombre</th><th>Descripción</th><th>Activa</th><th></th></tr></thead><tbody>'
                + rows.map(z => {
                    if (!this._zonasTmp) this._zonasTmp = {};
                    this._zonasTmp[z.id_zona] = z;
                    return `
                    <tr>
                        <td><strong>${App.escapeHtml(z.codigo)}</strong></td>
                        <td>${App.escapeHtml(z.nombre)}</td>
                        <td style="max-width:300px;">${App.escapeHtml((z.descripcion || '').substring(0, 80))}</td>
                        <td>${z.activo ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-secondary">No</span>'}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="App.views.cotizaciones.showEditZonaModal(${z.id_zona})" title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="App.views.cotizaciones.deleteZona(${z.id_zona}, decodeURIComponent('${encodeURIComponent(z.nombre)}'))" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                `;
                }).join('') + '</tbody></table>';
        } catch (e) {
            div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        }
    },

    showAddZonaModal() {
        App.openModal(`
            <h3><i class="bi bi-plus-lg"></i> Agregar nueva zona</h3>
            <div class="form-group">
                <label class="form-label">Código <span class="req">*</span></label>
                <input type="text" id="zn-codigo" class="form-input" placeholder="Ej: N (Norte), S (Sur), E (Este)..." maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label">Nombre <span class="req">*</span></label>
                <input type="text" id="zn-nombre" class="form-input" placeholder="Ej: Zona Norte, Zona Sur...">
            </div>
            <div class="form-group">
                <label class="form-label">Descripción</label>
                <textarea id="zn-descripcion" class="form-input" rows="3" placeholder="Distritos o zonas geográficas incluidas..."></textarea>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveNewZona()">
                    <i class="bi bi-save"></i> Guardar
                </button>
            </div>
        `);
    },

    async saveNewZona() {
        const body = {
            codigo: document.getElementById('zn-codigo').value.trim(),
            nombre: document.getElementById('zn-nombre').value.trim(),
            descripcion: document.getElementById('zn-descripcion').value.trim() || null,
        };
        if (!body.codigo || !body.nombre) { App.toast('Código y nombre son obligatorios', 'error'); return; }
        try {
            await App.request('/cotizaciones/zonas', { method: 'POST', body });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            await this.refreshGestionarZonasList();
            App.closeModal();
            App.toast('Zona creada', 'success');
            setTimeout(() => this.showGestionarZonasModal(), 300);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    showEditZonaModal(idZona) {
        const zona = (this._zonasTmp && this._zonasTmp[idZona]) || { codigo: '', nombre: '', descripcion: '', activo: 1 };
        App.openModal(`
            <h3><i class="bi bi-pencil"></i> Editar zona</h3>
            <div class="form-group">
                <label class="form-label">Código</label>
                <input type="text" id="zn-edit-codigo" class="form-input" value="${App.escapeHtml(zona.codigo || '')}" maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label">Nombre</label>
                <input type="text" id="zn-edit-nombre" class="form-input" value="${App.escapeHtml(zona.nombre || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">Descripción</label>
                <textarea id="zn-edit-descripcion" class="form-input" rows="3">${App.escapeHtml(zona.descripcion || '')}</textarea>
            </div>
            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" id="zn-edit-activo" ${zona.activo ? 'checked' : ''}> Activa
                </label>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveEditZona(${idZona})">
                    <i class="bi bi-save"></i> Guardar cambios
                </button>
            </div>
        `);
    },

    async saveEditZona(idZona) {
        const body = {
            codigo: document.getElementById('zn-edit-codigo').value.trim(),
            nombre: document.getElementById('zn-edit-nombre').value.trim(),
            descripcion: document.getElementById('zn-edit-descripcion').value.trim() || null,
            activo: document.getElementById('zn-edit-activo').checked ? 1 : 0,
        };
        if (!body.codigo || !body.nombre) { App.toast('Código y nombre son obligatorios', 'error'); return; }
        try {
            await App.request('/cotizaciones/zonas/' + idZona, { method: 'PATCH', body });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            await this.refreshGestionarZonasList();
            App.closeModal();
            App.toast('Zona actualizada', 'success');
            setTimeout(() => this.showGestionarZonasModal(), 300);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async deleteZona(idZona, nombre) {
        if (!confirm('¿Eliminar la zona "' + nombre + '"? Se desactivará (no se borra físicamente).')) return;
        try {
            await App.request('/cotizaciones/zonas/' + idZona, { method: 'DELETE' });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            await this.refreshGestionarZonasList();
            App.toast('Zona desactivada', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Modal: agregar cuenta bancaria ====== */
    /* ====== Modal: gestionar (CRUD) cuentas bancarias ====== */
    async showGestionarCuentasModal() {
        App.openModal(`
            <h3><i class="bi bi-bank"></i> Gestionar cuentas bancarias</h3>
            <div id="gest-ctas-list" style="max-height:400px;overflow:auto;border:1px solid #ddd;border-radius:4px;">
                <div class="text-muted" style="padding:20px;text-align:center;">Cargando...</div>
            </div>
            <div style="margin-top:10px;text-align:right;">
                <button class="btn btn-primary" onclick="App.views.cotizaciones.showCrearCuentaModal()">
                    <i class="bi bi-plus-lg"></i> Nueva cuenta
                </button>
                <button class="btn btn-secondary" onclick="App.closeModal()">Cerrar</button>
            </div>
        `);
        await this.refreshGestionarCuentasList();
    },

    async refreshGestionarCuentasList() {
        const div = document.getElementById('gest-ctas-list');
        if (!div) return;
        try {
            const resp = await App.request('/cotizaciones/catalogos', { background: true });
            this.state.catalogos = resp;
            const rows = resp.cuentas || [];
            if (!rows.length) {
                div.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center;">No hay cuentas bancarias.</div>';
                return;
            }
            if (!this._cuentasTmp) this._cuentasTmp = {};
            div.innerHTML = '<table class="table table-sm"><thead><tr><th>Banco</th><th>N° Cuenta</th><th>CCI</th><th>Moneda</th><th>Titular</th><th></th></tr></thead><tbody>'
                + rows.map(c => {
                    this._cuentasTmp[c.id_cuenta] = c;
                    return `
                    <tr>
                        <td><strong>${App.escapeHtml(c.banco)}</strong></td>
                        <td>${App.escapeHtml(c.numero_cuenta)}</td>
                        <td>${App.escapeHtml(c.cci || '—')}</td>
                        <td>${App.escapeHtml(c.moneda || 'PEN')}</td>
                        <td>${App.escapeHtml(c.titular || '—')}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="App.views.cotizaciones.showEditCuentaModal(${c.id_cuenta})" title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="App.views.cotizaciones.deleteCuentaBancaria(${c.id_cuenta})" title="Eliminar"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                `;
                }).join('') + '</tbody></table>';
        } catch (e) {
            div.innerHTML = '<div class="text-danger" style="padding:20px;">Error: ' + App.escapeHtml(e.message) + '</div>';
        }
    },

    showEditCuentaModal(idCuenta) {
        const c = (this._cuentasTmp && this._cuentasTmp[idCuenta]) || {};
        App.openModal(`
            <h3><i class="bi bi-pencil"></i> Editar cuenta bancaria</h3>
            <div class="form-group">
                <label class="form-label">Banco <span class="req">*</span></label>
                <input type="text" id="ct-edit-banco" class="form-input" value="${App.escapeHtml(c.banco || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">N° de cuenta <span class="req">*</span></label>
                <input type="text" id="ct-edit-numero" class="form-input" value="${App.escapeHtml(c.numero_cuenta || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">CCI</label>
                <input type="text" id="ct-edit-cci" class="form-input" value="${App.escapeHtml(c.cci || '')}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Moneda</label>
                    <select id="ct-edit-moneda" class="form-select">
                        <option value="PEN" ${c.moneda === 'PEN' ? 'selected' : ''}>Soles (PEN)</option>
                        <option value="USD" ${c.moneda === 'USD' ? 'selected' : ''}>Dólares (USD)</option>
                        <option value="EUR" ${c.moneda === 'EUR' ? 'selected' : ''}>Euros (EUR)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Acepta tarjeta</label>
                    <select id="ct-edit-tarjeta" class="form-select">
                        <option value="0" ${!c.acepta_tarjeta ? 'selected' : ''}>No</option>
                        <option value="1" ${c.acepta_tarjeta ? 'selected' : ''}>Sí</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Titular</label>
                <input type="text" id="ct-edit-titular" class="form-input" value="${App.escapeHtml(c.titular || '')}">
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveEditCuenta(${idCuenta})">
                    <i class="bi bi-save"></i> Guardar cambios
                </button>
            </div>
        `);
    },

    async saveEditCuenta(idCuenta) {
        const body = {
            banco: document.getElementById('ct-edit-banco').value.trim(),
            numero_cuenta: document.getElementById('ct-edit-numero').value.trim(),
            cci: document.getElementById('ct-edit-cci').value.trim() || null,
            moneda: document.getElementById('ct-edit-moneda').value,
            titular: document.getElementById('ct-edit-titular').value.trim() || null,
            acepta_tarjeta: document.getElementById('ct-edit-tarjeta').value === '1' ? 1 : 0,
            activo: 1,
        };
        if (!body.banco || !body.numero_cuenta) { App.toast('Banco y N° de cuenta son obligatorios', 'error'); return; }
        try {
            await App.request('/cotizaciones/cuentas-bancarias/' + idCuenta, { method: 'PATCH', body });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            App.closeModal();
            await this.refreshGestionarCuentasList();
            App.toast('Cuenta actualizada', 'success');
            setTimeout(() => this.showGestionarCuentasModal(), 300);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async deleteCuentaBancaria(idCuenta) {
        if (!confirm('¿Eliminar esta cuenta bancaria? No se podrá eliminar si está en uso en cotizaciones.')) return;
        try {
            await App.request('/cotizaciones/cuentas-bancarias/' + idCuenta, { method: 'DELETE' });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            await this.refreshGestionarCuentasList();
            App.toast('Cuenta eliminada', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    showAddCuentaModal() {
        const idEmisora = parseInt(document.getElementById('id_emisora').value) || 0;
        if (!idEmisora) {
            App.toast('Selecciona primero la empresa emisora', 'warning');
            return;
        }
        App.openModal(`
            <h3><i class="bi bi-bank"></i> Agregar cuenta bancaria</h3>
            <p>Elige una opción:</p>
            <button class="btn btn-primary" style="margin:5px;" onclick="App.views.cotizaciones.showSeleccionarCuentaModal()">
                <i class="bi bi-list"></i> Seleccionar cuenta existente
            </button>
            <button class="btn btn-secondary" style="margin:5px;" onclick="App.views.cotizaciones.showCrearCuentaModal()">
                <i class="bi bi-plus-lg"></i> Crear cuenta nueva
            </button>
            <div style="margin-top:15px;text-align:right;">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
            </div>
        `);
    },

    showSeleccionarCuentaModal() {
        const cat = this.state.catalogos;
        const idEmisora = parseInt(document.getElementById('id_emisora').value) || 0;
        const cuentas = (cat.cuentas || []).filter(c => c.id_emisora == idEmisora);
        if (!cuentas.length) {
            App.closeModal();
            App.toast('No hay cuentas para esta emisora. Crea una nueva.', 'warning');
            this.showCrearCuentaModal();
            return;
        }
        const yaSeleccionadas = [parseInt(document.getElementById('id_cuenta_bancaria').value) || 0, ...(this.state.formCuentasAdicionales || [])];
        App.openModal(`
            <h3><i class="bi bi-list"></i> Seleccionar cuenta bancaria</h3>
            <table class="table table-hover table-sm">
                <thead><tr><th>Banco</th><th>N° Cuenta</th><th>CCI</th><th>Moneda</th><th></th></tr></thead>
                <tbody>
                    ${cuentas.map(c => {
                        const yaEsta = yaSeleccionadas.indexOf(c.id_cuenta) !== -1;
                        return `
                            <tr>
                                <td>${App.escapeHtml(c.banco)}</td>
                                <td>${App.escapeHtml(c.numero_cuenta)}</td>
                                <td>${App.escapeHtml(c.cci || '—')}</td>
                                <td>${App.escapeHtml(c.moneda)}</td>
                                <td>${yaEsta
                                    ? '<span class="badge badge-secondary">seleccionada</span>'
                                    : `<button class="btn btn-primary btn-sm" onclick="App.views.cotizaciones.addCuentaAdicional(${c.id_cuenta});App.closeModal();">Agregar</button>`}
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cerrar</button>
            </div>
        `);
    },

    showCrearCuentaModal() {
        const idEmisora = parseInt(document.getElementById('id_emisora').value) || 0;
        App.openModal(`
            <h3><i class="bi bi-bank"></i> Crear nueva cuenta bancaria</h3>
            <input type="hidden" id="nc-id-emisora" value="${idEmisora}">
            <div class="form-group">
                <label class="form-label">Banco <span class="req">*</span></label>
                <input type="text" id="nc-banco" class="form-input" placeholder="Ej: BBVA, BCP, Interbank...">
            </div>
            <div class="form-group">
                <label class="form-label">N° de cuenta <span class="req">*</span></label>
                <input type="text" id="nc-numero" class="form-input" placeholder="Ej: 0011-0147-0100090376">
            </div>
            <div class="form-group">
                <label class="form-label">CCI</label>
                <input type="text" id="nc-cci" class="form-input" placeholder="Código interbancario (opcional)">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Moneda</label>
                    <select id="nc-moneda" class="form-select">
                        <option value="PEN">Soles (PEN)</option>
                        <option value="USD">Dólares (USD)</option>
                        <option value="EUR">Euros (EUR)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Acepta tarjeta</label>
                    <select id="nc-tarjeta" class="form-select">
                        <option value="0">No</option>
                        <option value="1">Sí</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Titular</label>
                <input type="text" id="nc-titular" class="form-input" placeholder="Ej: Ingeniería de Control Digital S.A.">
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveCuentaBancaria()">
                    <i class="bi bi-save"></i> Guardar y agregar
                </button>
            </div>
        `);
    },

    async saveCuentaBancaria() {
        const body = {
            id_emisora: parseInt(document.getElementById('nc-id-emisora').value),
            banco: document.getElementById('nc-banco').value.trim(),
            numero_cuenta: document.getElementById('nc-numero').value.trim(),
            cci: document.getElementById('nc-cci').value.trim() || null,
            moneda: document.getElementById('nc-moneda').value,
            titular: document.getElementById('nc-titular').value.trim() || null,
            acepta_tarjeta: document.getElementById('nc-tarjeta').value === '1',
        };
        if (!body.banco || !body.numero_cuenta) {
            App.toast('Banco y N° de cuenta son obligatorios', 'error'); return;
        }
        try {
            const resp = await App.request('/cotizaciones/cuentas-bancarias', { method: 'POST', body });
            this.state.catalogos = null;
            await this.reloadCatalogosInForm();
            this.addCuentaAdicional(resp.id_cuenta);
            App.closeModal();
            App.toast('Cuenta creada y agregada como adicional', 'success');
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Recargar catálogos en el form sin perder lo que el usuario escribió ====== */
    async reloadCatalogosInForm() {
        const resp = await App.request('/cotizaciones/catalogos', { background: true });
        if (!resp) return; // sesión expirada
        this.state.catalogos = resp;
        const cat = resp || {};
        const currentForma = document.getElementById('id_forma_pago')?.value;
        const currentGarantia = document.getElementById('id_garantia')?.value;
        const currentPlazo = document.getElementById('id_plazo_entrega')?.value;
        const currentValidez = document.getElementById('id_validez')?.value;
        const currentCuenta = document.getElementById('id_cuenta_bancaria')?.value;
        const currentZona = document.getElementById('id_zona_pricing')?.value;

        const selForma = document.getElementById('id_forma_pago');
        if (selForma) {
            selForma.innerHTML = '<option value="">—</option>' + (cat.formas_pago || []).map(f => `<option value="${f.id_forma_pago}">${App.escapeHtml((f.descripcion||'').substring(0,60))}${f.descuento_pct > 0 ? ` (-${f.descuento_pct}%)` : ''}</option>`).join('');
            if (currentForma) selForma.value = currentForma;
        }
        const selGarantia = document.getElementById('id_garantia');
        if (selGarantia) {
            selGarantia.innerHTML = '<option value="">—</option>' + (cat.garantias || []).map(g => `<option value="${g.id_garantia}">${App.escapeHtml((g.descripcion||'').substring(0,60))}</option>`).join('');
            if (currentGarantia) selGarantia.value = currentGarantia;
        }
        const selPlazo = document.getElementById('id_plazo_entrega');
        if (selPlazo) {
            selPlazo.innerHTML = '<option value="">—</option>' + (cat.plazos || []).map(p => `<option value="${p.id_plazo}">${App.escapeHtml((p.descripcion||'').substring(0,60))}</option>`).join('');
            if (currentPlazo) selPlazo.value = currentPlazo;
        }
        const selValidez = document.getElementById('id_validez');
        if (selValidez) {
            selValidez.innerHTML = '<option value="">—</option>' + (cat.valideces || []).map(v => `<option value="${v.id_validez}">${App.escapeHtml(v.descripcion||'')}</option>`).join('');
            if (currentValidez) selValidez.value = currentValidez;
        }
        const selCuenta = document.getElementById('id_cuenta_bancaria');
        if (selCuenta) {
            selCuenta.innerHTML = '<option value="">—</option>' + (cat.cuentas || []).map(c => `<option value="${c.id_cuenta}">${App.escapeHtml((c.banco||'') + ' - ' + (c.numero_cuenta||''))}</option>`).join('');
            if (currentCuenta) selCuenta.value = currentCuenta;
        }
        const selZona = document.getElementById('id_zona_pricing');
        if (selZona) {
            selZona.innerHTML = '<option value="">Ninguna</option>' + (cat.zonas || []).map(z => `<option value="${z.id_zona}">${App.escapeHtml(z.nombre)}</option>`).join('');
            if (currentZona) selZona.value = currentZona;
        }
        this.renderCuentasAdicionales();
    },

    /* ====== Helpers de items ====== */
    addProductItem(p) {
        const tbody = document.getElementById('items-body');
        const i = tbody.children.length;
        const tr = document.createElement('tr');
        // Resaltar la fila nueva con animación
        tr.style.background = '#e8f5e9';
        tr.style.transition = 'background 1.5s';
        // Guardar id_producto_catalogo y unidad_medida como data-attributes
        // del <tr> para que save() pueda incluirlos en el body.
        tr.dataset.idProductoCatalogo = p.id_producto || p.id_producto_catalogo || '';
        tr.dataset.unidadMedida = p.unidad_medida || 'UND';
        tr.innerHTML = `
            <td style="text-align:center;vertical-align:middle;">${i+1}</td>
            <td><input type="text" style="width:120px;font-family:monospace;" class="form-input item-codigo" value="${App.escapeHtml(p.codigo || '')}" onchange="App.views.cotizaciones.recalcRow(this)"></td>
            <td><textarea class="form-input item-desc" rows="2" style="width:100%;min-width:300px;" onchange="App.views.cotizaciones.recalcRow(this)">${App.escapeHtml(p.descripcion || '')}</textarea></td>
            <td><input type="number" class="form-input item-cant" value="${p.cantidad || 1}" min="1" step="1" onchange="App.views.cotizaciones.recalcRow(this)" style="width:55px;text-align:center;"></td>
            <td><input type="text" class="form-input item-um" value="${App.escapeHtml(p.unidad_medida || 'UND')}" style="width:60px;text-align:center;"></td>
            <td><input type="number" class="form-input item-pu" value="${p.precio_unitario || 0}" step="0.01" onchange="App.views.cotizaciones.recalcRow(this)" style="width:90px;text-align:right;"></td>
            <td><input type="number" class="form-input item-desc-pct" value="${p.descuento_pct || 0}" min="0" max="100" step="0.01" onchange="App.views.cotizaciones.recalcRow(this)" style="width:55px;text-align:center;"></td>
            <td class="text-right item-subtotal" style="vertical-align:middle;font-weight:bold;">${parseFloat((p.cantidad || 1) * (p.precio_unitario || 0)).toFixed(2)}</td>
            <td class="center" style="vertical-align:middle;"><input type="checkbox" class="item-igv" ${p.afecto_igv !== undefined ? (p.afecto_igv ? 'checked' : '') : 'checked'}></td>
            <td style="vertical-align:middle;"><button type="button" class="btn btn-danger btn-sm" onclick="this.closest('tr').remove();App.views.cotizaciones.recalcAll();"><i class="bi bi-x"></i></button></td>
        `;
        tbody.appendChild(tr);
        // Hacer scroll a la nueva fila
        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Quitar el resaltado después de 1.5s
        setTimeout(() => { tr.style.background = ''; }, 1500);
        this.recalcAll();
    },

    addEmptyItem() {
        this.addProductItem({codigo:'', descripcion:'', precio_unitario: 0, unidad_medida: 'UND'});
    },

    recalcRow(input) {
        const tr = input.closest('tr');
        const cant = parseFloat(tr.querySelector('.item-cant').value) || 0;
        const pu = parseFloat(tr.querySelector('.item-pu').value) || 0;
        const descPct = parseFloat(tr.querySelector('.item-desc-pct').value) || 0;
        const sub = cant * pu * (1 - descPct/100);
        tr.querySelector('.item-subtotal').textContent = sub.toFixed(2);
        this.recalcAll();
    },

    recalcAll() {
        let subtotal = 0;
        document.querySelectorAll('#items-body tr').forEach(tr => {
            const sub = parseFloat(tr.querySelector('.item-subtotal').textContent) || 0;
            subtotal += sub;
        });
        document.getElementById('t-subtotal').textContent = subtotal.toFixed(2);

        const descPct = parseFloat(document.getElementById('desc_global').value) || 0;
        const descMonto = subtotal * descPct / 100;
        document.getElementById('t-desc-monto').textContent = descMonto.toFixed(2);

        const base = subtotal - descMonto;
        const igvPct = parseFloat(document.getElementById('igv_pct').value) || 0;
        const igv = base * igvPct / 100;
        document.getElementById('t-igv').textContent = igv.toFixed(2);

        const total = base + igv;
        const tTotalTd = document.getElementById('t-total');
        tTotalTd.innerHTML = '<strong>' + total.toFixed(2) + '</strong>';
    },

    async save(id) {
        // Asegurar que el tipo hidden esté sincronizado con el área
        this.actualizarTituloYCodigoPreview();

        // Recolectar datos del form
        const tituloEl = document.getElementById('titulo_documento');
        const zonaEl = document.getElementById('id_zona_pricing');
        const body = {
            id_empresa: parseInt(document.getElementById('id_empresa').value),
            id_empresa_contacto: parseInt(document.getElementById('id_empresa_contacto').value) || null,
            id_sucursal: parseInt(document.getElementById('id_sucursal').value) || null,
            id_tipo: parseInt(document.getElementById('id_tipo').value),
            id_emisora: parseInt(document.getElementById('id_emisora').value),
            id_forma_pago: parseInt(document.getElementById('id_forma_pago').value) || null,
            id_garantia: parseInt(document.getElementById('id_garantia').value) || null,
            id_plazo_entrega: parseInt(document.getElementById('id_plazo_entrega').value) || null,
            id_validez: parseInt(document.getElementById('id_validez').value) || null,
            id_cuenta_bancaria: parseInt(document.getElementById('id_cuenta_bancaria').value) || null,
            cuentas_adicionales: (this.state.formCuentasAdicionales || []).slice(),
            id_zona_pricing: zonaEl ? (parseInt(zonaEl.value) || null) : null,
            id_vendedor: parseInt(document.getElementById('id_vendedor').value) || null,
            titulo_documento: tituloEl ? (tituloEl.value || null) : null,
            moneda: 'PEN',
            igv_pct: parseFloat(document.getElementById('igv_pct').value) || 18,
            descuento_global_pct: parseFloat(document.getElementById('desc_global').value) || 0,
            detalle: [],
        };

        if (!body.id_empresa) { App.toast('Selecciona una empresa cliente', 'error'); return; }
        if (!body.id_tipo) { App.toast('Selecciona un área (Ventas o Soporte)', 'error'); return; }
        if (!body.id_emisora) { App.toast('Selecciona una empresa emisora', 'error'); return; }
        // Validar rangos coherentes de IGV y descuento para evitar totales negativos
        if (isNaN(body.igv_pct) || body.igv_pct < 0 || body.igv_pct > 100) {
            App.toast('IGV % debe estar entre 0 y 100', 'error'); return;
        }
        if (isNaN(body.descuento_global_pct) || body.descuento_global_pct < 0 || body.descuento_global_pct > 100) {
            App.toast('Descuento global % debe estar entre 0 y 100', 'error'); return;
        }

        // Recolectar ítems
        const rows = document.querySelectorAll('#items-body tr');
        if (rows.length === 0) { App.toast('Agrega al menos un ítem', 'error'); return; }
        rows.forEach(tr => {
            body.detalle.push({
                id_producto_catalogo: parseInt(tr.dataset.idProductoCatalogo) || null,
                codigo: tr.querySelector('.item-codigo').value,
                descripcion: tr.querySelector('.item-desc').value,
                cantidad: parseFloat(tr.querySelector('.item-cant').value) || 1,
                unidad_medida: (tr.querySelector('.item-um')?.value || tr.dataset.unidadMedida || 'UND').trim(),
                precio_unitario: parseFloat(tr.querySelector('.item-pu').value) || 0,
                descuento_pct: parseFloat(tr.querySelector('.item-desc-pct').value) || 0,
                afecto_igv: tr.querySelector('.item-igv').checked ? 1 : 0,
            });
        });

        try {
            if (id) {
                await App.request(`/cotizaciones/${id}`, { method: 'PATCH', body });
                App.toast('Cotización actualizada', 'success');
            } else {
                const resp = await App.request('/cotizaciones', { method: 'POST', body });
                App.toast('Cotización ' + resp.codigo + ' creada', 'success');
                App.navigate('/cotizaciones/detalle/' + resp.id_cotizacion);
                return;
            }
            App.navigate('/cotizaciones/detalle/' + id);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Cambiar estado ====== */
    async changeStatus(id) {
        const estado = document.getElementById('nuevo-estado').value;
        const motivo = document.getElementById('motivo-estado').value.trim();
        if (!estado) { App.toast('Selecciona un estado', 'error'); return; }
        if (['APROBADA','RECHAZADA','CANCELADA','CERRADA'].includes(estado) && !motivo) {
            App.toast('Motivo obligatorio para este estado', 'error'); return;
        }
        try {
            await App.request(`/cotizaciones/${id}/status`, { method: 'PATCH', body: { estado, motivo } });
            App.toast('Estado actualizado a ' + estado, 'success');
            App.navigate('/cotizaciones/detalle/' + id);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Registrar comunicación (modal) ====== */
    showComunicacionModal(idCot) {
        App.openModal(`
            <h3>Registrar comunicación</h3>
            <div class="form-group">
                <label>Medio *</label>
                <select id="com-medio" class="form-select">
                    <option value="EMAIL">Correo electrónico</option>
                    <option value="TELEFONO">Teléfono</option>
                    <option value="WHATSAPP">WhatsApp</option>
                    <option value="REUNION">Reunión</option>
                    <option value="PRESENCIAL">Presencial</option>
                    <option value="OTRO">Otro</option>
                </select>
            </div>
            <div class="form-group">
                <label>Tipo de comunicación *</label>
                <select id="com-tipo" class="form-select">
                    <option value="ENVIO_INICIAL">Envío inicial</option>
                    <option value="SEGUIMIENTO">Seguimiento</option>
                    <option value="CONSULTA">Consulta</option>
                    <option value="NEGOCIACION">Negociación</option>
                    <option value="REENVIO">Reenvío</option>
                    <option value="RESPUESTA_CLIENTE">Respuesta del cliente</option>
                    <option value="OTRO">Otro</option>
                </select>
            </div>
            <div class="form-group">
                <label>Descripción *</label>
                <textarea id="com-desc" class="form-input" rows="4" placeholder="Describe lo comunicado..."></textarea>
            </div>
            <div class="form-group">
                <label>Respuesta del cliente</label>
                <textarea id="com-resp" class="form-input" rows="2" placeholder="Respuesta recibida del cliente..."></textarea>
            </div>
            <div class="form-group">
                <label>Resultado / compromiso</label>
                <input type="text" id="com-resultado" class="form-input" placeholder="Ej: Cliente va a evaluar y responde en 1 semana">
            </div>
            <div class="form-group">
                <label>Próximo seguimiento</label>
                <input type="date" id="com-prox" class="form-input">
            </div>
            <div class="form-group">
                <label>Observaciones</label>
                <textarea id="com-obs" class="form-input" rows="2"></textarea>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones.saveComunicacion(${idCot})">Guardar</button>
            </div>
        `);
    },

    async saveComunicacion(idCot) {
        const body = {
            medio: document.getElementById('com-medio').value,
            tipo_comunicacion: document.getElementById('com-tipo').value,
            descripcion: document.getElementById('com-desc').value,
            respuesta_cliente: document.getElementById('com-resp').value || null,
            resultado_compromiso: document.getElementById('com-resultado').value || null,
            fecha_proximo_seguimiento: document.getElementById('com-prox').value || null,
            observaciones: document.getElementById('com-obs').value || null,
        };
        if (!body.descripcion) { App.toast('La descripción es obligatoria', 'error'); return; }
        try {
            await App.request(`/cotizaciones/${idCot}/comunicaciones`, { method: 'POST', body });
            App.closeModal();
            App.toast('Comunicación registrada', 'success');
            App.navigate('/cotizaciones/detalle/' + idCot);
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Exportaciones ====== */
    exportPdf(id) {
        window.open('/api/v1/cotizaciones/' + id + '/export/pdf', '_blank');
    },

    exportXlsx(id) {
        window.open('/api/v1/cotizaciones/' + id + '/export/xlsx', '_blank');
    },

    /* ====== Subir documento ====== */
    async uploadFile(idCot) {
        const fileInput = document.getElementById('doc-file');
        if (!fileInput.files[0]) { App.toast('Selecciona un archivo', 'error'); return; }
        const formData = new FormData();
        formData.append('archivo', fileInput.files[0]);
        formData.append('tipo_documento', 'ADJUNTO_GENERAL');
        try {
            // Usar App.request que ya gestiona auth (cookie de sesión + Bearer
            // token), credentials cross-origin, toasts y manejo de errores.
            // App.request detecta FormData y NO fuerza Content-Type (líneas
            // 503-505 de app.js), permitiendo que el navegador setee el
            // boundary multipart/form-data correcto.
            await App.request('/cotizaciones/' + idCot + '/files', {
                method: 'POST',
                body: formData,
            });
            App.toast('Documento subido', 'success');
            App.navigate('/cotizaciones/detalle/' + idCot);
        } catch (e) {
            // App.request ya mostró el toast de error; solo loguear.
            console.error('[uploadFile]', e);
        }
    },
};

/* ============================================================================
 * Catálogo de Productos (vista administrativa)
 * ============================================================================
 */
App.views.cotizaciones_productos = {
    state: { currentPage: 1, perPage: 50, filters: {} },

    async render(container, params) {
        container.innerHTML = `
            <div class="view-header">
                <h2><i class="bi bi-boxes"></i> Catálogo de Productos</h2>
                <div class="view-actions">
                    <button class="btn btn-primary" onclick="App.views.cotizaciones_productos.showCreateModal()">
                        <i class="bi bi-plus-lg"></i> Nuevo Producto
                    </button>
                    <a class="btn btn-link" href="#/cotizaciones">← Volver a Cotizaciones</a>
                </div>
            </div>

            <div class="card" style="margin-bottom:12px;">
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Buscar</label>
                            <input type="text" id="p-search" class="form-input" placeholder="Código o descripción..." oninput="App.views.cotizaciones_productos.applyFilters()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tipo</label>
                            <select id="p-tipo" class="form-select" onchange="App.views.cotizaciones_productos.applyFilters()">
                                <option value="">Todos los tipos</option>
                                <option value="SOFTWARE">Software</option>
                                <option value="HARDWARE">Hardware</option>
                                <option value="FACIAL">Facial</option>
                                <option value="SERVICIO_SOFTWARE">Servicio de Software</option>
                                <option value="SERVICIO_HARDWARE">Servicio de Hardware</option>
                                <option value="INSTALACION">Instalación</option>
                                <option value="ACCESORIO">Accesorio</option>
                                <option value="REPUESTO">Repuesto</option>
                                <option value="FOTOCHECK">Fotocheck</option>
                                <option value="DESARROLLO">Desarrollo</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div id="productos-table-wrapper" class="card">
                <div class="card-body">Cargando productos...</div>
            </div>
        `;
        await this.load();
    },

    async applyFilters() {
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(async () => {
            this.state.filters = {
                search: document.getElementById('p-search')?.value || '',
                tipo_producto: document.getElementById('p-tipo')?.value || '',
            };
            await this.load();
        }, 300);
    },

    async load() {
        const wrapper = document.getElementById('productos-table-wrapper');
        if (!wrapper) return;
        try {
            // Incluir inactivos para poder mostrarlos y reactivarlos
            const params = new URLSearchParams(this.state.filters);
            params.set('incluir_inactivos', '1');
            params.set('limite', '2000');
            const resp = await App.request('/cotizaciones/productos?' + params.toString(), { background: true });
            if (!resp) {
                // Sesión expirada en background
                wrapper.innerHTML = '<div class="card-body text-center text-muted">Sesión expirada. <a href="/login.php">Inicia sesión de nuevo</a>.</div>';
                return;
            }
            const rows = Array.isArray(resp) ? resp : (resp.data || []);
            if (rows.length === 0) {
                wrapper.innerHTML = `
                    <div class="card-body text-center">
                        <i class="bi bi-inbox" style="font-size:48px;color:#aaa;"></i>
                        <p>No hay productos en el catálogo que coincidan.</p>
                        <p style="margin-top:10px;font-size:12px;">
                            <a href="/api/v1/cotizaciones/diagnostico" target="_blank" class="btn btn-link">
                                <i class="bi bi-stethoscope"></i> Ejecutar diagnóstico
                            </a>
                        </p>
                    </div>`;
                return;
            }
            if (!this._productosTmp) this._productosTmp = {};
            wrapper.innerHTML = `
                <div class="card-body">
                    <table class="table table-hover table-sm">
                        <thead><tr>
                            <th>Código</th><th>Descripción</th><th>Tipo</th><th>Unid.</th>
                            <th class="text-right">P. Cliente</th><th class="text-right">P. Dist.</th><th class="text-right">P. Mayor.</th>
                            <th>Estado</th><th style="width:90px;">Acciones</th>
                        </tr></thead>
                        <tbody>
                            ${rows.map(p => {
                                this._productosTmp[p.id_producto] = p;
                                return `
                                <tr>
                                    <td><strong>${App.escapeHtml(p.codigo)}</strong></td>
                                    <td style="max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${App.escapeHtml(p.descripcion)}">${App.escapeHtml(p.descripcion.substring(0,80))}...</td>
                                    <td>${App.escapeHtml(p.id_tipo_producto)}</td>
                                    <td>${App.escapeHtml(p.unidad_medida)}</td>
                                    <td class="text-right">${parseFloat(p.precio_cliente || 0).toFixed(2)}</td>
                                    <td class="text-right">${p.precio_distribuidor ? parseFloat(p.precio_distribuidor).toFixed(2) : '—'}</td>
                                    <td class="text-right">${p.precio_mayorista ? parseFloat(p.precio_mayorista).toFixed(2) : '—'}</td>
                                    <td>${p.activo ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-secondary">Inactivo</span>'}</td>
                                    <td>
                                        <button class="btn btn-sm btn-secondary" onclick="App.views.cotizaciones_productos.showEditModal(${p.id_producto})" title="Editar"><i class="bi bi-pencil"></i></button>
                                        ${p.activo
                                            ? `<button class="btn btn-sm btn-warning" onclick="App.views.cotizaciones_productos.darDeBaja(${p.id_producto})" title="Dar de baja"><i class="bi bi-arrow-down-circle"></i></button>`
                                            : `<button class="btn btn-sm btn-success" onclick="App.views.cotizaciones_productos.reactivar(${p.id_producto})" title="Reactivar"><i class="bi bi-arrow-up-circle"></i></button>`
                                        }
                                        <button class="btn btn-sm btn-danger" onclick="App.views.cotizaciones_productos.eliminar(${p.id_producto})" title="Eliminar"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            `;}).join('')}
                        </tbody>
                    </table>
                    <div class="text-muted" style="margin-top:8px;">Total: ${rows.length} productos</div>
                </div>
            `;
        } catch (e) {
            wrapper.innerHTML = '<div class="card-body text-danger">Error al cargar productos: ' + App.escapeHtml(e.message) + '</div>';
        }
    },

    /* ====== Modal: Crear nuevo producto ====== */
    showCreateModal() {
        App.openModal(`
            <h3><i class="bi bi-plus-lg"></i> Nuevo producto</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Código <span class="req">*</span></label>
                    <input type="text" id="np-codigo" class="form-input" placeholder="Ej: ACE-0029" style="width:150px;font-family:monospace;">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo <span class="req">*</span></label>
                    <select id="np-tipo" class="form-select">
                        <option value="SOFTWARE">Software</option>
                        <option value="HARDWARE">Hardware</option>
                        <option value="FACIAL">Facial</option>
                        <option value="SERVICIO_SOFTWARE">Servicio de Software</option>
                        <option value="SERVICIO_HARDWARE">Servicio de Hardware</option>
                        <option value="INSTALACION">Instalación</option>
                        <option value="ACCESORIO">Accesorio</option>
                        <option value="REPUESTO">Repuesto</option>
                        <option value="FOTOCHECK">Fotocheck</option>
                        <option value="DESARROLLO">Desarrollo</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Descripción <span class="req">*</span></label>
                <textarea id="np-descripcion" class="form-input" rows="3" placeholder="Descripción completa del producto..."></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Precio cliente (S/.)</label>
                    <input type="number" id="np-precio-cliente" class="form-input" value="0" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Precio distribuidor (S/.)</label>
                    <input type="number" id="np-precio-dist" class="form-input" value="0" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Precio mayorista (S/.)</label>
                    <input type="number" id="np-precio-mayor" class="form-input" value="0" step="0.01" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Unidad de medida</label>
                    <input type="text" id="np-unidad" class="form-input" value="UND" style="width:80px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Afecto a IGV</label>
                    <select id="np-igv" class="form-select">
                        <option value="1">Sí</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones_productos.saveNew()">
                    <i class="bi bi-save"></i> Guardar
                </button>
            </div>
        `);
    },

    async saveNew() {
        const body = {
            codigo: document.getElementById('np-codigo').value.trim(),
            id_tipo_producto: document.getElementById('np-tipo').value,
            descripcion: document.getElementById('np-descripcion').value.trim(),
            precio_cliente: parseFloat(document.getElementById('np-precio-cliente').value) || 0,
            precio_distribuidor: parseFloat(document.getElementById('np-precio-dist').value) || 0,
            precio_mayorista: parseFloat(document.getElementById('np-precio-mayor').value) || 0,
            unidad_medida: document.getElementById('np-unidad').value || 'UND',
            afecto_igv: document.getElementById('np-igv').value === '1',
        };
        if (!body.codigo || !body.descripcion) { App.toast('Código y descripción son obligatorios', 'error'); return; }
        try {
            await App.request('/cotizaciones/productos', { method: 'POST', body });
            App.closeModal();
            App.toast('Producto creado', 'success');
            await this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    /* ====== Modal: Editar producto ====== */
    showEditModal(idProducto) {
        const p = (this._productosTmp && this._productosTmp[idProducto]) || {};
        App.openModal(`
            <h3><i class="bi bi-pencil"></i> Editar producto</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Código</label>
                    <input type="text" id="ep-codigo" class="form-input" value="${App.escapeHtml(p.codigo || '')}" style="width:150px;font-family:monospace;">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select id="ep-tipo" class="form-select">
                        <option value="SOFTWARE" ${p.id_tipo_producto === 'SOFTWARE' ? 'selected' : ''}>Software</option>
                        <option value="HARDWARE" ${p.id_tipo_producto === 'HARDWARE' ? 'selected' : ''}>Hardware</option>
                        <option value="FACIAL" ${p.id_tipo_producto === 'FACIAL' ? 'selected' : ''}>Facial</option>
                        <option value="SERVICIO_SOFTWARE" ${p.id_tipo_producto === 'SERVICIO_SOFTWARE' ? 'selected' : ''}>Servicio de Software</option>
                        <option value="SERVICIO_HARDWARE" ${p.id_tipo_producto === 'SERVICIO_HARDWARE' ? 'selected' : ''}>Servicio de Hardware</option>
                        <option value="INSTALACION" ${p.id_tipo_producto === 'INSTALACION' ? 'selected' : ''}>Instalación</option>
                        <option value="ACCESORIO" ${p.id_tipo_producto === 'ACCESORIO' ? 'selected' : ''}>Accesorio</option>
                        <option value="REPUESTO" ${p.id_tipo_producto === 'REPUESTO' ? 'selected' : ''}>Repuesto</option>
                        <option value="FOTOCHECK" ${p.id_tipo_producto === 'FOTOCHECK' ? 'selected' : ''}>Fotocheck</option>
                        <option value="DESARROLLO" ${p.id_tipo_producto === 'DESARROLLO' ? 'selected' : ''}>Desarrollo</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Descripción</label>
                <textarea id="ep-descripcion" class="form-input" rows="3">${App.escapeHtml(p.descripcion || '')}</textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Precio cliente (S/.)</label>
                    <input type="number" id="ep-precio-cliente" class="form-input" value="${p.precio_cliente || 0}" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Precio distribuidor (S/.)</label>
                    <input type="number" id="ep-precio-dist" class="form-input" value="${p.precio_distribuidor || 0}" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Precio mayorista (S/.)</label>
                    <input type="number" id="ep-precio-mayor" class="form-input" value="${p.precio_mayorista || 0}" step="0.01" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Unidad de medida</label>
                    <input type="text" id="ep-unidad" class="form-input" value="${App.escapeHtml(p.unidad_medida || 'UND')}" style="width:80px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Afecto a IGV</label>
                    <select id="ep-igv" class="form-select">
                        <option value="1" ${p.afecto_igv ? 'selected' : ''}>Sí</option>
                        <option value="0" ${!p.afecto_igv ? 'selected' : ''}>No</option>
                    </select>
                </div>
            </div>
            <div class="text-right">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="App.views.cotizaciones_productos.saveEdit(${idProducto})">
                    <i class="bi bi-save"></i> Guardar cambios
                </button>
            </div>
        `);
    },

    async saveEdit(idProducto) {
        const body = {
            codigo: document.getElementById('ep-codigo').value.trim(),
            id_tipo_producto: document.getElementById('ep-tipo').value,
            descripcion: document.getElementById('ep-descripcion').value.trim(),
            precio_cliente: parseFloat(document.getElementById('ep-precio-cliente').value) || 0,
            precio_distribuidor: parseFloat(document.getElementById('ep-precio-dist').value) || 0,
            precio_mayorista: parseFloat(document.getElementById('ep-precio-mayor').value) || 0,
            unidad_medida: document.getElementById('ep-unidad').value || 'UND',
            afecto_igv: document.getElementById('ep-igv').value === '1',
        };
        if (!body.codigo || !body.descripcion) { App.toast('Código y descripción son obligatorios', 'error'); return; }
        try {
            await App.request('/cotizaciones/productos/' + idProducto, { method: 'PATCH', body });
            App.closeModal();
            App.toast('Producto actualizado', 'success');
            await this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async darDeBaja(idProducto) {
        const motivo = prompt('¿Por qué se da de baja este producto?');
        if (motivo === null) return;
        try {
            await App.request('/cotizaciones/productos/' + idProducto + '/baja', { method: 'POST', body: { motivo: motivo || 'Baja administrativa' } });
            App.toast('Producto dado de baja', 'success');
            await this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async reactivar(idProducto) {
        if (!confirm('¿Reactivar este producto?')) return;
        try {
            await App.request('/cotizaciones/productos/' + idProducto + '/reactivar', { method: 'POST' });
            App.toast('Producto reactivado', 'success');
            await this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },

    async eliminar(idProducto) {
        if (!confirm('¿Eliminar DEFINITIVAMENTE este producto? Esta acción no se puede deshacer.')) return;
        try {
            await App.request('/cotizaciones/productos/' + idProducto, { method: 'DELETE' });
            App.toast('Producto eliminado', 'success');
            await this.load();
        } catch (e) {
            App.toast('Error: ' + e.message, 'error', 8000);
        }
    },
};


/* ============================================================================
 * ALIAS: cotizaciones-productos (con guion) → cotizaciones_productos (con guion bajo)
 * ----------------------------------------------------------------------------
 * El router usa viewName = 'cotizaciones-productos' (con guion) pero la vista
 * se registró como 'cotizaciones_productos' (con guion bajo). Este alias
 * resuelve el problema.
 * ============================================================================
 */
App.views['cotizaciones-productos'] = App.views.cotizaciones_productos;
