<?php
// ローカル体験用の初期データ: 管理者 (admin / demo-pass-2026) とデモ用キャンペーン (A/B 2パターン)。
// 何度実行しても重複しない。ローカルモード以外では実行しない。
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = mailer_app();
if ($app->config->get('aws.mode') !== 'local') {
    exit("demo_seed はローカルモード専用です\n");
}
$db = $app->db();
$now = $app->clock->nowSql();
if (!$db->value("SELECT 1 FROM admin_users WHERE username = 'admin'")) {
    $db->run('INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)', ['admin', password_hash('demo-pass-2026', PASSWORD_DEFAULT), $now]);
    echo "created admin user: admin / demo-pass-2026\n";
}
if (!$db->value('SELECT 1 FROM campaigns LIMIT 1')) {
    $cid = $db->insert('campaigns', [
        'name' => 'デモ: 石綿廃棄物用袋のご案内', 'status' => 'draft', 'daily_limit' => 200,
        'send_window_start' => '00:00:00', 'send_window_end' => '23:59:59', 'send_days' => '1,2,3,4,5,6,7',
        'followup_enabled' => 1, 'followup_days' => 7, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $templates = [
        ['A', '石綿含有廃棄物の梱包資材のご案内', "{{会社名}}\n{{担当者名|ご担当者}} 様\n\n突然のご連絡失礼いたします。デモ資材の営業部です。\n{{地域}}で{{業種}}を営まれている貴社に、石綿含有廃棄物の梱包用資材をご案内いたします。\n\n・二重梱包に対応した厚手の専用袋\n・表示ラベル付きで現場での仕分けが簡単\n\n価格表とサンプルのご依頼はこちら:\nhttps://www.example.co.jp/products/\n\nご不明な点はこのメールにご返信ください。"],
        ['B', '解体現場の石綿廃棄物、袋の破れでお困りではありませんか', "{{会社名}}\n{{担当者名|ご担当者}} 様\n\nデモ資材の営業部です。\n解体・改修現場で「袋が破れて詰め直し」「保管場所を取る」といったお声をよく伺います。\n\n当社の石綿廃棄物用袋は、破れにくい厚手素材で作業時間を減らせます。\n\n無料サンプルのお申し込み:\nhttps://www.example.co.jp/sample/\n\nご返信いただければ担当者からご連絡します。"],
    ];
    foreach ($templates as [$v, $subject, $text]) {
        $db->insert('templates', ['campaign_id' => $cid, 'step' => 1, 'variant' => $v, 'subject' => $subject, 'body_text' => $text,
            'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    $db->insert('templates', ['campaign_id' => $cid, 'step' => 2, 'variant' => 'A', 'subject' => '先日ご案内した石綿廃棄物用袋について',
        'body_text' => "{{会社名}}\n{{担当者名|ご担当者}} 様\n\n先日ご案内した石綿廃棄物用袋について、改めてご連絡いたしました。\n無料サンプルをご用意しています: https://www.example.co.jp/sample/",
        'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    echo "created demo campaign #{$cid}\n";
}
