<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database as DB;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
final class ProjectXml {
    private const NS='http://schemas.microsoft.com/project';
    public static function gerar(int $id): array {
        $detail=DB::transaction(static fn()=>Projetos::detalhe($id));
        return ['nome'=>'projeto-'.$id.'-'.preg_replace('/[^A-Za-z0-9_-]/','-',$detail['project']['codigo']).'.xml','xml'=>self::montar($detail)];
    }
    private static function dia(?string $value): ?string {
        $date=substr((string)$value,0,10);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return $d&&$d->format('Y-m-d')===$date?$date:null;
    }
    public static function montar(array $detail): string {
        $p=$detail['project'];$rows=$detail['apontamentos'];
        usort($rows,static fn($a,$b)=>[(string)$a['processo_data'],(int)$a['origem_item_id']]<=>[(string)$b['processo_data'],(int)$b['origem_item_id']]);
        $dates=[];foreach($rows as $r)if($d=self::dia($r['processo_data']))$dates[]=$d;
        foreach(['inicio','fim'] as $k)if($d=self::dia($p[$k]??null))$dates[]=$d;
        $fallback=self::dia($p['inicio']??null)?:($dates?min($dates):(self::dia($p['criado_em']??null)?:date('Y-m-d')));
        $start=($dates?min($dates):$fallback).'T08:00:00';$finish=($dates?max($dates):$fallback).'T08:00:00';
        $title=$p['codigo'].' · '.$p['nome'];
        $doc=new DOMDocument('1.0','UTF-8');$doc->formatOutput=true;
        $root=$doc->createElementNS(self::NS,'Project');$doc->appendChild($root);
        $add=static function(DOMElement $parent,string $name,mixed $value)use($doc): DOMElement {
            $el=$doc->createElementNS(self::NS,$name);$text=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$value)??'';$el->appendChild($doc->createTextNode($text));$parent->appendChild($el);return $el;
        };
        foreach(['SaveVersion'=>12,'Name'=>$title.'.xml','Title'=>$title,'Subject'=>'Compras e custos do projeto · Salinas TI','Category'=>'Parque de TI','Company'=>'Salinas Gold Mineração','Manager'=>$p['gestor']??'','Author'=>'Salinas TI','CreationDate'=>str_replace(' ','T',substr($p['criado_em']??date('Y-m-d H:i:s'),0,19)),'LastSaved'=>date('Y-m-d\TH:i:s'),'ScheduleFromStart'=>1,'StartDate'=>$start,'FinishDate'=>$finish,'CurrencyDigits'=>2,'CurrencySymbol'=>'R$','CurrencyCode'=>'BRL','CurrencySymbolPosition'=>2,'CalendarUID'=>1,'DefaultStartTime'=>'08:00:00','DefaultFinishTime'=>'17:00:00','MinutesPerDay'=>480,'MinutesPerWeek'=>3360,'DaysPerMonth'=>30,'DefaultTaskType'=>1,'DefaultFixedCostAccrual'=>1,'DurationFormat'=>7,'WorkFormat'=>2,'NewTasksEstimated'=>0,'Autolink'=>0] as $k=>$v)$add($root,$k,$v);
        // Calendar used for documentary milestones, including purchases on weekends.
        $calendars=$add($root,'Calendars','');$cal=$add($calendars,'Calendar','');foreach(['UID'=>1,'Name'=>'Marcos documentais · todos os dias','IsBaseCalendar'=>1,'BaseCalendarUID'=>-1] as $k=>$v)$add($cal,$k,$v);
        $week=$add($cal,'WeekDays','');for($day=1;$day<=7;$day++){$wd=$add($week,'WeekDay','');$add($wd,'DayType',$day);$add($wd,'DayWorking',1);$times=$add($wd,'WorkingTimes','');foreach([['08:00:00','12:00:00'],['13:00:00','17:00:00']] as [$from,$to]){$t=$add($times,'WorkingTime','');$add($t,'FromTime',$from);$add($t,'ToTime',$to);}}
        $tasks=$add($root,'Tasks','');$position=0;
        $task=static function(int $uid,string $name,string $outline,int $level,bool $summary,string $from,string $to,string $cost,string $notes)use($tasks,$add,&$position): void {
            $t=$add($tasks,'Task','');$id=$position++;
            foreach(['UID'=>$uid,'ID'=>$id,'Name'=>mb_substr($name,0,255),'Type'=>1,'IsNull'=>0,'WBS'=>$outline,'OutlineNumber'=>$outline,'OutlineLevel'=>$level,'Start'=>$from,'Finish'=>$to] as $k=>$v)$add($t,$k,$v);
            if(!$summary)$add($t,'Duration','PT0H0M0S');
            $add($t,'DurationFormat',7);$add($t,'EffortDriven',0);$add($t,'Estimated',0);$add($t,'Milestone',$summary?0:1);$add($t,'Summary',$summary?1:0);
            $add($t,'FixedCost',$summary?'0.00':$cost);$add($t,'FixedCostAccrual',1);$add($t,'Cost',$cost);
            if(!$summary){$add($t,'ConstraintType',2);$add($t,'CalendarUID',1);$add($t,'ConstraintDate',$from);}
            $add($t,'Notes',$notes);
        };
        $total='0.00';foreach($rows as $r)$total=bcadd($total,(string)$r['valor_atribuido'],2);
        $note="Projeto: $title\nResponsável: ".($p['gestor']??'')."\nCentro de custo: ".($p['centro_custo']??'Não informado')."\nSituação na aplicação: ".$p['status']."\nOrçamento previsto BRL: ".($p['orcamento']??'Não definido')."\nInício previsto: ".($p['inicio']??'Não informado')."\nTérmino previsto: ".($p['fim']??'Não informado')."\nDescrição: ".($p['descricao']??'')."\nExportação integral, sem filtro de datas. Compras gerenciais, pelo total do pedido × percentual de rateio; não são pagamentos nem custos fiscais realizados.\nCada compra é um marco documental de duração zero na data do processo SGA. Não representa conclusão física. A aplicação não registra etapas, dependências ou percentuais físicos de execução: organize o cronograma no Microsoft Project.\nDatas ausentes usam início previsto, menor data disponível ou data do cadastro como referência, informada nas notas. Alterações no arquivo não retornam automaticamente à aplicação.\nExportado em ".date('d/m/Y H:i:s').' (Cuiabá).';
        $task(0,$title,'0',0,true,$start,$finish,$total,$note);
        $outline=0;
        foreach(['inicio'=>'Início previsto do projeto','fim'=>'Término previsto do projeto'] as $key=>$label)if($d=self::dia($p[$key]??null)){$outline++;$task($key==='inicio'?2:3,$label,(string)$outline,1,false,$d.'T08:00:00',$d.'T08:00:00','0.00','Data prevista cadastrada no Salinas TI. Ajuste o planejamento no Microsoft Project.');}
        if($rows){$outline++;$group=(string)$outline;$valid=array_filter(array_map(static fn($r)=>self::dia($r['processo_data']),$rows));$groupStart=($valid?min($valid):$fallback).'T08:00:00';$groupEnd=($valid?max($valid):$fallback).'T08:00:00';$task(1,'Compras SGA · custos atribuídos',$group,1,true,$groupStart,$groupEnd,$total,'Custos fixos de cada compra, com o rateio já aplicado. As linhas com alerta de origem permanecem incluídas e estão sinalizadas nas notas.');$seq=0;
            foreach($rows as $r){$seq++;$sourceDate=self::dia($r['processo_data']);$when=($sourceDate?:$fallback).'T08:00:00';$amount=bcadd((string)$r['valor_atribuido'],'0',2);$info='Linha SGA: '.$r['origem_item_id'].'; processo: '.$r['processo_id']."\nRequisitante: ".$r['requisitante']."\nData do processo: ".($r['processo_data']?:'Não informada; data de referência '.$fallback)."\nBase do pedido BRL: ".$r['base_pedido'].'; percentual: '.$r['percentual'].'%; atribuído BRL: '.$amount."\nFinalidade: ".$r['motivo']."\nVínculo com NF positiva: ".($r['vinculos_nf_positivos']>0?'Sim':'Não')."\nAlerta de origem: ".($r['alerta_origem']?'SIM — revisar origem / cancelamento / conciliação':'Não')."\nCusto gerencial do pedido. Execução física e pagamento não informados. Identificador do apontamento: ".$r['id'];$task((int)$r['id']+10,($r['alerta_origem']?'[REVISAR] ':'').'SGA #'.$r['origem_item_id'].' · '.$r['produto'],$group.'.'.$seq,2,false,$when,$when,$amount,$info);}
        }
        return $doc->saveXML();
    }
}
