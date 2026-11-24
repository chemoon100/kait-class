<?php
/* ═══════════════════════════════════════════════
   sfpc_contest.php — 도전하기 (본 대회). 모든 팀이 같은 시각에 시작해 같은 시각에 끝난다.

   · ICPC 방식: 맞힌 문제 수 → 벌점(맞힌 시각 + 오답 1번에 20분). 점수 계산은 sfpc_lib.php 의 sfpc_icpc().
   · 대회 중에만 문제가 열린다. 자기 팀의 결과는 스코어보드가 얼어 있어도 그대로 보인다.
   · 시작 · 끝 · 얼리는 때는 운영 > SFPC > 준비 · 대회에서 정한다.
   팀 계정만 푼다. 교사 · 예비교사 팀(옵저버)도 풀 수 있지만 스코어보드에는 나오지 않는다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
$team = sfpc_my_team($u);

page_head(['title' => '도전하기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_contest']);
sfpc_css();
if (!sfpc_gate('sfpc_contest_open', '도전하기', $u)) { page_foot(); exit; }

if (!$team && !is_admin($u)) { ?>
<div class="wrap narrow">
  <div class="phead"><h1>도전하기</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="sfpc-soon" style="margin:20px 0"><h1>팀 계정으로 들어와 주세요</h1>
    <p>본 대회는 접수한 팀이 팀 계정으로 로그인해서 참가합니다.</p>
    <p style="margin-top:20px"><a class="btn primary" href="<?= $u ? 'sfpc_teams.php' : 'login.php?next=' . rawurlencode('sfpc_contest.php') ?>"><?= $u ? '내 팀에서 팀 계정 확인하기' : '팀 계정으로 로그인' ?></a>
      <a class="btn" href="sfpc_board.php">스코어보드 보기</a></p></div>
</div>
<?php page_foot(); exit; }

$c     = sfpc_contest_time();
$setId = (int)sfpc_setting('sfpc_contest_set');
$probs = $setId ? sfpc_set_problems($setId) : [];
$off   = $team && $team['status'] !== 'submitted';
$sc    = ($team && $c['state'] !== 'unset' && $c['state'] !== 'before') ? sfpc_icpc_one((int)$team['account_user_id'], $setId, $c['from'], $c['to']) : ['solved' => 0, 'penalty' => 0, 'cells' => []];
$fmt   = fn(int $t) => date('n월 j일 H:i', $t);
?>
<div class="sfpc-world sfpc-arcade">
  <?php if (!$team): ?><div class="sfpc-world-note">운영진 미리 보기입니다. 시작 · 끝 · 스코어보드 얼리기는 운영 &gt; SFPC &gt; 준비 · 대회에서 정합니다.</div><?php endif; ?>
  <?php if ($team) sfpc_notice_box($u); ?>

<?php if ($off): ?>
  <div class="sfpc-world-empty">접수가 취소된 팀은 참가할 수 없습니다. 지도 선생님께 확인해 주세요.</div>
<?php elseif ($c['state'] === 'unset'): ?>
  <div class="sfpc-arena-h"><span class="tag">MAIN CONTEST</span><h1>도전하기</h1><p>대회 일시가 정해지면 여기에 안내합니다.</p></div>
<?php elseif ($c['state'] === 'before'): ?>
  <div class="sfpc-arena-h"><span class="tag">MAIN CONTEST</span><h1>곧 시작합니다</h1>
    <p><?= h($fmt($c['from'])) ?> ~ <?= h(date('H:i', $c['to'])) ?> (<?= (int)round(($c['to'] - $c['from']) / 60) ?>분)</p></div>
  <div class="sfpc-count" id="cd" data-at="<?= $c['from'] ?>" data-now="<?= time() ?>">--:--:--</div>
  <ul class="sfpc-rules">
    <li>시작 시각이 되면 이 화면에 문제가 나타납니다. 새로 고치지 않아도 됩니다.</li>
    <li>맞힌 문제가 많은 팀이 위, 같으면 벌점이 적은 팀이 위입니다.</li>
    <li>벌점은 맞힌 문제마다 "시작부터 맞힐 때까지의 분 + 그 전에 틀린 횟수 × <?= SFPC_WRONG_MIN ?>분"입니다. 끝내 못 맞힌 문제의 오답은 벌점이 없습니다.</li>
    <li>컴파일 오류는 오답으로 세지 않습니다.</li>
    <?php if ($c['freeze']): ?><li>끝나기 <?= (int)round(($c['to'] - $c['freeze']) / 60) ?>분 전(<?= h(date('H:i', $c['freeze'])) ?>)부터 스코어보드가 얼어 다른 팀의 결과가 가려집니다. 우리 팀의 결과는 계속 보입니다.</li><?php endif; ?>
  </ul>
  <script>
  (function () { var el = document.getElementById('cd'), at = +el.dataset.at, skew = +el.dataset.now - Date.now() / 1000;
    function p(n) { return (n < 10 ? '0' : '') + n; }
    function tick() { var l = Math.round(at - (Date.now() / 1000 + skew)); if (l <= 0) { location.reload(); return; }
      var d = Math.floor(l / 86400), h = Math.floor(l % 86400 / 3600), m = Math.floor(l % 3600 / 60), s = l % 60; el.textContent = (d ? d + '일 ' : '') + p(h) + ':' + p(m) + ':' + p(s); }
    setInterval(tick, 1000); tick(); })();
  </script>
<?php else: $live = $c['state'] === 'open'; ?>
  <div class="sfpc-arena-h"><span class="tag"><?= $live ? 'CONTEST RUNNING' : 'CONTEST OVER' ?></span><h1><?= $live ? '도전하기' : '대회가 끝났습니다' ?></h1>
    <p><?= $team ? '우리 팀: 맞힌 문제 <b>' . (int)$sc['solved'] . '</b>개 · 벌점 <b>' . (int)$sc['penalty'] . '</b>분' : h($fmt($c['from'])) . ' ~ ' . h(date('H:i', $c['to'])) ?>
      <?= $live && sfpc_board_frozen() ? ' · 스코어보드는 얼어 있습니다' : '' ?></p></div>
  <?php if (!$probs): ?><div class="sfpc-world-empty">문제가 아직 담기지 않았습니다.</div><?php endif; ?>
  <ol class="sfpc-plist">
    <?php foreach ($probs as $i => $p): $x = $sc['cells'][(int)$p['id']] ?? null; $can = $live || !$team; ?>
      <li class="<?= $x && $x['ok'] ? 'ok' : ($x && $x['tries'] ? 'bad' : '') ?>">
        <<?= $can ? 'a href="problem.php?no=' . (int)$p['prob_no'] . '&amp;set=' . $setId . '"' : 'span class="a"' ?>>
          <span class="l"><?= sfpc_letter($i) ?></span><span class="t"><?= h($p['title']) ?></span>
          <span class="s"><?= $x && $x['ok'] ? '맞음 · ' . (int)$x['min'] . '분' . ($x['tries'] ? ' · 오답 ' . (int)$x['tries'] : '') : ($x && $x['pend'] ? '채점 중' : ($x && $x['tries'] ? '오답 ' . (int)$x['tries'] . '번' : ($live ? '풀기 →' : ''))) ?></span>
        </<?= $can ? 'a' : 'span' ?>></li>
    <?php endforeach; ?>
  </ol>
  <p class="sfpc-world-help"><a href="sfpc_board.php">스코어보드 보기 →</a></p>
<?php endif; ?>
</div>
<?php page_foot();
