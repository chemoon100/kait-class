<?php
/* ═══════════════════════════════════════════════
   sfpc_me.php — 우리 팀. 팀 계정으로 로그인하면 처음 오는 화면.

   팀 정보와 대회 일정을 보여 주고, 캐릭터 꾸미기 · 대회 참가 · 스코어보드로 이어진다.
   ?team=팀번호 — 운영진이 그 팀의 화면을 본다 (보기 전용).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();

$viewAs = is_admin($u) ? (int)($_GET['team'] ?? 0) : 0;
$team = $viewAs ? sfpc_team($viewAs) : sfpc_my_team($u);
if (!$team) {
  /* 팀 계정이 아닌 사람 — 지도교사는 내 팀으로, 그 밖에는 접수 안내로 */
  header('Location: ' . ($viewAs || is_admin($u) ? 'admin/sfpc.php?tab=teams' : (sfpc_coach($u) ? 'sfpc_teams.php' : 'sfpc.php')));
  exit;
}
$members = sfpc_team_members((int)$team['id']);
$coach   = one("SELECT name FROM sfpc_coaches WHERE user_id = ?", [$team['coach_user_id']]);
$off     = $team['status'] !== 'submitted';
$sched   = trim(sfpc_setting('sfpc_schedule'));

page_head(['title' => '우리 팀', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<?php if ($viewAs): ?>
  <div class="sfpc-pvbar"><b><?= h($team['team_name']) ?> 팀의 화면 · 보기 전용</b>
    <span>이 팀이 팀 계정(<?= h(sfpc_account_id($team)) ?>)으로 로그인하면 보는 화면입니다.
      <a href="admin/sfpc.php?tab=teams&q=<?= rawurlencode($team['team_name']) ?>">팀 목록으로 돌아가기</a></span></div>
<?php endif; ?>
<div class="wrap narrow">
  <div class="sfpc-me-h">
    <div class="who">
      <p class="tag"><?= h(sfpc_setting('sfpc_title')) ?></p>
      <h1><?= h($team['team_name']) ?></h1>
      <p class="sub"><?= h($team['school_name']) ?> · <?= h($team['region']) ?> · <?= h(SFPC_CATS[$team['category']]['label'] ?? '') ?>
        · <?= (int)$team['onsite'] ? '현장 참가' : '온라인 참가' ?></p>
    </div>
    <div class="ids">
      <span>팀 계정 <code><?= h(sfpc_account_id($team)) ?></code></span>
      <span>접수 번호 <code><?= h(sfpc_team_no($team)) ?></code></span>
    </div>
  </div>

  <?php if ($off): ?>
    <div class="note err">이 팀은 접수가 <?= h(SFPC_STATUS_NAME[$team['status']] ?? '취소') ?> 상태여서 대회에 참가할 수 없습니다. 지도 선생님께 확인해 주세요.</div>
  <?php else: ?>

  <div class="sfpc-me-grid">
    <section class="sfpc-me-card char">
      <h2>우리 팀 캐릭터</h2>
      <div class="ph" aria-hidden="true"><span></span></div>
      <p>대회 화면과 스코어보드에서 우리 팀을 나타낼 캐릭터를 꾸밉니다.</p>
      <span class="soon">곧 열립니다</span>
    </section>

    <section class="sfpc-me-card">
      <h2>팀원</h2>
      <ul class="mem">
        <?php foreach ($members as $m): ?>
          <li><?= h($m['name']) ?><?= $m['grade'] ? ' <span class="muted small">' . (int)$m['grade'] . '학년</span>' : '' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($coach): ?><p class="small muted" style="margin:10px 0 0">지도교사 <?= h($coach['name']) ?> 선생님</p><?php endif; ?>
      <p class="small muted" style="margin:6px 0 0">팀원이나 팀 이름을 바꾸려면 지도 선생님께 말씀드리세요.</p>
    </section>
  </div>

  <div class="sfpc-me-links">
    <a href="sfpc_contest.php"><b>대회 참가</b><span><?= sfpc_setting('sfpc_contest_open') === '1' ? '문제 풀러 가기' : '준비 중입니다' ?></span></a>
    <a href="sfpc_board.php"><b>스코어보드</b><span><?= sfpc_setting('sfpc_board_open') === '1' ? '순위 보기' : '준비 중입니다' ?></span></a>
  </div>

  <?php if ($sched !== ''): ?>
    <section class="sfpc-me-card" style="margin-top:14px"><h2>대회 일정</h2><div class="md"><?= md($sched) ?></div></section>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php page_foot(); ?>
