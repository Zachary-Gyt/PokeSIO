@extends('wiki.layout')

@section('title', 'Wiki')

@section('wiki')
    <div class="box">
        <h1>Wiki</h1>
        <p class="lead">Tout ce qu'il faut savoir sur le monde du jeu.</p>
        @foreach (\App\Http\Controllers\WikiController::SECTIONS as $group => $list)
            <h2 style="margin-bottom:2px">{{ $group }}</h2>
            @foreach ($list as $section)
                <p style="margin:2px 0"><a href="{{ \App\Http\Controllers\WikiController::sectionUrl($section) }}"><strong>{{ $section['title'] }}</strong></a> — {{ $section['text'] }}</p>
            @endforeach
        @endforeach
    </div>
@endsection
