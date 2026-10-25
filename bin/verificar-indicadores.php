<?php
declare(strict_types=1);
// Teste portátil dos Indicadores de TI (sem banco): números, datas, metas, CSV e gráficos SVG compatíveis com a CSP.
if(PHP_SAPI!=='cli') exit(1);
define('ROOT',dirname(__DIR__));
spl_autoload_register(static function(string $c): void { if(str_starts_with($c,'App\\')) require ROOT.'/app/'.str_replace('\\','/',substr($c,4)).'.php'; });
if(!function_exists('e')) { function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
use App\Services\{IndicadoresTi as I,GraficoSvg as G};
$check=static function(bool $ok,string $msg): void { if(!$ok) throw new RuntimeException($msg); };
$falha=static function(callable $fn,string $trecho,string $msg) use($check): void { try { $fn(); $check(false,$msg); } catch(RuntimeException $e) { $check(str_contains($e->getMessage(),$trecho),$msg.' ('.$e->getMessage().')'); } };
try {
    foreach(['99,5'=>99.5,'99.5'=>99.5,'90,8%'=>90.8,'1.234,5'=>1234.5,' 7 '=>7.0] as $t=>$v) $check(abs(I::numero($t)-$v)<1e-9,"Número $t mal interpretado.");
    $falha(fn()=>I::numero('abc'),'inválido','Texto aceito como número.');
    $check(I::data('20/10/2026')==='2026-10-20' && I::data('2026-01-31')==='2026-01-31','Datas não convertidas.');
    $falha(fn()=>I::data('31/02/2026'),'inválida','Data impossível aceita.');
    $falha(fn()=>I::data(date('d/m/Y',strtotime('+3 days'))),'futura','Data futura aceita.');
    $pct=['nome'=>'Disponibilidade','unidade'=>'percentual','agregacao'=>'media','meta_operador'=>'>=','meta'=>'99.5000'];
    $qtd=['nome'=>'Problemas','unidade'=>'quantidade','agregacao'=>'ultimo','meta_operador'=>'<=','meta'=>'0.0000'];
    $check(I::situacao(99.5,$pct)==='ok' && I::situacao(99.49,$pct)==='fora' && I::situacao(0,$qtd)==='ok' && I::situacao(1,$qtd)==='fora' && I::situacao(null,$qtd)==='sem_dado','Regra de meta incorreta.');
    $check(I::formatar(95.3125,$pct)==='95,31%' && I::formatar(36,$qtd)==='36' && I::formatar(null,$qtd)==='—','Formatação incorreta.');
    $check(I::metaTexto($pct)==='Meta ≥ 99,50%' && I::metaTexto($qtd)==='Meta ≤ 0','Texto de meta incorreto.');
    $falha(fn()=>I::validarValor($pct,100.1),'entre 0 e 100','Percentual acima de 100 aceito.');
    $falha(fn()=>I::validarValor($qtd,-1),'negativa','Quantidade negativa aceita.');
    // CSV: BOM, separador vírgula, Windows-1252 e cabeçalho obrigatório.
    $l=I::lerCsv("\xEF\xBB\xBFcodigo,data,valor\r\ncobertura_mfa,20/10/2026,\"90,8\"\r\n\r\n",'medicoes');
    $check(count($l)===1 && $l[0]['valor']==='90,8' && $l[0]['_linha']===2,'CSV com vírgula/BOM mal lido.');
    $l=I::lerCsv("codigo;risco;nivel\nR-1;Pol\xEDtica sem revis\xE3o;9\n",'riscos'); $check($l[0]['risco']==='Política sem revisão','CSV em Windows-1252 não convertido.');
    $falha(fn()=>I::lerCsv("codigo;valor\nx;1",'medicoes'),'data','Cabeçalho incompleto aceito.');
    $falha(fn()=>I::lerCsv("codigo;data;valor\n",'medicoes'),'Nenhuma linha','CSV sem dados aceito.');
    $check(str_starts_with(I::modelo('medicoes'),"\xEF\xBB\xBFcodigo;data;valor;observacao"),'Modelo de medições incorreto.');
    // Gráficos: sem atributo style (CSP), com dica e tabela; dia ausente quebra a linha.
    $svg=G::linhas('Teste',['2026-10-01','2026-10-02','2026-10-03','2026-10-04'],['a'=>['2026-10-01'=>99.0,'2026-10-02'=>98.0,'2026-10-04'=>97.0],'b'=>['2026-10-01'=>95.5,'2026-10-02'=>96.0,'2026-10-03'=>96.5,'2026-10-04'=>97.0]],['a'=>'Hosts <críticos>','b'=>'Links']);
    $check(!preg_match('/\sstyle=/',$svg) && substr_count($svg,'<polyline')===2 && str_contains($svg,'Hosts &lt;críticos&gt;') && str_contains($svg,'Ver dados em tabela') && str_contains($svg,'—'),'Gráfico de linhas incorreto.');
    $r=G::rosca('Controles',[['rotulo'=>'Implementados','valor'=>10],['rotulo'=>'Em implantação','valor'=>13],['rotulo'=>'A avaliar','valor'=>30]],'vazio');
    $check(substr_count($r,'class="viz-fatia')===3 && str_contains($r,'>53<') && str_contains($r,'(56,6%)') && !preg_match('/\sstyle=/',$r),'Rosca incorreta.');
    $check(str_contains(G::rosca('x',[['rotulo'=>'a','valor'=>0]],'Sem dados'),'Sem dados'),'Rosca vazia sem aviso.');
    $b=G::barras('Riscos',[['rotulo'=>'Alto','valor'=>1],['rotulo'=>'Médio','valor'=>4]],'vazio',300);
    $check(substr_count($b,'<rect')===2 && str_contains($b,'width="300"') && !preg_match('/\sstyle=/',$b),'Barras incorretas.');
    echo "OK: indicadores de TI — números, datas, metas (≥/≤), formatação, CSV (BOM, separador, Windows-1252, cabeçalho), modelos e gráficos SVG sem style (CSP). Sem banco.\n";
} catch(Throwable $e) { fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL); exit(1); }
