<?php
/* sfpc_contest.php — 도전하기 (본 대회 — 한 번). 화면은 sfpc_round.inc.php 가 그린다. */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$KIND = 'main';
require __DIR__ . '/sfpc_round.inc.php';
