/* ═══════════════════════════════════════════════
   sfpc_boss.js — 공부하기의 보스 그림 (16×14 칸, 왼쪽 절반만 적고 좌우로 뒤집어 그린다)

     SfpcBoss.draw(ctx, kind, x, y, P, tick, opt)
       opt.sil  — 아직 열리지 않은 보스: 검은 그림자에 눈만 깜빡인다
       opt.hit  — 맞는 순간: 하얗게 번쩍인다 (0~1)
       opt.down — 쓰러진 뒤: 눈이 꺼진다
   모든 그림은 직접 찍은 것이다.
   ═══════════════════════════════════════════════ */
(function () {
  'use strict';
  var B = {
    bug: { p: { b: '#993C1D', s: '#4A1B0C', h: '#D85A30', l: '#712B13', m: '#F0997B', E: '#FAC775' }, r: [
      '...h....', '..h.....', '...hbbbb', '..bbbbbb', '.bbEEbbs', 'lbbEEbbs', '.bbbbbbs', 'llbbbbbs', '.bbbbbbs', 'llbbbbbs', '..bbbbbs', '..mbbbbb', '.m...bb.', 'm.......'] },
    golem: { p: { s: '#888780', d: '#2C2C2A', S: '#D3D1C7', E: '#EF9F27', c: '#5F5E5A' }, r: [
      '...sssss', '..ssssss', '..sdEEss', '..sdEEss', '..ssssss', '..sddddd', 'cssssscs', 'sssssssc', 'ss.ssscs', 'ss.sssss', 'SS.sssss', 'SS.ss.ss', '...ss...', '..sss...'] },
    snake: { p: { g: '#3B6D11', y: '#97C459', E: '#E24B4A' }, r: [
      '...ggggg', '..gyyyyy', '.gyggggg', '.gyg....', 'gyg.....', 'gyg.....', 'gyg.....', 'gyg.....', '.gyg....', '.gyggggg', '..gyyyyy', '...ggggg', '........', '........'],
      x: [[0, 0, 7, 5, '#3B6D11'], [1, 1, 5, 3, '#639922'], [1, 2, 2, 2, 'E'], [4, 2, 2, 2, 'E'], [0, 5, 1, 2, '#fff'], [3, 5, 1, 2, '#fff'], [6, 5, 1, 2, '#fff'], [3, 7, 1, 2, '#E24B4A'], [10, 12, 5, 2, '#97C459'], [14, 10, 2, 2, '#97C459']] },
    spaghetti: { p: { p: '#534AB7', y: '#FAC775', t: '#5DCAA5', E: '#fff', m: '#26215C', k: '#E24B4A' }, r: [
      'y..t....', '.y..tppp', '..yppppp', '..ppEEpp', '.pppEkpp', '.ppppppp', '.pppppEp', 't.pppppp', '.t.pmmmm', '..tppmpm', '.t..pppp', 't...p.p.', 'y..t.y..', '.y..t..y'] },
    oni: { p: { r: '#A32D2D', R: '#501313', h: '#FAC775', E: '#fff', w: '#F1EFE8', f: '#EF9F27' }, r: [
      'h.......', 'hh...f..', '.hh.rrrr', '..hrrrrr', '..rrrrrr', '.rRREErr', '.rrRREEr', '.rrrrrrr', '.rrwRwRw', '.rrwRwRw', 'rrrrrrrr', 'rr.rrrrr', 'RR.rr.rr', '...RR.RR'] }
  };
  function draw(g, kind, ox, oy, P, tk, opt) {
    var b = B[kind] || B.bug; opt = opt || {}; tk = tk || 0;
    var glow = opt.down ? 0 : (opt.sil ? (Math.floor(tk / 26) % 5 === 0 ? 0 : 1) : 0.6 + 0.4 * Math.abs(Math.sin(tk / 14)));
    function cell(x, y, w, h, ch) {
      var col = ch.charAt(0) === '#' ? ch : b.p[ch]; if (!col) return;
      if (ch === 'E') { if (opt.down) col = '#2C2C2A'; else if (opt.sil) col = '#E24B4A'; g.globalAlpha = glow; }
      else if (opt.sil) col = '#0C0618';
      g.fillStyle = col; g.fillRect(ox + x * P, oy + y * P, w * P, h * P); g.globalAlpha = 1;
    }
    for (var y = 0; y < b.r.length; y++) for (var x = 0; x < 16; x++) { var ch = b.r[y].charAt(x < 8 ? x : 15 - x); if (ch !== '.' && ch !== ' ') cell(x, y, 1, 1, ch); }
    (b.x || []).forEach(function (r) { cell(r[0], r[1], r[2], r[3], r[4]); });
    if (opt.hit > 0) { g.globalAlpha = Math.min(.8, opt.hit); g.fillStyle = '#fff'; g.fillRect(ox + P, oy, 14 * P, 14 * P); g.globalAlpha = 1; }
  }
  window.SfpcBoss = { draw: draw, KINDS: Object.keys(B) };
})();
