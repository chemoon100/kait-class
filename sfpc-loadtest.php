<?php
/* ═══════════════════════════════════════════════
   sfpc-loadtest.php — 채점 부하 시험 (CLI 전용)

   대회처럼 "정답 · 오답 · 시간 초과 · 런타임 오류 · 컴파일 오류가 섞인 제출"을
   정해진 속도로 대기열에 넣고, 제출부터 판정까지 얼마나 걸리는지 잰다.
   웹 화면을 거치지 않고 제출 표에 바로 넣는다 — 채점 계층(일꾼 · 채점 서버)만 본다.
   웹 계층까지 함께 보려면 원본의 loadtest.php 를 쓴다.

   쓰는 법 (웹과 같은 계정으로):
     sudo -u www-data php sfpc-loadtest.php --setup
     sudo -u www-data php sfpc-loadtest.php --run --rate=65 --minutes=3
     sudo -u www-data php sfpc-loadtest.php --cleanup

     --rate=65      1분에 넣을 제출 수 (작년 대회: 평균 65 · 가장 바쁜 10분 110 · 가장 바쁜 1분 188)
     --minutes=3    넣는 시간. 다 넣은 뒤 대기열이 빌 때까지(최대 --drain 분) 기다렸다가 결과를 적는다
     --mix=last     last = 작년 판정 비율(AC 22 · WA 65 · RE 8 · TLE 3 · CE 2), tle = 시간 초과가 많은 최악의 경우
     --set=light    light = 가벼운 문제 · heavy = 무거운 문제(제한 시간의 절반을 쓰는 계산 · 0.6MB 출력 · 150MB 메모리 · 케이스 50개) · all
     --lazy=1       틀리면 멈추기를 켜고(1) 끄고(0) 시작한다. 적지 않으면 지금 설정 그대로
     --label=이름   결과에 붙일 이름

     sudo -u www-data php sfpc-loadtest.php --bad      나쁜 코드 16가지를 내 보고, 그동안 정상 풀이의 실행 시간이 흔들리는지 본다

   시험용 계정(ltsfpc001…)과 문제(990001~990007, 잠긴 상태)는 --cleanup 으로 모두 지운다.
   진행 상황과 결과는 DATA_DIR/loadtest/ 에 JSON 으로 남고, 서버 모니터링 화면에서 본다.
   화면에는 영문 한 줄씩만 찍는다 (서버 콘솔은 한글을 못 그린다).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/judge_lib.php';

$opt = getopt('', ['setup', 'run', 'bad', 'cleanup', 'rate::', 'minutes::', 'mix::', 'label::', 'drain::', 'users::', 'set::', 'lazy::']);
const LT_PREFIX = 'ltsfpc';
/* 문제 번호 => [종류, 케이스 수, 시간 제한(초), 메모리 제한(KB), 묶음]
   sum  : N 을 읽어 1²+…+N² mod 1,000,000,007. 가벼운 문제 (파이썬 0.05~0.3초)
   heavy: 같은 문제인데 N 이 크다. 파이썬 정답이 제한 시간의 절반쯤을 쓴다 (0.6~1.4초 / 3초)
   out  : 1 부터 N 까지 한 줄씩 출력. 출력이 약 0.6MB (Judge0 기본 상한 1MB 바로 아래)
   mem  : N MB 를 잡아 채운 뒤 N 을 출력. 150~180MB (제한 256MB) */
const LT_PROBS  = [
  990001 => ['sum',   10, 1.0, 256000, 'light'], 990002 => ['sum', 20, 2.0, 256000, 'light'], 990003 => ['sum', 30, 2.0, 256000, 'light'],
  990004 => ['heavy', 20, 3.0, 256000, 'heavy'], 990005 => ['out', 10, 2.0, 256000, 'heavy'], 990006 => ['mem', 10, 2.0, 256000, 'heavy'],
  990007 => ['sum',   50, 2.0, 256000, 'heavy'],
];
const LT_MOD    = 1000000007;
$dir = DATA_DIR . '/loadtest';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
function say(string $s): void { fwrite(STDOUT, $s . "\n"); }

/* 문제: N 을 읽어 1²+2²+…+N² 을 1,000,000,007 로 나눈 나머지를 출력한다.
   파이썬 정답은 케이스마다 0.05~0.3초쯤 계산한다 — 아무 일도 안 하는 "Hello" 보다 대회 문제에 가깝다. */
function lt_answer(int $n): int { $s = 0; for ($i = 1; $i <= $n; $i++) $s = ($s + $i * $i) % LT_MOD; return $s; }

if (isset($opt['setup'])) {
  $nUsers = max(1, min(500, (int)($opt['users'] ?? 120)));
  tx(function (PDO $d) use ($nUsers) {
    $pw = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
    $u = $d->prepare("INSERT OR IGNORE INTO users(login_id,pw_hash,name,role,created_at) VALUES(?,?,?,'student',?)");
    for ($i = 1; $i <= $nUsers; $i++) $u->execute([sprintf('%s%03d', LT_PREFIX, $i), $pw, 'LOADTEST ' . $i, now()]);
    foreach (LT_PROBS as $no => [$type, $cases, $tl, $ml]) {
      if ($d->query("SELECT id FROM problems WHERE prob_no = $no")->fetchColumn()) continue;
      $d->prepare("INSERT INTO problems(prob_no,title,description,input_desc,output_desc,time_limit,memory_limit,active,show_diff,created_at) VALUES(?,?,?,?,?,?,?,0,0,?)")
        ->execute([$no, "[부하 시험] {$type} ({$cases}케이스)", '부하 시험용 문제입니다. 학생에게 보이지 않습니다.', 'N', '', $tl, $ml, now()]);
      $pid = (int)$d->lastInsertId();
      $t = $d->prepare("INSERT INTO testcases(problem_id,seq,input,expected,is_sample) VALUES(?,?,?,?,0)");
      mt_srand($no);
      for ($k = 1; $k <= $cases; $k++) {
        if ($type === 'sum')       { $n = mt_rand(200000, 1000000);  $exp = lt_answer($n) . "\n"; }
        elseif ($type === 'heavy') { $n = mt_rand(2000000, 4500000); $exp = lt_answer($n) . "\n"; }
        elseif ($type === 'out')   { $n = mt_rand(90000, 100000);    $exp = implode("\n", range(1, $n)) . "\n"; }
        else                       { $n = mt_rand(150, 180);         $exp = $n . "\n"; }
        $t->execute([$pid, $k, $n . "\n", $exp]);
      }
    }
  });
  say('setup ok: users=' . (int)col("SELECT COUNT(*) FROM users WHERE login_id LIKE ?", [LT_PREFIX . '%']) . ' problems=' . count(LT_PROBS));
  exit(0);
}

if (isset($opt['cleanup'])) {
  $n = tx(function (PDO $d) {
    $uids = "SELECT id FROM users WHERE login_id LIKE '" . LT_PREFIX . "%'";
    $n = $d->exec("DELETE FROM submissions WHERE user_id IN ($uids)");
    foreach (array_keys(LT_PROBS) as $no) {
      $pid = (int)$d->query("SELECT id FROM problems WHERE prob_no = $no")->fetchColumn();
      if (!$pid) continue;
      $d->exec("DELETE FROM submissions WHERE problem_id = $pid");
      $d->exec("DELETE FROM testcases WHERE problem_id = $pid");
      $d->exec("DELETE FROM problems WHERE id = $pid");
    }
    $d->exec("DELETE FROM users WHERE login_id LIKE '" . LT_PREFIX . "%'");
    return $n;
  });
  say("cleanup ok: submissions removed=$n");
  exit(0);
}

if (!isset($opt['run']) && !isset($opt['bad'])) { say('usage: --setup | --run --rate=65 --minutes=3 [--mix=last|tle] [--set=light|heavy|all] [--lazy=0|1] [--label=x] | --bad | --cleanup'); exit(1); }

/* ── 넣을 코드 ── */
function lt_source(string $kind, string $lang, int $nonce, string $type = 'sum'): string {
  /* 정답의 본문 (문제 종류마다 다르다). 오답은 같은 계산을 하고 틀린 값을 낸다. */
  if ($lang === 'c') {
    $head = "/* expect:$kind #$nonce */\n#include <stdio.h>\n#include <stdlib.h>\n#include <string.h>\nint main(){long long n,s=0,i;if(scanf(\"%lld\",&n)!=1)return 0;\n";
    $body = ['sum' => "for(i=1;i<=n;i++)s=(s+i*i)%1000000007LL;", 'heavy' => "for(i=1;i<=n;i++)s=(s+i*i)%1000000007LL;",
             'out' => "for(i=1;i<n;i++)printf(\"%lld\\n\",i);s=n;", 'mem' => "{char*p=malloc(n<<20);if(!p)return 1;memset(p,7,n<<20);s=n+p[n]-7;}"][$type];
    return match ($kind) {
      'AC'  => $head . $body . "printf(\"%lld\\n\",s);return 0;}\n",
      'WA'  => $head . $body . "printf(\"%lld\\n\",s+1);return 0;}\n",
      'TLE' => $head . "for(;;){s+=n;}return 0;}\n",
      'RE'  => $head . "{volatile int *p=0;*p=(int)n;}return 0;}\n",
      'CE'  => $head . "printf(\"%lld\\n\",s)\nreturn 0;}\n",
    };
  }
  $head = "# expect:$kind #$nonce\nn = int(input())\n";
  $body = ['sum' => "s = 0\nfor i in range(1, n + 1):\n    s = (s + i * i) % 1000000007\n", 'heavy' => "s = 0\nfor i in range(1, n + 1):\n    s = (s + i * i) % 1000000007\n",
           'out' => "print('\\n'.join(map(str, range(1, n))))\ns = n\n", 'mem' => "b = bytearray(b'x') * (n << 20)\ns = n + b[n] - 120\n"][$type];
  return match ($kind) {
    'AC'  => $head . $body . "print(s)\n",
    'WA'  => $head . $body . "print(s + 1)\n",
    'TLE' => $head . "while True:\n    n += 1\n",
    'RE'  => $head . "print(1 // (n - n))\n",
    'CE'  => $head . "print(s\n",          /* 파이썬은 컴파일 단계가 없어 런타임 오류로 나온다 */
  };
}
/* 틀리면 멈추기를 켜고 끄기 (채점 일꾼이 5초 안에 읽는다) */
if (isset($opt['lazy'])) { setting_set('judge_lazy', (string)$opt['lazy'] === '0' ? '0' : '1'); say('judge_lazy=' . setting('judge_lazy') . ' (wait 6s)'); sleep(6); }

/* ═══ 나쁜 코드 ═══════════════════════════════════
   채점 서버를 괴롭히는 코드를 하나씩 내서 (1) 채점 오류 없이 판정이 나는지, (2) 얼마 만에 끊기는지,
   (3) 그동안 옆에서 채점되던 정상 풀이의 실행 시간이 흔들리지 않는지를 본다.
   net · conf 는 "막혀 있는지"만 한 낱말로 출력한다 — 읽은 내용은 절대 출력하지 않는다. */
const LT_BAD = [
  'loop'     => ['py', "while True:\n    pass\n", '무한 반복'],
  'sleep'    => ['py', "import time\ntime.sleep(3600)\n", '아무것도 안 하고 멈춰 있기'],
  'membomb'  => ['py', "a = []\nwhile True:\n    a.append(bytearray(b'x') * (10 << 20))\n", '메모리를 끝없이 잡기'],
  'forkbomb' => ['c',  "#include <unistd.h>\nint main(){for(;;)fork();return 0;}\n", '프로세스를 끝없이 만들기 (C)'],
  'pyfork'   => ['py', "import os\nwhile True:\n    try:\n        os.fork()\n    except OSError:\n        pass\n", '프로세스를 끝없이 만들기 (파이썬)'],
  'threads'  => ['py', "import threading, time\nfor i in range(100000):\n    threading.Thread(target=time.sleep, args=(60,)).start()\n", '스레드를 끝없이 만들기'],
  'outflood' => ['py', "import sys\nx = 'x' * 65536\nwhile True:\n    sys.stdout.write(x)\n", '출력을 끝없이 쏟기'],
  'errflood' => ['py', "import sys\nx = 'x' * 65536\nwhile True:\n    sys.stderr.write(x)\n", '오류 출력을 끝없이 쏟기'],
  'bigfile'  => ['py', "f = open('big.bin', 'wb')\nx = b'x' * (1 << 20)\nwhile True:\n    f.write(x)\n", '큰 파일 만들기'],
  'tmpfile'  => ['py', "f = open('/tmp/big.bin', 'wb')\nx = b'x' * (1 << 20)\nwhile True:\n    f.write(x)\n", '/tmp 에 큰 파일 만들기'],
  'net'      => ['py', "import socket\ntry:\n    socket.create_connection(('1.1.1.1', 80), 3).close()\n    print('NET-OPEN')\nexcept Exception:\n    print('NET-BLOCKED')\n", '인터넷 접속'],
  'conf'     => ['py', "import os\nr = []\nfor p in ['/judge0.conf', '/api/judge0.conf', '/etc/shadow', '/proc/1/environ']:\n    try:\n        open(p, 'rb').read(1)\n        r.append('READ')\n    except Exception:\n        r.append('NO')\nprint('FILES-' + '-'.join(r))\n", '서버의 설정 파일 읽기'],
  'kill'     => ['py', "import os, signal\nn = 0\nfor pid in range(1, 400):\n    try:\n        os.kill(pid, signal.SIGKILL)\n        n += 1\n    except Exception:\n        pass\nprint('KILL-DONE')\n", '다른 프로세스 죽이기'],
  'cbig'     => ['c',  "#include <stdio.h>\nstatic char a[1<<30];\nint main(){for(long i=0;i<(1L<<30);i+=4096)a[i]=1;printf(\"%d\\n\",a[4096]);return 0;}\n", '1GB 배열 (C)'],
  'deeprec'  => ['c',  "#include <stdio.h>\nint f(int n){volatile char b[4096];b[0]=n;return f(n+1)+b[0];}\nint main(){printf(\"%d\\n\",f(0));return 0;}\n", '끝없는 재귀 (C)'],
  'incl'     => ['c',  "#include \"/dev/zero\"\nint main(){return 0;}\n", '컴파일을 끝없이 끌기 (C)'],
];
if (isset($opt['bad'])) {
  $uids = array_map('intval', array_column(all("SELECT id FROM users WHERE login_id LIKE ? ORDER BY id", [LT_PREFIX . '%']), 'id'));
  $pBad = (int)col("SELECT id FROM problems WHERE prob_no = 990001"); $pCan = (int)col("SELECT id FROM problems WHERE prob_no = 990004");
  if (!$uids || !$pBad || !$pCan) { say('run --setup first'); exit(1); }
  $ins = db()->prepare("INSERT INTO submissions(user_id,problem_id,set_id,lang,source_code,source_hash,state,created_at,queued_at) VALUES(?,?,NULL,?,?,?, 'pending', ?,?)");
  $put = function (int $pid, string $lang, string $src) use ($ins, $uids) { static $k = 0; $ins->execute([$uids[$k++ % count($uids)], $pid, $lang, $src, source_hash($src), now(), now()]); return (int)db()->lastInsertId(); };
  $wait = function (array $ids, int $sec) { $t = time(); do { $left = (int)col("SELECT COUNT(*) FROM submissions WHERE id IN (" . implode(',', $ids) . ") AND state NOT IN ('done','error')"); if (!$left) return true; sleep(2); } while (time() - $t < $sec); return false; };
  $canary = function (string $tag, int $n) use ($put, $pCan) { $ids = []; for ($i = 0; $i < $n; $i++) { $ids[] = $put($pCan, 'c', lt_source('AC', 'c', mt_rand(), 'heavy') . "/* canary:$tag */\n"); $ids[] = $put($pCan, 'py', lt_source('AC', 'py', mt_rand(), 'heavy') . "# canary:$tag\n"); } return $ids; };
  $cstat = function (array $ids) { $rows = all("SELECT lang, verdict, max_time FROM submissions WHERE id IN (" . implode(',', $ids) . ")"); $o = [];
    foreach (['c', 'py'] as $l) { $t = array_map(fn($r) => (float)$r['max_time'], array_filter($rows, fn($r) => $r['lang'] === $l)); $o[$l] = $t ? ['n' => count($t), 'avg' => round(array_sum($t) / count($t), 3), 'max' => round(max($t), 3)] : null; }
    $o['not_ac'] = count(array_filter($rows, fn($r) => $r['verdict'] !== 'AC')); return $o; };
  $t0 = time(); $prog = fn(string $ph) => @file_put_contents($dir . '/progress.json', json_encode(['label' => 'bad', 'phase' => $ph, 'at' => time(), 't' => time() - $t0]), LOCK_EX);
  say('bad: canary before'); $prog('before');
  $before = $canary('before', 4); $okB = $wait($before, 300);
  say('bad: sending ' . count(LT_BAD) . ' bad codes + canary'); $prog('bad');
  $bad = []; foreach (LT_BAD as $k => [$lang, $src]) $bad[$k] = $put($pBad, $lang, ($lang === 'c' ? "/* bad:$k */\n" : "# bad:$k\n") . $src);
  $during = $canary('during', 6);
  $okD = $wait(array_merge(array_values($bad), $during), 900);
  say('bad: canary after'); $prog('after');
  $after = $canary('after', 4); $okA = $wait($after, 300);
  $list = [];
  foreach ($bad as $k => $id) {
    $r = one("SELECT state, verdict, passed_count, max_time, max_memory, fail_output, compile_msg, created_at, judged_at" . ((bool)db()->query("SELECT 1 FROM pragma_table_info('submissions') WHERE name = 'dispatched_at'")->fetchColumn() ? ", dispatched_at" : ", created_at AS dispatched_at") . " FROM submissions WHERE id = ?", [$id]);
    $mark = ''; if (preg_match('/(NET-[A-Z]+|FILES-[A-Z-]+|KILL-DONE)/', (string)($r['fail_output'] ?? ''), $m)) $mark = $m[1];
    $list[] = ['kind' => $k, 'what' => LT_BAD[$k][2], 'lang' => LT_BAD[$k][0], 'state' => $r['state'], 'verdict' => $r['verdict'], 'time' => $r['max_time'], 'memory_kb' => $r['max_memory'],
               'judge_sec' => $r['judged_at'] && $r['dispatched_at'] ? strtotime($r['judged_at']) - strtotime($r['dispatched_at']) : null, 'mark' => $mark,
               'note' => $r['verdict'] === 'IE' ? mb_substr((string)$r['compile_msg'], 0, 120) : ''];
    say(sprintf('  %-9s %-4s %-5s %ss %s', $k, $r['verdict'] ?? '-', $r['state'], $list[count($list) - 1]['judge_sec'] ?? '-', $mark));
  }
  $res = ['label' => 'bad', 'at' => date('Y-m-d H:i:s', $t0), 'total_sec' => time() - $t0, 'finished' => $okB && $okD && $okA, 'bad' => $list,
          'canary' => ['before' => $cstat($before), 'during' => $cstat($during), 'after' => $cstat($after)], 'lazy' => setting('judge_lazy') !== '0'];
  @file_put_contents($dir . '/' . date('Ymd-His', $t0) . '-bad.json', json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX); $prog('end');
  say('END bad: ie=' . count(array_filter($list, fn($x) => $x['verdict'] === 'IE')) . ' unfinished=' . count(array_filter($list, fn($x) => !in_array($x['state'], ['done', 'error'], true)))
      . ' canary not_ac=' . ($res['canary']['before']['not_ac'] + $res['canary']['during']['not_ac'] + $res['canary']['after']['not_ac']) . ' c max before/during/after=' . ($res['canary']['before']['c']['max'] ?? '-') . '/' . ($res['canary']['during']['c']['max'] ?? '-') . '/' . ($res['canary']['after']['c']['max'] ?? '-'));
  exit(0);
}
$mixName = (string)($opt['mix'] ?? 'last');
$MIX = $mixName === 'tle' ? ['AC' => 20, 'WA' => 45, 'RE' => 8, 'TLE' => 25, 'CE' => 2] : ['AC' => 22, 'WA' => 65, 'RE' => 8, 'TLE' => 3, 'CE' => 2];
function lt_pick(array $w): string { $r = mt_rand(1, array_sum($w)); foreach ($w as $k => $v) { if (($r -= $v) <= 0) return $k; } return array_key_first($w); }

$rate = max(1, min(2000, (int)($opt['rate'] ?? 65)));
$minutes = max(0.2, min(30, (float)($opt['minutes'] ?? 3)));
$drainMax = max(1, min(60, (int)($opt['drain'] ?? 15))) * 60;
$label = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($opt['label'] ?? '')) ?: ('r' . $rate . 'm' . $minutes);
$lazyNow = setting('judge_lazy') !== '0';

$uids = array_map('intval', array_column(all("SELECT id FROM users WHERE login_id LIKE ? ORDER BY id", [LT_PREFIX . '%']), 'id'));
$setName = (string)($opt['set'] ?? 'light');       /* light = 가벼운 문제 3개 · heavy = 무거운 문제 4개 · all = 모두 */
$pids = []; $ptype = [];
foreach (LT_PROBS as $no => $def) {
  if ($setName !== 'all' && $def[4] !== $setName) continue;
  $pid = (int)col("SELECT id FROM problems WHERE prob_no = ?", [$no]); if ($pid) { $pids[] = $pid; $ptype[$pid] = $def[0]; }
}
if (!$uids || !$pids) { say('run --setup first'); exit(1); }
$cases = []; foreach ($pids as $pid) $cases[$pid] = (int)col("SELECT COUNT(*) FROM testcases WHERE problem_id = ?", [$pid]);

$hasPool = (bool)db()->query("SELECT 1 FROM pragma_table_info('submissions') WHERE name = 'dispatched_at'")->fetchColumn();
$startId = (int)col("SELECT COALESCE(MAX(id), 0) FROM submissions");
$t0 = microtime(true); $total = (int)round($rate * $minutes); $sent = 0; $series = []; $lastTick = 0;
$ins = db()->prepare("INSERT INTO submissions(user_id,problem_id,set_id,lang,source_code,source_hash,state,created_at,queued_at) VALUES(?,?,NULL,?,?,?, 'pending', ?,?)");
$idList = "user_id IN (" . implode(',', $uids) . ") AND id > $startId";
$status = function (string $phase) use (&$sent, &$series, $t0, $idList, $dir, $label, $rate, $minutes, $total, $mixName) {
  $r = one("SELECT SUM(state='pending') p, SUM(state='judging') j, SUM(state IN ('done','error')) d, SUM(verdict='IE') ie FROM submissions WHERE $idList");
  $row = ['t' => (int)round(microtime(true) - $t0), 'sent' => $sent, 'pending' => (int)$r['p'], 'judging' => (int)$r['j'], 'done' => (int)$r['d'], 'ie' => (int)$r['ie']];
  $series[] = $row;
  @file_put_contents($dir . '/progress.json', json_encode(['label' => $label, 'phase' => $phase, 'rate' => $rate, 'minutes' => $minutes, 'mix' => $mixName, 'total' => $total, 'at' => time()] + $row), LOCK_EX);
  say(sprintf('%4ds %-5s sent=%d pending=%d judging=%d done=%d ie=%d', $row['t'], $phase, $row['sent'], $row['pending'], $row['judging'], $row['done'], $row['ie']));
  return $row;
};

say("run label=$label rate=$rate/min minutes=$minutes mix=$mixName set=$setName lazy=" . ($lazyNow ? 1 : 0) . " total=$total");
mt_srand((int)(microtime(true) * 1000) & 0x7fffffff);
while ($sent < $total) {
  $due = (int)floor((microtime(true) - $t0) * $rate / 60) + 1;
  while ($sent < min($due, $total)) {
    $kind = lt_pick($MIX);
    $lang = ($kind === 'CE' || mt_rand(1, 100) <= 14) ? 'c' : 'py';
    $pid = $pids[mt_rand(0, count($pids) - 1)];
    $src = lt_source($kind, $lang, $startId * 100000 + $sent, $ptype[$pid]);
    $ins->execute([$uids[$sent % count($uids)], $pid, $lang, $src, source_hash($src), now(), now()]);
    $sent++;
  }
  if (time() - $lastTick >= 5) { $lastTick = time(); $status('send'); }
  usleep(100000);
}
$tSent = microtime(true) - $t0;
while (microtime(true) - $t0 < $tSent + $drainMax) {
  $r = $status('drain');
  if ($r['pending'] + $r['judging'] === 0) break;
  sleep(5);
}
$tAll = microtime(true) - $t0;

/* ── 결과 ── */
$sel = "id, problem_id, lang, source_code, state, verdict, created_at, judged_at" . ($hasPool ? ", dispatched_at, judge_srv, judge_tries" : "");
$rows = all("SELECT $sel FROM submissions WHERE $idList ORDER BY id");
$pct = function (array $a, float $p) { if (!$a) return null; sort($a); return $a[(int)min(count($a) - 1, floor($p * count($a)))]; };
$wait = []; $queue = []; $judge = []; $ver = []; $bySrv = []; $mismatch = []; $moved = 0; $left = 0; $caseSum = 0; $perMin = [];
foreach ($rows as $r) {
  $caseSum += $cases[(int)$r['problem_id']] ?? 0;
  if (!in_array($r['state'], ['done', 'error'], true)) { $left++; continue; }
  $v = (string)($r['verdict'] ?? '?'); $ver[$v] = ($ver[$v] ?? 0) + 1;
  preg_match('/expect:([A-Z]+)/', (string)$r['source_code'], $m); $exp = $m[1] ?? '?';
  $okSet = ['AC' => ['AC'], 'WA' => ['WA'], 'TLE' => ['TLE'], 'RE' => ['RE', 'MLE'], 'CE' => $r['lang'] === 'c' ? ['CE'] : ['RE', 'CE']][$exp] ?? [];
  if (!in_array($v, $okSet, true)) { $k = "$exp->$v"; $mismatch[$k] = ($mismatch[$k] ?? 0) + 1; }
  $c = strtotime((string)$r['created_at']); $j = strtotime((string)$r['judged_at']);
  if ($c && $j) { $wait[] = $j - $c; $perMin[(int)floor(($j - (int)$t0) / 60)] = ($perMin[(int)floor(($j - (int)$t0) / 60)] ?? 0) + 1; }
  if ($hasPool) {
    $d = strtotime((string)($r['dispatched_at'] ?? ''));
    if ($c && $d) $queue[] = max(0, $d - $c);
    if ($d && $j) $judge[] = max(0, $j - $d);
    $s = (string)($r['judge_srv'] ?? ''); $bySrv[$s] = ($bySrv[$s] ?? 0) + 1;
    if ((int)($r['judge_tries'] ?? 1) > 1) $moved++;
  }
}
$names = [];
if ($hasPool && is_file(__DIR__ . '/judge_pool.php')) { require_once __DIR__ . '/judge_pool.php'; foreach (pool_servers(true) as $k => $s) $names[$k] = $s['name']; }
$bySrvN = []; foreach ($bySrv as $k => $n) $bySrvN[$names[$k] ?? ($k === '' ? '(none)' : $k)] = $n;
$stat = fn(array $a) => $a ? ['avg' => round(array_sum($a) / count($a), 1), 'p50' => $pct($a, .5), 'p95' => $pct($a, .95), 'max' => max($a)] : null;
$maxBacklog = 0; foreach ($series as $s) $maxBacklog = max($maxBacklog, $s['pending'] + $s['judging']);
$res = [
  'label' => $label, 'at' => date('Y-m-d H:i:s', (int)$t0), 'rate' => $rate, 'minutes' => $minutes, 'mix' => $mixName, 'set' => $setName, 'lazy' => $lazyNow,
  'sent' => $sent, 'cases_avg' => $sent ? round($caseSum / $sent, 1) : 0, 'judged' => count($rows) - $left, 'left' => $left,
  'send_sec' => round($tSent), 'total_sec' => round($tAll), 'drain_sec' => round($tAll - $tSent),
  'throughput_per_min' => $tAll > 0 ? round((count($rows) - $left) / $tAll * 60, 1) : 0, 'best_minute' => $perMin ? max($perMin) : 0,
  'max_backlog' => $maxBacklog, 'wait' => $stat($wait), 'queue_wait' => $stat($queue), 'judge_time' => $stat($judge),
  'verdicts' => $ver, 'mismatch' => $mismatch, 'ie' => $ver['IE'] ?? 0, 'moved' => $moved, 'by_server' => $bySrvN, 'series' => $series,
];
@file_put_contents($dir . '/' . date('Ymd-His', (int)$t0) . '-' . $label . '.json', json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX);
@file_put_contents($dir . '/progress.json', json_encode(['label' => $label, 'phase' => 'end', 'at' => time(), 'sent' => $sent, 'done' => count($rows) - $left]), LOCK_EX);
say(sprintf('END sent=%d judged=%d left=%d total=%ds thr=%.1f/min wait avg=%s p95=%s max=%s ie=%d moved=%d mismatch=%d',
  $sent, count($rows) - $left, $left, round($tAll), $res['throughput_per_min'], $res['wait']['avg'] ?? '-', $res['wait']['p95'] ?? '-', $res['wait']['max'] ?? '-', $res['ie'], $moved, array_sum($mismatch)));
