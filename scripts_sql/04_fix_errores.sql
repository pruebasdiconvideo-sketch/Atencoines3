/* ============================================================================
 *  FIX SCRIPT v2 - Corrige 4 errores del script 02_extension_nuevas_opciones.sql
 *
 *  Errores corregidos:
 *    1. sp_encuesta_responder lineas 40-41: sintaxis '+' con subquery
 *       (T-SQL no permite concatenar string + (SELECT ...) directamente)
 *    2. sp_encuesta_reporte linea 21: ORDER BY ep.orden no esta en GROUP BY
 *    3. Linea 1237: WHERE activa = 1 en modulo_catalogo (la columna es 'activo')
 *    4. sp_api_token_validar linea 9: OUTPUT inserted.* en UPDATE no permite
 *       tablas con triggers habilitados (Msg 334). Solucion: usar variable de tabla.
 *
 *  Ejecutar despues del script 02_extension_nuevas_opciones.sql
 * ========================================================================== */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

PRINT '--- Iniciando script de correccion ---';
GO

/* ----------------------------------------------------------------------------
 * FIX 1: sp_encuesta_responder - error de sintaxis con '+' en linea 40-41
 *
 * Causa: T-SQL no permite:
 *   @param = '{"x":"' + (SELECT nro_ticket FROM ticket WHERE ...) + '"}'
 * Solucion: declarar variable previamente y usarla en la llamada.
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.sp_encuesta_responder', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_encuesta_responder;
GO

CREATE PROCEDURE [dbo].[sp_encuesta_responder]
    @id_ticket INT,
    @id_persona INT,
    @respuestas_json NVARCHAR(MAX)    -- [{"id_pregunta":1,"valor_texto":"...","valor_numero":5},...]
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRANSACTION;
    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.encuesta_respuesta WHERE id_ticket = @id_ticket)
        BEGIN
            RAISERROR('El ticket ya tiene respuestas de encuesta.', 16, 1);
            ROLLBACK; RETURN;
        END

        DECLARE @id_encuesta INT;
        SELECT TOP 1 @id_encuesta = id_encuesta FROM dbo.encuesta WHERE activa = 1;
        IF @id_encuesta IS NULL
        BEGIN
            RAISERROR('No hay encuesta activa.', 16, 1);
            ROLLBACK; RETURN;
        END

        -- Insert masivo desde JSON
        INSERT INTO dbo.encuesta_respuesta (id_encuesta, id_ticket, id_persona, id_pregunta, valor_texto, valor_numero)
        SELECT @id_encuesta, @id_ticket, @id_persona,
               CAST([key] AS INT),
               JSON_VALUE([value], '$.valor_texto'),
               TRY_CAST(JSON_VALUE([value], '$.valor_numero') AS DECIMAL(10,2))
        FROM OPENJSON(@respuestas_json);

        -- Disparar notificacion de agradecimiento al tecnico
        DECLARE @id_tecnico INT;
        SELECT TOP 1 @id_tecnico = id_usuario FROM dbo.ticket WHERE id_ticket = @id_ticket;
        IF @id_tecnico IS NOT NULL
        BEGIN
            -- FIX: declarar variables previas, no concatenar con subquery en la llamada EXEC
            DECLARE @nro_ticket NVARCHAR(50);
            SELECT @nro_ticket = nro_ticket FROM dbo.ticket WHERE id_ticket = @id_ticket;

            DECLARE @id_usuarios_json NVARCHAR(MAX);
            SET @id_usuarios_json = '[' + CAST(@id_tecnico AS NVARCHAR(20)) + ']';

            DECLARE @valores_json NVARCHAR(MAX);
            SET @valores_json = '{"nro_ticket":"' + ISNULL(@nro_ticket, '') + '"}';

            DECLARE @id_notif INT;
            EXEC dbo.sp_notificacion_enviar
                 @codigo_plantilla = 'ENCUESTA_DISPONIBLE',
                 @id_usuarios       = @id_usuarios_json,
                 @valores_json      = @valores_json,
                 @id_referencia     = @id_ticket,
                 @modulo            = 'ENCUESTAS',
                 @id_notificacion   = @id_notif OUTPUT;
        END

        COMMIT TRANSACTION;
        SELECT 1 AS success, @id_encuesta AS id_encuesta;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR(@msg, 16, 1);
    END CATCH
END
GO

PRINT '[OK] FIX 1: sp_encuesta_responder recreado correctamente';
GO

/* ----------------------------------------------------------------------------
 * FIX 2: sp_encuesta_reporte - ORDER BY con columna fuera del GROUP BY
 *
 * Causa: ORDER BY ep.orden no esta en GROUP BY ep.texto, ep.tipo
 * Solucion: agregar ep.orden al GROUP BY (no afecta el resultado)
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.sp_encuesta_reporte', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_encuesta_reporte;
GO

CREATE PROCEDURE [dbo].[sp_encuesta_reporte]
    @fecha_desde DATE = NULL,
    @fecha_hasta DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @fecha_desde IS NULL SET @fecha_desde = DATEADD(DAY, -90, CAST(GETDATE() AS DATE));
    IF @fecha_hasta IS NULL SET @fecha_hasta = CAST(GETDATE() AS DATE);

    SELECT ep.texto AS pregunta,
           ep.tipo,
           AVG(er.valor_numero) AS promedio,
           COUNT(*) AS respuestas,
           MIN(er.valor_numero) AS minimo,
           MAX(er.valor_numero) AS maximo
    FROM dbo.encuesta_respuesta er
    INNER JOIN dbo.encuesta_pregunta ep ON er.id_pregunta = ep.id_pregunta
    WHERE er.fecha_respuesta BETWEEN @fecha_desde AND @fecha_hasta
      AND er.valor_numero IS NOT NULL
    GROUP BY ep.texto, ep.tipo, ep.orden
    ORDER BY MIN(ep.orden);
END
GO

PRINT '[OK] FIX 2: sp_encuesta_reporte recreado correctamente';
GO

/* ----------------------------------------------------------------------------
 * FIX 3: Permisos por defecto para rol 1 - columna 'activa' no existe en modulo_catalogo
 *
 * Causa: La tabla modulo_catalogo tiene columna 'activo' (masculino),
 *        pero el INSERT final usaba 'WHERE activa = 1' (femenino).
 * Solucion: corregir a 'activo'.
 * ---------------------------------------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM dbo.role_permissions WHERE id_rol = 1)
BEGIN
    INSERT INTO dbo.role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    SELECT 1, codigo, 1, 1, 1, 1, 1, 1, 1, GETDATE()
    FROM dbo.modulo_catalogo WHERE activo = 1;   -- FIX: 'activo' (no 'activa')
END
GO

PRINT '[OK] FIX 3: Permisos por defecto insertados correctamente (rol 1)';
GO

/* ----------------------------------------------------------------------------
 * FIX 4: sp_api_token_validar - Msg 334 OUTPUT clause incompatible con triggers
 *
 * Causa: La tabla api_token tiene el trigger trg_audit_api_token AFTER UPDATE.
 *        SQL Server no permite UPDATE con OUTPUT inserted.* directo cuando
 *        la tabla destino tiene triggers habilitados.
 *
 * Solucion: usar variable de tabla intermedia con OUTPUT ... INTO @tabla,
 *           y luego SELECT desde esa variable.
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.sp_api_token_validar', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_api_token_validar;
GO

CREATE PROCEDURE [dbo].[sp_api_token_validar]
    @token_plano NVARCHAR(128)
AS
BEGIN
    SET NOCOUNT ON;

    -- Hash SHA-512 del token recibido
    DECLARE @hash NVARCHAR(128) = CONVERT(NVARCHAR(128),
        HASHBYTES('SHA2_512', CONVERT(VARCHAR(MAX), @token_plano)), 2);

    -- Variable de tabla para recibir el OUTPUT (porque api_token tiene triggers)
    DECLARE @result TABLE (
        id_token INT,
        id_usuario INT,
        scopes NVARCHAR(500),
        activo BIT,
        fecha_expiracion DATETIME
    );

    -- UPDATE con OUTPUT INTO (esto SI es compatible con triggers)
    UPDATE dbo.api_token
    SET ultimo_uso = GETDATE()
    OUTPUT inserted.id_token, inserted.id_usuario, inserted.scopes,
           inserted.activo, inserted.fecha_expiracion
    INTO @result
    WHERE token_hash = @hash;

    -- Devolver el resultado
    SELECT id_token, id_usuario, scopes, activo, fecha_expiracion
    FROM @result;
END
GO

PRINT '[OK] FIX 4: sp_api_token_validar recreado con variable de tabla (compatible con triggers)';
GO

/* ----------------------------------------------------------------------------
 * VERIFICACION: Confirmar que role_permissions tiene datos para rol 1
 * ---------------------------------------------------------------------------- */
DECLARE @count_permisos INT = (SELECT COUNT(*) FROM dbo.role_permissions WHERE id_rol = 1);
PRINT '[VERIFY] Permisos para rol 1: ' + CAST(@count_permisos AS NVARCHAR(10)) + ' modulos configurados';

IF @count_permisos = 0
BEGIN
    -- Aun no hay permisos, insertarlos todos
    INSERT INTO dbo.role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    SELECT 1, codigo, 1, 1, 1, 1, 1, 1, 1, GETDATE()
    FROM dbo.modulo_catalogo WHERE activo = 1;

    PRINT '[OK] Permisos insertados para rol 1 (post-verificacion)';
END
GO

/* ----------------------------------------------------------------------------
 * VERIFICACION ADICIONAL: confirmar columnas 'activa' donde deben existir
 * ---------------------------------------------------------------------------- */
PRINT '';
PRINT '--- Verificacion de columnas criticas ---';

IF EXISTS (SELECT 1 FROM sys.columns
           WHERE object_id = OBJECT_ID('dbo.encuesta') AND name = 'activa')
    PRINT '[OK] dbo.encuesta.activa existe';
ELSE
    PRINT '[FAIL] dbo.encuesta.activa NO existe - ejecutar ALTER TABLE dbo.encuesta ADD activa BIT NOT NULL DEFAULT(1)';

IF EXISTS (SELECT 1 FROM sys.columns
           WHERE object_id = OBJECT_ID('dbo.notificacion_plantilla') AND name = 'activa')
    PRINT '[OK] dbo.notificacion_plantilla.activa existe';
ELSE
    PRINT '[FAIL] dbo.notificacion_plantilla.activa NO existe';

IF EXISTS (SELECT 1 FROM sys.columns
           WHERE object_id = OBJECT_ID('dbo.auditoria_config') AND name = 'activa')
    PRINT '[OK] dbo.auditoria_config.activa existe';
ELSE
    PRINT '[FAIL] dbo.auditoria_config.activa NO existe';

IF EXISTS (SELECT 1 FROM sys.columns
           WHERE object_id = OBJECT_ID('dbo.modulo_catalogo') AND name = 'activo')
    PRINT '[OK] dbo.modulo_catalogo.activo existe';
ELSE
    PRINT '[FAIL] dbo.modulo_catalogo.activo NO existe';
GO

PRINT '';
PRINT '=== Script de correccion completado ===';
GO
