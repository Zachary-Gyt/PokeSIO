@extends('layouts.app')

@section('title', 'Administration')

@php
    $actionLabels = [
        'kick' => 'Kick', 'ban' => 'Ban', 'unban' => 'Déban', 'mute' => 'Muet', 'unmute' => 'Parole rendue', 'professor' => 'Professeur', 'unprofessor' => 'Plus Professeur',
        'give' => 'Objet', 'money' => 'Argent', 'givepoke' => 'Pokémon', 'spawn' => 'Spawn',
        'heal' => 'Soin', 'tp' => 'Téléport.', 'tpici' => 'Téléport. ici',
        'flag' => 'Drapeau', 'variable' => 'Variable', 'forget' => 'Scénario effacé',   // étape 24
    ];
@endphp

@push('styles')
<style>
    .admin-page { display: grid; gap: 20px; width: 100%; max-width: 1100px; }
    .admin-page .box { max-width: none; }
    .admin-page h1 { margin: 0; }
    .admin-page code { font-family: var(--font); color: var(--blue); }
    .stats { display: flex; gap: 22px; flex-wrap: wrap; color: #707880; }
    .stats strong { color: var(--ink); }
    .search { display: flex; gap: 8px; margin: 10px 0 4px; align-items: center; flex-wrap: wrap; }
    .search input[type=text] { flex: 1; min-width: 200px; width: auto; }
    table.admin-table { width: 100%; border-collapse: collapse; font-size: 20px; }
    .admin-table th { text-align: left; color: #909098; border-bottom: 2px solid #d8d8d0; padding: 4px 6px; }
    .admin-table td { padding: 4px 6px; border-bottom: 1px solid #ecece4; vertical-align: top; }
    .tag { display: inline-block; padding: 0 6px 2px; color: #fff; text-shadow: none; font-size: 18px; }
    .tag.admin { background: #c03030; }
    .tag.professor { background: #3c8c3c; }
    .tag.banned { background: #303038; }
    .tag.muted { background: #a07020; }
    .tag.online { background: #2e8b3e; }
    .chars { color: #707880; font-size: 18px; }
    .ban-form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .ban-form select, .ban-form input { width: auto; height: 30px; font-size: 18px; line-height: 30px; padding: 0 6px; }
    .ban-form input { width: 160px; }
    .btn.small { font-size: 18px; padding: 2px 10px 5px; }
    .help { columns: 2 320px; font-size: 19px; margin: 0; padding-left: 18px; }
    .pagination { margin-top: 10px; }
    .pagination nav { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
@endpush

@section('content')
<div class="admin-page">
    <div class="box">
        <h1>Administration</h1>
        <div class="stats">
            <span><strong>{{ $online }}</strong> joueur(s) en ligne</span>
            <span><strong>{{ $banned }}</strong> compte(s) banni(s)</span>
            <span><strong>{{ $users->total() }}</strong> compte(s){{ $search !== '' ? ' trouvé(s)' : '' }}</span>
        </div>

        @if (session('status')) <div class="status" style="margin-top:10px">{{ session('status') }}</div> @endif
        @error('ban') <div class="error">{{ $message }}</div> @enderror

        <form class="search" method="GET" action="{{ route('admin.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="Nom, e-mail ou personnage">
            <label class="checkbox" style="margin:0"><input type="checkbox" name="filtre" value="bannis" @checked(request('filtre') === 'bannis')> Bannis</label>
            <button class="btn secondary small" type="submit">Chercher</button>
        </form>

        <table class="admin-table">
            <tr><th>Compte</th><th>Personnages</th><th>État</th><th>Modération</th></tr>
            @foreach ($users as $user)
                <tr>
                    <td>
                        {{ $user->name }} <span class="chars">#{{ $user->id }}</span><br>
                        <span class="chars">{{ $user->email }}</span>
                    </td>
                    <td class="chars">
                        @forelse ($user->characters as $character)
                            {{ $character->name }} #{{ $character->id }}
                            @if ($character->last_seen_at?->gt(now()->subMinutes(2))) <span class="tag online">en ligne</span> @endif
                            <br>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td>
                        @if ($user->is_admin) <span class="tag admin">Admin</span> @endif
                        @if ($user->is_professor) <span class="tag professor">Professeur</span> @endif
                        @if ($user->isBanned())
                            <span class="tag banned">banni {{ $user->banEndText() }}</span>
                            @if ($user->ban_reason) <br><span class="chars">{{ $user->ban_reason }}</span> @endif
                        @endif
                        @if ($user->isMuted()) <span class="tag muted">muet → {{ $user->muted_until->format('d/m H:i') }}</span> @endif
                    </td>
                    <td>
                        @if ($user->isBanned())
                            <form method="POST" action="{{ route('admin.unban', $user) }}">@csrf
                                <button class="btn secondary small" type="submit">Lever le ban</button>
                            </form>
                        @elseif (! $user->is_admin)
                            <form class="ban-form" method="POST" action="{{ route('admin.ban', $user) }}"
                                  onsubmit="return confirm('Bannir {{ addslashes($user->name) }} ?')">@csrf
                                <select name="duration">
                                    @foreach ($durations as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                                </select>
                                <input type="text" name="reason" maxlength="200" placeholder="Motif">
                                <button class="btn small" type="submit">Bannir</button>
                            </form>
                        @endif
                        {{-- Le grade Professeur : seulement un titre dans le chat --}}
                        <form method="POST" action="{{ route('admin.professor', $user) }}" style="margin-top:4px">@csrf
                            <button class="btn secondary small" type="submit">{{ $user->is_professor ? 'Retirer Professeur' : 'Nommer Professeur' }}</button>
                        </form>
                        @if ($user->isMuted())
                            <form method="POST" action="{{ route('admin.unmute', $user) }}" style="margin-top:4px">@csrf
                                <button class="btn secondary small" type="submit">Rendre la parole</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @if ($users->hasPages())
            <div class="pagination">
                @if (! $users->onFirstPage()) <a class="btn secondary small" href="{{ $users->previousPageUrl() }}">← Précédents</a> @endif
                <span>Page {{ $users->currentPage() }} / {{ $users->lastPage() }}</span>
                @if ($users->hasMorePages()) <a class="btn secondary small" href="{{ $users->nextPageUrl() }}">Suivants →</a> @endif
            </div>
        @endif
    </div>

    <div class="box">
        <h2 style="margin-top:0">Commandes en jeu</h2>
        <p style="margin:0 0 6px">Dans le jeu, touche <strong>T</strong> puis la commande. <code>&lt;joueur&gt;</code> = nom du personnage ou <code>#numéro</code>.
            Les noms en plusieurs mots s'écrivent entre guillemets : <code>/give Sacha "Super Bonbon" 5</code>.</p>
        <ul class="help">
            @foreach (\App\Services\AdminCommandService::HELP as $usage => $what)
                <li><code>{{ $usage }}</code> : {{ $what }}</li>
            @endforeach
        </ul>
    </div>

    <div class="box">
        <h2 style="margin-top:0">Journal (50 dernières actions)</h2>
        @if ($actions->isEmpty())
            <p style="margin:0">Aucune action pour l'instant.</p>
        @else
            <table class="admin-table">
                <tr><th>Date</th><th>Admin</th><th>Action</th><th>Cible</th><th>Détails</th></tr>
                @foreach ($actions as $action)
                    <tr>
                        <td class="chars">{{ $action->created_at->format('d/m H:i') }}</td>
                        <td>{{ $action->admin?->name ?? '—' }}</td>
                        <td>{{ $actionLabels[$action->action] ?? $action->action }}</td>
                        <td>{{ $action->targetCharacter?->name ?? $action->targetUser?->name ?? '—' }}</td>
                        <td class="chars">{{ $action->details }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>
@endsection
