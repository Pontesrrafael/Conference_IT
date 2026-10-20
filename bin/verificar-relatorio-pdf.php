<?php
declare(strict_types=1);
// Teste portátil do relatório em PDF: sem banco, sem SGA, dados sintéticos. Valida estrutura, paginação, acentos e rodapé.
if(PHP_SAPI!=='cli') exit(1);
define('ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $c): void { if(str_starts_with($c,'App\\')) require ROOT.'/app/'.str_replace('\\','/',substr($c,4)).'.php'; });
use App\Services\{Pdf,RelatorioPdf,Periodo};
$check=static function(bool $ok,string $msg): void { if(!$ok) throw new RuntimeException($msg); };
try {
    // Métricas e corte de texto.
    $p=new Pdf(); $check(abs($p->larguraTexto('Relatório',10)-$p->larguraTexto('Relatorio',10))<0.01,'Métrica de acento incorreta.');
    $check($p->larguraTexto('W',10,true)>$p->larguraTexto('i',10),'Métricas de largura incoerentes.');
    $cut=$p->ajustar(str_repeat('CABO OPTICO ',20),8,false,120); $check(str_ends_with($cut,'...') && $p->larguraTexto($cut,8)<=120,'Corte de texto falhou.');
    $check(Pdf::cp1252('Ação — ç')==="A\xE7\xE3o - \xE7",'Conversão Windows-1252 incorreta.');
    // Relatório sintético com paginação.
    $o=['chave'=>'cc:900','codigo'=>900,'rotulo'=>'900 · CC SINTÉTICO (ação)','linhas'=>150,'total'=>'1000.50','total_nf'=>'900.00','sem_patrimonio'=>'800.00','ativos'=>['n'=>1,'valor'=>'200.50'],'sem_numero'=>['n'=>2,'valor'=>'50.00']];
    $linhas=[]; for($i=0;$i<150;$i++) $linhas[]=['processo_data'=>'2026-10-01 00:00:00','produto'=>'Item sintético (teste) \\ '.$i,'requisitante'=>'Requisitante','cc_sga'=>900,'cc_nome'=>'CC SINTÉTICO','origem_item_id'=>$i,'processo_id'=>1,'base_pedido'=>'6.67','vinculos_nf_positivos'=>1,'tem_patrimonio'=>false];
    $r=['origens'=>[$o],'total'=>['linhas'=>150,'linhas_nf'=>150,'total'=>'1000.50','total_nf'=>'900.00','sem_patrimonio'=>'800.00','sem_patrimonio_n'=>149],'meses'=>['2026-10'],'mensal'=>['cc:900'=>['2026-10'=>'1000.50']],'totalMes'=>['2026-10'=>'1000.50'],'linhas'=>$linhas,'detalheLimitado'=>false];
    $pdf=RelatorioPdf::gerar($r,Periodo::ler(['periodo'=>'todos']),'Teste',new DateTimeImmutable('2026-10-20 11:00'));
    $check(str_starts_with($pdf,'%PDF-1.4') && str_ends_with(rtrim($pdf),'%%EOF'),'Cabeçalho/fim do PDF inválido.');
    // Tabela xref: cada deslocamento aponta para "N 0 obj".
    preg_match('/startxref\n(\d+)/',$pdf,$m); $xref=(int)$m[1]; $check(substr($pdf,$xref,4)==='xref','startxref inválido.');
    preg_match('/xref\n0 (\d+)\n/',substr($pdf,$xref),$m); $n=(int)$m[1];
    $linhasXref=explode("\n",substr($pdf,$xref)); for($k=1;$k<$n;$k++) { $off=(int)substr($linhasXref[2+$k],0,10); $check(str_starts_with(substr($pdf,$off,12),$k.' 0 obj'),"Deslocamento do objeto $k inválido."); }
    preg_match('/\/Count (\d+)/',$pdf,$m); $paginas=(int)$m[1]; $check($paginas>=3,'Paginação não ocorreu (150 linhas).');
    $conteudo=''; preg_match_all('/stream\n(.*?)\nendstream/s',$pdf,$st); foreach($st[1] as $s) $conteudo.=function_exists('gzuncompress')?(@gzuncompress($s)?:$s):$s;
    $check(str_contains($conteudo,"(Relat\xF3rio de gastos por centro de custo)"),'Título com acento ausente.');
    $check(str_contains($conteudo,'(R$ 1.000,50)') && str_contains($conteudo,'(Total geral \\(sem dupla contagem\\))'),'Totais ou escape de parênteses ausentes.');
    $check(substr_count($conteudo,'(P'."\xE1".'gina ')===$paginas && str_contains($conteudo,"(P\xE1gina $paginas de $paginas)"),'Rodapé de paginação incorreto.');
    $check(str_contains($conteudo,'(Item sint'."\xE9".'tico \\(teste\\) \\\\ 149)'),'Escape de barra invertida ou última linha ausente.');
    // Logotipo oficial incorporado uma única vez e desenhado em todas as páginas.
    $check(substr_count($pdf,'/Subtype /Image')===1 && str_contains($pdf,'/Predictor 15') && substr_count($conteudo,'/Im1 Do')===$paginas,'Logotipo ausente ou duplicado no PDF.');
    try { (new Pdf())->imagemPng(ROOT.'/public/assets/brand/salinas-gold-horizontal.png',0,0,10); $check(false,'PNG com transparência aceito sem conversão.'); } catch(RuntimeException $e) { $check(str_contains($e->getMessage(),'PDF'),'Erro inesperado no PNG.'); }
    echo "OK: relatório PDF — logotipo em todas as páginas, métricas, corte de texto, Windows-1252, estrutura xref, $paginas páginas com cabeçalho/rodapé, totais, escapes e última linha. Dados sintéticos.\n";
} catch(Throwable $e) { fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL); exit(1); }
