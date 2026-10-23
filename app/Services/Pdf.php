<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
/**
 * Gerador de PDF mínimo, sem dependências (XAMPP sem composer).
 * - Fontes padrão do PDF (Helvetica / Helvetica-Bold) em WinAnsiEncoding: acentos do português via Windows-1252.
 * - API em coordenadas de cima para baixo (pontos), página A4 paisagem por padrão.
 * - Métricas de largura das fontes embutidas para alinhar, quebrar e truncar textos.
 */
final class Pdf {
    private const W=[
        'H'=>[278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
        'HB'=>[278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];
    private array $pages=[];
    private int $atual=-1;
    /** @var list<array{dados:string,w:int,h:int}> imagens PNG RGB (IDAT incorporado com preditor PNG) */
    private array $imagens=[];
    private array $imagensPorArquivo=[];
    public function __construct(public readonly float $largura=841.89,public readonly float $altura=595.28) {}
    public function novaPagina(): void { $this->pages[]=''; $this->atual=count($this->pages)-1; }
    public function paginas(): int { return count($this->pages); }
    /** Converte UTF-8 para Windows-1252 (WinAnsi); caracteres fora da tabela viram "?". */
    public static function cp1252(string $s): string {
        $s=str_replace(["\u{2013}","\u{2014}","\u{2026}","\u{2019}","\u{201C}","\u{201D}","\u{00A0}"],['-','-','...',"'",'"','"',' '],$s);
        $out=@iconv('UTF-8','Windows-1252//TRANSLIT',$s);
        if($out===false) $out=mb_convert_encoding($s,'Windows-1252','UTF-8');
        return preg_replace('/[\x00-\x1F]/',' ',(string)$out);
    }
    public function larguraTexto(string $utf8,float $tam,bool $negrito=false): float {
        $w=0; $tab=self::W[$negrito?'HB':'H'];
        foreach(str_split(self::cp1252($utf8)) as $c) { $o=ord($c); $w+=$o>=32?($tab[$o-32]?:556):0; }
        return $w*$tam/1000;
    }
    /** Corta o texto para caber na largura, com reticências. */
    public function ajustar(string $utf8,float $tam,bool $negrito,float $max): string {
        if($this->larguraTexto($utf8,$tam,$negrito)<=$max) return $utf8;
        $chars=mb_str_split($utf8);
        while($chars && $this->larguraTexto(implode('',$chars).'...',$tam,$negrito)>$max) array_pop($chars);
        return rtrim(implode('',$chars)).'...';
    }
    /** Quebra o texto em linhas que caibam na largura. */
    public function quebrar(string $utf8,float $tam,bool $negrito,float $max): array {
        $linhas=[]; $atual='';
        foreach(preg_split('/\s+/u',trim($utf8)) as $p) {
            $teste=$atual===''?$p:$atual.' '.$p;
            if($this->larguraTexto($teste,$tam,$negrito)<=$max || $atual==='') $atual=$teste; else { $linhas[]=$atual; $atual=$p; }
        }
        if($atual!=='') $linhas[]=$atual;
        return $linhas;
    }
    private function cor(array $rgb,bool $preenchimento): string {
        return sprintf('%.3F %.3F %.3F %s',$rgb[0]/255,$rgb[1]/255,$rgb[2]/255,$preenchimento?'rg':'RG');
    }
    public static function hex(string $h): array { $h=ltrim($h,'#'); return [hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))]; }
    /** Texto com linha de base em $y (de cima para baixo). Alinhamento L, R ou C dentro de $caixa. */
    public function texto(float $x,float $y,string $utf8,float $tam=9,bool $negrito=false,array $rgb=[33,33,33],string $alinh='L',float $caixa=0): void {
        if($this->atual<0) throw new RuntimeException('PDF sem página.');
        $w=$this->larguraTexto($utf8,$tam,$negrito);
        if($alinh==='R') $x+=$caixa-$w; elseif($alinh==='C') $x+=($caixa-$w)/2;
        $s=strtr(self::cp1252($utf8),['\\'=>'\\\\','('=>'\\(',')'=>'\\)']);
        $this->pages[$this->atual].=sprintf("BT %s /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",$this->cor($rgb,true),$negrito?'F2':'F1',$tam,$x,$this->altura-$y,$s);
    }
    public function retangulo(float $x,float $y,float $w,float $h,?array $preench=null,?array $borda=null,float $esp=0.5): void {
        $op=$preench&&$borda?'B':($preench?'f':'S');
        $this->pages[$this->atual].=($preench?$this->cor($preench,true).' ':'').($borda?$this->cor($borda,false).' '.sprintf('%.2F w ',$esp):'').sprintf("%.2F %.2F %.2F %.2F re %s\n",$x,$this->altura-$y-$h,$w,$h,$op);
    }
    /** Polígono preenchido; pontos em coordenadas de cima para baixo: [[x,y],[x,y],...]. */
    public function poligono(array $pontos,array $rgb): void {
        if(count($pontos)<3) return;
        $ops=[]; foreach(array_values($pontos) as $i=>[$x,$y]) $ops[]=sprintf('%.2F %.2F %s',$x,$this->altura-$y,$i===0?'m':'l');
        $this->pages[$this->atual].=$this->cor($rgb,true).' '.implode(' ',$ops)." h f\n";
    }
    /**
     * Texto girado 90° (lido de baixo para cima), como a razão social na faixa lateral do timbrado.
     * $x é a linha de base (as letras ficam à esquerda dela); centralizado verticalmente em torno de $yCentro.
     */
    public function textoVertical(float $x,float $yCentro,string $utf8,float $tam=7,bool $negrito=false,array $rgb=[33,33,33],float $espacamento=0): void {
        if($this->atual<0) throw new RuntimeException('PDF sem página.');
        $cp=self::cp1252($utf8);
        $w=$this->larguraTexto($utf8,$tam,$negrito)+$espacamento*max(0,strlen($cp)-1);
        $s=strtr($cp,['\\'=>'\\\\','('=>'\\(',')'=>'\\)']);
        $this->pages[$this->atual].=sprintf("BT %s /%s %.2F Tf %.2F Tc 0 1 -1 0 %.2F %.2F Tm (%s) Tj 0 Tc ET\n",$this->cor($rgb,true),$negrito?'F2':'F1',$tam,$espacamento,$x,$this->altura-($yCentro+$w/2),$s);
    }
    public function linha(float $x1,float $y1,float $x2,float $y2,array $rgb=[200,200,200],float $esp=0.5): void {
        $this->pages[$this->atual].=sprintf("%s %.2F w %.2F %.2F m %.2F %.2F l S\n",$this->cor($rgb,false),$esp,$x1,$this->altura-$y1,$x2,$this->altura-$y2);
    }
    /**
     * Desenha um PNG RGB 8 bits não entrelaçado (sem decodificar: o PDF usa FlateDecode com preditor PNG).
     * Largura em pontos; altura proporcional quando $h=0. Retorna a altura usada.
     */
    public function imagemPng(string $arquivo,float $x,float $y,float $w,float $h=0): float {
        if(!isset($this->imagensPorArquivo[$arquivo])) {
            $bin=@file_get_contents($arquivo);
            if($bin===false || !str_starts_with($bin,"\x89PNG\r\n\x1a\n")) throw new RuntimeException('Imagem do PDF indisponível.');
            $pos=8; $ihdr=null; $idat='';
            while($pos+8<=strlen($bin)) {
                $len=unpack('N',substr($bin,$pos,4))[1]; $tipo=substr($bin,$pos+4,4); $dados=substr($bin,$pos+8,$len);
                if($tipo==='IHDR') $ihdr=unpack('Nw/Nh/Cbits/Ccor/Ccomp/Cfiltro/Centrelaca',$dados);
                elseif($tipo==='IDAT') $idat.=$dados;
                elseif($tipo==='IEND') break;
                $pos+=12+$len;
            }
            if(!$ihdr || $ihdr['bits']!==8 || $ihdr['cor']!==2 || $ihdr['entrelaca']!==0 || $idat==='') throw new RuntimeException('O PNG do PDF precisa ser RGB 8 bits, sem transparência e não entrelaçado.');
            $this->imagens[]=['dados'=>$idat,'w'=>$ihdr['w'],'h'=>$ihdr['h']];
            $this->imagensPorArquivo[$arquivo]=count($this->imagens);
        }
        $n=$this->imagensPorArquivo[$arquivo]; $img=$this->imagens[$n-1];
        if($h<=0) $h=$w*$img['h']/$img['w'];
        $this->pages[$this->atual].=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n",$w,$h,$x,$this->altura-$y-$h,$n);
        return $h;
    }
    /** Executa uma função em cada página (ex.: rodapé "página X de Y" depois de saber o total). */
    public function emCadaPagina(callable $fn): void { foreach(array_keys($this->pages) as $i) { $this->atual=$i; $fn($i+1,count($this->pages)); } }
    public function gerar(string $titulo=''): string {
        if(!$this->pages) $this->novaPagina();
        $objs=[]; $n=count($this->pages);
        $objs[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $kids=[]; for($i=0;$i<$n;$i++) $kids[]=(5+2*$i).' 0 R';
        $primeiraImg=5+2*$n; $xobj='';
        foreach($this->imagens as $k=>$img) {
            $objs[$primeiraImg+$k]='<< /Type /XObject /Subtype /Image /Width '.$img['w'].' /Height '.$img['h'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns '.$img['w'].' >> /Length '.strlen($img['dados']).' >>'."\nstream\n".$img['dados']."\nendstream";
            $xobj.=' /Im'.($k+1).' '.($primeiraImg+$k).' 0 R';
        }
        $objs[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$n.' >>';
        $objs[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $zip=function_exists('gzcompress');
        foreach($this->pages as $i=>$c) {
            $page=5+2*$i; $cont=$page+1;
            $objs[$page]=sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',$this->largura,$this->altura,$xobj!==''?' /XObject <<'.$xobj.' >>':'',$cont);
            $data=$zip?gzcompress($c,6):$c;
            $objs[$cont]='<< /Length '.strlen($data).($zip?' /Filter /FlateDecode':'').' >>'."\nstream\n".$data."\nendstream";
        }
        $info=count($objs)+1;
        $objs[$info]='<< /Title ('.strtr(self::cp1252($titulo),['\\'=>'\\\\','('=>'\\(',')'=>'\\)']).') /Producer ('.strtr(self::cp1252(nome_app()),['\\'=>'\\\\','('=>'\\(',')'=>'\\)']).') /CreationDate (D:'.date('YmdHis').') >>';
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off=[];
        ksort($objs);
        foreach($objs as $k=>$o) { $off[$k]=strlen($pdf); $pdf.=$k." 0 obj\n".$o."\nendobj\n"; }
        $xref=strlen($pdf); $total=max(array_keys($objs))+1;
        $pdf.="xref\n0 $total\n0000000000 65535 f \n";
        for($k=1;$k<$total;$k++) $pdf.=sprintf("%010d 00000 n \n",$off[$k]);
        $pdf.="trailer\n<< /Size $total /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
