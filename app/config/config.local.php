<?php
// ============================================================
// 自宅・ローカル体験用の設定 (docker compose で使う)。
// aws.mode = 'local' なので、メールは外に一切出ずに「テスト受信箱」に届く。
// AWS アカウント・ドメイン・クレジットカードは不要。本番では使わないこと。
// ============================================================
$base = getenv('MAILER_PUBLIC_BASE_URL') ?: 'http://localhost:8080';
return [
    'environment' => 'local',
    'timezone' => 'Asia/Tokyo',
    'db' => [
        'dsn' => 'mysql:host=' . (getenv('MAILER_DB_HOST') ?: 'db') . ';dbname=mailer;charset=utf8mb4',
        'user' => 'mailer',
        'password' => 'mailer',
    ],
    'sender' => [
        'company_name' => '株式会社デモ資材 (テスト)',
        'address' => '〒100-0001 東京都千代田区デモ1-2-3',
        'phone' => '03-0000-0000',
        'contact_email' => 'sales@demo.example',
        'from_email' => 'info@news.demo.example',
        'from_name' => 'デモ資材 営業部',
        'reply_to' => 'sales@demo.example',
        'privacy_url' => '',
    ],
    'public_base_url' => rtrim($base, '/'),
    'app_secret' => 'local-demo-secret-0123456789abcdef0123456789abcdef', // ローカル専用
    'aws' => [
        'mode' => 'local',
        'region' => 'ap-northeast-1',
        'access_key_id' => 'local',
        'secret_access_key' => 'local',
        'configuration_set' => 'local-events',
        'sns_topic_arns' => ['local'],
    ],
    'sending' => [
        'allowed_recipients' => [],
        'per_second' => 5,
        'hourly_limit' => 600,
        'daily_limit' => 3000,
        'max_per_run' => 100,
        'run_time_budget' => 25,
        'max_attempts' => 3,
        'retry_delay_minutes' => [30, 180, 720],
        'require_mx_ok' => true,
        'holidays' => [],
        'followup_skip_if_opened' => false,
    ],
    'warmup' => [
        'plan' => [[3, 200], [3, 500], [4, 1000], [5, 2000], [0, 3000]],
        'max_bounce_rate' => 0.02,
        'max_complaint_rate' => 0.001,
    ],
    'guard' => ['window_days' => 7, 'min_sample' => 100, 'max_complaint_rate' => 0.001, 'max_bounce_rate' => 0.05],
    'admin' => ['allowed_ips' => [], 'session_name' => 'mailer_local', 'session_lifetime' => 28800],
    'notify' => ['admin_emails' => ['admin@demo.example'], 'slack_webhook_url' => '', 'daily_report_hour' => 18],
    'var_dir' => '/tmp/mailer-var',
];
