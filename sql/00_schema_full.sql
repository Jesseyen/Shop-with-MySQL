-- =====================================================================
-- 台灣好物商場　完整資料表結構（第一～六階段，全新安裝用）
-- MariaDB 10.5 以上（InfinityFree 為 11.4）、InnoDB、utf8mb4
-- 匯入方式：phpMyAdmin → 選擇資料庫 → 匯入 → 選本檔
-- 本檔只用 CREATE TABLE IF NOT EXISTS，重複匯入不會覆蓋既有資料。
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------------------
-- 第一階段：會員、商品、分類、標籤、異動紀錄
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS members (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                       VARCHAR(50)  NOT NULL,
  email                      VARCHAR(191) NOT NULL,
  password_hash              VARCHAR(255) NOT NULL,
  status                     TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '0 停用、1 啟用、2 待驗證',
  is_admin                   TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = 後台人員（任一模組權限或系統管理者）',
  is_super_admin             TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = 系統管理者（全部權限）',
  member_level               ENUM('regular','silver','gold','diamond') NOT NULL DEFAULT 'regular',
  level_locked               TINYINT UNSIGNED NOT NULL DEFAULT 0,
  total_spent                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  email_verify_token_hash    CHAR(64) NULL,
  email_verify_expires_at    DATETIME NULL,
  email_verified_at          DATETIME NULL,
  reset_password_token       CHAR(64) NULL COMMENT 'SHA-256 雜湊，不存明文',
  reset_password_expires_at  DATETIME NULL,
  deletion_requested_at      DATETIME NULL,
  anonymized_at              DATETIME NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at                 DATETIME NULL,
  row_version                INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_email (email),
  UNIQUE KEY uq_members_verify_token (email_verify_token_hash),
  UNIQUE KEY uq_members_reset_token (reset_password_token),
  KEY idx_members_status (status, deleted_at),
  KEY idx_members_created (created_at),
  KEY idx_members_name (name),
  CONSTRAINT chk_members_status CHECK (status IN (0,1,2)),
  CONSTRAINT chk_members_admin  CHECK (is_admin IN (0,1) AND is_super_admin IN (0,1)),
  CONSTRAINT chk_members_spent  CHECK (total_spent >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(50) NOT NULL,
  description      TEXT NULL,
  seo_title        VARCHAR(100) NULL,
  meta_description VARCHAR(200) NULL,
  og_image         VARCHAR(255) NULL COMMENT 'uploads/site/ 下的檔名',
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(50) NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipping_rules (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(50) NOT NULL,
  fee            DECIMAL(10,2) NOT NULL,
  free_threshold DECIMAL(10,2) NULL COMMENT 'NULL = 不因金額免運',
  is_active      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipping_rules_name (name),
  CONSTRAINT chk_shipping_rules_fee CHECK (fee >= 0 AND (free_threshold IS NULL OR free_threshold >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id      INT UNSIGNED NULL,
  shipping_rule_id INT UNSIGNED NULL,
  sku              VARCHAR(50)  NOT NULL,
  name             VARCHAR(150) NOT NULL,
  description      TEXT NULL,
  price            DECIMAL(10,2) NOT NULL,
  is_active        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  allow_cvs        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = 不可超商取貨',
  brand            VARCHAR(100) NULL,
  seo_title        VARCHAR(100) NULL,
  meta_description VARCHAR(200) NULL,
  og_image_id      INT UNSIGNED NULL COMMENT 'product_images.id',
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_category (category_id),
  KEY idx_products_rule (shipping_rule_id),
  KEY idx_products_active_name (is_active, name),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
  CONSTRAINT fk_products_rule FOREIGN KEY (shipping_rule_id) REFERENCES shipping_rules (id) ON DELETE RESTRICT,
  CONSTRAINT chk_products_price  CHECK (price >= 0),
  CONSTRAINT chk_products_active CHECK (is_active IN (0,1) AND allow_cvs IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_tags (
  product_id INT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, tag_id),
  KEY idx_product_tags_tag (tag_id),
  CONSTRAINT fk_product_tags_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 規格（第六階段）：每個商品至少有一個預設規格；庫存、購物車與訂單都以規格為單位
CREATE TABLE IF NOT EXISTS product_options (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  position   TINYINT UNSIGNED NOT NULL COMMENT '1 或 2',
  name       VARCHAR(20) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_options (product_id, position),
  CONSTRAINT fk_product_options_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT chk_product_options_pos CHECK (position IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_option_values (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  option_id  INT UNSIGNED NOT NULL,
  value      VARCHAR(30) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_option_values (option_id, value),
  CONSTRAINT fk_option_values_option FOREIGN KEY (option_id) REFERENCES product_options (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id   INT UNSIGNED NOT NULL,
  variant_id   INT UNSIGNED NULL,
  file_large   VARCHAR(80) NOT NULL,
  file_thumb   VARCHAR(80) NOT NULL,
  width        SMALLINT UNSIGNED NOT NULL,
  height       SMALLINT UNSIGNED NOT NULL,
  thumb_width  SMALLINT UNSIGNED NOT NULL,
  thumb_height SMALLINT UNSIGNED NOT NULL,
  alt          VARCHAR(150) NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_images (product_id, sort_order, id),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id       INT UNSIGNED NOT NULL,
  variant_key      VARCHAR(30) NOT NULL COMMENT '預設規格為 default，其餘為「值1編號-值2編號」',
  sku              VARCHAR(50) NOT NULL,
  option_value1_id INT UNSIGNED NULL,
  option_value2_id INT UNSIGNED NULL,
  price            DECIMAL(10,2) NULL COMMENT 'NULL = 沿用商品價格',
  gtin             VARCHAR(20) NULL,
  image_id         INT UNSIGNED NULL,
  is_default       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_variants_sku (sku),
  UNIQUE KEY uq_variants_key (product_id, variant_key),
  KEY idx_variants_product (product_id, is_active),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_variants_v1 FOREIGN KEY (option_value1_id) REFERENCES product_option_values (id) ON DELETE RESTRICT,
  CONSTRAINT fk_variants_v2 FOREIGN KEY (option_value2_id) REFERENCES product_option_values (id) ON DELETE RESTRICT,
  CONSTRAINT fk_variants_image FOREIGN KEY (image_id) REFERENCES product_images (id) ON DELETE SET NULL,
  CONSTRAINT chk_variants_price CHECK (price IS NULL OR price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variant_inventory (
  variant_id  INT UNSIGNED NOT NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 0,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (variant_id),
  CONSTRAINT fk_variant_inventory FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id    INT UNSIGNED NULL,
  entity_type VARCHAR(30) NOT NULL,
  entity_id   VARCHAR(40) NOT NULL,
  action      VARCHAR(30) NOT NULL,
  before_data LONGTEXT NULL,
  after_data  LONGTEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_actor (actor_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_write_lock (
  id         TINYINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_shop_write_lock_id CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO shop_write_lock (id) VALUES (1);

-- ---------------------------------------------------------------------
-- 第二階段：購物車（以規格為單位）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS carts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id  INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carts_member (member_id),
  KEY idx_carts_updated (updated_at),
  CONSTRAINT fk_carts_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id     INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  variant_id  INT UNSIGNED NOT NULL,
  quantity    INT UNSIGNED NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_items_cart_variant (cart_id, variant_id),
  KEY idx_cart_items_product (product_id),
  KEY idx_cart_items_variant (variant_id),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT,
  CONSTRAINT chk_cart_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第三階段：會員行為事件、價格批次、分區與封存
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_behavior_events (
  event_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_time            DATETIME(6) NOT NULL,
  event_type            ENUM('view_product','add_to_cart','update_cart','remove_from_cart') NOT NULL,
  session_key           CHAR(32) NULL,
  origin                ENUM('native','legacy_view','partition_test') NOT NULL DEFAULT 'native',
  legacy_view_id        BIGINT UNSIGNED NULL,
  member_id             INT UNSIGNED NOT NULL,
  member_level_at_event ENUM('regular','silver','gold','diamond') NULL,
  product_id            INT UNSIGNED NOT NULL,
  category_id_at_event  INT UNSIGNED NULL,
  category_origin       ENUM('snapshot','backfill','unknown') NOT NULL DEFAULT 'snapshot',
  quantity_before       INT UNSIGNED NULL,
  quantity_after        INT UNSIGNED NULL,
  quantity_delta        INT NULL,
  unit_price            DECIMAL(10,2) NULL,
  amount_delta          DECIMAL(30,2) NULL,
  cart_id               INT UNSIGNED NULL,
  cart_item_id          INT UNSIGNED NULL,
  PRIMARY KEY (event_id, event_time),
  KEY idx_mbe_time (event_time),
  KEY idx_mbe_member_time (member_id, event_time),
  KEY idx_mbe_product_type_time (product_id, event_type, event_time),
  KEY idx_mbe_category_time (category_id_at_event, event_time),
  KEY idx_mbe_session (session_key),
  KEY idx_mbe_legacy (legacy_view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (event_time) (
  PARTITION p_before VALUES LESS THAN ('2026-09-01 00:00:00'),
  PARTITION p202609  VALUES LESS THAN ('2026-10-01 00:00:00'),
  PARTITION p202610  VALUES LESS THAN ('2026-11-01 00:00:00'),
  PARTITION p202611  VALUES LESS THAN ('2026-12-01 00:00:00'),
  PARTITION p202612  VALUES LESS THAN ('2027-01-01 00:00:00'),
  PARTITION p202701  VALUES LESS THAN ('2027-02-01 00:00:00'),
  PARTITION p202702  VALUES LESS THAN ('2027-03-01 00:00:00'),
  PARTITION p202703  VALUES LESS THAN ('2027-04-01 00:00:00'),
  PARTITION p202704  VALUES LESS THAN ('2027-05-01 00:00:00'),
  PARTITION p202705  VALUES LESS THAN ('2027-06-01 00:00:00'),
  PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS member_behavior_archive (
  event_id              BIGINT UNSIGNED NOT NULL,
  event_time            DATETIME(6) NOT NULL,
  event_type            ENUM('view_product','add_to_cart','update_cart','remove_from_cart') NOT NULL,
  session_key           CHAR(32) NULL,
  origin                ENUM('native','legacy_view','partition_test') NOT NULL DEFAULT 'native',
  legacy_view_id        BIGINT UNSIGNED NULL,
  member_id             INT UNSIGNED NOT NULL,
  member_level_at_event ENUM('regular','silver','gold','diamond') NULL,
  product_id            INT UNSIGNED NOT NULL,
  category_id_at_event  INT UNSIGNED NULL,
  category_origin       ENUM('snapshot','backfill','unknown') NOT NULL DEFAULT 'snapshot',
  quantity_before       INT UNSIGNED NULL,
  quantity_after        INT UNSIGNED NULL,
  quantity_delta        INT NULL,
  unit_price            DECIMAL(10,2) NULL,
  amount_delta          DECIMAL(30,2) NULL,
  cart_id               INT UNSIGNED NULL,
  cart_item_id          INT UNSIGNED NULL,
  PRIMARY KEY (event_id, event_time),
  KEY idx_mba_time (event_time),
  KEY idx_mba_member_time (member_id, event_time),
  KEY idx_mba_product_type_time (product_id, event_type, event_time),
  KEY idx_mba_category_time (category_id_at_event, event_time),
  KEY idx_mba_session (session_key),
  KEY idx_mba_legacy (legacy_view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE COLUMNS (event_time) (
  PARTITION p_before VALUES LESS THAN ('2026-09-01 00:00:00'),
  PARTITION p202609  VALUES LESS THAN ('2026-10-01 00:00:00'),
  PARTITION p202610  VALUES LESS THAN ('2026-11-01 00:00:00'),
  PARTITION p202611  VALUES LESS THAN ('2026-12-01 00:00:00'),
  PARTITION p202612  VALUES LESS THAN ('2027-01-01 00:00:00'),
  PARTITION p202701  VALUES LESS THAN ('2027-02-01 00:00:00'),
  PARTITION p202702  VALUES LESS THAN ('2027-03-01 00:00:00'),
  PARTITION p202703  VALUES LESS THAN ('2027-04-01 00:00:00'),
  PARTITION p202704  VALUES LESS THAN ('2027-05-01 00:00:00'),
  PARTITION p202705  VALUES LESS THAN ('2027-06-01 00:00:00'),
  PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS cart_price_lots (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_item_id       INT UNSIGNED NOT NULL,
  unit_price         DECIMAL(10,2) NOT NULL,
  original_quantity  INT UNSIGNED NOT NULL,
  remaining_quantity INT UNSIGNED NOT NULL,
  origin             ENUM('native','opening') NOT NULL DEFAULT 'native',
  created_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_cpl_fifo (cart_item_id, id),
  CONSTRAINT chk_cpl_remaining CHECK (remaining_quantity <= original_quantity),
  CONSTRAINT chk_cpl_original  CHECK (original_quantity > 0),
  CONSTRAINT chk_cpl_price     CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS behavior_archive_months (
  archive_month    CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  table_name       VARCHAR(64) NOT NULL,
  status           ENUM('copying','verifying','drop_ready','dropping','completed','failed') NOT NULL DEFAULT 'copying',
  cursor_source    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 線上主表、1 舊共用封存表',
  cursor_time      DATETIME(6) NULL,
  cursor_event_id  BIGINT UNSIGNED NULL,
  expected_count   INT UNSIGNED NOT NULL DEFAULT 0,
  copied_count     INT UNSIGNED NOT NULL DEFAULT 0,
  pause_requested  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  operator_id      INT UNSIGNED NULL,
  started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at     DATETIME NULL,
  last_error       TEXT NULL,
  PRIMARY KEY (archive_month),
  UNIQUE KEY uq_bam_table (table_name),
  KEY idx_bam_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_behavior_maintenance_jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type    VARCHAR(30) NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'done',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mbmj_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(64) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第四階段：設定、地址、等級、訂單與單據
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key   VARCHAR(64) NOT NULL,
  setting_value MEDIUMTEXT NOT NULL,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doc_sequences (
  doc_type CHAR(1) NOT NULL COMMENT 'S 訂單、R 售後、D 出貨、I 收件、M 訊息',
  doc_date DATE NOT NULL,
  last_no  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, doc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_addresses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   INT UNSIGNED NOT NULL,
  recipient   VARCHAR(30) NOT NULL,
  phone       VARCHAR(20) NOT NULL,
  zip         VARCHAR(6)  NOT NULL,
  city        VARCHAR(10) NOT NULL,
  district    VARCHAR(10) NOT NULL,
  address     VARCHAR(100) NOT NULL,
  is_default  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  deleted_at  DATETIME NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_addresses_member (member_id, deleted_at),
  CONSTRAINT fk_addresses_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_level_thresholds (
  level       ENUM('regular','silver','gold','diamond') NOT NULL,
  threshold   DECIMAL(12,2) NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_level_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     INT UNSIGNED NOT NULL,
  old_level     ENUM('regular','silver','gold','diamond') NOT NULL,
  new_level     ENUM('regular','silver','gold','diamond') NOT NULL,
  total_spent   DECIMAL(12,2) NOT NULL,
  reason        VARCHAR(30) NOT NULL COMMENT 'order_completed、return_completed、recalculate、manual',
  order_id      INT UNSIGNED NULL,
  after_sale_id INT UNSIGNED NULL,
  actor_id      INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mll_member (member_id, created_at),
  CONSTRAINT fk_mll_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no              VARCHAR(20) NOT NULL,
  member_id             INT UNSIGNED NOT NULL,
  status                ENUM('pending_payment','processing','shipped','completed','cancelled','refused') NOT NULL,
  checkout_token        CHAR(64) NOT NULL,
  shipping_method       ENUM('home','cvs') NOT NULL DEFAULT 'home',
  payment_method        ENUM('cod','cvs_cod','sim_card','sim_paypal') NOT NULL DEFAULT 'cod',
  payment_status        ENUM('cod_unpaid','pending','paid','failed','partially_refunded','refunded') NOT NULL DEFAULT 'cod_unpaid',
  payment_due_at        DATETIME NULL,
  paid_at               DATETIME NULL,
  ship_name             VARCHAR(30) NULL,
  ship_phone            VARCHAR(20) NULL,
  ship_zip              VARCHAR(6)  NULL,
  ship_city             VARCHAR(10) NULL,
  ship_district         VARCHAR(10) NULL,
  ship_address          VARCHAR(100) NULL,
  cvs_chain             VARCHAR(10) NULL,
  cvs_store_code        VARCHAR(10) NULL,
  cvs_store_name        VARCHAR(50) NULL,
  cvs_store_address     VARCHAR(150) NULL,
  note                  VARCHAR(200) NULL,
  subtotal              DECIMAL(12,2) NOT NULL,
  shipping_fee          DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping_rule_name    VARCHAR(50) NULL,
  cod_fee               DECIMAL(10,2) NOT NULL DEFAULT 0,
  total                 DECIMAL(12,2) NOT NULL,
  refunded_total        DECIMAL(12,2) NOT NULL DEFAULT 0,
  shipped_at            DATETIME NULL,
  completed_at          DATETIME NULL,
  completed_by          ENUM('member','admin','system') NULL,
  completed_actor_id    INT UNSIGNED NULL,
  auto_complete_paused  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_at          DATETIME NULL,
  cancel_reason         VARCHAR(200) NULL,
  refused_reason        VARCHAR(200) NULL,
  ga_purchase_pushed_at DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_no (order_no),
  UNIQUE KEY uq_orders_checkout_token (checkout_token),
  KEY idx_orders_member (member_id, created_at),
  KEY idx_orders_status (status, created_at),
  KEY idx_orders_autocomplete (status, auto_complete_paused, shipped_at),
  KEY idx_orders_payment_due (status, payment_due_at),
  KEY idx_orders_phone (ship_phone),
  CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  variant_id   INT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  sku          VARCHAR(50) NOT NULL,
  variant_text VARCHAR(70) NULL,
  category_name VARCHAR(50) NULL,
  brand        VARCHAR(100) NULL,
  unit_price   DECIMAL(10,2) NOT NULL,
  quantity     INT UNSIGNED NOT NULL,
  subtotal     DECIMAL(12,2) NOT NULL,
  returned_qty INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已退貨完成數量',
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_variant (variant_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT,
  CONSTRAINT chk_order_items_qty CHECK (quantity > 0 AND returned_qty <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      INT UNSIGNED NOT NULL,
  event_type    VARCHAR(30) NOT NULL,
  from_status   VARCHAR(20) NULL,
  to_status     VARCHAR(20) NULL,
  actor_type    ENUM('member','admin','system') NOT NULL,
  actor_id      INT UNSIGNED NULL,
  public_note   VARCHAR(500) NULL,
  internal_note VARCHAR(1000) NULL,
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_order_events_order (order_id, id),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS after_sales (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  as_no            VARCHAR(20) NOT NULL,
  order_id         INT UNSIGNED NOT NULL,
  member_id        INT UNSIGNED NOT NULL,
  type             ENUM('return','exchange') NOT NULL,
  status           ENUM('requested','in_progress','completed','rejected','withdrawn') NOT NULL DEFAULT 'requested',
  reason           ENUM('defective','not_as_described','unwanted','other') NOT NULL,
  description      VARCHAR(500) NULL,
  reject_reason    VARCHAR(200) NULL,
  ship_name        VARCHAR(30) NULL,
  ship_phone       VARCHAR(20) NULL,
  ship_zip         VARCHAR(6)  NULL,
  ship_city        VARCHAR(10) NULL,
  ship_district    VARCHAR(10) NULL,
  ship_address     VARCHAR(100) NULL,
  return_carrier   VARCHAR(20) NULL,
  return_tracking  VARCHAR(40) NULL,
  refund_estimate  DECIMAL(12,2) NULL,
  includes_shipping TINYINT UNSIGNED NOT NULL DEFAULT 0,
  refund_amount    DECIMAL(12,2) NULL,
  refund_method    VARCHAR(30) NULL,
  refund_date      DATE NULL,
  refund_note      VARCHAR(200) NULL,
  approved_at      DATETIME NULL,
  completed_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version      INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_after_sales_no (as_no),
  KEY idx_after_sales_order (order_id),
  KEY idx_after_sales_status (status, type, created_at),
  CONSTRAINT fk_after_sales_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_after_sales_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS after_sale_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  after_sale_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  quantity      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_after_sale_items (after_sale_id, order_item_id),
  CONSTRAINT fk_asi_after_sale FOREIGN KEY (after_sale_id) REFERENCES after_sales (id) ON DELETE RESTRICT,
  CONSTRAINT fk_asi_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
  CONSTRAINT chk_asi_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_no       VARCHAR(20) NOT NULL,
  order_id          INT UNSIGNED NOT NULL,
  after_sale_id     INT UNSIGNED NULL,
  type              ENUM('order','exchange') NOT NULL,
  status            ENUM('on_hold','pending','shipped','arrived','picked_up','returned','void') NOT NULL,
  shipping_method   ENUM('home','cvs') NOT NULL DEFAULT 'home',
  ship_name         VARCHAR(30) NULL,
  ship_phone        VARCHAR(20) NULL,
  ship_zip          VARCHAR(6)  NULL,
  ship_city         VARCHAR(10) NULL,
  ship_district     VARCHAR(10) NULL,
  ship_address      VARCHAR(100) NULL,
  cvs_chain         VARCHAR(10) NULL,
  cvs_store_code    VARCHAR(10) NULL,
  cvs_store_name    VARCHAR(50) NULL,
  cvs_store_address VARCHAR(150) NULL,
  carrier           VARCHAR(20) NULL,
  tracking_no       VARCHAR(40) NULL,
  shipped_at        DATETIME NULL,
  arrived_at        DATETIME NULL,
  pickup_deadline   DATE NULL,
  picked_up_at      DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version       INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipments_no (shipment_no),
  KEY idx_shipments_order (order_id),
  KEY idx_shipments_status (status, created_at),
  CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_shipments_after_sale FOREIGN KEY (after_sale_id) REFERENCES after_sales (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shipment_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id   INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  quantity      INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_shipment_items (shipment_id),
  CONSTRAINT fk_si_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE RESTRICT,
  CONSTRAINT fk_si_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
  CONSTRAINT chk_si_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_receipts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no    VARCHAR(20) NOT NULL,
  order_id      INT UNSIGNED NOT NULL,
  after_sale_id INT UNSIGNED NULL,
  type          ENUM('return','exchange','refused') NOT NULL,
  status        ENUM('pending','received') NOT NULL DEFAULT 'pending',
  received_at   DATETIME NULL,
  received_by   INT UNSIGNED NULL,
  note          VARCHAR(200) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipts_no (receipt_no),
  KEY idx_receipts_order (order_id),
  KEY idx_receipts_status (status, created_at),
  CONSTRAINT fk_receipts_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
  CONSTRAINT fk_receipts_after_sale FOREIGN KEY (after_sale_id) REFERENCES after_sales (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_receipt_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id    INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  quantity      INT UNSIGNED NOT NULL,
  item_condition ENUM('good','defective') NULL,
  restocked     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_rri_receipt (receipt_id),
  CONSTRAINT fk_rri_receipt FOREIGN KEY (receipt_id) REFERENCES return_receipts (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rri_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
  CONSTRAINT chk_rri_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第五階段：公開文件、自訂程式碼、寄信紀錄、備份、白名單、權限、訊息
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_contents (
  content_key VARCHAR(30) NOT NULL COMMENT 'operator_name、contact_info、privacy、returns、shopping_guide',
  content     MEDIUMTEXT NOT NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (content_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_code_snippets (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(50) NOT NULL,
  position       ENUM('head_start','head_end','body_start','body_end') NOT NULL,
  scope          ENUM('front','admin','both') NOT NULL DEFAULT 'front',
  load_condition ENUM('always','google_tag','analytics','ads') NOT NULL DEFAULT 'always',
  sort_order     INT NOT NULL DEFAULT 100,
  is_active      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  code           MEDIUMTEXT NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_ccs_position (is_active, position, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mail_type     VARCHAR(30) NOT NULL,
  to_email      VARCHAR(191) NOT NULL,
  subject       VARCHAR(200) NOT NULL,
  result        ENUM('success','failed','quota_blocked') NOT NULL,
  error_message VARCHAR(1000) NULL,
  member_id     INT UNSIGNED NULL,
  order_id      INT UNSIGNED NULL,
  after_sale_id INT UNSIGNED NULL,
  message_id    INT UNSIGNED NULL,
  ip            VARCHAR(45) NULL,
  visitor_triggered TINYINT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_mail_logs_created (created_at),
  KEY idx_mail_logs_ip (ip, created_at),
  KEY idx_mail_logs_email (to_email, created_at),
  KEY idx_mail_logs_type (mail_type, result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_drills (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prefix           VARCHAR(30) NOT NULL COMMENT '隔離表前綴，例如 dr_20261121_093012_a1f_',
  status           ENUM('uploaded','restoring','verified','failed','applied','dropped') NOT NULL DEFAULT 'uploaded',
  file_name        VARCHAR(100) NOT NULL,
  meta_json        MEDIUMTEXT NULL,
  schema_match     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  table_count      INT UNSIGNED NOT NULL DEFAULT 0,
  row_count        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cursor_table     VARCHAR(64) NULL,
  cursor_offset    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_offset      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  message          TEXT NULL,
  bak_prefix       VARCHAR(30) NULL COMMENT '套用後正式表改名的前綴',
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_backup_drills_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_drill_tables (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  drill_id      INT UNSIGNED NOT NULL,
  table_name    VARCHAR(64) NOT NULL,
  expected_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  actual_rows   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expected_sha  CHAR(64) NULL,
  actual_sha    CHAR(64) NULL,
  result        ENUM('pending','ok','mismatch') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (id),
  UNIQUE KEY uq_bdt (drill_id, table_name),
  CONSTRAINT fk_bdt_drill FOREIGN KEY (drill_id) REFERENCES backup_drills (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_ip_allowlist (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cidr        VARCHAR(50) NOT NULL,
  note        VARCHAR(100) NULL,
  is_active   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_type VARCHAR(30) NOT NULL COMMENT 'ip_denied、captcha_lock、mail_quota、login_lock、permission_denied',
  ip         VARCHAR(45) NULL,
  member_id  INT UNSIGNED NULL,
  path       VARCHAR(255) NULL,
  detail     VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_security_logs (created_at),
  KEY idx_security_logs_ip (ip, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_permissions (
  member_id  INT UNSIGNED NOT NULL,
  module     ENUM('product','member','order','shipping','support','report') NOT NULL,
  level      ENUM('view','edit') NOT NULL,
  granted_by INT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id, module),
  CONSTRAINT fk_admin_permissions_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_no  VARCHAR(20) NOT NULL,
  source      ENUM('contact','member') NOT NULL,
  member_id   INT UNSIGNED NULL,
  name        VARCHAR(50) NULL,
  email       VARCHAR(191) NULL,
  phone       VARCHAR(20) NULL,
  category    ENUM('product','order','return','account','other') NOT NULL,
  subject     VARCHAR(100) NULL,
  body        TEXT NULL,
  order_id    INT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  status      ENUM('new','in_progress','done') NOT NULL DEFAULT 'new',
  handler_id  INT UNSIGNED NULL,
  done_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_messages_no (message_no),
  KEY idx_support_messages_status (status, created_at),
  KEY idx_support_messages_member (member_id),
  KEY idx_support_messages_ip (ip, created_at),
  CONSTRAINT fk_support_messages_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT,
  CONSTRAINT fk_support_messages_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_message_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  event_type ENUM('create','take','status','note','reply') NOT NULL,
  content    TEXT NULL,
  from_status VARCHAR(20) NULL,
  to_status  VARCHAR(20) NULL,
  is_public  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  actor_id   INT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_sme_message (message_id, id),
  CONSTRAINT fk_sme_message FOREIGN KEY (message_id) REFERENCES support_messages (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第六階段：刪除帳號、付款、門市、錯誤紀錄、清理
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS account_deletion_requests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id    INT UNSIGNED NOT NULL,
  status       ENUM('pending','cancelled','rejected','completed') NOT NULL DEFAULT 'pending',
  reason       VARCHAR(500) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_by   INT UNSIGNED NULL,
  handled_at   DATETIME NULL,
  note         VARCHAR(200) NULL,
  row_version  INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_adr_member (member_id, status),
  KEY idx_adr_status (status, requested_at),
  CONSTRAINT fk_adr_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED NOT NULL,
  method          ENUM('sim_card','sim_paypal') NOT NULL,
  attempt_no      SMALLINT UNSIGNED NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  status          ENUM('created','succeeded','failed','cancelled','refunded') NOT NULL DEFAULT 'created',
  token_hash      CHAR(64) NOT NULL,
  refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_token (token_hash),
  UNIQUE KEY uq_payments_attempt (order_id, attempt_no),
  KEY idx_payments_status (status, created_at),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cvs_stores (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chain       ENUM('711','family','hilife','ok') NOT NULL,
  store_code  VARCHAR(10) NOT NULL,
  name        VARCHAR(50) NOT NULL,
  city        VARCHAR(10) NOT NULL,
  address     VARCHAR(150) NOT NULL,
  is_active   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cvs_stores (chain, store_code),
  KEY idx_cvs_city (chain, city, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fingerprint CHAR(64) NOT NULL,
  level       VARCHAR(20) NOT NULL,
  message     VARCHAR(2000) NOT NULL,
  file        VARCHAR(255) NULL,
  line        INT UNSIGNED NULL,
  url         VARCHAR(500) NULL,
  method      VARCHAR(10) NULL,
  member_id   INT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  trace       TEXT NULL,
  occurrences INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_error_logs_fp (fingerprint),
  KEY idx_error_logs_last (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleanup_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task          VARCHAR(30) NOT NULL,
  started_at    DATETIME NOT NULL,
  finished_at   DATETIME NULL,
  deleted_count INT UNSIGNED NOT NULL DEFAULT 0,
  result        ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  message       VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_cleanup_runs (task, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
