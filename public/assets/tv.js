'use strict';
// Modo TV: relógio (Horário de Mato Grosso – UTC-4), tela cheia e recarga periódica da página (data-recarregar, em segundos).
(() => {
  const relogio = document.getElementById('tv-relogio');
  const hora = () => new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Cuiaba' });
  if (relogio) { relogio.textContent = hora(); setInterval(() => { relogio.textContent = hora(); }, 15000); }
  document.querySelectorAll('[data-tela-cheia]').forEach((b) => b.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.();
  }));
  const s = Number(document.body.dataset.recarregar || 0);
  if (s > 0) setInterval(() => { if (!document.hidden) location.reload(); }, s * 1000);
})();
