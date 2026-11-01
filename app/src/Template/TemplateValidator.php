<?php
declare(strict_types=1);

namespace Mailer\Template;

/** テンプレート保存時のチェック (虚偽件名・Re:偽装などを禁止) */
final class TemplateValidator
{
    /** @return list<string> エラーメッセージ */
    public static function validate(array $t): array
    {
        $errors = [];
        $subject = trim((string)($t['subject'] ?? ''));
        $text = trim((string)($t['body_text'] ?? ''));
        if ($subject === '') {
            $errors[] = '件名は必須です';
        }
        if ($text === '') {
            $errors[] = 'テキスト本文は必須です (テキストを主にする)';
        }
        if (mb_strlen($subject) > 100) {
            $errors[] = '件名は100文字以内にしてください';
        }
        // 返信・転送の偽装
        if (preg_match('/^\s*(re|fw|fwd|返信|転送)\s*[:：]/iu', $subject)) {
            $errors[] = '件名を「Re:」「Fw:」等で始めることはできません (返信・転送の偽装になるため)';
        }
        // 誤認を招く表現の簡易チェック
        foreach (['【重要】', '【緊急】', '至急', 'ご請求', '請求書', '未払い', 'ご注文ありがとう', 'ご当選', '当選', '絶対', '100%', '必ず儲'] as $ng) {
            if (mb_stripos($subject, $ng) !== false) {
                $errors[] = "件名に誤認・誇大のおそれがある表現「{$ng}」が含まれています";
            }
        }
        foreach (['subject' => $subject, 'body_text' => $text, 'body_html' => (string)($t['body_html'] ?? '')] as $field => $v) {
            if (preg_match_all('/\{\{\s*([^}|]+?)\s*(?:\|[^}]*)?\}\}/u', $v, $m)) {
                foreach ($m[1] as $name) {
                    if (!isset(TemplateRenderer::FIELDS[$name])) {
                        $errors[] = "未知の差し込み項目 {{{$name}}} があります ({$field})";
                    }
                }
            }
        }
        $html = (string)($t['body_html'] ?? '');
        if ($html !== '' && preg_match('#<script|javascript:|<form|<iframe#i', $html)) {
            $errors[] = 'HTML本文に script / form / iframe は使えません';
        }
        if (!in_array((int)($t['step'] ?? 1), [1, 2], true)) {
            $errors[] = 'step は 1 (初回) か 2 (追いメール) です';
        }
        return array_values(array_unique($errors));
    }
}
