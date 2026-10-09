@extends('documents.layout')
@section('title','Bulletins trimestriels')
@section('content')
@include('documents.partials.workflow')
<p class="no-print">Les brouillons utilisent les évaluations publiées. Une note manquante ou une absence laisse le résultat incomplet ; elle n’est pas transformée en zéro.</p>
@forelse($cards as $card)
@include('documents.partials.term-card')
@php($student = (object)$card['student'])
@if($status === 'draft' && in_array('pedagogy.write',request()->attributes->get('school.permissions',[])))<details class="no-print"><summary>Observations : {{ $student->last_name }} {{ $student->first_name }}</summary><form method="post" action="{{ route('documents.observations',['tenant'=>$school->id,'term'=>$term->id,'cohort'=>$class->id]) }}">@csrf<input type="hidden" name="enrollment" value="{{ $student->id }}">@foreach($card['lines'] as $line)<label dir="auto">{{ $line['name'] }}<textarea name="observations[{{ $line['subject_id'] }}]" maxlength="1000">{{ $line['observation'] }}</textarea></label>@endforeach<button>Enregistrer les observations</button></form></details>@endif
@empty<p>Aucun élève avec inscription validée dans cette classe.</p>@endforelse
<div class="no-print">{{ $students->links() }}<p>Impression par lots de 50 élèves. Imprimez chaque page du lot.</p></div>
@endsection
