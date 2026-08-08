# AxerOK Mail

Webmail central para cuentas alojadas en cPanel/WHM. Una única instalación atiende
todos los dominios de correo del mismo servidor. Usa el mismo buzón que Roundcube:
mensajes y carpetas se leen por IMAP y los envíos se realizan por SMTP.

## Requisitos

- cPanel con PHP 8.1 o posterior.
- Extensiones PHP: OpenSSL, PDO y PDO MySQL. SQLite3 habilita la importación directa
  desde Roundcube. `mbstring`, `iconv` y `fileinfo`
  mejoran compatibilidad, pero la aplicación incluye alternativas cuando no están disponibles.
- Una base MySQL creada desde cPanel para contactos.
- IMAP y SMTP TLS accesibles desde el servidor web.
- Certificado TLS válido para el hostname de correo.

No requiere la extensión PHP `imap`, Composer ni Node.js en el servidor. El modo
subdominio no requiere root; la integración nativa sí lo requiere durante instalación.
React/TypeScript y las dependencias PHP ya vienen compilados dentro del paquete.

## Dos modos de instalación

La instalación recomendada es la integración nativa de **cPanel Webmail**. Se instala
una sola vez como `root`, aparece junto a Roundcube y cPanel la ejecuta bajo el usuario
Unix propietario de cada cuenta. Eso permite leer, sin cambiar permisos, únicamente la
SQLite de Roundcube perteneciente a la casilla autenticada. Ver
`INSTALL-WEBMAIL-CPANEL.md`.

El modo de subdominio descrito a continuación sigue disponible, pero su proceso PHP sólo
puede leer archivos del usuario cPanel que posee ese subdominio. Por aislamiento del
sistema no puede importar automáticamente las SQLite `0600` de otras cuentas cPanel;
para ellas se usa vCard.

## Instalación como subdominio

1. Elegir una cuenta cPanel para alojar la instalación central.
2. Crear un dominio neutral, por ejemplo `webmail.pronexo.com`.
3. Subir el proyecto y establecer el document root en el directorio `public` del paquete.
4. Crear una base MySQL y un usuario desde cPanel, con todos los permisos sobre esa base.
5. Copiar `config/config.example.php` a `config/config.php`. Usar el hostname del servidor cPanel —por ejemplo `ns41.pronexo.com`— para IMAP y SMTP.
6. Forzar HTTPS. No habilitar `allow_self_signed` en producción.
7. Cada usuario ingresa con su dirección completa, aunque su dominio sea distinto al de la aplicación.
8. Abrir la URL raíz del subdominio. React se sirve directamente desde `/`; no existe
   una segunda interfaz en `/react/`.

Para generar `app.key` desde Terminal de cPanel:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### Actualización desde preview11 o anterior

Hacer una copia de seguridad y descomprimir el paquete sobre la instalación sin
reemplazar `config/config.php`. Como ZIP no elimina archivos remotos, después de
confirmar que la raíz abre correctamente se pueden borrar exclusivamente estos
restos de la interfaz anterior:

```text
public/react/
public/index.php
public/assets/app.js
public/assets/app.css
public/assets/favicon.svg
views/
```

No borrar `public/api.php`, `src/`, `vendor/`, `config/` ni `storage/`.

## Compatibilidad con Roundcube

- Carpetas, subcarpetas, mensajes, estados y destacados provienen de IMAP.
- Los mensajes enviados se copian a la carpeta marcada por IMAP como `Sent`.
- Los contactos se importan/exportan en vCard 3.0 (`.vcf`). También puede leerse,
  sin escribirla, la SQLite de Roundcube correspondiente a la misma cuenta cPanel:
  `/home/USUARIO/etc/DOMINIO/PARTE_LOCAL.rcube.db`.
- Se importan contactos personales y CardDAV. Los destinatarios recopilados y la
  identidad son opcionales. La base se abre en modo `READONLY` y `query_only`.
- El aislamiento de cPanel se conserva: cada sesión deriva la base exclusivamente
  desde su propia dirección activa y los permisos `0600` impiden leer otra cuenta.
  La API no acepta rutas elegidas por el navegador. Una migración autorizada entre
  casillas distintas debe hacerse explícitamente mediante un archivo vCard.
- No se leen directamente archivos Maildir.
- Todos los dominios deben pertenecer al mismo servidor IMAP/SMTP configurado.
- Para atender dominios distribuidos entre varios servidores hará falta agregar un mapa seguro dominio → servidor.
- CardDAV queda reservado para una fase posterior.

## Seguridad

- Las contraseñas de hasta ocho cuentas abiertas no se guardan en MySQL.
- En la sesión quedan cifradas con AES-256-GCM y una clave propia de la instalación.
- Todas las acciones POST usan token CSRF.
- El HTML de los correos se muestra aislado, sin scripts ni formularios.
- La raíz pública debe ser exclusivamente el directorio `public`.
- Consultar `SECURITY.md` antes de cualquier instalación pública.

## Estado

Implementado en React + TypeScript: login IMAP, administración de carpetas, etiquetas,
bandeja, búsqueda IMAP rápida y búsqueda avanzada por carpeta, lectura segura,
imágenes remotas con autorización, descarga de adjuntos,
selección y eliminación múltiple, vaciado seguro de Spam/Papelera, archivo, movimiento,
paginación, responder, responder a todos, reenviar, papelera, redacción HTML por SMTP,
CC/CCO, autocompletado desde Contactos, plantillas reutilizables, adjuntos acumulables por selector o arrastrar y soltar, copia en Enviados,
acuse de lectura, firmas, identidad, borradores automáticos, densidad y tipos de
bandeja, cuentas múltiples por pestaña y contactos con importación vCard o lectura
opcional de la SQLite de Roundcube. PHP 8.3 continúa como API segura para cPanel.

Rendimiento: la API libera el bloqueo de sesión antes de acceder a IMAP, informa tiempos
con `Server-Timing`, lista por rangos de secuencia sin transferir todos los UID y descarga
solamente la parte MIME necesaria al abrir un mensaje. No realiza precargas IMAP en
segundo plano ni guarda cuerpos en la caché del servidor.

Pendiente antes de producción: validar en el cPanel objetivo y completar las pruebas
dinámicas indicadas en `SECURITY.md`. El paquete actual es candidato de prueba, no una
versión aprobada para producción.
