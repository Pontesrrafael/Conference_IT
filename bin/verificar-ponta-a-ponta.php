<?php
declare(strict_types=1);
/*
 * Verificação de ponta a ponta (HTTP) com banco temporário e dados sintéticos.
 * - Cria um banco MariaDB exclusivo, aplica as migrações e o descarta no final.
 * - Sobe o servidor PHP embutido em porta livre apontando para esse banco.
 * - Percorre login, todas as telas, exportações e as principais ações (CSRF, perfis, rateio, triagem).
 * - NÃO chama as rotas /sga-*: elas alteram a configuração privada da instalação e consultam o SGA.
 * Requer a instância MariaDB da instalação (porta 3308) e storage/maria-admin.json, como as demais verificações.
 */
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Database as DB;
use App\Services\Integracao;
use App\Services\Requisitantes;
use App\Services\CentrosCusto;
$nome='salinas_ti_e2e_'.bin2hex(random_bytes(5));
$root=json_decode((string)file_get_contents(ROOT.'/storage/maria-admin.json'),true,512,JSON_THROW_ON_ERROR);
$admin=new PDO('mysql:host=127.0.0.1;port=3308;charset=utf8mb4','root',$root['senha'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ok=0; $check=static function(bool $v,string $m) use(&$ok): void { if(!$v) throw new RuntimeException($m); $ok++; };
$server=null; $pipes=[]; $log=sys_get_temp_dir().'/salinas-e2e-'.bin2hex(random_bytes(4)).'.log'; $created=false; $exit=0;
try {
    // 1. Banco temporário com as migrações reais.
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
    // 2. Usuários e dados sintéticos (nenhum dado da empresa).
    $pwAdmin='E2e-'.bin2hex(random_bytes(8)); $pwConsulta='E2e-'.bin2hex(random_bytes(8));
    DB::run("INSERT INTO usuarios(nome,email,senha_hash,perfil) VALUES('Admin E2E','admin@e2e.local',?,'admin'),('Consulta E2E','consulta@e2e.local',?,'consulta')",[password_hash($pwAdmin,PASSWORD_BCRYPT),password_hash($pwConsulta,PASSWORD_BCRYPT)]);
    // Usa o escopo real da instalação (storage/requisitantes.json): o teste funciona com qualquer lista configurada.
    // Escopo real da instalação: por requisitante ou, se não houver, pelo primeiro centro de custo configurado.
    $reqEscopo=Requisitantes::lista()[0]??2147483001; $ccEscopo=Requisitantes::lista()?null:(CentrosCusto::lista()[0]??null); $reqFora=2147483000;
    $origemEscopo=$ccEscopo?'cc:'.$ccEscopo:'req:'.$reqEscopo;
    $rows=[];
    foreach([[910001,'=CMD|calc','Notebook sintético'],[910002,'<script>alert(1)</script>','Switch sintético'],[910003,'Cabo sintético','Cabo sintético']] as $i=>[$id,$prod,$alt]) {
        $rows[]=['origem_item_id'=>$id,'processo_id'=>880000+$i,'empresa_id'=>1,'filial_id'=>1,'requisitante_id'=>$reqEscopo,'requisitante_nome'=>'Requisitante sintético','produto_id'=>770000+$i,'produto_nome'=>$prod,'centro_custo_compra_id'=>$ccEscopo??990000+$i,'centro_custo_compra_nome'=>'CC sintético '.$i,'processo_data'=>'2026-10-0'.($i+1).'T10:00:00','cancelado_em'=>null,'quantidade_solicitada'=>'2.0000','quantidade_faturada_cadastro'=>'2.0000','quantidade_cancelada'=>'0.0000','quantidade_vinculada_nf'=>'2.0000','valor_unitario_pedido'=>'1500.5000','valor_total_pedido'=>'3001.0000','vinculos_nf_positivos'=>1];
    }
    $check(Integracao::importar($rows,'evidencia',null)===3,'Importação sintética falhou.');
    // Linha de requisitante fora do escopo (ex.: removido da consulta), entrada por documento: histórico preservado.
    $fora=$rows[2]; $fora['origem_item_id']=910009; $fora['requisitante_id']=$reqFora; $fora['centro_custo_compra_id']=990009; $fora['produto_id']=770009; $fora['produto_nome']='Item fora do escopo E2E';
    Integracao::importar([$fora],'sga_documento',null,false);
    $compras=array_column(DB::all('SELECT id FROM compras_origem WHERE origem_item_id<910009 ORDER BY origem_item_id'),'id');
    $compraFora=(int)DB::one('SELECT id FROM compras_origem WHERE origem_item_id=910009')['id'];
    // Bens aguardando conferência: um do escopo (material não TI) e um fora do escopo.
    DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,situacao) SELECT produto_id,id,'E2E-DENTRO','aguardando_conferencia' FROM compras_origem WHERE id=?",[$compras[2]]);
    DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,situacao) SELECT produto_id,id,'E2E-FORA','aguardando_conferencia' FROM compras_origem WHERE id=?",[$compraFora]);
    // Bens do imobilizado sem número de patrimônio: um no escopo e um fora (não viram ativo, mas devem ser listados).
    foreach([[7001,'Rack sem número E2E',$reqEscopo],[7002,'Bem sem número fora E2E',$reqFora]] as [$imob,$nomeBem,$reqBem]) DB::run("INSERT INTO patrimonios_sga(imobilizado_item_id,bem_id,empresa_id,nome_bem,candidatos_json,payload_json,situacao,fonte) VALUES(?,?,1,?,'[910003]',?,'sem_numero','evidencia')",[$imob,$imob,$nomeBem,json_encode(['requisitantes_candidatos'=>[$reqBem],'centros_candidatos'=>$reqBem===$reqEscopo&&$ccEscopo?[$ccEscopo]:[],'numero_nota'=>'000123','valor_aquisicao_imobilizado'=>'999.90','descricao_imobilizado'=>$nomeBem.' [NCM:00]','compras_candidatas'=>[910003]])]);

    // 3. Servidor PHP embutido em porta livre apontando para o banco temporário.
    $sock=stream_socket_server('tcp://127.0.0.1:0'); $port=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1); fclose($sock);
    $env=['DB_DATABASE'=>$nome]; foreach(['PATH','SystemRoot','TEMP','TMP','HOME'] as $k) if(getenv($k)!==false) $env[$k]=getenv($k);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',ROOT.'/public',ROOT.'/public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,ROOT,$env);
    $base='http://127.0.0.1:'.$port;
    for($i=0;$i<50 && !@fsockopen('127.0.0.1',$port);$i++) usleep(100000);
    $cookie=''; $csrf='';
    $http=static function(string $method,string $path,array $data=[]) use(&$cookie,$base): array {
        $opts=['method'=>$method,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30,'header'=>"Cookie: $cookie\r\n"];
        if($method==='POST') { $opts['header'].="Content-Type: application/x-www-form-urlencoded\r\n"; $opts['content']=http_build_query($data); }
        $body=(string)file_get_contents($base.$path,false,stream_context_create(['http'=>$opts]));
        $headers=function_exists('http_get_last_response_headers')?(http_get_last_response_headers()??[]):($http_response_header??[]);
        preg_match('~HTTP/\S+\s+(\d{3})~',$headers[0]??'',$m); $h=[];
        foreach($headers as $line) { if(preg_match('/^set-cookie:\s*(est_sg=[^;]+)/i',$line,$c)) $cookie=$c[1]; if(str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $h[strtolower(trim($k))]=trim($v); } }
        return ['status'=>(int)($m[1]??0),'body'=>$body,'headers'=>$h];
    };
    $token=static function(string $html) use(&$csrf): void { if(preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)) $csrf=$m[1]; };
    $noErrors=static fn(string $b): bool => !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace)/i',$b);
    $flash=static function(string $path) use($http): string { $r=$http('GET',$path); return preg_match('/class="notice (success|error)">([^<]*)</',$r['body'],$m)?$m[1].': '.html_entity_decode($m[2]):''; };

    // 4. Saúde, login e CSRF.
    $r=$http('GET','/saude'); $check($r['status']===200 && str_contains($r['body'],'"ok"'),'Rota de saúde falhou.');
    $r=$http('GET','/'); $check($r['status']===200 && str_contains($r['body'],'name="senha"'),'Tela de login não exibida.'); $token($r['body']);
    $check(isset($r['headers']['content-security-policy'],$r['headers']['x-content-type-options']),'Cabeçalhos de segurança ausentes.');
    $r=$http('POST','/login',['email'=>'admin@e2e.local','senha'=>$pwAdmin]); $check($r['status']===303,'Login sem CSRF não redirecionou.');
    $r=$http('GET','/login'); $check(str_contains($r['body'],'Sessão do formulário expirou'),'Login sem CSRF foi aceito.'); $token($r['body']);
    $http('POST','/login',['csrf'=>$csrf,'email'=>'admin@e2e.local','senha'=>'errada']); $r=$http('GET','/login'); $check(str_contains($r['body'],'inválidos'),'Senha errada aceita.'); $token($r['body']);
    $r=$http('POST','/login',['csrf'=>$csrf,'email'=>'admin@e2e.local','senha'=>$pwAdmin]); $check($r['status']===303 && ($r['headers']['location']??'')==='/','Login válido falhou.');

    // 5. Todas as telas de leitura.
    $paginas=['/','/projetos','/compras','/compras?filtro=pendentes','/compras?filtro=incluidas&q=Notebook','/ativos','/estoque','/mapas','/historico','/cadastros','/integracao','/conta','/?periodo=mes','/projetos?periodo=personalizado&de=2026-10-01&ate=2026-10-31'];
    foreach($paginas as $p) { $r=$http('GET',$p); $check($r['status']===200 && $noErrors($r['body']),"Tela $p falhou (HTTP {$r['status']})."); $token($r['body']); }
    $r=$http('GET','/integracao'); $check(str_contains($r['body'],'centros de custo SGA') && str_contains($r['body'],'requisitantes SGA'),'Painéis de escopo ausentes na Integração.');
    $r=$http('GET','/compras?filtro=todas'); $check(str_contains($r['body'],'&lt;script&gt;') && !str_contains($r['body'],'<script>alert'),'Escape de HTML falhou (XSS).');
    $r=$http('GET','/ativos'); $check(str_contains($r['body'],'E2E-DENTRO') && !str_contains($r['body'],'E2E-FORA'),'Ativos fora do escopo atual exibidos por padrão.');
    $r=$http('GET','/ativos'); $check(str_contains($r['body'],'Rack sem número E2E') && !str_contains($r['body'],'Bem sem número fora E2E') && str_contains($r['body'],'R$ 999,90'),'Lista de bens sem número no escopo incorreta.');
    $r=$http('GET','/integracao'); $check(str_contains($r['body'],'Consulta os requisitantes configurados'),'Resumo do escopo ausente em Atualizar compras.');
    $r=$http('GET','/ativos?escopo=todos'); $check(str_contains($r['body'],'Bem sem número fora E2E'),'Histórico de bens sem número não exibido.'); $check(str_contains($r['body'],'E2E-DENTRO') && str_contains($r['body'],'E2E-FORA'),'Histórico completo de ativos não exibido.');
    $r=$http('GET','/compras?filtro=todas'); $check(!str_contains($r['body'],'Item fora do escopo E2E'),'Compra fora do escopo exibida por padrão.');
    $r=$http('GET','/compras?filtro=todas&escopo=todos'); $check(str_contains($r['body'],'Item fora do escopo E2E') && str_contains($r['body'],'fora do escopo atual (histórico)'),'Histórico completo de compras não exibido.');
    $r=$http('GET','/compras?filtro=todas&origem='.urlencode($origemEscopo)); $check(str_contains($r['body'],'Cabo sintético') && str_contains($r['body'],'No escopo por:') && $noErrors($r['body']),'Filtro por origem no escopo falhou.');
    $r=$http('GET','/'); $check(str_contains($r['body'],'ESCOPO ATUAL DA CONSULTA') && $noErrors($r['body']),'Painel sem indicação de escopo.');
    // Compras do escopo no Painel: 3 linhas sintéticas x R$ 3.001,00; só a terceira tem ativo vinculado.
    $check(str_contains($r['body'],'R$ 9.003,00') && str_contains($r['body'],'R$ 6.002,00') && str_contains($r['body'],'Compras sem patrimônio'),'Totais de compras do escopo incorretos no Painel.');
    $r=$http('GET','/?origem='.urlencode($origemEscopo)); $check($noErrors($r['body']) && str_contains($r['body'],'R$ 9.003,00') && str_contains($r['body'],'name="origem"'),'Filtro por origem no Painel falhou.');
    $r=$http('GET','/?origem=cc:999999999'); $check($noErrors($r['body']) && str_contains($r['body'],'R$ 9.003,00'),'Origem inválida não foi ignorada no Painel.');
    // Relatórios por centro de custo: tela, totais (quando o escopo tem CC), CSV e geração sem sincronizar.
    $r=$http('GET','/relatorios'); $check($r['status']===200 && $noErrors($r['body']) && str_contains($r['body'],'Parâmetros do relatório') && str_contains($r['body'],'name="novos"'),'Tela de relatórios falhou.'); $token($r['body']);
    $r=$http('GET','/relatorios?periodo=personalizado&data_inicio=2026-10-01&data_fim=2026-10-31'); $check($r['status']===200 && $noErrors($r['body']),'Relatório com período falhou.');
    $r=$http('GET','/relatorio-exportar'); $check(str_contains($r['headers']['content-type']??'','text/csv') && str_contains($r['body'],'Resumo por centro de custo'),'CSV do relatório inválido.');
    if($ccEscopo) {
        $r=$http('GET','/relatorios?cc[]='.$ccEscopo); $check(str_contains($r['body'],'R$ 9.003,00') && str_contains($r['body'],'Cabo sintético'),'Totais do relatório por CC incorretos.');
        $r=$http('GET','/relatorio-exportar?cc[]='.$ccEscopo); $check(str_contains($r['body'],"'=CMD|calc") && str_contains($r['body'],'9003'),'CSV do relatório sem proteção ou sem totais.');
    }
    $r=$http('GET','/relatorio-pdf?periodo=ano'); $check(($r['headers']['content-type']??'')==='application/pdf' && str_starts_with($r['body'],'%PDF-1.4') && substr_count($r['body'],'/Subtype /Image')===2 && str_contains($r['headers']['content-disposition']??'','.pdf'),'PDF do relatório inválido.');
    if($ccEscopo) { $r=$http('GET','/relatorio-pdf?cc[]='.$ccEscopo); $txt=''; preg_match_all('/stream\n(.*?)\nendstream/s',$r['body'],$st); foreach($st[1] as $z) $txt.=@gzuncompress($z)?:''; $check(str_contains($txt,'(R$ 9.003,00)'),'Totais ausentes no PDF do relatório.'); }
    $r=$http('POST','/relatorio-gerar',['csrf'=>$csrf,'periodo'=>'ano']); $check($r['status']===303 && str_starts_with($r['headers']['location']??'','/relatorios?periodo=ano'),'Geração do relatório sem sincronizar falhou.');
    $check(str_starts_with($flash('/relatorios'),'success'),'Aviso da geração do relatório ausente.');
    $http('POST','/relatorio-gerar',['csrf'=>$csrf,'novos'=>'abc']); $check(str_contains($flash('/relatorios'),'error'),'Código inválido aceito na geração do relatório.');
    // 5b. Visão Executiva de TI: painel, Modo TV, miolo parcial, lançamento, CSV (tudo ou nada), metas e riscos.
    $multipart=static function(string $path,array $campos,string $arquivo,string $nomeArq) use(&$cookie,$base): array {
        $b='----e2e'.bin2hex(random_bytes(6)); $c='';
        foreach($campos as $k=>$v) $c.="--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        $c.="--$b\r\nContent-Disposition: form-data; name=\"arquivo\"; filename=\"$nomeArq\"\r\nContent-Type: text/csv\r\n\r\n$arquivo\r\n--$b--\r\n";
        $body=(string)file_get_contents($base.$path,false,stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'header'=>"Cookie: $cookie\r\nContent-Type: multipart/form-data; boundary=$b\r\n",'content'=>$c]]));
        $headers=function_exists('http_get_last_response_headers')?(http_get_last_response_headers()??[]):($http_response_header??[]);
        preg_match('~HTTP/\S+\s+(\d{3})~',$headers[0]??'',$m); return ['status'=>(int)($m[1]??0),'body'=>$body];
    };
    $r=$http('GET','/indicadores'); $check($r['status']===200 && $noErrors($r['body']) && str_contains($r['body'],'Visão Executiva de TI') && str_contains($r['body'],'Disponibilidade da rede') && str_contains($r['body'],'Últimos 30 dias') && str_contains($r['body'],'/assets/indicadores.js'),'Visão Executiva não abriu.');
    $check(!preg_match('/\sstyle="/',$r['body']),'Visão Executiva com atributo style (bloqueado pela CSP).');
    $r=$http('GET','/indicadores?tv=1'); $check($r['status']===200 && str_contains($r['body'],'tv-relogio') && !str_contains($r['body'],'class="sidebar"'),'Modo TV inválido.');
    $r=$http('GET','/indicadores?parcial=1&periodo=7dias'); $check($r['status']===200 && !str_contains($r['body'],'<html') && str_contains($r['body'],'ind-kpis'),'Miolo parcial inválido.');
    $r=$http('GET','/indicadores-dados'); $check($r['status']===200 && $noErrors($r['body']) && str_contains($r['body'],'Lançar medição'),'Dados dos indicadores não abriu.'); $token($r['body']);
    $ontem=date('Y-m-d',strtotime('-1 day'));
    $http('POST','/indicador-medicao',['csrf'=>$csrf,'codigo'=>'disponibilidade_rede','data'=>$ontem,'valor'=>'99,8','observacao'=>'E2E']);
    $check(str_starts_with($flash('/indicadores-dados'),'success'),'Lançamento manual falhou.');
    $http('POST','/indicador-medicao',['csrf'=>$csrf,'codigo'=>'cobertura_mfa','data'=>$ontem,'valor'=>'120']); $check(str_contains($flash('/indicadores-dados'),'entre 0 e 100'),'Percentual acima de 100 aceito.');
    $http('POST','/indicador-medicao',['csrf'=>$csrf,'codigo'=>'cobertura_mfa','data'=>date('Y-m-d',strtotime('+2 days')),'valor'=>'90']); $check(str_contains($flash('/indicadores-dados'),'futura'),'Data futura aceita.');
    $r=$multipart('/indicadores-importar',['csrf'=>$csrf,'tipo'=>'medicoes'],"codigo;data;valor\nlinks_internet;$ontem;97,5\nindicador_x;$ontem;1\n",'m.csv');
    $check($r['status']===303 && str_contains($flash('/indicadores-dados'),'Nada foi importado') && (int)DB::one("SELECT COUNT(*) n FROM indicadores_ti_medicoes m JOIN indicadores_ti i ON i.id=m.indicador_id WHERE i.codigo='links_internet'")['n']===0,'CSV com erro importou parcialmente.');
    $multipart('/indicadores-importar',['csrf'=>$csrf,'tipo'=>'medicoes'],"\xEF\xBB\xBFcodigo;data;valor;observacao\nlinks_internet;".date('d/m/Y',strtotime('-1 day')).";97,5;csv\nproblemas_criticos;$ontem;2;\ncontroles_status_implementado;$ontem;10;\ncontroles_status_avaliar;$ontem;30;\n",'m.csv');
    $check(str_contains($flash('/indicadores-dados'),'4 linha(s) importada(s)'),'Importação de medições falhou.');
    $multipart('/indicadores-importar',['csrf'=>$csrf,'tipo'=>'riscos'],"codigo;risco;nivel;classificacao;responsavel;situacao\nR-E2E;Risco <b>sintético</b> E2E;16;Alto;TI;aberto\nR-E2F;Risco tratado E2E;20;;TI;tratado\n",'r.csv');
    $check(str_contains($flash('/indicadores-dados'),'2 linha(s)') && DB::one("SELECT classificacao FROM riscos_ti WHERE codigo='R-E2F'")['classificacao']==='Crítico','Importação de riscos falhou.');
    $http('POST','/indicador-meta',['csrf'=>$csrf,'codigo'=>'problemas_criticos','operador'=>'<=','meta'=>'2']); $check(str_starts_with($flash('/indicadores-dados'),'success'),'Meta não foi salva pelo administrador.');
    $r=$http('GET','/indicadores?periodo=7dias');
    $check(str_contains($r['body'],'99,80%') && str_contains($r['body'],'97,50%') && preg_match('/kpi-ok[^>]*>\s*<p class="kpi-rotulo">Problemas críticos/',$r['body'])===1,'Cartões não refletem lançamentos e meta.');
    $check(str_contains($r['body'],'Risco &lt;b&gt;sintético&lt;/b&gt; E2E') && !str_contains($r['body'],'Risco tratado E2E') && str_contains($r['body'],'class="viz-ponto') && str_contains($r['body'],'viz-fatia'),'Riscos ou gráficos ausentes no painel.');
    $r=$http('GET','/indicadores-modelo?tipo=riscos'); $check(str_starts_with($r['body'],"\xEF\xBB\xBFcodigo;risco;nivel") && str_contains($r['headers']['content-type']??'','text/csv'),'Modelo CSV inválido.');
    $med=(int)DB::one("SELECT m.id FROM indicadores_ti_medicoes m JOIN indicadores_ti i ON i.id=m.indicador_id WHERE i.codigo='problemas_criticos'")['id'];
    $http('POST','/indicador-medicao-remover',['csrf'=>$csrf,'id'=>(string)$med]); $check(!DB::one('SELECT id FROM indicadores_ti_medicoes WHERE id=?',[$med]) && DB::one("SELECT id FROM auditoria WHERE entidade='indicadores_ti_medicoes' AND acao='remover'"),'Remoção de medição sem auditoria.');
    // 5c. Tema claro/escuro (cookie por navegador, sem redirecionamento aberto) e Modo TV do painel Parque e custos.
    $r=$http('GET','/'); $check(str_contains($r['body'],'class="tema-seletor') && str_contains($r['body'],'data-tema-valor="escuro"') && !str_contains($r['body'],'<html lang="pt-BR" data-tema'),'Seletor de tema ausente ou tema forçado sem escolha.');
    $r=$http('GET','/tema?valor=escuro&voltar='.rawurlencode('/indicadores?tv=1')); $check($r['status']===303 && ($r['headers']['location']??'')==='/indicadores?tv=1','Troca de tema não voltou à página.');
    foreach(['//evil.example','/\\evil','https://evil.example'] as $mal) { $r=$http('GET','/tema?valor=claro&voltar='.rawurlencode($mal)); $check(($r['headers']['location']??'')==='/','Redirecionamento aberto em /tema: '.$mal); }
    $cookieOriginal=$cookie; $cookie.='; tema=escuro';
    $r=$http('GET','/'); $check(str_contains($r['body'],'<html lang="pt-BR" data-tema="escuro">') && str_contains($r['body'],'aria-pressed="true" title="Tema escuro"'),'Tema escuro não aplicado pelo cookie.');
    $r=$http('GET','/?tv=1&atualizar=60'); $check($r['status']===200 && $noErrors($r['body']) && str_contains($r['body'],'data-recarregar="60"') && str_contains($r['body'],'tv-relogio') && !str_contains($r['body'],'class="sidebar"') && str_contains($r['body'],'data-tema="escuro"') && str_contains($r['body'],'name="tv" value="1"'),'Modo TV do Parque e custos inválido.');
    $r=$http('GET','/indicadores?tv=1'); $check(str_contains($r['body'],'data-recarregar="0"') && str_contains($r['body'],'/assets/indicadores.js') && str_contains($r['body'],'Sair do Modo TV'),'Modo TV da Visão Executiva inválido.');
    $cookie=$cookieOriginal.'; tema=invalido'; $r=$http('GET','/'); $check(!str_contains($r['body'],'data-tema="invalido"'),'Valor de tema inválido refletido no HTML.');
    $cookie=$cookieOriginal;
    $r=$http('GET','/rota-inexistente'); $check($r['status']===404,'Rota inexistente não retornou 404.');

    // 6. Ações com CSRF: cadastro, projeto, rateio com limite de 100%, triagem.
    $http('POST','/cadastro',['csrf'=>$csrf,'cadastro'=>'local','nome'=>'Sala E2E']); $check(str_starts_with($flash('/cadastros'),'success'),'Cadastro de local falhou.');
    $check((int)DB::one("SELECT COUNT(*) n FROM auditoria WHERE entidade='locais' AND acao='criar'")['n']===1,'Auditoria do cadastro ausente.');
    $http('POST','/projeto-salvar',['csrf'=>$csrf,'codigo'=>'E2E-01','nome'=>'Projeto E2E','status'=>'em_andamento','orcamento'=>'10000,00','inicio'=>'2026-10-01','fim'=>'2026-12-31']);
    $p1=(int)DB::one("SELECT id FROM projetos WHERE codigo='E2E-01'")['id']; $check($p1>0,'Projeto não criado.');
    $http('POST','/projeto-salvar',['csrf'=>$csrf,'codigo'=>'E2E-02','nome'=>'Projeto E2E 2','status'=>'planejado']);
    $p2=(int)DB::one("SELECT id FROM projetos WHERE codigo='E2E-02'")['id'];
    $http('POST','/projeto-apontar',['csrf'=>$csrf,'projeto_id'=>$p1,'compra_id'=>$compras[0],'percentual'=>'60','motivo'=>'Rateio E2E']);
    $check(str_starts_with($flash('/projeto?id='.$p1),'success'),'Rateio de 60% falhou.');
    $http('POST','/projeto-apontar',['csrf'=>$csrf,'projeto_id'=>$p2,'compra_id'=>$compras[0],'percentual'=>'50','motivo'=>'Excede']);
    $check(str_starts_with($flash('/projeto?id='.$p2),'error'),'Rateio acima de 100% foi aceito.');
    $custo=DB::one('SELECT SUM(valor_atribuido) v FROM vw_apontamentos_custos WHERE projeto_id=?',[$p1]);
    $check($custo && bccomp((string)$custo['v'],'1800.60',2)===0,'Custo rateado divergente: '.($custo['v']??'nulo').' (esperado 1800.60).');
    $r=$http('GET','/projeto?id='.$p1); $check($r['status']===200 && $noErrors($r['body']) && str_contains($r['body'],'E2E-01'),'Painel do projeto falhou.'); $token($r['body']);
    $http('POST','/triagem',['csrf'=>$csrf,'compra_id'=>$compras[2],'triagem'=>'incluir','controle'=>'quantidade','categoria'=>'Cabos','unidade'=>'UN','motivo'=>'Triagem E2E']);
    $check(DB::one('SELECT triagem FROM compras_origem WHERE id=?',[$compras[2]])['triagem']==='incluir','Triagem não aplicada.');

    // 7. Exportações.
    $r=$http('GET','/exportar'); $check(str_contains($r['headers']['content-type']??'','text/csv') && str_starts_with($r['body'],"\xEF\xBB\xBF"),'CSV de patrimônios inválido.');
    $r=$http('GET','/projeto-exportar?id='.$p1); $check(str_contains($r['body'],"'=CMD|calc") && !preg_match('/;=CMD/',$r['body']),'Proteção contra fórmula no CSV falhou.');
    $r=$http('GET','/projeto-project?id='.$p1); $xml=new DOMDocument(); $check(@$xml->loadXML($r['body']) && $xml->documentElement->localName==='Project','Project XML inválido.');

    // 7b. Mapas: upload GeoTIFF sintético, área, vínculo de projeto, imagem e GeoJSON (quando o leitor Python estiver configurado).
    $fixture=ROOT.'/evidencias/geotiff-teste-wgs84.tif'; $mapas='ignorado (MAP_PYTHON ou fixture sintética ausente; gere com bin/criar-geotiff-teste.py)';
    if(getenv('MAP_PYTHON') && is_file((string)getenv('MAP_PYTHON')) && is_file($fixture)) {
        $boundary='----e2e'.bin2hex(random_bytes(8));
        $part=static fn(string $n,string $v)=>"--$boundary\r\nContent-Disposition: form-data; name=\"$n\"\r\n\r\n$v\r\n";
        $body=$part('csrf',$csrf).$part('nome','Mapa sintético E2E').$part('unidade','Central')."--$boundary\r\nContent-Disposition: form-data; name=\"imagem\"; filename=\"teste.tif\"\r\nContent-Type: image/tiff\r\n\r\n".file_get_contents($fixture)."\r\n--$boundary--\r\n";
        $ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>120,'header'=>"Cookie: $cookie\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",'content'=>$body]]);
        file_get_contents($base.'/mapa-upload',false,$ctx);
        $mapa=DB::one('SELECT id FROM mapas ORDER BY id DESC LIMIT 1'); $check((bool)$mapa,'Upload do GeoTIFF falhou: '.$flash('/mapas'));
        $r=$http('GET','/mapa?id='.$mapa['id']); $check($r['status']===200 && $noErrors($r['body']),'Tela do mapa falhou.'); $token($r['body']);
        $r=$http('GET','/mapa-imagem?id='.$mapa['id']); $check(str_starts_with($r['body'],"\x89PNG"),'Prévia PNG não servida.');
        $http('POST','/mapa-area',['csrf'=>$csrf,'mapa_id'=>$mapa['id'],'nome'=>'Área E2E','poligono'=>'[[0.1,0.1],[0.1,0.5],[0.5,0.5]]']);
        $area=DB::one('SELECT id FROM mapas_areas WHERE mapa_id=?',[$mapa['id']]); $check((bool)$area,'Área não salva.');
        $http('POST','/mapa-area',['csrf'=>$csrf,'mapa_id'=>$mapa['id'],'nome'=>'Cruzada','poligono'=>'[[0.1,0.1],[0.5,0.5],[0.1,0.5],[0.5,0.1]]']);
        $check((int)DB::one('SELECT COUNT(*) n FROM mapas_areas WHERE mapa_id=?',[$mapa['id']])['n']===1,'Polígono com bordas cruzadas aceito.');
        $http('POST','/mapa-vincular',['csrf'=>$csrf,'mapa_id'=>$mapa['id'],'area_id'=>$area['id'],'tipo'=>'projeto','referencia_id'=>$p1,'observacao'=>'Vínculo E2E']);
        $r=$http('GET','/mapa-exportar?id='.$mapa['id']); $geo=json_decode($r['body'],true);
        $check(($geo['type']??'')==='FeatureCollection' && count($geo['features']??[])===1 && ($geo['features'][0]['geometry']['type']??'')==='Polygon','GeoJSON inválido.');
        $mapas='upload, prévia PNG, área, polígono cruzado recusado, vínculo e GeoJSON';
    }

    // 8. Perfil de consulta: leitura sim, escrita e integração não.
    $r=$http('POST','/logout',['csrf'=>$csrf]); $r=$http('GET','/login'); $token($r['body']);
    $http('POST','/login',['csrf'=>$csrf,'email'=>'consulta@e2e.local','senha'=>$pwConsulta]);
    $r=$http('GET','/projetos'); $check($r['status']===200 && $noErrors($r['body']),'Consulta sem acesso de leitura.'); $token($r['body']);
    $r=$http('GET','/integracao'); $check(str_contains($r['body'],'Somente administradores'),'Consulta acessou a Integração.');
    $http('POST','/cadastro',['csrf'=>$csrf,'cadastro'=>'local','nome'=>'Indevido']); $check(str_contains($flash('/cadastros'),'apenas consulta'),'Consulta conseguiu gravar.');
    $check((int)DB::one("SELECT COUNT(*) n FROM locais WHERE nome='Indevido'")['n']===0,'Registro indevido gravado.');
    $http('POST','/sga-centro-custo',['csrf'=>$csrf,'centro_custo_id'=>'1290']); $check(str_contains($flash('/'),'apenas consulta'),'Consulta alterou escopo de CC.');
    $r=$http('GET','/relatorios'); $check($r['status']===200 && !str_contains($r['body'],'name="novos"') && str_contains($r['body'],'action="/relatorios"'),'Consulta viu opções de inclusão/sincronização no relatório.');
    $http('POST','/relatorio-gerar',['csrf'=>$csrf,'novos'=>'1290','sincronizar'=>'1']); $check(str_contains($flash('/'),'apenas consulta'),'Consulta conseguiu sincronizar pelo relatório.');
    $r=$http('GET','/indicadores'); $check($r['status']===200 && str_contains($r['body'],'Visão Executiva de TI') && !str_contains($r['body'],'href="/indicadores-dados" ') && !str_contains($r['body'],'>Dados dos indicadores</a>'),'Consulta não vê a Visão Executiva ou vê o acesso aos dados.');
    $r=$http('GET','/indicadores-dados'); $check(str_contains($r['body'],'apenas consulta'),'Consulta abriu Dados dos indicadores.');
    $http('POST','/indicador-medicao',['csrf'=>$csrf,'codigo'=>'cobertura_mfa','data'=>date('Y-m-d'),'valor'=>'50']); $check(str_contains($flash('/'),'apenas consulta'),'Consulta lançou medição.');

    // 9. Log do servidor sem avisos do PHP.
    usleep(200000); $server_log=(string)@file_get_contents($log);
    $check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/',$server_log),"Avisos do PHP no servidor:\n".implode("\n",array_slice(preg_grep('/PHP (Warning|Notice|Deprecated|Fatal)/',explode("\n",$server_log)),0,5)));
    echo "OK: ponta a ponta — $ok verificações: migrações, saúde, cabeçalhos, CSRF, login, ".count($paginas)." telas, escopo atual × histórico (ativos, compras, painel, bens sem número), filtro por origem (Compras e Painel), totais de compras com e sem patrimônio, relatórios por CC (tela, período, CSV, PDF no timbrado oficial, geração, permissões), Visão Executiva de TI e Parque e custos no Modo TV, tema claro/escuro (cookie, sem redirecionamento aberto), Visão Executiva (painel, miolo parcial, lançamento, CSV tudo-ou-nada, riscos, metas, remoção auditada, permissões), 404, XSS, cadastro+auditoria, projeto, rateio 60% e bloqueio >100%, custo 1800.60, triagem, CSV/BOM/fórmula, Project XML, mapas ($mapas), perfil consulta e log do PHP limpo. Banco temporário descartado.\n";
} catch(Throwable $e) {
    $exit=1; fwrite(STDERR,'FALHOU: '.$e->getMessage().PHP_EOL);
} finally {
    if(is_resource($server)) { foreach($pipes as $p) if(is_resource($p)) fclose($p); proc_terminate($server); proc_close($server); }
    if(is_file($log)) @unlink($log);
    if($created) {
        // Remove os arquivos de mapa criados por este teste antes de descartar o banco temporário.
        try { foreach($admin->query("SELECT arquivo FROM `$nome`.mapas")->fetchAll(PDO::FETCH_COLUMN) as $k) if(preg_match('/^[a-f0-9]{32}$/D',(string)$k)) foreach(['tif','png'] as $ext) @unlink(ROOT.'/storage/mapas/'.$k.'.'.$ext); } catch(Throwable) {}
        $admin->exec("DROP DATABASE `$nome`");
    }
}
exit($exit);
