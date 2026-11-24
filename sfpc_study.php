<?php
/* ═══════════════════════════════════════════════
   sfpc_study.php — 공부하기. 기초 문제를 차례로 푸는 곳.

   문제와 채점은 KAIT-CLASS 의 수업 묶음을 그대로 쓴다 (sfpc_lib.php 의 "공부하기").
   팀 계정이 모두 맞히면 수료되고, 수료한 중학교 팀은 정식참가자가 된다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();

page_head(['title' => '공부하기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
if (sfpc_gate('sfpc_study_open', '공부하기', $u)):
  $set   = sfpc_study_set();
  $probs = sfpc_study_problems();
  $skip  = sfpc_study_skip();
  $team  = sfpc_my_team($u);
  $done  = $team ? sfpc_study_check($team) : false;
  if ($team && $done) $team = sfpc_team((int)$team['id']);
  $solved = $u ? sfpc_study_solved((int)$u['id']) : [];
  $need   = array_diff(array_map('intval', array_column($probs, 'id')), $skip);
  $got    = count(array_intersect($need, $solved));
  $state  = set_state($set);
  $pct    = $need ? (int)floor($got * 100 / count($need)) : 0;
?>
<div class="wrap narrow">
  <div class="phead"><h1>공부하기</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?><?= period_text($set) !== '' ? ' · ' . h(period_text($set)) : '' ?></span></div>

  <p class="sfpc-st-lead">코딩이 처음이어도 괜찮습니다. 쉬운 문제부터 차례로 풀어 보세요.
    <b>모든 문제를 맞히면 수료</b>이고, 중학교 팀은 수료해야 정식참가자로 도전하기(본 대회)에 나갑니다.</p>

  <?php if ($state === 'before'): ?><div class="note info">아직 시작 전입니다. <?= h(period_text($set)) ?></div>
  <?php elseif ($state === 'after'): ?><div class="note info">공부하기 기간이 끝났습니다. 문제는 볼 수 있지만 제출할 수 없습니다.</div><?php endif; ?>

  <?php if (!$u): ?>
    <div class="note info">문제를 풀려면 <a href="login.php?next=<?= rawurlencode('sfpc_study.php') ?>"><b>팀 계정으로 로그인</b></a>해 주세요. 팀 계정은 지도 선생님께 받습니다.</div>
  <?php elseif ($team): ?>
    <div class="sfpc-st-prog<?= $done ? ' done' : '' ?>">
      <div class="top"><b><?= h($team['team_name']) ?></b>
        <?php if ($done): ?><span class="badge ok">수료</span><span class="small"> <?= h(substr($team['study_done_at'], 0, 16)) ?></span>
        <?php else: ?><span class="small">수료까지 <?= count($need) - $got ?>문제</span><?php endif; ?>
        <span class="n"><?= $got ?> / <?= count($need) ?></span></div>
      <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
      <?php if ($done && $team['category'] === 'middle'): ?><p class="small" style="margin:8px 0 0">수료했습니다. 참가 자격: <b><?= h(SFPC_TRACK_NAME[$team['track']] ?? '') ?></b></p><?php endif; ?>
    </div>
  <?php elseif (is_admin($u)): ?>
    <div class="note admin-preview">운영진 계정입니다. 문제를 풀어 볼 수 있지만 수료는 팀 계정에만 있습니다. <a href="admin/sfpc.php?tab=study">운영 &gt; SFPC &gt; 공부하기</a>에서 문제와 팀별 진행을 봅니다.</div>
  <?php else: ?>
    <div class="note info">지도교사 계정으로 보고 계십니다. 문제를 풀어 볼 수는 있지만, <b>수료는 팀 계정으로 푼 것만</b> 인정됩니다.</div>
  <?php endif; ?>

  <?php if (!$probs): ?>
    <div class="empty">문제를 준비하고 있습니다. 곧 올라옵니다.</div>
  <?php else: ?>
    <ol class="sfpc-st-list">
      <?php foreach ($probs as $i => $p): $ok = in_array((int)$p['id'], $solved, true); $off = in_array((int)$p['id'], $skip, true); ?>
        <li class="<?= $ok ? 'ok' : '' ?>">
          <a href="<?= $u ? 'problem.php?no=' . (int)$p['prob_no'] . '&amp;set=' . (int)$set['id'] : 'login.php?next=' . rawurlencode('sfpc_study.php') ?>">
            <span class="no"><?= $i + 1 ?></span>
            <span class="t"><?= h($p['title']) ?><?= $off ? ' <em>수료 조건에서 빠진 문제</em>' : '' ?></span>
            <span class="s"><?= $ok ? '맞았습니다' : ($u ? '풀기 →' : '') ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
<?php
endif;
page_foot();
