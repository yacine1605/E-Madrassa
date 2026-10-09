@extends('teacher.layout')
@section('title','Mes heures et ma rémunération')
@section('content')
<link rel="stylesheet" href="{{ asset('css/payroll.css') }}"><h1>Mes heures et ma rémunération</h1>
<form method="get" class="pay-form"><label>Mois<input type="month" name="month" value="{{ $month }}" required></label><button>Afficher</button></form>
@include('payroll.summary')
@if(!$ledger['run'] && $ledger['contracts']->isNotEmpty())<section class="card"><h2>Déclarer mes heures</h2><p>Déclarez la durée réellement travaillée. La direction doit valider ces heures avant le calcul de la rémunération.</p>@include('payroll.hour-form',['hourAction'=>route('teacher.pay.declare')])</section>@endif
<h2>Mes déclarations du mois</h2><div class="table-scroll"><table><thead><tr><th>Jour</th><th>Heures</th><th>État</th><th>Motif de la direction</th><th>Action</th></tr></thead><tbody>
@forelse($ledger['logs'] as $log)<tr><td>{{ $log->worked_on }}</td><td>{{ \App\School\Services\TeacherPay::duration($log->minutes) }}</td><td>{{ $log->validated_at ? 'Validées' : ($log->rejected_at ? 'Refusées' : 'En attente') }}</td><td>{{ $log->review_reason ?: '—' }}</td><td>@if(!$ledger['run'] && !$log->validated_at)<details><summary>Modifier</summary>@include('payroll.hour-form',['hourAction'=>route('teacher.pay.declare'),'editLog'=>$log])</details>@else Figées @endif</td></tr>@empty<tr><td colspan="5">Aucune heure déclarée pour ce mois.</td></tr>@endforelse
</tbody></table></div>
@include('payroll.payments')
@endsection
