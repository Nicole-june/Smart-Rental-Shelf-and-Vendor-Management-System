# Smart Rental Shelf & Vendor Management System

Final-year Computer Science project. Built with **PHP 8**, **MySQL**, HTML, CSS, JavaScript (Chart.js). Designed to run locally on **XAMPP**.

## Modules
1. Authentication (admin + vendor, session-based)
2. Vendor Management (CRUD, suspend/activate)
3. Shelf Management (CRUD)
4. Shelf Occupancy Monitor (real-time visual grid)
5. Rental Management (pending → active → expired/terminated; auto-release on end date)
6. Inventory (products, low-stock thresholds)
7. Sales (auto-decrement stock, low-stock alerts)
8. Payments (simulated M-Pesa / card / bank / cash gateway)
9. Notifications (sale, low_stock, rental_expiry, payment, system)
10. Dashboards & Reports (Chart.js)

## Smart features
- **Auto-decrement inventory on every sale** (`vendor/sales.php`)
- **Low-stock alerts** generated automatically at sale time
- **Automatic rental expiry** — shelves released when `end_date < CURDATE()` (`expire_due_rentals()`)
- **Expiry warnings** — notifications for rentals ending within 3 days (`warn_expiring_rentals()`)

## Setup (XAMPP)

1. Install XAMPP and start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Copy the entire `smart-rental-php` folder into `C:\xampp\htdocs\`.
3. Open http://localhost/phpmyadmin/ → click **Import** → choose `database/schema.sql` → **Go**.
4. Open http://localhost/smart-rental-php/reset_passwords.php once to set real password hashes.
5. Delete `reset_passwords.php` after use.
6. Visit http://localhost/smart-rental-php/

## Default logins
| Role   | Email              | Password    |
|--------|--------------------|-------------|
| Admin  | admin@rental.test  | admin123    |
| Vendor | jane@vendor.test   | vendor123   |
| Vendor | john@vendor.test   | vendor123   |

Vendors can also self-register at `/register.php`.

## Folder layout
```
smart-rental-php/
├── config/         database.php, config.php
├── includes/       auth.php, functions.php, header.php, footer.php
├── admin/          dashboard, vendors, shelves, occupancy, rentals, payments, reports, notifications
├── vendor/         dashboard, shelves, rentals, products, sales, pay, payments, notifications
├── assets/         style.css
├── database/       schema.sql
├── index.php       landing page
├── login.php / logout.php / register.php
└── reset_passwords.php   (run once, then delete)
```

## Academic mapping
- **ERD entities** ↔ tables: `users, vendors, shelves, rentals, products, sales, payments, notifications`
- **3NF**: no repeating groups; all non-key attributes depend on the whole primary key; FK relationships enforced with `InnoDB`.
- **Objectives**: every listed objective in the project scope is implemented as a working module (see above).
- **Methodology**: incremental / modular development — each module ships independently but shares the config, auth, and function layers.
