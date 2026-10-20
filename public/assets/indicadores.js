'use strict';
// Visão Executiva de TI: atualização automática do miolo (?parcial=1), relógio e tela cheia no Modo TV.
(() => {
  const alvo = document.querySelector('[data-indicadores]');
  const status = document.getElementById('ind-status');
  const relogio = document.getElementById('tv-relogio');
  const hora = () => new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Cuiaba' });
  if (relogio) { relogio.textContent = hora(); setInterval(() => { relogio.textContent = hora(); }, 15000); }
  document.querySelectorAll('[data-tela-cheia]').forEach((b) => b.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.();
  }));
  if (!alvo) return;
  const segundos = Number(alvo.dataset.atualizar || 0);
  if (!segundos) { if (status) status.textContent = 'Atualização automática desligada.'; return; }
  let falhas = 0;
  const atualizar = async () => {
    if (document.hidden) return; // aba em segundo plano: espera a próxima rodada
    try {
      const r = await fetch(alvo.dataset.url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } });
      if (!r.ok || r.redirected) throw new Error(String(r.status));
      alvo.innerHTML = await r.text();
      falhas = 0;
      if (status) status.textContent = 'Atualizado automaticamente às ' + hora() + '.';
    } catch (e) {
      falhas += 1;
      if (status) status.textContent = 'Não foi possível atualizar (' + falhas + 'ª tentativa). Os dados exibidos podem estar desatualizados.';
    }
  };
  if (status) status.textContent = 'Atualização automática a cada ' + (segundos >= 60 ? (segundos / 60) + ' min' : segundos + ' s') + '.';
  setInterval(atualizar, segundos * 1000);
})();
