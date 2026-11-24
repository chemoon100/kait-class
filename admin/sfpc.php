<?php
/* ═══════════════════════════════════════════════
   admin/sfpc.php — 운영 > SFPC

     ?tab=summary   접수 현황 (지역 · 참가 구분별 집계, 오프라인 참가 인원과 상의 사이즈)
     ?tab=teams     팀 목록 (찾기 · 참가 자격 · 반려 · 메모 · 내려받기 · 신청 내용 고치기 · 팀별 변경 기록)
     ?tab=coaches   지도교사 (정보 고치기 · 비밀번호 다시 정하기 · 계정 만들기 · 대신 팀 넣기)
     ?tab=log       변경 기록 (누가 언제 무엇을 바꿨는지)
     ?tab=schools   학교 목록 (나이스에서 받기 · CSV 올리기)
     ?tab=settings  접수 기간 · 안내 문구 · 화면 공개
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/../guard.php';
require_once __DIR__ . '/../layout.php';
$me = need_admin('../');

const SFPC_TABS = ['summary' => '접수 현황', 'teams' => '팀 목록', 'coaches' => '지도교사', 'study' => '공부하기', 'log' => '변경 기록',
                  'schools' => '학교 목록', 'settings' => '설정'];
const SFPC_COACH_ID_RE = '/^[A-Za-z][A-Za-z0-9_.-]{3,19}$/';
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
  $way = fn($t) => (int)$t['onsite'] ? '오프라인' : '온라인';
  if ($_GET['export'] === 'members') {
    $rows = [];
    foreach ($teams as $t) foreach ($t['members'] as $m) {
      $rows[] = [sfpc_team_no($t), SFPC_STATUS_NAME[$t['status']] ?? '', $t['region'], SFPC_CATS[$t['category']]['label'] ?? '',
                 SFPC_TRACK_NAME[$t['track']] ?? '', $way($t), $t['school_name'], $t['team_name'],
                 $m['seq'], $m['name'], $m['grade'] ?: '', (int)$t['onsite'] ? $m['shirt'] : '', $t['coach_name'], $t['coach_phone']];
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
               $t['coach_login'], sfpc_account_id($t), ($t['account_pw_at'] ?? '') !== '' ? '정함' : '', $t['created_at'], $t['updated_at'], $t['admin_memo']];
  }
  sfpc_csv_out("sfpc-teams-$stamp.csv",
    ['접수번호','상태','지역','참가구분','참가자격','참가방식','학교','학교확인','팀명','인원','팀원','지도교사','연락처','이메일',
     '지도교사아이디','팀계정','팀비밀번호','접수일시','수정일시','메모'], $rows);
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

  /* 안내 문구 미리 보기 — 저장하지 않고, 적은 마크다운이 화면에 어떻게 나오는지만 돌려준다 */
  if ($act === 'preview') {
    jout(['ok' => true, 'html' => md(mb_substr((string)($_POST['text'] ?? ''), 0, 20000))]);
  }

  if ($act === 'team') {
    $t = sfpc_team((int)($_POST['id'] ?? 0));
    if (!$t) redirect_with('', '팀을 찾지 못했습니다.');
    $track  = (string)($_POST['track'] ?? '');
    $status = (string)($_POST['status'] ?? '');
    $memo   = sfpc_clean((string)($_POST['memo'] ?? ''), 200);
    if (!isset(SFPC_TRACK_NAME[$track]) || !isset(SFPC_STATUS_NAME[$status])) redirect_with('', '값을 확인해 주세요.');
    db()->prepare("UPDATE sfpc_teams SET track = ?, status = ?, admin_memo = ?, updated_at = ? WHERE id = ?")
        ->execute([$track, $status, $memo, now(), $t['id']]);
    $chg = [];
    if ($t['track'] !== $track)   $chg[] = '참가 자격: ' . SFPC_TRACK_NAME[$t['track']] . ' → ' . SFPC_TRACK_NAME[$track];
    if ($t['status'] !== $status) $chg[] = '상태: ' . SFPC_STATUS_NAME[$t['status']] . ' → ' . SFPC_STATUS_NAME[$status];
    if ($t['admin_memo'] !== $memo) $chg[] = '메모: ' . ($t['admin_memo'] !== '' ? $t['admin_memo'] : '없음') . ' → ' . ($memo !== '' ? $memo : '없음');
    if ($chg) sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $me, 'admin', implode("\n", $chg));
    sfpc_account_sync((int)$t['id']);   /* 취소 · 반려하면 팀 계정 로그인도 막힌다 */
    redirect_with(sfpc_team_no($t) . ' "' . $t['team_name'] . '" 팀을 고쳤습니다.');
  }

  /* 참가 구분이 바뀐 팀의 계정 이름을 다시 매긴다 */
  if ($act === 'team_reissue') {
    $t = sfpc_team((int)($_POST['id'] ?? 0));
    if (!$t) redirect_with('', '팀을 찾지 못했습니다.');
    $old = sfpc_account_id($t);
    $e = sfpc_account_reissue($t, $me);
    if ($e !== '') redirect_with('', $e);
    redirect_with(sfpc_team_no($t) . ' "' . $t['team_name'] . '" 팀 계정을 다시 발급했습니다: ' . $old . ' → ' . sfpc_account_id(sfpc_team((int)$t['id'])) . ' (비밀번호는 그대로입니다. 지도교사에게 새 이름을 알려 주세요.)');
  }

  /* 팀 계정 비밀번호를 무작위로 새로 만든다 — 지도교사와 연락이 안 될 때 운영진이 대신 */
  if ($act === 'team_pw') {
    $t = sfpc_team((int)($_POST['id'] ?? 0));
    if (!$t) redirect_with('', '팀을 찾지 못했습니다.');
    if ($t['status'] !== 'submitted') redirect_with('', '접수 완료 상태인 팀만 계정을 쓸 수 있습니다.');
    $pw = sfpc_random_pw();
    $e = sfpc_account_set_pw($t, $pw, $me);
    if ($e !== '') redirect_with('', $e);
    redirect_with(sfpc_team_no($t) . ' "' . $t['team_name'] . '" 팀 계정 ' . sfpc_account_id($t) . ' 의 새 비밀번호는  ' . $pw . '  입니다. 지금 적어 두세요 — 다시 볼 수 없습니다.');
  }

  /* 지도교사 정보 고치기 · 비밀번호 다시 정하기 */
  if ($act === 'coach_save') {
    $uid = (int)($_POST['uid'] ?? 0);
    $c = one("SELECT c.*, u.login_id, u.role FROM sfpc_coaches c JOIN users u ON u.id = c.user_id WHERE c.user_id = ?", [$uid]);
    if (!$c) redirect_with('', '지도교사를 찾지 못했습니다.');
    $name  = sfpc_clean((string)($_POST['name'] ?? ''), 20);
    $phone = sfpc_phone((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pw    = (string)($_POST['pw'] ?? '');
    if (ustrlen($name) < 2) redirect_with('', '이름을 입력하세요.');
    if ($phone === '')      redirect_with('', '연락처를 확인해 주세요. 예: 010-1234-5678');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) redirect_with('', '이메일 주소를 확인해 주세요.');
    if ($pw !== '' && strlen($pw) < 8) redirect_with('', '새 비밀번호는 8자 이상이어야 합니다.');
    if ($pw !== '' && $c['role'] === 'admin') redirect_with('', '운영진 계정의 비밀번호는 여기서 바꾸지 않습니다.');
    db()->prepare("UPDATE sfpc_coaches SET name = ?, phone = ?, email = ? WHERE user_id = ?")->execute([$name, $phone, $email, $uid]);
    if ($pw !== '') db()->prepare("UPDATE users SET pw_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
    $chg = [];
    if ($c['name'] !== $name)   $chg[] = '이름: ' . $c['name'] . ' → ' . $name;
    if ($c['phone'] !== $phone) $chg[] = '연락처: ' . $c['phone'] . ' → ' . $phone;
    if ($c['email'] !== $email) $chg[] = '이메일: ' . ($c['email'] ?: '없음') . ' → ' . ($email ?: '없음');
    if ($pw !== '')             $chg[] = '비밀번호를 다시 정함';
    if ($chg) sfpc_log(null, $uid, $me, 'coach', implode("\n", $chg));
    redirect_with($name . ' 선생님의 정보를 고쳤습니다.' . ($pw !== '' ? ' 새 비밀번호를 본인에게 알려 주세요.' : ''));
  }

  /* 지도교사 계정 만들기 — 마감 뒤 누락된 팀을 대신 넣어 줄 때, 계정이 없는 교사를 위해 */
  if ($act === 'coach_new') {
    $id    = trim((string)($_POST['id'] ?? ''));
    $pw    = (string)($_POST['pw'] ?? '');
    $name  = sfpc_clean((string)($_POST['name'] ?? ''), 20);
    $phone = sfpc_phone((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    if (!preg_match(SFPC_COACH_ID_RE, $id)) redirect_with('', '아이디는 영문으로 시작하는 4~20자여야 합니다.');
    if (strlen($pw) < 8)    redirect_with('', '비밀번호는 8자 이상이어야 합니다.');
    if (ustrlen($name) < 2) redirect_with('', '이름을 입력하세요.');
    if ($phone === '')      redirect_with('', '연락처를 확인해 주세요. 예: 010-1234-5678');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) redirect_with('', '이메일 주소를 확인해 주세요.');
    if (one("SELECT 1 FROM users WHERE login_id = ?", [$id])) redirect_with('', '이미 쓰이고 있는 아이디입니다.');
    $uid = (int)tx(function (PDO $d) use ($id, $pw, $name, $phone, $email) {
      $d->prepare("INSERT INTO users(login_id, pw_hash, name, role, group_id, source, approved, created_at)
                   VALUES(?,?,?,'student',NULL,'sfpc',1,?)")->execute([$id, password_hash($pw, PASSWORD_DEFAULT), $name, now()]);
      $uid = (int)$d->lastInsertId();
      $d->prepare("INSERT INTO sfpc_coaches(user_id, name, phone, email, created_at) VALUES(?,?,?,?,?)")
        ->execute([$uid, $name, $phone, $email, now()]);
      return $uid;
    });
    sfpc_log(null, $uid, $me, 'coach', '운영진이 지도교사 계정을 만듦 (아이디 ' . $id . ')');
    $_SESSION['flash'] = ['msg' => $name . ' 선생님의 계정을 만들었습니다. 아이디와 비밀번호를 본인에게 알려 주세요.', 'err' => ''];
    header('Location: sfpc.php?tab=coaches&q=' . rawurlencode($id));
    exit;
  }

  /* 공부하기 — 수료 조건에서 문제 빼기/넣기, 팀을 손으로 수료 처리 · 취소 */
  if ($act === 'study_skip') {
    $pid = (int)($_POST['pid'] ?? 0); $skip = sfpc_study_skip();
    $skip = in_array($pid, $skip, true) ? array_values(array_diff($skip, [$pid])) : array_merge($skip, [$pid]);
    setting_set('sfpc_study_skip', implode(',', $skip));
    redirect_with('수료 조건을 바꿨습니다. 이미 수료한 팀은 그대로 수료입니다.');
  }
  /* 연타 기록 지우기 · 되돌리기 (지운 기록은 순위에서 빠질 뿐 남아 있다) */
  if ($act === 'mash_toggle') {
    $r = one("SELECT * FROM sfpc_mash WHERE id = ?", [(int)($_POST['mid'] ?? 0)]);
    if (!$r) redirect_with('', '기록을 찾지 못했습니다.');
    db()->prepare("UPDATE sfpc_mash SET removed_at = ? WHERE id = ?")->execute([$r['removed_at'] === '' ? now() : '', $r['id']]);
    $t = sfpc_team((int)$r['team_id']);
    if ($t) sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $me, 'study', '연타 기록(보스 ' . $r['boss'] . ' · ' . $r['cnt'] . '회)을 ' . ($r['removed_at'] === '' ? '지움' : '되돌림'));
    redirect_with($r['removed_at'] === '' ? '기록을 지웠습니다. 순위에서 빠집니다. 같은 자리에서 되돌릴 수 있습니다.' : '기록을 되돌렸습니다.');
  }
  if ($act === 'mash_again') {            /* 그 판을 아예 없애 다시 할 수 있게 한다 */
    $r = one("SELECT * FROM sfpc_mash WHERE id = ?", [(int)($_POST['mid'] ?? 0)]);
    if (!$r) redirect_with('', '기록을 찾지 못했습니다.');
    /* 넘어간 표시로 바꾼다 — 길은 열린 채로 두고(이미 다음 월드를 풀고 있을 수 있다) 그 보스만 다시 도전할 수 있게 */
    db()->prepare("UPDATE sfpc_mash SET skip = 1, cnt = 0, removed_at = '' WHERE id = ?")->execute([$r['id']]);
    $t = sfpc_team((int)$r['team_id']);
    if ($t) sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $me, 'study', '연타 기록(보스 ' . $r['boss'] . ' · ' . $r['cnt'] . '회)을 없애 다시 할 수 있게 함');
    redirect_with('그 기록을 없앴습니다. 팀이 지도에서 그 보스에게 다시 도전할 수 있습니다.');
  }
  if ($act === 'study_conf') {
    setting_set('sfpc_boss_every', (string)max(1, min(50, (int)($_POST['boss_every'] ?? 10))));
    setting_set('sfpc_mash_sec', (string)max(5, min(60, (int)($_POST['mash_sec'] ?? 20))));
    redirect_with('저장했습니다.');
  }
  /* 연습용 문제 넣기 — 묶음이 비어 있을 때만. 지도 · 수료 · 연타를 시험해 보는 용도다 */
  if ($act === 'study_demo') {
    if (sfpc_study_problems()) redirect_with('', '이미 문제가 담겨 있습니다.');
    $n = sfpc_demo_problems();
    redirect_with('연습용 문제 ' . $n . '개를 만들어 공부하기에 담았습니다. 교육팀 문제가 준비되면 바꿔 주세요.');
  }
  if ($act === 'study_grant' || $act === 'study_revoke') {
    $t = sfpc_team((int)($_POST['id'] ?? 0));
    if (!$t) redirect_with('', '팀을 찾지 못했습니다.');
    if ($act === 'study_grant') { if ($t['study_done_at'] === '') sfpc_study_grant($t, $me); redirect_with('"' . $t['team_name'] . '" 팀을 수료 처리했습니다.'); }
    db()->prepare("UPDATE sfpc_teams SET study_done_at = '' WHERE id = ?")->execute([$t['id']]);
    sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $me, 'study', '공부하기 수료를 취소 (운영진). 참가 자격은 바꾸지 않았습니다 — 필요하면 팀 관리에서 고칩니다.');
    redirect_with('"' . $t['team_name'] . '" 팀의 수료를 취소했습니다. 참가 자격은 그대로이니 필요하면 팀 목록에서 고쳐 주세요.');
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
    $sd = str_replace('T', ' ', trim((string)($_POST['shirt_due'] ?? '')));
    setting_set('sfpc_shirt_due', ($sd !== '' && sfpc_ts($sd)) ? substr($sd, 0, 16) : '');
    setting_set('sfpc_schedule', trim((string)($_POST['schedule'] ?? '')));
    setting_set('sfpc_privacy', trim((string)($_POST['privacy'] ?? '')));
    setting_set('sfpc_contact', sfpc_clean((string)($_POST['contact'] ?? ''), 100));
    foreach (['site', 'menu_on', 'landing_on', 'account_open', 'study_open', 'ready_open', 'contest_open', 'board_open'] as $k) setting_set('sfpc_' . $k, !empty($_POST[$k]) ? '1' : '0');
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
    <a href="../sfpc_ask.php">문의함<?= sfpc_qna_dot($me) ?></a>
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
    <?php $hostOnline = (int)col("SELECT COUNT(*) FROM sfpc_teams WHERE contest = ? AND status = 'submitted' AND kind = 'student' AND region = ? AND onsite = 0", [SFPC_CONTEST, $host]); ?>
    <div class="stat"><div class="k"><?= h($host) ?> 오프라인 참가</div><div class="v"><?= $tot['onsite_p'] ?></div><div class="s"><?= $tot['onsite_t'] ?>팀 · <?= h($host) ?> 학생 팀 중 온라인 <?= $hostOnline ?>팀</div></div>
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
          <td><?= h($r) ?><?= $r === $host ? ' <span class="sfpc-flag">개최지</span>' : '' ?></td>
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
    <h2><?= h($host) ?> 오프라인 참가 — 상의 사이즈</h2>
    <p class="desc">오프라인(대회장) 참가를 고른 <?= h($host) ?> 학생 팀의 팀원 기준입니다. 지도교사와 온라인 팀은 들어 있지 않습니다.
      <?php $sd = sfpc_setting('sfpc_shirt_due'); ?><?= $sd !== '' ? '티셔츠 조사 마감: <b>' . h($sd) . '</b>' . (sfpc_shirt_closed() ? ' (마감됨 — 이 뒤에 오프라인으로 바꾼 팀은 변경 기록에 표시됩니다)' : '') : '티셔츠 조사 마감일은 설정 탭에서 정합니다.' ?></p>
    <?php if (!$tot['onsite_p']): ?>
      <div class="empty" style="padding:28px 20px">아직 오프라인 참가 팀이 없습니다.</div>
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
  $logs = [];
  foreach (all("SELECT * FROM sfpc_log WHERE contest = ? AND team_id IS NOT NULL ORDER BY id DESC", [SFPC_CONTEST]) as $l) {
    $logs[(int)$l['team_id']][] = $l;
  }
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
      <option value="onsite"<?= $f['flag'] === 'onsite' ? ' selected' : '' ?>>오프라인 참가</option>
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
            <div class="sfpc-mem"><?= h(implode(', ', array_map(fn($m) => $m['name'] . ($m['grade'] ? ' ' . $m['grade'] : '') . ((int)$t['onsite'] && $m['shirt'] !== '' ? ' ' . $m['shirt'] : ''), $t['members']))) ?></div></td>
          <td><?= h($t['region']) ?> · <?= h($t['school_name']) ?>
            <?php if ((int)$t['school_manual'] && $t['category'] !== 'preteacher'): ?><span class="sfpc-flag" title="학교 목록에서 고르지 않고 직접 입력했습니다">직접 입력</span><?php endif; ?>
            <?php if ((int)$t['onsite']): ?><span class="sfpc-flag" style="background:var(--b100); color:var(--b700)">오프라인</span><?php endif; ?></td>
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
                  <a class="btn sm" href="../sfpc_team.php?id=<?= (int)$t['id'] ?>">신청 내용 고치기</a>
                  <a class="btn sm" href="?tab=coaches&q=<?= rawurlencode((string)$t['coach_login']) ?>">지도교사</a></div>
                <div class="sfpc-acc" style="margin-top:12px">
                  <div class="sfpc-acc-h"><span class="lab">팀 계정</span> <code class="id"><?= h(sfpc_account_id($t)) ?></code>
                    <?php if (($t['account_pw_at'] ?? '') !== ''): ?><span class="st ok">비밀번호 정함 · <?= h(substr((string)$t['account_pw_at'], 5, 11)) ?></span>
                    <?php else: ?><span class="st no">비밀번호 없음</span><?php endif; ?></div>
                  <a class="btn sm" href="../sfpc_me.php?team=<?= (int)$t['id'] ?>" target="_blank" rel="noopener" style="margin-top:8px">팀 화면 보기</a>
                  <?php if (sfpc_account_stale($t)): ?>
                    <p class="small" style="margin:8px 0 0; color:#B45309">참가 구분이 바뀌어 계정 이름이 맞지 않습니다. 지금 구분으로는 <code><?= h(sfpc_account_want($t)) ?></code> 입니다.</p>
                    <button class="btn sm" name="act" value="team_reissue" style="margin-top:6px"
                            onclick="return confirm('계정 이름을 <?= h(sfpc_account_want($t)) ?> 로 바꿀까요?\n비밀번호 · 캐릭터 · 기록은 그대로이고, 이전 이름으로는 로그인할 수 없게 됩니다.')">계정 다시 발급</button>
                  <?php endif; ?>
                  <?php if ($t['status'] === 'submitted'): ?>
                    <button class="btn sm" name="act" value="team_pw" style="margin-top:8px"
                            onclick="return confirm('이 팀 계정의 비밀번호를 무작위로 새로 만들까요?\n지금 쓰는 비밀번호는 더 쓸 수 없게 됩니다.')">비밀번호 새로 만들기</button>
                  <?php endif; ?>
                </div>
                <?php $tl = $logs[(int)$t['id']] ?? []; if ($tl): ?>
                  <div class="sfpc-hist">
                    <b>변경 기록 <?= count($tl) ?>건</b>
                    <?php foreach ($tl as $l): ?>
                      <div class="h"><span class="when"><?= h(substr((string)$l['created_at'], 5, 11)) ?></span>
                        <span class="who<?= (int)$l['by_admin'] ? ' adm' : '' ?>"><?= h($l['actor_name']) ?><?= (int)$l['by_admin'] ? ' (운영진)' : '' ?></span>
                        <span class="act"><?= h(SFPC_LOG_NAME[$l['action']] ?? $l['action']) ?></span>
                        <div class="d"><?= nl2br(h($l['detail'])) ?></div></div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'coaches'):
  /* ── 지도교사 ── */
  $q = sfpc_clean((string)($_GET['q'] ?? ''), 30);
  $where = '1'; $args = [SFPC_CONTEST];
  if ($q !== '') {
    $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    $where = "(c.name LIKE ? ESCAPE '\\' OR u.login_id LIKE ? ESCAPE '\\' OR c.phone LIKE ? ESCAPE '\\')";
    array_push($args, $like, $like, $like);
  }
  $coaches = all("SELECT c.*, u.login_id, u.role,
                    (SELECT COUNT(*) FROM sfpc_teams t WHERE t.coach_user_id = c.user_id AND t.contest = ? AND t.status = 'submitted') AS team_cnt
                  FROM sfpc_coaches c JOIN users u ON u.id = c.user_id
                  WHERE $where ORDER BY c.name, c.user_id", $args);
?>
  <form class="searchbar" method="get">
    <input type="hidden" name="tab" value="coaches">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="이름 · 아이디 · 연락처">
    <button class="btn" type="submit">찾기</button>
    <?php if ($q !== ''): ?><a class="btn" href="?tab=coaches">전체 보기</a><?php endif; ?>
  </form>

  <?php if (!$coaches): ?>
    <div class="empty"><?= $q !== '' ? '찾는 지도교사가 없습니다.' : '아직 가입한 지도교사가 없습니다.' ?></div>
  <?php else: ?>
    <p class="small muted" style="margin:0 0 10px"><?= count($coaches) ?>명</p>
    <div class="scrollx" style="border:0"><table class="list">
      <thead><tr><th>이름</th><th>아이디</th><th>연락처</th><th>이메일</th><th>팀</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($coaches as $c): ?>
        <tr>
          <td><b><?= h($c['name']) ?></b><?= $c['role'] === 'admin' ? ' <span class="sfpc-flag">운영진</span>' : '' ?></td>
          <td class="num"><?= h($c['login_id']) ?></td>
          <td><?= h($c['phone']) ?></td>
          <td class="small"><?= h($c['email']) ?></td>
          <td><?php if ((int)$c['team_cnt']): ?><a href="?tab=teams&q=<?= rawurlencode($c['name']) ?>"><?= (int)$c['team_cnt'] ?>팀</a><?php else: ?><span class="muted">없음</span><?php endif; ?></td>
          <td class="right">
            <details class="panel" style="margin:0; text-align:left; min-width:92px">
              <summary style="padding:6px 11px; font-size:13px">관리</summary>
              <div class="panel-b" style="min-width:250px">
                <form method="post">
                  <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="coach_save"><input type="hidden" name="uid" value="<?= (int)$c['user_id'] ?>">
                  <div class="field"><label>이름</label><input type="text" name="name" maxlength="20" value="<?= h($c['name']) ?>"></div>
                  <div class="field"><label>연락처</label><input type="tel" name="phone" value="<?= h($c['phone']) ?>"></div>
                  <div class="field"><label>이메일</label><input type="email" name="email" maxlength="100" value="<?= h($c['email']) ?>"></div>
                  <?php if ($c['role'] !== 'admin'): ?>
                    <div class="field"><label>새 비밀번호 <span class="hint">비밀번호를 잊었을 때만 · 8자 이상</span></label>
                      <input type="text" name="pw" autocomplete="off" placeholder="바꾸지 않으려면 비워 둡니다"></div>
                  <?php endif; ?>
                  <button class="btn sm primary" type="submit">저장</button>
                </form>
                <p class="small muted" style="margin:14px 0 6px">이 선생님에게 보이는 화면</p>
                <div class="btnrow"><a class="btn sm" href="../sfpc_teams.php?coach=<?= (int)$c['user_id'] ?>">내 팀 화면 보기</a></div>
                <p class="small muted" style="margin:14px 0 6px">이 선생님 이름으로 팀 넣기 — 접수 기간이 아니어도 됩니다</p>
                <div class="btnrow">
                  <a class="btn sm" href="../sfpc_team.php?kind=student&coach=<?= (int)$c['user_id'] ?>">학생 팀</a>
                  <a class="btn sm" href="../sfpc_team.php?kind=teacher&coach=<?= (int)$c['user_id'] ?>">교사 · 예비교사 팀</a>
                </div>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <div class="sec">
    <h2>지도교사 계정 만들기</h2>
    <p class="desc">스스로 가입하지 못한 선생님을 대신해 계정을 만듭니다. 만든 뒤 위 목록의 "관리"에서 그 선생님 이름으로 팀을 넣을 수 있습니다.
      아이디와 비밀번호는 본인에게 따로 알려 주세요.</p>
    <form method="post" class="sfpc-form" style="max-width:560px">
      <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="coach_new">
      <div class="row">
        <div class="field"><label for="cn-id">아이디</label><input type="text" id="cn-id" name="id" autocomplete="off" autocapitalize="off"><span class="hint">영문으로 시작하는 4~20자</span></div>
        <div class="field"><label for="cn-pw">비밀번호</label><input type="text" id="cn-pw" name="pw" autocomplete="off"><span class="hint">8자 이상</span></div>
      </div>
      <div class="row">
        <div class="field"><label for="cn-name">이름</label><input type="text" id="cn-name" name="name" maxlength="20"></div>
        <div class="field"><label for="cn-phone">연락처</label><input type="tel" id="cn-phone" name="phone" placeholder="010-1234-5678"></div>
      </div>
      <div class="field"><label for="cn-email">이메일 <span class="hint" style="display:inline">선택</span></label><input type="email" id="cn-email" name="email" maxlength="100"></div>
      <button class="btn primary" type="submit">계정 만들기</button>
    </form>
  </div>

<?php elseif ($tab === 'study'):
  $sset = sfpc_study_set(); $probs = sfpc_study_problems(); $skip = sfpc_study_skip();
  $needIds = array_diff(array_map('intval', array_column($probs, 'id')), $skip);
  $steams = all("SELECT t.* FROM sfpc_teams t WHERE t.contest = ? AND t.status = 'submitted' ORDER BY t.id", [SFPC_CONTEST]);
  $solvedBy = [];
  foreach (all("SELECT s.user_id, COUNT(DISTINCT s.problem_id) n FROM submissions s JOIN set_problems sp ON sp.problem_id = s.problem_id AND sp.set_id = ?
                WHERE s.verdict = 'AC' GROUP BY s.user_id", [$sset['id']]) as $r) $solvedBy[(int)$r['user_id']] = (int)$r['n'];
  $acBy = [];
  foreach (all("SELECT s.problem_id, COUNT(DISTINCT s.user_id) n FROM submissions s JOIN users u ON u.id = s.user_id AND u.source = ?
                WHERE s.verdict = 'AC' GROUP BY s.problem_id", [SFPC_ACCOUNT_SOURCE]) as $r) $acBy[(int)$r['problem_id']] = (int)$r['n'];
  $doneN = count(array_filter($steams, fn($t) => $t['study_done_at'] !== ''));
?>
  <div class="note info">공부하기 문제는 기존 "수업" 묶음 <b><?= h($sset['title']) ?></b> 에 담습니다.
    <a class="btn sm" href="set.php?id=<?= (int)$sset['id'] ?>" style="margin-left:8px">묶음에 문제 넣기 · 순서 바꾸기</a>
    <a class="btn sm" href="problems.php">문제 만들기</a>
    <a class="btn sm" href="../sfpc_study.php" target="_blank" rel="noopener">공부하기 화면 보기</a>
    <br><span class="small">팀 계정이 이 묶음의 문제를 <b>모두</b> 맞히면 자동으로 수료되고, 수료한 중학교 팀은 정식참가자가 됩니다. 공개 여부는 설정 탭의 "공부하기 공개"입니다 (지금 <?= sfpc_setting('sfpc_study_open') === '1' ? '공개' : '준비 중' ?>).</span></div>

  <form method="post" class="sfpc-rowform" style="margin-top:14px; gap:10px; flex-wrap:wrap">
    <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="study_conf">
    <label class="small">문제 <input type="number" name="boss_every" min="1" max="50" value="<?= sfpc_boss_every() ?>" style="width:70px"> 개마다 보스</label>
    <label class="small">연타 한 판 <input type="number" name="mash_sec" min="5" max="60" value="<?= sfpc_mash_sec() ?>" style="width:70px"> 초</label>
    <button class="btn sm">저장</button>
    <span class="small muted">지금 보스 <?= sfpc_boss_count() ?>마리 · 보스는 한 판, <?= SFPC_MASH_MIN ?>번 이하로 끝나면 무효(다시 도전) · 보스를 지나야 다음 월드가 열립니다</span>
  </form>

  <h2 style="font-size:17px; margin:22px 0 10px">문제 <?= count($probs) ?>개 <span class="small muted" style="font-weight:400">· 수료에 필요한 문제 <?= count($needIds) ?>개</span></h2>
  <?php if (!$probs): ?>
    <div class="empty">아직 묶음에 담긴 문제가 없습니다. 문제가 하나도 없으면 아무 팀도 수료되지 않습니다.
      <form method="post" style="margin-top:12px"><?= sfpc_csrf_field() ?><input type="hidden" name="act" value="study_demo">
        <button class="btn" onclick="return confirm('연습용 기초 문제 10개를 만들어 공부하기에 담을까요?')">연습용 문제 10개 넣기</button></form></div>
  <?php else: ?>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>순서</th><th>문제</th><th class="num">맞힌 팀</th><th>수료 조건</th></tr></thead>
    <tbody>
    <?php foreach ($probs as $i => $p): $off = in_array((int)$p['id'], $skip, true); ?>
      <tr>
        <td class="num"><?= $i + 1 ?></td>
        <td><a href="../problem.php?no=<?= (int)$p['prob_no'] ?>&amp;set=<?= (int)$sset['id'] ?>"><?= h($p['title']) ?></a> <span class="small muted">#<?= (int)$p['prob_no'] ?></span></td>
        <td class="num"><?= (int)($acBy[(int)$p['id']] ?? 0) ?></td>
        <td><form method="post" style="display:inline"><?= sfpc_csrf_field() ?><input type="hidden" name="act" value="study_skip"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
          <?= $off ? '<span class="sfpc-flag">조건에서 뺌</span>' : '필요' ?>
          <button class="btn sm" style="margin-left:8px"><?= $off ? '다시 넣기' : '조건에서 빼기' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <h2 style="font-size:17px; margin:26px 0 10px">팀별 진행 <span class="small muted" style="font-weight:400">· 수료 <?= $doneN ?> / <?= count($steams) ?>팀</span></h2>
  <?php if (!$steams): ?><div class="empty">접수한 팀이 없습니다.</div><?php else: ?>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>접수번호</th><th>팀</th><th>구분</th><th class="num">맞힌 문제</th><th>수료</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($steams as $t): $n = (int)($solvedBy[(int)$t['account_user_id']] ?? 0); $done = $t['study_done_at'] !== ''; ?>
      <tr>
        <td><span class="sfpc-no"><?= h(sfpc_team_no($t)) ?></span></td>
        <td><b><?= h($t['team_name']) ?></b> <span class="small muted"><?= h($t['school_name']) ?></span></td>
        <td><?= h(SFPC_CATS[$t['category']]['label'] ?? '') ?> · <?= h(SFPC_TRACK_NAME[$t['track']] ?? '') ?></td>
        <td class="num"><?= (int)$t['account_user_id'] ? $n . ' / ' . count($probs) : '<span class="muted small">계정 없음</span>' ?></td>
        <td><?= $done ? '<span class="badge ok">수료</span> <span class="small muted">' . h(substr($t['study_done_at'], 5, 11)) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td><form method="post" style="display:inline"><?= sfpc_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <?php if ($done): ?><button class="btn sm" name="act" value="study_revoke" onclick="return confirm('이 팀의 수료를 취소할까요?')">수료 취소</button>
          <?php else: ?><button class="btn sm" name="act" value="study_grant" onclick="return confirm('이 팀을 수료 처리할까요?<?= $t['category'] === 'middle' ? '\\n중학교 팀이라 정식참가자로 바뀝니다.' : '' ?>')">수료 처리</button><?php endif; ?></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php $mrows = all("SELECT m.*, t.team_name FROM sfpc_mash m JOIN sfpc_teams t ON t.id = m.team_id WHERE m.contest = ? AND m.round = 1 ORDER BY m.started_at DESC, m.id DESC LIMIT 300", [SFPC_CONTEST]); ?>
  <h2 style="font-size:17px; margin:26px 0 10px">연타 기록 <span class="small muted" style="font-weight:400">· 최근 <?= count($mrows) ?>건 · <a href="../sfpc_rank.php" target="_blank" rel="noopener">순위 보기</a></span></h2>
  <?php if (!$mrows): ?><div class="empty">아직 기록이 없습니다.</div><?php else: ?>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>때</th><th>팀</th><th>보스</th><th class="num">횟수</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($mrows as $m): $gone = $m['removed_at'] !== ''; ?>
      <tr<?= $gone ? ' style="opacity:.55"' : '' ?>>
        <td class="small"><?= h(substr($m['started_at'], 5, 11)) ?></td>
        <td><b><?= h($m['team_name']) ?></b></td>
        <td>보스 <?= (int)$m['boss'] ?></td>
        <td class="num"><?= (int)$m['skip'] ? '<span class="muted">넘어감 (0점)</span>' : ($m['ended_at'] === '' ? '<span class="muted">아직 (시작만 함)</span>' : (int)$m['cnt']) ?><?= $gone ? ' <span class="sfpc-flag">지움</span>' : '' ?></td>
        <td><form method="post" style="display:inline"><?= sfpc_csrf_field() ?><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
          <?php if (!(int)$m['skip'] && $m['ended_at'] !== ''): ?>
          <button class="btn sm" name="act" value="mash_toggle"><?= $gone ? '되돌리기' : '기록 지우기' ?></button>
          <button class="btn sm" name="act" value="mash_again" onclick="return confirm('이 기록을 없애고 팀이 이 보스에게 다시 도전하게 할까요?\n되돌릴 수 없습니다.')">다시 하게 하기</button>
          <?php endif; ?></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'log'):
  /* ── 변경 기록 ── */
  $only = (string)($_GET['by'] ?? '');
  $w = "l.contest = ?"; $a = [SFPC_CONTEST];
  if ($only === 'admin') $w .= " AND l.by_admin = 1";
  $rows = all("SELECT l.*, t.team_name, t.school_name, t.id AS tid, c.name AS coach_name
               FROM sfpc_log l
               LEFT JOIN sfpc_teams t ON t.id = l.team_id
               LEFT JOIN sfpc_coaches c ON c.user_id = l.coach_user_id
               WHERE $w ORDER BY l.id DESC LIMIT 300", $a);
?>
  <div class="sfpc-subtabs" style="margin-bottom:14px">
    <a class="<?= $only === '' ? 'on' : '' ?>" href="?tab=log">전체</a>
    <a class="<?= $only === 'admin' ? 'on' : '' ?>" href="?tab=log&by=admin">운영진이 바꾼 것만</a>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">아직 기록이 없습니다.</div>
  <?php else: ?>
    <p class="small muted" style="margin:0 0 10px">최근 <?= count($rows) ?>건 · 신청, 수정, 취소, 운영진 조정이 모두 남습니다. 기록은 지워지지 않습니다.</p>
    <div class="scrollx" style="border:0"><table class="list">
      <thead><tr><th>언제</th><th>누가</th><th>무엇을</th><th>대상</th><th>바뀐 내용</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td class="num" style="white-space:nowrap"><?= h(substr((string)$l['created_at'], 0, 16)) ?></td>
          <td style="white-space:nowrap"><?= h($l['actor_name']) ?><?= (int)$l['by_admin'] ? ' <span class="sfpc-flag">운영진</span>' : '' ?></td>
          <td style="white-space:nowrap"><?= h(SFPC_LOG_NAME[$l['action']] ?? $l['action']) ?></td>
          <td class="small"><?php if ($l['tid']): ?><a href="?tab=teams&status=all&q=<?= rawurlencode((string)$l['team_name']) ?>"><?= h((string)$l['team_name']) ?></a>
              <div class="muted"><?= h((string)$l['school_name']) ?></div>
            <?php else: ?>지도교사 <?= h((string)$l['coach_name']) ?><?php endif; ?></td>
          <td class="small"><?= nl2br(h($l['detail'])) ?></td>
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
        <div class="field" style="max-width:200px"><label for="sh">개최 지역 <span class="hint" style="display:inline">이 지역 학생 팀만 오프라인 선택</span></label>
          <select id="sh" name="host_region"><?php foreach (SFPC_REGIONS as $r): ?>
            <option<?= sfpc_setting('sfpc_host_region') === $r ? ' selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="row">
        <div class="field"><label for="sf">접수 시작</label>
          <input type="datetime-local" id="sf" name="apply_from" value="<?= h($dt('sfpc_apply_from')) ?>"></div>
        <div class="field"><label for="se">접수 마감</label>
          <input type="datetime-local" id="se" name="apply_to" value="<?= h($dt('sfpc_apply_to')) ?>"></div>
      </div>
      <div class="field" style="max-width:340px"><label for="sd">티셔츠 조사 마감 <span class="hint" style="display:inline">비워 두면 마감 없음</span></label>
        <input type="datetime-local" id="sd" name="shirt_due" value="<?= h($dt('sfpc_shirt_due')) ?>">
        <span class="hint">이 뒤에 오프라인으로 접수하거나 바꾸는 팀에게 "티셔츠를 받지 못할 수 있다"고 안내합니다. 변경 자체는 막지 않습니다.</span></div>
      <div class="field"><label for="sc">문의 이메일</label>
        <input type="text" id="sc" name="contact" maxlength="100" value="<?= h(sfpc_setting('sfpc_contact')) ?>" style="max-width:340px"></div>
    </fieldset>

    <fieldset>
      <legend>화면 공개</legend>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="site" value="1"<?= sfpc_setting('sfpc_site') === '1' ? ' checked' : '' ?>>
        SFPC 전용 사이트로 쓰기 <span class="muted">— 위쪽 메뉴가 접수 · 공부하기 · 준비하기 · 도전하기 · 스코어보드가 되고, 문제 모음 · 채점 결과 · 수업 · 평가는 운영진에게만 보입니다</span></label>
      <input type="hidden" name="menu_on" value="1">
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="landing_on" value="1"<?= sfpc_setting('sfpc_landing_on') === '1' ? ' checked' : '' ?>>
        사이트 첫 화면을 SFPC 안내 화면으로 <a href="../sfpc_landing.php" target="_blank" rel="noopener" style="margin-left:6px">미리 보기</a></label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="account_open" value="1"<?= sfpc_setting('sfpc_account_open') === '1' ? ' checked' : '' ?>>
        팀 계정 나눠 주기 <span class="muted">— 켜면 지도교사의 "내 팀" 화면에 팀 아이디가 보이고, 비밀번호를 정할 수 있습니다</span></label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="study_open" value="1"<?= sfpc_setting('sfpc_study_open') === '1' ? ' checked' : '' ?>>
        공부하기 공개</label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="ready_open" value="1"<?= sfpc_setting('sfpc_ready_open') === '1' ? ' checked' : '' ?>>
        준비하기 공개</label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="contest_open" value="1"<?= sfpc_setting('sfpc_contest_open') === '1' ? ' checked' : '' ?>>
        도전하기(본 대회) 공개</label>
      <label class="chk" style="margin-bottom:10px"><input type="checkbox" name="board_open" value="1"<?= sfpc_setting('sfpc_board_open') === '1' ? ' checked' : '' ?>>
        스코어보드 공개</label>
      <p class="small muted" style="margin:0 0 16px">공개하지 않은 화면은 "준비 중" 안내만 보입니다. 운영진 계정으로는 공개 전에도 들어가 볼 수 있습니다.</p>
    </fieldset>

    <fieldset>
      <legend>방문자 화면 미리 보기</legend>
      <p class="small muted" style="margin:0 0 12px">접수 시기마다 방문자에게 보이는 모습입니다. 설정을 바꾸지 않고 운영진만 볼 수 있습니다. 새 창으로 열립니다.</p>
      <table class="sfpc-pvlinks">
        <tr><th>첫 화면</th><td>
          <a href="../sfpc_landing.php?as=before" target="_blank" rel="noopener">접수 전</a>
          <a href="../sfpc_landing.php?as=open" target="_blank" rel="noopener">접수 중</a>
          <a href="../sfpc_landing.php?as=closed" target="_blank" rel="noopener">마감 후</a></td></tr>
        <tr><th>접수 안내</th><td>
          <a href="../sfpc.php?as=before" target="_blank" rel="noopener">접수 전</a>
          <a href="../sfpc.php?as=open" target="_blank" rel="noopener">접수 중</a>
          <a href="../sfpc.php?as=closed" target="_blank" rel="noopener">마감 후</a></td></tr>
        <tr><th>준비 중 안내</th><td>
          <a href="../sfpc_study.php?as=guest" target="_blank" rel="noopener">공부하기</a>
          <a href="../sfpc_ready.php?as=guest" target="_blank" rel="noopener">준비하기</a>
          <a href="../sfpc_contest.php?as=guest" target="_blank" rel="noopener">도전하기</a>
          <a href="../sfpc_board.php?as=guest" target="_blank" rel="noopener">스코어보드</a>
          <span class="muted">공개 전에 방문자가 보는 화면</span></td></tr>
      </table>
      <p class="small muted" style="margin:12px 0 16px">지도교사 한 사람의 화면은 <a href="?tab=coaches">지도교사</a> 탭의 "관리 → 내 팀 화면 보기"에서 봅니다.</p>
    </fieldset>

    <fieldset>
      <legend>안내 문구</legend>
      <div class="field"><label for="ss">대회 일정 <span class="hint" style="display:inline">접수 안내 화면과 준비 중 화면에 나옵니다 · 마크다운</span></label>
        <textarea id="ss" name="schedule" rows="5" style="width:100%" placeholder="- 공부하기: 1월 ○일 ~ ○일&#10;- 준비하기: 1월 ○일 10:00&#10;- 도전하기: 1월 ○일 10:00"><?= h(sfpc_setting('sfpc_schedule')) ?></textarea>
        <div class="btnrow" style="margin-top:8px"><button class="btn sm" type="button" data-preview="ss" data-empty="공부하기 · 준비하기 · 도전하기 일정은 곧 알려 드립니다.">미리 보기</button>
          <span class="small muted">저장하기 전에 어떻게 나오는지 봅니다</span></div>
        <div class="sfpc-preview sfpc-hide" id="pv-ss"><p class="cap">접수 안내 화면의 "대회 일정"에 이렇게 나옵니다</p><div class="md"></div></div></div>
      <div class="field"><label for="sp">개인정보 수집 · 이용 안내 <span class="hint" style="display:inline">비워 두면 기본 문구가 나옵니다 · 마크다운</span></label>
        <textarea id="sp" name="privacy" rows="7" style="width:100%"><?= h(sfpc_setting('sfpc_privacy')) ?></textarea>
        <div class="btnrow" style="margin-top:8px"><button class="btn sm" type="button" data-preview="sp" data-empty="">미리 보기</button>
          <span class="small muted">저장하기 전에 어떻게 나오는지 봅니다</span></div>
        <div class="sfpc-preview sfpc-hide" id="pv-sp"><p class="cap">팀 신청 양식의 동의 칸에 이렇게 나옵니다</p><div class="sfpc-consent md" style="margin:0"></div></div></div>
    </fieldset>

    <button class="btn primary" type="submit" style="padding:10px 24px">저장</button>
  </form>
  <script>
  /* 안내 문구 미리 보기 — 서버가 마크다운을 실제 화면과 같은 방식으로 바꿔 돌려준다. 저장은 하지 않는다. */
  document.querySelectorAll('[data-preview]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var ta = document.getElementById(btn.dataset.preview), box = document.getElementById('pv-' + btn.dataset.preview);
      var out = box.querySelector('.md'), text = ta.value.trim();
      box.classList.remove('sfpc-hide');
      if (text === '') {
        out.textContent = btn.dataset.empty || '비어 있습니다. 저장하면 기본 문구가 나옵니다.';
        return;
      }
      var fd = new FormData();
      fd.append('act', 'preview'); fd.append('text', text);
      fd.append('csrf', document.querySelector('input[name=csrf]').value);
      out.textContent = '불러오는 중…';
      fetch('sfpc.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { out.innerHTML = d.html; })
        .catch(function () { out.textContent = '미리 보기를 불러오지 못했습니다.'; });
    });
  });
  </script>
<?php endif; ?>
</div>
<?php page_foot('../'); ?>
