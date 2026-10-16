# Hikayat Banaras — Saree Store Management System

A web-based management system for a saree retail store, built with **PHP, MySQL (PDO), HTML, CSS and JavaScript**. It covers day-to-day shop operations: billing, inventory, customers, staff, vendors and reports.

## Features

- **Inventory:** saree records, categories, stock adjustments, CSV import/export, barcode label printing
- **Sales / POS:** billing, invoices (PDF + QR code), returns, enquiry logging, finishing/tailoring stages with printable tags, external invoice import
- **Sales Orders:** order details, deliveries, payments, delivery challan
- **Customers / CRM:** loyalty points, credit ledger, WhatsApp campaigns, automatic reminders
- **Staff:** roles and permissions, salary and commission, attendance with **face recognition, selfie and GPS location**
- **Vendors:** purchase orders, receiving stock, vendor payments
- **Expenses and Reports:** sales, stock, staff performance, profit and loss
- **Settings and Security:** user management, audit log, active sessions, automated database backups, bank reconciliation

## Tech Stack

- PHP 8 (PDO, prepared statements, `password_hash`)
- MySQL / MariaDB
- JavaScript libraries: face-api.js, JsBarcode, html2pdf.js, qrcodejs
- Runs on XAMPP / WAMP / Laragon

## Setup (localhost)

1. Copy the project folder into `C:\xampp\htdocs\hikayat_banaras`.
2. Start **Apache** and **MySQL** in XAMPP.
3. In phpMyAdmin, create a database named `hikayat_banaras` and import `database/schema.sql`.
4. Copy the config files and edit them:
   - `config/db.example.php` → `config/db.php` (set your DB user and password)
   - `config/config.example.php` → `config/config.php` (set `BASE_URL` if your folder name differs)
5. Create the first admin user. Generate a password hash:
   ```
   php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"
   ```
   Then insert a row into the `roles` table (role name `Admin`) and the `staff` table with that hash in `password_hash`.
6. Open `http://localhost/hikayat_banaras/admin/login.php` and log in.

## Scheduled Jobs

Run these daily (Windows Task Scheduler or Linux cron):

```
php cron/backup.php
php cron/expire-loyalty-points.php
php cron/generate-reminders.php
```

## Notes

- `config/`, `assets/uploads/` and `backups/*.sql` are excluded from the repository because they hold credentials and private data.
- This is a learning/portfolio project built for a real store workflow.
