# Bharat GPS Task Manager — Hostinger Setup Guide

## Files to Upload
Upload ALL files inside this folder to your Hostinger public_html:

```
public_html/
├── index.html         ← Main app
├── api/
│   ├── index.php      ← API backend
│   └── db.php         ← DB config (EDIT THIS FIRST)
├── uploads/           ← Create this folder, chmod 755
└── setup.sql          ← Run once in phpMyAdmin
```

## Step-by-Step Setup

### 1. Create MySQL Database
- Hostinger hPanel → Databases → MySQL Databases
- Create DB: `bharatgps_tasks`
- Create DB User + Password
- Assign user to DB with ALL PRIVILEGES

### 2. Edit db.php
Open `api/db.php` and update:
```php
define('DB_NAME', 'your_db_name');   // e.g. u123456_bharatgps
define('DB_USER', 'your_db_user');   // e.g. u123456_user
define('DB_PASS', 'your_db_password');
```

### 3. Run setup.sql
- Hostinger hPanel → phpMyAdmin → Select your DB
- Click "Import" → choose `setup.sql` → Go

### 4. Create uploads folder
- In Hostinger File Manager, create folder: `public_html/uploads`
- Right-click → Permissions → set to 755

### 5. Upload All Files
Upload everything to `public_html/`

## Default Login
- **Email:** somesh9346220090@gmail.com
- **Password:** password   ← CHANGE IMMEDIATELY after login

## User Roles
| Role | Can Do |
|------|--------|
| Admin | Everything — create/edit/delete tasks, manage users, all reports |
| Assigner | Create & assign tasks, view all, reports |
| Technician | View & update only their assigned tasks, record payments |

## Features
- ✅ Task Dashboard with live stats
- ✅ Create leads with full customer info
- ✅ Assign to technicians
- ✅ Status tracking (Open → In Progress → Closed)
- ✅ Payment recording with history
- ✅ Document uploads (Aadhar, RC, Selfie)
- ✅ Activity log / remarks
- ✅ Daily Report
- ✅ Balance Sheet
- ✅ WhatsApp sharing
- ✅ CSV & Text export
- ✅ Search & filter
- ✅ Technician panel (mobile-friendly)

## Add Technicians
Login as admin → Users → Add User → Role: Technician

## Notes
- Session-based auth (PHP sessions)
- All data stored in MySQL
- Uploads stored in /uploads/ folder
- Works on Hostinger shared hosting (PHP 8+, MySQL 5.7+)
