<?php
/* ═══════════════════════════════════════════════
   sfpc_landing.php — 접수 기간의 첫 화면 (화면 전체를 쓰는 SFPC 안내)

   index.php 가 sfpc_landing_gate() 로 이 파일을 끼워 넣는다 (접수 전 · 접수 중에,
   사이트 주소로 들어온 모든 방문자에게). 주소로 직접 열면 언제든 볼 수 있다 — 운영진 미리 보기용.

   화면 구성
     · 점으로 찍은 제목 "2026 SFPC 충북" (전광판)
     · 큰 "접수하기" 버튼 — 그 자체가 게이지다. 아래를 지나가는 픽셀 캐릭터들이
       전기를 쏘면 왼쪽부터 천천히 차오르고, 가득 차면 한 번 번쩍인 뒤 비워진다.
       장식일 뿐이고, 버튼은 언제 눌러도 접수 안내로 간다.
     · 오른쪽 위 "KAIT-CLASS 이동하기" — 기존 첫 화면으로
     · 마감까지 남은 날 · 현재 접수 팀 수

   색은 충청북도 심벌의 자색 · 청록에서 가져왔다 (심벌 자체는 쓰지 않는다).
   글꼴 Galmuri11 (SIL OFL 1.1) — fonts/Galmuri-LICENSE.txt

   움직임은 캐릭터와 버튼에만 둔다. 버튼 · 글자는 흔들지 않는다 (멀미).
   기기에서 '동작 줄이기'를 켰으면 정지 화면 한 장만 그린다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/layout.php';

$state = sfpc_apply_state();
$title = sfpc_setting('sfpc_title');
$host  = sfpc_setting('sfpc_host_region');

/* 제목에서 개최 지역만 강조색으로 찍는다: "2026 SFPC" + "충북" */
$titleMain = $title; $titleKey = '';
if ($host !== '' && str_ends_with($title, $host)) {
  $titleMain = rtrim(substr($title, 0, -strlen($host)));
  $titleKey  = $host;
}

/* 남은 날 — 날짜만 비교한다 (오늘 마감이면 D-DAY) */
$target = sfpc_ts(sfpc_setting($state === 'before' ? 'sfpc_apply_from' : 'sfpc_apply_to'));
$days   = $target ? (int)round((strtotime(date('Y-m-d', $target)) - strtotime(date('Y-m-d'))) / 86400) : 0;
$dday   = $state === 'closed' ? '' : ($days <= 0 ? 'D-DAY' : 'D-' . $days);
$ddayLabel = $state === 'before' ? '접수 시작까지' : '접수 마감까지';

$teamCnt = (int)col("SELECT COUNT(*) FROM sfpc_teams WHERE contest = ? AND status = 'submitted'", [SFPC_CONTEST]);
$fromTs = sfpc_ts(sfpc_setting('sfpc_apply_from')); $toTs = sfpc_ts(sfpc_setting('sfpc_apply_to'));
$period = ($fromTs ? date('Y.m.d', $fromTs) : '') . ' — ' . ($toTs ? date('Y.m.d', $toTs) : '');
$site = site_name();
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($site) ?></title>
<?php $fav = site_image_url('favicon'); if ($fav !== ''): ?><link rel="icon" href="<?= $fav ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<link rel="preload" href="<?= asset('fonts/Galmuri11-Bold.woff2') ?>" as="font" type="font/woff2" crossorigin>
<style>
@font-face{
  font-family:'Galmuri11'; font-style:normal; font-weight:700; font-display:swap;
  src:url('<?= asset('fonts/Galmuri11-Bold.woff2') ?>') format('woff2');
}
.lp{
  --bg:#140A24; --violet:#7F77DD; --violet-soft:#CECBF6; --teal:#5DCAA5; --teal-soft:#9FE1CB; --teal-ink:#04342C;
  position:relative; min-height:100vh; min-height:100svh; overflow:hidden;
  background-color:var(--bg); color:#fff;
  /* 격자는 그림이 아니라 배경으로 깐다 — 움직이지 않는다 */
  background-image:
    linear-gradient(rgba(127,119,221,.13) 1px, transparent 1px),
    linear-gradient(90deg, rgba(93,202,165,.09) 1px, transparent 1px);
  background-size:48px 48px;
  font-family:'Galmuri11', var(--mono); font-weight:700; letter-spacing:0;
  display:flex; flex-direction:column;
}
.lp canvas{position:absolute; inset:0; width:100%; height:100%; pointer-events:none}
.lp a{color:inherit}
.lp a:hover{text-decoration:none}

.lp-top{position:relative; display:flex; align-items:center; gap:16px; padding:22px 40px 0}
.lp-tag{font-size:12px; color:var(--violet)}
.lp-top .grow{flex:1}
.lp-kc{position:relative; font-size:12px; padding:10px 16px; color:var(--violet-soft);
       border:1px solid var(--violet); background:var(--bg)}
.lp-kc:hover,.lp-kc:focus-visible{color:#FAC775; border-color:#FAC775}

.lp-main{position:relative; flex:1; display:flex; align-items:center; gap:40px; padding:24px 40px 190px}
.lp-left{flex:1; min-width:0}
.lp-cmd{margin:0 0 22px; font-size:12px; color:var(--teal)}
/* 제목 — 글자는 화면 읽기 프로그램과 스크립트가 꺼진 경우를 위해 남기고, 점은 캔버스가 그 자리에 찍는다 */
.lp-title{margin:0; font-size:48px; line-height:1.2; font-weight:700; color:#EEEDFE}
.lp-title b{color:var(--teal); font-weight:700}
.lp-title.dots{color:transparent; user-select:none}
.lp-title.dots b{color:transparent}
.lp-date{margin:22px 0 34px; font-size:12px; color:var(--violet-soft)}
.lp-btns{display:flex; align-items:center; gap:22px; flex-wrap:wrap}

/* 접수하기 — 버튼이 곧 게이지. --p 까지가 찬 부분이다.
   글자는 한 번만 그리고 색만 같은 경계에서 바꾼다 (두 겹으로 겹치면 위치가 어긋난다). */
.lp-go{--p:0%; display:block; border:2px solid var(--teal);
       background:linear-gradient(90deg, var(--teal) 0, var(--teal) var(--p), #0B2A25 var(--p), #0B2A25 100%) padding-box}
.lp-go span{display:block; padding:22px 46px; font-size:24px; line-height:1; white-space:nowrap; color:transparent;
            background:linear-gradient(90deg, var(--teal-ink) 0, var(--teal-ink) var(--p), var(--teal-soft) var(--p), var(--teal-soft) 100%);
            -webkit-background-clip:text; background-clip:text}
.lp-go:hover,.lp-go:focus-visible{border-color:#fff}
.lp-go.full{background:#fff}
.lp-go.full span{background:var(--teal-ink); -webkit-background-clip:text; background-clip:text}
/* 좁은 화면에서 캐릭터가 걸어다니는 자리 — 버튼 바로 아래. 넓은 화면에서는 화면 맨 아래가 바닥이다. */
.lp-stage{display:none}
.lp-edit{font-size:12px; padding:12px 16px; color:var(--violet-soft); border:1px solid var(--violet)}
.lp-edit:hover,.lp-edit:focus-visible{color:#fff; border-color:#fff}

.lp-side{position:relative; text-align:right; white-space:nowrap}
.lp-side .n{font-size:72px; line-height:1; color:var(--teal)}
.lp-side .l{margin-top:10px; font-size:12px; color:#AFA9EC}
.lp-side .cnt{margin-top:38px; font-size:12px; color:#AFA9EC}
.lp-side .cnt b{display:block; margin-bottom:8px; font-size:36px; line-height:1; color:#fff; font-weight:700}
.lp-side .cnt b i{font-style:normal; font-size:12px; color:#AFA9EC; margin-left:6px}

@media(max-width:860px){
  .lp-top{padding:16px 20px 0}
  .lp-tag{display:none}
  .lp-main{flex-direction:column; align-items:flex-start; justify-content:center; gap:34px; padding:20px 20px 48px}
  .lp-btns{flex-direction:column; align-items:flex-start; gap:0}
  .lp-stage{display:block; width:100%; height:104px}
  .lp-edit{margin-top:22px}
  .lp-left{flex:none; width:100%}
  .lp-title{font-size:36px}
  .lp-go span{padding:20px 34px}
  .lp-side{text-align:left; display:flex; align-items:flex-end; gap:30px}
  .lp-side .n{font-size:48px}
  .lp-side .cnt{margin-top:0}
  .lp-side .cnt b{font-size:24px}
}
</style>
</head>
<body>
<main class="lp" id="lp" data-main="<?= h($titleMain) ?>" data-key="<?= h($titleKey) ?>">
  <canvas aria-hidden="true"></canvas>

  <div class="lp-top">
    <span class="lp-tag">SFPC://<?= h(SFPC_CONTEST) ?></span>
    <span class="grow"></span>
    <a class="lp-kc" id="lpKc" href="index.php?home=1"><?= h($site) ?> 이동하기 →</a>
  </div>

  <div class="lp-main">
    <div class="lp-left">
      <p class="lp-cmd">&gt; school friends programming challenge_</p>
      <h1 class="lp-title" id="lpTitle"><?= h($titleMain) ?><?= $titleKey !== '' ? ' <b>' . h($titleKey) . '</b>' : '' ?></h1>
      <p class="lp-date">참가 접수 <?= h($period) ?></p>
      <div class="lp-btns">
        <a class="lp-go" id="lpGo" href="sfpc.php"><span>&gt; <?= $state === 'open' ? '접수하기' : '접수 안내' ?></span></a>
        <div class="lp-stage" id="lpStage" aria-hidden="true"></div>
        <?php if ($state !== 'before'): ?>
          <a class="lp-edit" href="sfpc_teams.php">접수 확인 · 수정</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="lp-side">
      <?php if ($dday !== ''): ?>
        <div><div class="n"><?= h($dday) ?></div><div class="l"><?= h($ddayLabel) ?></div></div>
      <?php endif; ?>
      <?php if ($state !== 'before'): ?>
        <div class="cnt"><b><?= number_format($teamCnt) ?><i>팀</i></b>현재 접수</div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script>
(function () {
  'use strict';
  var lp = document.getElementById('lp'), cv = lp.querySelector('canvas'), c = cv.getContext('2d');
  var go = document.getElementById('lpGo'), kc = document.getElementById('lpKc'), titleEl = document.getElementById('lpTitle');
  var stage = document.getElementById('lpStage');
  var calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FONT = '700 12px Galmuri11';
  var W = 0, H = 0, dpr = 1;

  /* ── 제목을 점으로 ──────────────────────────────
     Galmuri11 은 12px 격자에 맞춰 그린 글꼴이라, 12px 로 써서 한 픽셀씩 읽으면 그대로 점 그림이 된다.
     글꼴을 못 불러오면 점을 찍지 않고 글자 제목을 그대로 둔다. */
  var title = null;   /* {cols, rows, pts:[[x,y,key]], img, imgLit, pitch} */
  function sampleTitle() {
    var main = lp.dataset.main, key = lp.dataset.key;
    var o = document.createElement('canvas'); o.width = 400; o.height = 20;
    var x = o.getContext('2d', { willReadFrequently: true });
    x.font = FONT; x.textBaseline = 'top'; x.fillStyle = '#fff';
    x.fillText(main, 0, 2);
    var keyX = key ? Math.round(x.measureText(main + ' ').width) : 1e9;
    if (key) x.fillText(key, keyX, 2);
    var d = x.getImageData(0, 0, 400, 20).data, pts = [], maxX = 0, minY = 99, maxY = 0;
    for (var yy = 0; yy < 20; yy++) for (var xx = 0; xx < 400; xx++) {
      if (d[(yy * 400 + xx) * 4 + 3] > 120) {
        pts.push([xx, yy, xx >= keyX - 1]);
        if (xx > maxX) maxX = xx; if (yy < minY) minY = yy; if (yy > maxY) maxY = yy;
      }
    }
    if (pts.length < 40) return null;
    pts.forEach(function (p) { p[1] -= minY; });
    return { cols: maxX + 1, rows: maxY - minY + 1, pts: pts };
  }
  function renderTitle() {
    if (!title) return;
    var avail = titleEl.parentNode.clientWidth;
    var pitch = Math.max(3, Math.min(11, Math.floor(avail / title.cols * 10) / 10));
    title.pitch = pitch;
    titleEl.style.width = Math.ceil(title.cols * pitch) + 'px';
    titleEl.style.height = Math.ceil(title.rows * pitch) + 'px';
    titleEl.style.overflow = 'hidden';
    titleEl.classList.add('dots');
    function paint(lit) {
      var o = document.createElement('canvas');
      o.width = Math.ceil(title.cols * pitch * dpr); o.height = Math.ceil(title.rows * pitch * dpr);
      var x = o.getContext('2d'); x.scale(dpr, dpr);
      var r = pitch * 0.4;
      title.pts.forEach(function (p) {
        x.fillStyle = lit ? '#fff' : (p[2] ? '#5DCAA5' : '#EEEDFE');
        x.beginPath(); x.arc(p[0] * pitch + pitch / 2, p[1] * pitch + pitch / 2, r, 0, 6.2832); x.fill();
      });
      return o;
    }
    title.img = paint(false); title.imgLit = paint(true);
  }

  /* ── 픽셀 캐릭터 ────────────────────────────────
     글자 하나가 점 하나. '.' 은 빈칸이고 나머지는 팔레트의 색 이름이다.
     걷는 모습은 다리 두 장을 번갈아 붙인다. */
  var ROBOT = ['....a....', '....a....', '.aaaaaaa.', '.akkkkka.', '.akekeka.', '.akkkkka.', '.aaaaaaa.', '..aaaaa..'];
  var ROBOT2 = ['.a.....a.', '.a.....a.', '.aaaaaaa.', '.akkkkka.', '.akekeka.', '.akkekka.', '.aaaaaaa.', '..aaaaa..'];
  var RLEG = [['..a...a..', '.aa...aa.'], ['...a.a...', '..aa.aa..']];
  var BLOB = ['..cccc..', '.cccccc.', 'cwkccwkc', 'cccccccc', 'cccccccc', '.cccccc.'];
  var BLEG = [['.c....c.'], ['..c..c..']];
  var KINDS = [
    { rows: ROBOT,  legs: RLEG, pal: { a: '#AFA9EC', k: '#26215C', e: '#5DCAA5' } },
    { rows: BLOB,   legs: BLEG, sym: '{}', pal: { c: '#F0997B', w: '#fff', k: '#4A1B0C' } },
    { rows: ROBOT2, legs: RLEG, pal: { a: '#9FE1CB', k: '#04342C', e: '#FAC775' } },
    { rows: BLOB,   legs: BLEG, sym: ';',  pal: { c: '#FAC775', w: '#fff', k: '#412402' } },
    { rows: ROBOT,  legs: RLEG, pal: { a: '#CECBF6', k: '#26215C', e: '#F0997B' } },
    { rows: BLOB,   legs: BLEG, sym: '01', pal: { c: '#ED93B1', w: '#fff', k: '#4B1528' } },
    { rows: ROBOT2, legs: RLEG, pal: { a: '#F4C0D1', k: '#4B1528', e: '#fff' } },
    { rows: BLOB,   legs: BLEG, sym: '<>', pal: { c: '#85B7EB', w: '#fff', k: '#042C53' } },
    { rows: ROBOT,  legs: RLEG, pal: { a: '#FAC775', k: '#412402', e: '#5DCAA5' } },
    { rows: BLOB,   legs: BLEG, sym: '#',  pal: { c: '#9FE1CB', w: '#fff', k: '#04342C' } }
  ];
  var S = 5, chars = [];
  function sprite(rows, x, y, pal) {
    for (var r = 0; r < rows.length; r++) for (var q = 0; q < rows[r].length; q++) {
      var ch = rows[r][q]; if (ch === '.') continue;
      c.fillStyle = pal[ch]; c.fillRect(Math.round(x + q * S), Math.round(y + r * S), S, S);
    }
  }
  function makeChars() {
    S = W < 700 ? 3 : (W < 1200 ? 4 : 5);
    var n = Math.max(3, Math.min(KINDS.length, Math.floor(W / 150)));
    chars = [];
    for (var i = 0; i < n; i++) {
      var k = KINDS[i];
      chars.push({
        k: k, w: k.rows[0].length * S, h: (k.rows.length + k.legs[0].length) * S,
        x: (i + 0.5) / n * (W - 80) + 20, dir: i % 2 ? 1 : -1,
        sp: 22 + Math.random() * 26,      /* 초당 px */
        pause: 0
      });
    }
  }

  /* ── 그리기 ──────────────────────────────────── */
  var mx = -9999, my = -9999, kcHot = false;
  var charge = 0, flash = 0, run = 0, step = 0;
  /* 초당 %. 쏘는 캐릭터 한 마리마다 FILL 씩 찬다 — 여럿이 쏘면 그만큼 빨라진다 (FILL_MAX 까지).
     아무도 없으면 DRAIN 씩 빠진다. 한 마리면 가득 차는 데 40초쯤, 서너 마리가 모이면 10여 초. */
  var FILL = 2.5, FILL_MAX = 10, DRAIN = 1.0, FLASH = 1.6;

  function rect(elm) {
    var r = elm.getBoundingClientRect(), e = lp.getBoundingClientRect();
    return { x: r.left - e.left, y: r.top - e.top, w: r.width, h: r.height };
  }
  function bolt(x1, y1, x2, y2, jit) {
    var n = Math.max(5, Math.round(Math.hypot(x2 - x1, y2 - y1) / 28));
    c.beginPath(); c.moveTo(x1, y1);
    for (var i = 1; i < n; i++) {
      var k = i / n;
      c.lineTo(x1 + (x2 - x1) * k + (Math.random() - .5) * jit, y1 + (y2 - y1) * k + (Math.random() - .5) * jit);
    }
    c.lineTo(x2, y2); c.stroke();
  }
  /* 네모 테두리를 따라 s 만큼 간 자리 */
  function edge(b, s) {
    var per = 2 * (b.w + b.h); s = ((s % per) + per) % per;
    if (s < b.w) return [b.x + s, b.y]; s -= b.w;
    if (s < b.h) return [b.x + b.w, b.y + s]; s -= b.h;
    if (s < b.w) return [b.x + b.w - s, b.y + b.h]; s -= b.w;
    return [b.x, b.y + b.h - s];
  }

  function frame(dt, still) {
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, W, H);

    /* 제목 */
    if (title && title.img) {
      var tb = rect(titleEl);
      c.drawImage(flash > 0 ? title.imgLit : title.img, tb.x, tb.y, title.cols * title.pitch, title.rows * title.pitch);
    }

    var b = rect(go), zaps = 0;
    /* 바닥 — 넓은 화면은 맨 아래, 좁은 화면은 버튼 아래의 자리(.lp-stage) */
    var narrow = stage.offsetParent !== null, sb = narrow ? rect(stage) : null;
    var ground = narrow ? sb.y + sb.h - 8 : H - 64;
    /* 바닥 — 점선 */
    c.fillStyle = 'rgba(127,119,221,.5)';
    for (var gx = 20; gx < W - 20; gx += 10) c.fillRect(gx, ground + 3, 5, 2);

    step += dt * 7;
    var fr = Math.floor(step) % 2;
    chars.forEach(function (o) {
      var mid = o.x + o.w / 2;
      var scared = !still && Math.abs(mid - mx) < 70 && Math.abs(ground - o.h / 2 - my) < 90;
      if (!still) {
        if (scared) { o.dir = mid < mx ? -1 : 1; o.x += o.dir * 170 * dt; o.pause = 0; }
        else if (o.pause > 0) o.pause -= dt;
        else { o.x += o.dir * o.sp * dt; if (Math.random() < dt * 0.25) o.pause = 1.2 + Math.random() * 1.5; }
        if (o.x < 14) { o.x = 14; o.dir = 1; }
        if (o.x > W - o.w - 14) { o.x = W - o.w - 14; o.dir = -1; }
      }
      mid = o.x + o.w / 2;
      var near = !still && !scared && flash <= 0 && mid > b.x - 20 && mid < b.x + b.w + 20;
      var top = ground - o.h, f = (scared || o.pause <= 0) && !still ? fr : 0;

      if (near) {
        zaps++;
        var tx = Math.max(b.x + 8, Math.min(b.x + b.w - 8, mid)), ty = b.y + b.h + 4;
        c.lineWidth = 2.5; c.strokeStyle = '#5DCAA5'; bolt(mid, top, tx, ty, 22);
        c.lineWidth = 1;   c.strokeStyle = '#fff';    bolt(mid, top, tx, ty, 12);
        c.fillStyle = '#fff'; c.fillRect(tx - 4, ty - 4, 8, 8);
      }
      sprite(o.k.rows.concat(o.k.legs[f]), o.x, top, o.k.pal);
      if (o.k.sym) {
        c.fillStyle = o.k.pal.k; c.font = '700 ' + (S * 3) + 'px Galmuri11, monospace';
        c.textAlign = 'center'; c.textBaseline = 'middle';
        c.fillText(o.k.sym, mid, top + S * 4.6);
        c.textAlign = 'left'; c.textBaseline = 'alphabetic';
      }
      if (scared) { c.fillStyle = '#fff'; c.font = FONT; c.fillText('!', mid - 2, top - 8); }
    });

    /* 게이지 */
    if (flash > 0) {
      flash -= dt;
      var k = 1 - Math.max(0, flash) / FLASH, pad = k * 70;
      c.lineWidth = 3; c.strokeStyle = 'rgba(255,255,255,' + (1 - k) + ')';
      c.strokeRect(b.x - pad, b.y - pad, b.w + pad * 2, b.h + pad * 2);
      c.lineWidth = 2; c.strokeStyle = 'rgba(93,202,165,' + (1 - k) + ')';
      c.strokeRect(b.x - pad * 1.8, b.y - pad * 1.8, b.w + pad * 3.6, b.h + pad * 3.6);
      if (flash <= 0) { charge = 0; go.classList.remove('full'); }
    } else if (!still) {
      if (zaps) charge += Math.min(FILL_MAX, zaps * FILL) * dt;
      else charge = Math.max(0, charge - DRAIN * dt);
      if (charge >= 100) { charge = 100; flash = FLASH; go.classList.add('full'); }
    }
    go.style.setProperty('--p', charge.toFixed(1) + '%');

    /* 테두리를 도는 빛 */
    run += (zaps ? 260 + zaps * 80 : 70) * dt;
    var n = zaps ? 16 : 7, ring = { x: b.x - 6, y: b.y - 6, w: b.w + 12, h: b.h + 12 };
    for (var j = 0; j < n; j++) {
      var p = edge(ring, run - j * 6), a = 1 - j / n;
      c.fillStyle = zaps ? 'rgba(255,255,255,' + a + ')' : 'rgba(93,202,165,' + a * 0.85 + ')';
      c.fillRect(p[0] - 2, p[1] - 2, 4, 4);
    }

    /* KAIT-CLASS 버튼 — 마우스를 올리면 번개 */
    if (kcHot && !still) {
      var kb = rect(kc); c.lineWidth = 1.5;
      for (var m = 0; m < 4; m++) {
        c.strokeStyle = m % 2 ? '#FAC775' : '#fff';
        var side = Math.floor(Math.random() * 3), qx = kb.x + Math.random() * kb.w, qy = kb.y + Math.random() * kb.h;
        if (side === 0)      bolt(qx, kb.y + kb.h, qx + (Math.random() - .5) * 60, kb.y + kb.h + 22 + Math.random() * 34, 16);
        else if (side === 1) bolt(kb.x, qy, kb.x - 24 - Math.random() * 40, qy + (Math.random() - .5) * 46, 16);
        else                 bolt(qx, kb.y, qx + (Math.random() - .5) * 40, kb.y - 6 - Math.random() * 10, 8);
      }
    }
  }

  /* ── 돌리기 ────────────────────────────────────
     초당 30장까지만 그린다 (오래된 학교 컴퓨터). 다른 탭을 보는 동안에는 멈춘다. */
  var raf = 0, last = 0;
  function loop(ts) {
    raf = requestAnimationFrame(loop);
    if (ts - last < 32) return;
    var dt = Math.min(0.1, (ts - last) / 1000); last = ts;
    frame(dt, false);
  }
  function start() { if (!calm && !raf && !document.hidden) { last = performance.now(); raf = requestAnimationFrame(loop); } }
  function stop() { if (raf) { cancelAnimationFrame(raf); raf = 0; } }
  function fit() {
    dpr = Math.min(2, window.devicePixelRatio || 1);
    W = lp.clientWidth; H = lp.clientHeight;
    cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
    renderTitle();
    makeChars();
    if (calm) frame(0, true);
  }

  lp.addEventListener('pointermove', function (e) { var r = lp.getBoundingClientRect(); mx = e.clientX - r.left; my = e.clientY - r.top; });
  lp.addEventListener('pointerleave', function () { mx = my = -9999; });
  ['pointerenter', 'focus'].forEach(function (ev) { kc.addEventListener(ev, function () { kcHot = true; }); });
  ['pointerleave', 'blur'].forEach(function (ev) { kc.addEventListener(ev, function () { kcHot = false; }); });
  document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
  var rt = 0;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(fit, 120); });

  fit(); start();
  /* 글꼴이 오면 제목을 점으로 바꾼다 */
  if (document.fonts && document.fonts.load) {
    document.fonts.load(FONT, lp.dataset.main + lp.dataset.key).then(function (f) {
      if (!f || !f.length) return;
      title = sampleTitle();
      fit();
    }).catch(function () {});
  }
})();
</script>
<?php page_foot(); ?>
