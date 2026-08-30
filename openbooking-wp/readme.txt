=== OpenBooking WP ===
Contributors: openbookingwp
Tags: booking, appointments, scheduling, reservations, calendar
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.2.4
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free open source booking and appointment plugin for WordPress. Services, availability, online payments, WhatsApp with your own API key, public reschedule/cancel flows, gateway health monitoring, audit logs, customer management, shortcode and Gutenberg block — no monthly fees or per-booking fees.

== Description ==

**OpenBooking WP** lets you accept bookings and appointments directly from your WordPress site without subscriptions or per-booking fees.

**Key features:**

* **Multi-step public booking form** — Service → Date & time → Customer data → Payment → Confirmation, fully accessible (ARIA, keyboard-navigable).
* **Flexible availability** — Define weekly schedules, breaks, date exceptions, and holidays per service or resource. Buffer times before and after each slot prevent back-to-back bookings.
* **Availability by scope** — Define schedules per service or resource, not just global.
* **Resources** — Assign bookings to specific persons, rooms, or equipment. Track capacity per resource.
* **Resource-service assignment** — Assign resources to services from the resources admin page.
* **Dynamic form fields** — Customise which fields appear in the booking form and in what order, directly from the admin panel.
* **Payment gateways** — Stripe (card), MercadoPago Checkout Pro, Webpay, and manual/on-site payment. Enable only the gateways that fit your country and business.
* **Gateway health monitoring** — Visual status of payment gateway configuration with missing field detection.
* **Deposit / anticipo mode** — Charge a configurable percentage upfront; collect the rest at the appointment.
* **Email notifications** — Automatic emails to customer and admin on booking confirmed, cancelled, and rescheduled. Fully editable templates with merge tags.
* **WhatsApp with your own API key** — Send local/best-effort WhatsApp notifications through Twilio or Meta Cloud API using your own credentials.
* **Email test send** — Send test emails to verify SMTP configuration.
* **Cancellation & rescheduling policies** — Set minimum advance hours required for customer-initiated changes.
* **Public reschedule flow** — Customers can reschedule bookings via token-based links.
* **Public cancel flow** — Customers can cancel bookings via token-based links.
* **Customer management** — Full customer list with search and booking history.
* **Audit log system** — Full audit trail for all admin actions with filtering and detail views.
* **Admin calendar** — Day, week, and month views. Filter by service and status. Confirm, cancel, or mark no-show from the sidebar. Reschedule bookings directly from the agenda sidebar.
* **System status** — Cron last runs, failed notifications, rejected webhooks, expired pending bookings.
* **Design customiser** — Change colours, typography, and border radius with a live preview. Built-in presets.
* **Shortcode and Gutenberg block** — Use `[openbooking]` or the OpenBooking block on any page or post. Optionally pre-select a service: `[openbooking service="my-service"]`.
* **i18n ready** — Fully translatable. Ships with Spanish (es_ES) as the default UI language. `.pot` file included.
* **WooCommerce integration** — Optional bridge that syncs booking status with WooCommerce order status automatically (not a standalone payment gateway).
* **Developer-friendly** — Action and filter hooks throughout (`openbooking_available_slots`, `openbooking_before_booking_insert`, `openbooking_booking_confirmed`, `openbooking_register_gateways`, and more). Clean DDD architecture.

== Installation ==

1. Upload the `openbooking-wp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **OpenBooking → Inicio** and follow the setup wizard.
4. Create a page, add the OpenBooking block or shortcode `[openbooking]`, and publish it.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
No. WooCommerce is optional. You can use Stripe, MercadoPago, or manual payment independently.

= Can I accept payments at the time of booking? =
Yes. Enable Stripe or MercadoPago in **Pagos**, enter your credentials, and customers will be redirected to the gateway checkout after filling in their details.

= How do I set up deposit (partial payment) mode? =
In **Pagos**, select **Anticipo** and set the percentage. Customers will pay that percentage online; the rest is collected at the appointment.

= Can I have multiple services with different schedules? =
Yes. Each service has its own availability rules, buffer times, capacity, and resources.

= How do I customise the booking form fields? =
Go to **Diseño → Campos**. You can reorder fields, mark them required or optional, and enable or disable individual fields.

= Is the booking form accessible? =
Yes. The form uses ARIA attributes, live regions, keyboard navigation, and semantic HTML throughout.

= What shortcode attributes are supported? =
`[openbooking service="slug"]` pre-selects a service (skips step 1).
`[openbooking layout="steps"]` chooses the layout (currently only `steps` is supported).

= Does WhatsApp require an external managed service? =
No. This community edition can send WhatsApp through your own Twilio or Meta credentials. Delivery depends on your site, provider and WP-Cron.

== Screenshots ==

1. Public booking form, service selection step.
2. Public booking form, date and time selection.
3. Admin calendar with booking sidebar actions.
4. Admin booking list with filters and statuses.
5. Service settings and availability configuration.
6. Payment settings with Stripe and MercadoPago health status.

== Changelog ==

= 1.2.2 =
* Catalog validation: service name length (2-191), description cap, SQL-like and separator rejection, duplicate slug/name rejection; duplicate active resource names rejected.
* Bookings: `resource_id` must exist, be active and belong to the service; internal notes editable from admin (`action: update_notes`) with audit trail.
* Privacy: `DELETE /admin/customers/{id}` anonymises customer PII while preserving booking history.
* Observability: outbox and notification queue mark exhausted items as `dead` (dead-letter); admin counts/filters expose the new state and retry reopens dead items.
* Security: plugin strips CORS headers on `openbooking/v1` routes when the request Origin is not allowed (WordPress core previously reflected any origin with credentials).
* Concurrency: service updates accept `expected_updated_at` and return 409 on stale writes; the admin edit form sends it automatically.
* Payments: gateway settings accept `mode` as an alias of `sandbox` for Webpay/MercadoPago (`mode=live` switches to production), normalise boolean flags, report applied/ignored fields, and return 404 for unknown gateways.
* Settings: required business fields (business name, sender name, sender address) reject empty values with a 400; sender address validates as an email.
* Wizard: switching services clears residual date/slot selection and in-flight polling; stored drafts expire after 24 hours.
* Admin UI: service list filters by status/visibility; resource list gains search plus type/status filters; assigned-resources tab shows errors with retry instead of an infinite spinner; double-submit guard on service creation.
* Version: OBWP_VERSION and Stable tag synchronised to 1.2.2.

= 1.1.9 =
* Security: fix DOM XSS in admin settings page (page_url injected via innerHTML, now uses DOM API).
* Uninstall: clean up all 11 tables added since v1.0 and all associated options/transients.
* i18n: all REST API error messages in Booking_Service and Payment_Service now pass through __().
* Reliability: CSV export endpoint is now rate-limited (5 requests per 5 minutes per admin user).
* Reliability: jQuery AJAX calls now enforce a 30-second timeout.
* UI: public booking form shows a privacy-policy consent checkbox when enabled in settings.
* CI: GitHub Actions workflow added (PHP 8.1/8.2 matrix + Jest + PHP lint).
* Assets: npm build scripts added for JS (terser) and CSS (cssnano/postcss) minification.
* Cron: error log messages translated to English for consistency.
* Version: OBWP_VERSION and Stable tag synchronized to 1.1.9.

= 1.1.0 =
* Public reschedule flow — customers can reschedule bookings via token-based links.
* Public cancel flow — Customers can cancel bookings via token-based links.
* Availability by scope — Define schedules per service or resource, not just global.
* Resource-service assignment — Assign resources to services from the resources admin page.
* Customer management — Full customer list with search and booking history.
* Gateway health monitoring — Visual status of payment gateway configuration with missing field detection.
* Audit log system — Full audit trail for all admin actions with filtering and detail views.
* Email test send — Send test emails to verify SMTP configuration.
* Admin reschedule — Reschedule bookings directly from the agenda sidebar.
* System status expansion — Cron last runs, failed notifications, rejected webhooks, expired pending bookings.
* MercadoPago webhook secret — Configurable webhook signature verification.
* Manual gateway always enabled — Simplifies booking flow for businesses.
* Double-submit prevention — UUID-based client reference prevents duplicate bookings.
* Webhook idempotency — Transient locks prevent duplicate webhook processing.
* Security hardening — SQL injection prevention, Stripe timestamp validation, rate limiting on webhooks.

= 1.0.0 =
* Initial release.
* Multi-step public booking form with 5 panels.
* Service, availability, resource, customer, and booking management.
* Stripe and MercadoPago payment gateways (no SDK required).
* WooCommerce bridge.
* Email notification system with 7 editable templates.
* Deposit/anticipo payment mode.
* Cancellation and rescheduling policies with time thresholds.
* Dynamic form fields from database.
* Transient caching for availability queries.
* Admin calendar with day/week/month views.
* Design customiser with live preview.
* Setup wizard / onboarding flow.
* Full ARIA accessibility.
* i18n with `.pot` file.

== Upgrade Notice ==

= 1.2.2 =
QA hardening update: catalog validation, dead-letter observability, CORS origin restriction, GDPR customer anonymisation, and Webpay production-mode fix. Upgrade recommended.

= 1.1.9 =
Security and reliability update. Includes an XSS fix in the admin panel, complete uninstall cleanup, and REST API i18n. Upgrade strongly recommended.

= 1.1.0 =
Major feature update. Upgrade recommended for all users.

= 1.0.0 =
First stable release. No upgrades required.
