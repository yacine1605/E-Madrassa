@extends('teacher.layout')
@section('title', 'Mes classes')
@section('content')
<p class="eyebrow">Votre quotidien</p><h1>Bonjour, <bdi>{{ $teacher->name }}</bdi></h1><p class="muted">Vos classes et matières affectées par la direction.</p>
<div class="stats"><div><strong>{{ $classes->count() }}</strong><span>Classes affectées</span></div><div><strong>{{ $students }}</strong><span>Élèves inscrits</span></div><a href="{{ route('teacher.schedule') }}">Consulter mon planning <span aria-hidden="true">↗</span></a></div>
<h2>Mes classes</h2><div class="cards">
@forelse($classes as $id => $assignments)
<article class="card"><span class="badge">{{ $assignments->first()->period }}</span><h3>{{ $assignments->first()->name }}</h3><ul class="subjects">@foreach($assignments as $assignment)<li dir="auto">{{ $assignment->subject }}</li>@endforeach</ul><a class="button" href="{{ route('teacher.classroom', $id) }}">Voir les élèves <span aria-hidden="true">→</span></a></article>
@empty<div class="empty"><h3>Aucune classe affectée</h3><p>Vos classes apparaîtront ici après leur affectation par la direction.</p></div>@endforelse
</div>
@endsection
