<?php
/* ═══════════════════════════════════════════════
   sfpc_teams.php — 내 팀. 지도교사가 신청한 팀을 보고, 고치고, 취소한다.

   ?coach=계정번호 — 운영진이 그 지도교사의 화면을 본다 (보기만 한다. 버튼은 나오지 않는다).
   "제 화면에 팀이 안 보여요" 같은 문의에 답할 때, 비밀번호를 묻지 않고 확인하려는 것이다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
sfpc_coach_only($u);
/* 운영진이 다른 지도교사의 화면을 보는 경우 */
$viewAs = is_admin($u) ? (int)($_GET['coach'] ?? 0) : 0;
if ($viewAs) {
  $coach = one("SELECT * FROM sfpc_coaches WHERE user_id = ?", [$viewAs]);
  if (!$coach) { header('Location: admin/sfpc.php?tab=coaches'); exit; }
} else {
  $coach = sfpc_coach($u);
  if (!$coach) { header('Location: sfpc_join.php?next=sfpc_teams.php'); exit; }
}
$ownerId = $viewAs ?: (int)$u['id'];

/* 보는 중에는 아무것도 바꾸지 못한다. 그 교사에게 버튼이 보일지는 접수 기간으로만 정해진다. */
$canEdit = $viewAs ? false : sfpc_can_edit($u);
$wouldEdit = sfpc_apply_state() === 'open';

/* 취소 · 취소 철회 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewAs) {
  $t   = sfpc_team((int)($_POST['id'] ?? 0));
  $act = (string)($_POST['act'] ?? '');
  if (!sfpc_csrf_ok())                                   redirect_with('', '화면을 연 지 오래되었습니다. 다시 시도해 주세요.');
  if (!$t || (int)$t['coach_user_id'] !== (int)$u['id']) redirect_with('', '팀을 찾지 못했습니다.');
  /* 팀 계정 비밀번호 — 접수 기간과 상관없이, 운영진이 계정을 공개한 뒤에 쓸 수 있다 */
  if ($act === 'pw' || $act === 'pw_random') {
    if (!sfpc_account_open())        redirect_with('', '아직 팀 계정을 나눠 드리기 전입니다.');
    if ($t['status'] !== 'submitted') redirect_with('', '접수가 취소된 팀은 계정을 쓸 수 없습니다.');
    $pw = $act === 'pw_random' ? sfpc_random_pw() : (string)($_POST['pw'] ?? '');
    if ($act === 'pw' && $pw !== (string)($_POST['pw2'] ?? '')) redirect_with('', '비밀번호를 두 번 똑같이 입력해 주세요.');
    $e = sfpc_account_set_pw($t, $pw, $u);
    if ($e !== '') redirect_with('', $e);
    /* 무작위 비밀번호는 그 팀 칸 안에 한 번만 크게 보여 준다 (다음 화면에서 꺼내고 지운다) */
    if ($act === 'pw_random') $_SESSION['sfpc_pw_once'] = ['team' => (int)$t['id'], 'pw' => $pw];
    $_SESSION['flash'] = ['msg' => '"' . $t['team_name'] . '" 팀 계정 ' . sfpc_account_id($t)
      . ($act === 'pw_random' ? ' 의 새 비밀번호를 만들었습니다. 아래 팀 칸에 표시된 비밀번호를 지금 적어 두세요.' : ' 의 비밀번호를 정했습니다.'), 'err' => ''];
    header('Location: sfpc_teams.php#team-' . (int)$t['id']); exit;
  }
  if (!$canEdit)                                         redirect_with('', '접수 기간이 아니어서 바꿀 수 없습니다.');
  if ($act === 'cancel' && $t['status'] === 'submitted') {
    db()->prepare("UPDATE sfpc_teams SET status = 'canceled', updated_at = ? WHERE id = ?")->execute([now(), $t['id']]);
    sfpc_log((int)$t['id'], (int)$u['id'], $u, 'cancel', '지도교사가 신청을 취소');
    sfpc_account_sync((int)$t['id']);
    redirect_with('"' . $t['team_name'] . '" 팀 신청을 취소했습니다.');
  }
  if ($act === 'restore' && $t['status'] === 'canceled') {
    db()->prepare("UPDATE sfpc_teams SET status = 'submitted', updated_at = ? WHERE id = ?")->execute([now(), $t['id']]);
    sfpc_log((int)$t['id'], (int)$u['id'], $u, 'restore', '지도교사가 다시 접수');
    sfpc_account_sync((int)$t['id']);
    redirect_with('"' . $t['team_name'] . '" 팀을 다시 접수했습니다.');
  }
  redirect_with('', '처리할 수 없는 요청입니다.');
}

[$msg, $err] = flash_take();
$pwOnce = $_SESSION['sfpc_pw_once'] ?? null; unset($_SESSION['sfpc_pw_once']);
$teams = sfpc_teams_of($ownerId);
$state = sfpc_apply_state();
$accOpen = sfpc_account_open();

page_head(['title' => '내 팀', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<?php if ($viewAs): ?>
  <div class="sfpc-pvbar"><b><?= h($coach['name']) ?> 선생님의 화면 · 보기 전용</b>
    <span>이 선생님이 로그인하면 보는 "내 팀" 화면입니다.
      <?= $wouldEdit ? '지금은 접수 기간이라 선생님 화면에는 팀마다 수정 · 신청 취소 버튼이 함께 보입니다.' : '지금은 접수 기간이 아니라 선생님 화면에도 수정 버튼이 없습니다.' ?>
      <a href="admin/sfpc.php?tab=coaches&q=<?= rawurlencode((string)col("SELECT login_id FROM users WHERE id = ?", [$viewAs])) ?>">지도교사 관리로 돌아가기</a></span></div>
<?php endif; ?>
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

  <?php if ($accOpen && $teams): ?>
    <div class="note info">팀 계정이 준비되었습니다. 팀마다 아래 "팀 계정"에서 비밀번호를 정한 뒤, 아이디와 비밀번호를 팀원에게 알려 주세요.
      팀원은 <a href="login.php">로그인</a> 화면에서 그 계정으로 들어옵니다.</div>
  <?php endif; ?>

  <div class="sfpc-ro">
    <span>신청인 <b><?= h($coach['name']) ?></b></span>
    <span>연락처 <b><?= h($coach['phone']) ?></b></span>
    <?php if ($coach['email'] !== ''): ?><span><?= h($coach['email']) ?></span><?php endif; ?>
    <?php if (!$viewAs): ?><a class="btn sm" href="sfpc_join.php?next=sfpc_teams.php">내 정보 수정</a><?php endif; ?>
  </div>

  <?php if (!$teams): ?>
    <div class="empty">아직 신청한 팀이 없습니다.</div>
  <?php else: foreach ($teams as $t):
    $members = sfpc_team_members((int)$t['id']);
    $off = $t['status'] !== 'submitted'; ?>
    <div id="team-<?= (int)$t['id'] ?>" class="sfpc-team<?= $off ? ' off' : '' ?>">
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
      <?php if ($accOpen && $t['status'] === 'submitted'): $hasPw = ($t['account_pw_at'] ?? '') !== ''; ?>
        <div class="sfpc-acc">
          <div class="sfpc-acc-h">
            <span class="lab">팀 계정</span>
            <code class="id"><?= h(sfpc_account_id($t)) ?></code>
            <?php if ($hasPw): ?><span class="st ok">비밀번호 정함 · <?= h(substr((string)$t['account_pw_at'], 5, 11)) ?></span>
            <?php else: ?><span class="st no">비밀번호를 아직 정하지 않았습니다</span><?php endif; ?>
          </div>
          <?php if (!$viewAs && $pwOnce && (int)$pwOnce['team'] === (int)$t['id']): ?>
            <div class="sfpc-pwonce" role="status">
              <span class="cap">새 비밀번호</span>
              <code id="pwOnce"><?= h($pwOnce['pw']) ?></code>
              <button type="button" class="btn sm" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('pwOnce').textContent).then(function(){ this.textContent='복사했습니다'; }.bind(this))">복사</button>
              <p>지금 적어 두세요. 이 화면을 벗어나거나 새로 고치면 다시 볼 수 없습니다. 팀원에게 아이디 <b><?= h(sfpc_account_id($t)) ?></b> 와 이 비밀번호를 알려 주세요.</p>
            </div>
          <?php endif; ?>
          <?php if (!$viewAs): ?>
          <details<?= $hasPw ? '' : ' open' ?>>
            <summary><?= $hasPw ? '비밀번호 바꾸기' : '비밀번호 정하기' ?></summary>
            <form method="post" class="sfpc-acc-f" autocomplete="off">
              <?= sfpc_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="password" name="pw" placeholder="새 비밀번호 (<?= SFPC_PW_MIN ?>자 이상)" minlength="<?= SFPC_PW_MIN ?>" maxlength="72" autocomplete="new-password" aria-label="새 비밀번호">
              <input type="password" name="pw2" placeholder="한 번 더" maxlength="72" autocomplete="new-password" aria-label="새 비밀번호 확인">
              <button class="btn sm primary" name="act" value="pw">이 비밀번호로 정하기</button>
              <span class="or">또는</span>
              <button class="btn sm" name="act" value="pw_random" formnovalidate
                      onclick="return confirm('무작위 비밀번호를 새로 만들까요?<?= $hasPw ? '\n지금 쓰는 비밀번호는 더 쓸 수 없게 됩니다.' : '' ?>')">무작위로 만들기</button>
            </form>
            <p class="hint">팀원들이 이 아이디와 비밀번호로 함께 로그인합니다. 비밀번호는 저장된 것을 다시 볼 수 없으니, 잊으면 새로 정해 주세요.</p>
          </details>
          <?php endif; ?>
        </div>
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
