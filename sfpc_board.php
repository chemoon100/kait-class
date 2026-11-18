<?php
/* ═══════════════════════════════════════════════
   sfpc_board.php — 스코어보드 화면

   운영 > SFPC > 설정에서 공개하기 전에는 "준비 중" 안내만 보인다.
   운영진은 공개 전에도 들어와 미리 볼 수 있다.
   실제 화면은 이 파일의 아래쪽에 만들어 간다 — 주소가 바뀌지 않게 자리만 먼저 잡아 둔다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();

page_head(['title' => '스코어보드', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
if (sfpc_gate('sfpc_board_open', '스코어보드', $u)):
?>
<div class="wrap narrow">
  <div class="phead"><h1>스코어보드</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="empty">스코어보드 화면을 만드는 중입니다.</div>
</div>
<?php
endif;
page_foot();
