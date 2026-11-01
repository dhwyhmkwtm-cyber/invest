<?php
// 環境チェック: 「これが全部OKなら本番でも動く」を確認する。
//   php app/bin/doctor.php            … 全項目
//   php app/bin/doctor.php --no-net   … AWS・HTTP・DNSの確認を省く
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$noNet = in_array('--no-net', $argv, true);
$results = ['ok' => 0, 'warn' => 0, 'ng' => 0];
$line = static function (string $status, string $label, string $detail = '') use (&$results): void {
    $results[$status]++;
    $mark = ['ok' => '[ OK ]', 'warn' => '[注意]', 'ng' => '[ NG ]'][$status];
    echo "{$mark} {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
};
$section = static fn(string $t) => print("\n■ {$t}\n");

// ---------- PHP ----------
$section('PHP');
$line(PHP_VERSION_ID >= 80100 ? 'ok' : 'ng', 'PHP バージョン', PHP_VERSION . ' (8.1以上が必要)');
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json', 'zip', 'simplexml'] as $ext) {
    $line(extension_loaded($ext) ? 'ok' : ($ext === 'zip' || $ext === 'simplexml' ? 'warn' : 'ng'), "拡張 {$ext}",
        extension_loaded($ext) ? '' : ($ext === 'zip' ? 'Excel(.xlsx)取り込みに必要。CSVは使える' : '必須'));
}
$line(function_exists('checkdnsrr') ? 'ok' : 'ng', 'DNS関数 (MXチェック用)');

// ---------- 設定 ----------
$section('設定ファイル');
try {
    $app = mailer_app();
} catch (Throwable $e) {
    $line('ng', '設定ファイル', $e->getMessage());
    exit(2);
}
$cfg = $app->config;
$cfgPath = getenv('MAILER_CONFIG') ?: MAILER_ROOT . '/config/config.php';
$line('ok', '設定ファイル', $cfgPath);
$perm = fileperms($cfgPath) & 0777;
$line(($perm & 0044) === 0 ? 'ok' : 'warn', '設定ファイルの権限', sprintf('%o', $perm) . (($perm & 0044) ? ' (chmod 600 推奨)' : ''));
$env = (string)$cfg->get('environment', 'staging');
$guard = new Mailer\RecipientGuard($cfg);
if ($guard->isProduction()) {
    $line('warn', '環境', 'production (本番) — 抑止リスト外のすべての宛先に送信します');
} else {
    $allowed = (array)$cfg->get('sending.allowed_recipients', []);
    $line($allowed ? 'ok' : 'warn', '環境', "{$env} (テスト) — 送信を許可: " . ($allowed ? implode(', ', $allowed) : 'なし (一切送信しない)'));
}
$missing = Mailer\Template\TemplateRenderer::missingSenderInfo($cfg);
$line($missing ? 'ng' : 'ok', '法定表示の送信者情報', $missing ? '不足: ' . implode('、', $missing) : '');
$line(strlen((string)$cfg->get('app_secret')) >= 32 ? 'ok' : 'ng', 'app_secret (32文字以上)');
$line(str_starts_with((string)$cfg->get('public_base_url'), 'https://') ? 'ok' : ($cfg->get('aws.mode') === 'local' ? 'warn' : 'ng'), 'public_base_url が https', (string)$cfg->get('public_base_url'));
$line((array)$cfg->get('admin.allowed_ips', []) ? 'ok' : 'warn', '管理画面のIP制限', (array)$cfg->get('admin.allowed_ips', []) ? implode(', ', (array)$cfg->get('admin.allowed_ips')) : '未設定 (誰でもログイン画面を開ける)');
$line((array)$cfg->get('notify.admin_emails', []) ? 'ok' : 'warn', '異常通知の送り先', implode(', ', (array)$cfg->get('notify.admin_emails', [])));
$ph = (float)$cfg->get('sending.per_second', 5);

// ---------- DB ----------
$section('データベース');
try {
    $db = $app->db();
    $ver = (string)$db->value('SELECT VERSION()');
    $line('ok', 'DB接続', $ver);
    $tables = array_map(static fn($r) => array_values($r)[0], $db->all('SHOW TABLES'));
    $need = ['companies', 'contacts', 'suppressions', 'campaigns', 'templates', 'send_queue', 'send_log', 'events', 'settings', 'admin_users', 'login_attempts', 'audit_log'];
    $lack = array_diff($need, $tables);
    $line($lack ? 'ng' : 'ok', 'テーブル', $lack ? '不足: ' . implode(', ', $lack) . ' (php bin/migrate.php を実行)' : count($need) . '個');
    if (!$lack) {
        $admins = (int)$db->value('SELECT COUNT(*) FROM admin_users');
        $line($admins ? 'ok' : 'ng', '管理者ユーザー', $admins ? "{$admins}人" : 'なし (php bin/create_admin.php ユーザー名)');
        $last = $app->settings()->get('cron_last_run');
        $age = $last ? time() - strtotime($last) : null;
        $line($age !== null && $age < 1800 ? 'ok' : 'warn', 'cron の最終実行', $last ? "{$last} (" . intdiv($age, 60) . '分前)' : 'まだ一度も動いていない');
        $line($app->settings()->isPaused() ? 'warn' : 'ok', '配信の状態', $app->settings()->isPaused() ? '一時停止中: ' . $app->settings()->get(Mailer\Settings::PAUSE_REASON) : '稼働中');
    }
} catch (Throwable $e) {
    $line('ng', 'DB接続', $e->getMessage());
}

// ---------- ファイル ----------
$section('ファイル');
$var = $app->varDir();
$line(is_writable($var) ? 'ok' : 'ng', 'ログ・ロック用ディレクトリに書き込める', $var);
$docRoot = realpath(MAILER_ROOT);
$line(!preg_match('#/web(/|$)#', (string)$docRoot) ? 'ok' : 'warn', 'app/ が公開ディレクトリ (web/) の外にある', (string)$docRoot);

if ($cfg->get('aws.mode') === 'local') {
    $section('AWS・DNS');
    $line('ok', 'ローカルモード', 'メールは外に出ずテスト受信箱に届く。SES・DNS の確認は省略');
    goto summary;
}
if ($noNet) {
    goto summary;
}

// ---------- 公開URL ----------
$section('公開ページ (配信停止・SNS受信)');
$base = rtrim((string)$cfg->get('public_base_url'), '/');
$t = $app->transport();
$probe = static function (string $method, string $url, string $body = '') use ($t): ?int {
    try {
        return $t->request($method, $url, $body !== '' ? ['content-type' => 'application/json'] : [], $body, 10)['status'];
    } catch (Throwable) {
        return null;
    }
};
$s = $probe('GET', $base . '/u.php?test=1');
$line($s === 200 ? 'ok' : 'ng', '配信停止ページが開ける', "{$base}/u.php → " . ($s ?? '接続できない'));
$s = $probe('POST', $base . '/sns.php', '{"Type":"Notification"}');
$line(in_array($s, [401, 403], true) ? 'ok' : 'ng', 'SNS受信口が動き、署名のない通知を拒否する', "{$base}/sns.php → " . ($s ?? '接続できない') . ' (401か403が正常)');
$s = $probe('GET', $base . '/_app.php');
$line($s !== null && $s >= 400 ? 'ok' : 'ng', '_app.php が外から見えない', (string)($s ?? '接続できない'));

// ---------- SES ----------
$section('Amazon SES');
$from = (string)$cfg->get('sender.from_email');
$domain = Mailer\Util\Email::domain($from);
try {
    $acc = $app->ses()->getAccount();
    $line('ok', 'SESに接続できる (認証キーが有効)', (string)$cfg->get('aws.region'));
    $prod = !empty($acc['ProductionAccessEnabled']);
    $line($prod ? 'ok' : 'warn', '本番利用 (サンドボックス解除)', $prod ? '解除済み' : 'サンドボックス中 — 認証済みアドレスにしか届かない (テストには好都合)');
    $line(!empty($acc['SendingEnabled']) ? 'ok' : 'ng', '送信が有効');
    $q = $acc['SendQuota'] ?? [];
    $line('ok', '送信枠', sprintf('1日 %s通 / 毎秒 %s通 / 直近24時間 %s通', $q['Max24HourSend'] ?? '?', $q['MaxSendRate'] ?? '?', $q['SentLast24Hours'] ?? '?'));
    if (isset($q['MaxSendRate']) && $ph > (float)$q['MaxSendRate']) {
        $line('ng', 'sending.per_second が SES の上限を超えている', "{$ph} > {$q['MaxSendRate']}");
    }
    if (isset($q['Max24HourSend']) && (int)$cfg->get('sending.daily_limit') > (float)$q['Max24HourSend']) {
        $line('warn', 'sending.daily_limit が SES の1日枠を超えている', $cfg->get('sending.daily_limit') . ' > ' . $q['Max24HourSend']);
    }
} catch (Throwable $e) {
    $line(str_contains($e->getMessage(), 'AccessDenied') ? 'warn' : 'ng', 'SES アカウント情報', $e->getMessage()
        . (str_contains($e->getMessage(), 'AccessDenied') ? ' (確認用に IAM へ ses:GetAccount を追加すると見られる)' : ''));
}
try {
    $id = $app->ses()->getEmailIdentity($domain);
    $line(!empty($id['VerifiedForSendingStatus']) ? 'ok' : 'ng', "送信ドメイン {$domain} の認証");
    $dk = $id['DkimAttributes']['Status'] ?? '?';
    $line($dk === 'SUCCESS' ? 'ok' : 'ng', 'DKIM', $dk);
    $mf = $id['MailFromAttributes']['MailFromDomainStatus'] ?? 'NOT SET';
    $line($mf === 'SUCCESS' ? 'ok' : 'warn', 'カスタム MAIL FROM (SPF)', ($id['MailFromAttributes']['MailFromDomain'] ?? '') . ' ' . $mf);
} catch (Throwable $e) {
    $line('warn', "送信ドメイン {$domain} の認証状態", $e->getMessage() . ' (確認用に IAM へ ses:GetEmailIdentity を追加すると見られる)');
}

// ---------- DNS ----------
$section('DNS');
$dmarc = array_column(@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [], 'txt');
$dmarcOrg = array_column(@dns_get_record('_dmarc.' . preg_replace('/^[^.]+\./', '', $domain), DNS_TXT) ?: [], 'txt');
$has = static fn(array $txts) => (bool)array_filter($txts, static fn($v) => str_starts_with((string)$v, 'v=DMARC1'));
$line($has($dmarc) || $has($dmarcOrg) ? 'ok' : 'ng', 'DMARC レコード', implode(' ', $dmarc ?: $dmarcOrg) ?: '_dmarc.' . $domain . ' に無い');
$bounce = 'bounce.' . $domain;
$spf = array_column(@dns_get_record($bounce, DNS_TXT) ?: [], 'txt');
$line((bool)array_filter($spf, static fn($v) => str_contains((string)$v, 'amazonses.com')) ? 'ok' : 'warn', "SPF ({$bounce})", implode(' ', $spf) ?: '見つからない (MAIL FROM を別名にしている場合は無視してよい)');
$line(@checkdnsrr($bounce, 'MX') ? 'ok' : 'warn', "MAIL FROM の MX ({$bounce})");

summary:
echo "\n結果: OK {$results['ok']} / 注意 {$results['warn']} / NG {$results['ng']}\n";
echo $results['ng'] === 0 ? "NG はありません。\n" : "NG の項目を直してから送信してください。\n";
exit($results['ng'] === 0 ? 0 : 1);
