#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""OGP画像（1200×630）を作る。検索キーワード「法令チェックシート」を一番大きく、Kurage の名前とマスコットを入れる。数字は焼き込まない。

  /usr/bin/python3 scripts/make_ogp.py   → php/klawnavi-ogp.png
"""
import os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MASCOT = os.path.join(ROOT, "..", "kurage_web", "images", "kurage-mascot-cutout.png")
B = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
K = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"

W, H = 1200, 630
im = Image.new("RGB", (W, H), "#f6f8f7")
d = ImageDraw.Draw(im)
d.rectangle([0, 0, W, 14], fill="#2f6b5a")
d.rounded_rectangle([60, 70, 440, 118], 24, fill="#e8f2ee", outline="#cfe2da", width=2)
d.text((84, 76), "ISO 27001・Pマーク・HACCP", font=ImageFont.truetype(B, 24), fill="#235245")
d.text((60, 150), "法令チェック", font=ImageFont.truetype(K, 104), fill="#2f6b5a")
d.text((60, 268), "シートをAIで作る", font=ImageFont.truetype(K, 84), fill="#1f2833")
d.text((62, 392), "AIの質問に答えるだけで、守るべき法令の一覧ができる", font=ImageFont.truetype(B, 28), fill="#46525f")
m = Image.open(MASCOT).convert("RGBA")
r = 380 / m.height
m = m.resize((int(m.width * r), 380))  # 縦横比を保つ（変形させない）
im.paste(m, (W - m.width - 50, 150), m)
d.rectangle([0, H - 86, W, H], fill="#ffffff")
d.line([0, H - 86, W, H - 86], fill="#dfe5e9", width=2)
d.text((60, H - 66), "Kurage 法令チェックシートAI", font=ImageFont.truetype(K, 38), fill="#1f2833")
d.text((W - 360, H - 58), "株式会社エクスブリッジ", font=ImageFont.truetype(B, 28), fill="#5f6b78")
out = os.path.join(ROOT, "php", "klawnavi-ogp.png")
im.save(out, optimize=True)
print("→", out)
