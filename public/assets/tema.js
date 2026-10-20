'use strict';
// Troca de tema na hora (sem recarregar). Sem JavaScript, os links /tema?valor=... gravam o cookie e voltam à página.
(() => {
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('[data-tema-valor]');
    if (!a) return;
    ev.preventDefault();
    const v = a.dataset.temaValor;
    const seguro = location.protocol === 'https:' ? '; Secure' : '';
    if (v === 'auto') { document.cookie = 'tema=; Max-Age=0; Path=/; SameSite=Lax' + seguro; delete document.documentElement.dataset.tema; }
    else { document.cookie = 'tema=' + v + '; Max-Age=31536000; Path=/; SameSite=Lax' + seguro; document.documentElement.dataset.tema = v; }
    document.querySelectorAll('[data-tema-valor]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.temaValor === v)));
  });
})();
