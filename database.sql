-- Karoor ERP database schema
-- Target: MySQL 8.0.16+
-- Import this file into an empty database selected by the hosting control panel.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

CREATE TABLE companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    legal_name VARCHAR(190) NULL,
    tax_number VARCHAR(100) NULL,
    vat_number VARCHAR(100) NULL,
    registration_number VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    website VARCHAR(255) NULL,
    address_line1 VARCHAR(255) NULL,
    address_line2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'ET',
    postal_code VARCHAR(20) NULL,
    logo_path VARCHAR(255) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'ETB',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Addis_Ababa',
    date_format VARCHAR(32) NOT NULL DEFAULT 'Y-m-d',
    fiscal_year_start TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT chk_companies_fiscal_month CHECK (fiscal_year_start BETWEEN 1 AND 12),
    UNIQUE KEY uq_companies_tax_number (tax_number),
    KEY idx_companies_active (is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    is_head_office BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_branches_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_branches_company_code (company_id, code),
    KEY idx_branches_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE warehouses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_warehouses_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_warehouses_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    UNIQUE KEY uq_warehouses_company_code (company_id, code),
    KEY idx_warehouses_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_roles_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_roles_company_slug (company_id, slug),
    KEY idx_roles_active (is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    module VARCHAR(60) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_name (name),
    KEY idx_permissions_module (module)
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    default_warehouse_id BIGINT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    phone VARCHAR(50) NULL,
    avatar_path VARCHAR(255) NULL,
    status ENUM('ACTIVE', 'INACTIVE', 'LOCKED') NOT NULL DEFAULT 'ACTIVE',
    locale VARCHAR(10) NOT NULL DEFAULT 'en',
    force_password_change BOOLEAN NOT NULL DEFAULT FALSE,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    remember_token_hash CHAR(64) NULL,
    remember_token_expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_users_default_warehouse FOREIGN KEY (default_warehouse_id) REFERENCES warehouses(id),
    UNIQUE KEY uq_users_company_username (company_id, username),
    UNIQUE KEY uq_users_company_email (company_id, email),
    KEY idx_users_status (company_id, status, deleted_at),
    KEY idx_users_remember_token (remember_token_hash)
) ENGINE=InnoDB;

ALTER TABLE companies
    ADD CONSTRAINT fk_companies_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE branches
    ADD CONSTRAINT fk_branches_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE warehouses
    ADD CONSTRAINT fk_warehouses_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE roles
    ADD CONSTRAINT fk_roles_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE users
    ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    granted_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_rate_limit (identifier_hash, ip_address, attempted_at),
    KEY idx_login_attempts_cleanup (attempted_at)
) ENGINE=InnoDB;

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    setting_group VARCHAR(60) NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT NULL,
    value_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') NOT NULL DEFAULT 'STRING',
    is_public BOOLEAN NOT NULL DEFAULT FALSE,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_settings_company_key (company_id, setting_key),
    KEY idx_settings_group (company_id, setting_group)
) ENGINE=InnoDB;

CREATE TABLE document_sequences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    document_type VARCHAR(40) NOT NULL,
    prefix VARCHAR(20) NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
    padding TINYINT UNSIGNED NOT NULL DEFAULT 6,
    reset_period ENUM('NEVER', 'YEARLY', 'MONTHLY') NOT NULL DEFAULT 'NEVER',
    last_reset_date DATE NULL,
    branch_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(branch_id, 0)) STORED,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sequences_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_sequences_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT chk_sequences_next_number CHECK (next_number > 0),
    CONSTRAINT chk_sequences_padding CHECK (padding BETWEEN 1 AND 12),
    UNIQUE KEY uq_sequences_scope (company_id, branch_scope_id, document_type)
) ENGINE=InnoDB;

CREATE TABLE taxes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    is_inclusive BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_taxes_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_taxes_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_taxes_rate CHECK (rate BETWEEN 0 AND 100),
    UNIQUE KEY uq_taxes_company_name (company_id, name),
    KEY idx_taxes_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    description TEXT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_categories_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_categories_company_slug (company_id, slug),
    KEY idx_categories_parent (company_id, parent_id, is_active, deleted_at),
    KEY idx_categories_sort (company_id, sort_order, name)
) ENGINE=InnoDB;

CREATE TABLE units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    short_name VARCHAR(20) NOT NULL,
    precision_scale TINYINT UNSIGNED NOT NULL DEFAULT 2,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_units_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_units_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_units_precision CHECK (precision_scale <= 4),
    UNIQUE KEY uq_units_company_short_name (company_id, short_name)
) ENGINE=InnoDB;

CREATE TABLE brands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_brands_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_brands_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_brands_company_name (company_id, name)
) ENGINE=InnoDB;

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    brand_id BIGINT UNSIGNED NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    tax_id BIGINT UNSIGNED NULL,
    sku VARCHAR(100) NOT NULL,
    barcode VARCHAR(100) NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    product_type ENUM('STOCK', 'SERVICE') NOT NULL DEFAULT 'STOCK',
    purchase_price DECIMAL(19,4) NOT NULL DEFAULT 0,
    selling_price DECIMAL(19,4) NOT NULL DEFAULT 0,
    wholesale_price DECIMAL(19,4) NULL,
    minimum_stock DECIMAL(19,4) NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    track_inventory BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    CONSTRAINT fk_products_tax FOREIGN KEY (tax_id) REFERENCES taxes(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_products_prices CHECK (purchase_price >= 0 AND selling_price >= 0 AND (wholesale_price IS NULL OR wholesale_price >= 0)),
    CONSTRAINT chk_products_minimum_stock CHECK (minimum_stock >= 0),
    UNIQUE KEY uq_products_company_sku (company_id, sku),
    UNIQUE KEY uq_products_company_barcode (company_id, barcode),
    KEY idx_products_search (company_id, name, is_active, deleted_at),
    KEY idx_products_category (company_id, category_id, is_active),
    KEY idx_products_low_stock (company_id, minimum_stock)
) ENGINE=InnoDB;

CREATE TABLE warehouse_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
    reserved_quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
    average_cost DECIMAL(19,4) NOT NULL DEFAULT 0,
    last_movement_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_inventory_quantity CHECK (quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= quantity),
    CONSTRAINT chk_inventory_average_cost CHECK (average_cost >= 0),
    UNIQUE KEY uq_inventory_warehouse_product (warehouse_id, product_id),
    KEY idx_inventory_product (product_id, warehouse_id),
    KEY idx_inventory_quantity (warehouse_id, quantity)
) ENGINE=InnoDB;

CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    customer_code VARCHAR(40) NOT NULL,
    name VARCHAR(190) NOT NULL,
    contact_person VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    tax_number VARCHAR(100) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    credit_limit DECIMAL(19,4) NOT NULL DEFAULT 0,
    opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_customers_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_customers_credit_limit CHECK (credit_limit >= 0),
    UNIQUE KEY uq_customers_company_code (company_id, customer_code),
    KEY idx_customers_search (company_id, name, phone, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    supplier_code VARCHAR(40) NOT NULL,
    name VARCHAR(190) NOT NULL,
    contact_person VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    tax_number VARCHAR(100) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_suppliers_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_suppliers_company_code (company_id, supplier_code),
    KEY idx_suppliers_search (company_id, name, phone, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE chart_of_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    account_type ENUM('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE') NOT NULL,
    account_subtype VARCHAR(60) NOT NULL,
    normal_balance ENUM('DEBIT', 'CREDIT') NOT NULL,
    is_control_account BOOLEAN NOT NULL DEFAULT FALSE,
    allow_manual_entries BOOLEAN NOT NULL DEFAULT TRUE,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_coa_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_coa_parent FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT fk_coa_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_coa_company_code (company_id, code),
    KEY idx_coa_type (company_id, account_type, is_active, deleted_at),
    KEY idx_coa_parent (parent_id)
) ENGINE=InnoDB;

CREATE TABLE accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    chart_account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    account_kind ENUM('CASH', 'BANK', 'MOBILE_MONEY', 'OTHER') NOT NULL,
    account_number VARCHAR(100) NULL,
    bank_name VARCHAR(150) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'ETB',
    opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_accounts_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_accounts_chart FOREIGN KEY (chart_account_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT fk_accounts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_accounts_company_name (company_id, name),
    KEY idx_accounts_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE journal_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    entry_number VARCHAR(50) NOT NULL,
    entry_date DATE NOT NULL,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    status ENUM('DRAFT', 'POSTED', 'REVERSED') NOT NULL DEFAULT 'DRAFT',
    posted_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    reversed_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_journal_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_journal_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_journal_posted_by FOREIGN KEY (posted_by) REFERENCES users(id),
    CONSTRAINT fk_journal_reversed_entry FOREIGN KEY (reversed_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_journal_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_journal_company_number (company_id, entry_number),
    KEY idx_journal_date_status (company_id, entry_date, status),
    KEY idx_journal_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE journal_entry_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id BIGINT UNSIGNED NOT NULL,
    chart_account_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    supplier_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    debit DECIMAL(19,4) NOT NULL DEFAULT 0,
    credit DECIMAL(19,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_journal_lines_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_journal_lines_account FOREIGN KEY (chart_account_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT fk_journal_lines_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_journal_lines_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT chk_journal_lines_amount CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
    KEY idx_journal_lines_account (chart_account_id, journal_entry_id),
    KEY idx_journal_lines_customer (customer_id),
    KEY idx_journal_lines_supplier (supplier_id)
) ENGINE=InnoDB;

CREATE TABLE sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    salesperson_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    sale_date DATETIME NOT NULL,
    status ENUM('DRAFT', 'COMPLETED', 'CANCELLED', 'REFUNDED') NOT NULL DEFAULT 'DRAFT',
    subtotal DECIMAL(19,4) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    due_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    completed_at DATETIME NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_sales_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_sales_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_sales_salesperson FOREIGN KEY (salesperson_id) REFERENCES users(id),
    CONSTRAINT fk_sales_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_sales_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_sales_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_sales_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_sales_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0 AND due_amount >= 0),
    UNIQUE KEY uq_sales_company_invoice (company_id, invoice_number),
    KEY idx_sales_date_status (company_id, sale_date, status, deleted_at),
    KEY idx_sales_customer (customer_id, sale_date),
    KEY idx_sales_salesperson (salesperson_id, sale_date),
    KEY idx_sales_warehouse (warehouse_id, sale_date)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(190) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    quantity DECIMAL(19,4) NOT NULL,
    unit_price DECIMAL(19,4) NOT NULL,
    cost_price DECIMAL(19,4) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    line_total DECIMAL(19,4) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_sale_items_values CHECK (quantity > 0 AND unit_price >= 0 AND cost_price >= 0 AND discount_amount >= 0 AND tax_rate >= 0 AND tax_amount >= 0 AND line_total >= 0),
    KEY idx_sale_items_product (product_id, sale_id)
) ENGINE=InnoDB;

CREATE TABLE purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    purchase_number VARCHAR(50) NOT NULL,
    supplier_invoice_number VARCHAR(100) NULL,
    purchase_date DATETIME NOT NULL,
    status ENUM('DRAFT', 'ORDERED', 'RECEIVED', 'CANCELLED', 'RETURNED') NOT NULL DEFAULT 'DRAFT',
    subtotal DECIMAL(19,4) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    due_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    received_at DATETIME NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_purchases_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_purchases_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_purchases_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_purchases_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_purchases_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_purchases_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_purchases_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_purchases_amounts CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0 AND due_amount >= 0),
    UNIQUE KEY uq_purchases_company_number (company_id, purchase_number),
    KEY idx_purchases_date_status (company_id, purchase_date, status, deleted_at),
    KEY idx_purchases_supplier (supplier_id, purchase_date),
    KEY idx_purchases_warehouse (warehouse_id, purchase_date)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(190) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    quantity DECIMAL(19,4) NOT NULL,
    received_quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(19,4) NOT NULL,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    line_total DECIMAL(19,4) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_purchase_items_values CHECK (quantity > 0 AND received_quantity >= 0 AND received_quantity <= quantity AND unit_cost >= 0 AND discount_amount >= 0 AND tax_rate >= 0 AND tax_amount >= 0 AND line_total >= 0),
    KEY idx_purchase_items_product (product_id, purchase_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    payment_number VARCHAR(50) NOT NULL,
    direction ENUM('IN', 'OUT') NOT NULL,
    payment_method ENUM('CASH', 'BANK', 'MOBILE_MONEY', 'CREDIT', 'OTHER') NOT NULL,
    amount DECIMAL(19,4) NOT NULL,
    payment_date DATETIME NOT NULL,
    reference_number VARCHAR(100) NULL,
    party_type ENUM('CUSTOMER', 'SUPPLIER', 'EMPLOYEE', 'OTHER') NULL,
    party_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_payments_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_payments_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_payments_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_payments_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_payments_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_payments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_payments_amount CHECK (amount > 0),
    UNIQUE KEY uq_payments_company_number (company_id, payment_number),
    KEY idx_payments_date (company_id, payment_date, direction, deleted_at),
    KEY idx_payments_party (party_type, party_id)
) ENGINE=InnoDB;

CREATE TABLE sale_payments (
    sale_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount_applied DECIMAL(19,4) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sale_id, payment_id),
    CONSTRAINT fk_sale_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_sale_payments_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT chk_sale_payments_amount CHECK (amount_applied > 0),
    KEY idx_sale_payments_payment (payment_id)
) ENGINE=InnoDB;

CREATE TABLE purchase_payments (
    purchase_id BIGINT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount_applied DECIMAL(19,4) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (purchase_id, payment_id),
    CONSTRAINT fk_purchase_payments_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    CONSTRAINT fk_purchase_payments_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT chk_purchase_payments_amount CHECK (amount_applied > 0),
    KEY idx_purchase_payments_payment (payment_id)
) ENGINE=InnoDB;

CREATE TABLE expense_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    chart_account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expense_categories_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_expense_categories_account FOREIGN KEY (chart_account_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT fk_expense_categories_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_expense_categories_company_name (company_id, name)
) ENGINE=InnoDB;

CREATE TABLE expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    expense_category_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    expense_number VARCHAR(50) NOT NULL,
    expense_date DATE NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(19,4) NOT NULL,
    payment_method ENUM('CASH', 'BANK', 'MOBILE_MONEY', 'CREDIT', 'OTHER') NOT NULL,
    attachment_path VARCHAR(255) NULL,
    notes TEXT NULL,
    payment_id BIGINT UNSIGNED NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_expenses_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_expenses_category FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id),
    CONSTRAINT fk_expenses_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_expenses_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_expenses_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_expenses_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    CONSTRAINT fk_expenses_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_expenses_amount CHECK (amount > 0),
    UNIQUE KEY uq_expenses_company_number (company_id, expense_number),
    KEY idx_expenses_date (company_id, expense_date, deleted_at),
    KEY idx_expenses_category (expense_category_id, expense_date)
) ENGINE=InnoDB;

CREATE TABLE customer_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    transaction_date DATETIME NOT NULL,
    transaction_type ENUM('OPENING_BALANCE', 'SALE', 'PAYMENT', 'REFUND', 'ADJUSTMENT') NOT NULL,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    debit DECIMAL(19,4) NOT NULL DEFAULT 0,
    credit DECIMAL(19,4) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_transactions_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_customer_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_customer_transactions_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_customer_transactions_amount CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
    KEY idx_customer_transactions_ledger (customer_id, transaction_date),
    KEY idx_customer_transactions_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE supplier_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    transaction_date DATETIME NOT NULL,
    transaction_type ENUM('OPENING_BALANCE', 'PURCHASE', 'PAYMENT', 'RETURN', 'ADJUSTMENT') NOT NULL,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    debit DECIMAL(19,4) NOT NULL DEFAULT 0,
    credit DECIMAL(19,4) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_transactions_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_supplier_transactions_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_supplier_transactions_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_supplier_transactions_amount CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
    KEY idx_supplier_transactions_ledger (supplier_id, transaction_date),
    KEY idx_supplier_transactions_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE stock_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    transfer_number VARCHAR(50) NOT NULL,
    from_warehouse_id BIGINT UNSIGNED NOT NULL,
    to_warehouse_id BIGINT UNSIGNED NOT NULL,
    transfer_date DATETIME NOT NULL,
    status ENUM('DRAFT', 'PENDING', 'APPROVED', 'IN_TRANSIT', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    notes TEXT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    completed_by BIGINT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_transfers_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_transfers_from_warehouse FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_transfers_to_warehouse FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_transfers_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_transfers_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_transfers_completed_by FOREIGN KEY (completed_by) REFERENCES users(id),
    CONSTRAINT fk_transfers_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_transfers_warehouses CHECK (from_warehouse_id <> to_warehouse_id),
    UNIQUE KEY uq_transfers_company_number (company_id, transfer_number),
    KEY idx_transfers_date_status (company_id, transfer_date, status, deleted_at),
    KEY idx_transfers_from (from_warehouse_id, transfer_date),
    KEY idx_transfers_to (to_warehouse_id, transfer_date)
) ENGINE=InnoDB;

CREATE TABLE stock_transfer_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_transfer_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(19,4) NOT NULL,
    received_quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transfer_items_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_transfer_items_quantity CHECK (quantity > 0 AND received_quantity >= 0 AND received_quantity <= quantity AND unit_cost >= 0),
    UNIQUE KEY uq_transfer_items_product (stock_transfer_id, product_id),
    KEY idx_transfer_items_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    adjustment_number VARCHAR(50) NOT NULL,
    adjustment_date DATETIME NOT NULL,
    adjustment_type ENUM('IN', 'OUT') NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('DRAFT', 'POSTED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_adjustments_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_adjustments_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_adjustments_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_adjustments_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_adjustments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_adjustments_company_number (company_id, adjustment_number),
    KEY idx_adjustments_date_status (company_id, adjustment_date, status, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustment_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_adjustment_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(19,4) NOT NULL,
    unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adjustment_items_adjustment FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    CONSTRAINT fk_adjustment_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_adjustment_items_quantity CHECK (quantity > 0 AND unit_cost >= 0),
    UNIQUE KEY uq_adjustment_items_product (stock_adjustment_id, product_id),
    KEY idx_adjustment_items_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('PURCHASE', 'SALE', 'RETURN', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'TRANSFER_IN', 'TRANSFER_OUT') NOT NULL,
    quantity DECIMAL(19,4) NOT NULL,
    quantity_before DECIMAL(19,4) NOT NULL,
    quantity_after DECIMAL(19,4) NOT NULL,
    unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0,
    reference_type VARCHAR(40) NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    notes VARCHAR(500) NULL,
    movement_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_movements_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_movements_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_movements_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_movements_values CHECK (quantity > 0 AND quantity_before >= 0 AND quantity_after >= 0 AND unit_cost >= 0),
    KEY idx_movements_product_date (product_id, warehouse_id, movement_at),
    KEY idx_movements_warehouse_date (warehouse_id, movement_at, movement_type),
    KEY idx_movements_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE pos_registers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_pos_registers_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_pos_registers_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_pos_registers_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_pos_registers_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_pos_registers_company_code (company_id, code)
) ENGINE=InnoDB;

CREATE TABLE pos_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pos_register_id BIGINT UNSIGNED NOT NULL,
    cashier_id BIGINT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL,
    opening_cash DECIMAL(19,4) NOT NULL DEFAULT 0,
    closed_at DATETIME NULL,
    closing_cash DECIMAL(19,4) NULL,
    expected_cash DECIMAL(19,4) NULL,
    cash_difference DECIMAL(19,4) NULL,
    status ENUM('OPEN', 'CLOSED') NOT NULL DEFAULT 'OPEN',
    opening_notes VARCHAR(500) NULL,
    closing_notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pos_sessions_register FOREIGN KEY (pos_register_id) REFERENCES pos_registers(id),
    CONSTRAINT fk_pos_sessions_cashier FOREIGN KEY (cashier_id) REFERENCES users(id),
    CONSTRAINT chk_pos_sessions_cash CHECK (opening_cash >= 0 AND (closing_cash IS NULL OR closing_cash >= 0)),
    KEY idx_pos_sessions_cashier (cashier_id, opened_at, status),
    KEY idx_pos_sessions_register (pos_register_id, status)
) ENGINE=InnoDB;

ALTER TABLE sales ADD COLUMN pos_session_id BIGINT UNSIGNED NULL AFTER salesperson_id,
    ADD CONSTRAINT fk_sales_pos_session FOREIGN KEY (pos_session_id) REFERENCES pos_sessions(id),
    ADD KEY idx_sales_pos_session (pos_session_id, sale_date);

CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(30) NOT NULL,
    description VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_departments_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id),
    CONSTRAINT fk_departments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_departments_company_code (company_id, code),
    KEY idx_departments_parent (parent_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    employee_number VARCHAR(40) NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    position VARCHAR(120) NOT NULL,
    hire_date DATE NOT NULL,
    base_salary DECIMAL(19,4) NOT NULL DEFAULT 0,
    currency_code CHAR(3) NOT NULL DEFAULT 'ETB',
    status ENUM('ACTIVE', 'ON_LEAVE', 'SUSPENDED', 'TERMINATED') NOT NULL DEFAULT 'ACTIVE',
    photo_path VARCHAR(255) NULL,
    emergency_contact_name VARCHAR(190) NULL,
    emergency_contact_phone VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_employees_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_employees_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_employees_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_employees_salary CHECK (base_salary >= 0),
    UNIQUE KEY uq_employees_company_number (company_id, employee_number),
    UNIQUE KEY uq_employees_user (user_id),
    KEY idx_employees_department_status (department_id, status, deleted_at),
    KEY idx_employees_name (company_id, full_name)
) ENGINE=InnoDB;

CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    check_in DATETIME NULL,
    check_out DATETIME NULL,
    status ENUM('PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'ON_LEAVE', 'HOLIDAY') NOT NULL,
    notes VARCHAR(500) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT chk_attendance_times CHECK (check_out IS NULL OR check_in IS NULL OR check_out >= check_in),
    UNIQUE KEY uq_attendance_employee_date (employee_id, attendance_date),
    KEY idx_attendance_company_date (company_id, attendance_date, status)
) ENGINE=InnoDB;

CREATE TABLE leave_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    days_per_year DECIMAL(6,2) NOT NULL DEFAULT 0,
    is_paid BOOLEAN NOT NULL DEFAULT TRUE,
    requires_approval BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_leave_types_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_leave_types_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_leave_types_days CHECK (days_per_year >= 0),
    UNIQUE KEY uq_leave_types_company_name (company_id, name)
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    total_days DECIMAL(6,2) NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('PENDING', 'APPROVED', 'REJECTED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_leave_requests_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_leave_requests_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_leave_requests_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
    CONSTRAINT fk_leave_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT fk_leave_requests_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_leave_requests_dates CHECK (end_date >= start_date AND total_days > 0),
    KEY idx_leave_requests_employee (employee_id, start_date, status),
    KEY idx_leave_requests_company_status (company_id, status, start_date)
) ENGINE=InnoDB;

CREATE TABLE payroll_components (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    component_type ENUM('ALLOWANCE', 'OVERTIME', 'BONUS', 'DEDUCTION', 'TAX') NOT NULL,
    calculation_type ENUM('FIXED', 'PERCENTAGE') NOT NULL,
    default_value DECIMAL(19,4) NOT NULL DEFAULT 0,
    percentage_base ENUM('BASIC', 'GROSS') NULL,
    chart_account_id BIGINT UNSIGNED NULL,
    is_taxable BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_payroll_components_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_payroll_components_account FOREIGN KEY (chart_account_id) REFERENCES chart_of_accounts(id),
    CONSTRAINT fk_payroll_components_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_payroll_components_value CHECK (default_value >= 0),
    UNIQUE KEY uq_payroll_components_company_name (company_id, name)
) ENGINE=InnoDB;

CREATE TABLE payroll_component_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_component_id BIGINT UNSIGNED NOT NULL,
    lower_limit DECIMAL(19,4) NOT NULL DEFAULT 0,
    upper_limit DECIMAL(19,4) NULL,
    fixed_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    rate DECIMAL(9,6) NOT NULL DEFAULT 0,
    subtract_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_rules_component FOREIGN KEY (payroll_component_id) REFERENCES payroll_components(id) ON DELETE CASCADE,
    CONSTRAINT chk_payroll_rules_limits CHECK (lower_limit >= 0 AND (upper_limit IS NULL OR upper_limit > lower_limit)),
    CONSTRAINT chk_payroll_rules_values CHECK (fixed_amount >= 0 AND rate BETWEEN 0 AND 100 AND subtract_amount >= 0),
    UNIQUE KEY uq_payroll_rules_range (payroll_component_id, lower_limit),
    KEY idx_payroll_rules_order (payroll_component_id, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE payroll (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    payroll_month DATE NOT NULL,
    basic_salary DECIMAL(19,4) NOT NULL,
    total_allowances DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_overtime DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_bonuses DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(19,4) NOT NULL DEFAULT 0,
    total_tax DECIMAL(19,4) NOT NULL DEFAULT 0,
    gross_salary DECIMAL(19,4) NOT NULL,
    net_salary DECIMAL(19,4) NOT NULL,
    status ENUM('DRAFT', 'APPROVED', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    payment_id BIGINT UNSIGNED NULL,
    journal_entry_id BIGINT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_payroll_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_payroll_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_payroll_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_payroll_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    CONSTRAINT fk_payroll_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_payroll_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_payroll_amounts CHECK (basic_salary >= 0 AND total_allowances >= 0 AND total_overtime >= 0 AND total_bonuses >= 0 AND total_deductions >= 0 AND total_tax >= 0 AND gross_salary >= 0 AND net_salary >= 0),
    UNIQUE KEY uq_payroll_employee_month (employee_id, payroll_month),
    KEY idx_payroll_company_month (company_id, payroll_month, status, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE payroll_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_id BIGINT UNSIGNED NOT NULL,
    payroll_component_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    component_type ENUM('ALLOWANCE', 'OVERTIME', 'BONUS', 'DEDUCTION', 'TAX') NOT NULL,
    amount DECIMAL(19,4) NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_items_payroll FOREIGN KEY (payroll_id) REFERENCES payroll(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_items_component FOREIGN KEY (payroll_component_id) REFERENCES payroll_components(id),
    CONSTRAINT chk_payroll_items_amount CHECK (amount >= 0),
    KEY idx_payroll_items_component (payroll_component_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    module VARCHAR(60) NOT NULL,
    record_type VARCHAR(100) NULL,
    record_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    request_id CHAR(36) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_audit_company_date (company_id, created_at),
    KEY idx_audit_user_date (user_id, created_at),
    KEY idx_audit_record (record_type, record_id),
    KEY idx_audit_action (module, action, created_at)
) ENGINE=InnoDB;

CREATE TABLE backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    status ENUM('CREATING', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'CREATING',
    failure_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by BIGINT UNSIGNED NULL,
    CONSTRAINT fk_backups_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_backups_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_backups_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_backups_filename (filename),
    KEY idx_backups_company_date (company_id, created_at, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    data JSON NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    CONSTRAINT fk_notifications_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_notifications_unread (user_id, read_at, created_at),
    KEY idx_notifications_company (company_id, created_at)
) ENGINE=InnoDB;

INSERT INTO companies (id, name, currency_code, timezone, date_format)
VALUES (1, 'Karoor ERP', 'ETB', 'Africa/Addis_Ababa', 'Y-m-d');

INSERT INTO branches (id, company_id, code, name, is_head_office)
VALUES (1, 1, 'HO', 'Head Office', TRUE);

INSERT INTO warehouses (id, company_id, branch_id, code, name, is_default)
VALUES (1, 1, 1, 'MAIN', 'Main Warehouse', TRUE);

INSERT INTO permissions (name, module, description) VALUES
('dashboard.view', 'dashboard', 'View the business dashboard'),
('pos.use', 'pos', 'Use point of sale'),
('pos.open', 'pos', 'Open a cash session'),
('pos.close', 'pos', 'Close a cash session'),
('sales.view', 'sales', 'View all sales'),
('sales.view_own', 'sales', 'View sales created by the current user'),
('sales.create', 'sales', 'Create sales'),
('sales.edit', 'sales', 'Edit sales'),
('sales.delete', 'sales', 'Archive sales'),
('sales.refund', 'sales', 'Refund completed sales'),
('sales.print', 'sales', 'Print invoices and receipts'),
('purchases.view', 'purchases', 'View purchases'),
('purchases.create', 'purchases', 'Create purchases'),
('purchases.edit', 'purchases', 'Edit purchases'),
('purchases.delete', 'purchases', 'Archive purchases'),
('products.view', 'inventory', 'View products'),
('inventory.view', 'inventory', 'View inventory'),
('inventory.create', 'inventory', 'Create products and inventory records'),
('inventory.edit', 'inventory', 'Edit products and inventory records'),
('inventory.delete', 'inventory', 'Archive products and inventory records'),
('inventory.adjust', 'inventory', 'Post stock adjustments'),
('inventory.transfer', 'inventory', 'Create stock transfers'),
('inventory.transfer_approve', 'inventory', 'Approve and complete stock transfers'),
('customers.view', 'parties', 'View customers'),
('customers.create', 'parties', 'Create customers'),
('customers.edit', 'parties', 'Edit customers'),
('customers.delete', 'parties', 'Archive customers'),
('suppliers.view', 'parties', 'View suppliers'),
('suppliers.create', 'parties', 'Create suppliers'),
('suppliers.edit', 'parties', 'Edit suppliers'),
('suppliers.delete', 'parties', 'Archive suppliers'),
('finance.view', 'finance', 'View accounting data'),
('finance.create', 'finance', 'Create accounting transactions'),
('finance.edit', 'finance', 'Edit draft accounting transactions'),
('finance.delete', 'finance', 'Archive financial records'),
('finance.post', 'finance', 'Post journal entries'),
('finance.reports', 'finance', 'View financial reports'),
('expenses.view', 'expenses', 'View expenses'),
('expenses.create', 'expenses', 'Create expenses'),
('expenses.edit', 'expenses', 'Edit expenses'),
('expenses.delete', 'expenses', 'Archive expenses'),
('hr.view', 'hrm', 'View HR information'),
('hr.employee', 'hrm', 'Manage employees and departments'),
('hr.attendance', 'hrm', 'Manage attendance'),
('hr.leave', 'hrm', 'Manage leave requests'),
('hr.payroll', 'hrm', 'Manage payroll and salary slips'),
('reports.view', 'reports', 'View operational reports'),
('reports.export', 'reports', 'Export and print reports'),
('users.manage', 'system', 'Manage users'),
('roles.manage', 'system', 'Manage roles and permissions'),
('settings.manage', 'system', 'Manage company and application settings'),
('backups.manage', 'system', 'Create, download, and delete backups'),
('audit.view', 'system', 'View audit logs'),
('recycle_bin.manage', 'system', 'Restore or permanently delete archived records');

INSERT INTO roles (id, company_id, name, slug, description, is_system) VALUES
(1, 1, 'Administrator', 'administrator', 'Full unrestricted access', TRUE),
(2, 1, 'Store Manager', 'store-manager', 'Manages day-to-day sales, purchases, and stock operations', TRUE),
(3, 1, 'Salesperson', 'salesperson', 'Creates sales and manages customer-facing activity', TRUE),
(4, 1, 'Cashier', 'cashier', 'Uses POS and manages assigned cash sessions', TRUE),
(5, 1, 'Accountant', 'accountant', 'Manages accounting, expenses, and financial reporting', TRUE),
(6, 1, 'HR Manager', 'hr-manager', 'Manages employees, attendance, leave, and payroll', TRUE),
(7, 1, 'Inventory Manager', 'inventory-manager', 'Manages products, warehouses, stock, and suppliers', TRUE);

-- The bootstrap administrator password is "ChangeMe@123!". The account is forced to
-- change it at first login. The bcrypt value is compatible with password_verify().
INSERT INTO users (
    id, company_id, branch_id, default_warehouse_id, username, email,
    password_hash, full_name, force_password_change
) VALUES (
    1, 1, 1, 1, 'admin', 'admin@karoor.local',
    '$2y$12$wBo1wvELfPfhcgFfNGEjwePp4Di4BsLK9iANjgm2ZyrbaF045QwD2',
    'System Administrator', TRUE
);

INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (1, 1, 1);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 1, id, 1 FROM permissions;

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 2, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'pos.use', 'pos.open', 'pos.close',
    'sales.view', 'sales.create', 'sales.edit', 'sales.refund', 'sales.print',
    'purchases.view', 'purchases.create', 'purchases.edit',
    'products.view', 'inventory.view', 'inventory.create', 'inventory.edit',
    'inventory.adjust', 'inventory.transfer', 'inventory.transfer_approve',
    'customers.view', 'customers.create', 'customers.edit',
    'suppliers.view', 'suppliers.create', 'suppliers.edit',
    'expenses.view', 'expenses.create', 'expenses.edit',
    'reports.view', 'reports.export'
);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 3, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'products.view', 'sales.view_own', 'sales.create', 'sales.print',
    'customers.view', 'customers.create'
);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 4, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'pos.use', 'pos.open', 'pos.close', 'products.view',
    'sales.view_own', 'sales.create', 'sales.print', 'customers.view'
);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 5, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'finance.view', 'finance.create', 'finance.edit',
    'finance.post', 'finance.reports', 'expenses.view', 'expenses.create',
    'expenses.edit', 'reports.view', 'reports.export', 'customers.view', 'suppliers.view'
);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 6, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'hr.view', 'hr.employee', 'hr.attendance', 'hr.leave',
    'hr.payroll', 'reports.view', 'reports.export'
);

INSERT INTO role_permissions (role_id, permission_id, granted_by)
SELECT 7, id, 1 FROM permissions WHERE name IN (
    'dashboard.view', 'products.view', 'inventory.view', 'inventory.create',
    'inventory.edit', 'inventory.adjust', 'inventory.transfer',
    'inventory.transfer_approve', 'purchases.view', 'purchases.create',
    'purchases.edit', 'suppliers.view', 'suppliers.create', 'suppliers.edit',
    'reports.view', 'reports.export'
);

INSERT INTO taxes (id, company_id, name, rate, is_inclusive)
VALUES (1, 1, 'No Tax', 0, FALSE);

INSERT INTO categories (id, company_id, parent_id, name, slug, sort_order)
VALUES
(1, 1, NULL, 'Products', 'products', 10),
(2, 1, NULL, 'Services', 'services', 20);

INSERT INTO units (id, company_id, name, short_name, precision_scale) VALUES
(1, 1, 'Piece', 'pc', 0),
(2, 1, 'Kilogram', 'kg', 3),
(3, 1, 'Litre', 'L', 3),
(4, 1, 'Service', 'svc', 2);

INSERT INTO chart_of_accounts (
    id, company_id, parent_id, code, name, account_type, account_subtype,
    normal_balance, is_control_account, allow_manual_entries, is_system
) VALUES
(1, 1, NULL, '1000', 'Assets', 'ASSET', 'HEADER', 'DEBIT', TRUE, FALSE, TRUE),
(2, 1, 1, '1100', 'Cash and Cash Equivalents', 'ASSET', 'CURRENT_ASSET', 'DEBIT', TRUE, FALSE, TRUE),
(3, 1, 2, '1110', 'Cash on Hand', 'ASSET', 'CASH', 'DEBIT', FALSE, TRUE, TRUE),
(4, 1, 2, '1120', 'Bank Accounts', 'ASSET', 'BANK', 'DEBIT', TRUE, FALSE, TRUE),
(5, 1, 1, '1200', 'Accounts Receivable', 'ASSET', 'ACCOUNTS_RECEIVABLE', 'DEBIT', TRUE, FALSE, TRUE),
(6, 1, 1, '1300', 'Inventory', 'ASSET', 'INVENTORY', 'DEBIT', TRUE, FALSE, TRUE),
(7, 1, 1, '1400', 'Input Tax', 'ASSET', 'TAX_RECEIVABLE', 'DEBIT', TRUE, FALSE, TRUE),
(8, 1, NULL, '2000', 'Liabilities', 'LIABILITY', 'HEADER', 'CREDIT', TRUE, FALSE, TRUE),
(9, 1, 8, '2100', 'Accounts Payable', 'LIABILITY', 'ACCOUNTS_PAYABLE', 'CREDIT', TRUE, FALSE, TRUE),
(10, 1, 8, '2200', 'Output Tax Payable', 'LIABILITY', 'TAX_PAYABLE', 'CREDIT', TRUE, FALSE, TRUE),
(11, 1, 8, '2300', 'Payroll Payable', 'LIABILITY', 'PAYROLL_PAYABLE', 'CREDIT', TRUE, FALSE, TRUE),
(12, 1, NULL, '3000', 'Equity', 'EQUITY', 'HEADER', 'CREDIT', TRUE, FALSE, TRUE),
(13, 1, 12, '3100', 'Owner Equity', 'EQUITY', 'OWNER_EQUITY', 'CREDIT', FALSE, TRUE, TRUE),
(14, 1, 12, '3200', 'Retained Earnings', 'EQUITY', 'RETAINED_EARNINGS', 'CREDIT', TRUE, FALSE, TRUE),
(15, 1, NULL, '4000', 'Revenue', 'REVENUE', 'HEADER', 'CREDIT', TRUE, FALSE, TRUE),
(16, 1, 15, '4100', 'Sales Revenue', 'REVENUE', 'SALES', 'CREDIT', FALSE, TRUE, TRUE),
(17, 1, 15, '4200', 'Service Revenue', 'REVENUE', 'SERVICES', 'CREDIT', FALSE, TRUE, TRUE),
(18, 1, 15, '4900', 'Sales Returns and Discounts', 'REVENUE', 'CONTRA_REVENUE', 'DEBIT', FALSE, TRUE, TRUE),
(19, 1, NULL, '5000', 'Cost of Sales', 'EXPENSE', 'HEADER', 'DEBIT', TRUE, FALSE, TRUE),
(20, 1, 19, '5100', 'Cost of Goods Sold', 'EXPENSE', 'COST_OF_GOODS_SOLD', 'DEBIT', FALSE, TRUE, TRUE),
(21, 1, NULL, '6000', 'Operating Expenses', 'EXPENSE', 'HEADER', 'DEBIT', TRUE, FALSE, TRUE),
(22, 1, 21, '6100', 'General and Administrative Expense', 'EXPENSE', 'GENERAL_EXPENSE', 'DEBIT', FALSE, TRUE, TRUE),
(23, 1, 21, '6200', 'Salary and Wage Expense', 'EXPENSE', 'PAYROLL_EXPENSE', 'DEBIT', FALSE, TRUE, TRUE),
(24, 1, 21, '6300', 'Rent Expense', 'EXPENSE', 'RENT', 'DEBIT', FALSE, TRUE, TRUE),
(25, 1, 21, '6400', 'Utilities Expense', 'EXPENSE', 'UTILITIES', 'DEBIT', FALSE, TRUE, TRUE);

INSERT INTO accounts (id, company_id, chart_account_id, name, account_kind, currency_code, is_default)
VALUES (1, 1, 3, 'Main Cash', 'CASH', 'ETB', TRUE);

INSERT INTO expense_categories (id, company_id, chart_account_id, name) VALUES
(1, 1, 22, 'General'),
(2, 1, 24, 'Rent'),
(3, 1, 25, 'Utilities');

INSERT INTO pos_registers (id, company_id, branch_id, warehouse_id, name, code)
VALUES (1, 1, 1, 1, 'Main Register', 'POS-01');

INSERT INTO document_sequences (company_id, branch_id, document_type, prefix, next_number, padding, reset_period) VALUES
(1, 1, 'SALE', 'INV-', 1, 6, 'YEARLY'),
(1, 1, 'PURCHASE', 'PUR-', 1, 6, 'YEARLY'),
(1, 1, 'PAYMENT', 'PAY-', 1, 6, 'YEARLY'),
(1, 1, 'EXPENSE', 'EXP-', 1, 6, 'YEARLY'),
(1, 1, 'TRANSFER', 'TRF-', 1, 6, 'YEARLY'),
(1, 1, 'ADJUSTMENT', 'ADJ-', 1, 6, 'YEARLY'),
(1, 1, 'JOURNAL', 'JV-', 1, 6, 'YEARLY'),
(1, 1, 'CUSTOMER', 'CUS-', 1, 5, 'NEVER'),
(1, 1, 'SUPPLIER', 'SUP-', 1, 5, 'NEVER'),
(1, 1, 'EMPLOYEE', 'EMP-', 1, 5, 'NEVER');

INSERT INTO settings (company_id, setting_group, setting_key, setting_value, value_type, is_public) VALUES
(1, 'company', 'company.name', 'Karoor ERP', 'STRING', TRUE),
(1, 'regional', 'currency.code', 'ETB', 'STRING', TRUE),
(1, 'regional', 'currency.symbol', 'Br', 'STRING', TRUE),
(1, 'regional', 'timezone', 'Africa/Addis_Ababa', 'STRING', TRUE),
(1, 'regional', 'date.format', 'Y-m-d', 'STRING', TRUE),
(1, 'tax', 'tax.default_id', '1', 'INTEGER', FALSE),
(1, 'invoice', 'invoice.payment_terms_days', '0', 'INTEGER', FALSE),
(1, 'invoice', 'invoice.footer', 'Thank you for your business.', 'STRING', TRUE),
(1, 'receipt', 'receipt.paper_width', '80', 'INTEGER', TRUE),
(1, 'receipt', 'receipt.show_logo', '1', 'BOOLEAN', TRUE),
(1, 'pos', 'pos.allow_negative_stock', '0', 'BOOLEAN', FALSE),
(1, 'pos', 'pos.default_customer_required', '0', 'BOOLEAN', FALSE),
(1, 'inventory', 'inventory.costing_method', 'WEIGHTED_AVERAGE', 'STRING', FALSE),
(1, 'payroll', 'payroll.default_payment_account_id', '1', 'INTEGER', FALSE),
(1, 'security', 'security.session_idle_minutes', '30', 'INTEGER', FALSE),
(1, 'system', 'system.records_per_page', '25', 'INTEGER', TRUE);

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
