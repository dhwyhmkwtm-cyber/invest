-- 自宅・ローカル用のテスト受信箱 (aws.mode = 'local' のときだけ使う)
CREATE TABLE IF NOT EXISTS local_mailbox (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id     VARCHAR(100) NOT NULL,
  to_email       VARCHAR(254) NOT NULL,
  from_email     VARCHAR(254) NOT NULL,
  subject        VARCHAR(500) NULL,
  raw            MEDIUMTEXT NOT NULL,
  is_campaign    TINYINT(1) NOT NULL DEFAULT 0,  -- 0 = 管理者への通知
  pending_events VARCHAR(200) NULL,              -- 次の cron で発生させる SES イベント (カンマ区切り)
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_local_msgid (message_id),
  KEY idx_local_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
