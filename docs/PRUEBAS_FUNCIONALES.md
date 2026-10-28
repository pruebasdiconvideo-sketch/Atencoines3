# Pruebas funcionales obligatorias

## PRUEBA 1 — Código automático por empresa

**Objetivo**: Verificar que cada empresa use su código identificador correcto.

**Precondiciones**:
- Script SQL 02 ejecutado.
- Empresas con `codigo_empresa` asignado (SERV, MANT, DESA).

**Pasos**:
1. Entrar al módulo de Cotizaciones → Nueva cotización.
2. Seleccionar empresa con código `SERV`.
3. Verificar que el banner muestre `SERV-2026-001.1`.
4. Guardar la cotización.
5. Repetir con empresa `MANT` → debe mostrar `MANT-2026-001.1`.

**Resultado esperado**:
- El código se genera automáticamente sin intervención del usuario.
- Cada empresa usa su propio prefijo.

**SQL de verificación**:
```sql
SELECT codigo, id_empresa, fecha_creacion
FROM dbo.cotizacion
WHERE fecha_creacion >= '2026-01-01'
ORDER BY id_cotizacion DESC;
```

---

## PRUEBA 2 — Correlativo anual

**Objetivo**: Verificar que el correlativo aumenta correctamente y se reinicia cada año.

**Pasos**:
1. Crear varias cotizaciones para la misma empresa en el año actual.
2. Verificar que los códigos siguen el patrón `SERV-2026-001.1`, `SERV-2026-002.1`, `SERV-2026-003.1`.
3. Simular cambio de año (modificando `SYSDATETIME()` en una BD de prueba o esperando al 1 de enero).
4. Crear una nueva cotización → debe generar `SERV-2027-001.1`.

**SQL de verificación**:
```sql
SELECT id_empresa, anio, ultimo_correlativo
FROM dbo.cotizacion_correlativo_anual
ORDER BY id_empresa, anio;
```

---

## PRUEBA 3 — Concurrencia

**Objetivo**: Verificar que dos usuarios creando cotizaciones simultáneamente no generen códigos duplicados.

**Pasos**:
1. Abrir dos navegadores (o dos pestañas incógnito) con sesiones distintas.
2. En ambos, ir a Nueva cotización → seleccionar la misma empresa.
3. En ambos, hacer clic en "Guardar" lo más simultáneamente posible.
4. Verificar que se generan códigos distintos (ej: `...001.1` y `...002.1`).

**SQL de verificación**:
```sql
-- Debe devolver 0 filas
SELECT codigo, COUNT(*) AS repeticiones
FROM dbo.cotizacion
GROUP BY codigo
HAVING COUNT(*) > 1;
```

---

## PRUEBA 4 — Seguimiento comercial

**Objetivo**: Verificar el registro y visualización de seguimientos.

**Pasos**:
1. Abrir una cotización existente.
2. Hacer clic en el botón "Seguimiento" (o en el badge del contador).
3. En el modal, registrar 3 seguimientos con distintos medios y resultados:
   - 28/10/2026 — Correo electrónico — Se envió la cotización — próximo: 30/10/2026
   - 30/10/2026 — Llamada telefónica — Cliente solicita más información — próximo: 02/11/2026
   - 02/11/2026 — WhatsApp — Cliente continúa evaluando — próximo: 05/11/2026
4. Verificar que el contador muestre "3".
5. Verificar que el historial completo se mantenga.

**SQL de verificación**:
```sql
SELECT id_cotizacion, COUNT(*) AS total
FROM dbo.cotizacion_comunicacion
GROUP BY id_cotizacion
ORDER BY total DESC;
```

---

## PRUEBA 5 — PDF e impresión sin estado

**Objetivo**: Verificar que el PDF no muestre el estado interno de aprobación.

**Pasos**:
1. Abrir una cotización en estado `APROBADA`.
2. Hacer clic en "Imprimir / PDF v2" (o abrir `/api/v1/cotizaciones_print_v2/{id}`).
3. Generar el PDF (Ctrl+P → Guardar como PDF).
4. Abrir el PDF y verificar que NO aparezca:
   - La palabra "APROBADA"
   - La palabra "RECHAZADA"
   - Cualquier badge de estado.

**Resultado esperado**:
- El PDF muestra: logo, emisora, cliente, condiciones, cuentas bancarias (3), detalle, totales, observaciones, firmas, pie.
- NO muestra: estado interno, badge de color, columna de estado.

---

## PRUEBA 6 — Logo en el PDF

**Objetivo**: Verificar que el logo cargado aparezca correctamente en el PDF.

**Pasos**:
1. Ir a módulo de Cotizaciones → Emisoras → seleccionar emisora → botón "Logo".
2. Subir un logo PNG (máx 2 MB, dimensiones recomendadas 400×200px).
3. Crear una cotización con esa emisora.
4. Generar el PDF v2.
5. Verificar que el logo aparece en el encabezado, sin deformarse.

**Resultado esperado**:
- El logo aparece con `object-fit: contain` (sin deformación).
- Si no hay logo, se muestra "(Sin logo)" en su lugar (sin error).

---

## PRUEBA 7 — 3 cuentas bancarias en el PDF

**Objetivo**: Verificar que las 3 cuentas bancarias aparezcan automáticamente.

**Precondición**: La emisora tiene al menos 3 cuentas activas en `cotizacion_cuenta_bancaria`.

**Pasos**:
1. Crear una cotización con esa emisora.
2. Generar el PDF v2.
3. Verificar que en la sección "Cuentas bancarias" aparezcan las 3 cuentas (banco, número, CCI, moneda).

**Resultado esperado**:
- El PDF muestra las 3 cuentas SIN necesidad de seleccionarlas en un combo.
- Cada cuenta aparece en una fila con: banco, número de cuenta, CCI (si tiene), moneda.

**SQL de verificación**:
```sql
SELECT id_emisora, banco, numero_cuenta, cci, moneda, activo, orden
FROM dbo.cotizacion_cuenta_bancaria
WHERE id_emisora = 1  -- la emisora de la cotización
ORDER BY orden;
```

---

## PRUEBA 8 — Zona Pricing eliminada

**Objetivo**: Verificar que la opción ya no aparezca en la interfaz.

**Pasos**:
1. Ir al formulario de Nueva cotización.
2. Verificar que NO exista el campo "Zona de pricing" en las condiciones comerciales.
3. Ir al menú lateral.
4. Verificar que NO exista un enlace "Zona Pricing" o "Zonas".

**Resultado esperado**:
- El formulario no muestra el combo de Zona Pricing.
- El menú no tiene la opción Zona Pricing.
- Las cotizaciones existentes con `id_zona_pricing` siguen funcionando (no se rompen).
- La tabla `cotizacion_zona_pricing` sigue existiendo (no se eliminó).

---

## PRUEBA 9 — Buscador de rubros

**Objetivo**: Verificar el funcionamiento del buscador.

**Pasos**:
1. Ir al módulo de Rubros.
2. Escribir en el input de búsqueda: "Tec" (por ejemplo).
3. Verificar que solo aparecen los rubros cuyo nombre contiene "Tec".
4. Hacer clic en "Limpiar".
5. Verificar que aparecen todos los rubros nuevamente.
6. Buscar un rubro inexistente (ej: "XYZ123") → debe mostrar "Sin resultados".
7. Verificar que el botón "Nuevo Rubro" sigue funcionando.
8. Verificar que editar y eliminar rubros sigue funcionando.

**Resultado esperado**:
- La búsqueda filtra en tiempo real (con debounce de 300 ms).
- El botón "Limpiar" restaura el listado completo.
- Mensaje claro cuando no hay resultados.
- Las operaciones CRUD siguen funcionando normalmente.

---

## PRUEBA 10 — Regresión

**Objetivo**: Verificar que las funciones existentes siguen funcionando.

**Pasos**:
1. **Crear** una cotización nueva → debe guardarse correctamente.
2. **Editar** una cotización existente → debe guardar los cambios.
3. **Eliminar** (soft-delete) una cotización → debe marcarse como eliminada.
4. **Exportar** a Excel → debe generar el XLSX.
5. **Exportar** a CSV → debe generar el CSV.
6. **Cambiar estado** de una cotización (Borrador → Pendiente de envío → Enviada → Aprobada).
7. **Crear versión** nueva de una cotización aprobada → debe generar `.2`, `.3`, etc.
8. **Subir documento** adjunto a una cotización → debe quedar asociado.
9. **Listar tickets** → debe seguir funcionando.
10. **Crear ticket** → debe seguir funcionando.
11. **Login / Logout** → debe seguir funcionando.

**Resultado esperado**: Todas las funciones existentes operan con normalidad.

---

## RESUMEN DE VERIFICACIÓN

| # | Prueba | Estado |
|---|--------|--------|
| 1 | Código automático por empresa | ☐ |
| 2 | Correlativo anual | ☐ |
| 3 | Concurrencia | ☐ |
| 4 | Seguimiento comercial | ☐ |
| 5 | PDF sin estado | ☐ |
| 6 | Logo en PDF | ☐ |
| 7 | 3 cuentas bancarias | ☐ |
| 8 | Zona Pricing eliminada | ☐ |
| 9 | Buscador de rubros | ☐ |
| 10 | Regresión | ☐ |

Marcar con ☑ cuando cada prueba pase correctamente.
