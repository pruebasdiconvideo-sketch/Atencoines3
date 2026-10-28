<?php
/**
 * snippets_index_php.php
 *
 * Fragmentos de código a integrar en index.php del proyecto Atenciones.
 *
 * ESTE ARCHIVO NO SE EJECUTA DIRECTAMENTE.
 * Es solo referencia de los cambios que debes hacer en tu index.php existente.
 *
 * Hay 3 cambios a aplicar en index.php:
 *   1) Agregar el tag <script> para cotizaciones.js
 *   2) Agregar el item de menú "Cotizaciones" en el sidebar
 *   3) (Opcional) Agregar el módulo en la función canAccessRoute de app.js
 */
?>

<?php
/* =========================================================================
 *  CAMBIO 1: agregar el tag <script> para cotizaciones.js
 * =========================================================================
 *
 *  Busca la línea en index.php donde se carga views.js:
 *
 *      <script src="/assets/js/views.js?v=<?= $assetHash('assets/js/views.js') ?>"></script>
 *
 *  Y agrega JUSTO DEBAJO esta línea:
 */
?>

<script src="/assets/js/cotizaciones.js?v=<?= $assetHash('assets/js/cotizaciones.js') ?>"></script>


<?php
/* =========================================================================
 *  CAMBIO 2: agregar el item "Cotizaciones" en el sidebar (menu lateral)
 * =========================================================================
 *
 *  Busca el bloque de navegación lateral. Suele verse así (con sus variantes
 *  de routes y svg):
 *
 *      <div class="nav-item" data-route="dashboard" ...>Dashboard</div>
 *      <div class="nav-item" data-toggle-submenu="tickets" ...>Tickets</div>
 *      ...
 *
 *  Pega este bloque en la posición que prefieras (sugerido: justo antes
 *  del bloque de tickets, o después de dashboard):
 */
?>

<div class="nav-item" data-route="cotizaciones" onclick="App.navigate('/cotizaciones')" title="Cotizaciones">
    <svg class="nav-item__icon" viewBox="0 0 24 24" fill="currentColor">
        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
    </svg>
    <span class="nav-item__label">Cotizaciones</span>
</div>


<?php
/* =========================================================================
 *  CAMBIO 3 (opcional): permitir el route "cotizaciones*" en app.js
 * =========================================================================
 *
 *  Tu app.js probablemente tiene una función canAccessRoute(viewName) que
 *  valida permisos antes de dejar navegar. Si al hacer click en "Cotizaciones"
 *  te redirige a /perfil, significa que canAccessRoute no reconoce la ruta.
 *
 *  Busca una función parecida a esta en assets/js/app.js:
 *
 *      canAccessRoute(viewName) {
 *          const map = {
 *              'dashboard':  ['DASHBOARD', 'ver'],
 *              'tickets':    ['TICKETS', 'ver'],
 *              ...
 *          };
 *      }
 *
 *  Agrega estas 4 entradas al map:
 *
 *      'cotizaciones':         ['COTIZACIONES', 'ver'],
 *      'cotizacion-nueva':     ['COTIZACIONES', 'registrar'],
 *      'cotizacion-detalle':   ['COTIZACIONES', 'ver'],
 *      'cotizaciones-emisora': ['COTIZACIONES', 'ver'],
 */
?>


<?php
/* =========================================================================
 *  NOTA ADICIONAL: si tu app.js tiene un switch/case para activar el
 *  nav-item activo, asegúrate de que matchee con data-route="cotizaciones".
 *
 *  La mayoría de las apps ya lo hacen automáticamente al leer data-route,
 *  pero si no, agrega una cláusula específica:
 *
 *      if (route === 'cotizaciones' ||
 *          route === 'cotizacion-nueva' ||
 *          route === 'cotizacion-detalle' ||
 *          route === 'cotizaciones-emisora') {
 *          document.querySelector('[data-route="cotizaciones"]')?.classList.add('active');
 *      }
 */
?>
