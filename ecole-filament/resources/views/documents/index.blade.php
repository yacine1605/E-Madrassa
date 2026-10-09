@extends('documents.layout')
@section('title','Bulletins scolaires')
@section('content')
<main><h1>Bulletins scolaires · كشوف النقاط</h1><p>Établissement : {{ $school->name }}</p>
<form method="get"><label>Classe<select name="cohort" onchange="this.form.submit()">@foreach($classes as $item)<option value="{{ $item->id }}" @selected($class?->id === $item->id)>{{ $item->name }} — {{ $item->year }}</option>@endforeach</select></label><button>Afficher</button></form>
@if($class)
<h2>{{ $class->level }} — {{ $class->year }}</h2>
@foreach($terms as $term)@php($termArchive = $archiveRows->firstWhere('term_id',$term->id))<p><a href="{{ route('documents.bulletins',['tenant'=>$school->id,'term'=>$term->id,'cohort'=>$class->id]) }}">Imprimer les bulletins du trimestre {{ $term->trimester }}</a> · {{ $term->starts_on }} – {{ $term->ends_on }} · /{{ $term->scale }} · <strong>{{ $termArchive?->status === 'closed' ? 'Clôturé — archive figée' : ($termArchive?->status === 'validated' ? 'Validé' : 'Brouillon') }}</strong></p>@endforeach
@if($archiveRows->where('kind','term')->where('status','closed')->count() === 3)
@php($annualArchive = $archiveRows->firstWhere('kind','annual'))
<p><a href="{{ route('documents.annual',['tenant'=>$school->id,'cohort'=>$class->id]) }}">Bulletins annuels — moyenne des trois trimestres</a> · {{ $annualArchive?->status === 'closed' ? 'Clôturé — archive figée' : ($annualArchive?->status === 'validated' ? 'Validé' : 'Brouillon') }}</p>
@else<p>Le bulletin annuel sera disponible après clôture des trois trimestres de cette classe.</p>@endif
@if($terms->isEmpty())<p>Aucun trimestre configuré pour ce niveau et cette année.</p>@endif
@if(in_array('pedagogy.write',request()->attributes->get('school.permissions',[])))
<details><summary>Configurer un trimestre</summary><p>Renseignez les poids de chaque composante et les coefficients des matières selon votre circulaire. Une matière qualitative ne participe pas à la moyenne générale. La configuration s’applique à toutes les classes de ce niveau pour l’année choisie.</p>
@if($preset)
<p class="notice">Coefficients du moyen 2026–2027 préremplis d’après la copie de la correspondance MEN n° 470 du 19 septembre 2026. <a href="{{ $preset['source_url'] }}" target="_blank" rel="noopener noreferrer">Consulter le document</a>. Vérifiez les matières applicables à la classe et renseignez les poids de calcul selon votre règle adoptée.</p>
@if($hasBothArts)<p>Arts plastiques ou musique : les arts plastiques sont proposés dans la moyenne et la musique hors moyenne. Pour choisir la musique, cochez les arts plastiques et décochez la musique dans la colonne « Hors moyenne / qualitative ».</p>@endif
@endif
<form method="post" action="{{ route('documents.configure',['tenant'=>$school->id]) }}">@csrf<input type="hidden" name="cohort" value="{{ $class->id }}">
<div class="form-grid"><label>Trimestre<select name="trimester" required>@foreach([1,2,3] as $number)@if(!$terms->contains('trimester',$number))<option value="{{ $number }}">{{ $number }}</option>@endif @endforeach</select></label>
<label>Début<input type="date" name="starts_on" value="{{ old('starts_on') }}" required></label><label>Fin<input type="date" name="ends_on" value="{{ old('ends_on') }}" required></label>
<label>Barème du bulletin<select name="scale"><option value="10" @selected((int)old('scale',$preset['scale'] ?? 10) === 10)>/10</option><option value="20" @selected((int)old('scale',$preset['scale'] ?? 10) === 20)>/20</option></select></label>
<label>Référence de la circulaire / règle adoptée<input name="reference" maxlength="1000" value="{{ old('reference',$preset['reference'] ?? '') }}" required></label></div>
<div class="table-scroll"><table><thead><tr><th>Matière</th><th>Coefficient</th><th>Poids contrôle continu</th><th>Poids devoirs</th><th>Poids composition</th><th>Hors moyenne / qualitative</th></tr></thead><tbody>
@foreach($subjects as $subject)<tr><td dir="auto">{{ $subject->name }}</td><td><input aria-label="Coefficient {{ $subject->name }}" type="number" name="rules[{{ $subject->id }}][coefficient]" min="0.01" max="100" step="0.01" value="{{ old('rules.'.$subject->id.'.coefficient',$preset['coefficients'][$subject->code] ?? null) }}" required></td>
@foreach(['continu','devoir','examen'] as $kind)<td><input aria-label="Poids {{ $kind }} {{ $subject->name }}" type="number" name="rules[{{ $subject->id }}][{{ $kind }}]" min="0" max="100" step="0.01" value="{{ old('rules.'.$subject->id.'.'.$kind,0) }}" required></td>@endforeach
<td><input aria-label="Qualitative {{ $subject->name }}" type="checkbox" name="rules[{{ $subject->id }}][qualitative]" value="1" @checked(session()->hasOldInput() ? old('rules.'.$subject->id.'.qualitative',false) : ($preset && $hasBothArts && $subject->code === 'MOY-MUSIC'))></td></tr>@endforeach
</tbody></table></div><p>Calcul : somme des moyennes des composantes × leurs poids, divisée par la somme des poids. Les évaluations d’une composante utilisent leurs propres coefficients. Vérifiez ces paramètres avant enregistrement.</p><button type="submit">Enregistrer le trimestre</button></form></details>
@endif
@if($students)<h2>Documents individuels</h2><table><thead><tr><th>Élève</th><th>Matricule</th><th>Documents</th></tr></thead><tbody>@foreach($students as $student)<tr><td dir="auto">{{ $student->last_name }} {{ $student->first_name }}</td><td>{{ $student->matricule }}</td><td>
@foreach($terms as $term)<a href="{{ route('documents.bulletins',['tenant'=>$school->id,'term'=>$term->id,'cohort'=>$class->id,'enrollment'=>$student->id]) }}">Bulletin T{{ $term->trimester }}</a> @endforeach</td></tr>@endforeach</tbody></table><div class="no-print">{{ $students->links() }}</div>@endif
@else<p>Créez d’abord une classe et son année scolaire.</p>@endif</main>
@endsection
