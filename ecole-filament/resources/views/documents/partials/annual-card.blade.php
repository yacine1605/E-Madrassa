@php($student = (object)$card['student'])
<article class="sheet">
<header><p dir="rtl">الجمهورية الجزائرية الديمقراطية الشعبية</p><h2 dir="auto">{{ $school->name }}</h2><h1>الكشف السنوي · Bulletin annuel</h1><p>{{ $class->year }}</p></header>
<div class="identity"><p>الاسم واللقب / Élève : <b dir="auto">{{ $student->last_name }} {{ $student->first_name }}</b></p><p>رقم التسجيل / Matricule : {{ $student->matricule }}</p><p>القسم / Classe : <span dir="auto">{{ $class->name }}</span></p><p>المستوى / Niveau : <span dir="auto">{{ $class->level }}</span></p></div>
<table><thead><tr><th>المادة<br>Matière</th><th>Trimestre 1</th><th>Trimestre 2</th><th>Trimestre 3</th><th>Moyenne annuelle /{{ $scale }}</th></tr></thead><tbody>
@foreach($card['lines'] as $line)<tr><td dir="auto">{{ $line['name'] }}</td>@foreach([1,2,3] as $number)<td>{{ $line['terms'][$number] === null ? '—' : number_format($line['terms'][$number],2,',',' ') }}</td>@endforeach<td>{{ $line['average'] === null ? '—' : number_format($line['average'],2,',',' ') }}</td></tr>@endforeach
</tbody></table>
<table><thead><tr><th>Moyenne générale T1</th><th>Moyenne générale T2</th><th>Moyenne générale T3</th></tr></thead><tbody><tr>@foreach([1,2,3] as $number)<td>{{ $card['term_averages'][$number] === null ? 'Incomplète' : number_format($card['term_averages'][$number],2,',',' ') }}</td>@endforeach</tr></tbody></table>
<p class="result">المعدل السنوي / Moyenne annuelle : <strong>{{ $card['average'] === null ? 'Non calculable' : number_format($card['average'],2,',',' ').' / '.$scale }}</strong></p>
@if($card['average'] === null)<p class="notice">Bulletin annuel incomplet : trois moyennes trimestrielles chiffrées sont nécessaires.</p>@endif
<p class="policy">{{ $payload['reference'] }} Une moyenne de matière annuelle est affichée uniquement si trois moyennes chiffrées sont disponibles ; les dispenses et matières qualitatives ne deviennent pas des zéros.</p>
<p class="policy">Archives sources : @foreach($payload['sources'] as $source) T{{ $source['trimester'] }} : {{ $source['id'] }} @endforeach</p>
@if(isset($archiveId))<p class="policy">Bulletin définitif · Archive {{ $archiveId }} · Clôturé le {{ $closedAt }}</p>@endif
<div class="signatures"><div>Observations du conseil de classe / ملاحظات مجلس القسم<br><br>................................................................</div><div>Direction : signature et cachet<br>الإدارة : الإمضاء والختم</div></div>
</article>
