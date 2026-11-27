<?php
/* ═══════════════════════════════════════════════
   sfpc-cli.php — 서버 터미널에서 쓰는 SFPC 도구 (웹에서는 열리지 않는다)

       cd /var/www/html
       sudo -u www-data php sfpc-cli.php status    지금 설정과 접수 현황
       sudo -u www-data php sfpc-cli.php open      접수 시작일을 어제로 — 시험 서버에서 접수를 미리 열어 볼 때
       sudo -u www-data php sfpc-cli.php reset     접수 기간을 기본값으로 되돌린다
       sudo -u www-data php sfpc-cli.php schools   함께 넣어 둔 학교 목록을 다시 넣는다
       sudo -u www-data php sfpc-cli.php mail      인증 메일을 보낼 계정을 넣는다 (Gmail 주소 + 앱 비밀번호). 넣고 나면 시험 메일을 보낸다
       sudo -u www-data php sfpc-cli.php mailoff   인증 메일을 끈다 (계정 정보를 지운다)

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
} elseif ($cmd === 'schools') {
  $n = sfpc_schools_load_bundled();
  if ($n) setting_set('sfpc_schools_at', now());
  echo "학교 " . $n . "곳을 넣었습니다.\n";
} elseif ($cmd === 'mail') {
  /* 계정과 앱 비밀번호는 여기서만 받는다 — 명령줄 인자로 받으면 기록(history)에 남는다. 비밀번호는 화면에 찍히지 않는다. */
  $ask = function (string $q, bool $hide = false): string {
    fwrite(STDOUT, $q);
    $tty = $hide && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $v = trim((string)fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $v;
  };
  echo "인증 메일을 보낼 계정을 넣습니다. Gmail 은 2단계 인증을 켠 뒤 '앱 비밀번호'(16자리)를 만들어 넣습니다.\n";
  $user = $ask("보내는 메일 주소: ");
  if (!filter_var($user, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "메일 주소가 올바르지 않습니다. 바꾸지 않았습니다.\n"); exit(1); }
  $pass = preg_replace('/\s+/', '', $ask("앱 비밀번호 (화면에 보이지 않습니다): ", true));
  if (strlen($pass) < 8) { fwrite(STDERR, "앱 비밀번호가 너무 짧습니다. 바꾸지 않았습니다.\n"); exit(1); }
  $conf = ['host' => 'smtp.gmail.com', 'port' => 465, 'user' => $user, 'pass' => $pass, 'name' => 'SFPC', 'day_max' => SFPC_MAIL_DAY_MAX];
  if (!str_ends_with(strtolower($user), '@gmail.com')) {
    $h = $ask("메일 서버 주소 (그냥 Enter 면 smtp.gmail.com): "); if ($h !== '') $conf['host'] = $h;
    $p = (int)$ask("포트 (그냥 Enter 면 465): ");                  if ($p > 0) $conf['port'] = $p;
  }
  echo "시험 메일을 " . $user . " 로 보내 봅니다…\n";
  $e = sfpc_smtp_send($conf, $user, '[SFPC] 메일 보내기 시험', "SFPC 사이트에서 보낸 시험 메일입니다.\r\n이 메일이 보이면 인증 메일을 보낼 수 있습니다.\r\n");
  if ($e !== '') { fwrite(STDERR, "보내지 못했습니다 — " . $e . "\n저장하지 않았습니다. 주소와 앱 비밀번호를 확인하고 다시 실행하세요.\n"); exit(1); }
  $old = umask(0077);
  file_put_contents(SFPC_MAIL_FILE, json_encode($conf, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  umask($old); chmod(SFPC_MAIL_FILE, 0600);
  echo "보냈습니다. 저장했습니다 — 이제 가입할 때 인증번호를 보냅니다. (받은편지함에서 시험 메일을 확인하세요)\n";
  exit(0);
} elseif ($cmd === 'mailoff') {
  if (is_file(SFPC_MAIL_FILE)) unlink(SFPC_MAIL_FILE);
  echo "인증 메일을 껐습니다. 가입은 인증 없이 됩니다.\n";
  exit(0);
} elseif ($cmd !== 'status') {
  fwrite(STDERR, "쓸 수 있는 명령: status | open | reset | schools | mail | mailoff\n");
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
echo "인증 메일 " . (sfpc_mail_on() ? '켜짐 (' . sfpc_mail_conf()['user'] . ' · 최근 24시간 ' . sfpc_mail_sent_today() . '통 / 한도 ' . (int)sfpc_mail_conf()['day_max'] . ')' : '꺼짐') . "\n";
