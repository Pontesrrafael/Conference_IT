<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Database as DB;
use App\Services\Integracao;
use App\Services\Requisitantes;
use App\Services\CentrosCusto;
use App\Services\Relatorios;
use App\Services\RelatorioPdf;
use App\Services\Inventario;
use App\Services\Projetos;
use App\Services\ProjectXml;
use App\Services\DocumentosCompra;
use App\Services\Periodo;
use App\Services\Parque;
use App\Services\Mapas;
use App\Services\PatrimoniosSga;
use RuntimeException;
final class Web {
    public static function run(): void {
        session_name('est_sg'); session_save_path(ROOT.'/storage/sessions');
        session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','path'=>'/']); session_start();
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
        if(isset($_SESSION['last']) && time()-$_SESSION['last']>3600) { $_SESSION=[]; session_regenerate_id(true); }
        $_SESSION['last']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(24));
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
        if($path==='/saude') { header('Content-Type: application/json'); try { DB::one('SELECT 1'); echo '{"status":"ok"}'; } catch(\Throwable) { http_response_code(503); echo '{"status":"indisponivel"}'; } return; }
        try {
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if((int)($_SERVER['CONTENT_LENGTH']??0)>0 && empty($_POST) && empty($_FILES)) throw new RuntimeException('O envio excedeu o limite do servidor. Use um TIFF de até 256 MB.');
                if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão do formulário expirou. Recarregue a página.');
                if($path==='/login') self::login();
                if(empty($_SESSION['uid'])) url('/login');
                if($path==='/logout') { $_SESSION=[]; session_regenerate_id(true); url('/login'); }
            }
            if(empty($_SESSION['uid'])) { self::view('login',['title'=>'Acesso ao inventário']); return; }
            $u=DB::one('SELECT id,nome,email,perfil FROM usuarios WHERE id=? AND ativo=1',[$_SESSION['uid']]);
            if(!$u) { $_SESSION=[]; url('/login'); }
            $can=in_array($u['perfil'],['admin','operador_ti'],true);
            if($_SERVER['REQUEST_METHOD']==='POST') {
                if(!$can) throw new RuntimeException('Seu perfil permite apenas consulta.');
                self::action($path,$u); return;
            }
            $periodo=Periodo::ler(in_array($path,['/','/projetos','/projeto','/projeto-exportar','/relatorios','/relatorio-exportar','/relatorio-pdf'],true)?$_GET:[]);
            $escopoTodos=($_GET['escopo']??'')==='todos'; // padrão: somente o escopo atual (requisitantes/CC configurados)
            $params=['escopoTodos'=>$escopoTodos,'periodo'=>$periodo,'u'=>$u,'can'=>$can,'path'=>$path,'title'=>match($path){'/'=>'Parque e custos','/mapas'=>'Mapas e áreas','/mapa'=>'Mapa e apontamentos','/projetos'=>'Projetos e custos','/projeto'=>'Painel do projeto','/compras'=>'Compras do Minerion','/ativos'=>'Patrimônios','/estoque'=>'Estoque de materiais','/cadastros'=>'Cadastros','/integracao'=>'Integração SGA','/historico'=>'Movimentações e auditoria','/conta'=>'Minha conta','/relatorios'=>'Relatórios de gastos',default=>'Visão geral'}];
            if($path==='/mapa-imagem') Mapas::arquivo((int)($_GET['id']??0));
            if($path==='/mapa-original') Mapas::arquivo((int)($_GET['id']??0),true);
            if($path==='/mapa-exportar') Mapas::exportar((int)($_GET['id']??0));
            if($path==='/exportar') { self::export(); return; }
            if($path==='/projeto-project'){ $file=ProjectXml::gerar((int)($_GET['id']??0)); header('Content-Type: application/xml; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$file['nome'].'"');echo $file['xml'];return; }
            if($path==='/projeto-exportar') { self::exportProjeto((int)($_GET['id']??0)); return; }
            if($path==='/relatorio-exportar') { self::exportRelatorio($periodo); return; }
            if($path==='/relatorio-pdf') {
                $sel=$_GET['cc']??[]; $sel=is_array($sel)?array_map('intval',array_filter($sel,'is_string')):[];
                $pdf=RelatorioPdf::gerar(Relatorios::gerar(Relatorios::selecionar($sel),$periodo,RelatorioPdf::LIMITE_LINHAS),$periodo,(string)$u['nome']);
                header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="relatorio-gastos-cc-'.date('Ymd-Hi').'.pdf"'); header('Content-Length: '.strlen($pdf));
                echo $pdf; return;
            }
            if($path==='/integracao' && $u['perfil']!=='admin') throw new RuntimeException('Somente administradores podem configurar a integração.');
            $params['locais']=DB::all('SELECT id,nome FROM locais WHERE ativo=1 ORDER BY nome');
            $params['ccs']=DB::all("SELECT id,CONCAT(COALESCE(sga_codigo,'Local'),' · ',nome) nome FROM centros_custo WHERE ativo=1 ORDER BY nome");
            $params['responsaveis']=DB::all('SELECT id,nome FROM responsaveis WHERE ativo=1 ORDER BY nome');
            switch($path) {
                case '/mapas':
                    $params['mapasLista']=DB::all('SELECT m.*,(SELECT COUNT(*) FROM mapas_areas a WHERE a.mapa_id=m.id) areas FROM mapas m ORDER BY m.id DESC'); break;
                case '/mapa':
                    $params+=Mapas::detalhe((int)($_GET['id']??0));
                    $params['buscaMapa']=mb_substr(trim((string)($_GET['q']??'')),0,100);
                    $params['projetosMapa']=DB::all("SELECT id,CONCAT(codigo,' · ',nome) nome FROM projetos WHERE status<>'arquivado' ORDER BY nome");
                    $search=$params['buscaMapa']; $params['ativosMapa']=array_slice(Parque::itens($search),0,100);
                    $params['comprasMapa']=$search===''?[]:DB::all('SELECT c.id,c.origem_item_id,c.processo_id,p.nome FROM compras_origem c JOIN produtos p ON p.id=c.produto_id WHERE p.nome LIKE ? OR CAST(c.origem_item_id AS CHAR)=? OR CAST(c.processo_id AS CHAR)=? ORDER BY c.id DESC LIMIT 100',["%$search%",$search,$search]); break;
                case '/projetos':
                    $params['projetos']=Projetos::lista($periodo);
                    $params['editar']=empty($_GET['editar'])?null:DB::one('SELECT * FROM projetos WHERE id=?',[(int)$_GET['editar']]);
                    break;
                case '/projeto':
                    $id=(int)($_GET['id']??0); $params+=Projetos::detalhe($id,$periodo);
                    $preview=$_SESSION['documentos_projetos'][$id]??null;
                    $params['documentoPreview']=$preview&&$preview['expires']>time()?$preview:null;
                    $params['documentos']=DB::all('SELECT * FROM projetos_documentos WHERE projeto_id=? ORDER BY atualizado_em DESC',[$id]);
                    $search=mb_substr(trim((string)($_GET['q']??'')),0,120);
                    $where=' WHERE c.vinculos_nf_positivos>0'; [$candidateDate,$bindings]=$periodo->condicao('c.processo_data'); $where.=$candidateDate;
                    if($search!=='') { $where.=' AND (p.nome LIKE ? OR CAST(c.origem_item_id AS CHAR)=?)'; $bindings=[...$bindings,"%$search%",$search]; }
                    $params['busca']=$search;
                    $params['candidatas']=DB::all('SELECT c.*,p.nome produto,COALESCE((SELECT SUM(a.percentual) FROM compras_projetos a WHERE a.compra_id=c.id),0) percentual_total FROM vw_compras_custos c JOIN produtos p ON p.id=c.produto_id'.$where.' ORDER BY c.processo_data DESC,c.id DESC LIMIT 30',$bindings);
                    break;
                case '/compras':
                    $q=mb_substr(trim((string)($_GET['q']??'')),0,120); $filter=(string)($_GET['filtro']??'faturadas');
                    $where=' WHERE 1=1'; $p=[];
                    if($q!=='') { $where.=' AND (p.nome LIKE ? OR CAST(c.origem_item_id AS CHAR)=? OR r.nome LIKE ?)'; $p=["%$q%",$q,"%$q%"]; }
                    if($filter==='faturadas') $where.=' AND c.vinculos_nf_positivos>0';
                    if($filter==='pendentes') $where.=" AND c.triagem='pendente' AND c.vinculos_nf_positivos>0";
                    if($filter==='incluidas') $where.=" AND c.triagem='incluir'";
                    if(!$escopoTodos) { [$scopeSql,$scopeArgs]=CentrosCusto::condicaoLocal('c.requisitante_codigo','c.centro_custo_compra_id'); $where.=' AND '.$scopeSql; $p=[...$p,...$scopeArgs]; }
                    // Origem no escopo: um requisitante ou um centro de custo configurado (mesma definição dos totais do Painel).
                    $origem=(string)($_GET['origem']??''); $origens=CentrosCusto::origens(); $reqNomes=[];
                    foreach($origens as $o) if($o['tipo']==='Requisitante') $reqNomes[$o['codigo']]=$o['rotulo'];
                    $sel=array_values(array_filter($origens,static fn($o)=>$o['chave']===$origem));
                    if($sel) { $where.=' AND '.$sel[0]['sql']; $p=[...$p,...$sel[0]['args']]; } else $origem='';
                    $params+=['origem'=>$origem,'origemReq'=>$reqNomes,'origemCc'=>CentrosCusto::config()['centros'],'ccEscopo'=>CentrosCusto::lista()];
                    $base=' FROM compras_origem c JOIN produtos p ON p.id=c.produto_id JOIN requisitantes_sga r ON r.codigo=c.requisitante_codigo LEFT JOIN centros_custo cc ON cc.id=c.centro_custo_compra_id';
                    $total=(int)DB::one('SELECT COUNT(*) n'.$base.$where,$p)['n']; $page=max(1,min((int)($_GET['pagina']??1),max(1,(int)ceil($total/40))));
                    $params+=['q'=>$q,'filter'=>$filter,'total'=>$total,'page'=>$page,'rows'=>DB::all('SELECT c.*,p.nome produto,p.controle,p.unidade,p.categoria,r.nome requisitante,cc.nome cc_nome,cc.sga_codigo cc_sga'.$base.$where.' ORDER BY c.processo_data DESC,c.id DESC LIMIT 40 OFFSET '.(($page-1)*40),$p)];
                    $params['projetosSelect']=DB::all("SELECT id,CONCAT(codigo,' · ',nome) nome FROM projetos WHERE status<>'arquivado' ORDER BY nome");
                    $params['rateiosPorCompra']=[];
                    $ids=array_column($params['rows'],'id');
                    if($ids) foreach(DB::all('SELECT a.*,p.nome projeto_nome,p.codigo FROM compras_projetos a JOIN projetos p ON p.id=a.projeto_id WHERE a.compra_id IN ('.implode(',',array_fill(0,count($ids),'?')).')', $ids) as $rateio) $params['rateiosPorCompra'][$rateio['compra_id']][]=$rateio;
                    break;
                case '/ativos':
                    $q=mb_substr(trim((string)($_GET['q']??'')),0,120); $estado=(string)($_GET['situacao']??'');
                    $params['qAtivos']=$q; $params['estadoAtivos']=$estado;
                    $params['rows']=Parque::itens($q,$estado,!$escopoTodos);
                    $params['sgaPorAtivo']=[];
                    foreach(DB::all('SELECT * FROM patrimonios_sga ORDER BY atualizado_em DESC,id DESC') as $sga) if($sga['ativo_id']&&!isset($params['sgaPorAtivo'][$sga['ativo_id']])) $params['sgaPorAtivo'][$sga['ativo_id']]=$sga;
                    $params['pendenciasSga']=DB::all("SELECT * FROM patrimonios_sga WHERE situacao IN ('ambiguo','conflito') ORDER BY id DESC");
                    if(!$escopoTodos) $params['pendenciasSga']=array_values(array_filter($params['pendenciasSga'],static fn(array $s):bool=>CentrosCusto::patrimonioNoEscopo(json_decode((string)$s['payload_json'],true)?:[])));
                    $params['semNumeroSga']=DB::all("SELECT * FROM patrimonios_sga WHERE situacao='sem_numero' ORDER BY id DESC");
                    if(!$escopoTodos) $params['semNumeroSga']=array_values(array_filter($params['semNumeroSga'],static fn(array $s):bool=>CentrosCusto::patrimonioNoEscopo(json_decode((string)$s['payload_json'],true)?:[])));
                    $params['resumoSga']=DB::one("SELECT COUNT(*) registros,COALESCE(SUM(situacao='sem_numero'),0) sem_numero,COALESCE(SUM(situacao='associado'),0) associados FROM patrimonios_sga");
                    break;
                case '/estoque':
                    $params['rows']=DB::all('SELECT s.*,l.referencia,p.nome produto,p.unidade,lo.nome local_nome,c.nome cc_nome FROM saldos_estoque s JOIN lotes_estoque l ON l.id=s.lote_id JOIN produtos p ON p.id=l.produto_id JOIN locais lo ON lo.id=s.local_id JOIN centros_custo c ON c.id=s.centro_custo_id WHERE s.quantidade>0 ORDER BY p.nome,lo.nome'); break;
                case '/cadastros': break;
                case '/integracao': $params['connection']=Integracao::config(); $params['runs']=DB::all('SELECT * FROM sincronizacoes ORDER BY id DESC LIMIT 20'); break;
                case '/historico':
                    $params['rows']=DB::all('SELECT m.*,p.nome produto,a.patrimonio,u.nome autor,lo.nome origem,ld.nome destino,co.nome cc_origem,cd.nome cc_destino,ro.nome resp_origem,rd.nome resp_destino FROM movimentacoes m LEFT JOIN ativos a ON a.id=m.ativo_id LEFT JOIN lotes_estoque l ON l.id=m.lote_id JOIN produtos p ON p.id=COALESCE(a.produto_id,l.produto_id) JOIN usuarios u ON u.id=m.autor_id LEFT JOIN locais lo ON lo.id=m.local_origem_id LEFT JOIN locais ld ON ld.id=m.local_destino_id LEFT JOIN centros_custo co ON co.id=m.cc_origem_id LEFT JOIN centros_custo cd ON cd.id=m.cc_destino_id LEFT JOIN responsaveis ro ON ro.id=m.responsavel_origem_id LEFT JOIN responsaveis rd ON rd.id=m.responsavel_destino_id ORDER BY m.id DESC LIMIT 200');
                    $params['audit']=DB::all('SELECT a.*,u.nome autor FROM auditoria a LEFT JOIN usuarios u ON u.id=a.autor_id ORDER BY a.id DESC LIMIT 100'); break;
                case '/conta': break;
                case '/relatorios':
                    $sel=$_GET['cc']??[]; if(!is_array($sel)) $sel=[];
                    $params['ccDisponiveis']=Relatorios::selecionar([]);
                    $params['relatorioOrigens']=Relatorios::selecionar(array_map('intval',array_filter($sel,'is_string')));
                    $params['ccSelecionados']=array_column($params['relatorioOrigens'],'codigo');
                    $params['relatorio']=Relatorios::gerar($params['relatorioOrigens'],$periodo);
                    break;
                case '/':
                    $params['origensPainel']=CentrosCusto::origens(); $params['origemPainel']=(string)($_GET['origem']??'');
                    if(!CentrosCusto::origem($params['origemPainel'])) $params['origemPainel']='';
                    $params+=Parque::painel($periodo,!$escopoTodos,$params['origemPainel']);
                    $params['last']=DB::one('SELECT * FROM sincronizacoes ORDER BY id DESC LIMIT 1'); break;
                default: http_response_code(404); throw new RuntimeException('Página não encontrada.');
            }
            self::view('app',$params);
        } catch(\Throwable $e) {
            $message=$e instanceof RuntimeException && !$e instanceof \PDOException ? $e->getMessage() : 'Não foi possível concluir. Confira a configuração ou possíveis dados duplicados.';
            error_log('Salinas TI: '.get_class($e).' codigo '.$e->getCode());
            if($_SERVER['REQUEST_METHOD']==='POST') { $_SESSION['error']=$message; url(empty($_SESSION['uid'])?'/login':self::back($path)); }
            self::view('error',['title'=>'Verificação necessária','message'=>$message]);
        }
    }
    private static function back(string $p): string { return match($p){'/mapa-upload'=>'/mapas','/mapa-area','/mapa-vincular','/mapa-desvincular'=>'/mapa?id='.(int)($_POST['mapa_id']??0),'/projeto-salvar'=>'/projetos','/projeto-documento-buscar','/projeto-documento-confirmar'=>'/projeto?id='.(int)($_POST['projeto_id']??0).'#documento-coleta','/projeto-apontar','/projeto-desvincular'=>(($_POST['voltar_compras']??'')==='1'?'/compras':'/projeto?id='.(int)($_POST['projeto_id']??0)),'/triagem','/incorporar'=>'/compras','/mover-ativo','/patrimonio-conferir','/patrimonio-associar'=>'/ativos','/mover-material'=>'/estoque','/cadastro'=>'/cadastros','/sga-salvar','/sga-sincronizar','/sga-requisitante','/sga-requisitante-remover','/sga-centro-custo','/sga-centro-custo-remover'=>'/integracao','/relatorio-gerar'=>'/relatorios','/senha'=>'/conta',default=>'/'}; }
    private static function login(): never {
        $email=strtolower(trim((string)($_POST['email']??''))); $key=hash('sha256',$email.'|'.($_SERVER['REMOTE_ADDR']??''));
        $t=DB::one('SELECT * FROM login_tentativas WHERE chave=?',[$key]);
        if($t && $t['bloqueado_ate'] && strtotime($t['bloqueado_ate'])>time()) throw new RuntimeException('Aguarde 15 minutos para tentar novamente.');
        $u=DB::one('SELECT * FROM usuarios WHERE email=? AND ativo=1',[$email]);
        if(!$u || !password_verify((string)($_POST['senha']??''),$u['senha_hash'])) {
            DB::run("INSERT INTO login_tentativas(chave,tentativas) VALUES(?,1) ON DUPLICATE KEY UPDATE tentativas=IF(atualizado_em<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,tentativas+1),bloqueado_ate=IF(tentativas>=5,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL),atualizado_em=NOW()",[$key]);
            throw new RuntimeException('E-mail ou senha inválidos.');
        }
        DB::run('DELETE FROM login_tentativas WHERE chave=?',[$key]);
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(24)); url('/');
    }
    private static function action(string $path,array $u): never {
        $actor=(int)$u['id'];
        switch($path) {
            case '/mapa-upload':
                $id=Mapas::importar($_POST,$_FILES['imagem']??[],$actor);$_SESSION['success']='GeoTIFF importado. Desenhe as áreas para começar os apontamentos.';url('/mapa?id='.$id);
            case '/mapa-area':
                $area=Mapas::salvarArea($_POST,$actor);$_SESSION['success']='Área salva com coordenadas geográficas.';url('/mapa?id='.(int)$_POST['mapa_id'].'&area='.$area);
            case '/mapa-vincular': Mapas::vincular($_POST,$actor);break;
            case '/mapa-desvincular': Mapas::desvincular($_POST,$actor);break;
            case '/projeto-salvar':
                $doc=trim((string)($_POST['documento_numero']??''))!==''?DocumentosCompra::buscar($_POST):null;
                $projectId=Projetos::salvar($_POST,$actor);
                if($doc) self::guardarDocumento($projectId,$doc);
                $_SESSION['success']='Projeto salvo.'.($doc?' Confira os itens coletados abaixo.':''); url('/projeto?id='.$projectId.($doc?'#documento-coleta':''));
            case '/projeto-documento-buscar':
                $projectId=(int)($_POST['projeto_id']??0); Projetos::detalhe($projectId);
                self::guardarDocumento($projectId,DocumentosCompra::buscar($_POST)); url('/projeto?id='.$projectId.'#documento-coleta');
            case '/projeto-documento-confirmar':
                $projectId=(int)($_POST['projeto_id']??0); $preview=$_SESSION['documentos_projetos'][$projectId]??null;
                if(!$preview||$preview['expires']<time()||!hash_equals($preview['token'],(string)($_POST['documento_token']??''))) throw new RuntimeException('Consulta expirada. Busque o documento novamente.');
                $selected=$_POST['itens']??[]; if(!is_array($selected)) throw new RuntimeException('Seleção de itens inválida.');
                $result=DocumentosCompra::confirmar($preview['documento'],$selected,(string)($_POST['percentual']??''),(string)($_POST['motivo']??''),$projectId,$actor);
                unset($_SESSION['documentos_projetos'][$projectId]); $_SESSION['success']='Documento vinculado. '.$result['compras'].' linhas de compra atribuídas ao projeto.'; url('/projeto?id='.$projectId);
            case '/projeto-apontar': Projetos::apontar($_POST,$actor); break;
            case '/projeto-desvincular': Projetos::desvincular($_POST,$actor); break;
            case '/triagem': Inventario::triagem($_POST,$actor); break;
            case '/incorporar': Inventario::incorporar($_POST,$actor); break;
            case '/mover-ativo': Inventario::moverAtivo($_POST,$actor); break;
            case '/patrimonio-conferir': PatrimoniosSga::conferir($_POST,$actor); break;
            case '/patrimonio-associar': PatrimoniosSga::escolher($_POST,$actor); break;
            case '/mover-material': Inventario::moverMaterial($_POST,$actor); break;
            case '/cadastro':
                $kind=(string)($_POST['cadastro']??''); $name=Inventario::text($_POST,'nome');
                DB::transaction(function() use($kind,$name,$actor) {
                    switch($kind) {
                        case 'local': DB::run("INSERT INTO locais(nome,tipo) VALUES(?,'sala')",[$name]); $table='locais'; break;
                        case 'cc': DB::run('INSERT INTO centros_custo(nome) VALUES(?)',[$name]); $table='centros_custo'; break;
                        case 'responsavel': DB::run('INSERT INTO responsaveis(nome) VALUES(?)',[$name]); $table='responsaveis'; break;
                        default: throw new RuntimeException('Cadastro inválido.');
                    }
                    DB::audit($table,DB::pdo()->lastInsertId(),'criar',null,['nome'=>$name],$actor);
                }); break;
            case '/sga-requisitante':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                $before=Requisitantes::lista();
                $req=Requisitantes::adicionar($_POST['requisitante_id']??'');
                DB::audit('integracao','requisitantes','adicionar',['ids'=>$before],$req,$actor);
                $_SESSION['success']='Requisitante '.$req['codigo'].' — '.$req['nome'].($req['adicionado']?' adicionado. Execute a sincronização para carregar suas compras.':' já está incluído nas consultas.');
                url('/integracao');
            case '/sga-requisitante-remover':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                $before=Requisitantes::lista();
                $req=Requisitantes::remover($_POST['requisitante_id']??'');
                DB::audit('integracao','requisitantes','remover',['ids'=>$before],$req,$actor);
                $_SESSION['success']='Requisitante '.$req['codigo'].($req['removido']?' removido das próximas consultas. O histórico importado foi preservado.':' já está fora da consulta.');
                url('/integracao');
            case '/sga-centro-custo':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                $before=CentrosCusto::config()['centros'];
                $cc=CentrosCusto::adicionar($_POST['centro_custo_id']??'',!empty($_POST['incluir_filhos']));
                DB::audit('integracao','centros_custo','adicionar',['centros'=>$before],$cc,$actor);
                $_SESSION['success']='Centro de custo '.$cc['codigo'].' — '.$cc['nome'].($cc['adicionado']?' incluído. ':' já estava na consulta com essa opção. ').$cc['efetivos'].' CC(s) efetivos; '.$cc['linhas_extras'].' linhas de outros requisitantes entram na próxima sincronização.'.($cc['bloqueado']?' Atenção: o CC está bloqueado no SGA.':'');
                url('/integracao');
            case '/sga-centro-custo-remover':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                $before=CentrosCusto::config()['centros'];
                $cc=CentrosCusto::remover($_POST['centro_custo_id']??'');
                DB::audit('integracao','centros_custo','remover',['centros'=>$before],$cc,$actor);
                $_SESSION['success']='Centro de custo '.$cc['codigo'].($cc['removido']?' removido das próximas consultas. O histórico importado foi preservado.':' já estava fora da consulta.');
                url('/integracao');
            case '/relatorio-gerar':
                // CCs novos entram no escopo (validados no SGA) e a sincronização roda antes do relatório.
                $novos=Relatorios::codigosNovos((string)($_POST['novos']??''));
                $sincronizar=!empty($_POST['sincronizar']);
                if(($novos || $sincronizar) && $u['perfil']!=='admin') throw new RuntimeException('Somente administradores incluem centros de custo e sincronizam com o SGA.');
                $msg='';
                if($novos || $sincronizar) {
                    set_time_limit(300);
                    $r=Relatorios::prepararComSincronizacao($novos,!empty($_POST['incluir_filhos']),$actor);
                    $msg=($r['adicionados']?count($r['adicionados']).' centro(s) de custo incluído(s): '.implode(', ',array_map(static fn($a)=>$a['codigo'].' — '.$a['nome'],$r['adicionados'])).'. ':'').'SGA sincronizado: '.$r['sincronizacao']['compras'].' compras no escopo.';
                }
                $sel=$_POST['cc']??[]; $sel=is_array($sel)?array_values(array_filter($sel,'is_string')):[];
                foreach($novos as $n) $sel[]=(string)$n;
                $q=['periodo'=>(string)($_POST['periodo']??'todos'),'data_inicio'=>(string)($_POST['data_inicio']??''),'data_fim'=>(string)($_POST['data_fim']??''),'cc'=>array_values(array_unique($sel))];
                Periodo::ler($q);
                $_SESSION['success']=$msg!==''?$msg:'Relatório gerado com os dados já importados.';
                url('/relatorios?'.http_build_query($q));
            case '/sga-salvar':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                Integracao::salvar($_POST); DB::audit('integracao','sga','configurar',null,['servidor'=>$_POST['servidor'],'banco'=>$_POST['banco']],$actor); break;
            case '/sga-sincronizar':
                if($u['perfil']!=='admin') throw new RuntimeException('Permissão insuficiente.');
                set_time_limit(180); $sync=Integracao::sincronizar($actor); $n=$sync['compras'];
                $_SESSION['success']="$n compras sincronizadas. Patrimônios: ".$sync['patrimonios']['associado']." associados; ".$sync['patrimonios']['ambiguo']." ambíguos; ".$sync['patrimonios']['sem_numero']." sem número.".($sync['aviso']??''); url('/integracao');
            case '/senha':
                $pw=(string)($_POST['nova_senha']??''); $old=DB::one('SELECT senha_hash FROM usuarios WHERE id=?',[$actor]);
                if(!password_verify((string)($_POST['senha_atual']??''),$old['senha_hash'])) throw new RuntimeException('Senha atual incorreta.');
                if(strlen($pw)<12 || strlen($pw)>72) throw new RuntimeException('Use de 12 a 72 caracteres na nova senha.');
                DB::run('UPDATE usuarios SET senha_hash=? WHERE id=?',[password_hash($pw,PASSWORD_BCRYPT,['cost'=>12]),$actor]);
                session_regenerate_id(true); DB::audit('usuarios',$actor,'trocar_senha',null,null,$actor); break;
            default: throw new RuntimeException('Ação não encontrada.');
        }
        $_SESSION['success']='Operação concluída.'; url(self::back($path));
    }
    private static function guardarDocumento(int $id,array $doc): void {
        $_SESSION['documentos_projetos'][$id]=['token'=>bin2hex(random_bytes(24)),'expires'=>time()+900,'documento'=>DocumentosCompra::preparar($doc)];
    }
    private static function view(string $file,array $vars): void { extract($vars,EXTR_SKIP); require ROOT.'/app/Views/'.$file.'.php'; }
    private static function exportRelatorio(Periodo $periodo): void {
        $sel=$_GET['cc']??[]; $sel=is_array($sel)?array_map('intval',array_filter($sel,'is_string')):[];
        $r=Relatorios::gerar(Relatorios::selecionar($sel),$periodo,null);
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="relatorio-gastos-cc-'.date('Ymd-His').'.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
        $safe=static fn(array $row)=>array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:($v??''),$row);
        fputcsv($out,['Resumo por centro de custo',$periodo->rotulo()],';');
        fputcsv($out,['CC','Centro de custo','Linhas','Total pedido BRL','Com NF positiva BRL','Sem patrimonio BRL','Patrimonios (n)','Patrimonios BRL','Bens sem numero (n)','Bens sem numero BRL'],';');
        foreach($r['origens'] as $o) fputcsv($out,$safe([$o['codigo'],$o['rotulo'],$o['linhas']??0,$o['total']??0,$o['total_nf']??0,$o['sem_patrimonio']??0,$o['ativos']['n'],$o['ativos']['valor'],$o['sem_numero']['n'],$o['sem_numero']['valor']]),';');
        fputcsv($out,$safe(['Total geral (sem dupla contagem)','',$r['total']['linhas']??0,$r['total']['total']??0,$r['total']['total_nf']??0,$r['total']['sem_patrimonio']??0]),';');
        fputcsv($out,[],';');
        fputcsv($out,['Data compra','Compra SGA','Processo','Produto','Requisitante','CC SGA','Centro de custo','Valor pedido BRL','Com NF','Com patrimonio'],';');
        foreach($r['linhas'] as $l) fputcsv($out,$safe([$l['processo_data'],$l['origem_item_id'],$l['processo_id'],$l['produto'],$l['requisitante'],$l['cc_sga'],$l['cc_nome'],$l['base_pedido'],$l['vinculos_nf_positivos']>0?'Sim':'Nao',$l['tem_patrimonio']?'Sim':'Nao']),';');
        fclose($out);
    }
    private static function exportProjeto(int $id): void {
        $periodo=Periodo::ler($_GET); $d=Projetos::detalhe($id,$periodo);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="projeto-'.$id.'-custos.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['Projeto','Compra SGA','Produto','Percentual','Base pedido BRL','Valor atribuido BRL','Com vinculo NF','Alerta origem','Data compra','Periodo de','Periodo ate'],';');
        foreach($d['apontamentos'] as $r) {
            $row=[$d['project']['codigo'],$r['origem_item_id'],$r['produto'],$r['percentual'],$r['base_pedido'],$r['valor_atribuido'],$r['vinculos_nf_positivos']>0?'Sim':'Nao',$r['alerta_origem']?'Sim':'Nao',$r['processo_data'],$periodo->inicio,$periodo->fim];
            fputcsv($out,array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:($v??''),$row),';');
        }
        fclose($out);
    }
    private static function export(): void {
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="patrimonios-salinas-ti.csv"');
        $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['Empresa','Patrimonio','Produto','Serie','Situacao','Local','Centro de custo','Responsavel'],';');
        $rows=DB::all('SELECT a.empresa_patrimonio,a.patrimonio,p.nome,a.serie,a.situacao,l.nome local,c.nome cc,r.nome responsavel FROM ativos a JOIN produtos p ON p.id=a.produto_id LEFT JOIN locais l ON l.id=a.local_atual_id LEFT JOIN centros_custo c ON c.id=a.centro_custo_atual_id LEFT JOIN responsaveis r ON r.id=a.responsavel_atual_id');
        foreach($rows as $row) fputcsv($out,array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:($v??''),array_values($row)),';'); fclose($out);
    }
}
