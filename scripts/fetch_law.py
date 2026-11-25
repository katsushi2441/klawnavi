#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""チェックシートの項目の根拠を、法令の条文そのもので裏取りする（kacnavi の fetch_law.py と同じ作り）。

  /usr/bin/python3 scripts/fetch_law.py                 # data/laws_*.json の法令を全部取り、data/law_*.json を作る（--genre food で1ジャンルだけ）
  /usr/bin/python3 scripts/fetch_law.py 食品衛生法 51     # 1条だけ見る（data/laws_*.json にある名前。条番号は 26_2 の形も可）
  /usr/bin/python3 scripts/fetch_law.py --num "平成十五年法律第五十七号" 23   # laws_*.json に入れる前に法令番号で見る

出典: e-Gov法令検索 法令API v2（デジタル庁）https://laws.e-gov.go.jp/api/2/
      コンテンツは政府標準利用規約(第2.0版)。出典表示のうえ商用利用可。
"""
from __future__ import annotations

import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
API = "https://laws.e-gov.go.jp/api/2/law_data/"
UA = {"User-Agent": "klawnavi/1.0 (+https://proto.exbridge.jp/klawnavi/)"}

# 取る法令は data/laws_<ジャンル>.json に {"法令名": "法令番号"} で書く（題名で引くと改称や同名で外す）。
# ジャンルごとに data/law_<ジャンル>.json へ書き出す（ジャンルの作業を並行しても書き込みがぶつからない）。
import glob
LAWS = {}
for _f in sorted(glob.glob(os.path.join(ROOT, "data", "laws_*.json"))):
    LAWS.update(json.load(open(_f, encoding="utf-8")))


KANSUJI = "〇一二三四五六七八九"


def num_to_kanji(n: int) -> str:
    """86 → 八十六。条文番号の見出し（第八十六条）を組み立てるため。"""
    if n < 10:
        return KANSUJI[n]
    if n < 100:
        t, o = divmod(n, 10)
        return ("十" if t == 1 else KANSUJI[t] + "十") + (KANSUJI[o] if o else "")
    h, r = divmod(n, 100)
    return ("百" if h == 1 else KANSUJI[h] + "百") + (num_to_kanji(r) if r else "")


def text_of(node) -> str:
    """法令APIのJSON木から文字列だけを拾う。"""
    if isinstance(node, str):
        return node
    if isinstance(node, list):
        return "".join(text_of(x) for x in node)
    if isinstance(node, dict):
        if node.get("tag") == "Ruby":  # 振り仮名は本文だけ取る
            ch = node.get("children") or []
            return "".join(text_of(c) for c in ch if not (isinstance(c, dict) and c.get("tag") == "Rt"))
        return text_of(node.get("children") or [])
    return ""


def walk(node, tag: str):
    if isinstance(node, dict):
        if node.get("tag") == tag:
            yield node
        for c in node.get("children") or []:
            yield from walk(c, tag)
    elif isinstance(node, list):
        for c in node:
            yield from walk(c, tag)


def fetch(law_num: str) -> dict:
    url = API + urllib.parse.quote(law_num, safe="")
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120) as r:
        return json.loads(r.read().decode("utf-8"))


def articles(doc: dict) -> dict[str, dict]:
    """{"86": {"title": "第八十六条", "caption": "…", "text": "…"}} を返す。

    **本則（MainProvision）の中だけを見る。** 附則にも第25条・第27条があり、木を素通しで
    歩くと後から出てくる附則が本則を上書きする。実際それで住民基本台帳法25条が
    「罰則に関する経過措置」に、相続税法27条が「配偶者に対する相続税額の軽減等に関する
    経過措置」になっていた（2026-09-24 実測）。検算しなければ、そのまま期限の根拠として
    出すところだった。
    """
    out = {}
    main = list(walk(doc["law_full_text"], "MainProvision")) or [doc["law_full_text"]]
    for a in walk(main, "Article"):
        num = (a.get("attr") or {}).get("Num") or ""
        if not num:
            continue
        title = caption = ""
        body = []
        for c in a.get("children") or []:
            if not isinstance(c, dict):
                continue
            if c["tag"] == "ArticleTitle":
                title = text_of(c)
            elif c["tag"] == "ArticleCaption":
                caption = text_of(c).strip("（）")
            elif c["tag"] == "Paragraph":
                body.append(re.sub(r"\s+", " ", text_of(c)).strip())
        out[num] = {"title": title, "caption": caption, "text": "\n".join(body)}
    return out


def main() -> int:
    if len(sys.argv) == 4 and sys.argv[1] == "--num":  # 法令番号で1条だけ見る
        d = fetch(sys.argv[2]); art = sys.argv[3]; name = sys.argv[2]
        a = articles(d).get(art)
        if not a:
            print(f"{name} 第{art}条 は無い（ある条: {', '.join(list(articles(d))[:40])}…）", file=sys.stderr)
            return 1
        print(f"{d['revision_info']['law_title']} {a['title']}（{a['caption']}）")
        print(a["text"][:1500])
        return 0
    if len(sys.argv) == 3 and sys.argv[1] != "--genre":  # 1条だけ確かめる
        name, art = sys.argv[1], sys.argv[2]
        d = fetch(LAWS[name])
        a = articles(d).get(art)
        if not a:
            print(f"{name} 第{art}条 は無い", file=sys.stderr)
            return 1
        print(f"{name}（{d['revision_info']['law_title']}）{a['title']}（{a['caption']}）")
        print(a["text"][:1500])
        return 0

    # 引数なし: 全ジャンル。--genre food: data/laws_food.json だけ取り、data/law_food.json に書く
    targets = [os.path.basename(f)[5:-5] for f in sorted(glob.glob(os.path.join(ROOT, "data", "laws_*.json")))]
    if len(sys.argv) == 3 and sys.argv[1] == "--genre":
        targets = [sys.argv[2]]
    for g in targets:
        laws = json.load(open(os.path.join(ROOT, "data", f"laws_{g}.json"), encoding="utf-8"))
        dump(laws, os.path.join(ROOT, "data", f"law_{g}.json"))
    return 0


def dump(laws: dict, out: str) -> None:
    data = {}
    for name, law_num in laws.items():
        try:
            d = fetch(law_num)
        except Exception as e:
            print(f"  !! {name}: {e}", file=sys.stderr)
            continue
        ri = d["revision_info"]
        arts = articles(d)
        data[name] = {
            "law_num": law_num,
            "law_id": d["law_info"]["law_id"],
            "title": ri["law_title"],
            "amendment_promulgate_date": ri.get("amendment_promulgate_date"),
            "amendment_enforcement_date": ri.get("amendment_enforcement_date"),
            "url": "https://laws.e-gov.go.jp/law/" + d["law_info"]["law_id"],
            "articles": arts,
        }
        print(f"  {name}: {len(arts)}条 施行 {ri.get('amendment_enforcement_date')}")
        time.sleep(1)
    json.dump(data, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"→ {out} 法令 {len(data)}本")


if __name__ == "__main__":
    sys.exit(main())
