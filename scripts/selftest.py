#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""画面を実際にブラウザで動かして確かめる（Playwright・使い捨てのプロファイル）。

  /usr/bin/python3 scripts/selftest.py http://127.0.0.1:PORT/klawnavi/      # 手元
  /usr/bin/python3 scripts/selftest.py https://proto.exbridge.jp/klawnavi/  # 本番

1) 全ジャンルを選んで、毎回いちばん最初の選択肢を押してインタビューを最後まで進め、チェックシートの行数を見る
2) 同じく「わからない」だけで進め、要確認が付くことを見る
3) 320/360/390/430px で横はみ出しが無いか（scrollWidth）を見る。トップとジャンルのページ
4) 計測（simpletrack.php）へのリクエストが1本出るか
"""
import sys
from playwright.sync_api import sync_playwright

URL = sys.argv[1] if len(sys.argv) > 1 else "https://proto.exbridge.jp/klawnavi/"
bad = []


def run_interview(page, mode):
    page.goto(URL, wait_until="networkidle")
    for cb in page.query_selector_all("input[name=g]"):
        if not cb.is_checked():
            cb.check()
    page.click("#go")
    for _ in range(200):
        if page.is_visible("#sheet"):
            break
        btns = page.query_selector_all("#ans .opt")
        if not btns:
            page.wait_for_timeout(200); continue
        if mode == "unknown":
            page.click("#ans .opt.unk")
        else:
            multi = page.query_selector("#ok") is not None  # 押す前に見る（押すと次の質問に入れ替わる）
            btns[0].click()
            if multi:
                page.click("#ok")
        page.wait_for_timeout(50)
    rows = page.eval_on_selector_all("#tbl tbody tr", "r=>r.length")
    unk = page.eval_on_selector_all("#tbl .tag.chk", "r=>r.length")
    return rows, unk


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    hits = []
    page.on("request", lambda r: hits.append(r.url) if "simpletrack.php" in r.url else None)
    errs = []
    page.on("pageerror", lambda e: errs.append(str(e)))
    rows, unk = run_interview(page, "first")
    print(f"最初の選択肢で通す: チェックシート {rows} 行・要確認 {unk}")
    if rows == 0: bad.append("最初の選択肢でチェックシートが0行")
    rows2, unk2 = run_interview(page, "unknown")
    print(f"「わからない」で通す: チェックシート {rows2} 行・要確認 {unk2}")
    if unk2 == 0: bad.append("わからないで通しても要確認が0")
    if not hits: bad.append("計測のリクエストが出ていない")
    if errs: bad.append("JSのエラー: " + "; ".join(errs[:3]))
    gen = page.eval_on_selector_all("nav.menu a[href*='genre/']", "a=>a.map(x=>x.href)")
    for w in (320, 360, 390, 430):
        c = b.new_context(viewport={"width": w, "height": 800}, device_scale_factor=2, is_mobile=True)
        pg = c.new_page()
        for u in [URL] + gen:
            pg.goto(u, wait_until="networkidle")
            sw = pg.evaluate("document.documentElement.scrollWidth")
            if sw > w:
                bad.append(f"{w}px {u}: 横はみ出し scrollWidth={sw}")
        if w == 360:
            run_interview(pg, "first")
            sw = pg.evaluate("document.documentElement.scrollWidth")
            if sw > w: bad.append(f"{w}px チェックシート表示後: 横はみ出し {sw}")
            pg.screenshot(path="outputs/shot_360_sheet.png", full_page=False)
        c.close()
    b.close()
for x in bad:
    print("NG", x)
print("OK" if not bad else f"{len(bad)} 件 NG")
sys.exit(1 if bad else 0)
