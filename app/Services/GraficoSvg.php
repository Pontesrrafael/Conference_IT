<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Gráficos em SVG gerados no servidor, compatíveis com a CSP (sem script nem atributo style).
 * Cores por classe CSS (indicadores.css), para acompanhar o modo escuro. Cada gráfico traz legenda,
 * dica ao passar o mouse (<title>) e tabela de dados acessível, no padrão dos Indicadores do Salinas Access Gate.
 */
final class GraficoSvg {
    private static function n(float $v,int $dec=1): string { return number_format($v,$dec,',','.'); }
    private static function c(float $v): string { return rtrim(rtrim(sprintf('%.2F',$v),'0'),'.'); }

    /**
     * Linhas diárias (percentual). $series: chave => [Y-m-d => valor]; dias ausentes quebram a linha.
     * @param list<string> $datas
     */
    public static function linhas(string $titulo,array $datas,array $series,array $nomes,string $unidade='%'): string {
        if(!$datas) return '<p class="muted vazio">Sem medições no período. Lance ou importe em Dados dos indicadores.</p>';
        $vals=[]; foreach($series as $s) foreach($s as $v) $vals[]=$v;
        $min=min($vals); $max=max($vals);
        $topo=$unidade==='%'?100.0:max(1.0,ceil($max*1.1));
        $base=$unidade==='%'?max(0.0,min(90.0,floor(($min-2)/5)*5)):0.0;
        if($topo-$base<1) $base=$topo-1;
        $W=640; $H=230; $L=44; $R=12; $T=12; $B=30; $pw=$W-$L-$R; $ph=$H-$T-$B; $n=count($datas);
        $x=static fn(int $i)=>$L+($n===1?$pw/2:$i*$pw/($n-1));
        $y=static fn(float $v)=>$T+$ph-($v-$base)/($topo-$base)*$ph;
        $h='<figure class="viz">'.self::legenda($series,$nomes);
        $h.='<svg class="viz-svg" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="'.e($titulo).'"><title>'.e($titulo).'</title>';
        for($k=0;$k<=4;$k++) { $v=$base+($topo-$base)*$k/4; $yy=self::c($y($v)); $h.='<line class="viz-grade" x1="'.$L.'" x2="'.($W-$R).'" y1="'.$yy.'" y2="'.$yy.'"/><text class="viz-eixo" x="'.($L-6).'" y="'.self::c($y($v)+4).'" text-anchor="end">'.self::n($v,0).$unidade.'</text>'; }
        $salto=max(1,(int)ceil($n/8));
        foreach($datas as $i=>$d) if($i%$salto===0 || ($i===$n-1 && $i%$salto>=$salto/2)) $h.='<text class="viz-eixo" x="'.self::c($x($i)).'" y="'.($H-8).'" text-anchor="middle">'.e(substr($d,8,2).'/'.substr($d,5,2)).'</text>';
        $k=0;
        foreach($series as $chave=>$s) {
            $cls='serie-'.($k++);
            $trecho=[]; $paths='';
            foreach($datas as $i=>$d) {
                if(isset($s[$d])) { $trecho[]=self::c($x($i)).','.self::c($y($s[$d])); continue; }
                if(count($trecho)>1) $paths.='<polyline class="viz-linha '.$cls.'" points="'.implode(' ',$trecho).'"/>';
                $trecho=[];
            }
            if(count($trecho)>1) $paths.='<polyline class="viz-linha '.$cls.'" points="'.implode(' ',$trecho).'"/>';
            $h.=$paths;
            foreach($datas as $i=>$d) if(isset($s[$d])) $h.='<circle class="viz-ponto '.$cls.'" cx="'.self::c($x($i)).'" cy="'.self::c($y($s[$d])).'" r="3"><title>'.e(($nomes[$chave]??$chave).' · '.date('d/m/Y',strtotime($d)).': '.self::n($s[$d],2).$unidade).'</title></circle>';
        }
        $h.='</svg>';
        $h.='<details class="viz-tabela"><summary>Ver dados em tabela</summary><table><thead><tr><th>Data</th>';
        foreach($series as $chave=>$_) $h.='<th>'.e($nomes[$chave]??$chave).'</th>';
        $h.='</tr></thead><tbody>';
        foreach(array_reverse($datas) as $d) { $h.='<tr><td>'.e(date('d/m/Y',strtotime($d))).'</td>'; foreach($series as $s) $h.='<td>'.(isset($s[$d])?self::n($s[$d],2).$unidade:'—').'</td>'; $h.='</tr>'; }
        return $h.'</tbody></table></details></figure>';
    }

    /** Rosca com total no centro. @param list<array{rotulo:string,valor:int}> $itens */
    public static function rosca(string $titulo,array $itens,string $vazio): string {
        $total=array_sum(array_column($itens,'valor'));
        if($total<=0) return '<p class="muted vazio">'.$vazio.'</p>';
        $r=60; $circ=2*M_PI*$r; $off=0.0;
        $h='<figure class="viz viz-rosca"><svg class="viz-svg" viewBox="0 0 180 180" role="img" aria-label="'.e($titulo).'"><title>'.e($titulo).'</title>';
        $h.='<circle class="viz-rosca-fundo" cx="90" cy="90" r="'.$r.'"/>';
        foreach($itens as $k=>$it) {
            if($it['valor']<=0) continue;
            $len=$it['valor']/$total*$circ; $pct=$it['valor']/$total*100;
            $h.='<circle class="viz-fatia serie-'.$k.'" cx="90" cy="90" r="'.$r.'" stroke-dasharray="'.self::c($len).' '.self::c($circ-$len).'" stroke-dashoffset="'.self::c(-$off).'" transform="rotate(-90 90 90)"><title>'.e($it['rotulo'].': '.$it['valor'].' ('.self::n($pct).'%)').'</title></circle>';
            $off+=$len;
        }
        $h.='<text class="viz-rosca-total" x="90" y="92" text-anchor="middle">'.$total.'</text><text class="viz-eixo" x="90" y="110" text-anchor="middle">controles</text></svg>';
        $h.='<ul class="viz-legenda viz-legenda-coluna">';
        foreach($itens as $k=>$it) $h.='<li><span class="viz-chave serie-'.$k.'" aria-hidden="true"></span>'.e($it['rotulo']).' <strong>'.$it['valor'].'</strong> <span class="muted">('.self::n($it['valor']/$total*100).'%)</span></li>';
        return $h.'</ul></figure>';
    }

    /** Barras horizontais em SVG. @param list<array{rotulo:string,valor:float,texto?:string,classe?:string}> $itens */
    public static function barras(string $titulo,array $itens,string $vazio,int $largura=320): string {
        $max=$itens?max(array_column($itens,'valor')):0;
        if($max<=0) return '<p class="muted vazio">'.$vazio.'</p>';
        $lin=26; $W=$largura; $rot=min(150,(int)($largura*0.32)); $val=(int)max(40,min(150,$largura*0.3)); $H=count($itens)*$lin+4; $pw=$W-$rot-$val;
        $h='<svg class="viz-barras" width="'.$W.'" height="'.$H.'" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="'.e($titulo).'"><title>'.e($titulo).'</title>';
        foreach($itens as $i=>$it) {
            $y=$i*$lin+4; $w=$it['valor']>0?max(2,$it['valor']/$max*$pw):0; $txt=$it['texto']??self::n($it['valor'],0);
            $h.='<g><title>'.e($it['rotulo'].': '.$txt).'</title><text class="viz-rotulo" x="'.($rot-8).'" y="'.($y+15).'" text-anchor="end">'.e(mb_strimwidth($it['rotulo'],0,22,'…')).'</text>'
               .'<rect class="viz-barra '.e($it['classe']??'serie-0').'" x="'.$rot.'" y="'.$y.'" width="'.self::c($w).'" height="18" rx="3"/>'
               .'<text class="viz-valor" x="'.self::c($rot+$w+6).'" y="'.($y+14).'">'.e($txt).'</text></g>';
        }
        return $h.'</svg>';
    }
    public static function legenda(array $series,array $nomes): string {
        $h='<ul class="viz-legenda">'; $k=0;
        foreach(array_keys($series) as $c) $h.='<li><span class="viz-chave serie-'.($k++).'" aria-hidden="true"></span>'.e($nomes[$c]??$c).'</li>';
        return $h.'</ul>';
    }
}
