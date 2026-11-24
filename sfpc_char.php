<?php
/* ═══════════════════════════════════════════════
   sfpc_char.php — 캐릭터 꾸미기. 팀 계정으로 우리 팀 캐릭터의 부품을 고른다.

   그림은 sfpc_char.js, 고를 수 있는 값은 sfpc_lib.php 의 SFPC_CHAR.
   저장은 고른 값 여섯 개뿐이라, 서버는 허용된 값인지만 확인한다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$team = sfpc_my_team($u);
if (!$team) { header('Location: sfpc_me.php'); exit; }               /* 팀 계정이 아니면 알맞은 곳으로 보내 준다 */
if ($team['status'] !== 'submitted') { header('Location: sfpc_me.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 시도해 주세요.');
  $c = sfpc_char_clean(json_decode((string)($_POST['char'] ?? ''), true));
  if (!$c) redirect_with('', '고른 값을 확인하지 못했습니다. 다시 골라 주세요.');
  db()->prepare("UPDATE sfpc_teams SET avatar = ? WHERE id = ?")->execute([json_encode($c), $team['id']]);
  $_SESSION['flash'] = ['msg' => '캐릭터를 저장했습니다.', 'err' => ''];
  header('Location: ' . ($team['avatar'] === '' ? 'sfpc_me.php' : 'sfpc_char.php')); exit;   /* 처음 만든 것이면 우리 팀 화면으로 */
}
[$msg, $err] = flash_take();
$first = $team['avatar'] === '';
$cur = sfpc_char_of($team);

page_head(['title' => '캐릭터 꾸미기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_me']);
sfpc_css();
?>
<div class="wrap narrow">
  <div class="phead"><h1>캐릭터 꾸미기</h1><span class="sub"><?= h($team['team_name']) ?><?php if ($team['avatar'] !== ''): ?> · <a href="sfpc_me.php">우리 팀으로</a><?php endif; ?></span></div>
  <?php if ($team['avatar'] === '' && !$err): ?>
    <div class="note info"><b><?= h($team['team_name']) ?></b> 팀, 반갑습니다. 먼저 우리 팀 캐릭터를 만들어 주세요. 대회 화면과 스코어보드에 이 캐릭터로 나옵니다. 저장하면 다음으로 넘어가고, 나중에 다시 바꿀 수 있습니다.</div>
  <?php endif; ?>
  <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>

  <canvas id="chStage" width="720" height="200" class="sfpc-ch-stage" role="img" aria-label="지금 고른 캐릭터가 걸어 다니는 모습"></canvas>

  <form method="post" id="chForm">
    <?= sfpc_csrf_field() ?>
    <input type="hidden" name="char" id="chVal" value="<?= h(json_encode($cur)) ?>">
    <?php foreach (SFPC_CHAR as $k => $part): ?>
      <div class="sfpc-ch-row" data-k="<?= h($k) ?>" role="group" aria-label="<?= h($part['label']) ?>">
        <span class="lab"><?= h($part['label']) ?></span>
        <div class="opts">
        <?php foreach ($part['items'] as $id => $name): ?>
          <?php if ($k === 'c'): ?>
            <button type="button" class="sw" data-v="<?= (int)$id ?>" aria-label="<?= h($name) ?>" title="<?= h($name) ?>"></button>
          <?php else: ?>
            <button type="button" data-v="<?= h((string)$id) ?>"><?= h($name) ?></button>
          <?php endif; ?>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="btnrow" style="margin-top:18px">
      <button class="btn primary" type="submit">이 캐릭터로 저장</button>
      <button class="btn" type="button" id="chRand">무작위로 섞기</button>
      <span class="small muted" id="chDirty" hidden>아직 저장하지 않았습니다</span>
    </div>
    <p class="small muted" style="margin:12px 0 0">몇 번이든 바꿀 수 있습니다. 이동 효과는 캐릭터가 움직일 때 뒤에 남습니다.</p>
  </form>
</div>
<script src="<?= asset('sfpc_char.js', '') ?>"></script>
<script>
(function () {
  var S = <?= json_encode($cur) ?>, saved = <?= $first ? "''" : 'JSON.stringify(S)' ?>;
  var c = document.getElementById('chStage'), g = c.getContext('2d'), val = document.getElementById('chVal');
  var rows = document.querySelectorAll('.sfpc-ch-row'), dirty = document.getElementById('chDirty');
  var P = 8, x = 120, dir = 1, tk = 0, puffs = [], still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function sync() {
    rows.forEach(function (r) {
      r.querySelectorAll('button').forEach(function (b) {
        var on = String(S[r.dataset.k]) === b.dataset.v;
        b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    });
    val.value = JSON.stringify(S);
    dirty.hidden = val.value === saved;
    if (still) frame();
  }
  rows.forEach(function (r) {
    r.querySelectorAll('button').forEach(function (b) {
      if (r.dataset.k === 'c') b.style.background = SfpcChar.COLOR[+b.dataset.v][0];
      b.addEventListener('click', function () { S[r.dataset.k] = r.dataset.k === 'c' ? +b.dataset.v : b.dataset.v; sync(); });
    });
  });
  document.getElementById('chRand').addEventListener('click', function () {
    rows.forEach(function (r) {
      var bs = r.querySelectorAll('button'), b = bs[Math.floor(Math.random() * bs.length)];
      S[r.dataset.k] = r.dataset.k === 'c' ? +b.dataset.v : b.dataset.v;
    });
    sync();
  });
  window.addEventListener('beforeunload', function (e) { if (saved !== '' && val.value !== saved && !window.__chSubmit) { e.preventDefault(); e.returnValue = ''; } });
  document.getElementById('chForm').addEventListener('submit', function () { window.__chSubmit = true; });

  function frame() {
    tk++; g.clearRect(0, 0, c.width, c.height);
    g.fillStyle = '#26215C'; g.fillRect(0, 182, c.width, 2);
    if (!still) { x += dir * 1.2; if (x > c.width - 16 * P - 20) dir = -1; if (x < 20) dir = 1; }
    if (!still && tk % 5 === 0) { var p = SfpcChar.puff(S, x, 50, P, dir); if (p) puffs.push(p); }
    puffs = puffs.filter(function (p) { return SfpcChar.drawPuff(g, p, P); });
    SfpcChar.draw(g, S, Math.round(still ? (c.width - 16 * P) / 2 : x), 58 + (!still && Math.floor(tk / 16) % 2 ? -P / 2 : 0), P, dir, tk);
    if (!still && !document.hidden) requestAnimationFrame(frame);
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && !still) requestAnimationFrame(frame); });
  sync(); if (!still) frame();
})();
</script>
<?php page_foot(); ?>
