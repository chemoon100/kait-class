<?php
/* ═══════════════════════════════════════════════
   sfpc_school.php — 학교 찾기 (신청 양식이 부른다). JSON 으로 답한다.
     ?q=이름 일부 &region=충북 &level=middle|high|any
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/sfpc_lib.php';
require_login();

$q      = sfpc_clean((string)($_GET['q'] ?? ''), 30);
$region = (string)($_GET['region'] ?? '');
$level  = (string)($_GET['level'] ?? '');
if ($q === '' || !in_array($region, SFPC_REGIONS, true) || !in_array($level, ['middle', 'high', 'any'], true)) {
  jout(['ok' => true, 'schools' => []]);
}

/* LIKE 의 특수 문자를 글자 그대로 찾게 한다 */
$like = strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
$where = "region = ? AND name LIKE ? ESCAPE '\\'";
$args  = [$region, '%' . $like . '%'];
if ($level !== 'any') { $where .= " AND level = ?"; $args[] = $level; }

/* 입력한 말로 시작하는 학교를 먼저 */
$args[] = $like . '%';
$rows = all("SELECT id, name, level, hs_type, addr FROM sfpc_schools
             WHERE $where
             ORDER BY (name LIKE ? ESCAPE '\\') DESC, name LIMIT 30", $args);
jout(['ok' => true, 'schools' => array_map(fn($r) => [
  'id' => (int)$r['id'], 'name' => $r['name'],
  'hs_type' => $r['level'] === 'high' ? ($r['hs_type'] !== '' ? $r['hs_type'] : '고등학교') : '중학교',
  'addr' => $r['addr'],
], $rows)]);
