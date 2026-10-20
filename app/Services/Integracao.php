<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Integracao {
    public static function config(bool $secrets=false): array {
        $file=ROOT.'/storage/sga.json';
        $c=is_file($file) ? json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR) : [];
        if(isset($c['senha_cifrada']) && $secrets) {
            $raw=base64_decode($c['senha_cifrada'],true);
            $key=hex2bin((string)getenv('APP_KEY'));
            $pass=$raw ? sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key) : false;
            if($pass===false) throw new RuntimeException('Não foi possível ler a senha armazenada.');
            $c['senha']=$pass;
        }
        unset($c['senha_cifrada']);
        $c['usar_ssl'] ??= true;
        return $c;
    }
    public static function salvar(array $p): void {
        $old=self::config(true);
        $host=trim((string)($p['servidor']??'')); $base=trim((string)($p['banco']??'')); $login=trim((string)($p['usuario']??''));
        if(!$host || !$base || !$login || strlen($host)>200 || strlen($base)>128 || strlen($login)>128) throw new RuntimeException('Preencha servidor, banco e usuário.');
        $senha=(string)($p['senha']??''); if($senha==='') $senha=$old['senha']??'';
        if($senha==='') throw new RuntimeException('Informe a senha da conexão.');
        $nonce=random_bytes(24);
        $c=['servidor'=>$host,'banco'=>$base,'usuario'=>$login,'usar_ssl'=>array_key_exists('usar_ssl',$p)?filter_var($p['usar_ssl'],FILTER_VALIDATE_BOOLEAN):($old['usar_ssl']??true),'confiar_certificado'=>isset($p['confiar_certificado']), 'senha_cifrada'=>base64_encode($nonce.sodium_crypto_secretbox($senha,$nonce,hex2bin((string)getenv('APP_KEY'))))];
        $file=ROOT.'/storage/sga.json';
        if(file_put_contents($file.'.tmp',json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX)===false || !rename($file.'.tmp',$file)) throw new RuntimeException('Não foi possível salvar a configuração.');
    }
    public static function consultar(string $tipo='compras'): array {
        $arquivo=match($tipo){'compras'=>'minerion_coleta.sql','patrimonios'=>'minerion_patrimonios.sql',default=>throw new RuntimeException('Consulta inválida.')};
        return self::executarSql(Requisitantes::aplicarSql((string)file_get_contents(ROOT.'/database/queries/'.$arquivo)));
    }
    public static function executarSql(string $sql): array {
        $c=self::config(true);
        if(empty($c['servidor'])) throw new RuntimeException('Configure a conexão ao Minerion primeiro.');
        if(in_array('sqlsrv',\PDO::getAvailableDrivers(),true)) {
            $dsn='sqlsrv:Server='.$c['servidor'].';Database='.$c['banco'].';Encrypt='.($c['usar_ssl']?'yes':'no').';TrustServerCertificate='.($c['confiar_certificado']?'yes':'no').';LoginTimeout=12';
            $pdo=new \PDO($dsn,$c['usuario'],$c['senha'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            return $pdo->query($sql)->fetchAll();
        }
        // Ponte local Windows: SqlClient, sem instalar DLL no PHP. Segredo segue por stdin.
        if(PHP_OS_FAMILY!=='Windows' || !function_exists('proc_open')) throw new RuntimeException('Habilite pdo_sqlsrv neste PHP.');
        $ps=getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe';
        $proc=proc_open([$ps,'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',ROOT.'/bin/sga-consultar.ps1'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',ROOT.'/storage/bridge-error.log','a']],$pipes,ROOT,['SystemRoot'=>getenv('SystemRoot')],['bypass_shell'=>true]);
        if(!is_resource($proc)) throw new RuntimeException('Não foi possível iniciar a conexão SQL Server.');
        fwrite($pipes[0],json_encode(['config'=>$c,'sql'=>$sql],JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $code=proc_close($proc);
        if($code!==0) throw new RuntimeException('Falha ao consultar o SGA. Confira VPN, servidor, banco, credenciais e certificado.');
        $data=json_decode(trim((string)$out,"\xEF\xBB\xBF \r\n"),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data)) throw new RuntimeException('Resposta inválida do SGA.');
        return $data;
    }
    public static function sincronizar(?int $actor): array {
        // Ler ambas as origens antes de alterar a carga local.
        CentrosCusto::atualizarExpansao(static fn(string $sql)=>self::executarSql($sql)); // novos CCs filhos passam a valer
        $compras=self::consultar(); $patrimonios=self::consultar('patrimonios');
        // O resultado normalizado (já filtrado pelo escopo atual) decide; a lista bruta pode ter itens fora do escopo.
        // Escopo sem bens no imobilizado (ex.: só material de consumo) é legítimo: importa as compras e preserva o levantamento patrimonial anterior, sem marcar ausências.
        $semPatrimonios=!PatrimoniosSga::normalizar($patrimonios);
        $n=self::importar($compras,'sga',$actor);
        if($semPatrimonios) return ['compras'=>$n,'patrimonios'=>['associado'=>0,'ambiguo'=>0,'sem_numero'=>0,'conflito'=>0],'aviso'=>' Nenhum bem do imobilizado retornou para o escopo atual; o levantamento patrimonial anterior foi preservado.'];
        try { $stats=PatrimoniosSga::importar($patrimonios,'sga',$actor); }
        catch(\Throwable $e) { throw new RuntimeException('Compras atualizadas; associação patrimonial não concluída. O levantamento anterior foi preservado.',0,$e); }
        return ['compras'=>$n,'patrimonios'=>$stats];
    }
    public static function importar(array $rows,string $fonte,?int $actor,bool $escopoRestrito=true): int {
        Minerion::resumir($rows,$escopoRestrito);
        if(!$rows) throw new RuntimeException('A consulta retornou vazia. A coleta anterior foi preservada para conferência.');
        $lock=DB::one("SELECT GET_LOCK('salinas_ti_sync',0) AS ok");
        if((int)$lock['ok']!==1) throw new RuntimeException('Já existe uma sincronização em andamento.');
        $id=null;
        try {
            DB::run("INSERT INTO sincronizacoes(situacao,fonte) VALUES('executando',?)",[$fonte]); $id=(int)DB::pdo()->lastInsertId();
            DB::transaction(function() use($rows,$id,$actor,$fonte,$escopoRestrito) {
                $permitidos=Requisitantes::lista(); $centrosEscopo=CentrosCusto::lista(); // lidos uma vez: mesmo escopo para toda a importação
                foreach($rows as $r) {
                    DB::run('INSERT INTO requisitantes_sga(codigo,nome,coletar) VALUES(?,?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome),coletar=VALUES(coletar)',[(int)$r['requisitante_id'],$r['requisitante_nome']?:'Não informado',in_array((int)$r['requisitante_id'],$permitidos,true)?1:0]);
                    $old=DB::one('SELECT * FROM compras_origem WHERE origem_item_id=? FOR UPDATE',[$r['origem_item_id']]);
                    $pid=(int)$r['produto_id'];
                    if($pid>0) {
                        $nome=$r['produto_nome'] ?: 'Produto SGA '.$pid.' — descrição não identificada';
                        DB::run('INSERT INTO produtos(sga_codigo,nome) VALUES(?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome)',[$pid,$nome]);
                        $product=DB::one('SELECT id FROM produtos WHERE sga_codigo=?',[$pid]);
                    } elseif($old) {
                        $product=['id'=>$old['produto_id']];
                    } else {
                        DB::run('INSERT INTO produtos(nome) VALUES(?)',['Linha SGA '.$r['origem_item_id'].' — produto não identificado; conferir na origem']);
                        $product=['id'=>(int)DB::pdo()->lastInsertId()];
                    }
                    $cc=null;
                    if((int)$r['centro_custo_compra_id']>0 && $r['centro_custo_compra_nome']) {
                        DB::run('INSERT INTO centros_custo(sga_codigo,nome) VALUES(?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome)',[$r['centro_custo_compra_id'],$r['centro_custo_compra_nome']]);
                        $cc=DB::one('SELECT id FROM centros_custo WHERE sga_codigo=?',[$r['centro_custo_compra_id']])['id'];
                    }
                    $date=empty($r['processo_data'])?null:substr(str_replace('T',' ',$r['processo_data']),0,19);
                    $cancel=empty($r['cancelado_em'])?null:substr(str_replace('T',' ',$r['cancelado_em']),0,19);
                    $pending=$old ? (bool)$old['conciliacao_pendente'] : false;
                    if($old && (bccomp((string)$old['quantidade_vinculada_nf'],(string)$r['quantidade_vinculada_nf'],4)!==0 || ($old['cancelado_em']??null)!==$cancel) && bccomp((string)$old['incorporada_quantidade'],'0',4)>0) $pending=true;
                    $v=[$r['processo_id'],$r['empresa_id'],$r['filial_id'],$r['requisitante_id'],$product['id'],$cc,$date,$cancel,$r['quantidade_solicitada'],$r['quantidade_faturada_cadastro'],$r['quantidade_cancelada'],$r['quantidade_vinculada_nf'],$r['valor_unitario_pedido'],$r['vinculos_nf_positivos'],$id,json_encode($r,JSON_UNESCAPED_UNICODE),$pending?1:0];
                    if($old) DB::run('UPDATE compras_origem SET processo_id=?,empresa_id=?,filial_id=?,requisitante_codigo=?,produto_id=?,centro_custo_compra_id=?,processo_data=?,cancelado_em=?,quantidade_solicitada=?,quantidade_faturada=?,quantidade_cancelada=?,quantidade_vinculada_nf=?,valor_unitario_pedido=?,vinculos_nf_positivos=?,ultima_sincronizacao_id=?,payload_json=?,conciliacao_pendente=?,origem_presente=1 WHERE id=?',[...$v,$old['id']]);
                    else DB::run('INSERT INTO compras_origem(processo_id,empresa_id,filial_id,requisitante_codigo,produto_id,centro_custo_compra_id,processo_data,cancelado_em,quantidade_solicitada,quantidade_faturada,quantidade_cancelada,quantidade_vinculada_nf,valor_unitario_pedido,vinculos_nf_positivos,ultima_sincronizacao_id,payload_json,conciliacao_pendente,origem_item_id) VALUES('.implode(',',array_fill(0,18,'?')).')',[...$v,$r['origem_item_id']]);
                    if($fonte==='sga_documento' && !$old) DB::run('UPDATE compras_origem SET entrada_documento=1 WHERE origem_item_id=?',[$r['origem_item_id']]);
                }
                if($fonte==='sga') {
                    $scope=$permitidos;
                    $ccClause=$centrosEscopo?' OR centro_custo_compra_id IN (SELECT id FROM centros_custo WHERE sga_codigo IN ('.implode(',',array_fill(0,count($centrosEscopo),'?')).'))':'';
                    DB::run('UPDATE compras_origem SET origem_presente=0,conciliacao_pendente=1 WHERE ultima_sincronizacao_id<>? AND entrada_documento=0 AND (requisitante_codigo IN ('.implode(',',array_fill(0,count($scope),'?')).')'.$ccClause.')',[$id,...$scope,...$centrosEscopo]);
                }
                DB::run("UPDATE sincronizacoes SET situacao='concluida',fim=NOW(),linhas_lidas=? WHERE id=?",[count($rows),$id]);
                DB::audit('sincronizacoes',$id,'importar',null,['fonte'=>$fonte,'linhas'=>count($rows)],$actor);
            });
            return count($rows);
        } catch(\Throwable $e) {
            if($id) DB::run("UPDATE sincronizacoes SET situacao='falhou',fim=NOW(),mensagem='Importação interrompida; dados anteriores preservados.' WHERE id=?",[$id]);
            throw $e;
        } finally { DB::run("SELECT RELEASE_LOCK('salinas_ti_sync')"); }
    }
}
