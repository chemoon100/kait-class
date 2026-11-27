#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
#  SFPC 옛 스코어보드(2021~2024, Spotboard) 옮겨 오기
#
#  sfpc.kr 주소를 새 서버로 돌리기 "전에" 새 서버에서 실행한다.
#  지금 sfpc.kr 에 떠 있는 파일을 그대로 받아 웹 폴더의 /2021sfpc … /2024sfpc 에 둔다.
#  주소를 돌린 뒤에도 https://sfpc.kr/2024sfpc/ 같은 옛 주소가 그대로 열린다.
#
#      bash sfpc-old-boards.sh                # https://sfpc.kr 에서 받는다
#      bash sfpc-old-boards.sh 주소           # 다른 곳에서 받을 때 (예: 옛 서버의 IP 주소)
#
#  ● Spotboard 는 파일만으로 도는 화면이다 (index.html · js · css · contest.json · runs.json). 서버 프로그램이 없다.
#  ● 몇 번을 다시 실행해도 된다. 못 받은 파일은 마지막에 알려 준다.
#  ● install.sh 는 업데이트할 때 이 폴더들을 지우지 않는다 (rsync 제외 목록의 /20??sfpc/).
# ═══════════════════════════════════════════════════════════════
set -uo pipefail
FROM="${1:-https://sfpc.kr}"; FROM="${FROM%/}"
WEB_ROOT="/var/www/html"
YEARS=(2021sfpc 2022sfpc 2023sfpc 2024sfpc)
FILES=(index.html config.js contest.json runs.json
  css/normalize.css css/scoreboard.css css/jquery.badger.css css/balloon.min.css
  js/require-config.js js/lib/require.js js/spotboard-all.min.js
  img/timeline.png img/events.png img/control_end_blue.png img/control_pause_blue.png img/control_fastforward_blue.png
  img/control_play_blue.png img/control_start_blue.png img/control_rewind_blue.png img/eye.png img/eye_close.png img/update.png img/magnifier.png
  assets/open_sans_light.woff assets/open_sans_regular.woff assets/open_sans_semibold.woff)
# 풍선 그림은 문제 색마다 다르다. 있는 것만 받는다.
COLORS=(gold yellow orange lightblue violet blue purple darkgreen lime pink deeppink ivory red white gray skyblue
        green black brown silver cyan magenta navy olive teal maroon beige coral indigo khaki salmon turquoise aqua)
OPTIONAL=(temp/award_slide.json changed_runs.json favicon.ico)

[[ $EUID -eq 0 ]] || { echo "root 로 실행하세요."; exit 1; }
[[ -d "$WEB_ROOT" ]] || { echo "$WEB_ROOT 가 없습니다. 먼저 install.sh 로 설치하세요."; exit 1; }

missing=0
for y in "${YEARS[@]}"; do
  dst="$WEB_ROOT/$y"; got=0; lost=()
  for f in "${FILES[@]}"; do
    mkdir -p "$dst/$(dirname "$f")"
    if curl -fsSL --max-time 60 -o "$dst/$f.part" "$FROM/$y/$f"; then mv "$dst/$f.part" "$dst/$f"; got=$((got+1)); else rm -f "$dst/$f.part"; lost+=("$f"); fi
  done
  for c in "${COLORS[@]}"; do
    f="assets/balloons/$c.png"; mkdir -p "$dst/assets/balloons"
    if curl -fsSL --max-time 30 -o "$dst/$f.part" "$FROM/$y/$f" && file "$dst/$f.part" | grep -q 'PNG image'; then mv "$dst/$f.part" "$dst/$f"; got=$((got+1)); else rm -f "$dst/$f.part"; fi
  done
  for f in "${OPTIONAL[@]}"; do
    mkdir -p "$dst/$(dirname "$f")"
    if curl -fsSL --max-time 30 -o "$dst/$f.part" "$FROM/$y/$f"; then mv "$dst/$f.part" "$dst/$f"; got=$((got+1)); else rm -f "$dst/$f.part"; fi
  done
  rmdir "$dst/temp" 2>/dev/null || true
  # 꼭 있어야 하는 것: 화면 · 프로그램 · 대회 자료
  need=0; for f in index.html config.js contest.json runs.json js/spotboard-all.min.js js/lib/require.js css/scoreboard.css; do [[ -s "$dst/$f" ]] || need=1; done
  chown -R www-data:www-data "$dst"; find "$dst" -type d -exec chmod 755 {} +; find "$dst" -type f -exec chmod 644 {} +
  size=$(du -sh "$dst" | cut -f1)
  if (( need )); then echo "FAIL $y : files=$got size=$size  (필수 파일이 빠졌습니다)"; missing=1
  else echo "OK   $y : files=$got size=$size runs=$(grep -o '"id"' "$dst/runs.json" | wc -l)${lost[*]:+  (없는 그림: ${lost[*]})}"; fi
done
(( missing )) && { echo "NOT DONE: 빠진 것이 있습니다. 주소를 돌리지 마세요."; exit 1; }
echo "DONE: http://(이 서버 주소)/2024sfpc/ 를 열어 확인한 뒤 sfpc.kr 주소를 돌리세요."
