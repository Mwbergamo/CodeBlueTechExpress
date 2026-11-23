/* Connection points for the live systems. Leave a value null to run in demo
   mode (sample inventory, made-up booked times, forms that do not send).
   Fill these in when the server endpoints exist. */
window.TE_CONFIG = {
  // GET -> JSON array of { sku, name, brand, category, description, price, qty, image }
  // (read-only feed from the register's synced ConnectWise catalog)
  inventoryUrl: null,
  // GET ?from=YYYY-MM-DD&to=YYYY-MM-DD -> JSON array of booked start times, ISO strings
  availabilityUrl: null,
  // POST JSON { start, reason, name, address, phone } -> { ok: true }
  appointmentUrl: null,
  // POST JSON { firstName, lastName, business, phone, email, reason } -> { ok: true }
  contactUrl: null
};
