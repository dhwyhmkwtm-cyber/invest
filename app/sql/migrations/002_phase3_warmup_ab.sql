-- フェーズ3: ウォームアップ (F5) と A/B テスト (F3)
ALTER TABLE campaigns
  ADD COLUMN warmup_enabled      TINYINT(1) NOT NULL DEFAULT 1 AFTER daily_limit,
  ADD COLUMN warmup_step         TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER warmup_enabled,
  ADD COLUMN warmup_days_at_step SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER warmup_step,
  ADD COLUMN warmup_bad_days     TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER warmup_days_at_step,
  ADD COLUMN warmup_last_eval    DATE NULL AFTER warmup_bad_days,
  ADD COLUMN ab_status           VARCHAR(10) NOT NULL DEFAULT 'testing' AFTER warmup_last_eval,  -- testing / decided / off
  ADD COLUMN ab_min_per_variant  INT UNSIGNED NOT NULL DEFAULT 200 AFTER ab_status,
  ADD COLUMN ab_winner_id        INT UNSIGNED NULL AFTER ab_min_per_variant;

-- ウォームアップの日次判定の記録
CREATE TABLE IF NOT EXISTS warmup_log (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id   INT UNSIGNED NOT NULL,
  eval_date     DATE NOT NULL,          -- 判定した日
  target_date   DATE NULL,              -- 判定の材料にした送信日
  sent          INT UNSIGNED NOT NULL DEFAULT 0,
  bounces       INT UNSIGNED NOT NULL DEFAULT 0,
  complaints    INT UNSIGNED NOT NULL DEFAULT 0,
  result        VARCHAR(20) NOT NULL,   -- start / advance / stay / hold / low_volume / pause
  step_before   TINYINT UNSIGNED NOT NULL,
  step_after    TINYINT UNSIGNED NOT NULL,
  limit_after   INT UNSIGNED NOT NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL,
  UNIQUE KEY uq_warmup_eval (campaign_id, eval_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
