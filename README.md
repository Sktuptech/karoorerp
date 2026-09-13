# Karoor ERP

Karoor ERP is a PHP 8.2/MySQL 8 business management application designed for
shared hosting, Plesk, XAMPP, Laragon, and VPS deployments.

## Phase 1 status

The foundation phase is complete:

- normalized MySQL schema for the planned ERP modules
- PDO database access with native prepared statements and transactions
- secure sessions, CSRF protection, password hashing, and login throttling
- dynamic users, roles, permissions, role assignments, and permission assignments
- seven seeded system roles with least-privilege permission sets
- forced bootstrap-password replacement and rotating remember-me tokens
- server-side authorization, audit logging, soft-delete columns, and tenant scoping
- Apache front-controller rules that protect source, configuration, and SQL files

## Phase 2 status

The backend phase is complete:

- REST-style, permission-protected APIs for dashboard metrics, contacts,
  inventory, sales, POS, purchases, expenses, finance, HRM, and reports
- transactional stock adjustments, transfers, purchasing, sales, refunds,
  payroll payments, and balanced double-entry journal posting
- customer and supplier ledgers, receivables/payables, trial balance, income
  statement, balance sheet, cash flow, and CSV report exports
- employee, attendance, leave, payroll component, payroll approval, and salary
  payment workflows
- server-side validation, tenant isolation, guarded uploads, audit trails,
  soft deletion, and administrator-only recovery operations

## Phase 3 status

The mobile-first UI/UX phase is complete:

- responsive dark navy/glass application shell with persistent dark/light mode
- desktop sidebar, mobile off-canvas navigation, and permission-aware bottom bar
- live dashboard KPIs, Chart.js visualizations, and reusable loading/error/empty states
- API-backed screens, filters, tables, forms, modals, and pagination for every module
- touch-friendly POS product browser and mobile cart drawer
- system administration for users, roles, permissions, audit logs, backups, and recycle bin
- responsive invoices, receipts, payslips, financial statements, and report exports

Offline/PWA behavior and final production hardening remain separate milestones
for Phases 4 and 5.

## Requirements

- PHP 8.2 or newer with `pdo_mysql`, `mbstring`, and `session`
- MySQL 8.0.16 or newer
- Apache with `mod_rewrite`, or another web server configured to route requests
  to `index.php`

No Composer, Node.js, framework, container, or build step is required.

## Installation

1. Create an empty MySQL database and import `database.sql`.
2. Copy `.env.example` to `.env` and set the database connection, application
   URL, and a unique `APP_KEY`.
3. Point the web root at this directory. Ensure Apache allows `.htaccess`
   overrides when using Apache.
4. Make `storage/logs`, `storage/backups`, and the required subdirectories of
   `uploads` writable by PHP. Do not make the whole project writable.
5. Serve the site over HTTPS in production and sign in with the one-time
   bootstrap credentials documented at the end of `database.sql`.
6. Replace the bootstrap password immediately when prompted.

Generate an application key locally with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

For PHP's development server:

```bash
php -S 127.0.0.1:8080 index.php
```

The development server is for local use only.

## Configuration

Configuration is read from environment variables first and then from the
non-public `.env` file. Production mode requires an HTTPS `APP_URL`, an
`APP_KEY` of at least 32 characters, a non-root database user, and a non-empty
database password.

Never commit `.env`, backups, logs, or uploaded private documents.

## Main API endpoints

- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/change-password`
- `POST /api/v1/auth/logout`
- `GET|POST|PUT|PATCH|DELETE /api/v1/system/users/{id?}`
- `GET|POST|PUT|PATCH /api/v1/system/roles/{id?}`
- `GET /api/v1/system/permissions`
- `GET /api/v1/system/audit`
- `/api/v1/dashboard/{summary|sales-purchases|revenue-profit|top-products}`
- `/api/v1/inventory/{products|categories|units|warehouses|adjustments|transfers}`
- `/api/v1/{customers|suppliers|sales|purchases|expenses}`
- `/api/v1/pos/{products|categories|registers|session|open|close|sales}`
- `/api/v1/finance/{chart-of-accounts|accounts|journals|ledger|trial-balance}`
- `/api/v1/hrm/{departments|employees|attendance|leave|payroll-components|payroll}`
- `/api/v1/reports/{sales|purchases|inventory|finance|hr|profit}`

All state-changing API requests require the CSRF token returned by the auth
API in the `X-CSRF-Token` header. User and role management endpoints also
enforce their corresponding permission on the server.
