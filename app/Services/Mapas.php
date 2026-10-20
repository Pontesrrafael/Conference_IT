<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use RuntimeException;
final class Mapas {
    public static function mapa(int $id): array {
        $m=DB::one('SELECT * FROM mapas WHERE id=?',[$id]);
        if(!$m)throw new RuntimeException('Mapa não encontrado.');
        $m['meta']=json_decode($m['metadados_json'],true,512,JSON_THROW_ON_ERROR);return $m;
    }
    public static function importar(array $p,array $file,int $actor): int {
        $name=Inventario::text($p,'nome');$unit=Inventario::text($p,'unidade');
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE?'O arquivo excede o limite de 256 MB.':'Selecione um arquivo .tif ou .tiff válido.');
        if(!preg_match('/\.(tif|tiff)$/i',(string)($file['name']??''))||!is_uploaded_file($file['tmp_name']??''))throw new RuntimeException('Envie uma imagem TIFF pelo formulário.');
        $size=filesize($file['tmp_name']);if(!$size||$size>256*1024*1024)throw new RuntimeException('O TIFF deve ter até 256 MB.');
        $f=fopen($file['tmp_name'],'rb');$magic=fread($f,4);fclose($f);
        if(!in_array(bin2hex($magic),['49492a00','4d4d002a','49492b00','4d4d002b'],true))throw new RuntimeException('O conteúdo enviado não é um TIFF.');
        $key=bin2hex(random_bytes(16));$dir=ROOT.'/storage/mapas';
        if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Não foi possível preparar o armazenamento do mapa.');
        $original=$dir.'/'.$key.'.tif';$png=$dir.'/'.$key.'.png';
        if(!move_uploaded_file($file['tmp_name'],$original))throw new RuntimeException('Não foi possível salvar o TIFF.');
        try {
            $meta=self::converter($original,$png);
            return DB::transaction(function()use($p,$name,$unit,$key,$meta,$file,$actor){
                DB::run('INSERT INTO mapas(nome,unidade,arquivo,original_nome,metadados_json,criado_por) VALUES(?,?,?,?,?,?)',[$name,$unit,$key,mb_substr(basename((string)$file['name']),0,200),json_encode($meta,JSON_THROW_ON_ERROR),$actor]);
                $id=(int)DB::pdo()->lastInsertId();DB::audit('mapas',$id,'importar_geotiff',null,['nome'=>$name,'unidade'=>$unit,'metadados'=>$meta],$actor);return $id;
            });
        }catch(\Throwable $e){@unlink($original);@unlink($png);throw $e;}
    }
    public static function converter(string $source,string $png): array {
        $python=getenv('MAP_PYTHON')?:'C:/Users/rafae/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
        if(!is_file($python))throw new RuntimeException('Leitor de GeoTIFF indisponível. Configure MAP_PYTHON no ambiente local.');
        set_time_limit(180);
        $proc=proc_open([$python,ROOT.'/bin/geotiff.py',$source,$png],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',ROOT.'/storage/logs/geotiff.log','a']],$pipes,ROOT,null,['bypass_shell'=>true]);
        if(!is_resource($proc))throw new RuntimeException('Não foi possível iniciar a leitura do GeoTIFF.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($proc);
        $meta=json_decode((string)$out,true);
        if($code!==0||!is_array($meta)||isset($meta['erro'])){error_log('GeoTIFF: '.($meta['erro']??'Falha no leitor').PHP_EOL,3,ROOT.'/storage/logs/geotiff.log');throw new RuntimeException('Não foi possível ler o GeoTIFF. Confira se o arquivo contém projeção e coordenadas válidas; detalhes em storage/logs/geotiff.log.');}
        if(!is_file($png)||count($meta['bounds']??[])!==4)throw new RuntimeException('Prévia geográfica inválida.');
        return $meta;
    }
    public static function poligono(string $json,array $bounds): array {
        try{$pts=json_decode($json,true,128,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new RuntimeException('Desenhe uma área válida no mapa.');}
        if(!is_array($pts)||count($pts)<3||count($pts)>100)throw new RuntimeException('A área deve ter entre 3 e 100 vértices.');
        $clean=[];
        foreach($pts as $p){if(!is_array($p)||count($p)!==2||!is_numeric($p[0])||!is_numeric($p[1]))throw new RuntimeException('Coordenada inválida.');$x=(float)$p[0];$y=(float)$p[1];if(!is_finite($x)||!is_finite($y)||$x<0||$y<0||$x>1||$y>1)throw new RuntimeException('Desenhe a área dentro da imagem.');$clean[]=[round($x,8),round($y,8)];}
        if(count(array_unique(array_map('json_encode',$clean)))!==count($clean))throw new RuntimeException('A área contém vértices repetidos.');
        $cross=static fn($a,$b,$c)=>($b[0]-$a[0])*($c[1]-$a[1])-($b[1]-$a[1])*($c[0]-$a[0]);
        $area=0;$n=count($clean);
        for($i=0;$i<$n;$i++){$a=$clean[$i];$b=$clean[($i+1)%$n];$area+=$a[0]*$b[1]-$b[0]*$a[1];for($j=$i+1;$j<$n;$j++){if($j===$i+1||($i===0&&$j===$n-1))continue;$c=$clean[$j];$d=$clean[($j+1)%$n];$c1=$cross($a,$b,$c);$c2=$cross($a,$b,$d);$c3=$cross($c,$d,$a);$c4=$cross($c,$d,$b);if($c1*$c2<=0&&$c3*$c4<=0&&max(min($a[0],$b[0]),min($c[0],$d[0]))<=min(max($a[0],$b[0]),max($c[0],$d[0]))&&max(min($a[1],$b[1]),min($c[1],$d[1]))<=min(max($a[1],$b[1]),max($c[1],$d[1])))throw new RuntimeException('As bordas da área não podem se cruzar.');}}
        if(abs($area)<0.00000001)throw new RuntimeException('A área desenhada é muito pequena ou está em linha reta.');
        [$west,$south,$east,$north]=$bounds;$coords=[];foreach($clean as [$x,$y])$coords[]=[$west+$x*($east-$west),$north-$y*($north-$south)];$coords[]=$coords[0];
        return ['pontos'=>$clean,'geojson'=>['type'=>'Polygon','coordinates'=>[$coords]]];
    }
    public static function salvarArea(array $p,int $actor): int {
        $map=self::mapa((int)($p['mapa_id']??0));$name=Inventario::text($p,'nome');$desc=trim((string)($p['descricao']??''));if(mb_strlen($desc)>2000)throw new RuntimeException('Descrição com até 2.000 caracteres.');
        $poly=self::poligono((string)($p['poligono']??''),$map['meta']['bounds']);
        return DB::transaction(function()use($p,$map,$name,$desc,$poly,$actor){
            $id=(int)($p['area_id']??0);$old=$id?DB::one('SELECT * FROM mapas_areas WHERE id=? AND mapa_id=? FOR UPDATE',[$id,$map['id']]):null;
            if($id&&!$old)throw new RuntimeException('Área não pertence a este mapa.');
            $values=[$name,$desc,json_encode($poly['pontos']),json_encode($poly['geojson'])];
            if($old)DB::run('UPDATE mapas_areas SET nome=?,descricao=?,poligono_json=?,geojson=? WHERE id=?',[...$values,$id]);
            else{DB::run('INSERT INTO mapas_areas(nome,descricao,poligono_json,geojson,mapa_id,criado_por) VALUES(?,?,?,?,?,?)',[...$values,$map['id'],$actor]);$id=(int)DB::pdo()->lastInsertId();}
            DB::audit('mapas_areas',$id,$old?'editar':'criar',$old,['nome'=>$name,'poligono'=>$poly],$actor);return $id;
        });
    }
    public static function vincular(array $p,int $actor): void {
        $area=DB::one('SELECT id FROM mapas_areas WHERE id=? AND mapa_id=?',[(int)($p['area_id']??0),(int)($p['mapa_id']??0)]);if(!$area)throw new RuntimeException('Selecione uma área deste mapa.');
        $type=(string)($p['tipo']??'');$tables=['ativo'=>['ativos','ativo_id'],'projeto'=>['projetos','projeto_id'],'compra'=>['compras_origem','compra_id']];if(!isset($tables[$type]))throw new RuntimeException('Selecione patrimônio, compra ou projeto.');
        [$table,$column]=$tables[$type];$id=(int)($p['referencia_id']??0);$obs=Inventario::text($p,'observacao',500);
        DB::transaction(function()use($table,$column,$type,$id,$obs,$area,$actor){
            $ref=DB::one("SELECT id FROM $table WHERE id=? FOR UPDATE",[$id]);if(!$ref)throw new RuntimeException('Item ou projeto não encontrado.');
            $old=DB::one('SELECT * FROM mapas_vinculos WHERE '.$column.'=?'.($type==='ativo'?'':' AND area_id=?').' FOR UPDATE',$type==='ativo'?[$id]:[$id,$area['id']]);
            DB::run('INSERT INTO mapas_vinculos(area_id,'.$column.',observacao,atualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE area_id=VALUES(area_id),observacao=VALUES(observacao),atualizado_por=VALUES(atualizado_por)',[$area['id'],$id,$obs,$actor]);
            DB::audit('mapas_vinculos',$type.':'.$id,'apontar_area',$old,['area_id'=>$area['id'],'observacao'=>$obs],$actor);
        });
    }
    public static function desvincular(array $p,int $actor): void {
        $reason=Inventario::text($p,'observacao',500);
        DB::transaction(function()use($p,$actor,$reason){$old=DB::one('SELECT v.* FROM mapas_vinculos v JOIN mapas_areas a ON a.id=v.area_id WHERE v.id=? AND a.mapa_id=? FOR UPDATE',[(int)($p['vinculo_id']??0),(int)($p['mapa_id']??0)]);if(!$old)throw new RuntimeException('Vínculo não encontrado neste mapa.');DB::run('DELETE FROM mapas_vinculos WHERE id=?',[$old['id']]);DB::audit('mapas_vinculos',$old['id'],'desvincular_area',$old,['motivo'=>$reason],$actor);});
    }
    public static function detalhe(int $id): array {
        $map=self::mapa($id);$areas=DB::all('SELECT * FROM mapas_areas WHERE mapa_id=? ORDER BY nome',[$id]);
        $links=DB::all("SELECT v.*,a.nome area_nome,pr.nome projeto_nome,pr.codigo projeto_codigo,at.patrimonio,p.nome produto,c.origem_item_id FROM mapas_vinculos v JOIN mapas_areas a ON a.id=v.area_id LEFT JOIN projetos pr ON pr.id=v.projeto_id LEFT JOIN ativos at ON at.id=v.ativo_id LEFT JOIN compras_origem c ON c.id=v.compra_id LEFT JOIN produtos p ON p.id=COALESCE(at.produto_id,c.produto_id) WHERE a.mapa_id=? ORDER BY a.nome,v.id",[$id]);
        $assets=[];foreach(Parque::itens() as $a)$assets[$a['id']]=$a;
        foreach($areas as &$area){$area['pontos']=json_decode($area['poligono_json'],true);$area['vinculos']=array_values(array_filter($links,fn($v)=>(int)$v['area_id']===(int)$area['id']));$area['custo_ativos']='0.00';$area['custo_projetos']='0.00';$area['sem_custo']=0;foreach($area['vinculos'] as $v){if($v['ativo_id']){$val=$assets[$v['ativo_id']]['valor_bem']??null;$area['sem_custo']+=($val===null?1:0);$area['custo_ativos']=bcadd($area['custo_ativos'],$val??'0',2);}if($v['projeto_id']){$cost=DB::one('SELECT COALESCE(SUM(valor_atribuido),0) custo FROM vw_apontamentos_custos WHERE projeto_id=?',[$v['projeto_id']])['custo'];$area['custo_projetos']=bcadd($area['custo_projetos'],(string)$cost,2);}}}unset($area);
        return ['mapa'=>$map,'areasMapa'=>$areas];
    }
    public static function arquivo(int $id,bool $original=false): never {
        $m=self::mapa($id);$file=ROOT.'/storage/mapas/'.$m['arquivo'].($original?'.tif':'.png');if(!is_file($file))throw new RuntimeException('Arquivo do mapa indisponível.');header('Content-Type: '.($original?'image/tiff':'image/png'));header('Content-Length: '.filesize($file));if($original)header('Content-Disposition: attachment; filename="mapa-'.$id.'.tif"');readfile($file);exit;
    }
    public static function exportar(int $id): never {
        $d=self::detalhe($id);$features=[];foreach($d['areasMapa'] as $a)$features[]=['type'=>'Feature','geometry'=>json_decode($a['geojson'],true),'properties'=>['id'=>$a['id'],'nome'=>$a['nome'],'mapa'=>$d['mapa']['nome'],'descricao'=>$a['descricao'],'vinculos'=>$a['vinculos'],'custo_ativos'=>$a['custo_ativos'],'custo_projetos'=>$a['custo_projetos']]];header('Content-Type: application/geo+json; charset=UTF-8');header('Content-Disposition: attachment; filename="mapa-'.$id.'-areas.geojson"');echo json_encode(['type'=>'FeatureCollection','features'=>$features],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
}
