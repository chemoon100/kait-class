<?php
/* ═══════════════════════════════════════════════
   admin/sfpc.php — 운영 > SFPC

     ?tab=summary   접수 현황 (지역 · 참가 구분별 집계, 현장 참가 인원과 상의 사이즈)
     ?tab=teams     팀 목록 (찾기 · 참가 자격 · 반려 · 메모 · 내려받기)
     ?tab=schools   학교 목록 (나이스에서 받기 · CSV 올리기)
     ?tab=settings  접수 기간 · 안내 문구 · 화면 공개
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../layout.php';
$me = need_admin('../');

const SFPC_TABS = ['summary' => '접수 현황', 'teams' => '팀 목록', 'schools' => '학교 목록', 'settings' => '설정'];
$tab = (string)($_GET['tab'] ?? 'summary');
if (!isset(SFPC_TABS[$tab])) $tab = 'summary';

/* ── 팀 목록 조건 (화면과 내려받기가 같이 쓴다) ── */
function sfpc_admin_filter(): array {
  $where = ["t.contest = ?"]; $args = [SFPC_CONTEST];
  $f = [
    'region'   => (string)($_GET['region'] ?? ''),
    'category' => (string)($_GET['category'] ?? ''),
    'status'   => (string)($_GET['status'] ?? 'submitted'),
    'flag'     => (string)($_GET['flag'] ?? ''),
    'q'        => sfpc_clean((string)($_GET['q'] ?? ''), 30),
  ];
  if (in_array($f['region'], SFPC_REGIONS, true)) { $where[] = "t.region = ?";   $args[] = $f['region']; } else $f['region'] = '';
  if (isset(SFPC_CATS[$f['category']]))           { $where[] = "t.category = ?"; $args[] = $f['category']; } else $f['category'] = '';
  if (isset(SFPC_STATUS_NAME[$f['status']]))      { $where[] = "t.status = ?";   $args[] = $f['status']; } else $f['status'] = 'all';
  if ($f['flag'] === 'manual')      $where[] = "t.school_manual = 1";
  elseif ($f['flag'] === 'onsite')  $where[] = "t.onsite = 1";
  elseif ($f['flag'] === 'participant' || $f['flag'] === 'observer') { $where[] = "t.track = ?"; $args[] = $f['flag']; }
  else $f['flag'] = '';
  if ($f['q'] !== '') {
    $like = '%' . strtr($f['q'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    $where[] = "(t.team_name LIKE ? ESCAPE '\\' OR t.school_name LIKE ? ESCAPE '\\' OR c.name LIKE ? ESCAPE '\\'
                 OR EXISTS(SELECT 1 FROM sfpc_members m WHERE m.team_id = t.id AND m.name LIKE ? ESCAPE '\\'))";
    array_push($args, $like, $like, $like, $like);
  }
  return [$f, implode(' AND ', $where), $args];
}
function sfpc_admin_teams(string $where, array $args): array {
  $teams = all("SELECT t.*, c.name AS coach_name, c.phone AS coach_phone, c.email AS coach_email, u.login_id AS coach_login
                FROM sfpc_teams t
                LEFT JOIN sfpc_coaches c ON c.user_id = t.coach_user_id
                LEFT JOIN users u ON u.id = t.coach_user_id
                WHERE $where ORDER BY t.id", $args);
  $byTeam = [];
  foreach (all("SELECT m.* FROM sfpc_members m JOIN sfpc_teams t ON t.id = m.team_id
                WHERE t.contest = ? ORDER BY m.team_id, m.seq", [SFPC_CONTEST]) as $m) {
    $byTeam[(int)$m['team_id']][] = $m;
  }
  foreach ($teams as &$t) $t['members'] = $byTeam[(int)$t['id']] ?? [];
  return $teams;
}

/* ── 내려받기 (CSV — 엑셀에서 바로 열린다) ── */
function sfpc_csv_cell($v): string {
  $s = (string)$v;
  /* 엑셀이 수식으로 읽을 수 있는 글자로 시작하면 글자로 고정한다 */
  if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
  return '"' . str_replace('"', '""', $s) . '"';
}
function sfpc_csv_out(string $file, array $head, array $rows): never {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $file . '"');
  header('Cache-Control: no-store');
  echo "\xEF\xBB\xBF";                                   /* 엑셀이 한글을 깨뜨리지 않게 */
  echo implode(',', array_map('sfpc_csv_cell', $head)) . "\r\n";
  foreach ($rows as $r) echo implode(',', array_map('sfpc_csv_cell', $r)) . "\r\n";
  exit;
}
if (isset($_GET['export'])) {
  [, $where, $args] = sfpc_admin_filter();
  $teams = sfpc_admin_teams($where, $args);
  $stamp = date('Ymd-Hi');
  $way = fn($t) => (int)$t['onsite'] ? '현장' : '온라인';
  if ($_GET['export'] === 'members') {
    $rows = [];
    foreach ($teams as $t) foreach ($t['members'] as $m) {
      $rows[] = [sfpc_team_no($t), SFPC_STATUS_NAME[$t['status']] ?? '', $t['region'], SFPC_CATS[$t['category']]['label'] ?? '',
                 SFPC_TRACK_NAME[$t['track']] ?? '', $way($t), $t['school_name'], $t['team_name'],
                 $m['seq'], $m['name'], $m['grade'] ?: '', $m['shirt'], $t['coach_name'], $t['coach_phone']];
    }
    sfpc_csv_out("sfpc-members-$stamp.csv",
      ['접수번호','상태','지역','참가구분','참가자격','참가방식','학교','팀명','순번','이름','학년','상의사이즈','지도교사','연락처'], $rows);
  }
  $rows = [];
  foreach ($teams as $t) {
    $names = implode(', ', array_map(fn($m) => $m['name'] . ($m['grade'] ? '(' . $m['grade'] . ')' : ''), $t['members']));
    $rows[] = [sfpc_team_no($t), SFPC_STATUS_NAME[$t['status']] ?? '', $t['region'], SFPC_CATS[$t['category']]['label'] ?? '',
               SFPC_TRACK_NAME[$t['track']] ?? '', $way($t), $t['school_name'], (int)$t['school_manual'] ? '직접 입력' : '',
               $t['team_name'], count($t['members']), $names, $t['coach_name'], $t['coach_phone'], $t['coach_email'],
               $t['coach_login'], $t['created_at'], $t['updated_at'], $t['admin_memo']];
  }
  sfpc_csv_out("sfpc-teams-$stamp.csv",
    ['접수번호','상태','지역','참가구분','참가자격','참가방식','학교','학교확인','팀명','인원','팀원','지도교사','연락처','이메일',
     '지도교사아이디','접수일시','수정일시','메모'], $rows);
}

/* ── 학교 목록 받기 ───────────────────────────── */
/* 나이스 교육정보 개방 포털 '학교기본정보'. 중학교 · 고등학교만 받는다. [넣은 수, 오류 문구] */
function sfpc_neis_fetch(string $key): array {
  if (!function_exists('curl_init')) return [0, '서버에 curl 이 없어 받아올 수 없습니다. CSV 로 올려 주세요.'];
  @set_time_limit(180);
  $rows = [];
  foreach (['중학교', '고등학교'] as $kind) {
    for ($page = 1; $page <= 20; $page++) {
      $url = 'https://open.neis.go.kr/hub/schoolInfo?' . http_build_query([
        'KEY' => $key, 'Type' => 'json', 'pIndex' => $page, 'pSize' => 1000, 'SCHUL_KND_SC_NM' => $kind]);
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10]);
      $body = curl_exec($ch);
      $cerr = curl_error($ch);
      curl_close($ch);
      if ($body === false) return [0, '나이스에 연결하지 못했습니다: ' . $cerr];
      $j = json_decode((string)$body, true);
      if (!is_array($j)) return [0, '나이스의 응답을 읽지 못했습니다.'];
      if (isset($j['RESULT'])) {                          /* 데이터 없음 또는 오류 */
        $code = (string)($j['RESULT']['CODE'] ?? '');
        if ($code === 'INFO-200') break;                   /* 더 없음 */
        return [0, '나이스 오류: ' . (string)($j['RESULT']['MESSAGE'] ?? $code)];
      }
      $got = $j['schoolInfo'][1]['row'] ?? [];
      if (!is_array($got) || !$got) break;
      foreach ($got as $r) $rows[] = $r;
      if (count($got) < 1000) break;
    }
  }
  if (!$rows) return [0, '받은 학교가 없습니다. 인증키를 확인해 주세요.'];
  $n = tx(function (PDO $d) use ($rows) {
    $n = 0;
    foreach ($rows as $r) {
      if (sfpc_school_put($d, (string)($r['SD_SCHUL_CODE'] ?? ''), (string)($r['SCHUL_NM'] ?? ''),
                          (string)($r['LCTN_SC_NM'] ?? ($r['ATPT_OFCDC_SC_NM'] ?? '')), (string)($r['SCHUL_KND_SC_NM'] ?? ''),
                          (string)($r['HS_SC_NM'] ?? ''), (string)($r['ORG_RDNMA'] ?? ''))) $n++;
    }
    return $n;
  });
  return [(int)$n, ''];
}
/* CSV 올리기 — 첫 줄의 열 이름으로 칸을 찾는다 (나이스 · 학교알리미에서 받은 파일의 이름들을 함께 받는다) */
function sfpc_school_csv(string $path): array {
  $raw = (string)file_get_contents($path);
  if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
  if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)@iconv('CP949', 'UTF-8//IGNORE', $raw);   /* 엑셀에서 저장한 CSV */
  $fp = fopen('php://temp', 'r+'); fwrite($fp, $raw); rewind($fp);
  $head = fgetcsv($fp, 0, ',', '"', '');
  if (!$head) return [0, '파일이 비어 있습니다.'];
  $head = array_map(fn($h) => preg_replace('/\s+/u', '', (string)$h), $head);
  $pickCol = function (array $names) use ($head): int {
    foreach ($names as $n) { $i = array_search($n, $head, true); if ($i !== false) return (int)$i; }
    return -1;
  };
  $cName   = $pickCol(['학교명', 'SCHUL_NM']);
  $cRegion = $pickCol(['소재지명', '시도명', '시도', '지역', 'LCTN_SC_NM', '시도교육청명', 'ATPT_OFCDC_SC_NM']);
  $cKind   = $pickCol(['학교종류명', '학교급구분', '학교급', 'SCHUL_KND_SC_NM']);
  $cState  = $pickCol(['운영상태']);                      /* 공공데이터포털 표준데이터 — 폐교를 거른다 */
  $cType   = $pickCol(['고등학교구분명', '고교유형', 'HS_SC_NM']);
  $cAddr   = $pickCol(['도로명주소', '주소', 'ORG_RDNMA']);
  $cCode   = $pickCol(['행정표준코드', '표준학교코드', '학교코드', '학교ID', 'SD_SCHUL_CODE']);
  if ($cName < 0 || $cRegion < 0 || $cKind < 0) {
    return [0, '열 이름을 찾지 못했습니다. 첫 줄에 "학교명", "소재지명"(또는 시도), "학교종류명"(또는 학교급)이 있어야 합니다.'];
  }
  $rows = [];
  while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) $rows[] = $r;
  fclose($fp);
  $n = tx(function (PDO $d) use ($rows, $cName, $cRegion, $cKind, $cType, $cAddr, $cCode, $cState) {
    $n = 0;
    foreach ($rows as $r) {
      $g = fn(int $i) => $i >= 0 ? (string)($r[$i] ?? '') : '';
      if ($cState >= 0 && trim($g($cState)) !== '운영') continue;
      if (sfpc_school_put($d, $g($cCode), $g($cName), $g($cRegion), $g($cKind), $g($cType), $g($cAddr))) $n++;
    }
    return $n;
  });
  return [(int)$n, $n ? '' : '넣을 수 있는 중학교 · 고등학교가 없었습니다. 열 내용을 확인해 주세요.'];
}

/* ── 저장 처리 ───────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 시도해 주세요.');
  $act = (string)($_POST['act'] ?? '');

  if ($act === 'team') {
    $t = sfpc_team((int)($_POST['id'] ?? 0));
    if (!$t) redirect_with('', '팀을 찾지 못했습니다.');
    $track  = (string)($_POST['track'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $memo   = sfpc_clean((string)($_POST['memo'] ?? ''), 200);
    if (!isset(SFPC_TRACK_NAME[$track]) || !isset(SFPC_STATUS_NAME[$status])) redirect_with('', '값을 확인해 주세요.');
    db()->prepare("UPDATE sfpc_teams SET track = ?, status = ?, admin_memo = ?, updated_at = ? WHERE id = ?")
        ->execute([$track, $status, $memo, now(), $t['id']]);
    redirect_with(sfpc_team_no($t) . ' "' . $t['team_name'] . '" 팀을 고쳤습니다.');
  }

  if ($act === 'settings') {
    $from = str_replace('T', ' ', trim((string)($_POST['apply_from'] ?? '')));
    $to   = str_replace('T', ' ', trim((string)($_POST['apply_to'] ?? '')));
    $host = (string)($_POST['host_region'] ?? '');
    $title = sfpc_clean((string)($_POST['title'] ?? ''), 40);
    if ($title === '')                                 redirect_with('', '대회 이름을 입력하세요.');
    if (!sfpc_ts($from) || !sfpc_ts($to))              redirect_with('', '접수 기간을 날짜와 시각으로 입력하세요.');
    if (sfpc_ts($from) >= sfpc_ts($to))                redirect_with('', '접수 마감이 시작보다 뒤여야 합니다.');
    if (!in_array($host, SFPC_REGIONS, true))          redirect_with('', '개최 지역을 고르세요.');
    setting_set('sfpc_title', $title);
    setting_set('sfpc_host_region', $host);
    setting_set('sfpc_apply_from', substr($from, 0, 16));
    setting_set('sfpc_apply_to', substr($to, 0, 16));
    setting_set('sfpc_schedule', trim((string)($_POST['schedule'] ?? '')));
    setting_set('sfpc_privacy', trim((string)($_POST['privacy'] ?? '')));
    setting_set('sfpc_contact', sfpc_clean((string)($_POST['contact'] ?? ''), 100));
    foreach (['menu_on', 'landing_on', 'contest_open', 'board_open'] as $k) setting_set('sfpc_' . $k, !empty($_POST[$k]) ? '1' : '0');
    redirect_with('설정을 저장했습니다.');
  }

  if ($act === 'neis') {
    if (DEMO_MODE) redirect_with('', DEMO_BLOCKED);
    $key = trim((string)($_POST['neis_key'] ?? ''));
    if ($key === '') $key = sfpc_setting('sfpc_neis_key');
    if ($key === '') redirect_with('', '나이스 인증키를 입력하세요.');
    setting_set('sfpc_neis_key', $key);
    [$n, $e] = sfpc_neis_fetch($key);
    if ($e !== '') redirect_with('', $e);
    setting_set('sfpc_schools_at', now());
    redirect_with('학교 ' . number_format($n) . '곳을 받아 넣었습니다.');
  }

  if ($act === 'bundled') {
    $n = sfpc_schools_load_bundled();
    if (!$n) redirect_with('', '함께 넣어 둔 학교 목록 파일을 읽지 못했습니다.');
    setting_set('sfpc_schools_at', now());
    redirect_with('학교 ' . number_format($n) . '곳을 넣었습니다.');
  }

  if ($act === 'csv') {
    if (DEMO_MODE) redirect_with('', DEMO_BLOCKED);
    $f = $_FILES['file'] ?? null;
    $ferr = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($ferr === UPLOAD_ERR_INI_SIZE || $ferr === UPLOAD_ERR_FORM_SIZE)
      redirect_with('', '파일이 서버가 한 번에 받을 수 있는 크기(' . ini_get('upload_max_filesize') . ')를 넘습니다. 중학교 · 고등학교 줄만 남겨 올려 주세요.');
    if (!$f || $ferr !== UPLOAD_ERR_OK) redirect_with('', '파일을 받지 못했습니다.');
    if ($f['size'] > 8 * 1024 * 1024) redirect_with('', '파일은 8MB 까지 올릴 수 있습니다.');
    [$n, $e] = sfpc_school_csv($f['tmp_name']);
    if ($e !== '') redirect_with('', $e);
    setting_set('sfpc_schools_at', now());
    redirect_with('학교 ' . number_format($n) . '곳을 넣었습니다.');
  }

  redirect_with('', '처리할 수 없는 요청입니다.');
}

[$msg, $err] = flash_take();
$state = sfpc_apply_state();

page_head(['title' => 'SFPC', 'root' => '../', 'user' => $me, 'nav' => 'ops']);
sfpc_css('../');
?>
<div class="wrap">
  <div class="phead">
    <h1>운영</h1>
    <span class="sub"><?= h(sfpc_setting('sfpc_title')) ?> ·
      접수 <?= ['before' => '시작 전', 'open' => '진행 중', 'closed' => '마감'][$state] ?> (<?= h(sfpc_period_text()) ?>)</span>
    <span class="grow"></span>
    <a class="btn sm" href="../sfpc.php">접수 화면 보기</a>
  </div>
  <?= ops_tabs('sfpc') ?>
  <div class="sfpc-subtabs">
    <?php foreach (SFPC_TABS as $k => $label): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= h($k) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>

<?php if ($tab === 'summary'):
  /* ── 접수 현황 ── */
  $rows = all("SELECT t.region, t.category, t.track, t.onsite, t.school_manual,
                      (SELECT COUNT(*) FROM sfpc_members m WHERE m.team_id = t.id) AS n
               FROM sfpc_teams t WHERE t.contest = ? AND t.status = 'submitted'", [SFPC_CONTEST]);
  $tot = ['teams' => 0, 'people' => 0, 'participant' => 0, 'observer' => 0, 'onsite_t' => 0, 'onsite_p' => 0, 'manual' => 0];
  $grid = [];   /* [지역][구분] = [팀, 명] */
  foreach ($rows as $r) {
    $tot['teams']++; $tot['people'] += (int)$r['n']; $tot[$r['track']]++;
    if ((int)$r['onsite']) { $tot['onsite_t']++; $tot['onsite_p'] += (int)$r['n']; }
    if ((int)$r['school_manual'] && $r['category'] !== 'preteacher') $tot['manual']++;
    $g = &$grid[$r['region']][$r['category']];
    $g = [($g[0] ?? 0) + 1, ($g[1] ?? 0) + (int)$r['n']];
    unset($g);
  }
  $coaches = (int)col("SELECT COUNT(DISTINCT coach_user_id) FROM sfpc_teams WHERE contest = ? AND status = 'submitted'", [SFPC_CONTEST]);
  $shirts = [];
  foreach (all("SELECT m.shirt, COUNT(*) AS n FROM sfpc_members m JOIN sfpc_teams t ON t.id = m.team_id
                WHERE t.contest = ? AND t.status = 'submitted' AND t.onsite = 1 GROUP BY m.shirt", [SFPC_CONTEST]) as $r) {
    $shirts[$r['shirt']] = (int)$r['n'];
  }
  $host = sfpc_setting('sfpc_host_region');
  $cell = fn(?array $g) => $g ? $g[0] . '팀 <span class="muted">' . $g[1] . '명</span>' : '';
?>
  <div class="statgrid">
    <div class="stat"><div class="k">접수 팀</div><div class="v"><?= $tot['teams'] ?></div><div class="s">지도교사 <?= $coaches ?>명</div></div>
    <div class="stat"><div class="k">참가 인원</div><div class="v"><?= $tot['people'] ?></div><div class="s">팀원 기준</div></div>
    <div class="stat"><div class="k">정식 · 시범</div><div class="v"><?= $tot['participant'] ?> · <?= $tot['observer'] ?></div><div class="s">팀 수</div></div>
    <div class="stat"><div class="k"><?= h($host) ?> 현장 참가</div><div class="v"><?= $tot['onsite_p'] ?></div><div class="s"><?= $tot['onsite_t'] ?>팀</div></div>
  </div>
  <?php if ($tot['manual']): ?>
    <p class="small" style="margin:10px 0 0">학교를 직접 입력한 팀이 <b><?= $tot['manual'] ?></b>곳 있습니다.
      <a href="?tab=teams&flag=manual">확인하기</a></p>
  <?php endif; ?>

  <div class="sec">
    <h2>지역 · 참가 구분별</h2>
    <p class="desc">취소 · 반려한 팀은 빼고 셉니다.</p>
    <div class="scrollx"><table class="sfpc-grid">
      <thead><tr><th>지역</th>
        <?php foreach (SFPC_CATS as $c): ?><th><?= h($c['label']) ?></th><?php endforeach; ?><th>합계</th></tr></thead>
      <tbody>
      <?php $colSum = []; foreach (SFPC_REGIONS as $r):
        $rowSum = [0, 0]; ?>
        <tr class="<?= $r === $host ? 'host' : '' ?>">
          <td><?= h($r) ?><?= $r === $host ? ' <span class="sfpc-flag">현장</span>' : '' ?></td>
          <?php foreach (SFPC_CATS as $k => $c): $g = $grid[$r][$k] ?? null;
            if ($g) { $rowSum = [$rowSum[0] + $g[0], $rowSum[1] + $g[1]];
                      $colSum[$k] = [($colSum[$k][0] ?? 0) + $g[0], ($colSum[$k][1] ?? 0) + $g[1]]; } ?>
            <td class="<?= $g ? '' : 'z' ?>"><?= $g ? $cell($g) : '·' ?></td>
          <?php endforeach; ?>
          <td class="<?= $rowSum[0] ? '' : 'z' ?>"><?= $rowSum[0] ? $cell($rowSum) : '·' ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="sum"><td>합계</td>
          <?php foreach (SFPC_CATS as $k => $c): ?><td><?= isset($colSum[$k]) ? $cell($colSum[$k]) : '·' ?></td><?php endforeach; ?>
          <td><?= $cell([$tot['teams'], $tot['people']]) ?></td></tr>
      </tbody>
    </table></div>
  </div>

  <div class="sec">
    <h2><?= h($host) ?> 현장 참가 — 상의 사이즈</h2>
    <p class="desc">현장 참가 팀의 팀원 기준입니다. 지도교사는 들어 있지 않습니다.</p>
    <?php if (!$tot['onsite_p']): ?>
      <div class="empty" style="padding:28px 20px">아직 현장 참가 팀이 없습니다.</div>
    <?php else: ?>
      <div class="scrollx" style="display:inline-block"><table class="sfpc-grid" style="min-width:0">
        <thead><tr><th>사이즈</th><?php foreach (SFPC_SHIRTS as $s): ?><th><?= h($s) ?></th><?php endforeach; ?><th>합계</th></tr></thead>
        <tbody><tr><td>인원</td>
          <?php foreach (SFPC_SHIRTS as $s): ?><td class="<?= isset($shirts[$s]) ? '' : 'z' ?>"><?= $shirts[$s] ?? '·' ?></td><?php endforeach; ?>
          <td><b><?= $tot['onsite_p'] ?></b></td></tr></tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'teams'):
  /* ── 팀 목록 ── */
  [$f, $where, $args] = sfpc_admin_filter();
  $teams = sfpc_admin_teams($where, $args);
  $qs = http_build_query(array_filter(['tab' => 'teams'] + $f, fn($v) => $v !== ''));
?>
  <form class="searchbar" method="get">
    <input type="hidden" name="tab" value="teams">
    <select name="status" aria-label="상태">
      <option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>>모든 상태</option>
      <?php foreach (SFPC_STATUS_NAME as $k => $v): ?><option value="<?= h($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
    </select>
    <select name="region" aria-label="지역">
      <option value="">모든 지역</option>
      <?php foreach (SFPC_REGIONS as $r): ?><option<?= $f['region'] === $r ? ' selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?>
    </select>
    <select name="category" aria-label="참가 구분">
      <option value="">모든 참가 구분</option>
      <?php foreach (SFPC_CATS as $k => $c): ?><option value="<?= h($k) ?>"<?= $f['category'] === $k ? ' selected' : '' ?>><?= h($c['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="flag" aria-label="조건">
      <option value="">조건 없음</option>
      <option value="participant"<?= $f['flag'] === 'participant' ? ' selected' : '' ?>>정식참가자</option>
      <option value="observer"<?= $f['flag'] === 'observer' ? ' selected' : '' ?>>시범참가자</option>
      <option value="onsite"<?= $f['flag'] === 'onsite' ? ' selected' : '' ?>>현장 참가</option>
      <option value="manual"<?= $f['flag'] === 'manual' ? ' selected' : '' ?>>학교 직접 입력</option>
    </select>
    <input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="팀명 · 학교 · 이름">
    <button class="btn" type="submit">찾기</button>
    <span style="flex:1"></span>
    <a class="btn" href="?<?= h($qs) ?>&export=teams" title="지금 조건에 맞는 팀을 한 줄에 한 팀씩">팀 명단 내려받기</a>
    <a class="btn" href="?<?= h($qs) ?>&export=members" title="지금 조건에 맞는 팀의 팀원을 한 줄에 한 명씩">팀원 명단 내려받기</a>
  </form>

  <?php if (!$teams): ?>
    <div class="empty">조건에 맞는 팀이 없습니다.</div>
  <?php else: ?>
    <p class="small muted" style="margin:0 0 10px"><?= count($teams) ?>팀 · <?= array_sum(array_map(fn($t) => count($t['members']), $teams)) ?>명</p>
    <div class="scrollx" style="border:0"><table class="list">
      <thead><tr><th>접수번호</th><th>팀명 · 팀원</th><th>지역 · 학교</th><th>구분</th><th>지도교사</th><th>상태</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($teams as $t): ?>
        <tr>
          <td class="num"><?= h(sfpc_team_no($t)) ?><div class="small muted" style="font-family:var(--sans); font-weight:400"><?= h(substr((string)$t['created_at'], 5, 11)) ?></div></td>
          <td><b><?= h($t['team_name']) ?></b>
            <div class="sfpc-mem"><?= h(implode(', ', array_map(fn($m) => $m['name'] . ($m['grade'] ? ' ' . $m['grade'] : '') . ($m['shirt'] !== '' ? ' ' . $m['shirt'] : ''), $t['members']))) ?></div></td>
          <td><?= h($t['region']) ?> · <?= h($t['school_name']) ?>
            <?php if ((int)$t['school_manual'] && $t['category'] !== 'preteacher'): ?><span class="sfpc-flag" title="학교 목록에서 고르지 않고 직접 입력했습니다">직접 입력</span><?php endif; ?>
            <?php if ((int)$t['onsite']): ?><span class="sfpc-flag" style="background:var(--b100); color:var(--b700)">현장</span><?php endif; ?></td>
          <td class="small"><?= h(SFPC_CATS[$t['category']]['label'] ?? '') ?><div class="muted"><?= h(SFPC_TRACK_NAME[$t['track']] ?? '') ?></div></td>
          <td class="small"><?= h((string)$t['coach_name']) ?> <span class="muted">(<?= h((string)$t['coach_login']) ?>)</span><div class="muted"><?= h((string)$t['coach_phone']) ?></div></td>
          <td><?= sfpc_status_badge($t) ?><?php if ($t['admin_memo'] !== ''): ?><div class="small muted" style="max-width:160px"><?= h($t['admin_memo']) ?></div><?php endif; ?></td>
          <td class="right">
            <details class="panel" style="margin:0; text-align:left; min-width:92px">
              <summary style="padding:6px 11px; font-size:13px">관리</summary>
              <form class="panel-b" method="post" style="min-width:230px">
                <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="team"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <div class="field"><label>참가 자격</label><select name="track">
                  <?php foreach (SFPC_TRACK_NAME as $k => $v): ?><option value="<?= h($k) ?>"<?= $t['track'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>상태</label><select name="status">
                  <?php foreach (SFPC_STATUS_NAME as $k => $v): ?><option value="<?= h($k) ?>"<?= $t['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>메모 <span class="hint">반려하면 지도교사에게 보입니다</span></label>
                  <input type="text" name="memo" maxlength="200" value="<?= h($t['admin_memo']) ?>"></div>
                <div class="btnrow"><button class="btn sm primary" type="submit">저장</button>
                  <a class="btn sm" href="../sfpc_team.php?id=<?= (int)$t['id'] ?>">신청 내용 고치기</a></div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'schools'):
  /* ── 학교 목록 ── */
  $cnt = all("SELECT region, level, COUNT(*) AS n FROM sfpc_schools GROUP BY region, level");
  $by = []; $total = 0;
  foreach ($cnt as $r) { $by[$r['region']][$r['level']] = (int)$r['n']; $total += (int)$r['n']; }
  $at = sfpc_setting('sfpc_schools_at');
  $hasKey = sfpc_setting('sfpc_neis_key') !== '';
?>
  <p style="margin:0 0 18px">신청 양식의 학교 찾기에 쓰는 목록입니다. 지금 <b><?= number_format($total) ?></b>곳이 들어 있습니다<?= $at !== '' ? ' (' . h(fmt_dt($at)) . ' 갱신)' : '' ?>.
    <?php if (!$total): ?><br><span class="bad-text">목록이 비어 있으면 신청자가 학교 이름을 모두 직접 입력하게 됩니다.</span><?php endif; ?></p>

  <div class="sec" style="margin-top:0">
    <h2>기본 목록</h2>
    <p class="desc">프로그램에 함께 들어 있는 전국 중학교 · 고등학교 목록입니다. 설치하면 자동으로 들어가고, 목록이 꼬였을 때 여기서 다시 넣을 수 있습니다.
      접수된 팀은 그대로입니다. 고등학교 유형(일반고 · 특성화고 등)은 들어 있지 않습니다.<br>
      출처: <?= h(SFPC_SCHOOLS_SOURCE) ?></p>
    <form method="post">
      <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="bundled">
      <button class="btn" type="submit">기본 목록 다시 넣기</button>
    </form>
  </div>

  <div class="sec">
    <h2>나이스에서 받아오기</h2>
    <p class="desc">나이스 교육정보 개방 포털(open.neis.go.kr)의 학교기본정보에서 전국 중학교 · 고등학교를 받아옵니다.
      인증키는 그 포털에 가입한 뒤 "인증키 신청"에서 받습니다. 다시 받으면 바뀐 학교 정보만 고쳐지고, 접수된 팀은 그대로입니다.</p>
    <form method="post" class="searchbar">
      <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="neis">
      <input type="text" name="neis_key" placeholder="<?= $hasKey ? '저장된 인증키를 씁니다 (바꾸려면 입력)' : '나이스 인증키' ?>" style="max-width:360px" autocomplete="off">
      <button class="btn primary" type="submit">받아오기</button>
    </form>
  </div>

  <div class="sec">
    <h2>CSV 파일로 올리기</h2>
    <p class="desc">서버에서 나이스에 연결되지 않을 때 씁니다. 첫 줄에 열 이름이 있어야 합니다 —
      <b>학교명</b>, <b>소재지명</b>(또는 시도 · 시도교육청명), <b>학교종류명</b>(또는 학교급구분: 중학교 · 고등학교), 그리고 있으면 고등학교구분명 · 도로명주소.
      공공데이터포털의 전국초중등학교위치표준데이터 CSV 를 그대로 올려도 됩니다.</p>
    <form method="post" enctype="multipart/form-data" class="searchbar">
      <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="csv">
      <input type="file" name="file" accept=".csv,text/csv" required>
      <button class="btn" type="submit">올리기</button>
    </form>
  </div>

  <?php if ($total): ?>
  <div class="sec">
    <h2>지역별 학교 수</h2>
    <div class="scrollx" style="display:inline-block; margin-top:10px"><table class="sfpc-grid" style="min-width:0">
      <thead><tr><th>지역</th><th>중학교</th><th>고등학교</th></tr></thead>
      <tbody><?php foreach (SFPC_REGIONS as $r): ?>
        <tr><td><?= h($r) ?></td><td><?= number_format($by[$r]['middle'] ?? 0) ?></td><td><?= number_format($by[$r]['high'] ?? 0) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php else:
  /* ── 설정 ── */
  $dt = fn(string $k) => str_replace(' ', 'T', substr(sfpc_setting($k), 0, 16));
?>
  <form method="post" class="sfpc-form">
    <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="settings">

    <fieldset>
      <legend>대회</legend>
      <div class="row">
        <div class="field"><label for="st">대회 이름</label>
          <input type="text" id="st" name="title" maxlength="40" value="<?= h(sfpc_setting('sfpc_title')) ?>"></div>
        <div class="field" style="max-width:200px"><label for="sh">개최 지역 <span class="hint" style="display:inline">현장 참가</span></label>
          <select id="sh" name="host_region"><?php foreach (SFPC_REGIONS as $r): ?>
            <option<?= sfpc_setting('sfpc_host_region') === $r ? ' selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="row">
        <div class="field"><label for="sf">접수 시작</label>
          <input type="datetime-local" id="sf" name="apply_from" value="<?= h($dt('sfpc_apply_from')) ?>"></div>
        <div class="field"><label for="se">접수 마감</label>
          <input type="datetime-local" id="se" name="apply_to" value="<?= h($dt('sfpc_apply_to')) ?>"></div>
      </div>
      <div class="field"><label for="sc">문의 이메일</label>
        <input type="text" id="sc" name="contact" maxlength="100" value="<?= h(sfpc_setting('sfpc_contact')) ?>" style="max-width:340px"></div>
    </fieldset>

    <fieldset>
      <legend>화면 공개</legend>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="menu_on" value="1"<?= sfpc_setting('sfpc_menu_on') === '1' ? ' checked' : '' ?>>
        위쪽 메뉴와 첫 화면에 SFPC 보이기</label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="landing_on" value="1"<?= sfpc_setting('sfpc_landing_on') === '1' ? ' checked' : '' ?>>
        접수 전 · 접수 중에는 사이트 첫 화면을 SFPC 안내로 바꾸기 <a href="../sfpc_landing.php" target="_blank" rel="noopener" style="margin-left:6px">미리 보기</a></label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="contest_open" value="1"<?= sfpc_setting('sfpc_contest_open') === '1' ? ' checked' : '' ?>>
        대회 참가 화면 공개</label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="board_open" value="1"<?= sfpc_setting('sfpc_board_open') === '1' ? ' checked' : '' ?>>
        스코어보드 공개</label>
      <p class="small muted" style="margin:0 0 16px">공개하지 않은 화면은 "준비 중" 안내만 보입니다. 운영진 계정으로는 공개 전에도 들어가 볼 수 있습니다.</p>
    </fieldset>

    <fieldset>
      <legend>안내 문구</legend>
      <div class="field"><label for="ss">대회 일정 <span class="hint" style="display:inline">접수 첫 화면과 준비 중 화면에 나옵니다 · 마크다운</span></label>
        <textarea id="ss" name="schedule" rows="5" style="width:100%" placeholder="- 공부하기: 1월 ○일 ~ ○일&#10;- 준비하기: 1월 ○일 10:00&#10;- 도전하기: 1월 ○일 10:00"><?= h(sfpc_setting('sfpc_schedule')) ?></textarea></div>
      <div class="field"><label for="sp">개인정보 수집 · 이용 안내 <span class="hint" style="display:inline">비워 두면 기본 문구가 나옵니다 · 마크다운</span></label>
        <textarea id="sp" name="privacy" rows="7" style="width:100%"><?= h(sfpc_setting('sfpc_privacy')) ?></textarea></div>
    </fieldset>

    <button class="btn primary" type="submit" style="padding:10px 24px">저장</button>
  </form>
<?php endif; ?>
</div>
<?php page_foot('../'); ?>
