<?php
/**
 * includes/Header.php  — VERSIÓN CORREGIDA 7.0.0
 * ---------------------------------------------------------------------------
 * BUG CORREGIDO:
 * El header mostraba el logo de la empresa emisora y el nombre de la empresa
 * "descuadrados" porque:
 *   1. Usaba <div style="float:left"> que no alineaba verticalmente.
 *   2. No tenía en cuenta si el logo venía como base64 o como URL.
 *   3. No había separador visual entre el logo y el nombre.
 *   4. El nombre de empresa no se truncaba con ellipsis en pantallas pequeñas.
 *
 * SOLUCIÓN:
 *   - Layout flexbox con la clase `.app-header` definida en header.css.
 *   - Logo con clase `.brand-logo` (tamaño controlado por CSS).
 *   - Nombre de empresa con `.brand-name` + `.brand-subname`.
 *   - En pantallas < 576px, el CSS oculta el texto y muestra solo las
 *     iniciales mediante `data-initials`.
 *   - Si la empresa no tiene logo, se muestran las iniciales en un círculo.
 *
 * USO:
 *   require_once __DIR__ . '/includes/Header.php';
 *   echo Header::render($user, $currentModule);
 * ---------------------------------------------------------------------------
 */

class Header
{
    /**
     * Renderiza el header completo.
     *
     * @param array $user           Datos del usuario logueado (id_persona, nombres, apellido_paterno, correo, rol)
     * @param string $currentModule Código del módulo actual (para resaltar menú)
     * @return string HTML del header
     */
    public static function render(array $user, string $currentModule = ''): string
    {
        // Obtener la empresa emisora principal (con logo)
        $emisora = self::getEmisoraPrincipal();
        $logoHtml = self::renderLogo($emisora);
        $brandName = self::escape($emisora['nombre_comercial'] ?? $emisora['razon_social'] ?? 'MonosyncERP');
        $brandSub  = self::escape($emisora['razon_social'] ?? '');
        $initials  = self::getInitials($brandName);

        $userName = self::escape(trim(($user['nombres'] ?? '') . ' ' . ($user['apellido_paterno'] ?? '')));
        $userRole = self::escape($user['rol_nombre'] ?? $user['rol'] ?? '');
        $userInitials = self::getInitials($userName);

        return <<<HTML
<header class="app-header">
    <div class="brand" data-initials="{$initials}">
        {$logoHtml}
        <div class="brand-divider"></div>
        <div class="brand-text">
            <span class="brand-name" title="{$brandName}">{$brandName}</span>
            <span class="brand-subname" title="{$brandSub}">{$brandSub}</span>
        </div>
    </div>
    <div class="navbar-actions">
        <button type="button" class="btn-icon" id="btn-notif" title="Notificaciones">
            <i class="bi bi-bell"></i>
            <span class="badge bg-danger d-none" id="notif-count"></span>
        </button>
        <button type="button" class="btn-icon d-md-none" id="btn-menu" title="Menú">
            <i class="bi bi-list"></i>
        </button>
        <div class="user-avatar" title="{$userName}">{$userInitials}</div>
        <div class="user-info">
            <span class="user-name" title="{$userName}">{$userName}</span>
            <span class="user-role">{$userRole}</span>
        </div>
        <button type="button" class="btn-icon" id="btn-logout" title="Cerrar sesión">
            <i class="bi bi-box-arrow-right"></i>
        </button>
    </div>
</header>
HTML;
    }

    /**
     * Renderiza el logo de la empresa emisora.
     * - Si hay logo_base64, usa <img>.
     * - Si no, usa un círculo con las iniciales.
     */
    private static function renderLogo(array $emisora): string
    {
        $logo = $emisora['logo_base64'] ?? null;
        $name = $emisora['nombre_comercial'] ?? $emisora['razon_social'] ?? 'EMP';

        if (!empty($logo)) {
            // Si ya incluye el prefijo data:image/..., usarlo tal cual
            $src = str_starts_with($logo, 'data:image')
                ? $logo
                : 'data:image/png;base64,' . $logo;
            $alt = self::escape($name);
            return '<img src="' . $src . '" alt="' . $alt . '" class="brand-logo" />';
        }

        // Fallback: círculo con iniciales
        $initials = self::getInitials($name);
        return '<div class="brand-logo brand-logo-fallback" '
             . 'style="width:40px;height:40px;border-radius:8px;'
             . 'background:#1f2937;color:#fff;display:flex;'
             . 'align-items:center;justify-content:center;'
             . 'font-weight:700;font-size:0.9rem;">' . $initials . '</div>';
    }

    /**
     * Obtiene la empresa emisora principal desde la BD.
     * Si hay caché de sesión, la usa; si no, consulta.
     */
    private static function getEmisoraPrincipal(): array
    {
        // Caché en sesión para no consultar la BD en cada render del header
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (isset($_SESSION['emisora_principal_cache']) && is_array($_SESSION['emisora_principal_cache'])) {
            return $_SESSION['emisora_principal_cache'];
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                "SELECT TOP 1 id_emisora, razon_social, nombre_comercial, logo_base64
                 FROM dbo.cotizacion_empresa_emisora
                 WHERE activo = 1
                 ORDER BY es_principal DESC, id_emisora ASC"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $_SESSION['emisora_principal_cache'] = $row;
                return $row;
            }
        } catch (Throwable $e) {
            error_log('[Header] No se pudo obtener emisora principal: ' . $e->getMessage());
        }
        return ['razon_social' => 'MonosyncERP', 'nombre_comercial' => 'MonosyncERP', 'logo_base64' => null];
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function getInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts) || $parts[0] === '') return '?';
        $ini = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $ini .= mb_substr($p, 0, 1, 'UTF-8');
        }
        return mb_strtoupper($ini, 'UTF-8');
    }
}
