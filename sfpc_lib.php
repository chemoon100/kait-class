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

/* 모니터링(admin/sfpc_monitor.php)을 위한 요청 기록 — 화면 요청마다 "시각 밀리초 계정번호" 한 줄을 1분 단위 파일에 덧붙인다.
   주소나 내용은 적지 않는다. 두 시간이 지난 파일은 채점 일꾼이 지운다. (제출 · 채점 확인 같은 api.php 요청은 이 파일을 거치지 않아 빠진다.) */
if (PHP_SAPI !== 'cli' && !defined('SFPC_REQ_LOGGED')) {
  define('SFPC_REQ_LOGGED', 1);
  register_shutdown_function(function () {
    $dir = DATA_DIR . '/req';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $ms = (int)round((microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
    @file_put_contents($dir . '/' . date('Ymd-Hi') . '.log', time() . ' ' . $ms . ' ' . (int)($_SESSION['uid'] ?? 0) . "\n", FILE_APPEND | LOCK_EX);
  });
}

/* 표 구조를 바꿀 때마다 1씩 올린다 */
const SFPC_SCHEMA = 16;

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
  'sfpc_host_region'  => '충북',                 // 이 지역의 학생 팀만 오프라인(대회장) 참가를 고를 수 있다
  'sfpc_shirt_due'    => '',                     // 티셔츠 조사 마감 (YYYY-MM-DD HH:MM). 그 뒤에 오프라인으로 바꾼 팀은 티셔츠를 못 받을 수 있다
  'sfpc_apply_from'   => '2026-11-27 00:00',
  'sfpc_apply_to'     => '2027-01-01 23:59',
  'sfpc_schedule'     => '',                     // 첫 화면에 보일 대회 일정 (마크다운)
  'sfpc_privacy'      => '',                     // 개인정보 수집·이용 안내 (마크다운). 비면 기본 문구
  'sfpc_contact'      => 'sfpcadmin@gmail.com',
  'sfpc_site'         => '1',                    // 이 사이트 전체가 SFPC 사이트다 — 위쪽 메뉴를 접수 · 공부하기 · 준비하기 · 도전하기 · 스코어보드로 바꾸고,
                                                 //   KAIT-CLASS 의 기존 메뉴(문제 모음 · 채점 결과 · 수업 · 평가)는 운영진에게만 보인다
  'sfpc_study_open'   => '1',
  'sfpc_boss_every'   => '2',                    // 공부하기에서 문제 몇 개마다 보스(연타 도전)를 둘지
  'sfpc_mash_sec'     => '20',                   // 연타 한 판의 길이(초)                    // 공부하기 공개
  'sfpc_ready_min'    => '90',                   // 준비하기 한 판의 길이(분)
  'sfpc_ready_per_day' => '3',                   // 준비하기 하루 도전 횟수 (자정에 다시 찬다)
  'sfpc_ready_sets'   => '',                     // 준비하기 문제 세트(묶음 번호, 쉼표로). 비면 처음 들어올 때 하나 만든다
  'sfpc_contest_set'  => '',                     // 도전하기(본 대회) 묶음 번호
  'sfpc_contest_from' => '',                     // 본 대회 시작 · 끝 (YYYY-MM-DD HH:MM)
  'sfpc_contest_to'   => '',
  'sfpc_freeze_min'   => '60',                   // 스코어보드를 끝나기 몇 분 전부터 얼릴지. 0 이면 얼리지 않는다
  'sfpc_board_final'  => '0',                    // 1 이면 얼린 것을 풀고 최종 결과를 보여 준다
  'sfpc_ready_open'   => '0',                    // 준비하기 공개
  'sfpc_menu_on'      => '1',                    // 위쪽 메뉴에 SFPC 보이기
  'sfpc_landing_on'   => '1',                    // 접수 전 · 접수 중에 첫 화면을 SFPC 안내로 (sfpc_landing.php)
  'sfpc_account_open' => '0',                    // 지도교사의 "내 팀" 화면에 팀 계정 보이기 (계정을 나눠 주는 날 켠다)
  'sfpc_contest_open' => '0',                    // 대회 참가 화면 공개
  'sfpc_board_open'   => '0',                    // 스코어보드 공개 (우리가 만든 것. 2026 은 바깥 스코어보드를 쓰므로 꺼 둔다)
  'sfpc_board_url'    => '',                     // 바깥 스코어보드의 주소. 있으면 "스코어보드"가 그리로 간다
  'sfpc_first_solve'  => '0',                    // 우리 화면의 퍼솔 알림. 바깥 스코어보드가 맡으면 꺼 둔다
  'sfpc_practice_open' => '0',                   // 연습(아무 때나 시작하는 모의 대회) 공개 — 준비하기가 끝난 뒤에 연다
  'sfpc_feed_key'     => '',                     // 스코어보드 서버가 자료를 받아 갈 때 쓰는 비밀 글자열의 sha256 (글자열 자체는 저장하지 않는다)
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

require_once __DIR__ . '/sfpc_mail.php';   /* 메일 보내기 · 지도교사 이메일 인증 */

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
  /* 7 — 공부하기. 팀이 수료한 때를 남기고, 공부하기는 열어 둔다. */
  if (!in_array('study_done_at', array_column(db()->query("PRAGMA table_info(sfpc_teams)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
    db()->exec("ALTER TABLE sfpc_teams ADD COLUMN study_done_at TEXT NOT NULL DEFAULT ''");
    setting_set('sfpc_study_open', '1');
  }
  /* 8 — 연타 기록. 보스 하나에 두 판(round 1 · 2). 시작할 때 줄을 만들고 끝나면 횟수를 적는다.
         removed_at 이 있으면 운영진이 지운 기록이다 (순위에서 빠지고, 되돌릴 수 있다). */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_mash(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contest TEXT NOT NULL, team_id INTEGER NOT NULL, boss INTEGER NOT NULL, round INTEGER NOT NULL,
    cnt INTEGER NOT NULL DEFAULT 0, started_at TEXT NOT NULL, ended_at TEXT NOT NULL DEFAULT '', removed_at TEXT NOT NULL DEFAULT '',
    UNIQUE(contest, team_id, boss, round))");
  /* 9 — 보스는 한 판. skip = 1 은 "넘어가기"를 누른 보스 (0점, 길은 열린다. 나중에 도전해 6번 이상 기록하면 0 으로 돌아간다).
         오프라인(대회장) 참가는 개최 지역의 학생 팀이 고른 경우뿐이다 — 교사 · 예비교사 팀은 옵저버이고 언제나 온라인. */
  if (!in_array('skip', array_column(db()->query("PRAGMA table_info(sfpc_mash)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
    db()->exec("ALTER TABLE sfpc_mash ADD COLUMN skip INTEGER NOT NULL DEFAULT 0");
    db()->exec("DELETE FROM sfpc_mash WHERE round > 1");
    db()->exec("UPDATE sfpc_teams SET onsite = 0 WHERE kind = 'teacher'");
    db()->exec("UPDATE sfpc_members SET shirt = '' WHERE team_id IN (SELECT id FROM sfpc_teams WHERE kind = 'teacher')");
  }
  /* 10 — 문의. 팀 계정 · 지도교사가 글을 남기면 운영진이 답한다 (sfpc_ask.php).
          kind 'ops' 운영 문의 → 교육팀, 'prob' 문제 문의 → 문항 제작팀 (admin 은 둘 다).
          last_by 가 'user' 면 답을 기다리는 글, 'staff' 면 운영진이 마지막으로 쓴 글이다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_qna(
    id INTEGER PRIMARY KEY AUTOINCREMENT, contest TEXT NOT NULL, kind TEXT NOT NULL,
    user_id INTEGER NOT NULL, team_id INTEGER, coach_user_id INTEGER, problem_id INTEGER, prob_label TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL, closed INTEGER NOT NULL DEFAULT 0, last_by TEXT NOT NULL DEFAULT 'user',
    user_seen_at TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_qna ON sfpc_qna(contest, last_by, kind)");
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_qna_msg(
    id INTEGER PRIMARY KEY AUTOINCREMENT, qna_id INTEGER NOT NULL, user_id INTEGER, by_staff INTEGER NOT NULL DEFAULT 0,
    author TEXT NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_qna_msg ON sfpc_qna_msg(qna_id, id)");
  /* 11 — 준비하기의 한 판. 시작할 때 줄이 생기고 ends_at 에 저절로 끝난다. ended_at 이 있으면 그 전에 포기한 판이다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_runs(
    id INTEGER PRIMARY KEY AUTOINCREMENT, contest TEXT NOT NULL, team_id INTEGER NOT NULL, set_id INTEGER NOT NULL,
    started_at TEXT NOT NULL, ends_at TEXT NOT NULL, ended_at TEXT NOT NULL DEFAULT '')");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_runs ON sfpc_runs(contest, team_id, started_at)");
  /* 12 — 재채점 묶음과 알림.
          재채점: 다시 채점하는 동안에도 순위는 예전 판정으로 센다 (item.open = 1 인 제출은 old_* 를 쓴다).
                  운영진이 "적용"하면 새 판정이 순위에 들어가고, "취소"하면 예전 판정으로 되돌린다.
          알림(sfpc_events): 'first' 문제를 가장 먼저 맞힌 팀, 'notice' 운영진이 보낸 전체 알림. 화면이 주기적으로 물어 가서 띄운다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_rejudge(
    id INTEGER PRIMARY KEY AUTOINCREMENT, contest TEXT NOT NULL, label TEXT NOT NULL, set_id INTEGER NOT NULL,
    auto_apply INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'running', by_user INTEGER, created_at TEXT NOT NULL, closed_at TEXT NOT NULL DEFAULT '')");
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_rejudge_item(
    id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER NOT NULL, submission_id INTEGER NOT NULL, open INTEGER NOT NULL DEFAULT 1,
    old_state TEXT, old_verdict TEXT, old_passed INTEGER, old_total INTEGER, old_time REAL, old_memory INTEGER, old_judged_at TEXT)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_rj_sub ON sfpc_rejudge_item(submission_id, open)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_rj_batch ON sfpc_rejudge_item(batch_id)");
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_events(
    id INTEGER PRIMARY KEY AUTOINCREMENT, contest TEXT NOT NULL, kind TEXT NOT NULL, team_id INTEGER, problem_id INTEGER,
    text TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_events ON sfpc_events(contest, id)");
  /* 13 — 대회 여러 개(준비하기 네 번 + 도전하기 한 번)와 바깥 스코어보드에 내줄 채점 기록.
          sfpc_rounds: 대회 하나. id 가 바깥에 알려 주는 "대회 ID". kind 'ready' 준비하기 · 'main' 도전하기.
          sfpc_judgements: 판정이 날 때마다 한 줄. 재채점으로 판정이 다시 나면 새 줄을 더하고 옛 줄은 valid = 0.
          그동안의 "아무 때나 시작하는 준비하기"는 "연습"이 되었다 (sfpc_practice.php, sfpc_ready_sets · sfpc_runs 그대로). */
  if (!db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sfpc_rounds'")->fetchColumn()) {
    db()->exec("CREATE TABLE sfpc_rounds(
      id INTEGER PRIMARY KEY AUTOINCREMENT, contest TEXT NOT NULL, kind TEXT NOT NULL, title TEXT NOT NULL, set_id INTEGER NOT NULL,
      from_at TEXT NOT NULL DEFAULT '', to_at TEXT NOT NULL DEFAULT '', freeze_min INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0)");
    $mk = function (string $title, string $type) {
      db()->prepare("INSERT INTO sets(set_type, title, start_at, end_at, active, langs, show_diff, visibility, no_paste, created_at) VALUES(?,?,NULL,NULL,1,?,0,'all',0,?)")
          ->execute([$type, $title, implode(',', array_keys(langs())), now()]);
      return (int)db()->lastInsertId();
    };
    $ins = db()->prepare("INSERT INTO sfpc_rounds(contest, kind, title, set_id, from_at, to_at, freeze_min, sort) VALUES(?,?,?,?,?,?,?,?)");
    for ($i = 1; $i <= 4; $i++) $ins->execute([SFPC_CONTEST, 'ready', '준비하기 ' . $i . '회', $mk('SFPC 준비하기 ' . $i . '회', 'assessment'), '', '', 0, $i]);
    $main = (int)sfpc_setting('sfpc_contest_set');
    if (!$main || !one("SELECT id FROM sets WHERE id = ?", [$main])) $main = $mk('SFPC 도전하기', 'assessment');
    $ins->execute([SFPC_CONTEST, 'main', '도전하기', $main, sfpc_setting('sfpc_contest_from'), sfpc_setting('sfpc_contest_to'), (int)sfpc_setting('sfpc_freeze_min'), 9]);
    foreach (array_filter(array_map('intval', explode(',', sfpc_setting('sfpc_ready_sets')))) as $i => $sid)
      db()->prepare("UPDATE sets SET title = ? WHERE id = ? AND title = ?")->execute(['SFPC 연습 ' . ($i + 1), $sid, 'SFPC 준비하기 ' . ($i + 1)]);
    setting_set('sfpc_board_open', '0');
  }
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_judgements(
    id INTEGER PRIMARY KEY AUTOINCREMENT, round_id INTEGER NOT NULL, submission_id INTEGER NOT NULL, verdict TEXT NOT NULL,
    valid INTEGER NOT NULL DEFAULT 1, end_at TEXT NOT NULL, src_judged_at TEXT NOT NULL DEFAULT '')");
  db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sfpc_jud_valid ON sfpc_judgements(submission_id) WHERE valid = 1");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_jud_round ON sfpc_judgements(round_id, id)");
  /* 14 · 16 — 지도교사 이메일 인증, 비밀번호 다시 정하기 (sfpc_mail.php). 인증한 주소 · 인증번호 · 보낸 메일 기록. */
  sfpc_mail_schema();
  /* 15 — 계정마다 처음 · 마지막으로 들어온 때 (sfpc_seen_touch). "한 번이라도 로그인한 팀인가" · "지금 누가 들어와 있나"에 쓴다. */
  db()->exec("CREATE TABLE IF NOT EXISTS sfpc_seen(
    user_id INTEGER PRIMARY KEY, first_at TEXT NOT NULL, last_at TEXT NOT NULL, page TEXT NOT NULL DEFAULT '')");
  db()->exec("CREATE INDEX IF NOT EXISTS idx_sfpc_seen_last ON sfpc_seen(last_at)");
  /* 학교 목록이 비어 있으면 함께 넣어 둔 목록으로 채운다 — 설치만 하면 학교 찾기가 바로 된다 */
  if ((int)db()->query("SELECT COUNT(*) FROM sfpc_schools")->fetchColumn() === 0 && sfpc_schools_load_bundled() > 0) {
    setting_set('sfpc_schools_at', now());
  }
  setting_set('sfpc_schema', (string)SFPC_SCHEMA);
}

/* ── 누가 들어와 있나 ──────────────────────────────
   로그인한 계정이 화면을 열 때마다 "마지막으로 본 때"와 "무슨 화면"을 적는다 (sfpc_seen). 처음 적힌 때가 곧 첫 로그인이다.
   요청마다 DB 에 쓰면 대회 중에 부담이 되므로 한 계정에 30초에 한 번만 쓴다 (화면이 바뀌면 3초 뒤부터).
   주기적으로 묻는 요청(알림 확인 등)은 시각만 고치고 "무슨 화면"은 건드리지 않는다. 주소 · 내용은 적지 않고 화면 이름만 남긴다. */
const SFPC_SEEN_EVERY = 30;
const SFPC_SEEN_BG = ['sfpc_live', 'sfpc_feed', 'api'];
const SFPC_PAGE_NAME = ['index' => '첫 화면', 'sfpc' => '접수 안내', 'sfpc_landing' => '첫 화면', 'sfpc_join' => '내 정보', 'sfpc_verify' => '이메일 인증',
  'sfpc_teams' => '내 팀', 'sfpc_team' => '팀 신청 · 수정', 'sfpc_me' => '팀 화면', 'sfpc_char' => '캐릭터 꾸미기', 'sfpc_study' => '공부하기', 'sfpc_boss' => '공부하기 · 보스',
  'sfpc_ready' => '준비하기', 'sfpc_practice' => '연습', 'sfpc_contest' => '도전하기', 'sfpc_board' => '스코어보드', 'sfpc_rank' => '연타 순위', 'sfpc_ask' => '문의',
  'problem' => '문제 풀기', 'problems' => '문제 모음', 'results' => '채점 결과', 'notices' => '공지사항', 'notice' => '공지사항', 'password' => '비밀번호 바꾸기', 'user' => '내 계정', 'admin' => '운영 화면'];
function sfpc_seen_touch(): void {
  $uid = (int)($_SESSION['uid'] ?? 0);
  if (!$uid) return;
  $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $page = str_contains($sn, '/admin/') ? 'admin' : basename($sn, '.php');
  $bg = in_array($page, SFPC_SEEN_BG, true);
  $age = time() - (int)($_SESSION['sfpc_seen_t'] ?? 0);
  if ($age < 3 || ($age < SFPC_SEEN_EVERY && ($bg || $page === (string)($_SESSION['sfpc_seen_p'] ?? '')))) return;
  try {
    if ($bg) db()->prepare("INSERT INTO sfpc_seen(user_id, first_at, last_at, page) VALUES(?,?,?,'')
                            ON CONFLICT(user_id) DO UPDATE SET last_at = excluded.last_at")->execute([$uid, now(), now()]);
    else     db()->prepare("INSERT INTO sfpc_seen(user_id, first_at, last_at, page) VALUES(?,?,?,?)
                            ON CONFLICT(user_id) DO UPDATE SET last_at = excluded.last_at, page = excluded.page")->execute([$uid, now(), now(), substr($page, 0, 40)]);
    $_SESSION['sfpc_seen_t'] = time();
    if (!$bg) $_SESSION['sfpc_seen_p'] = $page;
  } catch (Throwable $e) { /* 기록을 못 남겨도 화면은 떠야 한다 (DB 가 바쁠 때) */ }
}
if (PHP_SAPI !== 'cli') sfpc_seen_touch();

/* 계정 종류: 팀 · 지도교사 · 운영진 · 회원 */
function sfpc_user_kind(array $u, bool $isCoach): string {
  if (($u['role'] ?? '') === 'admin') return '운영진';
  if (($u['source'] ?? '') === SFPC_ACCOUNT_SOURCE) return '팀';
  return $isCoach ? '지도교사' : '회원';
}
/* 최근 $sec 초 안에 들어온 계정 (가장 최근 것부터). 모니터링의 "접속 중" 명단. */
function sfpc_online(int $sec = 1800, int $limit = 400): array {
  $rows = all("SELECT s.user_id, s.last_at, s.page, u.login_id, u.name, u.role, u.source,
                      t.team_name, t.school_name, t.id AS team_id, (c.user_id IS NOT NULL) AS is_coach
               FROM sfpc_seen s JOIN users u ON u.id = s.user_id
               LEFT JOIN sfpc_teams t ON t.account_user_id = u.id AND t.contest = ?
               LEFT JOIN sfpc_coaches c ON c.user_id = u.id
               WHERE s.last_at >= ? ORDER BY s.last_at DESC LIMIT " . (int)$limit, [SFPC_CONTEST, date('Y-m-d H:i:s', time() - $sec)]);
  $out = [];
  foreach ($rows as $r) {
    $kind = sfpc_user_kind($r, (bool)$r['is_coach']);
    $out[] = ['login' => $r['login_id'], 'kind' => $kind,
              'who' => $kind === '팀' && $r['team_name'] !== null ? $r['team_name'] . ' · ' . $r['school_name'] : $r['name'],
              'page' => SFPC_PAGE_NAME[$r['page']] ?? ($r['page'] !== '' ? $r['page'] : ''), 'ago' => max(0, time() - strtotime($r['last_at']))];
  }
  return $out;
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
                       'admin' => '운영진 조정', 'coach' => '지도교사 정보', 'account' => '팀 계정', 'study' => '공부하기'];
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
  if (isset($new['onsite'])) $pair('참가 방식', sfpc_way_name($old), sfpc_way_name($new));
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
/* 온라인 / 오프라인을 고를 수 있는 팀인지 — 개최 지역의 학생 팀만. 나머지는 언제나 온라인이고 티셔츠도 없다. */
function sfpc_can_onsite(string $kind, string $region): bool { return $kind === 'student' && sfpc_is_onsite($region); }
function sfpc_way_name(array $t): string { return (int)($t['onsite'] ?? 0) ? '오프라인(대회장)' : '온라인'; }
/* 티셔츠 조사가 마감되었는지 */
function sfpc_shirt_closed(): bool { $d = sfpc_ts(sfpc_setting('sfpc_shirt_due')); return $d > 0 && time() > $d + 59; }
/* 교사 · 예비교사 팀은 옵저버 — 대회에 참가하지만 어떤 순위에도 나오지 않는다 */
function sfpc_is_observer_team(array $t): bool { return ($t['kind'] ?? '') === 'teacher'; }
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
/* 기존 화면을 막고, 위쪽 메뉴에서 불 켤 항목을 돌려준다.
   공부하기의 문제는 기존 채점 화면(problem.php?set=…)을 그대로 쓰므로, 공부하기 묶음 안에서는 통과시킨다. */
function sfpc_site_gate(?array $u, string $nav, string $root = ''): string {
  if (!sfpc_site()) return $nav;
  $page  = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  $study = (int)sfpc_setting('sfpc_study_set');
  $inStudy = $study > 0 && (int)($_GET['set'] ?? 0) === $study && $page === 'problem.php';
  if ($inStudy) $nav = 'sfpc_study';
  /* 준비하기 · 도전하기의 문제 — 판이 돌아가는 동안(대회 중)에만 열린다 */
  $stage = $page === 'problem.php' ? sfpc_set_stage((int)($_GET['set'] ?? 0)) : '';
  if (in_array($stage, ['sfpc_ready', 'sfpc_contest', 'sfpc_practice'], true)) {
    if (is_admin($u)) return $stage;
    $team = sfpc_my_team($u);
    if ($stage === 'sfpc_practice') {
      $run = ($team && sfpc_setting('sfpc_practice_open') === '1') ? sfpc_run_active((int)$team['id']) : null;
      if (!$run || (int)$run['set_id'] !== (int)$_GET['set']) { header('Location: ' . $root . 'sfpc_practice.php'); exit; }
    } else {
      $home = $stage === 'sfpc_ready' ? 'sfpc_ready.php' : 'sfpc_contest.php'; $sw = $stage === 'sfpc_ready' ? 'sfpc_ready_open' : 'sfpc_contest_open';
      if (!$team || $team['status'] !== 'submitted' || sfpc_setting($sw) !== '1' || sfpc_round_time(sfpc_round_by_set((int)$_GET['set']))['state'] !== 'open') { header('Location: ' . $root . $home); exit; }
    }
    return $stage;
  }
  if (is_admin($u)) return $nav;
  if ($inStudy) {
    if (sfpc_setting('sfpc_study_open') !== '1') { header('Location: ' . $root . 'sfpc_study.php'); exit; }
    $team = sfpc_my_team($u);                               /* 앞 월드의 보스를 지나지 않았으면 그 문제는 아직 못 연다 */
    if ($team) foreach (sfpc_study_problems() as $i => $p) {
      if ((int)$p['prob_no'] === (int)($_GET['no'] ?? 0) && !sfpc_world_open((int)$team['id'], $i)) { header('Location: ' . $root . 'sfpc_study.php'); exit; }
    }
    return $nav;
  }
  if ($page === 'set.php' && $study > 0 && (int)($_GET['id'] ?? 0) === $study) { header('Location: ' . $root . 'sfpc_study.php'); exit; }
  if ($page === 'results.php' && $u && (int)($_GET['id'] ?? 0) > 0                      /* 자기 제출 하나를 다시 보는 것은 된다 */
      && (int)col("SELECT user_id FROM submissions WHERE id = ?", [(int)$_GET['id']]) === (int)$u['id']) return 'sfpc_study';
  if (in_array($nav, SFPC_STAFF_NAV, true) || $page === 'user.php') { header('Location: ' . $root . 'index.php'); exit; }
  return $nav;
}

/* 연습용 기초 문제 10개 — 지도 · 수료 · 연타를 시험하려고 넣는다. 만든 문제 수를 돌려준다.
   문제는 잠긴 채(문제 모음에 안 보임) 만들고 공부하기 묶음에 담는다. */
function sfpc_demo_problems(): int {
  $P = [
    ['Hello, SFPC', '화면에 `Hello, SFPC!` 를 출력하세요.', '입력은 없습니다.', '`Hello, SFPC!` 를 한 줄에 출력합니다.', [['', 'Hello, SFPC!']]],
    ['두 줄 출력', '첫 줄에 `SFPC`, 둘째 줄에 `2026` 을 출력하세요.', '입력은 없습니다.', '두 줄을 출력합니다.', [['', "SFPC\n2026"]]],
    ['그대로 따라 말하기', '한 줄을 입력받아 그대로 출력하세요.', '한 줄의 글이 주어집니다. (길이 100 이하)', '입력받은 줄을 그대로 출력합니다.', [['hello', 'hello'], ['chungbuk 2026', 'chungbuk 2026'], ['a', 'a']]],
    ['두 수 더하기', '두 정수 a, b 를 입력받아 a + b 를 출력하세요.', '한 줄에 정수 a, b 가 빈칸으로 나뉘어 주어집니다. (-1000 ≤ a, b ≤ 1000)', 'a + b 를 출력합니다.', [['3 5', '8'], ['-7 2', '-5'], ['1000 1000', '2000'], ['0 0', '0']]],
    ['몫과 나머지', '두 자연수 a, b 를 입력받아 a 를 b 로 나눈 몫과 나머지를 출력하세요.', '한 줄에 자연수 a, b 가 주어집니다. (1 ≤ a, b ≤ 10000)', '첫 줄에 몫, 둘째 줄에 나머지를 출력합니다.', [['7 3', "2\n1"], ['10 5', "2\n0"], ['3 7', "0\n3"], ['9999 10', "999\n9"]]],
    ['짝수와 홀수', '정수 n 이 짝수이면 `even`, 홀수이면 `odd` 를 출력하세요.', '정수 n 이 주어집니다. (0 ≤ n ≤ 100000)', '`even` 또는 `odd` 를 출력합니다.', [['4', 'even'], ['7', 'odd'], ['0', 'even'], ['99999', 'odd']]],
    ['더 큰 수', '두 정수 중 더 큰 수를 출력하세요. 같으면 그 수를 출력합니다.', '한 줄에 정수 a, b 가 주어집니다. (-1000 ≤ a, b ≤ 1000)', '더 큰 수를 출력합니다.', [['3 9', '9'], ['10 -2', '10'], ['5 5', '5'], ['-8 -3', '-3']]],
    ['1부터 N까지의 합', '1 부터 n 까지의 합을 출력하세요.', '자연수 n 이 주어집니다. (1 ≤ n ≤ 10000)', '합을 출력합니다.', [['10', '55'], ['1', '1'], ['100', '5050'], ['10000', '50005000']]],
    ['구구단', 'n 단을 1 부터 9 까지 출력하세요.', '자연수 n 이 주어집니다. (1 ≤ n ≤ 9)', '`n * i = 값` 꼴로 아홉 줄을 출력합니다.', [['2', "2 * 1 = 2\n2 * 2 = 4\n2 * 3 = 6\n2 * 4 = 8\n2 * 5 = 10\n2 * 6 = 12\n2 * 7 = 14\n2 * 8 = 16\n2 * 9 = 18"], ['9', "9 * 1 = 9\n9 * 2 = 18\n9 * 3 = 27\n9 * 4 = 36\n9 * 5 = 45\n9 * 6 = 54\n9 * 7 = 63\n9 * 8 = 72\n9 * 9 = 81"]]],
    ['거꾸로 출력', '낱말 하나를 입력받아 거꾸로 뒤집어 출력하세요.', '영어 소문자로 된 낱말이 주어집니다. (길이 1 이상 100 이하)', '뒤집은 낱말을 출력합니다.', [['sfpc', 'cpfs'], ['a', 'a'], ['level', 'level'], ['chungbuk', 'kubgnuhc']]],
  ];
  $set = sfpc_study_set();
  return (int)tx(function (PDO $d) use ($P, $set) {
    $no = max(1000, (int)$d->query("SELECT COALESCE(MAX(prob_no), 0) FROM problems")->fetchColumn());
    foreach ($P as $i => [$title, $desc, $in, $out, $tcs]) {
      $d->prepare("INSERT INTO problems(prob_no, title, description, input_desc, output_desc, time_limit, memory_limit, active, show_diff, created_at) VALUES(?,?,?,?,?,2.0,128000,0,1,?)")
        ->execute([++$no, $title, $desc, $in, $out, now()]);
      $pid = (int)$d->lastInsertId();
      foreach ($tcs as $k => [$tin, $texp])
        $d->prepare("INSERT INTO testcases(problem_id, seq, input, expected, is_sample) VALUES(?,?,?,?,?)")
          ->execute([$pid, $k + 1, str_replace('\\n', "\n", $tin), str_replace('\\n', "\n", $texp), $k === 0 ? 1 : 0]);
      $d->prepare("INSERT INTO set_problems(set_id, problem_id, required_ac, sort) VALUES(?,?,1,?)")->execute([$set['id'], $pid, $i + 1]);
    }
    return count($P);
  });
}

/* ── 단계 띠 ──────────────────────────────────────
   공부하기(와 그 안의 문제 풀이 화면)에서는 공용 메뉴 대신 이 띠가 나온다. 그려 넣었으면 true.
   운영진이 교사 화면으로 볼 때는 공용 메뉴를 그대로 둔다 (수정 · 테스트케이스 버튼을 써야 하므로). */
function sfpc_stage_bar(?array $u, string $nav, string $root = ''): bool {
  if (sfpc_site() && in_array($nav, ['sfpc_ready', 'sfpc_contest', 'sfpc_board', 'sfpc_practice'], true)) return sfpc_arena_bar($u, $nav, $root);
  if (!sfpc_site() || $nav !== 'sfpc_study' || !$u) return false;
  $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if (!in_array($page, ['sfpc_study.php', 'sfpc_boss.php', 'sfpc_rank.php', 'sfpc_grad.php', 'problem.php'], true)) return false;
  if (is_admin($u) && function_exists('teacher_view') && teacher_view($u) && $page === 'problem.php') return false;
  $set = sfpc_study_set(false); $probs = sfpc_study_problems();
  $team = sfpc_my_team($u);
  $need = array_diff(array_map('intval', array_column($probs, 'id')), sfpc_study_skip());
  $got  = count(array_intersect($need, sfpc_study_solved((int)$u['id'])));
  sfpc_css($root);
  echo '<header class="sfpc-bar"><div class="in">';
  if ($page === 'problem.php') {
    $no = (int)($_GET['no'] ?? 0); $idx = -1;
    foreach ($probs as $i => $p) if ((int)$p['prob_no'] === $no) $idx = $i;
    $link = fn(int $i) => $root . 'problem.php?no=' . (int)$probs[$i]['prob_no'] . '&amp;set=' . (int)$set['id'];
    echo '<a class="back" href="' . h($root) . 'sfpc_study.php">← 지도로</a><b>공부하기' . ($idx >= 0 ? ' · ' . ($idx + 1) . '번' : '') . '</b>'
       . '<span class="pill">' . $got . ' / ' . count($need) . '</span><span class="g"></span>'
       . ($idx >= 0 && !is_admin($u) ? '<a class="ask" href="' . h($root) . 'sfpc_ask.php?new=prob&amp;no=' . $no . '">이 문제 질문하기</a>' : '')
       . ($idx > 0 ? '<a href="' . $link($idx - 1) . '">◀ ' . $idx . '번</a>' : '')
       . ($idx >= 0 && $idx < count($probs) - 1 ? '<a href="' . $link($idx + 1) . '">' . ($idx + 2) . '번 ▶</a>' : '');
  } else {
    $on = fn(string $f) => $page === $f ? ' class="on"' : '';
    echo '<a class="back" href="' . h($root) . 'index.php">← SFPC</a><b>공부하기</b>'
       . '<span class="pill">' . $got . ' / ' . count($need) . '</span><span class="g"></span>'
       . '<a' . $on('sfpc_study.php') . ' href="' . h($root) . 'sfpc_study.php">지도</a>'
       . '<a href="' . h($root) . 'sfpc_study.php?view=list">목록</a>'
       . '<a' . $on('sfpc_rank.php') . ' href="' . h($root) . 'sfpc_rank.php">연타 순위</a>'
       . '<a href="' . h($root) . 'sfpc_ask.php">문의' . sfpc_qna_dot($u) . '</a>';
  }
  echo '<a class="me" href="' . h($root . ($team ? 'sfpc_me.php' : (is_admin($u) ? 'admin/sfpc.php?tab=study' : 'sfpc_teams.php'))) . '">'
     . h($team ? $team['team_name'] : ($u['name'] !== '' ? $u['name'] : $u['login_id'])) . '</a>'
     . '<a href="' . h($root) . 'logout.php">로그아웃</a></div></header>';
  sfpc_live_script($u, $root);
  return true;
}

/* ── 준비하기 · 도전하기 · 스코어보드 ─────────────
   문제와 채점은 KAIT-CLASS 의 묶음을 그대로 쓴다. 여기서는 "언제부터 언제까지 낸 제출을 셀지"만 정한다.
   · 준비하기: 팀이 시작을 누르면 그때부터 sfpc_ready_min 분. 하루 sfpc_ready_per_day 판, 자정에 다시 찬다. 순위표는 없다.
   · 도전하기: 모든 팀이 같은 시각에 시작해 같은 시각에 끝난다 (묶음의 시작 · 끝과 맞춘다).
   · 점수는 ICPC 방식 — 맞힌 문제 수가 많은 팀이 위, 같으면 벌점이 적은 팀이 위.
     벌점 = 맞힌 문제마다 (시작부터 맞힐 때까지의 분 + 그 전에 틀린 횟수 × 20). 못 맞힌 문제의 오답은 세지 않는다.
     컴파일 오류(CE)와 채점기 오류(IE)는 오답으로 세지 않는다. */
const SFPC_WRONG_MIN = 20;
function sfpc_arena_set(string $key, string $title, string $type): array {
  $id = (int)sfpc_setting($key);
  $s  = $id ? one("SELECT * FROM sets WHERE id = ?", [$id]) : null;
  if ($s) return $s;
  db()->prepare("INSERT INTO sets(set_type, title, start_at, end_at, active, langs, show_diff, visibility, no_paste, created_at) VALUES(?,?,NULL,NULL,1,?,0,'all',0,?)")
      ->execute([$type, $title, implode(',', array_keys(langs())), now()]);
  $id = (int)db()->lastInsertId();
  setting_set($key, (string)$id);
  return one("SELECT * FROM sets WHERE id = ?", [$id]);
}
function sfpc_set_problems(int $setId): array {
  return all("SELECT p.id, p.prob_no, p.title FROM set_problems sp JOIN problems p ON p.id = sp.problem_id WHERE sp.set_id = ? ORDER BY sp.sort, sp.id", [$setId]);
}
function sfpc_letter(int $i): string { return $i < 26 ? chr(65 + $i) : chr(65 + intdiv($i, 26) - 1) . chr(65 + $i % 26); }
/* 준비하기의 문제 세트들. 하나도 없으면 첫 세트를 만든다. */
function sfpc_ready_sets(): array {
  $out = [];
  foreach (array_filter(array_map('intval', explode(',', sfpc_setting('sfpc_ready_sets')))) as $id) {
    $s = one("SELECT * FROM sets WHERE id = ?", [$id]); if ($s) $out[$id] = $s;
  }
  if (!$out) {
    db()->prepare("INSERT INTO sets(set_type, title, start_at, end_at, active, langs, show_diff, visibility, no_paste, created_at) VALUES('lesson',?,NULL,NULL,1,?,0,'all',0,?)")
        ->execute(['SFPC 연습 1', implode(',', array_keys(langs())), now()]);
    $id = (int)db()->lastInsertId(); setting_set('sfpc_ready_sets', (string)$id);
    $out[$id] = one("SELECT * FROM sets WHERE id = ?", [$id]);
  }
  return $out;
}
function sfpc_ready_min(): int { return max(10, min(300, (int)sfpc_setting('sfpc_ready_min'))); }
function sfpc_ready_per_day(): int { return max(1, min(20, (int)sfpc_setting('sfpc_ready_per_day'))); }
function sfpc_run_end(array $r): string { return $r['ended_at'] !== '' ? $r['ended_at'] : $r['ends_at']; }
/* 지금 하고 있는 판 (없으면 null) */
function sfpc_run_active(int $teamId): ?array {
  return one("SELECT * FROM sfpc_runs WHERE contest = ? AND team_id = ? AND ended_at = '' AND ends_at > ? ORDER BY id DESC", [SFPC_CONTEST, $teamId, now()]);
}
function sfpc_runs_today(int $teamId): int {
  return (int)col("SELECT COUNT(*) FROM sfpc_runs WHERE contest = ? AND team_id = ? AND started_at >= ?", [SFPC_CONTEST, $teamId, date('Y-m-d') . ' 00:00:00']);
}
/* ── 대회(회차) ── 준비하기 네 번과 도전하기 한 번. 모든 팀이 같은 시각에 시작해 같은 시각에 끝난다. */
function sfpc_rounds(string $kind = ''): array {
  static $memo = null;
  if ($memo === null) $memo = all("SELECT * FROM sfpc_rounds WHERE contest = ? ORDER BY sort, id", [SFPC_CONTEST]);
  return $kind === '' ? $memo : array_values(array_filter($memo, fn($r) => $r['kind'] === $kind));
}
function sfpc_round(int $id): ?array { foreach (sfpc_rounds() as $r) if ((int)$r['id'] === $id) return $r; return null; }
function sfpc_round_by_set(int $setId): ?array { foreach (sfpc_rounds() as $r) if ((int)$r['set_id'] === $setId) return $r; return null; }
function sfpc_main_round(): ?array { return sfpc_rounds('main')[0] ?? null; }
function sfpc_main_set(): int { return (int)(sfpc_main_round()['set_id'] ?? 0); }
/* 한 대회의 때. ['from' => ts, 'to' => ts, 'state' => 'unset'|'before'|'open'|'after', 'freeze' => ts(0 이면 얼리지 않음)] */
function sfpc_round_time(?array $r): array {
  $f = $r ? sfpc_ts($r['from_at']) : 0; $t = $r ? sfpc_ts($r['to_at']) : 0; $now = time();
  if (!$f || !$t || $t <= $f) return ['from' => 0, 'to' => 0, 'state' => 'unset', 'freeze' => 0];
  $fm = max(0, (int)$r['freeze_min']);
  return ['from' => $f, 'to' => $t, 'state' => $now < $f ? 'before' : ($now >= $t ? 'after' : 'open'), 'freeze' => $fm > 0 ? max($f, $t - $fm * 60) : 0];
}
/* 그 종류에서 지금 보여 줄 대회 — 열려 있는 것, 없으면 가장 가까운 다음 것, 그것도 없으면 마지막으로 끝난 것 */
function sfpc_round_current(string $kind): ?array {
  $rs = sfpc_rounds($kind); $next = null; $last = null;
  foreach ($rs as $r) {
    $t = sfpc_round_time($r);
    if ($t['state'] === 'open') return $r;
    if ($t['state'] === 'before' && (!$next || $t['from'] < sfpc_round_time($next)['from'])) $next = $r;
    if ($t['state'] === 'after' && (!$last || $t['to'] > sfpc_round_time($last)['to'])) $last = $r;
  }
  return $next ?? $last ?? ($rs[0] ?? null);
}
/* 본 대회(도전하기)의 때 */
function sfpc_contest_time(): array { return sfpc_round_time(sfpc_main_round()); }
/* 스코어보드가 지금 얼어 있는지 — 얼리는 때가 지났고 아직 최종 결과를 공개하지 않았을 때 */
function sfpc_board_frozen(): bool {
  $c = sfpc_contest_time();
  return $c['freeze'] > 0 && time() >= $c['freeze'] && sfpc_setting('sfpc_board_final') !== '1';
}
/* ICPC 점수. $from ~ $to (초) 사이에 그 묶음에 낸 제출만 센다. $hideFrom 이 있으면 그때부터의 제출은 결과를 감춘다(얼림).
   돌려주는 값: [계정 번호 => ['solved', 'penalty', 'cells' => [문제 번호 => ['ok', 'tries', 'min', 'pend', 'first']]]]
   $userIds 를 주면 그 계정만. */
function sfpc_icpc(int $setId, int $from, int $to, int $hideFrom = 0, ?array $userIds = null): array {
  $w = "s.set_id = ? AND s.created_at >= ? AND s.created_at < ?"; $a = [$setId, date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)];
  if ($userIds !== null) { if (!$userIds) return []; $w .= " AND s.user_id IN (" . implode(',', array_map('intval', $userIds)) . ")"; }
  $out = []; $first = [];
  /* 재채점 묶음에 들어 있고 아직 적용하지 않은 제출은 예전 판정으로 센다 */
  foreach (all("SELECT s.id, s.user_id, s.problem_id, s.created_at,
                       CASE WHEN ri.id IS NULL THEN s.state ELSE ri.old_state END AS state,
                       CASE WHEN ri.id IS NULL THEN s.verdict ELSE ri.old_verdict END AS verdict
                FROM submissions s LEFT JOIN sfpc_rejudge_item ri ON ri.submission_id = s.id AND ri.open = 1
                WHERE $w ORDER BY s.created_at, s.id", $a) as $r) {
    $uid = (int)$r['user_id']; $pid = (int)$r['problem_id']; $ts = strtotime($r['created_at']);
    $c = &$out[$uid]['cells'][$pid];
    if ($c === null) $c = ['ok' => false, 'tries' => 0, 'min' => 0, 'pend' => 0, 'first' => false];
    if ($c['ok']) { unset($c); continue; }
    if (($hideFrom > 0 && $ts >= $hideFrom) || !in_array($r['state'], ['done', 'error'], true)) { $c['pend']++; unset($c); continue; }
    if ($r['verdict'] === 'AC') { $c['ok'] = true; $c['min'] = intdiv(max(0, $ts - $from), 60); if (!isset($first[$pid])) { $first[$pid] = true; $c['first'] = true; } }
    elseif (!in_array((string)$r['verdict'], ['CE', 'IE', ''], true)) $c['tries']++;
    unset($c);
  }
  foreach ($out as $uid => &$o) {
    $o['solved'] = 0; $o['penalty'] = 0;
    foreach ($o['cells'] as $c) if ($c['ok']) { $o['solved']++; $o['penalty'] += $c['min'] + SFPC_WRONG_MIN * $c['tries']; }
  }
  unset($o);
  return $out;
}
/* 한 계정의 점수 (제출이 없어도 빈 값으로) */
function sfpc_icpc_one(int $userId, int $setId, int $from, int $to): array {
  return sfpc_icpc($setId, $from, $to, 0, [$userId])[$userId] ?? ['solved' => 0, 'penalty' => 0, 'cells' => []];
}
/* 스코어보드에 놓을 줄들. $live 가 참이면 얼리지 않은 실제 점수 (운영진 확인용).
   · 학생 팀만 나온다. 교사 · 예비교사 팀(옵저버)은 대회에는 참가하지만 여기에는 나오지 않는다.
   · 정식참가자만 순위를 매긴다. 시범참가자는 순위 없이 아래에 따로 놓는다. */
function sfpc_board(bool $live = false): array {
  sfpc_rejudge_tick();
  $c = sfpc_contest_time(); $setId = sfpc_main_set();
  $out = ['c' => $c, 'probs' => $setId ? sfpc_set_problems($setId) : [], 'rows' => [], 'frozen' => false];
  if ($c['state'] === 'unset' || !$setId) return $out;
  $out['frozen'] = !$live && sfpc_board_frozen();
  $teams = all("SELECT * FROM sfpc_teams WHERE contest = ? AND status = 'submitted' AND kind = 'student' AND account_user_id > 0", [SFPC_CONTEST]);
  $sc = $c['state'] === 'before' ? [] : sfpc_icpc($setId, $c['from'], $c['to'], $out['frozen'] ? $c['freeze'] : 0, array_map(fn($t) => (int)$t['account_user_id'], $teams));
  foreach ($teams as $t) {
    $x = $sc[(int)$t['account_user_id']] ?? ['solved' => 0, 'penalty' => 0, 'cells' => []];
    $out['rows'][] = ['team' => $t, 'ranked' => $t['track'] === 'participant', 'solved' => $x['solved'], 'penalty' => $x['penalty'], 'cells' => $x['cells'], 'rank' => 0];
  }
  usort($out['rows'], fn($a, $b) => [$b['ranked'], $b['solved'], $a['penalty'], $a['team']['team_name']] <=> [$a['ranked'], $a['solved'], $b['penalty'], $b['team']['team_name']]);
  $rank = 0; $n = 0; $prev = null;
  foreach ($out['rows'] as &$r) {
    if (!$r['ranked']) continue;
    $n++; if ($prev === null || $prev !== [$r['solved'], $r['penalty']]) $rank = $n;
    $r['rank'] = $rank; $prev = [$r['solved'], $r['penalty']];
  }
  unset($r);
  return $out;
}
/* 이 묶음 화면(problem.php?set=…)이 어느 단계의 것인지: 'sfpc_study' | 'sfpc_ready' | 'sfpc_contest' | 'sfpc_practice' | '' */
function sfpc_set_stage(int $setId): string {
  if ($setId <= 0) return '';
  if ($setId === (int)sfpc_setting('sfpc_study_set')) return 'sfpc_study';
  $r = sfpc_round_by_set($setId);
  if ($r) return $r['kind'] === 'main' ? 'sfpc_contest' : 'sfpc_ready';
  if (in_array($setId, array_map('intval', explode(',', sfpc_setting('sfpc_ready_sets'))), true)) return 'sfpc_practice';
  return '';
}
const SFPC_STAGE_NAME = ['sfpc_study' => '공부하기', 'sfpc_ready' => '준비하기', 'sfpc_contest' => '도전하기', 'sfpc_practice' => '연습', 'sfpc_board' => '스코어보드'];
/* 새 공지가 있으면 깜빡이는 상자 + 그 자리에서 읽는 창 (공부하기 지도 · 준비하기 · 도전하기에 놓는다) */
function sfpc_notice_box(array $u, string $focusId = ''): void {
  $notices = all("SELECT id, title, body, pinned, created_at FROM notices WHERE active = 1 ORDER BY pinned DESC, id DESC LIMIT 8");
  $top = $notices ? max(array_map(fn($n) => (int)$n['id'], $notices)) : 0; ?>
  <button type="button" class="sfpc-nbox" id="nbox" data-top="<?= $top ?>" data-key="sfpc_notice_seen_<?= (int)$u['id'] ?>" data-focus="<?= h($focusId) ?>" aria-haspopup="dialog">
    <span class="ic" aria-hidden="true">!</span>
    <span class="tx"><b>공지사항</b><i id="nboxSub"><?= $notices ? h($notices[0]['title']) : '아직 공지가 없습니다' ?></i></span>
    <em id="nboxNew" hidden>새 공지를 확인하세요!</em>
  </button>
  <div class="sfpc-nmodal" id="nmodal" role="dialog" aria-modal="true" aria-labelledby="nmodalH" hidden>
    <div class="box">
      <div class="h"><b id="nmodalH">공지사항</b><button type="button" id="nmodalX" aria-label="닫기">닫기 ✕</button></div>
      <div class="list">
        <?php if (!$notices): ?><p class="none">아직 공지가 없습니다.</p><?php endif; ?>
        <?php foreach ($notices as $n): ?>
          <article data-id="<?= (int)$n['id'] ?>">
            <h3><?= !empty($n['pinned']) ? '<span>고정</span>' : '' ?><?= h($n['title']) ?></h3>
            <time><?= h(substr((string)$n['created_at'], 0, 16)) ?></time>
            <div class="md"><?= md((string)$n['body']) ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <script>
  (function () {
    'use strict';
    var box = document.getElementById('nbox'), modal = document.getElementById('nmodal');
    var top = parseInt(box.dataset.top, 10) || 0, key = box.dataset.key, seen = 0;
    try { seen = parseInt(localStorage.getItem(key) || '0', 10) || 0; } catch (e) {}
    function mark() {
      var fresh = top > seen; box.classList.toggle('new', fresh); document.getElementById('nboxNew').hidden = !fresh;
      modal.querySelectorAll('article').forEach(function (a) { a.classList.toggle('new', parseInt(a.dataset.id, 10) > seen); });
    }
    function close() { modal.hidden = true; seen = top; try { localStorage.setItem(key, String(top)); } catch (e) {} mark(); var c = box.dataset.focus && document.getElementById(box.dataset.focus); (c || box).focus(); }
    box.addEventListener('click', function () { modal.hidden = false; document.getElementById('nmodalX').focus(); });
    document.getElementById('nmodalX').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    mark();
  })();
  </script>
<?php }

/* 준비하기 · 도전하기 · 스코어보드의 띠 — 공용 메뉴 대신 나온다 (공부하기와 같은 모양).
   남은 시간과 문제 글자(A, B, C…)가 늘 보인다. 스코어보드는 방문자에게도 이 띠가 나온다. */
function sfpc_arena_bar(?array $u, string $nav, string $root): bool {
  $team = $u ? sfpc_my_team($u) : null; $admin = is_admin($u);
  $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  $home = ['sfpc_ready' => 'sfpc_ready.php', 'sfpc_contest' => 'sfpc_contest.php', 'sfpc_board' => 'sfpc_board.php', 'sfpc_practice' => 'sfpc_practice.php'][$nav];
  if (!in_array($page, [$home, 'problem.php'], true)) return false;
  if ($nav !== 'sfpc_board' && !$team && !$admin) return false;      /* 팀 계정이 아닌 사람에게는 안내와 공용 메뉴 */
  if ($admin && $page === 'problem.php' && function_exists('teacher_view') && teacher_view($u)) return false;   /* 교사 화면(수정 버튼)을 쓸 때는 공용 메뉴 */
  $setId = 0; $end = 0; $name = SFPC_STAGE_NAME[$nav];
  if ($nav === 'sfpc_practice') {
    $run = $team ? sfpc_run_active((int)$team['id']) : null;
    if ($run) { $setId = (int)$run['set_id']; $end = strtotime($run['ends_at']); }
    elseif ($admin && $page === 'problem.php') $setId = (int)($_GET['set'] ?? 0);
  } elseif ($nav === 'sfpc_board') {
    $c = sfpc_contest_time(); if ($c['state'] === 'open') $end = $c['to'];
  } else {                                                           /* 준비하기 · 도전하기 — 문제 화면이면 그 묶음의 대회, 아니면 지금의 대회 */
    $r = $page === 'problem.php' ? sfpc_round_by_set((int)($_GET['set'] ?? 0)) : (isset($_GET['r']) ? sfpc_round((int)$_GET['r']) : null);
    if (!$r || ($r['kind'] === 'main') !== ($nav === 'sfpc_contest')) $r = sfpc_round_current($nav === 'sfpc_contest' ? 'main' : 'ready');
    $c = sfpc_round_time($r);
    if ($c['state'] === 'open') $end = $c['to'];
    if ($r && ($c['state'] === 'open' || $admin)) $setId = (int)$r['set_id'];
    if ($r && $nav === 'sfpc_ready') $name = $r['title'];
  }
  sfpc_css($root);
  echo '<header class="sfpc-bar"><div class="in"><a class="back" href="' . h($root . ($page === 'problem.php' ? $home : 'index.php')) . '">← ' . ($page === 'problem.php' ? '문제 목록' : 'SFPC') . '</a>'
     . '<b>' . h($name) . '</b>';
  if ($end) echo '<span class="pill clock" id="sfClock" data-end="' . $end . '" data-now="' . time() . '">--:--</span>';
  echo '<span class="g"></span>';
  if ($setId) foreach (sfpc_set_problems($setId) as $i => $p)
    echo '<a class="ltr' . ($page === 'problem.php' && (int)($_GET['no'] ?? 0) === (int)$p['prob_no'] ? ' on' : '') . '" href="' . h($root) . 'problem.php?no=' . (int)$p['prob_no'] . '&amp;set=' . $setId . '" title="' . h($p['title']) . '">' . sfpc_letter($i) . '</a>';
  if (($nav === 'sfpc_contest' || $nav === 'sfpc_ready') && (sfpc_setting('sfpc_board_open') === '1' || sfpc_setting('sfpc_board_url') !== '' || $admin)) echo '<a href="' . h($root) . 'sfpc_board.php">스코어보드</a>';
  if ($nav === 'sfpc_board' && ($team || $admin)) echo '<a href="' . h($root) . 'sfpc_contest.php">도전하기</a>';
  if ($team || $admin) echo '<a href="' . h($root) . 'sfpc_ask.php' . ($team && $page === 'problem.php' ? '?new=prob' : '') . '">문의' . sfpc_qna_dot($u) . '</a>';
  if ($team)      echo '<a class="me" href="' . h($root) . 'sfpc_me.php">' . h($team['team_name']) . '</a>';
  elseif ($admin) echo '<a class="me" href="' . h($root) . 'admin/sfpc.php?tab=arena">관리자</a>';
  elseif ($u)     echo '<a class="me" href="' . h($root) . 'sfpc_teams.php">내 팀</a>';
  echo ($u ? '<a href="' . h($root) . 'logout.php">로그아웃</a>' : '<a href="' . h($root) . 'login.php?next=' . rawurlencode($home) . '">로그인</a>') . '</div></header>';
  if ($end) { ?>
  <script>
  (function () {  /* 남은 시간 — 서버 시각을 기준으로 센다. 끝나면 목록 화면으로 돌아간다 */
    var el = document.getElementById('sfClock'), end = +el.dataset.end, skew = +el.dataset.now - Date.now() / 1000;
    function tick() {
      var left = Math.max(0, Math.round(end - (Date.now() / 1000 + skew))), h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
      el.textContent = (h ? h + ':' : '') + (h && m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
      el.classList.toggle('low', left <= 300);
      if (left <= 0) { clearInterval(t); if (!/sfpc_(ready|contest|board|practice)\.php/.test(location.pathname)) el.textContent = '끝'; else location.reload(); }
    }
    var t = setInterval(tick, 1000); tick();
  })();
  </script>
  <?php }
  sfpc_live_script($u, $root);
  return true;
}

/* ── 재채점 (DOMjudge 방식) ───────────────────────
   묶음을 만들면 그 제출들을 다시 채점 줄에 세운다. 채점하는 동안에도 순위표는 예전 판정 그대로다.
   다 끝나면 무엇이 바뀌었는지 보고 "적용"하거나 "취소"한다. auto_apply 면 끝나는 대로 적용한다. */
function sfpc_verdict_label(string $v): string { return (VERDICT_NAME[$v] ?? $v) . ' (' . $v . ')'; }
const SFPC_REJUDGE_SCOPE = ['all' => '모든 제출', 'wrong' => '틀린 제출만 (정답 빼고)', 'ie' => '채점 오류만 (IE)'];
/* 묶음을 만든다. 만든 묶음의 번호를 돌려준다 (조건에 맞는 제출이 없으면 0). */
/* ── 운영진이 채점을 취소한 제출 ──
   대기 중이거나 채점 중인 제출을 멈춘다. 판정은 컴파일 오류(CE)로 둔다 — 순위와 벌점에 들어가지 않고,
   바깥 스코어보드에도 문서에 있는 여섯 판정 가운데 하나로 나간다 ("채점 중"으로 남으면 스코어보드가 대회를 끝내지 못한다).
   학생에게는 컴파일 메시지 자리에 아래 글이 보인다. 다시 채점하려면 그 제출을 재채점한다. */
const SFPC_CANCEL_MARK = '[채점 취소]';
const SFPC_CANCEL_MSG  = SFPC_CANCEL_MARK . ' 운영진이 이 제출의 채점을 취소했습니다. 순위와 벌점에는 반영되지 않습니다. 다시 제출해 주세요.';
/* @return int 취소한 제출 수. $ids 는 제출 번호들 — 그중 대기 · 채점 중인 것만 취소된다. */
function sfpc_judge_cancel(array $ids, array $actor): int {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return 0;
  $in = implode(',', $ids);
  $rows = all("SELECT s.id, u.login_id, p.prob_no FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id WHERE s.id IN ($in) AND s.state IN ('pending','judging')");
  if (!$rows) return 0;
  $st = db()->prepare("UPDATE submissions SET state='done', verdict='CE', passed_count=0, tokens=NULL, compile_msg=?, fail_seq=NULL,
                       fail_input=NULL, fail_expected=NULL, fail_output=NULL, max_time=NULL, max_memory=NULL, judged_at=? WHERE id=? AND state IN ('pending','judging')");
  $n = 0; $done = [];
  foreach ($rows as $r) { $st->execute([SFPC_CANCEL_MSG, now(), $r['id']]); if ($st->rowCount()) { $n++; $done[] = '#' . $r['id'] . ' ' . $r['login_id'] . ' · ' . $r['prob_no']; } }
  if ($n) sfpc_log(null, null, $actor, 'admin', '채점 취소 ' . $n . '건 (' . mb_substr(implode(', ', $done), 0, 400) . ')');
  return $n;
}
/* 끝난 제출 하나를 다시 채점한다 (운영 > 채점의 재채점과 같은 방식 — 새 제출이 먼저 처리된다).
   바깥 스코어보드에는 새 판정이 난 뒤에 새 줄이 더해지고 옛 줄이 valid=false 가 된다 (sfpc_judgements_sync).
   @return string '' 이면 성공, 아니면 안 된 까닭 */
function sfpc_judge_again(int $sid, array $actor): string {
  $r = one("SELECT s.id, s.state, s.verdict, u.login_id, p.prob_no FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id WHERE s.id = ?", [$sid]);
  if (!$r) return '없는 제출입니다.';
  if (!in_array($r['state'], ['done', 'error'], true)) return '아직 채점이 끝나지 않은 제출입니다.';
  if ((int)col("SELECT COUNT(*) FROM sfpc_rejudge_item WHERE submission_id = ? AND open = 1", [$sid])) return '재채점 묶음에 들어 있는 제출입니다. 재채점 화면에서 적용하거나 취소해 주세요.';
  db()->prepare("UPDATE submissions SET state='pending', priority=1, queued_at=?, verdict=NULL, passed_count=NULL, total_count=NULL,
                 max_time=NULL, max_memory=NULL, compile_msg=NULL, fail_seq=NULL, tokens=NULL, judged_at=NULL,
                 fail_input=NULL, fail_expected=NULL, fail_output=NULL WHERE id=? AND state IN ('done','error')")->execute([now(), $sid]);
  /* 채점 목록(바깥 스코어보드)이 "새 판정이 났다"는 것을 판정 시각으로 알아본다. 취소하자마자 재채점해 같은 초에 끝나도 놓치지 않게 표시해 둔다. */
  db()->prepare("UPDATE sfpc_judgements SET src_judged_at = '' WHERE submission_id = ? AND valid = 1")->execute([$sid]);
  sfpc_log(null, null, $actor, 'admin', '제출 한 건 재채점 (#' . $sid . ' ' . $r['login_id'] . ' · ' . $r['prob_no'] . ' · 예전 판정 ' . ($r['verdict'] ?? '-') . ')');
  return '';
}
function sfpc_rejudge_start(int $setId, int $problemId, int $userId, string $scope, bool $auto, int $byUser): int {
  $w = ["s.set_id = ?", "s.state IN ('done','error')", "NOT EXISTS (SELECT 1 FROM sfpc_rejudge_item x WHERE x.submission_id = s.id AND x.open = 1)"]; $a = [$setId];
  if ($problemId) { $w[] = "s.problem_id = ?"; $a[] = $problemId; }
  if ($userId)    { $w[] = "s.user_id = ?";    $a[] = $userId; }
  if ($scope === 'wrong') $w[] = "(s.verdict IS NULL OR s.verdict <> 'AC')";
  if ($scope === 'ie')    $w[] = "(s.verdict = 'IE' OR s.state = 'error')";
  $where = implode(' AND ', $w);
  if (!(int)col("SELECT COUNT(*) FROM submissions s WHERE $where", $a)) return 0;
  $label = (string)col("SELECT title FROM sets WHERE id = ?", [$setId])
         . ($problemId ? ' · ' . (string)col("SELECT title FROM problems WHERE id = ?", [$problemId]) : ' · 모든 문제')
         . ($userId ? ' · ' . (string)col("SELECT team_name FROM sfpc_teams WHERE account_user_id = ?", [$userId]) : '')
         . ' · ' . (SFPC_REJUDGE_SCOPE[$scope] ?? SFPC_REJUDGE_SCOPE['all']);
  return tx(function (PDO $d) use ($where, $a, $label, $setId, $auto, $byUser) {
    $d->prepare("INSERT INTO sfpc_rejudge(contest, label, set_id, auto_apply, by_user, created_at) VALUES(?,?,?,?,?,?)")
      ->execute([SFPC_CONTEST, $label, $setId, $auto ? 1 : 0, $byUser, now()]);
    $bid = (int)$d->lastInsertId();
    $d->prepare("INSERT INTO sfpc_rejudge_item(batch_id, submission_id, old_state, old_verdict, old_passed, old_total, old_time, old_memory, old_judged_at)
                 SELECT $bid, s.id, s.state, s.verdict, s.passed_count, s.total_count, s.max_time, s.max_memory, s.judged_at FROM submissions s WHERE $where")->execute($a);
    /* 기존 재채점(운영 > 채점)과 같은 방식 — 기록은 두고 판정만 비워 줄에 세운다. 새 제출이 먼저 처리된다. */
    $d->prepare("UPDATE submissions SET state='pending', priority=1, queued_at=?, verdict=NULL, passed_count=NULL, total_count=NULL,
                 max_time=NULL, max_memory=NULL, compile_msg=NULL, fail_seq=NULL, tokens=NULL, judged_at=NULL,
                 fail_input=NULL, fail_expected=NULL, fail_output=NULL
                 WHERE id IN (SELECT submission_id FROM sfpc_rejudge_item WHERE batch_id = ?)")->execute([now(), $bid]);
    return $bid;
  });
}
/* 묶음의 진행과 바뀐 것. ['total', 'done', 'changes' => [[old, new, n]…], 'changed' => 판정이 바뀐 제출 수, 'rows' => 바뀐 제출들] */
function sfpc_rejudge_info(int $bid): array {
  $rows = all("SELECT i.old_verdict, i.old_state, s.id, s.state, s.verdict, s.created_at, p.title, t.team_name
               FROM sfpc_rejudge_item i JOIN submissions s ON s.id = i.submission_id JOIN problems p ON p.id = s.problem_id
               LEFT JOIN sfpc_teams t ON t.account_user_id = s.user_id AND t.contest = ? WHERE i.batch_id = ? ORDER BY s.id", [SFPC_CONTEST, $bid]);
  $out = ['total' => count($rows), 'done' => 0, 'changed' => 0, 'changes' => [], 'rows' => []];
  foreach ($rows as $r) {
    if (!in_array($r['state'], ['done', 'error'], true)) continue;
    $out['done']++;
    $o = (string)($r['old_verdict'] ?: 'IE'); $n = (string)($r['verdict'] ?: 'IE');
    $k = $o . '>' . $n; $out['changes'][$k] = ($out['changes'][$k] ?? 0) + 1;
    if ($o !== $n) { $out['changed']++; if (count($out['rows']) < 200) $out['rows'][] = $r + ['o' => $o, 'n' => $n]; }
  }
  arsort($out['changes']);
  return $out;
}
function sfpc_rejudge_apply(int $bid): void {
  db()->prepare("UPDATE sfpc_rejudge_item SET open = 0 WHERE batch_id = ?")->execute([$bid]);
  db()->prepare("UPDATE sfpc_rejudge SET status = 'applied', closed_at = ? WHERE id = ? AND status = 'running'")->execute([now(), $bid]);
}
/* 취소 — 예전 판정으로 되돌린다. 채점기에 넘어가 있는(judging) 제출이 있으면 그것이 끝난 뒤에 할 수 있다. */
function sfpc_rejudge_cancel(int $bid): bool {
  if ((int)col("SELECT COUNT(*) FROM sfpc_rejudge_item i JOIN submissions s ON s.id = i.submission_id WHERE i.batch_id = ? AND s.state = 'judging'", [$bid])) return false;
  tx(function (PDO $d) use ($bid) {
    $d->prepare("UPDATE submissions SET state = (SELECT old_state FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   verdict = (SELECT old_verdict FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   passed_count = (SELECT old_passed FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   total_count = (SELECT old_total FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   max_time = (SELECT old_time FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   max_memory = (SELECT old_memory FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   judged_at = (SELECT old_judged_at FROM sfpc_rejudge_item i WHERE i.submission_id = submissions.id AND i.batch_id = $bid),
                   priority = 0, tokens = NULL
                 WHERE id IN (SELECT submission_id FROM sfpc_rejudge_item WHERE batch_id = $bid)")->execute();
    $d->prepare("UPDATE sfpc_rejudge_item SET open = 0 WHERE batch_id = ?")->execute([$bid]);
    $d->prepare("UPDATE sfpc_rejudge SET status = 'canceled', closed_at = ? WHERE id = ?")->execute([now(), $bid]);
  });
  return true;
}
/* "끝나는 대로 적용"으로 만든 묶음이 다 끝났으면 적용한다 (스코어보드 · 알림 · 운영 화면이 열릴 때마다 본다) */
function sfpc_rejudge_tick(): void {
  static $done = false; if ($done) return; $done = true;
  foreach (all("SELECT id FROM sfpc_rejudge WHERE contest = ? AND status = 'running' AND auto_apply = 1", [SFPC_CONTEST]) as $b) {
    $left = (int)col("SELECT COUNT(*) FROM sfpc_rejudge_item i JOIN submissions s ON s.id = i.submission_id WHERE i.batch_id = ? AND s.state NOT IN ('done','error')", [$b['id']]);
    if (!$left) sfpc_rejudge_apply((int)$b['id']);
  }
}

/* ── 알림 — 퍼솔(문제를 가장 먼저 맞힌 팀)과 운영진의 전체 알림 ─────────────
   화면(띠가 있는 화면)이 20초마다 sfpc_live.php 에 물어 가서 새 알림을 띄운다. */
const SFPC_LIVE_SEC = 20;
/* 대회 중이고 얼기 전이면, 아직 알리지 않은 문제의 첫 정답 팀을 찾아 알림으로 남긴다.
   스코어보드에 순위가 붙는 팀(학생 · 정식참가자)만 본다. 그 문제에 더 먼저 낸 제출이 아직 채점 중이면 기다린다. */
function sfpc_first_solve_tick(): void {
  if (sfpc_setting('sfpc_first_solve') !== '1') return;              /* 바깥 스코어보드가 맡는 동안은 꺼 둔다 */
  $c = sfpc_contest_time(); $setId = sfpc_main_set();
  if ($c['state'] !== 'open' || !$setId || sfpc_setting('sfpc_contest_open') !== '1') return;
  $last = (int)sfpc_setting('sfpc_live_tick');
  if (time() - $last < 5) return;                                   /* 여러 화면이 한꺼번에 물어도 한 번만 */
  setting_set('sfpc_live_tick', (string)time());
  $teams = [];
  foreach (all("SELECT id, account_user_id FROM sfpc_teams WHERE contest = ? AND status = 'submitted' AND kind = 'student' AND track = 'participant' AND account_user_id > 0", [SFPC_CONTEST]) as $t) $teams[(int)$t['account_user_id']] = (int)$t['id'];
  if (!$teams) return;
  $known = array_map('intval', array_column(all("SELECT problem_id FROM sfpc_events WHERE contest = ? AND kind = 'first'", [SFPC_CONTEST]), 'problem_id'));
  $in = implode(',', array_keys($teams));
  foreach (sfpc_set_problems($setId) as $p) {
    if (in_array((int)$p['id'], $known, true)) continue;
    foreach (all("SELECT s.user_id, s.created_at, CASE WHEN ri.id IS NULL THEN s.state ELSE ri.old_state END AS state, CASE WHEN ri.id IS NULL THEN s.verdict ELSE ri.old_verdict END AS verdict
                  FROM submissions s LEFT JOIN sfpc_rejudge_item ri ON ri.submission_id = s.id AND ri.open = 1
                  WHERE s.set_id = ? AND s.problem_id = ? AND s.user_id IN ($in) AND s.created_at >= ? AND s.created_at < ? ORDER BY s.created_at, s.id",
                 [$setId, $p['id'], date('Y-m-d H:i:s', $c['from']), date('Y-m-d H:i:s', $c['to'])]) as $r) {
      if (!in_array($r['state'], ['done', 'error'], true)) break;                 /* 더 먼저 낸 제출이 아직 채점 중 */
      if ($r['verdict'] !== 'AC') continue;
      if ($c['freeze'] > 0 && strtotime($r['created_at']) >= $c['freeze']) break;  /* 얼린 뒤의 첫 정답은 알리지 않는다 */
      db()->prepare("INSERT INTO sfpc_events(contest, kind, team_id, problem_id, created_at) VALUES(?,'first',?,?,?)")->execute([SFPC_CONTEST, $teams[(int)$r['user_id']], $p['id'], now()]);
      break;
    }
  }
}
function sfpc_event_top(): int { return (int)col("SELECT COALESCE(MAX(id),0) FROM sfpc_events WHERE contest = ?", [SFPC_CONTEST]); }
/* 알림을 받아 띄우는 스크립트. 한 화면에 한 번만 넣는다. */
function sfpc_live_script(?array $u, string $root): void {
  static $once = false; if ($once) return; $once = true; ?>
  <div class="sfpc-toasts" id="sfToasts" aria-live="polite"></div>
  <script>
  (function () {
    'use strict';
    var ROOT = <?= json_encode($root) ?>, KEY = 'sfpc_event_seen', top = <?= sfpc_event_top() ?>, seen = -1, box = document.getElementById('sfToasts');
    try { var v = localStorage.getItem(KEY); if (v !== null) seen = parseInt(v, 10); } catch (e) {}
    if (!(seen >= 0) || seen > top) seen = top;                 /* 처음 온 화면에는 지난 알림을 쏟지 않는다 */
    function save() { try { localStorage.setItem(KEY, String(seen)); } catch (e) {} }
    function draw(cv, ch) { if (window.SfpcChar) SfpcChar.draw(cv.getContext('2d'), ch, 0, 0, 4, 1, 0); }
    function needChar(cb) { if (window.SfpcChar) return cb(); var s = document.createElement('script'); s.src = ROOT + 'sfpc_char.js'; s.onload = cb; document.head.appendChild(s); }
    function show(e) {
      var d = document.createElement('div'); d.className = 'sfpc-toast ' + e.kind; d.setAttribute('role', 'status');
      if (e.kind === 'first') {
        var cv = document.createElement('canvas'); cv.width = 64; cv.height = 64; d.appendChild(cv); needChar(function () { draw(cv, e.char); });
        var t = document.createElement('div'); t.className = 'tx';
        var a = document.createElement('small'); a.textContent = 'FIRST SOLVE'; var b = document.createElement('b'); b.textContent = e.letter + '번 문제 첫 정답!';
        var c = document.createElement('span'); c.textContent = e.team + (e.school ? ' · ' + e.school : ''); t.appendChild(a); t.appendChild(b); t.appendChild(c); d.appendChild(t);
        setTimeout(function () { d.remove(); }, 12000);
      } else {
        var t2 = document.createElement('div'); t2.className = 'tx';
        var a2 = document.createElement('small'); a2.textContent = '운영진 알림'; var b2 = document.createElement('b'); b2.textContent = e.text; t2.appendChild(a2); t2.appendChild(b2); d.appendChild(t2);
      }
      var x = document.createElement('button'); x.type = 'button'; x.textContent = e.kind === 'first' ? '✕' : '확인'; x.setAttribute('aria-label', '알림 닫기'); x.addEventListener('click', function () { d.remove(); }); d.appendChild(x);
      box.appendChild(d);
    }
    function ask() {
      if (document.hidden) return;
      fetch(ROOT + 'sfpc_live.php?after=' + seen, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        (j.events || []).forEach(function (e) { if (e.id > seen) { seen = e.id; show(e); } });
        if (j.top > seen && !(j.events || []).length) seen = j.top;
        save();
      }).catch(function () {});
    }
    save(); setTimeout(ask, 1500); setInterval(ask, <?= SFPC_LIVE_SEC * 1000 ?>);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) ask(); });
  })();
  </script>
<?php }

/* ── 바깥 스코어보드에 내주는 자료 (sfpc_feed.php) ─────────────
   형식은 DOMjudge(ICPC Contest API)의 submissions · judgements 와 같은 이름과 모양이다.
   · 번호와 시각은 글자로 적는다. 시각은 대회 시작부터 흐른 시간 "시:분:초.밀리초".
   · team_id 는 접수의 팀 고유 번호(sfpc_teams.id), problem_id 는 문제의 고유 번호(problems.id).
   · 판정 이름은 AC · WA · RTE · TLE · CE · NO 여섯 가지로 바꿔 내준다. 메모리 초과는 RTE.
     채점 오류(IE)는 내주지 않는다 — 스코어보드에는 "채점 중"으로 보이다가, 재채점해 판정이 나면 그때 나간다.
   · 팀 계정이 대회 시간 안에 그 대회의 묶음에 낸 제출만. 정식 · 시범 · 교사 구분 없이 모두 내준다. */
const SFPC_FEED_VERDICT = ['AC' => 'AC', 'WA' => 'WA', 'TLE' => 'TLE', 'MLE' => 'RTE', 'RE' => 'RTE', 'CE' => 'CE'];
const SFPC_FEED_LANG = ['py' => 'python3', 'python' => 'python3', 'python3' => 'python3', 'c' => 'c', 'cpp' => 'cpp', 'java' => 'java', 'js' => 'javascript', 'javascript' => 'javascript', 'rust' => 'rust', 'r' => 'r'];
function sfpc_feed_time(int $sec, int $ms = 0): string {
  $sec = max(0, $sec);
  return sprintf('%d:%02d:%02d.%03d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60, $ms);
}
/* 그 대회에 들어가는 제출을 고르는 조건 — [JOIN · WHERE, 값] */
function sfpc_feed_scope(array $r): array {
  $c = sfpc_round_time($r);
  return ["JOIN sfpc_teams t ON t.account_user_id = s.user_id AND t.contest = ? WHERE s.set_id = ? AND s.created_at >= ? AND s.created_at < ?",
          [SFPC_CONTEST, (int)$r['set_id'], date('Y-m-d H:i:s', $c['from']), date('Y-m-d H:i:s', $c['to'])]];
}
function sfpc_feed_submissions(array $r, int $after = 0): array {
  $c = sfpc_round_time($r); if ($c['state'] === 'unset') return [];
  [$w, $a] = sfpc_feed_scope($r); $out = [];
  foreach (all("SELECT s.id, s.problem_id, s.lang, s.created_at, t.id AS team_id FROM submissions s $w AND s.id > ? ORDER BY s.id", array_merge($a, [$after])) as $x) {
    $ts = strtotime($x['created_at']);
    $out[] = ['id' => (string)$x['id'], 'team_id' => (string)$x['team_id'], 'problem_id' => (string)$x['problem_id'],
              'contest_time' => sfpc_feed_time($ts - $c['from']), 'language_id' => SFPC_FEED_LANG[strtolower((string)$x['lang'])] ?? (string)$x['lang'],
              'time' => date('Y-m-d\\TH:i:s.000P', $ts)];
  }
  return $out;
}
/* 채점 기록을 맞춘다 — 판정이 새로 난 제출에 줄을 더하고, 판정이 다시 난 제출은 옛 줄을 무효로 바꾼 뒤 새 줄을 더한다.
   재채점 묶음에 들어 있고 아직 적용하지 않은 제출은 예전 판정 그대로 본다 (적용하는 순간 새 줄이 나간다). */
function sfpc_judgements_sync(array $r): void {
  $c = sfpc_round_time($r); if ($c['state'] === 'unset' || $c['state'] === 'before') return;
  [$w, $a] = sfpc_feed_scope($r);
  $rows = all("SELECT s.id, j.id AS jid,
                 CASE WHEN ri.id IS NULL THEN s.verdict ELSE ri.old_verdict END AS verdict,
                 COALESCE(CASE WHEN ri.id IS NULL THEN s.judged_at ELSE ri.old_judged_at END, '') AS judged_at
               FROM submissions s LEFT JOIN sfpc_rejudge_item ri ON ri.submission_id = s.id AND ri.open = 1
               LEFT JOIN sfpc_judgements j ON j.submission_id = s.id AND j.valid = 1
               $w AND (CASE WHEN ri.id IS NULL THEN s.state ELSE ri.old_state END) IN ('done','error')
                 AND (j.id IS NULL OR j.src_judged_at <> COALESCE(CASE WHEN ri.id IS NULL THEN s.judged_at ELSE ri.old_judged_at END, ''))
               ORDER BY judged_at, s.id", $a);
  if (!$rows) return;
  tx(function (PDO $d) use ($rows, $r) {
    $off = $d->prepare("UPDATE sfpc_judgements SET valid = 0 WHERE id = ?");
    $ins = $d->prepare("INSERT INTO sfpc_judgements(round_id, submission_id, verdict, valid, end_at, src_judged_at) VALUES(?,?,?,1,?,?)");
    foreach ($rows as $x) {
      $v = SFPC_FEED_VERDICT[(string)$x['verdict']] ?? '';
      if ($v === '') continue;                                   /* 채점 오류(IE) — 내주지 않는다. 옛 줄이 있으면 그대로 둔다 */
      try { if ($x['jid']) $off->execute([$x['jid']]); $ins->execute([$r['id'], $x['id'], $v, $x['judged_at'] !== '' ? $x['judged_at'] : now(), $x['judged_at']]); }
      catch (Throwable $e) { /* 다른 요청이 방금 같은 줄을 넣었다 */ }
    }
  });
}
function sfpc_feed_judgements(array $r, int $after = 0): array {
  $c = sfpc_round_time($r); if ($c['state'] === 'unset') return [];
  sfpc_rejudge_tick(); sfpc_judgements_sync($r); $out = [];
  foreach (all("SELECT * FROM sfpc_judgements WHERE round_id = ? AND id > ? ORDER BY id", [$r['id'], $after]) as $x)
    $out[] = ['id' => (string)$x['id'], 'submission_id' => (string)$x['submission_id'], 'judgement_type_id' => $x['verdict'], 'valid' => (int)$x['valid'] === 1,
              'end_contest_time' => sfpc_feed_time(strtotime($x['end_at']) - $c['from'])];
  return $out;
}

/* ── 문의 (sfpc_ask.php) ─────────────────────────── */
const SFPC_QNA_KIND = ['ops' => '운영 문의', 'prob' => '문제 문의'];
const SFPC_QNA_STAFF = ['sfpcedu' => 'ops', 'sfpcset' => 'prob'];   /* 이 계정은 자기 몫의 문의만 "새 문의"로 센다. 그 밖의 운영 계정은 모두 */
/* 이 사람이 볼 수 있는 문의의 조건 — [SQL, 값]. 팀 계정은 그 팀의 글, 지도교사는 자기 글과 자기 팀들의 글 */
function sfpc_qna_scope(array $u): array {
  $team = sfpc_my_team($u);
  if ($team) return ['(q.team_id = ? OR q.user_id = ?)', [(int)$team['id'], (int)$u['id']]];
  return ['(q.user_id = ? OR q.coach_user_id = ?)', [(int)$u['id'], (int)$u['id']]];
}
/* 위쪽 메뉴의 빨간 점 — 운영진: 답을 기다리는 문의 수, 그 밖: 아직 읽지 않은 답의 수 */
function sfpc_qna_badge(?array $u): int {
  static $memo = [];
  if (!$u) return 0;
  $uid = (int)$u['id'];
  if (isset($memo[$uid])) return $memo[$uid];
  if (is_admin($u)) {
    $k = SFPC_QNA_STAFF[$u['login_id']] ?? '';
    return $memo[$uid] = (int)col("SELECT COUNT(*) FROM sfpc_qna q WHERE q.contest = ? AND q.closed = 0 AND q.last_by = 'user'" . ($k !== '' ? " AND q.kind = " . db()->quote($k) : ''), [SFPC_CONTEST]);
  }
  if (($u['source'] ?? '') !== SFPC_ACCOUNT_SOURCE && !sfpc_coach($u)) return $memo[$uid] = 0;
  [$w, $a] = sfpc_qna_scope($u);
  return $memo[$uid] = (int)col("SELECT COUNT(*) FROM sfpc_qna q WHERE q.contest = ? AND $w AND q.last_by = 'staff' AND q.updated_at > q.user_seen_at", array_merge([SFPC_CONTEST], $a));
}
function sfpc_qna_dot(?array $u): string {
  $n = sfpc_qna_badge($u);
  return $n > 0 ? ' <span class="sfpc-dot" title="' . (is_admin($u) ? '답을 기다리는 문의' : '새 답변') . ' ' . $n . '건">' . $n . '</span>' : '';
}

/* ── 보스와 연타 ──────────────────────────────────
   문제 sfpc_boss_every 개마다 보스 칸이 하나 있다 (한 월드 = 그 문제들 + 보스).
   · 보스는 관문이다. 그 월드의 문제를 다 맞히면 열리고, 보스를 지나야 다음 월드로 간다.
   · 보스에서는 연타를 한 판 한다. 끝났을 때 SFPC_MASH_MIN 번 이하면 무효 — 기록하지 않고 몇 번이든 다시 한다.
   · "넘어가기"를 누르면 0점으로 길만 열린다. 나중에 돌아와 도전할 수 있다.
   · 연타 순위는 모든 보스의 합. 수료와는 상관없다 (수료는 문제만 본다). */
const SFPC_BOSSES = ['bug' => '버그 대장', 'golem' => '조건문 골렘', 'snake' => '무한 루프 뱀', 'spaghetti' => '스파게티 괴물', 'oni' => '런타임 도깨비 왕'];
const SFPC_MASH_MIN = 5;           /* 이 횟수 이하로 끝난 판은 무효 */
function sfpc_boss_every(): int { return max(1, (int)sfpc_setting('sfpc_boss_every')); }
function sfpc_mash_sec(): int { return min(60, max(5, (int)sfpc_setting('sfpc_mash_sec'))); }
function sfpc_boss_count(): int { return intdiv(count(sfpc_study_problems()), sfpc_boss_every()); }
/* 보스 번호(1부터) → [종류, 이름]. 마지막 보스는 언제나 도깨비 왕이다. */
function sfpc_boss_kind(int $n): array {
  $keys = array_keys(SFPC_BOSSES); $total = sfpc_boss_count();
  $k = ($n === $total && $total > 0) ? 'oni' : $keys[($n - 1) % (count($keys) - 1)];
  return [$k, SFPC_BOSSES[$k]];
}
/* 팀의 보스별 줄. [보스 번호 => 줄] */
function sfpc_mash_rows(int $teamId): array {
  $out = [];
  foreach (all("SELECT * FROM sfpc_mash WHERE contest = ? AND team_id = ? AND round = 1", [SFPC_CONTEST, $teamId]) as $r) $out[(int)$r['boss']] = $r;
  return $out;
}
/* 보스 한 마리의 상태: 'done' 기록 확정 · 'skip' 넘어감(다시 도전 가능) · '' 아직 */
function sfpc_boss_state(?array $row): string {
  if (!$row) return '';
  if ((int)$row['skip'] === 1) return 'skip';
  return $row['ended_at'] !== '' ? 'done' : '';
}
/* 1번부터 이어서 지나간(기록했거나 넘어간) 보스의 수. 이 수 + 1 번째 월드까지 들어갈 수 있다. */
function sfpc_boss_passed(int $teamId): int {
  $rows = sfpc_mash_rows($teamId); $n = 0; $total = sfpc_boss_count();
  while ($n < $total && sfpc_boss_state($rows[$n + 1] ?? null) !== '') $n++;
  return $n;
}
/* 문제(0부터 센 순서)가 속한 월드에 들어갈 수 있는지 — 앞 월드의 보스를 모두 지나야 한다 */
function sfpc_world_open(int $teamId, int $probIndex): bool {
  $world = min(intdiv($probIndex, sfpc_boss_every()), sfpc_boss_count());   /* 0부터. 보스 없는 자투리 문제는 마지막 보스 뒤 */
  return sfpc_boss_passed($teamId) >= $world;
}
/* 연타 순위 — 확정된 기록의 합 (지운 기록 · 넘어간 보스는 0). 같으면 먼저 그 합에 닿은 팀이 위. 옵저버(교사 · 예비교사)는 빠진다. */
function sfpc_mash_rank(): array {
  return all("SELECT t.id, t.team_name, t.school_name, t.avatar, SUM(m.cnt) total, COUNT(*) plays, MAX(m.ended_at) last_at
              FROM sfpc_mash m JOIN sfpc_teams t ON t.id = m.team_id
              WHERE m.contest = ? AND m.round = 1 AND m.skip = 0 AND m.removed_at = '' AND m.ended_at <> '' AND t.status = 'submitted' AND t.kind = 'student'
              GROUP BY t.id ORDER BY total DESC, last_at ASC", [SFPC_CONTEST]);
}

/* ── 단계 버튼 (공부하기 · 준비하기 · 도전하기 · 스코어보드) ──
   첫 화면과 우리 팀 화면에 크게 놓는다. 열려 있는 단계만 테두리에 전류가 흐르고, 나머지는 "준비 중"이다. */
const SFPC_STAGES = [
  'study'   => ['공부하기',   'sfpc_study.php',   'STAGE 1', '기초 문제로 몸풀기'],
  'ready'   => ['준비하기',   'sfpc_ready.php',   'STAGE 2', '시간 재고 모의 대회'],
  'contest' => ['도전하기',   'sfpc_contest.php', 'STAGE 3', '본 대회'],
  'board'   => ['스코어보드', 'sfpc_board.php',   'RESULT',  '순위 공개'],
];
function sfpc_stage_tiles(string $root = '', string $cls = ''): void {
  echo '<nav class="sfpc-stages ' . h($cls) . '" aria-label="대회 단계">';
  foreach (SFPC_STAGES as $k => [$name, $href, $tag, $desc]) {
    $on = sfpc_setting('sfpc_' . $k . '_open') === '1' || ($k === 'board' && sfpc_setting('sfpc_board_url') !== '');
    echo '<a class="st' . ($on ? ' live' : ' soon') . '" href="' . h($root . $href) . '">'
       . '<span class="tag">' . h($tag) . '</span><b>' . h($name) . '</b>'
       . '<span class="d">' . h($on ? $desc : '준비 중') . '</span>'
       . ($on ? '<i class="zap" aria-hidden="true"></i>' : '') . '</a>';
  }
  echo '</nav>';
}

/* ── 공부하기 ─────────────────────────────────────
   문제 · 채점은 KAIT-CLASS 의 기존 "수업" 묶음을 그대로 쓴다. 공부하기용 묶음 하나를 자동으로 만들고
   (누구에게나 열린 수업), 운영진은 수업 화면에서 그 묶음에 문제를 담는다.
   · 팀 계정이 묶음의 문제를 모두 맞히면 수료 (sfpc_teams.study_done_at). 수료는 한 번 되면 유지된다.
   · 수료한 중학교 팀은 정식참가자가 된다.
   · sfpc_study_skip — 수료 조건에서 뺄 문제(problems.id, 쉼표). 문제에 오류가 있을 때 쓴다. */
function sfpc_study_set(bool $create = true): ?array {
  $id = (int)sfpc_setting('sfpc_study_set');
  $s  = $id ? one("SELECT * FROM sets WHERE id = ?", [$id]) : null;
  if ($s || !$create) return $s;
  db()->prepare("INSERT INTO sets(set_type, title, start_at, end_at, active, langs, show_diff, visibility, no_paste, created_at)
                 VALUES('lesson', ?, NULL, NULL, 1, ?, 1, 'all', 0, ?)")
      ->execute(['SFPC 공부하기', implode(',', array_keys(langs())), now()]);
  $id = (int)db()->lastInsertId();
  setting_set('sfpc_study_set', (string)$id);
  return one("SELECT * FROM sets WHERE id = ?", [$id]);
}
function sfpc_study_skip(): array {
  return array_values(array_filter(array_map('intval', explode(',', sfpc_setting('sfpc_study_skip')))));
}
/* 묶음의 문제들 (순서대로) */
function sfpc_study_problems(): array {
  $s = sfpc_study_set(false);
  if (!$s) return [];
  return all("SELECT p.id, p.prob_no, p.title FROM set_problems sp JOIN problems p ON p.id = sp.problem_id
              WHERE sp.set_id = ? ORDER BY sp.sort, sp.id", [$s['id']]);
}
/* 이 계정이 맞힌 문제 번호(problems.id)들 */
function sfpc_study_solved(int $userId): array {
  $s = sfpc_study_set(false);
  if (!$s || !$userId) return [];
  return array_map('intval', array_column(all("SELECT DISTINCT s.problem_id FROM submissions s JOIN set_problems sp ON sp.problem_id = s.problem_id AND sp.set_id = ?
                                               WHERE s.user_id = ? AND s.verdict = 'AC'", [$s['id'], $userId]), 'problem_id'));
}
/* 수료 조건을 다 채웠으면 수료 처리한다. 수료했으면(이번이든 전이든) true */
function sfpc_study_check(array $t): bool {
  if (($t['study_done_at'] ?? '') !== '') return true;
  $uid = (int)($t['account_user_id'] ?? 0);
  if (!$uid || $t['status'] !== 'submitted') return false;
  $need = array_diff(array_map('intval', array_column(sfpc_study_problems(), 'id')), sfpc_study_skip());
  if (!$need || array_diff($need, sfpc_study_solved($uid))) return false;
  sfpc_study_grant($t, null);
  if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['sfpc_grad'] = (int)$t['id'];   /* 다음 화면에서 축하 화면을 한 번 띄운다 */
  return true;
}
/* 수료 처리 — $actor 가 있으면 운영진이 손으로 한 것 */
function sfpc_study_grant(array $t, ?array $actor): void {
  db()->prepare("UPDATE sfpc_teams SET study_done_at = ? WHERE id = ? AND study_done_at = ''")->execute([now(), $t['id']]);
  $note = '공부하기 수료' . ($actor ? ' (운영진이 처리)' : '');
  if ($t['category'] === 'middle' && $t['track'] === 'observer') {
    db()->prepare("UPDATE sfpc_teams SET track = 'participant' WHERE id = ?")->execute([$t['id']]);
    $note .= ' — 참가 자격: 시범참가자 → 정식참가자';
  }
  $who = $actor ?: ['id' => (int)$t['account_user_id'], 'name' => $t['team_name'], 'role' => 'student'];
  sfpc_log((int)$t['id'], (int)$t['coach_user_id'], $who, 'study', $note);
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
