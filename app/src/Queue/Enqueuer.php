<?php
declare(strict_types=1);

namespace Mailer\Queue;

use Mailer\Clock;
use Mailer\Config;
use Mailer\Db;
use Mailer\Util\Email;

/**
 * 送信予約をキューに積む (F4)。
 * - 法令上送ってよい宛先だけを選ぶ (取得元・取得日あり、お断り表示なし、抑止リストに無い)
 * - 1社につき1通 (窓口アドレスを優先)
 * - 同じドメイン宛てが連続しないよう、送信順 (seq) を分散させる
 */
final class Enqueuer
{
    public function __construct(private Db $db, private Clock $clock, private Config $config)
    {
    }

    /** 送信してよい宛先の SQL 条件 (別名 c = contacts)。送信直前の再確認にも使う。 */
    public static function eligibilitySql(bool $requireMx): string
    {
        return "c.source_url IS NOT NULL AND c.source_url <> ''
            AND c.acquired_at IS NOT NULL
            AND c.no_ad_flag = 0
            AND c.status NOT IN ('bounced', 'complained', 'unsubscribed')
            " . ($requireMx ? "AND c.mx_status = 'ok'" : "AND c.mx_status <> 'ng'") . "
            AND NOT EXISTS (SELECT 1 FROM suppressions s WHERE s.type = 'email' AND s.value = c.email)
            AND NOT EXISTS (SELECT 1 FROM suppressions s2 WHERE s2.type = 'domain' AND s2.value = c.email_domain)";
    }

    /**
     * @param array{industry?:string, region?:string, tag?:string, origin?:string, limit?:int} $filters
     * @return array{queued:int, candidates:int}
     */
    public function enqueueInitial(int $campaignId, array $filters = []): array
    {
        $templates = $this->activeTemplateIds($campaignId, 1);
        if ($templates === []) {
            throw new \RuntimeException('このキャンペーンに有効な初回テンプレートがありません');
        }
        $where = [self::eligibilitySql((bool)$this->config->get('sending.require_mx_ok', true))];
        $params = [];
        // 1社1通: このキャンペーンで既に予約済みの会社は除く
        $where[] = 'NOT EXISTS (SELECT 1 FROM send_queue q JOIN contacts c2 ON c2.id = q.contact_id
                     WHERE q.campaign_id = ? AND q.step = 1 AND c2.company_id = c.company_id)';
        $params[] = $campaignId;
        foreach (['industry' => 'co.industry', 'region' => 'co.region'] as $k => $col) {
            if (!empty($filters[$k])) {
                $where[] = "{$col} = ?";
                $params[] = $filters[$k];
            }
        }
        if (!empty($filters['tag'])) {
            $where[] = 'CONCAT(\',\', REPLACE(co.tags, \' \', \'\'), \',\') LIKE ?';
            $params[] = '%,' . str_replace(' ', '', $filters['tag']) . ',%';
        }
        if (!empty($filters['origin'])) {
            $where[] = 'c.origin = ?';
            $params[] = $filters['origin'];
        }
        $rows = $this->db->all(
            'SELECT c.id, c.email, c.email_domain, c.company_id FROM contacts c JOIN companies co ON co.id = c.company_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id',
            $params
        );

        // 1社につき最も優先度の高いアドレスを1つ
        $best = [];
        foreach ($rows as $r) {
            $cid = (int)$r['company_id'];
            $p = Email::priority($r['email']);
            if (!isset($best[$cid]) || $p < $best[$cid]['_p']) {
                $r['_p'] = $p;
                $best[$cid] = $r;
            }
        }
        $picked = array_values($best);
        $limit = (int)($filters['limit'] ?? 0);
        if ($limit > 0 && count($picked) > $limit) {
            shuffle($picked);
            $picked = array_slice($picked, 0, $limit);
        }
        $ordered = self::spreadByDomain($picked);
        $queued = $this->insertRows($campaignId, 1, $templates, $ordered);
        return ['queued' => $queued, 'candidates' => count($picked)];
    }

    /** @return list<int> */
    public function activeTemplateIds(int $campaignId, int $step): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT id FROM templates WHERE campaign_id = ? AND step = ? AND active = 1 ORDER BY id',
            [$campaignId, $step]
        ), 'id'));
    }

    /**
     * @param list<int> $templates A/Bパターンは均等に割り当てる
     * @param list<array{id:int|string}> $contacts
     */
    public function insertRows(int $campaignId, int $step, array $templates, array $contacts, ?string $scheduledAt = null): int
    {
        if ($contacts === []) {
            return 0;
        }
        $now = $this->clock->nowSql();
        $scheduledAt ??= $now;
        $base = (int)$this->db->value('SELECT COALESCE(MAX(seq), 0) FROM send_queue WHERE campaign_id = ?', [$campaignId]);
        $offset = random_int(0, max(0, count($templates) - 1));
        $count = 0;
        foreach (array_chunk($contacts, 500) as $ci => $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $i => $c) {
                $n = $ci * 500 + $i;
                $values[] = '(?, ?, ?, ?, ?, ?, \'queued\', 0, ?, ?)';
                array_push($params, $campaignId, (int)$c['id'], $templates[($n + $offset) % count($templates)], $step, $base + $n + 1, $scheduledAt, $now, $now);
            }
            $st = $this->db->run(
                'INSERT IGNORE INTO send_queue (campaign_id, contact_id, template_id, step, seq, scheduled_at, status, attempts, created_at, updated_at)
                 VALUES ' . implode(',', $values),
                $params
            );
            $count += $st->rowCount();
        }
        return $count;
    }

    /**
     * 各ドメインの宛先がリスト全体に均等に散らばるよう並べ替える。
     * @template T of array{email_domain:string}
     * @param list<T> $rows
     * @return list<T>
     */
    public static function spreadByDomain(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['email_domain']][] = $r;
        }
        $keyed = [];
        foreach ($groups as $domain => $items) {
            $n = count($items);
            // ドメインごとに少しずらして、同じ位置に集中しないようにする
            $jitter = (crc32((string)$domain) % 1000) / 1000;
            foreach ($items as $k => $item) {
                $keyed[] = [($k + $jitter) / $n, $item];
            }
        }
        usort($keyed, static fn($a, $b) => $a[0] <=> $b[0]);
        $out = array_column($keyed, 1);
        // 仕上げ: 隣り合う同一ドメインを、後方の別ドメインと入れ替える
        $len = count($out);
        for ($i = 1; $i < $len; $i++) {
            if ($out[$i]['email_domain'] !== $out[$i - 1]['email_domain']) {
                continue;
            }
            for ($j = $i + 1; $j < $len; $j++) {
                if ($out[$j]['email_domain'] !== $out[$i - 1]['email_domain']
                    && ($j + 1 >= $len || $out[$j + 1]['email_domain'] !== $out[$i]['email_domain'])
                    && $out[$j - 1]['email_domain'] !== $out[$i]['email_domain']) {
                    [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
                    break;
                }
            }
        }
        return $out;
    }
}
