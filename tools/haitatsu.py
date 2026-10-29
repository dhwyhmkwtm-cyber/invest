#!/usr/bin/env python3
"""配達メッセージ — 参照実装（検索ロジック・データ検証）

iPhoneショートカット「配達メッセージ」と同じ手順で正規化・検索を行う。
ショートカットの動作を机上で確認し、データファイルを配布前に検証するために使う。

使い方:
  python3 tools/haitatsu.py validate data/messages.json
  python3 tools/haitatsu.py search data/messages.json "3番"
  python3 tools/haitatsu.py list data/messages.json
  python3 tools/haitatsu.py export-plist data/messages.json dist/user_dictionary.plist

標準ライブラリのみ使用（Python 3.9+）。
"""

from __future__ import annotations

import argparse
import json
import plistlib
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
NORMALIZE_MAP_PATH = ROOT / "shortcut" / "normalize_map.json"

SCHEMA_VERSION = 1
KEY_SEPARATOR = "|"
PARTIAL_MIN_LENGTH = 2

# ショートカットの「テキストを置き換える」（正規表現）と同じパターンを使う。
# ICU と Python re の両方で同じ意味になる構文だけを使うこと。
RE_WHITESPACE = re.compile(r"\s+")
RE_EDGE_PUNCT = re.compile(r"^[。、．.,，!！?？]+|[。、．.,，!！?？]+$")
RE_NUMBER_FORM = re.compile(
    r"^(?:番号)?([0-9〇零一二三四五六七八九十]+)(?:番目|番|ばんめ|ばん)?$"
)
# 「十」の処理。ショートカットでは置換文字列の \1 を $1 と書く。
# 「$10」がグループ10と解釈されるのを避けるため、二十→20 は後読みで書く。
RE_TEN_RULES = [
    (re.compile(r"^([0-9])十([0-9])$"), r"\1\2"),
    (re.compile(r"^十([0-9])$"), r"1\1"),
    (re.compile(r"^十$"), "10"),
    (re.compile(r"(?<=^[0-9])十$"), "0"),
]
RE_DIGITS = re.compile(r"^[0-9]+$")
RE_LEADING_ZEROS = re.compile(r"^0+(?=[0-9])")

MESSAGE_FIELDS = {
    "id", "number", "keyword", "aliases", "text", "category",
    "createdAt", "updatedAt", "deletedAt",
}
# 任意項目。yomi はユーザ辞書（テキスト置換）の「よみ」。ショートカットの検索には使わない
OPTIONAL_MESSAGE_FIELDS = {"yomi"}
# iPhone の日本語キーボードで「よみ」に使える文字（ひらがな・長音・英小文字・数字）
RE_YOMI = re.compile(r"^[ぁ-ゖー0-9a-z]+$")
ROOT_FIELDS = {"schemaVersion", "revision", "updatedAt", "messages"}


def load_normalize_map(path: Path = NORMALIZE_MAP_PATH) -> dict[str, dict[str, str]]:
    with path.open(encoding="utf-8") as f:
        return json.load(f)


NORMALIZE_MAP = load_normalize_map()


def _apply_map(text: str, mapping: dict[str, str]) -> str:
    # ショートカットでは「各項目を繰り返す」で辞書のキーごとに置換する
    for src, dst in mapping.items():
        text = text.replace(src, dst)
    return text


def normalize_key(text: str) -> str:
    """登録キー（keyword / aliases）と入力の共通正規化。

    ショートカット手順 N1〜N3 に対応:
      N1 空白（全角含む）をすべて除去
      N2 小文字化
      N3 全角数字を半角に
    """
    text = RE_WHITESPACE.sub("", text)
    text = text.lower()
    return _apply_map(text, NORMALIZE_MAP["fullwidth"])


def normalize_query(raw: str) -> str:
    """入力のみに適用する正規化。N1〜N3 に加え N4 前後の句読点除去。"""
    return RE_EDGE_PUNCT.sub("", normalize_key(raw))


def parse_number(query: str) -> int | None:
    """N5: 正規化済み入力が番号指定なら番号を返す。

    受け付ける例: 3 / 03 / 3番 / 3番目 / 番号3 / 三 / 三番 / 十二番 / 二十
    """
    m = RE_NUMBER_FORM.match(query)
    if not m:
        return None
    digits = _apply_map(m.group(1), NORMALIZE_MAP["kanji"])
    for pattern, repl in RE_TEN_RULES:
        digits = pattern.sub(repl, digits)
    if not RE_DIGITS.match(digits):
        return None
    return int(RE_LEADING_ZEROS.sub("", digits))


def keys_text(message: dict) -> str:
    """ショートカット内で作る照合用テキスト「|keyword|alias1|alias2|」。"""
    keys = [message["keyword"], *message.get("aliases", [])]
    return normalize_key(KEY_SEPARATOR + KEY_SEPARATOR.join(keys) + KEY_SEPARATOR)


def is_active(message: dict) -> bool:
    return message.get("deletedAt") is None


@dataclass
class SearchResult:
    # empty: 入力なし / none: 該当なし / exact: 1件確定 / candidates: 利用者が選ぶ
    kind: str
    query: str
    number: int | None = None
    matches: list[dict] = field(default_factory=list)

    @property
    def ids(self) -> list[str]:
        return [m["id"] for m in self.matches]


def search(data: dict, raw: str) -> SearchResult:
    query = normalize_query(raw)
    if not query:
        return SearchResult("empty", query)

    active = [m for m in data["messages"] if is_active(m)]
    number = parse_number(query)
    if number is not None:
        hits = [m for m in active if m["number"] == number]
        return _result(query, hits, number=number)

    needle = KEY_SEPARATOR + query + KEY_SEPARATOR
    exact = [m for m in active if needle in keys_text(m)]
    if exact:
        return _result(query, exact)

    # 完全一致なし: 部分一致は候補提示のみ（1件でも自動選択しない）
    if len(query) >= PARTIAL_MIN_LENGTH:
        partial = [m for m in active if query in keys_text(m)]
        if partial:
            return SearchResult("candidates", query, matches=partial)
    return SearchResult("none", query)


def _result(query: str, hits: list[dict], number: int | None = None) -> SearchResult:
    if not hits:
        return SearchResult("none", query, number)
    kind = "exact" if len(hits) == 1 else "candidates"
    return SearchResult(kind, query, number, hits)


def format_display(message: dict) -> str:
    """ショートカットのメニュー見出しに出す表示用テキスト。"""
    return f"{format_candidate(message)}\n――――\n{message['text']}"


def format_candidate(message: dict) -> str:
    # 候補辞書のキーになる。「辞書の値を取得」はキー中の「.」をドット記法と解釈するため「.」を含めない
    return f"【{message['number']}】{message['keyword']}"


# ---------------------------------------------------------------- validation


def _is_datetime(value) -> bool:
    if not isinstance(value, str):
        return False
    try:
        datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return False
    return True


def _check_key(label: str, value, errors: list[str]) -> None:
    if not isinstance(value, str) or not normalize_key(value):
        errors.append(f"{label}: 空でない文字列が必要です")
        return
    if KEY_SEPARATOR in value:
        errors.append(f"{label}: 区切り文字「{KEY_SEPARATOR}」は使えません")
    if label.endswith(".keyword") and "." in value:
        errors.append(f"{label}: 「.」は使えません（候補選択で辞書のキーに使うため）")
    if any(ch in value for ch in NORMALIZE_MAP["fullwidth"]):
        # ショートカットは登録キー側の全角数字を変換しない（ループ内の処理を減らすため）
        errors.append(f"{label}: 全角数字は使えません（半角で登録してください）")
    normalized = normalize_key(value)
    if RE_EDGE_PUNCT.search(normalized):
        errors.append(f"{label}: 前後に句読点・記号は付けられません（入力側で除去されるため一致しません）")
    if parse_number(normalize_query(value)) is not None:
        errors.append(f"{label}: 番号と区別できない語（例: 3, 三番）は使えません")


def validate(data) -> tuple[list[str], list[str]]:
    """(errors, warnings) を返す。errors が空なら配布可能。"""
    errors: list[str] = []
    warnings: list[str] = []

    if not isinstance(data, dict):
        return ["ルートはオブジェクトである必要があります"], warnings
    for key in sorted(set(data) - ROOT_FIELDS):
        warnings.append(f"ルート: 未定義の項目 {key}")
    if data.get("schemaVersion") != SCHEMA_VERSION:
        errors.append(f"schemaVersion は {SCHEMA_VERSION} である必要があります")
    revision = data.get("revision")
    if not isinstance(revision, int) or isinstance(revision, bool) or revision < 1:
        errors.append("revision は 1 以上の整数である必要があります")
    if not _is_datetime(data.get("updatedAt")):
        errors.append("updatedAt は ISO 8601 日時である必要があります")
    messages = data.get("messages")
    if not isinstance(messages, list):
        errors.append("messages は配列である必要があります")
        return errors, warnings

    seen_ids: set[str] = set()
    yomi_owner: dict[str, str] = {}
    active_numbers: dict[int, str] = {}
    deleted_numbers: dict[int, str] = {}
    key_owner: dict[str, str] = {}

    for i, m in enumerate(messages):
        where = f"messages[{i}]"
        if not isinstance(m, dict):
            errors.append(f"{where}: オブジェクトである必要があります")
            continue
        where = f"{where} ({m.get('id', '?')})"
        missing = MESSAGE_FIELDS - set(m)
        if missing:
            errors.append(f"{where}: 必須項目がありません: {', '.join(sorted(missing))}")
        for key in sorted(set(m) - MESSAGE_FIELDS - OPTIONAL_MESSAGE_FIELDS):
            warnings.append(f"{where}: 未定義の項目 {key}")

        mid = m.get("id")
        if not isinstance(mid, str) or not mid:
            errors.append(f"{where}: id は空でない文字列が必要です")
        elif mid in seen_ids:
            errors.append(f"{where}: id が重複しています")
        else:
            seen_ids.add(mid)

        number = m.get("number")
        if not isinstance(number, int) or isinstance(number, bool) or number < 1:
            errors.append(f"{where}: number は 1 以上の整数が必要です")
            number = None

        _check_key(f"{where}.keyword", m.get("keyword"), errors)
        aliases = m.get("aliases", [])
        if not isinstance(aliases, list):
            errors.append(f"{where}.aliases: 配列が必要です")
            aliases = []
        for j, alias in enumerate(aliases):
            _check_key(f"{where}.aliases[{j}]", alias, errors)

        if not isinstance(m.get("text"), str) or not m.get("text"):
            errors.append(f"{where}.text: 空でない文字列が必要です")
        if "yomi" in m:
            yomi = m["yomi"]
            if not isinstance(yomi, str) or not RE_YOMI.match(yomi):
                errors.append(f"{where}.yomi: ひらがな・英小文字・数字のみで指定してください")
            elif is_active(m):
                if yomi in yomi_owner:
                    errors.append(f"{where}.yomi: 「{yomi}」が {yomi_owner[yomi]} と重複しています")
                else:
                    yomi_owner[yomi] = str(mid)
                if "\n" in str(m.get("text", "")):
                    warnings.append(f"{where}: 本文に改行があります（iPhone のユーザ辞書では改行の扱いが不確実です）")
        if not isinstance(m.get("category"), str):
            errors.append(f"{where}.category: 文字列が必要です")
        for key in ("createdAt", "updatedAt"):
            if not _is_datetime(m.get(key)):
                errors.append(f"{where}.{key}: ISO 8601 日時が必要です")
        deleted_at = m.get("deletedAt")
        if deleted_at is not None and not _is_datetime(deleted_at):
            errors.append(f"{where}.deletedAt: null または ISO 8601 日時が必要です")

        if number is None:
            continue
        if not is_active(m):
            deleted_numbers.setdefault(number, str(mid))
            continue
        if number in active_numbers:
            errors.append(f"{where}: number {number} が {active_numbers[number]} と重複しています")
        else:
            active_numbers[number] = str(mid)
        keys = [m.get("keyword"), *aliases]
        own: set[str] = set()
        for key in keys:
            if not isinstance(key, str):
                continue
            nk = normalize_key(key)
            if nk in own:
                continue
            own.add(nk)
            if nk in key_owner:
                warnings.append(
                    f"{where}: キー「{key}」が {key_owner[nk]} と重複（検索時は候補として両方提示されます）"
                )
            else:
                key_owner[nk] = str(mid)

    for number, mid in deleted_numbers.items():
        if number in active_numbers:
            warnings.append(
                f"番号 {number}: 削除済み {mid} と有効 {active_numbers[number]} が同じ番号です（復元時に衝突します）"
            )
    return errors, warnings


# ---------------------------------------------------------------------- CLI


def load_data(path: str) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def cmd_validate(args) -> int:
    errors, warnings = validate(load_data(args.file))
    for w in warnings:
        print(f"警告: {w}")
    for e in errors:
        print(f"エラー: {e}")
    if errors:
        print(f"NG: エラー {len(errors)} 件")
        return 1
    print(f"OK: エラーなし（警告 {len(warnings)} 件）")
    return 0


def cmd_search(args) -> int:
    result = search(load_data(args.file), args.query)
    print(f"正規化後: {result.query!r}" + (f"（番号 {result.number}）" if result.number else ""))
    if result.kind == "empty":
        print("入力が空です。")
    elif result.kind == "none":
        print(f"該当する定型文がありません:「{args.query}」")
    elif result.kind == "candidates":
        print("候補から選択してください:")
        for m in result.matches:
            print(f"  - {format_candidate(m)}")
    else:
        print(format_display(result.matches[0]))
    return 0 if result.kind in ("exact", "candidates") else 2


def user_dictionary_entries(data: dict) -> list[dict]:
    """ユーザ辞書（テキスト置換）用の項目。未削除で yomi があるものだけ、番号順。"""
    targets = [m for m in data["messages"] if is_active(m) and m.get("yomi")]
    return [
        {"phrase": m["text"], "shortcut": m["yomi"]}
        for m in sorted(targets, key=lambda m: m["number"])
    ]


def cmd_export_plist(args) -> int:
    data = load_data(args.file)
    errors, _ = validate(data)
    if errors:
        print("データにエラーがあるため出力しません。先に validate を実行してください。")
        return 1
    entries = user_dictionary_entries(data)
    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)
    # macOS「システム設定 > キーボード > ユーザ辞書」の一覧へドラッグして取り込める形式
    with out.open("wb") as f:
        plistlib.dump(entries, f)
    print(f"{len(entries)} 件を {out} に出力しました")
    return 0


def cmd_list(args) -> int:
    for m in load_data(args.file)["messages"]:
        mark = "" if is_active(m) else "（削除済み）"
        print(f"{format_candidate(m)} {mark}\n    別名: {', '.join(m.get('aliases', []))}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="配達メッセージ 参照実装")
    sub = parser.add_subparsers(dest="command", required=True)
    p = sub.add_parser("validate", help="データファイルを検証")
    p.add_argument("file")
    p.set_defaults(func=cmd_validate)
    p = sub.add_parser("search", help="ショートカットと同じ手順で検索")
    p.add_argument("file")
    p.add_argument("query")
    p.set_defaults(func=cmd_search)
    p = sub.add_parser("list", help="登録内容を一覧表示")
    p.add_argument("file")
    p.set_defaults(func=cmd_list)
    p = sub.add_parser("export-plist", help="Mac のユーザ辞書に取り込む plist を出力")
    p.add_argument("file")
    p.add_argument("output")
    p.set_defaults(func=cmd_export_plist)
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
