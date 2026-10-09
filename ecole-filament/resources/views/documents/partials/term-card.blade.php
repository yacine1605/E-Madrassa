@php($student = (object)$card['student'])
<article class="sheet">
<header><p dir="rtl">الجمهورية الجزائرية الديمقراطية الشعبية</p><h2 dir="auto">{{ $school->name }}</h2><h1>كشف النقاط · Bulletin trimestriel</h1><p>الفصل / Trimestre {{ $term->trimester }} — {{ $class->year }}</p></header>
@if(collect($card['lines'])->contains(fn($line)=>$line['qualitative'] ? trim($line['observation']) === '' : (!$line['exempt'] && $line['average'] === null)))<p class="notice">Bulletin incomplet — notes ou observations à compléter / كشف غير مكتمل</p>@endif
<div class="identity"><p>الاسم واللقب / Élève : <b dir="auto">{{ $student->last_name }} {{ $student->first_name }}</b></p><p>رقم التسجيل / Matricule : {{ $student->matricule }}</p><p>القسم / Classe : <span dir="auto">{{ $class->name }}</span></p><p>المستوى / Niveau : <span dir="auto">{{ $class->level }}</span></p><p>Date de naissance : {{ $student->birth_date }}</p><p>Période : {{ $term->starts_on }} — {{ $term->ends_on }}</p></div>
<table><thead><tr><th>المادة<br>Matière</th><th>المعامل<br>Coeff.</th><th>التقويم المستمر<br>Continu</th><th>الفرض<br>Devoirs</th><th>الاختبار<br>Composition</th><th>المعدل<br>Moyenne /{{ $term->scale }}</th><th>الملاحظات<br>Observations</th></tr></thead><tbody>
@foreach($card['lines'] as $line)<tr><td dir="auto">{{ $line['name'] }}</td><td>{{ $line['qualitative'] ? '—' : $line['coefficient'] }}</td>
@foreach(['continu','devoir','examen'] as $kind)<td>{{ $line['qualitative'] || $line['components'][$kind] === null ? '—' : number_format($line['components'][$kind],2,',',' ') }}</td>@endforeach
<td>{{ $line['qualitative'] ? 'Évaluation qualitative' : ($line['exempt'] ? 'Dispensé' : ($line['average'] === null ? 'Incomplet' : number_format($line['average'],2,',',' '))) }}</td><td class="observation">{{ $line['observation'] }}@if($line['qualitative'] && !$line['observation']) À compléter @elseif(!$line['qualitative']) {{ implode(' ; ',$line['issues']) }} @endif</td></tr>@endforeach
</tbody></table><p class="result">المعدل العام / Moyenne générale : <strong>{{ $card['average'] === null ? 'Non calculable' : number_format($card['average'],2,',',' ').' / '.$term->scale }}</strong></p>
<p class="policy">Référence : {{ $term->reference }}. Coefficients et calcul configurés par l’établissement.</p>
@if(isset($archiveId))<p class="policy">Bulletin définitif · Archive {{ $archiveId }} · Clôturé le {{ $closedAt }}</p>@endif
<div class="signatures"><div>Observations du conseil de classe / ملاحظات مجلس القسم<br><br>................................................................</div><div>Direction : signature et cachet<br>الإدارة : الإمضاء والختم</div></div>
</article>
