# Informe de separación y seguridad

Fecha: 2026-08-29  
Proyecto de origen: copia de trabajo privada  
Edición generada: OpenBooking WP open source 1.2.4

## Resultado

La distribución se construyó desde una lista blanca del runtime de
`openbooking-wp`. No se copió `openbooking-pro` ni ningún artefacto del repositorio
padre. La copia de trabajo original no fue modificada.

Incluido: `assets`, `blocks`, `languages`, `resources`, `src`, `templates`, punto
de entrada, desinstalador, licencia GPL y `readme.txt`.

Excluido: edición Pro, builds anteriores, QA, datos y scripts de pruebas,
documentación interna, dependencias de desarrollo, `vendor`, `node_modules`,
reportes, configuración privada y metadatos Git.

## Cambios respecto del Core de origen

- `readme.txt`: `Stable tag` sincronizado con 1.2.4 y eliminada una referencia
  comercial a SaaS.
- `templates/admin/settings.php`: texto de WhatsApp neutral, sin referencia a una
  capa Premium/SaaS.

Los otros archivos incluidos son copias byte a byte del Core de origen.

## Verificaciones ejecutadas

- 339 archivos de distribución inspeccionados por el verificador local.
- 0 rutas o identificadores comerciales bloqueados.
- 0 patrones comunes de secretos o claves privadas detectados.
- 0 ejecutables o scripts de sistema dentro del plugin.
- Versiones consistentes: encabezado, `OBWP_VERSION` y `Stable tag` = 1.2.4.
- 14 archivos JavaScript comprobados con `node --check`; 0 fallos.
- ZIP inspeccionado: una única carpeta raíz `openbooking-wp`, 0 entradas prohibidas.
- SHA-256: `37C1B48433E648FFFA7812C70D8AAE8669A1415953280B9F16292E66409D4D5C`.

## Limitación de la revisión

PHP no estaba disponible en `PATH` durante esta sesión, por lo que no se pudo
ejecutar `php -l` ni PHPUnit. El script `scripts/verify-release.ps1` ejecutará el
lint automáticamente en cualquier entorno que tenga PHP disponible. La revisión
estática y los controles de empaquetado reducen riesgos, pero no pueden garantizar
la ausencia absoluta de vulnerabilidades.
