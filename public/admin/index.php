<?php
// 管理画面 (F7)
declare(strict_types=1);
require (require dirname(__DIR__) . '/_app.php') . '/bootstrap.php';

use Mailer\Admin\Auth;
use Mailer\Admin\View;
use Mailer\ContactStatus;
use Mailer\Guard\AutoPause;
use Mailer\Import\ContactImporter;
use Mailer\Import\SpreadsheetReader;
use Mailer\Queue\Enqueuer;
use Mailer\Report\Stats;
use Mailer\Settings;
use Mailer\SuppressionList;
use Mailer\Template\TemplateRenderer;
use Mailer\Template\TemplateValidator;
use Mailer\UnsubscribeService;

$app = mailer_app();
$db = $app->db();
$auth = new Auth($app);
$h = [View::class, 'h'];

if (!$auth->ipAllowed()) {
    http_response_code(403);
    exit('Forbidden');
}
$auth->startSession();

$page = (string)($_GET['p'] ?? 'dashboard');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$addFlash = static function (string $type, string $msg): void {
    $_SESSION['flash'][] = [$type, $msg];
};
$redirect = static function (string $query): never {
    header('Location: ?' . $query, true, 303);
    exit;
};
$render = static function (string $title, string $body) use ($auth, &$flash): never {
    View::layout($title, $body, $auth->user(), $auth->csrfToken(), $flash);
    exit;
};
$csrfField = static fn() => '<input type="hidden" name="_csrf" value="' . $h($auth->csrfToken()) . '">';

// ---------- ログイン ----------
if ($auth->user() === null) {
    if ($page === 'login' && $isPost) {
        if ($auth->tooManyAttempts()) {
            $flash[] = ['error', 'ログイン失敗が続いたため、15分間ログインできません。'];
        } elseif ($auth->checkCsrf() && $auth->login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            $auth->audit('login');
            $redirect('p=dashboard');
        } else {
            $flash[] = ['error', 'ユーザー名またはパスワードが違います。'];
        }
    }
    $render('ログイン', '<form method="post" action="?p=login" class="card narrow">' . $csrfField()
        . '<label>ユーザー名<input name="username" autocomplete="username" required></label>'
        . '<label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>'
        . '<button>ログイン</button></form>');
}

if ($isPost && !$auth->checkCsrf()) {
    http_response_code(400);
    exit('CSRF token mismatch. 画面を再読み込みしてやり直してください。');
}

$settings = $app->settings();
$stats = new Stats($db);
$today = $app->clock->now()->format('Y-m-d');
$campaignStatus = ['draft' => '下書き', 'active' => '配信中', 'paused' => '一時停止', 'completed' => '完了'];
$queueStatus = ['queued' => '未送信', 'retry' => '再送待ち', 'sending' => '送信中', 'sent' => '送信済', 'failed' => '失敗', 'cancelled' => '取消', 'unknown' => '結果不明'];

switch ($page) {
    // ---------- ログアウト ----------
    case 'logout':
        if ($isPost) {
            $auth->audit('logout');
            $auth->logout();
        }
        $redirect('p=login');

    // ---------- 全体の一時停止 / 再開 ----------
    case 'pause':
        if ($isPost) {
            if (($_POST['action'] ?? '') === 'resume') {
                (new AutoPause($app))->resume();
                $auth->audit('resume');
                $addFlash('ok', '配信を再開しました。自動停止の集計はこの時点からやり直します。');
            } else {
                $settings->pause('管理者による停止 (' . $auth->user() . ')');
                $auth->audit('pause');
                $addFlash('ok', '全配信を一時停止しました。');
            }
        }
        $redirect('p=dashboard');

    // ---------- (ローカルモード) cron を今すぐ実行 ----------
    case 'runcron':
        if ($isPost && Mailer\Local\LocalMailbox::isLocal($app)) {
            $out = (new Mailer\Cron($app))->run();
            $w = $out['worker'] ?? [];
            $addFlash('ok', sprintf('cron を1回実行しました: 送信 %d / 再送待ち %d / 失敗 %d / 取消 %d / SESイベント %d件%s',
                $w['sent'] ?? 0, $w['retry'] ?? 0, $w['failed'] ?? 0, $w['skipped'] ?? 0, $out['local_events'] ?? 0,
                !empty($w['note']) ? ' (' . $w['note'] . ')' : ''));
        }
        $redirect((string)($_POST['back'] ?? 'p=dashboard'));

    // ---------- (ローカルモード) テスト受信箱 ----------
    case 'mailbox':
        if (!Mailer\Local\LocalMailbox::isLocal($app)) {
            $redirect('p=dashboard');
        }
        $box = new Mailer\Local\LocalMailbox($app);
        $mid = (int)($_GET['id'] ?? 0);
        if ($isPost && $mid) {
            $m = $db->one('SELECT * FROM local_mailbox WHERE id = ?', [$mid]);
            $act = (string)($_POST['act'] ?? '');
            if ($m && in_array($act, ['Open', 'Click', 'Complaint', 'Bounce'], true)) {
                $res = $box->emit($act, $m['message_id'], $m['to_email']);
                $labels = ['Open' => '開封', 'Click' => 'リンクのクリック', 'Complaint' => '迷惑メール報告', 'Bounce' => 'バウンス (宛先不明)'];
                $addFlash('ok', "受信者の「{$labels[$act]}」を模擬しました ({$res})");
            }
            $redirect('p=mailbox&id=' . $mid);
        }
        if ($mid) {
            $m = $db->one('SELECT * FROM local_mailbox WHERE id = ?', [$mid]) ?? $redirect('p=mailbox');
            $mail = Mailer\Local\LocalMailbox::parse($m['raw']);
            $unsub = preg_match('/^List-Unsubscribe:\s*<([^>]+)>/mi', $m['raw'], $um) ? $um[1] : null;
            $q = $unsub ? parse_url($unsub) : null;
            parse_str((string)($q['query'] ?? ''), $qs);
            $buttons = '';
            if ($m['is_campaign']) {
                foreach (['Open' => '開封したことにする', 'Click' => '本文のリンクをクリックしたことにする', 'Complaint' => '「迷惑メール」ボタンを押したことにする', 'Bounce' => 'バウンスさせる'] as $act => $label) {
                    $buttons .= '<form method="post" class="inline">' . $csrfField() . '<input type="hidden" name="act" value="' . $act . '"><button class="' . ($act === 'Complaint' || $act === 'Bounce' ? 'danger ' : '') . 'small">' . $label . '</button></form> ';
                }
                if ($unsub) {
                    // Gmail 等の「配信停止」ボタンと同じリクエスト (RFC 8058)
                    $buttons .= '<form method="post" action="../u.php?q=' . (int)($qs['q'] ?? 0) . '&t=' . $h($qs['t'] ?? '') . '" class="inline">'
                        . '<input type="hidden" name="List-Unsubscribe" value="One-Click"><button class="danger small">メールソフトの「配信停止」ボタンを押す</button></form>';
                }
            }
            $text = $h($mail['text']);
            // 本文中のURLはリンクにする (配信停止URLはローカルの配信停止ページを開く)
            $text = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text) ?? $text;
            $body = '<p><a href="?p=mailbox">← テスト受信箱</a></p>'
                . '<div class="card"><p>' . $buttons . '</p><p class="muted">ボタンを押すと、本物の SES から届くのと同じ形の通知が発生し、抑止リスト・レポート・自動停止に反映されます。</p></div>'
                . '<h2>ヘッダー</h2><pre class="mail">' . $h($mail['headers']) . '</pre>'
                . '<h2>本文 (テキスト)</h2><pre class="mail">' . $text . '</pre>'
                . ($mail['html'] !== null ? '<h2>本文 (HTML のソース)</h2><pre class="mail">' . $h($mail['html']) . '</pre>' : '');
            $render('受信メール: ' . $m['to_email'], $body);
        }
        $list = $db->all('SELECT * FROM local_mailbox ORDER BY id DESC LIMIT 200');
        $body = '<p class="muted">ローカルモードでは、メールは外に出ずにここに届きます。'
            . '宛先 bounce@simulator.amazonses.com はバウンス、complaint@simulator.amazonses.com は迷惑メール報告になります (本物の SES のテスト用アドレスと同じ動き)。</p>'
            . '<form method="post" action="?p=runcron" class="filters">' . $csrfField() . '<input type="hidden" name="back" value="p=mailbox"><button>cron を今すぐ1回実行 (送信・通知処理)</button></form>'
            . View::table(['受信日時', '種類', '宛先', '件名', 'SESイベント', ''], array_map(static fn($m) => [
                $m['created_at'], $m['is_campaign'] ? '営業メール' : '管理者への通知', $m['to_email'], $m['subject'],
                $m['pending_events'] ? '処理待ち (次の cron)' : ($m['is_campaign'] ? '処理済み' : '-'),
                ['html' => '<a href="?p=mailbox&id=' . (int)$m['id'] . '">開く</a>'],
            ], $list));
        $render('テスト受信箱', $body);

    // ---------- ダッシュボード ----------
    case 'dashboard':
        $d = $stats->day($today);
        $q = $stats->queueCounts();
        $g = (new AutoPause($app))->stats();
        $missing = TemplateRenderer::missingSenderInfo($app->config);
        $body = '';
        if (Mailer\Local\LocalMailbox::isLocal($app)) {
            $body .= '<div class="card"><strong>ローカルモード</strong> — メールは外に出ず「<a href="?p=mailbox">テスト受信箱</a>」に届きます。cron は1分ごとに自動で動きます。'
                . '<form method="post" action="?p=runcron" class="inline">' . $csrfField() . '<button class="small">今すぐ1回実行</button></form>'
                . '<p class="muted">注意: 送信時間帯・曜日はキャンペーンの設定どおりに守られます (デモのキャンペーンは毎日0時〜24時)。</p></div>';
        }
        if ($missing) {
            $body .= '<div class="flash error">法定表示に必要な設定が不足しているため送信できません: ' . $h(implode('、', $missing)) . '</div>';
        }
        if ($settings->isPaused()) {
            $body .= '<div class="card alert"><strong>配信は一時停止中です。</strong><br>' . $h($settings->get(Settings::PAUSE_REASON))
                . '<form method="post" action="?p=pause">' . $csrfField() . '<input type="hidden" name="action" value="resume">'
                . '<button>原因を確認したので再開する</button></form></div>';
        } else {
            $body .= '<div class="card"><strong>配信: 稼働中</strong><form method="post" action="?p=pause" class="inline">' . $csrfField()
                . '<button class="danger">全配信を一時停止</button></form></div>';
        }
        $sentToday = (int)$db->value("SELECT COUNT(*) FROM send_log WHERE result = 'sent' AND created_at >= ?", [$today . ' 00:00:00']);
        $body .= '<div class="tiles">'
            . tile('本日の送信', number_format($sentToday) . ' / ' . number_format((int)$app->config->get('sending.daily_limit')))
            . tile('本日の到達', number_format($d['delivery']))
            . tile('本日の開封', number_format($d['open']))
            . tile('本日のクリック', number_format($d['click']))
            . tile('本日の配信停止', number_format($d['unsubscribe']))
            . tile('未送信キュー', number_format($q['queued'] + $q['retry']))
            . tile('結果不明', number_format($q['unknown']), $q['unknown'] > 0 ? 'warn' : '')
            . '</div>';
        $cfg = $app->config;
        $body .= '<h2>自動停止の判定 (直近' . (int)$cfg->get('guard.window_days') . '日 / 再開時点から)</h2>'
            . View::table(['送信数', '永久バウンス', 'バウンス率 (上限)', '苦情', '苦情率 (上限)', '判定'], [[
                number_format($g['sent']), $g['bounces'],
                View::pct($g['bounce_rate'], 2) . ' (' . View::pct((float)$cfg->get('guard.max_bounce_rate'), 1) . ')',
                $g['complaints'],
                View::pct($g['complaint_rate'], 3) . ' (' . View::pct((float)$cfg->get('guard.max_complaint_rate'), 1) . ')',
                $g['judged'] ? ($g['tripped'] ? '超過' : '正常') : '母数不足 (' . (int)$cfg->get('guard.min_sample') . '件未満)',
            ]]);
        $rows = [];
        foreach ($db->all('SELECT * FROM campaigns ORDER BY id DESC LIMIT 10') as $c) {
            $cq = $stats->queueCounts((int)$c['id']);
            $rows[] = [['html' => '<a href="?p=campaign&id=' . (int)$c['id'] . '">' . $h($c['name']) . '</a>'],
                $campaignStatus[$c['status']] ?? $c['status'], $c['daily_limit'], $cq['queued'] + $cq['retry'], $cq['sent'], $cq['failed'], $cq['unknown']];
        }
        $body .= '<h2>キャンペーン</h2>' . View::table(['名称', '状態', '1日上限', '未送信', '送信済', '失敗', '結果不明'], $rows);
        $body .= '<h2>宛先の状態</h2>';
        $cs = $db->all('SELECT status, COUNT(*) n FROM contacts GROUP BY status');
        $body .= View::table(['状態', '件数'], array_map(static fn($r) => [ContactStatus::LABELS[$r['status']] ?? $r['status'], number_format((int)$r['n'])], $cs));
        $render('ダッシュボード', $body);

    // ---------- 宛先リスト ----------
    case 'contacts':
        if ($isPost && ($_POST['action'] ?? '') === 'import') {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $addFlash('error', 'ファイルを選択してください。');
                $redirect('p=contacts');
            }
            try {
                $rows = SpreadsheetReader::read($f['tmp_name'], $f['name']);
                $origin = in_array($_POST['origin'] ?? '', ['own', 'agent', 'other'], true) ? $_POST['origin'] : 'own';
                $r = (new ContactImporter($db, $app->clock, $app->suppressions()))->import($rows, $origin);
                $auth->audit('import', ['file' => $f['name'], 'result' => array_diff_key($r, ['errors' => 1])]);
                $addFlash('ok', sprintf("取り込み完了 (バッチ %s)\n新規 %d件 / 統合 %d件 / 書式不正で除外 %d件 / 取得元なし(送信対象外) %d件 / お断り表示 %d件",
                    $r['batch'], $r['inserted'], $r['merged'], $r['invalid'], $r['missing_source'], $r['no_ad']));
                if ($r['errors']) {
                    $addFlash('warn', implode("\n", array_slice($r['errors'], 0, 30)) . (count($r['errors']) > 30 ? "\n…" : ''));
                }
            } catch (Throwable $e) {
                $addFlash('error', '取り込みに失敗しました: ' . $e->getMessage());
            } finally {
                @unlink($f['tmp_name']);
            }
            $redirect('p=contacts');
        }
        if ($isPost && ($_POST['action'] ?? '') === 'mx') {
            $r = (new Mailer\MxChecker($db, $app->clock, Mailer\Local\LocalMailbox::isLocal($app)))->checkPending(1000, 20.0);
            $addFlash('ok', "MXチェック: {$r['checked']}件確認 / NG {$r['ng']}件 (未確認分は cron でも順次確認します)");
            $redirect('p=contacts');
        }
        $where = ['1=1'];
        $params = [];
        $fq = trim((string)($_GET['q'] ?? ''));
        if ($fq !== '') {
            $where[] = '(c.email LIKE ? OR co.name LIKE ?)';
            $params[] = '%' . $fq . '%';
            $params[] = '%' . $fq . '%';
        }
        foreach (['status' => 'c.status', 'origin' => 'c.origin', 'mx' => 'c.mx_status'] as $k => $col) {
            if (($_GET[$k] ?? '') !== '') {
                $where[] = "{$col} = ?";
                $params[] = $_GET[$k];
            }
        }
        if (($_GET['nosource'] ?? '') === '1') {
            $where[] = "(c.source_url IS NULL OR c.source_url = '' OR c.acquired_at IS NULL)";
        }
        $pg = max(1, (int)($_GET['page'] ?? 1));
        $total = (int)$db->value('SELECT COUNT(*) FROM contacts c JOIN companies co ON co.id = c.company_id WHERE ' . implode(' AND ', $where), $params);
        $list = $db->all(
            'SELECT c.*, co.name company_name, co.industry, co.region FROM contacts c JOIN companies co ON co.id = c.company_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 100 OFFSET ' . (($pg - 1) * 100),
            $params
        );
        $rows = array_map(static fn($c) => [
            $c['company_name'], $c['email'] . ($c['is_role'] ? '' : ' (個人)'), $c['person_name'], $c['industry'], $c['region'],
            ['own' => '自社', 'agent' => '収集', 'other' => 'その他'][$c['origin']] ?? $c['origin'],
            ($c['source_url'] && $c['acquired_at'])
                ? ['html' => (preg_match('#^https?://#i', (string)$c['source_url'])
                    ? '<a href="' . $h($c['source_url']) . '" rel="noreferrer noopener" target="_blank">あり</a> '
                    : '<span title="' . $h($c['source_url']) . '">あり</span> ') . $h(substr((string)$c['acquired_at'], 0, 10))]
                : ['html' => '<span class="bad">なし</span>'],
            $c['no_ad_flag'] ? 'お断り' : '', $c['mx_status'], ContactStatus::LABELS[$c['status']] ?? $c['status'],
        ], $list);
        $qs = static fn(int $p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]));
        $body = '<div class="grid2"><form method="post" enctype="multipart/form-data" class="card">' . $csrfField()
            . '<h2>CSV / Excel 取り込み</h2><input type="hidden" name="action" value="import">'
            . '<p class="muted">見出し行: 会社名, メールアドレス, 担当者名, 業種, 地域, 取得元, 取得日 (任意: URL, 所在地, 規模, タグ, お断り)<br>'
            . '取得元URL・取得日が無い行は取り込みますが送信対象外になります。重複アドレスは自動で統合します。</p>'
            . '<input type="file" name="file" accept=".csv,.xlsx" required>'
            . '<label>取得区分<select name="origin">' . View::options(['own' => '自社収集', 'agent' => '収集エージェント', 'other' => 'その他'], 'own') . '</select></label>'
            . '<button>取り込む</button></form>'
            . '<form method="post" class="card">' . $csrfField() . '<h2>アドレス有効性チェック</h2><input type="hidden" name="action" value="mx">'
            . '<p class="muted">未確認 ' . number_format((int)$db->value("SELECT COUNT(*) FROM contacts WHERE mx_status = 'unchecked'"))
            . '件。ドメインのMXレコードを確認します (20秒で区切って実行)。</p><button>未確認分をチェック</button></form></div>'
            . '<form method="get" class="filters"><input type="hidden" name="p" value="contacts">'
            . '<input name="q" placeholder="アドレス・会社名" value="' . $h($fq) . '">'
            . '<select name="status"><option value="">状態: すべて</option>' . View::options(ContactStatus::LABELS, $_GET['status'] ?? '') . '</select>'
            . '<select name="origin"><option value="">区分: すべて</option>' . View::options(['own' => '自社', 'agent' => '収集', 'other' => 'その他'], $_GET['origin'] ?? '') . '</select>'
            . '<select name="mx"><option value="">MX: すべて</option>' . View::options(['unchecked' => '未確認', 'ok' => 'OK', 'ng' => 'NG'], $_GET['mx'] ?? '') . '</select>'
            . '<label class="inline"><input type="checkbox" name="nosource" value="1"' . (($_GET['nosource'] ?? '') === '1' ? ' checked' : '') . '> 取得元なしのみ</label>'
            . '<button>絞り込み</button></form>'
            . '<p>' . number_format($total) . '件</p>'
            . View::table(['会社名', 'アドレス', '担当者', '業種', '地域', '区分', '取得元', 'お断り', 'MX', '状態'], $rows)
            . '<p class="pager">' . ($pg > 1 ? '<a href="' . $h($qs($pg - 1)) . '">← 前へ</a> ' : '')
            . ($pg * 100 < $total ? '<a href="' . $h($qs($pg + 1)) . '">次へ →</a>' : '') . '</p>';
        $render('宛先リスト', $body);

    // ---------- 抑止リスト ----------
    case 'suppressions':
        $sup = $app->suppressions();
        if ($isPost && ($_POST['action'] ?? '') === 'add') {
            try {
                $type = ($_POST['type'] ?? 'email') === 'domain' ? 'domain' : 'email';
                $reason = array_key_exists($_POST['reason'] ?? '', SuppressionList::REASONS) ? $_POST['reason'] : 'manual';
                $added = 0;
                foreach (preg_split('/[\s,;]+/', (string)($_POST['values'] ?? '')) ?: [] as $v) {
                    if ($v !== '') {
                        $added += $sup->add($type, $v, $reason, 'admin:' . $auth->user(), (string)($_POST['note'] ?? '') ?: null) ? 1 : 0;
                    }
                }
                $auth->audit('suppression_add', ['type' => $type, 'count' => $added]);
                $addFlash('ok', "{$added}件を抑止リストに登録しました (登録済みのものは変更なし)。");
            } catch (Throwable $e) {
                $addFlash('error', $e->getMessage());
            }
            $redirect('p=suppressions');
        }
        if ($isPost && ($_POST['action'] ?? '') === 'import') {
            $f = $_FILES['file'] ?? null;
            try {
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('ファイルを選択してください');
                }
                $added = 0;
                $bad = 0;
                foreach (SpreadsheetReader::read($f['tmp_name'], $f['name']) as $row) {
                    foreach ($row as $cell) {
                        if (str_contains($cell, '@')) {
                            try {
                                $added += $sup->add('email', $cell, 'manual', 'import', '抑止リスト取り込み') ? 1 : 0;
                            } catch (InvalidArgumentException) {
                                $bad++;
                            }
                        }
                    }
                }
                $auth->audit('suppression_import', ['added' => $added]);
                $addFlash('ok', "抑止リスト取り込み: 新規 {$added}件 / 不正 {$bad}件");
            } catch (Throwable $e) {
                $addFlash('error', $e->getMessage());
            }
            $redirect('p=suppressions');
        }
        $fq = trim((string)($_GET['q'] ?? ''));
        $list = $fq !== ''
            ? $db->all('SELECT * FROM suppressions WHERE value LIKE ? ORDER BY id DESC LIMIT 200', ['%' . $fq . '%'])
            : $db->all('SELECT * FROM suppressions ORDER BY id DESC LIMIT 200');
        $counts = $db->all('SELECT reason, COUNT(*) n FROM suppressions GROUP BY reason');
        $body = '<p class="muted">ここに登録された宛先・ドメインには、どのキャンペーン・どのリストからも送信しません。「二度と送らない」ため、画面からの削除はできません。</p>'
            . View::table(['理由', '件数'], array_map(static fn($r) => [SuppressionList::REASONS[$r['reason']] ?? $r['reason'], number_format((int)$r['n'])], $counts))
            . '<div class="grid2"><form method="post" class="card">' . $csrfField() . '<h2>手動登録</h2><input type="hidden" name="action" value="add">'
            . '<label>種別<select name="type">' . View::options(['email' => 'メールアドレス', 'domain' => 'ドメイン (会社全体)'], 'email') . '</select></label>'
            . '<label>理由<select name="reason">' . View::options(SuppressionList::REASONS, 'manual') . '</select></label>'
            . '<label>アドレス / ドメイン (改行・カンマ区切りで複数可)<textarea name="values" rows="4" required></textarea></label>'
            . '<label>メモ<input name="note"></label><button>登録</button></form>'
            . '<form method="post" enctype="multipart/form-data" class="card">' . $csrfField() . '<h2>停止リストの取り込み</h2><input type="hidden" name="action" value="import">'
            . '<p class="muted">業者から引き継いだ配信停止リストなど。セル内のメールアドレスをすべて登録します。</p>'
            . '<input type="file" name="file" accept=".csv,.xlsx" required><button>取り込む</button></form></div>'
            . '<form method="get" class="filters"><input type="hidden" name="p" value="suppressions"><input name="q" placeholder="検索" value="' . $h($fq) . '"><button>検索</button></form>'
            . View::table(['種別', '値', '理由', '経路', 'メモ', '登録日時'], array_map(static fn($r) => [
                $r['type'] === 'domain' ? 'ドメイン' : 'アドレス', $r['value'], SuppressionList::REASONS[$r['reason']] ?? $r['reason'], $r['source'], $r['note'], $r['created_at'],
            ], $list));
        $render('抑止リスト', $body);

    // ---------- キャンペーン一覧・作成 ----------
    case 'campaigns':
        if ($isPost) {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $addFlash('error', '名称を入力してください');
                $redirect('p=campaigns');
            }
            $now = $app->clock->nowSql();
            $id = $db->insert('campaigns', ['name' => $name, 'status' => 'draft', 'daily_limit' => 200, 'created_at' => $now, 'updated_at' => $now]);
            $auth->audit('campaign_create', ['id' => $id]);
            $redirect('p=campaign&id=' . $id);
        }
        $rows = array_map(static fn($c) => [
            ['html' => '<a href="?p=campaign&id=' . (int)$c['id'] . '">' . $h($c['name']) . '</a>'],
            $campaignStatus[$c['status']] ?? $c['status'], $c['start_date'], $c['daily_limit'],
            substr($c['send_window_start'], 0, 5) . '〜' . substr($c['send_window_end'], 0, 5), $c['followup_enabled'] ? '有効' : '-',
        ], $db->all('SELECT * FROM campaigns ORDER BY id DESC'));
        $render('キャンペーン', '<form method="post" class="filters">' . $csrfField() . '<input name="name" placeholder="新しいキャンペーン名" required><button>作成</button></form>'
            . View::table(['名称', '状態', '開始日', '1日上限', '時間帯', '追いメール'], $rows));

    // ---------- キャンペーン詳細 ----------
    case 'campaign':
        $id = (int)($_GET['id'] ?? 0);
        $c = $db->one('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? $redirect('p=campaigns');
        if ($isPost) {
            $action = (string)($_POST['action'] ?? '');
            $now = $app->clock->nowSql();
            try {
                if ($action === 'save') {
                    $days = array_values(array_intersect(array_map('intval', (array)($_POST['send_days'] ?? [])), [1, 2, 3, 4, 5, 6, 7]));
                    $start = (string)($_POST['send_window_start'] ?? '09:00');
                    $end = (string)($_POST['send_window_end'] ?? '17:00');
                    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                        throw new RuntimeException('送信時間帯が不正です');
                    }
                    $warmup = !empty($_POST['warmup_enabled']) ? 1 : 0;
                    $warm = new Mailer\Campaign\Warmup($app);
                    $step = max(0, min(count($warm->plan()) - 1, (int)($_POST['warmup_step'] ?? $c['warmup_step'])));
                    // ウォームアップ中は上限を計画どおりに自動設定する (手入力は無視)
                    $limit = $warmup ? $warm->limitFor($step) : max(0, min(50000, (int)($_POST['daily_limit'] ?? 200)));
                    $stepChanged = $step !== (int)$c['warmup_step'];
                    $db->run('UPDATE campaigns SET name = ?, start_date = ?, daily_limit = ?, send_window_start = ?, send_window_end = ?, send_days = ?,
                              followup_enabled = ?, followup_days = ?, warmup_enabled = ?, warmup_step = ?,
                              warmup_days_at_step = IF(?, 0, warmup_days_at_step), ab_min_per_variant = ?, updated_at = ? WHERE id = ?', [
                        trim((string)$_POST['name']) ?: $c['name'], ($_POST['start_date'] ?? '') ?: null, $limit, $start . ':00', $end . ':00',
                        implode(',', $days ?: [1, 2, 3, 4, 5]), !empty($_POST['followup_enabled']) ? 1 : 0, max(7, (int)($_POST['followup_days'] ?? 7)),
                        $warmup, $step, $stepChanged ? 1 : 0, max(50, (int)($_POST['ab_min_per_variant'] ?? 200)), $now, $id,
                    ]);
                    $auth->audit('campaign_save', ['id' => $id, 'daily_limit' => $limit]);
                    $addFlash('ok', '保存しました');
                } elseif ($action === 'status') {
                    $to = (string)($_POST['to'] ?? '');
                    if (!isset($campaignStatus[$to])) {
                        throw new RuntimeException('不正な状態');
                    }
                    if ($to === 'active' && (new Enqueuer($db, $app->clock, $app->config))->activeTemplateIds($id, 1) === []) {
                        throw new RuntimeException('有効な初回テンプレートがありません');
                    }
                    $db->run('UPDATE campaigns SET status = ?, updated_at = ? WHERE id = ?', [$to, $now, $id]);
                    $auth->audit('campaign_status', ['id' => $id, 'to' => $to]);
                    $addFlash('ok', '状態を「' . $campaignStatus[$to] . '」にしました');
                } elseif ($action === 'enqueue') {
                    $r = (new Enqueuer($db, $app->clock, $app->config))->enqueueInitial($id, [
                        'industry' => trim((string)($_POST['industry'] ?? '')), 'region' => trim((string)($_POST['region'] ?? '')),
                        'tag' => trim((string)($_POST['tag'] ?? '')), 'origin' => (string)($_POST['origin'] ?? ''), 'limit' => (int)($_POST['limit'] ?? 0),
                    ]);
                    $auth->audit('enqueue', ['id' => $id] + $r);
                    $addFlash('ok', "キューに {$r['queued']}件 追加しました (対象 {$r['candidates']}社)。配信中にすると cron が順次送信します。");
                } elseif ($action === 'ab_decide') {
                    (new Mailer\Campaign\AbTest($app))->decide($id, (int)($_POST['template_id'] ?? 0), '管理者が決定 (' . $auth->user() . ')');
                    $auth->audit('ab_decide', ['id' => $id, 'template' => (int)$_POST['template_id']]);
                    $addFlash('ok', 'A/Bテストの勝ちパターンを決定しました。未送信キューはこのパターンで送ります。');
                } elseif ($action === 'ab_status') {
                    $to = ($_POST['to'] ?? '') === 'off' ? 'off' : 'testing';
                    $db->run('UPDATE campaigns SET ab_status = ?, ab_winner_id = NULL, updated_at = ? WHERE id = ?', [$to, $now, $id]);
                    $auth->audit('ab_status', ['id' => $id, 'to' => $to]);
                    $addFlash('ok', $to === 'off' ? 'A/Bの自動決定を止めました (有効なパターンを均等に送り続けます)' : 'A/Bテストを再開しました');
                } elseif ($action === 'cancel_queue') {
                    if (empty($_POST['confirm'])) {
                        throw new RuntimeException('「本当に取り消す」にチェックを入れてください');
                    }
                    $n = $db->run("UPDATE send_queue SET status = 'cancelled', last_error = 'cancelled by admin', updated_at = ? WHERE campaign_id = ? AND status IN ('queued', 'retry')", [$now, $id])->rowCount();
                    $auth->audit('cancel_queue', ['id' => $id, 'n' => $n]);
                    $addFlash('ok', "未送信 {$n}件を取り消しました");
                }
            } catch (Throwable $e) {
                $addFlash('error', $e->getMessage());
            }
            $redirect('p=campaign&id=' . $id);
        }
        $q = $stats->queueCounts($id);
        $days = array_map('intval', explode(',', $c['send_days']));
        $dayBoxes = '';
        foreach ([1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'] as $n => $label) {
            $dayBoxes .= '<label class="inline"><input type="checkbox" name="send_days[]" value="' . $n . '"' . (in_array($n, $days, true) ? ' checked' : '') . '>' . $label . '</label>';
        }
        $statusButtons = '';
        foreach (['active' => '配信開始', 'paused' => '一時停止', 'completed' => '完了にする', 'draft' => '下書きに戻す'] as $to => $label) {
            if ($to !== $c['status']) {
                $statusButtons .= '<form method="post" class="inline">' . $csrfField() . '<input type="hidden" name="action" value="status"><input type="hidden" name="to" value="' . $to . '"><button' . ($to === 'paused' ? ' class="danger"' : '') . '>' . $label . '</button></form> ';
            }
        }
        $tplRows = array_map(static fn($t) => [
            $t['step'] == 1 ? '初回' : '追いメール', $t['variant'], $t['subject'], (int)$t['sent'],
            View::pct($t['sent'] ? $t['opened'] / $t['sent'] : 0), View::pct($t['sent'] ? $t['clicked'] / $t['sent'] : 0),
            View::pct($t['sent'] ? $t['replied'] / $t['sent'] : 0), (int)$t['bounced'], (int)$t['complained'], (int)$t['unsubscribed'],
            ['html' => '<a href="?p=template&id=' . (int)$t['id'] . '">編集</a>'],
        ], $stats->variants($id));
        // ウォームアップ
        $warm = new Mailer\Campaign\Warmup($app);
        $plan = $warm->plan();
        $stepOptions = [];
        foreach ($plan as $i => [$pd, $pl]) {
            $stepOptions[$i] = '段階' . ($i + 1) . ': 1日 ' . number_format($pl) . '件' . ($pd ? " ({$pd}営業日)" : ' (以降ずっと)');
        }
        $wlog = $db->all('SELECT * FROM warmup_log WHERE campaign_id = ? ORDER BY eval_date DESC LIMIT 30', [$id]);
        $resultLabels = ['start' => '開始', 'advance' => '引き上げ', 'stay' => '継続', 'hold' => '据え置き', 'low_volume' => '件数不足', 'pause' => '停止'];
        $cur = min((int)$c['warmup_step'], count($plan) - 1);
        $warmupHtml = '<h2>ウォームアップ</h2>'
            . ($c['warmup_enabled']
                ? '<p>現在: <strong>段階' . ($cur + 1) . ' (1日 ' . number_format($plan[$cur][1]) . '件)</strong>'
                    . ($plan[$cur][0] ? ' — この段階の ' . (int)$c['warmup_days_at_step'] . ' / ' . $plan[$cur][0] . ' 営業日を完了' : ' — 最終段階')
                    . ((int)$c['warmup_bad_days'] ? ' <span class="bad">閾値超過 ' . (int)$c['warmup_bad_days'] . '日目 (2日続くと停止)</span>' : '') . '</p>'
                    . '<p class="muted">毎朝、前回の送信日のバウンス率 ' . View::pct((float)$app->config->get('warmup.max_bounce_rate', 0.02), 0)
                    . ' 未満・苦情率 ' . View::pct((float)$app->config->get('warmup.max_complaint_rate', 0.001), 1) . ' 未満なら日数を進めます。</p>'
                : '<p class="muted">ウォームアップは無効です (1日の上限は手動設定)。</p>')
            . View::table(['判定日', '対象の送信日', '送信', 'バウンス', '苦情', '結果', '上限', 'メモ'], array_map(static fn($w) => [
                $w['eval_date'], $w['target_date'], $w['sent'], $w['bounces'], $w['complaints'], $resultLabels[$w['result']] ?? $w['result'], number_format((int)$w['limit_after']), $w['note'],
            ], $wlog));
        // A/B
        $abRows = (new Mailer\Campaign\AbTest($app))->scores($id, false);
        $abStatusLabel = ['testing' => 'テスト中', 'decided' => '決定済み', 'off' => '自動決定しない'][$c['ab_status']] ?? $c['ab_status'];
        $abHtml = '<div class="card"><h2>A/Bテスト (初回メール): ' . $h($abStatusLabel) . '</h2>';
        if ($c['ab_status'] === 'testing') {
            $abHtml .= '<p class="muted">各パターン ' . (int)$c['ab_min_per_variant'] . '件 (24時間以上前の送信) に達するか、ウォームアップが段階3に入ったら自動で決定します。'
                . '点数 = (返信×10 + クリック×3 + 開封×1 − 停止×5 − 苦情×50) ÷ 送信数</p>';
        }
        if (count($abRows) >= 2) {
            $abHtml .= View::table(['パターン', '件名', '送信', '点数', ''], array_map(static fn($r) => [
                $r['variant'], $r['subject'], (int)$r['sent'], number_format((float)$r['score'], 3),
                ['html' => $c['ab_status'] !== 'decided'
                    ? '<form method="post" class="inline">' . $csrfField() . '<input type="hidden" name="action" value="ab_decide"><input type="hidden" name="template_id" value="' . (int)$r['id'] . '"><button class="small">これに決定</button></form>'
                    : ''],
            ], $abRows));
        } elseif ($c['ab_status'] === 'decided') {
            $abHtml .= '<p>決定したパターン: ' . $h($db->value('SELECT CONCAT(variant, \' / \', subject) FROM templates WHERE id = ?', [$c['ab_winner_id']])) . '</p>';
        } else {
            $abHtml .= '<p class="muted">有効な初回パターンが1つだけです。比較するには2〜3パターン登録してください。</p>';
        }
        $abHtml .= '<form method="post" class="inline">' . $csrfField() . '<input type="hidden" name="action" value="ab_status">'
            . ($c['ab_status'] === 'testing'
                ? '<input type="hidden" name="to" value="off"><button class="secondary small">自動決定しない</button>'
                : '<input type="hidden" name="to" value="testing"><button class="secondary small">A/Bテストをやり直す</button>')
            . '</form></div>';

        $body = '<p>状態: <strong>' . $h($campaignStatus[$c['status']] ?? $c['status']) . '</strong> ' . $statusButtons . '</p>'
            . '<div class="tiles">' . tile('未送信', $q['queued']) . tile('再送待ち', $q['retry']) . tile('送信済', $q['sent'])
            . tile('失敗', $q['failed']) . tile('取消', $q['cancelled']) . tile('結果不明', $q['unknown'], $q['unknown'] ? 'warn' : '') . '</div>'
            . '<h2>テンプレート (A/Bパターン)</h2>' . View::table(['種類', 'パターン', '件名', '送信', '開封率', 'クリック率', '返信率', 'バウンス', '苦情', '停止', ''], $tplRows)
            . $abHtml
            . '<p><a class="btn" href="?p=template&campaign_id=' . $id . '">＋ テンプレートを追加</a></p>'
            . '<div class="grid2"><form method="post" class="card">' . $csrfField() . '<h2>設定</h2><input type="hidden" name="action" value="save">'
            . '<label>名称<input name="name" value="' . $h($c['name']) . '"></label>'
            . '<label>開始日<input type="date" name="start_date" value="' . $h($c['start_date']) . '"></label>'
            . '<label class="inline"><input type="checkbox" name="warmup_enabled" value="1"' . ($c['warmup_enabled'] ? ' checked' : '') . '> ウォームアップ計画で上限を自動で引き上げる (推奨)</label>'
            . '<label>ウォームアップの段階<select name="warmup_step">' . View::options($stepOptions, (string)$c['warmup_step']) . '</select></label>'
            . '<label>1日の上限 (ウォームアップ中は段階の値が自動で入る)<input type="number" name="daily_limit" min="0" value="' . (int)$c['daily_limit'] . '"' . ($c['warmup_enabled'] ? ' readonly' : '') . '></label>'
            . '<label>A/Bテスト: 各パターンこの件数を送ったら自動で決定<input type="number" name="ab_min_per_variant" min="50" value="' . (int)$c['ab_min_per_variant'] . '"></label>'
            . '<label>送信時間帯<span class="row"><input type="time" name="send_window_start" value="' . substr($c['send_window_start'], 0, 5) . '"> 〜 <input type="time" name="send_window_end" value="' . substr($c['send_window_end'], 0, 5) . '"></span></label>'
            . '<div>送信曜日 ' . $dayBoxes . '</div>'
            . '<label class="inline"><input type="checkbox" name="followup_enabled" value="1"' . ($c['followup_enabled'] ? ' checked' : '') . '> 追いメールを送る (反応がない宛先に1通だけ)</label>'
            . '<label>初回から何営業日後以降 (7以上)<input type="number" name="followup_days" min="7" value="' . (int)$c['followup_days'] . '"></label>'
            . '<button>保存</button></form>'
            . '<form method="post" class="card">' . $csrfField() . '<h2>宛先をキューに追加</h2><input type="hidden" name="action" value="enqueue">'
            . '<p class="muted">送信可能な宛先 (取得元あり・お断り表示なし・抑止リスト外・MX確認済み) から1社1通で選び、同じドメインが続かないよう並べます。</p>'
            . '<label>取得区分<select name="origin">' . View::options(['' => 'すべて', 'own' => '自社収集のみ', 'agent' => '収集エージェントのみ'], '') . '</select></label>'
            . '<label>業種<input name="industry" placeholder="空欄ならすべて"></label><label>地域<input name="region" placeholder="空欄ならすべて"></label>'
            . '<label>タグ<input name="tag" placeholder="空欄ならすべて"></label><label>最大件数 (0 = 制限なし)<input type="number" name="limit" value="0" min="0"></label>'
            . '<button>キューに追加</button></form></div>'
            . '<form method="post" class="card">' . $csrfField() . '<input type="hidden" name="action" value="cancel_queue">'
            . '<label class="inline"><input type="checkbox" name="confirm" value="1" required> 本当に取り消す</label><button class="danger">未送信キューをすべて取り消す</button></form>'
            . $warmupHtml
            . '<h2>日次 (直近14日)</h2>' . dayTable($stats->days($today, 14, $id));
        $render('キャンペーン: ' . $c['name'], $body);

    // ---------- テンプレート編集 ----------
    case 'template':
        $id = (int)($_GET['id'] ?? 0);
        $t = $id ? $db->one('SELECT * FROM templates WHERE id = ?', [$id]) : null;
        $campaignId = (int)($t['campaign_id'] ?? $_GET['campaign_id'] ?? 0);
        $c = $db->one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]) ?? $redirect('p=campaigns');
        $form = $t ?? ['step' => 1, 'variant' => 'A', 'subject' => '', 'body_text' => "{{会社名}}\n{{担当者名|ご担当者}} 様\n\n", 'body_html' => '', 'active' => 1];
        $preview = '';
        if ($isPost) {
            $form = [
                'step' => (int)($_POST['step'] ?? 1), 'variant' => mb_substr(trim((string)($_POST['variant'] ?? 'A')), 0, 20) ?: 'A',
                'subject' => trim((string)($_POST['subject'] ?? '')), 'body_text' => str_replace("\r\n", "\n", (string)($_POST['body_text'] ?? '')),
                'body_html' => trim((string)($_POST['body_html'] ?? '')), 'active' => !empty($_POST['active']) ? 1 : 0,
            ];
            $errors = TemplateValidator::validate($form);
            if (($_POST['action'] ?? '') === 'preview' || $errors) {
                foreach ($errors as $e) {
                    $flash[] = ['error', $e];
                }
            } else {
                $now = $app->clock->nowSql();
                if ($t) {
                    // 送信済みのテンプレートは成績比較のため上書きせず、無効化して新規作成する
                    $used = (int)$db->value("SELECT COUNT(*) FROM send_queue WHERE template_id = ? AND status <> 'queued'", [$t['id']]);
                    $contentChanged = $form['subject'] !== $t['subject'] || $form['body_text'] !== $t['body_text'] || $form['body_html'] !== (string)$t['body_html'];
                    if ($used > 0 && $contentChanged) {
                        $db->run('UPDATE templates SET active = 0, updated_at = ? WHERE id = ?', [$now, $t['id']]);
                        $newId = $db->insert('templates', $form + ['campaign_id' => $campaignId, 'created_at' => $now, 'updated_at' => $now]);
                        $db->run("UPDATE send_queue SET template_id = ? WHERE template_id = ? AND status IN ('queued', 'retry')", [$newId, $t['id']]);
                        $auth->audit('template_version', ['old' => $t['id'], 'new' => $newId]);
                        $addFlash('ok', '送信済みのため旧版を無効化し、新しい版として保存しました (未送信キューは新しい版で送ります)。');
                        $redirect('p=template&id=' . $newId);
                    }
                    $db->run('UPDATE templates SET step = ?, variant = ?, subject = ?, body_text = ?, body_html = ?, active = ?, updated_at = ? WHERE id = ?',
                        [$form['step'], $form['variant'], $form['subject'], $form['body_text'], $form['body_html'] ?: null, $form['active'], $now, $t['id']]);
                    $auth->audit('template_save', ['id' => $t['id']]);
                    $addFlash('ok', '保存しました');
                    $redirect('p=template&id=' . $t['id']);
                }
                $newId = $db->insert('templates', ['campaign_id' => $campaignId, 'step' => $form['step'], 'variant' => $form['variant'],
                    'subject' => $form['subject'], 'body_text' => $form['body_text'], 'body_html' => $form['body_html'] ?: null,
                    'active' => $form['active'], 'created_at' => $now, 'updated_at' => $now]);
                $auth->audit('template_create', ['id' => $newId]);
                $addFlash('ok', '作成しました');
                $redirect('p=template&id=' . $newId);
            }
        }
        try {
            $sample = ['company_name' => '株式会社サンプル解体', 'person_name' => '', 'region' => '東京都', 'industry' => '解体工事業'];
            $r = (new TemplateRenderer($app->config))->render($form, $sample, rtrim((string)$app->config->get('public_base_url'), '/') . '/u.php?q=0&t=preview');
            $preview = '<h2>プレビュー (法定表示は自動で付きます)</h2><div class="card"><p><strong>件名:</strong> ' . $h($r['subject']) . '</p><pre class="mail">' . $h($r['text']) . '</pre></div>';
        } catch (Throwable $e) {
            $preview = '<div class="flash error">プレビューできません: ' . $h($e->getMessage()) . '</div>';
        }
        $body = '<p><a href="?p=campaign&id=' . $campaignId . '">← ' . $h($c['name']) . '</a></p>'
            . '<form method="post" class="card wide">' . $csrfField()
            . '<div class="row"><label>種類<select name="step">' . View::options([1 => '初回', 2 => '追いメール'], (string)$form['step']) . '</select></label>'
            . '<label>パターン名<input name="variant" value="' . $h($form['variant']) . '" size="6"></label>'
            . '<label class="inline"><input type="checkbox" name="active" value="1"' . ($form['active'] ? ' checked' : '') . '> 有効</label></div>'
            . '<label>件名<input name="subject" value="' . $h($form['subject']) . '" maxlength="100" required></label>'
            . '<label>テキスト本文 (必須・こちらが主)<textarea name="body_text" rows="16" required>' . $h($form['body_text']) . '</textarea></label>'
            . '<label>HTML本文 (任意)<textarea name="body_html" rows="6">' . $h($form['body_html']) . '</textarea></label>'
            . '<p class="muted">差し込み: {{会社名}} {{担当者名}} {{地域}} {{業種}} / 空欄時の既定値: {{担当者名|ご担当者}}<br>'
            . '送信者名・住所・電話・問い合わせ先・配信停止リンクは送信時に自動で末尾に付き、削除できません。「Re:」等の偽装件名は保存できません。</p>'
            . '<button name="action" value="save">保存</button> <button name="action" value="preview" class="secondary">プレビュー</button></form>'
            . $preview;
        $render($t ? 'テンプレート編集' : 'テンプレート作成', $body);

    // ---------- 返信の記録・手動停止 ----------
    case 'replies':
        if ($isPost) {
            $email = Mailer\Util\Email::normalize((string)($_POST['email'] ?? ''));
            $action = (string)($_POST['action'] ?? '');
            try {
                if ($email === null) {
                    throw new RuntimeException('メールアドレスが不正です');
                }
                if ($action === 'refuse') {
                    (new UnsubscribeService($db, $app->clock, $app->suppressions()))->refuseByEmail($email, (string)$auth->user(), 'reply_refusal', !empty($_POST['domain']));
                    $auth->audit('reply_refusal', ['email' => $email]);
                    $addFlash('ok', "{$email} を配信停止にしました (抑止リスト登録済み)");
                } elseif ($action === 'replied') {
                    $contact = $db->one('SELECT id FROM contacts WHERE email = ?', [$email]) ?? throw new RuntimeException('宛先リストに無いアドレスです');
                    $last = $db->one("SELECT id, campaign_id, ses_message_id FROM send_queue WHERE contact_id = ? AND status = 'sent' ORDER BY sent_at DESC LIMIT 1", [$contact['id']]);
                    ContactStatus::advance($db, $app->clock, (int)$contact['id'], 'replied');
                    $now = $app->clock->nowSql();
                    $db->insert('events', ['ses_message_id' => $last['ses_message_id'] ?? null, 'queue_id' => $last['id'] ?? null, 'contact_id' => $contact['id'],
                        'campaign_id' => $last['campaign_id'] ?? null, 'type' => 'reply', 'occurred_at' => $now,
                        'detail' => json_encode(['actor' => $auth->user(), 'note' => (string)($_POST['note'] ?? '')], JSON_UNESCAPED_UNICODE), 'dedupe_key' => null, 'created_at' => $now]);
                    $auth->audit('reply', ['email' => $email]);
                    $addFlash('ok', "{$email} を「返信あり」にしました (追いメールの対象から外れます)");
                }
            } catch (Throwable $e) {
                $addFlash('error', $e->getMessage());
            }
            $redirect('p=replies');
        }
        $recent = $db->all("SELECT e.type, e.occurred_at, c.email, co.name FROM events e JOIN contacts c ON c.id = e.contact_id JOIN companies co ON co.id = c.company_id
                             WHERE e.type IN ('reply', 'reply_refusal', 'unsubscribe') ORDER BY e.id DESC LIMIT 50");
        $body = '<div class="grid2"><form method="post" class="card">' . $csrfField() . '<h2>返信で「不要」と言われた</h2><input type="hidden" name="action" value="refuse">'
            . '<label>メールアドレス<input name="email" type="email" required></label>'
            . '<label class="inline"><input type="checkbox" name="domain" value="1"> 会社 (ドメイン) 全体を停止</label>'
            . '<button class="danger">配信停止に登録</button></form>'
            . '<form method="post" class="card">' . $csrfField() . '<h2>返信があった (商談化など)</h2><input type="hidden" name="action" value="replied">'
            . '<label>メールアドレス<input name="email" type="email" required></label><label>メモ<input name="note"></label>'
            . '<button>返信ありにする</button></form></div>'
            . '<h2>最近の記録</h2>' . View::table(['日時', '種別', '会社', 'アドレス'], array_map(static fn($r) => [
                $r['occurred_at'], ['reply' => '返信', 'reply_refusal' => '返信で不要', 'unsubscribe' => '配信停止'][$r['type']], $r['name'], $r['email'],
            ], $recent));
        $render('返信・手動停止', $body);

    // ---------- キュー確認 (結果不明・失敗) ----------
    case 'queue':
        if ($isPost) {
            $qid = (int)($_POST['id'] ?? 0);
            $to = (string)($_POST['to'] ?? '');
            if (in_array($to, ['sent', 'failed'], true)) {
                $n = $db->run("UPDATE send_queue SET status = ?, last_error = CONCAT(COALESCE(last_error, ''), ' / resolved by admin'), updated_at = ? WHERE id = ? AND status = 'unknown'",
                    [$to, $app->clock->nowSql(), $qid])->rowCount();
                $auth->audit('queue_resolve', ['id' => $qid, 'to' => $to]);
                $addFlash('ok', $n ? '更新しました' : '対象がありません');
            }
            $redirect('p=queue');
        }
        $st = array_key_exists($_GET['status'] ?? '', $queueStatus) ? $_GET['status'] : 'unknown';
        $list = $db->all('SELECT q.*, c.email, ca.name campaign FROM send_queue q JOIN contacts c ON c.id = q.contact_id JOIN campaigns ca ON ca.id = q.campaign_id
                           WHERE q.status = ? ORDER BY q.updated_at DESC LIMIT 200', [$st]);
        $tabs = '';
        foreach ($stats->queueCounts() as $k => $n) {
            $tabs .= '<a href="?p=queue&status=' . $k . '"' . ($k === $st ? ' class="on"' : '') . '>' . $h($queueStatus[$k] ?? $k) . ' (' . $n . ')</a> ';
        }
        $rows = array_map(static function ($r) use ($csrfField, $st) {
            $actions = '';
            if ($st === 'unknown') {
                foreach (['sent' => '送信済とする', 'failed' => '未送信(失敗)とする'] as $to => $label) {
                    $actions .= '<form method="post" class="inline">' . $csrfField() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="to" value="' . $to . '"><button class="small">' . $label . '</button></form>';
                }
            }
            return [$r['id'], $r['campaign'], $r['email'], $r['step'], $r['attempts'], $r['scheduled_at'], $r['sent_at'], $r['ses_message_id'], $r['last_error'], ['html' => $actions]];
        }, $list);
        $body = '<p class="tabs">' . $tabs . '</p>'
            . ($st === 'unknown' ? '<p class="muted">送信中に処理が止まった、または通信タイムアウトで「SESが受け付けたか分からない」宛先です。二重送信を避けるため自動では再送しません。'
                . 'SESの送信ログ (または受信者側) で確認できない場合は「送信済とする」を推奨します。</p>' : '')
            . View::table(['ID', 'キャンペーン', '宛先', 'ステップ', '試行', '予定', '送信', 'SES ID', 'エラー', ''], $rows);
        $render('キュー確認', $body);

    // ---------- レポート ----------
    case 'reports':
        $cid = (int)($_GET['campaign_id'] ?? 0) ?: null;
        $camps = ['' => '全キャンペーン'];
        foreach ($db->all('SELECT id, name FROM campaigns ORDER BY id DESC') as $c) {
            $camps[$c['id']] = $c['name'];
        }
        $days = $stats->days($today, 30, $cid);
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="report-' . $today . '.csv"');
            echo "\xEF\xBB\xBF日付,送信,到達,開封,クリック,返信,永久バウンス,一時バウンス,苦情,配信停止,失敗\n";
            foreach ($days as $d) {
                echo implode(',', [$d['date'], $d['sent'], $d['delivery'], $d['open'], $d['click'], $d['reply'], $d['bounce_hard'], $d['bounce_soft'], $d['complaint'], $d['unsubscribe'], $d['failed']]), "\n";
            }
            exit;
        }
        $body = '<form method="get" class="filters"><input type="hidden" name="p" value="reports"><select name="campaign_id">' . View::options($camps, (string)$cid) . '</select><button>表示</button> '
            . '<a class="btn secondary" href="?p=reports&format=csv&campaign_id=' . (int)$cid . '">CSVダウンロード</a></form>'
            . dayTable($days);
        $render('レポート (直近30日)', $body);

    default:
        $redirect('p=dashboard');
}

function tile(string $label, string|int $value, string $class = ''): string
{
    return '<div class="tile ' . $class . '"><div class="tl">' . View::h($label) . '</div><div class="tv">' . View::h($value) . '</div></div>';
}

function dayTable(array $days): string
{
    return View::table(['日付', '送信', '到達', '開封率', 'クリック率', '返信', 'バウンス率', '苦情率', '配信停止', '失敗'], array_map(static fn($d) => [
        $d['date'], $d['sent'], $d['delivery'], View::pct($d['open_rate']), View::pct($d['click_rate']), $d['reply'],
        ['html' => '<span class="' . ($d['bounce_rate'] >= 0.02 ? 'bad' : '') . '">' . View::pct($d['bounce_rate'], 2) . '</span>'],
        ['html' => '<span class="' . ($d['complaint_rate'] >= 0.001 ? 'bad' : '') . '">' . View::pct($d['complaint_rate'], 3) . '</span>'],
        $d['unsubscribe'], $d['failed'],
    ], $days));
}
