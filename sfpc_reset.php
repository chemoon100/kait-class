<?php
/* ═══════════════════════════════════════════════
   sfpc_reset.php — 지도교사 아이디 · 비밀번호 찾기

   1) 가입할 때 적은 이메일(과 아이디)을 넣는다 → 그 주소로 아이디와 인증번호 여섯 자리를 보낸다.
   2) 인증번호와 새 비밀번호를 넣는다 → 바뀐다.
   맞는 계정이 있든 없든 화면은 똑같이 2) 로 넘어간다 (누가 가입했는지 떠보지 못하게).
   운영진 계정 · 팀 계정은 여기서 바꾸지 않는다. 메일 보내기가 꺼져 있으면 문의처만 알려 준다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u = me();
if ($u) { header('Location: ' . (($u['source'] ?? '') === SFPC_ACCOUNT_SOURCE ? 'sfpc_me.php' : 'password.php')); exit; }   /* 로그인한 사람은 비밀번호 바꾸기로 */

const SFPC_RESET_KEEP = 900;   /* 2) 화면을 이 시간(초) 안에 마쳐야 한다 */
$st = $_SESSION['sfpc_reset'] ?? null;
if ($st && time() - (int)$st['at'] > SFPC_RESET_KEEP) { unset($_SESSION['sfpc_reset']); $st = null; }
if (isset($_GET['again'])) { unset($_SESSION['sfpc_reset']); header('Location: sfpc_reset.php'); exit; }

$msg = ''; $err = '';
$in = ['id' => trim((string)($_POST['id'] ?? '')), 'email' => trim((string)($_POST['email'] ?? ''))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = (string)($_POST['act'] ?? '');
  if (!sfpc_csrf_ok()) {
    $err = '화면을 연 지 오래되었습니다. 다시 시도해 주세요.';
  } elseif (trim((string)($_POST['homepage'] ?? '')) !== '') {
    $err = '요청을 처리하지 못했습니다.';
  } elseif ($act === 'send') {
    $r = sfpc_reset_request($in['id'], $in['email']);
    if ($r['err'] !== '') $err = $r['err'];
    else {
      $_SESSION['sfpc_reset'] = $st = ['uid' => (int)$r['uid'], 'email' => $in['email'], 'at' => time()];
      header('Location: sfpc_reset.php'); exit;
    }
  } elseif ($act === 'done' && $st) {
    $pw = (string)($_POST['pw'] ?? '');
    if ($pw !== (string)($_POST['pw2'] ?? '')) $err = '새 비밀번호를 두 번 똑같이 입력해 주세요.';
    else {
      $err = sfpc_reset_finish((int)$st['uid'], (string)($_POST['code'] ?? ''), $pw);
      if ($err === '') {
        unset($_SESSION['sfpc_reset']);
        session_regenerate_id(true);
        $_SESSION['flash'] = ['msg' => '비밀번호를 다시 정했습니다. 새 비밀번호로 로그인해 주세요.', 'err' => ''];
        header('Location: sfpc_reset.php?ok=1'); exit;
      }
    }
  }
}
$done = isset($_GET['ok']);
if ($done) [$msg] = flash_take();
$mailOn = sfpc_mail_on();

page_head(['title' => '아이디 · 비밀번호 찾기', 'root' => '', 'user' => null, 'nav' => 'sfpc']);
sfpc_css();
?>
<div class="wrap loginwrap" style="max-width:480px; padding-top:44px">
  <div class="logincard sfpc-form">
    <h1>아이디 · 비밀번호 찾기</h1>

    <?php if ($done): ?>
      <div class="note ok"><?= h($msg ?: '비밀번호를 다시 정했습니다.') ?></div>
      <a class="btn primary big" href="login.php?next=<?= rawurlencode(sfpc_path('sfpc_teams.php')) ?>" style="display:block; text-align:center">로그인하기</a>

    <?php elseif (!$mailOn): ?>
      <div class="note info">지금은 메일로 찾을 수 없습니다. 운영진에게 문의해 주세요.<br>
        <a href="mailto:<?= h(sfpc_setting('sfpc_contact')) ?>"><?= h(sfpc_setting('sfpc_contact')) ?></a></div>

    <?php elseif (!$st): ?>
      <p class="small muted" style="margin:-8px 0 16px">지도교사로 가입할 때 적은 이메일 주소를 넣어 주세요. 그 주소로 <b>아이디와 인증번호</b>를 보내 드립니다.</p>
      <form method="post" action="sfpc_reset.php">
        <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="send">
        <div class="field">
          <label for="remail">이메일</label>
          <input type="email" id="remail" name="email" autocomplete="email" maxlength="100" required autofocus value="<?= h($in['email']) ?>">
        </div>
        <div class="field">
          <label for="rid">아이디 <span class="hint" style="display:inline">기억나면 적어 주세요 · 비워도 됩니다</span></label>
          <input type="text" id="rid" name="id" autocomplete="username" autocapitalize="off" maxlength="20" value="<?= h($in['id']) ?>">
        </div>
        <div style="position:absolute; left:-9999px" aria-hidden="true"><label>비워 두세요 <input type="text" name="homepage" tabindex="-1" autocomplete="off"></label></div>
        <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>
        <button class="btn primary big" type="submit">인증번호 받기</button>
      </form>
      <ul class="sfpc-verify-help">
        <li>팀 계정(sfpc로 시작하는 아이디)의 비밀번호는 지도교사 선생님이 "내 팀" 화면에서 다시 정합니다.</li>
        <li>가입할 때 적은 이메일을 쓸 수 없으면 운영진에게 문의해 주세요. <?= h(sfpc_setting('sfpc_contact')) ?></li>
      </ul>

    <?php else: ?>
      <p class="small muted" style="margin:-8px 0 10px">아래 주소로 가입한 계정이 있으면 <b>아이디와 인증번호</b>를 보냈습니다. 메일을 확인해 주세요.</p>
      <span class="sfpc-verify-mail"><?= h($st['email']) ?></span>
      <form method="post" action="sfpc_reset.php">
        <?= sfpc_csrf_field() ?><input type="hidden" name="act" value="done">
        <div class="field">
          <label for="rcode">인증번호 여섯 자리</label>
          <input class="sfpc-code" type="text" id="rcode" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus>
          <span class="hint">메일 제목에도 적혀 있습니다 · <?= SFPC_CODE_MIN ?>분 동안 쓸 수 있습니다</span>
        </div>
        <div class="row">
          <div class="field">
            <label for="rpw">새 비밀번호</label>
            <input type="password" id="rpw" name="pw" autocomplete="new-password" required>
            <span class="hint">8자 이상</span>
          </div>
          <div class="field">
            <label for="rpw2">새 비밀번호 확인</label>
            <input type="password" id="rpw2" name="pw2" autocomplete="new-password" required>
          </div>
        </div>
        <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>
        <button class="btn primary big" type="submit">비밀번호 다시 정하기</button>
      </form>
      <div class="sfpc-verify-sub"><a class="btn" href="sfpc_reset.php?again=1">처음부터 다시</a><a class="btn" href="login.php">로그인으로</a></div>
      <ul class="sfpc-verify-help">
        <li>메일이 보이지 않으면 <b>스팸함 · 프로모션함</b>을 확인해 주세요. 1~2분 걸릴 수 있습니다.</li>
        <li>메일이 오지 않으면 가입할 때 다른 주소를 적었을 수 있습니다. "처음부터 다시"로 다른 주소를 넣어 보세요.</li>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php sfpc_pw_eye(); ?>
<?php page_foot(); ?>
