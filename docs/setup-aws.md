# フェーズ1: 送信基盤の準備 (AWS SES・DNS)

例として、会社ドメインを `example.co.jp`、送信専用サブドメインを `news.example.co.jp`、リージョンを東京 (`ap-northeast-1`) とする。

## 1. SES で送信用サブドメインを認証

1. AWS コンソール → Amazon SES → 「ID (Identities)」→「ID の作成」→ ドメイン `news.example.co.jp`
2. **Easy DKIM (RSA 2048)** を有効にする → 表示される CNAME 3件を DNS に登録
3. **カスタム MAIL FROM ドメイン** を `bounce.news.example.co.jp` に設定 → 表示される MX と TXT を DNS に登録 (SPF を DMARC と揃えるため)

## 2. DNS に登録するレコード

| 名前 | 種類 | 値 |
|---|---|---|
| `xxxx._domainkey.news.example.co.jp` ×3 | CNAME | SES が表示する値 (DKIM) |
| `bounce.news.example.co.jp` | MX | `10 feedback-smtp.ap-northeast-1.amazonses.com` |
| `bounce.news.example.co.jp` | TXT | `v=spf1 include:amazonses.com ~all` |
| `_dmarc.news.example.co.jp` | TXT | `v=DMARC1; p=none; rua=mailto:dmarc@example.co.jp` (安定したら `p=quarantine` に) |
| `news.example.co.jp` | A / CNAME | ロリポップのサーバー (配信停止ページ・SNS受信を置く場合) |

- 業務メール (`example.co.jp`) の MX・SPF には一切手を入れない。広告メールの評判が業務メールに影響しないようにするため
- 配信停止ページを別のホスト名に置く場合は `public_base_url` をそれに合わせる。受信者の安心感のため、送信ドメインと同じ会社ドメイン配下を推奨

## 3. 設定セットとイベント通知 (SNS)

1. SNS → トピック作成 (標準) `mailer-ses-events`
2. SES → 設定セット作成 `mailer-events`
   - 「イベントの送信先」→ SNS → 上記トピック
   - イベント: 送信 / 配信 / バウンス / 苦情 / 拒否 / 開封 / クリック / 配信遅延 / レンダリング失敗
   - 開封・クリックの計測を有効化 (可能ならカスタムのトラッキングドメインを設定する。共有の `awstrack.me` より迷惑メール判定されにくい)
3. SNS → トピック → サブスクリプション作成
   - プロトコル HTTPS、エンドポイント `https://news.example.co.jp/mailer/sns.php`
   - Basic 認証を使う場合は `https://ユーザー:パスワード@news.example.co.jp/mailer/sns.php` とし、config の `aws.sns_basic_auth` に同じ値を入れる
   - 作成すると `sns.php` が自動で購読確認を行う (ログ: `app/var/sns-YYYYMM.log`)
4. config の `aws.sns_topic_arns` にトピック ARN を入れる (それ以外の ARN からの通知は破棄する)

## 4. 送信専用 IAM ユーザー (最小権限)

IAM → ユーザー作成 (コンソールアクセスなし) → 次のインラインポリシーだけを付け、アクセスキーを発行して config に入れる。

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["ses:SendEmail", "ses:SendRawEmail"],
    "Resource": [
      "arn:aws:ses:ap-northeast-1:<アカウントID>:identity/news.example.co.jp",
      "arn:aws:ses:ap-northeast-1:<アカウントID>:configuration-set/mailer-events"
    ],
    "Condition": { "StringLike": { "ses:FromAddress": "*@news.example.co.jp" } }
  }]
}
```

- 環境チェック (`bin/doctor.php`) で SES の状態を見たい場合は、読み取り専用の権限を別の Statement で追加する:
  `{"Effect": "Allow", "Action": ["ses:GetAccount", "ses:GetEmailIdentity"], "Resource": "*"}`
- サンドボックス中は宛先も認証済み ID である必要があり、その ID も Resource に追加しないと拒否される (本番化後は不要)
- アクセスキーは `app/config/config.php` (公開ディレクトリ外) にだけ書く

## 5. 本番利用の申請 (サンドボックス解除)

SES → アカウントダッシュボード →「本番アクセスをリクエスト」。記入例の要点:

- 用途: 自社商材 (石綿廃棄物用資材) の法人向け案内。特定電子メール法に基づき、業務用として公表されたアドレスのみに送信
- 配信停止: 全メールに配信停止リンクとワンクリック配信停止ヘッダーを付け、即時反映
- バウンス・苦情: SNS で受信して即時に送信停止リストへ登録。苦情率 0.1% 超で自動停止
- 送信量: 1日200件から段階的に最大3,000件

## 6. 受け入れ確認 (フェーズ1)

```sh
php app/bin/test_send.php 自分のアドレス@gmail.com
php app/bin/test_send.php 自分のアドレス@outlook.jp
```

Gmail は「メッセージのソースを表示」、Outlook は「メッセージのソース」で確認する。

- [ ] 受信箱に届く (迷惑メールフォルダではない)
- [ ] `SPF: PASS` (`bounce.news.example.co.jp`)
- [ ] `DKIM: PASS` (`d=news.example.co.jp`)
- [ ] `DMARC: PASS`
- [ ] `List-Unsubscribe` と `List-Unsubscribe-Post` ヘッダーがあり、DKIM-Signature の `h=` に含まれている (Gmail のワンクリック停止の条件)
