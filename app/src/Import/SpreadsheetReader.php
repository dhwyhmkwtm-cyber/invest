<?php
declare(strict_types=1);

namespace Mailer\Import;

/**
 * CSV (UTF-8 / Shift_JIS) と Excel (.xlsx の先頭シート) を行配列として読む。
 */
final class SpreadsheetReader
{
    /** @return list<list<string>> */
    public static function read(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return self::readXlsx($path);
        }
        if ($ext === 'xls') {
            throw new \RuntimeException('旧形式の .xls には対応していません。Excelで .xlsx か CSV で保存し直してください。');
        }
        return self::readCsv($path);
    }

    /** @return list<list<string>> */
    public static function readCsv(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('ファイルを読めません');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
        }
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static fn($v) => trim((string)$v), $row);
        }
        fclose($fh);
        return $rows;
    }

    /** @return list<list<string>> */
    public static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('xlsx を開けません');
        }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $xml = simplexml_load_string($ss, \SimpleXMLElement::class, LIBXML_NONET);
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $shared[] = $text;
                }
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $wbx = simplexml_load_string($wb, \SimpleXMLElement::class, LIBXML_NONET);
            $relx = simplexml_load_string($rels, \SimpleXMLElement::class, LIBXML_NONET);
            $first = $wbx->sheets->sheet[0] ?? null;
            if ($first !== null) {
                $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($relx->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = ltrim((string)$rel['Target'], '/');
                        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        $sheet = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            throw new \RuntimeException('シートが見つかりません');
        }
        $x = simplexml_load_string($sheet, \SimpleXMLElement::class, LIBXML_NONET);
        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref) ?? 'A');
                $type = (string)$c['t'];
                $val = match ($type) {
                    's' => $shared[(int)$c->v] ?? '',
                    'inlineStr' => (string)($c->is->t ?? ''),
                    default => (string)$c->v,
                };
                $cells[$col] = trim($val);
            }
            if ($cells === []) {
                continue;
            }
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
