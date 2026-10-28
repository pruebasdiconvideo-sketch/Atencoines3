# DIAGNÓSTICO DEL 502 BAD GATEWAY

El 502 persiste aunque la BD esté bien. Eso significa que **el error no
viene de la BD sino de PHP/IIS**. Pero como `api/index.php` tiene
`display_errors = 0`, no vemos el error real — IIS lo captura y devuelve
502.

Este paquete trae 3 scripts para **ver el error real**:

---

## Scripts incluidos

```
db-diagnostico/
├── diagnostico_completo.php   ← diagnóstico paso a paso (texto)
├── login_debug.php            ← simula el login con todo visible
└── phpinfo_minimal.php        ← info PHP + test conexiones (HTML)
```

**Para descargar:**
- Carpeta: `/home/z/my-project/download/db-diagnostico/`
- ZIP: `/home/z/my-project/download/db-diagnostico.zip`

---

## CÓMO USAR

### PASO 1 — Sube los 3 scripts a la raíz del proyecto

Junto a `index.php`, `login.php`, etc. (NO dentro de subcarpetas).

### PASO 2 — Abre `phpinfo_minimal.php` primero

```
http://localhost:8036/phpinfo_minimal.php
```

Esto te muestra:
1. **Versión PHP y SAPI** (debe decir `cgi-fcgi` o `FastCGI` para IIS).
2. **Extensiones instaladas** — `pdo_sqlsrv` y `sqlsrv` deben decir **OK**.
   Si dicen **FALTA**, ese es el problema.
3. **Drivers PDO** disponibles — debe listar `sqlsrv`.
4. **Configuración de sesión** — `session.save_path` debe ser escribible.
5. **Permisos de archivos** — todos los archivos críticos deben decir
   "Legible: SÍ".
6. **Test conexión BD** — 3 variantes de DSN, al menos una debe dar OK.
7. **Errores PHP recientes** — muestra los últimos 15 errores de los
   logs típicos (`C:\inetpub\logs\php-errors.log`, etc.).

### PASO 3 — Abre `login_debug.php`

```
http://localhost:8036/login_debug.php
```

Este script ejecuta el login **paso a paso** con TODOS los errores
visibles. Pasa por:

1. Cargar includes
2. Conectar a BD
3. Buscar usuario en la BD
4. Verificar password con `password_verify()`
5. Verificar `estado` y `bloqueado`
6. Iniciar sesión PHP
7. Obtener rol
8. Registrar auditoría

Si algo falla, verás el mensaje EXACTO del error. Los escenarios típicos:

- **Paso 2 falla** → problema de conexión BD (ver punto anterior).
- **Paso 3 falla** → el usuario `soporte@dicon.com.pe` no existe con
  `activo=1` en la BD.
- **Paso 4 falla** → el password no coincide con el hash guardado.
  Para resetear, ejecuta en SSMS:
  ```sql
  UPDATE persona
  SET contrasena = '<nuevo-hash>'
  WHERE correo = 'soporte@dicon.com.pe';
  ```
  El hash lo puedes generar abriendo
  `http://localhost:8036/login_debug.php?pass=NUEVO_PASSWORD` y copiando
  el hash del PASO 4. Pero más fácil: en SSMS ejecuta:
  ```sql
  -- Para generar un hash nuevo, en PHP:
  -- echo password_hash('tu-password', PASSWORD_DEFAULT);
  ```
- **Paso 6 falla** → `session.save_path` no es escribible por IIS.

### PASO 4 — Abre `diagnostico_completo.php`

```
http://localhost:8036/diagnostico_completo.php
```

Igual que `login_debug.php` pero más extenso. Verifica:
- Archivos del proyecto presentes y legibles
- Información de sesión PHP
- `memory_limit`, `max_execution_time`
- Log de errores de IIS
- Sub-códigos 502 (502.2, 502.3, 502.5, 502.7)

---

## POSIBLES CAUSAS DEL 502 (por orden de probabilidad)

### 1. `pdo_sqlsrv` no instalado en PHP del servidor

Si en `phpinfo_minimal.php` ves `pdo_sqlsrv: FALTA`:
- Descarga: https://github.com/microsoft/msphpsql/releases
- Para PHP 8.x → `pdo_sqlsrv-5.12.x-8.x-nts-x64.zip`
- Copia `php_pdo_sqlsrv.dll` y `php_sqlsrv.dll` a la carpeta `ext/` de PHP
- En `php.ini` agrega:
  ```ini
  extension=pdo_sqlsrv
  extension=sqlsrv
  ```
- Verifica dependencias: el driver requiere **MSODBC (ODBC Driver 17/18 for SQL Server)**
  - https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server
- Reinicia IIS: `iisreset`

### 2. App Pool de IIS detenido o mal configurado

En IIS Manager:
1. **Application Pools** → encuentra el de tu app
2. Verifica que está **Started**
3. Click derecho → **Advanced Settings**:
   - `.NET CLR version`: **No Managed Code**
   - `Identity`: ApplicationPoolIdentity (o NetworkService)
   - `Load User Profile`: **True**
   - `Idle Time-out (minutes)`: **0** (evita que el pool se duerma)
4. **Recycle** → reinicia el pool

### 3. PHP crashed (502.5)

Mirar Event Viewer:
1. Win+R → `eventvwr`
2. **Windows Logs** → **Application**
3. Filtrar por origen: **PHP** o **IIS W3SVC**
4. Buscar eventos con errores recientes

Causas típicas de crash:
- Extensión PHP incompatible con la versión
- `php.ini` con `extension=php_<algo>.dll` de versión incorrecta
- Memoria insuficiente
- ODBC Driver SQL Server no instalado

### 4. FastCGI timeout (502.7)

En `php.ini`:
```ini
max_execution_time = 300
```

En IIS Manager → Handler Mappings → PHP:
- Click → **Edit**
- **Request Restrictions** → **Mapping** → verificar path
- En `web.config` del sitio:
  ```xml
  <system.webServer>
    <handlers>
      <add name="PHP_via_FastCGI"
           path="*.php"
           verb="*"
           modules="FastCgiModule"
           scriptProcessor="C:\PHP\php-cgi.exe"
           resourceType="File" />
    </handlers>
  </system.webServer>
  ```

### 5. Permisos NTFS

El usuario del App Pool (usualmente `IIS AppPool\NombreDelPool` o
`IUSR`) necesita:
- **Read** en toda la carpeta del proyecto
- **Modify** en `storage/` y subcarpetas (logs, sessions, uploads)

PowerShell admin:
```powershell
$path = "C:\inetpub\wwwroot\Atenciones"
$user  = "IIS AppPool\DefaultAppPool"  # ajustar al nombre real del pool
icacls $path /grant "${user}:(OI)(CI)R" /T
icacls "$path\storage" /grant "${user}:(OI)(CI)M" /T
```

### 6. URL Rewrite Module mal configurado

El `web.config` del proyecto debe tener:
```xml
<system.webServer>
  <rewrite>
    <rules>
      <rule name="API Router" stopProcessing="true">
        <match url="^api/v1/(.*)" />
        <action type="Rewrite" url="api/index.php" appendQueryString="true" />
      </rule>
    </rules>
  </rewrite>
</system.webServer>
```

Si URL Rewrite Module no está instalado, descárgalo:
https://www.iis.net/downloads/microsoft/url-rewrite

### 7. Sub-códigos 502 específicos

Mira `C:\inetpub\logs\LogFiles\W3SVC1\u_ex*.log` y busca tu request 502.
El campo `sc-substatus` dirá:

| Sub-status | Causa | Solución |
|---|---|---|
| 502.2 | PHP error | Ver `phpinfo_minimal.php` errores |
| 502.3 | FastCGI conx error | Reinstalar PHP / verificar FastCGI config |
| 502.5 | PHP process crash | Event Viewer, conflictos de extensiones |
| 502.7 | FastCGI timeout | Subir `max_execution_time` |

---

## DESPUÉS DE ENCONTRAR EL ERROR

1. **Borra los 3 scripts** (`diagnostico_completo.php`, `login_debug.php`,
   `phpinfo_minimal.php`) — exponen credenciales.
2. Si el problema era de configuración PHP, reinicia IIS:
   ```cmd
   iisreset
   ```
3. Prueba login normal en `http://localhost:8036/login.php`.

---

## QUÉ ENVIARME SI SIGUE FALLANDO

Si después de ejecutar los 3 scripts no logras identificar el problema,
copia y pégame:

1. **Screenshot completo** de `phpinfo_minimal.php` (o el HTML si lo
   puedes guardar).
2. **Output completo** de `login_debug.php` (texto plano).
3. **Últimas 20 líneas** del log de IIS donde aparece el 502:
   ```
   C:\inetpub\logs\LogFiles\W3SVC1\u_ex<fecha>.log
   ```
4. **Event Viewer** → Windows Logs → Application → últimos 5 errores
   con origen "PHP" o "IIS W3SVC".
5. **Resultado del comando** (en cmd admin en servidor APP):
   ```
   %SystemRoot%\system32\inetsrv\appcmd list apppools /state:Started
   ```
