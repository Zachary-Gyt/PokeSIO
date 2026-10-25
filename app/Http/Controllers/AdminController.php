<?php

namespace App\Http\Controllers;

use App\Events\PlayerNotice;
use App\Models\AdminAction;
use App\Models\Character;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La page /admin des administrateurs : les comptes (recherche, bannir, rendre la parole)
 * et le journal de toutes les actions de modération.
 * Les outils "en jeu" (donner un objet, /spawn, /tp...) sont des commandes du chat : AdminCommandService.
 *
 * Protégée par le middleware EnsureAdmin (routes/web.php).
 */
class AdminController extends Controller
{
    /** Durées proposées dans le formulaire de bannissement */
    public const BAN_DURATIONS = ['1h' => '1 heure', '1j' => '1 jour', '7j' => '7 jours', '30j' => '30 jours', 'def' => 'Définitif'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with(['characters' => fn ($q) => $q->select('id', 'user_id', 'slot', 'name', 'current_map', 'last_seen_at')])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('characters', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->when($request->query('filtre') === 'bannis', fn ($q) => $q->whereNotNull('banned_at'))
            ->orderByDesc('is_admin')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.index', [
            'users' => $users,
            'search' => $search,
            'actions' => AdminAction::with('admin:id,name', 'targetUser:id,name', 'targetCharacter:id,name')->latest('id')->limit(50)->get(),
            'online' => Character::where('last_seen_at', '>=', now()->subMinutes(2))->count(),
            'banned' => User::whereNotNull('banned_at')->get()->filter->isBanned()->count(),
            'durations' => self::BAN_DURATIONS,
        ]);
    }

    public function ban(Request $request, User $user)
    {
        $data = $request->validate([
            'duration' => ['required', Rule::in(array_keys(self::BAN_DURATIONS))],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        if ($user->is_admin) {
            return back()->withErrors(['ban' => 'Impossible de bannir un administrateur.']);
        }

        $until = match ($data['duration']) {
            '1h' => now()->addHour(),
            '1j' => now()->addDay(),
            '7j' => now()->addDays(7),
            '30j' => now()->addDays(30),
            default => null,
        };
        $user->ban($until, $data['reason'] ?? null);

        // S'il joue en ce moment : renvoyé tout de suite
        $message = 'Votre compte est banni ' . $user->banEndText() . '.' . (! empty($data['reason']) ? ' Motif : ' . $data['reason'] : '');
        foreach ($user->characters as $character) {
            PlayerNotice::send($character->id, 'kicked', ['message' => $message]);
        }
        AdminAction::record($request->user(), 'ban', $user, self::BAN_DURATIONS[$data['duration']] . (! empty($data['reason']) ? ' — ' . $data['reason'] : ''));

        return back()->with('status', "{$user->name} est banni {$user->banEndText()}.");
    }

    public function unban(Request $request, User $user)
    {
        $user->unban();
        AdminAction::record($request->user(), 'unban', $user);

        return back()->with('status', "{$user->name} n'est plus banni.");
    }

    /** Donner / retirer le grade Professeur (le titre dans le chat, sans privilège) */
    public function professor(Request $request, User $user)
    {
        $on = ! $user->is_professor;
        $user->forceFill(['is_professor' => $on])->save();
        AdminAction::record($request->user(), $on ? 'professor' : 'unprofessor', $user);

        return back()->with('status', $on ? "{$user->name} a maintenant le grade Professeur." : "{$user->name} n'est plus Professeur.");
    }

    public function unmute(Request $request, User $user)
    {
        $user->forceFill(['muted_until' => null])->save();
        AdminAction::record($request->user(), 'unmute', $user);

        return back()->with('status', "{$user->name} peut de nouveau écrire dans le chat.");
    }
}
