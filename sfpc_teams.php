<?php
/* ═══════════════════════════════════════════════
   sfpc_teams.php — 내 팀. 지도교사가 신청한 팀을 보고, 고치고, 취소한다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$coach = sfpc_coach($u);
if (!$coach) { header('Location: sfpc_join.php?next=sfpc_teams.php'); exit; }

$canEdit = sfpc_can_edit($u);

/* 취소 · 취소 철회 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $t   = sfpc_team((int)($_POST['id'] ?? 0));
  $act = (string)($_POST['act'] ?? '');
  if (!sfpc_csrf_ok())                                   redirect_with('', '화면을 연 지 오래되었습니다. 다시 시도해 주세요.');
  if (!$t || (int)$t['coach_user_id'] !== (int)$u['id']) redirect_with('', '팀을 찾지 못했습니다.');
  if (!$canEdit)                                         redirect_with('', '접수 기간이 아니어서 바꿀 수 없습니다.');
  if ($act === 'cancel' && $t['status'] === 'submitted') {
    db()->prepare("UPDATE sfpc_teams SET status = 'canceled', updated_at = ? WHERE id = ?")->execute([now(), $t['id']]);
    redirect_with('"' . $t['team_name'] . '" 팀 신청을 취소했습니다.');
  }
  if ($act === 'restore' && $t['status'] === 'canceled') {
    db()->prepare("UPDATE sfpc_teams SET status = 'submitted', updated_at = ? WHERE id = ?")->execute([now(), $t['id']]);
    redirect_with('"' . $t['team_name'] . '" 팀을 다시 접수했습니다.');
  }
  redirect_with('', '처리할 수 없는 요청입니다.');
}

[$msg, $err] = flash_take();
$teams = sfpc_teams_of((int)$u['id']);
$state = sfpc_apply_state();

page_head(['title' => '내 팀', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<div class="wrap narrow">
  <div class="phead">
    <h1>내 팀</h1>
    <span class="sub"><?= h(sfpc_setting('sfpc_title')) ?> · 접수 <?= h(sfpc_period_text()) ?></span>
  </div>

  <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>
  <?php if ($state === 'closed'): ?>
    <div class="note info">접수가 마감되어 내용을 고칠 수 없습니다. 바꿔야 할 것이 있으면 운영진에게 문의해 주세요.</div>
  <?php elseif ($state === 'before'): ?>
    <div class="note info">아직 접수 기간이 아닙니다.</div>
  <?php endif; ?>

  <div class="sfpc-ro">
    <span>신청인 <b><?= h($coach['name']) ?></b></span>
    <span>연락처 <b><?= h($coach['phone']) ?></b></span>
    <?php if ($coach['email'] !== ''): ?><span><?= h($coach['email']) ?></span><?php endif; ?>
    <a class="btn sm" href="sfpc_join.php?next=sfpc_teams.php">내 정보 수정</a>
  </div>

  <?php if (!$teams): ?>
    <div class="empty">아직 신청한 팀이 없습니다.</div>
  <?php else: foreach ($teams as $t):
    $members = sfpc_team_members((int)$t['id']);
    $off = $t['status'] !== 'submitted'; ?>
    <div class="sfpc-team<?= $off ? ' off' : '' ?>">
      <div class="sfpc-team-h">
        <h2><?= h($t['team_name']) ?></h2>
        <?= sfpc_status_badge($t) ?>
        <span class="sfpc-no" title="접수 번호"><?= h(sfpc_team_no($t)) ?></span>
        <span class="grow"></span>
        <?php if ($canEdit && $t['status'] === 'submitted'): ?>
          <a class="btn sm" href="sfpc_team.php?id=<?= (int)$t['id'] ?>">수정</a>
          <form method="post" onsubmit="return confirm('이 팀의 신청을 취소할까요?\n접수 기간에는 다시 접수할 수 있습니다.')">
            <?= sfpc_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn sm danger" name="act" value="cancel">신청 취소</button>
          </form>
        <?php elseif ($canEdit && $t['status'] === 'canceled'): ?>
          <form method="post">
            <?= sfpc_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="btn sm" name="act" value="restore">다시 접수</button>
          </form>
        <?php endif; ?>
      </div>
      <p class="meta">
        <?= h($t['region']) ?> · <?= h(SFPC_CATS[$t['category']]['label'] ?? $t['category']) ?> · <?= h($t['school_name']) ?>
        · <?= h(SFPC_TRACK_NAME[$t['track']] ?? '') ?>
        · <?= (int)$t['onsite'] ? '현장 참가' : '온라인 참가' ?>
      </p>
      <p class="mem">
        <?php foreach ($members as $m): ?>
          <span><?= h($m['name']) ?><?= $m['grade'] ? ' <span class="muted small">' . (int)$m['grade'] . '학년</span>' : '' ?><?= $m['shirt'] !== '' ? ' <span class="muted small">' . h($m['shirt']) . '</span>' : '' ?></span>
        <?php endforeach; ?>
      </p>
      <?php if ($t['status'] === 'rejected' && $t['admin_memo'] !== ''): ?>
        <p class="meta bad-text">반려 사유: <?= h($t['admin_memo']) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

  <?php if ($canEdit): ?>
    <div class="btnrow" style="margin-top:18px">
      <a class="btn primary" href="sfpc_team.php?kind=student">학생 팀 추가</a>
      <a class="btn" href="sfpc_team.php?kind=teacher">교사 · 예비교사 팀 추가</a>
    </div>
  <?php endif; ?>
</div>
<?php page_foot(); ?>
