**Fizz and Bits Order Management Website**



Fizz and Bits is a **PHP and MySQL** online ordering system with **separate customer and administrator functions**. Customers can browse products, manage their cart, choose pickup or delivery, complete COD or simulated online payments, receive notifications, and view e-receipts. Administrators can manage orders, update order status, monitor inventory, and notify customers.



**System requirements**

\- XAMPP with Apache and MySQL 

\- PHP with cURL enabled

\- Google Chrome or another modern browser

\- Mockoon

\- Mailtrap Sandbox account

\- Internet connection for Mailtrap, Nominatim, and OSRM requests



**Local setup**

1\. Copy or clone this project into: C:\\xampp\\htdocs\\Fizz\_and\_Bits\_Website

2\. Open XAMPP and start: Apache and MySQL

3\. Open phpMyAdmin: http://localhost/phpmyadmin

4\. Create a database named: fizz\_and\_bits\_db

5\. Import: fizz\_and\_bits\_db.sql

6\. Start Mockoon using the Fizz and Bits environment on: http://localhost:9001



&#x09;The following Mockoon routes should exist:

&#x09;	POST /api/v1/payments

&#x09;	POST /api/v1/notifications

&#x09;	POST /api/v1/deliveries



7\. Configure Mailtrap Credentials locally in: 

Customer\_side/php/config.php

Administrator\_side/php/config.php



Add the Mailtrap Sandbox API token and Inbox ID to the existing placeholders. Never commit or publicly share these credentials.



8\. Open the website: http://localhost/Fizz\_and\_Bits\_Website/



**Customer navigation**

1\. Open the website homepage.

2\. Log in or create a customer account.

3\. Open the menu and select products.

4\. Add products to the cart.

5\. Open the cart and proceed to checkout.

6\. Enter contact and delivery information.

7\. Select either:

&#x20;  - Pick Up for pickup orders

&#x20;  - Delivery for delivery orders

8\. Select either:

&#x20;  - Cash on Delivery

&#x20;  - Online Payment Test

9\. Complete the order.

10\. View the pickup or delivery details popup.

11\. View the e-receipt.

12\. Use the bell icon to view customer notifications.



Delivery orders use **Nominatim** for address lookup, **OSRM** for route distance and duration, and **Mockoon** for simulated delivery assignment. If the location or routing service is unavailable, the system uses its fallback delivery estimate of 30 minutes.



**Test payment details**



These values are mock-only and are not real payment credentials.

**Successful payment:** 4111 1111 1111 1111, expiry 12/30, CVV 123

**Declined card:** 4000 0000 0000 0002

**Insufficient funds:** 4000 0000 0000 0003

**Expired card:** Any card with an expired date, e.g. 01/25

**Incorrect CVV:** 	Any card with CVV 000



The payment popup also supports cancellation and a 60-second payment-session expiration.





**Administrator navigation**

1\. Open the administrator login page.

2\. Log in using an administrator account.

3\. Open the order-management page.

4\. Search or filter orders.

5\. Open an order to inspect its details.

6\. Change an order from On Process to:

&#x20;  - Completed

&#x20;  - Canceled

7\. Confirm the update.



When an order is completed:

\- The customer receipt displays Completed in green.

\- Inventory is deducted.

\- The customer receives an in-site notification.

\- Mailtrap receives a completion email.



When an order is canceled:

\- The customer receipt displays Canceled in red.

\- The customer receives an in-site notification.

\- Mailtrap receives a cancellation email without payment or delivery details.





**APIs used in the system**



**Payment**

* **Mockoon Payment API** — simulates payment approval and failure.

&#x09;POST http://localhost:9001/api/v1/payments



**Notifications**

* **Mockoon Notification API** — creates in-site customer notifications.
* **Mailtrap Sandbox Email API** — sends order and order-status emails into the Mailtrap test inbox.

&#x09;POST http://localhost:9001/api/v1/notifications

&#x09;POST https://sandbox.api.mailtrap.io/api/send/{inbox\_id}



**Location and delivery**

* **Nominatim Geocoding API** — converts customer addresses into coordinates.
* **OSRM Routing API** — calculates route distance and travel duration.
* **Mockoon Delivery API** — simulates delivery assignment, rider, and delivery reference.

&#x09;GET https://nominatim.openstreetmap.org/search

&#x09;GET https://router.project-osrm.org/route/v1/driving/...

&#x09;POST http://localhost:9001/api/v1/deliveries





**External-service limitations**

\- Mockoon is a local simulation and does not process real payments or dispatch real couriers.

\- Mailtrap Sandbox captures test emails and does not deliver them to real customer inboxes.

\- Nominatim and OSRM are public services intended for limited academic or development use.

\- Location and routing requests require an internet connection.

\- If a public location service fails, the website uses the configured fallback delivery estimate.





**Troubleshooting**



**Website does not open**

\- Confirm Apache is running.

\- Confirm the project is inside `C:\\xampp\\htdocs`.

\- Check the URL for the correct project folder name.



**Database error**

\- Confirm MySQL is running.

\- Confirm the database is named `fizz\_and\_bits\_db`.

\- Confirm the SQL file was imported.



**Online payment fails unexpectedly**

\- Confirm Mockoon is running on port `9001`.

\- Confirm the payment route uses `POST`.

\- Check Mockoon Logs.

\- Confirm the test card and expiry date are valid.



**Delivery information does not appear**

\- Confirm the order was created as Delivery.

\- Confirm the Mockoon delivery route exists.

\- Check the delivery API request in Mockoon Logs.

\- Confirm the computer has internet access for Nominatim and OSRM.



**Email does not appear in Mailtrap**

\- Confirm the contact field contains a valid email address.

\- Confirm the Sandbox API token and Inbox ID are configured in both PHP config files.

\- Confirm the email is being viewed in the correct Mailtrap Sandbox.

\- Check the Apache/PHP error log.



**Security reminders**

\- Do not commit Mailtrap API tokens, passwords, or database credentials.

\- Do not use real card details in this academic project.

\- Card fields are mock-only and are not saved as payment data.

\- Keep API credentials in local configuration files.

\- Revoke and replace a token if it is exposed.



**Demonstration checklist**



\- \[ ] Apache and MySQL running

\- \[ ] Mockoon environment running

\- \[ ] Mailtrap Sandbox open

\- \[ ] Customer login demonstrated

\- \[ ] Pickup COD order demonstrated

\- \[ ] Delivery online-payment order demonstrated

\- \[ ] Payment failure demonstrated

\- \[ ] Mockoon logs shown

\- \[ ] Mailtrap email shown

\- \[ ] Customer notification bell shown

\- \[ ] Admin order completion shown

\- \[ ] Completed customer receipt shown

\- \[ ] Database records verified in phpMyAdmin



