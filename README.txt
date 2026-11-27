

Test card numbers equivalent:
Successful payment: 4111 1111 1111 1111, expiry 12/30, CVV 123
Declined card: 4000 0000 0000 0002
Insufficient funds: 4000 0000 0000 0003
Expired card: Any card with an expired date, e.g. 01/25
Incorrect CVV: 	Any card with CVV 000

APIs Used in our System:

Payment
- Mockoon Payment API — simulates payment approval and failure.

Notifications
- Mockoon Notification API — creates in-site customer notifications.
- Mailtrap Sandbox Email API — sends order and status-update emails.

Location and Delivery
- Nominatim Geocoding API — converts customer addresses into coordinates.
- OSRM Routing API — calculates route distance and travel duration.
- Mockoon Delivery API — simulates delivery assignment, rider, and delivery reference.