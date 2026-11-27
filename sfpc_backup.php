<?php
/* ═══════════════════════════════════════════════
   sfpc_backup.php — DB 백업과 복구 (sfpc-cli.php 가 불러 쓴다. 웹에서는 열리지 않는다)

       sudo -u www-data php sfpc-cli.php backup      지금 백업 (매일 새벽 4시에 저절로 돈다 — install.sh 가 타이머를 건다)
       sudo -u www-data php sfpc-cli.php backupkey   메일로 보낼 백업 파일의 암호를 정한다 (화면에 찍히지 않는다)
       sudo -u www-data php sfpc-cli.php restore 파일   백업 파일로 되돌린다

   백업은 두 곳에 남는다.
     · 서버 안: DATA_DIR/backups/sfpc-날짜-시각.db.gz — 14일치
     · 서버 밖: 인증 메일을 보내는 계정(sfpc-mail.json)의 받은편지함으로 암호를 건 파일을 첨부해 보낸다.
       암호를 정하지 않았으면 메일로는 보내지 않는다 — 접수 자료(연락처 · 학생 이름)가 그대로 메일에 실리지 않게.

   암호를 건 파일은 흔한 도구로 풀 수 있다:
       openssl enc -d -aes-256-cbc -pbkdf2 -iter 10000 -md sha256 -in sfpc-….db.gz.enc -out sfpc-….db.gz
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
if (!defined('DATA_DIR')) { http_response_code(404); exit; }

const SFPC_BACKUP_DIR  = DATA_DIR . '/backups';
const SFPC_BACKUP_KEY  = DATA_DIR . '/sfpc-backup.json';   /* {"pass": "..."} — 0600 */
const SFPC_BACKUP_DAYS = 14;
const SFPC_BACKUP_MAIL_MAX = 17 * 1024 * 1024;             /* 첨부는 이 크기까지 (메일 한 통 25MB · base64 로 1.37배가 된다) */
const SFPC_BACKUP_STATE = SFPC_BACKUP_DIR . '/last.json';  /* 마지막 백업의 결과 — 운영 화면이 읽는다 */

function sfpc_backup_pass(): string {
  $j = is_file(SFPC_BACKUP_KEY) ? json_decode((string)@file_get_contents(SFPC_BACKUP_KEY), true) : null;
  return is_array($j) ? (string)($j['pass'] ?? '') : '';
}
/* openssl enc -aes-256-cbc -pbkdf2 -salt 와 같은 꼴 ("Salted__" + 소금 8바이트 + 암호문, PBKDF2-SHA256 10000번) */
function sfpc_enc(string $data, string $pass): string {
  $salt = random_bytes(8);
  $ki = hash_pbkdf2('sha256', $pass, $salt, 10000, 48, true);
  return 'Salted__' . $salt . openssl_encrypt($data, 'aes-256-cbc', substr($ki, 0, 32), OPENSSL_RAW_DATA, substr($ki, 32, 16));
}
function sfpc_dec(string $blob, string $pass): ?string {
  if (!str_starts_with($blob, 'Salted__') || strlen($blob) < 32) return null;
  $ki = hash_pbkdf2('sha256', $pass, substr($blob, 8, 8), 10000, 48, true);
  $d = openssl_decrypt(substr($blob, 16), 'aes-256-cbc', substr($ki, 0, 32), OPENSSL_RAW_DATA, substr($ki, 32, 16));
  return $d === false ? null : $d;
}
/* 백업 파일이 멀쩡한 DB 인지: [괜찮은가, 설명]. 접수 표가 있고 무결성 검사를 통과해야 한다. */
function sfpc_db_check(string $file): array {
  try {
    $p = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ic = (string)$p->query("PRAGMA integrity_check")->fetchColumn();
    if ($ic !== 'ok') return [false, '무결성 검사 실패: ' . $ic];
    $teams = (int)$p->query("SELECT COUNT(*) FROM sfpc_teams")->fetchColumn();
    $coaches = (int)$p->query("SELECT COUNT(*) FROM sfpc_coaches")->fetchColumn();
    $users = (int)$p->query("SELECT COUNT(*) FROM users")->fetchColumn();
    return [true, "팀 $teams · 지도교사 $coaches · 계정 $users"];
  } catch (Throwable $e) { return [false, $e->getMessage()]; }
}

/* 지금 백업한다. 돌려주는 값: ['ok' => bool, 'file' => 경로, 'size' => 바이트, 'info' => 내용 요약, 'mail' => 'sent'|'nokey'|'off'|'big'|오류, 'err' => 문구] */
function sfpc_backup_run(): array {
  global $CFG;
  $r = ['ok' => false, 'file' => '', 'size' => 0, 'info' => '', 'mail' => '', 'err' => '', 'at' => now()];
  if (!is_dir(SFPC_BACKUP_DIR)) { $old = umask(0077); @mkdir(SFPC_BACKUP_DIR, 0700, true); umask($old); }
  $old = umask(0077);
  try {
    $stamp = date('Ymd-Hi');
    $tmp = SFPC_BACKUP_DIR . '/tmp-' . $stamp . '-' . bin2hex(random_bytes(3)) . '.db';
    /* VACUUM INTO: 접수가 들어오는 중에도 한 순간의 모습을 온전한 파일 하나로 떠 준다 (WAL 포함) */
    db()->exec("VACUUM INTO " . db()->quote($tmp));
    [$good, $info] = sfpc_db_check($tmp);
    if (!$good) { @unlink($tmp); throw new RuntimeException('백업한 파일이 온전하지 않습니다 — ' . $info); }
    $gz = gzencode((string)file_get_contents($tmp), 6);
    @unlink($tmp);
    $file = SFPC_BACKUP_DIR . '/sfpc-' . $stamp . '.db.gz';
    if (file_put_contents($file, $gz) !== strlen($gz)) throw new RuntimeException('백업 파일을 쓰지 못했습니다 (디스크가 가득 찼는지 확인하세요)');
    $r = ['ok' => true, 'file' => $file, 'size' => strlen($gz), 'info' => $info] + $r;

    /* 오래된 것 지우기 — 새 백업이 성공한 뒤에만 */
    foreach (glob(SFPC_BACKUP_DIR . '/sfpc-*.db.gz') ?: [] as $f) if (filemtime($f) < time() - SFPC_BACKUP_DAYS * 86400) @unlink($f);
    foreach (glob(SFPC_BACKUP_DIR . '/tmp-*.db') ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f);

    /* 서버 밖으로 한 벌 */
    $pass = sfpc_backup_pass(); $c = sfpc_mail_conf();
    if (!$c)               $r['mail'] = 'off';
    elseif ($pass === '')  $r['mail'] = 'nokey';
    else {
      $enc = sfpc_enc($gz, $pass);
      $name = basename($file) . '.enc';
      $title = sfpc_setting('sfpc_title');
      $kb = number_format(strlen($gz) / 1024, 0);
      $body = $title . " 접수 자료 백업입니다.\r\n\r\n"
            . "만든 때: " . $r['at'] . "\r\n내용: " . $info . "\r\n크기: " . $kb . " KB\r\n\r\n";
      if (strlen($enc) > SFPC_BACKUP_MAIL_MAX) {
        $e = sfpc_mail_send($c['user'], '[SFPC 백업] ' . date('Y-m-d') . ' — 파일이 커서 첨부하지 못했습니다',
          $body . "파일이 메일로 보내기에 너무 큽니다. 서버 안에는 저장되어 있습니다 (" . basename($file) . ").\r\n서버 밖 보관 방법을 바꿔야 합니다.\r\n", 'backup');
        $r['mail'] = $e === '' ? 'big' : $e;
      } else {
        $e = sfpc_mail_send($c['user'], '[SFPC 백업] ' . date('Y-m-d H:i') . ' · ' . $info,
          $body . "첨부 파일은 암호가 걸려 있습니다. 푸는 방법:\r\n  openssl enc -d -aes-256-cbc -pbkdf2 -iter 10000 -md sha256 -in " . $name . " -out " . basename($file) . "\r\n"
                . "되돌리는 방법: 서버에서  sudo -u www-data php sfpc-cli.php restore (파일)\r\n", 'backup', ['name' => $name, 'data' => $enc]);
        $r['mail'] = $e === '' ? 'sent' : $e;
      }
    }
  } catch (Throwable $e) {
    $r['ok'] = false; $r['err'] = $e->getMessage();
  }
  @file_put_contents(SFPC_BACKUP_STATE, json_encode(['at' => $r['at'], 'ok' => $r['ok'], 'file' => basename($r['file']), 'size' => $r['size'], 'info' => $r['info'], 'mail' => $r['mail'], 'err' => $r['err']], JSON_UNESCAPED_UNICODE));
  umask($old);
  return $r;
}
function sfpc_backup_last(): ?array {
  $j = is_file(SFPC_BACKUP_STATE) ? json_decode((string)@file_get_contents(SFPC_BACKUP_STATE), true) : null;
  return is_array($j) ? $j : null;
}
const SFPC_BACKUP_MAIL_NAME = ['sent' => '메일로도 보냄', 'nokey' => '메일로는 안 보냄 (암호를 정하지 않음)', 'off' => '메일로는 안 보냄 (메일이 꺼져 있음)', 'big' => '파일이 커서 메일에 첨부하지 못함', '' => ''];

/* 백업 파일(.db.gz 또는 .db.gz.enc)을 풀어 DB 내용으로 돌려준다. [내용|null, 오류] */
function sfpc_backup_open(string $file, string $pass = ''): array {
  $raw = @file_get_contents($file);
  if ($raw === false) return [null, '파일을 읽지 못했습니다.'];
  if (str_starts_with($raw, 'Salted__')) {
    $raw = sfpc_dec($raw, $pass);
    if ($raw === null) return [null, '암호가 맞지 않습니다.'];
  }
  $db = @gzdecode($raw);
  if ($db === false || !str_starts_with($db, 'SQLite format 3')) return [null, '백업 파일이 아니거나 암호가 맞지 않습니다.'];
  return [$db, ''];
}
