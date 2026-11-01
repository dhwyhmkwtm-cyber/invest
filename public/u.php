<?php
// 配信停止ページ (本文リンク) と RFC 8058 ワンクリック配信停止の受付
declare(strict_types=1);
require (require __DIR__ . '/_app.php') . '/bootstrap.php';

$app = mailer_app();
$cfg = $app->config;
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$queueId = (int)($_GET['q'] ?? $_POST['q'] ?? 0);
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$page = static function (string $title, string $body) use ($cfg, $h): never {
    $company = $h($cfg->get('sender.company_name'));
    $contact = $h($cfg->get('sender.contact_email'));
    $phone = $h($cfg->get('sender.phone'));
    echo <<<HTML
<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>{$h($title)}</title>
<style>body{font-family:system-ui,-apple-system,"Hiragino Sans","Yu Gothic",sans-serif;max-width:560px;margin:40px auto;padding:0 16px;color:#222;line-height:1.7}
button{font-size:16px;padding:10px 24px;border:0;border-radius:6px;background:#c0392b;color:#fff;cursor:pointer}
.box{border:1px solid #ddd;border-radius:8px;padding:20px}small{color:#666}</style></head><body>
<h1 style="font-size:20px">{$h($title)}</h1><div class="box">{$body}</div>
<p><small>{$company}<br>お問い合わせ: {$contact} / {$phone}</small></p></body></html>
HTML;
    exit;
};

if (isset($_GET['test'])) {
    $page('配信停止 (テスト)', '<p>テスト送信用のリンクです。実際の配信では、ここから配信停止の手続きができます。</p>');
}

try {
    $tokens = $app->unsubscribeToken();
} catch (Throwable $e) {
    http_response_code(500);
    $page('エラー', '<p>現在受付できません。お手数ですが下記までご連絡ください。</p>');
}

if (!$tokens->verify($queueId, $token)) {
    http_response_code(400);
    $page('配信停止', '<p>リンクが正しくないか、期限切れです。お手数ですが、下記のお問い合わせ先へ「配信停止希望」とご連絡ください。すぐに対応いたします。</p>');
}

$service = new Mailer\UnsubscribeService($app->db(), $app->clock, $app->suppressions());

if ($method === 'POST') {
    // RFC 8058: メールソフトのワンクリック配信停止 (本文は "List-Unsubscribe=One-Click")
    $oneClick = ($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
    $email = $service->unsubscribeByQueue($queueId, $oneClick ? 'one_click' : 'unsubscribe_page', !$oneClick && !empty($_POST['domain']));
    $app->log('unsubscribe', sprintf('q=%d via=%s email=%s', $queueId, $oneClick ? 'one_click' : 'page', $email ?? '-'));
    if ($oneClick) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "unsubscribed\n";
        exit;
    }
    if ($email === null) {
        $page('配信停止', '<p>対象が見つかりませんでした。お手数ですが下記へご連絡ください。</p>');
    }
    $page('配信停止が完了しました', '<p><strong>' . $h($email) . '</strong> への配信を停止しました。今後、当社からご案内メールをお送りすることはありません。</p><p>ご迷惑をおかけし申し訳ございませんでした。</p>');
}

$row = $service->queueRow($queueId);
if (!$row) {
    $page('配信停止', '<p>対象が見つかりませんでした。お手数ですが下記へご連絡ください。</p>');
}
if ($app->suppressions()->isSuppressed($row['email'])) {
    $page('配信停止済みです', '<p><strong>' . $h($row['email']) . '</strong> は既に配信停止済みです。今後ご案内をお送りすることはありません。</p>');
}
$domainOpt = Mailer\Util\Email::isSharedDomain($row['email_domain']) ? '' :
    '<p><label><input type="checkbox" name="domain" value="1"> 同じドメイン (@' . $h($row['email_domain']) . ') の他のアドレスにも送らない</label></p>';
$page('配信停止の確認', '<form method="post" action="u.php">'
    . '<input type="hidden" name="q" value="' . (int)$queueId . '"><input type="hidden" name="t" value="' . $h($token) . '">'
    . '<p><strong>' . $h($row['email']) . '</strong> への今後のご案内メールを停止します。</p>'
    . $domainOpt
    . '<p><button type="submit">配信を停止する</button></p></form>');
