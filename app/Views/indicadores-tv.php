<?php /** Documento do Modo TV: sem menu lateral, para telão da sala de TI. */ ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Visão Executiva de TI · Modo TV · Salinas TI</title>
<link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/brand.css?v=20261019"><link rel="stylesheet" href="/assets/periods.css?v=1"><link rel="stylesheet" href="/assets/indicadores.css?v=1">
<script src="/assets/indicadores.js?v=1" defer></script></head>
<body class="modo-tv"><main class="tv-conteudo">
<?php foreach(['success','error'] as $flash): if(!empty($_SESSION[$flash])): ?><p role="alert" class="notice <?=$flash?>"><?=e($_SESSION[$flash])?></p><?php unset($_SESSION[$flash]); endif; endforeach; ?>
<?php require ROOT.'/app/Views/indicadores.php'; ?>
</main></body></html>
