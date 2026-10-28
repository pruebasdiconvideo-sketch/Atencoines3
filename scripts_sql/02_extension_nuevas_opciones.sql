/* ============================================================================
 *  MonosyncERP - Script de EXTENSION de nuevas opciones
 *  Version: 2.0
 *  Fecha:   2026-10-17
 *
 *  Requiere: Base de datos MonosyncERP ya creada con el script original.
 *
 *  Nuevas opciones implementadas:
 *    1. Dashboard / Reportes       -> sp_dashboard_kpis, sp_dashboard_series
 *    2. Roles y permisos avanzados  -> modulo_catalogo, sp_permisos_get, sp_permisos_save
 *    3. Notificaciones              -> notificacion, notificacion_usuario, notificacion_plantilla
 *    4. API REST                    -> api_token, api_endpoint_log, api_rate_limit
 *    5. Auditoria avanzada          -> auditoria_cambio, auditoria_config, triggers DML
 *    6. Exportacion masiva          -> exportacion_job, exportacion_log
 *    7. Encuestas de satisfaccion   -> encuesta, encuesta_pregunta, encuesta_respuesta
 *    8. Soporte PWA                 -> pwa_subscription (push notifications)
 *
 *  Notas:
 *    - Toda modificacion de datos se realiza via stored procedures (regla del cliente).
 *    - No se elimina ni se altera la estructura existente; solo se agregan tablas/SP.
 *    - Se incluye IF NOT EXISTS para re-ejecucion segura.
 * ========================================================================== */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

/* ----------------------------------------------------------------------------
 * 1. DASHBOARD / REPORTES
 *    No requiere nuevas tablas; los SPs consultan las existentes.
 *    Se agrega tabla saved_report para guardar reportes personalizados.
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.saved_report', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[saved_report] (
        [id_report]        INT IDENTITY(1,1) NOT NULL,
        [id_usuario]      INT NOT NULL,
        [nombre]          NVARCHAR(100) NOT NULL,
        [descripcion]     NVARCHAR(500) NULL,
        [tipo]            NVARCHAR(30) NOT NULL,        -- KPI / SERIES / TABLE / CUSTOM
        [modulo]          NVARCHAR(50) NULL,            -- DASHBOARD / TICKETS / AUDITORIA
        [config_json]     NVARCHAR(MAX) NOT NULL,        -- filtros, agrupacion, rango fechas
        [es_publico]      BIT NOT NULL DEFAULT (0),
        [fecha_creacion]  DATETIME NOT NULL DEFAULT (GETDATE()),
        [ultima_ejecucion] DATETIME NULL,
        CONSTRAINT PK_saved_report PRIMARY KEY CLUSTERED (id_report)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_saved_report_usuario')
    CREATE NONCLUSTERED INDEX IX_saved_report_usuario ON [dbo].[saved_report] (id_usuario, es_publico);
GO

/* ----------------------------------------------------------------------------
 * 2. ROLES Y PERMISOS AVANZADOS
 *    Tabla catalogo de modulos (para que role_permissions sea dinamica)
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.modulo_catalogo', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[modulo_catalogo] (
        [id_modulo]   INT IDENTITY(1,1) NOT NULL,
        [codigo]      NVARCHAR(50) NOT NULL,            -- DASHBOARD, TICKETS, EMPRESAS...
        [nombre]      NVARCHAR(100) NOT NULL,
        [descripcion] NVARCHAR(255) NULL,
        [orden]       INT NOT NULL DEFAULT (0),
        [activo]      BIT NOT NULL DEFAULT (1),
        CONSTRAINT PK_modulo_catalogo PRIMARY KEY CLUSTERED (id_modulo),
        CONSTRAINT UQ_modulo_codigo UNIQUE NONCLUSTERED (codigo)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[modulo_catalogo])
BEGIN
    INSERT INTO [dbo].[modulo_catalogo] (codigo, nombre, descripcion, orden) VALUES
     ('DASHBOARD',  'Tablero',         'Dashboard principal y KPIs', 1),
     ('TICKETS',    'Tickets',         'Gestion de tickets', 2),
     ('EMPRESAS',   'Empresas',        'Directorio de empresas', 3),
     ('SUCURSALES', 'Sucursales',      'Sucursales de empresa', 4),
     ('CONTACTOS',  'Contactos',       'Contactos de empresa', 5),
     ('PERSONAS',   'Personas',        'Personas y usuarios', 6),
     ('GEOGRAFIA',  'Geografia',       'Paises, divisiones, ubicaciones', 7),
     ('REPORTES',   'Reportes',        'Reportes y exportaciones', 8),
     ('AUDITORIA',  'Auditoria',       'Auditoria del sistema', 9),
     ('SEGURIDAD',  'Seguridad',       'Roles, permisos, tokens API', 10),
     ('ENCUESTAS',  'Encuestas',       'Encuestas de satisfaccion', 11),
     ('NOTIFICACIONES','Notificaciones','Notificaciones del sistema', 12);
END
GO

/* ----------------------------------------------------------------------------
 * 3. NOTIFICACIONES
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.notificacion_plantilla', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[notificacion_plantilla] (
        [id_plantilla]   INT IDENTITY(1,1) NOT NULL,
        [codigo]         NVARCHAR(50) NOT NULL,           -- TICKET_CREADO, TICKET_CERRADO...
        [titulo]         NVARCHAR(200) NOT NULL,
        [mensaje]        NVARCHAR(MAX) NOT NULL,           -- soporta placeholders {{nro_ticket}}
        [tipo]           NVARCHAR(20) NOT NULL,            -- INFO, WARNING, SUCCESS, ERROR
        [canal]          NVARCHAR(20) NOT NULL,            -- SISTEMA, EMAIL, PUSH, SMS
        [activa]         BIT NOT NULL DEFAULT (1),
        CONSTRAINT PK_notif_plantilla PRIMARY KEY CLUSTERED (id_plantilla),
        CONSTRAINT UQ_notif_cod UNIQUE NONCLUSTERED (codigo)
    );
END
GO

IF OBJECT_ID('dbo.notificacion', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[notificacion] (
        [id_notificacion] INT IDENTITY(1,1) NOT NULL,
        [id_plantilla]    INT NULL,
        [id_usuario_origen] INT NULL,                    -- quien disparo (NULL = sistema)
        [titulo]          NVARCHAR(200) NOT NULL,
        [mensaje]         NVARCHAR(MAX) NOT NULL,
        [tipo]            NVARCHAR(20) NOT NULL,
        [modulo]          NVARCHAR(50) NULL,
        [id_referencia]   INT NULL,                       -- id_ticket, id_atencion...
        [fecha_creacion]  DATETIME NOT NULL DEFAULT (GETDATE()),
        [fecha_expiracion] DATETIME NULL,
        CONSTRAINT PK_notificacion PRIMARY KEY CLUSTERED (id_notificacion),
        CONSTRAINT FK_notif_plantilla FOREIGN KEY (id_plantilla)
            REFERENCES [dbo].[notificacion_plantilla] (id_plantilla),
        CONSTRAINT FK_notif_origen FOREIGN KEY (id_usuario_origen)
            REFERENCES [dbo].[persona] (id_persona)
    );
END
GO

IF OBJECT_ID('dbo.notificacion_usuario', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[notificacion_usuario] (
        [id_notificacion_usuario] INT IDENTITY(1,1) NOT NULL,
        [id_notificacion] INT NOT NULL,
        [id_usuario]      INT NOT NULL,
        [leida]           BIT NOT NULL DEFAULT (0),
        [fecha_lectura]   DATETIME NULL,
        [enviada_email]   BIT NOT NULL DEFAULT (0),
        [enviada_push]    BIT NOT NULL DEFAULT (0),
        [fecha_envio_sistema] DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_notif_usuario PRIMARY KEY CLUSTERED (id_notificacion_usuario),
        CONSTRAINT FK_notif_usuario FOREIGN KEY (id_notificacion)
            REFERENCES [dbo].[notificacion] (id_notificacion) ON DELETE CASCADE,
        CONSTRAINT FK_notif_dest FOREIGN KEY (id_usuario)
            REFERENCES [dbo].[persona] (id_persona) ON DELETE CASCADE
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_notif_usuario_dest')
    CREATE NONCLUSTERED INDEX IX_notif_usuario_dest ON [dbo].[notificacion_usuario] (id_usuario, leida)
    INCLUDE (id_notificacion, fecha_envio_sistema);
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[notificacion_plantilla])
BEGIN
    INSERT INTO [dbo].[notificacion_plantilla] (codigo, titulo, mensaje, tipo, canal) VALUES
     ('TICKET_CREADO',     'Ticket #{{nro_ticket}} creado',      'Se ha registrado un nuevo ticket con prioridad {{prioridad}}.', 'INFO',    'SISTEMA'),
     ('TICKET_ASIGNADO',   'Ticket #{{nro_ticket}} asignado',    'Se le ha asignado el ticket {{nro_ticket}}.',                  'INFO',    'SISTEMA'),
     ('TICKET_EN_ATENCION','Ticket #{{nro_ticket}} en atencion', 'El ticket paso a EN_ATENCION.',                                'WARNING', 'SISTEMA'),
     ('TICKET_RESUELTO',   'Ticket #{{nro_ticket}} resuelto',    'El ticket fue marcado como RESUELTO.',                         'SUCCESS', 'SISTEMA'),
     ('TICKET_CERRADO',    'Ticket #{{nro_ticket}} cerrado',     'El ticket fue cerrado definitivamente.',                      'SUCCESS', 'EMAIL'),
     ('TICKET_REABIERTO',  'Ticket #{{nro_ticket}} reabierto',   'El ticket fue reabierto por el cliente.',                      'WARNING', 'PUSH'),
     ('ENCUESTA_DISPONIBLE','Encuesta disponible',               'Tiene una encuesta pendiente para el ticket {{nro_ticket}}.',  'INFO',    'EMAIL'),
     ('SISTEMA_MANTENIMIENTO','Mantenimiento programado',        'El sistema estara en mantenimiento desde {{fecha_ini}}.',      'WARNING', 'SISTEMA');
END
GO

/* ----------------------------------------------------------------------------
 * 4. API REST - tokens y registro de consumo
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.api_token', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[api_token] (
        [id_token]        INT IDENTITY(1,1) NOT NULL,
        [id_usuario]      INT NOT NULL,
        [nombre]          NVARCHAR(100) NOT NULL,             -- "Integracion ERP", "App movil"
        [token_hash]      NVARCHAR(128) NOT NULL,             -- SHA-512 del token (nunca el token plano)
        [prefix]          NVARCHAR(8) NOT NULL,              -- primeros 8 chars para identificacion rapida
        [scopes]          NVARCHAR(500) NULL,                 -- JSON: ["tickets:read","tickets:write"]
        [fecha_creacion]  DATETIME NOT NULL DEFAULT (GETDATE()),
        [fecha_expiracion] DATETIME NULL,
        [ultimo_uso]      DATETIME NULL,
        [activo]          BIT NOT NULL DEFAULT (1),
        CONSTRAINT PK_api_token PRIMARY KEY CLUSTERED (id_token),
        CONSTRAINT FK_api_token_usuario FOREIGN KEY (id_usuario)
            REFERENCES [dbo].[persona] (id_persona)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_api_token_hash')
    CREATE UNIQUE NONCLUSTERED INDEX IX_api_token_hash ON [dbo].[api_token] (token_hash);
GO

IF OBJECT_ID('dbo.api_endpoint_log', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[api_endpoint_log] (
        [id_log]        BIGINT IDENTITY(1,1) NOT NULL,
        [id_token]      INT NULL,                            -- NULL si auth fallida
        [endpoint]      NVARCHAR(200) NOT NULL,
        [metodo]        NVARCHAR(10) NOT NULL,               -- GET, POST, PUT, DELETE
        [status_code]   INT NOT NULL,
        [duracion_ms]   INT NOT NULL,
        [ip_origen]     NVARCHAR(100) NULL,
        [user_agent]    NVARCHAR(500) NULL,
        [request_body]  NVARCHAR(MAX) NULL,                  -- truncado a 4KB por app
        [error_mensaje] NVARCHAR(1000) NULL,
        [fecha_hora]    DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_api_log PRIMARY KEY CLUSTERED (id_log)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_api_log_fecha')
    CREATE NONCLUSTERED INDEX IX_api_log_fecha ON [dbo].[api_endpoint_log] (fecha_hora DESC)
    INCLUDE (id_token, endpoint, metodo, status_code);
GO

IF OBJECT_ID('dbo.api_rate_limit', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[api_rate_limit] (
        [id_token]      INT NOT NULL,
        [ventana_minuto] SMALLINT NOT NULL,                  -- ventana de tiempo (1, 5, 60)
        [limite]         INT NOT NULL,                       -- max requests por ventana
        [fecha_actualizacion] DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_api_rate PRIMARY KEY CLUSTERED (id_token, ventana_minuto)
    );
END
GO

/* ----------------------------------------------------------------------------
 * 5. AUDITORIA AVANZADA - cambios a nivel fila/campo
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.auditoria_config', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[auditoria_config] (
        [id_config]   INT IDENTITY(1,1) NOT NULL,
        [tabla]        NVARCHAR(100) NOT NULL,
        [auditar_insert] BIT NOT NULL DEFAULT (1),
        [auditar_update] BIT NOT NULL DEFAULT (1),
        [auditar_delete] BIT NOT NULL DEFAULT (1),
        [columnas_ignorar] NVARCHAR(500) NULL,               -- CSV: 'fecha_ultima_sesion,contador'
        [activa]       BIT NOT NULL DEFAULT (1),
        [fecha_config] DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_audit_config PRIMARY KEY CLUSTERED (id_config),
        CONSTRAINT UQ_audit_tabla UNIQUE NONCLUSTERED (tabla)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[auditoria_config])
BEGIN
    INSERT INTO [dbo].[auditoria_config] (tabla, columnas_ignorar) VALUES
     ('ticket',         'hora_registro,hora_cierre'),
     ('atencion',       'hora_inicio,hora_fin'),
     ('persona',        'contrasena'),
     ('empresa',        ''),
     ('empresa_sucursal',''),
     ('empresa_contacto',''),
     ('role_permissions','fecha_actualizacion'),
     ('api_token',      'ultimo_uso'),
     ('notificacion_usuario','fecha_lectura');
END
GO

IF OBJECT_ID('dbo.auditoria_cambio', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[auditoria_cambio] (
        [id_cambio]   BIGINT IDENTITY(1,1) NOT NULL,
        [tabla]       NVARCHAR(100) NOT NULL,
        [pk_tabla]    NVARCHAR(50) NOT NULL,                 -- valor PK como texto
        [operacion]   NVARCHAR(10) NOT NULL,                  -- INSERT, UPDATE, DELETE
        [campo]       NVARCHAR(100) NULL,                    -- NULL si INSERT/DELETE completo
        [valor_anterior] NVARCHAR(MAX) NULL,
        [valor_nuevo]    NVARCHAR(MAX) NULL,
        [id_usuario]     INT NULL,
        [usuario_nombre] NVARCHAR(100) NULL,                 -- desnormalizado para reportes
        [ip_origen]   NVARCHAR(100) NULL,
        [modulo]      NVARCHAR(50) NULL,
        [fecha_hora]  DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_audit_cambio PRIMARY KEY CLUSTERED (id_cambio)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_audit_cambio_tabla_pk')
    CREATE NONCLUSTERED INDEX IX_audit_cambio_tabla_pk ON [dbo].[auditoria_cambio] (tabla, pk_tabla, fecha_hora DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_audit_cambio_fecha')
    CREATE NONCLUSTERED INDEX IX_audit_cambio_fecha ON [dbo].[auditoria_cambio] (fecha_hora DESC)
    INCLUDE (tabla, operacion, id_usuario);
GO

/* ----------------------------------------------------------------------------
 * 6. EXPORTACION MASIVA - trabajos asincronos
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.exportacion_job', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[exportacion_job] (
        [id_job]         INT IDENTITY(1,1) NOT NULL,
        [id_usuario]     INT NOT NULL,
        [modulo]         NVARCHAR(50) NOT NULL,               -- TICKETS, ATENCIONES, AUDITORIA
        [formato]        NVARCHAR(10) NOT NULL,              -- XLSX, CSV, PDF, JSON
        [filtros_json]   NVARCHAR(MAX) NULL,
        [estado]         NVARCHAR(20) NOT NULL DEFAULT ('PENDIENTE'),  -- PENDIENTE, PROCESANDO, COMPLETADO, FALLIDO
        [total_registros] INT NULL,
        [ruta_archivo]   NVARCHAR(500) NULL,
        [tamano_bytes]   BIGINT NULL,
        [fecha_solicitud] DATETIME NOT NULL DEFAULT (GETDATE()),
        [fecha_inicio]   DATETIME NULL,
        [fecha_fin]      DATETIME NULL,
        [error_mensaje]  NVARCHAR(1000) NULL,
        CONSTRAINT PK_export_job PRIMARY KEY CLUSTERED (id_job)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_export_job_estado')
    CREATE NONCLUSTERED INDEX IX_export_job_estado ON [dbo].[exportacion_job] (estado, fecha_solicitud DESC);
GO

/* ----------------------------------------------------------------------------
 * 7. ENCUESTAS DE SATISFACCION
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.encuesta', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[encuesta] (
        [id_encuesta]    INT IDENTITY(1,1) NOT NULL,
        [nombre]         NVARCHAR(100) NOT NULL,
        [descripcion]    NVARCHAR(500) NULL,
        [activa]         BIT NOT NULL DEFAULT (1),
        [fecha_creacion] DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_encuesta PRIMARY KEY CLUSTERED (id_encuesta)
    );
END
GO

IF OBJECT_ID('dbo.encuesta_pregunta', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[encuesta_pregunta] (
        [id_pregunta]    INT IDENTITY(1,1) NOT NULL,
        [id_encuesta]    INT NOT NULL,
        [texto]          NVARCHAR(500) NOT NULL,
        [tipo]           NVARCHAR(20) NOT NULL,            -- ESCALA_1_5, ESCALA_1_10, TEXTO, SI_NO, MULTIPLE
        [opciones_json]  NVARCHAR(MAX) NULL,              -- para MULTIPLE: ["Malo","Regular","Bueno"]
        [obligatoria]    BIT NOT NULL DEFAULT (1),
        [orden]          INT NOT NULL DEFAULT (0),
        CONSTRAINT PK_enc_pregunta PRIMARY KEY CLUSTERED (id_pregunta),
        CONSTRAINT FK_enc_pregunta FOREIGN KEY (id_encuesta)
            REFERENCES [dbo].[encuesta] (id_encuesta) ON DELETE CASCADE
    );
END
GO

IF OBJECT_ID('dbo.encuesta_respuesta', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[encuesta_respuesta] (
        [id_respuesta]   BIGINT IDENTITY(1,1) NOT NULL,
        [id_encuesta]    INT NOT NULL,
        [id_ticket]      INT NOT NULL,
        [id_persona]     INT NULL,                         -- contacto que responde
        [id_pregunta]    INT NOT NULL,
        [valor_texto]    NVARCHAR(MAX) NULL,
        [valor_numero]   DECIMAL(10,2) NULL,
        [fecha_respuesta] DATETIME NOT NULL DEFAULT (GETDATE()),
        CONSTRAINT PK_enc_respuesta PRIMARY KEY CLUSTERED (id_respuesta),
        CONSTRAINT FK_enc_resp_enc FOREIGN KEY (id_encuesta)
            REFERENCES [dbo].[encuesta] (id_encuesta),
        CONSTRAINT FK_enc_resp_ticket FOREIGN KEY (id_ticket)
            REFERENCES [dbo].[ticket] (id_ticket),
        CONSTRAINT FK_enc_resp_pers FOREIGN KEY (id_persona)
            REFERENCES [dbo].[persona] (id_persona),
        CONSTRAINT FK_enc_resp_preg FOREIGN KEY (id_pregunta)
            REFERENCES [dbo].[encuesta_pregunta] (id_pregunta)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_enc_resp_ticket')
    CREATE NONCLUSTERED INDEX IX_enc_resp_ticket ON [dbo].[encuesta_respuesta] (id_ticket, id_encuesta);
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[encuesta])
BEGIN
    INSERT INTO [dbo].[encuesta] (nombre, descripcion) VALUES
     ('Encuesta de Satisfaccion de Ticket', 'Encuesta estandar post-cierre de ticket');

    DECLARE @id_enc INT = SCOPE_IDENTITY();

    INSERT INTO [dbo].[encuesta_pregunta] (id_encuesta, texto, tipo, opciones_json, orden) VALUES
     (@id_enc, 'Como califica la atencion recibida?',         'ESCALA_1_5',  NULL,           1),
     (@id_enc, 'El tecnico resolvio su problema?',           'SI_NO',       NULL,           2),
     (@id_enc, 'Como califica el tiempo de respuesta?',      'ESCALA_1_5',  NULL,           3),
     (@id_enc, 'Recomendaria nuestro servicio?',             'ESCALA_1_10', NULL,           4),
     (@id_enc, 'Comentarios adicionales',                    'TEXTO',       NULL,           5);
END
GO

/* ----------------------------------------------------------------------------
 * 8. PWA - suscripciones push (Web Push API)
 * ---------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.pwa_subscription', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[pwa_subscription] (
        [id_subscription] INT IDENTITY(1,1) NOT NULL,
        [id_usuario]      INT NOT NULL,
        [endpoint]        NVARCHAR(500) NOT NULL,
        [p256dh_key]      NVARCHAR(200) NOT NULL,
        [auth_key]        NVARCHAR(100) NOT NULL,
        [user_agent]      NVARCHAR(500) NULL,
        [fecha_alta]      DATETIME NOT NULL DEFAULT (GETDATE()),
        [activa]          BIT NOT NULL DEFAULT (1),
        CONSTRAINT PK_pwa_sub PRIMARY KEY CLUSTERED (id_subscription)
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_pwa_sub_usuario')
    CREATE NONCLUSTERED INDEX IX_pwa_sub_usuario ON [dbo].[pwa_subscription] (id_usuario, activa);
GO

/* ============================================================================
 *  STORED PROCEDURES - DASHBOARD / REPORTES
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_dashboard_kpis', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_dashboard_kpis AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_dashboard_kpis]
    @fecha_desde DATE = NULL,
    @fecha_hasta DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @fecha_desde IS NULL SET @fecha_desde = DATEADD(DAY, -30, CAST(GETDATE() AS DATE));
    IF @fecha_hasta IS NULL SET @fecha_hasta = CAST(GETDATE() AS DATE);

    -- KPIs principales en un solo result set
    SELECT
        (SELECT COUNT(*) FROM dbo.ticket WHERE fecha_registro BETWEEN @fecha_desde AND @fecha_hasta) AS tickets_total,
        (SELECT COUNT(*) FROM dbo.ticket WHERE estado = 'PENDIENTE')  AS tickets_pendientes,
        (SELECT COUNT(*) FROM dbo.ticket WHERE estado = 'EN_ATENCION') AS tickets_en_atencion,
        (SELECT COUNT(*) FROM dbo.ticket WHERE estado IN ('RESUELTO','CERRADO')
            AND fecha_registro BETWEEN @fecha_desde AND @fecha_hasta)  AS tickets_resueltos,
        (SELECT COUNT(*) FROM dbo.ticket WHERE prioridad = 'CRITICA'
            AND estado NOT IN ('CERRADO'))                            AS tickets_criticos,
        (SELECT COUNT(*) FROM dbo.atencion WHERE fecha_inicio BETWEEN @fecha_desde AND @fecha_hasta) AS atenciones_total,
        (SELECT ISNULL(AVG(duracion_minutos),0) FROM dbo.atencion_tiempo at
            INNER JOIN dbo.atencion a ON at.id_atencion = a.id_atencion
            WHERE a.fecha_inicio BETWEEN @fecha_desde AND @fecha_hasta) AS tiempo_promedio_minutos,
        (SELECT COUNT(*) FROM dbo.ticket WHERE estado IN ('RESUELTO','CERRADO')
            AND fecha_cierre BETWEEN @fecha_desde AND @fecha_hasta)   AS tickets_cerrados_periodo,
        (SELECT COUNT(DISTINCT id_usuario) FROM dbo.atencion
            WHERE fecha_inicio BETWEEN @fecha_desde AND @fecha_hasta) AS tecnicos_activos;
END
GO

IF OBJECT_ID('dbo.sp_dashboard_series', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_dashboard_series AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_dashboard_series]
    @tipo NVARCHAR(30),            -- TICKETS_POR_DIA, TICKETS_POR_ESTADO, TICKETS_POR_PRIORIDAD,
                                   -- TICKETS_POR_EMPRESA, ATENCIONES_POR_TECNICO, TIEMPOS_POR_DIA
    @fecha_desde DATE = NULL,
    @fecha_hasta DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @fecha_desde IS NULL SET @fecha_desde = DATEADD(DAY, -30, CAST(GETDATE() AS DATE));
    IF @fecha_hasta IS NULL SET @fecha_hasta = CAST(GETDATE() AS DATE);

    IF @tipo = 'TICKETS_POR_DIA'
    BEGIN
        SELECT CONVERT(VARCHAR(10), fecha_registro, 23) AS fecha,
               COUNT(*) AS total,
               SUM(CASE WHEN estado = 'CERRADO' THEN 1 ELSE 0 END) AS cerrados,
               SUM(CASE WHEN estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes
        FROM dbo.ticket
        WHERE fecha_registro BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY CONVERT(VARCHAR(10), fecha_registro, 23)
        ORDER BY fecha;
    END
    ELSE IF @tipo = 'TICKETS_POR_ESTADO'
    BEGIN
        SELECT estado, COUNT(*) AS total
        FROM dbo.ticket
        WHERE fecha_registro BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY estado
        ORDER BY total DESC;
    END
    ELSE IF @tipo = 'TICKETS_POR_PRIORIDAD'
    BEGIN
        SELECT prioridad, COUNT(*) AS total
        FROM dbo.ticket
        WHERE fecha_registro BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY prioridad
        ORDER BY CASE prioridad WHEN 'CRITICA' THEN 1 WHEN 'ALTA' THEN 2 WHEN 'MEDIA' THEN 3 ELSE 4 END;
    END
    ELSE IF @tipo = 'TICKETS_POR_EMPRESA'
    BEGIN
        SELECT TOP 15 e.razon_social AS empresa, COUNT(*) AS total
        FROM dbo.ticket t INNER JOIN dbo.empresa e ON t.id_empresa = e.id_empresa
        WHERE t.fecha_registro BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY e.razon_social ORDER BY total DESC;
    END
    ELSE IF @tipo = 'ATENCIONES_POR_TECNICO'
    BEGIN
        SELECT TOP 15 p.nombres + ' ' + p.apellido_paterno AS tecnico, COUNT(*) AS atenciones,
               ISNULL(AVG(at.duracion_minutos),0) AS promedio_minutos
        FROM dbo.atencion a
        INNER JOIN dbo.persona p ON a.id_usuario = p.id_persona
        LEFT JOIN dbo.atencion_tiempo at ON a.id_atencion = at.id_atencion
        WHERE a.fecha_inicio BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY p.nombres, p.apellido_paterno
        ORDER BY atenciones DESC;
    END
    ELSE IF @tipo = 'TIEMPOS_POR_DIA'
    BEGIN
        SELECT CONVERT(VARCHAR(10), a.fecha_inicio, 23) AS fecha,
               ISNULL(AVG(at.duracion_minutos),0) AS promedio_minutos,
               COUNT(*) AS atenciones
        FROM dbo.atencion a
        LEFT JOIN dbo.atencion_tiempo at ON a.id_atencion = at.id_atencion
        WHERE a.fecha_inicio BETWEEN @fecha_desde AND @fecha_hasta
        GROUP BY CONVERT(VARCHAR(10), a.fecha_inicio, 23)
        ORDER BY fecha;
    END
END
GO

/* ============================================================================
 *  STORED PROCEDURES - ROLES Y PERMISOS AVANZADOS
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_permisos_get', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_permisos_get AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_permisos_get]
    @id_rol INT
AS
BEGIN
    SET NOCOUNT ON;
    -- Modulos disponibles
    SELECT id_modulo, codigo, nombre, descripcion, orden
    FROM dbo.modulo_catalogo WHERE activo = 1 ORDER BY orden;

    -- Permisos actuales del rol
    SELECT rp.modulo, rp.ver, rp.registrar, rp.modificar, rp.eliminar,
           rp.aprobar, rp.exportar, rp.imprimir
    FROM dbo.role_permissions rp
    WHERE rp.id_rol = @id_rol;
END
GO

IF OBJECT_ID('dbo.sp_permisos_save_v2', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_permisos_save_v2 AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_permisos_save_v2]
    @id_rol INT,
    @permisos_json NVARCHAR(MAX),
    @id_usuario_admin INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRANSACTION;
    BEGIN TRY
        DELETE FROM dbo.role_permissions WHERE id_rol = @id_rol;

        -- Recorrer modulos del catalogo
        DECLARE @id_modulo INT, @codigo NVARCHAR(50);
        DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
            SELECT id_modulo, codigo FROM dbo.modulo_catalogo WHERE activo = 1;
        OPEN cur; FETCH NEXT FROM cur INTO @id_modulo, @codigo;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            DECLARE @path NVARCHAR(200) = '$.' + @codigo;
            DECLARE @ver BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.ver'), 0);
            DECLARE @reg BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.registrar'), 0);
            DECLARE @mod BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.modificar'), 0);
            DECLARE @eli BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.eliminar'), 0);
            DECLARE @apr BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.aprobar'), 0);
            DECLARE @exp BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.exportar'), 0);
            DECLARE @imp BIT = ISNULL(JSON_VALUE(@permisos_json, @path + '.imprimir'), 0);

            INSERT INTO dbo.role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
            VALUES (@id_rol, @codigo, @ver, @reg, @mod, @eli, @apr, @exp, @imp, GETDATE());

            FETCH NEXT FROM cur INTO @id_modulo, @codigo;
        END
        CLOSE cur; DEALLOCATE cur;

        -- Auditoria
        INSERT INTO dbo.auditoria_operacion (usuario, modulo, operacion, detalles)
        SELECT ISNULL(p.correo,'Sistema'), 'SEGURIDAD', 'PERMISOS_UPDATE',
               'Permisos actualizados para rol #' + CAST(@id_rol AS NVARCHAR)
        FROM dbo.persona p WHERE p.id_persona = @id_usuario_admin;

        COMMIT TRANSACTION;
        SELECT 1 AS success;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR(@msg, 16, 1);
    END CATCH
END
GO

/* ============================================================================
 *  STORED PROCEDURES - NOTIFICACIONES
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_notificacion_enviar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_notificacion_enviar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_notificacion_enviar]
    @codigo_plantilla NVARCHAR(50),
    @id_usuarios NVARCHAR(MAX),          -- JSON array: [1,2,3]
    @valores_json NVARCHAR(MAX) = NULL,  -- {"nro_ticket":"2026-00001","prioridad":"ALTA"}
    @id_referencia INT = NULL,
    @modulo NVARCHAR(50) = NULL,
    @id_notificacion INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRANSACTION;
    BEGIN TRY
        DECLARE @titulo NVARCHAR(200), @mensaje NVARCHAR(MAX), @tipo NVARCHAR(20), @canal NVARCHAR(20);
        SELECT @titulo = titulo, @mensaje = mensaje, @tipo = tipo, @canal = canal
        FROM dbo.notificacion_plantilla
        WHERE codigo = @codigo_plantilla AND activa = 1;

        IF @titulo IS NULL
        BEGIN
            RAISERROR('Plantilla de notificacion no encontrada o inactiva: %s', 16, 1, @codigo_plantilla);
            ROLLBACK; RETURN;
        END

        -- Reemplazo de placeholders {{clave}} por valor
        IF @valores_json IS NOT NULL
        BEGIN
            DECLARE @clave NVARCHAR(100), @valor NVARCHAR(500);
            DECLARE c CURSOR LOCAL FAST_FORWARD FOR
                SELECT [key], [value] FROM OPENJSON(@valores_json);
            OPEN c; FETCH NEXT FROM c INTO @clave, @valor;
            WHILE @@FETCH_STATUS = 0
            BEGIN
                SET @titulo  = REPLACE(@titulo,  '{{' + @clave + '}}', @valor);
                SET @mensaje = REPLACE(@mensaje, '{{' + @clave + '}}', @valor);
                FETCH NEXT FROM c INTO @clave, @valor;
            END
            CLOSE c; DEALLOCATE c;
        END

        INSERT INTO dbo.notificacion (titulo, mensaje, tipo, modulo, id_referencia)
        VALUES (@titulo, @mensaje, @tipo, @modulo, @id_referencia);
        SET @id_notificacion = SCOPE_IDENTITY();

        -- Insertar una fila por destinatario
        INSERT INTO dbo.notificacion_usuario (id_notificacion, id_usuario)
        SELECT @id_notificacion, CAST([value] AS INT)
        FROM OPENJSON(@id_usuarios);

        COMMIT TRANSACTION;
        SELECT @id_notificacion AS id_notificacion;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR(@msg, 16, 1);
    END CATCH
END
GO

IF OBJECT_ID('dbo.sp_notificacion_listar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_notificacion_listar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_notificacion_listar]
    @id_usuario INT,
    @solo_no_leidas BIT = 0,
    @limite INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@limite)
        nu.id_notificacion_usuario,
        n.id_notificacion,
        n.titulo,
        n.mensaje,
        n.tipo,
        n.modulo,
        n.id_referencia,
        nu.leida,
        CONVERT(VARCHAR(19), nu.fecha_envio_sistema, 120) AS fecha,
        nu.fecha_lectura
    FROM dbo.notificacion_usuario nu
    INNER JOIN dbo.notificacion n ON nu.id_notificacion = n.id_notificacion
    WHERE nu.id_usuario = @id_usuario
      AND (@solo_no_leidas = 0 OR nu.leida = 0)
    ORDER BY nu.fecha_envio_sistema DESC;
END
GO

IF OBJECT_ID('dbo.sp_notificacion_marcar_leida', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_notificacion_marcar_leida AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_notificacion_marcar_leida]
    @id_notificacion_usuario INT,
    @id_usuario INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.notificacion_usuario
    SET leida = 1, fecha_lectura = GETDATE()
    WHERE id_notificacion_usuario = @id_notificacion_usuario
      AND id_usuario = @id_usuario;
    SELECT 1 AS success;
END
GO

/* ============================================================================
 *  STORED PROCEDURES - API REST
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_api_token_crear', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_api_token_crear AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_api_token_crear]
    @id_usuario INT,
    @nombre NVARCHAR(100),
    @scopes NVARCHAR(500) = NULL,
    @dias_expiracion INT = 365,
    @token_plano NVARCHAR(128) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        -- Token aleatorio de 64 chars hex
        DECLARE @raw VARBINARY(64) = CRYPT_GEN_RANDOM(32);
        SET @token_plano = 'msa_' + LOWER(CONVERT(NVARCHAR(64), @raw, 2));

        DECLARE @hash NVARCHAR(128) = CONVERT(NVARCHAR(128),
            HASHBYTES('SHA2_512', CONVERT(VARCHAR(MAX), @token_plano)), 2);

        INSERT INTO dbo.api_token (id_usuario, nombre, token_hash, prefix, scopes, fecha_expiracion)
        VALUES (@id_usuario, @nombre, @hash, LEFT(@token_plano, 12),
                @scopes, DATEADD(DAY, @dias_expiracion, GETDATE()));

        SELECT @token_plano AS token, SCOPE_IDENTITY() AS id_token;
    END TRY
    BEGIN CATCH
        DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
        RAISERROR(@msg, 16, 1);
    END CATCH
END
GO

IF OBJECT_ID('dbo.sp_api_token_validar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_api_token_validar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_api_token_validar]
    @token_plano NVARCHAR(128)
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @hash NVARCHAR(128) = CONVERT(NVARCHAR(128),
        HASHBYTES('SHA2_512', CONVERT(VARCHAR(MAX), @token_plano)), 2);

    -- FIX: usar variable de tabla porque api_token tiene triggers AFTER UPDATE.
    -- SQL Server no permite OUTPUT inserted.* directo en UPDATE sobre tablas con triggers.
    DECLARE @result TABLE (
        id_token INT,
        id_usuario INT,
        scopes NVARCHAR(500),
        activo BIT,
        fecha_expiracion DATETIME
    );

    UPDATE dbo.api_token
    SET ultimo_uso = GETDATE()
    OUTPUT inserted.id_token, inserted.id_usuario, inserted.scopes, inserted.activo,
           inserted.fecha_expiracion
    INTO @result
    WHERE token_hash = @hash;

    SELECT id_token, id_usuario, scopes, activo, fecha_expiracion FROM @result;
END
GO

IF OBJECT_ID('dbo.sp_api_log_registrar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_api_log_registrar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_api_log_registrar]
    @id_token INT = NULL,
    @endpoint NVARCHAR(200),
    @metodo NVARCHAR(10),
    @status_code INT,
    @duracion_ms INT,
    @ip_origen NVARCHAR(100) = NULL,
    @user_agent NVARCHAR(500) = NULL,
    @request_body NVARCHAR(MAX) = NULL,
    @error_mensaje NVARCHAR(1000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.api_endpoint_log (id_token, endpoint, metodo, status_code, duracion_ms,
                                      ip_origen, user_agent, request_body, error_mensaje)
    VALUES (@id_token, @endpoint, @metodo, @status_code, @duracion_ms,
            @ip_origen, @user_agent, @request_body, @error_mensaje);
END
GO

/* ============================================================================
 *  STORED PROCEDURES - AUDITORIA AVANZADA
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_audit_cambio_registrar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_audit_cambio_registrar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_audit_cambio_registrar]
    @tabla NVARCHAR(100),
    @pk_tabla NVARCHAR(50),
    @operacion NVARCHAR(10),
    @campo NVARCHAR(100) = NULL,
    @valor_anterior NVARCHAR(MAX) = NULL,
    @valor_nuevo NVARCHAR(MAX) = NULL,
    @id_usuario INT = NULL,
    @usuario_nombre NVARCHAR(100) = NULL,
    @ip_origen NVARCHAR(100) = NULL,
    @modulo NVARCHAR(50) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    -- Solo auditar si la tabla esta activa
    IF EXISTS (SELECT 1 FROM dbo.auditoria_config
               WHERE tabla = @tabla AND activa = 1)
    BEGIN
        INSERT INTO dbo.auditoria_cambio (tabla, pk_tabla, operacion, campo,
            valor_anterior, valor_nuevo, id_usuario, usuario_nombre, ip_origen, modulo)
        VALUES (@tabla, @pk_tabla, @operacion, @campo,
                @valor_anterior, @valor_nuevo, @id_usuario, @usuario_nombre, @ip_origen, @modulo);
    END
END
GO

IF OBJECT_ID('dbo.sp_audit_cambio_listar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_audit_cambio_listar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_audit_cambio_listar]
    @tabla NVARCHAR(100) = NULL,
    @pk_tabla NVARCHAR(50) = NULL,
    @operacion NVARCHAR(10) = NULL,
    @id_usuario INT = NULL,
    @fecha_desde DATETIME = NULL,
    @fecha_hasta DATETIME = NULL,
    @limite INT = 200
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@limite)
        id_cambio, tabla, pk_tabla, operacion, campo,
        LEFT(valor_anterior, 500) AS valor_anterior,
        LEFT(valor_nuevo, 500)    AS valor_nuevo,
        id_usuario, usuario_nombre, ip_origen, modulo,
        CONVERT(VARCHAR(19), fecha_hora, 120) AS fecha_hora
    FROM dbo.auditoria_cambio
    WHERE (@tabla IS NULL OR tabla = @tabla)
      AND (@pk_tabla IS NULL OR pk_tabla = @pk_tabla)
      AND (@operacion IS NULL OR operacion = @operacion)
      AND (@id_usuario IS NULL OR id_usuario = @id_usuario)
      AND (@fecha_desde IS NULL OR fecha_hora >= @fecha_desde)
      AND (@fecha_hasta IS NULL OR fecha_hora <= @fecha_hasta)
    ORDER BY fecha_hora DESC;
END
GO

/* ============================================================================
 *  STORED PROCEDURES - EXPORTACION MASIVA
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_exportacion_crear', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_exportacion_crear AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_exportacion_crear]
    @id_usuario INT,
    @modulo NVARCHAR(50),
    @formato NVARCHAR(10),
    @filtros_json NVARCHAR(MAX) = NULL,
    @id_job INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.exportacion_job (id_usuario, modulo, formato, filtros_json, estado, fecha_solicitud)
    VALUES (@id_usuario, @modulo, @formato, @filtros_json, 'PENDIENTE', GETDATE());
    SET @id_job = SCOPE_IDENTITY();
    SELECT @id_job AS id_job;
END
GO

IF OBJECT_ID('dbo.sp_exportacion_actualizar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_exportacion_actualizar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_exportacion_actualizar]
    @id_job INT,
    @estado NVARCHAR(20),
    @total_registros INT = NULL,
    @ruta_archivo NVARCHAR(500) = NULL,
    @tamano_bytes BIGINT = NULL,
    @error_mensaje NVARCHAR(1000) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.exportacion_job
    SET estado = @estado,
        total_registros = ISNULL(@total_registros, total_registros),
        ruta_archivo = ISNULL(@ruta_archivo, ruta_archivo),
        tamano_bytes = ISNULL(@tamano_bytes, tamano_bytes),
        error_mensaje = ISNULL(@error_mensaje, error_mensaje),
        fecha_inicio = CASE WHEN @estado = 'PROCESANDO' THEN GETDATE() ELSE fecha_inicio END,
        fecha_fin = CASE WHEN @estado IN ('COMPLETADO','FALLIDO') THEN GETDATE() ELSE fecha_fin END
    WHERE id_job = @id_job;
    SELECT 1 AS success;
END
GO

IF OBJECT_ID('dbo.sp_exportacion_listar', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_exportacion_listar AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_exportacion_listar]
    @id_usuario INT = NULL,
    @estado NVARCHAR(20) = NULL,
    @limite INT = 50
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@limite)
        j.id_job, j.modulo, j.formato, j.estado, j.total_registros,
        j.ruta_archivo, j.tamano_bytes,
        CONVERT(VARCHAR(19), j.fecha_solicitud, 120) AS fecha_solicitud,
        CONVERT(VARCHAR(19), j.fecha_inicio, 120)    AS fecha_inicio,
        CONVERT(VARCHAR(19), j.fecha_fin, 120)      AS fecha_fin,
        j.error_mensaje,
        p.nombres + ' ' + p.apellido_paterno AS usuario
    FROM dbo.exportacion_job j
    INNER JOIN dbo.persona p ON j.id_usuario = p.id_persona
    WHERE (@id_usuario IS NULL OR j.id_usuario = @id_usuario)
      AND (@estado IS NULL OR j.estado = @estado)
    ORDER BY j.fecha_solicitud DESC;
END
GO

/* ============================================================================
 *  STORED PROCEDURES - ENCUESTAS DE SATISFACCION
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_encuesta_obtener', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_encuesta_obtener AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_encuesta_obtener]
    @id_ticket INT
AS
BEGIN
    SET NOCOUNT ON;
    -- Verifica si el ticket esta cerrado y sin encuesta previa
    DECLARE @estado NVARCHAR(20), @ya_respondio BIT = 0;
    SELECT @estado = estado FROM dbo.ticket WHERE id_ticket = @id_ticket;
    IF @estado NOT IN ('RESUELTO','CERRADO')
    BEGIN
        SELECT 0 AS disponible, 'El ticket no esta cerrado' AS mensaje,
               NULL AS id_encuesta, NULL AS nombre_encuesta;
        RETURN;
    END
    IF EXISTS (SELECT 1 FROM dbo.encuesta_respuesta WHERE id_ticket = @id_ticket)
        SET @ya_respondio = 1;

    IF @ya_respondio = 1
    BEGIN
        SELECT 0 AS disponible, 'Encuesta ya respondida' AS mensaje,
               NULL AS id_encuesta, NULL AS nombre_encuesta;
        RETURN;
    END

    -- Traer encuesta activa con sus preguntas
    SELECT @estado AS ticket_estado, e.id_encuesta, e.nombre, e.descripcion
    FROM dbo.encuesta e WHERE e.activa = 1;

    SELECT ep.id_pregunta, ep.texto, ep.tipo, ep.opciones_json, ep.obligatoria, ep.orden
    FROM dbo.encuesta e
    INNER JOIN dbo.encuesta_pregunta ep ON e.id_encuesta = ep.id_encuesta
    WHERE e.activa = 1
    ORDER BY ep.orden;
END
GO

IF OBJECT_ID('dbo.sp_encuesta_responder', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_encuesta_responder AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_encuesta_responder]
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
            -- Variables declaradas previamente (T-SQL no permite string + (SELECT ...) en llamada EXEC)
            DECLARE @nro_ticket_var NVARCHAR(50);
            SELECT @nro_ticket_var = nro_ticket FROM dbo.ticket WHERE id_ticket = @id_ticket;

            DECLARE @id_usuarios_json NVARCHAR(MAX);
            SET @id_usuarios_json = '[' + CAST(@id_tecnico AS NVARCHAR(20)) + ']';

            DECLARE @valores_json_var NVARCHAR(MAX);
            SET @valores_json_var = '{"nro_ticket":"' + ISNULL(@nro_ticket_var, '') + '"}';

            DECLARE @id_notif INT;
            EXEC dbo.sp_notificacion_enviar
                 @codigo_plantilla = 'ENCUESTA_DISPONIBLE',
                 @id_usuarios       = @id_usuarios_json,
                 @valores_json      = @valores_json_var,
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

IF OBJECT_ID('dbo.sp_encuesta_reporte', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_encuesta_reporte AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_encuesta_reporte]
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

/* ============================================================================
 *  TRIGGERS DE AUDITORIA AUTOMATICA (tablas criticas)
 *  Insertan en auditoria_cambio sin tocar la logica de los SP existentes.
 * ========================================================================== */

-- Trigger: ticket AFTER INSERT/UPDATE/DELETE
IF OBJECT_ID('trg_audit_ticket', 'TR') IS NULL
BEGIN
    EXEC('CREATE TRIGGER trg_audit_ticket ON dbo.ticket AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON; END');
END
GO
ALTER TRIGGER [dbo].[trg_audit_ticket] ON [dbo].[ticket]
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.auditoria_config WHERE tabla = 'ticket' AND activa = 1) RETURN;
    DECLARE @ip NVARCHAR(100) = CONVERT(NVARCHAR(100), CONNECTIONPROPERTY('client_net_address'));
    DECLARE @op NVARCHAR(10) =
        CASE WHEN EXISTS (SELECT 1 FROM inserted) AND EXISTS (SELECT 1 FROM deleted) THEN 'UPDATE'
             WHEN EXISTS (SELECT 1 FROM inserted) THEN 'INSERT'
             ELSE 'DELETE' END;

    IF @op IN ('INSERT','UPDATE')
    BEGIN
        INSERT INTO dbo.auditoria_cambio (tabla, pk_tabla, operacion, campo, valor_anterior, valor_nuevo, ip_origen, modulo, fecha_hora)
        SELECT 'ticket', CAST(i.id_ticket AS NVARCHAR(50)), @op, 'registro',
               (SELECT * FROM deleted d WHERE d.id_ticket = i.id_ticket FOR JSON PATH),
               (SELECT * FROM inserted  x WHERE x.id_ticket = i.id_ticket FOR JSON PATH),
               @ip, 'TICKETS', GETDATE()
        FROM inserted i;
    END
    ELSE -- DELETE
    BEGIN
        INSERT INTO dbo.auditoria_cambio (tabla, pk_tabla, operacion, campo, valor_anterior, ip_origen, modulo, fecha_hora)
        SELECT 'ticket', CAST(d.id_ticket AS NVARCHAR(50)), 'DELETE', 'registro',
               (SELECT * FROM deleted x WHERE x.id_ticket = d.id_ticket FOR JSON PATH),
               @ip, 'TICKETS', GETDATE()
        FROM deleted d;
    END
END
GO

-- Trigger: persona AFTER UPDATE (solo si cambia contrasena o bloqueado)
IF OBJECT_ID('trg_audit_persona', 'TR') IS NULL
BEGIN
    EXEC('CREATE TRIGGER trg_audit_persona ON dbo.persona AFTER UPDATE AS BEGIN SET NOCOUNT ON; END');
END
GO
ALTER TRIGGER [dbo].[trg_audit_persona] ON [dbo].[persona]
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.auditoria_config WHERE tabla = 'persona' AND activa = 1) RETURN;
    DECLARE @ip NVARCHAR(100) = CONVERT(NVARCHAR(100), CONNECTIONPROPERTY('client_net_address'));

    -- Solo registrar cambios en contrasena o bloqueado
    IF UPDATE(contrasena) OR UPDATE(bloqueado)
    BEGIN
        INSERT INTO dbo.auditoria_cambio (tabla, pk_tabla, operacion, campo, valor_anterior, valor_nuevo, ip_origen, modulo, fecha_hora)
        SELECT 'persona', CAST(i.id_persona AS NVARCHAR(50)), 'UPDATE',
               CASE WHEN UPDATE(contrasena) THEN 'contrasena' ELSE 'bloqueado' END,
               CASE WHEN UPDATE(contrasena) THEN '[OCULTO]' ELSE CAST(d.bloqueado AS NVARCHAR) END,
               CASE WHEN UPDATE(contrasena) THEN '[OCULTO]' ELSE CAST(i.bloqueado AS NVARCHAR) END,
               @ip, 'SEGURIDAD', GETDATE()
        FROM inserted i INNER JOIN deleted d ON i.id_persona = d.id_persona;
    END
END
GO

-- Trigger: api_token AFTER UPDATE (registrar uso)
IF OBJECT_ID('trg_audit_api_token', 'TR') IS NULL
BEGIN
    EXEC('CREATE TRIGGER trg_audit_api_token ON dbo.api_token AFTER UPDATE AS BEGIN SET NOCOUNT ON; END');
END
GO
ALTER TRIGGER [dbo].[trg_audit_api_token] ON [dbo].[api_token]
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.auditoria_config WHERE tabla = 'api_token' AND activa = 1) RETURN;
    DECLARE @ip NVARCHAR(100) = CONVERT(NVARCHAR(100), CONNECTIONPROPERTY('client_net_address'));

    IF UPDATE(activo) OR UPDATE(ultimo_uso) AND 0=1
    BEGIN
        -- Auditamos solo cambios de estado (activo), no cada uso
        INSERT INTO dbo.auditoria_cambio (tabla, pk_tabla, operacion, campo, valor_anterior, valor_nuevo, ip_origen, modulo, fecha_hora)
        SELECT 'api_token', CAST(i.id_token AS NVARCHAR(50)), 'UPDATE', 'activo',
               CAST(d.activo AS NVARCHAR), CAST(i.activo AS NVARCHAR), @ip, 'API', GETDATE()
        FROM inserted i INNER JOIN deleted d ON i.id_token = d.id_token
        WHERE i.activo <> d.activo;
    END
END
GO

/* ============================================================================
 *  STORED PROCEDURE: Sincroniza sp_sync_all original con nuevas tablas
 * ========================================================================== */
IF OBJECT_ID('dbo.sp_sync_all_v2', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_sync_all_v2 AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_sync_all_v2]
AS
BEGIN
    SET NOCOUNT ON;
    -- Catalogos nuevos
    SELECT * FROM dbo.modulo_catalogo ORDER BY orden;
    SELECT * FROM dbo.notificacion_plantilla WHERE activa = 1;
    SELECT * FROM dbo.encuesta WHERE activa = 1;
    SELECT * FROM dbo.encuesta_pregunta ORDER BY orden;
    SELECT * FROM dbo.auditoria_config ORDER BY tabla;
    -- Datos transaccionales nuevos (top 1000)
    SELECT TOP 1000 * FROM dbo.notificacion ORDER BY id_notificacion DESC;
    SELECT TOP 1000 * FROM dbo.notificacion_usuario ORDER BY id_notificacion_usuario DESC;
    SELECT TOP 1000 * FROM dbo.auditoria_cambio ORDER BY id_cambio DESC;
    SELECT TOP 500  * FROM dbo.api_endpoint_log ORDER BY id_log DESC;
    SELECT TOP 500  * FROM dbo.exportacion_job ORDER BY fecha_solicitud DESC;
    SELECT TOP 500  * FROM dbo.encuesta_respuesta ORDER BY id_respuesta DESC;
END
GO

/* ============================================================================
 *  DATOS INICIALES - Permisos por defecto para rol 1 (Administrador)
 *  Completar todos los modulos con ver=1, registrar=1, etc.
 * ========================================================================== */
IF NOT EXISTS (SELECT 1 FROM dbo.role_permissions WHERE id_rol = 1)
BEGIN
    INSERT INTO dbo.role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    SELECT 1, codigo, 1, 1, 1, 1, 1, 1, 1, GETDATE()
    FROM dbo.modulo_catalogo WHERE activo = 1;   -- FIX: columna 'activo' (no 'activa')
END
GO

PRINT 'Script de extension completado exitosamente.';
GO
