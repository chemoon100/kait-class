<?php
/* ═══════════════════════════════════════════════
   sfpc_ready.php — 준비하기. 운영 > SFPC > 설정에서 공개하기 전에는 준비 중 안내가 나온다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();

page_head(['title' => '준비하기', 'root' => '', 'user' => $u, 'nav' => 'sfpc_ready']);
sfpc_css();
if (sfpc_gate('sfpc_ready_open', '준비하기', $u)):
?>
<div class="wrap narrow">
  <div class="phead"><h1>준비하기</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="empty">실제 대회처럼 시간을 재며 풀어 보는 곳입니다.<br>화면을 만드는 중입니다.</div>
</div>
<?php
endif;
page_foot();
