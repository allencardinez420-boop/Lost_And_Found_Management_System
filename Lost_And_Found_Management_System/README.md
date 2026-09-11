# Lost & Found Management System

A complete PHP + MySQL starter application based on the supplied Excel workbook and the supplied Lost & Found logo.

## Files
- `index.php` — single-file application containing PHP backend, HTML, CSS, Tailwind CDN, and JavaScript.
- `database.sql` — MySQL/phpMyAdmin database schema and starter records transcribed from the workbook.
- `assets/logo.jpg` — supplied system logo.

## Database flow reflected in the workbook
`Users -> Lost Records`

`Users -> Found Item Records`

`Users -> Claim Records -> Found Item Records`

`Admin/User -> Verified By -> Claim Records`

The application adds a `Categories` table because the Excel tables reference `Category ID`, and it adds password/authentication fields to `Users` so the prototype can support real sessions.

Workflow: Report Lost / Report Found -> Registry -> Matching -> Claim -> Admin Verification -> Claimed / Returned.

## Install in XAMPP/WAMP/Laragon
1. Copy this folder into your web root, for example `htdocs/lost_found_system/`.
2. Start Apache and MySQL.
3. Open phpMyAdmin and import `database.sql`.
4. Check the DB constants at the top of `index.php`.
5. Visit `http://localhost/lost_found_system/`.

## Demo logins
- `U001` through `U010`
- Password: `demo123`
- `U004` is the Admin account.

## Notes
- The Excel workbook has some dates stored as Excel serial values and some as text; `database.sql` normalizes them to MySQL `DATE` values.
- The original core tables are preserved: Users, Lost Records, Found Item Records, Claim Records.
- The matching logic prioritizes category, item name, color, and location.
- For a production deployment, enable HTTPS, move DB credentials to environment variables, add rate limiting and audit logging, and replace demo accounts with real authentication/SSO.
