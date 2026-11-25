<?php
/* ═══════════════════════════════════════════════
   sfpc_study.php — 공부하기. 칸을 따라 걸어가며 기초 문제를 푸는 지도.

   · 문제와 채점은 KAIT-CLASS 의 수업 묶음 (sfpc_lib.php 의 "공부하기"). 칸을 열면 기존 채점 화면이 나온다.
   · 문제 sfpc_boss_every 개마다 보스 칸이 있다. 한 월드 = 그 문제들 + 보스.
   · 보스는 관문이다. 보스를 지나야(싸우거나 넘어가야) 다음 월드로 간다. 그 안에서는 자유롭게 다닌다.
   · 방향키는 화면에 보이는 방향 그대로 움직인다.
   · 지도 위의 공지 상자 — 새 공지가 있으면 깜빡인다. 누르면 그 자리에서 읽는다.
   · ?view=list — 같은 문제를 단순한 목록으로.
   팀 계정만 들어온다 (운영진은 확인용으로 들어올 수 있다).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
$team = sfpc_my_team($u);
$open = sfpc_setting('sfpc_study_open') === '1' || (is_admin($u) && sfpc_preview() !== 'guest');

/* 방금 수료했으면 축하 화면으로 — 다만 싸울 보스가 남아 있으면 보스가 먼저다 (보스를 끝내면 그 화면에서 축하로 간다) */
if ($open && $team && sfpc_study_check($team) && (int)($_SESSION['sfpc_grad'] ?? 0) === (int)$team['id']) {
  $ev = sfpc_boss_every(); $ps = sfpc_boss_passed((int)$team['id']);
  $ns = count(array_intersect(array_map('intval', array_column(sfpc_study_problems(), 'id')), sfpc_study_solved((int)$u['id'])));
  if (!($ps < sfpc_boss_count() && $ns >= ($ps + 1) * $ev)) { header('Location: sfpc_grad.php'); exit; }
}

page_head(['title' => '공부하기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
if (!sfpc_gate('sfpc_study_open', '공부하기', $u)) { page_foot(); exit; }

if (!$team && !is_admin($u)) { ?>
<div class="wrap narrow">
  <div class="phead"><h1>공부하기</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="sfpc-soon" style="margin:20px 0">
    <h1>팀 계정으로 들어와 주세요</h1>
    <?php if (!$u): ?>
      <p>공부하기는 대회에 접수한 팀이 팀 계정으로 로그인해서 풉니다. 팀 계정은 지도 선생님께 받습니다.</p>
      <p style="margin-top:20px"><a class="btn primary" href="login.php?next=<?= rawurlencode('sfpc_study.php') ?>">팀 계정으로 로그인</a></p>
    <?php else: ?>
      <p>지금은 지도교사 계정으로 들어와 계십니다. 공부하기는 <b>팀 계정</b>으로 로그인해야 풀 수 있고, 수료도 팀 계정으로 푼 것만 인정됩니다.</p>
      <p style="margin-top:20px"><a class="btn primary" href="sfpc_teams.php">내 팀에서 팀 계정 확인하기</a></p>
    <?php endif; ?>
  </div>
</div>
<?php page_foot(); exit; }

$set    = sfpc_study_set();
$probs  = sfpc_study_problems();
$skip   = sfpc_study_skip();
if ($team) $team = sfpc_team((int)$team['id']);
$done   = $team ? $team['study_done_at'] !== '' : false;
$solved = sfpc_study_solved((int)$u['id']);
$need   = array_diff(array_map('intval', array_column($probs, 'id')), $skip);
$got    = count(array_intersect($need, $solved));
$nSolved = count(array_intersect(array_map('intval', array_column($probs, 'id')), $solved));
$state  = set_state($set);
$pct    = $need ? (int)floor($got * 100 / count($need)) : 0;
$every  = sfpc_boss_every();
$mash   = $team ? sfpc_mash_rows((int)$team['id']) : [];
$passed = $team ? sfpc_boss_passed((int)$team['id']) : PHP_INT_MAX;   /* 운영진 미리 보기는 모두 열려 있다 */
$char   = $team ? sfpc_char_of($team) : sfpc_char_default((int)$u['id']);

if (($_GET['view'] ?? '') === 'list'): ?>
<div class="wrap narrow sfpc-st-listpage">
  <div class="sfpc-st-prog<?= $done ? ' done' : '' ?>">
    <div class="top"><b><?= h($team ? $team['team_name'] : '운영진 미리 보기') ?></b>
      <?php if ($done): ?><span class="badge ok">수료</span><?php else: ?><span class="small">수료까지 <?= count($need) - $got ?>문제</span><?php endif; ?>
      <span class="n"><?= $got ?> / <?= count($need) ?></span></div>
    <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
  </div>
  <?php if (!$probs): ?>
    <div class="empty">문제를 준비하고 있습니다. 곧 올라옵니다.</div>
  <?php else: ?>
    <ol class="sfpc-st-list">
      <?php foreach ($probs as $i => $p): $ok = in_array((int)$p['id'], $solved, true); $off = in_array((int)$p['id'], $skip, true);
        $lock = min(intdiv($i, $every), sfpc_boss_count()) > $passed; ?>
        <li class="<?= $ok ? 'ok' : '' ?><?= $lock ? ' lock' : '' ?>">
          <a href="<?= $lock ? 'sfpc_study.php' : 'problem.php?no=' . (int)$p['prob_no'] . '&amp;set=' . (int)$set['id'] ?>">
            <span class="no"><?= $i + 1 ?></span>
            <span class="t"><?= h($p['title']) ?><?= $off ? ' <em>수료 조건에서 빠진 문제</em>' : '' ?></span>
            <span class="s"><?= $ok ? '맞았습니다' : ($lock ? '앞의 보스를 지나야 열립니다' : '풀기 →') ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
<?php page_foot(); exit; endif;

/* 지도에 놓을 칸들 — 문제와 보스를 길 순서대로 */
$nodes = []; $bossNo = 0; $nBoss = sfpc_boss_count();
foreach ($probs as $i => $p) {
  $nodes[] = ['t' => 'p', 'n' => $i + 1, 'title' => $p['title'], 'ok' => in_array((int)$p['id'], $solved, true),
              'lock' => min(intdiv($i, $every), $nBoss) > $passed,
              'href' => 'problem.php?no=' . (int)$p['prob_no'] . '&set=' . (int)$set['id']];
  if (($i + 1) % $every === 0) {
    $bossNo++; [$kind, $name] = sfpc_boss_kind($bossNo);
    $r = $mash[$bossNo] ?? null; $st = $team ? sfpc_boss_state($r) : '';
    $nodes[] = ['t' => 'b', 'n' => $bossNo, 'kind' => $kind, 'name' => $name,
                'open' => !$team || ($nSolved >= $bossNo * $every && $passed >= $bossNo - 1),   /* 운영진 미리 보기는 모두 열려 있다 */
                'needMore' => max(0, $bossNo * $every - $nSolved), 'st' => $st,
                'cnt' => ($st === 'done' && $r['removed_at'] === '') ? (int)$r['cnt'] : 0, 'href' => 'sfpc_boss.php?b=' . $bossNo];
  }
}
?>
<div class="sfpc-world">
  <?php if ($state === 'before'): ?><div class="sfpc-world-note">아직 시작 전입니다. <?= h(period_text($set)) ?></div>
  <?php elseif ($state === 'after'): ?><div class="sfpc-world-note">공부하기 기간이 끝났습니다. 문제는 볼 수 있지만 제출할 수 없습니다.</div><?php endif; ?>
  <?php if (!$team): ?><div class="sfpc-world-note">운영진 미리 보기입니다. 수료 · 보스와 상관없이 모든 칸에 갈 수 있습니다. 수료와 연타 기록은 팀 계정에만 남습니다.</div><?php endif; ?>

  <div class="sfpc-world-hud">
    <div class="who"><b><?= h($team ? $team['team_name'] : '미리 보기') ?></b>
      <?php if ($done): ?><a class="grad" href="sfpc_grad.php">★ 수료 완료 — 축하 화면 다시 보기</a>
      <?php else: ?><span>수료까지 <?= count($need) - $got ?>문제</span><?php endif; ?></div>
    <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($need) ?>" aria-valuenow="<?= $got ?>"><i style="width:<?= $pct ?>%"></i></div>
    <div class="n"><?= $got ?> / <?= count($need) ?></div>
  </div>

  <?php if (!$probs): ?>
    <div class="sfpc-world-empty">문제를 준비하고 있습니다. 곧 올라옵니다.</div>
  <?php else: ?>
  <?php sfpc_notice_box($u, 'wm'); ?>
  <div class="sfpc-world-map">
    <canvas id="wm" tabindex="0" aria-label="공부하기 지도. 방향키로 칸을 옮기고 Enter 로 엽니다. 목록으로 보려면 위쪽의 목록을 누르세요."></canvas>
  </div>
  <div class="sfpc-world-info" id="wmInfo" aria-live="polite">
    <div class="txt"><b id="wmTitle"></b><span id="wmSub"></span></div>
    <a class="go" id="wmGo" href="#"></a>
  </div>
  <p class="sfpc-world-help">방향키 ← ↑ ↓ → 로 이동 · Enter 로 열기 · 칸을 눌러도 됩니다</p>
  <?php endif; ?>
</div>
<?php if ($probs): ?>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script src="<?= asset('sfpc_boss.js') ?>"></script>
<script>
(function () {
  'use strict';
  var NODES = <?= json_encode($nodes, JSON_UNESCAPED_UNICODE) ?>, EVERY = <?= (int)$every ?>, ME = <?= json_encode($char) ?>, KEY = 'sfpc_study_pos_<?= (int)$set['id'] ?>_<?= (int)$u['id'] ?>';
  var cv = document.getElementById('wm'), g = cv.getContext('2d');
  var W = 1100, BAND = 200, TOP = 86, PER = EVERY + 1, bands = Math.ceil(NODES.length / PER), H = TOP + bands * BAND + 30;
  cv.width = W; cv.height = H;
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* 칸의 자리 — 줄마다 방향을 바꿔 가며(지그재그) 놓는다 */
  var gap = Math.min(150, (W - 220) / Math.max(1, PER - 1));
  NODES.forEach(function (n, i) {
    var b = Math.floor(i / PER), c = i % PER, inBand = Math.min(PER, NODES.length - b * PER);
    var x0 = (W - gap * (PER - 1)) / 2;
    n.x = b % 2 === 0 ? x0 + c * gap : x0 + (PER - 1 - c) * gap; n.y = TOP + b * BAND + 96; n.band = b;
  });

  /* 관문 — 아직 지나지 않은 첫 보스까지만 갈 수 있다 */
  var MAX = NODES.length - 1, CALL = -1, PREVIEW = <?= $team ? 'false' : 'true' ?>;
  if (!PREVIEW) for (var q = 0; q < NODES.length; q++) if (NODES[q].t === 'b' && NODES[q].st === '') { MAX = q; if (NODES[q].open) CALL = q; break; }
  var cur = -1; try { cur = parseInt(sessionStorage.getItem(KEY) || '-1', 10); } catch (e) {}
  if (!(cur >= 0 && cur <= MAX)) { cur = 0; for (var i = 0; i <= MAX; i++) if (NODES[i].t === 'p' && !NODES[i].ok) { cur = i; break; } }
  if (CALL >= 0) cur = CALL;                                             /* 보스가 기다리고 있으면 그 앞에 세운다 */
  var blocked = 0, pos = { x: NODES[cur].x, y: NODES[cur].y }, queue = [], dir = 1, tk = 0, puffs = [], moving = false;

  function dirOf(a, b) { var dx = b.x - a.x, dy = b.y - a.y; return Math.abs(dx) >= Math.abs(dy) ? (dx > 0 ? 'ArrowRight' : 'ArrowLeft') : (dy > 0 ? 'ArrowDown' : 'ArrowUp'); }
  function neighbors(i) { var o = {}; if (i + 1 <= MAX) o[dirOf(NODES[i], NODES[i + 1])] = i + 1; if (i > 0) { var k = dirOf(NODES[i], NODES[i - 1]); if (!(k in o)) o[k] = i - 1; } return o; }
  function goTo(i) { if (i > MAX) { i = MAX; blocked = 90; } queue = []; if (i === cur) return; var s = cur, st = i > s ? 1 : -1; for (var k = s + st; st > 0 ? k <= i : k >= i; k += st) queue.push(k); }
  function setCur(i) { cur = i; try { sessionStorage.setItem(KEY, String(i)); } catch (e) {} info(); follow(); }

  var tEl = document.getElementById('wmTitle'), sEl = document.getElementById('wmSub'), goEl = document.getElementById('wmGo');
  function info() {
    var n = NODES[cur];
    if (n.t === 'p') { tEl.textContent = n.n + '번 · ' + n.title; sEl.textContent = n.ok ? '맞혔습니다' : '아직 풀지 않았습니다'; goEl.textContent = n.ok ? '다시 보기 →' : '문제 풀기 →'; goEl.href = n.href; goEl.className = 'go'; }
    else if (!n.open) { tEl.textContent = 'BOSS ' + n.n + ' · ???'; sEl.textContent = '이 월드의 문제를 ' + n.needMore + '개 더 맞히면 정체가 드러납니다. 보스를 지나야 다음 월드로 갑니다.'; goEl.textContent = '잠겨 있음'; goEl.removeAttribute('href'); goEl.className = 'go off'; }
    else if (n.st === 'done') { tEl.textContent = 'BOSS ' + n.n + ' · ' + n.name; sEl.textContent = '물리쳤습니다 · ' + n.cnt + '회'; goEl.textContent = '기록 보기 →'; goEl.href = n.href; goEl.className = 'go boss'; }
    else if (n.st === 'skip') { tEl.textContent = 'BOSS ' + n.n + ' · ' + n.name; sEl.textContent = '넘어간 보스입니다 (0점). 지금 도전하면 기록이 남습니다.'; goEl.textContent = '보스에게 도전 →'; goEl.href = n.href; goEl.className = 'go boss'; }
    else { tEl.textContent = '보스 등장! · ' + n.name; sEl.textContent = '스페이스 연타 한 판. 이 보스를 지나야 다음 월드로 갈 수 있습니다.'; goEl.textContent = '보스에게 도전 →'; goEl.href = n.href; goEl.className = 'go boss call'; }
  }
  function follow() {
    var r = cv.getBoundingClientRect(), y = r.top + (NODES[cur].y / H) * r.height;
    if (y < 150 || y > innerHeight - 190) window.scrollTo({ top: window.scrollY + y - innerHeight / 2, behavior: still ? 'auto' : 'smooth' });
  }

  function tile(n, i) {
    var x = n.x, y = n.y;
    if (n.t === 'p') {
      var s = 46;
      if (n.lock) g.globalAlpha = .35;
      g.fillStyle = n.ok ? '#0F6E56' : '#1D1238'; g.fillRect(x - s / 2 - 4, y - s / 2 + 4, s + 8, s);          /* 받침 */
      g.fillStyle = n.ok ? '#5DCAA5' : '#26215C'; g.fillRect(x - s / 2, y - s / 2, s, s);
      g.fillStyle = n.ok ? '#9FE1CB' : '#3C3489'; g.fillRect(x - s / 2, y - s / 2, s, 4);
      g.fillStyle = n.ok ? '#04342C' : '#AFA9EC'; g.font = '700 18px Galmuri11, monospace'; g.textAlign = 'center'; g.fillText(String(n.n), x, y + 7);
      if (n.ok) { g.fillStyle = '#FAC775'; g.fillRect(x + 12, y - 30, 6, 6); g.fillRect(x + 18, y - 36, 6, 6); g.fillRect(x + 6, y - 36, 6, 6); }  /* 깃발 같은 반짝임 */
      g.globalAlpha = 1;
    } else {
      var P = 4, bx = x - 8 * P, by = y - 10 * P - 6;
      g.fillStyle = n.open ? '#412402' : '#0C0618'; g.fillRect(x - 40, y + 20, 80, 8);
      g.fillStyle = n.open ? '#BA7517' : '#1D1238'; g.fillRect(x - 34, y + 14, 68, 8);
      SfpcBoss.draw(g, n.kind, bx, by + (still || !n.open ? 0 : Math.round(Math.sin(tk / 20 + i) * 2)), P, tk + i * 7, { sil: !n.open, down: n.st === 'done' });
      g.font = '700 12px Galmuri11, monospace'; g.textAlign = 'center'; g.fillStyle = n.open ? '#FAC775' : '#6E66A8';
      g.fillText(n.open ? (n.st === 'done' ? n.cnt + '회' : (n.st === 'skip' ? '미도전' : 'BOSS')) : '???', x, y + 46);
      if (n.open && n.st === '' && (still || Math.floor(tk / 24) % 2)) { g.font = '700 16px Galmuri11, monospace'; g.fillStyle = '#E24B4A'; g.fillText('보스 등장!', x, y - 62); }
      if (i === MAX && blocked > 0) { blocked--; g.font = '700 13px Galmuri11, monospace'; g.fillStyle = '#FAC775'; g.fillText('보스를 지나야 갈 수 있습니다', x, y + 66); }
    }
  }
  function frame() {
    tk++;
    if (queue.length) {
      var t = NODES[queue[0]], dx = t.x - pos.x, dy = t.y - pos.y, d = Math.sqrt(dx * dx + dy * dy), sp = still ? 9999 : Math.max(9, queue.length * 5);
      if (Math.abs(dx) > 1) dir = dx > 0 ? 1 : -1;
      if (d <= sp) { pos.x = t.x; pos.y = t.y; setCur(queue.shift()); } else { pos.x += dx / d * sp; pos.y += dy / d * sp; }
      moving = true;
      if (!still && tk % 3 === 0) { var p = SfpcChar.puff(ME, pos.x - 32, pos.y - 78, 4, dir); if (p) puffs.push(p); }
    } else moving = false;

    g.clearRect(0, 0, W, H);
    for (var b = 0; b < bands; b++) {                                   /* 월드 띠 */
      g.fillStyle = b % 2 ? 'rgba(127,119,221,.06)' : 'rgba(93,202,165,.05)'; g.fillRect(0, TOP + b * BAND, W, BAND - 14);
      var boss = null; NODES.forEach(function (n) { if (n.band === b && n.t === 'b') boss = n; });
      g.textAlign = 'left'; g.font = '700 16px Galmuri11, monospace'; g.fillStyle = '#7F77DD'; g.fillText('WORLD ' + (b + 1), 26, TOP + b * BAND + 30);
      g.font = '700 12px Galmuri11, monospace'; g.fillStyle = '#6E66A8';
      g.fillText(boss ? (boss.open ? boss.name : '보스: ???') : '', 26, TOP + b * BAND + 50);
    }
    g.lineWidth = 8; g.lineCap = 'round'; g.lineJoin = 'round';         /* 길 */
    for (var i = 0; i < NODES.length - 1; i++) {
      var a = NODES[i], c = NODES[i + 1], lit = (a.t === 'p' ? a.ok : a.open) && (c.t === 'p' ? c.ok : c.open);
      g.strokeStyle = lit ? '#1D9E75' : '#2A2150'; g.setLineDash(lit ? [] : [2, 16]);
      g.beginPath(); g.moveTo(a.x, a.y); if (a.band !== c.band) { g.lineTo(a.x, c.y); } g.lineTo(c.x, c.y); g.stroke();
    }
    g.setLineDash([]);
    NODES.forEach(tile);
    puffs = puffs.filter(function (p) { return SfpcChar.drawPuff(g, p, 4); });
    if (!moving) {                                                      /* 갈 수 있는 방향 화살표 */
      var nb = neighbors(cur), n0 = NODES[cur], bl = still ? 1 : (Math.floor(tk / 20) % 2 ? .9 : .45);
      g.fillStyle = 'rgba(255,255,255,' + bl + ')';
      [['ArrowRight', 44, 0], ['ArrowLeft', -44, 0], ['ArrowDown', 0, 52], ['ArrowUp', 0, -96]].forEach(function (a) {
        if (!(a[0] in nb)) return; var x = n0.x + a[1], y = n0.y + a[2];
        g.beginPath();
        if (a[0] === 'ArrowRight') { g.moveTo(x, y - 8); g.lineTo(x + 12, y); g.lineTo(x, y + 8); }
        if (a[0] === 'ArrowLeft')  { g.moveTo(x, y - 8); g.lineTo(x - 12, y); g.lineTo(x, y + 8); }
        if (a[0] === 'ArrowDown')  { g.moveTo(x - 8, y); g.lineTo(x, y + 12); g.lineTo(x + 8, y); }
        if (a[0] === 'ArrowUp')    { g.moveTo(x - 8, y); g.lineTo(x, y - 12); g.lineTo(x + 8, y); }
        g.fill();
      });
    }
    var hop = moving && !still ? (Math.floor(tk / 4) % 2 ? -5 : 0) : 0;
    g.fillStyle = 'rgba(0,0,0,.35)'; g.fillRect(pos.x - 22, pos.y - 24, 44, 5);
    SfpcChar.draw(g, ME, Math.round(pos.x - 32), Math.round(pos.y - 84 + hop), 4, dir, tk);
    if (!document.hidden) requestAnimationFrame(frame);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) requestAnimationFrame(frame); });

  cv.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (!queue.length && goEl.getAttribute('href')) location.href = goEl.href; return; }
    if (e.key.indexOf('Arrow') !== 0) return;
    e.preventDefault(); if (queue.length) return;
    var nb = neighbors(cur); if (e.key in nb) queue.push(nb[e.key]);
  });
  cv.addEventListener('click', function (e) {
    var r = cv.getBoundingClientRect(), x = (e.clientX - r.left) * W / r.width, y = (e.clientY - r.top) * H / r.height, best = -1, bd = 60 * 60;
    NODES.forEach(function (n, i) { var d = (n.x - x) * (n.x - x) + (n.y - 14 - y) * (n.y - 14 - y); if (d < bd) { bd = d; best = i; } });
    cv.focus();
    if (best < 0) return;
    if (best === cur && !queue.length) { if (goEl.getAttribute('href')) location.href = goEl.href; } else goTo(best);
  });
  info(); cv.focus({ preventScroll: true });
  (document.fonts && document.fonts.load ? document.fonts.load('700 16px Galmuri11') : Promise.resolve()).then(function () { requestAnimationFrame(frame); }, function () { requestAnimationFrame(frame); });
})();
</script>
<?php endif; ?>
<?php page_foot();
