import copy
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tools"))

import haitatsu  # noqa: E402

DATA = haitatsu.load_data(ROOT / "data" / "messages.json")
CASES = json.loads((ROOT / "tests" / "search_cases.json").read_text(encoding="utf-8"))["cases"]


class SearchCasesTest(unittest.TestCase):
    def test_cases(self):
        for case in CASES:
            with self.subTest(case=case["id"], input=case["input"]):
                result = haitatsu.search(DATA, case["input"])
                self.assertEqual(result.kind, case["kind"])
                self.assertEqual(result.ids, case["ids"])

    def test_multiple_exact_matches_become_candidates(self):
        data = copy.deepcopy(DATA)
        data["messages"][2]["aliases"].append("到着")
        result = haitatsu.search(data, "到着")
        self.assertEqual(result.kind, "candidates")
        self.assertEqual(result.ids, ["msg-0001", "msg-0003"])

    def test_single_partial_match_is_never_auto_selected(self):
        result = haitatsu.search(DATA, "番号")
        self.assertEqual(len(result.matches), 1)
        self.assertEqual(result.kind, "candidates")


class NormalizeTest(unittest.TestCase):
    def test_parse_number(self):
        expected = {
            "3": 3, "03": 3, "3番": 3, "3番目": 3, "番号3": 3, "3ばん": 3,
            "三": 3, "三番": 3, "十": 10, "十二": 12, "二十": 20, "二十三番": 23,
            "〇三": 3, "12": 12,
        }
        for text, number in expected.items():
            with self.subTest(text=text):
                self.assertEqual(haitatsu.parse_number(haitatsu.normalize_query(text)), number)

    def test_not_numbers(self):
        for text in ["番号", "一階", "十十", "3階", "到着", "百"]:
            with self.subTest(text=text):
                self.assertIsNone(haitatsu.parse_number(haitatsu.normalize_query(text)))

    def test_query_normalization(self):
        self.assertEqual(haitatsu.normalize_query("　置き 配。"), "置き配")
        self.assertEqual(haitatsu.normalize_query("ＡＢ１"), "ａｂ1")
        self.assertEqual(haitatsu.normalize_query("Hello!"), "hello")


class DisplayTest(unittest.TestCase):
    def test_text_is_preserved_verbatim(self):
        message = haitatsu.search(DATA, "お礼").matches[0]
        display = haitatsu.format_display(message)
        self.assertTrue(display.startswith("【7】お礼\n"))
        self.assertTrue(display.endswith(message["text"]))
        self.assertIn("😊\n\n", message["text"])


class ValidateTest(unittest.TestCase):
    def test_sample_data_is_valid(self):
        errors, warnings = haitatsu.validate(DATA)
        self.assertEqual(errors, [])
        self.assertEqual(warnings, [])

    def _errors(self, mutate):
        data = copy.deepcopy(DATA)
        mutate(data)
        return haitatsu.validate(data)

    def test_duplicate_active_number(self):
        errors, _ = self._errors(lambda d: d["messages"][1].update(number=1))
        self.assertTrue(any("重複" in e for e in errors))

    def test_duplicate_id(self):
        errors, _ = self._errors(lambda d: d["messages"][1].update(id="msg-0001"))
        self.assertTrue(any("id が重複" in e for e in errors))

    def test_number_like_keyword_rejected(self):
        errors, _ = self._errors(lambda d: d["messages"][0].update(keyword="三番"))
        self.assertTrue(any("番号と区別できない" in e for e in errors))

    def test_separator_and_punctuation_rejected(self):
        errors, _ = self._errors(lambda d: d["messages"][0].update(aliases=["a|b", "到着。"]))
        self.assertTrue(any("区切り文字" in e for e in errors))
        self.assertTrue(any("句読点" in e for e in errors))

    def test_dot_in_keyword_rejected(self):
        errors, _ = self._errors(lambda d: d["messages"][0].update(keyword="到着.完了"))
        self.assertTrue(any("「.」" in e for e in errors))

    def test_fullwidth_digit_key_rejected(self):
        errors, _ = self._errors(lambda d: d["messages"][0]["aliases"].append("５分遅れ"))
        self.assertTrue(any("全角数字" in e for e in errors))

    def test_duplicate_key_is_warning(self):
        errors, warnings = self._errors(lambda d: d["messages"][2]["aliases"].append("到 着"))
        self.assertEqual(errors, [])
        self.assertTrue(any("重複" in w for w in warnings))

    def test_deleted_number_collision_is_warning(self):
        errors, warnings = self._errors(lambda d: d["messages"][8].update(number=1))
        self.assertEqual(errors, [])
        self.assertTrue(any("復元時に衝突" in w for w in warnings))

    def test_missing_fields_and_bad_types(self):
        def mutate(d):
            del d["messages"][0]["text"]
            d["messages"][1]["deletedAt"] = "yesterday"
            d["revision"] = 0
        errors, _ = self._errors(mutate)
        self.assertTrue(any("必須項目" in e for e in errors))
        self.assertTrue(any("deletedAt" in e for e in errors))
        self.assertTrue(any("revision" in e for e in errors))


if __name__ == "__main__":
    unittest.main()
