<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database as DB;
use App\Services\Integracao;
use App\Services\Inventario;
use App\Services\Projetos;
use App\Services\ProjectXml;
use App\Services\DocumentosCompra;
use App\Services\Periodo;
use App\Services\Parque;
use App\Services\PatrimoniosSga;
// Banco temporário criado exclusivamente para esta verificação; nunca usa os dados reais.
$nome='salinas_ti_verificacao_'.bin2hex(random_bytes(5));
$root=json_decode((string)file_get_contents(ROOT.'/storage/maria-admin.json'),true,512,JSON_THROW_ON_ERROR);
$admin=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root',$root['senha'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$check=static function(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); };
$reject=static function(callable $fn) use($check): void { $failed=false; try { $fn(); } catch(Throwable) { $failed=true; } $check($failed,'A operação inválida foi aceita.'); };
$key=static fn()=>bin2hex(random_bytes(16)).'-000';
$created=false;
$exitCode=0;
try {
    $admin->exec("CREATE DATABASE `$nome` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $created=true;
    $admin->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$nome`.* TO 'salinas_ti'@'127.0.0.1'");
    $admin->exec("USE `$nome`");
    foreach(glob(ROOT.'/database/00*.sql') as $file) {
        $delimiter=';'; $buffer='';
        foreach(file($file) as $line) {
            if(preg_match('/^DELIMITER\s+(\S+)/i',trim($line),$m)) { $delimiter=$m[1]; continue; }
            $buffer.=$line;
            if(str_ends_with(rtrim($buffer),$delimiter)) { $sql=substr(rtrim($buffer),0,-strlen($delimiter)); if(trim($sql)!=='') $admin->exec($sql); $buffer=''; }
        }
    }
    putenv('DB_DATABASE='.$nome);
    DB::run("INSERT INTO usuarios(nome,email,senha_hash,perfil) VALUES('Teste','teste@local',?,'admin')",[password_hash(bin2hex(random_bytes(8)),PASSWORD_BCRYPT)]);
    $actor=(int)DB::pdo()->lastInsertId();
    $rows=json_decode((string)file_get_contents(ROOT.'/evidencias/coleta-minerion.json'),true,512,JSON_THROW_ON_ERROR)['linhas'];
    Integracao::importar($rows,'evidencia',$actor); Integracao::importar($rows,'evidencia',$actor);
    $check((int)DB::one('SELECT COUNT(*) n FROM compras_origem')['n']===1441,'Duplicação na sincronização.');
    $check((int)DB::one('SELECT COUNT(*) n FROM compras_origem WHERE vinculos_nf_positivos>0')['n']===587,'População faturada divergente.');
    echo "OK: migrações MariaDB, carga completa e sincronização idempotente.\n";
    DB::run("INSERT INTO locais(nome,tipo) VALUES('Local A','sala'),('Local B','sala')");
    $loc=DB::all('SELECT id FROM locais ORDER BY id'); $a=$loc[0]['id']; $b=$loc[1]['id'];
    DB::run("INSERT INTO responsaveis(nome) VALUES('Responsavel de teste')"); $resp=(int)DB::pdo()->lastInsertId();
    $cc=(int)DB::one('SELECT id FROM centros_custo LIMIT 1')['id'];
    $c=DB::one('SELECT * FROM compras_origem WHERE origem_item_id=62609');
    Inventario::triagem(['compra_id'=>$c['id'],'triagem'=>'incluir','controle'=>'serializado','unidade'=>'un','categoria'=>'Rede','motivo'=>'Teste de patrimônio'],$actor);
    $form=['compra_id'=>$c['id'],'quantidade'=>'1','local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Conferência teste','patrimonio'=>'TESTE-001','serie'=>'SERIE-001','chave_operacao'=>$key()];
    Inventario::incorporar($form,$actor); Inventario::incorporar($form,$actor);
    $check((int)DB::one('SELECT COUNT(*) n FROM ativos')['n']===1,'Patrimônio duplicado no reenvio.');
    $active=DB::one('SELECT * FROM ativos LIMIT 1');
    $reject(fn()=>Inventario::triagem(['compra_id'=>$c['id'],'triagem'=>'incluir','controle'=>'quantidade','motivo'=>'Mudar tipo'],$actor));
    $reject(fn()=>Inventario::moverAtivo(['ativo_id'=>$active['id'],'tipo'=>'alocacao','local_id'=>$b,'cc_id'=>$cc,'motivo'=>'Sem responsável','chave_operacao'=>$key()],$actor));
    $move=['ativo_id'=>$active['id'],'tipo'=>'alocacao','local_id'=>$b,'cc_id'=>$cc,'responsavel_id'=>$resp,'motivo'=>'Entrega teste','chave_operacao'=>$key()];
    Inventario::moverAtivo($move,$actor); Inventario::moverAtivo($move,$actor);
    $check(DB::one('SELECT situacao FROM ativos WHERE id=?',[$active['id']])['situacao']==='alocado','Falha na alocação.');
    Inventario::moverAtivo([...$move,'tipo'=>'transferencia','local_id'=>$a,'chave_operacao'=>$key()],$actor);
    Inventario::moverAtivo([...$move,'tipo'=>'devolucao','local_id'=>$a,'chave_operacao'=>$key()],$actor);
    Inventario::moverAtivo([...$move,'tipo'=>'manutencao','chave_operacao'=>$key()],$actor);
    Inventario::moverAtivo([...$move,'tipo'=>'devolucao','chave_operacao'=>$key()],$actor);
    echo "OK: patrimônio, reenvio de formulário, alocação, transferência, devolução e manutenção.\n";
    $mat=DB::one('SELECT * FROM compras_origem WHERE quantidade_vinculada_nf>=6 AND produto_id<>? AND cancelado_em IS NULL LIMIT 1',[$c['produto_id']]);
    Inventario::triagem(['compra_id'=>$mat['id'],'triagem'=>'incluir','controle'=>'quantidade','unidade'=>'un','motivo'=>'Material teste'],$actor);
    Inventario::incorporar(['compra_id'=>$mat['id'],'quantidade'=>'6','local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Contagem teste','chave_operacao'=>$key()],$actor);
    $lote=(int)DB::one('SELECT id FROM lotes_estoque WHERE compra_origem_id=?',[$mat['id']])['id'];
    $mf=['lote_id'=>$lote,'origem_id'=>$a,'cc_origem_id'=>$cc,'destino_id'=>$b,'cc_destino_id'=>$cc,'tipo'=>'transferencia','quantidade'=>'2.5','motivo'=>'Transferência teste','chave_operacao'=>$key()];
    Inventario::moverMaterial($mf,$actor); Inventario::moverMaterial($mf,$actor);
    $saldo=DB::one('SELECT quantidade FROM saldos_estoque WHERE lote_id=? AND local_id=? AND centro_custo_id=?',[$lote,$a,$cc]);
    $check(bccomp($saldo['quantidade'],'3.5',4)===0,'Transferência duplicada ou saldo errado.');
    $reject(fn()=>Inventario::moverMaterial([...$mf,'quantidade'=>'999','chave_operacao'=>$key()],$actor));
    Inventario::moverMaterial([...$mf,'tipo'=>'consumo','quantidade'=>'1','chave_operacao'=>$key()],$actor);
    $sum=DB::one('SELECT SUM(quantidade) q FROM saldos_estoque WHERE lote_id=?',[$lote]);
    $check(bccomp($sum['q'],'5',4)===0,'Saldo total incorreto após consumo.');
    $reject(fn()=>Inventario::incorporar(['compra_id'=>$mat['id'],'quantidade'=>'99999','local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Excesso','chave_operacao'=>$key()],$actor));
    echo "OK: quantidade fracionária, transferência, consumo e bloqueios de saldo/recebimento excedente.\n";
    Integracao::importar($rows,'evidencia',$actor);
    $check(DB::one('SELECT triagem FROM compras_origem WHERE id=?',[$c['id']])['triagem']==='incluir','Triagem perdida na sincronização.');
    $check((int)DB::one('SELECT COUNT(*) n FROM ativos')['n']===1,'Sincronização alterou patrimônio.');
    $changed=$rows;
    foreach($changed as &$r) if((int)$r['origem_item_id']===62609) $r['quantidade_vinculada_nf']=0;
    unset($r); Integracao::importar($changed,'sga',$actor);
    $check((bool)DB::one('SELECT conciliacao_pendente FROM compras_origem WHERE id=?',[$c['id']])['conciliacao_pendente'],'Divergência não sinalizada.');
    $reject(fn()=>Inventario::incorporar([...$form,'patrimonio'=>'TESTE-002','serie'=>'SERIE-002','chave_operacao'=>$key()],$actor));
    $reject(fn()=>DB::run("UPDATE movimentacoes SET motivo='alterado' LIMIT 1"));
    $reject(fn()=>DB::run('DELETE FROM auditoria LIMIT 1'));
    $reject(fn()=>Integracao::importar([$rows[0],$rows[0]],'evidencia',$actor));
    echo "OK: sincronização preserva inventário, divergência bloqueia incorporação e histórico é imutável.\n";
    $p1=Projetos::salvar(['codigo'=>'TESTE-P1','nome'=>'Projeto teste 1','orcamento'=>'100,00','status'=>'em_andamento','inicio'=>'2026-01-01','fim'=>'2026-12-31'],$actor);
    $p2=Projetos::salvar(['codigo'=>'TESTE-P2','nome'=>'Projeto teste 2','status'=>'planejado'],$actor);
    $purchase=DB::one('SELECT * FROM vw_compras_custos WHERE base_pedido>0 AND cancelado_em IS NULL AND conciliacao_pendente=0 AND origem_presente=1 LIMIT 1');
    $rate=['compra_id'=>$purchase['id'],'projeto_id'=>$p1,'percentual'=>'60','motivo'=>'Rateio homologacao'];
    Projetos::apontar($rate,$actor); Projetos::apontar($rate,$actor);
    Projetos::apontar([...$rate,'projeto_id'=>$p2,'percentual'=>'40'],$actor);
    $check((int)DB::one('SELECT COUNT(*) n FROM compras_projetos')['n']===2,'Reenvio duplicou rateio.');
    $reject(fn()=>Projetos::apontar([...$rate,'percentual'=>'61'],$actor));
    $d=Projetos::detalhe($p1);
    $expected=bcmul((string)$purchase['base_pedido'],'0.60',2);
    $check(bccomp($d['totalCusto'],$expected,2)===0,'Custo por percentual incorreto.');
    $check(bccomp($d['saldoOrcamento'],bcsub('100',$expected,2),2)===0,'Saldo do orçamento incorreto.');
    $tot=DB::one('SELECT SUM(valor_atribuido) v FROM vw_apontamentos_custos')['v'];
    $check(bccomp($tot,(string)$purchase['base_pedido'],2)<=0,'Rateio duplicou valor da compra.');
    $reject(fn()=>Projetos::apontar([...$rate,'percentual'=>'50','percentual_anterior'=>'59'],$actor));
    $reject(fn()=>Projetos::salvar(['codigo'=>'INVALIDO','nome'=>'Invalido','orcamento'=>'-1'],$actor));
    $reject(fn()=>Projetos::salvar(['codigo'=>'INVALIDO','nome'=>'Invalido','inicio'=>'2026-04-31'],$actor));
    $old=DB::one('SELECT * FROM projetos WHERE id=?',[$p1]);
    $updated=['projeto_id'=>$p1,'versao'=>$old['versao'],'codigo'=>'TESTE-P1','nome'=>'Projeto atualizado','status'=>'concluido','orcamento'=>'150.00'];
    Projetos::salvar($updated,$actor);
    $reject(fn()=>Projetos::salvar($updated,$actor));
    $link=DB::one('SELECT * FROM compras_projetos WHERE projeto_id=?',[$p2]);
    Projetos::desvincular(['apontamento_id'=>$link['id'],'percentual_anterior'=>$link['percentual'],'motivo'=>'Redistribuicao teste'],$actor);
    Projetos::apontar([...$rate,'percentual'=>'100'],$actor);
    $check(bccomp(Projetos::detalhe($p1)['totalCusto'],bcmul((string)$purchase['base_pedido'],'1',2),2)===0,'Redistribuição incorreta.');
    $p2row=DB::one('SELECT * FROM projetos WHERE id=?',[$p2]);
    Projetos::salvar(['projeto_id'=>$p2,'versao'=>$p2row['versao'],'codigo'=>'TESTE-P2','nome'=>'Arquivado','status'=>'arquivado'],$actor);
    $reject(fn()=>Projetos::apontar([...$rate,'projeto_id'=>$p2,'percentual'=>'1'],$actor));
    Integracao::importar($rows,'evidencia',$actor);
    $check((int)DB::one('SELECT COUNT(*) n FROM compras_projetos')['n']===1,'Sincronização perdeu apontamento.');
    $check(bccomp(Projetos::detalhe($p1)['totalCusto'],bcmul((string)$purchase['base_pedido'],'1',2),2)===0,'Custo alterado inesperadamente na sincronização.');
    $check((int)DB::one("SELECT COUNT(*) n FROM auditoria WHERE entidade='compras_projetos'")['n']>=5,'Auditoria dos rateios ausente.');
    echo "OK: projetos, rateio 60/40, limite de 100%, custo decimal, orçamento, edição concorrente, desvinculação, arquivamento e preservação na sincronização.\n";
    $_SESSION=['csrf'=>'teste-renderizacao'];
    $path='/projeto'; $title='Painel do projeto'; $u=['id'=>$actor,'nome'=>'Teste','perfil'=>'admin']; $can=true;
    $ccs=DB::all('SELECT id,nome FROM centros_custo'); $locais=[]; $responsaveis=[];
    extract(Projetos::detalhe($p1),EXTR_OVERWRITE); $busca=''; $candidatas=[];
    set_error_handler(static function(int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
    ob_start();
    try { require ROOT.'/app/Views/app.php'; $html=ob_get_clean(); } catch(Throwable $e) { ob_end_clean(); throw $e; } finally { restore_error_handler(); }
    $check(str_contains($html,'Projeto atualizado') && str_contains($html,'Compras apontadas ao projeto') && str_contains($html,'cost-bar'),'Painel de projeto não renderizou corretamente.');
    $check(str_contains($html,'projeto-exportar?'),'Exportação não aparece no painel.');
    echo "OK: painel com compras, orçamento e gráficos renderizado sem avisos PHP.\n";
    DB::run('UPDATE compras_origem SET conciliacao_pendente=0 WHERE origem_item_id=62609');
    $dataPat=json_decode((string)file_get_contents(ROOT.'/evidencias/patrimonios-identificados.json'),true,512,JSON_THROW_ON_ERROR)['registros'];
    $statsPat=PatrimoniosSga::importar($dataPat,'evidencia',$actor);
    $check($statsPat['associado']===67 && $statsPat['ambiguo']===4 && $statsPat['sem_numero']===139,'Contagem de associações patrimoniais incorreta.');
    $countBefore=(int)DB::one('SELECT COUNT(*) n FROM ativos')['n'];
    PatrimoniosSga::importar($dataPat,'evidencia',$actor);
    $check((int)DB::one('SELECT COUNT(*) n FROM ativos')['n']===$countBefore,'Patrimônios duplicados na nova importação.');
    $pat=DB::one("SELECT a.* FROM ativos a WHERE a.patrimonio='6208' AND a.empresa_patrimonio=3");
    $check($pat['situacao']==='aguardando_conferencia' && $pat['local_atual_id']===null && $pat['conferido_em']===null,'Importação inventou conferência física.');
    $sgaComp=DB::one('SELECT incorporada_quantidade FROM compras_origem WHERE id=?',[$pat['compra_origem_id']]);
    $check(bccomp($sgaComp['incorporada_quantidade'],'0',4)===0,'Associação documental gerou saldo.');
    $reject(fn()=>Inventario::moverAtivo(['ativo_id'=>$pat['id'],'tipo'=>'transferencia','local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Teste sem conferir','chave_operacao'=>$key()],$actor));
    PatrimoniosSga::conferir(['ativo_id'=>$pat['id'],'local_id'=>$a,'cc_id'=>$cc,'serie'=>'SGA-CONFERIDA-001','motivo'=>'Conferência física de teste'],$actor);
    $reject(fn()=>PatrimoniosSga::conferir(['ativo_id'=>$pat['id'],'local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Reenvio de conferência'],$actor));
    PatrimoniosSga::importar($dataPat,'evidencia',$actor);
    $after=DB::one('SELECT * FROM ativos WHERE id=?',[$pat['id']]);
    $check($after['situacao']==='em_estoque' && (int)$after['local_atual_id']===(int)$a && $after['serie']==='SGA-CONFERIDA-001','Sincronização apagou conferência local.');
    $amb=DB::one("SELECT * FROM patrimonios_sga WHERE patrimonio='2243'");
    $reject(fn()=>PatrimoniosSga::escolher(['patrimonio_sga_id'=>$amb['id'],'origem_item_id'=>123,'motivo'=>'Compra inválida'],$actor));
    $candidates=json_decode($amb['candidatos_json'],true);
    PatrimoniosSga::escolher(['patrimonio_sga_id'=>$amb['id'],'origem_item_id'=>$candidates[0],'motivo'=>'Nota conferida no teste'],$actor);
    $check(DB::one('SELECT situacao FROM patrimonios_sga WHERE id=?',[$amb['id']])['situacao']==='associado','Escolha manual não associou.');
    PatrimoniosSga::importar($dataPat,'evidencia',$actor);
    $check(DB::one('SELECT situacao FROM patrimonios_sga WHERE id=?',[$amb['id']])['situacao']==='associado','Nova coleta perdeu escolha manual válida.');
    $diff=$dataPat;
    foreach($diff as &$r) if((int)$r['imobilizado_item_id']===8528) $r['patrimonio_numerico']=9996208;
    unset($r); PatrimoniosSga::importar($diff,'sga',$actor);
    $check(DB::one('SELECT situacao FROM patrimonios_sga WHERE imobilizado_item_id=8528')['situacao']==='conflito','Mudança da etiqueta não sinalizada.');
    $check(DB::one('SELECT patrimonio FROM ativos WHERE id=?',[$pat['id']])['patrimonio']==='6208','Importação sobrescreveu etiqueta confirmada.');
    echo "OK: 67 associações únicas, 4 ambiguidades, 139 sem número; idempotência, conferência física, bloqueio de movimento pendente, escolha manual e preservação de estado local.\n";
    $reject(fn()=>Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-02-30']));
    $reject(fn()=>Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-10-02','data_fim'=>'2026-10-01']));
    $reject(fn()=>Periodo::ler(['periodo'=>'invalido']));
    foreach(['hoje','7dias','30dias','mes','ano','todos'] as $opt) { $per=Periodo::ler(['periodo'=>$opt]); $check($opt==='todos'||($per->inicio<=$per->fim),'Período rápido inválido.'); }
    $seven=Periodo::ler(['periodo'=>'7dias']);
    $check((new DateTimeImmutable($seven->inicio))->diff(new DateTimeImmutable($seven->fim))->days===6,'Últimos 7 dias não inclui sete dias.');
    DB::run('UPDATE compras_origem SET processo_data=? WHERE id=?',['2026-09-30 23:59:59',$purchase['id']]);
    $second=DB::one('SELECT * FROM vw_compras_custos WHERE id<>? AND base_pedido>0 AND conciliacao_pendente=0 AND cancelado_em IS NULL LIMIT 1',[$purchase['id']]);
    DB::run('UPDATE compras_origem SET processo_data=? WHERE id=?',['2026-10-01 00:00:00',$second['id']]);
    Projetos::apontar(['compra_id'=>$second['id'],'projeto_id'=>$p1,'percentual'=>'100','motivo'=>'Teste de limite de datas'],$actor);
    $sep=Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2026-09-01','data_fim'=>'2026-09-30']);
    $detail=Projetos::detalhe($p1,$sep);
    $check(count($detail['apontamentos'])===1 && (int)$detail['apontamentos'][0]['compra_id']===(int)$purchase['id'],'Dia final não incluído integralmente ou dia seguinte incluído.');
    $check(bccomp($detail['totalCusto'],bcmul((string)$purchase['base_pedido'],'1',2),2)===0,'Custo do período incorreto.');
    $full=Projetos::detalhe($p1);
    $check(bccomp($detail['saldoOrcamento'],$full['saldoOrcamento'],2)===0,'Filtro alterou saldo global do orçamento.');
    $list=Projetos::lista($sep); $selected=array_values(array_filter($list,fn($p)=>(int)$p['id']===$p1))[0];
    $check(bccomp((string)$selected['custo'],$detail['totalCusto'],2)===0,'Resumo e detalhe filtrados divergentes.');
    $check(bccomp((string)$selected['custo_total'],$full['totalCusto'],2)===0,'Custo total histórico perdido.');
    $empty=Projetos::detalhe($p1,Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2090-01-01','data_fim'=>'2090-12-31']));
    $check($empty['totalCusto']==='0.00' && !$empty['mensal'] && !$empty['categorias'],'Período vazio não zera indicadores e gráficos.');
    [$oneSide,$oneArgs]=Periodo::ler(['periodo'=>'personalizado','data_fim'=>'2026-09-30'])->condicao('c.processo_data');
    $check($oneArgs===['2026-10-01 00:00:00'] && str_contains($oneSide,' < ?'),'Intervalo aberto incorreto.');
    echo "OK: períodos rápidos, datas inválidas, intervalo invertido, limite inclusivo do dia final, período vazio, consistência de lista/detalhe e preservação do orçamento global.\n";
    $items=Parque::itens(); $known=array_values(array_filter($items,fn($r)=>$r['patrimonio']==='6208'))[0];
    $check($known['valor_bem']==='4233.60' && $known['base_custo']==='Imobilizado SGA','Custo do imobilizado não foi priorizado.');
    $park=Parque::painel(new Periodo());
    $check(count($items)===(int)DB::one('SELECT COUNT(*) n FROM ativos')['n'],'Junção duplicou bens no custo.');
    $sum='0.00'; foreach($items as $row) if($row['situacao']!=='baixado') $sum=bcadd($sum,$row['valor_bem']??'0.00',2);
    $check(bccomp($sum,$park['parqueStats']['parque'],2)===0,'Soma de custo do parque incorreta.');
    $snapshot=Parque::painel(Periodo::ler(['periodo'=>'personalizado','data_inicio'=>'2090-01-01','data_fim'=>'2090-12-31']));
    $check($snapshot['parqueStats']['parque']===$park['parqueStats']['parque'] && $snapshot['parquePeriodoN']===0,'Período alterou indevidamente custo total do parque.');
    $usage=Parque::itens('','fora_uso');
    $check(!array_filter($usage,fn($r)=>!in_array($r['situacao'],['em_estoque','manutencao','baixado'],true)),'Filtro fora de uso classificou pendente como inativo.');
    $aBaixa=(int)$active['id'];
    Inventario::moverAtivo(['ativo_id'=>$aBaixa,'tipo'=>'baixa','local_id'=>$a,'cc_id'=>$cc,'motivo'=>'Teste de baixa para custo','chave_operacao'=>$key()],$actor);
    $afterPark=Parque::painel(new Periodo());
    $check($afterPark['parqueStats']['baixado_n']===$park['parqueStats']['baixado_n']+1,'Baixa não separou custo e quantidade.');
    $check(count(Parque::itens('','baixado'))>=1,'Baixados não aparecem no filtro.');
    echo "OK: custo individual do imobilizado, estimativa por unidade, soma sem duplicação, total global independente do período e separação de fora de uso/pendentes/baixados.\n";

    $docRows=json_decode(file_get_contents(ROOT.'/evidencias/documento-processo-29968.json'),true,512,JSON_THROW_ON_ERROR);
    $doc=DocumentosCompra::buscar(['documento_tipo'=>'processo','documento_numero'=>'29968'],static function($sql)use($docRows,$check){$check(str_contains($sql,'p.PCOCOD=29968'),'Número ausente da consulta.');return $docRows;});
    $pDoc=Projetos::salvar(['codigo'=>'DOC-TEST','nome'=>'Documento teste'],$actor);
    $sources=array_column($docRows,'origem_item_id');
    DocumentosCompra::confirmar($doc,$sources,'100','Teste de coleta por documento',$pDoc,$actor);
    $cost=Projetos::detalhe($pDoc)['totalCusto'];
    $check($cost==='1162.00','Total dos três itens de pedido incorreto: '.$cost);
    DocumentosCompra::confirmar($doc,$sources,'100','Repetir documento sem duplicar',$pDoc,$actor);
    $check(Projetos::detalhe($pDoc)['totalCusto']===$cost,'Reconsulta duplicou custo.');
    $check((int)DB::one('SELECT COUNT(*) n FROM projetos_documentos WHERE projeto_id=?',[$pDoc])['n']===1,'Documento duplicado.');
    $rc=$doc; $rc['tipo']='requisicao';$rc['numero']=26574;$rc['requisicao_itens']=[['requisicao_item_id'=>75015,'empresa_id'=>3,'produto_nome'=>'Item de teste','quantidade'=>'5','valor_estimado_registrado'=>'40']];
    DocumentosCompra::confirmar($rc,$sources,'100','Mesma compra via requisição',$pDoc,$actor);
    $check(Projetos::detalhe($pDoc)['totalCusto']===$cost,'RC e ordem duplicaram custos.');
    $other=Projetos::salvar(['codigo'=>'DOC-OTHER','nome'=>'Outro projeto'],$actor);
    $reject(fn()=>DocumentosCompra::confirmar($doc,$sources,'10','Excesso de rateio',$other,$actor));
    $check(!Projetos::detalhe($other)['apontamentos'],'Lote parcialmente gravado.');
    // A primeira linha passa, a segunda falha: garante rollback do primeiro vínculo.
    $first=DB::one('SELECT id FROM compras_origem WHERE origem_item_id=?',[$sources[0]])['id'];
    Projetos::apontar(['compra_id'=>$first,'projeto_id'=>$pDoc,'percentual'=>'50','motivo'=>'Liberar primeira linha'],$actor);
    $reject(fn()=>DocumentosCompra::confirmar($doc,$sources,'50','Falha na segunda linha',$other,$actor));
    $check(!Projetos::detalhe($other)['apontamentos'],'Rollback do lote falhou.');
    $pending=$rc; $pending['numero']=26598;$pending['compras']=[];
    DocumentosCompra::confirmar($pending,[],'100','RC sem ordem',$other,$actor);
    $check(Projetos::detalhe($other)['totalCusto']==='0.00','Estimativa de RC entrou como custo de pedido.');
    $reject(fn()=>DocumentosCompra::buscar(['documento_tipo'=>'processo','documento_numero'=>'1 OR 1=1'],static fn()=>[]));
    $reject(fn()=>DocumentosCompra::confirmar($doc,['999999999'],'100','Item adulterado',$other,$actor));
    // Documento explícito pode incluir outro requisitante; sincronização dos quatro não o invalida.
    $outside=$docRows[0];$outside['origem_item_id']=1999999999;$outside['requisitante_id']=999999;$outside['requisitante_nome']='Requisitante do documento';
    Integracao::importar([$outside],'sga_documento',$actor,false);
    Integracao::importar($rows,'sga',$actor);
    $check((int)DB::one('SELECT origem_presente FROM compras_origem WHERE origem_item_id=1999999999')['origem_presente']===1,'Coleta geral invalidou compra pontual.');
    $reject(fn()=>Integracao::importar([$outside],'sga',$actor));
    $can=true;$project=Projetos::detalhe($pDoc)['project'];$id=$pDoc;$documentos=[];$documentoPreview=['token'=>'teste','documento'=>DocumentosCompra::preparar($rc)];$brl=static fn($v)=>'R$ '.number_format((float)$v,2,',','.');$_SESSION['csrf']='teste';
    ob_start();require ROOT.'/app/Views/documentos-projeto.php';$html=ob_get_clean();
    $check(str_contains($html,'Buscar itens e valores no SGA')&&str_contains($html,'1.162')===false&&str_contains($html,'995,00'),'Prévia não apresentou valores dos itens.');
    echo "OK: documentos SGA, coleta de três itens reais, RC/ordem sem duplicação, rateio e rollback do lote, RC sem pedido, validação de entrada e preservação da coleta pontual.\n";


    $file=ProjectXml::gerar($pDoc);$xml=$file['xml'];$document=new DOMDocument();$check($document->loadXML($xml,LIBXML_NONET),'XML inválido.');$check($document->documentElement->namespaceURI==='http://schemas.microsoft.com/project','Namespace MSPDI inválido.');
    // Microsoft publishes its 2007 schema with /2007 in the target namespace.
    // Validate structure with that namespace; the downloadable file keeps the MSPDI namespace used by Project.
    $validation=new DOMDocument();$validation->loadXML(str_replace('xmlns="http://schemas.microsoft.com/project"','xmlns="http://schemas.microsoft.com/project/2007"',$xml),LIBXML_NONET);
    libxml_use_internal_errors(true);$valid=$validation->schemaValidate(ROOT.'/evidencias/mspdi_pj12.xsd');$errors=libxml_get_errors();libxml_clear_errors();libxml_use_internal_errors(false);
    $check($valid,'Arquivo incompatível com esquema oficial Microsoft: '.implode('; ',array_map(fn($e)=>trim($e->message),$errors)));
    $xp=new DOMXPath($document);$xp->registerNamespace('p','http://schemas.microsoft.com/project');
    $sum='0.00';foreach($xp->query('//p:Task[p:Summary="0"]/p:FixedCost') as $cost)$sum=bcadd($sum,$cost->textContent,2);
    $detail=Projetos::detalhe($pDoc);$check($sum===$detail['totalCusto'],'Custos fixos de tarefas divergentes do projeto.');
    $check($xp->query('//p:Task/p:ActualCost')->length===0&&$xp->query('//p:Task/p:PercentComplete')->length===0,'Exportação inventou pagamento ou execução física.');
    $check($xp->query('//p:Task[p:OutlineLevel="2"]')->length===count($detail['apontamentos']),'Exportação perdeu compras.');
    $uids=[];foreach($xp->query('//p:Task/p:UID') as $uid)$uids[]=$uid->textContent;$check(count($uids)===count(array_unique($uids)),'UIDs de tarefas duplicados.');
    $check(str_contains($xml,'CurrencyCode>BRL')&&str_contains($xml,'Exportação integral'),'Moeda ou escopo não documentados.');
    $mock=$detail;$mock['project']['nome']='Projeto & TI <Cuiabá> "Teste"';$mock['project']['descricao']="Texto com acento: mineração\x01";$mock['apontamentos'][0]['motivo']='Testar & preservar <texto>';
    $safe=ProjectXml::montar($mock);$doc2=new DOMDocument();$check($doc2->loadXML($safe,LIBXML_NONET)&&str_contains($safe,'&amp;')&&str_contains($safe,'&lt;Cuiabá&gt;')&&!str_contains($safe,"\x01"),'Escape XML ou Unicode inválido.');
    $mock['apontamentos']=[];$mock['project']['inicio']=null;$mock['project']['fim']=null;$empty=ProjectXml::montar($mock);$doc2->loadXML($empty,LIBXML_NONET);$xp2=new DOMXPath($doc2);$xp2->registerNamespace('p','http://schemas.microsoft.com/project');$check($xp2->query('//p:Task')->length===1,'Projeto sem compras deve conter o resumo.');
    file_put_contents(ROOT.'/evidencias/exemplo-ms-project-teste.xml',$xml);
    $reject(fn()=>ProjectXml::gerar(2147483647));
    echo "OK: Microsoft Project XML, esquema oficial, Unicode, identificadores, custos sem duplicação, ausência de progresso inventado, escopo integral e projeto vazio.\n";


    $web=null;$cookie=tempnam(ROOT.'/storage','project-cookie-');
    try{
        $port=2289;$sock=@fsockopen('127.0.0.1',$port,$errno,$errstr,1);if($sock){fclose($sock);throw new RuntimeException('Porta 2289 ocupada; teste HTTP não iniciado.');}
        $web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',ROOT.'/public',ROOT.'/public/router.php'],[0=>['pipe','r'],1=>['file',ROOT.'/storage/logs/project-http-teste.log','a'],2=>['file',ROOT.'/storage/logs/project-http-teste.log','a']],$webPipes,ROOT,null,['bypass_shell'=>true]);
        if(!is_resource($web))throw new RuntimeException('Servidor de teste não iniciou.');fclose($webPipes[0]);
        $request=static function(string $path,?array $data=null)use($port,$cookie){$ch=curl_init('http://127.0.0.1:'.$port.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$data);$raw=curl_exec($ch);if($raw===false)throw new RuntimeException(curl_error($ch));$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$len=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$out=['status'=>$status,'headers'=>substr($raw,0,$len),'body'=>substr($raw,$len)];curl_close($ch);unset($ch);return $out;};
        $ready=false;for($try=0;$try<25;$try++){usleep(100000);try{if($request('/saude')['status']===200){$ready=true;break;}}catch(Throwable){}}$check($ready,'Servidor não respondeu.');
        $noAuth=$request('/projeto-project?id='.$pDoc);$check(!str_contains($noAuth['body'],'<Project')&&str_contains($noAuth['body'],'name="senha"'),'Exportação acessível sem login.');
        $login=$request('/login');preg_match('/name="csrf" value="([^"]+)"/',$login['body'],$match);$pw=bin2hex(random_bytes(18));DB::run('UPDATE usuarios SET senha_hash=? WHERE id=?',[password_hash($pw,PASSWORD_BCRYPT),$actor]);$logged=$request('/login',['csrf'=>$match[1],'email'=>'teste@local','senha'=>$pw]);$check($logged['status']===303,'Login falhou.');
        $export=$request('/projeto-project?id='.$pDoc.'&periodo=personalizado&data_inicio=2090-01-01&data_fim=2090-12-31');
        $check($export['status']===200&&str_contains($export['headers'],'application/xml')&&str_contains($export['headers'],'attachment; filename="projeto-'),'Cabeçalhos do download inválidos.');
        $docHttp=new DOMDocument();$check($docHttp->loadXML($export['body'],LIBXML_NONET),'Download não é XML válido.');$xpHttp=new DOMXPath($docHttp);$xpHttp->registerNamespace('p','http://schemas.microsoft.com/project');$check($xpHttp->query('//p:Task[p:OutlineLevel="2"]')->length===count($detail['apontamentos']),'Filtro da página truncou o projeto exportado.');
        $panel=$request('/projeto?id='.$pDoc);$cards=$request('/projetos');$check(str_contains($panel['body'],'/projeto-project?id='.$pDoc)&&str_contains($cards['body'],'/projeto-project?id='.$pDoc),'Botões de exportação ausentes.');
        $invalid=$request('/projeto-project?id=2147483647');$check(!str_contains($invalid['body'],'<Project')&&str_contains($invalid['body'],'Projeto não encontrado'),'Projeto inexistente gerou arquivo falso.');
        echo "OK: download HTTP autenticado, cabeçalhos XML, exportação integral, botões em lista/painel e bloqueio de projeto inexistente.\n";
    }finally{if(is_resource($web)){proc_terminate($web);proc_close($web);}@unlink($cookie);}

    echo "VERIFICACAO CONCLUIDA; dados da aplicação não foram modificados.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL); $exitCode=1;
} finally {
    if($created) { $admin->exec("DROP DATABASE `$nome`"); $admin->exec("REVOKE SELECT,INSERT,UPDATE,DELETE ON `$nome`.* FROM 'salinas_ti'@'127.0.0.1'"); }
}
exit($exitCode);
