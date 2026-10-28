/* ============================================================================
 *  app.js - Sistema de Atenciones v2 (SPA Router)
 *
 *  Caracteristicas:
 *   - Routing basado en hash (#/dashboard, #/tickets, #/tickets/123, etc.)
 *   - Cliente HTTP para API REST
 *   - Sidebar colapsable en mobile
 *   - Submenus expandibles
 *   - Notificaciones toast
 *   - Modales
 *   - Polling de notificaciones no leidas
 * ========================================================================== */

const App = {
    config: {
        apiBase: '/api/v1',
        user: window.AppUser || null,
        // Permisos cargados desde /auth/permissions al iniciar.
        // Estructura: { DASHBOARD: { ver: 1, registrar: 0, ... }, TICKETS: {...} }
        // es_super_admin = true => bypass total (todos los permisos activos)
        permissions: null,
        isSuperAdmin: false,
    },

    state: {
        sidebarOpen: false,
        deferredPrompt: null,
        notificationsBadge: 0,
        currentRoute: null,
        renderVersion: 0,
        // Auto-logout por inactividad: 5 minutos = 300.000 ms
        lastActivity: Date.now(),
        inactivityTimeoutMs: 5 * 60 * 1000,
        inactivityTimerId: null,
        inactivityWarningShown: false,
    },

    // Registry de vistas - cada vista tiene su render() function
    views: {},

    async init() {
        this.bindEvents();
        this.bindTheme();
        this.bindSidebar();
        this.bindSubmenus();
        this.bindRouting();
        this.registerServiceWorker();
        // Cargar permisos ANTES de enrutar para poder ocultar/mostrar
        // elementos del menú según los permisos del usuario.
        await this.loadPermissions();
        // Ocultar ítems del menú según permisos (oculta los que no puede ver)
        this.applyPermissionsToMenu();
        this.startPermissionObserver(); // FIX: ocultar botones en vistas dinámicas
        this.startNotificationsPolling();
        this.startInactivityWatcher();

        // Cargar la ruta inicial (inteligente: si no tiene DASHBOARD.ver,
        // llevar a Mi Perfil que cualquier usuario autenticado puede ver)
        this.router();
    },

    /* ====== CARGA DE PERMISOS ====== */
    async loadPermissions() {
        if (!this.config.user) return;
        try {
            const resp = await this.request('/auth/permissions', { background: true });
            this.config.permissions = resp.permisos || {};
            this.config.isSuperAdmin = !!resp.es_super_admin;
        } catch (e) {
            // Si falla, asumir sin permisos (la app seguirá funcionando pero
            // ocultará todos los menús excepto Mi Perfil, que siempre es accesible).
            console.warn('[App] loadPermissions falló:', e.message);
            this.config.permissions = {};
            this.config.isSuperAdmin = false;
        }
    },

    /* ====== VERIFICACIÓN DE PERMISOS ======
     *  can('TICKETS', 'ver') => true si el usuario puede ver tickets.
     *  Si es super admin, siempre true.
     */
    can(modulo, accion = 'ver') {
        if (this.config.isSuperAdmin) return true;
        if (!this.config.permissions) return false;
        const mod = this.config.permissions[modulo];
        if (!mod) return false;
        return Number(mod[accion] || 0) === 1;
    },

    /* ====== OCULTAR ÍTEMS DEL MENÚ SEGÚN PERMISOS ======
     *  Recorre el sidebar y oculta cualquier nav-item cuyo data-route
     *  corresponda a un módulo que el usuario no puede ver.
     *  Mi Perfil y Configuración son siempre visibles (cualquier usuario
     *  autenticado puede verlos, aunque internamente muestren u oculten
     *  secciones según permisos).
     */
    applyPermissionsToMenu() {
        // Mapeo de data-route -> (módulo, acción) requerida para verlo
        const routeToPermission = {
            'dashboard':          ['DASHBOARD',  'ver'],
            'tickets':           ['TICKETS',    'ver'],
            'tickets-eliminados': ['TICKETS',    'eliminar'], // solo admin
            'motivos':           ['DIRECTORIOS','ver'],
            'cotizaciones':         ['COTIZACIONES', 'ver'],
            'cotizaciones-productos':['COTIZACIONES', 'ver'],
            'empresas':          ['EMPRESAS',   'ver'],
            'sucursales':        ['EMPRESAS',   'ver'],
            'contactos':         ['EMPRESAS',   'ver'],
            'rubros':            ['DIRECTORIOS','ver'],
            'grupos':            ['DIRECTORIOS','ver'],
            'paises':            ['GEOGRAFIA',  'ver'],
            'divisiones':        ['GEOGRAFIA',  'ver'],
            'usuarios':          ['SEGURIDAD',  'ver'],
            'reportes':          ['REPORTES',   'ver'],
            'auditoria':         ['AUDITORIA',  'ver'],
            'seguridad':        ['SEGURIDAD',  'ver'],
            'notificaciones':    ['NOTIFICACIONES', 'ver'],
            // 'configuracion' y 'perfil' son siempre visibles
        };

        // 1. Ocultar ítems del menú lateral
        document.querySelectorAll('.nav-item').forEach(item => {
            const route = item.dataset.route;
            if (!route) return;
            // Mi Perfil y Configuración: siempre visibles
            if (route === 'configuracion' || route === 'perfil') return;
            const perm = routeToPermission[route];
            if (!perm) return; // si no está mapeado, dejarlo visible
            const [modulo, accion] = perm;
            if (!this.can(modulo, accion)) {
                item.style.display = 'none';
            } else {
                item.style.display = ''; // restaurar por si cambia el rol en runtime
            }
        });

        // 2. Ocultar secciones de menú enteras si todos los hijos están ocultos.
        //    Incluye 'directorio' (rubros/grupos/motivos) que faltaba en la versión original.
        ['tickets', 'empresas', 'geografia', 'directorio', 'cotizaciones'].forEach(key => {
            const submenu = document.getElementById('submenu-' + key);
            if (!submenu) return;
            const parentItem = submenu.previousElementSibling;
            const visibleChildren = submenu.querySelectorAll('.nav-item:not([style*="display: none"])').length;
            if (visibleChildren === 0) {
                if (parentItem) parentItem.style.display = 'none';
                submenu.style.display = 'none';
            }
        });

        // 3. Ocultar botones del header y de cualquier vista ya cargada
        //    con data-requires-permission="MODULO:accion"
        this.applyPermissionsToDynamic(document.body);
    },

    /* ====== APLICAR PERMISOS A ELEMENTOS DINÁMICOS ======
     *  Llamar desde views.js al final de cada render():
     *    App.applyPermissionsToDynamic(container);
     *  También lo usa applyPermissionsToMenu para cubrir el header y la
     *  vista actual.
     */
    applyPermissionsToDynamic(root) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-requires-permission]').forEach(el => {
            const perm = el.dataset.requiresPermission.split(':');
            if (perm.length !== 2) return;
            const [modulo, accion] = perm;
            if (!this.can(modulo, accion)) {
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.classList.add('perm-hidden');
            } else {
                el.style.display = '';
                el.removeAttribute('aria-hidden');
                el.classList.remove('perm-hidden');
            }
        });
    },

    /* ====== OBSERVADOR AUTOMÁTICO DE PERMISOS ======
     *  Observa #view-container y reaplica permisos cuando se renderiza
     *  nueva UI (botones, links, etc.). Activar en init().
     */
    startPermissionObserver() {
        const target = document.getElementById('view-container') || document.body;
        if (!target || !window.MutationObserver) return;
        if (this._permObserver) return; // ya activo
        this._permObserver = new MutationObserver((mutations) => {
            let shouldApply = false;
            for (const m of mutations) {
                if (m.addedNodes && m.addedNodes.length > 0) {
                    shouldApply = true;
                    break;
                }
            }
            if (shouldApply) {
                cancelAnimationFrame(this._permRaf);
                this._permRaf = requestAnimationFrame(() => {
                    this.applyPermissionsToDynamic(target);
                });
            }
        });
        this._permObserver.observe(target, { childList: true, subtree: true });
    },

    /* ====== HELPERS PARA VIEWS.JS ======
     *  Permite marcar botones con data-requires-permission en template
     *  strings y dejarlos ocultos automáticamente.
     *  Ejemplo:
     *    <button ${App.permissionAttrs('TICKETS','registrar')}>Nuevo</button>
     */
    permissionAttrs(modulo, accion) {
        const has = this.can(modulo, accion);
        const attr = `data-requires-permission="${modulo}:${accion}"`;
        return has ? attr : `${attr} style="display:none" aria-hidden="true"`;
    },

    // Atajos para usar en condicionales dentro de views.js
    canView(modulo)    { return this.can(modulo, 'ver'); },
    canCreate(modulo)  { return this.can(modulo, 'registrar'); },
    canEdit(modulo)    { return this.can(modulo, 'modificar'); },
    canDelete(modulo)  { return this.can(modulo, 'eliminar'); },
    canApprove(modulo) { return this.can(modulo, 'aprobar'); },
    canExport(modulo)  { return this.can(modulo, 'exportar'); },
    canPrint(modulo)   { return this.can(modulo, 'imprimir'); },

    /* ====== VERIFICAR SI UNA RUTA ES ACCESIBLE ======
     *  Retorna true si el usuario puede navegar a esa ruta.
     *  Se usa en router() para redirigir a otra vista si no tiene permiso.
     */
    canAccessRoute(viewName) {
        // Perfil y Configuración son siempre accesibles
        if (viewName === 'perfil' || viewName === 'configuracion') return true;
        // Super admin: todo accesible
        if (this.config.isSuperAdmin) return true;
        // Si no hay permisos cargados aún (primer render), permitir todo
        // temporalmente para no romper la carga inicial.
        if (!this.config.permissions) return true;
        const routeToPermission = {
            'dashboard':          ['DASHBOARD',  'ver'],
            'tickets':           ['TICKETS',    'ver'],
            'tickets-eliminados': ['TICKETS',    'eliminar'],
            'motivos':           ['DIRECTORIOS','ver'],
            'cotizaciones':         ['COTIZACIONES', 'ver'],
            'cotizaciones-productos':['COTIZACIONES', 'ver'],
            'empresas':          ['EMPRESAS',   'ver'],
            'sucursales':        ['EMPRESAS',   'ver'],
            'contactos':         ['EMPRESAS',   'ver'],
            'rubros':            ['DIRECTORIOS','ver'],
            'grupos':            ['DIRECTORIOS','ver'],
            'paises':            ['GEOGRAFIA',  'ver'],
            'divisiones':        ['GEOGRAFIA',  'ver'],
            'usuarios':          ['SEGURIDAD',  'ver'],
            'reportes':          ['REPORTES',   'ver'],
            'auditoria':         ['AUDITORIA',  'ver'],
            'seguridad':        ['SEGURIDAD',  'ver'],
            'notificaciones':    ['NOTIFICACIONES', 'ver'],
        };
        const perm = routeToPermission[viewName];
        if (!perm) return true; // rutas no mapeadas (login, logout, etc.) son accesibles
        return this.can(perm[0], perm[1]);
    },

    /* ====== AUTO-LOGOUT POR INACTIVIDAD (5 minutos) ======
     *  Si el usuario no interactúa con el sistema en 5 minutos (click,
     *  keypress, scroll, mousemove, touch), se cierra la sesión
     *  automáticamente y se redirige a /login.php?error=expired.
     *
     *  Implementación:
     *   - Cada evento de usuario actualiza state.lastActivity.
     *   - Un setInterval de 10s revisa si (now - lastActivity) > 5min.
     *   - Al expirar, redirige a /logout.php que cierra la sesión del lado
     *     del servidor y redirige a /login.php?logout=1.
     *   - Se muestra un toast de advertencia 30s antes para avisar al usuario.
     */
    startInactivityWatcher() {
        // Eventos que cuentan como actividad del usuario
        const activityEvents = ['mousedown', 'keydown', 'touchstart', 'scroll', 'mousemove'];
        const updateActivity = () => {
            this.state.lastActivity = Date.now();
            this.state.inactivityWarningShown = false;
        };
        activityEvents.forEach(ev => {
            // passive: true para no bloquear el scroll; capture para capturar temprano
            document.addEventListener(ev, updateActivity, { passive: true, capture: true });
        });

        // Verificar cada 10 segundos si expiró la inactividad
        this.state.inactivityTimerId = setInterval(() => {
            const elapsed = Date.now() - this.state.lastActivity;
            const remaining = this.state.inactivityTimeoutMs - elapsed;

            // Aviso 30 segundos antes de expirar (solo una vez)
            if (!this.state.inactivityWarningShown
                && remaining > 0 && remaining <= 30000) {
                this.state.inactivityWarningShown = true;
                this.toast(
                    'Su sesión se cerrará en 30 segundos por inactividad. ' +
                    'Haga clic para mantenerla activa.',
                    'warning',
                    30000
                );
            }

            // Expiró: redirigir al logout
            if (elapsed >= this.state.inactivityTimeoutMs) {
                clearInterval(this.state.inactivityTimerId);
                console.warn('[Inactivity] Sesión cerrada por inactividad (5 min sin actividad)');
                // Redirigir a logout.php con ?reason=inactivity para que
                // login.php muestre "Su sesión ha expirado" en vez de
                // "Sesión cerrada correctamente".
                window.location.href = '/logout.php?reason=inactivity';
            }
        }, 10000);

        // Reset en unload para limpiar el timer (mejor que dejarlo colgado)
        window.addEventListener('beforeunload', () => {
            if (this.state.inactivityTimerId) {
                clearInterval(this.state.inactivityTimerId);
            }
        });
    },

    /* ====== EVENTOS GLOBALES ====== */
    bindEvents() {
        // Capturar prompt de instalacion PWA
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.state.deferredPrompt = e;
            document.querySelector('[data-action="install-pwa"]')?.classList.remove('hidden');
        });

        // Click en boton instalar PWA
        document.querySelector('[data-action="install-pwa"]')?.addEventListener('click', async () => {
            if (!this.state.deferredPrompt) return;
            this.state.deferredPrompt.prompt();
            const choice = await this.state.deferredPrompt.userChoice;
            if (choice.outcome === 'accepted') {
                this.toast('App instalada', 'success');
            }
            this.state.deferredPrompt = null;
            document.querySelector('[data-action="install-pwa"]')?.classList.add('hidden');
        });

        // El botón "Salir" (id=btnLogout) ahora es un <a href="/logout.php">
        // simple gestionado por el servidor — no necesita handler JS.
        // El cierre de sesión se hace en logout.php (destruye sesión + borra
        // cookie + redirige a /login.php), evitando la race condition del
        // fetch anterior.
    },

    bindTheme() {
        const root = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        let savedTheme = null;
        try { savedTheme = localStorage.getItem('atenciones-theme'); } catch (e) { }
        if (savedTheme === 'light' || savedTheme === 'dark') root.dataset.theme = savedTheme;

        const updateButton = () => {
            const theme = root.dataset.theme || (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (toggle) {
                toggle.textContent = theme === 'dark' ? '☀' : '☾';
                toggle.title = theme === 'dark' ? 'Cambiar a modo dia' : 'Cambiar a modo noche';
                toggle.setAttribute('aria-label', toggle.title);
                toggle.setAttribute('aria-pressed', String(theme === 'dark'));
            }
        };

        updateButton();
        toggle?.addEventListener('click', () => {
            const currentTheme = root.dataset.theme || (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            root.dataset.theme = nextTheme;
            try { localStorage.setItem('atenciones-theme', nextTheme); } catch (e) { }
            updateButton();
        });
    },

    /* ====== SIDEBAR MODO HAMBURGUESA SIEMPRE ====== */
    bindSidebar() {
        const toggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        // Abrir/cerrar sidebar
        const openSidebar = () => {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            this.state.sidebarOpen = true;
        };
        const closeSidebar = () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            this.state.sidebarOpen = false;
        };

        toggle?.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });

        overlay?.addEventListener('click', closeSidebar);

        // Cerrar sidebar con tecla Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.state.sidebarOpen) closeSidebar();
        });

        // Cerrar sidebar al hacer click en cualquier nav-item que tenga href
        document.addEventListener('click', (e) => {
            const navItem = e.target.closest('.nav-item[href]');
            if (navItem) {
                // Pequeno delay para que el navegador procese el cambio de hash
                setTimeout(closeSidebar, 100);
            }
        });
    },

    /* ====== SUBMENUS EXPANDIBLES ====== */
    bindSubmenus() {
        document.querySelectorAll('[data-toggle-submenu]').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const key = item.dataset.toggleSubmenu;
                const submenu = document.getElementById('submenu-' + key);
                if (submenu) {
                    submenu.classList.toggle('open');
                    item.classList.toggle('expanded');
                }
            });
        });
    },

    /* ====== ROUTING ====== */
    bindRouting() {
        window.addEventListener('hashchange', () => this.router());
        // Tambien escuchar popstate por si usamos history API en futuro
        window.addEventListener('popstate', () => this.router());
    },

    async router() {
        const renderVersion = ++this.state.renderVersion;
        const hash = window.location.hash.slice(1) || '/dashboard';
        const parts = hash.split('/').filter(Boolean);   // ['tickets', '123']
        let viewName = parts[0] || 'dashboard';
        const params = parts.slice(1);   // ['123']

        // VERIFICAR ACCESO: si el usuario no tiene permiso para ver esta ruta,
        // redirigir a /perfil (que cualquier usuario autenticado puede ver).
        // Caso especial: si ni siquiera puede ver Dashboard, lo llevamos a /perfil.
        if (!this.canAccessRoute(viewName)) {
            console.warn('[Router] Sin permiso para acceder a:', viewName, '→ redirigiendo a /perfil');
            // No usar App.navigate para no duplicar en historial
            window.location.replace('#/perfil');
            // Mostrar toast informativo
            this.toast('No tiene permiso para acceder a "' + viewName + '". Redirigiendo a Mi Perfil.', 'warning', 6000);
            return;
        }

        this.state.currentRoute = { viewName, params };

        // Actualizar nav-item activo
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
            const route = item.dataset.route || item.getAttribute('href')?.replace('#/', '');
            if (route === viewName) item.classList.add('active');
        });

        // Renderizar la vista
        const view = this.views[viewName] || this.views.notFound;
        const container = document.getElementById('appView');
        container.innerHTML = '<div class="loading-container"><div class="spinner spinner-lg"></div><p>Cargando...</p></div>';

        try {
            await view.render(container, params);
        } catch (err) {
            if (renderVersion !== this.state.renderVersion) return;
            console.error('[Router] Error:', err);
            container.innerHTML = `
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state__icon">⚠️</div>
                        <div class="empty-state__title">Error al cargar la vista</div>
                        <div class="empty-state__desc">${this.escapeHtml(err.message)}</div>
                        <button class="btn btn-primary" onclick="location.reload()">Reintentar</button>
                    </div>
                </div>`;
        }
    },

    navigate(path) {
        window.location.hash = path;
    },

    /* ====== HTTP ====== */
    async request(path, opts = {}) {
        const url = this.config.apiBase + path;
        const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
        const options = {
            credentials: 'include',
            ...opts,
            headers,
        };
        const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
        if (isFormData) {
            delete options.headers['Content-Type'];
        } else if (options.body && typeof options.body === 'object') {
            options.body = JSON.stringify(options.body);
        }
        // OPTIMIZACIÓN: AbortController para cancelar peticiones lentas y evitar
        // que se acumulen en el navegador cuando el usuario cambia de vista.
        // Timeout por defecto: 20s. Se puede sobrescribir con opts.timeout.
        const timeoutMs = opts.timeout || 20000;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        options.signal = options.signal || controller.signal;
        try {
            const r = await fetch(url, options);
            clearTimeout(timer);
            const text = await r.text();
            let j;
            try {
                j = JSON.parse(text);
            } catch {
                const contentType = r.headers?.get?.('content-type') || '';
                const isHtml = /text\/html/i.test(contentType) || /^\s*<(?:!doctype|html|head|body)\b/i.test(text);
                const message = r.status >= 500 || isHtml
                    ? `Error del servidor (HTTP ${r.status}) al consultar ${path}. Revise el registro PHP/IIS.`
                    : (text.trim().slice(0, 300) || `Respuesta no valida del servidor (HTTP ${r.status}).`);
                j = { ok: false, message };
            }

            if (!r.ok || !j.ok) {
                if (r.status === 401) {
                    // Sesión expirada.
                    // - En peticiones normales: redirigir al login.
                    // - En peticiones en background (polling, modales): retornar null
                    //   explícitamente para que el caller pueda distinguir "sesión
                    //   expirada" de "respuesta vacía" y hacer `if (!resp) return;`
                    //   en vez de `.data` que rompe con TypeError.
                    if (!opts.background) {
                        window.location.href = '/login.php';
                    }
                    return null;
                }
                if (r.status === 403) {
                    // 403 Forbidden: el usuario no tiene permiso.
                    // Crear un error con metadata para que la vista pueda
                    // mostrar un mensaje más amigable en vez de un toast.
                    const permErr = new Error(j.message || 'No tiene permiso para esta acción');
                    permErr.code = 'forbidden';
                    permErr.httpStatus = 403;
                    permErr.errorCode = j.error || 'forbidden';
                    throw permErr;
                }
                throw new Error(j.message || `HTTP ${r.status}`);
            }
            // Si el backend devolvió un error estructurado, devolverlo como objeto para
            // que las vistas puedan inspeccionar el error_code y mostrar ayuda.
            return j.data !== undefined ? j.data : j;
        } catch (err) {
            clearTimeout(timer);
            // No mostrar toast para peticiones marcadas como background (polling).
            if (!opts.background) {
                // Para errores 403, el toast ya lo puede mostrar la vista si quiere,
                // pero por defecto lo mostramos aquí con tono warning (no error).
                if (err.httpStatus === 403) {
                    console.warn('[App.request] 403:', path, err.message);
                    // Solo mostrar toast si la vista no lo va a manejar
                    if (!opts.silent) {
                        this.toast(err.message, 'warning', 5000);
                    }
                } else {
                    console.error('[App.request]', path, err);
                    if (!opts.silent) {
                        this.toast(err.message || 'Error de red', 'error');
                    }
                }
            }
            throw err;
        }
    },

    /* ====== NOTIFICACIONES ======
     *  Polling cada 90s (antes 60s). Con 5000 usuarios concurrentes esto
     *  reduce la carga del endpoint /notificaciones/unread-count en un 33%.
     *  Además, marca las peticiones como background para no mostrar toasts
     *  ni redirigir al login si la sesión caduca (evita interrumpir modales).
     */
    startNotificationsPolling() {
        if (!this.config.user) return;
        // Si el usuario no tiene permiso para ver notificaciones, no hacer polling
        // (evita errores 500 por permiso denegado en cada llamada automática).
        if (!this.can('NOTIFICACIONES', 'ver')) return;
        const poll = async () => {
            try {
                const data = await this.request('/notificaciones/unread-count', { background: true });
                this.state.notificationsBadge = data.total || 0;
                this.updateNotificationsBadge();
            } catch (e) { /* offline o sesión expirada: silencioso */ }
        };
        poll();
        // 90 segundos en vez de 60: reduce ~33% la carga del servidor con
        // 5000 usuarios concurrentes sin afectar UX (las notificaciones push
        // se reciben en tiempo real via Web Push API).
        setInterval(poll, 90_000);
    },

    updateNotificationsBadge() {
        document.querySelectorAll('[data-notif-badge]').forEach(el => {
            const count = this.state.notificationsBadge;
            if (count > 0) {
                el.textContent = count > 99 ? '99+' : count;
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
    },

    /* ====== TOAST ====== */
    toast(message, type = 'info', duration = 4000) {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 200);
        }, duration);
    },

    /* ====== MODAL (definido en views.js como App.showModal) ====== */
    // App.showModal y App.closeModal estan definidos en views.js
    // para evitar duplicacion.

    /* ====== PWA ====== */
    async registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;
        try {
            await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            console.info('[PWA] Service worker registrado');
        } catch (err) {
            console.warn('[PWA] No se pudo registrar SW', err);
        }
    },

    /* ====== HELPERS DE FORMATO ====== */
    escapeHtml(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    },

    formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-PE', { year: 'numeric', month: '2-digit', day: '2-digit' });
    },

    formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleString('es-PE', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit'
        });
    },

    formatNumber(n, decimals = 0) {
        if (n == null || isNaN(n)) return '-';
        return new Intl.NumberFormat('es-PE', { minimumFractionDigits: decimals }).format(n);
    },

    /* ====== UI HELPERS ====== */
    badge(text, type = 'muted') {
        return `<span class="badge badge-${type}">${this.escapeHtml(text)}</span>`;
    },

    btn(label, opts = {}) {
        const cls = ['btn'];
        if (opts.variant) cls.push(`btn-${opts.variant}`);
        if (opts.size) cls.push(`btn-${opts.size}`);
        if (opts.icon) cls.push('btn-icon');
        const attrs = Object.entries(opts.attrs || {})
            .map(([k, v]) => `${k}="${this.escapeHtml(v)}"`).join(' ');
        return `<button class="${cls.join(' ')}" ${attrs}>${opts.icon ? opts.icon : ''}<span>${this.escapeHtml(label)}</span></button>`;
    },

    /* ====== ESTADO VACIO ====== */
    emptyState(icon, title, desc, action) {
        return `<div class="empty-state">
            <div class="empty-state__icon">${icon}</div>
            <div class="empty-state__title">${this.escapeHtml(title)}</div>
            <div class="empty-state__desc">${this.escapeHtml(desc)}</div>
            ${action || ''}
        </div>`;
    },

    /* ====== TABLA GENERICA ====== */
    table({ columns, rows, empty = 'Sin datos' }) {
        if (!rows || rows.length === 0) {
            return `<div class="table-wrap"><table><thead><tr>${columns.map(c => `<th>${this.escapeHtml(c.label)}</th>`).join('')}</tr></thead></table><div class="table-empty">${this.escapeHtml(empty)}</div></div>`;
        }
        const head = columns.map(c => `<th>${this.escapeHtml(c.label)}</th>`).join('');
        const body = rows.map(row =>
            `<tr>${columns.map(c => `<td>${c.render ? c.render(row) : this.escapeHtml(row[c.key] ?? '')}</td>`).join('')}</tr>`
        ).join('');
        return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
    },

    /* ====== PAGINACION (cliente) ======
     *  opts:
     *    - page:        página actual (1-based)
     *    - totalPages:  total de páginas
     *    - total:       total de registros
     *    - perPage:     registros por página
     *    - onChange:    callback(pagina)
     *
     *  Renderiza:  [«] [1] [2] [3] … [10] [»]   ·  Mostrando 1-50 de 487
     */
    renderPagination({ page, totalPages, total, perPage, onChange }) {
        if (totalPages <= 1) {
            // Aunque sea una sola página, mostramos el contador de registros
            return `<div class="pagination-info">
                <span class="pagination-info__count">
                    <i class="bi bi-list-ul" aria-hidden="true"></i>
                    ${this.formatNumber(total)} registro${total === 1 ? '' : 's'}
                </span>
            </div>`;
        }
        // Construir lista de páginas visibles (ventana de 5 alrededor de la actual)
        const window = 2;
        const pages = new Set([1, totalPages, page]);
        for (let i = 1; i <= window; i++) {
            if (page - i >= 1) pages.add(page - i);
            if (page + i <= totalPages) pages.add(page + i);
        }
        const sorted = [...pages].sort((a, b) => a - b);
        // Insertar elipsis donde haya saltos
        const items = [];
        let prev = 0;
        sorted.forEach(p => {
            if (p - prev > 1) items.push('ellipsis');
            items.push(p);
            prev = p;
        });
        const from = (page - 1) * perPage + 1;
        const to = Math.min(page * perPage, total);
        const btn = (label, p, opts = {}) =>
            `<button type="button" class="pagination__btn${opts.active ? ' active' : ''}"${opts.disabled ? ' disabled' : ''} data-page="${p}" ${opts.ariaLabel ? `aria-label="${opts.ariaLabel}"` : ''}>${label}</button>`;
        const html = items.map(item => {
            if (item === 'ellipsis') return `<span class="pagination__ellipsis" aria-hidden="true">…</span>`;
            if (item === page) return btn(item, item, { active: true, ariaLabel: `Página ${item} (actual)` });
            return btn(item, item, { ariaLabel: `Ir a página ${item}` });
        }).join('');
        // Envolver en un contenedor con un identificador único
        const containerId = 'pagination-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        setTimeout(() => {
            const container = document.getElementById(containerId);
            if (container) {
                container.querySelectorAll('.pagination__btn[data-page]').forEach(button => {
                    button.addEventListener('click', () => {
                        const p = Number(button.dataset.page);
                        if (p >= 1 && p <= totalPages && p !== page) onChange(p);
                    });
                });
            }
        }, 0);
        return `<div class="pagination-wrap" id="${containerId}">
            <div class="pagination">${btn('<i class="bi bi-chevron-left" aria-hidden="true"></i>', page - 1, { disabled: page === 1, ariaLabel: 'Página anterior' })}${html}${btn('<i class="bi bi-chevron-right" aria-hidden="true"></i>', page + 1, { disabled: page === totalPages, ariaLabel: 'Página siguiente' })}</div>
            <div class="pagination-info">
                <span class="pagination-info__count">
                    Mostrando <strong>${this.formatNumber(from)}–${this.formatNumber(to)}</strong> de <strong>${this.formatNumber(total)}</strong>
                </span>
            </div>
        </div>`;
    },

    /* Helper para paginar un array en cliente.
     *  Devuelve { rows, page, totalPages, total, perPage }.
     */
    paginate(rows, page = 1, perPage = 50) {
        const total = rows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        const safePage = Math.min(Math.max(1, page), totalPages);
        const start = (safePage - 1) * perPage;
        return {
            rows: rows.slice(start, start + perPage),
            page: safePage,
            totalPages,
            total,
            perPage,
        };
    },

    /* ====== PIE DE PAGINA PROFESIONAL (todas las vistas) ====== */
    renderFooter(opts = {}) {
        const moduleName = opts.moduleName || 'Módulo';
        const year = new Date().getFullYear();
        const user = this.config.user;
        const userName = user ? (user.nombre || user.correo || 'Usuario') : 'Usuario';
        const now = new Date();
        const lastUpdate = now.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
        return `<footer class="app-view-footer" role="contentinfo">
            <div class="app-view-footer__left">
                <span class="app-view-footer__module">
                    <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> ${this.escapeHtml(moduleName)}
                </span>
                <span class="app-view-footer__separator" aria-hidden="true">·</span>
                <span class="app-view-footer__user">
                    <i class="bi bi-person-circle" aria-hidden="true"></i> ${this.escapeHtml(userName)}
                </span>
            </div>
            <div class="app-view-footer__right">
                <span class="app-view-footer__updated">
                    <i class="bi bi-clock-history" aria-hidden="true"></i> Actualizado: ${lastUpdate}
                </span>
                <span class="app-view-footer__separator" aria-hidden="true">·</span>
                <span class="app-view-footer__brand">Sistema de Atenciones © ${year}</span>
            </div>
        </footer>`;
    },
};

window.App = App;

// Auto-init
document.addEventListener('DOMContentLoaded', () => App.init());
