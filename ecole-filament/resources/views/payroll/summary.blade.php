@php use App\School\Services\TeacherPay as P; @endphp
<div class="pay-metrics">
<div class="card"><small>Heures validées</small><strong data-pay-validated>{{ P::duration($ledger['validated']) }}</strong></div>
<div class="card"><small>Heures en attente</small><strong data-pay-pending>{{ P::duration($ledger['pending']) }}</strong></div>
<div class="card"><small>{{ $ledger['run'] ? ($ledger['draft'] ? 'Rémunération calculée (brouillon)' : 'Rémunération validée') : 'Estimation sur heures validées' }}</small><strong data-pay-due>{{ P::money($ledger['run'] ? (int)$ledger['run']->amount_minor : $ledger['estimate']) }}</strong></div>
<div class="card"><small>Montant versé</small><strong data-pay-paid>{{ P::money($ledger['paid']) }}</strong></div>
<div class="card"><small>Reste à verser</small><strong data-pay-balance>{{ P::money($ledger['balance']) }}</strong></div>
</div>
<p class="pay-status" data-pay-status>{{ !$ledger['run'] ? 'À calculer' : ($ledger['draft'] ? 'Brouillon : une autre personne doit valider ce calcul' : ($ledger['balance'] === 0 ? 'Payé' : ($ledger['paid'] > 0 ? 'Partiellement payé' : 'À payer'))) }}</p>
@if($ledger['run'])<p>Base retenue : {{ $ledger['calculation']['basis'] === 'hourly' ? 'Horaire' : 'Mensuelle' }} · {{ P::money((int)$ledger['calculation']['rate_minor']) }}{{ $ledger['calculation']['basis'] === 'hourly' ? ' / heure' : ' / mois' }} · Heures retenues : {{ P::duration((int)$ledger['calculation']['minutes']) }}.</p>
@elseif($ledger['contract'])<p>Contrat couvrant le mois : {{ $ledger['contract']->basis === 'hourly' ? 'Horaire' : 'Mensuel' }} · {{ P::money((int)$ledger['contract']->rate_minor) }}{{ $ledger['contract']->basis === 'hourly' ? ' / heure' : ' / mois' }}. L’estimation n’est pas un versement.</p>
@else<p class="notice">Aucun contrat unique ne couvre tout le mois. La direction doit vérifier les dates du contrat avant de calculer la rémunération.</p>@endif
