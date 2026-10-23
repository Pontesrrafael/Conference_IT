<?php
/**
 * Layout do Modo TV (telão): sem menu lateral, com logotipo, título, relógio, tema, tela cheia e saída.
 * @var string $tvView view do conteúdo @var string $tvTitulo @var string $tvSubtitulo @var string $tvSair URL de saída
 * @var int $recarregar segundos para recarregar a página inteira (0 = não recarrega; a Visão Executiva atualiza só o miolo)
 */
?>
<!doctype html><html lang="pt-BR"<?=attr_tema()?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($tvTitulo)?> · Modo TV · <?=e(nome_app())?></title>
<link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/brand.css?v=20261020"><link rel="stylesheet" href="/assets/projects.css?v=1"><link rel="stylesheet" href="/assets/periods.css?v=1"><link rel="stylesheet" href="/assets/indicadores.css?v=2"><link rel="stylesheet" href="/assets/tv.css?v=1">
<script src="/assets/tema.js?v=1" defer></script><script src="/assets/tv.js?v=1" defer></script><?php if($tvView==='indicadores'): ?><script src="/assets/indicadores.js?v=2" defer></script><?php endif; ?></head>
<body class="modo-tv" data-recarregar="<?=(int)$recarregar?>"><main class="tv-conteudo">
<header class="tv-topo">
  <img src="/assets/brand/salinas-gold-branco.png" alt="Salinas Gold Mineração" class="tv-logo" width="150" height="50">
  <div class="tv-titulo"><h1><?=e($tvTitulo)?></h1><p><?=e($tvSubtitulo)?></p></div>
  <div class="tv-relogio" id="tv-relogio" aria-live="off"><?=e(date('H:i'))?></div>
  <div class="tv-acoes"><?=seletor_tema()?><button type="button" class="secondary" data-tela-cheia>Tela cheia</button><a class="button secondary" href="<?=e($tvSair)?>">Sair do Modo TV</a></div>
</header>
<?php foreach(['success','error'] as $flash): if(!empty($_SESSION[$flash])): ?><p role="alert" class="notice <?=$flash?>"><?=e($_SESSION[$flash])?></p><?php unset($_SESSION[$flash]); endif; endforeach; ?>
<div class="content-tv"><?php require ROOT.'/app/Views/'.$tvView.'.php'; ?></div>
</main></body></html>
