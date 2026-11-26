<?php
/* ═══════════════════════════════════════════════
   judge_pool.php — 채점 서버(Judge0) 여러 대를 함께 쓰기 위한 부분. (SFPC 에서 더한 파일)

   원래 KAIT-CLASS 는 Judge0 한 대(config.php 의 judge0_url)만 쓴다. 대회에서는 한 대가 죽어도 채점이 멈추지 않아야 하므로
   여러 대를 등록해 두고, 채점 일꾼(judge-worker.php)이 살아 있는 서버에 나눠 맡기게 했다.

   · 서버 목록은 설정 judge_servers (JSON) 에 둔다. 비어 있으면 judge0_url 한 대뿐이고, 그때는 예전과 똑같이 돈다.
       name   화면에 보이는 이름
       url    Judge0 주소 (http://10.0.0.5:2358)
       token  Judge0 의 X-Auth-Token (없으면 빈칸)
       slots  한꺼번에 맡겨 둘 제출 수
       reserve 1 이면 예비 — 예비가 아닌 서버가 모두 죽었을 때만 쓴다
       on     0 이면 쓰지 않는다
   · 제출을 맡길 때 어느 서버에 맡겼는지 submissions.judge_srv 에 적는다.
   · 서버가 연달아 응답하지 않으면 "죽음"으로 표시하고, 거기 맡겼던 제출을 대기열로 되돌린다 → 살아 있는 서버가 가져간다.
     죽은 서버는 10초마다 찔러 보고, 답하면 다시 쓴다.
   · 서버는 답하는데 맡긴 제출이 한참 끝나지 않으면(안쪽 일꾼이 죽은 경우) 그 제출만 다른 서버로 옮긴다.
   · 상태는 DATA_DIR/judge_pool.json 에 적어 둔다. 모니터링 화면(admin/sfpc_monitor.php)이 읽는다.

   학생이 낸 코드는 본서버의 DB 에 있으므로, 채점 서버가 죽어도 제출은 사라지지 않는다. 다시 보내면 된다.
   ═══════════════════════════════════════════════ */

const POOL_FAILS_DOWN = 3;      /* 연달아 이만큼 응답이 없으면 죽은 것으로 본다 */
const POOL_PROBE_SEC  = 10;     /* 죽은 서버를 다시 찔러 보는 간격 · 살아 있는 서버의 상태를 묻는 간격 */
define('POOL_STALL_SEC', max(10, (int)(getenv('POOL_STALL_SEC') ?: 180)));   /* 맡긴 제출이 이만큼(초) 지나도 안 끝나면 다른 서버로 옮긴다 */
const POOL_MAX_TRIES  = 4;      /* 한 제출을 옮겨 볼 횟수. 넘으면 채점 오류로 둔다 (재채점하면 된다) */

/* 등록된 서버들. [key => ['key','name','url','token','slots','reserve','on']] — key 는 주소에서 만든다 */
function pool_servers(bool $fresh = false): array {
  static $memo = null, $at = 0;
  if (!$fresh && $memo !== null && time() - $at < 5) return $memo;
  $at = time(); $out = [];
  $raw = (string)(one("SELECT value FROM settings WHERE key = 'judge_servers'")['value'] ?? '');
  $list = $raw !== '' ? json_decode($raw, true) : null;
  if (is_array($list)) foreach ($list as $s) {
    $url = rtrim(trim((string)($s['url'] ?? '')), '/');
    if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
    $key = substr(sha1($url), 0, 10);
    $out[$key] = ['key' => $key, 'name' => (string)($s['name'] ?? $url), 'url' => $url, 'token' => (string)($s['token'] ?? ''),
                  'slots' => max(1, min(200, (int)($s['slots'] ?? 6))), 'reserve' => !empty($s['reserve']), 'on' => !isset($s['on']) || !empty($s['on'])];
  }
  if (!$out) {      /* 등록한 서버가 없다 — 예전처럼 judge0_url 한 대 */
    $out['default'] = ['key' => 'default', 'name' => '기본 (config 의 judge0_url)', 'url' => JUDGE0_URL, 'token' => '',
                       'slots' => defined('MAX_INFLIGHT') ? MAX_INFLIGHT : 6, 'reserve' => false, 'on' => true];
  }
  return $memo = $out;
}
function pool_multi(): bool { return !isset(pool_servers()['default']); }

/* j0_request 가 쓸 서버를 정한다 (null 이면 judge0_url) */
function pool_use(?array $srv): void { $GLOBALS['J0_SERVER'] = $srv; }

/* ── 여기부터는 채점 일꾼만 쓴다 ── */
$GLOBALS['POOL'] = [];      /* key => ['alive','fails','down_since','last_ok','last_err','err','probe_at','workers','stat'] */
$GLOBALS['POOL_STAT'] = ['dispatched' => 0, 'finished' => 0, 'moved' => 0, 'started_at' => time()];

function pool_init(): void {
  $cols = fn(string $t) => array_column(db()->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
  $c = $cols('submissions');
  if (!in_array('judge_srv', $c, true))      db()->exec("ALTER TABLE submissions ADD COLUMN judge_srv TEXT");
  if (!in_array('dispatched_at', $c, true))  db()->exec("ALTER TABLE submissions ADD COLUMN dispatched_at TEXT");
  if (!in_array('judge_tries', $c, true))    db()->exec("ALTER TABLE submissions ADD COLUMN judge_tries INTEGER NOT NULL DEFAULT 0");
  if (!in_array('judge_pos', $c, true))      db()->exec("ALTER TABLE submissions ADD COLUMN judge_pos INTEGER NOT NULL DEFAULT 0");   /* 여기까지의 테스트케이스는 통과했다 (틀리면 멈추기) */
  if (!in_array('judge_srv', $cols('runs'), true)) db()->exec("ALTER TABLE runs ADD COLUMN judge_srv TEXT");
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_monitor(ts INTEGER PRIMARY KEY, data TEXT NOT NULL)");
}
function &pool_state(string $key): array {
  if (!isset($GLOBALS['POOL'][$key])) $GLOBALS['POOL'][$key] = ['alive' => true, 'fails' => 0, 'down_since' => 0, 'last_ok' => 0, 'last_err' => 0, 'err' => '', 'probe_at' => 0, 'workers' => null, 'ms' => null];
  return $GLOBALS['POOL'][$key];
}
function pool_inflight(string $key): int {
  $legacy = $key === array_key_first(pool_servers()) ? " OR judge_srv IS NULL" : '';      /* 예전에 맡긴 제출은 첫 서버의 것 */
  return (int)col("SELECT COUNT(*) FROM submissions WHERE state = 'judging' AND (judge_srv = ?$legacy)", [$key]);
}
/* 응답이 왔다 */
function pool_ok(string $key): void {
  $s = &pool_state($key);
  if (!$s['alive']) wlog('채점 서버가 돌아왔습니다: ' . (pool_servers()[$key]['name'] ?? $key));
  $s['alive'] = true; $s['fails'] = 0; $s['down_since'] = 0; $s['last_ok'] = time(); $s['err'] = '';
}
/* 응답이 없거나 서버 쪽 고장이다. 연달아 쌓이면 죽은 것으로 보고 맡겼던 제출을 되돌린다 */
function pool_fail(string $key, string $why): void {
  $s = &pool_state($key);
  $s['fails']++; $s['last_err'] = time(); $s['err'] = mb_substr($why, 0, 200);
  if ($s['alive'] && $s['fails'] >= POOL_FAILS_DOWN) {
    $s['alive'] = false; $s['down_since'] = time(); $s['probe_at'] = time();
    $n = pool_release($key);
    wlog('채점 서버가 응답하지 않습니다: ' . (pool_servers()[$key]['name'] ?? $key) . " ({$why}) — 맡겼던 제출 {$n}건을 대기열로 되돌렸습니다");
  }
}
/* 그 서버에 맡겼던 제출과 실행을 대기열로 되돌린다 */
function pool_release(string $key): int {
  $legacy = $key === array_key_first(pool_servers()) ? " OR judge_srv IS NULL" : '';
  $n = db()->prepare("UPDATE submissions SET state = 'pending', tokens = NULL, judge_srv = NULL WHERE state = 'judging' AND (judge_srv = ?$legacy)");
  $n->execute([$key]); $cnt = $n->rowCount();
  db()->prepare("UPDATE runs SET state = 'pending', token = NULL, judge_srv = NULL WHERE state = 'running' AND (judge_srv = ?$legacy)")->execute([$key]);
  $GLOBALS['POOL_STAT']['moved'] += $cnt;
  return $cnt;
}
/* 다음 제출을 맡길 서버. 자리가 있는 살아 있는 서버 가운데 가장 한가한 곳. 예비는 다른 서버가 모두 죽었을 때만.
   $avoid 는 방금 그 제출을 붙들고 있다가 놓친 서버 (다른 곳이 있으면 피한다). 맡길 곳이 없으면 null */
function pool_pick(string $avoid = ''): ?array {
  $live = array_filter(pool_servers(), fn($s) => $s['on'] && pool_state($s['key'])['alive']);
  $main = array_filter($live, fn($s) => !$s['reserve']);
  $cand = $main ?: $live;                                  /* 주 서버가 하나도 없을 때만 예비 */
  $best = null; $bestLoad = 2.0; $fallback = null;
  foreach ($cand as $s) {
    $load = pool_inflight($s['key']) / $s['slots'];
    if ($load >= 1.0) continue;
    if ($s['key'] === $avoid) { $fallback = $s; continue; }
    if ($load < $bestLoad) { $best = $s; $bestLoad = $load; }
  }
  return $best ?? $fallback;
}
/* 맡겨 둘 수 있는 제출 수의 합 (지금 쓸 수 있는 서버 기준) */
function pool_capacity(): int {
  $live = array_filter(pool_servers(), fn($s) => $s['on'] && pool_state($s['key'])['alive']);
  $main = array_filter($live, fn($s) => !$s['reserve']);
  return array_sum(array_column($main ?: $live, 'slots'));
}
/* 죽은 서버를 찔러 보고, 살아 있는 서버의 일꾼 상태를 물어 둔다. 맡긴 지 오래된 제출은 다른 서버로 옮긴다. */
function pool_tick(): void {
  static $last = 0;
  if (time() - $last < 2) return;
  $last = time();
  foreach (pool_servers() as $key => $srv) {
    $s = &pool_state($key);
    if (!$srv['on'] || time() - $s['probe_at'] < POOL_PROBE_SEC) continue;
    $s['probe_at'] = time();
    pool_use($srv); $t0 = microtime(true);
    $r = j0_request('GET', '/workers', null, 4);
    pool_use(null);
    if ($r['http'] >= 200 && $r['http'] < 500 && $r['http'] !== 401 && $r['http'] !== 403) {     /* 답은 한다 (404 여도 살아는 있다) */
      $s['ms'] = (int)round((microtime(true) - $t0) * 1000);
      $s['workers'] = is_array($r['data']) && isset($r['data'][0]) ? ['queue' => (int)($r['data'][0]['size'] ?? 0), 'available' => (int)($r['data'][0]['available'] ?? 0), 'working' => (int)($r['data'][0]['working'] ?? 0), 'idle' => (int)($r['data'][0]['idle'] ?? 0)] : null;
      pool_ok($key);
    } else {
      $why = $r['http'] === 401 || $r['http'] === 403 ? '인증 글자열이 맞지 않습니다 (HTTP ' . $r['http'] . ')' : ($r['error'] ?: 'HTTP ' . $r['http']);
      if ($s['alive']) pool_fail($key, $why); else { $s['last_err'] = time(); $s['err'] = mb_substr($why, 0, 200); }
    }
    unset($s);
  }
  /* 서버는 살아 있는데 맡긴 제출이 끝나지 않는다 — 그 제출만 되돌려 다른 서버가 집게 한다 */
  if (pool_multi()) {
    foreach (all("SELECT id, judge_srv, judge_tries FROM submissions WHERE state = 'judging' AND tokens IS NOT NULL AND dispatched_at IS NOT NULL AND dispatched_at < ?",
                 [date('Y-m-d H:i:s', time() - POOL_STALL_SEC)]) as $r) {
      if ((int)$r['judge_tries'] >= POOL_MAX_TRIES) { fail_submission((int)$r['id'], '채점 서버를 ' . POOL_MAX_TRIES . '번 옮겨도 끝나지 않았습니다'); continue; }
      db()->prepare("UPDATE submissions SET state = 'pending', tokens = NULL WHERE id = ?")->execute([$r['id']]);     /* judge_srv 는 남겨 두어 다음에 피한다 */
      $GLOBALS['POOL_STAT']['moved']++;
      wlog("#{$r['id']} 가 " . POOL_STALL_SEC . "초 넘게 끝나지 않아 다른 채점 서버로 옮깁니다");
    }
  }
  pool_write_status();
}
/* 모니터링 화면이 읽을 상태 */
function pool_write_status(): void {
  $out = ['at' => time(), 'multi' => pool_multi(), 'stat' => $GLOBALS['POOL_STAT'], 'servers' => []];
  foreach (pool_servers() as $key => $srv) {
    $s = pool_state($key);
    $out['servers'][] = ['key' => $key, 'name' => $srv['name'], 'url' => preg_replace('#^(https?://[^/]+).*$#', '$1', $srv['url']), 'on' => $srv['on'], 'reserve' => $srv['reserve'], 'slots' => $srv['slots'],
                         'alive' => $s['alive'], 'inflight' => pool_inflight($key), 'fails' => $s['fails'], 'down_since' => $s['down_since'], 'last_ok' => $s['last_ok'], 'err' => $s['err'], 'last_err' => $s['last_err'], 'ms' => $s['ms'], 'workers' => $s['workers']];
  }
  @file_put_contents(DATA_DIR . '/judge_pool.json.tmp', json_encode($out, JSON_UNESCAPED_UNICODE));
  @rename(DATA_DIR . '/judge_pool.json.tmp', DATA_DIR . '/judge_pool.json');
}

/* ── 서버 상태 기록 (모니터링의 지난 기록) — 1분마다 한 줄, 하루치 ── */
function monitor_proc(): array {
  $o = ['t' => time()];
  $la = @file_get_contents('/proc/loadavg'); if ($la) { $p = explode(' ', $la); $o['load'] = [(float)$p[0], (float)$p[1], (float)$p[2]]; }
  $st = @file('/proc/stat'); if ($st) { $p = preg_split('/\s+/', trim($st[0])); $o['cpu'] = ['total' => array_sum(array_slice($p, 1, 8)), 'idle' => (int)$p[4] + (int)($p[5] ?? 0)]; }
  $o['cores'] = max(1, (int)@shell_exec('nproc 2>/dev/null') ?: substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1);
  $mi = @file_get_contents('/proc/meminfo');
  if ($mi) { $g = function (string $k) use ($mi) { return preg_match('/^' . $k . ':\s+(\d+)/m', $mi, $m) ? (int)$m[1] : 0; };
    $o['mem'] = ['total' => $g('MemTotal'), 'avail' => $g('MemAvailable'), 'swap_total' => $g('SwapTotal'), 'swap_free' => $g('SwapFree')]; }
  $o['disk'] = ['total' => (int)(@disk_total_space(DATA_DIR) / 1024), 'free' => (int)(@disk_free_space(DATA_DIR) / 1024)];
  $nd = @file('/proc/net/dev'); $rx = 0; $tx = 0;
  if ($nd) foreach ($nd as $l) if (preg_match('/^\s*([^:]+):\s*(.+)$/', $l, $m) && !in_array(trim($m[1]), ['lo'], true) && !preg_match('/^(docker|br-|veth)/', trim($m[1]))) { $p = preg_split('/\s+/', trim($m[2])); $rx += (int)$p[0]; $tx += (int)$p[8]; }
  $o['net'] = ['rx' => $rx, 'tx' => $tx];
  return $o;
}
function monitor_db(): array {
  $q = ['pending' => 0, 'judging' => 0];
  foreach (all("SELECT state, COUNT(*) n FROM submissions WHERE state IN ('pending','judging') GROUP BY state") as $r) $q[$r['state']] = (int)$r['n'];
  $m1 = date('Y-m-d H:i:s', time() - 60); $m5 = date('Y-m-d H:i:s', time() - 300);
  $old = (string)col("SELECT MIN(COALESCE(queued_at, created_at)) FROM submissions WHERE state IN ('pending','judging')");
  $lat = one("SELECT AVG(strftime('%s', judged_at) - strftime('%s', COALESCE(queued_at, created_at))) a, MAX(strftime('%s', judged_at) - strftime('%s', COALESCE(queued_at, created_at))) m FROM submissions WHERE state = 'done' AND judged_at >= ?", [$m5]);
  return ['pending' => $q['pending'], 'judging' => $q['judging'], 'oldest_wait' => $old !== '' ? max(0, time() - strtotime($old)) : 0,
          'sub_1m' => (int)col("SELECT COUNT(*) FROM submissions WHERE created_at >= ?", [$m1]), 'done_1m' => (int)col("SELECT COUNT(*) FROM submissions WHERE judged_at >= ? AND state = 'done'", [$m1]),
          'ie_10m' => (int)col("SELECT COUNT(*) FROM submissions WHERE judged_at >= ? AND (verdict = 'IE' OR state = 'error')", [date('Y-m-d H:i:s', time() - 600)]),
          'wait_avg' => $lat && $lat['a'] !== null ? round((float)$lat['a'], 1) : null, 'wait_max' => $lat && $lat['m'] !== null ? (int)$lat['m'] : null,
          'runs' => (int)col("SELECT COUNT(*) FROM runs WHERE state IN ('pending','running')")];
}
/* 화면 요청 기록 (sfpc_lib.php 가 요청마다 한 줄씩 적는다: "밀리초 계정번호") 에서 최근 $sec 초를 센다 */
function monitor_requests(int $sec = 60): array {
  $ms = []; $users = []; $now = time();
  for ($t = $now - $sec; $t <= $now; $t += 60) {
    $f = DATA_DIR . '/req/' . date('Ymd-Hi', $t) . '.log';
    foreach ((@file($f, FILE_IGNORE_NEW_LINES) ?: []) as $l) { $p = explode(' ', $l); if (count($p) < 3 || (int)$p[0] < $now - $sec) continue; $ms[] = (int)$p[1]; if ((int)$p[2] > 0) $users[(int)$p[2]] = 1; }
  }
  sort($ms); $n = count($ms);
  return ['n' => $n, 'per_sec' => round($n / max(1, $sec), 2), 'avg' => $n ? (int)round(array_sum($ms) / $n) : null, 'p95' => $n ? $ms[(int)floor(($n - 1) * 0.95)] : null, 'max' => $n ? $ms[$n - 1] : null, 'users' => count($users)];
}
/* 채점 일꾼이 1분마다 부른다 */
function monitor_sample(): void {
  static $last = 0, $prev = null;
  if (time() - $last < 60) return;
  $last = time();
  try {
    $p = monitor_proc(); $row = ['load' => $p['load'][0] ?? null, 'cores' => $p['cores']];
    if ($prev && isset($p['cpu'], $prev['cpu']) && $p['cpu']['total'] > $prev['cpu']['total']) {
      $row['cpu'] = round(100 * (1 - ($p['cpu']['idle'] - $prev['cpu']['idle']) / ($p['cpu']['total'] - $prev['cpu']['total'])), 1);
      $dt = max(1, $p['t'] - $prev['t']); $row['rx'] = (int)(($p['net']['rx'] - $prev['net']['rx']) / $dt); $row['tx'] = (int)(($p['net']['tx'] - $prev['net']['tx']) / $dt);
    }
    $prev = $p;
    if (!empty($p['mem']['total'])) { $row['mem'] = round(100 * (1 - $p['mem']['avail'] / $p['mem']['total']), 1); $row['swap'] = $p['mem']['swap_total'] ? round(100 * (1 - $p['mem']['swap_free'] / $p['mem']['swap_total']), 1) : 0; }
    $d = monitor_db(); $r = monitor_requests(60);
    $row += ['pending' => $d['pending'], 'judging' => $d['judging'], 'sub' => $d['sub_1m'], 'done' => $d['done_1m'], 'wait' => $d['wait_avg'], 'req' => $r['n'], 'ms' => $r['avg'], 'p95' => $r['p95'], 'users' => $r['users'],
             'srv' => count(array_filter(pool_servers(), fn($s) => $s['on'] && pool_state($s['key'])['alive']))];
    db()->prepare("INSERT OR REPLACE INTO sfpc_monitor(ts, data) VALUES(?, ?)")->execute([intdiv(time(), 60) * 60, json_encode($row)]);
    db()->prepare("DELETE FROM sfpc_monitor WHERE ts < ?")->execute([time() - 86400 * 2]);
    foreach (glob(DATA_DIR . '/req/*.log') ?: [] as $f) if (filemtime($f) < time() - 7200) @unlink($f);
  } catch (Throwable $e) { wlog('상태 기록 실패: ' . $e->getMessage()); }
}
