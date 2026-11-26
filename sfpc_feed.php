<?php
/* ═══════════════════════════════════════════════
   sfpc_feed.php — 바깥 스코어보드 서버가 제출 목록과 채점 목록을 받아 가는 곳. 읽기만 한다.

     /sfpc_feed.php/contests/{대회 ID}/submissions?after={제출 번호}
     /sfpc_feed.php/contests/{대회 ID}/judgements?after={채점 번호}
     (같은 것: /sfpc_feed.php?contest={대회 ID}&list=submissions&after=…)

   · 대회 ID 는 운영 > SFPC > 준비 · 대회에 나온다 (준비하기 네 번과 도전하기가 각각 하나).
   · after 를 붙이면 그 번호보다 큰 것만, 없으면 전체. 번호는 생긴 순서대로 커진다.
   · 요청 머리말에 Authorization: Bearer (비밀 글자열) 이 있어야 한다. 서버에는 그 글자열의 sha256 만 둔다.
   · 형식과 규칙은 sfpc_lib.php 의 "바깥 스코어보드에 내주는 자료"에 적어 두었다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit; };

$want = sfpc_setting('sfpc_feed_key');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$tok  = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
if ($want === '')  $fail(503, 'feed key is not set');
if ($tok === '' || !hash_equals($want, hash('sha256', $tok))) { header('WWW-Authenticate: Bearer'); $fail(401, 'unauthorized'); }

$cid = (int)($_GET['contest'] ?? 0); $list = (string)($_GET['list'] ?? '');
if (preg_match('#/contests/(\d+)/(submissions|judgements)/?$#', (string)($_SERVER['PATH_INFO'] ?? parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), $m)) { $cid = (int)$m[1]; $list = $m[2]; }
$round = sfpc_round($cid);
if (!$round) $fail(404, 'no such contest');
$after = max(0, (int)($_GET['after'] ?? 0));
if ($list === 'submissions')     echo json_encode(sfpc_feed_submissions($round, $after), JSON_UNESCAPED_UNICODE);
elseif ($list === 'judgements')  echo json_encode(sfpc_feed_judgements($round, $after), JSON_UNESCAPED_UNICODE);
else $fail(404, 'unknown list');
