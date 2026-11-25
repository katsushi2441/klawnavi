<?php
/**
 * Kurage 法令チェックシートAI（klawnavi）
 *
 * ジャンル（情報・個人情報、食品衛生 …）を選んでインタビューを始めると、AI が1問ずつ聞き、
 * 答えに当てはまる法令のチェック項目だけを並べた「法令チェックシート」を作る。
 *
 * - チェック項目は人が e-Gov の条文で確かめたデータ（klawnavi_data/data.json）からだけ出す。AI に法令を作らせない。
 *   AI の仕事は、自由に書かれた答えを選択肢に直すことと、質問の意味を聞き返されたときの説明だけ。
 * - インタビューの答えはブラウザの中で持つ。サーバーには残さない（AI に送るのは、その1問の答えの文章だけ）。
 *
 * 置き方: このファイルと klawnavi_data/（data.json）・.htaccess を同じ階層に置く。
 *         AI を使うときは klawnavi_config.php に KLN_API_BASE と KLN_API_KEY（OpenAI 互換の口）を書く。
 *         無ければ AI なしで動く（選択肢を押して答える）。
 */

$SITE   = 'Kurage 法令チェックシートAI';
$SUB    = 'インタビューに答えると、守るべき法令の一覧ができる';
$ROOT   = '/klawnavi/';
$BASE   = 'https://proto.exbridge.jp/klawnavi/';
$OGP    = $BASE . 'klawnavi-ogp.png';
$MASCOT = 'https://kurage.exbridge.jp/images/kurage-mascot-cutout-300.webp';
$LOGO   = 'https://exbridge.jp/images/logo-mark-128.png';

mb_internal_encoding('UTF-8');
if (is_file(__DIR__ . '/klawnavi_config.php')) { require __DIR__ . '/klawnavi_config.php'; }

$DATA = json_decode((string)@file_get_contents(__DIR__ . '/klawnavi_data/data.json'), true);
if (!is_array($DATA)) { http_response_code(503); header('Content-Type: text/html; charset=UTF-8'); echo '<p>準備中です。</p>'; exit; }
$GEN = array();
foreach ($DATA['genres'] as $g) { $GEN[$g['id']] = $g; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------- 道筋
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rel  = trim(substr($path, strlen($ROOT)) ?: '', '/');
if (strpos($path, $ROOT) !== 0) { $rel = ''; }

if ($rel === 'api/interpret') { api_interpret(); exit; }
if ($rel === 'sitemap.xml') { sitemap(); exit; }
if ($rel === 'llms.txt') { llms(); exit; }
if (preg_match('#^genre/([a-z0-9_-]+)$#', $rel, $m) && isset($GEN[$m[1]])) { page_genre($GEN[$m[1]]); exit; }
if ($rel === 'about') { page_about(); exit; }
if ($rel === '' || $rel === 'index.php') { page_top(); exit; }
http_response_code(404); head_html('ページが見つかりません｜' . $SITE, '', $ROOT, false);
echo '<h1>ページが見つかりません</h1><p><a href="' . h($ROOT) . '">トップへ</a></p>'; foot_html(); exit;

// ---------------------------------------------------------------- AI（答えの文章を選択肢に直す）
function api_interpret() {
    global $GEN;
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $in = json_decode((string)file_get_contents('php://input'), true);
    $g = $GEN[$in['g'] ?? ''] ?? null;
    $q = null;
    if ($g) { foreach ($g['questions'] as $x) { if ($x['id'] === ($in['q'] ?? '')) { $q = $x; } } }
    $text = trim(mb_substr((string)($in['text'] ?? ''), 0, 400));
    if (!$q || $text === '') { http_response_code(400); echo json_encode(array('error' => 'bad request')); return; }
    if (!defined('KLN_API_BASE') || !defined('KLN_API_KEY')) {
        echo json_encode(array('values' => array(), 'reply' => 'いまは文章での回答を読み取れません。下のボタンから選んでください。', 'ai' => false), JSON_UNESCAPED_UNICODE);
        return;
    }
    $opts = array();
    foreach ($q['options'] as $o) { $opts[] = $o['v'] . '：' . $o['label']; }
    $opts[] = 'unknown：わからない・判断できない';
    $sys = "あなたは、会社の担当者に聞き取りをして法令チェックシートを作る係です。\n"
         . "質問と選択肢と、担当者が文章で書いた答えを渡します。答えを選択肢の値に直し、JSON だけを返してください。\n"
         . '形式: {"values": ["値", ...], "reply": "担当者への短い受け答え（1〜2文）"}' . "\n"
         . "決まり:\n"
         . "- values には選択肢の値（：の左側）だけを入れる。" . ($q['type'] === 'multi' ? '当てはまるものはいくつでも入れてよい。' : '1つだけ入れる。') . "\n"
         . "- 答えから判断できないときは unknown。\n"
         . "- 答えが質問への聞き返し（「〜って何？」など）のときは values を空にし、reply で質問の意味をやさしく説明してから、もう一度答えてもらうよう頼む。\n"
         . "- 法令の名前や条文、罰則の話はしない。推測で事実を足さない。\n"
         . "- reply は「です・ます」の自然な日本語。";
    $usr = "質問: " . $q['text'] . "\n質問の理由: " . ($q['why'] ?? '') . "\n選択肢:\n- " . implode("\n- ", $opts) . "\n\n担当者の答え: " . $text;
    $body = json_encode(array('model' => (defined('KLN_MODEL') ? KLN_MODEL : 'gemma4'), 'temperature' => 0.1, 'max_tokens' => 400,
        'messages' => array(array('role' => 'system', 'content' => $sys), array('role' => 'user', 'content' => $usr))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init(rtrim(KLN_API_BASE, '/') . '/chat/completions');
    curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . KLN_API_KEY)));
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $txt = '';
    if ($res !== false && $code === 200) { $j = json_decode($res, true); $txt = (string)($j['choices'][0]['message']['content'] ?? ''); }
    $out = null;
    if (preg_match('/\{.*\}/su', $txt, $m)) { $out = json_decode($m[0], true); }
    if (!is_array($out)) {
        echo json_encode(array('values' => array(), 'reply' => 'うまく読み取れませんでした。下のボタンから選んでください。', 'ai' => false), JSON_UNESCAPED_UNICODE);
        return;
    }
    $ok = array('unknown' => true);
    foreach ($q['options'] as $o) { $ok[$o['v']] = true; }
    $vals = array();
    foreach ((array)($out['values'] ?? array()) as $v) { if (is_string($v) && isset($ok[$v])) { $vals[] = $v; } }
    if ($q['type'] !== 'multi') { $vals = array_slice($vals, 0, 1); }
    if (in_array('unknown', $vals, true) && count($vals) > 1) { $vals = array_values(array_diff($vals, array('unknown'))); }
    $reply = trim(mb_substr((string)($out['reply'] ?? ''), 0, 300));
    echo json_encode(array('values' => $vals, 'reply' => $reply, 'ai' => true), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------- 共通の部品
function head_html($title, $desc, $canon, $index = true, $ld = array()) {
    global $SITE, $SUB, $BASE, $OGP, $MASCOT, $LOGO, $ROOT;
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="robots" content="' . ($index ? 'index,follow,max-image-preview:large' : 'noindex') . '">';
    if ($desc !== '') { echo '<meta name="description" content="' . h($desc) . '">'; }
    $url = 'https://proto.exbridge.jp' . $canon;
    echo '<link rel="canonical" href="' . h($url) . '">';
    echo '<link rel="icon" type="image/webp" href="' . h($MASCOT) . '">';
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h($SITE) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '">';
    echo '<meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . h($OGP) . '"><meta property="og:locale" content="ja_JP">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    $graph = array_merge(array(
        array('@type' => 'WebSite', 'name' => $SITE, 'url' => $BASE, 'inLanguage' => 'ja'),
        array('@type' => 'SoftwareApplication', 'name' => $SITE, 'alternateName' => $SUB, 'url' => $BASE,
              'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'PHP が動くサーバー', 'inLanguage' => 'ja',
              'description' => 'ISO・プライバシーマーク・HACCP などの認証で求められる「守るべき法令の一覧」を、AIのインタビューに答えるだけで作る法令チェックシート作成システム。項目は e-Gov の条文で確かめたものだけを使います。',
              'image' => $OGP,
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/'))), $ld);
    echo '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>';
    echo '<style>' . css() . '</style></head><body>';
    echo '<header class="top"><div class="wrap hd">';
    echo '<a class="brand" href="' . h($ROOT) . '"><img class="logo" src="' . h($LOGO) . '" alt="株式会社エクスブリッジ" width="36" height="36">'
       . '<span><b>' . h($SITE) . '</b><small>' . h($SUB) . '</small></span></a>';
    echo '<nav class="menu"><a href="' . h($ROOT) . '#start">インタビューを始める</a>';
    global $GEN;
    foreach ($GEN as $g) { echo '<a href="' . h($ROOT . 'genre/' . $g['id']) . '">' . h($g['title']) . 'の項目</a>'; }
    echo '<a href="' . h($ROOT . 'about') . '">このシステムについて</a></nav>';
    echo '</div></header><main class="wrap">';
}

function share_html($title, $summary, $url) {
    $text = '【' . $title . '】' . $summary;
    echo '<div class="share"><button type="button" class="sbtn" data-copy="' . h($text . "\n" . $url) . '">紹介文とURLをコピー</button>'
       . '<a class="sbtn x" target="_blank" rel="noopener" href="https://x.com/intent/post?text=' . rawurlencode($text) . '&amp;url=' . rawurlencode($url) . '">Xで共有</a>'
       . '<span class="copied" hidden>コピーしました</span></div>';
}

function foot_html() {
    global $DATA, $LOGO, $MASCOT, $ROOT;
    echo '</main><footer><div class="wrap">';
    echo '<div class="cta"><img src="' . h($MASCOT) . '" alt="Kurage" width="265" height="300">'
       . '<div><b>自社の書式・自社の法令データで使いたい</b><p>このシステムは、自社のサーバーに置ける業務システムとして提供しています。'
       . '環境（エコアクション21・ISO 14001）、労働安全衛生など、ジャンルの追加や、御社の監査で使っているチェックシートの書式に合わせる改修も承ります。</p>'
       . '<p><a class="btn" href="https://kappstore.exbridge.jp/app.php?id=d821883093629b57&amp;ref=klawnavi-foot">Kurage App Store で見る（税込110,000円）</a> '
       . '<a class="btn ghost" href="https://exbridge.jp/contact.php#ref=klawnavi-foot">導入・改修の相談</a> '
       . '<a class="btn ghost" href="https://exbridge.jp/ai-it-komon.html#ref=klawnavi-foot">AI-IT顧問について</a></p></div></div>';
    echo '<p class="src">チェック項目の根拠は <a href="https://laws.e-gov.go.jp/" rel="nofollow">e-Gov法令検索</a>（デジタル庁）の条文です（政府標準利用規約に従って出典を示しています）。'
       . '条例・ガイドラインは各自治体・省庁の公式ページで確かめ、確かめた日を付けています。データの更新日 ' . h($DATA['asof']) . '。<br>'
       . 'このシステムが作るチェックシートは、遵守状況を確かめるための<strong>たたき台</strong>です。当てはまるかどうかの最終的な判断は、所管の役所や専門家に確かめてください。</p>';
    echo '<div class="co"><img src="' . h($LOGO) . '" alt="株式会社エクスブリッジ" width="40" height="40"><span><b>株式会社エクスブリッジ</b>'
       . '<span>名古屋市瑞穂区　創業2004年　<a href="https://exbridge.jp/">会社のサイト</a>　<a href="https://proto.exbridge.jp/">ほかのシステムのデモ</a></span></span></div>';
    echo '</div></footer>';
    echo '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-copy]");if(!b)return;'
       . 'navigator.clipboard.writeText(b.getAttribute("data-copy")).then(function(){var c=b.parentNode.querySelector(".copied");if(c){c.hidden=false;setTimeout(function(){c.hidden=true},1800)}})});</script>';
    echo '</body></html>';
}

function css() {
    return ':root{--ink:#1f2833;--mut:#5f6b78;--ac:#2f6b5a;--ac-d:#235245;--ac-l:#e8f2ee;--line:#dfe5e9;--bg:#f6f8f7;--amb:#8a6516;--amb-l:#fbf3e1;--blue:#2f5f86;--blue-l:#eaf1f7}'
     . '*{box-sizing:border-box}html,body{overflow-x:hidden;max-width:100%}'
     . 'body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.8 system-ui,-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif}'
     . 'img{max-width:100%}a{color:var(--ac-d)}.wrap{width:min(1040px,100% - 32px);margin:0 auto}'
     . 'header.top{background:#fff;border-bottom:1px solid var(--line)}.hd{padding:12px 0 4px}'
     . '.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink)}'
     . '.brand .logo{width:36px;height:36px;object-fit:contain;flex:none}'
     . '.brand b{display:block;font-size:18px;line-height:1.3}.brand small{display:block;font-size:12px;color:var(--mut)}'
     . '.menu{display:flex;flex-wrap:wrap;gap:4px 16px;padding:8px 0 10px;font-size:14px}.menu a{color:var(--mut);text-decoration:none}'
     . 'main{padding:26px 0 40px}h1{font-size:28px;line-height:1.45;margin:0 0 10px;text-wrap:balance}'
     . 'h1 .k{color:var(--ac)}h2{font-size:21px;margin:40px 0 14px}h3{font-size:17px;margin:0 0 6px}'
     . '.hero{display:grid;grid-template-columns:minmax(0,1fr) 200px;gap:20px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px 28px}'
     . '.hero>*{min-width:0}.hero img{width:100%;max-width:200px;height:auto;justify-self:end}'
     . '.badge{display:inline-block;font-size:12px;letter-spacing:.08em;color:var(--ac-d);background:var(--ac-l);border:1px solid #cfe2da;border-radius:999px;padding:3px 12px;margin-bottom:10px}'
     . '.lead{color:#46525f;margin:0}'
     . '.share{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0 0}'
     . '.sbtn{font:inherit;font-size:13.5px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 12px;color:var(--ink);cursor:pointer;text-decoration:none}'
     . '.sbtn.x{background:#111;color:#fff;border-color:#111}.copied{font-size:13px;color:var(--ac)}'
     . '.steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0 0}'
     . '.steps>div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;min-width:0}'
     . '.steps .n{display:inline-flex;width:28px;height:28px;border-radius:50%;background:var(--ac);color:#fff;font-weight:700;align-items:center;justify-content:center;font-size:14px;margin-bottom:6px}'
     . '.steps p{margin:0;font-size:14.5px;color:#46525f}'
     . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:22px 24px;margin:16px 0}'
     . '.gcards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:12px}'
     . '.gcard{display:flex;gap:12px;align-items:flex-start;border:2px solid var(--line);border-radius:12px;padding:14px 16px;cursor:pointer;background:#fff}'
     . '.gcard:has(input:checked){border-color:var(--ac);background:var(--ac-l)}.gcard input{width:20px;height:20px;margin-top:4px;flex:none}'
     . '.gcard b{display:block;font-size:16.5px}.gcard span{display:block;font-size:13px;color:var(--mut)}'
     . '.chip{display:inline-block;font-size:12px;background:var(--blue-l);color:var(--blue);border-radius:6px;padding:1px 8px;margin:4px 4px 0 0}'
     . '.soon{opacity:.6;cursor:default}'
     . 'select{font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;max-width:100%}'
     . '.row{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px}'
     . '.btn{display:inline-block;background:var(--ac);color:#fff;border:0;border-radius:9px;padding:12px 24px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
     . '.btn:disabled{opacity:.45;cursor:default}.btn.ghost{background:#fff;color:var(--ac-d);border:1px solid var(--line);font-weight:400;padding:10px 16px}'
     // インタビュー
     . '#chat{display:none}.prog{height:6px;background:#e7ecef;border-radius:9px;overflow:hidden;margin:4px 0 14px}.prog i{display:block;height:100%;background:var(--ac);width:0;transition:width .3s}'
     . '.log{display:flex;flex-direction:column;gap:10px}'
     . '.msg{display:flex;gap:10px;align-items:flex-start;max-width:100%}.msg>*{min-width:0}'
     . '.msg img{width:38px;height:38px;object-fit:contain;flex:none;background:#eef3f2;border-radius:10px;padding:2px}'
     . '.bub{background:#fff;border:1px solid var(--line);border-radius:4px 14px 14px 14px;padding:11px 15px;max-width:640px}'
     . '.bub .why{display:block;font-size:13px;color:var(--mut);margin-top:4px}'
     . '.msg.me{justify-content:flex-end}.msg.me .bub{background:var(--ac-l);border-color:#cfe2da;border-radius:14px 4px 14px 14px}'
     . '.ans{margin:12px 0 0 48px}.opts{display:flex;flex-wrap:wrap;gap:8px}'
     . '.opt{font:inherit;font-size:15px;background:#fff;border:1.5px solid #b9cfc6;color:var(--ac-d);border-radius:999px;padding:8px 16px;cursor:pointer}'
     . '.opt.on{background:var(--ac);color:#fff;border-color:var(--ac)}.opt.unk{border-style:dashed;color:var(--mut);border-color:#c5ccd2}'
     . '.free{display:flex;gap:8px;margin-top:10px}.free input{flex:1;min-width:0;font:inherit;font-size:16px;padding:10px 12px;border:1px solid var(--line);border-radius:9px}'
     . '.free button{flex:none}.hint{font-size:12.5px;color:var(--mut);margin-top:6px}'
     . '.ctl{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 0 48px}'
     . '.typing{color:var(--mut);font-size:14px}'
     // チェックシート
     . '#sheet{display:none}.sum{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:10px 0 16px}'
     . '.sum div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 16px}.sum b{display:block;font-size:26px;line-height:1.2}'
     . '.sum span{font-size:13px;color:var(--mut)}'
     . '.tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;background:#fff;border:1px solid var(--line);border-radius:12px}'
     . 'table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:880px}'
     . 'table.t th,table.t td{border-bottom:1px solid #edf1f3;padding:10px 12px;text-align:left;vertical-align:top}'
     . 'table.t th{background:#f6f9f8;font-size:12px;color:var(--mut);font-weight:600;white-space:nowrap}'
     . 'table.t td.cite{min-width:150px}table.t td.cite a{color:var(--ink)}table.t td .ex{display:block;font-size:12px;color:var(--mut);margin-top:4px}'
     . '.tag{display:inline-block;font-size:11.5px;border-radius:5px;padding:1px 7px;margin:0 4px 4px 0;white-space:nowrap}'
     . '.tag.law{background:var(--blue-l);color:var(--blue)}.tag.loc{background:var(--ac-l);color:var(--ac-d)}.tag.gl{background:#f1eef7;color:#5b4a86}'
     . '.tag.chk{background:var(--amb-l);color:var(--amb);font-weight:700}'
     . '.reason{font-size:12.5px;color:var(--mut)}'
     . '.items{display:grid;gap:10px}.item{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 18px}'
     . '.item .meta{font-size:13px;color:var(--mut)}.item blockquote{margin:8px 0 0;padding:8px 12px;background:#f8faf9;border-left:3px solid var(--line);font-size:13.5px;color:#46525f}'
     . '.qa{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin:10px 0}.qa h3{font-size:16px}.qa p{margin:0;color:#46525f}'
     . 'footer{background:#fff;border-top:1px solid var(--line);padding:26px 0 40px;margin-top:20px}'
     . '.cta{display:grid;grid-template-columns:140px minmax(0,1fr);gap:18px;align-items:center;background:var(--ac-l);border:1px solid #cfe2da;border-radius:14px;padding:18px 20px;margin-bottom:18px}'
     . '.cta img{width:100%;max-width:140px;height:auto}.cta b{font-size:17px}.cta p{margin:6px 0}'
     . '.src{font-size:12.5px;color:var(--mut);line-height:1.85}'
     . '.co{display:flex;gap:10px;align-items:center;font-size:13px}.co img{width:40px;height:40px;object-fit:contain}.co b{display:block}'
     . '@media(max-width:700px){.hero{grid-template-columns:1fr;padding:20px}.hero img{max-width:120px;justify-self:center}'
     . '.steps{grid-template-columns:1fr}.sum{grid-template-columns:repeat(3,minmax(0,1fr))}.sum b{font-size:21px}'
     . '.ans,.ctl{margin-left:0}.cta{grid-template-columns:1fr}.cta img{max-width:100px}h1{font-size:23px}}'
     . '@media print{header.top,footer,#start,#chat,.noprint,.share,.hero,.steps,h2.noprint{display:none!important}'
     . 'body{background:#fff;font-size:11px}#sheet{display:block!important}.tscroll{overflow:visible;border:0}table.t{min-width:0;font-size:10px}}';
}

// ---------------------------------------------------------------- ページ
function page_top() {
    global $DATA, $GEN, $ROOT, $BASE, $SITE, $MASCOT;
    $faq = array(
        array('法令チェックシートとは何ですか', 'ISO 27001・プライバシーマーク・HACCP・エコアクション21 などの認証では、自社に当てはまる法令を洗い出し、守れているかを毎年確かめることが求められます。その「当てはまる法令」と「確かめること」を一覧にしたものが法令チェックシート（法規制登録簿・法令遵守評価表とも呼ばれます）です。'),
        array('AIが法令を判断するのですか', 'いいえ。チェック項目は、人が e-Gov法令検索の条文で確かめて入れたデータからだけ出します。AIは、文章で書かれた答えを選択肢に直すことと、質問の意味を聞かれたときの説明だけをします。'),
        array('入力した内容はどこかに保存されますか', 'インタビューの答えはブラウザの中だけで持ち、サーバーには残しません。文章で答えたときだけ、その1問の答えをAIに送って読み取ります。答えはファイルに保存して、翌年の見直しで読み込めます。'),
        array('どのジャンルに対応していますか', '今は「情報・個人情報（ISO 27001・プライバシーマーク）」と「食品衛生（HACCP）」です。環境（エコアクション21・ISO 14001）や労働安全衛生（ISO 45001）は、自社に置く版で追加できます。'),
    );
    $ld = array(array('@type' => 'FAQPage', 'mainEntity' => array_map(function ($x) {
        return array('@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $x[1])); }, $faq)));
    head_html('法令チェックシートをAIのインタビューで作成｜' . $SITE,
        'ISO 27001・プライバシーマーク・HACCPの審査で求められる法令チェックシートを、AIの質問に答えるだけで作ります。業務や設備に合わせて当てはまる法令だけを、e-Govの条文で確かめた項目で一覧にします。',
        $ROOT, true, $ld);
    echo '<section class="hero"><div><span class="badge">ISO 27001・プライバシーマーク・HACCP の法令チェック</span>';
    echo '<h1><span class="k">法令チェックシート</span>を、AIのインタビューで作る</h1>';
    echo '<p class="lead">ジャンルを選んで「インタビュー開始」を押すと、AIが会社の業務や設備について1問ずつ聞きます。答え終わると、御社に当てはまる法令のチェック項目だけが並んだ一覧ができます。項目はすべて e-Gov の条文で確かめたものです。</p>';
    echo '</div><img src="' . h($MASCOT) . '" alt="Kurage のマスコット" width="265" height="300"></section>';
    share_html($SITE, '業務や設備についてAIの質問に答えるだけで、ISO・プライバシーマーク・HACCPの審査で使う法令チェックシートができます。', $BASE);
    echo '<div class="steps"><div><span class="n">1</span><h3>ジャンルを選ぶ</h3><p>個人情報、食品衛生など。いくつでも一緒に選べます。所在地を選ぶと、その地域の条例も入ります。</p></div>'
       . '<div><span class="n">2</span><h3>AIの質問に答える</h3><p>ボタンで選んでも、文章で答えても構いません。分からない質問は「わからない」で先へ進めます。</p></div>'
       . '<div><span class="n">3</span><h3>チェックシートができる</h3><p>法令と条番号、やること、頻度、確かめ方、残す記録。Excel に保存して、確認日と結果を書き込めます。</p></div></div>';

    echo '<h2 id="start">インタビューを始める</h2><div class="panel noprint" id="setup"><div class="gcards">';
    foreach ($GEN as $g) {
        echo '<label class="gcard"><input type="checkbox" name="g" value="' . h($g['id']) . '"' . ($g['id'] === array_key_first($GEN) ? ' checked' : '') . '>'
           . '<span><b>' . h($g['title']) . '</b><span>' . h($g['summary'] ?? '') . '</span>';
        foreach ($g['standards'] as $s) { echo '<i class="chip">' . h($s) . '</i>'; }
        echo '<span>質問 ' . count($g['questions']) . '問から・項目 ' . count($g['items']) . '件</span></span></label>';
    }
    foreach (array('環境（エコアクション21・ISO 14001）', '労働安全衛生（ISO 45001）') as $t) {
        echo '<div class="gcard soon"><span><b>' . h($t) . '</b><span>自社に置く版で追加できます（<a href="https://exbridge.jp/contact.php#ref=klawnavi-genre">相談する</a>）</span></span></div>';
    }
    echo '</div><div class="row"><label>事業所の所在地 <select id="region"><option value="全国">全国の法令だけ</option><option value="名古屋市" selected>愛知県 名古屋市</option></select></label>'
       . '<button class="btn" id="go" type="button">インタビュー開始</button>'
       . '<label class="btn ghost">前回の回答を読み込む<input type="file" id="load" accept=".json" hidden></label></div>'
       . '<p class="hint">答えはこのブラウザの中だけで使い、サーバーには残しません。</p></div>';

    echo '<div id="chat" class="panel"><div class="prog"><i id="bar"></i></div><div class="log" id="log"></div><div id="ans"></div>'
       . '<div class="ctl noprint"><button class="btn ghost" type="button" id="back">ひとつ前に戻る</button><button class="btn ghost" type="button" id="restart">最初からやり直す</button></div></div>';

    echo '<div id="sheet"><h2>法令チェックシート</h2><p class="lead" id="sheetlead"></p><div class="sum" id="sum"></div>'
       . '<div class="row noprint"><button class="btn" type="button" id="xlsx">Excel で保存</button><button class="btn ghost" type="button" onclick="window.print()">印刷・PDF</button>'
       . '<button class="btn ghost" type="button" id="save">回答をファイルに保存（翌年の見直し用）</button><button class="btn ghost" type="button" id="edit">答えを直す</button></div>'
       . '<div class="tscroll" style="margin-top:14px"><table class="t" id="tbl"></table></div>'
       . '<p class="src">「要確認」は、「わからない」と答えた質問に関係する項目です。担当の部署や専門家に確かめてください。チェックシートは遵守状況を確かめるためのたたき台で、当てはまるかどうかの最終的な判断は所管の役所や専門家に確かめてください。</p></div>';

    echo '<h2>よくある質問</h2>';
    foreach ($faq as $x) { echo '<div class="qa"><h3>' . h($x[0]) . '</h3><p>' . h($x[1]) . '</p></div>'; }
    app_js();
    foot_html();
}

function page_genre($g) {
    global $ROOT, $BASE, $SITE;
    head_html($g['title'] . 'の法令チェックシート項目一覧｜Kurage', $g['title'] . '（' . implode('・', $g['standards']) . '）の審査で確かめる法令のチェック項目 ' . count($g['items']) . '件の一覧です。法令名と条番号、やること、頻度、確かめ方、残す記録を、e-Gov の条文で確かめて載せています。',
        $ROOT . 'genre/' . $g['id'], true, array(array('@type' => 'BreadcrumbList', 'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => $BASE),
            array('@type' => 'ListItem', 'position' => 2, 'name' => $g['title'], 'item' => $BASE . 'genre/' . $g['id'])))));
    echo '<p class="hint"><a href="' . h($ROOT) . '">' . h($SITE) . '</a> ＞ ' . h($g['title']) . '</p>';
    echo '<h1>' . h($g['title']) . 'の法令チェック項目（' . count($g['items']) . '件）</h1>';
    share_html($SITE . '｜' . $g['title'], $g['title'] . '（' . implode('・', $g['standards']) . '）の審査で確かめる法令のチェック項目の一覧です。', $BASE . 'genre/' . $g['id']);
    echo '<p class="lead">' . h($g['summary'] ?? '') . ' ここには全部の項目を載せています。御社に当てはまるものだけにするには、<a href="' . h($ROOT) . '#start">インタビュー</a>に答えてください（質問 ' . count($g['questions']) . '問から）。</p>';
    echo '<div class="items" style="margin-top:16px">';
    foreach ($g['items'] as $it) {
        $cls = $it['kind'] === '法令' ? 'law' : ($it['kind'] === 'ガイドライン' ? 'gl' : 'loc');
        echo '<div class="item" id="' . h($it['id']) . '"><span class="tag ' . $cls . '">' . h($it['kind']) . '</span>'
           . ($it['region'] !== '全国' ? '<span class="tag loc">' . h($it['region']) . '</span>' : '')
           . '<h3>' . h($it['title']) . '</h3><div class="meta">' . ($it['law_url'] ? '<a href="' . h($it['law_url']) . '" rel="nofollow">' . h($it['cite']) . '</a>' : h($it['cite']))
           . '　頻度：' . h($it['freq']) . '</div><p style="margin:6px 0 0">' . h($it['duty']) . '</p>'
           . '<p class="meta" style="margin:6px 0 0">確かめ方：' . h($it['check']) . (empty($it['records']) ? '' : '<br>残す記録：' . h($it['records'])) . '</p>'
           . (!empty($it['excerpt']) ? '<blockquote>' . h(($it['caption'] ? '（' . $it['caption'] . '）' : '') . $it['excerpt']) . '</blockquote>' : '')
           . '</div>';
    }
    echo '</div><p style="margin-top:18px"><a class="btn" href="' . h($ROOT) . '#start">インタビューで自社に当てはまる項目だけにする</a></p>';
    foot_html();
}

function page_about() {
    global $ROOT, $BASE, $SITE, $DATA;
    head_html('このシステムについて｜' . $SITE, '法令チェックシートを AI のインタビューで作る Kurage 法令チェックシートAI の作りと、チェック項目の確かめ方、データの出典を説明します。', $ROOT . 'about');
    echo '<h1>このシステムについて</h1>';
    share_html($SITE, '業務や設備についてAIの質問に答えるだけで、ISO・プライバシーマーク・HACCPの審査で使う法令チェックシートができます。', $BASE . 'about');
    echo '<div class="panel"><h3>なぜ作ったか</h3><p>ISO やエコアクション21 の認証を受けると、自社に当てはまる法令を一覧にして、毎年守れているかを確かめる必要があります。ところが、どの法令が当てはまるかは業務の内容や設備で変わり、それを判断するのに何を調べればよいかが、担当者にも分かりにくいのが実情です。'
       . 'このシステムは、名古屋の環境分析会社と作った「環境法令AIナビ」の考え方を、ほかの分野にも使えるようにしたものです。AIが聞き取りをして、答えに当てはまる項目だけを並べます。</p></div>';
    echo '<div class="panel"><h3>チェック項目の確かめ方</h3><p>法律・政令・省令の項目は、e-Gov法令検索（デジタル庁）の法令API から条文を取り、条番号が実在するか、項目に書いた語が条文の本文にあるかを、公開の前に全項目で機械的に突き合わせています。改正で条文が動けば、ここで気づけます。'
       . '自治体の条例やガイドラインは、公式ページで確かめた日を項目ごとに残しています。いま載せている項目は ' . (int)$DATA['n_items'] . '件、質問は ' . (int)$DATA['n_questions'] . '問です（' . h($DATA['asof']) . ' 現在）。</p></div>';
    echo '<div class="panel"><h3>AIの使い方</h3><p>AIに法令を判断させることはしません。AIがするのは、文章で書かれた答えを選択肢に直すことと、「この質問はどういう意味？」と聞かれたときの説明だけです。デモでは、当社のサーバーで動かしている AI（gemma）を使っています。</p></div>';
    foot_html();
}

function sitemap() {
    global $GEN, $BASE, $DATA;
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $u = array('', 'about');
    foreach ($GEN as $g) { $u[] = 'genre/' . $g['id']; }
    foreach ($u as $p) { echo '<url><loc>' . h($BASE . $p) . '</loc><lastmod>' . h($DATA['asof']) . '</lastmod></url>'; }
    echo '</urlset>';
}

function llms() {
    global $GEN, $BASE, $SITE, $DATA;
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# {$SITE}\n\n> ISO 27001・プライバシーマーク・HACCP などの認証で求められる「法令チェックシート」（自社に当てはまる法令と、守れているかを確かめる項目の一覧）を、AIのインタビューに答えるだけで作るシステム。\n\n";
    echo "- チェック項目は人が e-Gov法令検索の条文で確かめたデータだけを使う。AIは答えの文章を選択肢に直すだけで、法令を判断しない。\n";
    echo "- 法令チェックシートは、法規制登録簿・法令遵守評価表・法的要求事項の一覧とも呼ばれる。\n";
    echo "- インタビューの答えはブラウザの中だけで扱い、サーバーに残さない。\n\n## ジャンル\n";
    foreach ($GEN as $g) { echo "- [{$g['title']}]({$BASE}genre/{$g['id']}): " . implode('・', $g['standards']) . "。質問 " . count($g['questions']) . "問・項目 " . count($g['items']) . "件\n"; }
    echo "\nデータの更新日: {$DATA['asof']}\n運営: 株式会社エクスブリッジ（名古屋）https://exbridge.jp/\n";
}

// ---------------------------------------------------------------- インタビューとチェックシート（ブラウザ側）
function app_js() {
    global $DATA, $ROOT, $MASCOT;
    $slim = array();
    foreach ($DATA['genres'] as $g) {
        $items = array();
        foreach ($g['items'] as $it) {
            $items[] = array_intersect_key($it, array_flip(array('id', 'title', 'kind', 'cite', 'law_url', 'duty', 'freq', 'check', 'records', 'need', 'need_any', 'region')));
        }
        $slim[] = array('id' => $g['id'], 'title' => $g['title'], 'questions' => $g['questions'], 'items' => $items);
    }
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>';
    echo '<script>var KLN=' . json_encode(array('genres' => $slim, 'api' => $ROOT . 'api/interpret', 'mascot' => $MASCOT, 'asof' => $DATA['asof']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
    echo '<script>' . <<<'JS'
(function(){
var $=function(s){return document.querySelector(s)};
var G={};KLN.genres.forEach(function(g){G[g.id]=g});
var st={gens:[],region:"名古屋市",ans:{},hist:[]};
function esc(s){return String(s==null?"":s).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]})}
function key(g,q){return g+"."+q}
function qs(){var a=[];st.gens.forEach(function(g){G[g].questions.forEach(function(q){a.push({g:g,q:q})})});return a}
// 条件の判定: true / false / "unk"
function cond(g,c){var m=c.match(/^([A-Za-z0-9_]+)(!=|=)(.+)$/);if(!m)return false;var a=st.ans[key(g,m[1])];
 if(a===undefined)return false;if(a==="unknown"||(Array.isArray(a)&&a.indexOf("unknown")>=0))return"unk";
 var hit=Array.isArray(a)?a.indexOf(m[3])>=0:a===m[3];return m[2]==="="?hit:!hit}
function judge(g,need,any){var u=false;for(var i=0;i<(need||[]).length;i++){var r=cond(g,need[i]);if(r===false)return false;if(r==="unk")u=true}
 if(any&&any.length){var ok=false,uu=false;any.forEach(function(c){var r=cond(g,c);if(r===true)ok=true;if(r==="unk")uu=true});if(!ok){if(uu)u=true;else return false}}
 return u?"unk":true}
function visible(x){var r=judge(x.g,x.q.show_if,x.q.show_if_any);return r!==false}
function nextQ(){var all=qs();for(var i=0;i<all.length;i++){if(st.ans[key(all[i].g,all[i].q.id)]===undefined&&visible(all[i]))return all[i]}return null}
function progress(){var all=qs().filter(visible),d=all.filter(function(x){return st.ans[key(x.g,x.q.id)]!==undefined}).length;$("#bar").style.width=(all.length?Math.round(d/all.length*100):100)+"%"}
function bot(html){var d=document.createElement("div");d.className="msg";d.innerHTML='<img src="'+KLN.mascot+'" alt="" width="38" height="38"><div class="bub">'+html+'</div>';$("#log").appendChild(d);return d}
function me(t){var d=document.createElement("div");d.className="msg me";d.innerHTML='<div class="bub">'+esc(t)+'</div>';$("#log").appendChild(d)}
function labelOf(q,v){if(v==="unknown")return"わからない";for(var i=0;i<q.options.length;i++)if(q.options[i].v===v)return q.options[i].label;return v}
function render(){progress();var x=nextQ();$("#ans").innerHTML="";
 if(!x){sheet();return}
 var multi=x.q.type==="multi",sel=[];
 var gtitle=st.gens.length>1?'<span class="chip">'+esc(G[x.g].title)+'</span><br>':"";
 bot(gtitle+esc(x.q.text)+(x.q.why?'<span class="why">'+esc(x.q.why)+'</span>':""));
 var box=document.createElement("div");box.className="ans";
 var h='<div class="opts">';x.q.options.forEach(function(o){h+='<button type="button" class="opt" data-v="'+esc(o.v)+'">'+esc(o.label)+'</button>'});
 h+='<button type="button" class="opt unk" data-v="unknown">わからない</button></div>';
 if(multi)h+='<div class="row" style="margin-top:10px"><button type="button" class="btn" id="ok" disabled>これで決定</button><span class="hint">当てはまるものをいくつでも選んでください</span></div>';
 h+='<div class="free"><input type="text" id="ft" placeholder="文章で答えても大丈夫です（例：社員は12人、パートが3人）" maxlength="300"><button type="button" class="btn ghost" id="fs">送る</button></div>';
 box.innerHTML=h;$("#ans").appendChild(box);
 box.querySelectorAll(".opt").forEach(function(b){b.onclick=function(){var v=b.getAttribute("data-v");
  if(!multi||v==="unknown"){answer(x,v);return}
  var i=sel.indexOf(v);if(i>=0){sel.splice(i,1);b.classList.remove("on")}else{sel.push(v);b.classList.add("on")}$("#ok").disabled=!sel.length}});
 if(multi)$("#ok").onclick=function(){answer(x,sel.slice())};
 var send=function(){var t=$("#ft").value.trim();if(!t)return;me(t);$("#ft").value="";var w=bot('<span class="typing">読み取っています…</span>');
  fetch(KLN.api,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({g:x.g,q:x.q.id,text:t})}).then(function(r){return r.json()}).then(function(j){
   w.remove();var v=j.values||[];
   if(!v.length){bot(esc(j.reply||"もう一度、下のボタンから選んでください。"));return}
   if(j.reply)bot(esc(j.reply));answer(x,multi?v:v[0],true)}).catch(function(){w.remove();bot("読み取れませんでした。下のボタンから選んでください。")})};
 $("#fs").onclick=send;$("#ft").onkeydown=function(e){if(e.key==="Enter"&&!e.isComposing)send()};
 box.scrollIntoView({block:"nearest",behavior:"smooth"})}
function answer(x,v,quiet){st.ans[key(x.g,x.q.id)]=v;st.hist.push(key(x.g,x.q.id));
 if(!quiet)me(Array.isArray(v)?v.map(function(z){return labelOf(x.q,z)}).join("・"):labelOf(x.q,v));render()}
function regionOk(r){if(!r||r.indexOf("全国")===0)return true;if(r==="愛知県")return st.region==="愛知県"||st.region==="名古屋市";return r===st.region}
function reason(g,it){var out=[];(it.need||[]).concat(it.need_any||[]).forEach(function(c){var m=c.match(/^([A-Za-z0-9_]+)(!=|=)(.+)$/);if(!m)return;
  var q=G[g].questions.filter(function(z){return z.id===m[1]})[0];var a=st.ans[key(g,m[1])];if(!q||a===undefined)return;
  var lab=Array.isArray(a)?a.map(function(z){return labelOf(q,z)}).join("・"):labelOf(q,a);var s=(q.label||q.text.slice(0,16))+"："+lab;if(out.indexOf(s)<0)out.push(s)});
 return out.length?out.join("／"):"このジャンルを選んだ全員"}
var ROWS=[];
function sheet(){$("#ans").innerHTML="";bot("質問は以上です。お答えいただいた内容から、法令チェックシートを作りました。");
 ROWS=[];st.gens.forEach(function(g){G[g].items.forEach(function(it){if(!regionOk(it.region))return;var r=judge(g,it.need,it.need_any);if(r===false)return;
  ROWS.push({g:G[g].title,it:it,unk:r==="unk",why:reason(g,it)})})});
 var nU=ROWS.filter(function(r){return r.unk}).length,nL=ROWS.filter(function(r){return r.it.kind!=="法令"}).length;
 $("#sum").innerHTML='<div><b>'+ROWS.length+'</b><span>当てはまる項目</span></div><div><b>'+nU+'</b><span>要確認</span></div><div><b>'+nL+'</b><span>条例・ガイドライン</span></div>';
 $("#sheetlead").textContent=st.gens.map(function(g){return G[g].title}).join("・")+"／所在地："+($("#region").selectedOptions[0]||{}).text+"／作成日："+new Date().toLocaleDateString("ja-JP")+"／データ "+KLN.asof+" 時点";
 var h='<thead><tr><th>No</th><th>根拠</th><th>チェック項目・やること</th><th>頻度</th><th>確かめ方・残す記録</th><th>当てはまった理由</th><th>確認日</th><th>結果</th></tr></thead><tbody>';
 ROWS.forEach(function(r,i){var it=r.it,c=it.kind==="法令"?"law":(it.kind==="ガイドライン"?"gl":"loc");
  h+='<tr><td>'+(i+1)+'</td><td class="cite"><span class="tag '+c+'">'+esc(it.kind)+'</span>'+(r.unk?'<span class="tag chk">要確認</span>':'')+'<br>'+(it.law_url?'<a href="'+esc(it.law_url)+'" target="_blank" rel="noopener nofollow">'+esc(it.cite)+'</a>':esc(it.cite))+'</td>'
   +'<td><b>'+esc(it.title)+'</b><br>'+esc(it.duty)+'</td><td>'+esc(it.freq)+'</td><td>'+esc(it.check)+(it.records?'<span class="ex">記録：'+esc(it.records)+'</span>':'')+'</td>'
   +'<td class="reason">'+esc(r.why)+'</td><td></td><td></td></tr>'});
 $("#tbl").innerHTML=h+'</tbody>';$("#sheet").style.display="block";$("#sheet").scrollIntoView({behavior:"smooth"})}
function start(){st.gens=[].slice.call(document.querySelectorAll('input[name=g]:checked')).map(function(i){return i.value});
 if(!st.gens.length){alert("ジャンルを1つ以上選んでください");return}st.region=$("#region").value;
 $("#chat").style.display="block";$("#sheet").style.display="none";$("#log").innerHTML="";
 bot("こんにちは。"+st.gens.map(function(g){return"「"+G[g].title+"」"}).join("と")+"について、御社の業務や設備のことを順番にお聞きします。ボタンで選んでも、文章で答えても構いません。");
 render();$("#chat").scrollIntoView({behavior:"smooth"})}
$("#go").onclick=function(){st.ans={};st.hist=[];start()};
$("#restart").onclick=function(){if(confirm("答えを消して最初からやり直しますか？")){st.ans={};st.hist=[];start()}};
$("#back").onclick=function(){var k=st.hist.pop();if(!k)return;delete st.ans[k];$("#sheet").style.display="none";$("#log").innerHTML="";
 // 答えを残したまま並べ直す
 var keep=JSON.parse(JSON.stringify(st.ans)),hist=st.hist.slice();st.ans={};st.hist=[];
 bot("ひとつ前の質問に戻りました。");hist.forEach(function(k){var p=k.split("."),q=G[p[0]].questions.filter(function(z){return z.id===p[1]})[0];if(!q)return;st.ans[k]=keep[k];st.hist.push(k);
  bot(esc(q.text));var v=keep[k];me(Array.isArray(v)?v.map(function(z){return labelOf(q,z)}).join("・"):labelOf(q,v))});render()};
$("#edit").onclick=function(){$("#back").click()};
$("#save").onclick=function(){var b=new Blob([JSON.stringify({app:"klawnavi",saved:new Date().toISOString(),gens:st.gens,region:st.region,ans:st.ans},null,1)],{type:"application/json"});
 var a=document.createElement("a");a.href=URL.createObjectURL(b);a.download="法令チェックシート_回答_"+new Date().toISOString().slice(0,10)+".json";a.click()};
$("#load").onchange=function(e){var f=e.target.files[0];if(!f)return;f.text().then(function(t){var j=JSON.parse(t);if(j.app!=="klawnavi")throw 0;
  document.querySelectorAll('input[name=g]').forEach(function(i){i.checked=j.gens.indexOf(i.value)>=0});$("#region").value=j.region||"全国";
  st.ans={};st.hist=[];start();var keep=j.ans;
  // 前回の答えをそのまま入れ、新しく増えた質問だけを聞く
  var guard=0;while(guard++<500){var x=nextQ();if(!x)break;var k=key(x.g,x.q.id);if(keep[k]===undefined)break;st.ans[k]=keep[k];st.hist.push(k)}
  $("#log").innerHTML="";bot("前回の回答を読み込みました。変わったところがあれば「ひとつ前に戻る」で直せます。");render()}).catch(function(){alert("このシステムで保存した回答ファイルを選んでください")})};
$("#xlsx").onclick=function(){if(!window.XLSX){alert("Excel の部品を読み込み中です。少し待ってからもう一度押してください");return}
 var aoa=[["No","ジャンル","区分","根拠（法令・条例）","チェック項目","やること","頻度","確かめ方","残す記録","当てはまった理由","要確認","確認日","担当","結果（○・×・該当なし）","備考"]];
 ROWS.forEach(function(r,i){var it=r.it;aoa.push([i+1,r.g,it.kind,it.cite,it.title,it.duty,it.freq,it.check,it.records||"",r.why,r.unk?"要確認":"","","","",""])});
 var ws=XLSX.utils.aoa_to_sheet(aoa);ws["!cols"]=[{wch:4},{wch:12},{wch:9},{wch:28},{wch:24},{wch:44},{wch:14},{wch:40},{wch:28},{wch:30},{wch:7},{wch:11},{wch:10},{wch:12},{wch:20}];
 var wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,"法令チェックシート");XLSX.writeFile(wb,"法令チェックシート_"+new Date().toISOString().slice(0,10)+".xlsx")};
})();
JS
       . '</script>';
}
