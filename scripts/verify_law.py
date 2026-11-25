#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""data/genres/*.json のチェック項目と質問を、1件ずつ検算する。落ちたら公開しない。

  /usr/bin/python3 scripts/verify_law.py

見るもの:
  1) 法令の項目: 引いた法令が data/law_*.json にあり、条番号があり、expect の語がすべてその条文の本文に出てくるか
     （改正で条文が動けばここで落ちる。kacnavi の verify_law.py と同じ考え）
  2) 条例の項目（region が「全国」以外で e-Gov に無いもの）: source_url（自治体の公式ページ）と checked（確かめた日）があるか
  3) 条件（need / need_any / show_if）: 書いた質問の id と選択肢の値が実在するか
  4) 質問の id・項目の id が重複していないか
"""
from __future__ import annotations

import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def conds(x: dict) -> list[str]:
    return list(x.get("need", [])) + list(x.get("need_any", [])) + list(x.get("show_if", [])) + list(x.get("show_if_any", []))


def main() -> int:
    law = {}
    for f in sorted(glob.glob(os.path.join(ROOT, "data", "law_*.json"))):  # fetch_law.py がジャンルごとに書く
        law.update(json.load(open(f, encoding="utf-8")))
    bad, n_items, n_law = [], 0, 0
    for fn in sorted(glob.glob(os.path.join(ROOT, "data", "genres", "*.json"))):
        g = json.load(open(fn, encoding="utf-8"))
        gid = g.get("id") or os.path.basename(fn)
        qs = {q["id"]: q for q in g.get("questions", [])}
        if len(qs) != len(g.get("questions", [])):
            bad.append(f"{gid}: 質問の id が重複")
        ids = [it["id"] for it in g.get("items", [])]
        if len(set(ids)) != len(ids):
            bad.append(f"{gid}: 項目の id が重複")
        for obj in list(qs.values()) + g.get("items", []):
            for c in conds(obj):
                m = re.fullmatch(r"([A-Za-z0-9_]+)(=|!=)(.+)", c)
                if not m or m.group(1) not in qs:
                    bad.append(f"{gid}/{obj['id']}: 条件 {c} の質問が無い"); continue
                vals = {o["v"] for o in qs[m.group(1)].get("options", [])}
                if m.group(3) not in vals:
                    bad.append(f"{gid}/{obj['id']}: 条件 {c} の値が選択肢に無い（{sorted(vals)}）")
        for it in g.get("items", []):
            n_items += 1
            for k in ("id", "title", "law", "duty", "freq", "check", "region"):
                if not it.get(k):
                    bad.append(f"{gid}/{it.get('id')}: {k} が空")
            if it.get("law") in law:
                L = law[it["law"]]
                for art in it.get("articles") or []:
                    a = L["articles"].get(str(art))
                    if not a:
                        bad.append(f"{gid}/{it['id']}: {it['law']} 第{art}条 が無い"); continue
                    n_law += 1
                for w in it.get("expect") or []:
                    body = "\n".join((L["articles"].get(str(x)) or {}).get("text", "") + (L["articles"].get(str(x)) or {}).get("caption", "")
                                     for x in it.get("articles") or [])
                    if w not in body:
                        bad.append(f"{gid}/{it['id']}: 「{w}」が {it['law']} {it.get('articles')} の本文に無い")
                if not it.get("articles"):
                    bad.append(f"{gid}/{it['id']}: 条番号（articles）が無い")
            elif it.get("region", "全国") != "全国" or it.get("source_url"):
                if not it.get("source_url") or not it.get("checked"):
                    bad.append(f"{gid}/{it['id']}: 条例・告示などは source_url と checked（確かめた日）が要る")
            else:
                bad.append(f"{gid}/{it['id']}: 法令「{it['law']}」が data/law_<ジャンル>.json に無い（data/laws_<ジャンル>.json に足して fetch_law.py --genre <ジャンル>）")
    for b in bad:
        print("NG", b)
    print(f"項目 {n_items} 件・条文 {n_law} 件を検算 → {'OK' if not bad else str(len(bad)) + ' 件 NG'}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
