<?php
// ============================================================
// 設定ファイルのひな形。config.php にコピーして値を埋めること。
// このディレクトリは必ず公開ディレクトリ (web/) の外に置く。
// config.php は git にコミットしない (.gitignore 済み)。
// ============================================================
return [
    // 'staging' (テスト環境) / 'production' (本番)。
    // staging では sending.allowed_recipients に一致する宛先にしか送らない (一致しない宛先は取消)。
    'environment' => 'staging',

    'timezone' => 'Asia/Tokyo',

    'db' => [
        // ロリポップの「サーバーの管理・設定 > データベース」に表示される値
        'dsn'      => 'mysql:host=mysql000.phy.lolipop.lan;dbname=LAA0000000-mailer;charset=utf8mb4',
        'user'     => 'LAA0000000',
        'password' => 'CHANGE_ME',
    ],

    // 送信者情報。メール末尾の法定表示 (特定電子メール法) に使う。
    // 1つでも空欄だと送信ワーカーは送信を拒否する。
    'sender' => [
        'company_name'   => '株式会社サンプル',
        'address'        => '〒000-0000 東京都○○区○○ 1-2-3',
        'phone'          => '03-0000-0000',
        'contact_email'  => 'sales@example.co.jp',   // 苦情・問い合わせ受付先
        'from_email'     => 'info@news.example.co.jp', // SESで認証した送信専用サブドメインのアドレス
        'from_name'      => '株式会社サンプル 営業部',
        'reply_to'       => 'sales@example.co.jp',   // 営業担当が読める実在アドレス
        'privacy_url'    => 'https://www.example.co.jp/privacy/', // 任意
    ],

    // 配信停止ページ・SNS受信エンドポイントを置く公開URL (末尾スラッシュなし)
    'public_base_url' => 'https://news.example.co.jp/mailer',

    // 配信停止リンクの改ざん防止用。32文字以上のランダム文字列にする:
    //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'app_secret' => 'CHANGE_ME_TO_RANDOM_64_HEX',

    'aws' => [
        'region'            => 'ap-northeast-1',
        // 送信専用・最小権限の IAM ユーザーのキー
        'access_key_id'     => 'AKIA...',
        'secret_access_key' => 'CHANGE_ME',
        'configuration_set' => 'mailer-events',   // SESの設定セット (イベント→SNS)
        // 受け付ける SNS トピック ARN (これ以外の通知は破棄)
        'sns_topic_arns'    => [
            'arn:aws:sns:ap-northeast-1:000000000000:mailer-ses-events',
        ],
        // SNSエンドポイントにBasic認証を掛ける場合 (SNSの購読URLを https://user:pass@... にする)
        'sns_basic_auth'    => ['user' => '', 'password' => ''],
    ],

    // 送信速度・上限 (全キャンペーン合計)。キャンペーンごとの1日上限は管理画面で設定。
    'sending' => [
        // テスト環境で送ってよい宛先 ('@ドメイン' でドメイン全体、またはアドレス)。空なら一切送らない。
        'allowed_recipients' => ['@example.co.jp', '@simulator.amazonses.com'],
        'per_second'        => 5,     // SESの送信レート以下にする
        'hourly_limit'      => 600,
        'daily_limit'       => 3000,
        'max_per_run'       => 100,   // cron 1回で送る最大件数
        'run_time_budget'   => 25,    // cron 1回の最大実行秒数 (30秒以内に収める)
        'max_attempts'      => 3,     // 一時エラーの再送上限
        'retry_delay_minutes' => [30, 180, 720], // 1回目, 2回目, 3回目失敗後の待ち時間
        'require_mx_ok'     => true,  // MX確認済み(ok)の宛先だけ送る
        // 日本の祝日など、送信しない日 (YYYY-MM-DD)
        // ※毎年見直すこと (内閣府「国民の祝日」で確認)。年末年始休業も含める。
        'holidays'          => [
            '2026-11-03', '2026-11-23', '2026-12-29', '2026-12-30', '2026-12-31',
            '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-11', '2027-02-11', '2027-02-23',
            '2027-03-22', '2027-04-29', '2027-05-03', '2027-05-04', '2027-05-05', '2027-07-19',
            '2027-08-11', '2027-09-20', '2027-09-23', '2027-10-11', '2027-11-03', '2027-11-23',
        ],
        // 追いメールの条件で「開封」を反応とみなすか (開封は計測が不正確なので既定はfalse)
        'followup_skip_if_opened' => false,
    ],

    // 自動停止の閾値 (F6)
    // ウォームアップ計画 (F5 / 要件定義書 第7章)。[その段階の営業日数 (0=最終), 1日の上限]
    // 前日のバウンス率・苦情率が下の値未満のときだけ日数を進める。2日続けて超えたらキャンペーンを停止。
    'warmup' => [
        'plan' => [[3, 200], [3, 500], [4, 1000], [5, 2000], [0, 3000]],
        'max_bounce_rate'    => 0.02,   // 2%
        'max_complaint_rate' => 0.001,  // 0.1%
    ],

    'guard' => [
        'window_days'        => 7,      // 直近何日の送信を対象にするか
        'min_sample'         => 100,    // 母数がこれ未満なら判定しない
        'max_complaint_rate' => 0.001,  // 0.1%
        'max_bounce_rate'    => 0.05,   // 5%
    ],

    'admin' => [
        // 管理画面にアクセスできるIP (空配列なら制限なし。本番では必ず設定)
        'allowed_ips'     => ['203.0.113.10'],
        'session_name'    => 'mailer_admin',
        'session_lifetime'=> 7200,
    ],

    // 異常通知・日次レポートの送り先
    'notify' => [
        'admin_emails'      => ['admin@example.co.jp'],
        'slack_webhook_url' => '', // 任意
        'daily_report_hour' => 18,  // この時刻以降の最初の cron で日次レポートを送る
    ],

    // ログ・ロックファイル置き場 (公開ディレクトリ外)
    'var_dir' => __DIR__ . '/../var',
];
