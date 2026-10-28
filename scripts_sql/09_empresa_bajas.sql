USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

IF COL_LENGTH(N'dbo.empresa', N'activo') IS NULL
BEGIN
    ALTER TABLE dbo.empresa
        ADD activo BIT NOT NULL
            CONSTRAINT DF_empresa_activo DEFAULT (1) WITH VALUES;
END;
GO

IF COL_LENGTH(N'dbo.empresa', N'fecha_baja') IS NULL
BEGIN
    ALTER TABLE dbo.empresa ADD fecha_baja DATETIME2(0) NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.empresa')
      AND name = N'IX_empresa_activo_id'
)
BEGIN
    CREATE INDEX IX_empresa_activo_id ON dbo.empresa(activo, id_empresa);
END;
GO