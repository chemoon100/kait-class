<?php
/* ═══════════════════════════════════════════════
   sfpc_board.php — 스코어보드. 본 대회의 순위를 누구나 본다 (공개 스위치가 켜져 있을 때).

   · 얼림(프리즈): 끝나기 N분 전부터의 제출은 결과를 가리고 "?"로만 보인다. 대회가 끝나도 얼어 있다가,
     운영진이 "최종 결과 공개"를 누르면 풀린다. 시상식에서 결과를 발표한 뒤 누르면 된다.
   · 운영진은 ?live=1 로 얼지 않은 실제 순위를 볼 수 있다.
   · 학생 팀만 나온다. 정식참가자만 순위가 붙고, 시범참가자는 아래에 순위 없이 나온다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
$team = sfpc_my_team($u);
$live = is_admin($u) && !empty($_GET['live']);

page_head(['title' => '스코어보드', 'root' => '', 'user' => $u, 'nav' => 'sfpc_board']);
sfpc_css();
if (!sfpc_gate('sfpc_board_open', '스코어보드', $u)) { page_foot(); exit; }
$b = sfpc_board($live); $c = $b['c']; $probs = $b['probs'];
$final = sfpc_setting('sfpc_board_final') === '1';
?>
<div class="sfpc-world sfpc-board">
  <div class="sfpc-arena-h"><span class="tag">SCOREBOARD</span><h1><?= h(sfpc_setting('sfpc_title')) ?></h1>
    <p><?php if ($c['state'] === 'unset'): ?>대회 일시가 정해지면 열립니다.
      <?php elseif ($c['state'] === 'before'): ?><?= h(date('n월 j일 H:i', $c['from'])) ?> 에 시작합니다.
      <?php elseif ($c['state'] === 'open'): ?>대회 진행 중 · <?= h(date('H:i', $c['to'])) ?> 에 끝납니다 · 30초마다 새로 고칩니다
      <?php else: ?>대회가 끝났습니다<?= $final || !$c['freeze'] ? ' · 최종 결과' : '' ?><?php endif; ?></p></div>
  <?php if ($b['frozen']): ?><div class="sfpc-frozen">❄ 스코어보드가 얼어 있습니다 — <?= h(date('H:i', $c['freeze'])) ?> 이후의 제출은 결과가 가려져 <b>?</b> 로 보입니다. 최종 결과는 시상식에서 공개합니다.</div><?php endif; ?>
  <?php if (is_admin($u) && $c['freeze'] && !$final && $c['state'] !== 'before'): ?>
    <div class="sfpc-world-note"><?= $live ? '운영진에게만 보이는 실제 순위입니다 (얼리지 않음). ' : '' ?><a href="sfpc_board.php<?= $live ? '' : '?live=1' ?>"><?= $live ? '참가자가 보는 화면으로' : '운영진용: 얼리지 않은 실제 순위 보기' ?></a> · 최종 결과 공개는 운영 &gt; SFPC &gt; 준비 · 대회</div>
  <?php endif; ?>

  <?php if ($c['state'] === 'unset' || $c['state'] === 'before' || !$b['rows']): ?>
    <div class="sfpc-world-empty"><?= $c['state'] === 'open' || $c['state'] === 'after' ? '참가 팀이 없습니다.' : '대회가 시작되면 순위가 나타납니다.' ?></div>
  <?php else: ?>
  <div class="sfpc-board-wrap"><table>
    <thead><tr><th class="r">순위</th><th class="tm">팀</th><th class="n">맞힌 문제</th><th class="n">벌점</th>
      <?php foreach ($probs as $i => $p): ?><th class="p" title="<?= $c['state'] === 'after' || is_admin($u) ? h($p['title']) : '' ?>"><?= sfpc_letter($i) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php $sep = false; foreach ($b['rows'] as $r): $t = $r['team']; $mine = $team && (int)$team['id'] === (int)$t['id'];
      if (!$r['ranked'] && !$sep): $sep = true; ?><tr class="sep"><td colspan="<?= 4 + count($probs) ?>">시범참가 (순위 없음)</td></tr><?php endif; ?>
      <tr class="<?= $mine ? 'me' : '' ?><?= $r['ranked'] && $r['rank'] <= 3 && $r['solved'] > 0 ? ' top' : '' ?>">
        <td class="r"><?= $r['ranked'] ? (int)$r['rank'] : '–' ?></td>
        <td class="tm"><canvas width="32" height="32" data-char="<?= h(json_encode(sfpc_char_of($t))) ?>" aria-hidden="true"></canvas><span><b><?= h($t['team_name']) ?></b><small><?= h($t['school_name']) ?></small></span></td>
        <td class="n"><b><?= (int)$r['solved'] ?></b></td><td class="n"><?= (int)$r['penalty'] ?></td>
        <?php foreach ($probs as $p): $x = $r['cells'][(int)$p['id']] ?? null; ?>
          <td class="p <?= !$x ? '' : ($x['ok'] ? ($x['first'] ? 'first' : 'ok') : ($x['pend'] ? 'pend' : 'bad')) ?>"><?php
            if ($x && $x['ok']) echo '<b>' . ($x['tries'] ? '+' . (int)$x['tries'] : '+') . '</b><small>' . (int)$x['min'] . '</small>';
            elseif ($x && $x['pend']) echo '<b>?</b><small>' . ((int)$x['tries'] + (int)$x['pend']) . '회</small>';
            elseif ($x && $x['tries']) echo '<b>-' . (int)$x['tries'] . '</b>'; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="sfpc-board-key"><i class="first"></i>가장 먼저 맞힘 <i class="ok"></i>맞힘 (+오답 수 / 맞힌 분) <i class="bad"></i>오답 <i class="pend"></i>가려진 제출 · 벌점 = 맞힌 분 + 오답 × <?= SFPC_WRONG_MIN ?></p>
  <?php endif; ?>
</div>
<script src="<?= asset('sfpc_char.js') ?>"></script>
<script>
document.querySelectorAll('.sfpc-board canvas').forEach(function (c) { SfpcChar.draw(c.getContext('2d'), JSON.parse(c.dataset.char), 0, 0, 2, 1, 0); });
<?php if ($c['state'] === 'open'): ?>setTimeout(function () { if (!document.hidden) location.reload(); else document.addEventListener('visibilitychange', function () { location.reload(); }, { once: true }); }, 30000);<?php endif; ?>
</script>
<?php page_foot();
