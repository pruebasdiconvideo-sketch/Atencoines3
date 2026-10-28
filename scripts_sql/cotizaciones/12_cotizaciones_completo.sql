/* =====================================================================
 * scripts_sql/12_cotizaciones_completo.sql
 *
 * Script UNIFICADO para habilitar el módulo de COTIZACIONES:
 *   1) Verifica/agrega columnas necesarias en ticket_documento y
 *      cotizacion_empresa_emisora (logo_base64).
 *   2) Recrea sp_ticket_documento_save (soporta base64 y binario).
 *   3) Crea sp_cotizacion_emisora_logo_save para subir/actualizar logo.
 *   4) Registra el módulo COTIZACIONES en modulo_catalogo.
 *   5) Otorga permisos completos (ver/registrar/modificar/eliminar/
 *      aprobar/exportar/imprimir) a TODOS los roles existentes.
 *   6) Crea SPs faltantes para listado de emisoras y catálogos.
 *   7) Inserta empresa emisora por defecto si no existe ninguna.
 *
 * Este script COMBINA y MEJORA los scripts 10 y 11 originales.
 * Reemplaza a:
 *   - 10_cotizaciones_verificar_y_logo.sql
 *   - 11_cotizaciones_modulo_y_permisos.sql
 *
 * Ejecutar en: SQL Server Management Studio, sobre la BD MonosyncERP.
 * Es IDEMPOTENTE: puede ejecutarse cuantas veces sea necesario.
 * ===================================================================== */

USE [MonosyncERP];
GO

SET NOCOUNT ON;
GO

PRINT '===== INICIO: CONFIGURACIÓN MÓDULO COTIZACIONES =====';

/* =====================================================================
 * 1) COLUMNAS EN ticket_documento
 * ===================================================================== */
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.ticket_documento')
                 AND name = N'contenido')
BEGIN
    ALTER TABLE dbo.ticket_documento ADD contenido VARBINARY(MAX) NULL;
    PRINT '  [OK] Columna contenido añadida a ticket_documento';
END
ELSE
    PRINT '  [OK] Columna contenido ya existe en ticket_documento';

IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.ticket_documento')
                 AND name = N'id_atencion')
BEGIN
    ALTER TABLE dbo.ticket_documento ADD id_atencion INT NULL;
    PRINT '  [OK] Columna id_atencion añadida';
END
ELSE
    PRINT '  [OK] Columna id_atencion ya existe';

IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.ticket_documento')
                 AND name = N'tipo_documento')
BEGIN
    ALTER TABLE dbo.ticket_documento ADD tipo_documento NVARCHAR(50) NOT NULL
        CONSTRAINT DF_ticket_documento_tipo DEFAULT N'EVIDENCIA_ADICIONAL';
    PRINT '  [OK] Columna tipo_documento añadida';
END
ELSE
    PRINT '  [OK] Columna tipo_documento ya existe';
GO

/* =====================================================================
 * 2) COLUMNAS EN cotizacion_empresa_emisora (logo_base64)
 * ===================================================================== */
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.cotizacion_empresa_emisora')
                 AND name = N'logo_base64')
BEGIN
    ALTER TABLE dbo.cotizacion_empresa_emisora
        ADD logo_base64 NVARCHAR(MAX) NULL;
    PRINT '  [OK] Columna logo_base64 añadida a cotizacion_empresa_emisora';
END
ELSE
    PRINT '  [OK] Columna logo_base64 ya existe';

-- Columnas de auditoría si no existen (para sp_cotizacion_emisora_logo_save)
IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.cotizacion_empresa_emisora')
                 AND name = N'fecha_modificacion')
BEGIN
    ALTER TABLE dbo.cotizacion_empresa_emisora
        ADD fecha_modificacion DATETIME2(0) NULL;
    PRINT '  [OK] Columna fecha_modificacion añadida';
END
ELSE
    PRINT '  [OK] Columna fecha_modificacion ya existe';

IF NOT EXISTS (SELECT 1 FROM sys.columns
               WHERE object_id = OBJECT_ID(N'dbo.cotizacion_empresa_emisora')
                 AND name = N'id_usuario_modificacion')
BEGIN
    ALTER TABLE dbo.cotizacion_empresa_emisora
        ADD id_usuario_modificacion INT NULL;
    PRINT '  [OK] Columna id_usuario_modificacion añadida';
END
ELSE
    PRINT '  [OK] Columna id_usuario_modificacion ya existe';
GO

/* =====================================================================
 * 3) SP sp_ticket_documento_save (versión final con base64 + binario)
 * ===================================================================== */
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
                           WHERE id_atencion = @id_atencion AND id_ticket = @id_ticket)
            THROW 50202, N'La atencion indicada no pertenece al ticket.', 1;

        IF @id_usuario IS NULL
            SELECT @id_usuario = id_usuario FROM dbo.ticket WHERE id_ticket = @id_ticket;

        IF @archivo_key IS NULL OR LTRIM(RTRIM(@archivo_key)) = N''
            SET @archivo_key = SUBSTRING(REPLACE(NEWID(),'-',''),1,32) +
                               SUBSTRING(CONVERT(VARCHAR(64),
                                        CONVERT(VARBINARY(32), CRYPT_GEN_RANDOM(8)), 2), 1, 16);

        IF @tipo_documento IS NULL OR LTRIM(RTRIM(@tipo_documento)) = N''
            SET @tipo_documento = N'EVIDENCIA_ADICIONAL';

        -- Decodificar base64 → VARBINARY(MAX) usando XML xs:base64Binary
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
                   N' (' + @nombre_original + N', ' + CAST(@tamano_bytes AS NVARCHAR(20)) + N' bytes)'
            FROM dbo.persona p WHERE p.id_persona = @id_usuario;
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoria no registrada: ' + ERROR_MESSAGE();
        END CATCH

        COMMIT TRANSACTION;
        SELECT @id_documento AS id_documento, @archivo_key AS archivo_key,
               @id_ticket AS id_ticket, @tamano_bytes AS tamano_bytes,
               @tipo_documento AS tipo_documento;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT            = ERROR_NUMBER();
        DECLARE @errLine INT           = ERROR_LINE();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_ticket_documento_save ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] en linea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 50299, @throwMsg, 1;
    END CATCH
END;
GO
PRINT '  [OK] SP sp_ticket_documento_save (re)creado';

/* =====================================================================
 * 4) SP sp_cotizacion_emisora_logo_save (subir/actualizar logo)
 * ===================================================================== */
IF OBJECT_ID(N'dbo.sp_cotizacion_emisora_logo_save', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_emisora_logo_save;
GO
CREATE PROCEDURE [dbo].[sp_cotizacion_emisora_logo_save]
    @id_emisora    INT,
    @logo_base64   NVARCHAR(MAX),   -- incluir el prefijo data:image/png;base64,
    @id_usuario    INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = @id_emisora)
            THROW 51120, N'La empresa emisora no existe.', 1;

        -- Validar tamaño razonable (máx 2 MB en base64 ≈ 2.7M chars)
        IF LEN(@logo_base64) > 3000000
            THROW 51121, N'El logo es demasiado grande. Máximo permitido: 2 MB.', 1;

        UPDATE dbo.cotizacion_empresa_emisora
        SET logo_base64             = @logo_base64,
            fecha_modificacion      = SYSDATETIME(),
            id_usuario_modificacion = @id_usuario
        WHERE id_emisora = @id_emisora;

        -- Auditoría
        BEGIN TRY
            DECLARE @correo NVARCHAR(100);
            SELECT @correo = ISNULL(p.correo, N'Sistema')
            FROM dbo.persona p WHERE p.id_persona = @id_usuario;

            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@correo, N'COTIZACIONES', N'LOGO_UPDATE',
                    N'Logo actualizado para empresa emisora ID ' +
                    CAST(@id_emisora AS NVARCHAR(10)));
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoria no registrada: ' + ERROR_MESSAGE();
        END CATCH

        SELECT 1 AS success, @id_emisora AS id_emisora;
    END TRY
    BEGIN CATCH
        DECLARE @errMsg2 NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum2 INT = ERROR_NUMBER();
        DECLARE @throwMsg2 NVARCHAR(4000) =
            N'sp_cotizacion_emisora_logo_save ERROR [' + CAST(@errNum2 AS NVARCHAR(10)) +
            N']: ' + @errMsg2;
        THROW 51199, @throwMsg2, 1;
    END CATCH
END;
GO
PRINT '  [OK] SP sp_cotizacion_emisora_logo_save creado';

/* =====================================================================
 * 5) SP sp_cotizacion_emisora_listar (para dropdown en UI)
 * ===================================================================== */
IF OBJECT_ID(N'dbo.sp_cotizacion_emisora_listar', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_emisora_listar;
GO
CREATE PROCEDURE [dbo].[sp_cotizacion_emisora_listar]
    @incluir_inactivas BIT = 0,
    @incluir_logo      BIT = 0   -- 1: retorna logo_base64; 0: omite para ahorrar ancho de banda
AS
BEGIN
    SET NOCOUNT ON;
    IF @incluir_logo = 1
    BEGIN
        SELECT id_emisora, razon_social, nombre_comercial, ruc, direccion,
               telefono, correo, web, logo_base64,
               es_principal, activo,
               CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion
        FROM dbo.cotizacion_empresa_emisora
        WHERE (@incluir_inactivas = 1 OR activo = 1)
        ORDER BY es_principal DESC, razon_social;
    END
    ELSE
    BEGIN
        SELECT id_emisora, razon_social, nombre_comercial, ruc, direccion,
               telefono, correo, web,
               CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo,
               es_principal, activo,
               CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion
        FROM dbo.cotizacion_empresa_emisora
        WHERE (@incluir_inactivas = 1 OR activo = 1)
        ORDER BY es_principal DESC, razon_social;
    END
END;
GO
PRINT '  [OK] SP sp_cotizacion_emisora_listar creado';

/* =====================================================================
 * 6) SP sp_cotizacion_emisora_get (obtener una emisora con su logo)
 * ===================================================================== */
IF OBJECT_ID(N'dbo.sp_cotizacion_emisora_get', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_emisora_get;
GO
CREATE PROCEDURE [dbo].[sp_cotizacion_emisora_get]
    @id_emisora INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT id_emisora, razon_social, nombre_comercial, ruc, direccion,
           telefono, correo, web, logo_base64,
           es_principal, activo,
           CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion,
           CONVERT(VARCHAR(19), fecha_modificacion, 120) AS fecha_modificacion
    FROM dbo.cotizacion_empresa_emisora
    WHERE id_emisora = @id_emisora;
END;
GO
PRINT '  [OK] SP sp_cotizacion_emisora_get creado';

/* =====================================================================
 * 7) SP sp_cotizacion_emisora_save (crear/actualizar emisora)
 * ===================================================================== */
IF OBJECT_ID(N'dbo.sp_cotizacion_emisora_save', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_emisora_save;
GO
CREATE PROCEDURE [dbo].[sp_cotizacion_emisora_save]
    @id_emisora          INT            = NULL OUTPUT,
    @razon_social        NVARCHAR(255),
    @nombre_comercial    NVARCHAR(255)  = NULL,
    @ruc                 NVARCHAR(20),
    @direccion           NVARCHAR(255)  = NULL,
    @telefono            NVARCHAR(50)   = NULL,
    @correo              NVARCHAR(100)  = NULL,
    @web                 NVARCHAR(100)  = NULL,
    @es_principal        BIT            = 0,
    @activo              BIT            = 1,
    @id_usuario          INT            = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        IF @id_emisora IS NULL OR @id_emisora = 0
        BEGIN
            -- Validar RUC único
            IF EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE ruc = @ruc)
                THROW 51130, N'El RUC ya existe en otra empresa emisora.', 1;

            INSERT INTO dbo.cotizacion_empresa_emisora
                (razon_social, nombre_comercial, ruc, direccion, telefono, correo, web,
                 es_principal, activo, fecha_creacion, id_usuario_creacion)
            VALUES
                (@razon_social, @nombre_comercial, @ruc, @direccion, @telefono, @correo, @web,
                 @es_principal, @activo, SYSDATETIME(), @id_usuario);
            SET @id_emisora = SCOPE_IDENTITY();

            -- Si es_principal, quitar el flag de las demás
            IF @es_principal = 1
                UPDATE dbo.cotizacion_empresa_emisora
                SET es_principal = 0
                WHERE id_emisora <> @id_emisora;

            DECLARE @correo_crea NVARCHAR(100);
            SELECT @correo_crea = ISNULL(p.correo, N'Sistema')
            FROM dbo.persona p WHERE p.id_persona = @id_usuario;
            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@correo_crea, N'COTIZACIONES', N'EMISORA_CREAR',
                    N'Empresa emisora creada: ' + @razon_social + N' (RUC: ' + @ruc + N')');
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = @id_emisora)
                THROW 51131, N'La empresa emisora no existe.', 1;

            IF EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora
                       WHERE ruc = @ruc AND id_emisora <> @id_emisora)
                THROW 51132, N'El RUC ya existe en otra empresa emisora.', 1;

            UPDATE dbo.cotizacion_empresa_emisora
            SET razon_social        = @razon_social,
                nombre_comercial    = @nombre_comercial,
                ruc                 = @ruc,
                direccion           = @direccion,
                telefono            = @telefono,
                correo              = @correo,
                web                 = @web,
                es_principal        = @es_principal,
                activo              = @activo,
                fecha_modificacion  = SYSDATETIME(),
                id_usuario_modificacion = @id_usuario
            WHERE id_emisora = @id_emisora;

            IF @es_principal = 1
                UPDATE dbo.cotizacion_empresa_emisora
                SET es_principal = 0
                WHERE id_emisora <> @id_emisora;

            DECLARE @correo_edit NVARCHAR(100);
            SELECT @correo_edit = ISNULL(p.correo, N'Sistema')
            FROM dbo.persona p WHERE p.id_persona = @id_usuario;
            INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
            VALUES (@correo_edit, N'COTIZACIONES', N'EMISORA_UPDATE',
                    N'Empresa emisora actualizada ID ' + CAST(@id_emisora AS NVARCHAR(10)));
        END

        COMMIT TRANSACTION;
        SELECT @id_emisora AS id_emisora, @razon_social AS razon_social, @ruc AS ruc;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO
PRINT '  [OK] SP sp_cotizacion_emisora_save creado';

/* =====================================================================
 * 8) Registrar módulo COTIZACIONES en modulo_catalogo
 * ===================================================================== */
IF NOT EXISTS (SELECT 1 FROM dbo.modulo_catalogo WHERE codigo = N'COTIZACIONES')
BEGIN
    INSERT INTO dbo.modulo_catalogo (codigo, nombre, descripcion, orden, activo, fecha_creacion)
    VALUES (N'COTIZACIONES', N'Cotizaciones',
            N'Módulo de cotizaciones y propuestas comerciales',
            10, 1, SYSDATETIME());
    PRINT '  [OK] Módulo COTIZACIONES insertado en modulo_catalogo (orden=10)';
END
ELSE
BEGIN
    UPDATE dbo.modulo_catalogo
    SET activo = 1, nombre = N'Cotizaciones',
        descripcion = N'Módulo de cotizaciones y propuestas comerciales'
    WHERE codigo = N'COTIZACIONES';
    PRINT '  [OK] Módulo COTIZACIONES ya existía (activado)';
END
GO

/* =====================================================================
 * 9) Otorgar permisos completos de COTIZACIONES a TODOS los roles
 * ===================================================================== */
DECLARE @id_rol INT;
DECLARE rol_cursor CURSOR LOCAL FAST_FORWARD FOR
    SELECT DISTINCT id_rol FROM dbo.persona_rol;

OPEN rol_cursor;
FETCH NEXT FROM rol_cursor INTO @id_rol;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.role_permissions
                   WHERE id_rol = @id_rol AND modulo = N'COTIZACIONES')
    BEGIN
        INSERT INTO dbo.role_permissions
            (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
        VALUES
            (@id_rol, N'COTIZACIONES', 1, 1, 1, 1, 1, 1, 1, GETDATE());
        PRINT '  [OK] Permisos COTIZACIONES otorgados a id_rol=' + CAST(@id_rol AS NVARCHAR(10));
    END
    ELSE
    BEGIN
        UPDATE dbo.role_permissions
        SET ver=1, registrar=1, modificar=1, eliminar=1, aprobar=1, exportar=1, imprimir=1,
            fecha_actualizacion=GETDATE()
        WHERE id_rol=@id_rol AND modulo=N'COTIZACIONES';
        PRINT '  [OK] Permisos COTIZACIONES ya existían para id_rol=' + CAST(@id_rol AS NVARCHAR(10)) + ' (activados)';
    END
    FETCH NEXT FROM rol_cursor INTO @id_rol;
END
CLOSE rol_cursor;
DEALLOCATE rol_cursor;
GO

/* =====================================================================
 * 10) Insertar empresa emisora por defecto si la tabla está vacía
 * ===================================================================== */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora)
BEGIN
    INSERT INTO dbo.cotizacion_empresa_emisora
        (razon_social, nombre_comercial, ruc, direccion, telefono, correo, web,
         logo_base64, es_principal, activo, fecha_creacion)
    VALUES
        (N'MI EMPRESA SAC', N'Mi Empresa', N'20000000001',
         N'Av. Principal 123, Lima', N'+51 1 234-5678',
         N'ventas@miempresa.com', N'www.miempresa.com',
         NULL, 1, 1, SYSDATETIME());
    PRINT '  [OK] Empresa emisora por defecto insertada (id_emisora=1)';
END
ELSE
    PRINT '  [OK] Ya existen empresas emisoras en el catálogo';
GO

/* =====================================================================
 * 11) Insertar catálogos mínimos si están vacíos (forma de pago, garantía, etc.)
 * ===================================================================== */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo)
BEGIN
    INSERT INTO dbo.cotizacion_tipo_catalogo
        (codigo, nombre, titulo_documento, prefijo_codigo, descripcion, orden, activo,
         fecha_creacion, codigo_por_empresa)
    VALUES
        (N'COT',  N'Cotización',          N'COTIZACIÓN',           N'COT', N'Cotización comercial estándar',  1, 1, SYSDATETIME(), 1),
        (N'PROP', N'Propuesta',           N'PROPUESTA COMERCIAL',  N'PROP',N'Propuesta comercial',             2, 1, SYSDATETIME(), 1),
        (N'PROF', N'Proforma',            N'PROFORMA',             N'PROF',N'Proforma de servicio',           3, 1, SYSDATETIME(), 1);
    PRINT '  [OK] Tipos de cotización insertados (COT, PROP, PROF)';
END
ELSE
    PRINT '  [OK] Tipos de cotización ya existían';

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago)
BEGIN
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo, fecha_creacion)
    VALUES
        (N'Contado',           0.00, 1, 1, SYSDATETIME()),
        (N'Crédito 15 días',   0.00, 2, 1, SYSDATETIME()),
        (N'Crédito 30 días',   0.00, 3, 1, SYSDATETIME()),
        (N'Crédito 45 días',   0.00, 4, 1, SYSDATETIME()),
        (N'Crédito 60 días',   0.00, 5, 1, SYSDATETIME()),
        (N'50% adelantado, 50% contra entrega', 0.00, 6, 1, SYSDATETIME());
    PRINT '  [OK] Formas de pago insertadas';
END
ELSE
    PRINT '  [OK] Formas de pago ya existían';

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia)
BEGIN
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo, fecha_creacion)
    VALUES
        (N'Sin garantía',                                  1, 1, SYSDATETIME()),
        (N'Garantía de 3 meses por defecto de fábrica',   2, 1, SYSDATETIME()),
        (N'Garantía de 6 meses',                           3, 1, SYSDATETIME()),
        (N'Garantía de 1 año',                             4, 1, SYSDATETIME()),
        (N'Garantía de 2 años',                            5, 1, SYSDATETIME());
    PRINT '  [OK] Garantías insertadas';
END
ELSE
    PRINT '  [OK] Garantías ya existían';

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega)
BEGIN
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo, fecha_creacion)
    VALUES
        (N'Inmediato',                1, 1, SYSDATETIME()),
        (N'24 horas',                 2, 1, SYSDATETIME()),
        (N'48 horas',                 3, 1, SYSDATETIME()),
        (N'3 a 5 días hábiles',       4, 1, SYSDATETIME()),
        (N'1 semana',                 5, 1, SYSDATETIME()),
        (N'2 semanas',                6, 1, SYSDATETIME()),
        (N'3 a 4 semanas',            7, 1, SYSDATETIME()),
        (N'30 días calendario',       8, 1, SYSDATETIME()),
        (N'45 días calendario',       9, 1, SYSDATETIME()),
        (N'60 días calendario',      10, 1, SYSDATETIME());
    PRINT '  [OK] Plazos de entrega insertados';
END
ELSE
    PRINT '  [OK] Plazos de entrega ya existían';

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_validez)
BEGIN
    INSERT INTO dbo.cotizacion_validez (descripcion, dias, orden, activo, fecha_creacion)
    VALUES
        (N'Válida por 7 días',     7, 1, 1, SYSDATETIME()),
        (N'Válida por 15 días',   15, 2, 1, SYSDATETIME()),
        (N'Válida por 30 días',   30, 3, 1, SYSDATETIME()),
        (N'Válida por 60 días',   60, 4, 1, SYSDATETIME()),
        (N'Válida por 90 días',   90, 5, 1, SYSDATETIME());
    PRINT '  [OK] Valideces insertadas';
END
ELSE
    PRINT '  [OK] Valideces ya existían';

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing)
BEGIN
    INSERT INTO dbo.cotizacion_zona_pricing (codigo, nombre, descripcion, orden, activo)
    VALUES
        (N'LIMA',  N'Lima Metropolitana',     N'Precio regular para Lima',                1, 1),
        (N'CONOS', N'Conos (N/S/E/O)',         N'Conos de Lima con recargo logístico',     2, 1),
        (N'BALNE', N'Provincias balnearias',   N'Callao, Chorrillos, Miraflores, etc.',    3, 1),
        (N'PROV',  N'Provincias',              N'Resto del país',                          4, 1);
    PRINT '  [OK] Zonas de pricing insertadas';
END
ELSE
    PRINT '  [OK] Zonas de pricing ya existían';
GO

/* =====================================================================
 * 12) Verificación final
 * ===================================================================== */
SELECT
    OBJECT_ID(N'dbo.sp_ticket_documento_save', N'P')              AS sp_documento_save_id,
    OBJECT_ID(N'dbo.sp_cotizacion_emisora_logo_save', N'P')       AS sp_emisora_logo_save_id,
    OBJECT_ID(N'dbo.sp_cotizacion_emisora_listar', N'P')           AS sp_emisora_listar_id,
    OBJECT_ID(N'dbo.sp_cotizacion_emisora_get', N'P')               AS sp_emisora_get_id,
    OBJECT_ID(N'dbo.sp_cotizacion_emisora_save', N'P')              AS sp_emisora_save_id,
    COL_LENGTH(N'dbo.ticket_documento', N'contenido')              AS contenido_len,
    COL_LENGTH(N'dbo.cotizacion_empresa_emisora', N'logo_base64') AS logo_base64_len;

SELECT id_modulo, codigo, nombre, orden, activo
FROM dbo.modulo_catalogo
WHERE codigo = N'COTIZACIONES';

SELECT id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir
FROM dbo.role_permissions
WHERE modulo = N'COTIZACIONES'
ORDER BY id_rol;

SELECT id_emisora, razon_social, ruc, es_principal, activo,
       CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo
FROM dbo.cotizacion_empresa_emisora
ORDER BY es_principal DESC, razon_social;

PRINT '===== CONFIGURACIÓN MÓDULO COTIZACIONES COMPLETADA =====';
PRINT 'NOTA: si tu sesión PHP está activa, los permisos están cacheados 5 min.';
PRINT '      Cierra sesión y vuelve a entrar, o ejecuta en otra pestaña:';
PRINT '      http://localhost:8036/logout.php';
GO
