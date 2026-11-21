<?php
/* ═══════════════════════════════════════════════
   sfpc.php — SFPC 대회 안내와 참가 접수의 첫 화면

   큰 버튼 둘(학생 팀 / 교사·예비교사 팀)로 신청을 가른다.
   로그인하지 않았거나 지도교사 정보가 없으면 sfpc_join.php 를 거쳐 양식으로 간다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u     = me();
$coach = sfpc_coach($u);
$state = sfpc_apply_state();
$canApply = sfpc_can_edit($u);
$mine  = $coach ? array_filter(sfpc_teams_of((int)$u['id']), fn($t) => $t['status'] !== 'canceled') : [];
/* 운영진 미리 보기(?as=) — 방문자 눈으로 본다: 기간 밖이면 버튼이 잠기고, 운영진용 안내는 나오지 않는다 */
$pv = sfpc_preview();
if ($pv !== null) { $canApply = ($state === 'open'); $mine = []; }

/* 버튼이 갈 곳 — 지도교사 정보가 있으면 바로 양식, 없으면 가입부터 */
$go = function (string $kind) use ($coach): string {
  $form = 'sfpc_team.php?kind=' . $kind;
  return $coach ? $form : 'sfpc_join.php?next=' . rawurlencode($form);
};
$host     = sfpc_setting('sfpc_host_region');
$schedule = trim(sfpc_setting('sfpc_schedule'));
$contact  = trim(sfpc_setting('sfpc_contact'));

page_head(['title' => sfpc_setting('sfpc_title'), 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
sfpc_preview_bar();
?>
<section class="sfpc-band">
  <div class="sfpc-band-in">
    <p class="k">School Friends Programming Challenge · 학교 친구 프로그래밍 챌린지</p>
    <h1><?= h(sfpc_setting('sfpc_title')) ?></h1>
    <p class="lead">프로그래밍에 관심 있는 학생이라면 누구나 친구들과 팀을 이뤄 도전하는 대회입니다.
      순위 경쟁보다 함께 문제를 해결하는 경험을 나눕니다.</p>
    <div class="sfpc-period">
      <?php if ($state === 'open'): ?><span class="sfpc-pill">접수 중</span>
      <?php elseif ($state === 'before'): ?><span class="sfpc-pill wait">접수 예정</span>
      <?php else: ?><span class="sfpc-pill end">접수 마감</span><?php endif; ?>
      <span>참가 접수 <b><?= h(sfpc_period_text()) ?></b></span>
    </div>
  </div>
</section>

<div class="wrap">
  <?php if ($state !== 'open' && is_admin($u) && $pv === null): ?>
    <div class="note admin-preview">접수 기간이 아닙니다. 운영진은 기간 밖에서도 신청 화면을 눌러 볼 수 있습니다.</div>
  <?php endif; ?>

  <?php if ($mine): ?>
    <div class="sfpc-mine">
      <span><b><?= h($coach['name']) ?></b> 선생님이 신청한 팀이 <b><?= count($mine) ?></b>개 있습니다.</span>
      <span class="grow"></span>
      <a class="btn sm primary" href="sfpc_teams.php">내 팀 보기</a>
    </div>
  <?php endif; ?>

  <h2 class="sfpc-pick-h"><?= $canApply ? '어떤 팀으로 신청하시나요?' : ($state === 'before' ? '참가 신청은 접수 기간에 열립니다' : '참가 접수가 마감되었습니다') ?>
    <?php if ($canApply): ?><span>아래에서 하나를 눌러 신청을 시작하세요</span><?php endif; ?></h2>
  <div class="sfpc-pick">
    <a href="<?= h($go('student')) ?>" class="k-student<?= $canApply ? '' : ' off' ?>">
      <span class="who">중학생 · 고등학생</span>
      <span class="t">학생 팀 신청</span>
      <span class="d">학생 2~4명이 한 팀입니다. 소속 학교 정보 선생님이 지도교사로 신청합니다.</span>
      <span class="go"><?= $canApply ? '학생 팀 신청하기 →' : ($state === 'before' ? '접수 기간에 열립니다' : '접수가 마감되었습니다') ?></span>
    </a>
    <a href="<?= h($go('teacher')) ?>" class="k-teacher<?= $canApply ? '' : ' off' ?>">
      <span class="who">교사 · 예비교사</span>
      <span class="t">교사 · 예비교사 팀 신청</span>
      <span class="d">현직 정보 교사, 사범대 컴퓨터교육과 재학생 · 졸업생 1~4명이 한 팀입니다.</span>
      <span class="go"><?= $canApply ? '교사 팀 신청하기 →' : ($state === 'before' ? '접수 기간에 열립니다' : '접수가 마감되었습니다') ?></span>
    </a>
  </div>
  <p class="small muted" style="margin:0 0 30px">
    <?php if (!$u): ?>이미 신청하셨나요? <a href="login.php?next=<?= rawurlencode(sfpc_path('sfpc_teams.php')) ?>">로그인해서 내 팀 보기</a> · <?php endif; ?>
    한 선생님이 여러 팀을 신청할 수 있고, 접수 기간에는 언제든 고칠 수 있습니다.
  </p>

  <div class="sec" style="margin-top:0">
    <h2>어떤 대회인가요</h2>
    <div class="sfpc-info" style="margin-top:10px">
      <section>
        <ul>
          <li><b>누구나 도전하는 대회</b> — 수업에서 배운 프로그래밍만으로 풀 수 있는 문제부터 시작합니다. 출력문만 알아도 풀 수 있는 문제가 많습니다.</li>
          <li><b>친구와 함께</b> — 학생 2~4명이 한 팀이 되어 같이 문제를 해결합니다.</li>
        </ul>
      </section>
      <section>
        <ul>
          <li><b>오픈북</b> — 책, 필기, 인터넷 자료를 모두 볼 수 있습니다.</li>
          <li><b>정보 선생님이 만드는 대회</b> — 전국의 정보 교사들이 문제를 내고 운영합니다. 신청도 지도교사가 합니다.</li>
        </ul>
      </section>
    </div>
  </div>

  <div class="sec">
    <h2>신청 순서</h2>
    <ol class="sfpc-steps" style="margin-top:10px">
      <li><b>지도교사 가입</b>아이디와 연락처를 등록합니다.</li>
      <li><b>팀 신청</b>지역 · 학교 · 팀명 · 팀원을 입력합니다.</li>
      <li><b>확인과 수정</b>마감 전까지 내 팀에서 고칩니다.</li>
      <li><b>대회 계정 안내</b>마감 뒤 팀마다 계정을 드립니다.</li>
    </ol>
  </div>

  <div class="sec">
    <div class="sfpc-info">
      <section>
        <h2>참가 구분</h2>
        <ul>
          <li><b>정식참가자</b> — 일반고 학생, '공부하기'를 수료한 중학생</li>
          <li><b>시범참가자</b> — 그 밖의 중학생, 특목 · 특성화 · 자사고 등 학생, 교사 · 예비교사</li>
        </ul>
      </section>
      <section>
        <h2>참가 방법</h2>
        <ul>
          <li><b><?= h($host) ?> 지역</b> 팀은 대회장에서 참가합니다. 신청할 때 상의 사이즈를 함께 받습니다.</li>
          <li>그 밖의 지역 팀은 온라인으로 참가합니다.</li>
        </ul>
      </section>
      <section>
        <h2>대회 일정</h2>
        <?php if ($schedule !== ''): ?><div class="md"><?= md($schedule) ?></div>
        <?php else: ?><p>공부하기 · 준비하기 · 도전하기 일정은 곧 알려 드립니다.</p><?php endif; ?>
      </section>
      <section>
        <h2>대회 참가와 스코어보드</h2>
        <p><a href="sfpc_contest.php">대회 참가</a> · <a href="sfpc_board.php">스코어보드</a> 화면은 대회 일정에 맞춰 열립니다.
          <?php if ($contact !== ''): ?><br>문의 <a href="mailto:<?= h($contact) ?>"><?= h($contact) ?></a><?php endif; ?></p>
      </section>
    </div>
  </div>
</div>
<?php page_foot(); ?>
