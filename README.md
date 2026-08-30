# OpenBooking WP — edición open source

Este repositorio contiene exclusivamente la edición comunitaria de OpenBooking WP.
El código se distribuye bajo GPL-2.0-or-later y no incluye el plugin comercial,
código de licencias, activadores, telemetría remota ni artefactos internos de QA.

## Contenido

- `openbooking-wp/`: código fuente instalable del plugin.
- `scripts/verify-release.ps1`: verifica la separación de código comercial,
  secretos accidentales, extensiones ejecutables inesperadas y sintaxis PHP
  cuando PHP está disponible.
- `scripts/build-release.ps1`: valida y crea el ZIP reproducible.
- `SECURITY.md`: modelo de seguridad y procedimiento de reporte.

## Instalación

1. Comprime la carpeta `openbooking-wp` o usa `scripts/build-release.ps1`.
2. En WordPress, abre **Plugins → Añadir plugin → Subir plugin**.
3. Selecciona `openbooking-wp-opensource-1.2.4.zip` y actívalo.

Las conexiones con Stripe, MercadoPago, Webpay, Twilio o Meta están desactivadas
hasta que un administrador las configure explícitamente con sus propias
credenciales. El plugin no incluye credenciales ni servicios remotos propios.

## Alcance de esta distribución

Se publica mediante lista blanca. Incluye únicamente el punto de entrada,
desinstalador, licencia, documentación de WordPress y los directorios de runtime:
`assets`, `blocks`, `languages`, `resources`, `src` y `templates`.

Quedan fuera el plugin comercial, dependencias de desarrollo, suites y datos de
prueba, reportes, documentación interna, builds previos y directorios de control
de versiones.

## Licencia

GPL-2.0-or-later. Consulta `openbooking-wp/LICENSE`.
