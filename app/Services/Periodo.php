<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class Periodo {
    public function __construct(public string $opcao='todos',public ?string $inicio=null,public ?string $fim=null) {}
    public static function ler(array $input): self {
        foreach(['periodo','data_inicio','data_fim'] as $key) if(isset($input[$key])&&!is_string($input[$key])) throw new RuntimeException('Filtro de datas inválido.');
        $option=$input['periodo']??'todos'; $today=new \DateTimeImmutable('today',new \DateTimeZone('America/Cuiaba'));
        $start=trim($input['data_inicio']??''); $end=trim($input['data_fim']??'');
        if($option==='personalizado') {
            foreach([$start,$end] as $value) if($value!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))||(int)substr($value,0,4)<1000||(int)substr($value,0,4)>9998)) throw new RuntimeException('Informe datas válidas para o filtro.');
            if($start!==''&&$end!==''&&$start>$end) throw new RuntimeException('A data inicial deve ser igual ou anterior à data final.');
            return new self('personalizado',$start?:null,$end?:null);
        }
        return match($option) {
            'todos'=>new self(),
            'hoje'=>new self($option,$today->format('Y-m-d'),$today->format('Y-m-d')),
            '7dias'=>new self($option,$today->modify('-6 days')->format('Y-m-d'),$today->format('Y-m-d')),
            '30dias'=>new self($option,$today->modify('-29 days')->format('Y-m-d'),$today->format('Y-m-d')),
            'mes'=>new self($option,$today->format('Y-m-01'),$today->format('Y-m-d')),
            'ano'=>new self($option,$today->format('Y-01-01'),$today->format('Y-m-d')),
            default=>throw new RuntimeException('Selecione um período válido.')
        };
    }
    public function condicao(string $column): array {
        if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D',$column)) throw new RuntimeException('Campo de data inválido.');
        $sql=''; $values=[];
        if($this->inicio) { $sql.=" AND $column >= ?"; $values[]=$this->inicio.' 00:00:00'; }
        if($this->fim) { $sql.=" AND $column < ?"; $values[]=(new \DateTimeImmutable($this->fim,new \DateTimeZone('America/Cuiaba')))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        return [$sql,$values];
    }
    public function query(array $extra=[]): string { return http_build_query(array_merge(['periodo'=>$this->opcao,'data_inicio'=>$this->inicio??'','data_fim'=>$this->fim??''],$extra)); }
    public function ativo(): bool { return $this->inicio!==null||$this->fim!==null; }
    public function rotulo(): string {
        $format=static fn(?string $d)=>$d?(new \DateTimeImmutable($d))->format('d/m/Y'):null;
        if(!$this->ativo()) return 'Todo o histórico';
        return ($this->inicio?'De '.$format($this->inicio):'Desde o início').' · '.($this->fim?'até '.$format($this->fim):'sem data final');
    }
}
