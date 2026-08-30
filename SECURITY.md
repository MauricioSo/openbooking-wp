# Seguridad

## Garantías de distribución

El control `scripts/verify-release.ps1` rechaza:

- rutas o identificadores reservados para ediciones comerciales;
- claves privadas y patrones comunes de credenciales reales;
- PHP fuera de los directorios permitidos;
- ejecutables y scripts de sistema dentro del paquete instalable;
- enlaces simbólicos o puntos de reanálisis;
- inconsistencias entre la versión del encabezado, `OBWP_VERSION` y `Stable tag`.

El control también ejecuta `php -l` sobre todo el PHP cuando encuentra PHP en
`PATH`. Que una revisión automática finalice correctamente reduce riesgos, pero
no equivale a una garantía absoluta de ausencia de vulnerabilidades.

## Superficie de red

El plugin solo realiza solicitudes salientes cuando el administrador habilita y
configura una integración. Los destinos incluidos son las API oficiales de
Stripe, MercadoPago, Webpay/Transbank, Twilio y Meta. Los webhooks salientes son
configurables por un administrador y deben apuntar a HTTPS en producción.

## Reporte responsable

No publiques datos personales, credenciales ni pruebas de explotación en un issue
público. Reporta el hallazgo de forma privada al mantenedor del proyecto e incluye
la versión afectada, impacto, pasos de reproducción y una prueba mínima.
