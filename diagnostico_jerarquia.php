<?php
/**
 * diagnostico_jerarquia.php — Diagnostica la jerarquia geografica
 *
 * Sube este archivo a la raiz del proyecto y abrilo en el navegador:
 *   http://localhost:8036/diagnostico_jerarquia.php
 *
 * Te mostrara:
 *   1. Cuantos paises hay en dbo.pais
 *   2. Cuantas divisiones hay por cada id_tipo_division
 *   3. Para los departamentos (tipo 2): cuantos tienen id_pais lleno
 *      vs cuantos tienen id_division_padre apuntando a tipo 1
 *   4. Para las provincias (tipo 3): cuantos tienen padre tipo 2
 *   5. Para los distritos (tipo 4): cuantos tienen padre tipo 3
 *   6. Los 5 primeros departamentos con su padre
 *   7. Los 5 primeras provincias con su padre
 *   8. Los 5 primeros distritos con su padre
 *
 * BORRAR DESPUES DE PROBAR.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/config/database.php';

echo "================================================================\n";
echo "  DIAGNOSTICO DE JERARQUIA GEOGRAFICA\n";
echo "================================================================\n\n";

try {
    $pdo = Database::connection();

    echo "[1] PAISES EN dbo.pais\n";
    $stmt = $pdo->query("SELECT id_pais, nombre, codigo_iso FROM dbo.pais ORDER BY id_pais");
    $paises = $stmt->fetchAll();
    foreach ($paises as $p) {
        echo "  - id_pais={$p['id_pais']}  nombre={$p['nombre']}  iso={$p['codigo_iso']}\n";
    }
    echo "\n";

    echo "[2] DIVISIONES POR TIPO\n";
    $stmt = $pdo->query("
        SELECT id_tipo_division, COUNT(*) AS total
        FROM dbo.division_geografica
        GROUP BY id_tipo_division
        ORDER BY id_tipo_division
    ");
    while ($r = $stmt->fetch()) {
        $tipo = (int)$r['id_tipo_division'];
        $nombre = ['Pais (raiz)', 'Departamento', 'Provincia', 'Distrito'][$tipo - 1] ?? "Tipo $tipo";
        echo "  tipo {$tipo} ({$nombre}): {$r['total']} divisiones\n";
    }
    echo "\n";

    echo "[3] DEPARTAMENTOS (tipo 2) - estado de id_pais y padre\n";
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN id_pais IS NOT NULL THEN 1 ELSE 0 END) AS con_id_pais,
            SUM(CASE WHEN id_pais IS NULL THEN 1 ELSE 0 END) AS sin_id_pais,
            SUM(CASE WHEN id_division_padre IS NULL THEN 1 ELSE 0 END) AS sin_padre,
            SUM(CASE WHEN id_division_padre IS NOT NULL THEN 1 ELSE 0 END) AS con_padre
        FROM dbo.division_geografica
        WHERE id_tipo_division = 2
    ");
    $r = $stmt->fetch();
    echo "  Total departamentos: {$r['total']}\n";
    echo "  Con id_pais lleno:   {$r['con_id_pais']}\n";
    echo "  Sin id_pais (NULL):  {$r['sin_id_pais']}\n";
    echo "  Con padre asignado:  {$r['con_padre']}\n";
    echo "  Sin padre (NULL):    {$r['sin_padre']}\n";
    echo "\n";

    echo "[4] PROVINCIAS (tipo 3) - estado\n";
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN id_division_padre IS NULL THEN 1 ELSE 0 END) AS sin_padre,
            SUM(CASE WHEN id_division_padre IS NOT NULL THEN 1 ELSE 0 END) AS con_padre
        FROM dbo.division_geografica
        WHERE id_tipo_division = 3
    ");
    $r = $stmt->fetch();
    echo "  Total provincias:    {$r['total']}\n";
    echo "  Con padre asignado:  {$r['con_padre']}\n";
    echo "  Sin padre (NULL):    {$r['sin_padre']}\n";
    echo "\n";

    echo "[5] DISTRITOS (tipo 4) - estado\n";
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN id_division_padre IS NULL THEN 1 ELSE 0 END) AS sin_padre,
            SUM(CASE WHEN id_division_padre IS NOT NULL THEN 1 ELSE 0 END) AS con_padre
        FROM dbo.division_geografica
        WHERE id_tipo_division = 4
    ");
    $r = $stmt->fetch();
    echo "  Total distritos:     {$r['total']}\n";
    echo "  Con padre asignado:  {$r['con_padre']}\n";
    echo "  Sin padre (NULL):    {$r['sin_padre']}\n";
    echo "\n";

    echo "[6] PRIMEROS 5 DEPARTAMENTOS (con su padre)\n";
    $stmt = $pdo->query("
        SELECT TOP 5
            d.id_division, d.nombre, d.id_pais,
            d.id_division_padre, p.nombre AS padre_nombre,
            p.id_tipo_division AS padre_tipo
        FROM dbo.division_geografica d
        LEFT JOIN dbo.division_geografica p ON d.id_division_padre = p.id_division
        WHERE d.id_tipo_division = 2
        ORDER BY d.nombre
    ");
    while ($r = $stmt->fetch()) {
        echo "  - id={$r['id_division']}  nombre={$r['nombre']}  id_pais={$r['id_pais']}  padre={$r['id_division_padre']} ({$r['padre_nombre']}, tipo {$r['padre_tipo']})\n";
    }
    echo "\n";

    echo "[7] PRIMERAS 5 PROVINCIAS (con su padre)\n";
    $stmt = $pdo->query("
        SELECT TOP 5
            d.id_division, d.nombre,
            d.id_division_padre, p.nombre AS padre_nombre,
            p.id_tipo_division AS padre_tipo
        FROM dbo.division_geografica d
        LEFT JOIN dbo.division_geografica p ON d.id_division_padre = p.id_division
        WHERE d.id_tipo_division = 3
        ORDER BY d.nombre
    ");
    while ($r = $stmt->fetch()) {
        echo "  - id={$r['id_division']}  nombre={$r['nombre']}  padre={$r['id_division_padre']} ({$r['padre_nombre']}, tipo {$r['padre_tipo']})\n";
    }
    echo "\n";

    echo "[8] PRIMEROS 5 DISTRITOS (con su padre)\n";
    $stmt = $pdo->query("
        SELECT TOP 5
            d.id_division, d.nombre,
            d.id_division_padre, p.nombre AS padre_nombre,
            p.id_tipo_division AS padre_tipo
        FROM dbo.division_geografica d
        LEFT JOIN dbo.division_geografica p ON d.id_division_padre = p.id_division
        WHERE d.id_tipo_division = 4
        ORDER BY d.nombre
    ");
    while ($r = $stmt->fetch()) {
        echo "  - id={$r['id_division']}  nombre={$r['nombre']}  padre={$r['id_division_padre']} ({$r['padre_nombre']}, tipo {$r['padre_tipo']})\n";
    }
    echo "\n";

    echo "[9] DIVISIONES TIPO 1 (pais-division) Y SU id_pais\n";
    $stmt = $pdo->query("
        SELECT d.id_division, d.nombre, d.id_pais, p.nombre AS pais_nombre
        FROM dbo.division_geografica d
        LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
        WHERE d.id_tipo_division = 1
        ORDER BY d.id_pais
    ");
    while ($r = $stmt->fetch()) {
        echo "  - id_division={$r['id_division']}  nombre={$r['nombre']}  id_pais={$r['id_pais']}  pais_tabla={$r['pais_nombre']}\n";
    }
    echo "\n";

    echo "[10] PROBAR fn_pais_de_division() PARA LOS PRIMEROS DEPARTAMENTOS\n";
    $stmt = $pdo->query("
        SELECT TOP 5 id_division, nombre, dbo.fn_pais_de_division(id_division) AS id_pais_calculado
        FROM dbo.division_geografica
        WHERE id_tipo_division = 2
        ORDER BY id_division
    ");
    while ($r = $stmt->fetch()) {
        echo "  - id={$r['id_division']}  nombre={$r['nombre']}  fn_pais_de_division={$r['id_pais_calculado']}\n";
    }
    echo "\n";

    echo "================================================================\n";
    echo "  DIAGNOSTICO COMPLETADO\n";
    echo "================================================================\n";
    echo "\n";
    echo "Si en [3] ves que la mayoria de departamentos tienen sin_padre > 0\n";
    echo "(es decir, id_division_padre IS NULL), entonces la jerarquia esta mal\n";
    echo "configurada en la BD y el filtro en cascada no funciona.\n";
    echo "\n";
    echo "Solucion: ejecutar UPDATEs en SQL Server para asignar el padre correcto.\n";
    echo "\n";
    echo "*** BORRA ESTE ARCHIVO DESPUES DE PROBAR ***\n";

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
