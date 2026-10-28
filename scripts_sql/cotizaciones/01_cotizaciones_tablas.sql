/* ============================================================================
 *  01_cotizaciones_tablas.sql
 *  ----------------------------------------------------------------------------
 *  Módulo de Cotizaciones Comerciales — MonosyncERP
 *
 *  Crea TODAS las tablas necesarias para el módulo de cotizaciones:
 *    - cotizacion_tipo_catalogo        (VENTAS, SOPORTE, MANTENIMIENTO, ...)
 *    - cotizacion_empresa_emisora      (DICON y otras emisoras configurables)
 *    - cotizacion_zona_pricing         (Lima, Conos, Balnearios)
 *    - cotizacion_producto_catalogo    (productos/servicios importados del Excel)
 *    - cotizacion_producto_precio_zona (precios por zona para servicios)
 *    - cotizacion_forma_pago           (catálogo importado del Excel)
 *    - cotizacion_garantia             (catálogo)
 *    - cotizacion_plazo_entrega        (catálogo)
 *    - cotizacion_validez              (7, 15, 30 días)
 *    - cotizacion_cuenta_bancaria      (BBVA, BCP, etc.)
 *    - cotizacion_correlativo_anual    (control de numeración por empresa+tipo+año)
 *    - cotizacion                      (cabecera principal)
 *    - cotizacion_version              (versionado .1, .2, .3 de una cotización)
 *    - cotizacion_detalle              (ítems de la cotización)
 *    - cotizacion_comunicacion         (historial de comunicaciones)
 *    - cotizacion_historial_estado     (trazabilidad de estados)
 *    - cotizacion_documento            (adjuntos, igual patrón que ticket_documento)
 *
 *  CONVENCIONES (alineadas con el resto del proyecto):
 *    - Esquema dbo
 *    - datetime2(0) para fechas
 *    - bit para flags
 *    - nvarchar para cadenas (UTF-16 nativo de SQL Server)
 *    - dec(12,2) para importes
 *    - Las FK siguen el patrón de ticket y empresa
 *    - Soft delete via eliminado bit + fecha_eliminacion (como ticket)
 *
 *  No afecta ninguna tabla existente. Solo CREATES.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
GO

/* ============================================================================
 * 1) Catálogo de tipos de cotización
 *    Origen: hoja "Valores" del Excel (7 tipos)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_tipo_catalogo','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_tipo_catalogo (
        id_tipo           INT IDENTITY(1,1) NOT NULL,
        codigo            NVARCHAR(20)  NOT NULL,
        nombre            NVARCHAR(100) NOT NULL,
        titulo_documento  NVARCHAR(200) NOT NULL,
        prefijo_codigo    NVARCHAR(10)  NOT NULL,
        descripcion       NVARCHAR(500) NULL,
        orden             INT NOT NULL DEFAULT 0,
        activo            BIT NOT NULL DEFAULT 1,
        fecha_creacion    DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        fecha_modificacion DATETIME2(0) NULL,
        id_usuario_creacion INT NULL,
        id_usuario_modificacion INT NULL,
        CONSTRAINT PK_cotizacion_tipo_catalogo PRIMARY KEY CLUSTERED (id_tipo),
        CONSTRAINT UQ_cotizacion_tipo_codigo UNIQUE NONCLUSTERED (codigo)
    );
END
GO

/* ============================================================================
 * 2) Empresa emisora (la que emite la cotización)
 *    Origen: hoja "VENTAS" del Excel — Ingeniería de Control Digital S.A.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_empresa_emisora','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_empresa_emisora (
        id_emisora        INT IDENTITY(1,1) NOT NULL,
        razon_social      NVARCHAR(255) NOT NULL,
        nombre_comercial  NVARCHAR(255) NULL,
        ruc               NVARCHAR(20)  NOT NULL,
        direccion         NVARCHAR(255) NULL,
        telefono          NVARCHAR(50)  NULL,
        correo            NVARCHAR(100) NULL,
        web               NVARCHAR(100) NULL,
        logo_base64       NVARCHAR(MAX) NULL,
        es_principal      BIT NOT NULL DEFAULT 0,
        activo            BIT NOT NULL DEFAULT 1,
        fecha_creacion    DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        fecha_modificacion DATETIME2(0) NULL,
        id_usuario_creacion INT NULL,
        id_usuario_modificacion INT NULL,
        CONSTRAINT PK_cotizacion_empresa_emisora PRIMARY KEY CLUSTERED (id_emisora),
        CONSTRAINT UQ_cotizacion_emisora_ruc UNIQUE NONCLUSTERED (ruc)
    );
END
GO

/* ============================================================================
 * 3) Zonas de pricing (solo para servicios del catálogo)
 *    Origen: hoja "Datos" del Excel — L (Lima), C (Conos), B (Balnearios)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_zona_pricing','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_zona_pricing (
        id_zona           INT IDENTITY(1,1) NOT NULL,
        codigo            NVARCHAR(10)  NOT NULL,
        nombre            NVARCHAR(100) NOT NULL,
        descripcion       NVARCHAR(500) NULL,
        orden             INT NOT NULL DEFAULT 0,
        activo            BIT NOT NULL DEFAULT 1,
        CONSTRAINT PK_cotizacion_zona_pricing PRIMARY KEY CLUSTERED (id_zona),
        CONSTRAINT UQ_cotizacion_zona_codigo UNIQUE NONCLUSTERED (codigo)
    );
END
GO

/* ============================================================================
 * 4) Catálogo de productos y servicios
 *    Origen: hojas V.Software, V.Hardware, V.Facial, Software, Hardware,
 *            Accesorios, Repuestos, Fotocheck del Excel.
 *
 *    Los productos físicos (software, hardware, accesorios, repuestos,
 *    fotocheck) tienen un único precio (precio_cliente).
 *    Los SERVICIOS (instalación, soporte) tienen 3 precios según zona.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_producto_catalogo','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_producto_catalogo (
        id_producto        INT IDENTITY(1,1) NOT NULL,
        codigo             NVARCHAR(50)  NOT NULL,
        descripcion        NVARCHAR(MAX) NOT NULL,
        descripcion_corta  NVARCHAR(500) NULL,
        id_tipo_producto   NVARCHAR(30)  NOT NULL,
        -- Tipo: SOFTWARE, HARDWARE, FACIAL, SERVICIO_SOFTWARE, SERVICIO_HARDWARE,
        --       ACCESORIO, REPUESTO, FOTOCHECK, DESARROLLO
        unidad_medida      NVARCHAR(20)  NOT NULL DEFAULT 'UND',
        -- Precio base (cliente). Para productos físicos es el precio único.
        -- Para servicios, este es el precio de Lima Metropolitana; conos y
        -- balnearios se guardan en cotizacion_producto_precio_zona.
        precio_cliente     DECIMAL(12,2) NOT NULL DEFAULT 0,
        precio_distribuidor DECIMAL(12,2) NULL,
        precio_mayorista   DECIMAL(12,2) NULL,
        afecto_igv         BIT NOT NULL DEFAULT 1,
        moneda             NVARCHAR(10)  NOT NULL DEFAULT 'PEN',
        -- Si es un servicio variable por zona:
        aplica_precio_zona BIT NOT NULL DEFAULT 0,
        categoria          NVARCHAR(100) NULL,
        subcategoria       NVARCHAR(100) NULL,
        activo             BIT NOT NULL DEFAULT 1,
        es_producto_agotado BIT NOT NULL DEFAULT 0,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        fecha_modificacion DATETIME2(0) NULL,
        id_usuario_creacion    INT NULL,
        id_usuario_modificacion INT NULL,
        CONSTRAINT PK_cotizacion_producto_catalogo PRIMARY KEY CLUSTERED (id_producto),
        CONSTRAINT UQ_cotizacion_producto_codigo UNIQUE NONCLUSTERED (codigo)
    );
END
GO

/* ============================================================================
 * 5) Precios por zona para servicios (productos con aplica_precio_zona = 1)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_producto_precio_zona','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_producto_precio_zona (
        id_producto_precio INT IDENTITY(1,1) NOT NULL,
        id_producto        INT NOT NULL,
        id_zona            INT NOT NULL,
        precio             DECIMAL(12,2) NOT NULL,
        CONSTRAINT PK_cotizacion_producto_precio_zona PRIMARY KEY CLUSTERED (id_producto_precio),
        CONSTRAINT UQ_cotizacion_precio_zona UNIQUE NONCLUSTERED (id_producto, id_zona),
        CONSTRAINT FK_cotizacion_precio_producto FOREIGN KEY (id_producto)
            REFERENCES dbo.cotizacion_producto_catalogo (id_producto),
        CONSTRAINT FK_cotizacion_precio_zona FOREIGN KEY (id_zona)
            REFERENCES dbo.cotizacion_zona_pricing (id_zona)
    );
END
GO

/* ============================================================================
 * 6) Catálogo de formas de pago
 *    Origen: hoja "Valores" (9 opciones + descuentos asociados)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_forma_pago','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_forma_pago (
        id_forma_pago      INT IDENTITY(1,1) NOT NULL,
        descripcion        NVARCHAR(500) NOT NULL,
        descuento_pct      DECIMAL(5,2) NOT NULL DEFAULT 0,
        orden              INT NOT NULL DEFAULT 0,
        activo             BIT NOT NULL DEFAULT 1,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_forma_pago PRIMARY KEY CLUSTERED (id_forma_pago)
    );
END
GO

/* ============================================================================
 * 7) Catálogo de garantías
 *    Origen: hoja "Valores" (6 opciones)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_garantia','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_garantia (
        id_garantia        INT IDENTITY(1,1) NOT NULL,
        descripcion        NVARCHAR(500) NOT NULL,
        orden              INT NOT NULL DEFAULT 0,
        activo             BIT NOT NULL DEFAULT 1,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_garantia PRIMARY KEY CLUSTERED (id_garantia)
    );
END
GO

/* ============================================================================
 * 8) Catálogo de plazos de entrega
 *    Origen: hoja "Valores" (3 opciones)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_plazo_entrega','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_plazo_entrega (
        id_plazo           INT IDENTITY(1,1) NOT NULL,
        descripcion        NVARCHAR(500) NOT NULL,
        orden              INT NOT NULL DEFAULT 0,
        activo             BIT NOT NULL DEFAULT 1,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_plazo_entrega PRIMARY KEY CLUSTERED (id_plazo)
    );
END
GO

/* ============================================================================
 * 9) Catálogo de validez de cotización
 *    Origen: hoja "Valores" (3 opciones: 7, 15, 30 días)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_validez','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_validez (
        id_validez         INT IDENTITY(1,1) NOT NULL,
        descripcion        NVARCHAR(500) NOT NULL,
        dias               INT NOT NULL,
        orden              INT NOT NULL DEFAULT 0,
        activo             BIT NOT NULL DEFAULT 1,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_validez PRIMARY KEY CLUSTERED (id_validez)
    );
END
GO

/* ============================================================================
 * 10) Catálogo de cuentas bancarias
 *     Origen: hoja "VENTAS" — BBVA y BCP
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_cuenta_bancaria','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_cuenta_bancaria (
        id_cuenta          INT IDENTITY(1,1) NOT NULL,
        id_emisora         INT NOT NULL,
        banco              NVARCHAR(50)  NOT NULL,
        numero_cuenta      NVARCHAR(50)  NOT NULL,
        cci                NVARCHAR(50)  NULL,
        moneda             NVARCHAR(10)  NOT NULL DEFAULT 'PEN',
        titular            NVARCHAR(255) NULL,
        acepta_tarjeta     BIT NOT NULL DEFAULT 0,
        orden              INT NOT NULL DEFAULT 0,
        activo             BIT NOT NULL DEFAULT 1,
        fecha_creacion     DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_cuenta_bancaria PRIMARY KEY CLUSTERED (id_cuenta),
        CONSTRAINT FK_cuenta_emisora FOREIGN KEY (id_emisora)
            REFERENCES dbo.cotizacion_empresa_emisora (id_emisora)
    );
END
GO

/* ============================================================================
 * 11) Control de correlativos anuales por empresa + tipo
 *
 *     Estructura atómica con row locking para garantizar unicidad bajo
 *     concurrencia. El SP sp_cotizacion_correlativo_siguiente hace un
 *     UPDATE con OUTPUT que bloquea la fila (UPDLOCK) y devuelve el
 *     siguiente número.
 *
 *     UNIQUE (id_empresa, id_tipo, anio) garantiza que nunca existan
 *     dos filas para el mismo año+empresa+tipo → no hay duplicidad.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_correlativo_anual','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_correlativo_anual (
        id_correlativo     INT IDENTITY(1,1) NOT NULL,
        id_empresa         INT NOT NULL,
        id_tipo            INT NOT NULL,
        anio               INT NOT NULL,
        ultimo_correlativo INT NOT NULL DEFAULT 0,
        fecha_ultimo       DATETIME2(0) NULL,
        CONSTRAINT PK_cotizacion_correlativo_anual PRIMARY KEY CLUSTERED (id_correlativo),
        CONSTRAINT UQ_cotizacion_correlativo UNIQUE NONCLUSTERED (id_empresa, id_tipo, anio),
        CONSTRAINT FK_cot_correlativo_empresa FOREIGN KEY (id_empresa)
            REFERENCES dbo.empresa (id_empresa),
        CONSTRAINT FK_cot_correlativo_tipo FOREIGN KEY (id_tipo)
            REFERENCES dbo.cotizacion_tipo_catalogo (id_tipo)
    );
END
GO

/* ============================================================================
 * 12) Cabecera principal de cotización
 *
 *     El código generado: COT-EMP000001-2026-0001
 *       - EMP000001 = id_empresa rellenado a 6 dígitos (e.g. 1 -> 000001)
 *       - 2026 = año de creación
 *       - 0001 = correlativo del año para (empresa, tipo)
 *
 *     UNIQUE (codigo) garantiza unicidad global en la tabla.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion (
        id_cotizacion          INT IDENTITY(1,1) NOT NULL,
        codigo                 NVARCHAR(40)  NOT NULL,
        version_numero         INT NOT NULL DEFAULT 1,
        -- Relaciones principales
        id_empresa             INT NOT NULL,           -- cliente
        id_empresa_contacto    INT NULL,               -- contacto en la empresa cliente
        id_sucursal            INT NULL,
        id_tipo                INT NOT NULL,
        id_emisora             INT NOT NULL,
        id_forma_pago          INT NULL,
        id_garantia            INT NULL,
        id_plazo_entrega       INT NULL,
        id_validez             INT NULL,
        id_cuenta_bancaria     INT NULL,
        id_zona_pricing        INT NULL,               -- aplica solo si hay servicios
        -- Persona-usuario que crea/actualiza
        id_usuario_creador     INT NOT NULL,
        id_usuario_actualizo   INT NULL,
        id_usuario_aprobo      INT NULL,
        id_usuario_rechazo     INT NULL,
        id_usuario_cancelo     INT NULL,
        id_usuario_cerro       INT NULL,
        id_vendedor            INT NULL,               -- persona (rol VENTAS)
        -- Fechas del ciclo de vida
        fecha_creacion         DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        fecha_actualizacion    DATETIME2(0) NULL,
        fecha_envio            DATETIME2(0) NULL,
        fecha_aprobacion       DATETIME2(0) NULL,
        fecha_rechazo          DATETIME2(0) NULL,
        fecha_cancelacion      DATETIME2(0) NULL,
        fecha_cierre           DATETIME2(0) NULL,
        fecha_ultima_comunicacion DATETIME2(0) NULL,
        fecha_proximo_seguimiento DATE NULL,
        -- Cabecera comercial
        titulo_documento       NVARCHAR(200) NULL,
        motivo                 NVARCHAR(500) NULL,
        observaciones          NVARCHAR(MAX) NULL,
        motivo_rechazo         NVARCHAR(500) NULL,
        motivo_cancelacion     NVARCHAR(500) NULL,
        motivo_cierre          NVARCHAR(500) NULL,
        -- Condiciones comerciales en texto (snapshot al momento de crear)
        -- Se guardan como snapshot para que si luego cambian los catálogos,
        -- la cotización histórica mantenga las condiciones que tuvo.
        snapshot_forma_pago    NVARCHAR(500) NULL,
        snapshot_garantia      NVARCHAR(500) NULL,
        snapshot_plazo_entrega NVARCHAR(500) NULL,
        snapshot_validez       NVARCHAR(500) NULL,
        snapshot_cuenta_bancaria NVARCHAR(MAX) NULL,
        -- Importes totales (calculados por SP al insertar/actualizar detalle)
        subtotal               DECIMAL(14,2) NOT NULL DEFAULT 0,
        descuento_global_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0,
        descuento_global_monto DECIMAL(14,2) NOT NULL DEFAULT 0,
        base_imponible         DECIMAL(14,2) NOT NULL DEFAULT 0,
        igv_pct                DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
        igv_monto              DECIMAL(14,2) NOT NULL DEFAULT 0,
        total                  DECIMAL(14,2) NOT NULL DEFAULT 0,
        moneda                 NVARCHAR(10)  NOT NULL DEFAULT 'PEN',
        -- Estado
        estado                 NVARCHAR(20)  NOT NULL DEFAULT 'BORRADOR',
        -- Borrador, PENDIENTE_ENVIO, ENVIADA, EN_SEGUIMIENTO, EN_NEGOCIACION,
        -- APROBADA, RECHAZADA, CANCELADA, CERRADA
        eliminado              BIT NOT NULL DEFAULT 0,
        fecha_eliminacion      DATETIME2(0) NULL,
        id_usuario_eliminacion INT NULL,
        motivo_eliminacion     NVARCHAR(500) NULL,
        -- Origen (MANUAL, IMPORTADO, API)
        origen                 NVARCHAR(20)  NOT NULL DEFAULT 'MANUAL',
        CONSTRAINT PK_cotizacion PRIMARY KEY CLUSTERED (id_cotizacion),
        CONSTRAINT UQ_cotizacion_codigo UNIQUE NONCLUSTERED (codigo)
    );
END
GO

/* FK de cotizacion → todas sus tablas relacionadas */
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_empresa')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_empresa
        FOREIGN KEY (id_empresa) REFERENCES dbo.empresa (id_empresa);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_empresa_contacto')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_empresa_contacto
        FOREIGN KEY (id_empresa_contacto) REFERENCES dbo.empresa_contacto (id_empresa_contacto);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_sucursal')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_sucursal
        FOREIGN KEY (id_sucursal) REFERENCES dbo.empresa_sucursal (id_sucursal);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_tipo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_tipo
        FOREIGN KEY (id_tipo) REFERENCES dbo.cotizacion_tipo_catalogo (id_tipo);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_emisora')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_emisora
        FOREIGN KEY (id_emisora) REFERENCES dbo.cotizacion_empresa_emisora (id_emisora);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_forma_pago')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_forma_pago
        FOREIGN KEY (id_forma_pago) REFERENCES dbo.cotizacion_forma_pago (id_forma_pago);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_garantia')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_garantia
        FOREIGN KEY (id_garantia) REFERENCES dbo.cotizacion_garantia (id_garantia);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_plazo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_plazo
        FOREIGN KEY (id_plazo_entrega) REFERENCES dbo.cotizacion_plazo_entrega (id_plazo);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_validez')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_validez
        FOREIGN KEY (id_validez) REFERENCES dbo.cotizacion_validez (id_validez);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_cuenta')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_cuenta
        FOREIGN KEY (id_cuenta_bancaria) REFERENCES dbo.cotizacion_cuenta_bancaria (id_cuenta);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_zona')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_zona
        FOREIGN KEY (id_zona_pricing) REFERENCES dbo.cotizacion_zona_pricing (id_zona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_creador')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_creador
        FOREIGN KEY (id_usuario_creador) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_actualizo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_actualizo
        FOREIGN KEY (id_usuario_actualizo) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_aprobo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_aprobo
        FOREIGN KEY (id_usuario_aprobo) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_rechazo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_rechazo
        FOREIGN KEY (id_usuario_rechazo) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_cancelo')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_cancelo
        FOREIGN KEY (id_usuario_cancelo) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_cerro')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_cerro
        FOREIGN KEY (id_usuario_cerro) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_usuario_eliminacion')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_usuario_eliminacion
        FOREIGN KEY (id_usuario_eliminacion) REFERENCES dbo.persona (id_persona);
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_cotizacion_vendedor')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT FK_cotizacion_vendedor
        FOREIGN KEY (id_vendedor) REFERENCES dbo.persona (id_persona);
GO

/* CHECK de estados válidos */
IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_cotizacion_estado')
    ALTER TABLE dbo.cotizacion ADD CONSTRAINT CK_cotizacion_estado
        CHECK (estado IN ('BORRADOR','PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO',
                          'EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','CERRADA'));
GO

/* ============================================================================
 * 13) Versiones de cotización
 *     Cada vez que se edita una cotización ENVIADA/APROBADA, se crea una
 *     nueva versión. El código visible lleva el sub-índice: .2, .3, .4
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_version','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_version (
        id_version          INT IDENTITY(1,1) NOT NULL,
        id_cotizacion       INT NOT NULL,
        version_numero      INT NOT NULL,
        codigo_versionado   NVARCHAR(50) NOT NULL,
        id_usuario          INT NOT NULL,
        motivo              NVARCHAR(500) NULL,
        snapshot_json       NVARCHAR(MAX) NULL,
        fecha_version       DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_version PRIMARY KEY CLUSTERED (id_version),
        CONSTRAINT UQ_cotizacion_version UNIQUE NONCLUSTERED (id_cotizacion, version_numero),
        CONSTRAINT FK_cotizacion_version_cot FOREIGN KEY (id_cotizacion)
            REFERENCES dbo.cotizacion (id_cotizacion),
        CONSTRAINT FK_cotizacion_version_usuario FOREIGN KEY (id_usuario)
            REFERENCES dbo.persona (id_persona)
    );
END
GO

/* ============================================================================
 * 14) Detalle (ítems) de la cotización
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_detalle','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_detalle (
        id_detalle          INT IDENTITY(1,1) NOT NULL,
        id_cotizacion       INT NOT NULL,
        id_version          INT NULL,           -- NULL si es la versión actual
        orden               INT NOT NULL,
        id_producto_catalogo INT NULL,          -- NULL si es ítem libre
        codigo              NVARCHAR(50) NULL,
        descripcion         NVARCHAR(MAX) NOT NULL,
        cantidad            DECIMAL(10,2) NOT NULL DEFAULT 1,
        unidad_medida       NVARCHAR(20)  NOT NULL DEFAULT 'UND',
        precio_unitario     DECIMAL(12,2) NOT NULL,
        descuento_pct       DECIMAL(5,2)  NOT NULL DEFAULT 0,
        descuento_monto     DECIMAL(12,2) NOT NULL DEFAULT 0,
        subtotal            DECIMAL(14,2) NOT NULL,
        afecto_igv          BIT NOT NULL DEFAULT 1,
        id_zona_pricing     INT NULL,
        CONSTRAINT PK_cotizacion_detalle PRIMARY KEY CLUSTERED (id_detalle),
        CONSTRAINT FK_cot_detalle_cot FOREIGN KEY (id_cotizacion)
            REFERENCES dbo.cotizacion (id_cotizacion),
        CONSTRAINT FK_cot_detalle_producto FOREIGN KEY (id_producto_catalogo)
            REFERENCES dbo.cotizacion_producto_catalogo (id_producto),
        CONSTRAINT FK_cot_detalle_zona FOREIGN KEY (id_zona_pricing)
            REFERENCES dbo.cotizacion_zona_pricing (id_zona)
    );
END
GO

/* ============================================================================
 * 15) Historial de comunicaciones (no sobrescribible)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_comunicacion','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_comunicacion (
        id_comunicacion     INT IDENTITY(1,1) NOT NULL,
        id_cotizacion       INT NOT NULL,
        id_usuario          INT NOT NULL,
        fecha_registro      DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        fecha_comunicacion  DATETIME2(0) NULL,
        medio               NVARCHAR(30) NOT NULL,
        -- EMAIL, TELEFONO, WHATSAPP, REUNION, PRESENCIAL, OTRO
        tipo_comunicacion   NVARCHAR(30) NOT NULL,
        -- ENVIO_INICIAL, SEGUIMIENTO, CONSULTA, NEGOCIACION, REENVIO,
        -- RESPUESTA_CLIENTE, APROBACION, RECHAZO, CIERRE, OTRO
        descripcion         NVARCHAR(MAX) NOT NULL,
        respuesta_cliente   NVARCHAR(MAX) NULL,
        resultado_compromiso NVARCHAR(MAX) NULL,
        fecha_proximo_seguimiento DATE NULL,
        observaciones       NVARCHAR(MAX) NULL,
        CONSTRAINT PK_cotizacion_comunicacion PRIMARY KEY CLUSTERED (id_comunicacion),
        CONSTRAINT FK_cot_comunicacion_cot FOREIGN KEY (id_cotizacion)
            REFERENCES dbo.cotizacion (id_cotizacion),
        CONSTRAINT FK_cot_comunicacion_usuario FOREIGN KEY (id_usuario)
            REFERENCES dbo.persona (id_persona)
    );
END
GO

/* ============================================================================
 * 16) Historial de cambios de estado (trazabilidad)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_historial_estado','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_historial_estado (
        id_historial        INT IDENTITY(1,1) NOT NULL,
        id_cotizacion       INT NOT NULL,
        estado_anterior     NVARCHAR(20) NOT NULL,
        estado_nuevo        NVARCHAR(20) NOT NULL,
        id_usuario          INT NOT NULL,
        motivo              NVARCHAR(500) NOT NULL,
        tipo_evento         NVARCHAR(30) NOT NULL DEFAULT 'CAMBIO_ESTADO',
        -- CREACION, CAMBIO_ESTADO, ENVIO, APROBACION, RECHAZO, CANCELACION,
        -- CIERRE, REENVIO, NUEVA_VERSION
        origen_evento       NVARCHAR(20) NOT NULL DEFAULT 'MANUAL',
        datos_adicionales   NVARCHAR(MAX) NULL,
        fecha_cambio        DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_cotizacion_historial_estado PRIMARY KEY CLUSTERED (id_historial),
        CONSTRAINT FK_cot_historial_cot FOREIGN KEY (id_cotizacion)
            REFERENCES dbo.cotizacion (id_cotizacion),
        CONSTRAINT FK_cot_historial_usuario FOREIGN KEY (id_usuario)
            REFERENCES dbo.persona (id_persona)
    );
END
GO

/* ============================================================================
 * 17) Documentos adjuntos (mismo patrón que ticket_documento)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.cotizacion_documento','U') IS NULL
BEGIN
    CREATE TABLE dbo.cotizacion_documento (
        id_documento        INT IDENTITY(1,1) NOT NULL,
        id_cotizacion       INT NOT NULL,
        id_comunicacion     INT NULL,
        nombre_original     NVARCHAR(255) NOT NULL,
        archivo_key         CHAR(48) NOT NULL,
        tipo_mime           VARCHAR(127) NOT NULL,
        tamano_bytes        BIGINT NOT NULL,
        tipo_documento      NVARCHAR(50) NOT NULL DEFAULT 'ADJUNTO_GENERAL',
        -- ADJUNTO_GENERAL, EVIDENCIA_COMUNICACION, COTIZACION_FIRMADA,
        -- ORDEN_COMPRA, FACTURA, OTRO
        id_usuario          INT NULL,
        fecha_registro      DATETIME2(0) NOT NULL DEFAULT (SYSDATETIME()),
        contenido           VARBINARY(MAX) NULL,
        CONSTRAINT PK_cotizacion_documento PRIMARY KEY CLUSTERED (id_documento),
        CONSTRAINT UQ_cotizacion_documento_key UNIQUE NONCLUSTERED (archivo_key),
        CONSTRAINT FK_cot_doc_cot FOREIGN KEY (id_cotizacion)
            REFERENCES dbo.cotizacion (id_cotizacion),
        CONSTRAINT FK_cot_doc_com FOREIGN KEY (id_comunicacion)
            REFERENCES dbo.cotizacion_comunicacion (id_comunicacion),
        CONSTRAINT FK_cot_doc_usuario FOREIGN KEY (id_usuario)
            REFERENCES dbo.persona (id_persona)
    );
END
GO

/* ============================================================================
 * 18) ÍNDICES para consultas frecuentes
 * ============================================================================
 */

-- Cotización: filtros por empresa, estado, tipo, año
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_empresa_estado')
    CREATE NONCLUSTERED INDEX IX_cotizacion_empresa_estado
        ON dbo.cotizacion (id_empresa, estado)
        INCLUDE (id_cotizacion, codigo, fecha_creacion, total);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_estado_fecha')
    CREATE NONCLUSTERED INDEX IX_cotizacion_estado_fecha
        ON dbo.cotizacion (estado, fecha_creacion DESC)
        INCLUDE (id_cotizacion, codigo, id_empresa, id_tipo);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_tipo_anio')
    CREATE NONCLUSTERED INDEX IX_cotizacion_tipo_anio
        ON dbo.cotizacion (id_tipo, fecha_creacion DESC)
        INCLUDE (id_cotizacion, codigo, estado);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_vendedor')
    CREATE NONCLUSTERED INDEX IX_cotizacion_vendedor
        ON dbo.cotizacion (id_vendedor)
        INCLUDE (id_cotizacion, codigo, estado, fecha_creacion);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_creador')
    CREATE NONCLUSTERED INDEX IX_cotizacion_creador
        ON dbo.cotizacion (id_usuario_creador)
        INCLUDE (id_cotizacion, codigo, estado, fecha_creacion);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_proximo_seguimiento')
    CREATE NONCLUSTERED INDEX IX_cotizacion_proximo_seguimiento
        ON dbo.cotizacion (fecha_proximo_seguimiento)
        INCLUDE (id_cotizacion, codigo, estado, id_empresa)
        WHERE fecha_proximo_seguimiento IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_codigo_like')
    CREATE NONCLUSTERED INDEX IX_cotizacion_codigo_like
        ON dbo.cotizacion (codigo)
        INCLUDE (id_cotizacion, estado, id_empresa);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_eliminado')
    CREATE NONCLUSTERED INDEX IX_cotizacion_eliminado
        ON dbo.cotizacion (eliminado, estado)
        INCLUDE (id_cotizacion, codigo, fecha_creacion);
GO

-- Historial y comunicaciones
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_historial_cot_fecha')
    CREATE NONCLUSTERED INDEX IX_cot_historial_cot_fecha
        ON dbo.cotizacion_historial_estado (id_cotizacion, fecha_cambio DESC)
        INCLUDE (estado_anterior, estado_nuevo, id_usuario, tipo_evento);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_comunicacion_cot_fecha')
    CREATE NONCLUSTERED INDEX IX_cot_comunicacion_cot_fecha
        ON dbo.cotizacion_comunicacion (id_cotizacion, fecha_registro DESC)
        INCLUDE (id_comunicacion, medio, tipo_comunicacion, id_usuario);
GO

-- Detalle
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_detalle_cot_orden')
    CREATE NONCLUSTERED INDEX IX_cot_detalle_cot_orden
        ON dbo.cotizacion_detalle (id_cotizacion, orden)
        INCLUDE (id_detalle, codigo, cantidad, precio_unitario, subtotal);
GO

-- Versiones
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_version_cot_fecha')
    CREATE NONCLUSTERED INDEX IX_cot_version_cot_fecha
        ON dbo.cotizacion_version (id_cotizacion, version_numero DESC)
        INCLUDE (id_version, codigo_versionado, fecha_version);
GO

-- Documentos
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_doc_cot')
    CREATE NONCLUSTERED INDEX IX_cot_doc_cot
        ON dbo.cotizacion_documento (id_cotizacion)
        INCLUDE (id_documento, nombre_original, tipo_documento, fecha_registro);
GO

PRINT N'[OK] 01_cotizaciones_tablas.sql ejecutado correctamente.';
GO
