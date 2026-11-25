#!/usr/bin/env python3
"""PV用に、デモの実画面を 1920x1080 で撮る（文字が読めるように拡大）。
  /usr/bin/python3 capture.py"""
import os
from playwright.sync_api import sync_playwright
U = "https://proto.exbridge.jp/klawnavi/"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "caps")


def zoom(pg, z):
    pg.evaluate(f"document.body.style.zoom='{z}'"); pg.wait_for_timeout(300)


def to(pg, sel, off=30):
    # body に zoom を掛けると座標の計算がずれるので、ブラウザに任せる
    pg.evaluate("""([s,o])=>{document.querySelector(s).scrollIntoView({block:'start'});window.scrollBy(0,-o)}""", [sel, off]); pg.wait_for_timeout(400)


with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1920, "height": 1080})
    pg.goto(U, wait_until="networkidle"); zoom(pg, 1.5)
    pg.evaluate("window.scrollTo(0,0)"); pg.wait_for_timeout(300); pg.screenshot(path=f"{OUT}/01-top.png")
    # ジャンルを選ぶ画面（食品衛生も選んだ状態）
    pg.check("input[name=g][value=food]"); to(pg, "#start", 20); pg.screenshot(path=f"{OUT}/02-genres.png")
    # 食品だけでインタビュー。4問答えたところ
    pg.uncheck("input[name=g][value=privacy]"); pg.click("#go"); pg.wait_for_timeout(500)
    for i in range(4):
        multi = pg.query_selector("#ok") is not None
        pg.query_selector_all("#ans .opt")[0].click()
        if multi: pg.click("#ok")
        pg.wait_for_timeout(300)
    pg.evaluate("document.querySelector('.ctl').scrollIntoView({block:'end'});window.scrollBy(0,40)"); pg.wait_for_timeout(400)
    pg.screenshot(path=f"{OUT}/03-chat.png")
    for _ in range(60):
        if pg.is_visible("#sheet"): break
        multi = pg.query_selector("#ok") is not None
        pg.query_selector_all("#ans .opt")[0].click()
        if multi: pg.click("#ok")
        pg.wait_for_timeout(120)
    to(pg, "#sheet", 10); pg.screenshot(path=f"{OUT}/04-sheet.png")
    to(pg, "#tbl", 10); pg.screenshot(path=f"{OUT}/05-table.png")
    pg.goto(U + "genre/food", wait_until="networkidle"); zoom(pg, 1.5); to(pg, ".items", 20); pg.screenshot(path=f"{OUT}/06-items.png")
    b.close()
print("ok")
