<?php
/* ═══════════════════════════════════════════════
   sfpc_ask.php — 문의. 팀 계정 · 지도교사가 운영진에게 묻고, 운영진이 답한다.

     (목록)            내 문의들 / 운영진에게는 문의함
     ?new=ops|prob     새 문의 (&no=문제번호 — 문제 화면의 "이 문제 질문하기")
     ?id=N             문의 하나와 주고받은 글

   · 운영 문의(ops)는 admin 과 교육팀(sfpcedu), 문제 문의(prob)는 admin 과 문항 제작팀(sfpcset)의 몫이다.
     운영 계정은 모든 문의를 볼 수 있고, "새 문의" 숫자만 자기 몫으로 센다.
   · 팀 계정으로 쓴 글은 그 팀의 지도교사도 본다. 지도교사가 쓴 글은 지도교사만 본다.
   · 알림은 화면 위쪽 "문의" 옆의 빨간 숫자뿐이다 (메일은 보내지 않는다).
   · 모두에게 알릴 것은 공지사항에 쓴다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
$staff = is_admin($u);
$team  = sfpc_my_team($u);
$coach = $staff ? null : sfpc_coach($u);
if (!$staff && !$team && !$coach) { header('Location: sfpc.php'); exit; }

const SFPC_QNA_PER_DAY = 20;     /* 한 계정이 하루에 새로 낼 수 있는 문의 수 */

/* 문의 하나 — 볼 수 있는 사람만 */
$load = function (int $id) use ($u, $staff): ?array {
  if ($staff) return one("SELECT q.* FROM sfpc_qna q WHERE q.id = ? AND q.contest = ?", [$id, SFPC_CONTEST]);
  [$w, $a] = sfpc_qna_scope($u);
  return one("SELECT q.* FROM sfpc_qna q WHERE q.id = ? AND q.contest = ? AND $w", array_merge([$id, SFPC_CONTEST], $a));
};
$myName = $staff ? ($u['name'] !== '' ? $u['name'] : $u['login_id']) : ($team ? $team['team_name'] : ($coach['name'] ?? $u['login_id']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 보내 주세요.');
  $act  = (string)($_POST['act'] ?? '');
  $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
  if (ustrlen($body) > 2000) $body = mb_substr($body, 0, 2000);

  if ($act === 'new' && !$staff) {
    $kind  = (string)($_POST['kind'] ?? '');
    $title = sfpc_clean((string)($_POST['title'] ?? ''), 60);
    $keep  = function (string $e) use ($kind, $title, $body) { $_SESSION['sfpc_ask_draft'] = ['kind' => $kind, 'title' => $title, 'body' => $body, 'no' => (int)($_POST['no'] ?? 0)]; redirect_with('', $e); };
    if (!isset(SFPC_QNA_KIND[$kind])) $keep('문의 종류를 고르세요.');
    if (ustrlen($title) < 2)          $keep('제목을 2자 이상 적어 주세요.');
    if (ustrlen($body) < 5)           $keep('내용을 조금 더 자세히 적어 주세요.');
    if ((int)col("SELECT COUNT(*) FROM sfpc_qna WHERE user_id = ? AND created_at > ?", [$u['id'], date('Y-m-d H:i:s', time() - 86400)]) >= SFPC_QNA_PER_DAY)
      $keep('오늘은 문의를 더 낼 수 없습니다. 이미 낸 문의에 이어서 적어 주세요.');
    $pid = null; $plabel = '';
    if ($kind === 'prob' && (int)($_POST['no'] ?? 0) > 0) {
      foreach (sfpc_study_problems() as $i => $p) if ((int)$p['prob_no'] === (int)$_POST['no']) { $pid = (int)$p['id']; $plabel = '공부하기 ' . ($i + 1) . '번 · ' . $p['title']; }
    }
    $coachId = $team ? (int)$team['coach_user_id'] : (int)$u['id'];
    $id = tx(function (PDO $d) use ($u, $team, $coachId, $kind, $title, $body, $pid, $plabel, $myName) {
      $d->prepare("INSERT INTO sfpc_qna(contest, kind, user_id, team_id, coach_user_id, problem_id, prob_label, title, last_by, user_seen_at, created_at, updated_at)
                   VALUES(?,?,?,?,?,?,?,?,'user',?,?,?)")
        ->execute([SFPC_CONTEST, $kind, $u['id'], $team ? $team['id'] : null, $coachId, $pid, $plabel, $title, now(), now(), now()]);
      $id = (int)$d->lastInsertId();
      $d->prepare("INSERT INTO sfpc_qna_msg(qna_id, user_id, by_staff, author, body, created_at) VALUES(?,?,0,?,?,?)")->execute([$id, $u['id'], $myName, $body, now()]);
      return $id;
    });
    $_SESSION['flash'] = ['msg' => '문의를 보냈습니다. 답이 오면 위쪽 "문의" 옆에 빨간 숫자가 뜹니다.', 'err' => ''];
    header('Location: sfpc_ask.php?id=' . $id); exit;
  }

  $q = $load((int)($_POST['id'] ?? 0));
  if (!$q) redirect_with('', '문의를 찾지 못했습니다.');
  if ($act === 'reply') {
    if (ustrlen($body) < 1) redirect_with('', '내용을 적어 주세요.');
    if ((int)$q['closed'] && !$staff) redirect_with('', '끝난 문의입니다. 새 문의로 적어 주세요.');
    db()->prepare("INSERT INTO sfpc_qna_msg(qna_id, user_id, by_staff, author, body, created_at) VALUES(?,?,?,?,?,?)")
        ->execute([$q['id'], $u['id'], $staff ? 1 : 0, $myName, $body, now()]);
    db()->prepare("UPDATE sfpc_qna SET last_by = ?, updated_at = ?, closed = 0" . ($staff ? '' : ", user_seen_at = " . db()->quote(now())) . " WHERE id = ?")
        ->execute([$staff ? 'staff' : 'user', now(), $q['id']]);
    $_SESSION['flash'] = ['msg' => $staff ? '답을 보냈습니다.' : '이어서 보냈습니다.', 'err' => ''];
    header('Location: sfpc_ask.php?id=' . (int)$q['id']); exit;
  }
  if ($act === 'close' && $staff) {       /* 답할 것이 없는 글(인사 · 확인)을 "답 기다림"에서 뺀다. 상대가 다시 쓰면 열린다 */
    db()->prepare("UPDATE sfpc_qna SET closed = ? WHERE id = ?")->execute([(int)$q['closed'] ? 0 : 1, $q['id']]);
    $_SESSION['flash'] = ['msg' => (int)$q['closed'] ? '다시 열었습니다.' : '끝난 문의로 표시했습니다.', 'err' => ''];
    header('Location: sfpc_ask.php?id=' . (int)$q['id']); exit;
  }
  redirect_with('', '처리할 수 없는 요청입니다.');
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$id  = (int)($_GET['id'] ?? 0);
$new = (string)($_GET['new'] ?? '');
$q   = $id ? $load($id) : null;
if ($id && !$q) { header('Location: sfpc_ask.php'); exit; }
/* 읽은 것으로 */
if ($q && !$staff) db()->prepare("UPDATE sfpc_qna SET user_seen_at = ? WHERE id = ?")->execute([now(), $q['id']]);

$who = function (array $q): string {
  $t = $q['team_id'] ? one("SELECT team_name, school_name FROM sfpc_teams WHERE id = ?", [$q['team_id']]) : null;
  $c = one("SELECT name FROM sfpc_coaches WHERE user_id = ?", [(int)$q['coach_user_id']]);
  if ($t && (int)$q['user_id'] !== (int)$q['coach_user_id']) return $t['team_name'] . ' 팀 (' . $t['school_name'] . ')';
  return ($c['name'] ?? '지도교사') . ' 선생님';
};
$state = function (array $q) use ($staff): array {
  if ((int)$q['closed']) return ['끝남', 'v-wait'];
  if ($q['last_by'] === 'user') return [$staff ? '답 기다림' : '답변 기다리는 중', $staff ? 'v-WA' : 'v-wait'];
  return [$staff ? '답함' : (($q['updated_at'] > $q['user_seen_at']) ? '새 답변' : '답변 완료'), 'v-AC'];
};

page_head(['title' => $staff ? '문의함' : '문의', 'root' => '', 'user' => $u, 'nav' => 'sfpc_ask']);
sfpc_css();
?>
<div class="wrap narrow sfpc-ask">
  <div class="phead">
    <h1><?= $staff ? '문의함' : '문의' ?></h1>
    <span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span>
    <span class="grow"></span>
    <?php if ($q || $new !== ''): ?><a class="btn sm" href="sfpc_ask.php">목록으로</a>
    <?php elseif (!$staff): ?><a class="btn primary sm" href="sfpc_ask.php?new=ops">새 문의</a>
    <?php else: ?><a class="btn sm" href="admin/sfpc.php">운영 화면</a><a class="btn sm" href="notices.php">공지사항 쓰기</a><?php endif; ?>
  </div>
  <?php if ($flash && $flash['msg'] !== ''): ?><div class="note ok"><?= h($flash['msg']) ?></div><?php endif; ?>
  <?php if ($flash && $flash['err'] !== ''): ?><div class="note err"><?= h($flash['err']) ?></div><?php endif; ?>

<?php if ($q): /* ── 문의 하나 ── */
  $msgs = all("SELECT * FROM sfpc_qna_msg WHERE qna_id = ? ORDER BY id", [$q['id']]); [$sl, $sc] = $state($q); ?>
  <div class="sfpc-ask-h">
    <span class="sfpc-flag k-<?= h($q['kind']) ?>"><?= h(SFPC_QNA_KIND[$q['kind']] ?? '') ?></span>
    <h2><?= h($q['title']) ?></h2>
    <span class="v <?= $sc ?>"><?= h($sl) ?></span>
  </div>
  <p class="small muted" style="margin:0 0 14px"><?= h($who($q)) ?> · <?= h(substr($q['created_at'], 0, 16)) ?><?= $q['prob_label'] !== '' ? ' · <b>' . h($q['prob_label']) . '</b>' : '' ?></p>
  <div class="sfpc-ask-thread">
    <?php foreach ($msgs as $m): ?>
      <div class="m<?= (int)$m['by_staff'] ? ' staff' : '' ?>">
        <div class="by"><b><?= (int)$m['by_staff'] ? '운영진' . ($staff ? ' · ' . h($m['author']) : '') : h($m['author']) ?></b><time><?= h(substr($m['created_at'], 5, 11)) ?></time></div>
        <div class="tx"><?= nl2br(h($m['body'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!(int)$q['closed'] || $staff): ?>
  <form method="post" class="sfpc-ask-form">
    <?= sfpc_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
    <label for="askBody"><?= $staff ? '답하기' : '이어서 적기' ?></label>
    <textarea id="askBody" name="body" rows="5" maxlength="2000" required></textarea>
    <div class="btnrow">
      <button class="btn primary" name="act" value="reply"><?= $staff ? '답 보내기' : '보내기' ?></button>
      <?php if ($staff): ?><button class="btn" name="act" value="close" formnovalidate><?= (int)$q['closed'] ? '다시 열기' : '답 없이 끝난 문의로 표시' ?></button><?php endif; ?>
    </div>
    <?php if ($staff): ?><p class="small muted" style="margin:8px 0 0">답은 이 문의를 낸 <?= $q['team_id'] ? '팀과 그 지도교사' : '지도교사' ?>만 봅니다. 모두에게 알릴 내용은 <a href="notices.php">공지사항</a>에 써 주세요.</p><?php endif; ?>
  </form>
  <?php else: ?><p class="note info small">끝난 문의입니다. 더 물을 것이 있으면 <a href="sfpc_ask.php?new=<?= h($q['kind']) ?>">새 문의</a>로 적어 주세요.</p><?php endif; ?>

<?php elseif ($new !== '' && !$staff): /* ── 새 문의 ── */
  $d = $_SESSION['sfpc_ask_draft'] ?? null; unset($_SESSION['sfpc_ask_draft']);
  $kind = $d['kind'] ?? (isset(SFPC_QNA_KIND[$new]) ? $new : 'ops'); $no = (int)($d['no'] ?? ($_GET['no'] ?? 0)); $pl = '';
  if ($no > 0) foreach (sfpc_study_problems() as $i => $p) if ((int)$p['prob_no'] === $no) $pl = '공부하기 ' . ($i + 1) . '번 · ' . $p['title'];
  if ($pl === '') $no = 0; ?>
  <form method="post" class="sfpc-ask-form">
    <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="new"><input type="hidden" name="no" value="<?= $no ?>">
    <div class="field"><label>무엇에 대한 문의인가요</label>
      <div class="sfpc-way">
        <label><input type="radio" name="kind" value="ops"<?= $kind === 'ops' ? ' checked' : '' ?>><span><b>운영 문의</b><small>접수, 팀 계정, 일정, 참가 방식, 화면이 이상할 때</small></span></label>
        <label><input type="radio" name="kind" value="prob"<?= $kind === 'prob' ? ' checked' : '' ?>><span><b>문제 문의</b><small>문제 내용, 입출력 설명, 채점 결과가 이상할 때</small></span></label>
      </div></div>
    <?php if ($pl !== ''): ?><p class="note info small" style="margin:0 0 14px">이 문제에 대한 문의로 보냅니다: <b><?= h($pl) ?></b></p><?php endif; ?>
    <div class="field"><label for="askTitle">제목</label><input type="text" id="askTitle" name="title" maxlength="60" required value="<?= h($d['title'] ?? '') ?>"></div>
    <div class="field"><label for="askBody">내용</label>
      <textarea id="askBody" name="body" rows="8" maxlength="2000" required placeholder="어떤 화면에서 무엇을 했더니 어떻게 되었는지 적어 주시면 빨리 답할 수 있습니다."><?= h($d['body'] ?? '') ?></textarea>
      <span class="hint"><?= $team ? '팀 계정으로 보내는 문의는 지도 선생님도 볼 수 있습니다. ' : '' ?>문제의 정답이나 풀이는 알려 드리지 않습니다. 비밀번호 같은 것은 적지 마세요.</span></div>
    <div class="btnrow"><button class="btn primary">문의 보내기</button><a class="btn" href="sfpc_ask.php">그만두기</a></div>
  </form>

<?php else: /* ── 목록 ── */
  $f = (string)($_GET['f'] ?? ($staff ? 'wait' : 'all')); $k = (string)($_GET['k'] ?? ($staff ? (SFPC_QNA_STAFF[$u['login_id']] ?? '') : ''));
  $w = ['q.contest = ?']; $a = [SFPC_CONTEST];
  if ($staff) {
    if ($f === 'wait') $w[] = "q.closed = 0 AND q.last_by = 'user'";
    if (isset(SFPC_QNA_KIND[$k])) { $w[] = 'q.kind = ?'; $a[] = $k; }
  } else { [$sw, $sa] = sfpc_qna_scope($u); $w[] = $sw; $a = array_merge($a, $sa); }
  $rows = all("SELECT q.*, (SELECT COUNT(*) FROM sfpc_qna_msg m WHERE m.qna_id = q.id) AS n FROM sfpc_qna q WHERE " . implode(' AND ', $w)
            . " ORDER BY " . ($staff && $f === 'wait' ? 'q.updated_at ASC, q.id ASC' : 'q.updated_at DESC, q.id DESC') . " LIMIT 300", $a);
  if ($staff):
    $cnt = fn(string $kk) => (int)col("SELECT COUNT(*) FROM sfpc_qna q WHERE q.contest = ? AND q.closed = 0 AND q.last_by = 'user'" . ($kk !== '' ? " AND q.kind = " . db()->quote($kk) : ''), [SFPC_CONTEST]);
    $lnk = fn(string $ff, string $kk) => 'sfpc_ask.php?f=' . $ff . '&amp;k=' . ($kk === '' ? 'all' : $kk); ?>
    <div class="sfpc-ask-filter">
      <a class="<?= $f === 'wait' ? 'on' : '' ?>" href="<?= $lnk('wait', $k) ?>">답 기다리는 문의</a>
      <a class="<?= $f !== 'wait' ? 'on' : '' ?>" href="<?= $lnk('all', $k) ?>">전체</a>
      <span class="sep"></span>
      <a class="<?= !isset(SFPC_QNA_KIND[$k]) ? 'on' : '' ?>" href="<?= $lnk($f, '') ?>">모두 <i><?= $cnt('') ?></i></a>
      <a class="<?= $k === 'ops' ? 'on' : '' ?>" href="<?= $lnk($f, 'ops') ?>">운영 문의 <i><?= $cnt('ops') ?></i></a>
      <a class="<?= $k === 'prob' ? 'on' : '' ?>" href="<?= $lnk($f, 'prob') ?>">문제 문의 <i><?= $cnt('prob') ?></i></a>
    </div>
    <p class="small muted" style="margin:0 0 12px">숫자는 답을 기다리는 문의 수입니다. 운영 문의는 admin · 교육팀(sfpcedu), 문제 문의는 admin · 문항 제작팀(sfpcset)의 몫입니다. 답 기다리는 문의는 오래된 것부터 나옵니다.</p>
  <?php else: ?>
    <p class="small muted" style="margin:0 0 12px">운영진에게 묻고 싶은 것을 남기면 이 화면에서 답을 받습니다. 답이 오면 위쪽 "문의" 옆에 빨간 숫자가 뜹니다. 모두에게 알리는 내용은 <a href="notices.php">공지사항</a>에 올라옵니다.</p>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <div class="empty"><?= $staff ? ($f === 'wait' ? '답을 기다리는 문의가 없습니다.' : '아직 문의가 없습니다.') : '아직 낸 문의가 없습니다.' ?></div>
  <?php else: ?>
    <ul class="sfpc-ask-list">
      <?php foreach ($rows as $r): [$sl, $sc] = $state($r); ?>
        <li><a href="sfpc_ask.php?id=<?= (int)$r['id'] ?>">
          <span class="sfpc-flag k-<?= h($r['kind']) ?>"><?= h(SFPC_QNA_KIND[$r['kind']] ?? '') ?></span>
          <span class="t"><b><?= h($r['title']) ?></b><small><?= h($who($r)) ?><?= $r['prob_label'] !== '' ? ' · ' . h($r['prob_label']) : '' ?> · 글 <?= (int)$r['n'] ?>개 · <?= h(substr($r['updated_at'], 5, 11)) ?></small></span>
          <span class="v <?= $sc ?>"><?= h($sl) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php page_foot();
