# 配達メッセージ

iPhone 標準の Siri・ショートカット・ファイルアプリだけで、音声で定型文を検索・表示・コピーする仕組み（開発仕様書 Ver.1.3）。専用アプリ・外部API・Apple Intelligence は使わない。

現在の段階: **第1段階（MVP）実装準備完了・実機テスト未実施**

## 使い方（完成後）

1. 「Hey Siri、配達メッセージ」
2. 「3番」や「到着」などと答える
3. 表示された本文を確認し「コピーする」または「終了する」を選ぶ（コピーされるのは本文だけ）

## ファイル構成

| パス | 内容 |
|---|---|
| `docs/01_feasibility.md` | Apple 公式資料に基づく技術的実現可能性・データ形式の比較 |
| `docs/02_design.md` | 最小構成・データ構造・検索仕様・エラー処理 |
| `docs/03_build_guide.md` | iPhone でのショートカット作成・導入手順（アクション単位） |
| `docs/04_test_plan.md` | 実機テスト手順とチェック表 |
| `docs/05_open_issues.md` | 未解決の制約・要確認事項・機能別ステータス |
| `docs/06_user_dictionary.md` | 配達用定型文の一覧とユーザ辞書の一括登録手順 |
| `data/messages.json` | 配達用定型文データ（35件。ユーザ辞書の「よみ」付き） |
| `dist/user_dictionary.plist` | Mac のユーザ辞書へドラッグして一括登録するファイル |
| `tests/fixtures/sample_messages.json` | テスト用サンプルデータ（実機テストでも使用） |
| `data/messages.schema.json` | データの JSON Schema |
| `shortcut/normalize_map.json` | ショートカットに貼り付ける正規化表（全角数字・漢数字） |
| `tools/haitatsu.py` | 参照実装（ショートカットと同じ検索手順の再現・データ検証） |
| `tests/` | 単体テストと検索テストケース（実機テストと共通） |

## PC での確認

```sh
python3 tools/haitatsu.py validate data/messages.json   # データ検証
python3 tools/haitatsu.py search data/messages.json "三番"  # 検索の再現
python3 tools/haitatsu.py export-plist data/messages.json dist/user_dictionary.plist  # ユーザ辞書用ファイル
python3 -m unittest discover -s tests -v                 # 単体テスト
```

Python 3.9 以降・標準ライブラリのみ。
