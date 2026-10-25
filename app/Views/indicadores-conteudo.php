<?php
/**
 * Miolo da Visão Executiva de TI — também devolvido sozinho na atualização automática (?parcial=1).
 * @var array $ind painel de IndicadoresTi::painel @var \App\Services\Periodo $periodo
 */
use App\Services\GraficoSvg;
use App\Services\IndicadoresTi;
$brl=static fn($v)=>'R$ '.number_format((float)$v,2,',','.');
$int=static fn($v)=>number_format((float)$v,0,',','.');
$app=$ind['app']; $st=$app['stats']; $compras=$app['compras'];
$variacao=static function(array $c): string {
    if($c['variacao']===null) return '';
    $d=$c['variacao']; $pp=$c['ind']['unidade']==='percentual';
    if(abs($d)<0.005) return '<span class="kpi-delta">■ igual ao período anterior</span>';
    $melhor=$c['ind']['meta_operador']==='<='?$d<0:$d>0;
    return '<span class="kpi-delta '.($melhor?'melhor':'pior').'">'.($d>0?'▲ +':'▼ ').number_format($d,$pp?2:0,',','.').($pp?' p.p.':'').' vs período anterior</span>';
};
?>
<p class="ind-periodo"><strong><?=e($periodo->rotulo())?></strong> · Atualizado às <?=e(substr($ind['geradoEm'],-8))?> (Horário de Mato Grosso – UTC-4)</p>
<?php if(!$ind['pronto']): ?><p class="notice error" role="status">Os cartões com meta, controles e riscos precisam da migração <strong>007_indicadores_ti</strong>. Feche e abra a aplicação pelo <strong>iniciar.ps1</strong> (ele aplica as migrações). Enquanto isso, o painel mostra os dados dos ativos e das compras.</p><?php endif; ?>

<?php if($ind['cartoes']): ?>
<section class="ind-kpis" aria-label="Indicadores com meta">
<?php foreach($ind['cartoes'] as $c): $sit=$c['situacao']; ?>
  <article class="kpi kpi-<?=e($sit)?>" title="<?=e($c['ind']['fonte'].($c['data']?' · última medição em '.date('d/m/Y',strtotime($c['data'])):' · sem medição'))?>">
    <p class="kpi-rotulo"><?=e($c['ind']['nome'])?></p>
    <p class="kpi-meta"><?=e($c['meta'])?></p>
    <strong class="kpi-valor"><?=e($c['texto'])?></strong>
    <span class="kpi-fonte"><?=e($c['ind']['fonte'])?> · <?=$c['data']?e(($c['ind']['agregacao']==='media'?'média até ':'em ').date('d/m',strtotime($c['data']))):'sem dado'?></span>
    <?=$variacao($c)?>
    <span class="sr-only"><?=e(['ok'=>'Dentro da meta','fora'=>'Fora da meta','sem_dado'=>'Sem medição','sem_meta'=>'Sem meta'][$sit])?></span>
  </article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<h2 class="ind-secao">Ativos e compras <span class="muted">· escopo atual da consulta SGA</span></h2>
<section class="ind-kpis ind-kpis-app" aria-label="Ativos e compras">
  <a class="kpi kpi-link" href="/ativos"><p class="kpi-rotulo">Ativos cadastrados</p><strong class="kpi-valor"><?=$int($st['parque_n'])?></strong><span class="kpi-fonte"><?=$brl($st['parque'])?> · <?=$int($st['uso_n'])?> em uso</span></a>
  <a class="kpi kpi-link<?=$st['pendente_n']>0?' kpi-atencao':''?>" href="/ativos"><p class="kpi-rotulo">Aguardando conferência</p><strong class="kpi-valor"><?=$int($st['pendente_n'])?></strong><span class="kpi-fonte"><?=$brl($st['pendente'])?></span></a>
  <a class="kpi kpi-link<?=$app['ambiguos']>0?' kpi-atencao':''?>" href="/ativos"><p class="kpi-rotulo">Patrimônios ambíguos</p><strong class="kpi-valor"><?=$int($app['ambiguos'])?></strong><span class="kpi-fonte">escolher a associação correta</span></a>
  <a class="kpi kpi-link<?=$app['semNumero']['n']>0?' kpi-atencao':''?>" href="/ativos"><p class="kpi-rotulo">Bens sem número no SGA</p><strong class="kpi-valor"><?=$int($app['semNumero']['n'])?></strong><span class="kpi-fonte"><?=$brl($app['semNumero']['valor'])?> no imobilizado</span></a>
  <a class="kpi kpi-link" href="/compras?filtro=todas"><p class="kpi-rotulo">Compras no período</p><strong class="kpi-valor kpi-valor-moeda"><?=$brl($compras['total']??0)?></strong><span class="kpi-fonte"><?=$int($compras['linhas']??0)?> linhas · <?=$brl($compras['sem_patrimonio']??0)?> sem patrimônio</span></a>
  <?php $sync=$app['sync']; $atrasada=!$sync || $sync['situacao']!=='concluida' || ($app['syncHoras']??0)>=24; ?>
  <a class="kpi kpi-link<?=$atrasada?' kpi-fora':''?>" href="/integracao"><p class="kpi-rotulo">Última sincronização SGA</p><strong class="kpi-valor kpi-valor-data"><?=$sync?e(date('d/m H:i',strtotime((string)$sync['inicio']))):'—'?></strong><span class="kpi-fonte"><?=$sync?e(($sync['situacao']==='concluida'?'concluída':$sync['situacao']).' · '.$int($sync['linhas']).' linhas · há '.$app['syncHoras'].' h'):'nenhuma sincronização'?></span></a>
</section>

<div class="ind-grade ind-grade-3">
  <section class="panel ind-cartao ind-largo"><h2>Disponibilidade diária</h2>
    <?=GraficoSvg::linhas('Disponibilidade diária: hosts críticos e links de internet',$ind['serie']['datas'],$ind['serie']['series'],IndicadoresTi::SERIE_DISPONIBILIDADE)?></section>
  <section class="panel ind-cartao"><h2>Controles por status</h2>
    <?=GraficoSvg::rosca('Controles por status',$ind['controles'],'Sem fotografia do catálogo de controles. Lance os três status em Dados dos indicadores.')?></section>
  <section class="panel ind-cartao"><h2>Riscos em aberto por nível</h2>
    <?php $itens=[]; foreach($ind['riscos']['porNivel'] as $cls=>$n) $itens[]=['rotulo'=>$cls,'valor'=>$n,'classe'=>'risco-'.mb_strtolower(strtr($cls,['í'=>'i','é'=>'e']))]; ?>
    <?=GraficoSvg::barras('Riscos em aberto por nível',$itens,$ind['riscos']['total']?'Nenhum risco em aberto.':'Nenhum risco cadastrado. Importe o registro de riscos em Dados dos indicadores.',300)?></section>
</div>

<div class="ind-grade ind-grade-2">
  <section class="panel ind-cartao table-panel"><h2>Riscos altos em aberto</h2>
    <?php if(!$ind['riscos']['altos']): ?><p class="muted vazio">Nenhum risco alto ou crítico em aberto.</p><?php else: ?>
    <table><thead><tr><th>Risco</th><th>Nível</th><th>Responsável</th></tr></thead><tbody>
    <?php foreach($ind['riscos']['altos'] as $r): ?><tr><td><strong><?=e($r['risco'])?></strong><small><?=e($r['codigo'])?> · <?=e($r['classificacao'])?> · <?=e(IndicadoresTi::SITUACOES_RISCO[$r['situacao']]??$r['situacao'])?></small></td><td><span class="nivel-risco"><?=e($r['nivel'])?></span></td><td><?=e($r['responsavel']?:'—')?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></section>
  <section class="panel ind-cartao"><h2>Ativos por situação <span class="muted">· compras no período</span></h2>
    <?php $nomesSit=['alocado'=>'Em uso','em_estoque'=>'Em estoque','aguardando_conferencia'=>'Aguardando conferência','manutencao'=>'Manutenção','baixado'=>'Baixado']; $itens=[];
    foreach($nomesSit as $k=>$rot) if(isset($app['porSituacao'][$k])) $itens[]=['rotulo'=>$rot,'valor'=>$app['porSituacao'][$k]['n'],'texto'=>$int($app['porSituacao'][$k]['n']).' · '.$brl($app['porSituacao'][$k]['valor']),'classe'=>'serie-0']; ?>
    <?=GraficoSvg::barras('Ativos por situação',$itens,'Nenhum ativo com compra no período.',560)?></section>
</div>
