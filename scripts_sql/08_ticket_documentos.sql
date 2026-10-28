USE [MonosyncERP];
GO

IF OBJECT_ID(N'dbo.ticket_documento', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ticket_documento
    (
        id_documento INT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_ticket_documento PRIMARY KEY,
        id_ticket INT NOT NULL,
        nombre_original NVARCHAR(255) NOT NULL,
        archivo_key CHAR(48) NOT NULL
            CONSTRAINT UQ_ticket_documento_archivo_key UNIQUE,
        tipo_mime VARCHAR(127) NOT NULL,
        tamano_bytes BIGINT NOT NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL
            CONSTRAINT DF_ticket_documento_fecha DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_ticket_documento_ticket
            FOREIGN KEY (id_ticket) REFERENCES dbo.ticket(id_ticket)
    );

    CREATE INDEX IX_ticket_documento_ticket
        ON dbo.ticket_documento(id_ticket, id_documento);
END;
GO