<?php
/* ═══════════════════════════════════════════════
   sfpc_grad.php — 공부하기 수료 축하. 수료하는 순간 한 번 자동으로 뜨고, 그 뒤에는 지도에서 다시 볼 수 있다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$team = sfpc_my_team($u);
if (!$team && is_admin($u) && !empty($_GET['team'])) $team = sfpc_team((int)$_GET['team']);     /* 운영진 미리 보기 */
if (!$team || $team['study_done_at'] === '') { header('Location: sfpc_study.php'); exit; }
unset($_SESSION['sfpc_grad']);
$members = sfpc_team_members((int)$team['id']);
$char    = sfpc_char_of($team);
$formal  = $team['track'] === 'participant';
$bosses  = []; for ($i = 1; $i <= sfpc_boss_count(); $i++) $bosses[] = sfpc_boss_kind($i)[0];

page_head(['title' => '수료 축하', 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
?>
<div class="sfpc-world sfpc-grad" id="grad">
  <canvas id="gfx" aria-hidden="true"></canvas>
  <div class="in">
    <p class="tag">STUDY COMPLETE</p>
    <h1>수료를 축하합니다!</h1>
    <p class="team"><?= h($team['team_name']) ?></p>
    <p class="sch"><?= h($team['school_name']) ?> · <?= h(substr($team['study_done_at'], 0, 10)) ?> 수료</p>
    <div class="members" id="gmem">
      <?php foreach ($members as $m): ?>
        <div class="m"><canvas width="112" height="120" aria-hidden="true"></canvas><b><?= h($m['name']) ?></b></div>
      <?php endforeach; ?>
    </div>
    <div class="pass">
      <b>공부하기의 모든 문제를 풀었습니다.</b>
      <?php if ($formal): ?>
        <span>이제 <em>정식참가자</em>로 <em>도전하기(본 대회)</em>에 참가할 수 있습니다.</span>
      <?php else: ?>
        <span><em>도전하기(본 대회)</em>에 나갈 준비를 마쳤습니다. (참가 자격: <?= h(SFPC_TRACK_NAME[$team['track']] ?? '') ?>)</span>
      <?php endif; ?>
    </div>
    <p class="links"><a class="go" href="sfpc_study.php">지도로 돌아가기</a><a href="sfpc_rank.php">연타 순위</a><a href="sfpc_me.php">우리 팀</a></p>
  </div>
</div>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script src="<?= asset('sfpc_boss.js') ?>"></script>
<script>
(function () {
  'use strict';
  var ME = <?= json_encode($char) ?>, BOSSES = <?= json_encode($bosses) ?>;
  var box = document.getElementById('grad'), cv = document.getElementById('gfx'), g = cv.getContext('2d');
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches, tk = 0, bits = [], rockets = [];
  var COL = ['#5DCAA5', '#FAC775', '#ED93B1', '#85B7EB', '#7F77DD', '#F0997B', '#fff'];
  function size() { cv.width = box.clientWidth; cv.height = box.clientHeight; }
  size(); addEventListener('resize', size);
  function burst(x, y) { var c = COL[Math.floor(Math.random() * COL.length)]; for (var i = 0; i < 46; i++) { var a = Math.random() * 6.283, s = 2 + Math.random() * 5; bits.push({ x: x, y: y, vx: Math.cos(a) * s, vy: Math.sin(a) * s, l: 1, c: Math.random() < .3 ? '#fff' : c, z: 3 + Math.random() * 3, f: true }); } }
  var mem = [].slice.call(document.querySelectorAll('#gmem canvas'));
  function frame() {
    tk++; g.clearRect(0, 0, cv.width, cv.height);
    if (!still) {
      if (tk % 34 === 1) rockets.push({ x: cv.width * (.12 + Math.random() * .76), y: cv.height, ty: cv.height * (.12 + Math.random() * .3), c: '#fff' });
      if (tk % 3 === 0 && bits.length < 600) bits.push({ x: Math.random() * cv.width, y: -8, vx: (Math.random() - .5) * 1.4, vy: 1.6 + Math.random() * 2.2, l: 1, c: COL[Math.floor(Math.random() * COL.length)], z: 5 + Math.random() * 4, f: false });
      rockets = rockets.filter(function (r) { r.y -= 9; g.fillStyle = '#fff'; g.fillRect(r.x, r.y, 3, 10); if (r.y <= r.ty) { burst(r.x, r.y); return false; } return true; });
      bits = bits.filter(function (b) {
        b.x += b.vx; b.y += b.vy; if (b.f) { b.vy += .09; b.l -= .014; } else if (b.y > cv.height + 10) return false;
        g.globalAlpha = Math.max(0, b.l); g.fillStyle = b.c; g.fillRect(b.x, b.y, b.z, b.f ? b.z : b.z * (.5 + Math.abs(Math.sin((tk + b.x) / 9)))); g.globalAlpha = 1;
        return b.l > 0;
      });
    }
    /* 물리친 보스들이 아래에 늘어선다 */
    var bw = 14 * 4 + 26, x0 = (cv.width - BOSSES.length * bw) / 2;
    BOSSES.forEach(function (k, i) { SfpcBoss.draw(g, k, x0 + i * bw, cv.height - 86, 4, tk + i * 9, { down: true }); });
    mem.forEach(function (c, i) {
      var x = c.getContext('2d'); x.clearRect(0, 0, 112, 120);
      var hop = still ? 0 : Math.round(Math.abs(Math.sin(tk / 9 + i * 1.1)) * 16);
      SfpcChar.draw(x, ME, 0, 22 - hop, 7, i % 2 ? -1 : 1, tk + i * 13);
    });
    if (!still && !document.hidden) requestAnimationFrame(frame);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && !still) requestAnimationFrame(frame); });
  frame();
})();
</script>
<?php page_foot();
