<?php
/* ═══════════════════════════════════════════════
   sfpc_team.php — 팀 신청 양식 (새로 내기 · 고치기)

     ?kind=student | teacher   새 팀
     ?id=N                     내 팀 고치기 (운영진은 모든 팀, 접수 기간이 아니어도)
     ?kind=…&coach=계정번호     운영진이 그 지도교사 이름으로 새 팀을 넣는다 (마감 뒤 누락 접수)

   운영진이 남의 팀을 다룰 때는 개인정보 동의 칸이 나오지 않는다 — 동의는 지도교사가 하는 것이다.
   새로 내기 · 고치기는 모두 변경 기록(sfpc_log)에 남는다.

   학교는 sfpc_schools 목록에서 찾아 고른다. 목록에 없으면 직접 적고,
   그런 팀은 school_manual = 1 로 남겨 운영진이 확인할 수 있게 한다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
sfpc_coach_only($u);
$coach = sfpc_coach($u);

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$team = $id ? sfpc_team($id) : null;
if ($id && (!$team || ((int)$team['coach_user_id'] !== (int)$u['id'] && !is_admin($u)))) {
  header('Location: sfpc_teams.php'); exit;
}
$kind = $team ? $team['kind'] : (string)($_GET['kind'] ?? $_POST['kind'] ?? '');
if (!isset(SFPC_KIND_NAME[$kind])) { header('Location: sfpc.php'); exit; }

/* 운영진이 지도교사를 대신해 새 팀을 넣는 경우 */
$forCoach = (!$team && is_admin($u)) ? (int)($_GET['coach'] ?? $_POST['coach'] ?? 0) : 0;
if ($forCoach && !one("SELECT 1 FROM sfpc_coaches WHERE user_id = ?", [$forCoach])) {
  header('Location: admin/sfpc.php?tab=coaches'); exit;
}
if (!$team && !$coach && !$forCoach) {
  header('Location: sfpc_join.php?next=' . rawurlencode('sfpc_team.php?kind=' . $kind)); exit;
}
/* 이 팀의 지도교사 — 내 팀이면 나, 운영진이 다루는 남의 팀이면 그 사람 */
$ownerId = $team ? (int)$team['coach_user_id'] : ($forCoach ?: (int)$u['id']);
$owner   = one("SELECT * FROM sfpc_coaches WHERE user_id = ?", [$ownerId]);
$byAdmin = is_admin($u) && $ownerId !== (int)$u['id'];
$back    = $byAdmin ? 'admin/sfpc.php?tab=teams' : 'sfpc_teams.php';

if (!sfpc_can_edit($u)) { header('Location: sfpc_teams.php'); exit; }
if ($team && $team['status'] === 'canceled' && !is_admin($u)) { header('Location: sfpc_teams.php'); exit; }

[$minM, $maxM] = SFPC_MEMBERS[$kind];
$cats       = array_filter(SFPC_CATS, fn($c) => $c['kind'] === $kind);
$host       = sfpc_setting('sfpc_host_region');
$hasSchools = sfpc_school_count() > 0;

/* ── 화면에 채울 값 ── */
$in = [
  'region'      => $team['region'] ?? '',
  'category'    => $team['category'] ?? '',
  'school_id'   => (int)($team['school_id'] ?? 0),
  'school_name' => $team['school_name'] ?? '',
  'manual'      => $team ? (int)$team['school_manual'] === 1 : false,
  'team_name'   => $team['team_name'] ?? '',
  'way'         => $team ? ((int)$team['onsite'] ? 'off' : 'on') : '',   /* 참가 방식: 'off' 오프라인(대회장) · 'on' 온라인 */
  'members'     => [],
];
if ($team) {
  foreach (sfpc_team_members((int)$team['id']) as $m)
    $in['members'][] = ['name' => $m['name'], 'grade' => (int)$m['grade'], 'shirt' => $m['shirt']];
} elseif ($kind === 'teacher' && $owner) {
  $in['members'][] = ['name' => $owner['name'], 'grade' => 0, 'shirt' => ''];   /* 신청인이 첫 팀원인 경우가 대부분 */
}

$errs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in['region']      = (string)($_POST['region'] ?? '');
  $in['category']    = (string)($_POST['category'] ?? '');
  $in['school_id']   = (int)($_POST['school_id'] ?? 0);
  $in['school_name'] = sfpc_clean((string)($_POST['school_name'] ?? ''), 40);
  $in['manual']      = !empty($_POST['school_manual']);
  $in['team_name']   = sfpc_clean((string)($_POST['team_name'] ?? ''), 20);
  $in['way']         = (string)($_POST['way'] ?? '');
  $names  = (array)($_POST['m_name'] ?? []);
  $grades = (array)($_POST['m_grade'] ?? []);
  $shirts = (array)($_POST['m_shirt'] ?? []);
  $in['members'] = [];
  for ($i = 0; $i < $maxM; $i++) {
    $in['members'][] = [
      'name'  => sfpc_clean((string)($names[$i] ?? ''), 20),
      'grade' => (int)($grades[$i] ?? 0),
      'shirt' => (string)($shirts[$i] ?? ''),
    ];
  }

  if (!sfpc_csrf_ok()) $errs[] = '화면을 연 지 오래되었습니다. 다시 저장해 주세요.';
  if (!in_array($in['region'], SFPC_REGIONS, true)) $errs[] = '지역을 고르세요.';
  if (!isset($cats[$in['category']]))               $errs[] = '참가 구분을 고르세요.';
  $cat    = $cats[$in['category']] ?? null;
  /* 온라인 / 오프라인은 개최 지역의 학생 팀만 고른다. 나머지는 언제나 온라인이고 티셔츠도 없다. */
  $canOn  = sfpc_can_onsite($kind, $in['region']);
  if ($canOn && !in_array($in['way'], ['on', 'off'], true)) $errs[] = '참가 방식(온라인 · 오프라인)을 고르세요.';
  $onsite = $canOn && $in['way'] === 'off';
  $in['onsite'] = $onsite ? 1 : 0;

  /* 학교 — 목록에서 골랐으면 그 학교의 이름을 쓴다 (화면에서 바꿔 보낸 이름은 믿지 않는다) */
  $school = null;
  if ($cat && $cat['level'] !== '' && $in['school_id'] > 0) {
    $school = one("SELECT * FROM sfpc_schools WHERE id = ?", [$in['school_id']]);
    if (!$school) {
      $errs[] = '고른 학교를 찾지 못했습니다. 다시 찾아 주세요.';
    } elseif ($school['region'] !== $in['region']) {
      $errs[] = '고른 학교(' . $school['name'] . ')는 ' . $school['region'] . ' 지역입니다. 지역을 다시 확인해 주세요.';
    } elseif ($cat['level'] !== 'any' && $school['level'] !== $cat['level']) {
      $errs[] = '참가 구분과 학교급이 맞지 않습니다. 학교를 다시 찾아 주세요.';
    } else {
      $in['school_name'] = $school['name'];
    }
  } else {
    $in['school_id'] = 0;
    if (ustrlen($in['school_name']) < 2)
      $errs[] = ($in['category'] === 'preteacher' ? '대학교' : '학교') . ' 이름을 입력하세요.';
  }

  if (ustrlen($in['team_name']) < 2) $errs[] = '팀명을 2자 이상 입력하세요.';

  /* 팀원 — 이름이 있는 줄만 팀원으로 본다 */
  $members = array_values(array_filter($in['members'], fn($m) => $m['name'] !== ''));
  if (count($members) < $minM) $errs[] = '팀원을 ' . $minM . '명 이상 입력하세요.';
  foreach ($members as $i => $m) {
    $who = '팀원 ' . ($i + 1) . '(' . $m['name'] . ')';
    if (ustrlen($m['name']) < 2) $errs[] = $who . '의 이름을 확인해 주세요.';
    if ($kind === 'student' && ($m['grade'] < 1 || $m['grade'] > 3)) $errs[] = $who . '의 학년을 고르세요.';
    if ($onsite && !in_array($m['shirt'], SFPC_SHIRTS, true)) $errs[] = $who . '의 상의 사이즈를 고르세요.';
  }
  $nameList = array_column($members, 'name');
  if (count($nameList) !== count(array_unique($nameList))) $errs[] = '같은 이름의 팀원이 두 번 들어갔습니다. 동명이인이면 이름 뒤에 A, B 를 붙여 주세요.';

  if (!$byAdmin && empty($_POST['agree'])) $errs[] = '개인정보 수집 · 이용에 동의해야 신청할 수 있습니다.';

  /* 같은 학교에 같은 팀명이 있으면 스코어보드에서 구분되지 않는다 */
  if (!$errs && one("SELECT 1 FROM sfpc_teams WHERE contest = ? AND status = 'submitted' AND school_name = ?
                     AND team_name = ? AND id <> ?", [SFPC_CONTEST, $in['school_name'], $in['team_name'], $id])) {
    $errs[] = '같은 학교에 같은 이름의 팀이 이미 있습니다. 팀명을 바꿔 주세요.';
  }

  if (!$errs) {
    $manual = $in['school_id'] > 0 ? 0 : 1;
    $oldMembers = $team ? sfpc_team_members((int)$team['id']) : [];
    $newId = tx(function (PDO $d) use ($team, $ownerId, $byAdmin, $kind, $in, $cat, $onsite, $canOn, $manual, $members) {
      /* 동의한 때 — 지도교사가 저장할 때만 새로 적는다. 운영진이 고칠 때는 그대로 두고,
         운영진이 대신 넣은 팀은 비워 둔다 (동의를 화면에서 받지 않았다는 표시). */
      if ($team) {
        /* 참가 구분이 바뀌었을 때만 참가 자격을 처음 값으로 되돌린다 (운영진이 바꾼 값을 지키려고) */
        $track = $team['category'] === $in['category'] ? $team['track'] : $cat['track'];
        $d->prepare("UPDATE sfpc_teams SET category=?, region=?, school_id=?, school_name=?, school_manual=?,
                       team_name=?, track=?, onsite=?, consent_at=?, updated_at=? WHERE id=?")
          ->execute([$in['category'], $in['region'], $in['school_id'] ?: null, $in['school_name'], $manual,
                     $in['team_name'], $track, $onsite ? 1 : 0, $byAdmin ? $team['consent_at'] : now(), now(), $team['id']]);
        $tid = (int)$team['id'];
        $d->prepare("DELETE FROM sfpc_members WHERE team_id = ?")->execute([$tid]);
      } else {
        $d->prepare("INSERT INTO sfpc_teams(contest, coach_user_id, kind, category, region, school_id, school_name,
                       school_manual, team_name, track, onsite, status, consent_at, created_at, updated_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,'submitted',?,?,?)")
          ->execute([SFPC_CONTEST, $ownerId, $kind, $in['category'], $in['region'], $in['school_id'] ?: null,
                     $in['school_name'], $manual, $in['team_name'], $cat['track'], $onsite ? 1 : 0, $byAdmin ? '' : now(), now(), now()]);
        $tid = (int)$d->lastInsertId();
      }
      $ins = $d->prepare("INSERT INTO sfpc_members(team_id, seq, name, grade, shirt) VALUES(?,?,?,?,?)");
      foreach ($members as $i => $m) {
        /* 사이즈는 오프라인일 때만 쓰지만, 온라인으로 바꿔도 적어 둔 값은 남긴다 (다시 오프라인으로 돌아오면 그대로) */
        $ins->execute([$tid, $i + 1, $m['name'], $kind === 'student' ? $m['grade'] : null, ($canOn && in_array($m['shirt'], SFPC_SHIRTS, true)) ? $m['shirt'] : '']);
      }
      return $tid;
    });
    /* 변경 기록 */
    $newMembers = array_map(fn($m) => ['name' => $m['name'], 'grade' => $kind === 'student' ? $m['grade'] : 0,
                                       'shirt' => $onsite ? $m['shirt'] : ''], $members);
    if ($team) {
      if (!(int)$team['onsite']) foreach ($oldMembers as &$om) $om['shirt'] = '';
      unset($om);
      $diff = sfpc_team_diff($team, $oldMembers, $in, $newMembers);
      if ($onsite && !(int)$team['onsite'] && sfpc_shirt_closed()) $diff .= "\n(티셔츠 조사 마감 뒤에 오프라인으로 바꿈)";
      if ($diff !== '') sfpc_log((int)$team['id'], $ownerId, $u, 'edit', $diff);
      sfpc_account_sync((int)$team['id']);   /* 팀 이름이 바뀌면 계정에 보이는 이름도 */
    } else {
      sfpc_log((int)$newId, $ownerId, $u, 'create',
        ($byAdmin ? "운영진이 대신 접수\n" : '') . $in['region'] . ' · ' . $cat['label'] . ' · ' . $in['school_name'] . ' · ' . $in['team_name'] . ' · ' . sfpc_way_name($in)
        . (($onsite && sfpc_shirt_closed()) ? ' (티셔츠 조사 마감 뒤)' : '')
        . "\n팀원: " . implode(', ', array_map('sfpc_member_text', $newMembers)));
    }
    $_SESSION['flash'] = ['msg' => '"' . $in['team_name'] . '" 팀 ' . ($team ? '내용을 고쳤습니다.' : '신청을 접수했습니다.'), 'err' => ''];
    header('Location: ' . $back);
    exit;
  }
}

while (count($in['members']) < $maxM) $in['members'][] = ['name' => '', 'grade' => 0, 'shirt' => ''];
$isPre    = $in['category'] === 'preteacher';
$canOnUi  = sfpc_can_onsite($kind, $in['region']);
$onsiteUi = $canOnUi && $in['way'] === 'off';
$shirtLate = sfpc_shirt_closed() && !($team && (int)$team['onsite']);
$picked   = $in['school_id'] > 0 && !$isPre;
$privacy  = trim(sfpc_setting('sfpc_privacy'));

page_head(['title' => SFPC_KIND_NAME[$kind] . ($team ? ' 수정' : ' 신청'), 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<div class="wrap narrow">
  <div class="phead">
    <h1><?= h(SFPC_KIND_NAME[$kind]) ?> <?= $team ? '수정' : '신청' ?></h1>
    <span class="sub"><?= h(sfpc_setting('sfpc_title')) ?><?= $team ? ' · ' . h(sfpc_team_no($team)) : '' ?></span>
    <span class="grow"></span>
    <a class="btn sm" href="<?= h($back) ?>">돌아가기</a>
  </div>

  <?php if ($errs): ?>
    <div class="note err" id="errbox"><?php foreach ($errs as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
  <div class="note err sfpc-hide" id="jsErr"></div>

  <form class="sfpc-form" method="post" action="sfpc_team.php" id="teamForm" novalidate
        data-host="<?= $kind === 'student' ? h($host) : '' ?>" data-schools="<?= $hasSchools ? '1' : '0' ?>">
    <?= sfpc_csrf_field() ?>
    <input type="hidden" name="kind" value="<?= h($kind) ?>">
    <?php if ($team): ?><input type="hidden" name="id" value="<?= (int)$team['id'] ?>"><?php endif; ?>
    <?php if ($forCoach): ?><input type="hidden" name="coach" value="<?= $forCoach ?>"><?php endif; ?>

    <fieldset>
      <legend><?= $kind === 'student' ? '지도교사' : '신청인(대표자)' ?></legend>
      <div class="sfpc-ro">
        <span>이름 <b><?= h($owner['name'] ?? '') ?></b></span>
        <span>연락처 <b><?= h($owner['phone'] ?? '') ?></b></span>
        <?php if (!$byAdmin): ?><a class="btn sm" href="sfpc_join.php?next=sfpc_teams.php">내 정보 수정</a><?php endif; ?>
      </div>
    </fieldset>

    <fieldset>
      <legend>소속</legend>
      <div class="row">
        <div class="field" style="max-width:200px">
          <label for="region">지역</label>
          <select id="region" name="region" required>
            <option value="">고르세요</option>
            <?php foreach (SFPC_REGIONS as $r): ?>
              <option value="<?= h($r) ?>"<?= $in['region'] === $r ? ' selected' : '' ?>><?= h($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>참가 구분</label>
          <div class="chips">
            <?php foreach ($cats as $k => $c): ?>
              <label class="chip"><input type="radio" name="category" value="<?= h($k) ?>" data-level="<?= h($c['level']) ?>"
                <?= $in['category'] === $k ? 'checked' : '' ?>> <?= h($c['label']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="field<?= $canOnUi ? '' : ' sfpc-hide' ?>" id="wayBox">
        <label>참가 방식</label>
        <div class="sfpc-way">
          <label><input type="radio" name="way" value="off"<?= $in['way'] === 'off' ? ' checked' : '' ?>>
            <span><b>오프라인 (대회장)</b><small>팀 전체가 대회장에 와서 참가합니다. 기념 티셔츠를 드립니다.</small></span></label>
          <label><input type="radio" name="way" value="on"<?= $in['way'] === 'on' ? ' checked' : '' ?>>
            <span><b>온라인</b><small>팀 전체가 학교나 집에서 참가합니다.</small></span></label>
        </div>
        <span class="hint"><b><?= h($host) ?></b> 지역 학생 팀만 고릅니다. 접수 기간에는 수정에서 바꿀 수 있습니다.</span>
      </div>
      <p class="note info small sfpc-hide" id="onsiteNote" style="margin:-4px 0 16px">
        <?php if ($shirtLate): ?><b>티셔츠 조사가 마감되었습니다.</b> 지금 오프라인으로 접수하거나 바꾸면 기념 티셔츠를 받지 못할 수 있습니다. 사이즈는 적어 주세요.
        <?php else: ?>오프라인 참가 팀에게는 기념 티셔츠를 드립니다. 아래에서 팀원의 상의 사이즈를 함께 골라 주세요.<?php endif; ?></p>

      <div class="field sfpc-school">
        <label for="schoolQ" id="schoolLabel"><?= $isPre ? '대학교' : '학교' ?></label>
        <input type="hidden" name="school_id" id="schoolId" value="<?= $picked ? (int)$in['school_id'] : '' ?>">
        <div class="sfpc-picked<?= $picked ? '' : ' sfpc-hide' ?>" id="schoolPicked">
          <span id="schoolPickedName"><?= h($picked ? $in['school_name'] : '') ?></span>
          <span class="grow"></span>
          <button class="btn sm" type="button" id="schoolChange">바꾸기</button>
        </div>
        <div id="schoolFind"<?= $picked ? ' class="sfpc-hide"' : '' ?>>
          <input type="text" id="schoolQ" name="school_name" maxlength="40" autocomplete="off"
                 value="<?= h($in['school_name']) ?>" placeholder="학교 이름을 입력하세요">
          <div class="sfpc-hits" id="schoolHits" role="listbox"></div>
          <span class="hint" id="schoolHint"></span>
          <label class="chk" id="manualWrap" style="margin-top:8px">
            <input type="checkbox" name="school_manual" id="schoolManual" value="1"<?= $in['manual'] ? ' checked' : '' ?>>
            목록에 우리 학교가 없어서 직접 입력합니다
          </label>
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>팀</legend>
      <div class="field">
        <label for="teamName">팀명</label>
        <input type="text" id="teamName" name="team_name" maxlength="20" value="<?= h($in['team_name']) ?>" style="max-width:340px">
        <span class="hint">2~20자. 스코어보드에 이 이름으로 나옵니다.</span>
      </div>

      <table class="sfpc-members">
        <thead><tr>
          <th></th><th>이름</th>
          <?php if ($kind === 'student'): ?><th class="c-grade">학년</th><?php endif; ?>
          <th class="c-shirt js-shirt<?= $onsiteUi ? '' : ' sfpc-hide' ?>">상의 사이즈</th>
        </tr></thead>
        <tbody>
        <?php foreach ($in['members'] as $i => $m): ?>
          <tr>
            <td class="n">팀원 <?= $i + 1 ?><small><?= $i < $minM ? '필수' : '없으면 비움' ?></small></td>
            <td><input type="text" name="m_name[]" maxlength="20" value="<?= h($m['name']) ?>" aria-label="팀원 <?= $i + 1 ?> 이름"></td>
            <?php if ($kind === 'student'): ?>
              <td class="c-grade"><select name="m_grade[]" aria-label="팀원 <?= $i + 1 ?> 학년">
                <option value="0">학년</option>
                <?php for ($g = 1; $g <= 3; $g++): ?>
                  <option value="<?= $g ?>"<?= (int)$m['grade'] === $g ? ' selected' : '' ?>><?= $g ?>학년</option>
                <?php endfor; ?>
              </select></td>
            <?php endif; ?>
            <td class="c-shirt js-shirt<?= $onsiteUi ? '' : ' sfpc-hide' ?>"><select name="m_shirt[]" aria-label="팀원 <?= $i + 1 ?> 상의 사이즈">
              <option value="">사이즈</option>
              <?php foreach (SFPC_SHIRTS as $s): ?>
                <option value="<?= h($s) ?>"<?= $m['shirt'] === $s ? ' selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small muted" style="margin:-4px 0 14px">
        <?= $kind === 'student' ? '학생 2~4명이 한 팀입니다.' : '1~4명이 한 팀입니다. 신청인도 참가한다면 팀원에 넣어 주세요.' ?></p>
    </fieldset>

    <?php if ($byAdmin): ?>
      <div class="note admin-preview" style="margin:0 0 20px">운영진이 <b><?= h($owner['name'] ?? '') ?></b> 선생님의 팀을 <?= $team ? '고치는' : '대신 접수하는' ?> 중입니다.
        저장하면 바뀐 내용이 변경 기록에 남습니다. 접수 기간이 아니어도 저장됩니다.</div>
    <?php else: ?>
    <fieldset>
      <legend>개인정보 수집 · 이용 동의</legend>
      <div class="sfpc-consent md">
        <?php if ($privacy !== ''): ?><?= md($privacy) ?>
        <?php else: ?>
          <p><b>수집 항목</b></p>
          <ul><li>지도교사(신청인): 이름, 연락처, 이메일</li>
              <li>팀원: 이름, 학년, 소속 학교, 상의 사이즈(오프라인 참가자)</li></ul>
          <p><b>이용 목적</b> 참가 접수와 확인, 대회 계정 발급, 대회 운영과 안내, 결과 발표와 시상, 기념품 준비</p>
          <p><b>보유 기간</b> 대회가 끝나고 결과 정리를 마칠 때까지 보관한 뒤 파기합니다.</p>
          <p>동의하지 않을 수 있으나, 그 경우 참가 신청을 할 수 없습니다.</p>
        <?php endif; ?>
      </div>
      <label class="sfpc-agree">
        <input type="checkbox" name="agree" value="1" id="agree">
        <span>위 내용을 확인했으며, 팀원 모두(만 14세 미만은 보호자)의 동의를 받았습니다.</span>
      </label>
    </fieldset>
    <?php endif; ?>

    <div class="btnrow">
      <button class="btn primary" type="submit" style="padding:11px 26px; font-size:15px"><?= $team ? '저장' : '신청하기' ?></button>
      <a class="btn" href="<?= h($back) ?>">취소</a>
    </div>
  </form>
</div>

<script>
(function () {
  'use strict';
  var f = document.getElementById('teamForm');
  var host = f.dataset.host, hasSchools = f.dataset.schools === '1';
  var region = document.getElementById('region');
  var q = document.getElementById('schoolQ'), hits = document.getElementById('schoolHits');
  var sid = document.getElementById('schoolId'), manual = document.getElementById('schoolManual');
  var picked = document.getElementById('schoolPicked'), pickedName = document.getElementById('schoolPickedName');
  var find = document.getElementById('schoolFind'), hint = document.getElementById('schoolHint');
  var manualWrap = document.getElementById('manualWrap'), label = document.getElementById('schoolLabel');
  var jsErr = document.getElementById('jsErr');

  function level() {
    var c = f.querySelector('input[name=category]:checked');
    return c ? c.dataset.level : null;          /* null = 아직 안 고름, '' = 목록 없이 직접 입력 */
  }
  /* 목록에서 찾는 중인지 — 학교 목록이 있고, 직접 입력을 고르지 않았고, 목록이 있는 참가 구분일 때 */
  function searching() { return hasSchools && !manual.checked && level() !== '' ; }

  function show(el, on) { el.classList.toggle('sfpc-hide', !on); }

  function sync() {
    var can = region.value !== '' && region.value === host;                 /* 개최 지역의 학생 팀만 참가 방식을 고른다 */
    var w = f.querySelector('input[name=way]:checked'), on = can && !!w && w.value === 'off';
    show(document.getElementById('wayBox'), can);
    document.querySelectorAll('.js-shirt').forEach(function (el) { show(el, on); });
    show(document.getElementById('onsiteNote'), on);

    var lv = level(), pre = lv === '';
    label.textContent = pre ? '대학교' : '학교';
    q.placeholder = pre ? '대학교 이름을 입력하세요' : (searching() ? '학교 이름의 일부를 입력하면 목록이 나옵니다' : '학교 이름을 입력하세요');
    show(manualWrap, hasSchools && !pre);
    hint.textContent = (searching() && (region.value === '' || lv === null)) ? '지역과 참가 구분을 먼저 고르면 학교를 찾을 수 있습니다.' : '';
    if (!searching()) hits.innerHTML = '';
  }
  function unpick() {
    sid.value = ''; show(picked, false); show(find, true);
  }
  function pick(s) {
    sid.value = s.id; q.value = s.name; pickedName.textContent = s.name;
    hits.innerHTML = ''; show(picked, true); show(find, false);
  }

  var timer = 0, seq = 0;
  function search() {
    clearTimeout(timer);
    if (!searching() || region.value === '' || level() === null) { hits.innerHTML = ''; return; }
    var term = q.value.trim();
    if (term.length < 1) { hits.innerHTML = ''; return; }
    timer = setTimeout(function () {
      var my = ++seq;
      var url = 'sfpc_school.php?q=' + encodeURIComponent(term) + '&region=' + encodeURIComponent(region.value)
              + '&level=' + encodeURIComponent(level());
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (my !== seq) return;                 /* 늦게 온 예전 응답은 버린다 */
        hits.innerHTML = '';
        if (!d.ok || !d.schools.length) {
          var none = document.createElement('div');
          none.className = 'none';
          none.textContent = '찾는 학교가 없습니다. 지역을 확인하거나, 아래 "목록에 우리 학교가 없어서 직접 입력합니다"를 체크하세요.';
          hits.appendChild(none);
          return;
        }
        d.schools.forEach(function (s) {
          var b = document.createElement('button');
          b.type = 'button'; b.setAttribute('role', 'option');
          b.textContent = s.name;
          var sm = document.createElement('small');
          sm.textContent = [s.hs_type, s.addr].filter(Boolean).join(' · ');
          b.appendChild(sm);
          b.addEventListener('click', function () { pick(s); });
          hits.appendChild(b);
        });
      }).catch(function () { hits.innerHTML = ''; });
    }, 180);
  }

  q.addEventListener('input', search);
  q.addEventListener('focus', search);
  document.addEventListener('click', function (e) { if (!e.target.closest('.sfpc-school')) hits.innerHTML = ''; });
  document.getElementById('schoolChange').addEventListener('click', function () { unpick(); q.focus(); q.select(); });
  manual.addEventListener('change', function () { sync(); if (!manual.checked) search(); });
  /* 지역이나 참가 구분이 바뀌면 고른 학교가 맞지 않게 되므로 다시 고르게 한다 */
  region.addEventListener('change', function () { if (sid.value) { unpick(); q.value = ''; } sync(); });
  f.querySelectorAll('input[name=category]').forEach(function (r) {
    r.addEventListener('change', function () { if (sid.value) { unpick(); q.value = ''; } sync(); });
  });

  f.querySelectorAll('input[name=way]').forEach(function (r) { r.addEventListener('change', sync); });

  f.addEventListener('submit', function (e) {
    var msg = '';
    if (searching() && !sid.value && q.value.trim() !== '')
      msg = '학교를 목록에서 골라 주세요. 목록에 없으면 "목록에 우리 학교가 없어서 직접 입력합니다"를 체크하세요.';
    else if (document.getElementById('agree') && !document.getElementById('agree').checked)
      msg = '개인정보 수집 · 이용에 동의해야 신청할 수 있습니다.';
    if (msg) {
      e.preventDefault();
      jsErr.textContent = msg; show(jsErr, true);
      jsErr.scrollIntoView({ block: 'center' });
    }
  });

  sync();
  var eb = document.getElementById('errbox');
  if (eb) eb.scrollIntoView({ block: 'center' });
})();
</script>
<?php page_foot(); ?>
