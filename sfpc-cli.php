<?php
/* ═══════════════════════════════════════════════
   sfpc-cli.php — 서버 터미널에서 쓰는 SFPC 도구 (웹에서는 열리지 않는다)

       cd /var/www/html
       sudo -u www-data php sfpc-cli.php status    지금 설정과 접수 현황
       sudo -u www-data php sfpc-cli.php open      접수 시작일을 어제로 — 시험 서버에서 접수를 미리 열어 볼 때
       sudo -u www-data php sfpc-cli.php reset     접수 기간을 기본값으로 되돌린다

   ★ open 은 시험 서버용이다. 운영 서버에서 쓰면 접수가 실제로 열린다.
   서버 화면(VNC)에서는 밑줄 · 따옴표 같은 글자가 잘 입력되지 않아,
   파일 이름과 명령을 그런 글자 없이 칠 수 있게 지었다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/sfpc_lib.php';

$cmd = $argv[1] ?? 'status';
if ($cmd === 'open') {
  setting_set('sfpc_apply_from', date('Y-m-d', time() - 86400) . ' 00:00');
  echo "접수 시작일을 어제로 바꿨습니다.\n";
} elseif ($cmd === 'reset') {
  setting_set('sfpc_apply_from', SFPC_DEFAULTS['sfpc_apply_from']);
  setting_set('sfpc_apply_to',   SFPC_DEFAULTS['sfpc_apply_to']);
  echo "접수 기간을 기본값으로 되돌렸습니다.\n";
} elseif ($cmd !== 'status') {
  fwrite(STDERR, "쓸 수 있는 명령: status | open | reset\n");
  exit(1);
}

/* 방금 바꾼 값이 보이게 DB 에서 다시 읽는다 (settings_all 은 한 번 읽은 값을 기억한다) */
$get = fn(string $k) => (string)(col("SELECT value FROM settings WHERE key = ?", [$k]) ?: (SFPC_DEFAULTS[$k] ?? ''));
$from = $get('sfpc_apply_from'); $to = $get('sfpc_apply_to');
$now = time();
$state = $now < sfpc_ts($from) ? 'before (시작 전)' : ($now > sfpc_ts($to) + 59 ? 'closed (마감)' : 'open (접수 중)');
echo "대회      " . $get('sfpc_title') . "\n";
echo "접수 기간 " . $from . " ~ " . $to . "\n";
echo "상태      " . $state . "\n";
echo "접수 팀   " . (int)col("SELECT COUNT(*) FROM sfpc_teams WHERE contest = ? AND status = 'submitted'", [SFPC_CONTEST]) . "\n";
echo "학교 목록 " . sfpc_school_count() . "\n";
