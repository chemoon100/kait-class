<?php
/* ═══════════════════════════════════════════════
   admin/sfpc_monitor.php — 서버 모니터링. 운영자만 본다.

     (화면)     3초마다 스스로 새로 고친다. 숫자마다 신호등(초록 · 노랑 · 빨강)과 한 줄 해설이 붙는다.
     ?json=1    지금 상태 (화면이 물어 가는 곳)
     ?hist=1    지난 기록 — 채점 일꾼이 1분마다 남긴 것 (sfpc_monitor 표)

   보여 주는 것
   · 서버: CPU · 부하 · 메모리 · 스왑 · 디스크 · 네트워크 (/proc 에서 읽는다)
   · 사이트: 화면 요청 수 · 응답 시간 · 접속 중인 사람 (sfpc_lib.php 가 요청마다 남기는 기록)
   · 채점: 일꾼이 살아 있는지 · 기다리는 제출 · 처리 속도 · 채점 오류
   · 채점 서버: 등록한 Judge0 마다 살아 있는지 · 맡긴 양 (judge_pool.json — 채점 일꾼이 적는다)
   아래쪽에서 채점 서버를 등록한다 (주 서버 여러 대 + 예비).

   이 화면은 본서버에서 돈다. 본서버가 완전히 멈추면 이 화면도 열리지 않는다 — 그때는 호스팅 회사의 관리 화면을 본다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../judge_pool.php';
$me = need_admin('../');

/* 제출 하나하나에 대한 동작 — 재채점 · 채점 취소 (화면의 단추가 부른다) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sub_act'])) {
  header('Content-Type: application/json; charset=utf-8');
  if (!sfpc_csrf_ok()) { echo json_encode(['ok' => false, 'msg' => '화면을 연 지 오래되었습니다. 새로 고친 뒤 다시 눌러 주세요.'], JSON_UNESCAPED_UNICODE); exit; }
  $sid = (int)($_POST['id'] ?? 0); $act = (string)$_POST['sub_act'];
  if ($act === 'rejudge') { $why = sfpc_judge_again($sid, $me); echo json_encode(['ok' => $why === '', 'msg' => $why === '' ? "#{$sid} 을 다시 채점합니다." : $why], JSON_UNESCAPED_UNICODE); exit; }
  if ($act === 'cancel')  { $n = sfpc_judge_cancel([$sid], $me); echo json_encode(['ok' => $n > 0, 'msg' => $n ? "#{$sid} 의 채점을 취소했습니다." : '이미 채점이 끝난 제출입니다.'], JSON_UNESCAPED_UNICODE); exit; }
  if ($act === 'cancel_user') {
    $uid = (int)col("SELECT user_id FROM submissions WHERE id = ?", [$sid]);
    $ids = $uid ? array_column(all("SELECT id FROM submissions WHERE user_id = ? AND state IN ('pending','judging')", [$uid]), 'id') : [];
    $n = sfpc_judge_cancel($ids, $me); echo json_encode(['ok' => $n > 0, 'msg' => $n ? "대기 · 채점 중이던 제출 {$n}건을 취소했습니다." : '취소할 제출이 없습니다.'], JSON_UNESCAPED_UNICODE); exit;
  }
  echo json_encode(['ok' => false, 'msg' => '모르는 동작입니다.'], JSON_UNESCAPED_UNICODE); exit;
}
/* 부하 시험(sfpc-loadtest.php)이 남긴 진행 상황과 결과 */
if (isset($_GET['loadtest'])) {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  $dir = DATA_DIR . '/loadtest'; $runs = [];
  foreach (array_slice(array_reverse(glob($dir . '/2*.json') ?: []), 0, 30) as $f) {
    $r = json_decode((string)@file_get_contents($f), true); if (!is_array($r)) continue;
    if (empty($_GET['series'])) unset($r['series']);
    $runs[] = $r;
  }
  echo json_encode(['progress' => json_decode((string)@file_get_contents($dir . '/progress.json'), true), 'runs' => $runs], JSON_UNESCAPED_UNICODE); exit;
}
if (isset($_GET['json']) || isset($_GET['hist'])) {
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  if (isset($_GET['hist'])) {
    $out = [];
    if (db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sfpc_monitor'")->fetchColumn())
      foreach (all("SELECT ts, data FROM sfpc_monitor WHERE ts >= ? ORDER BY ts", [time() - max(600, min(86400, (int)($_GET['sec'] ?? 3600)))]) as $r) $out[] = ['t' => (int)$r['ts']] + (json_decode($r['data'], true) ?: []);
    echo json_encode($out); exit;
  }
  /* 제출 하나하나의 상태 — 채점 중 · 대기 · 방금 끝난 것. DOMjudge 의 제출 목록처럼 테스트케이스 칸을 그릴 수 있게 내준다. */
  $subs = (function () {
    $cols = array_column(db()->query("PRAGMA table_info(submissions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $x = in_array('judge_pos', $cols, true) ? 's.judge_pos, s.judge_srv, s.dispatched_at, s.judge_tries' : '0 AS judge_pos, NULL AS judge_srv, NULL AS dispatched_at, 0 AS judge_tries';
    $sel = "SELECT s.id, s.lang, s.state, s.verdict, s.passed_count, s.total_count, s.max_time, s.priority, s.tokens, s.judged_at, COALESCE(s.queued_at, s.created_at) AS q, $x,
              (COALESCE(s.compile_msg, '') LIKE '" . SFPC_CANCEL_MARK . "%') AS canceled, (SELECT COUNT(*) FROM submissions w WHERE w.user_id = s.user_id AND w.state IN ('pending','judging')) AS mine,
              u.name AS uname, u.login_id, p.prob_no, p.title
            FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id";
    $names = []; foreach (pool_servers() as $k => $sv) $names[$k] = $sv['name'];
    $now = time();
    $fmt = function (array $r) use ($names, $now) {
      $q = strtotime((string)$r['q']) ?: $now; $d = strtotime((string)($r['dispatched_at'] ?? '')) ?: 0; $j = strtotime((string)($r['judged_at'] ?? '')) ?: 0;
      $run = 0; if ($r['state'] === 'judging' && (string)$r['tokens'] !== '') { $t = json_decode((string)$r['tokens'], true); $run = is_array($t) ? count($t) : 0; }
      $done = in_array($r['state'], ['done', 'error'], true);
      return ['id' => (int)$r['id'], 'who' => (string)($r['uname'] !== '' ? $r['uname'] : $r['login_id']), 'login' => (string)$r['login_id'], 'no' => (int)$r['prob_no'], 'title' => mb_substr((string)$r['title'], 0, 30),
              'lang' => (string)$r['lang'], 'state' => (string)$r['state'], 'verdict' => $r['verdict'], 'rejudge' => (int)$r['priority'] > 0, 'tries' => (int)$r['judge_tries'], 'canceled' => (int)$r['canceled'] === 1, 'mine' => (int)$r['mine'],
              'pass' => $done ? (int)$r['passed_count'] : (int)$r['judge_pos'], 'run' => $run, 'total' => (int)$r['total_count'],
              'wait' => max(0, ($done && $j ? $j : $now) - $q), 'judge' => $done && $j && $d ? max(0, $j - $d) : null, 'time' => $r['max_time'] !== null ? round((float)$r['max_time'], 2) : null,
              'srv' => $r['judge_srv'] !== null && $r['judge_srv'] !== '' ? ($names[(string)$r['judge_srv']] ?? '?') : '', 'at' => $done && $j ? date('H:i:s', $j) : date('H:i:s', $q)];
    };
    return ['judging' => array_map($fmt, all("$sel WHERE s.state = 'judging' ORDER BY s.id LIMIT 60")),
            'pending' => array_map($fmt, all("$sel WHERE s.state = 'pending' ORDER BY s.priority, s.id LIMIT 15")),
            'pending_total' => (int)col("SELECT COUNT(*) FROM submissions WHERE state = 'pending'"),
            'recent' => array_map($fmt, all("$sel WHERE s.state IN ('done','error') ORDER BY s.judged_at DESC, s.id DESC LIMIT 25"))];
  })();
  $hb = DATA_DIR . '/worker.heartbeat'; $pf = DATA_DIR . '/judge_pool.json';
  $pool = is_file($pf) ? json_decode((string)file_get_contents($pf), true) : null;
  $dbf = defined('DB_PATH') ? DB_PATH : (DATA_DIR . '/kait-class.db');
  $r60 = monitor_requests(60);
  echo json_encode([
    'now' => time(), 'proc' => monitor_proc(), 'db' => monitor_db(), 'req' => $r60, 'users5' => monitor_requests(300)['users'],
    'worker_age' => is_file($hb) ? time() - (int)filemtime($hb) : null,
    'pool' => $pool, 'pool_age' => $pool ? time() - (int)$pool['at'] : null,
    'dbsize' => is_file($dbf) ? (int)(filesize($dbf) / 1024) : null, 'walsize' => is_file($dbf . '-wal') ? (int)(filesize($dbf . '-wal') / 1024) : 0,
    'teams' => (int)col("SELECT COUNT(DISTINCT user_id) FROM submissions WHERE created_at >= ?", [date('Y-m-d H:i:s', time() - 300)]),
    'subs' => $subs,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ── 채점 설정 (언어별 시간 · 출력 · 스택) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tune_save'])) {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 저장해 주세요.');
  $time = [];
  foreach ((array)($_POST['mult'] ?? []) as $k => $m) {
    if (!isset(langs(false)[(string)$k])) continue;
    $m = max(1.0, min(10.0, (float)$m ?: 1.0)); $pl = max(0.0, min(10.0, (float)($_POST['plus'][$k] ?? 0)));
    if ($m > 1.0 || $pl > 0.0) $time[(string)$k] = [$m, $pl];
  }
  $f = (int)($_POST['file_kb'] ?? 0); $st = (int)($_POST['stack_kb'] ?? 0);
  $conf = ['time' => $time, 'file_kb' => $f > 0 ? max(1024, min(4096, $f)) : 0, 'stack_kb' => $st > 0 ? max(64000, min(128000, $st)) : 0];
  setting_set('judge_tune', ($time || $conf['file_kb'] || $conf['stack_kb']) ? json_encode($conf) : '');
  sfpc_log(null, null, $me, 'admin', '채점 설정을 바꿈 (시간 ' . ($time ? implode(', ', array_map(fn($k, $v) => $k . ' ×' . $v[0] . ' +' . $v[1] . '초', array_keys($time), $time)) : '그대로') . ' · 출력 ' . ($conf['file_kb'] ?: '기본') . ' · 스택 ' . ($conf['stack_kb'] ?: '기본') . ')');
  redirect_with('채점 설정을 저장했습니다. 채점 일꾼이 5초 안에 새 값을 씁니다. 이미 채점이 끝난 제출은 바뀌지 않습니다 (필요하면 재채점).');
}
$tune = judge_tune_conf(true);

/* ── 채점 서버 등록 ── */
$raw = json_decode(setting('judge_servers'), true); $saved = is_array($raw) ? $raw : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 저장해 주세요.');
  /* 인증 글자열 가져오기 — 채점 서버에서 `sfpc-judge-install.sh --share-token` 을 실행해 두면
     그 서버가 3분 동안, 본서버에게만, 한 번만 글자열을 내준다. 사람이 옮겨 적지 않아도 된다. */
  if (isset($_POST['pull'])) {
    $pu = rtrim(trim((string)$_POST['pull']), '/'); $hit = -1;
    foreach ($saved as $i => $s) if (rtrim((string)($s['url'] ?? ''), '/') === $pu) $hit = $i;
    $host = $hit >= 0 ? (string)parse_url($pu, PHP_URL_HOST) : '';
    if ($host === '') redirect_with('', '먼저 그 서버의 주소를 저장해 주세요.');
    $ch = curl_init('http://' . $host . ':2359/t');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
    $got = trim((string)curl_exec($ch)); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $got))
      redirect_with('', '가져오지 못했습니다. 채점 서버에서 bash sfpc-judge-install.sh --share-token 을 실행한 뒤 3분 안에 눌러 주세요.');
    $saved[$hit]['token'] = $got;
    setting_set('judge_servers', json_encode(array_values($saved), JSON_UNESCAPED_UNICODE));
    sfpc_log(null, null, $me, 'admin', '채점 서버 인증 글자열을 가져옴 (' . ($saved[$hit]['name'] ?? $pu) . ')');
    redirect_with('인증 글자열을 가져와 저장했습니다 (' . ($saved[$hit]['name'] ?? $pu) . '). 채점 일꾼이 곧 다시 연결합니다.');
  }
  $old = []; foreach ($saved as $s) $old[rtrim((string)($s['url'] ?? ''), '/')] = (string)($s['token'] ?? '');
  $list = [];
  foreach ((array)($_POST['url'] ?? []) as $i => $u) {
    $u = rtrim(trim((string)$u), '/'); if ($u === '') continue;
    if (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+(/.*)?$#', $u)) redirect_with('', '주소는 http:// 나 https:// 로 시작해야 합니다: ' . $u);
    $tok = trim((string)($_POST['token'][$i] ?? ''));
    $list[] = ['name' => sfpc_clean((string)($_POST['name'][$i] ?? ''), 30) ?: ('채점 서버 ' . (count($list) + 1)), 'url' => $u,
               'token' => !empty($_POST['token_clear'][$i]) ? '' : ($tok !== '' ? $tok : ($old[$u] ?? '')),
               'slots' => max(1, min(200, (int)($_POST['slots'][$i] ?? 6))), 'reserve' => !empty($_POST['reserve'][$i]) ? 1 : 0, 'on' => !empty($_POST['on'][$i]) ? 1 : 0];
  }
  setting_set('judge_servers', $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : '');
  sfpc_log(null, null, $me, 'admin', '채점 서버 목록을 바꿈 (' . count($list) . '대: ' . implode(', ', array_map(fn($s) => $s['name'] . ($s['reserve'] ? '[예비]' : '') . ($s['on'] ? '' : '[끔]'), $list)) . ')');
  redirect_with($list ? '채점 서버 ' . count($list) . '대를 저장했습니다. 채점 일꾼이 5초 안에 새 목록을 씁니다.' : '등록한 서버를 모두 지웠습니다. 예전처럼 config 의 judge0_url 한 대만 씁니다.');
}
[$msg, $err] = flash_take();
$rows = $saved ?: [];
for ($i = count($rows); $i < max(3, count($saved) + 1); $i++) $rows[] = ['name' => '', 'url' => '', 'token' => '', 'slots' => 6, 'reserve' => 0, 'on' => 1];

page_head(['title' => '서버 모니터링', 'root' => '../', 'user' => $me, 'nav' => 'admin']);
sfpc_css('../');
?>
<div class="wrap sfpc-mon">
  <div class="phead"><h1>서버 모니터링</h1><span class="sub" id="monAt">불러오는 중…</span><span class="grow"></span>
    <label class="small" style="display:flex; gap:6px; align-items:center"><input type="checkbox" id="monSound"> 위험할 때 소리</label>
    <a class="btn sm" href="sfpc.php?tab=rejudge">재채점</a><a class="btn sm" href="sfpc.php?tab=arena">준비 · 대회</a></div>
  <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>
  <div class="mon-banner" id="monBanner" hidden></div>

  <h2>채점 <span>제출이 밀리지 않고 처리되는지</span></h2>
  <div class="mon-grid" id="gJudge"></div>
  <h2>제출 하나하나 <span>지금 채점 중인 것 · 기다리는 것 · 방금 끝난 것. 칸 하나가 테스트케이스 하나입니다</span></h2>
  <div class="note ok" id="monSubNote" hidden></div>
  <div class="mon-subs" id="gSubs"></div>
  <div class="mon-legend small muted"><span><i class="c ok"></i>통과</span><span><i class="c run"></i>채점 중</span><span><i class="c bad"></i>여기서 틀려 멈춤</span><span><i class="c"></i>돌리지 않음</span>
    · <b>취소</b>: 대기 · 채점 중인 제출을 멈춥니다. 순위에 들어가지 않고(바깥 스코어보드에는 CE), 학생에게 다시 제출하라는 안내가 보입니다 · <b>재채점</b>: 끝난 제출 하나를 다시 채점합니다
    · 틀리면 거기서 멈추므로 뒤쪽 칸은 비어 있습니다 · 칸은 조각(3 · 6 · 12 · 20개) 단위로 채워집니다</div>
  <h2>채점 서버 <span>Judge0 마다 살아 있는지, 얼마나 맡겨 두었는지</span></h2>
  <div class="mon-grid" id="gPool"></div>
  <h2>본서버 <span>이 사이트가 돌아가는 기계</span></h2>
  <div class="mon-grid" id="gSys"></div>
  <h2>사이트 <span>화면이 빨리 열리는지, 몇 명이 쓰고 있는지</span></h2>
  <div class="mon-grid" id="gSite"></div>

  <h2>지난 기록 <span>1분마다 한 점. 부하 시험을 걸었을 때 어느 순간부터 무너지는지 봅니다</span>
    <select id="monSpan" style="margin-left:10px; width:auto; display:inline-block; font-size:12px"><option value="3600">1시간</option><option value="10800">3시간</option><option value="43200">12시간</option><option value="86400">하루</option></select></h2>
  <div class="mon-charts" id="monCharts"></div>

  <h2>채점 설정 <span>언어마다 시간을 더 줄지, 출력과 스택을 얼마까지 받을지. 비워 두면 예전과 같습니다</span></h2>
  <form method="post" class="mon-tune">
    <?= sfpc_csrf_field() ?><input type="hidden" name="tune_save" value="1">
    <div class="tablewrap"><table class="list">
      <thead><tr><th>언어</th><th class="num">시간 배수</th><th class="num">더하기 (초)</th><th>제한 시간 2초인 문제라면</th><th>컴파일 옵션 <span class="small muted">(운영 &gt; 언어에서 바꿉니다)</span></th></tr></thead><tbody>
      <?php foreach (langs(false) as $k => $L): $t = $tune['time'][$k] ?? [1, 0]; ?>
        <tr<?= $L['active'] ? '' : ' class="muted"' ?>><td><b><?= h($L['label']) ?></b> <span class="small muted"><?= h($k) ?><?= $L['active'] ? '' : ' · 꺼짐' ?></span></td>
          <td class="num"><input type="number" name="mult[<?= h($k) ?>]" min="1" max="10" step="0.5" value="<?= h((string)(float)$t[0]) ?>" style="width:80px"></td>
          <td class="num"><input type="number" name="plus[<?= h($k) ?>]" min="0" max="10" step="0.5" value="<?= h((string)(float)$t[1]) ?>" style="width:80px"></td>
          <td><?= rtrim(rtrim(number_format(judge_tune_cpu($k, 2.0), 2), '0'), '.') ?>초</td>
          <td><code><?= h($L['opts'] !== '' ? $L['opts'] : '(없음)') ?></code></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <div class="row" style="margin-top:10px; gap:18px; flex-wrap:wrap">
      <label class="small">출력 크기 상한 (KB) <input type="number" name="file_kb" min="0" max="4096" step="1" value="<?= (int)$tune['file_kb'] ?: '' ?>" placeholder="기본 1024" style="width:120px"></label>
      <label class="small">스택 상한 (KB) <input type="number" name="stack_kb" min="0" max="128000" step="1000" value="<?= (int)$tune['stack_kb'] ?: '' ?>" placeholder="기본 64000" style="width:120px"></label>
      <button class="btn primary">저장</button>
    </div>
    <ul class="small muted" style="margin:10px 0 0; padding-left:18px; line-height:1.8">
      <li>실제 제한 시간 = 문제의 제한 시간 × 배수 + 더하기. 채점 엔진이 받는 최대가 15초라 그보다 크면 15초가 됩니다. 문제 화면에는 문제의 제한 시간만 보이므로, 배수를 주면 학생들에게 따로 알려 주세요.</li>
      <li>출력 크기: 기본 1MB 를 넘으면 맞는 풀이도 런타임 오류가 됩니다. 4096KB 까지 올릴 수 있습니다. 스택: 기본 64MB, 깊은 재귀가 있는 문제면 128000KB 까지 올립니다.</li>
      <li>직접 실행(코드 돌려 보기)에도 같은 값이 적용됩니다. 바꾼 뒤에는 정답 풀이를 한 번씩 내 보고 확인하세요.</li>
    </ul>
  </form>

  <h2>채점 서버 등록 <span>주 서버는 동시에 나눠 쓰고, 예비는 주 서버가 모두 죽었을 때만 씁니다</span></h2>
  <form method="post" class="mon-servers">
    <?= sfpc_csrf_field() ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>이름</th><th>주소 (Judge0)</th><th>인증 글자열</th><th class="num">동시에 맡길 수</th><th>예비</th><th>사용</th></tr></thead><tbody>
      <?php foreach ($rows as $i => $s): $has = ($s['token'] ?? '') !== ''; ?>
        <tr><td><input type="text" name="name[<?= $i ?>]" maxlength="30" value="<?= h($s['name'] ?? '') ?>" placeholder="예: 채점 1" style="width:120px"></td>
          <td><input type="text" name="url[<?= $i ?>]" maxlength="200" value="<?= h($s['url'] ?? '') ?>" placeholder="http://10.0.0.5:2358" style="width:230px"></td>
          <td><input type="password" name="token[<?= $i ?>]" autocomplete="new-password" placeholder="<?= $has ? '정해져 있음 — 바꿀 때만 입력' : '없으면 빈칸' ?>" style="width:190px">
            <?php if ($has): ?><label class="small"><input type="checkbox" name="token_clear[<?= $i ?>]" value="1"> 지우기</label><?php endif; ?>
            <?php if (($s['url'] ?? '') !== ''): ?><button class="btn small" name="pull" value="<?= h($s['url']) ?>" title="채점 서버에서 --share-token 을 실행한 뒤 누릅니다">가져오기</button><?php endif; ?></td>
          <td class="num"><input type="number" name="slots[<?= $i ?>]" min="1" max="200" value="<?= (int)($s['slots'] ?? 6) ?>" style="width:70px"></td>
          <td><input type="checkbox" name="reserve[<?= $i ?>]" value="1"<?= !empty($s['reserve']) ? ' checked' : '' ?>></td>
          <td><input type="checkbox" name="on[<?= $i ?>]" value="1"<?= !isset($s['on']) || !empty($s['on']) ? ' checked' : '' ?>></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <div class="btnrow" style="margin-top:10px"><button class="btn primary">저장</button></div>
    <ul class="small muted" style="margin:10px 0 0; padding-left:18px; line-height:1.8">
      <li>한 줄도 적지 않으면 예전처럼 서버 설정 파일(config.php)의 <code>judge0_url</code> 한 대만 씁니다. 지금 값: <code><?= h(JUDGE0_URL) ?></code></li>
      <li>인증 글자열을 옮겨 적지 않으려면: 채점 서버에서 <code>bash sfpc-judge-install.sh --share-token</code> 을 실행하고 3분 안에 그 줄의 "가져오기"를 누릅니다. 채점 서버가 본서버에게만, 한 번만 내줍니다.</li>
      <li>주소 칸을 비우고 저장하면 그 줄은 지워집니다. 인증 글자열은 저장한 뒤에는 화면에 다시 보이지 않습니다.</li>
      <li>"동시에 맡길 수"는 그 서버에 한꺼번에 맡겨 두는 제출 수입니다. 테스트케이스가 10개인 문제면 6 × 10 = 60건이 그 서버 안에 있게 됩니다. 서버의 일꾼 수에 맞춰 정합니다.</li>
      <li>서버가 세 번 연달아 응답하지 않으면 죽은 것으로 보고, 맡겼던 제출을 살아 있는 서버로 다시 보냅니다. 죽은 서버는 10초마다 다시 찔러 봅니다.</li>
      <li>주 서버들은 <b>같은 사양 · 같은 Judge0 버전 · 같은 언어 설정</b>이어야 합니다. 다르면 같은 코드의 판정(시간 초과)이 서버에 따라 달라집니다.</li>
      <li>채점 서버는 방화벽으로 본서버에서만 접속되게 막아 주세요. Judge0 는 받은 코드를 실행해 주는 프로그램입니다.</li>
    </ul>
  </form>
</div>
<script>
(function () {
  'use strict';
  var prev = null, alarmOn = false, lastBeep = 0, hist = [], live = [];
  var $ = function (id) { return document.getElementById(id); };
  var fmtKB = function (kb) { return kb >= 1048576 ? (kb / 1048576).toFixed(1) + ' GB' : (kb >= 1024 ? (kb / 1024).toFixed(0) + ' MB' : kb + ' KB'); };
  var fmtBps = function (b) { b = Math.round(b); return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB/s' : (b >= 1024 ? (b / 1024).toFixed(0) + ' KB/s' : b + ' B/s'); };
  var fmtSec = function (s) { return s >= 3600 ? Math.floor(s / 3600) + '시간 ' + Math.floor(s % 3600 / 60) + '분' : (s >= 60 ? Math.floor(s / 60) + '분 ' + (s % 60) + '초' : s + '초'); };
  var lv = function (v, warn, bad) { return v === null || v === undefined ? 'na' : (v >= bad ? 'bad' : (v >= warn ? 'warn' : 'ok')); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  function card(title, value, level, note, extra) {
    return '<div class="mon-card ' + level + '"><i></i><div class="k">' + title + '</div><div class="v">' + value + '</div><div class="n">' + note + '</div>' + (extra ? '<div class="x">' + extra + '</div>' : '') + '</div>';
  }
  function bar(pct, level) { return '<div class="mon-bar"><b class="' + level + '" style="width:' + Math.max(0, Math.min(100, pct)) + '%"></b></div>'; }

  function render(d) {
    var bad = [], p = d.proc, cores = p.cores || 1, cpu = null, rx = null, tx = null;
    if (prev && p.cpu && prev.proc.cpu && p.cpu.total > prev.proc.cpu.total) {
      cpu = 100 * (1 - (p.cpu.idle - prev.proc.cpu.idle) / (p.cpu.total - prev.proc.cpu.total));
      var dt = Math.max(1, d.now - prev.now); rx = (p.net.rx - prev.proc.net.rx) / dt; tx = (p.net.tx - prev.proc.net.tx) / dt;
    }
    /* ── 채점 ── */
    var j = d.db, h = '';
    var wl = d.worker_age === null ? 'bad' : lv(d.worker_age, 20, 60);
    if (wl === 'bad') bad.push('채점 일꾼이 멈췄습니다');
    h += card('채점 일꾼', d.worker_age === null ? '신호 없음' : (wl === 'ok' ? '정상' : fmtSec(d.worker_age) + ' 전'), wl,
      wl === 'ok' ? '제출을 채점 서버에 넘기고 결과를 받아 적는 프로그램입니다. 살아 있습니다.' : '채점 일꾼의 신호가 끊겼습니다. 서버에서 <code>systemctl restart judge-worker</code> 로 다시 켭니다. 그동안의 제출은 기다렸다가 채점됩니다.');
    var pl = lv(j.pending, 15, 50); if (pl === 'bad') bad.push('채점 대기 ' + j.pending + '건');
    h += card('채점을 기다리는 제출', j.pending + '<small>건</small>', pl,
      pl === 'ok' ? '낸 뒤 아직 채점 서버에 넘어가지 않은 제출입니다. 0 에 가까우면 잘 따라가고 있는 것입니다.' : '제출이 처리보다 빨리 들어오고 있습니다. 계속 늘면 채점 서버가 부족한 것입니다.', '채점 중 ' + j.judging + '건 · 직접 실행 ' + j.runs + '건');
    var ol = lv(j.oldest_wait, 30, 120); if (ol === 'bad') bad.push('가장 오래 기다린 제출 ' + fmtSec(j.oldest_wait));
    h += card('가장 오래 기다린 제출', fmtSec(j.oldest_wait), ol, ol === 'ok' ? '지금 결과를 기다리는 제출 가운데 가장 오래된 것. 학생이 느끼는 기다림입니다.' : '학생이 결과를 한참 못 보고 있습니다. 채점 서버 상태를 확인하세요.',
      '최근 5분 평균 ' + (j.wait_avg === null ? '–' : j.wait_avg + '초') + ' · 최대 ' + (j.wait_max === null ? '–' : j.wait_max + '초'));
    var behind = j.sub_1m - j.done_1m;
    h += card('1분 동안', j.sub_1m + '<small>건 들어옴</small>', behind > 20 ? 'warn' : 'ok', '같은 1분에 ' + j.done_1m + '건을 채점했습니다. 들어온 것이 채점한 것보다 계속 많으면 밀리는 중입니다.', '최근 5분에 제출한 계정 ' + d.teams + '개');
    var il = lv(j.ie_10m, 1, 5); if (il === 'bad') bad.push('채점 오류 ' + j.ie_10m + '건');
    h += card('채점 오류 (최근 10분)', j.ie_10m + '<small>건</small>', il, il === 'ok' ? '채점 자체가 실패한 제출. 0 이 정상입니다.' : '채점 서버에 문제가 있었습니다. 원인을 고친 뒤 재채점 탭에서 "채점 오류만"으로 다시 돌립니다.');
    $('gJudge').innerHTML = h;

    /* ── 제출 하나하나 ── */
    if (d.subs) {
      var V = { AC: '맞았습니다', WA: '틀렸습니다', TLE: '시간 초과', MLE: '메모리 초과', RE: '런타임 오류', CE: '컴파일 오류', IE: '채점 오류' };
      var strip = function (x) {
        var n = x.total, done = x.state === 'done' || x.state === 'error', failAt = done && x.verdict !== 'AC' && x.verdict !== 'CE' && x.verdict !== 'IE' ? x.pass : -1;
        if (done && x.canceled) return '<span class="muted small">채점하지 않음</span>';
        if (!n) return '<span class="muted small">' + (done && x.verdict === 'CE' ? '컴파일에서 멈춤' : '–') + '</span>';
        if (n > 60) {      /* 케이스가 아주 많으면 칸 대신 막대 */
          var a = 100 * x.pass / n, r = 100 * x.run / n;
          return '<div class="mon-strip bar"><b class="ok" style="width:' + a + '%"></b>' + (x.run ? '<b class="run" style="width:' + r + '%"></b>' : '') + (failAt >= 0 ? '<b class="bad" style="width:' + Math.max(2, 100 / n) + '%"></b>' : '') + '</div>';
        }
        var o = '<div class="mon-strip">';
        for (var i = 0; i < n; i++) o += '<i class="' + (i < x.pass ? 'ok' : (i === failAt ? 'bad' : (!done && i < x.pass + x.run ? 'run' : ''))) + '"></i>';
        return o + '</div>';
      };
      var row = function (x, kind) {
        var st = kind === 'p' ? '<span class="v v-wait">대기</span>' : (kind === 'j' ? '<span class="v v-open">채점 중</span>' : '<span class="v v-' + esc(x.verdict || 'IE') + '" title="' + (V[x.verdict] || '') + '">' + esc(x.verdict || '?') + '</span>');
        var wl = kind === 'r' ? '' : (x.wait >= 120 ? ' bad' : (x.wait >= 30 ? ' warn' : ''));
        var act = kind === 'r' ? '<button class="btn small" data-sub="rejudge" data-id="' + x.id + '" data-who="' + esc(x.who) + '">재채점</button>'
                : '<button class="btn small danger" data-sub="cancel" data-id="' + x.id + '" data-who="' + esc(x.who) + '">취소</button>' + (x.mine > 1 ? ' <button class="btn small danger" data-sub="cancel_user" data-id="' + x.id + '" data-who="' + esc(x.who) + '" data-n="' + x.mine + '" title="이 팀 · 사용자의 대기 · 채점 중인 제출을 모두 취소">이 팀 ' + x.mine + '건</button>' : '');
        if (kind === 'r' && x.canceled) st = '<span class="v v-wait" title="운영진이 채점을 취소한 제출. 바깥에는 CE 로 나갑니다">취소됨</span>';
        return '<tr class="' + kind + '"><td class="num"><a href="../results.php?id=' + x.id + '">' + x.id + '</a></td><td>' + st + (x.rejudge ? ' <span class="small muted">재채점</span>' : '') + (x.tries > 1 ? ' <span class="small muted" title="다른 채점 서버로 옮겨진 횟수">×' + x.tries + '</span>' : '') + '</td>'
          + '<td class="who" title="' + esc(x.login) + '">' + esc(x.who) + '</td><td class="prob" title="' + esc(x.title) + '">' + x.no + ' <span class="muted">' + esc(x.title) + '</span></td><td>' + esc(x.lang) + '</td>'
          + '<td>' + strip(x) + '</td><td class="num">' + (x.total ? x.pass + ' / ' + x.total : '') + '</td>'
          + '<td class="num' + wl + '">' + fmtSec(x.wait) + '</td><td class="num">' + (x.time !== null && kind === 'r' ? x.time.toFixed(2) + '초' : '') + '</td><td class="srv" title="' + esc(x.srv) + '">' + esc(x.srv) + '</td><td class="num muted">' + x.at + '</td><td class="act">' + act + '</td></tr>';
      };
      var s = d.subs, t = '';
      s.judging.forEach(function (x) { t += row(x, 'j'); });
      s.pending.forEach(function (x) { t += row(x, 'p'); });
      if (s.pending_total > s.pending.length) t += '<tr class="more"><td colspan="12">… 기다리는 제출이 ' + (s.pending_total - s.pending.length) + '건 더 있습니다</td></tr>';
      if (!s.judging.length && !s.pending_total) t += '<tr class="more"><td colspan="12">지금 채점 중이거나 기다리는 제출이 없습니다</td></tr>';
      if (s.recent.length) t += '<tr class="sep"><td colspan="12">방금 끝난 제출</td></tr>';
      s.recent.forEach(function (x) { t += row(x, 'r'); });
      $('gSubs').innerHTML = '<div class="tablewrap"><table class="list mon-subtable"><thead><tr><th class="num">번호</th><th>상태</th><th>팀 · 사용자</th><th>문제</th><th>언어</th><th>테스트케이스</th><th class="num">통과</th><th class="num">기다린 시간</th><th class="num">실행</th><th>채점 서버</th><th class="num">시각</th><th></th></tr></thead><tbody>' + t + '</tbody></table></div>';
    }

    /* ── 채점 서버 ── */
    h = '';
    if (!d.pool) h = card('채점 서버 상태', '기록 없음', 'warn', '채점 일꾼이 상태를 아직 적지 않았습니다. 일꾼이 새 버전으로 다시 켜지면 나타납니다.');
    else {
      if (d.pool_age > 30) h += card('상태 기록', fmtSec(d.pool_age) + ' 전', 'warn', '채점 일꾼이 상태를 적은 지 오래되었습니다. 아래 숫자는 그때의 것입니다.');
      var mainAlive = 0, mainAll = 0;
      d.pool.servers.forEach(function (s) {
        if (!s.on) { h += card(esc(s.name), '꺼 둠', 'na', esc(s.url)); return; }
        if (!s.reserve) { mainAll++; if (s.alive) mainAlive++; }
        var pct = 100 * s.inflight / s.slots, w = s.workers;
        h += card(esc(s.name) + (s.reserve ? ' <em>예비</em>' : ''), s.alive ? s.inflight + '<small> / ' + s.slots + '</small>' : '죽음', s.alive ? (pct >= 100 ? 'warn' : 'ok') : 'bad',
          s.alive ? (s.reserve ? '주 서버가 모두 죽었을 때만 씁니다. ' : '') + '맡겨 둔 제출 / 한꺼번에 맡길 수 있는 수.' + (pct >= 100 ? ' 꽉 차 있습니다.' : '') : fmtSec(d.now - s.down_since) + ' 전부터 응답이 없습니다. 맡겼던 제출은 다른 서버로 되돌렸습니다. 살아나면 자동으로 다시 씁니다.' + (s.err ? '<br>마지막 오류: ' + esc(s.err) : ''),
          (s.alive ? bar(pct, pct >= 100 ? 'warn' : 'ok') : '') + esc(s.url) + (s.ms !== null && s.alive ? ' · 응답 ' + s.ms + 'ms' : '') + (w && s.alive ? ' · 안쪽 대기 ' + w.queue + ' · 일꾼 ' + w.working + '/' + w.available : ''));
      });
      if (mainAll && mainAlive < mainAll) bad.push(mainAlive === 0 ? '주 채점 서버가 모두 죽었습니다 — 예비로 버티는 중' : '채점 서버 ' + (mainAll - mainAlive) + '대가 죽었습니다');
      var st = d.pool.stat;
      h += card('채점 일꾼이 켜진 뒤', st.finished + '<small>건 채점</small>', st.moved > 0 ? 'warn' : 'ok', '다른 서버로 옮긴 제출 ' + st.moved + '건. 서버가 죽었거나 너무 오래 걸려 다시 보낸 횟수입니다.', fmtSec(d.now - st.started_at) + ' 전에 켜짐' + (d.pool.multi ? '' : ' · 등록한 서버 없음(기본 한 대)'));
    }
    $('gPool').innerHTML = h;

    /* ── 본서버 ── */
    h = '';
    var cl = lv(cpu, 65, 85); if (cl === 'bad') bad.push('CPU ' + cpu.toFixed(0) + '%');
    h += card('CPU', cpu === null ? '…' : cpu.toFixed(0) + '<small>%</small>', cl, cl === 'bad' ? '거의 다 쓰고 있습니다. 화면이 느려지고 채점이 밀립니다.' : '서버의 두뇌를 얼마나 쓰고 있는지. 잠깐 치솟는 것은 괜찮고, 계속 높으면 문제입니다.', (cpu === null ? '' : bar(cpu, cl)) + '코어 ' + cores + '개');
    var load = p.load ? p.load[0] : null, ll = lv(load === null ? null : load / cores, 1.0, 1.5); if (ll === 'bad') bad.push('부하 ' + load.toFixed(1));
    h += card('부하', load === null ? '–' : load.toFixed(2), ll, '차례를 기다리는 일의 수. 코어 수(' + cores + ')보다 작으면 여유가 있고, 크면 일이 밀리고 있는 것입니다.', p.load ? '1분 ' + p.load[0].toFixed(2) + ' · 5분 ' + p.load[1].toFixed(2) + ' · 15분 ' + p.load[2].toFixed(2) : '');
    if (p.mem && p.mem.total) {
      var mu = 100 * (1 - p.mem.avail / p.mem.total), ml = lv(mu, 75, 90); if (ml === 'bad') bad.push('메모리 ' + mu.toFixed(0) + '%');
      h += card('메모리', mu.toFixed(0) + '<small>%</small>', ml, ml === 'bad' ? '거의 다 찼습니다. 더 차면 스왑을 쓰기 시작하고 모든 것이 느려집니다.' : '프로그램들이 쓰는 작업 공간. 90% 를 넘으면 위험합니다.', bar(mu, ml) + '남은 ' + fmtKB(p.mem.avail) + ' / 전체 ' + fmtKB(p.mem.total));
      var su = p.mem.swap_total ? 100 * (1 - p.mem.swap_free / p.mem.swap_total) : 0, sl = !p.mem.swap_total ? 'na' : lv(su, 1, 10); if (sl === 'bad') bad.push('스왑 ' + su.toFixed(0) + '%');
      h += card('스왑', p.mem.swap_total ? su.toFixed(0) + '<small>%</small>' : '없음', sl, p.mem.swap_total ? '메모리가 모자라 디스크를 대신 쓰는 양. 0 이 정상이고, 늘어나면 서버가 눈에 띄게 느려집니다.' : '이 서버에는 스왑이 없습니다. 메모리가 다 차면 프로그램이 강제로 꺼질 수 있습니다.');
    }
    if (p.disk && p.disk.total) { var du = 100 * (1 - p.disk.free / p.disk.total), dl = lv(du, 80, 90); if (dl === 'bad') bad.push('디스크 ' + du.toFixed(0) + '%');
      h += card('디스크', du.toFixed(0) + '<small>%</small>', dl, '꽉 차면 제출을 저장하지 못합니다.', bar(du, dl) + '남은 ' + fmtKB(p.disk.free) + ' · DB ' + (d.dbsize === null ? '–' : fmtKB(d.dbsize)) + (d.walsize > 102400 ? ' · 쓰기 대기 ' + fmtKB(d.walsize) : '')); }
    h += card('네트워크', rx === null ? '…' : fmtBps(rx) + '<small> 받음</small>', 'ok', '초당 들어오고 나가는 양.', tx === null ? '' : '보냄 ' + fmtBps(tx));
    $('gSys').innerHTML = h;

    /* ── 사이트 ── */
    h = ''; var r = d.req;
    h += card('화면 요청', r.per_sec + '<small>/초</small>', 'ok', '최근 1분 동안 열린 화면 수 ' + r.n + '개. (제출 · 채점 확인 요청은 여기에 들어가지 않습니다.)');
    var rl = lv(r.p95, 1000, 3000); if (rl === 'bad') bad.push('화면 응답 ' + (r.p95 / 1000).toFixed(1) + '초');
    h += card('화면 응답 시간', r.avg === null ? '–' : r.avg + '<small>ms</small>', rl, rl === 'ok' ? '화면 하나를 만들어 내는 데 걸린 평균 시간. 1000ms(1초)를 넘으면 느리다고 느낍니다.' : '화면이 느리게 열리고 있습니다. CPU · 메모리와 채점 대기를 같이 보세요.', r.p95 === null ? '' : '느린 쪽 5%: ' + r.p95 + 'ms · 가장 느린 것 ' + r.max + 'ms');
    h += card('접속 중', r.users + '<small>명</small>', 'ok', '최근 1분 안에 화면을 연 로그인 계정 수.', '최근 5분: ' + d.users5 + '명');
    $('gSite').innerHTML = h;

    /* 경고 */
    var b = $('monBanner'); b.hidden = !bad.length; b.textContent = bad.length ? '⚠ ' + bad.join(' · ') : '';
    document.title = (bad.length ? '⚠ ' : '') + '서버 모니터링';
    document.body.classList.toggle('mon-alarm', bad.length > 0);
    if (bad.length && $('monSound').checked && Date.now() - lastBeep > 15000) { lastBeep = Date.now(); beep(); }
    $('monAt').textContent = new Date(d.now * 1000).toLocaleTimeString() + ' 기준 · 3초마다 새로 고침';
    if (cpu !== null) { live.push({ t: d.now, cpu: +cpu.toFixed(1), mem: p.mem ? +(100 * (1 - p.mem.avail / p.mem.total)).toFixed(1) : null, pending: j.pending, judging: j.judging, wait: j.wait_avg, ms: r.avg, users: r.users, req: r.n, sub: j.sub_1m, done: j.done_1m, load: load }); if (live.length > 40) live.shift(); }
    prev = d; charts();
  }
  /* 제출 하나하나의 단추 — 재채점 · 취소 */
  $('gSubs').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-sub]'); if (!b) return;
    var act = b.getAttribute('data-sub'), id = b.getAttribute('data-id'), who = b.getAttribute('data-who');
    var q = act === 'rejudge' ? '#' + id + ' (' + who + ') 을 다시 채점할까요?\n새 판정이 나면 순위와 바깥 스코어보드에 반영됩니다.'
          : act === 'cancel' ? '#' + id + ' (' + who + ') 의 채점을 취소할까요?\n순위에는 반영되지 않고, 학생에게는 "운영진이 채점을 취소했습니다. 다시 제출해 주세요"가 보입니다.'
          : who + ' 의 대기 · 채점 중인 제출 ' + b.getAttribute('data-n') + '건을 모두 취소할까요?\n순위에는 반영되지 않습니다.';
    if (!window.confirm(q)) return;
    b.disabled = true;
    var fd = new FormData(); fd.append('csrf', <?= json_encode(sfpc_csrf()) ?>); fd.append('sub_act', act); fd.append('id', id);
    fetch('sfpc_monitor.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var n = $('monSubNote'); n.textContent = j.msg; n.className = 'note ' + (j.ok ? 'ok' : 'err'); n.hidden = false; setTimeout(function () { n.hidden = true; }, 6000); tick();
    }).catch(function () { b.disabled = false; });
  });
  function beep() { try { var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.value = .15; o.start(); setTimeout(function () { o.frequency.value = 660; }, 180); setTimeout(function () { o.stop(); a.close(); }, 400); } catch (e) {} }

  /* 지난 기록 그래프 — 1분마다의 점 뒤에 방금의 값을 잇는다 */
  var CH = [['cpu', 'CPU %', 100, [['cpu', '#D85A30']]], ['mem', '메모리 %', 100, [['mem', '#378ADD']]], ['q', '채점 대기 · 채점 중 (건)', 0, [['pending', '#E24B4A'], ['judging', '#1D9E75']]],
            ['flow', '1분에 들어온 제출 · 채점한 제출', 0, [['sub', '#7F77DD'], ['done', '#1D9E75']]], ['wait', '채점까지 기다린 시간 (초)', 0, [['wait', '#BA7517']]], ['ms', '화면 응답 시간 (ms)', 0, [['ms', '#D4537E']]], ['users', '접속 중 (명)', 0, [['users', '#185FA5']]]];
  function charts() {
    var box = $('monCharts');
    if (!box.children.length) CH.forEach(function (c) { var d = document.createElement('div'); d.className = 'mon-chart'; d.innerHTML = '<div class="t">' + c[1] + ' <span id="cv_' + c[0] + '"></span></div><canvas id="c_' + c[0] + '" width="560" height="120"></canvas>'; box.appendChild(d); });
    var lastT = hist.length ? hist[hist.length - 1].t : 0, data = hist.concat(live.filter(function (x) { return x.t > lastT + 60; }).slice(-1));
    CH.forEach(function (c) {
      var cv = $('c_' + c[0]), g = cv.getContext('2d'), W = cv.width, H = cv.height; g.clearRect(0, 0, W, H);
      var max = c[2]; if (!max) { max = 1; data.forEach(function (x) { c[3].forEach(function (s) { if (x[s[0]] > max) max = x[s[0]]; }); }); max = Math.ceil(max * 1.15); }
      g.strokeStyle = '#E6E4F3'; g.lineWidth = 1; for (var k = 0; k <= 2; k++) { var y = 6 + (H - 22) * k / 2; g.beginPath(); g.moveTo(34, y); g.lineTo(W, y); g.stroke(); }
      g.fillStyle = '#8a889c'; g.font = '10px sans-serif'; g.textAlign = 'right'; g.fillText(max, 30, 12); g.fillText('0', 30, H - 14);
      if (data.length < 2) { g.textAlign = 'center'; g.fillText('기록이 쌓이는 중입니다 (1분마다 한 점)', W / 2, H / 2); return; }
      var t0 = data[0].t, t1 = data[data.length - 1].t, span = Math.max(60, t1 - t0), last = [];
      c[3].forEach(function (s) { g.strokeStyle = s[1]; g.lineWidth = 2; g.beginPath(); var on = false;
        data.forEach(function (x) { var v = x[s[0]]; if (v === null || v === undefined) { on = false; return; } var px = 34 + (W - 38) * (x.t - t0) / span, py = 6 + (H - 22) * (1 - Math.min(v, max) / max); if (on) g.lineTo(px, py); else { g.moveTo(px, py); on = true; } });
        g.stroke(); var lv2 = data[data.length - 1][s[0]]; last.push('<b style="color:' + s[1] + '">' + (lv2 === null || lv2 === undefined ? '–' : lv2) + '</b>'); });
      g.textAlign = 'left'; g.fillText(new Date(t0 * 1000).toTimeString().slice(0, 5), 34, H - 2); g.textAlign = 'right'; g.fillText(new Date(t1 * 1000).toTimeString().slice(0, 5), W - 2, H - 2);
      $('cv_' + c[0]).innerHTML = last.join(' · ');
    });
  }
  function loadHist() { fetch('?hist=1&sec=' + $('monSpan').value, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { hist = j; charts(); }).catch(function () {}); }
  function tick() {
    if (document.hidden && !$('monSound').checked) return;
    fetch('?json=1', { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(render).catch(function () {
      var b = $('monBanner'); b.hidden = false; b.textContent = '⚠ 서버가 응답하지 않습니다 — 화면을 새로 고쳐도 같으면 서버가 멈춘 것입니다';
      document.body.classList.add('mon-alarm'); document.title = '⚠ 서버 모니터링';
      if ($('monSound').checked && Date.now() - lastBeep > 15000) { lastBeep = Date.now(); beep(); }
    });
  }
  try { $('monSound').checked = localStorage.getItem('sfpc_mon_sound') === '1'; } catch (e) {}
  $('monSound').addEventListener('change', function () { try { localStorage.setItem('sfpc_mon_sound', this.checked ? '1' : '0'); } catch (e) {} if (this.checked) beep(); });
  $('monSpan').addEventListener('change', loadHist);
  tick(); setInterval(tick, 3000); loadHist(); setInterval(loadHist, 60000);
})();
</script>
<?php page_foot();
