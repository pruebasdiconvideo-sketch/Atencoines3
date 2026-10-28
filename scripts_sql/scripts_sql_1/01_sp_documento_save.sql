/* =====================================================================
 * FIX 01 — SP para subir documentos (base64 → VARBINARY(MAX))
 *
 * Resuelve: "Implicit conversion from nvarchar to varbinary(max) is not
 *            allowed. Use the CONVERT function to run this query."
 *
 * El PHP manda el archivo como base64 (string NVARCHAR(MAX)) al SP.
 * El SP lo decodifica dentro de SQL Server usando XML xs:base64Binary.
 * Como ningún parámetro desde PHP es VARBINARY, el driver pdo_sqlsrv
 * nunca necesita hacer conversión implícita.
 * ===================================================================== */

USE [MonosyncERP];
GO

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.sp_ticket_documento_save', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ticket_documento_save;
GO

CREATE PROCEDURE [dbo].[sp_ticket_documento_save]
    @id_ticket        INT,
    @id_usuario       INT            = NULL,
    @id_atencion      INT            = NULL,
    @nombre_original  NVARCHAR(255),
    @archivo_key      CHAR(48)       = NULL,
    @tipo_mime        VARCHAR(127),
    @tamano_bytes     BIGINT,
    @tipo_documento   NVARCHAR(50)   = 'EVIDENCIA_ADICIONAL',
    @contenido_bin    VARBINARY(MAX) = NULL,
    @base64_contenido NVARCHAR(MAX)  = NULL,
    @id_documento     INT            OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        IF @id_ticket IS NULL
            THROW 50200, N'El id_ticket es obligatorio.', 1;

        IF NOT EXISTS (SELECT 1 FROM dbo.ticket WHERE id_ticket = @id_ticket)
            THROW 50201, N'El ticket indicado no existe.', 1;

        IF @id_atencion IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.atencion
                           WHERE id_atencion = @id_atencion
                             AND id_ticket   = @id_ticket)
            THROW 50202, N'La atencion indicada no pertenece al ticket.', 1;

        IF @id_usuario IS NULL
            SELECT @id_usuario = id_usuario FROM dbo.ticket WHERE id_ticket = @id_ticket;

        IF @archivo_key IS NULL OR LTRIM(RTRIM(@archivo_key)) = N''
            SET @archivo_key = SUBSTRING(REPLACE(NEWID(),'-',''),1,32) +
                               SUBSTRING(CONVERT(VARCHAR(64),
                                        CONVERT(VARBINARY(32), CRYPT_GEN_RANDOM(8)), 2), 1, 16);

        IF @tipo_documento IS NULL OR LTRIM(RTRIM(@tipo_documento)) = N''
            SET @tipo_documento = N'EVIDENCIA_ADICIONAL';

        /* Decodificar base64 → VARBINARY(MAX) usando XML.
         * xs:base64Binary ignora whitespace automáticamente.
         * CONVERT(XML, ...) acepta NVARCHAR(MAX) completo sin límite. */
        DECLARE @contenido_final VARBINARY(MAX);

        IF @contenido_bin IS NOT NULL
            SET @contenido_final = @contenido_bin;
        ELSE IF @base64_contenido IS NOT NULL AND LEN(@base64_contenido) > 0
            SET @contenido_final =
                CONVERT(VARBINARY(MAX),
                        CONVERT(XML, N'<x>' + @base64_contenido + N'</x>')
                            .value(N'xs:base64Binary(/x[1])', N'VARBINARY(MAX)'));
        ELSE
            SET @contenido_final = NULL;

        INSERT INTO dbo.ticket_documento
            (id_ticket, id_atencion, nombre_original, archivo_key,
             tipo_mime, tamano_bytes, id_usuario, fecha_registro,
             tipo_documento, contenido)
        VALUES
            (@id_ticket, @id_atencion, @nombre_original, @archivo_key,
             @tipo_mime, @tamano_bytes, @id_usuario, SYSDATETIME(),
             @tipo_documento, @contenido_final);

        SET @id_documento = SCOPE_IDENTITY();

        BEGIN TRY
            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            SELECT ISNULL(p.correo, N'Sistema'), N'TICKETS', N'DOCUMENTO_UPLOAD',
                   N'Documento adjuntado al ticket #' + CAST(@id_ticket AS NVARCHAR(20)) +
                   N' (archivo: ' + @nombre_original + N', ' +
                   CAST(@tamano_bytes AS NVARCHAR(20)) + N' bytes)'
            FROM dbo.persona p WHERE p.id_persona = @id_usuario;
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoría no registrada: ' + ERROR_MESSAGE();
        END CATCH

        COMMIT TRANSACTION;

        SELECT @id_documento     AS id_documento,
               @archivo_key      AS archivo_key,
               @id_ticket        AS id_ticket,
               @tamano_bytes     AS tamano_bytes,
               @tipo_documento   AS tipo_documento;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT            = ERROR_NUMBER();
        DECLARE @errLine INT           = ERROR_LINE();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_ticket_documento_save ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] en línea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 50299, @throwMsg, 1;
    END CATCH
END
GO

PRINT N'FIX 01 OK: sp_ticket_documento_save';
GO
