<?php
/* ═══════════════════════════════════════════════
   sfpc_verify.php — 지도교사 이메일 인증

   가입하거나 이메일 주소를 바꾸면 여기로 온다. 메일로 받은 여섯 자리를 넣으면 인증된다.
   인증번호가 오지 않아도 접수는 할 수 있다 — "나중에 인증"으로 넘어가면 내 팀 화면에 안내 띠가 남는다.
   메일 보내기가 꺼져 있으면(sfpc-mail.json 없음) 이 화면은 그냥 다음 화면으로 보낸다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = need_login();
sfpc_coach_only($u);
$coach = sfpc_coach($u);

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^sfpc_[a-z_]+\.php(\?[A-Za-z0-9_=&-]*)?$#', $next)) $next = 'sfpc_teams.php';
if (!$coach) { header('Location: sfpc_join.php?next=' . rawurlencode($next)); exit; }

$go = function (string $msg) use ($next): never {
  if ($msg !== '') $_SESSION['flash'] = ['msg' => $msg, 'err' => ''];
  header('Location: ' . $next); exit;
};
if (!sfpc_mail_on())       $go('');
if (sfpc_verified($coach)) $go('');

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = (string)($_POST['act'] ?? '');
  if (!sfpc_csrf_ok()) {
    $err = '화면을 연 지 오래되었습니다. 다시 시도해 주세요.';
  } elseif ($act === 'send') {
    $err = sfpc_verify_send($coach);
    if ($err === '') $msg = '인증번호를 보냈습니다. 메일함을 확인해 주세요.';
  } elseif ($act === 'check') {
    $err = sfpc_verify_check($coach, (string)($_POST['code'] ?? ''), $u);
    if ($err === '') $go('이메일 인증을 마쳤습니다.');
  }
} else {
  [$msg, $err] = flash_take();
}
$sent = (bool)one("SELECT 1 x FROM sfpc_email_codes WHERE user_id = ? AND used = 0 AND expires_at > ? AND lower(email) = lower(?)", [$u['id'], now(), $coach['email']]);
$wait = sfpc_verify_wait($coach);

page_head(['title' => '이메일 인증', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<div class="wrap loginwrap" style="max-width:480px; padding-top:44px">
  <div class="logincard sfpc-form">
    <h1>이메일 인증</h1>
    <p class="small muted" style="margin:-8px 0 10px">대회 안내와 팀 계정 안내를 아래 주소로 보내 드립니다. 받을 수 있는 주소인지 확인합니다.</p>
    <span class="sfpc-verify-mail"><?= h($coach['email']) ?></span>

    <?php if ($msg): ?><div class="note ok"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>

    <?php if ($sent): ?>
      <form method="post" action="sfpc_verify.php">
        <?= sfpc_csrf_field() ?><input type="hidden" name="next" value="<?= h($next) ?>"><input type="hidden" name="act" value="check">
        <div class="field">
          <label for="vcode">인증번호 여섯 자리</label>
          <input class="sfpc-code" type="text" id="vcode" name="code" inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9 ]*" maxlength="7" autofocus required>
          <span class="hint">메일 제목에도 인증번호가 적혀 있습니다 · <?= SFPC_CODE_MIN ?>분 동안 쓸 수 있습니다</span>
        </div>
        <button class="btn primary big" type="submit">인증하기</button>
      </form>
    <?php endif; ?>

    <form method="post" action="sfpc_verify.php" class="sfpc-verify-sub">
      <?= sfpc_csrf_field() ?><input type="hidden" name="next" value="<?= h($next) ?>"><input type="hidden" name="act" value="send">
      <button class="btn<?= $sent ? '' : ' primary big' ?>" type="submit" id="vsend" data-wait="<?= (int)$wait ?>"><?= $sent ? '인증번호 다시 받기' : '인증번호 받기' ?></button>
      <a class="btn" href="sfpc_join.php?next=<?= rawurlencode($next) ?>">이메일 주소 고치기</a>
      <a class="btn" href="<?= h($next) ?>">나중에 인증</a>
    </form>

    <ul class="sfpc-verify-help">
      <li>메일이 보이지 않으면 <b>스팸함 · 프로모션함</b>을 확인해 주세요. 도착까지 1~2분 걸릴 수 있습니다.</li>
      <li>학교 · 교육청 메일은 바깥 메일을 늦게 받거나 막는 경우가 있습니다. 오지 않으면 다른 주소로 고쳐 주세요.</li>
      <li>인증하지 않아도 <b>접수는 할 수 있습니다.</b> "나중에 인증"을 누르면 내 팀 화면에서 언제든 인증할 수 있습니다.</li>
    </ul>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('vsend'), n = parseInt(b.getAttribute('data-wait'), 10) || 0, t = b.textContent;
  if (n <= 0) return;
  b.disabled = true;
  (function tick() {
    if (n <= 0) { b.disabled = false; b.textContent = t; return; }
    b.textContent = t + ' (' + n + '초)'; n--; setTimeout(tick, 1000);
  })();
})();
</script>
<?php page_foot(); ?>
