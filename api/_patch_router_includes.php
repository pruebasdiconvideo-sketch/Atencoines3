<?php
/**
 * api/_patch_router_includes.php
 * ----------------------------------------------------------------------------
 * PATCH DE INTEGRACIÓN — Debe incluirse AL FINAL de api/routes/cotizaciones.php
 * y api/routes/empresas.php para activar los endpoints nuevos sin modificar
 * los archivos originales.
 *
 * INSTRUCCIONES DE INSTALACIÓN:
 *
 * 1. Al final de api/routes/cotizaciones.php, agregar:
 *
 *      // ----- PATCH COTIZACIONES MEJORAS -----
 *      $patchCot = __DIR__ . '/cotizaciones_seguimiento.php';
 *      if (is_file($patchCot)) {
 *          require $patchCot;
 *      }
 *      // Para el print v2 (oculta estado, muestra 3 cuentas):
 *      // ya está mapeado via router: si el frontend llama a
 *      // /cotizaciones_print_v2/{id}, el router cargará
 *      // cotizaciones_print_v2.php automáticamente.
 *
 * 2. Al final de api/routes/empresas.php, agregar:
 *
 *      // ----- PATCH EMPRESAS CODIGO -----
 *      $patchEmp = __DIR__ . '/empresas_codigo.php';
 *      if (is_file($patchEmp)) {
 *          require $patchEmp;
 *      }
 *
 * 3. Para evitar tocar api/routes/cotizaciones.php y empresas.php,
 *    también puede hacerse desde api/index.php, agregando JUSTO ANTES
 *    del "require $routeFile;" (línea ~169) el siguiente bloque:
 *
 *      // ---- PATCH: incluir archivos de mejora ----
 *      $patchAfterRoute = __DIR__ . "/routes/{$resource}_seguimiento.php";
 *      $patchAfterRoute2 = __DIR__ . "/routes/{$resource}_codigo.php";
 *
 * 4. Y después del "require $routeFile;", agregar:
 *
 *      if (is_file($patchAfterRoute))  require $patchAfterRoute;
 *      if (is_file($patchAfterRoute2)) require $patchAfterRoute2;
 *
 * ESTE ARCHIVO NO HACE NADA POR SÍ MISMO — solo documenta la integración.
 */
