# ロリポップへの配置

前提: ハイスピードプラン (SSH・cron が使える)。具体的なパスとPHPのバージョンは契約サーバーで確認すること (要件定義書 第10章の要確認事項)。

## 1. ファイルの配置

SSH でログインし、ホーム直下 (公開ディレクトリ `web/` の外) に置く。

```
~/mailer/app/        ← このリポジトリの app/ (公開しない)
~/web/mailer/        ← このリポジトリの public/ の中身 (公開)
```

`~/web/mailer/_app.php` を編集して、app の場所を指す:

```php
return '/home/users/0/<ログインID>/mailer/app';
```

万一 app/ を公開ディレクトリに置いてしまった場合に備え、`app/.htaccess` と `app/config/.htaccess` で全アクセスを拒否している。

## 2. データベース

1. ロリポップの管理画面 →「サーバーの管理・設定」→「データベース」で作成 (MySQL 8 推奨)
2. 表示されたホスト名・DB名・ユーザー・パスワードを `app/config/config.php` の `db` に入れる
3. テーブル作成と管理者作成:

```sh
cd ~/mailer/app
cp config/config.sample.php config/config.php   # 値を埋める
chmod 600 config/config.php
php bin/migrate.php
php bin/create_admin.php admin
```

`php` が古い版を指す場合は、`/usr/local/php/8.3/bin/php` のように版を指定したパスで実行する (要確認)。

## 3. 設定 (config.php) の必須項目

- `sender.*` 送信者名・住所・電話・問い合わせ先・From・Reply-To (欠けていると送信しない)
- `public_base_url` 例 `https://news.example.co.jp/mailer`
- `app_secret` `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` の出力
- `aws.*` リージョン・IAMキー・設定セット名・SNSトピックARN
- `admin.allowed_ips` 社内の固定IP (管理画面のIP制限)
- `notify.admin_emails` 異常通知・日次レポートの送り先
- `sending.holidays` 祝日・休業日

## 4. cron

ロリポップの管理画面 →「cron 設定」で1本だけ登録する (設定画面で選べる最短の間隔。5分ごと程度を推奨)。

```
/usr/local/php/8.3/bin/php /home/users/0/<ログインID>/mailer/app/bin/cron.php
```

- 1回の実行は25秒以内で区切る (`sending.run_time_budget`)。送れなかった分は次回に回る
- 1回あたりの最大件数は `sending.max_per_run`。5分ごと × 100件 で、1時間あたり最大1,200件 (別途 `hourly_limit` で制限)
- 実行ログは `app/var/cron-YYYYMM.log`、エラーは `app/var/error-YYYYMM.log`

## 5. 管理画面

`https://news.example.co.jp/mailer/admin/` (IP制限 + ID/パスワード)。必ず HTTPS (ロリポップの無料SSL) で使うこと。

## 6. 個人情報・ログの削除

```sh
php bin/purge.php logs              # 400日より古い送信ログ・イベントを削除 (1年以上保存の要件を満たす)
php bin/purge.php contact x@y.co.jp # 特定の宛先のデータを削除 (抑止リストには残す)
php bin/purge.php batch <バッチID>   # 取り込み単位で未送信の宛先を削除
```
