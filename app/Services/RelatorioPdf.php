<?php
declare(strict_types=1);
namespace App\Services;
/**
 * Relatório de gastos por centro de custo em PDF (A4 paisagem), com identidade Salinas Gold
 * (#024B37 verde, #FFCD00 amarelo, #C6D53E verde-claro, #707070 cinza). Fonte padrão do PDF (Helvetica).
 */
final class RelatorioPdf {
    public const LIMITE_LINHAS=2000;
    private const VERDE='#024B37', AMARELO='#FFCD00', VERDE_CLARO='#C6D53E', CINZA='#707070', ZEBRA='#F2F5F3';
    private Pdf $pdf;
    private float $y=0;
    private const M=36; // margem
    private function __construct(private array $r,private Periodo $periodo,private string $usuario,private string $geradoEm) { $this->pdf=new Pdf(); }

    public static function gerar(array $relatorio,Periodo $periodo,string $usuario,?\DateTimeImmutable $agora=null): string {
        $agora??=new \DateTimeImmutable('now',new \DateTimeZone('America/Cuiaba'));
        $doc=new self($relatorio,$periodo,$usuario,$agora->format('d/m/Y H:i'));
        return $doc->montar();
    }
    private static function brl(mixed $v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
    private function c(string $hex): array { return Pdf::hex($hex); }
    private function larguraUtil(): float { return $this->pdf->largura-2*self::M; }

    private function cabecalho(bool $completo): void {
        $p=$this->pdf; $p->novaPagina();
        $alt=$completo?64:34;
        $p->retangulo(0,0,$p->largura,$alt,$this->c(self::VERDE));
        $p->retangulo(0,$alt,$p->largura,3,$this->c(self::AMARELO));
        // Logotipo oficial (horizontal) sobre placa branca, como na interface; sem alteração de cor.
        $placaH=$completo?48:26; $placaY=($alt-$placaH)/2; $pad=$completo?6:3;
        $logoH=$placaH-2*$pad; $logoW=$logoH*1200/401;
        $p->retangulo(self::M,$placaY,$logoW+2*$pad+4,$placaH,[255,255,255]);
        $p->imagemPng(ROOT.'/app/Assets/logo-salinas-gold-pdf.png',self::M+$pad+2,$placaY+$pad,$logoW,$logoH);
        if($completo) $p->texto(self::M+$logoW+2*$pad+16,$alt/2+4,'Tecnologia da Informação · Parque de TI',9,false,[255,255,255]);
        $p->texto(0,$completo?30:22,'Relatório de gastos por centro de custo',$completo?13:9,true,[255,255,255],'R',$p->largura-self::M);
        if($completo) $p->texto(0,46,$this->periodo->rotulo(),9,false,$this->c(self::VERDE_CLARO),'R',$p->largura-self::M);
        $this->y=$alt+20;
    }
    private function espaco(float $h): void { if($this->y+$h>$this->pdf->altura-48) $this->cabecalho(false); }
    private function titulo(string $t,string $sub=''): void {
        $this->espaco(40);
        $this->pdf->texto(self::M,$this->y+10,$t,12,true,$this->c(self::VERDE));
        $this->pdf->retangulo(self::M,$this->y+15,40,2,$this->c(self::AMARELO));
        $this->y+=24;
        if($sub!=='') { foreach($this->pdf->quebrar($sub,7.5,false,$this->larguraUtil()) as $l) { $this->pdf->texto(self::M,$this->y,$l,7.5,false,$this->c(self::CINZA)); $this->y+=10; } $this->y+=2; }
    }
    /** @param list<array{0:string,1:float,2:string}> $cols título, largura relativa, alinhamento */
    private function tabela(array $cols,array $linhas,array $negritoUltima=[]): void {
        $p=$this->pdf; $total=array_sum(array_column($cols,1)); $util=$this->larguraUtil();
        $ws=array_map(static fn($c)=>$c[1]/$total*$util,$cols);
        $cab=function() use($p,$cols,$ws,$util): void {
            $p->retangulo(self::M,$this->y,$util,16,$this->c(self::VERDE));
            $x=self::M; foreach($cols as $i=>$c) { $p->texto($x+4,$this->y+11,$p->ajustar(mb_strtoupper($c[0]),7,true,$ws[$i]-8),7,true,[255,255,255],$c[2]==='R'?'R':'L',$ws[$i]-8); $x+=$ws[$i]; }
            $this->y+=16;
        };
        $this->espaco(32); $cab();
        foreach($linhas as $n=>$cells) {
            if($this->y+14>$p->altura-48) { $this->cabecalho(false); $cab(); }
            $ultima=$negritoUltima && $n===count($linhas)-1;
            if($n%2===1 || $ultima) $p->retangulo(self::M,$this->y,$util,14,$ultima?$this->c('#E6EFE9'):$this->c(self::ZEBRA));
            $x=self::M;
            foreach($cols as $i=>$c) { $txt=$p->ajustar((string)($cells[$i]??''),7.5,$ultima,$ws[$i]-8); $p->texto($x+4,$this->y+10,$txt,7.5,$ultima,[33,33,33],$c[2]==='R'?'R':'L',$ws[$i]-8); $x+=$ws[$i]; }
            $this->y+=14;
        }
        $p->linha(self::M,$this->y,self::M+$util,$this->y,$this->c(self::VERDE),0.8);
        $this->y+=10;
    }
    private function montar(): string {
        $p=$this->pdf; $r=$this->r; $t=$r['total']??[];
        $this->cabecalho(true);
        // Identificação
        $p->texto(self::M,$this->y,'Gerado em '.$this->geradoEm.' (Horário de Mato Grosso – UTC-4) por '.$this->usuario.' · '.count($r['origens']).' centro(s) de custo · Fonte: Minerion SGA (compras importadas)',8,false,$this->c(self::CINZA));
        $this->y+=16;
        if(!$r['origens']) { $p->texto(self::M,$this->y+10,'Nenhum centro de custo selecionado.',11,true,$this->c(self::VERDE)); return $this->finalizar(); }
        // Resumo em cartões
        $cards=[['Total do pedido · sem dupla contagem',self::brl($t['total']??0),($t['linhas']??0).' linhas de compra'],['Com NF positiva',self::brl($t['total_nf']??0),($t['linhas_nf']??0).' linhas'],['Compras sem patrimônio',self::brl($t['sem_patrimonio']??0),($t['sem_patrimonio_n']??0).' linhas sem ativo vinculado'],['Centros de custo',(string)count($r['origens']),'Selecionados no relatório']];
        $gap=10; $cw=($this->larguraUtil()-3*$gap)/4;
        foreach($cards as $i=>[$rot,$val,$sub]) {
            $x=self::M+$i*($cw+$gap);
            $p->retangulo($x,$this->y,$cw,54,[250,251,250],$this->c('#D5DED8'),0.6);
            $p->retangulo($x,$this->y,$cw,3,$this->c($i===0?self::AMARELO:self::VERDE_CLARO));
            $p->texto($x+8,$this->y+16,$p->ajustar($rot,7.5,false,$cw-16),7.5,false,$this->c(self::CINZA));
            $p->texto($x+8,$this->y+34,$val,14,true,$this->c(self::VERDE));
            $p->texto($x+8,$this->y+46,$p->ajustar($sub,7,false,$cw-16),7,false,$this->c(self::CINZA));
        }
        $this->y+=70;
        // Por centro de custo
        $this->titulo('Gastos por centro de custo','Custo de compra pelo pedido no SGA (canceladas fora), com ou sem patrimônio. Um CC com filhos pode conter outro CC selecionado; a soma das linhas pode passar do total geral, que conta cada compra uma vez. Patrimônios excluem baixados.');
        $rows=[];
        foreach($r['origens'] as $o) $rows[]=[$o['rotulo'],(string)($o['linhas']??0),self::brl($o['total']??0),self::brl($o['total_nf']??0),self::brl($o['sem_patrimonio']??0),self::brl($o['ativos']['valor']).' ('.$o['ativos']['n'].')',self::brl($o['sem_numero']['valor']).' ('.$o['sem_numero']['n'].')'];
        $rows[]=['Total geral (sem dupla contagem)',(string)($t['linhas']??0),self::brl($t['total']??0),self::brl($t['total_nf']??0),self::brl($t['sem_patrimonio']??0),'',''];
        $this->tabela([['Centro de custo',3.4,'L'],['Linhas',0.7,'R'],['Total do pedido',1.3,'R'],['Com NF positiva',1.3,'R'],['Sem patrimônio',1.3,'R'],['Patrimônios (qtd.)',1.4,'R'],['Bens sem número (qtd.)',1.5,'R']],$rows,[true]);
        // Mensal
        if($r['meses']) {
            $this->titulo('Evolução mensal · total do pedido');
            $cols=[['Mês',1.0,'L']]; foreach($r['origens'] as $o) $cols[]=['CC '.$o['codigo'],1.2,'R']; $cols[]=['Total (sem dupla contagem)',1.6,'R'];
            $rows=[];
            foreach($r['meses'] as $mes) { $l=[$mes==='sem data'?$mes:substr($mes,5,2).'/'.substr($mes,0,4)]; foreach($r['origens'] as $o) $l[]=isset($r['mensal'][$o['chave']][$mes])?self::brl($r['mensal'][$o['chave']][$mes]):'—'; $l[]=self::brl($r['totalMes'][$mes]??0); $rows[]=$l; }
            $this->tabela($cols,$rows);
        }
        // Linhas
        $this->titulo('Linhas de compra',$r['detalheLimitado']?'Exibindo as '.number_format(self::LIMITE_LINHAS,0,',','.').' mais recentes. A exportação CSV traz todas.':'');
        $rows=[];
        foreach($r['linhas'] as $l) $rows[]=[substr((string)$l['processo_data'],0,10)?date('d/m/Y',strtotime((string)$l['processo_data'])):'—',$l['produto'],$l['requisitante'],trim(($l['cc_sga']??'').' '.($l['cc_nome']??'')),'#'.$l['origem_item_id'].' / '.$l['processo_id'],self::brl($l['base_pedido']),$l['vinculos_nf_positivos']>0?'Sim':'Não',$l['tem_patrimonio']?'Sim':'Não'];
        if(!$rows) $rows[]=['—','Nenhuma compra no período.','','','','','',''];
        $this->tabela([['Data',0.8,'L'],['Produto',3.2,'L'],['Requisitante',1.6,'L'],['Centro de custo',2.2,'L'],['Compra / proc.',1.2,'L'],['Valor do pedido',1.1,'R'],['NF',0.45,'L'],['Patrim.',0.55,'L']],$rows);
        return $this->finalizar();
    }
    private function finalizar(): string {
        $p=$this->pdf; $rodape='Salinas TI · Custo de compra pelo pedido no SGA, não é pagamento nem comprovação fiscal. Totais de parque, compras e projetos não devem ser somados.';
        $p->emCadaPagina(function(int $n,int $tot) use($p,$rodape): void {
            $y=$p->altura-28;
            $p->linha(self::M,$y-10,$p->largura-self::M,$y-10,Pdf::hex(self::VERDE_CLARO),0.6);
            $p->texto(self::M,$y,$p->ajustar($rodape,7,false,$p->largura-2*self::M-90),7,false,Pdf::hex(self::CINZA));
            $p->texto(0,$y,'Página '.$n.' de '.$tot,7,true,Pdf::hex(self::VERDE),'R',$p->largura-self::M);
        });
        return $p->gerar('Relatório de gastos por centro de custo — '.$this->periodo->rotulo());
    }
}
