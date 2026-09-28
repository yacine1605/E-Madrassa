@extends('teacher.layout')
@section('title', 'Notes de devoirs et examens')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-grades.css') }}">
<p class="eyebrow">Suivi des apprentissages</p><h1>Devoirs et examens</h1><p class="muted">Créez une évaluation puis saisissez les notes de vos élèves, matière par matière.</p>
@if($cohort)<a class="text-link" href="{{ route('teacher.grades.index') }}">Afficher toutes mes classes</a>@endif
@if($assignments->isNotEmpty())
<section class="card grading-create"><h2>Nouvelle évaluation</h2>
<form method="post" action="{{ route('teacher.grades.create') }}" class="grading-form">@csrf
<label class="wide">Classe et matière<select name="assignment_id" required>@foreach($assignments as $assignment)<option value="{{ $assignment->id }}" @selected(old('assignment_id') === $assignment->id)>{{ $assignment->classroom }} — {{ $assignment->subject }}</option>@endforeach</select></label>
<label>Type<select name="kind" required><option value="devoir" @selected(old('kind') === 'devoir')>Devoir</option><option value="examen" @selected(old('kind') === 'examen')>Examen</option></select></label>
<label>Titre<input name="title" value="{{ old('title') }}" maxlength="200" placeholder="Ex. Devoir 1 — premier trimestre" required></label>
<label>Date<input type="date" name="held_on" value="{{ old('held_on', now()->toDateString()) }}" required></label>
<label>Barème<input type="number" name="max_score" min="1" max="1000" step="0.01" value="{{ old('max_score', 20) }}" required></label>
<div class="wide"><button type="submit">Créer et saisir les notes</button></div>
</form></section>
@else<div class="empty"><h2>Aucune matière affectée</h2><p>La direction doit vous affecter une classe et une matière pour permettre la saisie des notes.</p></div>@endif
<h2>Mes évaluations</h2><div class="cards">
@forelse($assessments as $assessment)
<article class="card"><span class="badge">{{ match($assessment->kind) {'devoir'=>'Devoir','examen'=>'Examen',default=>'Évaluation'} }} · /{{ (float)$assessment->max_score }}</span><h3>{{ $assessment->title }}</h3><p>{{ $assessment->classroom }}</p><p dir="auto">{{ $assessment->subject }}</p><p class="muted">{{ \Carbon\Carbon::parse($assessment->held_on)->format('d/m/Y') }} · {{ $assessment->published_at ? 'Notes publiées' : 'Saisie ouverte' }}</p><a class="button" href="{{ route('teacher.grades.show', $assessment->id) }}">{{ $assessment->published_at ? 'Consulter les notes' : 'Saisir les notes' }}</a></article>
@empty<div class="empty"><h3>Aucune évaluation pour le moment</h3><p>Ajoutez votre premier devoir ou examen avec le formulaire ci-dessus.</p></div>@endforelse
</div>
<div class="pager">@if($assessments->previousPageUrl())<a href="{{ $assessments->previousPageUrl() }}">Précédent</a>@endif<span>Page {{ $assessments->currentPage() }} / {{ $assessments->lastPage() }}</span>@if($assessments->nextPageUrl())<a href="{{ $assessments->nextPageUrl() }}">Suivant</a>@endif</div>
@endsection
