<?php
// Amazon SNS からの SES 通知 (バウンス・苦情・配信・開封・クリック) の受信エンドポイント
declare(strict_types=1);
require (require __DIR__ . '/_app.php') . '/bootstrap.php';

$app = mailer_app();
$cfg = $app->config;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$user = (string)$cfg->get('aws.sns_basic_auth.user', '');
if ($user !== '') {
    $ok = hash_equals($user, (string)($_SERVER['PHP_AUTH_USER'] ?? ''))
        && hash_equals((string)$cfg->get('aws.sns_basic_auth.password', ''), (string)($_SERVER['PHP_AUTH_PW'] ?? ''));
    if (!$ok) {
        header('WWW-Authenticate: Basic realm="sns"');
        http_response_code(401);
        exit;
    }
}

$raw = file_get_contents('php://input', false, null, 0, 256 * 1024);
$msg = json_decode((string)$raw, true);
if (!is_array($msg)) {
    http_response_code(400);
    exit;
}

try {
    (new Mailer\Sns\SnsMessageValidator($app->varDir()))->validate($msg);
} catch (Throwable $e) {
    $app->log('sns', 'rejected: ' . $e->getMessage());
    http_response_code(403);
    exit;
}
if (!in_array($msg['TopicArn'], (array)$cfg->get('aws.sns_topic_arns', []), true)) {
    $app->log('sns', 'rejected: unknown topic ' . $msg['TopicArn']);
    http_response_code(403);
    exit;
}

try {
    switch ($msg['Type']) {
        case 'SubscriptionConfirmation':
            $url = (string)($msg['SubscribeURL'] ?? '');
            if (!Mailer\Sns\SnsMessageValidator::isAmazonUrl($url)) {
                throw new RuntimeException('bad SubscribeURL');
            }
            $resp = $app->transport()->request('GET', $url, [], '', 10);
            $app->log('sns', 'subscription confirmed: ' . $msg['TopicArn'] . ' status=' . $resp['status']);
            break;
        case 'UnsubscribeConfirmation':
            $app->log('sns', 'unsubscribe confirmation received for ' . $msg['TopicArn']);
            break;
        case 'Notification':
            $result = (new Mailer\Sns\SesEventHandler($app))->handle($msg);
            $app->log('sns', $msg['MessageId'] . ' ' . $result);
            break;
    }
} catch (Throwable $e) {
    // 500 を返すと SNS が再送してくれる
    $app->log('error', 'sns: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
http_response_code(200);
echo 'ok';
