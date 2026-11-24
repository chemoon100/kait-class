<?php
/* ═══════════════════════════════════════════════
   sfpc_study.php — 공부하기. 운영 > SFPC > 설정에서 공개하기 전에는 준비 중 안내가 나온다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();

page_head(['title' => '공부하기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_study']);
sfpc_css();
if (sfpc_gate('sfpc_study_open', '공부하기', $u)):
?>
<div class="wrap narrow">
  <div class="phead"><h1>공부하기</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="empty">기초 문제를 풀며 대회를 준비하는 곳입니다. 중학교 팀은 공부하기를 모두 풀어 수료해야 정식참가자로 도전하기에 나갈 수 있습니다.<br>화면을 만드는 중입니다.</div>
</div>
<?php
endif;
page_foot();
