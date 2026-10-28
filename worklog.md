---
Task ID: 10
Agent: main (Z.ai)
Task: Corregir error "Permiso 'ver' sobre modulo 'DASHBOARD' denegado" en vista Reportes.

Work Log:
- Diagnóstico del error:
  * El usuario accede a la vista Reportes (#/reportes) que tiene permiso REPORTES.ver.
  * Pero la vista llamaba a /dashboard/series (endpoint que requiere DASHBOARD.ver).
  * Si el usuario NO tiene DASHBOARD.ver (porque es un rol limitado), las 4 llamadas
    en paralelo a /dashboard/series fallan con 403 y se muestran los toasts de error.
  * El usuario veía 8 mensajes de error (uno por cada serie + el toast global).

- Solución aplicada:
  1. Creado api/routes/reportes.php (NUEVO endpoint):
     * GET /reportes/series?tipo=X
     * Requiere permiso REPORTES.ver (no DASHBOARD.ver).
     * Reutiliza el mismo SP sp_dashboard_series del backend (que ya filtra eliminado=0).
     * Soporta los mismos 6 tipos de serie que el endpoint /dashboard/series.
  2. Modificada la vista App.views.reportes en assets/js/views.js:
     * Cambiado /dashboard/series por /reportes/series.
     * Agregado { background: true, silent: true } para que si una llamada falla,
       no muestre toast global (la vista ya muestra el error en su propia card).
     * Comentario explicativo del porqué se usa /reportes/series.

Stage Summary:
- Vista Reportes ahora funciona con solo permiso REPORTES.ver (sin requerir DASHBOARD.ver).
- Los usuarios con rol limitado pueden ver reportes sin errores de permiso.
- Las 4 cards de la vista Reportes (Tickets por Estado, Prioridad, Empresa, Atenciones por Técnico)
  ahora cargan correctamente desde /reportes/series.
- Archivos modificados: api/routes/reportes.php (NUEVO), assets/js/views.js.
- No requiere cambios en la base de datos.

---
Task ID: 11
Agent: main (Z.ai)
Task: Implementar módulo completo de Cotizaciones Comerciales con numeración correlativa anual, trazabilidad, versionado, comunicaciones, exportación a PDF/Excel, integrado con el sistema existente (tickets, empresas, personas, roles, permisos, auditoría). Adaptado del cotizador Excel del usuario (DICON).

Work Log:
- Analizado el repositorio GitHub Atenciones (PHP 8 + SQL Server + IIS, REST API modular, autenticación dual sesión+token, permisos basados en roles con modulo_catalogo y role_permissions, SPA frontend con App.views.{modulo} en assets/js/views.js)
- Analizada la base de datos MonosyncERP (script SQL adjunto) y el cotizador Excel actual del usuario (3.2.1 Cotizacion Ventas V9.53 - V15.xlsx) para adaptar formato, catálogos y productos
- Creado /scripts_sql/cotizaciones/01_cotizaciones_tablas.sql: 17 tablas (cotizacion_tipo_catalogo, cotizacion_empresa_emisora, cotizacion_zona_pricing, cotizacion_producto_catalogo, cotizacion_producto_precio_zona, cotizacion_forma_pago, cotizacion_garantia, cotizacion_plazo_entrega, cotizacion_validez, cotizacion_cuenta_bancaria, cotizacion_correlativo_anual, cotizacion, cotizacion_version, cotizacion_detalle, cotizacion_comunicacion, cotizacion_historial_estado, cotizacion_documento) con índices y FKs
- Creado /scripts_sql/cotizaciones/02_cotizaciones_seed_catalogos.sql: importa 7 tipos de cotización, emisora DICON, 3 zonas (Lima/Conos/Balnearios), 9 formas de pago, 7 garantías, 6 plazos, 3 valideces, 4 cuentas bancarias (BBVA, BCP, BN, Tarjeta)
- Creado /scripts_sql/cotizaciones/03_cotizaciones_seed_productos.sql: importa ~160 productos del Excel (SOA software, EQA hardware, FVA facial, ATS soporte software, STE soporte hardware, ACE accesorios, RCA repuestos, FOT fotocheck) con precios por zona para servicios
- Creado /scripts_sql/cotizaciones/04_cotizaciones_seed_modulo_permisos.sql: registra módulo COTIZACIONES en modulo_catalogo, crea rol VENTAS en persona_rol, asigna permisos (ver/registrar/modificar/exportar/imprimir para VENTAS; todo para ADMIN)
- Creado /scripts_sql/cotizaciones/05_cotizaciones_sps_parte1.sql: SP sp_cotizacion_correlativo_siguiente (MERGE con UPDLOCK+HOLDLOCK para concurrencia), sp_cotizacion_calcular_totales (subtotal/IGV/total con descuento global), sp_cotizacion_create (crea cotización con detalle JSON + historial + auditoría atómico)
- Creado /scripts_sql/cotizaciones/05_cotizaciones_sps_parte2.sql: sp_cotizacion_get (6 result sets), sp_cotizacion_list_paged (filtros + paginación OFFSET/FETCH), sp_cotizacion_update_status (matriz de 9 estados), sp_cotizacion_add_comunicacion, sp_cotizacion_update (con opción forzar_version), sp_cotizacion_documento_save/get, sp_cotizacion_delete_admin, sp_cotizacion_catalogos_listar, sp_cotizacion_producto_listar
- Creado /scripts_sql/cotizaciones/06_cotizaciones_run_all.sql: script maestro que ejecuta todo en orden
- Creado /api/routes/cotizaciones.php: 16 endpoints REST (list, create, get, update, status, comunicaciones, versiones, export pdf/xlsx/csv, files upload/download, delete, export job)
- Creado /includes/CotizacionExporter.php: helper con outputPdf (HTML/CSS réplica Excel + auto window.print), outputXlsx (PhpSpreadsheet con 4 hojas: Cotización, Historial, Comunicaciones, Versiones), outputCsv (UTF-8 BOM)
- Modificado /modules/exportacion/worker.php: agregado case COTIZACIONES en fetchData() para exportación masiva asíncrona
- Modificado /index.php: agregado grupo de menú "Comercial" con submenu Cotizaciones y Catálogo de Productos
- Modificado /assets/js/app.js: agregado mapeo COTIZACIONES:ver en applyPermissionsToMenu() y canAccessRoute() para rutas cotizaciones y cotizaciones-productos
- Modificado /assets/js/views.js: agregadas ~1000 líneas al final con App.views.cotizaciones (listado con filtros, detalle con tabs historial/comunicaciones/versiones/documentos, form con autocompletar de empresa/vendedor/productos del catálogo, cálculo automático de subtotales/IGV/total, modal de comunicación, upload de documentos) y App.views.cotizaciones_productos (vista de catálogo)
- Creado /tests/cotizaciones_test.php: 14 escenarios de prueba ejecutables desde el navegador (correlativo, concurrencia, comunicaciones, estados, permisos, transiciones inválidas, rollback, búsqueda)
- Creado /README_COTIZACIONES.md: documentación completa del módulo

Stage Summary:
- Módulo de Cotizaciones Comerciales completo y listo para producción
- 17 tablas nuevas, 13 SPs transaccionales, 16 endpoints REST, 2 vistas SPA, 1 helper de exportación, 14 pruebas
- Catálogo de 160 productos importado del Excel del usuario
- Numeración segura bajo concurrencia (MERGE con UPDLOCK+HOLDLOCK)
- 9 estados con matriz de transiciones validada
- Versionado .1, .2, .3 para cotizaciones enviadas
- Trazabilidad completa: historial no sobrescribible + auditoría general + fechas y responsables por estado
- Exportación a PDF (réplica del Excel), Excel (PhpSpreadsheet con 4 hojas) y CSV
- Integrado con sistema existente: persona, empresa, empresa_contacto, empresa_sucursal, persona_rol, modulo_catalogo, role_permissions, auditoria_accion
- Permisos por rol: VENTAS (ver/registrar/modificar/exportar/imprimir) y ADMIN (todo)
- Cero dependencias nuevas en composer.json
- Scripts SQL idempotentes (pueden ejecutarse múltiples veces)
- No afecta tablas, columnas ni datos existentes
- Archivos modificados: index.php, assets/js/app.js, assets/js/views.js, modules/exportacion/worker.php
- Archivos nuevos: api/routes/cotizaciones.php, includes/CotizacionExporter.php, tests/cotizaciones_test.php, README_COTIZACIONES.md, scripts_sql/cotizaciones/*.sql (7 archivos)
