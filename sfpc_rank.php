<?php
/* ═══════════════════════════════════════════════
   sfpc_rank.php — 연타 순위. 공부하기의 보스들과 싸운 기록의 합 (보스마다 한 판).
   다른 팀과 견주는 것은 이 순위 하나뿐이다 (공부하기 진도는 순위를 내지 않는다).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$team = sfpc_my_team($u);
if (!$team && !is_admin($u)) { header('Location: sfpc_study.php'); exit; }
$rank = sfpc_mash_rank();

page_head(['title' => '연타 순위', 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
?>
<div class="sfpc-world sfpc-rank">
  <div class="sfpc-arena-h"><span class="tag">SPACE MASH RANKING</span><h1>연타 순위</h1>
    <p>모든 보스와 싸운 기록의 합입니다. 보스마다 한 판, 다섯 마리를 다 만나야 합계가 완성됩니다.</p></div>
  <?php if (!$rank): ?>
    <div class="sfpc-world-empty">아직 기록이 없습니다. 첫 보스를 만나 보세요.</div>
  <?php else: ?>
  <ol class="sfpc-rank-list">
    <?php foreach ($rank as $i => $r): $mine = $team && (int)$team['id'] === (int)$r['id']; ?>
      <li class="<?= $mine ? 'me' : '' ?><?= $i < 3 ? ' top' : '' ?>">
        <span class="no"><?= $i + 1 ?></span>
        <canvas width="48" height="48" data-char="<?= h(json_encode(sfpc_char_of($r))) ?>" aria-hidden="true"></canvas>
        <span class="t"><b><?= h($r['team_name']) ?></b><small><?= h($r['school_name']) ?> · 보스 <?= (int)$r['plays'] ?>마리</small></span>
        <span class="sum"><?= number_format((int)$r['total']) ?><i>회</i></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <p class="sfpc-world-help"><a href="sfpc_study.php">← 지도로</a></p>
</div>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script>
document.querySelectorAll('.sfpc-rank-list canvas').forEach(function (c) { SfpcChar.draw(c.getContext('2d'), JSON.parse(c.dataset.char), 0, 0, 3, 1, 0); });
</script>
<?php page_foot();
