# Despliegue de AxerOK Mail (estado real en producción)

> Documento operativo. Registra **dónde está instalado hoy**, los dominios y los
> dos modelos de deploy. Pensado para que cualquier sesión/persona que retome el
> proyecto sepa el estado sin tener que investigarlo de cero.
> Última verificación: **2026-09-02**.

## Servidor

- **ns41** — `ns41.pronexo.com`, IP `173.212.199.69`. Contabo, **cPanel/WHM 11.110.0.142**.
- Acceso SSH: **`ssh ns41`** (alias en el `~/.ssh/config` del operador; puerto no estándar + llave dedicada).

## Cómo está desplegado HOY (modelo A: app en subdominio)

El webmail corre como **aplicación PHP standalone en un subdominio por cuenta cPanel**
(NO como app de webmail integrada de cPanel). Cada cuenta tiene su propia copia.

| Tenant | Dominio | Docroot | PHP |
|--------|---------|---------|-----|
| `pronexo` | **email.pronexo.com** | `/home/pronexo/public_html/email.pronexo.com` (docroot público: `.../public`) | ea-php83 |
| `axr` | **email.axr.ar** | `/home/axr/public_html/email.axr.ar` (docroot público: `.../public`) | ea-php83 |

- El layout desplegado = el layout del repo: `composer.json`, `public/`, `src/`,
  `config/`, `storage/`, `vendor/`, `tests/`.
- **`vendor/`** se genera con `composer install` en el server (está en `.gitignore`).
- El **frontend** va **pre-buildeado** dentro de `public/assets/` (Vite). El repo
  trackea el bundle vigente; `public/index.html` referencia el bundle actual.
- Despliegues anteriores quedan respaldados como `email.<dominio>.old-YYYYMMDD-HHMMSS/`
  al lado del docroot.

### Datos / runtime por cuenta (NO borrar — es correo en vivo)

Cada cuenta guarda su estado en **`~/.axerok-mail/`** (p.ej. `/home/pronexo/.axerok-mail`,
`/home/axr/.axerok-mail`):

- `axerok.sqlite` — base del webmail (etiquetas, cache, etc.). **Datos reales.**
- `app.key` — **secreto** de la instalación (no está en el repo).
- `api-cache/`, `login-attempts/`, `send-idempotency/`, `sessions/`.

> ⚠️ Estas carpetas son estado vivo del correo del cliente. Un deploy actualiza el
> **código** en el docroot, nunca toca `~/.axerok-mail`.

## Modelo B (alternativo, NO usado hoy): paquete webmail-cPanel

Los zips en `release/axerok-mail-0.4.0-previewNN-webmail-cpanel.zip` son el
**otro** modelo de instalación: se registran como app de webmail dentro de cPanel.

- `install.sh` (como root) instala en **`/usr/local/cpanel/base/3rdparty/axerok-mail`**.
- Registra el webmail en **`/var/cpanel/webmail/webmail_axerok_mail.yaml`**
  (vía `packaging/cpanel/webmail-registration.pl`).
- Backupea la instalación previa en `/var/cpanel/backups/axerok-mail-<timestamp>`.
- Requiere PHP 8.1+ del cPanel interno (`/usr/local/cpanel/3rdparty/bin/php`) con
  OpenSSL, PDO SQLite y SQLite3.

> A **2026-09-02** este modelo NO está instalado en ns41 (`/usr/local/cpanel/base/3rdparty/axerok-mail`
> no existe). Producción usa el **modelo A** (subdominios). Elegir un modelo por
> servidor; no mezclar sin decidirlo.

## Versiones / artefactos

- Repo: `axeroklab/axerok-webmail` (rama `main`). El working tree/HEAD reflejan el
  último build (ver `release/`).
- Releases construidos localmente en **`release/`** (`preview25/26/27...`). Son el
  **paquete del modelo B**; para el modelo A se despliega el árbol del repo directo.
- **Estado 2026-09-02:** subdominios en producción corrían **preview23**; se
  actualizan a **preview27** (modelo A).

## Checklist de deploy (modelo A, subdominio)

1. `composer install` presente / actualizado en el server (o incluido en el sync).
2. Frontend buildeado en `public/assets/` (ya versionado en el repo).
3. Backup del docroot actual → `email.<dominio>.old-<timestamp>/`.
4. Sincronizar el árbol del repo al docroot **sin pisar** `config/config.php`,
   `storage/` ni `~/.axerok-mail`.
5. Verificar `https://email.<dominio>/` (login + listado de correo).
