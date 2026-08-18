# Instalación nativa en cPanel Webmail

Este paquete registra AxerOK Mail como cliente webmail del servidor. Se instala una vez
y queda disponible para las casillas de todos los dominios alojados en ese cPanel.

## Requisitos

- Acceso SSH como `root`.
- cPanel & WHM con `/usr/local/cpanel/3rdparty/bin/php` 8.1 o posterior.
- Extensiones `openssl`, `pdo_sqlite` y `sqlite3` en ese PHP.
- IMAP 993 y SMTP 465 con TLS válido para el hostname del servidor.
- Copia de seguridad reciente antes de instalar en producción.

No necesita MySQL, Composer, Node.js, Docker ni cambios de permisos en `/home`.

## Instalar

El archivo `.tar.gz` se descomprime directamente con `install.sh`, `uninstall.sh` y
`app/` en el directorio actual; no crea una carpeta envolvente adicional.

```bash
tar -xzf axerok-mail-0.4.0-preview17-webmail-cpanel.tar.gz
bash install.sh
```

El instalador valida PHP y SQLite, instala el código en
`/usr/local/cpanel/base/3rdparty/axerok-mail`, registra el cliente mediante la API de
cPanel y conserva una copia de una instalación anterior en `/var/cpanel/backups/`.

Después, entrar por `https://HOSTNAME:2096`, elegir **AxerOK Mail** y probar primero con
una casilla no productiva. No usar `email.dominio` para esta prueba: esa URL corresponde
al modo subdominio y no hereda el usuario autenticado por cPanel Webmail.

## Datos y aislamiento

El código es global, pero cada propietario cPanel guarda sus datos privados en:

```text
/home/USUARIO/.axerok-mail/
```

cPanel ejecuta el cliente con el propietario de la cuenta. Para `jm@pronexo.com`, la
importación deriva exclusivamente:

```text
/home/pronexo/etc/pronexo.com/jm.rcube.db
```

La ruta no llega desde el navegador, debe pertenecer al usuario efectivo y se abre sólo
para lectura. `jm@pronexo.com` no puede elegir ni importar `info@pronexo.com`.

## Actualizar

Descomprimir la nueva entrega en un directorio vacío y volver a ejecutar `bash
install.sh`. No copiar manualmente archivos sobre la instalación global.

## Desinstalar

```bash
bash uninstall.sh
```

Elimina el registro global y el código compartido. Conserva deliberadamente los datos
privados `/home/USUARIO/.axerok-mail`; su eliminación requiere una decisión separada y
una copia de seguridad.

## Prueba mínima

1. Abrir AxerOK Mail desde cPanel Webmail.
2. Confirmar carpetas, mensajes, estados leído/no leído y destacados.
3. En Contactos, importar los contactos personales de esa misma casilla.
4. Enviar con adjuntos y comprobar Enviados en Roundcube.
5. Cerrar sesión desde Webmail y confirmar que la ruta ya no abre el buzón.
