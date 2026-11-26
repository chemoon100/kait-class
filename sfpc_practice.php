<?php
/* ═══════════════════════════════════════════════
   sfpc_practice.php — 연습. 팀이 아무 때나 시작하는 모의 대회 (오락실처럼).
   준비하기(정해진 시각에 다 같이 치르는 네 번)가 끝난 뒤에 열어 두는 곳이다. 공개 스위치는 "연습 공개".

   · 팀이 "시작"을 누르면 그때부터 한 판(기본 90분). 하루 3판, 자정에 다시 찬다.
   · 한 판은 시간이 다 되면 저절로 끝나고, "포기하기"로 먼저 끝낼 수도 있다 (그 판은 쓴 것이다).
   · 점수는 본 대회와 같은 ICPC 방식. 판마다 기록이 남고, 세트마다 가장 좋은 기록을 보여 준다.
   · 순위표는 없고, 바깥 스코어보드에도 나가지 않는다.
   팀 계정만 한다 (운영진은 화면만 미리 본다).
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
$team = sfpc_my_team($u);
$open = sfpc_setting('sfpc_practice_open') === '1';
$sets = sfpc_ready_sets();
$MIN = sfpc_ready_min(); $PER = sfpc_ready_per_day();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team && $open) {
  if (!sfpc_csrf_ok()) redirect_with('', '화면을 연 지 오래되었습니다. 다시 눌러 주세요.');
  $act = (string)($_POST['act'] ?? '');
  $run = sfpc_run_active((int)$team['id']);
  if ($act === 'start') {
    $sid = (int)($_POST['set'] ?? 0);
    if ($team['status'] !== 'submitted')            redirect_with('', '접수가 취소된 팀은 할 수 없습니다.');
    if ($run)                                       redirect_with('', '이미 하고 있는 판이 있습니다.');
    if (!isset($sets[$sid]))                        redirect_with('', '문제 세트를 고르세요.');
    if (!sfpc_set_problems($sid))                   redirect_with('', '이 세트에는 아직 문제가 없습니다.');
    if (sfpc_runs_today((int)$team['id']) >= $PER)  redirect_with('', '오늘의 도전을 모두 썼습니다. 자정이 지나면 다시 ' . $PER . '판이 찹니다.');
    db()->prepare("INSERT INTO sfpc_runs(contest, team_id, set_id, started_at, ends_at) VALUES(?,?,?,?,?)")
        ->execute([SFPC_CONTEST, $team['id'], $sid, now(), date('Y-m-d H:i:s', time() + $MIN * 60)]);
    redirect_with('시작! ' . $MIN . '분 동안 풀 수 있습니다.');
  }
  if ($act === 'giveup' && $run) {
    if (trim((string)($_POST['word'] ?? '')) !== '포기') redirect_with('', '포기하려면 입력 칸에 "포기"라고 적어 주세요. 판은 계속됩니다.');
    db()->prepare("UPDATE sfpc_runs SET ended_at = ? WHERE id = ?")->execute([now(), $run['id']]);
    redirect_with('이번 판을 끝냈습니다. 기록은 아래에 남았습니다.');
  }
  redirect_with('', '처리할 수 없는 요청입니다.');
}
[$msg, $err] = flash_take();

page_head(['title' => '연습', 'root' => '', 'user' => $u, 'nav' => 'sfpc_practice']);
sfpc_css();
if (!sfpc_gate('sfpc_practice_open', '연습', $u)) { page_foot(); exit; }

if (!$team && !is_admin($u)) { ?>
<div class="wrap narrow">
  <div class="phead"><h1>연습</h1><span class="sub"><?= h(sfpc_setting('sfpc_title')) ?></span></div>
  <div class="sfpc-soon" style="margin:20px 0"><h1>팀 계정으로 들어와 주세요</h1>
    <p>연습은 대회에 접수한 팀이 팀 계정으로 로그인해서 하는 모의 대회입니다.</p>
    <p style="margin-top:20px"><a class="btn primary" href="<?= $u ? 'sfpc_teams.php' : 'login.php?next=' . rawurlencode('sfpc_practice.php') ?>"><?= $u ? '내 팀에서 팀 계정 확인하기' : '팀 계정으로 로그인' ?></a></p></div>
</div>
<?php page_foot(); exit; }

$run   = $team ? sfpc_run_active((int)$team['id']) : null;
$used  = $team ? sfpc_runs_today((int)$team['id']) : 0;
$uid   = $team ? (int)$team['account_user_id'] : 0;
$score = fn(array $r) => sfpc_icpc_one($uid, (int)$r['set_id'], strtotime($r['started_at']), strtotime(sfpc_run_end($r)));
$past  = $team ? all("SELECT * FROM sfpc_runs WHERE contest = ? AND team_id = ? ORDER BY id DESC LIMIT 60", [SFPC_CONTEST, $team['id']]) : [];
?>
<div class="sfpc-world sfpc-arcade">
  <?php if ($msg): ?><div class="sfpc-world-note ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="sfpc-world-note bad"><?= h($err) ?></div><?php endif; ?>
  <?php if (!$team): ?><div class="sfpc-world-note">운영진 미리 보기입니다. 판을 시작할 수 없습니다 (팀 계정만). 세트와 시간은 운영 &gt; SFPC &gt; 준비 · 대회의 "연습"에서 정합니다.</div><?php endif; ?>
  <?php if ($team) sfpc_notice_box($u); ?>

<?php if ($run): /* ── 판이 돌아가는 중 ── */
  $probs = sfpc_set_problems((int)$run['set_id']); $sc = $score($run); ?>
  <div class="sfpc-arena-h"><span class="tag">NOW PLAYING</span><h1><?= h($sets[(int)$run['set_id']]['title'] ?? '연습') ?></h1>
    <p>남은 시간은 위쪽 띠에 있습니다. 맞힌 문제 <b><?= (int)$sc['solved'] ?></b>개 · 벌점 <b><?= (int)$sc['penalty'] ?></b>분</p></div>
  <ol class="sfpc-plist">
    <?php foreach ($probs as $i => $p): $c = $sc['cells'][(int)$p['id']] ?? null; ?>
      <li class="<?= $c && $c['ok'] ? 'ok' : ($c && $c['tries'] ? 'bad' : '') ?>">
        <a href="problem.php?no=<?= (int)$p['prob_no'] ?>&amp;set=<?= (int)$run['set_id'] ?>">
          <span class="l"><?= sfpc_letter($i) ?></span><span class="t"><?= h($p['title']) ?></span>
          <span class="s"><?= $c && $c['ok'] ? '맞음 · ' . (int)$c['min'] . '분' . ($c['tries'] ? ' · 오답 ' . (int)$c['tries'] : '') : ($c && $c['pend'] ? '채점 중' : ($c && $c['tries'] ? '오답 ' . (int)$c['tries'] . '번' : '풀기 →')) ?></span>
        </a></li>
    <?php endforeach; ?>
  </ol>
  <details class="sfpc-giveup"><summary>포기하기</summary>
    <form method="post"><?= sfpc_csrf_field() ?><input type="hidden" name="act" value="giveup">
      <p>지금 판을 끝냅니다. 오늘 쓴 도전은 돌아오지 않고, 지금까지의 기록은 남습니다.</p>
      <label>정말 끝내려면 <b>포기</b> 라고 입력하세요 <input type="text" name="word" maxlength="4" autocomplete="off"></label>
      <button class="btn sm danger">이번 판 끝내기</button></form></details>

<?php else: /* ── 시작 전 ── */ ?>
  <div class="sfpc-arena-h"><span class="tag">PRACTICE</span><h1>연습</h1>
    <p>실제 대회처럼 <?= $MIN ?>분 동안 풉니다. 시작을 누르면 시계가 돌고, 멈출 수 없습니다. 다른 팀과 견주는 순위표는 없습니다.</p></div>
  <div class="sfpc-coins" aria-label="오늘 남은 도전 <?= max(0, $PER - $used) ?>판">
    <?php for ($i = 0; $i < $PER; $i++): ?><i class="<?= $i < $PER - $used ? 'on' : '' ?>"></i><?php endfor; ?>
    <span>오늘 남은 도전 <b><?= max(0, $PER - $used) ?></b> / <?= $PER ?> · 자정에 다시 찹니다</span>
  </div>
  <div class="sfpc-sets">
    <?php foreach ($sets as $sid => $s): $n = count(sfpc_set_problems($sid));
      $best = null; foreach ($past as $r) if ((int)$r['set_id'] === $sid) { $x = $score($r); if (!$best || $x['solved'] > $best['solved'] || ($x['solved'] === $best['solved'] && $x['penalty'] < $best['penalty'])) $best = $x; } ?>
      <form method="post" class="set"><?= sfpc_csrf_field() ?><input type="hidden" name="act" value="start"><input type="hidden" name="set" value="<?= (int)$sid ?>">
        <h2><?= h($s['title']) ?></h2>
        <p><?= $n ?>문제 · <?= $MIN ?>분</p>
        <p class="best"><?= $best ? '최고 기록 <b>' . (int)$best['solved'] . '문제</b> · 벌점 ' . (int)$best['penalty'] . '분' : '아직 기록이 없습니다' ?></p>
        <button class="big" <?= (!$team || !$n || $used >= $PER) ? 'disabled' : '' ?> onclick="return confirm('시작하면 <?= $MIN ?>분 시계가 돌고 멈출 수 없습니다. 오늘의 도전 한 판을 씁니다.\n시작할까요?')">
          <?= !$n ? '문제 준비 중' : ($used >= $PER ? '오늘은 끝 — 내일 다시' : 'START') ?></button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

  <?php if ($past): ?>
  <h2 class="sfpc-h2">지난 판</h2>
  <table class="sfpc-runs"><thead><tr><th>때</th><th>세트</th><th>맞힌 문제</th><th>벌점</th><th></th></tr></thead><tbody>
    <?php foreach ($past as $r): if ($run && (int)$r['id'] === (int)$run['id']) continue; $x = $score($r); ?>
      <tr><td><?= h(substr($r['started_at'], 5, 11)) ?></td><td><?= h($sets[(int)$r['set_id']]['title'] ?? '(없어진 세트)') ?></td>
        <td><b><?= (int)$x['solved'] ?></b></td><td><?= (int)$x['penalty'] ?>분</td><td><?= $r['ended_at'] !== '' ? '포기' : '' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php page_foot();
