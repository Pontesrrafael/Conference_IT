<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
/**
 * Indicadores de TI — Visão Executiva (espelha a página "Visão Executiva" do SGM | Dashboard de TI no Power BI).
 * - Cartões com meta: valores lançados à mão ou importados por CSV (Zabbix, Microsoft 365, controles, licenças, rádios).
 * - Parque e compras: calculados pela própria aplicação (Parque::painel), no escopo atual da consulta.
 * - Regras de cálculo por período:
 *   media  = média das medições dentro do período (ex.: disponibilidade diária);
 *   ultimo = última medição até o fim do período (fotografia, ex.: cobertura de MFA).
 * - Comparação com o período anterior de mesma duração quando o período tem início e fim.
 */
final class IndicadoresTi {
    public const ATUALIZACOES=[0=>'Desligada',60=>'A cada 1 min',300=>'A cada 5 min',900=>'A cada 15 min'];
    public const ATUALIZACAO_PADRAO=300;
    public const LIMITE_CSV_BYTES=2_097_152, LIMITE_CSV_LINHAS=5000;
    public const SERIE_DISPONIBILIDADE=['disponibilidade_rede'=>'Hosts críticos','links_internet'=>'Links de internet'];
    public const CLASSIFICACOES=['Crítico','Alto','Médio','Baixo'];
    public const SITUACOES_RISCO=['aberto'=>'Aberto','em_tratamento'=>'Em tratamento','tratado'=>'Tratado','aceito'=>'Aceito'];
    public const MODELOS=[
        'medicoes'=>['codigo','data','valor','observacao'],
        'riscos'=>['codigo','risco','nivel','classificacao','responsavel','situacao'],
    ];

    /** A migração 007 é aplicada por bin/instalar.php (iniciar.ps1). Sem ela, o painel mostra só os dados da aplicação. */
    public static function pronto(): bool {
        try { DB::one('SELECT 1 FROM indicadores_ti LIMIT 1'); DB::one('SELECT 1 FROM riscos_ti LIMIT 1'); return true; }
        catch(\PDOException) { return false; }
    }
    public static function catalogo(bool $todos=false): array {
        return DB::all('SELECT * FROM indicadores_ti'.($todos?'':' WHERE ativo=1').' ORDER BY ordem,id');
    }
    public static function indicador(string $codigo): array {
        $i=DB::one('SELECT * FROM indicadores_ti WHERE codigo=? AND ativo=1',[$codigo]);
        if(!$i) throw new RuntimeException('Indicador não encontrado: '.mb_substr($codigo,0,60).'.');
        return $i;
    }

    // ---------- Números e datas ----------
    /** "99,5" · "99.5" · "99,5%" · "1.234,5" → float. */
    public static function numero(string $txt): float {
        $t=str_replace(['%',' ',"\u{00A0}"],'',trim($txt));
        if($t==='' ) throw new RuntimeException('Valor vazio.');
        if(str_contains($t,',') && str_contains($t,'.')) $t=str_replace('.','',$t);
        $t=str_replace(',','.',$t);
        if(!is_numeric($t)) throw new RuntimeException('Valor inválido: '.mb_substr($txt,0,30).'.');
        return (float)$t;
    }
    /** "20/10/2026" ou "2026-10-20" → "2026-10-20" (não aceita datas futuras). */
    public static function data(string $txt): string {
        $t=trim($txt);
        if(preg_match('#^(\d{2})/(\d{2})/(\d{4})$#',$t,$m)) $t="$m[3]-$m[2]-$m[1]";
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$t);
        if(!$d || $d->format('Y-m-d')!==$t) throw new RuntimeException('Data inválida: '.mb_substr($txt,0,20).' (use dd/mm/aaaa ou aaaa-mm-dd).');
        if($t>date('Y-m-d')) throw new RuntimeException('Data futura não é aceita: '.$d->format('d/m/Y').'.');
        return $t;
    }
    public static function validarValor(array $ind,float $v): float {
        if($ind['unidade']==='percentual' && ($v<0 || $v>100)) throw new RuntimeException($ind['nome'].': percentual deve ficar entre 0 e 100.');
        if($ind['unidade']==='quantidade' && $v<0) throw new RuntimeException($ind['nome'].': quantidade não pode ser negativa.');
        return round($v,4);
    }
    public static function formatar(?float $v,array $ind): string {
        if($v===null) return '—';
        if($ind['unidade']==='percentual') return number_format($v,$ind['agregacao']==='media'?2:1,',','.').'%';
        return number_format($v,floor($v)==$v?0:1,',','.');
    }
    public static function metaTexto(array $ind): string {
        if($ind['meta_operador']===null || $ind['meta']===null) return 'Sem meta';
        $m=(float)$ind['meta'];
        return 'Meta '.($ind['meta_operador']==='>='?'≥':'≤').' '.($ind['unidade']==='percentual'?number_format($m,2,',','.').'%':number_format($m,0,',','.'));
    }
    /** ok · fora · sem_dado · sem_meta */
    public static function situacao(?float $v,array $ind): string {
        if($v===null) return 'sem_dado';
        if($ind['meta_operador']===null || $ind['meta']===null) return 'sem_meta';
        $m=(float)$ind['meta'];
        return ($ind['meta_operador']==='>='?$v>=$m-1e-9:$v<=$m+1e-9)?'ok':'fora';
    }

    // ---------- Cálculo por período ----------
    /** @return array{0:?float,1:?string} valor e data da última medição usada */
    public static function valor(array $ind,?string $ini,?string $fim): array {
        $args=[(int)$ind['id']]; $w='';
        if($ind['agregacao']==='media') {
            if($ini) { $w.=' AND data_ref>=?'; $args[]=$ini; }
            if($fim) { $w.=' AND data_ref<=?'; $args[]=$fim; }
            $r=DB::one('SELECT AVG(valor) v,MAX(data_ref) d,COUNT(*) n FROM indicadores_ti_medicoes WHERE indicador_id=?'.$w,$args);
            return (int)($r['n']??0)>0?[(float)$r['v'],(string)$r['d']]:[null,null];
        }
        if($fim) { $w.=' AND data_ref<=?'; $args[]=$fim; }
        $r=DB::one('SELECT valor v,data_ref d FROM indicadores_ti_medicoes WHERE indicador_id=?'.$w.' ORDER BY data_ref DESC LIMIT 1',$args);
        return $r?[(float)$r['v'],(string)$r['d']]:[null,null];
    }
    /** Período anterior de mesma duração (só quando o período tem início). */
    public static function anterior(Periodo $p): ?array {
        if(!$p->inicio) return null;
        $ini=new \DateTimeImmutable($p->inicio); $fim=new \DateTimeImmutable($p->fim??date('Y-m-d'));
        $dias=(int)$ini->diff($fim)->days+1;
        return [$ini->modify('-'.$dias.' days')->format('Y-m-d'),$ini->modify('-1 day')->format('Y-m-d')];
    }
    public static function cartoes(Periodo $p): array {
        $ant=self::anterior($p); $out=[];
        foreach(self::catalogo() as $ind) {
            if($ind['grupo']!=='executivo') continue;
            [$v,$d]=self::valor($ind,$p->inicio,$p->fim);
            $prev=$ant?self::valor($ind,$ant[0],$ant[1])[0]:null;
            $out[]=['ind'=>$ind,'valor'=>$v,'data'=>$d,'texto'=>self::formatar($v,$ind),'meta'=>self::metaTexto($ind),'situacao'=>self::situacao($v,$ind),
                'variacao'=>($v!==null && $prev!==null)?$v-$prev:null,'anterior'=>$prev];
        }
        return $out;
    }
    /** Controles por status (rosca): última fotografia de cada status até o fim do período. */
    public static function controles(Periodo $p): array {
        $out=[];
        foreach(self::catalogo() as $ind) if($ind['grupo']==='controles') { [$v]=self::valor($ind,null,$p->fim); $r=preg_replace('/^Controles · /u','',$ind['nome']); $out[]=['rotulo'=>mb_strtoupper(mb_substr($r,0,1)).mb_substr($r,1),'codigo'=>$ind['codigo'],'valor'=>(int)round($v??0)]; }
        return $out;
    }
    /** Série diária das disponibilidades (gráfico de linhas). Sem período: últimos 60 dias com dado. */
    public static function serieDisponibilidade(Periodo $p): array {
        $ids=[]; foreach(self::SERIE_DISPONIBILIDADE as $c=>$_) { $i=DB::one('SELECT id FROM indicadores_ti WHERE codigo=?',[$c]); if($i) $ids[$c]=(int)$i['id']; }
        if(!$ids) return ['datas'=>[],'series'=>[]];
        $args=array_values($ids); $w='';
        if($p->inicio) { $w.=' AND m.data_ref>=?'; $args[]=$p->inicio; }
        if($p->fim) { $w.=' AND m.data_ref<=?'; $args[]=$p->fim; }
        $rows=DB::all('SELECT i.codigo,m.data_ref,m.valor FROM indicadores_ti_medicoes m JOIN indicadores_ti i ON i.id=m.indicador_id WHERE m.indicador_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.$w.' ORDER BY m.data_ref',$args);
        $datas=[]; $series=array_fill_keys(array_keys($ids),[]);
        foreach($rows as $r) { $datas[$r['data_ref']]=true; $series[$r['codigo']][$r['data_ref']]=(float)$r['valor']; }
        $datas=array_keys($datas); sort($datas);
        if(!$p->inicio) $datas=array_slice($datas,-60);
        return ['datas'=>$datas,'series'=>$series];
    }
    public static function riscos(): array {
        $abertos="situacao IN ('aberto','em_tratamento')";
        $porNivel=[]; foreach(self::CLASSIFICACOES as $c) $porNivel[$c]=0;
        foreach(DB::all("SELECT classificacao c,COUNT(*) n FROM riscos_ti WHERE $abertos GROUP BY classificacao") as $r) $porNivel[$r['c']]=(int)$r['n'];
        return ['porNivel'=>$porNivel,'abertos'=>array_sum($porNivel),
            'altos'=>DB::all("SELECT codigo,risco,nivel,classificacao,responsavel,situacao FROM riscos_ti WHERE $abertos AND classificacao IN ('Crítico','Alto') ORDER BY nivel DESC,codigo LIMIT 10"),
            'total'=>(int)DB::one('SELECT COUNT(*) n FROM riscos_ti')['n']];
    }
    /** Dados da aplicação no escopo atual (Parque::painel) + saúde da integração SGA. */
    public static function aplicacao(Periodo $p): array {
        $pn=Parque::painel($p,true);
        $sync=DB::one("SELECT inicio,situacao,linhas_lidas linhas FROM sincronizacoes WHERE fonte='sga' ORDER BY id DESC LIMIT 1");
        $horas=null;
        if($sync && $sync['inicio']) $horas=max(0,(int)floor((time()-strtotime((string)$sync['inicio']))/3600));
        // Ambíguos do escopo atual (mesmo critério da sincronização e dos bens sem número).
        $amb=['n'=>0];
        foreach(DB::all("SELECT payload_json FROM patrimonios_sga WHERE situacao='ambiguo'") as $r) if(CentrosCusto::patrimonioNoEscopo(json_decode((string)$r['payload_json'],true)?:[])) $amb['n']++;
        return ['stats'=>$pn['parqueStats'],'porSituacao'=>$pn['parquePorSituacao'],'compras'=>$pn['comprasEscopo']?:[],'projetos'=>$pn['custoProjetosPeriodo'],
            'semNumero'=>$pn['semNumeroPainel'],'ambiguos'=>(int)($amb['n']??0),'sync'=>$sync,'syncHoras'=>$horas];
    }
    public static function painel(Periodo $p): array {
        $pronto=self::pronto();
        return ['pronto'=>$pronto,'cartoes'=>$pronto?self::cartoes($p):[],'controles'=>$pronto?self::controles($p):[],'serie'=>$pronto?self::serieDisponibilidade($p):['datas'=>[],'series'=>[]],
            'riscos'=>$pronto?self::riscos():['porNivel'=>[],'abertos'=>0,'altos'=>[],'total'=>0],'app'=>self::aplicacao($p),'geradoEm'=>date('d/m/Y H:i:s')];
    }

    // ---------- Lançamentos ----------
    public static function lancar(array $in,?int $actor): void {
        $ind=self::indicador((string)($in['codigo']??''));
        $data=self::data((string)($in['data']??''));
        $valor=self::validarValor($ind,self::numero((string)($in['valor']??'')));
        $obs=mb_substr(trim((string)($in['observacao']??'')),0,300);
        DB::transaction(function() use($ind,$data,$valor,$obs,$actor) {
            $antes=DB::one('SELECT valor,observacao FROM indicadores_ti_medicoes WHERE indicador_id=? AND data_ref=?',[$ind['id'],$data]);
            DB::run('INSERT INTO indicadores_ti_medicoes(indicador_id,data_ref,valor,origem,observacao,registrado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor),origem=VALUES(origem),observacao=VALUES(observacao),registrado_por=VALUES(registrado_por)',[$ind['id'],$data,$valor,'manual',$obs,$actor]);
            DB::audit('indicadores_ti_medicoes',$ind['codigo'].'@'.$data,$antes?'alterar':'lancar',$antes,['valor'=>$valor,'observacao'=>$obs],$actor);
        });
    }
    public static function remover(int $id,?int $actor): void {
        $m=DB::one('SELECT m.*,i.codigo FROM indicadores_ti_medicoes m JOIN indicadores_ti i ON i.id=m.indicador_id WHERE m.id=?',[$id]);
        if(!$m) throw new RuntimeException('Medição não encontrada.');
        DB::transaction(function() use($m,$actor) { DB::run('DELETE FROM indicadores_ti_medicoes WHERE id=?',[$m['id']]); DB::audit('indicadores_ti_medicoes',$m['codigo'].'@'.$m['data_ref'],'remover',['valor'=>$m['valor'],'origem'=>$m['origem']],null,$actor); });
    }
    /** Somente administrador: meta e operador de um indicador com meta. */
    public static function salvarMeta(array $in,?int $actor): void {
        $ind=self::indicador((string)($in['codigo']??''));
        if($ind['grupo']!=='executivo') throw new RuntimeException('Este indicador não tem meta.');
        $op=(string)($in['operador']??''); if(!in_array($op,['>=','<='],true)) throw new RuntimeException('Operador de meta inválido.');
        $meta=self::validarValor($ind,self::numero((string)($in['meta']??'')));
        DB::run('UPDATE indicadores_ti SET meta_operador=?,meta=? WHERE id=?',[$op,$meta,$ind['id']]);
        DB::audit('indicadores_ti',$ind['codigo'],'meta',['operador'=>$ind['meta_operador'],'meta'=>$ind['meta']],['operador'=>$op,'meta'=>$meta],$actor);
    }
    public static function salvarRisco(array $r,?int $actor): void {
        $codigo=trim((string)($r['codigo']??'')); $texto=trim((string)($r['risco']??''));
        if(!preg_match('/^[A-Za-z0-9._-]{1,30}$/',$codigo)) throw new RuntimeException('Código de risco inválido (até 30 letras, números, ponto, hífen ou sublinhado).');
        if($texto==='' || mb_strlen($texto)>300) throw new RuntimeException('Descreva o risco '.$codigo.' (até 300 caracteres).');
        $nivel=(int)self::numero((string)($r['nivel']??'')); if($nivel<1 || $nivel>25) throw new RuntimeException('Nível do risco '.$codigo.' deve ficar entre 1 e 25 (impacto × probabilidade).');
        $cls=trim((string)($r['classificacao']??''));
        if($cls==='') $cls=$nivel>=20?'Crítico':($nivel>=12?'Alto':($nivel>=6?'Médio':'Baixo'));
        $mapa=['critico'=>'Crítico','crítico'=>'Crítico','alto'=>'Alto','medio'=>'Médio','médio'=>'Médio','baixo'=>'Baixo'];
        $cls=$mapa[mb_strtolower($cls)]??throw new RuntimeException('Classificação do risco '.$codigo.' inválida (Crítico, Alto, Médio ou Baixo).');
        $sit=mb_strtolower(trim((string)($r['situacao']??'aberto'))); $sit=str_replace([' ','ç','ã'],['_','c','a'],$sit===''?'aberto':$sit);
        if(!isset(self::SITUACOES_RISCO[$sit])) throw new RuntimeException('Situação do risco '.$codigo.' inválida (aberto, em tratamento, tratado ou aceito).');
        $resp=mb_substr(trim((string)($r['responsavel']??'')),0,120);
        $antes=DB::one('SELECT risco,nivel,classificacao,responsavel,situacao FROM riscos_ti WHERE codigo=?',[$codigo]);
        DB::run('INSERT INTO riscos_ti(codigo,risco,nivel,classificacao,responsavel,situacao,atualizado_por) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE risco=VALUES(risco),nivel=VALUES(nivel),classificacao=VALUES(classificacao),responsavel=VALUES(responsavel),situacao=VALUES(situacao),atualizado_por=VALUES(atualizado_por)',[$codigo,$texto,$nivel,$cls,$resp,$sit,$actor]);
        DB::audit('riscos_ti',$codigo,$antes?'alterar':'incluir',$antes,['risco'=>$texto,'nivel'=>$nivel,'classificacao'=>$cls,'responsavel'=>$resp,'situacao'=>$sit],$actor);
    }

    // ---------- CSV ----------
    /** Lê CSV (separador ; ou , detectado no cabeçalho, BOM UTF-8 removido, Windows-1252 convertido). */
    public static function lerCsv(string $conteudo,string $tipo): array {
        if(!isset(self::MODELOS[$tipo])) throw new RuntimeException('Tipo de importação inválido.');
        if(strlen($conteudo)>self::LIMITE_CSV_BYTES) throw new RuntimeException('Arquivo maior que 2 MB.');
        if(str_starts_with($conteudo,"\xEF\xBB\xBF")) $conteudo=substr($conteudo,3);
        if(!mb_check_encoding($conteudo,'UTF-8')) $conteudo=mb_convert_encoding($conteudo,'UTF-8','Windows-1252');
        $linhas=preg_split('/\r\n|\n|\r/',trim($conteudo));
        if(!$linhas || trim($linhas[0])==='') throw new RuntimeException('Arquivo vazio.');
        $sep=substr_count($linhas[0],';')>=substr_count($linhas[0],',')?';':',';
        $cab=array_map(static fn($c)=>mb_strtolower(trim($c)),str_getcsv($linhas[0],$sep,'"','\\'));
        $falta=array_diff(array_slice(self::MODELOS[$tipo],0,3),$cab);
        if($falta) throw new RuntimeException('Cabeçalho sem as colunas: '.implode(', ',$falta).'. Baixe o modelo.');
        $out=[];
        foreach(array_slice($linhas,1) as $n=>$l) {
            if(trim($l)==='') continue;
            if(count($out)>=self::LIMITE_CSV_LINHAS) throw new RuntimeException('Máximo de '.self::LIMITE_CSV_LINHAS.' linhas por arquivo.');
            $cols=str_getcsv($l,$sep,'"','\\'); $row=['_linha'=>$n+2];
            foreach($cab as $i=>$c) $row[$c]=trim((string)($cols[$i]??''));
            $out[]=$row;
        }
        if(!$out) throw new RuntimeException('Nenhuma linha de dados no arquivo.');
        return $out;
    }
    /** Valida tudo antes de gravar: com qualquer erro, nada é importado. */
    public static function importar(string $tipo,string $conteudo,?int $actor): int {
        $linhas=self::lerCsv($conteudo,$tipo); $erros=[]; $validas=[];
        $cat=[]; foreach(self::catalogo() as $i) $cat[$i['codigo']]=$i;
        foreach($linhas as $r) {
            try {
                if($tipo==='medicoes') {
                    $ind=$cat[$r['codigo']]??throw new RuntimeException('indicador desconhecido "'.mb_substr($r['codigo'],0,40).'"');
                    $validas[]=['ind'=>$ind,'data'=>self::data($r['data']),'valor'=>self::validarValor($ind,self::numero($r['valor'])),'obs'=>mb_substr($r['observacao']??'',0,300)];
                } else {
                    if(!preg_match('/^[A-Za-z0-9._-]{1,30}$/',$r['codigo'])) throw new RuntimeException('código de risco inválido');
                    $validas[]=$r;
                }
            } catch(RuntimeException $e) { $erros[]='linha '.$r['_linha'].': '.rtrim($e->getMessage(),'.'); }
            if(count($erros)>=10) break;
        }
        if($erros) throw new RuntimeException('Nada foi importado. Corrija: '.implode('; ',$erros).'.');
        return DB::transaction(function() use($tipo,$validas,$actor) {
            foreach($validas as $v) {
                if($tipo==='medicoes') DB::run('INSERT INTO indicadores_ti_medicoes(indicador_id,data_ref,valor,origem,observacao,registrado_por) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor),origem=VALUES(origem),observacao=VALUES(observacao),registrado_por=VALUES(registrado_por)',[$v['ind']['id'],$v['data'],$v['valor'],'csv',$v['obs'],$actor]);
                else self::salvarRisco($v,$actor); // valida nível, classificação e situação; um erro desfaz toda a importação
            }
            DB::audit('indicadores_ti','importacao','importar_'.$tipo,null,['linhas'=>count($validas)],$actor);
            return count($validas);
        });
    }
    public static function modelo(string $tipo): string {
        if(!isset(self::MODELOS[$tipo])) throw new RuntimeException('Modelo inválido.');
        $l=[implode(';',self::MODELOS[$tipo])];
        if($tipo==='medicoes') { $d=date('d/m/Y'); array_push($l,"disponibilidade_rede;$d;99,62;Zabbix - SLA hosts críticos","links_internet;$d;98,40;","cobertura_mfa;$d;90,8;Entra ID","problemas_criticos;$d;3;"); }
        else array_push($l,'R-001;Senhas de dispositivos armazenadas em planilha sem proteção;16;Alto;TI - Salinas Gold;aberto','R-002;Controlador de domínio único;15;Alto;TI - Salinas Gold;em tratamento');
        return "\xEF\xBB\xBF".implode("\r\n",$l)."\r\n";
    }
    public static function ultimasMedicoes(int $limite=40): array {
        return DB::all('SELECT m.id,m.data_ref,m.valor,m.origem,m.observacao,m.registrado_em,i.codigo,i.nome,i.unidade,i.agregacao,u.nome autor FROM indicadores_ti_medicoes m JOIN indicadores_ti i ON i.id=m.indicador_id LEFT JOIN usuarios u ON u.id=m.registrado_por ORDER BY m.registrado_em DESC,m.id DESC LIMIT '.(int)$limite);
    }
}
