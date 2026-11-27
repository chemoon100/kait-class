<?php
/* ═══════════════════════════════════════════════
   sfpc_mail.php — 메일 보내기와 지도교사 이메일 인증 (sfpc_lib.php 가 불러 쓴다)

   메일은 Gmail 같은 바깥 메일 계정을 빌려 보낸다 (SMTP · 앱 비밀번호).
   계정과 앱 비밀번호는 DB 가 아니라 DATA_DIR/sfpc-mail.json 에 둔다 — 서버 터미널에서
       sudo -u www-data php sfpc-cli.php mail
   로 넣는다 (화면에 찍히지 않는다). 이 파일이 없으면 인증 기능 전체가 꺼진 것이고, 가입은 예전과 똑같다.

   인증은 접수를 막지 않는다. 인증번호가 오지 않는 메일(학교 · 교육청 메일이 바깥 메일을 막는 경우)이 있어,
   "나중에 인증"으로 넘어갈 수 있고 내 팀 화면에 안내 띠가 남는다. 운영진은 지도교사 목록에서 인증 여부를 본다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
if (!defined('DATA_DIR')) { http_response_code(404); exit; }   /* 직접 열 수 없다 */

const SFPC_MAIL_FILE     = DATA_DIR . '/sfpc-mail.json';
const SFPC_MAIL_DAY_MAX  = 450;    /* 하루(24시간)에 보낼 수 있는 수. 무료 Gmail 은 500통 — 조금 남긴다. sfpc-mail.json 의 day_max 로 바꾼다 */
const SFPC_CODE_MIN      = 10;     /* 인증번호가 살아 있는 시간(분) */
const SFPC_CODE_TRIES    = 5;      /* 인증번호 하나에 틀릴 수 있는 횟수 */
const SFPC_CODE_WAIT     = 60;     /* 다시 보내기까지 기다리는 시간(초) */
const SFPC_CODE_PER_HOUR = 5;      /* 한 계정이 한 시간에 받을 수 있는 수 */
const SFPC_CODE_IP_HOUR  = 20;     /* 한 주소(IP)에서 한 시간에 보낼 수 있는 수 */

function sfpc_mail_schema(): void {
  $cols = array_column(db()->query("PRAGMA table_info(sfpc_coaches)")->fetchAll(PDO::FETCH_ASSOC), 'name');
  /* verified_email: 인증을 마친 주소. 지금 주소와 같을 때만 "인증됨"이다 — 주소를 바꾸면 저절로 미인증이 된다. */
  if (!in_array('verified_email', $cols, true)) db()->exec("ALTER TABLE sfpc_coaches ADD COLUMN verified_email TEXT NOT NULL DEFAULT ''");
  if (!in_array('verified_at', $cols, true))    db()->exec("ALTER TABLE sfpc_coaches ADD COLUMN verified_at TEXT NOT NULL DEFAULT ''");
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_email_codes(
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, email TEXT NOT NULL, code_hash TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '', tries INTEGER NOT NULL DEFAULT 0, used INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, expires_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_code_user ON sfpc_email_codes(user_id, id)");
  /* 비밀번호 다시 정하기에 쓰는 인증번호 (sfpc_reset.php). user_id 0 은 맞는 계정이 없던 요청 — 횟수 제한을 세려고 남긴다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_pw_resets(
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, email TEXT NOT NULL, code_hash TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '', tries INTEGER NOT NULL DEFAULT 0, used INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, expires_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_reset_user ON sfpc_pw_resets(user_id, id)");
  /* 보낸 메일의 기록. 하루 한도를 세고, "메일이 안 와요" 문의에 답할 때 본다. 내용은 남기지 않는다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_mail_log(
    id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT NOT NULL, kind TEXT NOT NULL, ok INTEGER NOT NULL,
    err TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_mail_time ON sfpc_mail_log(created_at)");
}

/* ── 메일 계정 ─────────────────────────────────── */
function sfpc_mail_conf(): ?array {
  static $c = false;
  if ($c !== false) return $c;
  $c = null;
  if (is_file(SFPC_MAIL_FILE)) {
    $j = json_decode((string)@file_get_contents(SFPC_MAIL_FILE), true);
    if (is_array($j) && ($j['user'] ?? '') !== '' && ($j['pass'] ?? '') !== '') {
      $c = $j + ['host' => 'smtp.gmail.com', 'port' => 465, 'name' => 'SFPC', 'day_max' => SFPC_MAIL_DAY_MAX];
    }
  }
  return $c;
}
function sfpc_mail_on(): bool { return sfpc_mail_conf() !== null; }
function sfpc_mail_sent_today(): int {
  return (int)col("SELECT COUNT(*) FROM sfpc_mail_log WHERE ok = 1 AND created_at > ?", [date('Y-m-d H:i:s', time() - 86400)]);
}
function sfpc_mail_left(): int {
  $c = sfpc_mail_conf();
  return $c ? max(0, (int)$c['day_max'] - sfpc_mail_sent_today()) : 0;
}

/* SMTP 로 한 통 보낸다. 돌려주는 값: '' 성공 · 그 밖은 오류 문구(운영 기록용 — 사용자에게 그대로 보이지 않는다).
   465(처음부터 암호화) · 587(STARTTLS) 을 쓴다. 암호화 없이 보내는 것은 시험용 설정(tls => false)에서만. */
/* $attach: ['name' => 파일 이름, 'data' => 내용] 을 주면 첨부로 붙인다 (백업 메일). */
function sfpc_smtp_send(array $c, string $to, string $subject, string $body, ?array $attach = null): string {
  $port = (int)$c['port'];
  $tls  = $c['tls'] ?? true;
  $addr = (($tls && $port === 465) ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port;
  $fp = @stream_socket_client($addr, $en, $es, 10);
  if (!$fp) return '연결 실패: ' . $es;
  stream_set_timeout($fp, 15);
  $read = function () use ($fp): string {
    $all = '';
    while (($line = fgets($fp, 1000)) !== false) { $all .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
    return $all;
  };
  $cmd = function (string $s, string $want) use ($fp, $read): string {
    if ($s !== '') fwrite($fp, $s . "\r\n");
    $r = $read();
    return str_starts_with($r, $want) ? '' : (trim(substr($r, 0, 200)) ?: '응답 없음');
  };
  $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
  $fail = function (string $step, string $e) use ($fp): string { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return $step . ': ' . $e; };

  if (($e = $cmd('', '220')) !== '')                  return $fail('인사', $e);
  if (($e = $cmd('EHLO ' . $host, '250')) !== '')     return $fail('EHLO', $e);
  if ($tls && $port !== 465) {
    if (($e = $cmd('STARTTLS', '220')) !== '')        return $fail('STARTTLS', $e);
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $fail('STARTTLS', '암호화 실패');
    if (($e = $cmd('EHLO ' . $host, '250')) !== '')   return $fail('EHLO', $e);
  }
  if (($e = $cmd('AUTH LOGIN', '334')) !== '')                    return $fail('로그인', $e);
  if (($e = $cmd(base64_encode($c['user']), '334')) !== '')       return $fail('로그인', $e);
  if (($e = $cmd(base64_encode($c['pass']), '235')) !== '')       return $fail('로그인(계정 또는 앱 비밀번호)', $e);
  if (($e = $cmd('MAIL FROM:<' . $c['user'] . '>', '250')) !== '') return $fail('보내는 주소', $e);
  if (($e = $cmd('RCPT TO:<' . $to . '>', '25')) !== '')           return $fail('받는 주소', $e);
  if (($e = $cmd('DATA', '354')) !== '')                           return $fail('DATA', $e);
  $b64 = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
  $head = [
    'Date: ' . date('r'),
    'From: ' . $b64((string)$c['name']) . ' <' . $c['user'] . '>',
    'To: <' . $to . '>',
    'Subject: ' . $b64($subject),
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
    'MIME-Version: 1.0',
  ];
  $text = ['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', rtrim(chunk_split(base64_encode($body), 76, "\r\n"))];
  if ($attach === null) {
    $msg = implode("\r\n", array_merge($head, $text));
  } else {
    $bd = 'sfpc-' . bin2hex(random_bytes(12));
    $fn = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$attach['name']);
    $msg = implode("\r\n", array_merge($head, ['Content-Type: multipart/mixed; boundary="' . $bd . '"', '', '--' . $bd], $text, [
      '--' . $bd,
      'Content-Type: application/octet-stream; name="' . $fn . '"',
      'Content-Disposition: attachment; filename="' . $fn . '"',
      'Content-Transfer-Encoding: base64',
      '',
      rtrim(chunk_split(base64_encode((string)$attach['data']), 76, "\r\n")),
      '--' . $bd . '--',
    ]));
  }
  if (($e = $cmd($msg . "\r\n.", '250')) !== '')      return $fail('보내기', $e);
  @fwrite($fp, "QUIT\r\n"); @fclose($fp);
  return '';
}

/* 메일 한 통. 하루 한도를 지키고 기록을 남긴다. '' 성공 · 'off' 꺼짐 · 'limit' 오늘 한도 · 그 밖은 오류 */
function sfpc_mail_send(string $to, string $subject, string $body, string $kind, ?array $attach = null): string {
  $c = sfpc_mail_conf();
  if (!$c) return 'off';
  if (sfpc_mail_left() <= 0) return 'limit';
  if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $to)) return '받는 주소가 올바르지 않습니다';
  $e = sfpc_smtp_send($c, $to, $subject, $body, $attach);
  db()->prepare("INSERT INTO sfpc_mail_log(to_email, kind, ok, err, created_at) VALUES(?,?,?,?,?)")
      ->execute([$to, $kind, $e === '' ? 1 : 0, mb_substr($e, 0, 300), now()]);
  return $e;
}

/* ── 이메일 인증 ───────────────────────────────── */
function sfpc_verified(?array $coach): bool {
  return $coach && ($coach['verified_email'] ?? '') !== '' && strcasecmp((string)$coach['verified_email'], (string)$coach['email']) === 0;
}
/* 인증을 받아야 하는데 아직 안 받은 지도교사인가 (메일이 꺼져 있으면 아무도 해당하지 않는다) */
function sfpc_verify_due(?array $coach): bool {
  return sfpc_mail_on() && $coach && ($coach['email'] ?? '') !== '' && !sfpc_verified($coach);
}
function sfpc_code_hash(int $uid, string $code): string { return hash('sha256', $uid . ':' . $code); }
/* 같은 주소로 다시 보낼 수 있을 때까지 남은 초 (0 이면 지금 보낼 수 있다).
   주소를 고친 직후에는 기다리게 하지 않는다 — 오타를 고치고 바로 받을 수 있어야 한다 (한 시간 한도는 그대로 적용된다). */
function sfpc_verify_wait(array $coach): int {
  $last = one("SELECT email, created_at FROM sfpc_email_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1", [(int)$coach['user_id']]);
  if (!$last || strcasecmp($last['email'], (string)$coach['email']) !== 0) return 0;
  return max(0, SFPC_CODE_WAIT - (time() - strtotime($last['created_at'])));
}

/* 인증번호를 만들어 보낸다. '' 성공 · 그 밖은 사용자에게 보일 문구 */
function sfpc_verify_send(array $coach): string {
  $uid = (int)$coach['user_id']; $email = (string)$coach['email'];
  if (!sfpc_mail_on()) return '지금은 인증 메일을 보낼 수 없습니다.';
  if (($w = sfpc_verify_wait($coach)) > 0) return $w . '초 뒤에 다시 보낼 수 있습니다.';
  $hourAgo = date('Y-m-d H:i:s', time() - 3600);
  if ((int)col("SELECT COUNT(*) FROM sfpc_email_codes WHERE user_id = ? AND created_at > ?", [$uid, $hourAgo]) >= SFPC_CODE_PER_HOUR)
    return '인증번호를 너무 여러 번 요청했습니다. 한 시간쯤 뒤에 다시 시도하거나 "나중에 인증"을 눌러 주세요.';
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($ip !== '' && (int)col("SELECT COUNT(*) FROM sfpc_email_codes WHERE ip = ? AND created_at > ?", [$ip, $hourAgo]) >= SFPC_CODE_IP_HOUR)
    return '이 자리에서 인증번호를 너무 여러 번 요청했습니다. 잠시 뒤에 다시 시도해 주세요.';

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $title = sfpc_setting('sfpc_title');
  $body = $title . " 지도교사 이메일 인증번호입니다.\r\n\r\n"
        . "    " . $code . "\r\n\r\n"
        . "가입 화면의 인증번호 칸에 위 여섯 자리를 입력해 주세요. " . SFPC_CODE_MIN . "분 동안 쓸 수 있습니다.\r\n"
        . "직접 요청하지 않으셨다면 이 메일은 지우셔도 됩니다.\r\n\r\n"
        . "문의: " . sfpc_setting('sfpc_contact') . "\r\n";
  $e = sfpc_mail_send($email, '[SFPC] 이메일 인증번호 ' . $code, $body, 'verify');
  if ($e === 'limit') return '오늘 보낼 수 있는 인증 메일을 모두 보냈습니다. "나중에 인증"을 누르고 내일 내 팀 화면에서 인증해 주세요. 접수는 지금 하실 수 있습니다.';
  if ($e !== '')      return '인증 메일을 보내지 못했습니다. 주소를 다시 확인하시거나 "나중에 인증"을 눌러 주세요. 접수는 지금 하실 수 있습니다.';
  db()->prepare("UPDATE sfpc_email_codes SET used = 1 WHERE user_id = ? AND used = 0")->execute([$uid]);   /* 앞서 보낸 번호는 못 쓰게 */
  db()->prepare("INSERT INTO sfpc_email_codes(user_id, email, code_hash, ip, created_at, expires_at) VALUES(?,?,?,?,?,?)")
      ->execute([$uid, $email, sfpc_code_hash($uid, $code), $ip, now(), date('Y-m-d H:i:s', time() + SFPC_CODE_MIN * 60)]);
  return '';
}

/* 인증번호를 맞춰 본다. '' 성공(인증됨) · 그 밖은 사용자에게 보일 문구 */
function sfpc_verify_check(array $coach, string $code, array $actor): string {
  $uid = (int)$coach['user_id'];
  $code = preg_replace('/\D/', '', $code);
  $row = one("SELECT * FROM sfpc_email_codes WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1", [$uid]);
  if (!$row)                                                return '먼저 인증번호를 받아 주세요.';
  if (strcasecmp($row['email'], (string)$coach['email']) !== 0) return '이메일 주소가 바뀌었습니다. 인증번호를 다시 받아 주세요.';
  if ($row['expires_at'] < now())                           return '인증번호의 시간이 지났습니다. 다시 받아 주세요.';
  if ((int)$row['tries'] >= SFPC_CODE_TRIES)                return '여러 번 틀려 이 인증번호는 쓸 수 없습니다. 다시 받아 주세요.';
  if (strlen($code) !== 6 || !hash_equals($row['code_hash'], sfpc_code_hash($uid, $code))) {
    db()->prepare("UPDATE sfpc_email_codes SET tries = tries + 1 WHERE id = ?")->execute([$row['id']]);
    $left = SFPC_CODE_TRIES - (int)$row['tries'] - 1;
    return $left > 0 ? '인증번호가 맞지 않습니다. (' . $left . '번 더 시도할 수 있습니다)' : '여러 번 틀려 이 인증번호는 쓸 수 없습니다. 다시 받아 주세요.';
  }
  db()->prepare("UPDATE sfpc_email_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
  db()->prepare("UPDATE sfpc_coaches SET verified_email = email, verified_at = ? WHERE user_id = ?")->execute([now(), $uid]);
  sfpc_log(null, $uid, $actor, 'coach', '이메일 인증: ' . $coach['email']);
  return '';
}

/* ── 비밀번호 다시 정하기 (지도교사) ────────────────
   가입할 때 적은 이메일로 인증번호를 보내고, 그 번호를 아는 사람이 새 비밀번호를 정한다 (sfpc_reset.php).
   · 운영진 계정과 팀 계정은 여기서 바꾸지 않는다 (운영진은 서버의 reset-admin.php, 팀 계정은 지도교사의 "내 팀" 화면).
   · 맞는 계정이 있든 없든 화면에는 같은 말만 보인다 — 누가 가입했는지 떠보지 못하게.
   · 아이디를 비워도 된다. 그 이메일의 계정이 하나면 메일에 아이디를 함께 적어 보낸다 (아이디를 잊은 사람).
   돌려주는 값: ['uid' => 맞은 계정(없으면 0), 'err' => 사용자에게 보일 문구('' 이면 계속)] */
const SFPC_RESET_PER_HOUR = 3;
function sfpc_reset_request(string $loginId, string $email): array {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $hourAgo = date('Y-m-d H:i:s', time() - 3600);
  if (!sfpc_mail_on()) return ['uid' => 0, 'err' => '지금은 메일을 보낼 수 없습니다. 운영진에게 문의해 주세요. (' . sfpc_setting('sfpc_contact') . ')'];
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['uid' => 0, 'err' => '이메일 주소를 확인해 주세요.'];
  if ($ip !== '' && (int)col("SELECT COUNT(*) FROM sfpc_pw_resets WHERE ip = ? AND created_at > ?", [$ip, $hourAgo]) >= SFPC_CODE_IP_HOUR)
    return ['uid' => 0, 'err' => '이 자리에서 너무 여러 번 요청했습니다. 한 시간쯤 뒤에 다시 시도해 주세요.'];

  $sql = "SELECT u.id, u.login_id, c.email FROM sfpc_coaches c JOIN users u ON u.id = c.user_id
          WHERE lower(c.email) = lower(?) AND u.role <> 'admin' AND u.source <> ? AND u.approved = 1";
  $found = $loginId !== '' ? all($sql . " AND u.login_id = ?", [$email, SFPC_ACCOUNT_SOURCE, $loginId]) : all($sql . " ORDER BY u.id", [$email, SFPC_ACCOUNT_SOURCE]);
  $note = function (int $uid) use ($email, $ip) {     /* 요청이 있었다는 것만 남긴다 */
    db()->prepare("INSERT INTO sfpc_pw_resets(user_id, email, ip, used, created_at, expires_at) VALUES(?,?,?,1,?,?)")->execute([$uid, $email, $ip, now(), now()]);
  };
  if (!$found) { $note(0); return ['uid' => 0, 'err' => '']; }
  $title = sfpc_setting('sfpc_title');
  if (count($found) > 1) {
    /* 같은 이메일로 계정을 여러 개 만든 경우 — 아이디만 알려 주고, 아이디를 넣어 다시 요청하게 한다 */
    $note(0);
    if ((int)col("SELECT COUNT(*) FROM sfpc_mail_log WHERE to_email = ? AND kind = 'reset_ids' AND created_at > ?", [$email, $hourAgo]) < 2)
      sfpc_mail_send($email, '[SFPC] 가입하신 아이디 안내', $title . " 에 이 이메일로 가입한 아이디가 여러 개입니다.\r\n\r\n    "
        . implode("\r\n    ", array_column($found, 'login_id')) . "\r\n\r\n비밀번호를 다시 정하려면 아이디를 함께 적어 다시 요청해 주세요.\r\n", 'reset_ids');
    return ['uid' => 0, 'err' => ''];
  }
  $u = $found[0]; $uid = (int)$u['id'];
  $last = (string)col("SELECT created_at FROM sfpc_pw_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
  if ($last !== '' && time() - strtotime($last) < SFPC_CODE_WAIT) return ['uid' => $uid, 'err' => ''];          /* 방금 보냈다 — 또 보내지 않고 그 번호를 쓰게 한다 */
  if ((int)col("SELECT COUNT(*) FROM sfpc_pw_resets WHERE user_id = ? AND created_at > ?", [$uid, $hourAgo]) >= SFPC_RESET_PER_HOUR) return ['uid' => $uid, 'err' => ''];

  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $e = sfpc_mail_send($u['email'], '[SFPC] 비밀번호 다시 정하기 인증번호 ' . $code,
    $title . " 비밀번호를 다시 정하는 인증번호입니다.\r\n\r\n    아이디: " . $u['login_id'] . "\r\n    인증번호: " . $code . "\r\n\r\n"
    . "화면의 인증번호 칸에 위 여섯 자리를 입력하고 새 비밀번호를 정해 주세요. " . SFPC_CODE_MIN . "분 동안 쓸 수 있습니다.\r\n"
    . "직접 요청하지 않으셨다면 이 메일은 지우셔도 됩니다. 비밀번호는 바뀌지 않습니다.\r\n\r\n문의: " . sfpc_setting('sfpc_contact') . "\r\n", 'reset');
  if ($e === 'limit') return ['uid' => 0, 'err' => '오늘 보낼 수 있는 메일을 모두 보냈습니다. 내일 다시 시도하거나 운영진에게 문의해 주세요. (' . sfpc_setting('sfpc_contact') . ')'];
  if ($e !== '') { $note(0); return ['uid' => 0, 'err' => '']; }     /* 보내지 못한 까닭은 운영 기록(sfpc_mail_log)에만 남는다 */
  db()->prepare("UPDATE sfpc_pw_resets SET used = 1 WHERE user_id = ? AND used = 0")->execute([$uid]);
  db()->prepare("INSERT INTO sfpc_pw_resets(user_id, email, code_hash, ip, created_at, expires_at) VALUES(?,?,?,?,?,?)")
      ->execute([$uid, $u['email'], sfpc_code_hash($uid, 'reset' . $code), $ip, now(), date('Y-m-d H:i:s', time() + SFPC_CODE_MIN * 60)]);
  return ['uid' => $uid, 'err' => ''];
}
/* 인증번호가 맞으면 새 비밀번호를 정한다. '' 성공 · 그 밖은 사용자에게 보일 문구 */
function sfpc_reset_finish(int $uid, string $code, string $pw): string {
  $bad = '인증번호가 맞지 않거나 시간이 지났습니다. 메일의 여섯 자리를 다시 확인해 주세요.';
  $code = preg_replace('/\D/', '', $code);
  if (strlen($pw) < 8) return '새 비밀번호는 8자 이상이어야 합니다.';
  $row = $uid ? one("SELECT * FROM sfpc_pw_resets WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1", [$uid]) : null;
  if (!$row || $row['expires_at'] < now()) return $bad;
  if ((int)$row['tries'] >= SFPC_CODE_TRIES) return '여러 번 틀려 이 인증번호는 쓸 수 없습니다. 처음부터 다시 요청해 주세요.';
  if (strlen($code) !== 6 || !hash_equals($row['code_hash'], sfpc_code_hash($uid, 'reset' . $code))) {
    db()->prepare("UPDATE sfpc_pw_resets SET tries = tries + 1 WHERE id = ?")->execute([$row['id']]);
    return $bad;
  }
  $u = one("SELECT id, login_id, name, role, source FROM users WHERE id = ?", [$uid]);
  if (!$u || $u['role'] === 'admin' || $u['source'] === SFPC_ACCOUNT_SOURCE) return $bad;
  db()->prepare("UPDATE sfpc_pw_resets SET used = 1 WHERE id = ?")->execute([$row['id']]);
  db()->prepare("UPDATE users SET pw_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
  db()->prepare("DELETE FROM login_attempts WHERE login_id = ?")->execute([$u['login_id']]);
  /* 그 메일을 받았다는 것이 곧 주소 확인이다 */
  db()->prepare("UPDATE sfpc_coaches SET verified_email = email, verified_at = ? WHERE user_id = ? AND lower(email) = lower(?) AND lower(verified_email) <> lower(email)")
      ->execute([now(), $uid, $row['email']]);
  sfpc_log(null, $uid, $u, 'coach', '메일 인증으로 비밀번호를 다시 정함');
  return '';
}
