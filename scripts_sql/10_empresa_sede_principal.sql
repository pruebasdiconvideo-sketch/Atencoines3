USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

ALTER PROCEDURE [dbo].[sp_empresa_guardar]
    @id_empresa INT = NULL OUTPUT,
    @ruc NVARCHAR(20),
    @razon_social NVARCHAR(255),
    @nombre_comercial NVARCHAR(255) = NULL,
    @telefono NVARCHAR(20) = NULL,
    @correo NVARCHAR(100),
    @id_rubro INT,
    @id_grupo INT = NULL,
    @direccion NVARCHAR(255),
    @referencia NVARCHAR(255) = NULL,
    @id_division INT,
    @usuario NVARCHAR(100) = 'Sistema',
    @crear_sede_principal BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @id_ubicacion INT;
        DECLARE @nuevo_id_empresa INT = @id_empresa;

        IF @id_empresa IS NULL OR @id_empresa = 0
        BEGIN
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE ruc = @ruc)
                THROW 50001, 'El RUC ya existe en otra empresa.', 1;
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE correo = @correo)
                THROW 50002, 'El correo ya esta registrado en otra empresa.', 1;

            INSERT INTO dbo.ubicacion (direccion, referencia, id_division)
            VALUES (@direccion, @referencia, @id_division);
            SET @id_ubicacion = SCOPE_IDENTITY();

            INSERT INTO dbo.empresa
                (ruc, razon_social, nombre_comercial, telefono, correo, id_rubro, id_grupo, id_ubicacion)
            VALUES
                (@ruc, @razon_social, @nombre_comercial, @telefono, @correo, @id_rubro, @id_grupo, @id_ubicacion);
            SET @nuevo_id_empresa = SCOPE_IDENTITY();

            IF @crear_sede_principal = 1
            BEGIN
                INSERT INTO dbo.empresa_sucursal (id_empresa, nombre, id_ubicacion, telefono, es_principal)
                VALUES (@nuevo_id_empresa, 'Sede Principal - ' + ISNULL(@nombre_comercial, 'Central'), @id_ubicacion, @telefono, 1);
            END

            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@usuario, 'EMPRESAS', 'CREACION',
                    'Se registro la empresa ''' + @razon_social + ''' (RUC: ' + @ruc +
                    ') con ubicacion en ' + dbo.fn_obtener_ruta_geografica(@id_division));
        END
        ELSE
        BEGIN
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE ruc = @ruc AND id_empresa <> @id_empresa)
                THROW 50003, 'El RUC ya existe en otra empresa.', 1;
            IF EXISTS (SELECT 1 FROM dbo.empresa WHERE correo = @correo AND id_empresa <> @id_empresa)
                THROW 50004, 'El correo ya esta registrado en otra empresa.', 1;

            SELECT @id_ubicacion = id_ubicacion FROM dbo.empresa WHERE id_empresa = @id_empresa;
            IF @id_ubicacion IS NULL
                THROW 50005, 'No se encontro la ubicacion de la empresa.', 1;

            UPDATE dbo.ubicacion
            SET direccion = @direccion, referencia = @referencia, id_division = @id_division
            WHERE id_ubicacion = @id_ubicacion;

            UPDATE dbo.empresa
            SET ruc = @ruc, razon_social = @razon_social, nombre_comercial = @nombre_comercial,
                telefono = @telefono, correo = @correo, id_rubro = @id_rubro, id_grupo = @id_grupo
            WHERE id_empresa = @id_empresa;
            SET @nuevo_id_empresa = @id_empresa;

            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@usuario, 'EMPRESAS', 'MODIFICACION',
                    'Se modifico la empresa ID ' + CAST(@id_empresa AS NVARCHAR(20)) + ' (''' + @razon_social +
                    ''') estableciendo ubicacion en ' + dbo.fn_obtener_ruta_geografica(@id_division));
        END

        COMMIT TRANSACTION;
        SET @id_empresa = @nuevo_id_empresa;
        SELECT @nuevo_id_empresa AS id_empresa;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO
