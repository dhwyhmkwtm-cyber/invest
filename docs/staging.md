# テスト環境 (本番と同じ環境で動作確認する)

目的: **このテスト環境で全部動けば、本番でも同じように動く** 状態を作る。
そのため、テスト環境は本番と同じロリポップのサーバー・同じ PHP・同じ MySQL・同じ cron・同じ SES・同じ DNS 設定で動かし、違うのは「データベース」「置き場所」「送ってよい宛先」だけにする。

## 本番との違い

| 項目 | テスト環境 | 本番 |
|---|---|---|
| サーバー / PHP / cron | 同じロリポップ | 同じ |
| 置き場所 | `~/mailer-test/app` と `~/web/mailer-test/` | `~/mailer/app` と `~/web/mailer/` |
| データベース | `mailer_test` (別DB) | `mailer` |
| SES・送信ドメイン・DNS | 同じ (フェーズ1で設定したもの) | 同じ |
| SES の設定セット / SNSトピック | `mailer-test-events` / `mailer-test-ses-events` | `mailer-events` / `mailer-ses-events` |
| `environment` | `staging` | `production` |
| 送れる宛先 | `allowed_recipients` に書いたものだけ (社内ドメイン・SESシミュレーター) | 抑止リスト外の全宛先 |

## 二重の安全装置

1. **テストモード** (`environment => 'staging'`): `allowed_recipients` に一致しない宛先は、キューに入っていても送らずに「取消」にする。許可リストが空なら一切送らない。管理画面の上部に「テスト環境」と表示される
2. **SES のサンドボックス**: 本番利用の申請前は、SES に登録して認証したアドレスにしか届かない。テストの段階では申請しないままにしておくと、仮に設定を間違えても社外には届かない

## 手順

1. フェーズ1 の AWS・DNS 設定をする ([setup-aws.md](setup-aws.md))。サンドボックスのままでよい。社内のテスト用アドレスを SES の「ID」に登録して認証しておく
2. テスト用の設定セット `mailer-test-events` と SNS トピックを作り、購読先を `https://news.example.co.jp/mailer-test/sns.php` にする
3. ロリポップに配置する ([deploy-lolipop.md](deploy-lolipop.md) の手順で、置き場所と DB 名だけ `mailer-test` にする)
4. `config.php` を次のようにする:

   ```php
   'environment' => 'staging',
   'public_base_url' => 'https://news.example.co.jp/mailer-test',
   'sending' => [
       'allowed_recipients' => ['@example.co.jp', '@simulator.amazonses.com'],
       // その他は本番と同じ値
   ],
   'aws' => ['configuration_set' => 'mailer-test-events', 'sns_topic_arns' => ['arn:...:mailer-test-ses-events'], ...],
   ```

5. cron を登録する (テスト用に1本。`~/mailer-test/app/bin/cron.php`)
6. 環境チェックを実行し、**NG が 0 件になるまで直す**:

   ```sh
   php ~/mailer-test/app/bin/doctor.php
   ```

7. フェーズ1 のテスト送信 (`bin/test_send.php`) と、フェーズ2 の受け入れ確認 ([acceptance-phase2.md](acceptance-phase2.md)) をこの環境で行う

## 環境チェック (doctor.php) で確認する項目

- PHP のバージョンと必要な拡張機能
- 設定ファイル: 法定表示の送信者情報、秘密鍵、URL、IP制限、通知先、テスト/本番の別と送信許可先
- データベース: 接続、テーブル、管理者ユーザー、cron が最近動いたか、配信が一時停止中でないか
- ファイル: ログ用ディレクトリに書き込めるか、app/ が公開ディレクトリの外にあるか
- 公開ページ: 配信停止ページが開けるか、SNS受信口が署名のない通知を拒否するか、`_app.php` が外から見えないか
- SES: 認証キーが有効か、サンドボックスかどうか、送信枠、送信ドメインの認証・DKIM・MAIL FROM
- DNS: DMARC、SPF、MAIL FROM の MX

## 本番への切り替え

テスト環境で受け入れ確認がすべて済んだら:

1. 本番用に同じ手順で `~/mailer` を配置する (コードは同じもの)
2. `config.php` を `environment => 'production'`、本番 DB、本番の設定セット・SNS トピックにする
3. `php ~/mailer/app/bin/doctor.php` で NG 0 件を確認する
4. SES の本番利用を申請する
5. ウォームアップ計画 (1日200件から) に沿って配信を始める
