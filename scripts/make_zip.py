#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""配布ZIP（kappstore 同梱物）を作る。

  /usr/bin/python3 scripts/make_zip.py   → outputs/kurage-law-checksheet-ai.zip

**klawnavi_config.php（公開デモの AI の合言葉）は絶対に入れない。** 見本の klawnavi_config.sample.php だけ入れる。
同梱の LICENSE と README はこのプロジェクトのものか、他製品の名前が混ざっていないかも確かめる。
"""
import glob, os, re, sys, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOP = "kurage-law-checksheet-ai"
OTHERS = ("kacnavi", "kminpaku", "kseido", "kecnavi", "kshuisho", "AfterCare")


def main() -> int:
    files = [("php/index.php", "index.php"), ("php/.htaccess", ".htaccess"),
             ("php/klawnavi_config.sample.php", "klawnavi_config.sample.php"),
             ("php/klawnavi_data/data.json", "klawnavi_data/data.json"),
             ("php/klawnavi-ogp.png", "klawnavi-ogp.png"),
             ("README.md", "README.md"), ("LICENSE", "LICENSE"), ("docs/DATA.md", "docs/DATA.md")]
    for f in sorted(glob.glob(os.path.join(ROOT, "data", "**", "*.json"), recursive=True)):
        r = os.path.relpath(f, ROOT); files.append((r, r))
    for f in ("fetch_law.py", "verify_law.py", "build_data.py", "selftest.py", "make_ogp.py"):
        files.append(("scripts/" + f, "scripts/" + f))
    bad = [s for s, _ in files if not os.path.exists(os.path.join(ROOT, s))]
    if bad:
        print("!! 無いファイル:", bad, file=sys.stderr); return 1
    if any("klawnavi_config.php" == os.path.basename(d) for _, d in files):
        print("!! 合言葉の入った設定が混ざっている", file=sys.stderr); return 1
    for s, d in files:
        if d in ("README.md", "LICENSE"):
            t = open(os.path.join(ROOT, s), encoding="utf-8").read()
            for o in OTHERS:
                if o in t:
                    print(f"!! {d} に「{o}」が残っている", file=sys.stderr); return 1
    os.makedirs(os.path.join(ROOT, "outputs"), exist_ok=True)
    out = os.path.join(ROOT, "outputs", TOP + ".zip")
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for s, d in files:
            z.write(os.path.join(ROOT, s), f"{TOP}/{d}")
    with zipfile.ZipFile(out) as z:
        names = z.namelist()
        blob = b"".join(z.read(n) for n in names)
    tok = ""
    cfg = os.path.join(ROOT, "php", "klawnavi_config.php")
    if os.path.exists(cfg):
        m = re.search(r"KLN_API_KEY', '([^']+)'", open(cfg, encoding="utf-8").read())
        tok = m.group(1) if m else ""
    if tok and tok.encode() in blob:
        os.remove(out); print("!! ZIP に合言葉が入っていた。消した", file=sys.stderr); return 1
    print(f"→ {out} {len(names)}ファイル {os.path.getsize(out):,}バイト")
    for n in names:
        print("  ", n)
    return 0


if __name__ == "__main__":
    sys.exit(main())
