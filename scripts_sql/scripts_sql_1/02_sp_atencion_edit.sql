/* =====================================================================
 * FIX 02 — SP para EDITAR atenciones existentes
 *
 * Permite editar el detalle, diagnóstico, acciones, solución, resultado
 * y estado de una atención. Recalcula automáticamente la duración si
 * el estado pasa a COMPLETADO.
 *
 * Es más eficiente que el UPDATE directo del PHP actual porque:
 *   - Hace todo en una sola transacción server-side
 *   - Recalcula duración con fechas del servidor (no del cliente)
 *   - Registra auditoría sin necesidad de otro round-trip PHP→SQL
 * ===================================================================== */

USE [MonosyncERP];
GO

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.sp_atencion_edit', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_atencion_edit;
GO

CREATE PROCEDURE [dbo].[sp_atencion_edit]
    @id_atencion          INT,
    @detalle_atencion     NVARCHAR(MAX),
    @solucion_aplicada    NVARCHAR(MAX) = NULL,
    @diagnostico          NVARCHAR(MAX) = NULL,
    @acciones_ejecutadas  NVARCHAR(MAX) = NULL,
    @resultado            NVARCHAR(500) = NULL,
    @estado               NVARCHAR(20)  = 'EN_PROCESO',
    @fecha_fin            DATE          = NULL,
    @hora_fin             TIME          = NULL,
    @id_usuario_editor    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        /* Validar existencia */
        DECLARE @id_ticket INT, @estado_anterior NVARCHAR(20), @fecha_inicio DATE, @hora_inicio TIME;
        SELECT @id_ticket = a.id_ticket,
               @estado_anterior = a.estado,
               @fecha_inicio = a.fecha_inicio,
               @hora_inicio = a.hora_inicio
        FROM dbo.atencion a
        WHERE a.id_atencion = @id_atencion;

        IF @id_ticket IS NULL
            THROW 50300, N'La atencion no existe.', 1;

        /* Validar estado */
        IF @estado NOT IN ('EN_PROCESO', 'COMPLETADO', 'PAUSADO')
            THROW 50301, N'Estado no valido. Use EN_PROCESO, COMPLETADO o PAUSADO.', 1;

        /* Si pasa a COMPLETADO y no se especifica fecha_fin, usar SYSDATETIME */
        IF @estado = 'COMPLETADO' AND @fecha_fin IS NULL
        BEGIN
            SET @fecha_fin = CAST(SYSDATETIME() AS DATE);
            SET @hora_fin  = CAST(SYSDATETIME() AS TIME);
        END;

        /* Si pasa a COMPLETADO, validar coherencia de fechas */
        IF @estado = 'COMPLETADO' AND @fecha_fin IS NOT NULL AND @hora_fin IS NOT NULL
        BEGIN
            DECLARE @ini DATETIME = CONVERT(DATETIME, @fecha_inicio) + CONVERT(DATETIME, @hora_inicio);
            DECLARE @fin DATETIME = CONVERT(DATETIME, @fecha_fin)    + CONVERT(DATETIME, @hora_fin);
            IF @fin < @ini
                THROW 50302, N'La fecha de finalizacion no puede ser anterior a la de inicio.', 1;
        END;

        /* UPDATE de la atencion */
        UPDATE dbo.atencion
        SET detalle_atencion    = @detalle_atencion,
            solucion_aplicada   = @solucion_aplicada,
            diagnostico         = @diagnostico,
            acciones_ejecutadas = @acciones_ejecutadas,
            resultado           = @resultado,
            estado              = @estado,
            fecha_fin           = CASE WHEN @estado = 'COMPLETADO' THEN @fecha_fin ELSE fecha_fin END,
            hora_fin            = CASE WHEN @estado = 'COMPLETADO' THEN @hora_fin  ELSE hora_fin  END
        WHERE id_atencion = @id_atencion;

        /* Recalcular duracion si paso a COMPLETADO */
        IF @estado = 'COMPLETADO' AND @fecha_fin IS NOT NULL AND @hora_fin IS NOT NULL
        BEGIN
            DECLARE @diff_min INT = DATEDIFF(MINUTE, @ini, @fin);
            IF @diff_min < 0 SET @diff_min = 0;
            DECLARE @horas DECIMAL(5,2) = ROUND(@diff_min / 60.0, 2);
            DECLARE @formato NVARCHAR(20) =
                CONCAT(@diff_min / 60, N'h ', RIGHT(N'0' + CAST(@diff_min % 60 AS NVARCHAR(2)), 2), N'm');

            IF EXISTS (SELECT 1 FROM dbo.atencion_tiempo WHERE id_atencion = @id_atencion)
                UPDATE dbo.atencion_tiempo
                SET duracion_minutos = @diff_min,
                    duracion_horas   = @horas,
                    duracion_formato = @formato
                WHERE id_atencion = @id_atencion;
            ELSE
                INSERT INTO dbo.atencion_tiempo (id_atencion, duracion_minutos, duracion_horas, duracion_formato)
                VALUES (@id_atencion, @diff_min, @horas, @formato);
        END;

        /* Si vuelve de COMPLETADO a EN_PROCESO/PAUSADO, limpiar duracion */
        IF @estado_anterior = 'COMPLETADO' AND @estado <> 'COMPLETADO'
        BEGIN
            DELETE FROM dbo.atencion_tiempo WHERE id_atencion = @id_atencion;
            UPDATE dbo.atencion SET fecha_fin = NULL, hora_fin = NULL
            WHERE id_atencion = @id_atencion;
        END;

        /* Auditoria */
        BEGIN TRY
            DECLARE @correo_editor NVARCHAR(100);
            SELECT @correo_editor = ISNULL(correo, N'Sistema')
            FROM dbo.persona WHERE id_persona = @id_usuario_editor;

            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@correo_editor, N'ATENCIONES', N'MODIFICACION',
                    N'Atencion #' + CAST(@id_atencion AS NVARCHAR(20)) +
                    N' del ticket #' + CAST(@id_ticket AS NVARCHAR(20)) +
                    N' actualizada. Estado: ' + @estado_anterior + N' -> ' + @estado);
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoria no registrada: ' + ERROR_MESSAGE();
        END CATCH

        COMMIT TRANSACTION;

        SELECT @id_atencion AS id_atencion,
               @id_ticket   AS id_ticket,
               @estado      AS estado,
               @estado_anterior AS estado_anterior;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT            = ERROR_NUMBER();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_atencion_edit ERROR [' + CAST(@errNum AS NVARCHAR(10)) + N']: ' + @errMsg;
        THROW 50399, @throwMsg, 1;
    END CATCH
END
GO

PRINT N'FIX 02 OK: sp_atencion_edit';
GO
