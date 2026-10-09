<section class="no-print workflow">
<p><strong>État : {{ $status === 'validated' ? 'Validé' : 'Brouillon' }}</strong> · {{ count($payload['cards']) }} élève(s) dans le lot de la classe.</p>
@if($status === 'validated')<p>Copie validée le {{ \Illuminate\Support\Carbon::parse($archive->validated_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}. La clôture fige définitivement les résultats, les identités et le document imprimable. Si les données ont changé, revenez au brouillon avant de valider à nouveau.</p>@else<p>Vérifiez toutes les pages du lot. La validation concerne toute la classe et exige des bulletins complets.</p>@endif
@if(in_array('pedagogy.write',request()->attributes->get('school.permissions',[])))
@foreach($status === 'validated' ? ['close'=>'Clôturer et archiver définitivement','draft'=>'Revenir au brouillon'] : ['validate'=>'Valider le lot de la classe'] as $action=>$label)
<form method="post" action="{{ route('documents.'.$action.'-'.$kind,array_filter(['tenant'=>$school->id,'term'=>$term?->id,'cohort'=>$class->id])) }}">@csrf<input type="hidden" name="version" value="{{ $version }}"><button>{{ $label }}</button></form>
@endforeach
@endif
</section>
