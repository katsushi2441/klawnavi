#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""data/genres/*.json と条文（data/law_*.json）から、画面が読む php/klawnavi_data/data.json を作る。

  /usr/bin/python3 scripts/verify_law.py && /usr/bin/python3 scripts/build_data.py

各チェック項目に、根拠の条文の見出し・冒頭の一節・e-Gov のURL・改正の施行日を付ける。
検算（verify_law.py）が通らないデータからは作らない。
"""
from __future__ import annotations

import glob
import json
import os
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "php", "klawnavi_data", "data.json")
ORDER = ["privacy", "food"]  # 画面に並べる順（無いものは後ろへ）


def art_label(num: str) -> str:
    # 26_2 → 26条の2
    a, *rest = str(num).split("_")
    return a + "条" + "".join("の" + r for r in rest)


def main() -> int:
    if subprocess.call([sys.executable, os.path.join(ROOT, "scripts", "verify_law.py")]) != 0:
        print("検算が通らないので作りません", file=sys.stderr)
        return 1
    law = {}
    for f in sorted(glob.glob(os.path.join(ROOT, "data", "law_*.json"))):
        law.update(json.load(open(f, encoding="utf-8")))
    genres = []
    files = sorted(glob.glob(os.path.join(ROOT, "data", "genres", "*.json")),
                   key=lambda f: (ORDER.index(os.path.basename(f)[:-5]) if os.path.basename(f)[:-5] in ORDER else 99, f))
    for fn in files:
        g = json.load(open(fn, encoding="utf-8"))
        for it in g["items"]:
            L = law.get(it["law"])
            if L:
                it["kind"] = "法令"
                it["law_url"] = L["url"]
                it["law_enforced"] = L.get("amendment_enforcement_date") or ""
                it["cite"] = it["law"] + " " + "・".join(art_label(a) for a in it.get("articles") or [])
                first = (L["articles"].get(str((it.get("articles") or [""])[0])) or {})
                it["caption"] = first.get("caption", "")
                t = first.get("text", "").split("\n")[0]
                it["excerpt"] = t[:150] + ("…" if len(t) > 150 else "")
            else:
                it["kind"] = "ガイドライン" if "ガイドライン" in it.get("region", "") else "条例・規則"
                it["cite"] = it["law"]
                it["law_url"] = it.get("source_url", "")
        genres.append(g)
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    data = {"asof": time.strftime("%Y-%m-%d"), "genres": genres,
            "n_items": sum(len(g["items"]) for g in genres), "n_questions": sum(len(g["questions"]) for g in genres)}
    json.dump(data, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
    print(f"→ {OUT} ジャンル {len(genres)}・質問 {data['n_questions']}・項目 {data['n_items']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
