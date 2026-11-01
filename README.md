# 営業メール配信ツール

要件定義書 (2026-11-01) に基づく、Amazon SES + ロリポップ (PHP 8 / MySQL) の営業メール配信ツール。
現在の実装範囲は **フェーズ2・3 (F2〜F7)**。収集エージェント (F1) は未着手。

| フェーズ | 内容 | 状態 |
|---|---|---|
| 1 | SES・DNS (SPF/DKIM/DMARC) の準備 | 手順書: [docs/setup-aws.md](docs/setup-aws.md)、確認ツール `bin/doctor.php`・`bin/test_send.php` |
| 2 | リスト取り込み・抑止リスト・キュー・cron送信・SES通知・配信停止・管理画面 | **実装済み** ([受け入れ確認](docs/acceptance-phase2.md)) |
| 3 | テンプレート・法定表示の強制・A/Bテストの自動決定・ウォームアップの自動引き上げ (F3・F5) | **実装済み** ([受け入れ確認](docs/acceptance-phase3.md)、[文面作成の手順書](docs/copywriting-guide.md)) |
| 4 | 収集エージェント (F1) | 未着手。収集結果のCSVは現在の取り込み画面で読み込める |

## 構成

```
app/                ← 公開ディレクトリの外に置く (ロリポップなら ~/mailer/app など)
  bootstrap.php
  config/config.sample.php   設定ひな形 → config.php にコピー (gitに入れない)
  sql/schema.sql             テーブル定義 (主要7テーブル + 設定・管理者・ログ)
  src/                       本体 (外部ライブラリ不要)
  bin/cron.php               cron に1本だけ登録する定期処理
  bin/migrate.php            テーブル作成・更新 (何度実行しても安全)
  bin/create_admin.php       管理者の作成
  bin/test_send.php          フェーズ1のテスト送信
  bin/check_mx.php           MXチェックの一括実行
  bin/purge.php              個人情報・ログの削除手順
  bin/doctor.php             環境チェック (これが全部OKなら本番でも動く)
  tests/                     テスト (php tests/run.php)
public/             ← 公開ディレクトリに置く (例: web/mailer/)
  u.php                      配信停止ページ + RFC 8058 ワンクリック停止
  sns.php                    SES通知 (SNS) の受信エンドポイント
  admin/                     管理画面
```

### 送信の流れ

1. 管理画面で CSV/Excel を取り込む → 重複統合・書式チェック・お断り表示は抑止リストへ
2. MXチェック (cron が空き時間に自動実行、管理画面からも実行可)
3. キャンペーンを作り、テンプレートを登録し、「キューに追加」(1社1通・同一ドメインが続かない順)
4. 状態を「配信中」にすると、cron (数分ごと) が時間帯・1日/1時間上限・送信レートを守って少量ずつ SES に送る
5. SES の通知 (配信・バウンス・苦情・開封・クリック) が SNS → `sns.php` に届き、永久バウンスと苦情は即座に抑止リストへ
6. 配信停止 (本文リンク / メールソフトのワンクリック) は即時に抑止リストへ入り、未送信キューも取り消す
7. 直近の苦情率 0.1% 超 / バウンス率 5% 超で全体を自動停止し、管理者に通知

### 法令遵守を仕組みで保証している箇所

| 要件 | 実装 |
|---|---|
| 取得元URL・取得日時の記録がない宛先は送信不可 | `Enqueuer::eligibilitySql()` でキュー投入時と **送信直前** の2回確認 |
| お断り表示のある宛先には送らない | 取り込み時にフラグ + 抑止リスト登録。送信直前にも確認 |
| 配信停止した宛先には二度と送らない | グローバル抑止リスト (アドレス/ドメイン単位)。画面から削除不可。どのリスト・キャンペーンからも除外 |
| 送信者名・住所・電話/メール・配信停止URLの表示 | `TemplateRenderer` が送信時に必ず末尾へ付加 (テンプレートに保存しないので削除できない)。設定が欠けていればワーカーは送信しない |
| 「Re:」偽装・誇大件名の禁止 | `TemplateValidator` で保存を拒否 |
| 送信元を偽らない | From は SES で認証した送信専用サブドメインのみ。Reply-To は営業担当の実在アドレス |

### 二重送信の防止

- 1キャンペーン × 1宛先 × 1ステップにDBの一意制約。さらに送信直前に「同じ会社に送信済みでないか」を確認
- 送信前に行を `sending` にロック → SES 成功で `sent` に確定
- 送信中にプロセスが落ちた行・通信タイムアウトで結果が分からない行は **自動再送しない**。`結果不明` にして管理者が「キュー確認」画面で判断する
- cron の多重起動はファイルロックで防止

## セットアップ

0. **まずテスト環境で動作確認する**: [docs/staging.md](docs/staging.md) (本番と同じサーバー・SES・DNSで、許可した宛先にだけ送るモード)。`bin/doctor.php` で環境を一括チェックできる
1. フェーズ1 (AWS・DNS): [docs/setup-aws.md](docs/setup-aws.md)
2. ロリポップへの配置・cron 登録: [docs/deploy-lolipop.md](docs/deploy-lolipop.md)
3. フェーズ2 受け入れ確認 (社内50件): [docs/acceptance-phase2.md](docs/acceptance-phase2.md)

## テスト

MySQL 8 / MariaDB のテスト用DBを用意して実行する (テストのたびに全テーブルを作り直すので、本番DBは絶対に指定しないこと)。

```sh
MAILER_TEST_DSN='mysql:host=127.0.0.1;dbname=mailer_test;charset=utf8mb4' \
MAILER_TEST_USER=mailer MAILER_TEST_PASS=mailer php app/tests/run.php
```

SES は偽の通信層に差し替えてテストするので、AWS には接続しない。フェーズ2の受け入れ基準は `tests/Phase2AcceptanceTest.php` に対応するテストがある。

## 設計上の判断 (要件定義書との差分)

- **AWS SDK for PHP は使っていない。** SES API v2 の呼び出し (SigV4 署名) と SNS 署名検証を自前で実装した (`src/Ses/SigV4Signer.php` は AWS 公式テストベクタで検証済み)。共用サーバーで Composer や数十MBの vendor を置かずに済み、FTP/SSH でファイルを置くだけで動く。SDK に切り替える場合は `SesClient` を差し替えればよい
- **cron は1本。** ロリポップの cron 登録数の上限に合わせ、`bin/cron.php` が送信・追いメール予約・MXチェック・日次レポートをまとめて実行する
- **管理者への通知も SES 経由。** ロリポップのメールサーバーからは1通も送らない
- **送信済みテンプレートを編集すると新しい版を作る。** A/B の成績が混ざらないようにするため
- **配信停止ページは確認ボタン付き。** メールのリンク検査ツールが URL を開いただけで停止されないようにするため。メールソフトのワンクリック停止 (List-Unsubscribe-Post) は確認なしで即時に反映する
