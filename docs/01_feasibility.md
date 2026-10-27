# 01. 技術的実現可能性（Step 1）

Ver.1.3 仕様 第1段階（MVP）について、必要な機能ごとに実現可能性を整理する。

## 根拠の区分

| 区分 | 意味 |
|---|---|
| **公式** | Apple 公式資料（Shortcuts User Guide / Apple Support）で記述を確認したもの |
| **一般知見** | 公式資料に明記はないが、ショートカットで広く使われている動作。実機確認が必要 |
| **要実機** | 公式資料・一般知見ともに確証がなく、実機検証で判断するもの |

> 補足: 調査環境から support.apple.com 本文への直接アクセスができず、検索結果に表示された公式ページの記述から確認した。参照ページは末尾に記載する。

## 機能別の判定

| # | 必要機能 | 使うアクション / 機能 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | Siriにショートカット名を話して起動 | Siri（「Hey Siri, 配達メッセージ」） | 可能 | **公式**: ショートカット名を Siri に話すと実行される。名前が Siri 標準コマンドと一致した場合はショートカットが優先される |
| 2 | 番号・キーワードの音声入力 | 「入力を要求」（Ask for Input・テキスト） | 可能（要実機） | **公式**: 質問ダイアログを表示し、テキスト・数値・日付を受け付ける。**一般知見**: Siri 経由で実行した場合は質問が読み上げられ、音声で回答できる。公式資料に音声回答の明記はないため実機確認必須 |
| 3 | JSON データの読み込み | 「ファイル」または「フォルダからファイルを取得」→「入力から辞書を取得」 | 可能 | **公式**: 「入力から辞書を取得」は JSON / plist テキストから辞書を作る。「辞書の値を取得」でキー指定・ドット記法による値取得が可能 |
| 4 | 検索（完全一致・正規化） | 「各項目を繰り返す」「if文」「テキストを置き換える」（正規表現）「テキストを照合」「大文字/小文字を変更」 | 可能 | **一般知見**: いずれも標準アクション。正規表現は ICU 方式。参照実装 `tools/haitatsu.py` で同じ手順を検証済み |
| 5 | 複数候補の提示 | 「リストから選択」 | 可能 | **公式**: リスト／メニューから選ぶアクションがある |
| 6 | 番号・キーワード・本文の表示 | 「メニューから選択」の見出し（プロンプト） | 可能（要実機） | 長文時の見出しの省略・Siri での読み上げ挙動は**要実機** |
| 7 | 「コピーする」「終了する」の提示 | 「メニューから選択」 | 可能 | **公式**: メニューから選択アクション |
| 8 | 本文のみクリップボードへコピー | 「クリップボードにコピー」 | 可能 | **公式**: 「ローカルのみ」（Handoff で共有しない）、「有効期限」オプションあり |
| 9 | 終了時にクリップボードを変更しない | コピーアクションを通らない分岐 | 可能 | 構造上保証（分岐内にコピー処理がない） |
| 10 | オフライン検索 | 端末内ファイル、または iCloud Drive ファイルの「ダウンロードしたままにする」 | 条件付き可能 | ショートカット自体はネット不要。iCloud Drive 上のファイルは端末から退避されることがあるため、**このiPhone内** への保存を推奨。iOS 18 以降は Files に「ダウンロードしたままにする」がある（**一般知見**・要実機） |
| 11 | ショートカットの自動生成・配布 | `.shortcut` ファイル / iCloud リンク | **制約あり** | **公式**: macOS の `shortcuts sign` で署名したファイルのみ他者が読み込める。署名には Mac と Apple のサーバー接続が必要。iPhone のみ・Linux 上では署名済みファイルを作れないため、**手作業で組み立てる手順書を提供**する（→ `03_build_guide.md`） |
| 12 | AIによる書き換えなし | — | 可能 | 検索処理は文字列比較のみ。Apple Intelligence・外部APIを使わない |

## 結論

- MVP は **iPhone 標準のショートカット・Siri・ファイルアプリだけで実現可能**。外部有料API・Apple Intelligence・専用アプリは不要。
- 実現性のリスクは「**Siri 経由の音声入力と画面表示の挙動**」と「**ショートカット名の Siri 認識**」に集中している。これらは実機テスト（`04_test_plan.md`）で判定する。
- ショートカット本体は自動配布できないため、**手順書に沿ってiPhone上で作成**する（約40アクション、30〜45分程度の作業を想定）。

## データ形式の比較（JSON か 辞書か）

| 方式 | 実装負担 | 編集のしやすさ | 第2段階への拡張 | 判定 |
|---|---|---|---|---|
| **A. JSON ファイル**（ファイルアプリに保存） | 低（読み込み2アクション） | テキストとして編集・Codexで生成可 | 書き戻し・バックアップ・版数管理に向く | **採用** |
| B. ショートカット内の「辞書」アクション | 低 | ショートカット編集画面で1件ずつ入力。長文・改行の入力が煩雑 | 書き戻し不可（ショートカット自身は自分を編集できない） | 不採用 |
| C. ショートカット内の「テキスト」アクションにJSONを貼り付け | 最低 | 貼り替えのたびにショートカット編集が必要 | 不可 | **緊急時の予備案**（ファイル読み込みが不安定な場合） |

JSON の配列・ネスト（`aliases`）は「辞書の値を取得」でそのまま扱えるため、仕様どおり JSON を採用する。

## 参照した Apple 公式資料

- Use Siri to run shortcuts with your voice — https://support.apple.com/guide/shortcuts/run-shortcuts-with-siri-apd07c25bb38/ios
- Use the Ask for Input action — https://support.apple.com/guide/shortcuts/use-the-ask-for-input-action-apd68b5c9161/ios
- Using Dictionaries in Shortcuts — https://support.apple.com/guide/shortcuts/dictionaries-apd43b69f337/ios
- Get Dictionary Value action — https://support.apple.com/guide/shortcuts/get-dictionary-value-action-apdf01294032/ios
- About actions in complicated shortcuts（Copy to Clipboard / Choose from Menu / Show Alert） — https://support.apple.com/guide/shortcuts/about-actions-in-complicated-shortcuts-apd081d9d61f/ios
- Share shortcuts on iPhone or iPad — https://support.apple.com/guide/shortcuts/share-shortcuts-apdf01f8c054/ios
- Shortcuts for Mac: `shortcuts sign`（コマンドラインからの署名） — https://support.apple.com/guide/shortcuts-mac/apd455c82f02/mac
