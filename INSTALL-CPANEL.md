# Instalación reproducible en cPanel

Este documento describe el modo **subdominio independiente**. Para instalar AxerOK Mail
como cliente nativo seleccionable dentro de cPanel Webmail y habilitar la lectura segura
de la libreta propia de cada cuenta, usar `INSTALL-WEBMAIL-CPANEL.md`.

## 1. Preparar la cuenta

En cPanel, no en WHM:

1. Elegir una sola cuenta cPanel para alojar el webmail central.
2. Crear una base de datos MySQL.
3. Crear un usuario MySQL con contraseña aleatoria.
4. Asociar el usuario a la base con **ALL PRIVILEGES**.
5. Crear un subdominio neutral, por ejemplo `webmail.pronexo.com`.
6. Seleccionar **PHP 8.3** para ese subdominio.

No hay que crear una instalación por cada dominio. Todas las cuentas de los 50 dominios
pueden iniciar sesión aquí si están alojadas en el mismo servidor de correo.

## 2. Subir AxerOK Mail

Si cPanel permite elegir rutas fuera de `public_html`, se puede usar:

```text
/home/USUARIO/axerok-mail/
```

El document root del subdominio debe apuntar a:

```text
/home/USUARIO/axerok-mail/public
```

Si cPanel obliga a trabajar dentro de `public_html`, descomprimir el paquete en:

```text
/home/USUARIO/public_html/webmail.pronexo.com/
```

Y al crear el dominio escribir como document root:

```text
/public_html/webmail.pronexo.com/public
```

En la interfaz mostrará `/public_html/` como prefijo fijo; en el campo editable se
debe escribir `webmail.pronexo.com/public`. El paquete incluye reglas `.htaccess` que
bloquean el acceso web a `config`, `src` y `storage`.

## 3. Configurar

Copiar:

```text
config/config.example.php -> config/config.php
```

Editar únicamente `config/config.php`:

- `app.base_url`: URL HTTPS completa del subdominio.
- `app.key`: 64 caracteres hexadecimales aleatorios.
- `mail.imap_host`: hostname SSL central del servidor, en este caso `ns41.pronexo.com`.
- `mail.smtp_host`: normalmente el mismo hostname.
- IMAP: puerto `993`, cifrado `ssl`.
- SMTP: puerto `465`, cifrado `ssl`; usar `587` + `tls` sólo si lo exige el servidor.
- Base MySQL: nombre, usuario y contraseña creados en el paso 1.

Generar la clave desde Terminal de cPanel:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Permisos recomendados:

```text
directorios: 755
archivos PHP: 644
config/config.php: 600
```

No habilitar `allow_self_signed` en producción.

## 4. Extensiones PHP

Verificar en **Select PHP Version** o **MultiPHP INI Editor**:

- openssl
- PDO
- pdo_mysql
- mbstring
- iconv
- fileinfo
- sqlite3 (para importar directamente la libreta propia de Roundcube)

No hace falta habilitar `imap` porque AxerOK Mail implementa el cliente IMAP con
conexiones TLS estándar.

## 5. SSL

Ejecutar AutoSSL para el subdominio y confirmar que abre sin advertencias. No probar
el login mientras el certificado sea inválido.

## 6. Primera prueba

La interfaz React se abre directamente en:

```text
https://webmail.pronexo.com/
```

No existe una interfaz PHP visual separada ni una ruta `/react/`. PHP continúa como
API interna para IMAP, SMTP, sesión y seguridad. Node.js no se ejecuta ni se instala
en cPanel.

Ingresar con una cuenta de correo de prueba existente en cPanel usando la dirección
completa, por ejemplo `prueba@cualquiera-de-los-50-dominios.com`, y la misma contraseña
que Roundcube. El dominio del usuario no necesita coincidir con el dominio del webmail.

Comprobar en este orden:

1. Aparecen todas las carpetas y subcarpetas de Roundcube.
2. Se abre un mensaje de texto y otro HTML.
3. Se descarga un adjunto.
4. Se envía un mensaje a una dirección externa.
5. El mensaje aparece en Enviados tanto en AxerOK Mail como en Roundcube.
6. Se importa la libreta propia con “Importar desde Roundcube”; confirmar que la
   pantalla no permite elegir otra casilla ni una ruta. Probar también un `.vcf`.
7. Se cierra sesión y no se puede volver atrás al buzón.
8. Se crea, renombra y elimina una carpeta de prueba, confirmando los cambios en Roundcube.
9. Se crea una etiqueta y se aplica/quita sobre un mensaje seleccionado.
10. Se agrega una segunda cuenta, se cambia entre ambas y se abre cada una en una
    pestaña distinta sin que una pestaña cambie la cuenta de la otra.

La importación directa del paso 6 sólo estará disponible si la SQLite pertenece al mismo
usuario Unix que ejecuta PHP. Es deliberado: no se deben relajar los permisos `0600` de
Roundcube. Para abarcar distintas cuentas cPanel usar el modo nativo o importar vCard.

No empezar con una cuenta productiva: primero completar todo el checklist con una
cuenta creada exclusivamente para pruebas.
