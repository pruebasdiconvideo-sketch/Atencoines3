USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

IF COL_LENGTH(N'dbo.persona', N'tipo_documento') IS NULL
    THROW 51001, 'No existe dbo.persona.tipo_documento.', 1;

IF COL_LENGTH(N'dbo.persona', N'correo') IS NULL
    THROW 51002, 'No existe dbo.persona.correo.', 1;
GO

ALTER TABLE dbo.persona
    ALTER COLUMN tipo_documento NVARCHAR(10) NULL;
GO

ALTER TABLE dbo.persona
    ALTER COLUMN correo NVARCHAR(100) NULL;
GO
