#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
#  SFPC 채점 전용 서버 설치
#
#  본서버(사이트)와 따로 두는 채점 서버에 Judge0 만 설치한다.
#  웹 서버 · PHP · 사이트 소스는 설치하지 않는다.
#
#  사용법 (소스를 받은 폴더에서, root 로):
#      bash sfpc-judge-install.sh --allow <본서버 주소> [--allow <본서버 사설 주소>]
#
#  ● 두 번에 나뉜다. 처음 실행하면 부팅 설정(cgroup v1)을 바꾸고 재부팅을 묻는다.
#    재부팅한 뒤 똑같은 명령을 한 번 더 실행하면 나머지를 설치한다.
#  ● 몇 번을 다시 실행해도 안전하다. --allow 를 바꿔 다시 실행하면 허용 주소만 바뀐다.
#  ● 채점 포트(2358)는 --allow 로 적은 주소에서만 열린다. 그 밖에서는 닫혀 있다.
#  ● 인증 글자열은 설치할 때 무작위로 만들어 /root/sfpc-judge-token.txt 에 둔다.
#    화면에는 보여 주지 않는다. 직접 정하고 싶으면:
#        bash sfpc-judge-install.sh --token
#  ● 대상: Ubuntu 24.04 LTS, x86_64
#
#  원본 install.sh 의 Judge0 설치 절차를 그대로 따랐다 (판 · 비밀번호 처리 · cgroup v1).
# ═══════════════════════════════════════════════════════════════
set -Eeuo pipefail

JUDGE0_VER="1.13.1"
JUDGE0_DIR="/opt/judge0-v${JUDGE0_VER}"
JUDGE0_ZIP_URL="https://github.com/judge0/judge0/releases/download/v${JUDGE0_VER}/judge0-v${JUDGE0_VER}.zip"
GRUB_DROPIN="/etc/default/grub.d/99-sfpc-judge.cfg"
CGROUP_PARAM="systemd.unified_cgroup_hierarchy=0"
STATE_DIR="/var/lib/sfpc-judge"
LOG="/var/log/sfpc-judge-install.log"
TOKEN_FILE="/root/sfpc-judge-token.txt"
FW_SCRIPT="/usr/local/sbin/sfpc-judge-fw"
FW_UNIT="/etc/systemd/system/sfpc-judge-fw.service"
PORT=2358

export DEBIAN_FRONTEND=noninteractive
APT=(apt-get -y -q -o DPkg::Lock::Timeout=600
     -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

say()  { printf '%s\n' "$*"; }
line() { say "────────────────────────────────────────────────────────"; }
die()  { say; say "  ✗ $*"; say "  기록: $LOG"; exit 1; }

[[ $EUID -eq 0 ]] || { echo "root 로 실행하세요."; exit 1; }
mkdir -p "$STATE_DIR"

# ── 인자 ────────────────────────────────────────────────────────
ALLOW=()
DO_TOKEN=0
while (( $# )); do
  case "$1" in
    --allow) [[ $# -ge 2 ]] || die "--allow 뒤에 주소가 없습니다"; ALLOW+=("$2"); shift 2 ;;
    --token) DO_TOKEN=1; shift ;;
    *) die "모르는 옵션: $1" ;;
  esac
done
for a in "${ALLOW[@]:-}"; do
  [[ -z "$a" || "$a" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$ ]] || die "주소 모양이 아닙니다: $a"
done

conf_set() {  # conf_set 이름 값 — judge0.conf 의 한 줄을 바꾼다 (없으면 덧붙인다)
  local k="$1" v="$2" f="$JUDGE0_DIR/judge0.conf"
  if grep -qE "^${k}=" "$f"; then sed -i -E "s|^${k}=.*$|${k}=${v}|" "$f"; else echo "${k}=${v}" >> "$f"; fi
}
conf_get() { grep -E "^$1=" "$JUDGE0_DIR/judge0.conf" | head -1 | cut -d= -f2-; }

# ── 인증 글자열을 직접 정하기 ───────────────────────────────────
if (( DO_TOKEN )); then
  [[ -f "$JUDGE0_DIR/judge0.conf" ]] || die "아직 설치되지 않았습니다. 먼저 설치하세요."
  say "새 인증 글자열을 입력하세요 (영문 · 숫자 · - · _ 만, 16자 이상). 화면에 보이지 않습니다."
  read -r -s -p "  인증 글자열: " t1 < /dev/tty; echo
  read -r -s -p "  한 번 더:    " t2 < /dev/tty; echo
  [[ "$t1" == "$t2" ]] || die "두 번 입력한 것이 다릅니다."
  [[ "$t1" =~ ^[A-Za-z0-9_-]{16,}$ ]] || die "영문 · 숫자 · - · _ 로 16자 이상이어야 합니다."
  conf_set AUTHN_TOKEN "$t1"
  ( umask 077; printf '%s\n' "$t1" > "$TOKEN_FILE" )
  ( cd "$JUDGE0_DIR" && docker compose up -d --force-recreate server workers ) >> "$LOG" 2>&1
  say "  바꿨습니다. 본서버의 서버 모니터링 화면에도 같은 글자열을 넣어 주세요."
  exit 0
fi

# 허용 주소는 한 번 적으면 기억한다
if (( ${#ALLOW[@]} )); then printf '%s\n' "${ALLOW[@]}" | awk 'NF && !seen[$0]++' > "$STATE_DIR/allow"; fi
[[ -s "$STATE_DIR/allow" ]] || die "본서버 주소가 필요합니다:  bash sfpc-judge-install.sh --allow <본서버 주소>"

step() { printf '  %-44s' "$1"; printf '\n===== %s (%s) =====\n' "$1" "$(date '+%F %T')" >> "$LOG"; }
ok()   { printf '%s\n' "${1:-완료}"; }
run()  { "$@" >> "$LOG" 2>&1 || die "실패: $*  (아래는 마지막 기록)$(printf '\n'; tail -n 12 "$LOG" | sed 's/^/    │ /')"; }

say; line
say "  SFPC 채점 전용 서버 설치"
say "  채점 포트를 열어 줄 주소: $(paste -sd' ' "$STATE_DIR/allow")"
say "  기록: $LOG"
line

# ── 1. 환경 ─────────────────────────────────────────────────────
step "설치 환경 확인"
. /etc/os-release
[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] || die "Ubuntu 24.04 가 아닙니다: ${PRETTY_NAME:-?}"
[[ "$(uname -m)" == "x86_64" ]] || die "x86_64 가 아닙니다: $(uname -m)"
ok "완료 (코어 $(nproc) · 메모리 $(free -h | awk '/^Mem/{print $2}'))"

step "기본 패키지"
run "${APT[@]}" update
run "${APT[@]}" install curl wget unzip ca-certificates openssl iptables
ok

# ── 2. cgroup v1 ────────────────────────────────────────────────
step "채점 격리 설정 (cgroup v1)"
if [[ "$(stat -fc %T /sys/fs/cgroup)" != "tmpfs" ]]; then
  if grep -qw "$CGROUP_PARAM" /proc/cmdline; then
    die "부팅 인자가 적용됐는데도 cgroup v1 이 아닙니다. 이 운영체제에서는 Judge0 가 돌지 않습니다."
  fi
  mkdir -p "$(dirname "$GRUB_DROPIN")"
  cat > "$GRUB_DROPIN" <<EOF
# SFPC 채점 서버: Judge0 의 격리 기능이 cgroup v1 을 필요로 한다
GRUB_CMDLINE_LINUX_DEFAULT="\${GRUB_CMDLINE_LINUX_DEFAULT} $CGROUP_PARAM"
EOF
  run update-grub
  ok "완료 (재부팅 필요)"
  say; line
  say "  재부팅이 필요합니다. 재부팅한 뒤 같은 명령을 한 번 더 실행하세요."
  say "      cd \"$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)\" && bash sfpc-judge-install.sh"
  line
  if [[ -t 0 ]]; then
    read -r -p "  지금 재부팅할까요? [Y/n] " ans || ans=n
    if [[ -z "$ans" || "$ans" =~ ^[Yy] ]]; then say "  재부팅합니다…"; sleep 2; systemctl reboot; fi
  fi
  exit 0
fi
ok "완료 (cgroup v1 확인)"

# ── 3. Docker ───────────────────────────────────────────────────
step "Docker"
if dpkg -s docker-ce > /dev/null 2>&1 && docker compose version > /dev/null 2>&1; then
  run systemctl enable --now docker
  ok "완료 (이미 설치됨)"
else
  for p in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
    if dpkg -s "$p" > /dev/null 2>&1; then run "${APT[@]}" remove "$p"; fi
  done
  run install -m 0755 -d /etc/apt/keyrings
  run curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  run "${APT[@]}" update
  run "${APT[@]}" install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  run systemctl enable --now docker
  ok
fi

# ── 4. 방화벽 — 채점 포트는 본서버에서만 ────────────────────────
#   Docker 가 연 포트는 ufw 를 거치지 않는다. Docker 가 비워 두는 DOCKER-USER 사슬에 직접 건다.
#   컨테이너를 띄우기 전에 먼저 걸어, 잠깐이라도 바깥에 열리는 일이 없게 한다.
step "방화벽 (채점 포트는 본서버에서만)"
cat > "$FW_SCRIPT" <<EOF
#!/usr/bin/env bash
# SFPC 채점 서버: $PORT 포트를 허용한 주소에서만 받는다. sfpc-judge-install.sh 가 만든 파일.
set -e
iptables -N DOCKER-USER 2>/dev/null || true
iptables -N SFPC-JUDGE 2>/dev/null || true
iptables -F SFPC-JUDGE
while read -r ip; do
  [ -n "\$ip" ] && iptables -A SFPC-JUDGE -s "\$ip" -j RETURN
done < "$STATE_DIR/allow"
iptables -A SFPC-JUDGE -j DROP
iptables -C DOCKER-USER -p tcp -m conntrack --ctorigdstport $PORT --ctdir ORIGINAL -j SFPC-JUDGE 2>/dev/null \\
  || iptables -I DOCKER-USER -p tcp -m conntrack --ctorigdstport $PORT --ctdir ORIGINAL -j SFPC-JUDGE
EOF
chmod 755 "$FW_SCRIPT"
cat > "$FW_UNIT" <<EOF
[Unit]
Description=SFPC judge firewall (port $PORT only from the main server)
After=docker.service
Requires=docker.service
PartOf=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$FW_SCRIPT

[Install]
WantedBy=multi-user.target docker.service
EOF
run systemctl daemon-reload
run systemctl enable sfpc-judge-fw.service
run systemctl restart sfpc-judge-fw.service
ok

# ── 5. Judge0 ───────────────────────────────────────────────────
step "채점 엔진 Judge0 (처음엔 오래 걸립니다)"
if [[ ! -f "$JUDGE0_DIR/docker-compose.yml" ]]; then
  tmp=$(mktemp -d)
  run wget -q -O "$tmp/judge0.zip" "$JUDGE0_ZIP_URL"
  run unzip -oq "$tmp/judge0.zip" -d /opt
  rm -rf "$tmp"
fi
cd "$JUDGE0_DIR"
# 비밀번호: 비어 있을 때만 만든다 (DB 볼륨이 옛 비밀번호를 기억한다)
for k in POSTGRES_PASSWORD REDIS_PASSWORD; do
  if grep -qE "^${k}=\s*$" judge0.conf; then sed -i -E "s/^${k}=\s*$/${k}=$(openssl rand -hex 16)/" judge0.conf; fi
done
# 인증 글자열: 비어 있을 때만 만든다. 화면과 기록에는 남기지 않는다.
if [[ -z "$(conf_get AUTHN_TOKEN)" ]]; then
  conf_set AUTHN_TOKEN "$(openssl rand -hex 12)"
fi
( umask 077; conf_get AUTHN_TOKEN > "$TOKEN_FILE" )
chmod 644 judge0.conf
# 바깥(본서버)에서 닿아야 하므로 모든 주소에서 듣게 둔다. 누가 닿을 수 있는지는 위의 방화벽이 정한다.
sed -i -E 's/^(\s*-\s*)"127\.0\.0\.1:2358:2358"/\1"2358:2358"/' docker-compose.yml
grep -qE '^\s*-\s*"2358:2358"' docker-compose.yml || die "docker-compose.yml 에서 포트 줄을 찾지 못했습니다."

existed=$(docker compose ps -aq server 2>/dev/null || true)
run docker compose pull
run docker compose up -d db redis
sleep 10
run docker compose up -d
if [[ -n "$existed" ]]; then run docker compose up -d --force-recreate server workers; fi

TOKEN="$(cat "$TOKEN_FILE")"
up=0
for _ in $(seq 1 60); do
  if curl -fsS --max-time 5 -H "X-Auth-Token: $TOKEN" "http://127.0.0.1:$PORT/about" >> "$LOG" 2>&1; then up=1; break; fi
  sleep 5
done
(( up )) || { docker compose ps >> "$LOG" 2>&1; docker compose logs --tail 30 server workers >> "$LOG" 2>&1; die "채점 엔진이 응답하지 않습니다."; }
ok

# ── 6. 시험 ─────────────────────────────────────────────────────
step "채점 시험"
try() {
  curl -fsS --max-time 60 -X POST "http://127.0.0.1:$PORT/submissions?base64_encoded=false&wait=true" \
    -H 'Content-Type: application/json' -H "X-Auth-Token: $TOKEN" \
    -d "$(printf '{"source_code":%s,"language_id":%s}' "$2" "$1")"
}
py='"print(1+2)"'
c='"#include <stdio.h>\nint main(){printf(\"3\\n\");return 0;}"'
for pair in "71:$py" "50:$c"; do
  good=0
  for _ in 1 2 3 4 5; do
    out=$(try "${pair%%:*}" "${pair#*:}" 2>> "$LOG" || true)
    echo "언어 ${pair%%:*}: $out" >> "$LOG"
    if grep -q '"description":"Accepted"' <<< "$out" && grep -q '"stdout":"3\\n"' <<< "$out"; then good=1; break; fi
    sleep 5
  done
  (( good )) || die "채점 시험 실패 (언어 ${pair%%:*})"
done
ok "완료 (Python · C 정답 확인)"

step "인증 없이는 거절하는지"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$PORT/workers" || true)
[[ "$code" == "401" || "$code" == "403" ]] || die "인증 글자열 없이도 응답합니다 (HTTP $code). 중단합니다."
ok "완료 (HTTP $code)"

# ── 끝 ──────────────────────────────────────────────────────────
info=$(curl -fsS --max-time 5 -H "X-Auth-Token: $TOKEN" "http://127.0.0.1:$PORT/config_info" 2>/dev/null || true)
pick() { grep -oE "\"$1\":[^,}]*" <<< "$info" | head -1 | cut -d: -f2; }
workers=$(curl -fsS --max-time 5 -H "X-Auth-Token: $TOKEN" "http://127.0.0.1:$PORT/workers" 2>/dev/null | grep -oE '"available":[0-9]+' | head -1 | cut -d: -f2 || true)
say; line
say "  채점 서버 설치가 끝났습니다."
say
say "  주소              http://$(hostname -I | awk '{print $1}'):$PORT   (사설 주소가 따로 있으면 그것도 됩니다: $(hostname -I))"
say "  인증 글자열       $TOKEN_FILE 에 있습니다 (보기: cat $TOKEN_FILE)"
say "  채점 일꾼         ${workers:-?} 개"
say "  대기열 크기       $(pick max_queue_size)"
say "  출력 크기 상한    $(pick max_max_file_size) KB (기본 $(pick max_file_size) KB)"
say "  스택 상한         $(pick max_stack_limit) KB (기본 $(pick stack_limit) KB)"
say "  메모리 상한       $(pick max_memory_limit) KB (기본 $(pick memory_limit) KB)"
say "  시간 상한         CPU $(pick max_cpu_time_limit) 초 · 실제 $(pick max_wall_time_limit) 초"
say
say "  본서버의 운영 > SFPC > 서버 모니터링 > 채점 서버 등록에 위 주소와 인증 글자열을 넣으세요."
say "  채점 포트는 $(paste -sd' ' "$STATE_DIR/allow") 에서만 열립니다."
line
