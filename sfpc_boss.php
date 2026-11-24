<?php
/* ═══════════════════════════════════════════════
   sfpc_boss.php?b=N — 보스와 연타 대결. 스페이스를 정해진 시간(기본 20초) 동안 누른 횟수를 잰다.

   · 보스 하나에 두 판. 순위는 모든 판의 합 (sfpc_rank.php).
   · 판은 "시작"을 누를 때 서버에 줄이 생기고(그때 한 판을 쓴 것이다), 끝나면 횟수를 적는다.
     횟수는 브라우저가 세고 끝에 한 번만 보낸다 — 누르는 동안 서버와 주고받지 않는다.
   · 놀이다. 수료와 상관없고, 보스를 "못 잡는" 경우는 없다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$team = sfpc_my_team($u);
if (!$team && !is_admin($u)) { header('Location: sfpc_study.php'); exit; }
if (sfpc_setting('sfpc_study_open') !== '1' && !is_admin($u)) { header('Location: sfpc_study.php'); exit; }

$b = (int)($_GET['b'] ?? 0); $total = sfpc_boss_count(); $every = sfpc_boss_every(); $SEC = sfpc_mash_sec();
if ($b < 1 || $b > $total) { header('Location: sfpc_study.php'); exit; }
[$kind, $bname] = sfpc_boss_kind($b);
$probIds = array_map('intval', array_column(sfpc_study_problems(), 'id'));
$nSolved = count(array_intersect($probIds, sfpc_study_solved((int)$u['id'])));
if ($nSolved < $b * $every && !is_admin($u)) { header('Location: sfpc_study.php'); exit; }

const SFPC_MASH_GRACE = 25;        /* 카운트다운과 통신을 넉넉히 봐준다(초) */
const SFPC_MASH_MAX_PER_SEC = 30;  /* 터무니없는 숫자가 들어오는 것만 막는다 */

/* 끝내지 않고 떠난 판은 0회로 닫는다 */
if ($team) db()->prepare("UPDATE sfpc_mash SET ended_at = started_at WHERE team_id = ? AND ended_at = '' AND started_at < ?")
               ->execute([$team['id'], date('Y-m-d H:i:s', time() - $SEC - SFPC_MASH_GRACE - 30)]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) jout(['ok' => false, 'error' => '화면을 새로 고친 뒤 다시 해 주세요.']);
  if (!$team) jout(['ok' => false, 'error' => '운영진 미리 보기에서는 기록이 남지 않습니다.']);
  $act = (string)($_POST['act'] ?? '');
  $rows = sfpc_mash_rows((int)$team['id'])[$b] ?? [];
  if ($act === 'start') {
    foreach ($rows as $r) if ($r['ended_at'] === '') jout(['ok' => false, 'error' => '이미 진행 중인 판이 있습니다. 잠시 뒤 다시 열어 주세요.']);
    if (count($rows) >= 2) jout(['ok' => false, 'error' => '이 보스와는 두 판을 모두 했습니다.']);
    $round = count($rows) + 1;
    try {
      db()->prepare("INSERT INTO sfpc_mash(contest, team_id, boss, round, cnt, started_at) VALUES(?,?,?,?,0,?)")->execute([SFPC_CONTEST, $team['id'], $b, $round, now()]);
    } catch (Throwable $e) { jout(['ok' => false, 'error' => '다른 화면에서 이미 시작했습니다.']); }
    jout(['ok' => true, 'id' => (int)db()->lastInsertId(), 'round' => $round, 'sec' => $SEC]);
  }
  if ($act === 'finish') {
    $r = one("SELECT * FROM sfpc_mash WHERE id = ? AND team_id = ? AND boss = ?", [(int)($_POST['id'] ?? 0), $team['id'], $b]);
    if (!$r || $r['ended_at'] !== '') jout(['ok' => false, 'error' => '이미 끝난 판입니다.']);
    $el  = time() - strtotime($r['started_at']);
    $cnt = max(0, min((int)($_POST['cnt'] ?? 0), $SEC * SFPC_MASH_MAX_PER_SEC));
    if ($el > $SEC + SFPC_MASH_GRACE + 30) $cnt = 0;
    db()->prepare("UPDATE sfpc_mash SET cnt = ?, ended_at = ? WHERE id = ?")->execute([$cnt, now(), $r['id']]);
    $sum = (int)col("SELECT COALESCE(SUM(cnt),0) FROM sfpc_mash WHERE contest = ? AND team_id = ? AND removed_at = '' AND ended_at <> ''", [SFPC_CONTEST, $team['id']]);
    jout(['ok' => true, 'cnt' => $cnt, 'round' => (int)$r['round'], 'total' => $sum]);
  }
  jout(['ok' => false, 'error' => '알 수 없는 요청입니다.']);
}

$rows = $team ? (sfpc_mash_rows((int)$team['id'])[$b] ?? []) : [];
$char = $team ? sfpc_char_of($team) : sfpc_char_default((int)$u['id']);
$played = []; foreach ($rows as $rd => $r) if ($r['ended_at'] !== '') $played[$rd] = $r['removed_at'] === '' ? (int)$r['cnt'] : null;

page_head(['title' => 'BOSS ' . $b . ' · ' . $bname, 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
?>
<div class="sfpc-world sfpc-arena">
  <div class="sfpc-arena-h">
    <span class="tag">WORLD <?= $b ?> BOSS</span>
    <h1><?= h($bname) ?></h1>
    <p>스페이스를 <?= $SEC ?>초 동안 최대한 많이 누르세요. 두 판의 합이 기록됩니다. 꾹 누르고 있는 것은 세지 않습니다.</p>
  </div>
  <canvas id="ar" width="1000" height="420" role="img" aria-label="보스와 우리 팀 캐릭터가 마주 선 모습"></canvas>
  <div class="sfpc-arena-ui">
    <div class="score"><span>1판 <b id="r1"><?= isset($played[1]) ? (int)$played[1] : '–' ?></b></span><span>2판 <b id="r2"><?= isset($played[2]) ? (int)$played[2] : '–' ?></b></span><span class="sum">합계 <b id="rs"><?= array_sum(array_map('intval', $played)) ?></b></span></div>
    <button type="button" class="big" id="arBtn"></button>
    <p class="msg" id="arMsg" aria-live="polite"></p>
    <p class="links"><a href="sfpc_study.php">← 지도로</a><a href="sfpc_rank.php">연타 순위 보기</a></p>
  </div>
</div>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script src="<?= asset('sfpc_boss.js') ?>"></script>
<script>
(function () {
  'use strict';
  var KIND = <?= json_encode($kind) ?>, ME = <?= json_encode($char) ?>, SEC = <?= (int)$SEC ?>, DONE = <?= count($played) ?>, TEAM = <?= $team ? 'true' : 'false' ?>;
  var CSRF = document.createElement('div'); CSRF.innerHTML = <?= json_encode(sfpc_csrf_field()) ?>; var tokIn = CSRF.querySelector('input');
  var cv = document.getElementById('ar'), g = cv.getContext('2d'), btn = document.getElementById('arBtn'), msg = document.getElementById('arMsg');
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var state = 'idle', cnt = 0, id = 0, round = 0, t0 = 0, cdAt = 0, hit = 0, lunge = 0, tk = 0, pops = [], down = false, TARGET = SEC * 7;

  function post(data) {
    var fd = new FormData(); fd.append(tokIn.name, tokIn.value); for (var k in data) fd.append(k, data[k]);
    return fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function label() {
    if (state === 'idle') { btn.disabled = DONE >= 2 || !TEAM; btn.textContent = !TEAM ? '운영진 미리 보기 (기록 없음)' : (DONE >= 2 ? '두 판을 모두 했습니다' : (DONE + 1) + '판 시작'); }
    if (state === 'count') { btn.disabled = true; }
    if (state === 'play') { btn.disabled = false; btn.textContent = '여기를 두드려도 됩니다 (스페이스)'; }
  }
  function start() {
    if (state !== 'idle' || DONE >= 2 || !TEAM) return;
    btn.disabled = true; msg.textContent = '';
    post({ act: 'start' }).then(function (j) {
      if (!j.ok) { msg.textContent = j.error; label(); return; }
      id = j.id; round = j.round; SEC = j.sec; cnt = 0; down = false; state = 'count'; cdAt = performance.now(); label();
    }).catch(function () { msg.textContent = '서버에 닿지 못했습니다. 다시 눌러 주세요.'; label(); });
  }
  function finish(beacon) {
    if (state !== 'play') return; state = 'sending';
    if (beacon && navigator.sendBeacon) { var fd = new FormData(); fd.append(tokIn.name, tokIn.value); fd.append('act', 'finish'); fd.append('id', id); fd.append('cnt', cnt); navigator.sendBeacon(location.href, fd); return; }
    btn.disabled = true; btn.textContent = '기록하는 중…';
    post({ act: 'finish', id: id, cnt: cnt }).then(function (j) {
      state = 'idle';
      if (!j.ok) { msg.textContent = j.error; label(); return; }
      DONE = j.round; document.getElementById('r' + j.round).textContent = j.cnt; document.getElementById('rs').textContent =
        (parseInt(document.getElementById('r1').textContent, 10) || 0) + (parseInt(document.getElementById('r2').textContent, 10) || 0);
      msg.textContent = j.round + '판 ' + j.cnt + '회! ' + (j.round < 2 ? '한 판 더 할 수 있습니다.' : '우리 팀 전체 합계는 ' + j.total + '회입니다.');
      label();
    }).catch(function () { state = 'play'; msg.textContent = '기록을 보내지 못했습니다. 다시 보내는 중…'; setTimeout(function () { finish(false); }, 1500); });
  }
  function tap() {
    if (state !== 'play') return;
    cnt++; hit = 1; lunge = 1;
    if (pops.length < 30) pops.push({ x: 640 + Math.random() * 180, y: 150 + Math.random() * 120, l: 1, v: cnt });
    if (cnt >= TARGET) down = true;
  }
  document.addEventListener('keydown', function (e) {
    if (e.code !== 'Space' && e.key !== ' ') return;
    e.preventDefault(); if (e.repeat) return;                    /* 꾹 누르기는 세지 않는다 */
    if (state === 'idle') start(); else tap();
  });
  btn.addEventListener('pointerdown', function (e) { if (state === 'play') { e.preventDefault(); tap(); } });
  btn.addEventListener('click', function () { if (state === 'idle') start(); });
  window.addEventListener('pagehide', function () { finish(true); });

  function text(s, x, y, size, col, al) { g.font = '700 ' + size + 'px Galmuri11, monospace'; g.fillStyle = col; g.textAlign = al || 'center'; g.fillText(s, x, y); }
  function frame(now) {
    tk++; g.clearRect(0, 0, 1000, 420);
    g.fillStyle = '#26215C'; g.fillRect(0, 372, 1000, 4);
    var left = SEC, hp = 1;
    if (state === 'count') { var c = 3 - Math.floor((now - cdAt) / 1000); if (c <= 0) { state = 'play'; t0 = now; label(); } else text(String(c), 500, 230, 120, '#FAC775'); }
    if (state === 'play' || state === 'sending') { left = Math.max(0, SEC - (now - t0) / 1000); if (state === 'play' && left <= 0) finish(false); }
    hp = Math.max(0, 1 - cnt / TARGET);
    /* 체력 막대 — 보기용이다. 0 이 되어도 계속 셀 수 있다 */
    g.fillStyle = '#2A2150'; g.fillRect(560, 40, 380, 18); g.fillStyle = hp > .3 ? '#E24B4A' : '#EF9F27'; g.fillRect(560, 40, 380 * hp, 18);
    text(down ? 'OVERKILL!' : 'BOSS HP', 940, 30, 14, down ? '#FAC775' : '#AFA9EC', 'right');
    /* 남은 시간과 횟수 */
    g.fillStyle = '#2A2150'; g.fillRect(60, 40, 380, 18); g.fillStyle = '#5DCAA5'; g.fillRect(60, 40, 380 * (left / SEC), 18);
    text((state === 'play' ? left.toFixed(1) : SEC.toFixed(1)) + '초', 60, 30, 14, '#AFA9EC', 'left');
    text(String(cnt), 250, 150, 84, '#fff'); text('회', 250, 182, 16, '#AFA9EC');
    /* 보스 */
    var bob = still || down ? 0 : Math.round(Math.sin(tk / 18) * 5);
    SfpcBoss.draw(g, KIND, 640, 120 + bob + (down ? 26 : 0), 15, tk, { hit: hit, down: down });
    /* 우리 캐릭터 — 칠 때마다 앞으로 */
    SfpcChar.draw(g, ME, 300 + Math.round(lunge * 46), 250 - Math.round(lunge * 10), 7, 1, tk);
    pops = pops.filter(function (p) { g.globalAlpha = p.l; text('+1', p.x, p.y - (1 - p.l) * 40, 18, '#FAC775'); g.globalAlpha = 1; p.l -= .05; return p.l > 0; });
    hit = Math.max(0, hit - .2); lunge = Math.max(0, lunge - .25);
    if (!document.hidden) requestAnimationFrame(frame);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) requestAnimationFrame(frame); });
  label(); requestAnimationFrame(frame);
})();
</script>
<?php page_foot();
