<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database as DB;
use App\Services\Integracao;
use App\Services\Inventario;
use App\Services\Projetos;
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
    $check(str_contains($html,'projeto-exportar?id='),'Exportação não aparece no painel.');
    echo "OK: painel com compras, orçamento e gráficos renderizado sem avisos PHP.\n";
    echo "VERIFICACAO CONCLUIDA; dados da aplicação não foram modificados.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL); $exitCode=1;
} finally {
    if($created) { $admin->exec("DROP DATABASE `$nome`"); $admin->exec("REVOKE SELECT,INSERT,UPDATE,DELETE ON `$nome`.* FROM 'salinas_ti'@'127.0.0.1'"); }
}
exit($exitCode);
