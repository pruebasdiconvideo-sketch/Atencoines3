/* ============================================================================
 *  Script 06: SP para jerarquia geografica + validacion de duplicados
 * ========================================================================== */
USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

/* SP: Obtener divisiones hijas de un padre (jerarquia) */
IF OBJECT_ID('dbo.sp_divisiones_hijas', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_divisiones_hijas AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_divisiones_hijas]
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
    WHERE (@id_padde IS NULL OR d.id_division_padre = @id_padre)
      AND (@id_tipo_division IS NULL OR d.id_tipo_division = @id_tipo_division)
      AND (@id_pais IS NULL OR dbo.fn_pais_de_division(d.id_division) = @id_pais)
    ORDER BY d.nombre;
END
GO

PRINT '[OK] sp_divisiones_hijas creado';
GO

/* SP: Validar si existe empresa por RUC o correo (para evitar duplicados) */
IF OBJECT_ID('dbo.sp_empresa_existe', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_empresa_existe AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_empresa_existe]
    @ruc NVARCHAR(20) = NULL,
    @correo NVARCHAR(100) = NULL,
    @id_excluir INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        CASE WHEN EXISTS (
            SELECT 1 FROM empresa
            WHERE (@ruc IS NOT NULL AND ruc = @ruc)
               AND (@id_excluir IS NULL OR id_empresa <> @id_excluir)
        ) THEN 1 ELSE 0 END AS existe_ruc,
        CASE WHEN EXISTS (
            SELECT 1 FROM empresa
            WHERE (@correo IS NOT NULL AND correo = @correo)
               AND (@id_excluir IS NULL OR id_empresa <> @id_excluir)
        ) THEN 1 ELSE 0 END AS existe_correo;
END
GO

PRINT '[OK] sp_empresa_existe creado';
GO

/* SP: Validar si existe sucursal por nombre dentro de la misma empresa */
IF OBJECT_ID('dbo.sp_sucursal_existe', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_sucursal_existe AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_sucursal_existe]
    @id_empresa INT,
    @nombre NVARCHAR(100),
    @id_excluir INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM empresa_sucursal
        WHERE id_empresa = @id_empresa AND nombre = @nombre
          AND (@id_excluir IS NULL OR id_sucursal <> @id_excluir)
    ) THEN 1 ELSE 0 END AS existe;
END
GO

PRINT '[OK] sp_sucursal_existe creado';
GO

/* SP: Validar si existe persona por correo o documento */
IF OBJECT_ID('dbo.sp_persona_existe', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_persona_existe AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_persona_existe]
    @correo NVARCHAR(100) = NULL,
    @numero_documento NVARCHAR(50) = NULL,
    @id_excluir INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        CASE WHEN EXISTS (
            SELECT 1 FROM persona
            WHERE (@correo IS NOT NULL AND correo = @correo)
               AND (@id_excluir IS NULL OR id_persona <> @id_excluir)
        ) THEN 1 ELSE 0 END AS existe_correo,
        CASE WHEN EXISTS (
            SELECT 1 FROM persona
            WHERE (@numero_documento IS NOT NULL AND numero_documento = @numero_documento)
               AND (@id_excluir IS NULL OR id_persona <> @id_excluir)
        ) THEN 1 ELSE 0 END AS existe_documento;
END
GO

PRINT '[OK] sp_persona_existe creado';
GO

PRINT '=== Script 06 completado ===';
GO
