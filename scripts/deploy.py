#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""proto.exbridge.jp/klawnavi/ へ置く（FTP は1接続にまとめる）。

  /usr/bin/python3 scripts/build_data.py && /usr/bin/python3 scripts/deploy.py

置くもの: php/index.php・php/.htaccess・php/klawnavi_config.php（あれば）・php/klawnavi_data/data.json・php/klawnavi-ogp.png
認証は aixec/.env の FTP_HOST / FTP_USER / FTP_PASS。
"""
import ftplib, os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV = os.path.join(ROOT, "..", "aixec", ".env")
for line in open(ENV, encoding="utf-8"):
    if "=" in line and not line.startswith("#"):
        k, v = line.rstrip("\n").split("=", 1)
        os.environ.setdefault(k, v.strip().strip('"').strip("'"))
DEST = "/web/proto_exbridge_jp/klawnavi"
FILES = ["index.php", ".htaccess", "klawnavi_config.php", "klawnavi-ogp.png", "klawnavi_data/data.json"]


def main() -> int:
    f = ftplib.FTP(); f.encoding = "utf-8"
    f.connect(os.environ["FTP_HOST"], 21, timeout=60); f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    for d in (DEST, DEST + "/klawnavi_data"):
        try:
            f.mkd(d)
        except ftplib.error_perm:
            pass
    for rel in FILES:
        src = os.path.join(ROOT, "php", rel)
        if not os.path.exists(src):
            print("  (無いので飛ばす)", rel); continue
        with open(src, "rb") as fh:
            f.storbinary("STOR " + DEST + "/" + rel, fh)
        print("  置いた", rel, os.path.getsize(src), "バイト")
    f.quit()
    return 0


if __name__ == "__main__":
    sys.exit(main())
