# Admin Page Modules

Page-specific admin modules live here. Each module should self-guard by checking for its page root before binding events.

Example pattern:

```js
export function initPaymentsPage() {
  const root = document.querySelector('[data-ob-admin-page="payments"]');
  if (!root) return;
}
```
