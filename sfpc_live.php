<?php
/* ═══════════════════════════════════════════════
   sfpc_live.php?after=N — 새 알림을 JSON 으로 준다. 띠가 있는 화면이 20초마다 물어 간다 (sfpc_lib.php 의 sfpc_live_script).

   · first  문제를 가장 먼저 맞힌 팀 (스코어보드에 순위가 붙는 팀만, 얼리기 전까지). 스코어보드가 공개되어 있으면 누구에게나.
   · notice 운영진이 보낸 전체 알림. 로그인한 사람에게만.
   오래된 알림(15분 넘은 것)은 주지 않는다 — 한참 뒤에 들어온 화면에 지난 소식이 쏟아지지 않게.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!sfpc_site()) { echo '{"top":0,"events":[]}'; exit; }

sfpc_rejudge_tick();
sfpc_first_solve_tick();

$after = max(0, (int)($_GET['after'] ?? 0));
$kinds = [];
if ($u || sfpc_setting('sfpc_board_open') === '1') $kinds[] = "'first'";
if ($u) $kinds[] = "'notice'";
$out = ['top' => sfpc_event_top(), 'events' => []];
if ($kinds) {
  $letters = [];
  foreach (sfpc_set_problems(sfpc_main_set()) as $i => $p) $letters[(int)$p['id']] = sfpc_letter($i);
  foreach (all("SELECT * FROM sfpc_events WHERE contest = ? AND id > ? AND kind IN (" . implode(',', $kinds) . ") AND created_at >= ? ORDER BY id LIMIT 10",
               [SFPC_CONTEST, $after, date('Y-m-d H:i:s', time() - 900)]) as $e) {
    $row = ['id' => (int)$e['id'], 'kind' => $e['kind'], 'text' => $e['text']];
    if ($e['kind'] === 'first') {
      $t = sfpc_team((int)$e['team_id']);
      if (!$t || !isset($letters[(int)$e['problem_id']])) continue;
      $row += ['letter' => $letters[(int)$e['problem_id']], 'team' => $t['team_name'], 'school' => $t['school_name'], 'char' => sfpc_char_of($t)];
    }
    $out['events'][] = $row;
  }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
