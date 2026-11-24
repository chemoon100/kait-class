<?php
/* ═══════════════════════════════════════════════
   sfpc_lib.php — SFPC(학교 친구 프로그래밍 챌린지) 대회 기능의 공통 부분

   KAIT-CLASS 원본 파일은 되도록 건드리지 않고, 대회 기능은 sfpc_ 로 시작하는
   새 파일에 모은다. 원본 업데이트를 병합할 때 충돌을 줄이기 위해서다.

   · 표는 여기서 따로 만든다 (schema.php 의 SCHEMA_VERSION 과 무관).
   · 설정은 settings 표를 같이 쓰되 키를 sfpc_ 로 시작하게 한다.
   · 팀이 모든 것의 기준이다. 접수 → 대회 계정 → 제출 → 스코어보드 → 이벤트가
     전부 sfpc_teams.id 에 매달린다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

/* 표 구조를 바꿀 때마다 1씩 올린다 */
const SFPC_SCHEMA = 6;

/* 올해 대회. 내년에는 이 값과 설정만 바꾸면 접수를 새로 받을 수 있다. */
const SFPC_CONTEST = '2026';

/* 16개 시도 — 접수 화면에 이 순서로 나온다.
   2026년 7월 1일 광주광역시와 전라남도가 전남광주통합특별시로 합쳐져 17개에서 16개가 되었다. */
const SFPC_REGIONS = ['서울','부산','대구','인천','대전','울산','세종','경기',
                      '강원','충북','충남','전북','전남광주','경북','경남','제주'];

/* 저장소에 함께 넣어 둔 전국 중 · 고등학교 목록 (운영 중인 학교만).
   출처: 공공데이터포털 '전국초중등학교위치표준데이터' (한국교육시설안전원), 2026-10-01 기준.
   고등학교 유형(일반고 · 특성화고 …)은 이 자료에 없다. */
const SFPC_SCHOOLS_FILE = __DIR__ . '/sfpc_schools.csv';
const SFPC_SCHOOLS_SOURCE = '공공데이터포털 전국초중등학교위치표준데이터 (한국교육시설안전원, 2026-10-01 기준)';

/* 참가 구분.
   kind   학생 팀인지 교사 팀인지 (첫 화면의 큰 버튼 둘)
   level  학교 목록에서 찾을 학교급. '' 이면 목록 없이 직접 입력
   track  처음 정해지는 참가 자격. 운영진이 바꿀 수 있다
          (중학생 팀은 '공부하기'를 마치면 정식참가자가 된다) */
const SFPC_CATS = [
  'middle'     => ['code' => '01', 'label' => '중학교',               'kind' => 'student', 'level' => 'middle', 'track' => 'observer'],
  'general'    => ['code' => '02', 'label' => '일반고',               'kind' => 'student', 'level' => 'high',   'track' => 'participant'],
  'special'    => ['code' => '03', 'label' => '특목/특성화/자사고 등', 'kind' => 'student', 'level' => 'high',   'track' => 'observer'],
  'teacher'    => ['code' => '04', 'label' => '교사',                 'kind' => 'teacher', 'level' => 'any',    'track' => 'observer'],
  'preteacher' => ['code' => '05', 'label' => '예비교사',             'kind' => 'teacher', 'level' => '',       'track' => 'observer'],
];
const SFPC_KIND_NAME  = ['student' => '학생 팀', 'teacher' => '교사·예비교사 팀'];
const SFPC_TRACK_NAME = ['participant' => '정식참가자', 'observer' => '시범참가자'];
const SFPC_STATUS_NAME = ['submitted' => '접수 완료', 'canceled' => '취소', 'rejected' => '반려'];

/* 팀원 수 [최소, 최대] */
const SFPC_MEMBERS = ['student' => [2, 4], 'teacher' => [1, 4]];

/* 현장 참가자 기념 티셔츠 */
const SFPC_SHIRTS = ['S', 'M', 'L', 'XL', '2XL', '3XL'];

/* ── 설정 ─────────────────────────────────────────
   lib.php 의 SETTING_DEFAULTS 를 고치지 않으려고 기본값을 여기 따로 둔다. */
const SFPC_DEFAULTS = [
  'sfpc_title'        => '2026 SFPC 충북',
  'sfpc_host_region'  => '충북',                 // 이 지역 팀은 현장 참가
  'sfpc_apply_from'   => '2026-11-27 00:00',
  'sfpc_apply_to'     => '2027-01-01 23:59',
  'sfpc_schedule'     => '',                     // 첫 화면에 보일 대회 일정 (마크다운)
  'sfpc_privacy'      => '',                     // 개인정보 수집·이용 안내 (마크다운). 비면 기본 문구
  'sfpc_contact'      => 'kaiteachers2013@gmail.com',
  'sfpc_site'         => '1',                    // 이 사이트 전체가 SFPC 사이트다 — 위쪽 메뉴를 접수 · 공부하기 · 준비하기 · 도전하기 · 스코어보드로 바꾸고,
                                                 //   KAIT-CLASS 의 기존 메뉴(문제 모음 · 채점 결과 · 수업 · 평가)는 운영진에게만 보인다
  'sfpc_study_open'   => '0',                    // 공부하기 공개
  'sfpc_ready_open'   => '0',                    // 준비하기 공개
  'sfpc_menu_on'      => '1',                    // 위쪽 메뉴에 SFPC 보이기
  'sfpc_landing_on'   => '1',                    // 접수 전 · 접수 중에 첫 화면을 SFPC 안내로 (sfpc_landing.php)
  'sfpc_account_open' => '0',                    // 지도교사의 "내 팀" 화면에 팀 계정 보이기 (계정을 나눠 주는 날 켠다)
  'sfpc_contest_open' => '0',                    // 대회 참가 화면 공개
  'sfpc_board_open'   => '0',                    // 스코어보드 공개
  'sfpc_neis_key'     => '',                     // 나이스 교육정보 개방 포털 인증키
];
function sfpc_setting(string $key): string {
  $all = settings_all();
  return array_key_exists($key, $all) ? (string)$all[$key] : (string)(SFPC_DEFAULTS[$key] ?? '');
}

/* ── 표 ───────────────────────────────────────────
   sfpc_schools   전국 중·고등학교 목록 (나이스에서 받아 둔다)
   sfpc_coaches   지도교사. 로그인 계정(users)에 이름·연락처를 덧붙인다
   sfpc_teams     팀. account_user_id 는 대회용 팀 계정 — 접수 단계에서는 비어 있고
                  계정을 발급할 때 채운다. 제출 기록이 계정 번호로 쌓이므로
                  이 칸 하나로 기존 채점 기능과 이어진다.
   sfpc_members   팀원 */
function sfpc_ensure_schema(PDO $p): void {
  $p->exec("
    CREATE TABLE IF NOT EXISTS sfpc_schools(
      id      INTEGER PRIMARY KEY AUTOINCREMENT,
      code    TEXT UNIQUE,                     -- 나이스 학교 코드
      name    TEXT NOT NULL,
      region  TEXT NOT NULL,                   -- SFPC_REGIONS 의 값
      level   TEXT NOT NULL,                   -- 'middle' | 'high'
      hs_type TEXT NOT NULL DEFAULT '',        -- 일반고 · 특목고 · 특성화고 · 자율고
      addr    TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_sfpc_school ON sfpc_schools(region, level, name);

    CREATE TABLE IF NOT EXISTS sfpc_coaches(
      user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
      name    TEXT NOT NULL,
      phone   TEXT NOT NULL,
      email   TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS sfpc_teams(
      id       INTEGER PRIMARY KEY AUTOINCREMENT,
      contest  TEXT NOT NULL,
      coach_user_id INTEGER NOT NULL REFERENCES users(id),
      kind     TEXT NOT NULL,                  -- 'student' | 'teacher'
      category TEXT NOT NULL,                  -- SFPC_CATS 의 키
      region   TEXT NOT NULL,
      school_id     INTEGER REFERENCES sfpc_schools(id) ON DELETE SET NULL,
      school_name   TEXT NOT NULL,
      school_manual INTEGER NOT NULL DEFAULT 0,  -- 1 이면 목록에 없어 직접 입력한 학교
      team_name TEXT NOT NULL,
      track    TEXT NOT NULL,                  -- 'participant' | 'observer'
      onsite   INTEGER NOT NULL DEFAULT 0,     -- 1 이면 현장 참가
      status   TEXT NOT NULL DEFAULT 'submitted',  -- 'submitted' | 'canceled' | 'rejected'
      admin_memo TEXT NOT NULL DEFAULT '',
      account_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
      consent_at TEXT NOT NULL,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_sfpc_team_coach ON sfpc_teams(contest, coach_user_id);
    CREATE INDEX IF NOT EXISTS idx_sfpc_team_list  ON sfpc_teams(contest, status, region);

    CREATE TABLE IF NOT EXISTS sfpc_members(
      id      INTEGER PRIMARY KEY AUTOINCREMENT,
      team_id INTEGER NOT NULL REFERENCES sfpc_teams(id) ON DELETE CASCADE,
      seq     INTEGER NOT NULL,
      name    TEXT NOT NULL,
      grade   INTEGER,                         -- 학생만. 교사 팀은 NULL
      shirt   TEXT NOT NULL DEFAULT ''         -- 현장 참가자만
    );
    CREATE INDEX IF NOT EXISTS idx_sfpc_member ON sfpc_members(team_id, seq);

    -- 변경 기록. 누가 언제 무엇을 바꿨는지 남긴다 (마감 뒤 운영진이 명단을 고칠 때 근거가 된다).
    -- team_id 가 비면 팀이 아닌 것(지도교사 정보)에 대한 기록이다. 지우지 않는다.
    CREATE TABLE IF NOT EXISTS sfpc_log(
      id       INTEGER PRIMARY KEY AUTOINCREMENT,
      contest  TEXT NOT NULL,
      team_id  INTEGER,
      coach_user_id INTEGER,                   -- 기록의 대상인 지도교사 (팀 기록이면 그 팀의 지도교사)
      actor_user_id INTEGER,                   -- 바꾼 사람
      actor_name TEXT NOT NULL,                -- 바꾼 사람의 그때 이름 (계정이 지워져도 남게)
      by_admin INTEGER NOT NULL DEFAULT 0,     -- 1 이면 운영진이 바꿨다
      action   TEXT NOT NULL,                  -- create · edit · cancel · restore · admin · coach
      detail   TEXT NOT NULL DEFAULT '',       -- 사람이 읽는 변경 내용. 줄바꿈으로 여러 항목
      created_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_sfpc_log_team ON sfpc_log(team_id, id);
    CREATE INDEX IF NOT EXISTS idx_sfpc_log_time ON sfpc_log(contest, id);
  ");
}
/* 함께 넣어 둔 학교 목록(sfpc_schools.csv)을 표에 넣는다. 넣은 수를 돌려준다.
   같은 학교 코드는 고쳐 쓰므로 여러 번 불러도 된다. */
function sfpc_schools_load_bundled(): int {
  if (!is_file(SFPC_SCHOOLS_FILE) || !($fp = fopen(SFPC_SCHOOLS_FILE, 'r'))) return 0;
  fgetcsv($fp, 0, ',', '"', '');                       /* 첫 줄은 열 이름 */
  $rows = [];
  while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) if (count($r) >= 4) $rows[] = $r;
  fclose($fp);
  return (int)tx(function (PDO $d) use ($rows) {
    $st = $d->prepare("INSERT INTO sfpc_schools(code, name, region, level, hs_type, addr) VALUES(?,?,?,?,'',?)
                       ON CONFLICT(code) DO UPDATE SET name = excluded.name, region = excluded.region,
                         level = excluded.level, addr = excluded.addr");
    $n = 0;
    foreach ($rows as $r) {
      if (!in_array($r[2], SFPC_REGIONS, true) || !in_array($r[3], ['middle', 'high'], true)) continue;
      $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4] ?? '']);
      $n++;
    }
    return $n;
  });
}

/* 요청마다 CREATE TABLE 을 돌리지 않게, 설정에 적어 둔 버전과 다를 때만 */
if ((int)sfpc_setting('sfpc_schema') !== SFPC_SCHEMA) {
  sfpc_ensure_schema(db());
  /* 2 — 광주 · 전남이 전남광주로 합쳐졌다. 먼저 받아 둔 팀과 학교의 지역 이름을 맞춘다. */
  db()->exec("UPDATE sfpc_teams   SET region = '전남광주' WHERE region IN ('광주', '전남')");
  db()->exec("UPDATE sfpc_schools SET region = '전남광주' WHERE region IN ('광주', '전남')");
  /* 4 — 팀 계정의 비밀번호를 마지막으로 정한 때. 비어 있으면 아직 비밀번호가 없다(로그인할 수 없다). */
  if (!in_array('account_pw_at', array_column(db()->query("PRAGMA table_info(sfpc_teams)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
    db()->exec("ALTER TABLE sfpc_teams ADD COLUMN account_pw_at TEXT NOT NULL DEFAULT ''");
  }
  /* 5 — 팀 캐릭터 (고른 부품을 JSON 으로). 비어 있으면 팀 번호로 정해지는 기본 캐릭터가 나온다. */
  if (!in_array('avatar', array_column(db()->query("PRAGMA table_info(sfpc_teams)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
    db()->exec("ALTER TABLE sfpc_teams ADD COLUMN avatar TEXT NOT NULL DEFAULT ''");
  }
  /* 6 — 독립된 SFPC 사이트로. 처음 한 번만: 사이트 이름을 SFPC 로, KAIT-PLAY 메뉴는 숨기고(기능은 그대로),
         교육팀 · 문항 제작팀의 운영 계정을 만든다. 비밀번호는 넣지 않는다 — 운영 > 회원에서 정해야 로그인할 수 있다. */
  if (setting('sfpc_site_init') !== '1') {
    if (in_array(trim(setting('site_name')), ['', APP_NAME], true)) setting_set('site_name', 'SFPC');
    setting_set('play_menu', '0');
    foreach (['sfpcedu' => '교육팀', 'sfpcset' => '문항 제작팀'] as $login => $name) {
      if (!db()->query("SELECT 1 FROM users WHERE login_id = " . db()->quote($login))->fetchColumn()) {
        db()->prepare("INSERT INTO users(login_id, pw_hash, name, role, group_id, source, approved, created_at) VALUES(?,?,?,'admin',NULL,'sfpc_staff',1,?)")
            ->execute([$login, '!', $name, now()]);
      }
    }
    setting_set('sfpc_site_init', '1');
  }
  /* 학교 목록이 비어 있으면 함께 넣어 둔 목록으로 채운다 — 설치만 하면 학교 찾기가 바로 된다 */
  if ((int)db()->query("SELECT COUNT(*) FROM sfpc_schools")->fetchColumn() === 0 && sfpc_schools_load_bundled() > 0) {
    setting_set('sfpc_schools_at', now());
  }
  setting_set('sfpc_schema', (string)SFPC_SCHEMA);
}

/* ── 접수 기간 ───────────────────────────────────── */
function sfpc_ts(string $s): int {
  $t = strtotime(str_replace('T', ' ', trim($s)));
  return $t === false ? 0 : $t;
}
/* ── 운영진 미리 보기 ─────────────────────────────
   주소에 ?as=before | open | closed 를 붙이면, 운영진에게만, 그 시기의 화면을 방문자 눈으로 보여 준다.
   설정은 바뀌지 않는다 — 접수가 진행 중인 운영 서버에서 기간을 바꿔 가며 확인할 수는 없기 때문이다.
   ?as=guest 는 "준비 중" 화면처럼 운영진이 그냥은 볼 수 없는 방문자 화면을 보여 준다. */
const SFPC_AS_NAME = ['before' => '접수 전', 'open' => '접수 중', 'closed' => '마감 후', 'guest' => '방문자'];
function sfpc_preview(): ?string {
  static $v = false;
  if ($v === false) {
    $s = (string)($_GET['as'] ?? '');
    $v = (isset(SFPC_AS_NAME[$s]) && is_admin(me())) ? $s : null;
  }
  return $v;
}
function sfpc_preview_bar(): void {
  $p = sfpc_preview();
  if ($p === null) return;
  echo '<div class="sfpc-pvbar"><b>미리 보기 · ' . h(SFPC_AS_NAME[$p]) . ' 모습</b>'
     . '<span>방문자에게 이렇게 보입니다. 운영진에게만 보이는 화면이고, 설정은 바뀌지 않았습니다.</span></div>';
}

/** 'before' | 'open' | 'closed' */
function sfpc_apply_state(): string {
  $p = sfpc_preview();
  if ($p !== null && $p !== 'guest') return $p;
  $now  = time();
  $from = sfpc_ts(sfpc_setting('sfpc_apply_from'));
  $to   = sfpc_ts(sfpc_setting('sfpc_apply_to'));
  if ($from && $now < $from) return 'before';
  if ($to && $now > $to + 59) return 'closed';   /* 23:59 로 적으면 그 1분이 끝날 때까지 */
  return 'open';
}
/* 팀을 새로 내거나 고칠 수 있는지. 운영진은 기간 밖에서도 미리 눌러 볼 수 있다. */
function sfpc_can_edit(?array $u): bool {
  return sfpc_apply_state() === 'open' || is_admin($u);
}
function sfpc_period_text(): string {
  $f = fn(string $s) => ($t = sfpc_ts($s)) ? date('Y. n. j.', $t) . '(' . ['일','월','화','수','목','금','토'][(int)date('w', $t)] . ') ' . date('H:i', $t) : '';
  return $f(sfpc_setting('sfpc_apply_from')) . ' ~ ' . $f(sfpc_setting('sfpc_apply_to'));
}

/* ── 지도교사 ───────────────────────────────────── */
function sfpc_coach(?array $u): ?array {
  if (!$u) return null;
  return one("SELECT * FROM sfpc_coaches WHERE user_id = ?", [$u['id']]);
}
/* 전화번호는 숫자만 남겨 010-1234-5678 꼴로 맞춘다. 맞지 않으면 '' */
function sfpc_phone(string $s): string {
  $d = preg_replace('/\D+/', '', $s);
  if (preg_match('/^(01[016789])(\d{3,4})(\d{4})$/', $d, $m)) return "$m[1]-$m[2]-$m[3]";
  if (preg_match('/^(02)(\d{3,4})(\d{4})$/', $d, $m))         return "$m[1]-$m[2]-$m[3]";
  if (preg_match('/^(0\d{2})(\d{3,4})(\d{4})$/', $d, $m))     return "$m[1]-$m[2]-$m[3]";
  return '';
}

/* ── 팀 계정 ──────────────────────────────────────
   팀마다 대회용 계정이 하나 있다. 팀원이 이 계정으로 함께 로그인해 문제를 푼다.
   · 아이디는 접수 번호에서 정해진다 (sfpc260001). 비밀번호를 정하기 전에도 미리 알려 줄 수 있다.
   · 계정(users 행)은 비밀번호를 처음 정할 때 만든다. 그 전에는 로그인할 것이 없다.
   · 비밀번호는 암호화한 값만 저장한다. 그래서 "조회"는 없고, 새로 정하는 것만 된다.
   · 취소 · 반려된 팀의 계정은 로그인이 막힌다 (users.approved = 0).
   지도교사에게 이 기능이 보이는 때는 설정 sfpc_account_open 으로 정한다. */
const SFPC_ACCOUNT_SOURCE = 'sfpc_team';
const SFPC_PW_MIN = 6;
/* 팀 계정 이름 = sfpc + 참가 구분 두 자리 + 접수 번호 네 자리 (중학교 3번 팀 → sfpc010003).
   · sfpc_account_want() — 지금의 참가 구분으로 매긴 이름
   · sfpc_account_id()   — 실제로 쓰는 이름. 계정을 이미 만들었으면(비밀번호를 정했으면) 그 계정의 이름이고,
                           아직이면 want 와 같다. 그래서 나눠 주기 전에는 구분을 고치면 이름도 따라가고,
                           나눠 준 뒤에는 운영진이 "계정 다시 발급"을 눌러야 바뀐다 (sfpc_account_reissue). */
function sfpc_account_want(array $t): string {
  return 'sfpc' . (SFPC_CATS[$t['category']]['code'] ?? '00') . str_pad((string)$t['id'], 4, '0', STR_PAD_LEFT);
}
function sfpc_account_id(array $t): string {
  $uid = (int)($t['account_user_id'] ?? 0);
  if ($uid) { $l = db()->query("SELECT login_id FROM users WHERE id = $uid")->fetchColumn(); if ($l !== false) return (string)$l; }
  return sfpc_account_want($t);
}
function sfpc_account_stale(array $t): bool { return sfpc_account_id($t) !== sfpc_account_want($t); }
/* 계정 이름을 지금의 참가 구분에 맞게 다시 매긴다. 비밀번호 · 캐릭터 · 풀이 기록은 그대로다. 오류 문구를 돌려준다. */
function sfpc_account_reissue(array $t, array $actor): string {
  $uid = (int)($t['account_user_id'] ?? 0); $old = sfpc_account_id($t); $new = sfpc_account_want($t);
  if (!$uid || $old === $new) return '';
  if (db()->query("SELECT 1 FROM users WHERE login_id = " . db()->quote($new))->fetchColumn()) return '이미 쓰이고 있는 계정 이름입니다. (' . $new . ')';
  db()->prepare("UPDATE users SET login_id = ? WHERE id = ? AND source = ?")->execute([$new, $uid, SFPC_ACCOUNT_SOURCE]);
  sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $actor, 'account', '팀 계정 이름을 다시 발급: ' . $old . ' → ' . $new);
  return '';
}
function sfpc_account_open(): bool { return sfpc_setting('sfpc_account_open') === '1'; }
/* 읽어 주기 쉬운 무작위 비밀번호 — 헷갈리는 글자(0 o 1 l i)는 뺀다 */
function sfpc_random_pw(int $len = 8): string {
  $abc = 'abcdefghjkmnpqrstuvwxyz23456789'; $out = '';
  for ($i = 0; $i < $len; $i++) $out .= $abc[random_int(0, strlen($abc) - 1)];
  return $out;
}
/* 팀 계정의 비밀번호를 정한다. 계정이 없으면 만든다. 오류 문구를 돌려준다 ('' 이면 성공). */
function sfpc_account_set_pw(array $t, string $pw, array $actor): string {
  if (strlen($pw) < SFPC_PW_MIN) return '비밀번호는 ' . SFPC_PW_MIN . '자 이상으로 정해 주세요.';
  if (strlen($pw) > 72)          return '비밀번호가 너무 깁니다.';
  $login = sfpc_account_id($t);
  $hash  = password_hash($pw, PASSWORD_DEFAULT);
  $ok    = $t['status'] === 'submitted' ? 1 : 0;
  try {
    tx(function (PDO $d) use ($t, $login, $hash, $ok) {
      $uid = (int)($t['account_user_id'] ?? 0);
      if ($uid && !$d->query("SELECT 1 FROM users WHERE id = $uid")->fetchColumn()) $uid = 0;
      if (!$uid) {
        $d->prepare("INSERT INTO users(login_id, pw_hash, name, role, group_id, source, approved, created_at)
                     VALUES(?,?,?,'student',NULL,?,?,?)")
          ->execute([$login, $hash, $t['team_name'], SFPC_ACCOUNT_SOURCE, $ok, now()]);
        $uid = (int)$d->lastInsertId();
      } else {
        $d->prepare("UPDATE users SET pw_hash = ?, name = ?, approved = ? WHERE id = ?")->execute([$hash, $t['team_name'], $ok, $uid]);
      }
      $d->prepare("UPDATE sfpc_teams SET account_user_id = ?, account_pw_at = ? WHERE id = ?")->execute([$uid, now(), $t['id']]);
    });
  } catch (Throwable $e) {
    return '계정을 만들지 못했습니다. 운영진에게 알려 주세요. (' . $login . ')';
  }
  sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $actor, 'account',
           ($t['account_pw_at'] ?? '') === '' ? '팀 계정 ' . $login . ' 의 비밀번호를 처음 정함' : '팀 계정 ' . $login . ' 의 비밀번호를 바꿈');
  return '';
}
/* 비밀번호 칸 오른쪽에 "보기" 눈 버튼을 붙이고, .js-pwrand 버튼에는 무작위 비밀번호 채우기를 건다.
   이 함수를 부른 화면의 모든 비밀번호 칸에 적용된다. 무작위 비밀번호는 브라우저가 만들어 두 칸에 넣을 뿐이고,
   저장은 사람이 "정하기"를 눌러야 된다. */
function sfpc_pw_eye(): void { ?>
<script>
(function () {
  var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.7 8.5 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.5 4.5-1.2"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    var wrap = document.createElement('span'); wrap.className = 'sfpc-pw';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    var b = document.createElement('button'); b.type = 'button'; b.className = 'sfpc-pw-eye';
    function set(show) {
      inp.type = show ? 'text' : 'password'; b.innerHTML = show ? OFF : EYE;
      b.setAttribute('aria-label', show ? '비밀번호 숨기기' : '비밀번호 보기'); b.title = b.getAttribute('aria-label');
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
    }
    b.addEventListener('click', function () { set(inp.type === 'password'); });
    inp.form && inp.form.addEventListener('submit', function () { inp.type = 'password'; });   /* 보낼 때는 다시 가린다 */
    set(false); wrap.appendChild(b);
  });
  var ABC = 'abcdefghjkmnpqrstuvwxyz23456789';
  document.querySelectorAll('.js-pwrand').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var f = btn.form, a = new Uint32Array(8), pw = ''; crypto.getRandomValues(a);
      for (var i = 0; i < a.length; i++) pw += ABC[a[i] % ABC.length];
      f.querySelectorAll('input[name=pw], input[name=pw2]').forEach(function (inp) { inp.value = pw; });
      var n = f.querySelector('.rand-note'); if (n) n.hidden = false;
    });
  });
})();
</script>
<?php }

/* 팀 이름 · 상태가 바뀌면 계정에도 맞춘다. 팀을 저장하는 곳마다 부른다. */
function sfpc_account_sync(int $teamId): void {
  $t = sfpc_team($teamId);
  if (!$t || !(int)$t['account_user_id']) return;
  db()->prepare("UPDATE users SET name = ?, approved = ? WHERE id = ? AND source = ?")
      ->execute([$t['team_name'], $t['status'] === 'submitted' ? 1 : 0, $t['account_user_id'], SFPC_ACCOUNT_SOURCE]);
}
/* 흔한 이메일 주소 오타 — 가입 화면이 바로 알려 준다 (메일을 실제로 보내 확인하는 기능은 뒤에 붙인다) */
const SFPC_EMAIL_TYPO = [
  'gamil.com' => 'gmail.com', 'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.co' => 'gmail.com',
  'gmail.cm' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
  'naver.con' => 'naver.com', 'naver.co' => 'naver.com', 'naver.cm' => 'naver.com', 'nvaer.com' => 'naver.com', 'naber.com' => 'naver.com',
  'naver.net' => 'naver.com', 'navar.com' => 'naver.com', 'never.com' => 'naver.com',
  'daum.con' => 'daum.net', 'daum.com' => 'daum.net', 'duam.net' => 'daum.net', 'hanmail.com' => 'hanmail.net', 'hanmail.con' => 'hanmail.net',
  'kakao.con' => 'kakao.com', 'kakao.net' => 'kakao.com',
];
function sfpc_email_typo(string $email) {
  $d = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
  return isset(SFPC_EMAIL_TYPO[$d]) ? [$d, SFPC_EMAIL_TYPO[$d]] : '';
}

/* 로그인한 사람이 팀 계정이면 그 팀, 아니면 null */
function sfpc_my_team(?array $u): ?array {
  if (!$u || ($u['source'] ?? '') !== SFPC_ACCOUNT_SOURCE) return null;
  return one("SELECT * FROM sfpc_teams WHERE account_user_id = ? AND contest = ?", [$u['id'], SFPC_CONTEST]);
}
/* 팀 계정으로는 접수 화면(신청 · 내 팀 · 지도교사 가입)을 쓰지 않는다 — 팀 화면으로 보낸다 */
function sfpc_coach_only(?array $u): void {
  if ($u && ($u['source'] ?? '') === SFPC_ACCOUNT_SOURCE) { header('Location: ' . sfpc_path('sfpc_me.php')); exit; }
}

/* ── 팀 캐릭터 ────────────────────────────────────
   부품 일곱 가지를 골라 만든다. 그림은 sfpc_char.js 가 그리고, 여기에는 고를 수 있는 값과 이름만 둔다
   (두 파일의 값 이름이 같아야 한다). 색은 번호다. */
const SFPC_CHAR = [
  'b' => ['label' => '몸', 'items' => ['robot' => '로봇', 'slime' => '슬라임', 'ghost' => '유령', 'cat' => '고양이', 'bread' => '식빵',
                                        'potato' => '감자', 'alien' => '외계인', 'tofu' => '두부', 'dumpling' => '만두', 'mushroom' => '버섯', 'pudding' => '푸딩',
                                        'pumpkin' => '호박', 'pouch' => '복주머니']],
  'c' => ['label' => '색', 'items' => ['보라', '청록', '주황', '분홍', '파랑', '노랑', '연두', '회색', '빨강', '하양']],
  'e' => ['label' => '눈', 'items' => ['big' => '큰 눈', 'dot' => '점', 'smile' => '웃는 눈', 'sleepy' => '졸린 눈', 'cyclops' => '외눈',
                                        'spin' => '뱅글뱅글', 'tear' => '눈물 글썽', 'odd' => '짝눈', 'heart' => '하트눈', 'star' => '반짝 눈', 'angry' => '화난 눈', 'visor' => '바이저',
                                        'sunglasses' => '선글라스', 'glasses' => '동그란 안경', 'swirl' => '뱅글 안경과 콧수염',
                                        'yeonji' => '연지곤지', 'tal' => '탈 웃음', 'skull' => '해골 눈']],
  'm' => ['label' => '입', 'items' => ['none' => '없음', 'tongue' => '메롱', 'grin' => '씨익', 'o' => '뻐끔', 'drool' => '침 흘림',
                                        'cat' => '고양이 입', 'frown' => '시무룩', 'buck' => '앞니', 'candy' => '막대사탕', 'mustache' => '콧수염',
                                        'fangs' => '송곳니', 'jack' => '호박 등불 입']],
  'h' => ['label' => '머리', 'items' => ['none' => '없음', 'antenna' => '안테나', 'crown' => '왕관', 'sprout' => '새싹', 'egg' => '계란후라이',
                                         'banana' => '바나나 껍질', 'pot' => '냄비', 'leek' => '대파', 'question' => '물음표', 'exclaim' => '느낌표',
                                         'flowerpot' => '화분', 'book' => '책', 'party' => '고깔모자', 'propeller' => '프로펠러', 'storm' => '먹구름 번개',
                                         'bulb' => '전구', 'fin' => '상어 지느러미', 'cone' => '공사장 고깔', 'halo' => '천사 고리', 'duck' => '오리',
                                         'gat' => '갓', 'jokduri' => '족두리', 'sangmo' => '상모', 'dokkaebi' => '도깨비 뿔',
                                         'witch' => '마녀 모자', 'bat' => '박쥐', 'spider' => '거미', 'candle' => '촛불']],
  'w' => ['label' => '옷', 'items' => ['none' => '없음', 'hanbok' => '한복', 'dopo' => '도포', 'saekdong' => '색동', 'scarf' => '목도리',
                                        'cape' => '드라큘라 망토', 'mummy' => '미라 붕대', 'bones' => '해골 옷']],
  'f' => ['label' => '이동 효과', 'items' => ['none' => '없음', 'sparkle' => '반짝이', 'bolt' => '번개', 'fire' => '불꽃', 'bits' => '0과 1',
                                              'fart' => '방귀 구름', 'sweat' => '땀방울', 'note' => '음표', 'heart' => '하트', 'leaf' => '낙엽', 'bubble' => '비눗방울', 'zzz' => '쿨쿨',
                                              'songpyeon' => '송편', 'coin' => '엽전', 'candy' => '사탕', 'bats' => '박쥐 떼', 'ghosts' => '꼬마 유령']],
];
/* 보낸 값에서 허용된 것만 남긴다. 하나라도 틀리면 null */
function sfpc_char_clean($in): ?array {
  if (!is_array($in)) return null;
  $out = [];
  foreach (SFPC_CHAR as $k => $part) {
    $v = $in[$k] ?? ($k === 'w' ? 'none' : null);   /* 옷은 나중에 생긴 부품 — 없으면 '없음' */
    if ($k === 'c') { if (!is_int($v) && !(is_string($v) && ctype_digit($v))) return null; $v = (int)$v; }
    elseif (!is_string($v)) return null;
    if (!array_key_exists($v, $part['items'])) return null;
    $out[$k] = $v;
  }
  return $out;
}
/* 아직 꾸미지 않은 팀의 캐릭터 — 팀 번호로 정해져 늘 같다 */
function sfpc_char_default(int $teamId): array {
  $out = []; $n = $teamId * 2654435761 % 4294967296;
  foreach (SFPC_CHAR as $k => $part) {
    $keys = array_keys($part['items']);
    $out[$k] = $keys[$n % count($keys)];
    $n = intdiv($n, 7) + $teamId * 31;
  }
  $out['f'] = 'none'; $out['w'] = 'none';
  return $out;
}
/* 팀 계정은 캐릭터를 만들 때까지 어느 화면을 열어도 꾸미기 화면으로 온다.
   layout.php 의 page_head() 가 화면을 그리기 직전에 부른다. */
function sfpc_char_gate(?array $u, string $root = ''): void {
  if (!$u || ($u['source'] ?? '') !== SFPC_ACCOUNT_SOURCE) return;
  if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'sfpc_char.php') return;
  $t = sfpc_my_team($u);
  if (!$t || $t['status'] !== 'submitted' || ($t['avatar'] ?? '') !== '') return;
  header('Location: ' . $root . 'sfpc_char.php'); exit;
}
function sfpc_char_of(array $t): array {
  $c = ($t['avatar'] ?? '') !== '' ? sfpc_char_clean(json_decode((string)$t['avatar'], true)) : null;
  return $c ?? sfpc_char_default((int)$t['id']);
}

/* ── 변경 기록 ───────────────────────────────────── */
const SFPC_LOG_NAME = ['create' => '신청', 'edit' => '수정', 'cancel' => '취소', 'restore' => '다시 접수',
                       'admin' => '운영진 조정', 'coach' => '지도교사 정보', 'account' => '팀 계정'];
function sfpc_log(?int $teamId, ?int $coachUserId, array $actor, string $action, string $detail): void {
  $admin = is_admin($actor);
  $name  = $admin ? (string)$actor['name'] : (string)(col("SELECT name FROM sfpc_coaches WHERE user_id = ?", [$actor['id']]) ?: $actor['name']);
  db()->prepare("INSERT INTO sfpc_log(contest, team_id, coach_user_id, actor_user_id, actor_name, by_admin, action, detail, created_at)
                 VALUES(?,?,?,?,?,?,?,?,?)")
      ->execute([SFPC_CONTEST, $teamId, $coachUserId, $actor['id'], $name, $admin ? 1 : 0, $action, $detail, now()]);
}
/* 팀원 한 사람을 기록에 적는 꼴: "홍길동(2학년, L)" */
function sfpc_member_text(array $m): string {
  $x = array_filter([!empty($m['grade']) ? $m['grade'] . '학년' : '', (string)($m['shirt'] ?? '')], fn($v) => $v !== '');
  return $m['name'] . ($x ? '(' . implode(', ', $x) . ')' : '');
}
/* 고치기 전과 뒤를 견주어 바뀐 것만 "항목: 전 → 뒤" 로 적는다. 바뀐 것이 없으면 '' */
function sfpc_team_diff(array $old, array $oldMembers, array $new, array $newMembers): string {
  $out = [];
  $pair = function (string $label, string $a, string $b) use (&$out) { if ($a !== $b) $out[] = "$label: $a → $b"; };
  $pair('지역', (string)$old['region'], (string)$new['region']);
  $pair('참가 구분', SFPC_CATS[$old['category']]['label'] ?? (string)$old['category'], SFPC_CATS[$new['category']]['label'] ?? (string)$new['category']);
  $pair('학교', (string)$old['school_name'], (string)$new['school_name']);
  $pair('팀명', (string)$old['team_name'], (string)$new['team_name']);
  $n = max(count($oldMembers), count($newMembers));
  for ($i = 0; $i < $n; $i++) {
    $a = isset($oldMembers[$i]) ? sfpc_member_text($oldMembers[$i]) : '';
    $b = isset($newMembers[$i]) ? sfpc_member_text($newMembers[$i]) : '';
    if ($a === $b) continue;
    if ($a === '')      $out[] = '팀원 ' . ($i + 1) . ' 추가: ' . $b;
    elseif ($b === '')  $out[] = '팀원 ' . ($i + 1) . ' 삭제: ' . $a;
    else                $out[] = '팀원 ' . ($i + 1) . ': ' . $a . ' → ' . $b;
  }
  return implode("\n", $out);
}

/* ── 팀 ─────────────────────────────────────────── */
function sfpc_team(int $id): ?array {
  return one("SELECT * FROM sfpc_teams WHERE id = ? AND contest = ?", [$id, SFPC_CONTEST]);
}
function sfpc_team_members(int $teamId): array {
  return all("SELECT * FROM sfpc_members WHERE team_id = ? ORDER BY seq", [$teamId]);
}
function sfpc_teams_of(int $coachUserId): array {
  return all("SELECT t.*, (SELECT COUNT(*) FROM sfpc_members m WHERE m.team_id = t.id) AS member_cnt
              FROM sfpc_teams t WHERE t.contest = ? AND t.coach_user_id = ?
              ORDER BY t.id", [SFPC_CONTEST, $coachUserId]);
}
function sfpc_is_onsite(string $region): bool {
  return $region !== '' && $region === sfpc_setting('sfpc_host_region');
}
/* 접수 번호 — 화면과 문의에 쓰는 사람이 읽는 번호 */
function sfpc_team_no(array $t): string {
  return 'S' . substr((string)$t['contest'], -2) . '-' . str_pad((string)$t['id'], 4, '0', STR_PAD_LEFT);
}
function sfpc_clean(string $s, int $max): string {
  $s = trim((string)preg_replace('/\s+/u', ' ', $s));
  return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/* ── 학교 목록 ───────────────────────────────────── */
function sfpc_school_count(): int { return (int)col("SELECT COUNT(*) FROM sfpc_schools"); }

/* '서울특별시' · '강원특별자치도교육청' · '충청북도' 같은 이름을 '서울' · '강원' · '충북' 으로.
   광주 · 전남은 옛 이름으로 적혀 있어도 '전남광주' 로 모은다. */
function sfpc_region_short(string $s): string {
  $s = trim($s);
  $map = ['충청북' => '충북', '충청남' => '충남', '전라북' => '전북', '전북특' => '전북',
          '전라남' => '전남광주', '전남광' => '전남광주', '광주광' => '전남광주',
          '경상북' => '경북', '경상남' => '경남', '강원특' => '강원',
          '제주특' => '제주', '세종특' => '세종'];
  $head3 = function_exists('mb_substr') ? mb_substr($s, 0, 3) : substr($s, 0, 9);
  if (isset($map[$head3])) return $map[$head3];
  $head2 = function_exists('mb_substr') ? mb_substr($s, 0, 2) : substr($s, 0, 6);
  if ($head2 === '광주' || $head2 === '전남') return '전남광주';
  return in_array($head2, SFPC_REGIONS, true) ? $head2 : '';
}
/** '중학교' → middle, '고등학교' → high, 그 밖은 '' (목록에 넣지 않는다) */
function sfpc_level_of(string $kind): string {
  $kind = trim($kind);
  if ($kind === '중학교') return 'middle';
  if ($kind === '고등학교') return 'high';
  return '';
}
/* 학교 한 줄을 넣거나 고친다. 넣었으면 true */
function sfpc_school_put(PDO $d, string $code, string $name, string $regionRaw, string $kindRaw, string $hsType, string $addr): bool {
  $region = sfpc_region_short($regionRaw);
  $level  = sfpc_level_of($kindRaw);
  $name   = trim($name);
  if ($region === '' || $level === '' || $name === '') return false;
  $code = trim($code);
  if ($code === '') $code = 'x' . substr(md5($region . '|' . $level . '|' . $name), 0, 12);
  $d->prepare("INSERT INTO sfpc_schools(code, name, region, level, hs_type, addr) VALUES(?,?,?,?,?,?)
               ON CONFLICT(code) DO UPDATE SET name = excluded.name, region = excluded.region,
                 level = excluded.level, hs_type = excluded.hs_type, addr = excluded.addr")
    ->execute([$code, $name, $region, $level, $level === 'high' ? trim($hsType) : '', trim($addr)]);
  return true;
}

/* ── 폼 위조 방지 ─────────────────────────────────
   접수는 개인정보를 받고 운영 화면은 명단을 바꾸므로, 폼마다 세션에 묶인 값을 확인한다. */
function sfpc_csrf(): string {
  if (empty($_SESSION['sfpc_csrf'])) $_SESSION['sfpc_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['sfpc_csrf'];
}
function sfpc_csrf_field(): string {
  return '<input type="hidden" name="csrf" value="' . h(sfpc_csrf()) . '">';
}
function sfpc_csrf_ok(): bool {
  return hash_equals(sfpc_csrf(), (string)($_POST['csrf'] ?? ''));
}

/* ── 화면 조각 ───────────────────────────────────── */
/* page_head() 바로 뒤에 부른다. 원본 style.css 를 고치지 않으려고 따로 둔 스타일이다. */
function sfpc_css(string $root = ''): void {
  echo '<link rel="stylesheet" href="' . asset('sfpc.css', $root) . '">' . "\n";
}
function sfpc_status_badge(array $t): string {
  $cls = ['submitted' => 'v-AC', 'canceled' => 'v-wait', 'rejected' => 'v-WA'][$t['status']] ?? 'v-wait';
  return '<span class="v ' . $cls . '">' . h(SFPC_STATUS_NAME[$t['status']] ?? $t['status']) . '</span>';
}

/* 아직 열지 않은 화면 — 운영진에게는 그대로 보여 주고(미리 보기), 그 밖에는 안내만 한다.
   돌려주는 값이 true 면 부른 쪽이 화면을 이어서 그린다. */
function sfpc_gate(string $settingKey, string $title, ?array $u): bool {
  if (sfpc_setting($settingKey) === '1') return true;
  if (is_admin($u) && sfpc_preview() !== 'guest') {
    echo '<div class="wrap" style="padding-bottom:0"><div class="note admin-preview" style="margin:0">'
       . '아직 공개하지 않은 화면입니다. 운영진에게만 보입니다. 운영 &gt; SFPC &gt; 설정에서 공개합니다.</div></div>';
    return true;
  }
  $schedule = trim(sfpc_setting('sfpc_schedule'));
  sfpc_preview_bar();
  echo '<div class="wrap narrow"><div class="sfpc-soon">'
     . '<p class="sfpc-soon-k">' . h(sfpc_setting('sfpc_title')) . '</p>'
     . '<h1>' . h($title) . ' 화면은 준비 중입니다</h1>'
     . '<p>대회 일정에 맞춰 열립니다. 지금은 참가 접수만 받고 있습니다.</p>'
     . ($schedule !== '' ? '<div class="md sfpc-soon-sch">' . md($schedule) . '</div>' : '')
     . '<p style="margin-top:22px"><a class="btn primary" href="sfpc.php">대회 안내와 참가 접수</a></p>'
     . '</div></div>';
  return false;
}

/* ── 독립 SFPC 사이트 ─────────────────────────────
   sfpc_site 가 켜져 있으면 이 설치 전체가 SFPC 사이트다.
   · 위쪽 메뉴: 접수 · 공부하기 · 준비하기 · 도전하기 · 스코어보드 (+ 팀 계정에게는 우리 팀)
   · KAIT-CLASS 의 기존 화면(문제 모음 · 출처 · 채점 결과 · 수업 · 평가)은 운영진만 쓴다.
     메뉴에서 빼고, 주소를 직접 쳐도 첫 화면으로 돌려보낸다 — 준비 중인 대회 문제가 보이면 안 된다.
     (공지사항은 대회 공지에 쓰므로 누구에게나 보인다.) */
function sfpc_site(): bool { return sfpc_setting('sfpc_site') === '1'; }
const SFPC_NAV = [
  'sfpc'         => ['접수',       'sfpc.php'],
  'sfpc_study'   => ['공부하기',   'sfpc_study.php'],
  'sfpc_ready'   => ['준비하기',   'sfpc_ready.php'],
  'sfpc_contest' => ['도전하기',   'sfpc_contest.php'],
  'sfpc_board'   => ['스코어보드', 'sfpc_board.php'],
];
const SFPC_STAFF_NAV = ['problems', 'tags', 'results', 'lessons', 'assessments'];   /* 운영진만 */
function sfpc_site_gate(?array $u, string $nav, string $root = ''): void {
  if (!sfpc_site() || is_admin($u)) return;
  $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if (in_array($nav, SFPC_STAFF_NAV, true) || $page === 'user.php') { header('Location: ' . $root . 'index.php'); exit; }
}

/* 첫 화면(index.php)에 끼우는 알림 띠. 접수 전 · 접수 중에만 나온다. */
function sfpc_home_banner(): void {
  if (sfpc_setting('sfpc_menu_on') !== '1') return;
  $st = sfpc_apply_state();
  if ($st === 'closed') return;
  sfpc_css();
  echo '<div class="sfpc-banner"><div class="sfpc-banner-in">'
     . '<b>' . h(sfpc_setting('sfpc_title')) . '</b>'
     . '<span>' . ($st === 'open' ? '참가 접수 중' : '참가 접수 예정') . ' · ' . h(sfpc_period_text()) . '</span>'
     . '<span class="grow"></span>'
     . '<a class="btn sm" href="sfpc.php">' . ($st === 'open' ? '참가 접수하기' : '대회 안내 보기') . '</a>'
     . '</div></div>';
}

/* 같은 사이트 안의 주소 (로그인 뒤 돌아올 곳으로 쓴다) — '/sfpc_teams.php' 꼴 */
function sfpc_path(string $file): string {
  $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
  return $dir . '/' . ltrim($file, '/');
}

/* ── 접수 기간의 첫 화면 ──────────────────────────
   접수 전 · 접수 중에는 사이트 주소로 들어오면 첫 화면(index.php) 대신 SFPC 안내(sfpc_landing.php)가 뜬다.
   로그인했든 아니든, 전에 "KAIT-CLASS 이동하기"를 눌렀든 아니든 마찬가지다 —
   건너뛰는 규칙을 두었더니 "있던 화면이 왜 없지" 하고 헷갈렸다.

   사이트 안에서 온 경우만 기존 첫 화면을 보여 준다 (위쪽 로고를 누름, 로그인한 뒤, 이동하기를 누름).
   안에서 왔는지는 브라우저가 알려 주는 앞 화면 주소(Referer)로 본다. 그 값을 보내지 않는
   브라우저에서는 로고를 눌러도 SFPC 안내가 뜨지만, 거기서 이동하기를 누르면 된다.
   접수가 끝나면 자동으로 기존 첫 화면으로 돌아간다. */
function sfpc_landing_active(): bool {
  if (sfpc_site()) return sfpc_setting('sfpc_landing_on') === '1';      /* SFPC 사이트에서는 접수가 끝나도 이 화면이 첫 화면이다 */
  return sfpc_setting('sfpc_menu_on') === '1' && sfpc_setting('sfpc_landing_on') === '1'
      && sfpc_apply_state() !== 'closed';
}
function sfpc_from_inside(): bool {
  $ref  = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($ref === '' || $host === '') return false;
  $rh = (string)parse_url($ref, PHP_URL_HOST);
  $rp = parse_url($ref, PHP_URL_PORT);
  return strcasecmp($rh . ($rp ? ':' . $rp : ''), $host) === 0;
}
function sfpc_landing_gate(?array $u): void {
  sfpc_char_gate($u);   /* 캐릭터를 만들지 않은 팀 계정은 첫 화면보다 꾸미기가 먼저 */
  if (!sfpc_landing_active()) return;
  if (sfpc_site()) { if (is_admin($u) && isset($_GET['home'])) return; }       /* SFPC 사이트: 운영진만 ?home=1 로 기존 첫 화면을 본다 */
  elseif (isset($_GET['home']) || sfpc_from_inside()) return;
  require __DIR__ . '/sfpc_landing.php';
  exit;
}
