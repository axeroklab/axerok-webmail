# Seguridad de AxerOK Mail

## Modelo de amenaza

La aplicación es un punto de autenticación público para todas las cuentas de correo del
servidor. Se asume internet hostil, correos MIME malformados, adjuntos no confiables e
intentos automatizados de contraseña.

## Controles implementados

- IMAP y SMTP únicamente con validación TLS; certificados autofirmados deshabilitados.
- Contraseñas de hasta ocho casillas cifradas en la sesión con AES-256-GCM y nunca
  persistidas en MySQL. Cada pestaña selecciona explícitamente su cuenta activa.
- En modo subdominio, sesiones almacenadas en `storage/sessions` con directorio `0700`.
  En modo cPanel nativo, sesiones, base local y clave se guardan por propietario en
  `/home/USUARIO/.axerok-mail` (`0700`), nunca en el árbol público compartido.
- La sesión de correo es persistente y renovable hasta el límite configurado en
  `app.session_lifetime`; el identificador se rota cada 24 horas y `Cerrar sesión`
  elimina la cookie y los datos de sesión del servidor.
- Regeneración de ID al autenticar, cookie `Secure`, `HttpOnly`, `SameSite=Lax` y modo
  estricto de sesiones.
- No hay expiración corta por inactividad: por decisión de producto la sesión continúa
  hasta que el usuario cierre sesión o alcance `app.session_lifetime` (un año por
  defecto). Esto aumenta el impacto del robo de una cookie; exige HTTPS, equipos
  confiables y cierre de sesión explícito en dispositivos compartidos.
- CSRF en todas las operaciones que modifican estado, incluido logout.
- Límite de login por cuenta y por IP, sin revelar la causa exacta del fallo IMAP.
- Consultas SQL preparadas y aislamiento de contactos por dirección completa del dueño.
- La importación desde Roundcube deriva una única SQLite desde la dirección autenticada,
  verifica que esté dentro de `HOME/etc`, pertenezca al usuario PHP actual y la abre con
  `SQLITE3_OPEN_READONLY` más `PRAGMA query_only`. Nunca acepta una ruta del navegador ni
  permite importar la libreta de otra casilla.
- La aplicación nativa recibe usuario y contraseña desde el entorno autenticado de
  cPanel Webmail; nunca los incorpora al HTML ni al JavaScript. El directorio global
  `/usr/local/cpanel/base/3rdparty/axerok-mail` contiene sólo código compartido y no
  almacena credenciales de usuarios.
- Límite de 25 MiB por mensaje, 12 niveles MIME, 200 partes MIME, 10 adjuntos por envío,
  10 MiB por archivo y 20 MiB totales.
- Correos HTML en iframe `sandbox`, con CSP propia que bloquea scripts, formularios,
  objetos, imágenes remotas y rastreadores.
- HTML redactado limitado en el servidor a etiquetas de formato sin atributos. `CCO`
  sólo se usa en el sobre SMTP y nunca se escribe en las cabeceras del mensaje.
- Búsquedas IMAP limitadas a 200 bytes y sin caracteres de control.
- Descargas con `Content-Disposition: attachment` y `nosniff`.
- HTTPS requerido, HSTS, CSP, políticas de origen, permisos y caché privada deshabilitada.
- Directorios privados bloqueados mediante `.htaccess` y fuera del document root lógico.

## Rendimiento

La bandeja obtiene hasta 40 encabezados mediante un único `FETCH` por rango de secuencia.
Las búsquedas usan `UID SEARCH` y luego `UID FETCH`. No realiza una
consulta IMAP por cada fila. Mensajes completos y adjuntos se descargan sólo al abrirlos.
La caché breve en `storage/api-cache` contiene únicamente carpetas y metadatos de
listados, usa nombres HMAC y archivos `0600`; nunca almacena cuerpos ni contraseñas.

## Riesgos residuales antes de producción

- El cliente IMAP y el parser MIME son propios. Aunque tienen límites y pruebas, necesitan
  validación con Dovecot real y una colección amplia de correos legítimos/malformados.
- El rate limit local no sustituye Fail2ban, WAF ni rate limiting en Apache/Cloudflare.
- Falta prueba dinámica contra MySQL, IMAP y SMTP del servidor objetivo.
- Falta auditoría independiente; esta revisión no equivale a una certificación ni a un
  pentest externo.
- El servidor cPanel 110/CentOS 7 está cerca del fin de soporte extendido y debe migrarse.

## Proceso de reporte

No publicar vulnerabilidades junto con credenciales, mensajes reales o configuraciones.
Registrar versión, pasos mínimos de reproducción y logs previamente anonimizados.
