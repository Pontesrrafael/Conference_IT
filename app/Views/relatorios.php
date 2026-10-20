<?php
$currency=static fn($v)=>'R$ '.number_format((float)$v,2,',','.');
$admin=($u['perfil']??'')==='admin';
$r=$relatorio; $t=$r['total'];
$qs=http_build_query(['periodo'=>$periodo->opcao,'data_inicio'=>$periodo->opcao==='personalizado'?($periodo->inicio??''):'','data_fim'=>$periodo->opcao==='personalizado'?($periodo->fim??''):'','cc'=>$ccSelecionados]);
?>
<p class="muted">Gastos de compra por centro de custo do SGA (custo pelo pedido, canceladas fora), com ou sem patrimônio. É custo de compra, não pagamento. Os totais do parque, das compras e dos projetos podem representar as mesmas aquisições: não some esses totais.</p>
<section class="panel no-print"><h2>Parâmetros do relatório</h2>
<form method="<?=$can?'post':'get'?>" action="<?=$can?'/relatorio-gerar':'/relatorios'?>" class="stack"><?php if($can) echo csrf(); ?>
<fieldset><legend>Centros de custo adicionados (seleção múltipla; nenhum marcado = todos)</legend>
<?php if(!$ccDisponiveis): ?><p class="muted">Nenhum centro de custo adicionado ainda. Informe um código novo abaixo.</p><?php endif; ?>
<?php foreach($ccDisponiveis as $o): ?><label class="check"><input type="checkbox" name="cc[]" value="<?=e($o['codigo'])?>" <?=in_array($o['codigo'],$ccSelecionados,true)?'checked':''?>><?=e($o['rotulo'])?></label><?php endforeach; ?>
</fieldset>
<?php if($admin): ?>
<label>Novos centros de custo (códigos CCCOD do SGA, separados por vírgula)<input name="novos" maxlength="300" placeholder="Ex.: 1241, 1353" inputmode="numeric"></label>
<label class="check"><input type="checkbox" name="incluir_filhos" value="1">Incluir centros de custo filhos dos novos códigos</label>
<label class="check"><input type="checkbox" name="sincronizar" value="1" checked>Sincronizar com o SGA antes de gerar (obrigatório quando houver códigos novos)</label>
<p class="small muted">Os códigos novos são conferidos no SGA, entram no escopo da consulta (Integração) com os mesmos limites e a sincronização traz as compras deles para o relatório.</p>
<?php endif; ?>
<div class="date-fields"><label>Período<select name="periodo"><?php foreach(['todos'=>'Todo o histórico','hoje'=>'Hoje','7dias'=>'Últimos 7 dias','30dias'=>'Últimos 30 dias','mes'=>'Mês atual','ano'=>'Ano atual','personalizado'=>'Personalizado'] as $v=>$label): ?><option value="<?=$v?>" <?=$periodo->opcao===$v?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label><label>De<input type="date" name="data_inicio" value="<?=e($periodo->opcao==='personalizado'?$periodo->inicio:'')?>"></label><label>Até<input type="date" name="data_fim" value="<?=e($periodo->opcao==='personalizado'?$periodo->fim:'')?>"></label></div>
<p class="small muted">Para digitar datas, selecione Personalizado. Usa a data do processo de compra no SGA; o dia final é incluído por inteiro (Horário de Mato Grosso – UTC-4).</p>
<div><button><?=$admin?'Gerar relatório':'Atualizar relatório'?></button> <a class="button secondary" href="/relatorio-exportar?<?=e($qs)?>">Exportar CSV</a></div>
</form></section>
<?php if(!$r['origens']): ?><section class="panel empty"><h2>Sem dados</h2><p class="muted">Selecione ou informe pelo menos um centro de custo.</p></section><?php else: ?>
<p class="period-note"><strong><?=e($periodo->rotulo())?></strong> · <?=count($r['origens'])?> centro(s) de custo</p>
<section class="metrics project-metrics"><article><p>Total do pedido · sem dupla contagem</p><strong class="currency"><?=$currency($t['total'])?></strong><small><?=e($t['linhas'])?> linhas de compra</small></article><article><p>Com NF positiva</p><strong class="currency"><?=$currency($t['total_nf'])?></strong><small><?=e($t['linhas_nf'])?> linhas</small></article><article><p>Compras sem patrimônio</p><strong class="currency"><?=$currency($t['sem_patrimonio'])?></strong><small><?=e($t['sem_patrimonio_n'])?> linhas sem ativo vinculado</small></article><article><p>Centros de custo</p><strong><?=count($r['origens'])?></strong><small>Selecionados no relatório</small></article></section>
<section class="panel table-panel"><h2>Gastos por centro de custo</h2><table><thead><tr><th>Centro de custo</th><th>Linhas</th><th>Total do pedido</th><th>Com NF positiva</th><th>Sem patrimônio</th><th>Patrimônios</th><th>Bens sem número</th></tr></thead><tbody>
<?php foreach($r['origens'] as $o): ?><tr><td><strong><?=e($o['rotulo'])?></strong><small><a href="/compras?<?=e(http_build_query(['filtro'=>'todas','origem'=>$o['chave']]))?>">ver compras</a></small></td><td><?=e($o['linhas']??0)?></td><td><?=$currency($o['total']??0)?></td><td><?=$currency($o['total_nf']??0)?></td><td><?=$currency($o['sem_patrimonio']??0)?></td><td><?=$currency($o['ativos']['valor'])?><small><?=e($o['ativos']['n'])?> bens não baixados</small></td><td><?=$currency($o['sem_numero']['valor'])?><small><?=e($o['sem_numero']['n'])?> bens</small></td></tr><?php endforeach; ?>
</tbody></table><p class="small muted">Um CC com filhos pode conter outro CC selecionado; por isso a soma das linhas pode passar do total geral, que conta cada compra uma vez. Patrimônios e bens sem número usam, respectivamente, a data da compra e a data da nota no imobilizado.</p></section>
<?php if($r['meses']): ?><section class="panel table-panel"><h2>Evolução mensal · total do pedido</h2><table><thead><tr><th>Mês</th><?php foreach($r['origens'] as $o): ?><th><?=e($o['codigo'])?></th><?php endforeach; ?><th>Total (sem dupla contagem)</th></tr></thead><tbody>
<?php foreach($r['meses'] as $mes): ?><tr><td><?=e($mes==='sem data'?$mes:substr($mes,5,2).'/'.substr($mes,0,4))?></td><?php foreach($r['origens'] as $o): ?><td><?=isset($r['mensal'][$o['chave']][$mes])?$currency($r['mensal'][$o['chave']][$mes]):'—'?></td><?php endforeach; ?><td><strong><?=$currency($r['totalMes'][$mes]??0)?></strong></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<section class="panel table-panel"><h2>Linhas de compra</h2><?php if($r['detalheLimitado']): ?><p class="small muted">Exibindo as <?=\App\Services\Relatorios::LIMITE_DETALHE?> mais recentes. O CSV traz todas.</p><?php endif; ?><table><thead><tr><th>Data / produto</th><th>Requisitante</th><th>Centro de custo</th><th>Valor do pedido</th><th>NF</th><th>Patrimônio</th></tr></thead><tbody>
<?php foreach($r['linhas'] as $l): ?><tr><td><?=e(substr((string)$l['processo_data'],0,10))?><small><?=e($l['produto'])?> · SGA #<?=e($l['origem_item_id'])?> · Processo <?=e($l['processo_id'])?></small></td><td><?=e($l['requisitante'])?></td><td><?=e($l['cc_sga'])?><small><?=e($l['cc_nome'])?></small></td><td><?=$currency($l['base_pedido'])?></td><td><?=$l['vinculos_nf_positivos']>0?'Sim':'Não'?></td><td><?=$l['tem_patrimonio']?'Sim':'Não'?></td></tr><?php endforeach; ?>
</tbody></table><?php if(!$r['linhas']): ?><p class="empty">Nenhuma compra no período.</p><?php endif; ?></section>
<?php endif; ?>
