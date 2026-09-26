// Roda dentro da página de visão geral do Dotabuff (/players/<id>) e devolve o
// registro compacto usado por build_data.py.
() => {
  const q = (s, r = document) => r.querySelector(s), qa = (s, r = document) => [...r.querySelectorAll(s)];
  const num = t => {
    if (t == null) return null;
    t = String(t).trim();
    t = /^\d+,\d{1,2}$/.test(t) ? t.replace(',', '.') : t.replace(/,/g, '');
    const v = parseFloat(t.replace('%', ''));
    return isNaN(v) ? null : v;
  };
  const ts = t => t ? Math.round(Date.parse(t) / 1000) : null;
  const attr = (el, a) => el ? el.getAttribute(a) : null;
  const secs = qa('section');
  const sec = k => secs.find(s => ((q('header', s) || {}).innerText || '').toUpperCase().includes(k));
  const h1 = q('.header-content-title h1');
  const gr = q('.header-content-secondary .game-record');
  const o = {
    n: h1 ? h1.childNodes[0].textContent.trim() : null,
    av: (q('.header-content-avatar img') || {}).src || null,
    lm: ts(attr(q('.header-content-secondary time'), 'datetime')),
    w: gr ? num(q('.wins', gr).innerText) : null,
    l: gr ? num(q('.losses', gr).innerText) : null,
    ab: gr ? num((q('.abandons', gr) || {}).innerText) : null,
    rk: (attr(q('.rank-tier-wrapper'), 'oldtitle') || '').replace('Classificação: ', ''),
  };
  const ac = q('section.player-achievements header');
  o.pts = ac ? num(ac.innerText.split(' ')[0]) : null;
  const rs = sec('FUNÇÕES');
  o.roles = rs ? qa('.sector.role', rs).map(r => [
    ((q('.label', r) || {}).innerText || '').trim(), +parseFloat(r.style.width).toFixed(1),
    qa('.sector.lane', r).map(l => [(q('.inner', l).className.match(/segment-(\w+)/) || [])[1], +parseFloat(l.style.width).toFixed(1)]).filter(l => l[1] > 0),
  ]).filter(r => r[1] > 0) : [];
  const slugOf = r => (attr(q('a[href^="/heroes/"]', r), 'href') || '').split('/').pop();
  const hs = sec('HERÓIS MAIS');
  o.h = hs ? qa('.r-row', hs).map(r => {
    const b = qa('.r-line-graph .r-body', r), img = q('img', r);
    return [img ? (img.getAttribute('oldtitle') || img.alt) : null, slugOf(r),
      num(b[0] && b[0].childNodes[0].textContent), num(b[1] && b[1].childNodes[0].textContent), num(b[2] && b[2].childNodes[0].textContent),
      ((q('.primary-role-text', r) || {}).innerText || '').trim(), ((q('.primary-lane-text', r) || {}).innerText || '').trim(),
      ts(attr(q('time', r), 'datetime'))];
  }) : [];
  const ms = sec('ÚLTIMAS PARTIDAS');
  o.r = ms ? qa('.r-row', ms).map(r => {
    const k = qa('.kda-record .value', r).map(v => num(v.innerText));
    const img = q('img', r), ty = q('.r-first .r-body', r), party = q('.r-first [oldtitle]', r);
    const icons = [...new Set(qa('.r-none-mobile [oldtitle]', r).map(i => i.getAttribute('oldtitle')))].filter(x => !/TrueSight/.test(x));
    const brk = q('.r-icon-text .subtext:not(.icons)', r);
    return [slugOf(r), img && img.alt, num((q('a[href^="/heroes/"] .tw-absolute', r) || {}).innerText),
      q('.r-match-result a.won', r) ? 1 : 0, ts(attr(q('.r-match-result time', r), 'datetime')),
      ty ? ty.childNodes[0].textContent.trim() : null, +(((party && party.getAttribute('oldtitle')) || '').match(/\d+/) || [1])[0],
      (q('.r-first .subtext', r) || {}).innerText || null, (q('.r-duration .r-body', r) || { innerText: '' }).innerText.trim(),
      k[0], k[1], k[2], icons.join('|'), brk ? brk.childNodes[0].textContent.trim() : '',
      (attr(q('a[href^="/matches/"]', r), 'href') || '').split('/').pop()];
  }) : [];
  const gs = sec('ESTATÍSTICAS GERAIS');
  o.g = gs ? qa('tr', gs).map(t => qa('td,th', t).map(c => c.innerText.trim())).filter(r => r.length > 1 && r[1] !== 'Partidas').map(r => r.slice(0, 3)) : [];
  const al = q('section.player-aliases');
  o.al = al ? qa('tbody tr', al).map(t => (q('td', t) || {}).innerText.trim()) : [];
  const fr = sec('AMIGOS');
  o.fr = fr ? qa('tbody tr', fr).map(t => ({ c: qa('td', t).map(c => c.innerText.trim()), l: attr(q('a[href^="/players/"]', t), 'href') }))
    .filter(f => f.l).map(f => [f.l.split('/').pop(), ...f.c]) : [];
  o.act = qa('section.player-activity .year-chart-tooltip').map(t => {
    const sp = qa('span', t);
    return [q('h3', t).innerText, num(sp[0] && sp[0].innerText), num(sp[2] && sp[2].innerText)];
  }).filter(a => a[1] || a[2]);
  o.ok = !!h1;
  return o;
}
