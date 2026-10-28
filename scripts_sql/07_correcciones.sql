/* ============================================================================
 *  Script 07: Correcciones
 *   1. Recrea sp_divisiones_hijas (fix typo @id_padre)
 *   2. Verifica permisos del ADMINISTRADOR
 * ========================================================================== */
USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

PRINT '=== Fix 1: Recreando sp_divisiones_hijas ===';
GO

IF OBJECT_ID('dbo.sp_divisiones_hijas', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_divisiones_hijas;
GO

CREATE PROCEDURE [dbo].[sp_divisiones_hijas]
    @id_padre INT = NULL,
    @id_tipo_division INT = NULL,
    @id_pais INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT d.id_division, d.nombre, d.id_tipo_division, d.id_division_padre,
           td.nombre AS tipo_division_nombre,
           dbo.fn_pais_de_division(d.id_division) AS id_pais
    FROM division_geografica d WITH (NOLOCK)
    LEFT JOIN tipo_division td WITH (NOLOCK) ON d.id_tipo_division = td.id_tipo_division
    WHERE (@id_padre IS NULL OR d.id_division_padre = @id_padre)
      AND (@id_tipo_division IS NULL OR d.id_tipo_division = @id_tipo_division)
      AND (@id_pais IS NULL OR dbo.fn_pais_de_division(d.id_division) = @id_pais)
    ORDER BY d.nombre;
END
GO

PRINT '[OK] sp_divisiones_hijas recreado correctamente';
GO

PRINT '';
PRINT '=== Fix 2: Verificacion de permisos del ADMINISTRADOR ===';
GO

DECLARE @id_rol_admin INT;
DECLARE @total_permisos INT;
DECLARE @total_modulos INT;

SELECT @id_rol_admin = id_rol FROM persona_rol WHERE nombre = 'ADMINISTRADOR';

SELECT @total_permisos = COUNT(*)
FROM role_permissions
WHERE id_rol = @id_rol_admin;

SELECT @total_modulos = COUNT(*) FROM modulo_catalogo WHERE activo = 1;

PRINT '[INFO] Rol ADMINISTRADOR ID: ' + ISNULL(CAST(@id_rol_admin AS VARCHAR), 'NULL');
PRINT '[INFO] Permisos asignados al rol ADMINISTRADOR: ' + CAST(@total_permisos AS VARCHAR) + ' de ' + CAST(@total_modulos AS VARCHAR) + ' modulos';

IF @total_permisos = @total_modulos AND @total_permisos > 0
    PRINT '[OK] Permisos completos asignados correctamente';
ELSE
    PRINT '[WARN] Faltan permisos. Ejecuta 05_permisos_admin.sql de nuevo';
GO

PRINT '';
PRINT '=== Fix 3: Verificar tipos de division geografica ===';
GO

SELECT id_tipo_division, nombre FROM tipo_division ORDER BY id_tipo_division;
GO

PRINT '';
PRINT '=== Script 07 completado ===';
GO
