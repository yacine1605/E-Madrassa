@extends('teacher.layout')
@section('title', $assessment->title)
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-grades.css') }}">
<a class="text-link" href="{{ route('teacher.grades.index', ['classe'=>$class->id]) }}">← Devoirs et examens</a>
<p class="eyebrow">{{ match($assessment->kind) {'continu'=>'Contrôle continu','devoir'=>'Devoir','examen'=>'Examen',default=>'Évaluation'} }}</p><h1>{{ $assessment->title }}</h1><p class="muted">{{ $class->name }} · {{ $subject->name }} · {{ \Carbon\Carbon::parse($assessment->held_on)->format('d/m/Y') }}</p>
@if($assessment->published_at)<div class="notice">Notes publiées : consultation uniquement.</div>@endif
<section class="card"><h2>Notes sur {{ (float)$assessment->max_score }} <span class="badge">{{ $students->total() }} élèves</span></h2>
@if($students->isEmpty())<div class="empty"><h3>Aucun élève inscrit</h3><p>La saisie sera disponible dès que des élèves seront inscrits dans cette classe.</p></div>
@else
<p class="muted">Une case vide signifie « non noté ». La note 0 est une note réelle. Pour une absence ou une dispense, choisissez le statut correspondant.</p>
<form method="post" action="{{ route('teacher.grades.save', $assessment->id) }}" id="grade-form">@csrf
<input type="hidden" name="page" value="{{ $students->currentPage() }}">
<fieldset @disabled($assessment->published_at)>
<div class="table-wrap"><table class="grades-table"><thead><tr><th>Élève</th><th>Statut</th><th>Note / {{ (float)$assessment->max_score }}</th></tr></thead><tbody>
@foreach($students as $index => $student)
@php
    $grade = $grades->get($student->enrollment_id);
    $submitted = collect(old('grades', []))->firstWhere('enrollment_id', $student->enrollment_id);
    $status = $submitted['status'] ?? ($grade && $grade->status !== 'missing' ? $grade->status : 'marked');
@endphp
<tr data-grade-row>
<td><span dir="auto">{{ $student->last_name }} {{ $student->first_name }}</span><small class="muted">{{ $student->matricule }}</small>
<input type="hidden" name="grades[{{ $index }}][enrollment_id]" value="{{ $student->enrollment_id }}">
<input type="hidden" name="grades[{{ $index }}][version]" value="{{ $submitted['version'] ?? $versions[$student->enrollment_id] }}"></td>
<td><select name="grades[{{ $index }}][status]" aria-label="Statut de {{ $student->matricule }}" data-grade-status><option value="marked" @selected($status === 'marked')>Note</option><option value="absent" @selected($status === 'absent')>Absent</option><option value="exempt" @selected($status === 'exempt')>Dispensé</option></select></td>
<td><input type="number" name="grades[{{ $index }}][score]" aria-label="Note de {{ $student->matricule }}" value="{{ $submitted !== null ? ($submitted['score'] ?? '') : $grade?->score }}" min="0" max="{{ $assessment->max_score }}" step="0.01" placeholder="Non noté" data-grade-score @disabled($status !== 'marked')></td>
</tr>
@endforeach
</tbody></table></div>
@unless($assessment->published_at)
<label class="correction">Motif de correction <span class="muted small">Obligatoire si vous modifiez une note déjà enregistrée.</span><input name="reason" maxlength="1000" value="{{ old('reason') }}" placeholder="Ex. Correction après vérification de la copie"></label>
<div class="save-grades"><button type="submit">Enregistrer les notes</button><span class="muted small">Enregistrez cette page avant de changer de page.</span></div>
@endunless
</fieldset></form>
<div class="pager">@if($students->previousPageUrl())<a href="{{ $students->previousPageUrl() }}">Précédent</a>@endif<span>Page {{ $students->currentPage() }} / {{ $students->lastPage() }}</span>@if($students->nextPageUrl())<a href="{{ $students->nextPageUrl() }}">Suivant</a>@endif</div>
@endif
</section>
<script src="{{ asset('js/teacher-grades.js') }}" defer></script>
@endsection
