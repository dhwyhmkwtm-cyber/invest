<?php
declare(strict_types=1);

namespace Mailer\Template;

use Mailer\Config;

/**
 * 件名・本文の差し込みと、法定表示 (特定電子メール法) の自動挿入。
 *
 * 法定表示はテンプレートに保存せず、送信時に必ず末尾へ付け足す。
 * そのためテンプレート編集で削除することはできない。
 */
final class TemplateRenderer
{
    /** 差し込み項目: プレースホルダ名 → 宛先データのキー */
    public const FIELDS = [
        '会社名' => 'company_name', 'company' => 'company_name',
        '担当者名' => 'person_name', 'person' => 'person_name',
        '地域' => 'region', 'region' => 'region',
        '業種' => 'industry', 'industry' => 'industry',
    ];

    public function __construct(private Config $config)
    {
    }

    /** 法定表示に必要な送信者情報が揃っているか。不足項目名を返す。 */
    public static function missingSenderInfo(Config $config): array
    {
        $required = [
            'sender.company_name' => '送信者の名称',
            'sender.address' => '送信者の住所',
            'sender.phone' => '電話番号',
            'sender.contact_email' => '問い合わせ先メールアドレス',
            'sender.from_email' => '送信元アドレス',
            'public_base_url' => '配信停止ページのURL',
            'app_secret' => 'app_secret',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            $v = trim((string)$config->get($key, ''));
            if ($v === '' || str_starts_with($v, 'CHANGE_ME')) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * @param array<string,?string> $vars company_name, person_name, region, industry
     * @return array{subject:string, text:string, html:?string}
     */
    public function render(array $template, array $vars, string $unsubscribeUrl): array
    {
        $missing = self::missingSenderInfo($this->config);
        if ($missing) {
            throw new \RuntimeException('法定表示に必要な設定が不足しています: ' . implode('、', $missing));
        }
        $subject = $this->merge((string)$template['subject'], $vars, false);
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        $text = rtrim($this->merge((string)$template['body_text'], $vars, false)) . "\n\n" . $this->legalFooterText($unsubscribeUrl);

        $html = null;
        if (!empty($template['body_html'])) {
            $body = $this->merge((string)$template['body_html'], $vars, true);
            $footer = $this->legalFooterHtml($unsubscribeUrl);
            if (stripos($body, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $footer . '</body>', $body, 1);
            } else {
                $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $body . $footer . '</body></html>';
            }
        }
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /** {{会社名}} / {{担当者名|ご担当者}} (|以降は空欄時の既定値) */
    public function merge(string $tpl, array $vars, bool $html): string
    {
        return preg_replace_callback('/\{\{\s*([^}|]+?)\s*(?:\|\s*([^}]*?)\s*)?\}\}/u', function (array $m) use ($vars, $html) {
            $key = self::FIELDS[$m[1]] ?? null;
            if ($key === null) {
                return $m[0];
            }
            $v = trim((string)($vars[$key] ?? ''));
            if ($v === '') {
                $v = $m[2] ?? '';
            }
            $v = preg_replace('/[\r\n]+/', ' ', $v) ?? '';
            return $html ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
        }, $tpl) ?? $tpl;
    }

    public function legalFooterText(string $unsubscribeUrl): string
    {
        $s = fn(string $k) => (string)$this->config->get('sender.' . $k, '');
        $lines = [
            '──────────────────────────',
            '送信者: ' . $s('company_name'),
            '住所: ' . $s('address'),
            '電話: ' . $s('phone') . ' / お問い合わせ・苦情: ' . $s('contact_email'),
            '',
            '本メールは、貴社が公開されている連絡先あてにお送りしています。',
            '今後このようなご案内が不要な場合は、下記URLから配信停止できます(すぐに反映されます)。',
            '配信停止: ' . $unsubscribeUrl,
        ];
        if ($s('privacy_url') !== '') {
            $lines[] = '個人情報の取り扱い: ' . $s('privacy_url');
        }
        $lines[] = '──────────────────────────';
        return implode("\n", $lines);
    }

    public function legalFooterHtml(string $unsubscribeUrl): string
    {
        $e = static fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $s = fn(string $k) => $e((string)$this->config->get('sender.' . $k, ''));
        $u = $e($unsubscribeUrl);
        $privacy = $s('privacy_url') !== ''
            ? '<br>個人情報の取り扱い: <a href="' . $s('privacy_url') . '">' . $s('privacy_url') . '</a>'
            : '';
        // ses:no-track で配信停止リンクはSESのクリック計測から除外する
        return '<hr style="border:none;border-top:1px solid #ccc;margin:24px 0 12px">'
            . '<p style="font-size:12px;color:#555;line-height:1.6">'
            . '送信者: ' . $s('company_name') . '<br>'
            . '住所: ' . $s('address') . '<br>'
            . '電話: ' . $s('phone') . ' / お問い合わせ・苦情: <a href="mailto:' . $s('contact_email') . '">' . $s('contact_email') . '</a><br><br>'
            . '本メールは、貴社が公開されている連絡先あてにお送りしています。<br>'
            . '今後このようなご案内が不要な場合は、<a ses:no-track href="' . $u . '">こちらから配信停止</a>できます(すぐに反映されます)。<br>'
            . '配信停止URL: ' . $u
            . $privacy
            . '</p>';
    }
}
