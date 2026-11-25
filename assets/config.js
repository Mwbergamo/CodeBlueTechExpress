/* Connection points for the live systems. Leave a value null to run in demo
   mode (sample inventory, made-up booked times, forms that do not send).
   Fill these in when the server endpoints exist. */
window.TE_CONFIG = {
  // GET -> JSON array of { sku, name, brand, category, description, price, qty, image }
  // (read-only feed from the register's synced ConnectWise catalog)
  inventoryUrl: null,
  // GET ?from=YYYY-MM-DD&to=YYYY-MM-DD -> JSON array of booked start times, ISO strings
  availabilityUrl: '/api/availability.php',
  // POST JSON { start, reason, name, address, phone, email } -> { ok: true }
  appointmentUrl: '/api/appointments.php',
  // POST JSON { firstName, lastName, business, phone, email, reason } -> { ok: true }
  contactUrl: null,
  // Schedule Manager (staff console). Leave null for the demo console.
  // GET -> state JSON; POST -> actions. Needs ~/te-private/config.php on the server.
  managerUrl: '/api/manager.php'
};
