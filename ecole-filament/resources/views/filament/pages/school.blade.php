@php use App\Support\DemoStore as D; @endphp
<x-filament-panels::page>
@php $meta=$this->meta();$module=$this::$module;$kind=$meta['kind'];$metrics=$this->metrics(); @endphp
<div class="school-app">
<div class="school-context"><span><b class="context-square">É</b> {{ $this->preferences['name'] }} <span class="context-separator">/</span> {{ $this->preferences['city'] }}</span><span>{{ $this->preferences['year'] }} <span class="demo-pill">Démonstration</span></span></div>
@if($kind==='dashboard') @include('filament.pages.dashboard')
@elseif(in_array($kind,['list','schedule'])) @include('filament.pages.records')
@elseif(in_array($kind,['teacher','student'])) @include('filament.pages.portal')
@else @include('filament.pages.utilities') @endif
<footer class="school-footer"><span>Madrassa · École privée algérienne</span><span>Données fictives · modifications par session · aucun envoi externe</span></footer>
</div>
</x-filament-panels::page>
