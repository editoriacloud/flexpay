-- ============================================================================
-- FlexPay (Daraja) WHMCS Module Suite — Database Schema
-- ============================================================================
-- Auto-created by both the gateway module (flexpay_MetaData) and the
-- dashboard addon (flexpay_dashboard_activate) — this file is a manual
-- fallback only. Safe to import multiple times (all CREATE TABLE IF NOT
-- EXISTS / idempotent).
--
-- Compatible with: MySQL 5.7+, MariaDB 10.3+
-- ============================================================================

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_transactions
-- The single source of truth for EVERY Daraja-related transaction this
-- module has ever seen, regardless of channel (STK, C2B, B2C/refund,
-- reversal). Powers the dashboard's transaction list, search, and stats.
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_transactions` (
    `id`                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `channel`             ENUM('stk','c2b','b2c','reversal') NOT NULL,
    `direction`           ENUM('in','out') NOT NULL DEFAULT 'in' COMMENT 'in = customer paid us, out = we paid customer',
    `invoice_id`          INT UNSIGNED   NULL,
    `client_id`           INT UNSIGNED   NULL,
    `checkout_request_id` VARCHAR(100)   NOT NULL DEFAULT '' COMMENT 'STK only',
    `merchant_request_id` VARCHAR(100)   NOT NULL DEFAULT '' COMMENT 'STK only',
    `conversation_id`     VARCHAR(100)   NOT NULL DEFAULT '' COMMENT 'B2C/Reversal only',
    `originator_conversation_id` VARCHAR(100) NOT NULL DEFAULT '',
    `mpesa_receipt`       VARCHAR(30)    NOT NULL DEFAULT '' COMMENT 'Safaricom transaction code, e.g. NLJ7RT61SV',
    `phone`               VARCHAR(15)    NOT NULL DEFAULT '',
    `amount`               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `account_reference`   VARCHAR(50)    NOT NULL DEFAULT '',
    `status`              ENUM('pending','success','failed','reversed') NOT NULL DEFAULT 'pending',
    `result_code`         VARCHAR(20)    NOT NULL DEFAULT '',
    `result_desc`         VARCHAR(255)   NOT NULL DEFAULT '',
    `payment_outcome`     VARCHAR(20)    NULL COMMENT 'exact, partial, overpaid — observational only',
    `raw_request`         TEXT           NULL,
    `raw_response`        TEXT           NULL,
    `created_at`          DATETIME       NOT NULL,
    `updated_at`          DATETIME       NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_checkout_request_id` (`checkout_request_id`),
    UNIQUE KEY `uq_mpesa_receipt` (`mpesa_receipt`),
    KEY `idx_invoice_id`  (`invoice_id`),
    KEY `idx_client_id`   (`client_id`),
    KEY `idx_channel`     (`channel`),
    KEY `idx_status`      (`status`),
    KEY `idx_created_at`  (`created_at`),
    KEY `idx_phone`       (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master ledger of every STK/C2B/B2C/Reversal transaction';

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_unmatched_payments
-- C2B payments whose account reference didn't resolve to a WHMCS invoice.
-- The dashboard's "Reconciliation" tab lets admins manually match these.
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_unmatched_payments` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `trans_id`     VARCHAR(30)   NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL,
    `phone`        VARCHAR(15)   NOT NULL DEFAULT '',
    `bill_ref`     VARCHAR(50)   NOT NULL DEFAULT '',
    `customer_name` VARCHAR(150) NOT NULL DEFAULT '',
    `raw_data`     TEXT          NULL,
    `matched`      TINYINT(1)    NOT NULL DEFAULT 0,
    `matched_invoice_id` INT UNSIGNED NULL,
    `matched_by`   VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'admin username who reconciled it',
    `notes`        VARCHAR(255)  NOT NULL DEFAULT '',
    `created_at`   DATETIME      NOT NULL,
    `matched_at`   DATETIME      NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_trans_id` (`trans_id`),
    KEY `idx_matched`    (`matched`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='C2B payments with no matching WHMCS invoice reference';

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_refunds
-- Every B2C disbursement triggered as a WHMCS refund, tracked separately
-- from flexpay_transactions for fast dashboard "Refunds" tab queries and
-- to retain the link back to the originating invoice/transaction.
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_refunds` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `invoice_id`       INT UNSIGNED   NOT NULL,
    `original_trans_id` VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'the M-Pesa receipt being refunded',
    `conversation_id`  VARCHAR(100)   NOT NULL DEFAULT '',
    `phone`            VARCHAR(15)    NOT NULL,
    `amount`           DECIMAL(12,2)  NOT NULL,
    `status`           ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    `result_desc`      VARCHAR(255)   NOT NULL DEFAULT '',
    `initiated_by`     VARCHAR(100)   NOT NULL DEFAULT '' COMMENT 'admin username',
    `created_at`       DATETIME       NOT NULL,
    `updated_at`       DATETIME       NULL,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_id`   (`invoice_id`),
    KEY `idx_status`       (`status`),
    KEY `idx_conversation` (`conversation_id`),
    KEY `idx_created_at`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='B2C refund disbursements initiated from WHMCS';

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_balance_snapshots
-- Historical record of Account Balance Query results, so the dashboard
-- can show a balance trend rather than just "current balance".
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_balance_snapshots` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `shortcode`       VARCHAR(20)   NOT NULL,
    `working_account`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `utility_account`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `charges_account`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `raw_response`    TEXT          NULL,
    `created_at`      DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_shortcode`  (`shortcode`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical AccountBalance query snapshots for trend display';

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_api_log
-- Full request/response audit trail for EVERY Daraja API call made by
-- either module — the dashboard's "API Log" tab reads from here. Distinct
-- from WHMCS's native Gateway Log so non-payment calls (balance, reversal,
-- status queries triggered from the addon) are also captured.
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_api_log` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `operation`    VARCHAR(40)   NOT NULL COMMENT 'stk_push, c2b_register, b2c, reversal, balance, status',
    `request_data` TEXT          NULL,
    `response_data` TEXT         NULL,
    `success`      TINYINT(1)    NOT NULL DEFAULT 0,
    `triggered_by` VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'admin username or "system" for automated calls',
    `created_at`   DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_operation`  (`operation`),
    KEY `idx_success`    (`success`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Full audit trail of every Daraja API request/response';

-- ────────────────────────────────────────────────────────────────────────────
-- Table: flexpay_settings
-- Lightweight key-value store for cross-module state, e.g. "C2B URLs last
-- registered at <timestamp>" so the gateway module can auto-re-register
-- only when the domain changes, without spamming Safaricom on every load.
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `flexpay_settings` (
    `setting_key`   VARCHAR(100)  NOT NULL,
    `setting_value` TEXT          NULL,
    `updated_at`    DATETIME      NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cross-module key-value settings store (e.g. last C2B registration)';
