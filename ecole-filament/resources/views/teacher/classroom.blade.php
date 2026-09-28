@extends('teacher.layout')
@section('title', $class->name)
@section('content')
<a class="text-link" href="{{ route('teacher.home') }}">← Mes classes</a><h1>{{ $class->name }}</h1><p class="muted">{{ $assignments->pluck('name')->join(' · ') }}</p>
<p><a class="button" href="{{ route('teacher.grades.index', ['classe'=>$class->id]) }}">Notes de devoirs et examens</a></p>
<section class="card"><h2>Élèves inscrits <span class="badge">{{ $students->total() }}</span></h2>
@if($students->isEmpty())<div class="empty"><h3>La liste des élèves est encore vide</h3><p>Les élèves apparaîtront ici après leur inscription dans cette classe.</p></div>
@else<div class="table-wrap"><table><thead><tr><th>Matricule</th><th>Nom</th><th>Prénom</th></tr></thead><tbody>@foreach($students as $student)<tr><td>{{ $student->matricule }}</td><td dir="auto">{{ $student->last_name }}</td><td dir="auto">{{ $student->first_name }}</td></tr>@endforeach</tbody></table></div>
<div class="pager">@if($students->previousPageUrl())<a href="{{ $students->previousPageUrl() }}">Précédent</a>@endif<span>Page {{ $students->currentPage() }} / {{ $students->lastPage() }}</span>@if($students->nextPageUrl())<a href="{{ $students->nextPageUrl() }}">Suivant</a>@endif</div>@endif
</section>
@endsection
