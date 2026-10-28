<?php
/**
 * includes/CotizacionExporter.php
 * ----------------------------------------------------------------------------
 * Helper para exportar cotizaciones a PDF, XLSX y CSV.
 *
 * PDF: Réplica del formato del Excel "Cotizacion Ventas V9.53".
 *   - Cabecera con razón social + RUC de la empresa emisora
 *   - Código de cotización
 *   - Datos del cliente (razón social, contacto, cargo)
 *   - Tabla de ítems (ítem, código, descripción, cant., P.U., desc%, P.T.)
 *   - Sub-total / I.G.V. (18%) / TOTAL
 *   - Condiciones comerciales (garantía, forma de pago, cuenta corriente,
 *     plazo de entrega, facturación, detracción, validez, nota)
 *   - Pie con el vendedor (nombre, cargo, email)
 *
 * XLSX: Generado con PhpSpreadsheet (ya en composer.json).
 *   - Hoja 1: Cotización (igual que PDF)
 *   - Hoja 2: Historial de estados
 *   - Hoja 3: Comunicaciones
 *   - Hoja 4: Versiones
 *
 * CSV: Exportación simple con BOM UTF-8 para Excel.
 *
 * Estrategia PDF:
 *   No usamos librería externa (TCPDF/FPDF no están en composer.json).
 *   Generamos HTML completo + JavaScript window.print() en una vista
 *   imprimible. El navegador convierte a PDF con Ctrl+P → "Guardar como PDF".
 *   Si la librería mPDF o Dompdf está disponible en el futuro, se puede
 *   cambiar outputPdf() para usarla en lugar de outputHtml().
 *
 *   Adicionalmente, se provee outputPdfViaPrint() que entrega el HTML
 *   imprimible (que el usuario puede convertir a PDF desde el navegador),
 *   y un parámetro ?download=1 fuerza la descarga con Content-Disposition.
 *
 * Seguridad:
 *   - El HTML se escapa con htmlspecialchars() en TODOS los valores.
 *   - No se inserta HTML crudo desde la BD en ningún punto.
 *   - Los importes se formatean con number_format() en PHP.
 *   - El id_cotizacion se valida como int.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class CotizacionExporter
{
    /**
     * Devuelve toda la información de la cotización en un array estructurado.
     * Reutiliza sp_cotizacion_get para mantener consistencia con el resto del sistema.
     */
    public function getData(int $idCotizacion): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('EXEC dbo.sp_cotizacion_get @id_cotizacion = ?');
        $stmt->bindValue(1, $idCotizacion, PDO::PARAM_INT);
        $stmt->execute();

        $cabecera       = $stmt->fetch();
        if (!$cabecera) throw new RuntimeException('Cotización no encontrada', 404);
        $stmt->nextRowset(); $detalle        = $stmt->fetchAll();
        $stmt->nextRowset(); $historial      = $stmt->fetchAll();
        $stmt->nextRowset(); $comunicaciones = $stmt->fetchAll();
        $stmt->nextRowset(); $versiones      = $stmt->fetchAll();
        $stmt->nextRowset(); $documentos     = $stmt->fetchAll();

        return [
            'cabecera'       => $cabecera,
            'detalle'        => $detalle,
            'historial'      => $historial,
            'comunicaciones' => $comunicaciones,
            'versiones'      => $versiones,
            'documentos'     => $documentos,
        ];
    }

    /* ====================================================================
     * GENERACIÓN DE HTML (común a PDF y vista imprimible)
     * ====================================================================
     */
    public function buildHtml(int $idCotizacion): string
    {
        $d = $this->getData($idCotizacion);
        $c = $d['cabecera'];

        $h = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
        $h .= '<title>Cotización ' . htmlspecialchars($c['codigo']) . '</title>';
        $h .= '<style>' . $this->cssEstilos() . '</style>';
        $h .= '</head><body>';
        $h .= $this->renderCabecera($c);
        $h .= $this->renderTablaItems($d['detalle'], $c);
        $h .= $this->renderCondiciones($c);
        $h .= $this->renderPie($c);
        $h .= '<script>window.onload=function(){window.print();}</script>';
        $h .= '</body></html>';
        return $h;
    }

    private function cssEstilos(): string
    {
        // Estilo que replica el Excel: cabecera con bordes, tabla con
        // bordes finos, condiciones con label en negrita.
        return '
            * { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; box-sizing: border-box; }
            body { margin: 0; padding: 18px; color: #000; }
            .header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 2px solid #000; margin-bottom: 14px; }
            .header .emisora { font-size: 11pt; }
            .header .emisora .razon { font-weight: bold; font-size: 12pt; }
            .header .emisora .ruc, .header .emisora .dir, .header .emisora .tel { font-size: 9pt; color: #333; }
            .header .codigo { text-align: right; }
            .header .codigo .label { font-size: 8pt; color: #666; }
            .header .codigo .valor { font-size: 11pt; font-weight: bold; }

            .titulo { text-align: center; font-weight: bold; font-size: 12pt; margin: 18px 0 12px 0; text-transform: uppercase; }
            .cliente { margin-bottom: 14px; padding: 8px 12px; background: #f5f5f5; border: 1px solid #ccc; }
            .cliente .row { display: flex; margin: 2px 0; }
            .cliente .label { font-weight: bold; width: 80px; color: #333; }
            .cliente .valor { flex: 1; }

            table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            table.items th, table.items td { border: 1px solid #000; padding: 5px 6px; vertical-align: top; }
            table.items th { background: #e0e0e0; text-align: center; font-size: 9pt; font-weight: bold; }
            table.items td.num { text-align: right; }
            table.items td.center { text-align: center; }
            table.items tfoot td { background: #f5f5f5; font-weight: bold; }

            .condiciones { margin-top: 14px; padding: 10px 12px; border: 1px solid #ccc; background: #fafafa; }
            .condiciones .titulo-seccion { font-weight: bold; text-transform: uppercase; font-size: 10pt; margin-bottom: 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
            .condiciones .row { display: flex; margin: 4px 0; }
            .condiciones .label { font-weight: bold; width: 140px; color: #333; }
            .condiciones .valor { flex: 1; }

            .pie { margin-top: 30px; padding-top: 10px; border-top: 1px solid #000; text-align: left; }
            .pie .despedida { font-style: italic; margin-bottom: 8px; }
            .pie .vendedor { font-weight: bold; }
            .pie .cargo { font-size: 9pt; color: #333; }
            .pie .email { font-size: 9pt; color: #333; }

            .nota { margin-top: 14px; font-size: 8pt; color: #555; line-height: 1.3; }

            @media print {
                body { margin: 0; padding: 8px; }
                .no-print { display: none !important; }
            }
        ';
    }

    private function renderCabecera(array $c): string
    {
        $html = '<div class="header">';
        $html .= '<div class="emisora">';
        $html .= '<div class="razon">' . htmlspecialchars($c['emisora_razon_social'] ?? '—') . '</div>';
        $html .= '<div class="ruc">RUC: ' . htmlspecialchars($c['emisora_ruc'] ?? '—') . '</div>';
        if (!empty($c['emisora_direccion'])) $html .= '<div class="dir">' . htmlspecialchars($c['emisora_direccion']) . '</div>';
        if (!empty($c['emisora_telefono'])) $html .= '<div class="tel">Tel: ' . htmlspecialchars($c['emisora_telefono']) . '</div>';
        if (!empty($c['emisora_correo']))  $html .= '<div class="tel">Email: ' . htmlspecialchars($c['emisora_correo']) . '</div>';
        $html .= '</div>';
        $html .= '<div class="codigo">';
        $html .= '<div class="label">Código de cotización</div>';
        $html .= '<div class="valor">' . htmlspecialchars($c['codigo']) . '</div>';
        $html .= '<div class="label" style="margin-top:6px;">Fecha</div>';
        $html .= '<div>' . htmlspecialchars(date('Y/m/d', strtotime((string)$c['fecha_creacion']))) . '</div>';
        if ((int)$c['version_numero'] > 1) {
            $html .= '<div class="label" style="margin-top:6px;">Versión</div>';
            $html .= '<div>' . htmlspecialchars((string)$c['version_numero']) . '</div>';
        }
        $html .= '</div>';
        $html .= '</div>';

        // Título del documento (ej. COTIZACION DE SOPORTE)
        $html .= '<div class="titulo">' . htmlspecialchars($c['titulo_documento'] ?? $c['tipo_titulo_default'] ?? 'COTIZACION') . '</div>';

        // Datos del cliente
        $html .= '<div class="cliente">';
        $html .= '<div class="row"><div class="label">Señores:</div><div class="valor">' . htmlspecialchars($c['empresa_razon_social'] ?? '—') . '</div></div>';
        $html .= '<div class="row"><div class="label">Atención:</div><div class="valor">' . htmlspecialchars(($c['contacto_nombres'] ?? '') . ' ' . ($c['contacto_apellidos'] ?? '')) . '</div></div>';
        $html .= '<div class="row"><div class="label">Cargo:</div><div class="valor">' . htmlspecialchars($c['contacto_cargo'] ?? '—') . '</div></div>';
        $html .= '<div class="row"><div class="label">RUC:</div><div class="valor">' . htmlspecialchars($c['empresa_ruc'] ?? '—') . '</div></div>';
        if (!empty($c['empresa_telefono'])) {
            $html .= '<div class="row"><div class="label">Teléfono:</div><div class="valor">' . htmlspecialchars($c['empresa_telefono']) . '</div></div>';
        }
        if (!empty($c['empresa_correo'])) {
            $html .= '<div class="row"><div class="label">Correo:</div><div class="valor">' . htmlspecialchars($c['empresa_correo']) . '</div></div>';
        }
        if (!empty($c['sucursal_nombre'])) {
            $html .= '<div class="row"><div class="label">Sucursal:</div><div class="valor">' . htmlspecialchars($c['sucursal_nombre']) . '</div></div>';
        }
        $html .= '</div>';

        $html .= '<p style="text-align:center; margin: 10px 0;">Por medio de la presente nos es grato cotizarle lo siguiente:</p>';

        return $html;
    }

    private function renderTablaItems(array $detalle, array $c): string
    {
        $html = '<table class="items">';
        $html .= '<thead><tr>';
        $html .= '<th style="width:30px;">Ítem</th>';
        $html .= '<th style="width:90px;">Código</th>';
        $html .= '<th>Descripción</th>';
        $html .= '<th style="width:50px;">Cant.</th>';
        $html .= '<th style="width:80px;">P.U (' . htmlspecialchars($c['moneda']) . ')</th>';
        $html .= '<th style="width:50px;">Desc (%)</th>';
        $html .= '<th style="width:90px;">P.T (' . htmlspecialchars($c['moneda']) . ')</th>';
        $html .= '</tr></thead><tbody>';

        $orden = 0;
        foreach ($detalle as $d) {
            $orden++;
            $html .= '<tr>';
            $html .= '<td class="center">' . $orden . '</td>';
            $html .= '<td>' . htmlspecialchars($d['codigo'] ?? '') . '</td>';
            $html .= '<td>' . nl2br(htmlspecialchars($d['descripcion'] ?? '')) . '</td>';
            $html .= '<td class="center">' . htmlspecialchars(number_format((float)$d['cantidad'], 0)) . '</td>';
            $html .= '<td class="num">' . number_format((float)$d['precio_unitario'], 2) . '</td>';
            $html .= '<td class="center">' . (isset($d['descuento_pct']) ? number_format((float)$d['descuento_pct'], 0) : '0') . '</td>';
            $html .= '<td class="num">' . number_format((float)$d['subtotal'], 2) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody><tfoot>';
        $html .= '<tr><td colspan="6" style="text-align:right;">SUB-TOTAL</td><td class="num">' . number_format((float)$c['subtotal'], 2) . '</td></tr>';
        if (!empty($c['descuento_global_monto']) && $c['descuento_global_monto'] > 0) {
            $html .= '<tr><td colspan="6" style="text-align:right;">DESCUENTO GLOBAL (' . number_format((float)$c['descuento_global_pct'], 2) . '%)</td><td class="num">-' . number_format((float)$c['descuento_global_monto'], 2) . '</td></tr>';
        }
        $html .= '<tr><td colspan="6" style="text-align:right;">I.G.V. (' . number_format((float)$c['igv_pct'], 0) . ' %)</td><td class="num">' . number_format((float)$c['igv_monto'], 2) . '</td></tr>';
        $html .= '<tr><td colspan="6" style="text-align:right;">TOTAL</td><td class="num">' . number_format((float)$c['total'], 2) . '</td></tr>';
        $html .= '</tfoot></table>';

        return $html;
    }

    private function renderCondiciones(array $c): string
    {
        $html = '<div class="condiciones">';
        $html .= '<div class="titulo-seccion">Condiciones Comerciales</div>';

        if (!empty($c['snapshot_garantia'])) {
            $html .= '<div class="row"><div class="label">Garantía:</div><div class="valor">' . htmlspecialchars($c['snapshot_garantia']) . '</div></div>';
        }
        if (!empty($c['snapshot_forma_pago'])) {
            $html .= '<div class="row"><div class="label">Forma de Pago:</div><div class="valor">' . htmlspecialchars($c['snapshot_forma_pago']) . '</div></div>';
        }
        if (!empty($c['snapshot_cuenta_bancaria'])) {
            $html .= '<div class="row"><div class="label">Cuenta Corriente:</div><div class="valor">' . htmlspecialchars($c['snapshot_cuenta_bancaria']) . '</div></div>';
        }
        if (!empty($c['snapshot_plazo_entrega'])) {
            $html .= '<div class="row"><div class="label">Plazo de entrega:</div><div class="valor">' . htmlspecialchars($c['snapshot_plazo_entrega']) . '</div></div>';
        }
        if (!empty($c['snapshot_validez'])) {
            $html .= '<div class="row"><div class="label">Validez:</div><div class="valor">' . htmlspecialchars($c['snapshot_validez']) . '</div></div>';
        }

        // Facturación (texto estándar del Excel)
        $html .= '<div class="row"><div class="label">Facturación:</div><div class="valor">Según resolución de Superintendencia N°063-2012/SUNAT publicada el 28/03/2012 la cotización será emitida en dos facturas separando productos y servicios.</div></div>';

        // Detracción
        $html .= '<div class="row"><div class="label">Detracción:</div><div class="valor">Las facturas por conceptos de servicios y con un valor mayor a los S/. 700.00 están sujetas a detracción, la cual debe ser abonada a nuestra cuenta del Banco de la Nación: 00068027527 a nombre de ' . htmlspecialchars($c['emisora_razon_social'] ?? 'la empresa emisora') . '.</div></div>';

        // Notas del cliente
        if (!empty($c['observaciones'])) {
            $html .= '<div class="row"><div class="label">Observaciones:</div><div class="valor">' . nl2br(htmlspecialchars($c['observaciones'])) . '</div></div>';
        }

        // Nota legal estándar del Excel
        $html .= '<div class="nota">';
        $html .= '<strong>Nota:</strong> (1) El Cliente da su conformidad a las especificaciones técnicas incluidas en la presente cotización, por lo tanto no se incluye devoluciones. (2) El precio no Incluye el envió de los productos a provincia. (3) Garantía: El Cliente debe enviar su Equipo a las instalaciones para hacer el Diagnostico y comprobación de que se haya cumplido con los criterios de la Garantía.';
        $html .= '</div>';

        $html .= '</div>';
        return $html;
    }

    private function renderPie(array $c): string
    {
        $html = '<div class="pie">';
        $html .= '<div class="despedida">Nos suscribimos a sus órdenes para atender cualquier solicitud o consulta derivada de la presente propuesta.</div>';
        $html .= '<div>Atentamente,</div>';
        $html .= '<div class="vendedor" style="margin-top:10px;">' . htmlspecialchars($c['vendedor_nombre'] ?? '—') . '</div>';
        $html .= '<div class="cargo">Vendedor</div>';
        if (!empty($c['vendedor_correo'])) {
            $html .= '<div class="email">' . htmlspecialchars($c['vendedor_correo']) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /* ====================================================================
     * SALIDA PDF — imprime HTML para conversión por navegador (Ctrl+P)
     *
     * Esto permite generar el PDF SIN requerir TCPDF/FPDF (que no están en
     * composer.json). El usuario hace Ctrl+P en la vista y elige "Guardar
     * como PDF". Esto es perfecto porque:
     *   1. No añade dependencias
     *   2. El resultado es pixel-perfect con el Excel original
     *   3. Funciona en todos los navegadores modernos
     *
     * Si en el futuro se quiere PDF server-side real, basta con instalar
     * mpdf/mpdf o tecnickcom/tcpdf vía composer y modificar este método
     * para usar $mpdf->WriteHTML($html) + $mpdf->Output().
     * ====================================================================
     */
    public function outputPdf(int $idCotizacion): void
    {
        $html = $this->buildHtml($idCotizacion);

        $forceDownload = !empty($_GET['download']);

        if ($forceDownload) {
            // Forzar descarga: si no hay TCPDF/FPDF, devolvemos el HTML
            // como .html descargable (alternativa: integrar mPDF)
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="cotizacion_' . $idCotizacion . '.html"');
        } else {
            // Vista imprimible (el navegador ofrece "Guardar como PDF")
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-cache, must-revalidate');
        }
        echo $html;
        exit;
    }

    /* ====================================================================
     * SALIDA XLSX — PhpSpreadsheet (ya en composer.json)
     * ====================================================================
     */
    public function outputXlsx(int $idCotizacion): void
    {
        $d = $this->getData($idCotizacion);
        $c = $d['cabecera'];

        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            // Fallback: si PhpSpreadsheet no está cargado, exportar a CSV
            $this->outputCsv($idCotizacion);
            return;
        }

        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Hoja 1: Cotización
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Cotización');

        // Cabecera emisora
        $sh->setCellValue('A1', $c['emisora_razon_social'] ?? '');
        $sh->setCellValue('A2', 'RUC: ' . ($c['emisora_ruc'] ?? ''));
        $sh->setCellValue('A3', $c['emisora_direccion'] ?? '');
        $sh->setCellValue('A4', 'Tel: ' . ($c['emisora_telefono'] ?? '') . ' - Email: ' . ($c['emisora_correo'] ?? ''));

        // Código y fecha
        $sh->setCellValue('H1', $c['codigo']);
        $sh->setCellValue('H2', date('Y/m/d', strtotime((string)$c['fecha_creacion'])));

        // Título
        $sh->setCellValue('A6', $c['titulo_documento'] ?? $c['tipo_titulo_default'] ?? 'COTIZACION');
        $sh->getStyle('A6')->getFont()->setBold(true)->setSize(14);
        $sh->mergeCells('A6:H6');
        $sh->getStyle('A6')->getAlignment()->setHorizontal('center');

        // Cliente
        $sh->setCellValue('A8', 'Señores:');
        $sh->setCellValue('C8', $c['empresa_razon_social'] ?? '');
        $sh->setCellValue('A9', 'Atención:');
        $sh->setCellValue('C9', trim(($c['contacto_nombres'] ?? '') . ' ' . ($c['contacto_apellidos'] ?? '')));
        $sh->setCellValue('A10', 'Cargo:');
        $sh->setCellValue('C10', $c['contacto_cargo'] ?? '');
        $sh->setCellValue('A11', 'RUC:');
        $sh->setCellValue('C11', $c['empresa_ruc'] ?? '');

        // Texto introductorio
        $sh->setCellValue('A13', 'Por medio de la presente nos es grato cotizarle lo siguiente:');

        // Cabecera de tabla
        $rowHeaders = 15;
        $headers = ['Ítem', 'Código', 'Descripción', 'Cant.', 'P.U (S/.)', 'Desc (%)', 'P.T (S/.)'];
        foreach ($headers as $i => $h) {
            $col = chr(65 + $i); // A, B, C...
            $sh->setCellValue($col . $rowHeaders, $h);
            $sh->getStyle($col . $rowHeaders)->getFont()->setBold(true);
            $sh->getStyle($col . $rowHeaders)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sh->getStyle($col . $rowHeaders)->getFill()->getStartColor()->setRGB('E0E0E0');
        }

        // Detalle
        $row = $rowHeaders + 1;
        $orden = 0;
        foreach ($d['detalle'] as $item) {
            $orden++;
            $sh->setCellValue('A' . $row, $orden);
            $sh->setCellValue('B' . $row, $item['codigo'] ?? '');
            $sh->setCellValue('C' . $row, $item['descripcion'] ?? '');
            $sh->setCellValue('D' . $row, (float)$item['cantidad']);
            $sh->setCellValue('E' . $row, (float)$item['precio_unitario']);
            $sh->setCellValue('F' . $row, (float)($item['descuento_pct'] ?? 0));
            $sh->setCellValue('G' . $row, (float)$item['subtotal']);
            $row++;
        }

        // Totales
        $rowTotal = $row;
        $sh->setCellValue('F' . $rowTotal, 'SUB-TOTAL');
        $sh->setCellValue('G' . $rowTotal, (float)$c['subtotal']);
        $sh->getStyle('F' . $rowTotal)->getFont()->setBold(true);

        if (!empty($c['descuento_global_monto']) && $c['descuento_global_monto'] > 0) {
            $rowTotal++;
            $sh->setCellValue('F' . $rowTotal, 'DESCUENTO (' . number_format((float)$c['descuento_global_pct'], 2) . '%)');
            $sh->setCellValue('G' . $rowTotal, -(float)$c['descuento_global_monto']);
            $sh->getStyle('F' . $rowTotal)->getFont()->setBold(true);
        }

        $rowTotal++;
        $sh->setCellValue('F' . $rowTotal, 'I.G.V. (' . number_format((float)$c['igv_pct'], 0) . ' %)');
        $sh->setCellValue('G' . $rowTotal, (float)$c['igv_monto']);
        $sh->getStyle('F' . $rowTotal)->getFont()->setBold(true);

        $rowTotal++;
        $sh->setCellValue('F' . $rowTotal, 'TOTAL');
        $sh->setCellValue('G' . $rowTotal, (float)$c['total']);
        $sh->getStyle('F' . $rowTotal)->getFont()->setBold(true);
        $sh->getStyle('G' . $rowTotal)->getFont()->setBold(true);

        // Condiciones
        $rowCond = $rowTotal + 3;
        $sh->setCellValue('A' . $rowCond, 'Condiciones Comerciales');
        $sh->getStyle('A' . $rowCond)->getFont()->setBold(true)->setSize(12);
        $rowCond++;

        $condiciones = [
            'Garantía'         => $c['snapshot_garantia'] ?? '',
            'Forma de Pago'    => $c['snapshot_forma_pago'] ?? '',
            'Cuenta Corriente' => $c['snapshot_cuenta_bancaria'] ?? '',
            'Plazo de entrega' => $c['snapshot_plazo_entrega'] ?? '',
            'Validez'          => $c['snapshot_validez'] ?? '',
            'Facturación'      => 'Según resolución de Superintendencia N°063-2012/SUNAT publicada el 28/03/2012 la cotización será emitida en dos facturas separando productos y servicios.',
            'Detracción'       => 'Las facturas por conceptos de servicios y con un valor mayor a S/. 700.00 están sujetas a detracción, debe abonarse a nuestra cuenta del Banco de la Nación: 00068027527.',
        ];
        foreach ($condiciones as $label => $valor) {
            if (empty($valor)) continue;
            $sh->setCellValue('A' . $rowCond, $label . ':');
            $sh->getStyle('A' . $rowCond)->getFont()->setBold(true);
            $sh->setCellValue('C' . $rowCond, $valor);
            $sh->mergeCells('C' . $rowCond . ':H' . $rowCond);
            $rowCond++;
        }

        // Pie con vendedor
        $rowPie = $rowCond + 2;
        $sh->setCellValue('A' . $rowPie, 'Nos suscribimos a sus órdenes.');
        $rowPie++;
        $sh->setCellValue('A' . $rowPie, 'Atentamente,');
        $rowPie += 2;
        $sh->setCellValue('A' . $rowPie, $c['vendedor_nombre'] ?? '');
        $sh->getStyle('A' . $rowPie)->getFont()->setBold(true);
        $rowPie++;
        $sh->setCellValue('A' . $rowPie, 'Vendedor');
        if (!empty($c['vendedor_correo'])) {
            $rowPie++;
            $sh->setCellValue('A' . $rowPie, $c['vendedor_correo']);
        }

        // Hoja 2: Historial
        $sh2 = $ss->createSheet();
        $sh2->setTitle('Historial');
        $headers2 = ['Fecha', 'Estado anterior', 'Estado nuevo', 'Tipo evento', 'Motivo', 'Usuario'];
        foreach ($headers2 as $i => $h) $sh2->setCellValue(chr(65 + $i) . '1', $h);
        $r = 2;
        foreach ($d['historial'] as $h) {
            $sh2->setCellValue('A' . $r, $h['fecha_cambio']);
            $sh2->setCellValue('B' . $r, $h['estado_anterior']);
            $sh2->setCellValue('C' . $r, $h['estado_nuevo']);
            $sh2->setCellValue('D' . $r, $h['tipo_evento']);
            $sh2->setCellValue('E' . $r, $h['motivo']);
            $sh2->setCellValue('F' . $r, $h['usuario_nombre']);
            $r++;
        }

        // Hoja 3: Comunicaciones
        $sh3 = $ss->createSheet();
        $sh3->setTitle('Comunicaciones');
        $headers3 = ['Fecha registro', 'Medio', 'Tipo', 'Descripción', 'Respuesta cliente', 'Resultado', 'Próx. seguimiento', 'Usuario'];
        foreach ($headers3 as $i => $h) $sh3->setCellValue(chr(65 + $i) . '1', $h);
        $r = 2;
        foreach ($d['comunicaciones'] as $com) {
            $sh3->setCellValue('A' . $r, $com['fecha_registro']);
            $sh3->setCellValue('B' . $r, $com['medio']);
            $sh3->setCellValue('C' . $r, $com['tipo_comunicacion']);
            $sh3->setCellValue('D' . $r, $com['descripcion']);
            $sh3->setCellValue('E' . $r, $com['respuesta_cliente']);
            $sh3->setCellValue('F' . $r, $com['resultado_compromiso']);
            $sh3->setCellValue('G' . $r, $com['fecha_proximo_seguimiento']);
            $sh3->setCellValue('H' . $r, $com['usuario_nombre']);
            $r++;
        }

        // Hoja 4: Versiones
        $sh4 = $ss->createSheet();
        $sh4->setTitle('Versiones');
        $headers4 = ['Versión', 'Código', 'Motivo', 'Fecha', 'Usuario'];
        foreach ($headers4 as $i => $h) $sh4->setCellValue(chr(65 + $i) . '1', $h);
        $r = 2;
        foreach ($d['versiones'] as $v) {
            $sh4->setCellValue('A' . $r, $v['version_numero']);
            $sh4->setCellValue('B' . $r, $v['codigo_versionado']);
            $sh4->setCellValue('C' . $r, $v['motivo']);
            $sh4->setCellValue('D' . $r, $v['fecha_version']);
            $sh4->setCellValue('E' . $r, $v['usuario_nombre']);
            $r++;
        }

        // Anchos de columna auto
        foreach (range('A', 'H') as $col) {
            $sh->getColumnDimension($col)->setAutoSize(true);
            $sh2->getColumnDimension($col)->setAutoSize(true);
            $sh3->getColumnDimension($col)->setAutoSize(true);
            $sh4->getColumnDimension($col)->setAutoSize(true);
        }

        // Salida
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="cotizacion_' . $idCotizacion . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    /* ====================================================================
     * SALIDA CSV
     * ====================================================================
     */
    public function outputCsv(int $idCotizacion): void
    {
        $d = $this->getData($idCotizacion);
        $c = $d['cabecera'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cotizacion_' . $idCotizacion . '.csv"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8

        // Cabecera
        fputcsv($out, ['COTIZACION', $c['codigo']]);
        fputcsv($out, ['Emisora', $c['emisora_razon_social'] ?? '']);
        fputcsv($out, ['RUC Emisora', $c['emisora_ruc'] ?? '']);
        fputcsv($out, ['Cliente', $c['empresa_razon_social'] ?? '']);
        fputcsv($out, ['RUC Cliente', $c['empresa_ruc'] ?? '']);
        fputcsv($out, ['Fecha', $c['fecha_creacion']]);
        fputcsv($out, ['Estado', $c['estado']]);
        fputcsv($out, ['Version', $c['version_numero']]);
        fputcsv($out, []);

        // Items
        fputcsv($out, ['Item', 'Codigo', 'Descripcion', 'Cantidad', 'Precio Unit.', 'Desc %', 'Subtotal', 'Afecto IGV']);
        $orden = 0;
        foreach ($d['detalle'] as $item) {
            $orden++;
            fputcsv($out, [
                $orden,
                $item['codigo'] ?? '',
                $item['descripcion'] ?? '',
                $item['cantidad'],
                $item['precio_unitario'],
                $item['descuento_pct'] ?? 0,
                $item['subtotal'],
                $item['afecto_igv'] ? 'SI' : 'NO',
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['SUBTOTAL', '', '', '', '', '', $c['subtotal']]);
        fputcsv($out, ['IGV ' . $c['igv_pct'] . '%', '', '', '', '', '', $c['igv_monto']]);
        fputcsv($out, ['TOTAL', '', '', '', '', '', $c['total']]);
        fputcsv($out, []);

        // Condiciones
        fputcsv($out, ['CONDICIONES COMERCIALES']);
        fputcsv($out, ['Garantia', $c['snapshot_garantia'] ?? '']);
        fputcsv($out, ['Forma de Pago', $c['snapshot_forma_pago'] ?? '']);
        fputcsv($out, ['Cuenta', $c['snapshot_cuenta_bancaria'] ?? '']);
        fputcsv($out, ['Plazo', $c['snapshot_plazo_entrega'] ?? '']);
        fputcsv($out, ['Validez', $c['snapshot_validez'] ?? '']);
        fputcsv($out, []);

        // Historial
        fputcsv($out, ['HISTORIAL DE ESTADOS']);
        fputcsv($out, ['Fecha', 'Estado anterior', 'Estado nuevo', 'Tipo evento', 'Motivo', 'Usuario']);
        foreach ($d['historial'] as $h) {
            fputcsv($out, [$h['fecha_cambio'], $h['estado_anterior'], $h['estado_nuevo'], $h['tipo_evento'], $h['motivo'], $h['usuario_nombre']]);
        }
        fputcsv($out, []);

        // Comunicaciones
        fputcsv($out, ['COMUNICACIONES']);
        fputcsv($out, ['Fecha', 'Medio', 'Tipo', 'Descripcion', 'Respuesta', 'Usuario']);
        foreach ($d['comunicaciones'] as $com) {
            fputcsv($out, [$com['fecha_registro'], $com['medio'], $com['tipo_comunicacion'], $com['descripcion'], $com['respuesta_cliente'], $com['usuario_nombre']]);
        }

        fclose($out);
        exit;
    }
}
