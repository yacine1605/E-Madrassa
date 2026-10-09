@extends('documents.layout')
@section('title','Bulletins annuels')
@section('content')
@include('documents.partials.workflow')
<p class="no-print">Moyenne annuelle : (T1 + T2 + T3) / 3, sur les moyennes non arrondies des trois trimestres clôturés.</p>
@forelse($cards as $card)@include('documents.partials.annual-card')@empty<p>Aucun élève dans les archives trimestrielles de cette classe.</p>@endforelse
<div class="no-print">{{ $students->links() }}<p>Impression par lots de 50 élèves.</p></div>
@endsection
