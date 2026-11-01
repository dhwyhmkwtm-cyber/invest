-- 営業メール配信ツール スキーマ (MySQL 5.7+/8.0, MariaDB 10.3+)
-- 文字コードは utf8mb4、時刻はすべて日本時間 (Asia/Tokyo) の DATETIME で保存する。

CREATE TABLE IF NOT EXISTS companies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  url           VARCHAR(512) NULL,
  domain        VARCHAR(255) NULL,
  address       VARCHAR(512) NULL,
  industry      VARCHAR(100) NULL,
  region        VARCHAR(100) NULL,
  size          VARCHAR(50)  NULL,
  tags          VARCHAR(512) NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  KEY idx_companies_domain (domain),
  KEY idx_companies_name (name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    INT UNSIGNED NOT NULL,
  email         VARCHAR(254) NOT NULL,
  email_domain  VARCHAR(255) NOT NULL,
  person_name   VARCHAR(100) NULL,
  is_role       TINYINT(1) NOT NULL DEFAULT 0,      -- info@ 等の窓口アドレスか
  origin        VARCHAR(20) NOT NULL DEFAULT 'own', -- own=自社収集 / agent=収集エージェント / other
  source_url    VARCHAR(1024) NULL,                 -- 取得元URL (無いと送信不可)
  acquired_at   DATETIME NULL,                      -- 取得日時 (無いと送信不可)
  no_ad_flag    TINYINT(1) NOT NULL DEFAULT 0,      -- 公表箇所付近に「広告メールお断り」等の表示あり
  mx_status     VARCHAR(10) NOT NULL DEFAULT 'unchecked', -- unchecked / ok / ng
  mx_checked_at DATETIME NULL,
  status        VARCHAR(20) NOT NULL DEFAULT 'unsent',
                -- unsent / sent / opened / clicked / replied / bounced / complained / unsubscribed
  last_sent_at  DATETIME NULL,
  import_batch  VARCHAR(40) NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY uq_contacts_email (email),
  KEY idx_contacts_company (company_id),
  KEY idx_contacts_domain (email_domain),
  KEY idx_contacts_status (status),
  KEY idx_contacts_mx (mx_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- グローバル抑止リスト。全キャンペーン共通で、送信直前に必ず照合する。
CREATE TABLE IF NOT EXISTS suppressions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(10) NOT NULL DEFAULT 'email', -- email / domain
  value       VARCHAR(254) NOT NULL,
  reason      VARCHAR(20) NOT NULL,
              -- unsubscribe / complaint / hard_bounce / no_ad / reply_refusal / manual
  note        VARCHAR(512) NULL,
  source      VARCHAR(50) NULL,                     -- 登録経路 (sns, unsubscribe_page, one_click, admin, import ...)
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_suppressions (type, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS campaigns (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(200) NOT NULL,
  status            VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft / active / paused / completed
  start_date        DATE NULL,
  daily_limit       INT UNSIGNED NOT NULL DEFAULT 200,
  send_window_start TIME NOT NULL DEFAULT '09:00:00',
  send_window_end   TIME NOT NULL DEFAULT '17:00:00',
  send_days         VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5', -- ISO曜日 (1=月 ... 7=日)
  followup_enabled  TINYINT(1) NOT NULL DEFAULT 0,
  followup_days     INT UNSIGNED NOT NULL DEFAULT 7,          -- 初回送信から何営業日後以降に追いメール
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  step        TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1=初回 / 2=追いメール
  variant     VARCHAR(20) NOT NULL DEFAULT 'A',     -- A/Bパターン名
  subject     VARCHAR(255) NOT NULL,
  body_text   MEDIUMTEXT NOT NULL,
  body_html   MEDIUMTEXT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY idx_templates_campaign (campaign_id, step, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS send_queue (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id    INT UNSIGNED NOT NULL,
  contact_id     INT UNSIGNED NOT NULL,
  template_id    INT UNSIGNED NOT NULL,
  step           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  seq            BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- 送信順 (同一ドメインが連続しないよう分散)
  scheduled_at   DATETIME NOT NULL,
  status         VARCHAR(20) NOT NULL DEFAULT 'queued',
                 -- queued / retry / sending / sent / failed / cancelled / unknown
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lock_token     CHAR(32) NULL,
  locked_at      DATETIME NULL,
  ses_message_id VARCHAR(100) NULL,
  last_error     VARCHAR(1000) NULL,
  sent_at        DATETIME NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  UNIQUE KEY uq_queue_once (campaign_id, contact_id, step),  -- 1キャンペーン×1宛先×1ステップ = 1通
  KEY idx_queue_pick (campaign_id, status, scheduled_at, seq),
  KEY idx_queue_contact (contact_id),
  KEY idx_queue_msgid (ses_message_id),
  KEY idx_queue_token (lock_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 全送信試行の記録 (最低1年保存)
CREATE TABLE IF NOT EXISTS send_log (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_id       INT UNSIGNED NOT NULL,
  campaign_id    INT UNSIGNED NOT NULL,
  contact_id     INT UNSIGNED NOT NULL,
  template_id    INT UNSIGNED NOT NULL,
  email          VARCHAR(254) NOT NULL,
  attempt        TINYINT UNSIGNED NOT NULL,
  ses_message_id VARCHAR(100) NULL,
  result         VARCHAR(20) NOT NULL, -- sent / retry / failed / unknown / skipped
  error          VARCHAR(1000) NULL,
  created_at     DATETIME NOT NULL,
  KEY idx_log_created (created_at, result),
  KEY idx_log_campaign (campaign_id, created_at),
  KEY idx_log_msgid (ses_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ses_message_id  VARCHAR(100) NULL,
  queue_id        INT UNSIGNED NULL,
  contact_id      INT UNSIGNED NULL,
  campaign_id     INT UNSIGNED NULL,
  type            VARCHAR(30) NOT NULL,
                  -- send / delivery / bounce_hard / bounce_soft / complaint / open / click /
                  -- reject / delay / unsubscribe / reply / reply_refusal
  occurred_at     DATETIME NOT NULL,
  detail          TEXT NULL,
  dedupe_key      VARCHAR(150) NULL,   -- SNS MessageId 等 (重複受信の除外)
  created_at      DATETIME NOT NULL,
  UNIQUE KEY uq_events_dedupe (dedupe_key),
  KEY idx_events_type_time (type, occurred_at),
  KEY idx_events_msgid (ses_message_id),
  KEY idx_events_campaign (campaign_id, type),
  KEY idx_events_queue (queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64) PRIMARY KEY,
  value      TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  username   VARCHAR(50) NULL,
  success    TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_login_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor      VARCHAR(50) NOT NULL,
  action     VARCHAR(50) NOT NULL,
  detail     TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
