/* ═══════════════════════════════════════════════
   sfpc_char.js — SFPC 팀 캐릭터 그리기

   16×16 칸짜리 픽셀 캐릭터를 캔버스에 그린다. 꾸미기 화면, 우리 팀 화면이 쓰고,
   뒤에 만들 OX 운동장 · 스코어보드도 이 파일 하나로 같은 캐릭터를 그린다.

   캐릭터는 부품 여섯 가지의 고른 값으로 정해진다 (sfpc_lib.php 의 SFPC_CHAR 와 이름이 같아야 한다).
     b 몸 · c 색(번호) · e 눈 · m 입 · h 머리 장식 · f 이동 효과
   모든 그림은 직접 찍은 것이다 (외부 그림 없음).

     SfpcChar.draw(ctx, S, x, y, P, dir, tick)   P = 한 칸의 픽셀 수, dir = 1 오른쪽 / -1 왼쪽
     SfpcChar.puff(S, x, y, P, dir)              이동 효과 알갱이 하나를 만든다 (없으면 null)
     SfpcChar.drawPuff(ctx, p, P)                알갱이를 그리고 수명을 줄인다. 다 됐으면 false
   ═══════════════════════════════════════════════ */
(function () {
  'use strict';
  function R(a, b, x0, x1) { var o = {}; for (var y = a; y <= b; y++) o[y] = [[x0, x1]]; return o; }
  function mix(a, b) { for (var k in b) a[k] = b[k]; return a; }

  /* 몸 — t: 머리 꼭대기 줄(머리 장식이 얹히는 곳), r: 줄마다 칠할 가로 구간 */
  var BODY = {
    robot:    { t: 5, r: mix(R(5, 12, 3, 12), { 13: [[4, 5], [10, 11]], 14: [[4, 5], [10, 11]] }) },
    slime:    { t: 6, r: mix(R(9, 13, 3, 12), { 6: [[6, 9]], 7: [[5, 10]], 8: [[4, 11]], 14: [[2, 13]] }) },
    ghost:    { t: 4, r: mix(R(6, 13, 4, 11), { 4: [[6, 9]], 5: [[5, 10]], 14: [[4, 5], [7, 8], [10, 11]] }) },
    cat:      { t: 5, r: mix(R(5, 13, 4, 11), { 3: [[4, 4], [11, 11]], 4: [[4, 5], [10, 11]], 14: [[4, 5], [10, 11]] }) },
    bread:    { t: 4, r: mix(R(5, 13, 3, 12), { 4: [[3, 6], [9, 12]], 14: [[5, 6], [9, 10]] }) },
    potato:   { t: 5, r: mix(R(7, 12, 3, 12), { 5: [[5, 9]], 6: [[4, 11]], 13: [[4, 11]], 14: [[5, 6], [9, 10]] }) },
    alien:    { t: 5, r: mix(R(6, 9, 3, 12), { 5: [[5, 10]], 10: [[4, 11]], 11: [[5, 10]], 12: [[6, 9]], 13: [[5, 5], [7, 8], [10, 10]], 14: [[5, 5], [7, 8], [10, 10]] }) },
    tofu:     { t: 6, r: mix(R(6, 13, 3, 12), { 14: [[5, 6], [9, 10]] }) },
    dumpling: { t: 6, r: mix(R(9, 12, 3, 12), { 6: [[6, 9]], 7: [[5, 10]], 8: [[4, 11]], 13: [[2, 13]], 14: [[3, 12]] }) },
    mushroom: { t: 4, r: mix(R(9, 13, 5, 10), { 4: [[6, 9]], 5: [[4, 11]], 6: [[3, 12]], 7: [[2, 13]], 8: [[2, 13]], 14: [[5, 6], [9, 10]] }) },
    pudding:  { t: 6, r: mix(R(8, 11, 3, 12), { 6: [[5, 10]], 7: [[4, 11]], 12: [[2, 13]], 13: [[2, 13]], 14: [[1, 14]] }) }
  };
  /* 색 — [밝은 쪽, 어두운 쪽] */
  var COLOR = [['#7F77DD', '#534AB7'], ['#5DCAA5', '#1D9E75'], ['#F0997B', '#D85A30'], ['#ED93B1', '#D4537E'],
               ['#85B7EB', '#378ADD'], ['#FAC775', '#BA7517'], ['#97C459', '#639922'], ['#D3D1C7', '#888780'],
               ['#F7C1C1', '#E24B4A'], ['#F1EFE8', '#B4B2A9']];
  var W = '#fff', K = '#140A24', Y = '#FAC775', PINK = '#ED93B1', BLUE = '#85B7EB', GRN = '#639922', LGRN = '#97C459';

  /* 눈 · 입 · 머리 장식 — px(칸x, 칸y, 색, 너비, 높이), d = 오른쪽을 볼 때 1, t = 머리 꼭대기 줄 */
  var EYE = {
    big:     function (px, d) { px(5, 8, W, 2, 2); px(9, 8, W, 2, 2); px(5 + d, 9, K); px(9 + d, 9, K); },
    dot:     function (px) { px(5, 9, K); px(10, 9, K); },
    smile:   function (px) { px(5, 8, K); px(6, 9, K); px(4, 9, K); px(10, 8, K); px(9, 9, K); px(11, 9, K); },
    sleepy:  function (px) { px(5, 9, K, 2); px(9, 9, K, 2); },
    cyclops: function (px, d) { px(6, 7, W, 4, 3); px(7 + d, 8, K); },
    spin:    function (px, d, tk) { var q = Math.floor(tk / 8) % 4, qx = [0, 1, 1, 0][q], qy = [0, 0, 1, 1][q];
                                    px(5, 8, W, 2, 2); px(9, 8, W, 2, 2); px(5 + qx, 8 + qy, K); px(9 + qx, 8 + qy, K); },
    tear:    function (px, d) { EYE.big(px, d); px(5, 10, BLUE); px(10, 10, BLUE); px(5, 11, BLUE); },
    odd:     function (px) { px(4, 7, W, 3, 3); px(5, 8, K); px(10, 9, K); },
    heart:   function (px) { var H = '#D4537E'; px(4, 8, H); px(6, 8, H); px(4, 9, H, 3); px(5, 10, H);
                             px(9, 8, H); px(11, 8, H); px(9, 9, H, 3); px(10, 10, H); },
    sunglasses: function (px) { px(3, 8, K, 10); px(4, 8, K, 3, 2); px(9, 8, K, 3, 2); px(5, 8, '#888780'); px(10, 8, '#888780'); },
    swirl:   function (px, d, tk) { var q = Math.floor(tk / 10) % 2;                       /* 뱅글뱅글 안경에 코와 콧수염 */
                                    px(4, 7, W, 3, 3); px(9, 7, W, 3, 3); px(7, 8, K, 2);
                                    px(5, 8, K); px(q ? 4 : 6, 7, K); px(q ? 6 : 4, 9, K);
                                    px(10, 8, K); px(q ? 9 : 11, 7, K); px(q ? 11 : 9, 9, K);
                                    px(7, 9, PINK, 2); px(5, 10, '#4A1B0C', 6); px(4, 11, '#4A1B0C', 2); px(10, 11, '#4A1B0C', 2); },
    glasses: function (px, d) { px(4, 7, K, 4, 4); px(9, 7, K, 4, 4); px(8, 8, K); px(5, 8, W, 2, 2); px(10, 8, W, 2, 2); px(5 + d, 9, K); px(10 + d, 9, K); },
    angry:   function (px) { px(4, 7, K, 2); px(6, 8, K); px(10, 7, K, 2); px(9, 8, K); px(5, 9, K); px(10, 9, K); },
    star:    function (px) { px(5, 7, Y, 1, 3); px(4, 8, Y, 3); px(10, 7, Y, 1, 3); px(9, 8, Y, 3); },
    visor:   function (px) { px(4, 8, K, 8, 2); px(5, 8, '#5DCAA5', 2); px(9, 8, '#5DCAA5'); }
  };
  var MOUTH = {
    none:     function () {},
    tongue:   function (px) { px(7, 11, K, 2); px(7, 12, PINK, 2, 2); },
    grin:     function (px) { px(5, 11, K, 6, 2); px(6, 11, W, 4); },
    o:        function (px) { px(7, 11, K, 2, 2); },
    drool:    function (px) { px(6, 11, K, 3); px(8, 12, BLUE, 1, 2); },
    cat:      function (px) { px(6, 11, K); px(7, 12, K); px(8, 11, K); px(9, 12, K); px(10, 11, K); },
    frown:    function (px) { px(6, 11, K, 4); px(5, 12, K); px(10, 12, K); },
    buck:     function (px) { px(6, 11, K, 4); px(7, 12, W, 2, 2); },
    candy:    function (px) { px(7, 11, K, 2); px(9, 11, W, 3); px(12, 10, '#D4537E', 2, 2); px(12, 10, W); },
    mustache: function (px) { px(5, 11, '#4A1B0C', 6); px(4, 12, '#4A1B0C', 2); px(10, 12, '#4A1B0C', 2); }
  };
  var HAT = {
    none:    function () {},
    antenna: function (px, t) { px(8, t - 1, '#B4B2A9'); px(8, t - 2, Y); },
    crown:   function (px, t) { px(5, t - 1, Y, 6); px(5, t - 2, Y); px(7, t - 2, Y, 2); px(10, t - 2, Y); },
    sprout:  function (px, t) { px(8, t - 1, GRN); px(7, t - 2, LGRN); px(9, t - 2, LGRN); },
    egg:     function (px, t) { px(4, t - 1, W, 8); px(5, t - 2, W, 5); px(11, t, W); px(7, t - 2, '#EF9F27', 2); px(7, t - 1, '#EF9F27', 2); },
    banana:  function (px, t) { px(6, t - 1, Y, 4); px(7, t - 2, '#BA7517', 2); px(5, t, Y); px(10, t, Y);
                                px(4, t + 1, Y); px(11, t + 1, Y); px(3, t + 2, Y); px(12, t + 2, Y); },
    pot:     function (px, t) { px(4, t - 2, '#888780', 8, 2); px(3, t - 1, '#5F5E5A', 10); px(12, t - 2, '#5F5E5A', 2); px(7, t - 3, '#5F5E5A', 2); },
    leek:    function (px, t) { px(7, t - 1, W, 2); px(7, t - 3, GRN, 2, 2); px(6, t - 4, LGRN); px(9, t - 4, LGRN); },
    question: function (px, t, tk) { var o = Math.floor(tk / 20) % 2; px(7, t - 5 - o, W, 3); px(9, t - 4 - o, W); px(8, t - 3 - o, W); px(8, t - 1 - o, W); },
    exclaim: function (px, t, tk) { var o = Math.floor(tk / 14) % 2; px(8, t - 5 - o, '#E24B4A', 1, 3); px(8, t - 1 - o, '#E24B4A'); },
    flowerpot: function (px, t) { px(6, t - 1, '#D85A30', 4); px(5, t - 2, '#993C1D', 6); px(8, t - 3, GRN); px(7, t - 4, PINK, 3); px(8, t - 5, PINK); px(8, t - 4, Y); },
    book:    function (px, t) { px(4, t - 1, '#F1EFE8', 8); px(4, t - 2, '#854F0B', 8); px(8, t - 1, '#B4B2A9'); px(5, t - 1, '#888780', 2); px(10, t - 1, '#888780'); },
    propeller: function (px, t, tk) { var w = Math.floor(tk / 3) % 2;                      /* 머리 위에서 도는 프로펠러 */
                                      px(8, t - 2, '#888780', 1, 2); if (w) px(4, t - 3, Y, 9); else px(7, t - 3, Y, 3); px(8, t - 3, '#E24B4A'); },
    storm:   function (px, t, tk) { px(6, t - 6, '#D3D1C7', 4); px(5, t - 5, '#B4B2A9', 7); px(4, t - 4, '#888780', 9);   /* 먹구름과 번개 */
                                    if (Math.floor(tk / 9) % 3) { px(8, t - 3, Y); px(7, t - 2, Y, 2); px(8, t - 1, Y); } },
    bulb:    function (px, t, tk) { var on = Math.floor(tk / 24) % 2; px(7, t - 4, on ? Y : '#D3D1C7', 2); px(6, t - 3, on ? Y : '#D3D1C7', 4, 2); px(7, t - 1, '#888780', 2);
                                    if (on) { px(4, t - 3, Y); px(11, t - 3, Y); px(5, t - 5, Y); px(10, t - 5, Y); } },
    fin:     function (px, t) { px(7, t - 1, '#5F5E5A', 4); px(8, t - 2, '#5F5E5A', 3); px(9, t - 3, '#5F5E5A', 2); px(10, t - 4, '#5F5E5A'); },
    cone:    function (px, t) { px(4, t - 1, '#D85A30', 8); px(6, t - 2, '#D85A30', 4); px(6, t - 3, W, 4); px(7, t - 4, '#D85A30', 2); px(7, t - 5, '#D85A30', 2); },
    halo:    function (px, t, tk) { var o = Math.floor(tk / 22) % 2; px(6, t - 3 - o, Y, 4); px(5, t - 2 - o, Y); px(10, t - 2 - o, Y); px(6, t - 1 - o, Y, 4); },
    duck:    function (px, t) { px(5, t - 2, Y, 6, 2); px(8, t - 4, Y, 3, 2); px(11, t - 3, '#D85A30', 2); px(9, t - 4, K); px(5, t - 3, Y); },
    party:   function (px, t) { px(6, t - 1, '#D4537E', 4); px(7, t - 2, Y, 2); px(7, t - 3, '#D4537E', 2); px(8, t - 4, '#5DCAA5'); px(8, t - 5, W); }
  };
  /* 몸마다 덧그리는 무늬 */
  var DECO = {
    bread:    function (px, c) { px(3, 4, c[1], 4); px(9, 4, c[1], 4); px(3, 5, c[1]); px(12, 5, c[1]); },
    dumpling: function (px, c) { px(6, 7, c[1]); px(8, 7, c[1]); px(10, 7, c[1]); },
    mushroom: function (px) { px(5, 5, W, 2); px(9, 6, W, 2); px(11, 7, W); px(3, 7, W); },
    pudding:  function (px) { px(5, 6, '#854F0B', 6); px(4, 7, '#854F0B', 8); px(4, 8, '#854F0B'); px(9, 8, '#854F0B', 2); px(10, 9, '#854F0B'); },
    potato:   function (px, c) { px(4, 7, c[1]); px(11, 10, c[1]); px(9, 6, c[1]); },
    robot:    function (px, c) { px(3, 10, c[1]); px(12, 10, c[1]); }
  };

  function draw(g, S, ox, oy, P, dir, tk) {
    function px(cx, cy, col, w, h) { g.fillStyle = col; g.fillRect(ox + cx * P, oy + cy * P, (w || 1) * P, (h || 1) * P); }
    var b = BODY[S.b] || BODY.robot, c = COLOR[S.c] || COLOR[0], last = 14, y, i, d = dir > 0 ? 1 : 0;
    tk = tk || 0;
    for (y in b.r) for (i = 0; i < b.r[y].length; i++) px(b.r[y][i][0], +y, +y >= last - 1 ? c[1] : c[0], b.r[y][i][1] - b.r[y][i][0] + 1);
    if (DECO[S.b]) DECO[S.b](px, c);
    (EYE[S.e] || EYE.big)(px, d, tk);
    (MOUTH[S.m] || MOUTH.none)(px);
    (HAT[S.h] || HAT.none)(px, b.t, tk);
  }

  /* 이동 효과 — p: {x, y, l(수명 1→0), k(종류), v} · 크기는 P 에 맞춘다 */
  var FX = {
    none: null,
    sparkle: function (g, p, u, s) { g.fillStyle = Y; g.fillRect(p.x, p.y - 1.5 * s, .6 * s, 3.4 * s); g.fillRect(p.x - 1.4 * s, p.y, 3.4 * s, .6 * s); },
    bolt:    function (g, p, u, s) { g.fillStyle = Y; g.fillRect(p.x, p.y, 1.4 * s, .7 * s); g.fillRect(p.x - .7 * s, p.y + .7 * s, 1.4 * s, .7 * s); g.fillRect(p.x, p.y + 1.4 * s, 1.4 * s, .7 * s); },
    fire:    function (g, p, u, s) { g.fillStyle = p.l > .5 ? '#EF9F27' : '#D85A30'; var z = (.6 + p.l * 1.6) * s; g.fillRect(p.x, p.y - u * 4.5 * s, z, z); },
    bits:    function (g, p, u, s) { g.fillStyle = '#5DCAA5'; g.font = '500 ' + Math.round(4 * s) + 'px monospace'; g.fillText(p.v, p.x, p.y - u * 2.5 * s); },
    fart:    function (g, p, u, s) { g.fillStyle = '#C0DD97'; var z = (1.3 + u * 2.6) * s; g.fillRect(p.x - z / 2, p.y + (2 - u * 2) * s, z, z * .7); g.fillRect(p.x - z / 2 + s, p.y + (1 - u * 2) * s, z * .6, z * .6); },
    sweat:   function (g, p, u, s) { g.fillStyle = BLUE; g.fillRect(p.x, p.y - (6.5 - u * 4.5) * s, .7 * s, 1.3 * s); },
    note:    function (g, p, u, s) { g.fillStyle = PINK; var yy = p.y - (4 + u * 3.3) * s; g.fillRect(p.x, yy, .7 * s, 2.7 * s); g.fillRect(p.x - s, yy + 2 * s, 1.4 * s, s); g.fillRect(p.x, yy, 1.7 * s, .7 * s); },
    heart:   function (g, p, u, s) { g.fillStyle = '#D4537E'; var yy = p.y - (3 + u * 3) * s; g.fillRect(p.x, yy, s, s); g.fillRect(p.x + 2 * s, yy, s, s); g.fillRect(p.x, yy + s, 3 * s, s); g.fillRect(p.x + s, yy + 2 * s, s, s); },
    bubble:  function (g, p, u, s) { g.fillStyle = BLUE; var z = (1.2 + u * 2) * s, yy = p.y - u * 5 * s; g.fillRect(p.x, yy, z, .5 * s); g.fillRect(p.x, yy + z, z + .5 * s, .5 * s); g.fillRect(p.x, yy, .5 * s, z); g.fillRect(p.x + z, yy, .5 * s, z); },
    zzz:     function (g, p, u, s) { g.fillStyle = '#AFA9EC'; g.font = '700 ' + Math.round((3 + u * 3) * s) + 'px monospace'; g.fillText('z', p.x, p.y - (4 + u * 5) * s); },
    leaf:    function (g, p, u, s) { g.fillStyle = u > .5 ? '#BA7517' : LGRN; var xx = p.x + Math.sin(u * 6) * 2 * s; g.fillRect(xx, p.y - (5 - u * 6) * s, 1.4 * s, .7 * s); g.fillRect(xx + .7 * s, p.y - (5 - u * 6) * s + .7 * s, .7 * s, .7 * s); }
  };
  function puff(S, x, y, P, dir) {
    if (!FX[S.f]) return null;
    return { x: x + (dir > 0 ? 1 : 14) * P, y: y + (9 + Math.random() * 4) * P, l: 1, k: S.f, v: Math.random() < .5 ? '0' : '1' };
  }
  function drawPuff(g, p, P) {
    var f = FX[p.k]; if (!f) return false;
    g.globalAlpha = Math.max(0, p.l); f(g, p, 1 - p.l, P); g.globalAlpha = 1;
    p.l -= .03; return p.l > 0;
  }

  window.SfpcChar = { draw: draw, puff: puff, drawPuff: drawPuff, COLOR: COLOR,
                      IDS: { b: Object.keys(BODY), e: Object.keys(EYE), m: Object.keys(MOUTH), h: Object.keys(HAT), f: Object.keys(FX) } };
})();
