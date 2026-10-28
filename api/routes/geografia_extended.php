<?php
/**
 * api/routes/geografia_extended.php
 *
 * Endpoints adicionales para filtros geográficos en cascada.
 * Se carga automáticamente desde api/index.php si el resource es
 * 'geografia' y la acción coincide.
 *
 * Endpoints:
 *   GET /geografia/cascada?id_pais=X
 *     Devuelve: { departamentos: [...], provincias: [...], distritos: [...] }
 *     Filtra todo por el pais indicado.
 *
 *   GET /geografia/departamentos?id_pais=X
 *     Devuelve solo departamentos del pais.
 *
 *   GET /geografia/provincias?id_departamento=X
 *     Devuelve provincias del departamento.
 *
 *   GET /geografia/distritos?id_provincia=X
 *     Devuelve distritos de la provincia.
 *
 *   GET /geografia/ruta?id_division=X
 *     Devuelve la ruta completa de una division (pais > depto > prov > distrito).
 */

// Si este archivo se incluye directamente desde api/index.php, $pdo y
// $action ya están definidos. Si no, salir.
if (!isset($pdo)) {
    http_response_code(403);
    exit('Forbidden');
}

/* Helper: devolver JSON y salir */
function geo_json_success($data) {
    ApiResponse::success($data, 200);
}

/* GET /geografia/cascada?id_pais=X
 * Devuelve la jerarquía completa en una sola petición (para frontend). */
if (($method ?? 'GET') === 'GET' && ($action ?? null) === 'cascada') {
    $idPais = (int)($_GET['id_pais'] ?? 0);

    // Departamentos (nivel 2 en la vista, o tipo_division=1 en tabla según esquema)
    $deptos = $pdo->prepare("
        SELECT d.id_division, d.nombre
        FROM dbo.division_geografica d
        WHERE d.id_pais = ?
          AND d.id_division_padre IS NULL
        ORDER BY d.nombre
    ");
    $deptos->execute([$idPais]);
    $departamentos = $deptos->fetchAll();

    // Provincias (hijas de cualquier departamento del pais)
    $provs = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_departamento
        FROM dbo.division_geografica d
        WHERE d.id_pais = ?
          AND d.id_division_padre IN (
              SELECT id_division FROM dbo.division_geografica
              WHERE id_pais = ? AND id_division_padre IS NULL
          )
        ORDER BY d.nombre
    ");
    $provs->execute([$idPais, $idPais]);
    $provincias = $provs->fetchAll();

    // Distritos (hijos de cualquier provincia del pais)
    $dists = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_provincia
        FROM dbo.division_geografica d
        WHERE d.id_pais = ?
          AND d.id_division_padre IN (
              SELECT id_division FROM dbo.division_geografica
              WHERE id_pais = ? AND id_division_padre IN (
                  SELECT id_division FROM dbo.division_geografica
                  WHERE id_pais = ? AND id_division_padre IS NULL
              )
          )
        ORDER BY d.nombre
    ");
    $dists->execute([$idPais, $idPais, $idPais]);
    $distritos = $dists->fetchAll();

    geo_json_success([
        'departamentos' => $departamentos,
        'provincias'    => $provincias,
        'distritos'     => $distritos,
    ]);
}

/* GET /geografia/departamentos?id_pais=X */
if (($method ?? 'GET') === 'GET' && ($action ?? null) === 'departamentos') {
    $idPais = (int)($_GET['id_pais'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_pais,
               p.nombre AS pais_nombre
        FROM dbo.division_geografica d
        LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
        WHERE d.id_pais = ? AND d.id_division_padre IS NULL
        ORDER BY d.nombre
    ");
    $stmt->execute([$idPais]);
    geo_json_success($stmt->fetchAll());
}

/* GET /geografia/provincias?id_departamento=X */
if (($method ?? 'GET') === 'GET' && ($action ?? null) === 'provincias') {
    $idDepto = (int)($_GET['id_departamento'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_departamento,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS departamento_nombre
        FROM dbo.division_geografica d
        WHERE d.id_division_padre = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$idDepto]);
    geo_json_success($stmt->fetchAll());
}

/* GET /geografia/distritos?id_provincia=X */
if (($method ?? 'GET') === 'GET' && ($action ?? null) === 'distritos') {
    $idProv = (int)($_GET['id_provincia'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT d.id_division, d.nombre, d.id_division_padre AS id_provincia,
               (SELECT nombre FROM dbo.division_geografica WHERE id_division = d.id_division_padre) AS provincia_nombre
        FROM dbo.division_geografica d
        WHERE d.id_division_padre = ?
        ORDER BY d.nombre
    ");
    $stmt->execute([$idProv]);
    geo_json_success($stmt->fetchAll());
}

/* GET /geografia/ruta?id_division=X
 * Devuelve la ruta completa: { pais, departamento, provincia, distrito } */
if (($method ?? 'GET') === 'GET' && ($action ?? null) === 'ruta') {
    $idDiv = (int)($_GET['id_division'] ?? 0);

    // Recorrer hacia arriba
    $ruta = [];
    $current = $idDiv;
    $depth = 0;
    while ($current && $depth < 10) {
        $stmt = $pdo->prepare("
            SELECT d.id_division, d.nombre, d.id_tipo_division,
                   d.id_division_padre, d.id_pais,
                   p.nombre AS pais_nombre
            FROM dbo.division_geografica d
            LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
            WHERE d.id_division = ?
        ");
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row) break;
        $ruta[] = $row;
        $current = $row['id_division_padre'];
        $depth++;
    }

    // Revertir para tener de padre -> hijo
    $ruta = array_reverse($ruta);

    geo_json_success([
        'ruta'         => $ruta,
        'pais'         => !empty($ruta[0]) ? $ruta[0] : null,
        'departamento' => !empty($ruta[1]) ? $ruta[1] : null,
        'provincia'    => !empty($ruta[2]) ? $ruta[2] : null,
        'distrito'     => !empty($ruta[3]) ? $ruta[3] : null,
    ]);
}
