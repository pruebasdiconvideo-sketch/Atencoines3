<?php
/**
 * api/routes/cotizaciones_catalogos.php
 *
 * Catálogos del módulo de Cotizaciones.
 *
 * Endpoints:
 *   GET    /cotizaciones_catalogos                    Todos los catálogos en un solo request
 *   GET    /cotizaciones_catalogos/tipos              Tipos de cotización
 *   GET    /cotizaciones_catalogos/zonas             Zonas de pricing
 *   GET    /cotizaciones_catalogos/formas_pago       Formas de pago
 *   GET    /cotizaciones_catalogos/garantias        Garantías
 *   GET    /cotizaciones_catalogos/plazos            Plazos de entrega
 *   GET    /cotizaciones_catalogos/valideces        Valideces
 *   GET    /cotizaciones_catalogos/cuentas           Cuentas bancarias (por emisora si ?id_emisora=)
 *   GET    /cotizaciones_catalogos/productos         Productos del catálogo
 *
 * SPs usados:
 *   - sp_cotizacion_catalogos_listar (devuelve 8 result sets)
 *   - sp_cotizacion_producto_listar
 */

Auth::requirePermission('COTIZACIONES', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;   // tipo de catálogo
$action = $segments[2] ?? null;

if ($method !== 'GET') {
    ApiResponse::error('method_not_allowed', 'Solo GET permitido', 405);
}

$pdo = Database::connection();

/* =====================================================================
 *  GET /cotizaciones_catalogos — Todos en un request (8 result sets)
 * ===================================================================== */
if ($id === null) {
    try {
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_catalogos_listar");
        $stmt->execute();

        $tipos        = $stmt->fetchAll();
        $stmt->nextRowset();
        $emisoras     = $stmt->fetchAll();
        $stmt->nextRowset();
        $zonas        = $stmt->fetchAll();
        $stmt->nextRowset();
        $formasPago   = $stmt->fetchAll();
        $stmt->nextRowset();
        $garantias    = $stmt->fetchAll();
        $stmt->nextRowset();
        $plazos       = $stmt->fetchAll();
        $stmt->nextRowset();
        $valideces    = $stmt->fetchAll();
        $stmt->nextRowset();
        $cuentas      = $stmt->fetchAll();

        ApiResponse::success([
            'tipos'         => $tipos,
            'emisoras'      => $emisoras,
            'zonas'         => $zonas,
            'formas_pago'   => $formasPago,
            'garantias'     => $garantias,
            'plazos'        => $plazos,
            'valideces'     => $valideces,
            'cuentas'       => $cuentas,
        ], 200);
    } catch (Throwable $e) {
        ApiResponse::error('catalogos_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  GET /cotizaciones_catalogos/productos — Listado de productos
 * ===================================================================== */
if ($id === 'productos') {
    try {
        $search = $_GET['search'] ?? null;
        $tipoProd = $_GET['id_tipo_producto'] ?? null;
        $incluirInactivos = !empty($_GET['incluir_inactivos']) ? 1 : 0;
        $limite = (int)($_GET['limite'] ?? 500);

        $stmt = $pdo->prepare(
            "EXEC dbo.sp_cotizacion_producto_listar
                @id_tipo_producto = ?, @search_term = ?,
                @incluir_inactivos = ?, @limite = ?"
        );
        $stmt->bindValue(1, $tipoProd, PDO::PARAM_STR);
        $stmt->bindValue(2, $search,   PDO::PARAM_STR);
        $stmt->bindValue(3, $incluirInactivos, PDO::PARAM_INT);
        $stmt->bindValue(4, $limite,   PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('productos_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  GET /cotizaciones_catalogos/cuentas — Cuentas bancarias por emisora
 * ===================================================================== */
if ($id === 'cuentas') {
    try {
        $idEmisora = isset($_GET['id_emisora']) ? (int)$_GET['id_emisora'] : null;

        $sql = "SELECT id_cuenta, id_emisora, banco, numero_cuenta, cci,
                       moneda, titular, acepta_tarjeta, orden, activo,
                       CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion
                FROM dbo.cotizacion_cuenta_bancaria
                WHERE activo = 1";
        $params = [];
        if ($idEmisora !== null) {
            $sql .= " AND id_emisora = ?";
            $params[] = $idEmisora;
        }
        $sql .= " ORDER BY id_emisora, orden";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('cuentas_failed', $e->getMessage(), 500);
    }
}

/* =====================================================================
 *  GET /cotizaciones_catalogos/{tipo} — Catálogo individual
 * ===================================================================== */
$catalogosIndividuales = [
    'tipos'       => ['tabla' => 'dbo.cotizacion_tipo_catalogo',
                      'campos' => 'id_tipo, codigo, nombre, titulo_documento, prefijo_codigo, descripcion, orden, activo, codigo_por_empresa',
                      'orden'  => 'orden'],
    'zonas'       => ['tabla' => 'dbo.cotizacion_zona_pricing',
                      'campos' => 'id_zona, codigo, nombre, descripcion, orden, activo',
                      'orden'  => 'orden'],
    'formas_pago' => ['tabla' => 'dbo.cotizacion_forma_pago',
                      'campos' => 'id_forma_pago, descripcion, descuento_pct, orden, activo',
                      'orden'  => 'orden'],
    'garantias'   => ['tabla' => 'dbo.cotizacion_garantia',
                      'campos' => 'id_garantia, descripcion, orden, activo',
                      'orden'  => 'orden'],
    'plazos'      => ['tabla' => 'dbo.cotizacion_plazo_entrega',
                      'campos' => 'id_plazo, descripcion, orden, activo',
                      'orden'  => 'orden'],
    'valideces'   => ['tabla' => 'dbo.cotizacion_validez',
                      'campos' => 'id_validez, descripcion, dias, orden, activo',
                      'orden'  => 'orden'],
];

if (isset($catalogosIndividuales[$id])) {
    try {
        $cfg = $catalogosIndividuales[$id];
        $sql = "SELECT {$cfg['campos']},
                       CONVERT(VARCHAR(19), fecha_creacion, 120) AS fecha_creacion
                FROM {$cfg['tabla']}
                WHERE activo = 1
                ORDER BY {$cfg['orden']}";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        ApiResponse::success($rows, 200);
    } catch (Throwable $e) {
        ApiResponse::error('catalogo_failed', $e->getMessage(), 500);
    }
}

ApiResponse::error('not_found',
    "Catálogo no reconocido. Disponibles: tipos, zonas, formas_pago, garantias, plazos, valideces, cuentas, productos",
    404
);
