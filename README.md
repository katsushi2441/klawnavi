# Kurage 法令チェックシートAI（klawnavi）

**AIの質問に答えるだけで、守るべき法令の一覧ができる。**
ジャンル（情報・個人情報、食品衛生 …）を選んで「インタビュー開始」を押すと、AI が会社の業務や設備について1問ずつ聞きます。
答え終わると、その会社に当てはまる法令のチェック項目だけが並んだ「法令チェックシート」ができます。

- デモ: https://proto.exbridge.jp/klawnavi/
- 販売（ソースコードごと納品・税込110,000円）: https://kappstore.exbridge.jp/app.php?id=d821883093629b57
- ジャンル: 情報・個人情報（ISO/IEC 27001・プライバシーマーク）／食品衛生（HACCP・JFS・FSSC 22000）
- PHP 1ファイル＋データ（JSON）1本。**インタビューの答えはブラウザの中だけで扱い、サーバーに残しません。**

ISO・プライバシーマーク・HACCP・エコアクション21 などの認証では、自社に当てはまる法令を洗い出し、
守れているかを毎年確かめることが求められます。どの法令が当てはまるかは業務や設備で変わり、
それを判断するのに何を調べればよいかが、担当者にも分かりにくい。そこを聞き取りで埋めるシステムです。
名古屋の環境分析会社と作った「環境法令AIナビ」の考え方を、ほかの分野にも使えるようにしました。

## AI に法令を作らせない

- チェック項目は、人が e-Gov法令検索の条文で確かめて入れたデータ（`data/genres/*.json`）からだけ出します。
- AI（デモは当社サーバーの gemma4）がするのは、文章で書かれた答えを選択肢に直すことと、
  「この質問はどういう意味？」と聞き返されたときの説明だけです。AI に送るのは、その1問の答えの文章だけです。
- 「わからない」と答えた質問に関係する項目は、消さずに **要確認** として残します。

## 画面

| パス | 内容 |
|---|---|
| `/klawnavi/` | ジャンルと所在地を選んでインタビュー → チェックシート（Excel 保存・印刷・回答の保存と読み込み） |
| `/klawnavi/genre/<id>` | そのジャンルの全項目（条文の一節つき） |
| `/klawnavi/about` | 作りと出典 |
| `/klawnavi/api/interpret` | POST `{g, q, text}` → `{values, reply}`（答えの文章を選択肢に直す） |
| `/klawnavi/sitemap.xml`, `/klawnavi/llms.txt` | SEO・AEO |

## データを作り直す

```bash
/usr/bin/python3 scripts/fetch_law.py               # 条文を取る（data/laws_<ジャンル>.json の法令）
/usr/bin/python3 scripts/verify_law.py              # 全項目を条文と突き合わせる（落ちたら公開しない）
/usr/bin/python3 scripts/build_data.py              # php/klawnavi_data/data.json を作る（検算を通ったときだけ）
/usr/bin/python3 scripts/make_ogp.py                # OGP画像
/usr/bin/python3 scripts/deploy.py                  # proto.exbridge.jp/klawnavi/ へ
```

データの形は [docs/DATA.md](docs/DATA.md)。ジャンルは1ファイル足すだけで増やせます（環境・労働安全衛生などは自社に置く版で追加）。

## 自分のところに置く

PHP が動くサーバーに `php/` の中身（index.php・.htaccess・klawnavi_data/）を置くだけです。
AI を使うときは `klawnavi_config.php` に OpenAI 互換の口（`KLN_API_BASE`・`KLN_API_KEY`）を書きます。無ければ選択肢を押して答える形で動きます。
heteml では `.htaccess` の `AddHandler` が要ります（既定は PHP 5.6）。

## 出典

法令の条文: e-Gov法令検索 法令API v2（デジタル庁）。政府標準利用規約(第2.0版)に従い出典を示しています。
条例・ガイドライン: 各自治体・省庁の公式ページ（項目ごとに確かめた日を記録）。

## ライセンス

MIT（ソースコード）。条文の権利は各出典元にあります。
