<?php
declare(strict_types=1);

// テスト用の初期化。MySQL/MariaDB のテスト用DBが必要:
//   MAILER_TEST_DSN='mysql:host=127.0.0.1;dbname=mailer_test;charset=utf8mb4' MAILER_TEST_USER=... MAILER_TEST_PASS=... php tests/run.php

require __DIR__ . '/../bootstrap.php';

use Mailer\App;
use Mailer\Clock;
use Mailer\Config;
use Mailer\Db;
use Mailer\Ses\Transport;
use Mailer\Ses\TransportException;

final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array,body:string}> */
    public array $requests = [];
    /** @var list<callable|array> 順番に返す応答 (無ければ成功) */
    public array $queue = [];
    private int $n = 0;

    public function request(string $method, string $url, array $headers, string $body, int $timeout = 15): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->queue) {
            $next = array_shift($this->queue);
            if ($next instanceof Throwable) {
                throw $next;
            }
            return $next;
        }
        $this->n++;
        return ['status' => 200, 'body' => json_encode(['MessageId' => 'msg-' . $this->n . '-' . bin2hex(random_bytes(4))]), 'headers' => []];
    }

    /** SES 送信リクエストだけ (管理者通知を除く) */
    public function sends(): array
    {
        return array_values(array_filter($this->requests, function ($r) {
            if (!str_contains($r['url'], '/v2/email/outbound-emails')) {
                return false;
            }
            $p = json_decode($r['body'], true);
            return isset($p['ConfigurationSetName']);
        }));
    }

    public static function recipient(array $req): string
    {
        return json_decode($req['body'], true)['Destination']['ToAddresses'][0];
    }

    public static function mime(array $req): string
    {
        return base64_decode(json_decode($req['body'], true)['Content']['Raw']['Data']);
    }
}

function test_config(array $override = []): Config
{
    $cfg = [
        'environment' => 'production',
        'timezone' => 'Asia/Tokyo',
        'db' => [
            'dsn' => getenv('MAILER_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=mailer_test;charset=utf8mb4',
            'user' => getenv('MAILER_TEST_USER') ?: 'mailer',
            'password' => getenv('MAILER_TEST_PASS') ?: 'mailer',
        ],
        'sender' => [
            'company_name' => 'テスト資材株式会社', 'address' => '〒100-0001 東京都千代田区1-1', 'phone' => '03-0000-0000',
            'contact_email' => 'sales@example.co.jp', 'from_email' => 'info@news.example.co.jp', 'from_name' => 'テスト資材 営業部',
            'reply_to' => 'sales@example.co.jp', 'privacy_url' => '',
        ],
        'public_base_url' => 'https://news.example.co.jp/mailer',
        'app_secret' => str_repeat('s3cr3t', 8),
        'aws' => ['region' => 'ap-northeast-1', 'access_key_id' => 'AKIDEXAMPLE', 'secret_access_key' => 'secret',
            'configuration_set' => 'mailer-events', 'sns_topic_arns' => ['arn:aws:sns:ap-northeast-1:123456789012:mailer']],
        'sending' => ['per_second' => 1000, 'hourly_limit' => 10000, 'daily_limit' => 10000, 'max_per_run' => 1000,
            'run_time_budget' => 25, 'max_attempts' => 3, 'retry_delay_minutes' => [30, 180, 720], 'require_mx_ok' => true, 'holidays' => []],
        'guard' => ['window_days' => 7, 'min_sample' => 100, 'max_complaint_rate' => 0.001, 'max_bounce_rate' => 0.05],
        'admin' => ['allowed_ips' => []],
        'notify' => ['admin_emails' => ['admin@example.co.jp'], 'slack_webhook_url' => ''],
        'var_dir' => sys_get_temp_dir() . '/mailer-test-var',
    ];
    $c = new Config($cfg);
    foreach ($override as $k => $v) {
        $c = $c->with($k, $v);
    }
    return $c;
}

/** @return array{0:App,1:FakeTransport} */
function fresh_app(array $override = [], string $now = '2026-11-04 10:00:00'): array
{
    $config = test_config($override);
    $clock = new Clock();
    $clock->freeze(new DateTimeImmutable($now, new DateTimeZone('Asia/Tokyo')));
    $app = new App($config, $clock);
    $db = Db::connect($config);
    foreach ($db->all('SHOW TABLES') as $row) {
        $db->pdo->exec('DROP TABLE `' . array_values($row)[0] . '`');
    }
    (new Mailer\Migrator($db, $clock))->migrate();
    $app->setDb($db);
    $t = new FakeTransport();
    $app->setTransport($t);
    return [$app, $t];
}

/** テスト用の宛先を作る */
function seed_contacts(App $app, int $n, string $domainPattern = 'co%d.example.jp', array $extra = []): array
{
    $rows = [['会社名', 'メールアドレス', '担当者名', '業種', '地域', '取得元', '取得日']];
    for ($i = 1; $i <= $n; $i++) {
        $domain = sprintf($domainPattern, $i);
        $rows[] = ["テスト社{$i}", "info@{$domain}", '', '解体工事業', '東京都', "https://{$domain}/company", '2026-10-01'];
    }
    foreach ($extra as $r) {
        $rows[] = $r;
    }
    $r = (new Mailer\Import\ContactImporter($app->db(), $app->clock, $app->suppressions()))->import($rows, 'own');
    $app->db()->run("UPDATE contacts SET mx_status = 'ok'");
    return $r;
}

function seed_campaign(App $app, array $overrides = []): int
{
    $now = $app->clock->nowSql();
    $id = $app->db()->insert('campaigns', $overrides + ['name' => 'テスト', 'status' => 'active', 'daily_limit' => 1000, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('templates', ['campaign_id' => $id, 'step' => 1, 'variant' => 'A', 'subject' => '石綿廃棄物用袋のご案内',
        'body_text' => "{{会社名}}\n{{担当者名|ご担当者}} 様\n\n本文です。", 'body_html' => null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    return $id;
}

function worker(App $app): array
{
    return (new Mailer\Queue\Worker($app, static function (float $s): void {
    }))->run();
}

// --- assertions ---
final class AssertionFailed extends Exception
{
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
