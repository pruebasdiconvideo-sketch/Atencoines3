/**
 * assets/js/app_cotizaciones_menu_patch.js
 *
 * Ubicación en tu proyecto:
 *   assets/js/app.js  (añadir al final, o cargar este archivo después de app.js)
 *
 * Registra el ítem "Cotizaciones" en el menú principal y enruta
 * /#/cotizaciones a CotizacionesView.renderList.
 */
(function () {
    'use strict';

    const App = window.App || (window.App = {});

    // 1) Definir el ítem de menú
    App.menuItems = App.menuItems || [];
    if (!App.menuItems.some(m => m.codigo === 'COTIZACIONES')) {
        App.menuItems.push({
            codigo: 'COTIZACIONES',
            nombre: 'Cotizaciones',
            icono: 'bi-file-earmark-text',
            url: '#/cotizaciones',
            orden: 5,
        });
    }

    // 2) Registrar la ruta en el router SPA (si App.router existe)
    if (App.router && typeof App.router.add === 'function') {
        App.router.add('cotizaciones', {
            path: '/cotizaciones',
            render: (container) => {
                if (!window.CotizacionesView) {
                    container.innerHTML = '<div class="alert alert-danger">No se cargó cotizaciones_view.js</div>';
                    return;
                }
                window.CotizacionesView.renderList(container);
            },
            requires: ['COTIZACIONES', 'ver'],
        });
    } else {
        // 3) Fallback: hashchange listener
        window.addEventListener('hashchange', () => {
            if (location.hash.startsWith('#/cotizaciones')) {
                const main = document.getElementById('main') || document.getElementById('app-main');
                if (main && window.CotizacionesView) {
                    window.CotizacionesView.renderList(main);
                }
            }
        });
    }
})();

/* ============================================================
 * En tu index.php (o layout.php), añadir antes de </body>:
 *
 *   <script src="assets/js/app.js" defer></script>
 *   <script src="assets/js/cotizaciones_view.js" defer></script>
 *   <script src="assets/js/app_cotizaciones_menu_patch.js" defer></script>
 * ============================================================

   En el menú principal (donde construyes los items), si usas un bucle
   sobre App.menuItems, el item aparecerá solo. Si los items son estáticos
   en HTML, añadir:

   <a href="#/cotizaciones" class="nav-link" data-modulo="COTIZACIONES">
       <i class="bi bi-file-earmark-text"></i> Cotizaciones
   </a>
 */
