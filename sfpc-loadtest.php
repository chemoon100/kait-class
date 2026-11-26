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
     --label=이름   결과에 붙일 이름

   시험용 계정(ltsfpc001…)과 문제(990001~990003, 잠긴 상태)는 --cleanup 으로 모두 지운다.
   진행 상황과 결과는 DATA_DIR/loadtest/ 에 JSON 으로 남고, 서버 모니터링 화면에서 본다.
   화면에는 영문 한 줄씩만 찍는다 (서버 콘솔은 한글을 못 그린다).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/judge_lib.php';

$opt = getopt('', ['setup', 'run', 'cleanup', 'rate::', 'minutes::', 'mix::', 'label::', 'drain::', 'users::']);
const LT_PREFIX = 'ltsfpc';
const LT_PROBS  = [990001 => [10, 1.0], 990002 => [20, 2.0], 990003 => [30, 2.0]];   /* 문제 번호 => [케이스 수, 시간 제한] */
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
    foreach (LT_PROBS as $no => [$cases, $tl]) {
      if ($d->query("SELECT id FROM problems WHERE prob_no = $no")->fetchColumn()) continue;
      $d->prepare("INSERT INTO problems(prob_no,title,description,input_desc,output_desc,time_limit,memory_limit,active,show_diff,created_at) VALUES(?,?,?,?,?,?,256000,0,0,?)")
        ->execute([$no, "[부하 시험] 제곱의 합 ({$cases}케이스)", '부하 시험용 문제입니다. 학생에게 보이지 않습니다.', 'N', '1²+…+N² mod 1,000,000,007', $tl, now()]);
      $pid = (int)$d->lastInsertId();
      $t = $d->prepare("INSERT INTO testcases(problem_id,seq,input,expected,is_sample) VALUES(?,?,?,?,0)");
      mt_srand($no);
      for ($k = 1; $k <= $cases; $k++) { $n = mt_rand(200000, 1000000); $t->execute([$pid, $k, $n . "\n", lt_answer($n) . "\n"]); }
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

if (!isset($opt['run'])) { say('usage: --setup | --run --rate=65 --minutes=3 [--mix=last|tle] [--label=x] | --cleanup'); exit(1); }

/* ── 넣을 코드 ── */
function lt_source(string $kind, string $lang, int $nonce): string {
  if ($lang === 'c') {
    $head = "/* expect:$kind #$nonce */\n#include <stdio.h>\nint main(){long long n,s=0,i;if(scanf(\"%lld\",&n)!=1)return 0;\n";
    return match ($kind) {
      'AC'  => $head . "for(i=1;i<=n;i++)s=(s+i*i)%1000000007LL;printf(\"%lld\\n\",s);return 0;}\n",
      'WA'  => $head . "for(i=1;i<=n;i++)s=(s+i*i)%1000000007LL;printf(\"%lld\\n\",s+1);return 0;}\n",
      'TLE' => $head . "for(;;){s+=n;}return 0;}\n",
      'RE'  => $head . "{volatile int *p=0;*p=(int)n;}return 0;}\n",
      'CE'  => $head . "printf(\"%lld\\n\",s)\nreturn 0;}\n",
    };
  }
  $head = "# expect:$kind #$nonce\nn = int(input())\n";
  return match ($kind) {
    'AC'  => $head . "s = 0\nfor i in range(1, n + 1):\n    s = (s + i * i) % 1000000007\nprint(s)\n",
    'WA'  => $head . "s = 0\nfor i in range(1, n + 1):\n    s = (s + i * i) % 1000000007\nprint(s + 1)\n",
    'TLE' => $head . "while True:\n    n += 1\n",
    'RE'  => $head . "print(1 // (n - n))\n",
    'CE'  => $head . "print(s\n",          /* 파이썬은 컴파일 단계가 없어 런타임 오류로 나온다 */
  };
}
$mixName = (string)($opt['mix'] ?? 'last');
$MIX = $mixName === 'tle' ? ['AC' => 20, 'WA' => 45, 'RE' => 8, 'TLE' => 25, 'CE' => 2] : ['AC' => 22, 'WA' => 65, 'RE' => 8, 'TLE' => 3, 'CE' => 2];
function lt_pick(array $w): string { $r = mt_rand(1, array_sum($w)); foreach ($w as $k => $v) { if (($r -= $v) <= 0) return $k; } return array_key_first($w); }

$rate = max(1, min(2000, (int)($opt['rate'] ?? 65)));
$minutes = max(0.2, min(30, (float)($opt['minutes'] ?? 3)));
$drainMax = max(1, min(60, (int)($opt['drain'] ?? 15))) * 60;
$label = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($opt['label'] ?? '')) ?: ('r' . $rate . 'm' . $minutes);

$uids = array_map('intval', array_column(all("SELECT id FROM users WHERE login_id LIKE ? ORDER BY id", [LT_PREFIX . '%']), 'id'));
$pids = [];
foreach (LT_PROBS as $no => $_) { $pid = (int)col("SELECT id FROM problems WHERE prob_no = ?", [$no]); if ($pid) $pids[] = $pid; }
if (!$uids || count($pids) !== count(LT_PROBS)) { say('run --setup first'); exit(1); }
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

say("run label=$label rate=$rate/min minutes=$minutes mix=$mixName total=$total");
mt_srand((int)(microtime(true) * 1000) & 0x7fffffff);
while ($sent < $total) {
  $due = (int)floor((microtime(true) - $t0) * $rate / 60) + 1;
  while ($sent < min($due, $total)) {
    $kind = lt_pick($MIX);
    $lang = ($kind === 'CE' || mt_rand(1, 100) <= 14) ? 'c' : 'py';
    $src = lt_source($kind, $lang, $startId * 100000 + $sent);
    $ins->execute([$uids[$sent % count($uids)], $pids[mt_rand(0, count($pids) - 1)], $lang, $src, source_hash($src), now(), now()]);
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
  'label' => $label, 'at' => date('Y-m-d H:i:s', (int)$t0), 'rate' => $rate, 'minutes' => $minutes, 'mix' => $mixName,
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
