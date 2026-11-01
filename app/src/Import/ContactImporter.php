<?php
declare(strict_types=1);

namespace Mailer\Import;

use Mailer\Clock;
use Mailer\Db;
use Mailer\SuppressionList;
use Mailer\Util\Email;

/**
 * 宛先リストの取り込み (F2)。
 * - 重複アドレスは統合 (既存の取得元情報は上書きしない。空欄だけ補う)
 * - 書式不正アドレスは除外
 * - 取得元URL・取得日時が無い行は取り込むが送信対象外になる (警告を出す)
 * - お断り表示ありの行は抑止リストにも登録する
 */
final class ContactImporter
{
    /** 見出し名 → 内部項目名 */
    private const HEADER_MAP = [
        'company' => ['会社名', '企業名', '社名', 'company', 'company_name', '名称', '商号'],
        'email' => ['メールアドレス', 'メール', 'email', 'e-mail', 'mail', 'アドレス'],
        'person' => ['担当者名', '担当者', '氏名', 'person', 'name', 'contact_name'],
        'industry' => ['業種', 'industry'],
        'region' => ['地域', '都道府県', 'region', 'prefecture'],
        'source_url' => ['取得元', '取得元url', '出所', 'source', 'source_url'],
        'acquired_at' => ['取得日', '取得日時', 'acquired_at', 'acquired'],
        'url' => ['url', 'ホームページ', 'hp', 'website', 'サイト'],
        'address' => ['所在地', '住所', 'address'],
        'size' => ['規模', '従業員数', 'size', 'employees'],
        'tags' => ['タグ', 'tags', 'tag'],
        'no_ad' => ['お断り', 'お断り表示', '広告お断り', 'no_ad', 'no_ad_flag'],
    ];

    public function __construct(private Db $db, private Clock $clock, private SuppressionList $suppressions)
    {
    }

    /**
     * @param list<list<string>> $rows 先頭行は見出し
     * @return array{inserted:int, merged:int, invalid:int, missing_source:int, no_ad:int, errors:list<string>, batch:string}
     */
    public function import(array $rows, string $origin = 'own'): array
    {
        if (!in_array($origin, ['own', 'agent', 'other'], true)) {
            throw new \InvalidArgumentException('origin');
        }
        $result = ['inserted' => 0, 'merged' => 0, 'invalid' => 0, 'missing_source' => 0, 'no_ad' => 0, 'errors' => [], 'batch' => ''];
        if (count($rows) < 2) {
            $result['errors'][] = 'データ行がありません';
            return $result;
        }
        $map = $this->mapHeader($rows[0]);
        if (!isset($map['email'])) {
            $result['errors'][] = '見出し行に「メールアドレス」列が見つかりません';
            return $result;
        }
        $batch = $this->clock->now()->format('YmdHis') . '-' . bin2hex(random_bytes(3));
        $result['batch'] = $batch;
        $now = $this->clock->nowSql();

        $this->db->transaction(function () use ($rows, $map, $origin, $batch, $now, &$result) {
            foreach (array_slice($rows, 1) as $i => $row) {
                $line = $i + 2;
                $get = static fn(string $k) => isset($map[$k]) ? trim((string)($row[$map[$k]] ?? '')) : '';
                if (implode('', $row) === '') {
                    continue;
                }
                $email = Email::normalize($get('email'));
                if ($email === null) {
                    $result['invalid']++;
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = "{$line}行目: アドレス不正「{$get('email')}」";
                    }
                    continue;
                }
                $sourceUrl = $get('source_url');
                $acquired = $this->parseDate($get('acquired_at'));
                if ($sourceUrl === '' || $acquired === null) {
                    $result['missing_source']++;
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = "{$line}行目: 取得元または取得日が無いため送信対象外 ({$email})";
                    }
                }
                $noAd = $this->truthy($get('no_ad'));
                $companyName = $get('company') !== '' ? $get('company') : Email::domain($email);
                $companyId = $this->upsertCompany($companyName, $get('url'), $email, [
                    'address' => $get('address'), 'industry' => $get('industry'), 'region' => $get('region'),
                    'size' => $get('size'), 'tags' => $get('tags'),
                ], $now);

                $existing = $this->db->one('SELECT * FROM contacts WHERE email = ?', [$email]);
                if ($existing) {
                    // 統合: 空欄の項目だけ補完。お断りフラグは立つ方向にのみ更新。
                    $this->db->run(
                        'UPDATE contacts SET
                            person_name = COALESCE(NULLIF(person_name, \'\'), ?),
                            source_url  = COALESCE(NULLIF(source_url, \'\'), ?),
                            acquired_at = COALESCE(acquired_at, ?),
                            no_ad_flag  = GREATEST(no_ad_flag, ?),
                            updated_at  = ?
                         WHERE id = ?',
                        [$get('person') ?: null, $sourceUrl ?: null, $acquired, $noAd ? 1 : 0, $now, $existing['id']]
                    );
                    $result['merged']++;
                } else {
                    $this->db->insert('contacts', [
                        'company_id' => $companyId,
                        'email' => $email,
                        'email_domain' => Email::domain($email),
                        'person_name' => $get('person') ?: null,
                        'is_role' => Email::isRole($email) ? 1 : 0,
                        'origin' => $origin,
                        'source_url' => $sourceUrl ?: null,
                        'acquired_at' => $acquired,
                        'no_ad_flag' => $noAd ? 1 : 0,
                        'import_batch' => $batch,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $result['inserted']++;
                }
                if ($noAd) {
                    $result['no_ad']++;
                    $this->suppressions->add('email', $email, 'no_ad', 'import', '取り込み時にお断り表示あり');
                }
            }
        });
        return $result;
    }

    /** @return array<string,int> */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            $n = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$name) ?? ''));
            $n = str_replace([' ', '　'], '', $n);
            foreach (self::HEADER_MAP as $key => $aliases) {
                if (!isset($map[$key]) && in_array($n, array_map('mb_strtolower', $aliases), true)) {
                    $map[$key] = $idx;
                }
            }
        }
        return $map;
    }

    private function upsertCompany(string $name, string $url, string $email, array $attrs, string $now): int
    {
        $domain = '';
        if ($url !== '') {
            $host = parse_url(preg_match('#^https?://#i', $url) ? $url : 'http://' . $url, PHP_URL_HOST);
            $domain = is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : '';
        }
        if ($domain === '' && !Email::isSharedDomain(Email::domain($email))) {
            $domain = Email::domain($email);
        }
        $row = null;
        if ($domain !== '') {
            $row = $this->db->one('SELECT id FROM companies WHERE domain = ? LIMIT 1', [$domain]);
        }
        if (!$row) {
            $row = $this->db->one('SELECT id FROM companies WHERE name = ? AND (domain IS NULL OR domain = ?) LIMIT 1', [$name, $domain]);
        }
        $attrs = array_map(static fn($v) => $v === '' ? null : $v, $attrs);
        if ($row) {
            $this->db->run(
                'UPDATE companies SET url = COALESCE(url, ?), address = COALESCE(address, ?), industry = COALESCE(industry, ?),
                    region = COALESCE(region, ?), size = COALESCE(size, ?), tags = COALESCE(tags, ?), updated_at = ? WHERE id = ?',
                [$url ?: null, $attrs['address'], $attrs['industry'], $attrs['region'], $attrs['size'], $attrs['tags'], $now, $row['id']]
            );
            return (int)$row['id'];
        }
        return $this->db->insert('companies', [
            'name' => $name, 'url' => $url ?: null, 'domain' => $domain ?: null,
            'address' => $attrs['address'], 'industry' => $attrs['industry'], 'region' => $attrs['region'],
            'size' => $attrs['size'], 'tags' => $attrs['tags'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function parseDate(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
            // Excel のシリアル日付
            $ts = (int)round(((float)$v - 25569) * 86400);
            return gmdate('Y-m-d H:i:s', $ts);
        }
        $v = str_replace(['年', '月', '.'], ['-', '-', '-'], $v);
        $v = str_replace('日', '', $v);
        $ts = strtotime(str_replace('/', '-', $v));
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private function truthy(string $v): bool
    {
        $v = mb_strtolower(trim($v));
        return in_array($v, ['1', 'true', 'yes', 'y', 'あり', '有', '○', '〇', 'はい', 'x', '×'], true);
    }
}
