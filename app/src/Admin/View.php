<?php
declare(strict_types=1);

namespace Mailer\Admin;

final class View
{
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public static function layout(string $title, string $body, ?string $user, string $csrf, array $flash = []): void
    {
        $h = [self::class, 'h'];
        $nav = '';
        if ($user !== null) {
            $items = ['dashboard' => 'ダッシュボード', 'contacts' => '宛先リスト', 'campaigns' => 'キャンペーン',
                'suppressions' => '抑止リスト', 'replies' => '返信・手動停止', 'queue' => 'キュー確認', 'reports' => 'レポート'];
            if (function_exists('mailer_app') && mailer_app()->config->get('aws.mode') === 'local') {
                $items['mailbox'] = 'テスト受信箱';
            }
            $cur = $_GET['p'] ?? 'dashboard';
            foreach ($items as $k => $label) {
                $nav .= '<a href="?p=' . $k . '"' . ($cur === $k ? ' class="on"' : '') . '>' . $label . '</a>';
            }
            $nav .= '<form method="post" action="?p=logout" class="logout"><input type="hidden" name="_csrf" value="' . $h($csrf) . '">'
                . '<button>ログアウト (' . $h($user) . ')</button></form>';
        }
        $flashHtml = '';
        foreach ($flash as [$type, $msg]) {
            $flashHtml .= '<div class="flash ' . $h($type) . '">' . nl2br($h($msg)) . '</div>';
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $h($title) . ' | 配信ツール</title><link rel="stylesheet" href="style.css"></head><body>'
            . (function_exists('mailer_app') && mailer_app()->config->get('aws.mode') === 'local'
                ? '<div style="background:#2f855a;color:#fff;text-align:center;padding:4px;font-weight:600">ローカルモード — メールは外に送信されません (テスト受信箱に届きます)</div>'
                : (function_exists('mailer_app') && mailer_app()->config->get('environment', 'staging') !== 'production'
                ? '<div style="background:#b7791f;color:#fff;text-align:center;padding:4px;font-weight:600">テスト環境 — 許可した宛先 (allowed_recipients) 以外には送信しません</div>' : ''))
            . '<header><strong>営業メール配信ツール</strong><nav>' . $nav . '</nav></header><main><h1>' . $h($title) . '</h1>'
            . $flashHtml . $body . '</main></body></html>';
    }

    public static function table(array $headers, array $rows): string
    {
        $out = '<div class="tw"><table><thead><tr>';
        foreach ($headers as $hd) {
            $out .= '<th>' . self::h($hd) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr>';
            foreach ($r as $cell) {
                // 配列 ['html' => ...] はエスケープ済みHTMLとして出力
                $out .= '<td>' . (is_array($cell) ? $cell['html'] : self::h($cell)) . '</td>';
            }
            $out .= '</tr>';
        }
        if ($rows === []) {
            $out .= '<tr><td colspan="' . count($headers) . '" class="muted">データがありません</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }

    public static function pct(float $v, int $digits = 1): string
    {
        return number_format($v * 100, $digits) . '%';
    }

    public static function options(array $opts, ?string $selected): string
    {
        $o = '';
        foreach ($opts as $v => $label) {
            $o .= '<option value="' . self::h($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . self::h($label) . '</option>';
        }
        return $o;
    }
}
