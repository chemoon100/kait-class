<?php
/* ═══════════════════════════════════════════════
   sfpc_join.php — 지도교사(신청인) 가입과 정보 수정

   세 가지 경우를 한 화면에서 다룬다.
     · 로그인 전            → 계정을 만들고 이름 · 연락처를 등록한다
     · 로그인했지만 정보 없음 → 이름 · 연락처만 등록한다 (기존 회원 · 운영진)
     · 이미 등록함           → 이름 · 연락처를 고친다

   일반 회원가입(signup.php)과 달리 승인을 기다리지 않는다. 접수 기간이 정해져 있어
   승인을 기다리게 하면 신청을 놓친다. 계정의 source 는 'sfpc' 로 남겨 구분한다.
   ═══════════════════════════════════════════════ */
declare(strict_types=1);
require __DIR__ . '/guard.php';
require __DIR__ . '/layout.php';
$u     = me();
sfpc_coach_only($u);
$coach = sfpc_coach($u);

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^sfpc_[a-z_]+\.php(\?[A-Za-z0-9_=&-]*)?$#', $next)) $next = 'sfpc_teams.php';

const SFPC_ID_RE = '/^[A-Za-z][A-Za-z0-9_.-]{3,19}$/';

/* 새로 가입하는 것은 접수를 받을 수 있을 때만. 이미 가입한 사람의 정보 수정은 언제든 된다. */
$needAccount = ($u === null);
if ($needAccount && !sfpc_can_edit($u)) { header('Location: sfpc.php'); exit; }

$err = '';
$in = [
  'id'    => trim((string)($_POST['id'] ?? '')),
  'name'  => sfpc_clean((string)($_POST['name'] ?? ($coach['name'] ?? '')), 20),
  'phone' => trim((string)($_POST['phone'] ?? ($coach['phone'] ?? ''))),
  'email' => trim((string)($_POST['email'] ?? ($coach['email'] ?? ''))),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pw    = (string)($_POST['pw'] ?? '');
  $pw2   = (string)($_POST['pw2'] ?? '');
  $phone = sfpc_phone($in['phone']);

  if (!sfpc_csrf_ok()) {
    $err = '화면을 연 지 오래되었습니다. 다시 시도해 주세요.';
  } elseif (trim((string)($_POST['homepage'] ?? '')) !== '') {
    $err = '신청을 처리하지 못했습니다.';            /* 사람 눈에 안 보이는 칸을 채웠다 = 자동 프로그램 */
  } elseif ($needAccount && !preg_match(SFPC_ID_RE, $in['id'])) {
    $err = '아이디는 영문으로 시작하는 4~20자여야 합니다. 영문 · 숫자 · 밑줄 · 점 · 붙임표만 쓸 수 있습니다.';
  } elseif ($needAccount && strlen($pw) < 8) {
    $err = '비밀번호는 8자 이상이어야 합니다.';
  } elseif ($needAccount && $pw !== $pw2) {
    $err = '비밀번호가 서로 다릅니다.';
  } elseif (ustrlen($in['name']) < 2) {
    $err = '이름을 입력하세요.';
  } elseif ($phone === '') {
    $err = '연락처를 확인해 주세요. 예: 010-1234-5678';
  } elseif ($in['email'] === '') {
    $err = '이메일 주소를 입력하세요. 대회 안내를 이 주소로 보내 드립니다.';
  } elseif (!filter_var($in['email'], FILTER_VALIDATE_EMAIL) || strlen($in['email']) > 100) {
    $err = '이메일 주소를 확인해 주세요.';
  } elseif (($typo = sfpc_email_typo($in['email'])) !== '') {
    $err = '이메일 주소의 "' . $typo[0] . '" 는 "' . $typo[1] . '" 의 오타로 보입니다. 확인해 주세요.';
  } elseif (strcasecmp($in['email'], trim((string)($_POST['email2'] ?? ''))) !== 0) {
    $err = '이메일 주소를 두 번 똑같이 입력해 주세요.';
  } elseif ($needAccount && one("SELECT 1 FROM users WHERE login_id = ?", [$in['id']])) {
    $err = '이미 쓰이고 있는 아이디입니다.';
  } else {
    try {
      $uid = tx(function (PDO $d) use ($needAccount, $u, $in, $pw, $phone) {
        if ($needAccount) {
          $d->prepare("INSERT INTO users(login_id, pw_hash, name, role, group_id, source, approved, created_at)
                       VALUES(?,?,?,'student',NULL,'sfpc',1,?)")
            ->execute([$in['id'], password_hash($pw, PASSWORD_DEFAULT), $in['name'], now()]);
          $uid = (int)$d->lastInsertId();
        } else {
          $uid = (int)$u['id'];
        }
        $d->prepare("INSERT INTO sfpc_coaches(user_id, name, phone, email, created_at) VALUES(?,?,?,?,?)
                     ON CONFLICT(user_id) DO UPDATE SET name = excluded.name, phone = excluded.phone, email = excluded.email")
          ->execute([$uid, $in['name'], $phone, $in['email'], now()]);
        return $uid;
      });
      if ($needAccount) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
      }
      if ($coach) {
        $chg = [];
        if ($coach['name']  !== $in['name'])  $chg[] = '이름: ' . $coach['name'] . ' → ' . $in['name'];
        if ($coach['phone'] !== $phone)       $chg[] = '연락처: ' . $coach['phone'] . ' → ' . $phone;
        if ($coach['email'] !== $in['email']) $chg[] = '이메일: ' . ($coach['email'] ?: '없음') . ' → ' . ($in['email'] ?: '없음');
        if ($chg) sfpc_log(null, (int)$uid, $u, 'coach', implode("\n", $chg));
      }
      $_SESSION['flash'] = ['msg' => $coach ? '정보를 고쳤습니다.' : '등록했습니다. 이제 팀을 신청할 수 있습니다.', 'err' => ''];
      header('Location: ' . $next);
      exit;
    } catch (PDOException $e) {
      /* 같은 아이디로 동시에 눌린 경우 — UNIQUE 제약이 잡아 준다 */
      $err = '이미 쓰이고 있는 아이디입니다.';
    }
  }
}

page_head(['title' => $coach ? '내 정보' : '지도교사 가입', 'root' => '', 'user' => $u, 'nav' => 'sfpc']);
sfpc_css();
?>
<div class="wrap loginwrap" style="max-width:480px; padding-top:44px">
  <form class="logincard sfpc-form" method="post" action="sfpc_join.php">
    <h1><?= $coach ? '내 정보 수정' : ($needAccount ? '지도교사 가입' : '지도교사 정보 등록') ?></h1>
    <?= sfpc_csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <p class="small muted" style="margin:-8px 0 18px">
      <?php if ($needAccount): ?>
        <?= h(sfpc_setting('sfpc_title')) ?> 참가 신청은 지도교사(교사 · 예비교사 팀은 대표자)가 합니다.
        한 번 가입하면 여러 팀을 신청하고 고칠 수 있습니다.
        이미 가입하셨다면 <a href="login.php?next=<?= rawurlencode(sfpc_path($next)) ?>">로그인</a>하세요.
      <?php elseif (!$coach): ?>
        <b><?= h($u['login_id']) ?></b> 계정으로 신청합니다. 연락받을 이름과 연락처를 등록해 주세요.
      <?php else: ?>
        대회 안내와 계정 전달에 쓰는 정보입니다.
      <?php endif; ?>
    </p>

    <?php if ($needAccount): ?>
      <div class="field">
        <label for="jid">아이디</label>
        <input type="text" id="jid" name="id" autocomplete="username" autocapitalize="off" autofocus
               value="<?= h($in['id']) ?>">
        <span class="hint">영문으로 시작하는 4~20자</span>
      </div>
      <div class="row">
        <div class="field">
          <label for="jpw">비밀번호</label>
          <input type="password" id="jpw" name="pw" autocomplete="new-password">
          <span class="hint">8자 이상</span>
        </div>
        <div class="field">
          <label for="jpw2">비밀번호 확인</label>
          <input type="password" id="jpw2" name="pw2" autocomplete="new-password">
        </div>
      </div>
    <?php endif; ?>

    <div class="field">
      <label for="jname">이름</label>
      <input type="text" id="jname" name="name" autocomplete="name" maxlength="20" value="<?= h($in['name']) ?>">
    </div>
    <div class="field">
      <label for="jphone">연락처</label>
      <input type="tel" id="jphone" name="phone" autocomplete="tel" inputmode="tel" placeholder="010-1234-5678"
             value="<?= h($in['phone']) ?>">
      <span class="hint">대회 안내와 계정 전달에 씁니다</span>
    </div>
    <div class="field">
      <label for="jemail">이메일</label>
      <input type="email" id="jemail" name="email" autocomplete="email" maxlength="100" required value="<?= h($in['email']) ?>">
      <span class="hint">대회 안내를 이 주소로 보냅니다. 자주 확인하는 주소를 적어 주세요</span>
    </div>
    <div class="field">
      <label for="jemail2">이메일 확인</label>
      <input type="email" id="jemail2" name="email2" autocomplete="off" maxlength="100" required
             value="<?= h($_SERVER['REQUEST_METHOD'] === 'POST' ? trim((string)($_POST['email2'] ?? '')) : $in['email']) ?>">
      <span class="hint" id="jemailMsg">같은 주소를 한 번 더 적어 주세요 (붙여 넣지 말고 직접)</span>
    </div>
    <script>
    (function () {
      var a = document.getElementById('jemail'), b = document.getElementById('jemail2'), m = document.getElementById('jemailMsg');
      var TYPO = <?= json_encode(SFPC_EMAIL_TYPO) ?>;
      b.addEventListener('paste', function (e) { e.preventDefault(); });
      function chk() {
        var v = a.value.trim().toLowerCase(), w = b.value.trim().toLowerCase(), d = v.split('@')[1] || '';
        if (TYPO[d])            { m.textContent = '"' + d + '" 는 "' + TYPO[d] + '" 의 오타로 보입니다.'; m.style.color = '#B42318'; }
        else if (w && v !== w)  { m.textContent = '두 주소가 서로 다릅니다.'; m.style.color = '#B42318'; }
        else if (w && v === w)  { m.textContent = '두 주소가 같습니다.'; m.style.color = '#1F7A4D'; }
        else                    { m.textContent = '같은 주소를 한 번 더 적어 주세요 (붙여 넣지 말고 직접)'; m.style.color = ''; }
      }
      a.addEventListener('input', chk); b.addEventListener('input', chk); chk();
    })();
    </script>
    <div style="position:absolute; left:-9999px" aria-hidden="true">
      <label>비워 두세요 <input type="text" name="homepage" tabindex="-1" autocomplete="off"></label>
    </div>

    <?php if ($err): ?><div class="note err"><?= h($err) ?></div><?php endif; ?>

    <button class="btn primary big" type="submit"><?= $coach ? '저장' : ($needAccount ? '가입하고 팀 신청하기' : '등록하고 팀 신청하기') ?></button>
    <?php if ($needAccount): ?>
      <p class="small muted" style="margin:14px 0 0; text-align:center">
        비밀번호를 잊으면 운영진에게 문의해 주세요. 직접 찾는 기능은 없습니다.
      </p>
    <?php endif; ?>
  </form>
</div>
<?php sfpc_pw_eye(); ?>
<?php page_foot(); ?>
