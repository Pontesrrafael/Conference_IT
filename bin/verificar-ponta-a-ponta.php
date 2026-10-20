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
    $reqEscopo=Requisitantes::lista()[0]; $reqFora=2147483000;
    $rows=[];
    foreach([[910001,'=CMD|calc','Notebook sintético'],[910002,'<script>alert(1)</script>','Switch sintético'],[910003,'Cabo sintético','Cabo sintético']] as $i=>[$id,$prod,$alt]) {
        $rows[]=['origem_item_id'=>$id,'processo_id'=>880000+$i,'empresa_id'=>1,'filial_id'=>1,'requisitante_id'=>$reqEscopo,'requisitante_nome'=>'Requisitante sintético','produto_id'=>770000+$i,'produto_nome'=>$prod,'centro_custo_compra_id'=>990000+$i,'centro_custo_compra_nome'=>'CC sintético '.$i,'processo_data'=>'2026-10-0'.($i+1).'T10:00:00','cancelado_em'=>null,'quantidade_solicitada'=>'2.0000','quantidade_faturada_cadastro'=>'2.0000','quantidade_cancelada'=>'0.0000','quantidade_vinculada_nf'=>'2.0000','valor_unitario_pedido'=>'1500.5000','valor_total_pedido'=>'3001.0000','vinculos_nf_positivos'=>1];
    }
    $check(Integracao::importar($rows,'evidencia',null)===3,'Importação sintética falhou.');
    // Linha de requisitante fora do escopo (ex.: removido da consulta), entrada por documento: histórico preservado.
    $fora=$rows[2]; $fora['origem_item_id']=910009; $fora['requisitante_id']=$reqFora; $fora['produto_id']=770009; $fora['produto_nome']='Item fora do escopo E2E';
    Integracao::importar([$fora],'sga_documento',null,false);
    $compras=array_column(DB::all('SELECT id FROM compras_origem WHERE origem_item_id<910009 ORDER BY origem_item_id'),'id');
    $compraFora=(int)DB::one('SELECT id FROM compras_origem WHERE origem_item_id=910009')['id'];
    // Bens aguardando conferência: um do escopo (material não TI) e um fora do escopo.
    DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,situacao) SELECT produto_id,id,'E2E-DENTRO','aguardando_conferencia' FROM compras_origem WHERE id=?",[$compras[2]]);
    DB::run("INSERT INTO ativos(produto_id,compra_origem_id,patrimonio,situacao) SELECT produto_id,id,'E2E-FORA','aguardando_conferencia' FROM compras_origem WHERE id=?",[$compraFora]);

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
    $r=$http('GET','/ativos?escopo=todos'); $check(str_contains($r['body'],'E2E-DENTRO') && str_contains($r['body'],'E2E-FORA'),'Histórico completo de ativos não exibido.');
    $r=$http('GET','/compras?filtro=todas'); $check(!str_contains($r['body'],'Item fora do escopo E2E'),'Compra fora do escopo exibida por padrão.');
    $r=$http('GET','/compras?filtro=todas&escopo=todos'); $check(str_contains($r['body'],'Item fora do escopo E2E'),'Histórico completo de compras não exibido.');
    $r=$http('GET','/'); $check(str_contains($r['body'],'ESCOPO ATUAL DA CONSULTA') && $noErrors($r['body']),'Painel sem indicação de escopo.');
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

    // 9. Log do servidor sem avisos do PHP.
    usleep(200000); $server_log=(string)@file_get_contents($log);
    $check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/',$server_log),"Avisos do PHP no servidor:\n".implode("\n",array_slice(preg_grep('/PHP (Warning|Notice|Deprecated|Fatal)/',explode("\n",$server_log)),0,5)));
    echo "OK: ponta a ponta — $ok verificações: migrações, saúde, cabeçalhos, CSRF, login, ".count($paginas)." telas, escopo atual × histórico (ativos, compras, painel), 404, XSS, cadastro+auditoria, projeto, rateio 60% e bloqueio >100%, custo 1800.60, triagem, CSV/BOM/fórmula, Project XML, mapas ($mapas), perfil consulta e log do PHP limpo. Banco temporário descartado.\n";
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
