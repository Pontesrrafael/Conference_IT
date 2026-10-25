<?php
/**
 * Visão Executiva de TI (página normal e Modo TV). Filtros por GET; o miolo é atualizado sozinho via ?parcial=1.
 * @var array $ind @var \App\Services\Periodo $periodo @var int $atualizar @var bool $modoTv @var bool $can
 */
use App\Services\IndicadoresTi;
$qs=['periodo'=>$periodo->opcao,'data_inicio'=>$periodo->opcao==='personalizado'?($periodo->inicio??''):'','data_fim'=>$periodo->opcao==='personalizado'?($periodo->fim??''):'','atualizar'=>$atualizar];
$qs=array_filter($qs,static fn($v)=>$v!=='' && $v!==null);
$urlTv='/indicadores?'.http_build_query($qs+['tv'=>1]); $urlNormal='/indicadores?'.http_build_query($qs);
?>
<?php if($modoTv): ?>
<details class="tv-filtros"><summary>Filtros e atualização</summary>
<?php else: ?>
<p class="muted ind-intro">Disponibilidade, segurança, conformidade e ativos — Salinas Gold Mineração. Espelha a página <strong>Visão Executiva</strong> do <em>SGM | Dashboard de TI</em> (Power BI): cartões com meta lançados ou importados em <?=$can?'<a href="/indicadores-dados">Dados dos indicadores</a>':'Dados dos indicadores (administradores e operadores de TI)'?> e ativos/compras calculados pela aplicação.</p>
<?php endif; ?>
<form class="panel date-filter no-print" method="get" action="/indicadores" id="form-indicadores">
  <?php if($modoTv): ?><input type="hidden" name="tv" value="1"><?php endif; ?>
  <div class="date-fields">
    <label>Período<select name="periodo"><?php foreach(['todos'=>'Todo o histórico','hoje'=>'Hoje','7dias'=>'Últimos 7 dias','30dias'=>'Últimos 30 dias','mes'=>'Mês atual','ano'=>'Ano atual','personalizado'=>'Personalizado'] as $v=>$label): ?><option value="<?=$v?>" <?=$periodo->opcao===$v?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label>
    <label>De<input type="date" name="data_inicio" value="<?=e($periodo->opcao==='personalizado'?$periodo->inicio:'')?>"></label>
    <label>Até<input type="date" name="data_fim" value="<?=e($periodo->opcao==='personalizado'?$periodo->fim:'')?>"></label>
    <label>Atualização automática<select name="atualizar"><?php foreach(IndicadoresTi::ATUALIZACOES as $s=>$rot): ?><option value="<?=$s?>" <?=$atualizar===$s?'selected':''?>><?=e($rot)?></option><?php endforeach; ?></select></label>
    <button>Aplicar</button>
    <?php if(!$modoTv): ?><a class="button secondary" href="<?=e($urlTv)?>">Modo TV</a><?php if($can): ?> <a class="button secondary" href="/indicadores-dados">Dados dos indicadores</a><?php endif; endif; ?>
  </div>
  <p class="small muted">Disponibilidades usam a média das medições do período; os demais cartões, a última medição até o fim do período. Para digitar datas, selecione Personalizado.</p>
  <p class="small muted ind-status" id="ind-status" role="status"></p>
</form>
<?php if($modoTv): ?></details><?php endif; ?>
<div id="indicadores" data-indicadores data-url="<?=e('/indicadores?'.http_build_query($qs+['parcial'=>1]))?>" data-atualizar="<?=(int)$atualizar?>">
<?php require ROOT.'/app/Views/indicadores-conteudo.php'; ?>
</div>
