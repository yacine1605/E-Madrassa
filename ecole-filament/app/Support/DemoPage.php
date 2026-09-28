<?php
namespace App\Support;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\{TextInput,Textarea,Select,DatePicker,TimePicker,Toggle,RichEditor,FileUpload};
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

abstract class DemoPage extends Page
{
    use WithFileUploads;
    public static string $module;
    protected string $view='filament.pages.school';
    public string $search='';public string $cycle='';public string $status='';public string $groupFilter='';
    public int $pageNumber=1;public ?string $detailId=null;public string $scheduleMode='week';public $importFile=null;
    #[Locked] public array $importPreview=[];
    #[Locked] public array $importErrors=[];
    #[Locked] public bool $optimizationGenerated=false;
    #[Locked] public bool $optimizationApproved=false;
    public array $preferences=['name'=>'École privée El Amal','city'=>'Batna','year'=>'2026–2027','capacity'=>25,'partial'=>true,'absence_threshold'=>3];
    public function mount():void {$this->preferences=session('school_preferences',$this->preferences);}
    public function meta():array {return DemoStore::modules()[static::$module];}
    public function getTitle():string {return $this->meta()['title'];}
    public function getSubheading():?string {return $this->meta()['description'];}
    public function updatedSearch():void {$this->pageNumber=1;}
    public function updatedCycle():void {$this->pageNumber=1;}
    public function updatedStatus():void {$this->pageNumber=1;}
    public function updatedGroupFilter():void {$this->pageNumber=1;}
    public function clearFilters():void {$this->reset('search','cycle','status','groupFilter','pageNumber');}
    public function rows():array {
        return array_values(array_filter(DemoStore::all(static::$module),function($r){
            if($this->cycle&&($r['cycle']??'')!==$this->cycle)return false;
            if($this->status&&($r['status']??'')!==$this->status)return false;
            if($this->groupFilter&&($r['group']??'')!==$this->groupFilter)return false;
            $text=implode(' ',array_map(fn($f)=>DemoStore::value($f,$r),$this->meta()['fields']));
            return !$this->search||mb_stripos($text,$this->search)!==false;
        }));
    }
    public function fieldSchema():array {
        $out=[];
        foreach($this->meta()['fields'] as $f){$k=$f['key'];$t=$f['type'];
            $field=match($t){
                'select'=>Select::make($k)->options(array_combine($f['options'],$f['options']))->native(false)->searchable(),
                'relation'=>Select::make($k)->options(DemoStore::options($f['options']))->native(false)->searchable(),
                'date'=>DatePicker::make($k)->native(false)->displayFormat('d/m/Y'),
                'time'=>TimePicker::make($k)->seconds(false),
                'toggle'=>Toggle::make($k),
                'textarea'=>Textarea::make($k)->rows(3)->columnSpanFull(),
                'rich'=>RichEditor::make($k)->toolbarButtons(['bold','italic','bulletList','orderedList','undo','redo'])->columnSpanFull(),
                'file'=>FileUpload::make($k)->disk('local')->directory('demo-uploads/'.session()->getId())->visibility('private')->acceptedFileTypes(['application/pdf','image/jpeg','image/png'])->maxSize(10240)->previewable(false)->helperText('Fichier fictif uniquement · 10 Mo maximum.'),
                default=>TextInput::make($k),
            };
            if(in_array($t,['money','number','decimal','grade','signed'])){
                $field->numeric()->step($t==='number'?1:0.01);if($t!=='signed')$field->minValue(0);if($t==='grade')$field->maxValue(20);
                if(in_array($k,['capacity','coefficient','scale','duration']))$field->minValue(1);if(in_array($t,['money','signed']))$field->suffix('DA');
            }
            if($t==='email')$field->email();if($t==='tel')$field->tel()->maxLength(30);if($t==='text')$field->maxLength(255);
            $out[]=$field->label($f['label'])->required($f['required']);
        }return $out;
    }
    protected function getHeaderActions():array {
        if(!$this->meta()['fields'])return [];
        return [$this->editAction()->label($this->meta()['create'])->icon('heroicon-o-plus'),Action::make('export')->label('Exporter')->icon('heroicon-o-arrow-down-tray')->color('gray')->action(fn()=>$this->exportRows())];
    }
    public function editAction():Action {
        return Action::make('edit')->modalHeading(fn(array $arguments)=>isset($arguments['id'])?'Modifier · '.$this->getTitle():$this->meta()['create'])
            ->modalDescription('Données fictives • modifications limitées à cette session.')->schema([\Filament\Schemas\Components\Grid::make(['default'=>1,'md'=>2])->schema($this->fieldSchema())])
            ->fillForm(fn(array $arguments)=>isset($arguments['id'])?DemoStore::find(static::$module,(string)$arguments['id']):[])
            ->modalSubmitActionLabel('Enregistrer')->action(function(array $data,array $arguments){
                try{DemoStore::save(static::$module,$data,isset($arguments['id'])?(string)$arguments['id']:null);}
                catch(\Illuminate\Validation\ValidationException $e){Notification::make()->title('Vérifiez la saisie')->body(collect($e->errors())->flatten()->first())->danger()->send();throw new \Filament\Support\Exceptions\Halt;}
                Notification::make()->title('Enregistrement sauvegardé')->body('La démonstration a été mise à jour.')->success()->send();
            });
    }
    public function viewRecord(string $id):void {abort_unless(DemoStore::find(static::$module,$id),404);$this->detailId=$id;}
    public function closeDetail():void {$this->detailId=null;}
    public function nextPage():void {$this->pageNumber=min($this->pageNumber+1,max(1,(int)ceil(count($this->rows())/10)));}
    public function prevPage():void {$this->pageNumber=max(1,$this->pageNumber-1);}
    public function exportRows(){
        $fields=$this->meta()['fields'];$rows=$this->rows();
        return response()->streamDownload(function()use($fields,$rows){$h=fopen('php://output','w');fwrite($h,"\xEF\xBB\xBF");fputcsv($h,array_column($fields,'label'),';',escape:'');foreach($rows as $r)fputcsv($h,array_map(function($f)use($r){$v=DemoStore::value($f,$r);return preg_match('/^[=+@\-]/u',$v)?"'".$v:$v;},$fields),';',escape:'');fclose($h);},static::$module.'-demo.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
    public function markPresence(string $id,string $status):void {
        abort_unless(static::$module==='attendance'&&in_array($status,['Présent','Absent','Retard']),403);$r=DemoStore::find('attendance',$id);abort_unless($r,404);$r['status']=$status;DemoStore::save('attendance',$r,$id);
    }
    public function metrics():array {
        $en=DemoStore::all('enrollments');$pay=collect(DemoStore::all('payments'))->where('status','Validé')->sum('amount');$exp=collect(DemoStore::all('expenses'))->where('status','Validée');$sal=collect(DemoStore::all('payroll'))->where('status','Validé');
        return ['students'=>count(DemoStore::all('students')),'groups'=>count(DemoStore::all('groups')),'teachers'=>count(DemoStore::all('teachers')),'pending'=>collect($en)->where('status','En attente')->count(),'due'=>collect($en)->sum('amount'),'paid'=>$pay,'balance'=>collect($en)->sum(fn($r)=>DemoStore::balance($r)),'charges'=>$exp->sum('amount')+$sal->sum(fn($r)=>DemoStore::totalPay($r)),'outflow'=>$exp->sum('paid')+$sal->sum('paid'),'absences'=>collect(DemoStore::all('attendance'))->where('status','Absent')->count()];
    }
    public function settingsSave():void {
        $this->validate(['preferences.name'=>'required|string|max:120','preferences.city'=>'required|string|max:80','preferences.year'=>'required|in:2026–2027,2025–2026','preferences.capacity'=>'required|integer|min:1|max:100','preferences.absence_threshold'=>'required|integer|min:1|max:30','preferences.partial'=>'boolean']);session()->put('school_preferences',$this->preferences);DemoStore::log('settings','Modification','école');Notification::make()->title('Préférences enregistrées')->success()->send();
    }
    public function resetDemoAction():Action {return Action::make('resetDemo')->label('Réinitialiser les données fictives')->color('danger')->requiresConfirmation()->modalDescription('Les modifications de cette session seront effacées.')->action(function(){session()->forget(['school_data','school_audit','school_preferences']);$this->redirect('/admin/dashboard');});}
    public function generateOptimization():void {$this->optimizationGenerated=true;$this->optimizationApproved=false;DemoStore::log('optimization','Simulation générée','SIM-001');}
    public function approveOptimization():void {if(!$this->optimizationGenerated)return;$this->optimizationApproved=true;DemoStore::log('optimization','Exemple examiné','SIM-001');Notification::make()->title('Examen enregistré')->body('Aucune affectation réelle n’a été publiée.')->success()->send();}
    public function importTemplate(){return response()->streamDownload(fn()=>print("\xEF\xBB\xBFname;cycle;level;birth;guardian;phone\nExemple Élève;Primaire;1AP;2020-05-15;Responsable légal;0550000000\n"),'modele-eleves.csv',['Content-Type'=>'text/csv']);}
    public function previewImport():void {
        $this->validate(['importFile'=>'required|file|max:2048|extensions:csv,txt']);$this->importPreview=[];$this->importErrors=[];
        $h=fopen($this->importFile->getRealPath(),'r');$header=fgetcsv($h,0,';',escape:'');
        if(!$header){$this->importErrors[]='Le fichier est vide.';fclose($h);return;}$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
        if($header!==['name','cycle','level','birth','guardian','phone']){$this->importErrors[]='En-têtes incorrects : utilisez le modèle fourni.';fclose($h);return;}
        $names=array_map(fn($r)=>mb_strtolower(trim($r['name'])),DemoStore::all('students'));$line=1;
        while(($values=fgetcsv($h,0,';',escape:''))!==false){$line++;if($line>501){$this->importErrors[]='Limite : 500 lignes par aperçu.';break;}if(count($values)!==6){$this->importErrors[]="Ligne $line : 6 colonnes attendues.";continue;}
            $r=array_combine($header,array_map('trim',$values));$norm=mb_strtolower($r['name']);$valid=match($r['cycle']){'Primaire'=>['1AP','2AP','3AP','4AP','5AP'],'Moyen'=>['1AM','2AM','3AM','4AM'],'Secondaire'=>['1AS','2AS','3AS'],default=>[]};$dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$r['birth']);
            if(in_array('',$r,true)||!in_array($r['level'],$valid)||!$dt||$dt->format('Y-m-d')!==$r['birth']||in_array($norm,$names)){$this->importErrors[]="Ligne $line : champ manquant, date/niveau invalide ou nom déjà présent.";continue;}
            $names[]=$norm;$this->importPreview[]=$r+['status'=>'Actif','address'=>'','name_ar'=>''];
        }fclose($h);
    }
    public function confirmImport():void {if(!$this->importPreview||$this->importErrors)return;foreach($this->importPreview as $r)DemoStore::save('students',$r);$this->importPreview=[];$this->importFile=null;Notification::make()->title('Élèves ajoutés à la démonstration')->success()->send();}
}
