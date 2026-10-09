@extends('lifecycle.layout')
@section('title','Historique scolaire')
@section('content')
@php $labels=['promoted'=>'Passage','repeated'=>'Redoublement','left'=>'Sortie','completed'=>'Fin de cycle']; @endphp
<h1>Historique scolaire</h1><h2 dir="auto">{{ $student->last_name }} {{ $student->first_name }}</h2><p>Matricule : {{ $student->matricule }} · Naissance : {{ $student->birth_date ?: '—' }}</p><p><a href="{{ route('lifecycle.admissions',['tenant'=>$tenant,'student'=>$student->id]) }}">Nouvelle candidature pour cet élève</a></p>
<div class="table-scroll"><table><thead><tr><th>Année</th><th>Niveau</th><th>Classe</th><th>Inscription</th><th>Tarif convenu</th><th>Décision annuelle</th><th>Documents</th></tr></thead><tbody>
@forelse($enrollments as $enrollment)@php $decision=$decisions->get($enrollment->id); @endphp<tr><td>{{ $enrollment->year }}</td><td dir="auto">{{ $enrollment->level }}</td><td>{{ $enrollment->classroom }}</td><td>{{ ['validated'=>'Validée','pending'=>'En attente','suspended'=>'Suspendue','cancelled'=>'Annulée'][$enrollment->status] }}</td><td>{{ number_format($enrollment->price_minor/100,2,',',' ') }} DA</td><td>{{ $decision ? $labels[$decision->decision].' — '.$decision->reason : '—' }}</td><td>@if(app(\App\School\Modules\ModuleRegistry::class)->enabled('report_cards') && in_array('pedagogy.read',request()->attributes->get('school.permissions',[])))<a href="{{ route('documents.index',['tenant'=>$tenant,'cohort'=>$enrollment->cohort_id]) }}">Bulletins de cette classe et année</a>@else — @endif</td></tr>@empty<tr><td colspan="7">Aucune inscription enregistrée.</td></tr>@endforelse
</tbody></table></div><p>Les anciennes inscriptions, notes et factures restent conservées dans leurs années scolaires.</p>
@endsection
