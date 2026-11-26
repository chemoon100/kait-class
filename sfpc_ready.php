<?php
/* sfpc_ready.php — 준비하기 (정해진 시각에 다 같이 치르는 모의 대회 — 네 번). 화면은 sfpc_round.inc.php 가 그린다. */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$KIND = 'ready';
require __DIR__ . '/sfpc_round.inc.php';
