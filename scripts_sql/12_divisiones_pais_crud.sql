USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

IF COL_LENGTH(N'dbo.division_geografica', N'id_pais') IS NULL
BEGIN
    ALTER TABLE dbo.division_geografica ADD id_pais INT NULL;
END;
GO

UPDATE dbo.division_geografica
SET id_pais = dbo.fn_pais_de_division(id_division)
WHERE id_tipo_division = 1 AND id_pais IS NULL;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_division_geografica_pais'
      AND parent_object_id = OBJECT_ID(N'dbo.division_geografica')
)
BEGIN
    ALTER TABLE dbo.division_geografica WITH CHECK
        ADD CONSTRAINT FK_division_geografica_pais
        FOREIGN KEY (id_pais) REFERENCES dbo.pais(id_pais);
END;
GO

CREATE OR ALTER FUNCTION dbo.fn_pais_de_division (@id_division INT)
RETURNS INT
AS
BEGIN
    DECLARE @current_id INT = @id_division;
    DECLARE @pais_id INT = NULL;

    WHILE @current_id IS NOT NULL
    BEGIN
        SELECT @pais_id = id_pais
        FROM dbo.division_geografica
        WHERE id_division = @current_id AND id_tipo_division = 1;

        IF @pais_id IS NOT NULL RETURN @pais_id;

        SELECT @current_id = id_division_padre
        FROM dbo.division_geografica
        WHERE id_division = @current_id;
    END

    RETURN NULL;
END;
GO
