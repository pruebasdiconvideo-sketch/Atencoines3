/* =====================================================================
 * FIX 03 — Datos semilla: catálogo de módulos completo
 *
 * Si tu tabla modulo_catalogo está vacía o solo tiene algunos códigos,
 * este script inserta los 9 módulos estándar que usa el frontend
 * (assets/js/app.js, función applyPermissionsToMenu):
 *
 *   DASHBOARD, TICKETS, EMPRESAS, DIRECTORIOS, GEOGRAFIA,
 *   SEGURIDAD, REPORTES, AUDITORIA, NOTIFICACIONES
 *
 * Nota: app.js usa 'EMPRESAS' y 'DIRECTORIOS' como módulos distintos,
 * pero el esquema viejo solo tenía 'CLIENTES'. Esto lo deja consistente.
 * ===================================================================== */

USE [MonosyncERP];
GO

/* Asegurar existencia de los 9 módulos. Si ya existen, no los duplica. */
IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'DASHBOARD')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'DASHBOARD', N'Panel Principal', N'Metricas y resumen general', 1, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'TICKETS')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'TICKETS', N'Tickets', N'Gestion de tickets de soporte', 2, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'EMPRESAS')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'EMPRESAS', N'Clientes', N'Empresas, sucursales y contactos', 3, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'DIRECTORIOS')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'DIRECTORIOS', N'Directorios', N'Rubros, grupos y motivos', 4, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'GEOGRAFIA')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'GEOGRAFIA', N'Geografia', N'Paises y divisiones geograficas', 5, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'SEGURIDAD')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'SEGURIDAD', N'Seguridad', N'Usuarios, roles y permisos', 6, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'REPORTES')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'REPORTES', N'Reportes', N'Exportacion y reportes', 7, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'AUDITORIA')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'AUDITORIA', N'Auditoria', N'Trazabilidad y auditoria', 8, 1);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'NOTIFICACIONES')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo)
    VALUES (N'NOTIFICACIONES', N'Notificaciones', N'Plantillas y envios', 9, 1);
GO

/* Verificación final */
SELECT id_modulo, codigo, nombre, orden, activo
FROM dbo.modulo_catalogo
ORDER BY orden;
GO

PRINT N'FIX 03 OK: catalogo de modulos verificado (9 modulos esperados).';
GO
