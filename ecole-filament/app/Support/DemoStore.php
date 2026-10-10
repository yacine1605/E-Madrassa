<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;

/** Adaptateur session pour la recette du frontend, pas un backend de production. */
class DemoStore
{
    public static function modules():array {return json_decode(file_get_contents(resource_path('demo/modules.json')),true,flags:JSON_THROW_ON_ERROR);}
    public static function all(string $module):array {
        if(!session()->has('school_data'))session()->put('school_data',json_decode(file_get_contents(resource_path('demo/seed.json')),true,flags:JSON_THROW_ON_ERROR));
        return session("school_data.$module",[]);
    }
    public static function find(string $module,string $id):?array {return collect(self::all($module))->firstWhere('id',$id);}
    public static function label(string $module,string $id):string {
        $r=self::find($module,$id);if(!$r)return '—';
        return $module==='enrollments'?'INS-'.$id.' · '.self::label('students',$r['student']).' · '.self::label('formations',$r['formation']):($r['name']??'#'.$id);
    }
    public static function options(string $module):array {$out=[];foreach(self::all($module) as $r)$out[$r['id']]=self::label($module,$r['id']);return $out;}
    public static function money(int|float|string|null $v):string {return number_format((float)$v,2,',',' ').' DA';}
    /** Sums in integer centimes, like the real backend, so no rounding drift accumulates. */
    public static function cents(int|float|string|null $v):int {return (int)round((float)$v*100);}
    public static function paid(string $id):float {return collect(self::all('payments'))->where('enrollment',$id)->where('status','Validé')->sum(fn($p)=>self::cents($p['amount']))/100;}
    public static function balance(array $r):float {return (self::cents($r['amount'])-self::cents(self::paid($r['id'])))/100;}
    public static function paymentStatus(array $r):string {return self::paid($r['id'])<=0?'Non payé':(self::balance($r)>0?'Payé partiellement':'Payé');}
    public static function totalPay(array $r):float {return (($r['type']==='Horaire'?(int)round((float)$r['hours']*self::cents($r['rate'])):self::cents($r['base']))+self::cents($r['bonus'])+self::cents($r['adjustment']))/100;}
    public static function immutable(string $module,array $r):bool {
        return (in_array($module,['payments','expenses','payroll'])&&in_array($r['status']??'',['Validé','Validée']))||($module==='enrollments'&&($r['status']??'')==='Annulée')||($module==='schedule'&&($r['locked']??false));
    }
    public static function save(string $module,array $data,?string $id=null):array {
        foreach(self::modules()[$module]['fields'] as $f)if($f['type']==='relation'&&isset($data[$f['key']]))$data[$f['key']]=(string)$data[$f['key']];
        $rows=self::all($module);$old=$id?self::find($module,$id):null;
        $fail=fn($k,$m)=>throw ValidationException::withMessages(["data.$k"=>$m]);
        if($id&&!$old)$fail('id','Enregistrement introuvable.');
        if($old&&self::immutable($module,$old))$fail('status','Enregistrement figé : une correction liée doit être traitée par le service métier.');
        if(in_array($module,['students','groups','formations','admissions','subjects'])){
            $valid=match($data['cycle']){'Primaire'=>['1AP','2AP','3AP','4AP','5AP'],'Moyen'=>['1AM','2AM','3AM','4AM'],'Secondaire'=>['1AS','2AS','3AS'],default=>[]};
            if(!in_array($data['level'],$valid))$fail('level','Le niveau doit appartenir au cycle choisi.');
        }
        if($module==='enrollments'){
            $g=self::find('groups',$data['group']);$s=self::find('students',$data['student']);
            if(!$g||!$s||$g['formation']!==$data['formation']||$g['cycle']!==$data['cycle']||$s['cycle']!==$data['cycle'])$fail('group','Le groupe, la formation et le cycle doivent correspondre.');
            $peers=collect($rows)->reject(fn($r)=>$r['id']===$id)->where('year',$data['year'])->reject(fn($r)=>$r['status']==='Annulée');
            if($data['status']!=='Annulée'&&$peers->where('student',$data['student'])->where('formation',$data['formation'])->isNotEmpty())$fail('student','Une inscription active existe déjà pour cette formation.');
            if($data['status']!=='Annulée'&&$peers->where('group',$data['group'])->count()>=$g['capacity'])$fail('group','La capacité de cette classe est atteinte.');
        }
        if($module==='schedule'){
            if($data['end']<=$data['start'])$fail('end','La fin doit suivre le début.');
            if($data['status']!=='Annulée')foreach($rows as $r){
                if($r['id']===$id||$r['status']==='Annulée'||$r['day']!==$data['day'])continue;
                if($data['start']<$r['end']&&$data['end']>$r['start']&&($data['teacher']===$r['teacher']||$data['room']===$r['room']||$data['group']===$r['group']))$fail('start','Conflit de groupe, enseignant ou salle à ce créneau.');
            }
        }
        if($module==='payments'){
            $e=self::find('enrollments',$data['enrollment']);
            if(!$e||$e['status']==='Annulée')$fail('enrollment','Inscription annulée ou introuvable.');
            if($data['amount']<=0)$fail('amount','Le montant doit être positif.');
            if($data['status']==='Validé'&&$data['amount']>self::balance($e))$fail('amount','Le montant dépasse le solde dû.');
        }
        if(in_array($module,['attendance','grades']))foreach($rows as $r){
            if($r['id']!==$id&&$r['student']===$data['student']&&($module==='grades'?$r['assessment']===$data['assessment']:($r['date']===$data['date']&&$r['time']===$data['time'])))$fail('student','Cette saisie existe déjà ; modifiez la ligne existante.');
        }
        if($module==='payroll'){
            if(self::totalPay($data)<0||$data['paid']>self::totalPay($data))$fail('paid','Le versement dépasse la rémunération calculée.');
            foreach($rows as $r)if($r['id']!==$id&&$r['name']===$data['name']&&$r['period']===$data['period'])$fail('period','Une rémunération existe déjà pour cette personne et ce mois.');
        }
        if($module==='expenses'&&$data['paid']>$data['amount'])$fail('paid','Le versement dépasse la charge engagée.');
        $data['id']=$id??(string)((int)(collect($rows)->max('id')??0)+1);$data['updated_at']=now()->format('d/m/Y H:i');
        if($old)$rows=array_map(fn($r)=>$r['id']===$id?$data:$r,$rows);else $rows[]=$data;
        session()->put("school_data.$module",$rows);self::log($module,$old?'Modification':'Création',$data['id'],$old,$data);return $data;
    }
    public static function log(string $module,string $action,string $id,?array $before=null,?array $after=null):void {
        $audit=session('school_audit',[]);array_unshift($audit,['date'=>now()->format('d/m/Y H:i:s'),'author'=>auth()->user()?->name??'Démo','module'=>self::modules()[$module]['title']??$module,'action'=>$action,'reference'=>$id,'before'=>$before,'after'=>$after]);session()->put('school_audit',$audit);
    }
    public static function value(array $f,array $r):string {
        $v=$r[$f['key']]??null;if($v===null||$v==='')return '—';
        return match($f['type']){'relation'=>self::label($f['options'],(string)$v),'money','signed'=>self::money($v),'toggle'=>$v?'Oui':'Non','date'=>date('d/m/Y',strtotime($v)),'rich'=>strip_tags($v),'file'=>basename($v),default=>(string)$v};
    }
}
