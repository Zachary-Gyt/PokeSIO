@extends('layouts.app')

{{-- Mise en page commune du wiki : un menu des rubriques à gauche, le contenu à droite --}}

@push('styles')
<style>
    .wiki { width: 100%; max-width: 1100px; display: grid; grid-template-columns: 220px 1fr; gap: 18px; align-items: start; }
    .wiki-menu .box { max-width: none; }
    .wiki-menu h2 { margin-top: 0; }
    .wiki-menu a { display: block; color: var(--ink); text-decoration: none; padding: 2px 0 2px 20px; position: relative; }
    .wiki-menu a.active::before, .wiki-menu a:hover::before {
        content: ''; position: absolute; left: 0; top: 6px; width: 16px; height: 16px;
        background: url('{{ asset('game/ui/sel_arrow.png') }}') 0 0 / 100% 100%; image-rendering: pixelated;
    }
    .wiki-content .box { max-width: none; }
    .wiki-group { color: #909098; font-size: 18px; margin: 10px 0 2px; border-bottom: 2px solid #e4e4dc; }
    .wiki-group:first-of-type { margin-top: 4px; }
    /* Tableaux et encadrés communs à toutes les pages du wiki */
    table.wiki-table { width: 100%; border-collapse: collapse; font-size: 20px; margin: 6px 0 10px; }
    .wiki-table th { text-align: left; color: #909098; font-weight: normal; border-bottom: 2px solid #d8d8d0; padding: 2px 6px; }
    .wiki-table td { padding: 2px 6px; border-bottom: 1px solid #ecece4; vertical-align: top; }
    .note { border-left: 4px solid var(--blue); background: #eef3fc; padding: 6px 10px; margin: 8px 0; font-size: 20px; }
    .wiki-text p, .wiki-text li { font-size: 21px; }
    .wiki-text h2 { margin: 22px 0 6px; }
    .wiki-text ul { margin: 4px 0 8px; padding-left: 22px; }
    .wiki-tools { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
    .wiki-tools input { max-width: 280px; }
    .wiki-tools select { max-width: 200px; }
    .muted { color: #909098; }
    .mon-icon { width: 32px; height: 32px; object-fit: cover; object-position: left; image-rendering: pixelated; vertical-align: middle; }
    .type-badge { display: inline-block; min-width: 72px; padding: 0 6px; text-align: center; color: #fff; font-size: 18px;
        text-shadow: 1px 1px 0 rgba(0,0,0,.45); border: 2px solid rgba(0,0,0,.25); }
    @media (max-width: 760px) { .wiki { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <div class="wiki">
        <aside class="wiki-menu">
            <div class="box">
                <h2><a href="{{ route('wiki') }}" style="padding:0">Wiki</a></h2>
                {{-- Les rubriques, par groupe (WikiController::SECTIONS) --}}
                @foreach (\App\Http\Controllers\WikiController::SECTIONS as $group => $list)
                    <div class="wiki-group">{{ $group }}</div>
                    @foreach ($list as $section)
                        <a href="{{ \App\Http\Controllers\WikiController::sectionUrl($section) }}" class="{{ \App\Http\Controllers\WikiController::isCurrent($section) ? 'active' : '' }}">{{ $section['title'] }}</a>
                    @endforeach
                @endforeach
            </div>
        </aside>
        <div class="wiki-content">
            @yield('wiki')
        </div>
    </div>
@endsection
