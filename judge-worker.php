<?php
/* ═══════════════════════════════════════════════
   judge-worker.php — 채점 워커 (CLI 전용, systemd 로 상주시킨다)

   왜 따로 도는가:
     학생이 제출하면 웹은 기록만 남기고 즉시 응답한다. 실제 채점은 이 워커가 한다.
     그래야 100명이 몰려도 PHP 프로세스가 채점을 기다리며 묶이지 않는다.

   왜 한 개만 도는가:
     SQLite 는 쓰기 중 DB 전체를 잠근다. 채점 결과 쓰기를 한 프로세스로 모으면
     쓰기가 한 줄로 정렬되어 잠금 충돌이 사실상 사라진다.

   상태 흐름:
     pending ──(Judge0 에 제출)──> judging ──(결과 도착)──> done
                     │                          │
                     └──────── 실패 ────────────┴──> error (verdict=IE)

   [SFPC] 채점 서버 여러 대:
     어느 Judge0 에 맡길지는 judge_pool.php 가 정한다. 서버가 죽으면 맡겼던 제출을 대기열로 되돌려 다른 서버가 집게 한다.
     서버를 따로 등록하지 않으면 config 의 judge0_url 한 대만 쓰고, 그때는 예전과 똑같이 돈다.
     이 파일에서 고친 곳에는 [SFPC] 표시를 해 두었다.

   실행:  php /var/www/html/judge-worker.php
   로그:  journalctl -u judge-worker -f
   ═══════════════════════════════════════════════ */
declare(strict_types=1);

/* ★ 이 파일은 상주 워커다. 웹으로 열면 요청 하나가 영원히 끝나지 않고
   PHP 프로세스를 물고 있게 된다. 주소로 부르는 것을 막는다. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/judge0.php';

/* ── 설정 ─────────────────────────────────────── */
const IDLE_SLEEP   = 300000;   /* 할 일이 없을 때 쉬는 시간 (마이크로초) */
const COLLECT_MAX  = 12;       /* 한 번에 결과를 확인할 제출 수 */
const STALE_MIN    = 15;       /* 이만큼 지나도 안 끝나면 포기 (분) */

/* Judge0 에 동시에 맡겨 둘 제출 수.
   Judge0 의 대기열에는 정해진 크기가 있어서, 한꺼번에 밀어 넣으면
   넘치는 만큼 503 으로 거절당한다. 우리 쪽에서 미리 조절하는 편이 낫다.
   테스트케이스가 10개인 문제면 6 × 10 = 60건이 Judge0 안에 있게 된다. */
const MAX_INFLIGHT = 6;

/* 직접 실행은 학생이 화면 앞에서 기다리는 것이므로 조금 따로 떼어 둔다.
   채점과 자원을 나눠 쓰되 서로를 굶기지 않게 한다. */
const MAX_INFLIGHT_RUN = 3;

/* 거절당했을 때 쉬는 시간 (마이크로초) */
const BUSY_SLEEP   = 500000;

$running = true;
if (function_exists('pcntl_signal')) {
  pcntl_async_signals(true);
  pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
  pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

/* 워커가 살아 있다는 표시. 관리자 화면이 이 파일의 시각을 보고 판단한다. */
function heartbeat(): void {
  static $last = 0;
  if (time() - $last < 5) return;
  $last = time();
  @touch(DATA_DIR . '/worker.heartbeat');
}

function wlog(string $msg): void {
  fwrite(STDOUT, date('H:i:s') . ' ' . $msg . "\n");
}

/* ═══ 시작 시 정리 ════════════════════════════════
   워커가 죽었다 살아나면, Judge0 에 넘기기 전에 멈춘 제출이 남아 있을 수 있다.
   토큰이 없다는 것은 아직 넘기지 못했다는 뜻이므로 대기열로 되돌린다. */
function recover_stuck(): void {
  $n = db()->exec("UPDATE submissions SET state='pending'
                   WHERE state='judging' AND (tokens IS NULL OR tokens='')");
  if ($n) wlog("다시 대기열로 돌린 제출: {$n}건");
}

/* Judge0 가 지금 붙들고 있는 제출 수 */
function inflight(): int {
  return (int)col("SELECT COUNT(*) FROM submissions WHERE state='judging'");
}

/* 마지막으로 '바쁨'을 알린 시각 — 로그가 도배되지 않게 */
$GLOBALS['busy_logged_at'] = 0;
function log_busy(string $why): void {
  if (time() - $GLOBALS['busy_logged_at'] < 10) return;
  $GLOBALS['busy_logged_at'] = time();
  wlog('Judge0 가 바빠 잠시 미룹니다 (' . $why . ')');
}

/* ═══ 1) 대기 중인 제출 하나를 집어 Judge0 에 넘긴다 ═══ */
function dispatch_one(): bool {
  /* 이미 충분히 맡겨 두었으면 더 밀어 넣지 않는다
     [SFPC] 맡길 수 있는 양은 살아 있는 채점 서버들의 자리 수의 합. 맡길 서버가 없으면 대기열에 그대로 둔다. */
  if (inflight() >= pool_capacity()) return false;
  $srv = pool_pick();
  if (!$srv) return false;

  /* 집는 것과 상태를 바꾸는 것은 한 트랜잭션 안에서. 네트워크 호출은 밖에서. */
  $sub = tx(function (PDO $d) use (&$srv) {
    /* 우선순위가 낮은(숫자가 큰) 재채점은 새 제출이 없을 때만 집는다 */
    $st = $d->prepare("SELECT id, problem_id, lang, source_code, judge_srv
                       FROM submissions WHERE state='pending'
                       ORDER BY priority ASC, id ASC LIMIT 1");
    $st->execute();
    $row = $st->fetch();
    $st->closeCursor();
    if (!$row) return null;
    /* [SFPC] 방금 이 제출을 붙들고 있다가 놓친 서버는, 다른 곳이 있으면 피한다 */
    if ((string)$row['judge_srv'] === $srv['key']) { $alt = pool_pick($srv['key']); if ($alt) $srv = $alt; }
    /* [SFPC] 대기열에서 집은 제출은 늘 첫 테스트케이스부터 다시 본다 (재채점 · 되돌린 제출 포함) */
    $d->prepare("UPDATE submissions SET state='judging', judge_srv=?, dispatched_at=?, judge_tries=judge_tries+1,
                 judge_pos=0, passed_count=NULL, max_time=NULL, max_memory=NULL WHERE id=?")
      ->execute([$srv['key'], now(), $row['id']]);
    return $row;
  });
  if (!$sub) return false;

  $sid = (int)$sub['id'];
  pool_use($srv);

  try {
    /* [SFPC] 테스트케이스를 한꺼번에 넘기지 않고 조각으로 나눠 넘긴다 — 틀리면 거기서 멈춘다 (post_chunk) */
    $n = post_chunk($sid, $sub, 0);
    pool_ok($srv['key']); $GLOBALS['POOL_STAT']['dispatched']++;
    wlog("#{$sid} 제출 → " . (pool_multi() ? $srv['name'] : 'Judge0') . " (" . $n[0] . " / " . $n[1] . "케이스)");
  } catch (Judge0Busy $e) {
    /* 잘못된 제출이 아니다. 대기열로 되돌려 두었다가 다시 보낸다. */
    requeue($sid, $e->getMessage());
    /* [SFPC] 바쁜 것(503 · 429)이 아니라 아예 답이 없으면 서버가 죽었는지 센다 */
    if (!in_array($e->getCode(), [503, 429], true)) pool_fail($srv['key'], $e->getMessage());
    usleep(BUSY_SLEEP);
  } catch (Throwable $e) {
    fail_submission($sid, $e->getMessage());
  } finally {
    pool_use(null);
  }
  return true;
}

/* ═══ [SFPC] 틀리면 멈추기 ═══════════════════════
   테스트케이스를 한꺼번에 Judge0 에 넘기면, 첫 케이스에서 틀린 제출도 나머지를 끝까지 돌린다.
   작년 대회 제출의 78% 가 정답이 아니었다 — 그만큼이 헛일이고, 시간 초과 풀이는 케이스마다 제한 시간을 꽉 채운다.
   그래서 조각으로 나눠 넘긴다: 앞 조각이 모두 통과해야 다음 조각을 넘긴다. DOMjudge 의 기본 방식(lazy)과 같다.
   조각은 3 → 6 → 12 → 20 → 20 … 으로 커진다. 틀린 풀이는 대개 앞에서 걸리고, 정답은 주고받는 횟수가 적어야 하기 때문.
   설정 judge_lazy 가 '0' 이면 예전처럼 한꺼번에 넘긴다 (비교 시험용).
   판정 · 통과 개수 · 처음 틀린 케이스는 예전과 같다 — 예전에도 처음 틀린 곳까지만 셌다. */
function lazy_on(): bool {
  static $memo = true, $at = 0;
  if (time() - $at >= 5) { $at = time(); $memo = (string)(one("SELECT value FROM settings WHERE key = 'judge_lazy'")['value'] ?? '') !== '0'; }
  return $memo;
}
function chunk_len(int $pos, int $total): int {
  $left = $total - $pos;
  if (!lazy_on()) return $left;
  $at = 0;
  foreach ([3, 6, 12] as $n) { if ($pos <= $at) return min($n, $left); $at += $n; }
  return min(J0_BATCH, $left);
}
/* $pos 번째부터 한 조각을 넘기고 토큰을 적어 둔다. 지금 쓸 서버는 pool_use 로 정해 둔 것.
   @return int[] [넘긴 수, 전체 수]   @throws Judge0Busy|RuntimeException */
function post_chunk(int $sid, array $sub, int $pos): array {
  $p = one("SELECT time_limit, memory_limit FROM problems WHERE id=?", [$sub['problem_id']]);
  if (!$p) throw new RuntimeException('문제를 찾을 수 없습니다');
  $cases = all("SELECT id, seq, input, expected FROM testcases WHERE problem_id=? ORDER BY seq, id", [$sub['problem_id']]);
  if (!$cases) throw new RuntimeException('테스트케이스가 없는 문제입니다');
  $part = array_slice($cases, $pos, chunk_len($pos, count($cases)));
  if (!$part) throw new RuntimeException('넘길 테스트케이스가 없습니다');
  $tokens = j0_submit((string)$sub['source_code'], (string)$sub['lang'], $part, (float)$p['time_limit'], (int)$p['memory_limit']);
  /* [SFPC] 그사이 운영진이 취소했으면(state 가 judging 이 아니다) 덮어쓰지 않는다 */
  db()->prepare("UPDATE submissions SET tokens=?, total_count=?, judge_pos=?, dispatched_at=? WHERE id=? AND state='judging'")
      ->execute([json_encode($tokens), count($cases), $pos, now(), $sid]);
  return [count($part), count($cases)];
}
/* 앞 조각을 통과하고 다음 조각을 기다리는 제출들 (judging · 토큰 없음 · judge_pos > 0). 맡겼던 서버에 이어서 넘긴다. */
function continue_chunks(): bool {
  $rows = all("SELECT id, problem_id, lang, source_code, judge_srv, judge_pos, COALESCE(queued_at, created_at) AS queued_at
               FROM submissions WHERE state='judging' AND (tokens IS NULL OR tokens='') AND judge_pos > 0 ORDER BY id LIMIT 60");
  if (!$rows) return false;
  $servers = pool_servers(); $worked = false;
  foreach ($rows as $r) {
    $sid = (int)$r['id'];
    $srv = $servers[(string)$r['judge_srv']] ?? reset($servers);
    if (!pool_state($srv['key'])['alive']) continue;          /* 죽은 서버의 것은 pool_fail 이 대기열로 되돌린다 */
    pool_use($srv);
    try {
      post_chunk($sid, $r, (int)$r['judge_pos']);
      pool_ok($srv['key']); $worked = true;
    } catch (Judge0Busy $e) {
      if (!in_array($e->getCode(), [503, 429], true)) pool_fail($srv['key'], $e->getMessage());
      if (strtotime((string)$r['queued_at']) < time() - STALE_MIN * 60) fail_submission($sid, $e->getMessage() . ' (' . STALE_MIN . '분 넘게 재시도)');
      log_busy($e->getMessage());
      usleep(BUSY_SLEEP);
      break;                                                   /* 이 서버가 바쁘다 — 다음 차례에 다시 */
    } catch (Throwable $e) {
      fail_submission($sid, $e->getMessage());
    } finally {
      pool_use(null);
    }
  }
  return $worked;
}

/* 다시 시도하려고 대기열로 되돌린다.
   너무 오래 붙들려 있었다면 그때는 포기한다. */
function requeue(int $sid, string $why): void {
  $born = (string)col("SELECT COALESCE(queued_at, created_at) FROM submissions WHERE id=?", [$sid]);
  if ($born && strtotime($born) < time() - STALE_MIN * 60) {
    fail_submission($sid, $why . ' (' . STALE_MIN . '분 넘게 재시도)');
    return;
  }
  db()->prepare("UPDATE submissions SET state='pending', tokens=NULL WHERE id=? AND state='judging'")->execute([$sid]);
  log_busy($why);
}

/* ═══ 2) 채점 중인 제출들의 결과를 거둔다 ═══ */
function collect(): bool {
  /* [SFPC] 서버가 여러 대면 그만큼 더 많이 본다. 어느 서버에 맡겼는지도 함께 읽는다. */
  $rows = all("SELECT id, problem_id, tokens, judge_srv, judge_pos, max_time, max_memory, COALESCE(queued_at, created_at) AS queued_at
               FROM submissions
               WHERE state='judging' AND tokens IS NOT NULL AND tokens<>''
               ORDER BY id LIMIT " . max(COLLECT_MAX, pool_capacity()));
  if (!$rows) return false;
  $servers = pool_servers();

  $worked = false;
  foreach ($rows as $r) {
    $sid = (int)$r['id'];
    $tokens = json_decode((string)$r['tokens'], true);
    if (!is_array($tokens) || !$tokens) { fail_submission($sid, '토큰이 손상되었습니다'); continue; }

    /* [SFPC] 맡긴 서버에게 묻는다. 죽은 것으로 표시된 서버의 것은 건너뛴다 (이미 대기열로 되돌렸다). */
    $srv = $servers[(string)$r['judge_srv']] ?? reset($servers);
    if (!pool_state($srv['key'])['alive']) continue;
    pool_use($srv);
    try {
      $res = j0_fetch($tokens);
      pool_ok($srv['key']);
    } catch (Judge0Busy $e) {
      if (!in_array($e->getCode(), [503, 429], true)) pool_fail($srv['key'], $e->getMessage());
      log_busy($e->getMessage());
      usleep(BUSY_SLEEP);
      continue;   /* 상태를 그대로 두고 다음 차례에 다시 본다 */
    } catch (Throwable $e) {
      wlog("#{$sid} 결과 조회 실패 (다음 차례에 다시 시도): " . $e->getMessage());
      continue;
    } finally {
      pool_use(null);
    }

    /* 하나라도 아직 안 끝났으면 기다린다 */
    foreach ($res as $one) {
      if (j0_pending($one)) {
        /* 너무 오래 걸리면 포기한다 */
        if (strtotime((string)$r['queued_at']) < time() - STALE_MIN * 60) {
          fail_submission($sid, '채점이 ' . STALE_MIN . '분 넘게 끝나지 않았습니다');
        }
        continue 2;
      }
    }

    $cases = all("SELECT seq, input, expected FROM testcases WHERE problem_id=? ORDER BY seq, id",
                 [$r['problem_id']]);
    /* [SFPC] 이번 조각만 본다. 틀렸거나 마지막 조각이면 확정하고, 아니면 다음 조각을 넘긴다. */
    $pos = (int)$r['judge_pos'];
    $ev  = judge_chunk($sid, $res, array_slice($cases, $pos, count($tokens)));
    $ev['passed'] += $pos;
    $ev['maxTime'] = max($ev['maxTime'], (float)($r['max_time'] ?? 0));
    $ev['maxMem']  = max($ev['maxMem'],  (int)($r['max_memory'] ?? 0));
    $worked = true;
    if ($ev['verdict'] === null && $ev['passed'] < count($cases)) {
      db()->prepare("UPDATE submissions SET tokens='', judge_pos=?, passed_count=?, max_time=?, max_memory=? WHERE id=? AND state='judging'")
          ->execute([$ev['passed'], $ev['passed'], $ev['maxTime'], $ev['maxMem'], $sid]);
      continue;                                   /* continue_chunks 가 이어서 넘긴다 */
    }
    finish_submission($sid, $ev, count($cases));
    $GLOBALS['POOL_STAT']['finished']++;
  }
  return $worked;
}

/* ═══ 판정 ═══════════════════════════════════════
   테스트케이스를 순서대로 보며 처음 실패한 지점에서 확정한다. */
/* [SFPC] 한 조각을 판정한다. 모두 통과했으면 verdict 는 null (아직 모른다 — 다음 조각이 남았을 수 있다). */
function judge_chunk(int $sid, array $res, array $cases): array {
  $memLimit = (int)col("SELECT p.memory_limit FROM submissions s
                        JOIN problems p ON p.id=s.problem_id WHERE s.id=?", [$sid]);

  $verdict = null;
  $passed  = 0;
  $failSeq = null;
  $compile = '';
  $maxTime = 0.0;
  $maxMem  = 0;
  /* 처음 틀린 케이스 하나만 남겨 학생에게 보여준다 */
  $fIn = null; $fExp = null; $fGot = null;

  foreach ($res as $i => $one) {
    $st  = (int)($one['status_id'] ?? 0);
    $seq = (int)($cases[$i]['seq'] ?? ($i + 1));
    $maxTime = max($maxTime, (float)($one['time'] ?? 0));
    $maxMem  = max($maxMem,  (int)($one['memory'] ?? 0));

    if ($st === J0_CE) {
      $verdict = 'CE';
      $compile = j0_text($one['compile_output'] ?? '');
      $failSeq = null;
      break;
    }
    if ($st === J0_TLE) { $verdict = 'TLE'; $failSeq = $seq; break; }
    if ($st === J0_INTERNAL || $st === J0_EXEC_FORMAT) {
      $verdict = 'IE'; $failSeq = $seq;
      $compile = j0_text($one['message'] ?? '');
      break;
    }
    if ($st >= J0_RE_FIRST && $st <= J0_RE_LAST) {
      /* Judge0 CE 에는 메모리 초과 상태가 따로 없다.
         한도 가까이 쓰고 죽었으면 메모리 초과로 본다. */
      $verdict = ($memLimit > 0 && (int)($one['memory'] ?? 0) >= (int)($memLimit * 0.95))
                 ? 'MLE' : 'RE';
      $failSeq = $seq;
      $compile = j0_text($one['stderr'] ?? '');
      break;
    }
    /* 정상 종료 — 이제 우리 규칙으로 출력을 비교한다 */
    $got  = j0_text($one['stdout'] ?? '');
    $want = (string)($cases[$i]['expected'] ?? '');
    if (!outputs_match($got, $want)) {
      $verdict = 'WA'; $failSeq = $seq;
      $fIn  = diff_store((string)($cases[$i]['input'] ?? ''));
      $fExp = diff_store($want);
      $fGot = diff_store($got);
      break;
    }
    $passed++;
  }

  return ['verdict' => $verdict, 'passed' => $passed, 'failSeq' => $failSeq, 'compile' => $compile,
          'maxTime' => $maxTime, 'maxMem' => $maxMem, 'fIn' => $fIn, 'fExp' => $fExp, 'fGot' => $fGot];
}
/* 판정을 적는다. $ev 는 judge_chunk 의 결과에 앞 조각들의 통과 수 · 최대 시간 · 최대 메모리를 합친 것. */
function finish_submission(int $sid, array $ev, int $total): void {
  $verdict = $ev['verdict'] ?? 'AC';
  db()->prepare("UPDATE submissions SET state='done', verdict=?, passed_count=?,
                 total_count=?, max_time=?, max_memory=?, compile_msg=?, fail_seq=?,
                 fail_input=?, fail_expected=?, fail_output=?, judged_at=? WHERE id=? AND state='judging'")
      ->execute([$verdict, $ev['passed'], $total, $ev['maxTime'], $ev['maxMem'],
                 mb_substr((string)$ev['compile'], 0, 4000), $ev['failSeq'],
                 $ev['fIn'], $ev['fExp'], $ev['fGot'], now(), $sid]);

  wlog("#{$sid} → {$verdict} ({$ev['passed']}/{$total}, "
       . number_format($ev['maxTime'], 3) . "s, {$ev['maxMem']}KB)");
}

/* 채점 자체가 실패한 경우 */
function fail_submission(int $sid, string $why): void {
  db()->prepare("UPDATE submissions SET state='error', verdict='IE', compile_msg=?,
                 judged_at=? WHERE id=? AND state IN ('pending','judging')")
      ->execute([mb_substr($why, 0, 1000), now(), $sid]);
  wlog("#{$sid} → 채점 오류: {$why}");
}

/* ═══ 직접 실행 ═══════════════════════════════════
   학생이 자기 입력으로 코드를 돌려보는 기능. 채점이 아니라 실행만 한다.
   테스트케이스와 무관하므로 판정도 하지 않는다. */
function dispatch_run(): bool {
  if ((int)col("SELECT COUNT(*) FROM runs WHERE state='running'") >= MAX_INFLIGHT_RUN) return false;

  $run = tx(function (PDO $d) {
    $st = $d->prepare("SELECT id, problem_id, lang, source_code, stdin
                       FROM runs WHERE state='pending' ORDER BY id LIMIT 1");
    $st->execute();
    $row = $st->fetch();
    $st->closeCursor();
    if (!$row) return null;
    $d->prepare("UPDATE runs SET state='running' WHERE id=?")->execute([$row['id']]);
    return $row;
  });
  if (!$run) return false;

  $rid = (int)$run['id'];
  /* [SFPC] 직접 실행도 살아 있는 서버로. 맡길 곳이 없으면 잠시 뒤에 다시 */
  $srv = pool_pick();
  if (!$srv) { db()->prepare("UPDATE runs SET state='pending' WHERE id=?")->execute([$rid]); return false; }
  pool_use($srv);
  try {
    $lim = $run['problem_id']
      ? one("SELECT time_limit, memory_limit FROM problems WHERE id=?", [$run['problem_id']])
      : null;
    $cpu = min(5.0, (float)($lim['time_limit'] ?? 2.0));
    $mem = (int)($lim['memory_limit'] ?? 128000);

    $tokens = j0_submit((string)$run['source_code'], (string)$run['lang'],
                        [['input' => (string)$run['stdin']]], $cpu, $mem);
    db()->prepare("UPDATE runs SET token=?, judge_srv=? WHERE id=?")->execute([$tokens[0], $srv['key'], $rid]);
    wlog("실행 #{$rid} → Judge0");
  } catch (Judge0Busy $e) {
    db()->prepare("UPDATE runs SET state='pending', token=NULL WHERE id=?")->execute([$rid]);
    if (!in_array($e->getCode(), [503, 429], true)) pool_fail($srv['key'], $e->getMessage());
    log_busy($e->getMessage());
    usleep(BUSY_SLEEP);
  } catch (Throwable $e) {
    fail_run($rid, $e->getMessage());
  } finally {
    pool_use(null);
  }
  return true;
}

function collect_runs(): bool {
  $rows = all("SELECT id, token, judge_srv, COALESCE(queued_at, created_at) AS queued_at
               FROM runs WHERE state='running' AND token IS NOT NULL ORDER BY id LIMIT 10");
  if (!$rows) return false;
  $servers = pool_servers();

  $worked = false;
  foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $srv = $servers[(string)$r['judge_srv']] ?? reset($servers);      /* [SFPC] 맡긴 서버에게 묻는다 */
    if (!pool_state($srv['key'])['alive']) continue;
    pool_use($srv);
    try {
      $res = j0_fetch([(string)$r['token']]);
    } catch (Judge0Busy $e) {
      if (!in_array($e->getCode(), [503, 429], true)) pool_fail($srv['key'], $e->getMessage());
      log_busy($e->getMessage()); usleep(BUSY_SLEEP); continue;
    } catch (Throwable $e) {
      wlog("실행 #{$rid} 조회 실패: " . $e->getMessage()); continue;
    } finally {
      pool_use(null);
    }
    $one = $res[0] ?? null;
    if (!$one || j0_pending($one)) {
      if (strtotime((string)$r['queued_at']) < time() - 120) fail_run($rid, '실행이 끝나지 않았습니다');
      continue;
    }

    $cut = fn(string $s) => mb_strlen($s) > RUN_MAX_OUTPUT
         ? mb_substr($s, 0, RUN_MAX_OUTPUT) . "\n… (출력이 너무 길어 여기까지만 보여줍니다)"
         : $s;

    db()->prepare("UPDATE runs SET state='done', status_id=?, stdout=?, stderr=?,
                   compile_msg=?, message=?, time=?, memory=?, finished_at=? WHERE id=?")
        ->execute([
          (int)($one['status_id'] ?? 0),
          $cut(j0_text($one['stdout'] ?? '')),
          $cut(j0_text($one['stderr'] ?? '')),
          mb_substr(j0_text($one['compile_output'] ?? ''), 0, 4000),
          mb_substr(j0_text($one['message'] ?? ''), 0, 500),
          (float)($one['time'] ?? 0), (int)($one['memory'] ?? 0), now(), $rid,
        ]);
    $worked = true;
  }
  return $worked;
}

function fail_run(int $rid, string $why): void {
  db()->prepare("UPDATE runs SET state='error', message=?, finished_at=? WHERE id=?")
      ->execute([mb_substr($why, 0, 500), now(), $rid]);
  wlog("실행 #{$rid} → 오류: {$why}");
}

/* 실행 기록은 오래 둘 이유가 없다 */
function sweep_runs(): void {
  static $last = 0;
  if (time() - $last < 600) return;
  $last = time();
  db()->prepare("DELETE FROM runs WHERE created_at < ?")
      ->execute([date('Y-m-d H:i:s', time() - 86400)]);
}

/* ═══ 메인 루프 ═══════════════════════════════════ */
pool_init();      /* [SFPC] 채점 서버 여러 대를 위한 칸과 상태 기록 표 */
wlog('워커 시작 (Judge0: ' . implode(', ', array_map(fn($s) => $s['name'] . ' ' . $s['url'] . ' ×' . $s['slots'] . ($s['reserve'] ? ' 예비' : ''), pool_servers()))
     . ' · 실행 ' . MAX_INFLIGHT_RUN . '건)');
recover_stuck();
db()->exec("UPDATE runs SET state='pending', token=NULL WHERE state='running' AND token IS NULL");

while ($running) {
  try {
    /* 직접 실행을 먼저 본다. 한 건짜리라 금방 끝나고, 학생이 바로 앞에서 기다린다. */
    $r1 = dispatch_run();
    $r2 = collect_runs();
    $b  = collect();
    $c  = continue_chunks();   /* [SFPC] 앞 조각을 통과한 제출의 다음 조각 — 새 제출보다 먼저 */
    $a  = dispatch_one() || $c;
    heartbeat();
    sweep_runs();
    pool_tick();          /* [SFPC] 채점 서버가 살아 있는지 살피고, 죽은 서버의 제출을 되돌린다 */
    monitor_sample();     /* [SFPC] 모니터링 화면의 지난 기록 (1분마다) */
    if (!$a && !$b && !$r1 && !$r2) usleep(IDLE_SLEEP);
  } catch (Throwable $e) {
    /* 루프 자체는 절대 죽지 않게 한다 */
    wlog('루프 오류: ' . $e->getMessage());
    sleep(2);
  }
}
wlog('워커 종료');
