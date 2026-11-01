<?php
declare(strict_types=1);

namespace Mailer\Campaign;

use Mailer\App;

/**
 * A/Bテスト (F3)。初回テンプレートの複数パターンを比べ、成績の良いものを残りの配信に使う。
 *
 * 各パターンの送信 (24時間以上前の送信のみ) が ab_min_per_variant 件に達したら自動で決定する。
 * ウォームアップが段階3に入ったときは、件数が足りなくてもその時点の成績で決定する。
 *
 * 成績の点数 (送信1件あたり):
 *   返信×10 + クリック×3 + 開封×1 − 配信停止×5 − 苦情×50
 * 開封は計測が不正確なので重みを低くし、苦情は大きく減点する。
 */
final class AbTest
{
    public const WEIGHTS = ['replied' => 10, 'clicked' => 3, 'opened' => 1, 'unsubscribed' => -5, 'complained' => -50];

    public function __construct(private App $app)
    {
    }

    /** @return list<array<string,mixed>> 初回パターンごとの成績 (点数つき) */
    public function scores(int $campaignId, bool $onlyMatured = true): array
    {
        $cutoff = $this->app->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $rows = $this->app->db()->all(
            "SELECT t.id, t.variant, t.subject, t.active,
                    COUNT(DISTINCT q.id) AS sent,
                    COUNT(DISTINCT CASE WHEN e.type = 'open' THEN q.id END) AS opened,
                    COUNT(DISTINCT CASE WHEN e.type = 'click' THEN q.id END) AS clicked,
                    COUNT(DISTINCT CASE WHEN e.type = 'reply' THEN q.id END) AS replied,
                    COUNT(DISTINCT CASE WHEN e.type = 'complaint' THEN q.id END) AS complained,
                    COUNT(DISTINCT CASE WHEN e.type = 'unsubscribe' THEN q.id END) AS unsubscribed
               FROM templates t
               LEFT JOIN send_queue q ON q.template_id = t.id AND q.status = 'sent' AND q.sent_at < ?
               LEFT JOIN events e ON e.queue_id = q.id
              WHERE t.campaign_id = ? AND t.step = 1 AND t.active = 1
              GROUP BY t.id, t.variant, t.subject, t.active
              ORDER BY t.id",
            [$onlyMatured ? $cutoff : '9999-12-31 00:00:00', $campaignId]
        );
        foreach ($rows as &$r) {
            $sent = (int)$r['sent'];
            $score = 0.0;
            foreach (self::WEIGHTS as $k => $w) {
                $score += $w * (int)$r[$k];
            }
            $r['score'] = $sent ? $score / $sent : 0.0;
        }
        return $rows;
    }

    /** @return int|null 決定したテンプレートID */
    public function maybeDecide(int $campaignId, bool $force = false): ?int
    {
        $c = $this->app->db()->one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$c || $c['ab_status'] !== 'testing') {
            return null;
        }
        $rows = $this->scores($campaignId, !$force);
        if (count($rows) < 2) {
            return null;
        }
        $min = $force ? 1 : max(1, (int)$c['ab_min_per_variant']);
        foreach ($rows as $r) {
            if ((int)$r['sent'] < $min) {
                return null;
            }
        }
        usort($rows, static fn($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $winner = (int)$rows[0]['id'];
        $this->decide($campaignId, $winner, $force ? '段階3到達による決定' : '自動決定', $rows);
        return $winner;
    }

    /** 勝ちパターンに決定する。負けパターンを無効化し、未送信キューを勝ちパターンに付け替える。 */
    public function decide(int $campaignId, int $winnerId, string $by, ?array $rows = null): void
    {
        $db = $this->app->db();
        $now = $this->app->clock->nowSql();
        $winner = $db->one('SELECT * FROM templates WHERE id = ? AND campaign_id = ? AND step = 1', [$winnerId, $campaignId]);
        if (!$winner) {
            throw new \InvalidArgumentException('テンプレートが見つかりません');
        }
        $rows ??= $this->scores($campaignId, false);
        $db->transaction(function () use ($db, $campaignId, $winnerId, $now) {
            $db->run('UPDATE templates SET active = 0, updated_at = ? WHERE campaign_id = ? AND step = 1 AND id <> ? AND active = 1', [$now, $campaignId, $winnerId]);
            $db->run('UPDATE templates SET active = 1, updated_at = ? WHERE id = ?', [$now, $winnerId]);
            $db->run("UPDATE send_queue SET template_id = ?, updated_at = ? WHERE campaign_id = ? AND step = 1 AND status IN ('queued', 'retry') AND template_id <> ?",
                [$winnerId, $now, $campaignId, $winnerId]);
            $db->run("UPDATE campaigns SET ab_status = 'decided', ab_winner_id = ?, updated_at = ? WHERE id = ?", [$winnerId, $now, $campaignId]);
        });
        $lines = ["A/Bテストの結果、パターン「{$winner['variant']}」({$winner['subject']}) に決定しました ({$by})。", '残りの配信はこのパターンで送ります。', ''];
        foreach ($rows as $r) {
            $s = max(1, (int)$r['sent']);
            $lines[] = sprintf('%s: 送信 %d / 開封 %.1f%% / クリック %.1f%% / 返信 %d / 停止 %d / 苦情 %d / 点数 %.3f',
                $r['variant'], $r['sent'], $r['opened'] / $s * 100, $r['clicked'] / $s * 100, $r['replied'], $r['unsubscribed'], $r['complained'], $r['score']);
        }
        $this->app->notifier()->send('【配信ツール】A/Bテスト決定: ' . $db->value('SELECT name FROM campaigns WHERE id = ?', [$campaignId]), implode("\n", $lines));
    }
}
