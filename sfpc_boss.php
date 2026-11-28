<?php
/* ═══════════════════════════════════════════════
   sfpc_boss.php?b=N — 보스와 연타 대결. 스페이스를 정해진 시간(기본 20초) 동안 누른 횟수를 잰다.

   · 보스 하나에 한 판. 순위는 모든 보스의 합 (sfpc_rank.php).
   · 끝났을 때 SFPC_MASH_MIN(5)번 이하면 무효 — 실수로 시작했거나 꺼 버린 판이다. 기록하지 않고 몇 번이든 다시 한다.
     6번 이상이면 그 판이 확정된다 (중간에 창을 닫아도 그때까지 센 것으로 확정).
   · 횟수는 브라우저가 세고 끝에 한 번만 보낸다 — 누르는 동안 서버와 주고받지 않는다.
   · 보스는 관문이라 지나야 다음 월드가 열린다. "넘어가기"는 0점으로 길만 연다 (나중에 도전할 수 있다).
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
if (!is_admin($u) && ($nSolved < $b * $every || sfpc_boss_passed((int)$team['id']) < $b - 1)) { header('Location: sfpc_study.php'); exit; }

const SFPC_MASH_GRACE = 25;        /* 카운트다운과 통신을 넉넉히 봐준다(초) */
const SFPC_MASH_MAX_PER_SEC = 30;  /* 터무니없는 숫자가 들어오는 것만 막는다 */

/* 이 보스를 끝낸 뒤 갈 곳 — 마지막 보스이고 수료했으면 축하 화면 */
$after = function () use ($team, $b, $total): string {
  $t = $team ? sfpc_team((int)$team['id']) : null;
  return ($t && $b === $total && sfpc_study_check($t)) ? 'sfpc_grad.php' : 'sfpc_study.php';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) jout(['ok' => false, 'error' => '화면을 새로 고친 뒤 다시 해 주세요.']);
  if (!$team) jout(['ok' => false, 'error' => '운영진 미리 보기에서는 기록이 남지 않습니다.']);
  $act = (string)($_POST['act'] ?? '');
  $row = sfpc_mash_rows((int)$team['id'])[$b] ?? null;
  $st  = sfpc_boss_state($row);
  if ($act === 'start') {
    if ($st === 'done') jout(['ok' => false, 'error' => '이 보스와는 이미 싸웠습니다.']);
    if ($row) db()->prepare("UPDATE sfpc_mash SET cnt = 0, started_at = ?, ended_at = '' WHERE id = ?")->execute([now(), $row['id']]);
    else {
      try { db()->prepare("INSERT INTO sfpc_mash(contest, team_id, boss, round, cnt, started_at) VALUES(?,?,?,1,0,?)")->execute([SFPC_CONTEST, $team['id'], $b, now()]); }
      catch (Throwable $e) { jout(['ok' => false, 'error' => '다른 화면에서 이미 시작했습니다. 새로 고친 뒤 다시 해 주세요.']); }
    }
    $id = $row ? (int)$row['id'] : (int)db()->lastInsertId();
    jout(['ok' => true, 'id' => $id, 'sec' => $SEC]);
  }
  if ($act === 'finish') {
    if (!$row || (int)$row['id'] !== (int)($_POST['id'] ?? 0) || $row['ended_at'] !== '') jout(['ok' => false, 'error' => '이미 끝난 판입니다.']);
    $el  = time() - strtotime($row['started_at']);
    $cnt = max(0, min((int)($_POST['cnt'] ?? 0), $SEC * SFPC_MASH_MAX_PER_SEC));
    if ($el > $SEC + SFPC_MASH_GRACE + 30) $cnt = 0;
    if ($cnt <= SFPC_MASH_MIN) {                    /* 무효 — 넘어간 보스였으면 넘어간 채로, 아니면 아직 안 한 채로 둔다 */
      if ($st === 'skip') db()->prepare("UPDATE sfpc_mash SET cnt = 0, ended_at = started_at WHERE id = ?")->execute([$row['id']]);
      jout(['ok' => true, 'void' => true, 'cnt' => $cnt, 'min' => SFPC_MASH_MIN]);
    }
    db()->prepare("UPDATE sfpc_mash SET cnt = ?, ended_at = ?, skip = 0, removed_at = '' WHERE id = ?")->execute([$cnt, now(), $row['id']]);
    $sum = (int)col("SELECT COALESCE(SUM(cnt),0) FROM sfpc_mash WHERE contest = ? AND team_id = ? AND round = 1 AND skip = 0 AND removed_at = '' AND ended_at <> ''", [SFPC_CONTEST, $team['id']]);
    jout(['ok' => true, 'cnt' => $cnt, 'total' => $sum, 'next' => $after()]);
  }
  if ($act === 'skip') {
    if ($st === 'done') jout(['ok' => false, 'error' => '이미 기록이 있는 보스입니다.']);
    if ($row) db()->prepare("UPDATE sfpc_mash SET skip = 1, cnt = 0, ended_at = ? WHERE id = ?")->execute([now(), $row['id']]);
    else db()->prepare("INSERT INTO sfpc_mash(contest, team_id, boss, round, cnt, started_at, ended_at, skip) VALUES(?,?,?,1,0,?,?,1)")->execute([SFPC_CONTEST, $team['id'], $b, now(), now()]);
    jout(['ok' => true, 'next' => $after()]);
  }
  jout(['ok' => false, 'error' => '알 수 없는 요청입니다.']);
}

$row  = $team ? (sfpc_mash_rows((int)$team['id'])[$b] ?? null) : null;
$st   = sfpc_boss_state($row);
$mine = $st === 'done' ? ($row['removed_at'] === '' ? (int)$row['cnt'] : 0) : null;
$sumAll = $team ? (int)col("SELECT COALESCE(SUM(cnt),0) FROM sfpc_mash WHERE contest = ? AND team_id = ? AND round = 1 AND skip = 0 AND removed_at = '' AND ended_at <> ''", [SFPC_CONTEST, $team['id']]) : 0;
$char = $team ? sfpc_char_of($team) : sfpc_char_default((int)$u['id']);
$nextUrl = $after();

page_head(['title' => 'BOSS ' . $b . ' · ' . $bname, 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
?>
<div class="sfpc-world sfpc-arena">
  <div class="sfpc-arena-h">
    <span class="tag">WORLD <?= $b ?> BOSS</span>
    <h1><?= h($bname) ?></h1>
    <p>스페이스를 <?= $SEC ?>초 동안 최대한 많이 누르세요. 한 판뿐입니다. 꾹 누르고 있는 것은 세지 않습니다.</p>
  </div>
  <canvas id="ar" width="1000" height="420" role="img" aria-label="보스와 우리 팀 캐릭터가 마주 선 모습"></canvas>
  <div class="sfpc-arena-ui">
    <div class="score"><span>이 보스 <b id="r1"><?= $mine === null ? '–' : $mine ?></b></span><span class="sum">우리 팀 합계 <b id="rs"><?= $sumAll ?></b></span></div>
    <button type="button" class="big" id="arBtn"></button>
    <p class="msg" id="arMsg" aria-live="polite"><?= $st === 'skip' ? '넘어간 보스입니다 (0점). 지금 도전하면 기록이 남습니다.' : '' ?></p>
    <p class="links"><a href="sfpc_study.php">← 지도로</a><a href="sfpc_rank.php">연타 순위 보기</a>
      <?php if ($team && $st === ''): ?><button type="button" class="skip" id="arSkip">넘어가기 (0점)</button><?php endif; ?></p>
  </div>
</div>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script src="<?= asset('sfpc_boss.js') ?>"></script>
<script>
(function () {
  'use strict';
  var KIND = <?= json_encode($kind) ?>, ME = <?= json_encode($char) ?>, SEC = <?= (int)$SEC ?>, DONE = <?= $st === 'done' ? 'true' : 'false' ?>, TEAM = <?= $team ? 'true' : 'false' ?>, NEXT = <?= json_encode($nextUrl) ?>;
  var CSRF = document.createElement('div'); CSRF.innerHTML = <?= json_encode(sfpc_csrf_field()) ?>; var tokIn = CSRF.querySelector('input');
  var cv = document.getElementById('ar'), g = cv.getContext('2d'), btn = document.getElementById('arBtn'), msg = document.getElementById('arMsg'), skip = document.getElementById('arSkip');
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var state = 'idle', cnt = <?= (int)$mine ?>, id = 0, t0 = 0, cdAt = 0, hit = 0, lunge = 0, tk = 0, pops = [], down = DONE, TARGET = SEC * 7;

  function post(data) {
    var fd = new FormData(); fd.append(tokIn.name, tokIn.value); for (var k in data) fd.append(k, data[k]);
    return fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function label() {
    if (state === 'idle') { btn.disabled = false; btn.textContent = !TEAM ? '운영진 연습 시작 (스페이스) · 기록 없음' : (DONE ? (NEXT === 'sfpc_grad.php' ? '수료 축하 보러 가기 →' : '지도로 돌아가기 →') : '시작 (스페이스)'); btn.classList.toggle('go', DONE); }
    if (state === 'count') { btn.disabled = true; btn.textContent = '준비…'; }
    if (state === 'play') { btn.disabled = false; btn.textContent = '여기를 두드려도 됩니다 (스페이스)'; }
    if (skip) skip.hidden = state !== 'idle' || DONE;
  }
  function start() {
    if (state !== 'idle') return;
    if (!TEAM) {   /* 운영진 연습: 서버를 거치지 않는다 — 기록도 순위도 없다. 몇 번이든 다시 할 수 있다 */
      cnt = 0; down = false; msg.textContent = ''; state = 'count'; cdAt = performance.now(); label(); return;
    }
    if (DONE) { location.href = NEXT; return; }
    btn.disabled = true; msg.textContent = '';
    post({ act: 'start' }).then(function (j) {
      if (!j.ok) { msg.textContent = j.error; label(); return; }
      id = j.id; SEC = j.sec; cnt = 0; down = false; state = 'count'; cdAt = performance.now(); label();
    }).catch(function () { msg.textContent = '서버에 닿지 못했습니다. 다시 눌러 주세요.'; label(); });
  }
  function finish(beacon) {
    if (state !== 'play') return;
    if (!TEAM) { state = 'idle'; msg.textContent = cnt + '회! (운영진 연습 — 기록에 남지 않습니다. 다시 하려면 시작을 누르세요)'; label(); return; }
    state = 'sending';
    if (beacon && navigator.sendBeacon) { var fd = new FormData(); fd.append(tokIn.name, tokIn.value); fd.append('act', 'finish'); fd.append('id', id); fd.append('cnt', cnt); navigator.sendBeacon(location.href, fd); return; }
    btn.disabled = true; btn.textContent = '기록하는 중…';
    post({ act: 'finish', id: id, cnt: cnt }).then(function (j) {
      state = 'idle';
      if (!j.ok) { msg.textContent = j.error; label(); return; }
      if (j.void) { cnt = 0; msg.textContent = j.min + '번 이하는 기록하지 않습니다. 다시 도전하세요!'; label(); return; }
      DONE = true; NEXT = j.next; down = true;
      document.getElementById('r1').textContent = j.cnt; document.getElementById('rs').textContent = j.total;
      msg.textContent = j.cnt + '회! 보스를 물리쳤습니다. 우리 팀 합계는 ' + j.total + '회입니다.';
      label(); btn.focus();
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
    if (state === 'idle') { if (!DONE || !TEAM) start(); } else tap();    /* 끝난 뒤의 스페이스로 화면이 넘어가 버리지 않게, 이동은 버튼으로만 */
  });
  btn.addEventListener('pointerdown', function (e) { if (state === 'play') { e.preventDefault(); tap(); } });
  btn.addEventListener('click', function () { if (state === 'idle') start(); });
  btn.addEventListener('keydown', function (e) { if (e.key === 'Enter' && state === 'idle' && DONE) location.href = NEXT; });
  if (skip) skip.addEventListener('click', function () {
    if (state !== 'idle' || !confirm('이 보스를 넘어갈까요?\n이 보스의 기록은 0점이 됩니다. 나중에 지도에서 돌아와 도전할 수 있습니다.')) return;
    post({ act: 'skip' }).then(function (j) { if (j.ok) location.href = j.next; else msg.textContent = j.error; });
  });
  window.addEventListener('pagehide', function () { finish(true); });
  function text(s, x, y, size, col, al) { g.font = '700 ' + size + 'px Galmuri11, monospace'; g.fillStyle = col; g.textAlign = al || 'center'; g.fillText(s, x, y); }
  function frame(now) {
    tk++; g.clearRect(0, 0, 1000, 420);
    g.fillStyle = '#26215C'; g.fillRect(0, 372, 1000, 4);
    var left = SEC, hp = 1;
    if (state === 'count') { var c = 3 - Math.floor((now - cdAt) / 1000); if (c <= 0) { state = 'play'; t0 = now; label(); } else text(String(c), 500, 230, 120, '#FAC775'); }
    if (state === 'play' || state === 'sending') { left = Math.max(0, SEC - (now - t0) / 1000); if (state === 'play' && left <= 0) finish(false); }
    hp = Math.max(0, 1 - cnt / TARGET);
    if (DONE && state === 'idle') hp = 0;
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
