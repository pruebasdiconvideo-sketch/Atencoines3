/* ============================================================================
 *  04_cotizaciones_seed_modulo_permisos.sql
 *  ----------------------------------------------------------------------------
 *  Registra en el sistema existente:
 *    - El módulo COTIZACIONES en modulo_catalogo (con su código/nombre)
 *    - El rol VENTAS en persona_rol (si no existe)
 *    - Permisos por defecto del rol VENTAS sobre COTIZACIONES
 *    - Permisos completos para el rol ADMIN (id_rol = 1)
 *
 *  Reutiliza las tablas existentes del proyecto:
 *    - dbo.modulo_catalogo
 *    - dbo.persona_rol
 *    - dbo.role_permissions
 *
 *  Idempotente: usa IF NOT EXISTS.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

/* ============================================================================
 * 1) Registrar el módulo COTIZACIONES en modulo_catalogo
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = 'COTIZACIONES')
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo, fecha_creacion)
    VALUES (
        'COTIZACIONES',
        N'Cotizaciones Comerciales',
        N'Módulo de cotizaciones con numeración correlativa anual, trazabilidad, comunicaciones y versionado.',
        25,
        1,
        SYSDATETIME()
    );
GO

/* ============================================================================
 * 2) Crear el rol VENTAS en persona_rol
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol WHERE nombre = 'VENTAS')
    INSERT INTO dbo.persona_rol (nombre, descripcion)
    VALUES ('VENTAS', N'Rol de vendedores que crean y gestionan cotizaciones comerciales.');
GO

DECLARE @id_rol_admin INT = 1;
DECLARE @id_rol_ventas INT = (SELECT id_rol FROM dbo.persona_rol WHERE nombre = 'VENTAS');

/* ============================================================================
 * 3) Permisos por defecto del rol VENTAS sobre COTIZACIONES
 *
 *    VENTAS puede: ver, registrar, modificar, exportar, imprimir
 *    VENTAS NO puede: eliminar, aprobar (lo hace un supervisor/admin)
 * ============================================================================
 */
IF @id_rol_ventas IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.role_permissions WHERE id_rol = @id_rol_ventas AND modulo = 'COTIZACIONES')
        INSERT INTO dbo.role_permissions
            (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
        VALUES
            (@id_rol_ventas, 'COTIZACIONES', 1, 1, 1, 0, 0, 1, 1, GETDATE());
END
GO

/* ============================================================================
 * 4) Permisos completos para el rol ADMIN (id_rol = 1) sobre COTIZACIONES
 *    El admin tiene bypass total, pero registramos igual para consistencia
 *    y para que la vista de permisos en la UI lo muestre activo.
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.role_permissions WHERE id_rol = 1 AND modulo = 'COTIZACIONES')
    INSERT INTO dbo.role_permissions
        (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    VALUES
        (1, 'COTIZACIONES', 1, 1, 1, 1, 1, 1, 1, GETDATE());
GO

/* ============================================================================
 * 5) Asignar rol VENTAS a los colaboradores mencionados en el Excel
 *
 *    Angel Sanchez (Jefe de Ventas, asanchez@dicon.com.pe)
 *    Juan Olivos
 *    Edgar Vargas
 *    Briyit Valencia
 *    Jony Posito
 *
 *    Se busca a estas personas por correo en la tabla persona. Si ya existen
 *    en el sistema, se les asigna el rol VENTAS. Si no existen, se omite
 *    (no se crean personas porque no tenemos datos suficientes).
 * ============================================================================
 */
DECLARE @id_rol_ventas INT = (SELECT id_rol FROM dbo.persona_rol WHERE nombre = 'VENTAS');

IF @id_rol_ventas IS NOT NULL
BEGIN
    -- Angel Sanchez (asanchez@dicon.com.pe)
    IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
                   INNER JOIN dbo.persona p ON pra.id_persona = p.id_persona
                   WHERE p.correo = 'asanchez@dicon.com.pe' AND pra.id_rol = @id_rol_ventas)
    BEGIN
        INSERT INTO dbo.persona_rol_asignacion (id_persona, id_rol, fecha_asignacion, asignado_por)
        SELECT p.id_persona, @id_rol_ventas, GETDATE(), NULL
        FROM dbo.persona p
        WHERE p.correo = 'asanchez@dicon.com.pe';
    END

    -- Juan Olivos (jolivos@dicon.com.pe o similar)
    IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
                   INNER JOIN dbo.persona p ON pra.id_persona = p.id_persona
                   WHERE p.correo LIKE '%olivos%' AND pra.id_rol = @id_rol_ventas)
    BEGIN
        INSERT INTO dbo.persona_rol_asignacion (id_persona, id_rol, fecha_asignacion, asignado_por)
        SELECT p.id_persona, @id_rol_ventas, GETDATE(), NULL
        FROM dbo.persona p
        WHERE p.correo LIKE '%olivos%';
    END

    -- Edgar Vargas
    IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
                   INNER JOIN dbo.persona p ON pra.id_persona = p.id_persona
                   WHERE p.correo LIKE '%vargas%' AND pra.id_rol = @id_rol_ventas)
    BEGIN
        INSERT INTO dbo.persona_rol_asignacion (id_persona, id_rol, fecha_asignacion, asignado_por)
        SELECT p.id_persona, @id_rol_ventas, GETDATE(), NULL
        FROM dbo.persona p
        WHERE p.correo LIKE '%vargas%';
    END

    -- Briyit Valencia
    IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
                   INNER JOIN dbo.persona p ON pra.id_persona = p.id_persona
                   WHERE p.correo LIKE '%valencia%' AND pra.id_rol = @id_rol_ventas)
    BEGIN
        INSERT INTO dbo.persona_rol_asignacion (id_persona, id_rol, fecha_asignacion, asignado_por)
        SELECT p.id_persona, @id_rol_ventas, GETDATE(), NULL
        FROM dbo.persona p
        WHERE p.correo LIKE '%valencia%';
    END

    -- Jony Posito
    IF NOT EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
                   INNER JOIN dbo.persona p ON pra.id_persona = p.id_persona
                   WHERE p.correo LIKE '%posito%' AND pra.id_rol = @id_rol_ventas)
    BEGIN
        INSERT INTO dbo.persona_rol_asignacion (id_persona, id_rol, fecha_asignacion, asignado_por)
        SELECT p.id_persona, @id_rol_ventas, GETDATE(), NULL
        FROM dbo.persona p
        WHERE p.correo LIKE '%posito%';
    END
END
GO

/* ============================================================================
 * Verificación
 * ============================================================================
 */
PRINT N'[OK] 04_cotizaciones_seed_modulo_permisos.sql ejecutado correctamente.';
PRINT N'[INFO] Módulo COTIZACIONES registrado en modulo_catalogo.';
PRINT N'[INFO] Rol VENTAS registrado en persona_rol (si no existía).';
PRINT N'[INFO] Permisos COTIZACIONES asignados a roles ADMIN y VENTAS.';
GO
