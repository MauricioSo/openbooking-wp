# Core

Nucleo del plugin: bootstrap, infraestructura compartida y contratos.

- `Api/` → Controllers de sistema (dashboard, cron, outbox, health, webhooks).
- `Application/` → Servicios de sistema (dashboard, health check, integridad, feature flags).
- `Domain/` → Interfaces port (SettingsInterface, CronManagerInterface, etc.) y value objects.
- `Infrastructure/` → Adaptadores WP, cron manager, activador, event bus, metricas.

Entry point: Rest_Api_Registrar (registro de rutas), Activator (migraciones)
Dependencias: Ninguna (es el nucleo del que dependen los demas modulos)
