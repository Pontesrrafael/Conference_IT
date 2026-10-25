<?php
/**
 * Dados dos indicadores: lançamento manual, importação CSV, metas (admin), riscos e últimas medições.
 * @var array $catalogo @var array $medicoes @var array $riscosLista @var bool $admin @var bool $pronto
 */
use App\Services\IndicadoresTi;
$fmt=static fn($v,array $i)=>IndicadoresTi::formatar($v===null?null:(float)$v,$i);
?>
<p class="muted">Enquanto as integrações diretas (Zabbix, Microsoft 365, NinjaOne) não entram, os valores do Power BI são lançados aqui ou importados por CSV. Um lançamento por indicador e por dia: repetir a data substitui o valor. Tudo fica na auditoria.</p>
<?php if(!$pronto): ?><section class="panel"><h2>Migração pendente</h2><p>Feche e abra a aplicação pelo <strong>iniciar.ps1</strong> para aplicar a migração <strong>007_indicadores_ti</strong>.</p></section><?php return; endif; ?>
<p><a class="button secondary" href="/indicadores">← Voltar à Visão Executiva</a></p>
<div class="columns">
<section class="panel"><h2>Lançar medição</h2>
<form method="post" action="/indicador-medicao" class="stack"><?=csrf()?>
<label>Indicador<select name="codigo" required><?php foreach(['executivo'=>'Cartões com meta','controles'=>'Catálogo de controles (gráfico por status)'] as $g=>$rot): ?><optgroup label="<?=e($rot)?>"><?php foreach($catalogo as $i) if($i['grupo']===$g): ?><option value="<?=e($i['codigo'])?>"><?=e($i['nome'])?> (<?=$i['unidade']==='percentual'?'%':'qtd.'?>)</option><?php endif; ?></optgroup><?php endforeach; ?></select></label>
<div class="date-fields"><label>Data de referência<input type="date" name="data" value="<?=e(date('Y-m-d'))?>" max="<?=e(date('Y-m-d'))?>" required></label><label>Valor<input name="valor" inputmode="decimal" placeholder="Ex.: 99,62" required maxlength="20"></label></div>
<label>Observação (opcional)<input name="observacao" maxlength="300" placeholder="Ex.: Zabbix SLA de 20/10, hosts críticos"></label>
<button>Lançar</button></form></section>

<section class="panel"><h2>Importar CSV</h2>
<form method="post" action="/indicadores-importar" enctype="multipart/form-data" class="stack"><?=csrf()?>
<label>Tipo<select name="tipo"><option value="medicoes">Medições (codigo; data; valor; observacao)</option><option value="riscos">Registro de riscos (codigo; risco; nivel; classificacao; responsavel; situacao)</option></select></label>
<label>Arquivo CSV (até 2 MB, separador ; ou ,)<input type="file" name="arquivo" accept=".csv,text/csv" required></label>
<button>Importar</button></form>
<p class="small muted">Modelos: <a href="/indicadores-modelo?tipo=medicoes">medições</a> · <a href="/indicadores-modelo?tipo=riscos">riscos</a>. Datas em dd/mm/aaaa ou aaaa-mm-dd; valores com vírgula ou ponto. Todas as linhas são conferidas antes: com qualquer erro, nada é importado. Riscos são atualizados pelo código.</p></section>
</div>

<section class="panel table-panel"><h2>Catálogo e metas</h2><table><thead><tr><th>Indicador</th><th>Fonte</th><th>Cálculo no período</th><th>Meta</th><th>Última medição</th></tr></thead><tbody>
<?php foreach($catalogo as $i): ?><tr><td><strong><?=e($i['nome'])?></strong><small><?=e($i['codigo'])?></small></td><td><?=e($i['fonte'])?></td><td><?=$i['agregacao']==='media'?'Média das medições':'Última medição'?></td>
<td><?php if($i['grupo']!=='executivo'): ?><span class="muted">—</span><?php elseif($admin): ?><form method="post" action="/indicador-meta" class="meta-form"><?=csrf()?><input type="hidden" name="codigo" value="<?=e($i['codigo'])?>"><select name="operador" aria-label="Operador da meta de <?=e($i['nome'])?>"><option value=">=" <?=$i['meta_operador']==='>='?'selected':''?>>≥</option><option value="<=" <?=$i['meta_operador']==='<='?'selected':''?>>≤</option></select><input name="meta" value="<?=e($i['meta']===null?'':rtrim(rtrim(number_format((float)$i['meta'],2,',',''),'0'),','))?>" inputmode="decimal" maxlength="12" aria-label="Meta de <?=e($i['nome'])?>"><button class="secondary">Salvar</button></form><?php else: ?><?=e(IndicadoresTi::metaTexto($i))?><?php endif; ?></td>
<td><?php $ult=\App\Core\Database::one('SELECT valor,data_ref FROM indicadores_ti_medicoes WHERE indicador_id=? ORDER BY data_ref DESC LIMIT 1',[$i['id']]); ?><?=$ult?e($fmt($ult['valor'],['agregacao'=>'media']+$i).' em '.date('d/m/Y',strtotime($ult['data_ref']))):'<span class="muted">sem medição</span>'?></td></tr><?php endforeach; ?>
</tbody></table><?php if(!$admin): ?><p class="small muted">Somente administradores alteram metas.</p><?php endif; ?></section>

<section class="panel table-panel"><h2>Registro de riscos</h2>
<details><summary>Incluir ou atualizar um risco</summary>
<form method="post" action="/indicador-risco" class="stack"><?=csrf()?>
<div class="date-fields"><label>Código<input name="codigo" required maxlength="30" pattern="[A-Za-z0-9._\-]{1,30}" placeholder="R-001"></label><label>Nível (1–25)<input name="nivel" type="number" min="1" max="25" required></label><label>Classificação<select name="classificacao"><option value="">Pelo nível</option><?php foreach(IndicadoresTi::CLASSIFICACOES as $c): ?><option><?=e($c)?></option><?php endforeach; ?></select></label><label>Situação<select name="situacao"><?php foreach(IndicadoresTi::SITUACOES_RISCO as $k=>$rot): ?><option value="<?=e($k)?>"><?=e($rot)?></option><?php endforeach; ?></select></label></div>
<label>Risco<input name="risco" required maxlength="300"></label><label>Responsável<input name="responsavel" maxlength="120" value="TI - Salinas Gold"></label><button>Salvar risco</button>
<p class="small muted">Sem classificação informada: nível ≥ 20 Crítico, ≥ 12 Alto, ≥ 6 Médio, abaixo disso Baixo. Repetir o código atualiza o risco.</p></form></details>
<?php if(!$riscosLista): ?><p class="muted">Nenhum risco cadastrado.</p><?php else: ?><table><thead><tr><th>Código / risco</th><th>Nível</th><th>Classificação</th><th>Situação</th><th>Responsável</th></tr></thead><tbody>
<?php foreach($riscosLista as $r): ?><tr><td><strong><?=e($r['risco'])?></strong><small><?=e($r['codigo'])?> · atualizado em <?=e(date('d/m/Y H:i',strtotime($r['atualizado_em'])))?></small></td><td><?=e($r['nivel'])?></td><td><?=e($r['classificacao'])?></td><td><?=e(IndicadoresTi::SITUACOES_RISCO[$r['situacao']]??$r['situacao'])?></td><td><?=e($r['responsavel']?:'—')?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>

<section class="panel table-panel"><h2>Últimas medições</h2><?php if(!$medicoes): ?><p class="muted">Nenhuma medição lançada ainda.</p><?php else: ?><table><thead><tr><th>Data / indicador</th><th>Valor</th><th>Origem</th><th>Registrado por</th><th>Ação</th></tr></thead><tbody>
<?php foreach($medicoes as $m): ?><tr><td><?=e(date('d/m/Y',strtotime($m['data_ref'])))?><small><?=e($m['nome'])?><?=$m['observacao']!==''?' · '.e($m['observacao']):''?></small></td><td><?=e($fmt($m['valor'],['agregacao'=>'media','unidade'=>$m['unidade']]))?></td><td><?=e($m['origem']==='csv'?'CSV':'Manual')?></td><td><?=e($m['autor']??'—')?><small><?=e(date('d/m/Y H:i',strtotime($m['registrado_em'])))?></small></td>
<td><form method="post" action="/indicador-medicao-remover"><?=csrf()?><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="secondary">Remover</button></form></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
