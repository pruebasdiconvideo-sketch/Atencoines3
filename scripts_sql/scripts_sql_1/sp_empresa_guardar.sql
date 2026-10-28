SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_empresa_guardar]
    @id_empresa                 INT           = NULL OUTPUT,
    @ruc                        NVARCHAR(20),
    @razon_social               NVARCHAR(255),
    @nombre_comercial           NVARCHAR(255) = NULL,
    @telefono                   NVARCHAR(20)  = NULL,
    @correo                     NVARCHAR(100),
    @id_rubro                   INT,
    @id_grupo                   INT           = NULL,
    @direccion                  NVARCHAR(255),
    @referencia                 NVARCHAR(255) = NULL,
    @id_division                INT,
    @usuario                    NVARCHAR(100) = 'Sistema',
    @crear_sede_principal       BIT           = 1,
    @id_usuario_modificacion    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @id_ubicacion INT;
        DECLARE @nuevo_id_empresa INT = @id_empresa;
        DECLARE @ruta_geo NVARCHAR(500);

        IF @id_empresa IS NULL OR @id_empresa = 0
        BEGIN
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE ruc = @ruc)
                THROW 50001, 'El RUC ya existe en otra empresa.', 1;
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE correo = @correo)
                THROW 50002, 'El correo ya esta registrado en otra empresa.', 1;

            INSERT INTO dbo.ubicacion
                (direccion, referencia, id_division, fecha_creacion, id_usuario_creacion)
            VALUES
                (@direccion, @referencia, @id_division, SYSDATETIME(), @id_usuario_modificacion);
            SET @id_ubicacion = SCOPE_IDENTITY();

            INSERT INTO dbo.empresa
                (ruc, razon_social, nombre_comercial, telefono, correo,
                 id_rubro, id_grupo, id_ubicacion,
                 fecha_creacion, id_usuario_creacion)
            VALUES
                (@ruc, @razon_social, @nombre_comercial, @telefono, @correo,
                 @id_rubro, @id_grupo, @id_ubicacion,
                 SYSDATETIME(), @id_usuario_modificacion);
            SET @nuevo_id_empresa = SCOPE_IDENTITY();

            IF @crear_sede_principal = 1
            BEGIN
                INSERT INTO dbo.empresa_sucursal
                    (id_empresa, nombre, id_ubicacion, telefono, es_principal,
                     activo, fecha_creacion, id_usuario_creacion)
                VALUES
                    (@nuevo_id_empresa,
                     N'Sede Principal - ' + ISNULL(@nombre_comercial, N'Central'),
                     @id_ubicacion, @telefono, 1,
                     1, SYSDATETIME(), @id_usuario_modificacion);
            END

            SET @ruta_geo = ISNULL(dbo.fn_obtener_ruta_geografica(@id_division), N'(sin ruta)');
            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES
                (@usuario, N'EMPRESAS', N'CREACION',
                 N'Se registro la empresa ''' + @razon_social + N''' (RUC: ' + @ruc +
                 N') con ubicacion en ' + @ruta_geo);
        END
        ELSE
        BEGIN
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE ruc = @ruc AND id_empresa <> @id_empresa)
                THROW 50003, 'El RUC ya existe en otra empresa.', 1;
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE correo = @correo AND id_empresa <> @id_empresa)
                THROW 50004, 'El correo ya esta registrado en otra empresa.', 1;

            SELECT @id_ubicacion = id_ubicacion
            FROM dbo.empresa
            WHERE id_empresa = @id_empresa;

            IF @id_ubicacion IS NULL
            BEGIN
                INSERT INTO dbo.ubicacion
                    (direccion, referencia, id_division, fecha_creacion, id_usuario_creacion)
                VALUES
                    (@direccion, @referencia, @id_division, SYSDATETIME(), @id_usuario_modificacion);
                SET @id_ubicacion = SCOPE_IDENTITY();

                UPDATE dbo.empresa
                SET id_ubicacion = @id_ubicacion,
                    fecha_modificacion = SYSDATETIME(),
                    id_usuario_modificacion = @id_usuario_modificacion
                WHERE id_empresa = @id_empresa;
            END
            ELSE
            BEGIN
                UPDATE dbo.ubicacion
                SET direccion           = @direccion,
                    referencia          = @referencia,
                    id_division         = @id_division,
                    fecha_modificacion  = SYSDATETIME(),
                    id_usuario_modificacion = @id_usuario_modificacion
                WHERE id_ubicacion = @id_ubicacion;
            END

            UPDATE dbo.empresa
            SET ruc                       = @ruc,
                razon_social              = @razon_social,
                nombre_comercial          = @nombre_comercial,
                telefono                  = @telefono,
                correo                    = @correo,
                id_rubro                  = @id_rubro,
                id_grupo                  = @id_grupo,
                fecha_modificacion        = SYSDATETIME(),
                id_usuario_modificacion   = @id_usuario_modificacion
            WHERE id_empresa = @id_empresa;

            SET @nuevo_id_empresa = @id_empresa;

            SET @ruta_geo = ISNULL(dbo.fn_obtener_ruta_geografica(@id_division), N'(sin ruta)');
            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES
                (@usuario, N'EMPRESAS', N'MODIFICACION',
                 N'Se modifico la empresa ID ' + CAST(@id_empresa AS NVARCHAR(20)) +
                 N' (''' + @razon_social + N''') estableciendo ubicacion en ' + @ruta_geo);
        END

        COMMIT TRANSACTION;
        SET @id_empresa = @nuevo_id_empresa;
        SELECT @nuevo_id_empresa AS id_empresa;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg  NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum  INT            = ERROR_NUMBER();
        DECLARE @errLine INT            = ERROR_LINE();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_empresa_guardar ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] en linea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 50999, @throwMsg, 1;
    END CATCH
END;
GO