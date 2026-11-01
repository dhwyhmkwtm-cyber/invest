<?php
declare(strict_types=1);

use Mailer\Sns\SnsMessageValidator;

function sns_keypair(): array
{
    static $pair = null;
    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $pem);
        $pair = [$key, $pem];
    }
    return $pair;
}

function signed_sns(array $msg, string $version = '2'): array
{
    [$key] = sns_keypair();
    $msg += ['SignatureVersion' => $version, 'SigningCertURL' => 'https://sns.ap-northeast-1.amazonaws.com/SimpleNotificationService-abc.pem'];
    openssl_sign(SnsMessageValidator::stringToSign($msg), $sig, $key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
    $msg['Signature'] = base64_encode($sig);
    return $msg;
}

return [
    'SNS署名: 正しい署名は通り、改ざん・偽の証明書URLは拒否' => function () {
        [, $pem] = sns_keypair();
        $v = new SnsMessageValidator(sys_get_temp_dir(), fn() => $pem);
        $base = ['Type' => 'Notification', 'MessageId' => 'm1', 'TopicArn' => 'arn:aws:sns:ap-northeast-1:1:t', 'Message' => '{"a":1}', 'Timestamp' => '2026-11-04T00:00:00Z'];
        $v->validate(signed_sns($base, '1'));
        $v->validate(signed_sns($base, '2'));
        $sub = ['Type' => 'SubscriptionConfirmation', 'MessageId' => 'm2', 'TopicArn' => 'arn:aws:sns:ap-northeast-1:1:t', 'Message' => 'confirm',
            'Timestamp' => '2026-11-04T00:00:00Z', 'Token' => 'tok', 'SubscribeURL' => 'https://sns.ap-northeast-1.amazonaws.com/?Action=ConfirmSubscription'];
        $v->validate(signed_sns($sub));

        $bad = signed_sns($base);
        $bad['Message'] = '{"a":2}';
        $threw = false;
        try {
            $v->validate($bad);
        } catch (RuntimeException) {
            $threw = true;
        }
        ok($threw, '改ざんを検出');

        $evil = signed_sns($base + ['SigningCertURL' => 'https://sns.evil.com/x.pem']);
        $threw = false;
        try {
            $v->validate($evil);
        } catch (RuntimeException) {
            $threw = true;
        }
        ok($threw, 'amazonaws.com 以外の証明書URLは拒否');
        ok(!SnsMessageValidator::isAmazonUrl('https://sns.us-east-1.amazonaws.com.evil.com/'));
        ok(!SnsMessageValidator::isAmazonUrl('http://sns.us-east-1.amazonaws.com/'));
    },
];
