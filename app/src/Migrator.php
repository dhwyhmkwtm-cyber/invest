<?php
declare(strict_types=1);

namespace Mailer;

/**
 * テーブル作成と追加変更 (sql/migrations/*.sql) を順に適用する。
 * 適用済みの番号は settings.schema_version に記録し、何度実行しても安全。
 */
final class Migrator
{
    public function __construct(private Db $db, private Clock $clock)
    {
    }

    /** @return list<string> 今回適用したファイル名 */
    public function migrate(): array
    {
        $this->exec((string)file_get_contents(MAILER_ROOT . '/sql/schema.sql'));
        $settings = new Settings($this->db, $this->clock);
        $current = (int)$settings->get('schema_version', '1');
        $applied = [];
        $files = glob(MAILER_ROOT . '/sql/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $n = (int)basename($file);
            if ($n <= $current) {
                continue;
            }
            $this->exec((string)file_get_contents($file));
            $settings->set('schema_version', (string)$n);
            $applied[] = basename($file);
        }
        return $applied;
    }

    private function exec(string $sql): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->db->pdo->exec($stmt);
        }
    }
}
