<?php

namespace App\Http\Controllers;

use App\Battle\InvalidAction;
use App\Events\ChatMessage;
use App\Models\Character;
use App\Services\AdminCommandService;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Le CHAT : quatre canaux.
 *
 *   Message                    LOCAL    lu par les joueurs de la même carte
 *   /all Message (/monde)      GLOBAL   lu par tous les joueurs connectés
 *   /msg Pseudo Message (/mp)  PRIVÉ    lu par ce joueur seulement (/r Message : répondre au dernier)
 *   /g Message                 GROUPE   lu par les membres de mon groupe
 *
 * Commandes du groupe : /inviter Pseudo, /quitter, /exclure Pseudo, /chef Pseudo, /groupe (les membres).
 * /aide : la liste. Les autres commandes ("/heal"...) sont celles des administrateurs (AdminCommandService).
 *
 * Tout passe PAR LARAVEL : longueur, nettoyage, anti-spam, joueur muet. Chaque canal garde ses 20 derniers
 * messages (cache) pour ceux qui arrivent. Les messages des admins portent "admin": true (nom en rouge).
 */
class ChatController extends Controller
{
    public const MAX_LENGTH = 120;

    public const HELP = [
        'Message' => 'à la carte (local)',
        '/all Message' => 'à tous les joueurs (global)',
        '/msg Pseudo Message' => 'message privé (/r Message : répondre)',
        '/g Message' => 'à votre groupe',
        '/inviter Pseudo' => 'inviter dans votre groupe',
        '/groupe' => 'les membres de votre groupe',
        '/quitter' => 'quitter votre groupe',
        '/exclure Pseudo' => 'exclure un membre (chef)',
        '/chef Pseudo' => 'donner la place de chef (chef)',
    ];

    public function __construct(private GroupService $groups)
    {
    }

    /** Les derniers messages que je peux lire : ma carte, le global, mon groupe, mes messages privés */
    public function index(Request $request, Character $character)
    {
        $this->authorizeCharacter($request, $character);
        $map = $this->mapKey($request->query('map', ''));

        $messages = array_merge(
            $map ? Cache::get("chat:{$map}", []) : [],
            Cache::get('chat:global', []),
            $character->group_id ? Cache::get("chat:group:{$character->group_id}", []) : [],
            Cache::get("chat:pm:{$character->id}", []),
        );
        usort($messages, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));

        return response()->json(['messages' => array_slice($messages, -30), 'group' => $character->group_id]);
    }

    public function send(Request $request, Character $character, AdminCommandService $commands)
    {
        $this->authorizeCharacter($request, $character);
        $user = $request->user();
        $data = $request->validate([
            'map' => ['required', 'string', 'max:100'],
            'text' => ['required', 'string', 'max:500'],
        ]);
        $text = ltrim($data['text']);

        try {
            if (! str_starts_with($text, '/')) {
                $map = $this->mapKey($data['map']) ?? throw new InvalidAction('Carte inconnue.');

                return $this->post($request, $character, 'local', $map, $text);
            }

            [$command, $rest] = array_pad(preg_split('/\s+/u', mb_substr($text, 1), 2), 2, '');
            $command = Str::lower($command);
            $rest = trim($rest);

            switch ($command) {
                case 'all': case 'monde': case 'global': case 'tous':
                    return $this->post($request, $character, 'global', null, $rest);

                case 'g': case 'groupe': case 'group':
                    if ($rest === '') {
                        return $this->reply($this->groupLines($character));
                    }
                    $character->group_id ?: throw new InvalidAction('Vous n\'êtes dans aucun groupe. /inviter Pseudo pour en créer un.');

                    return $this->post($request, $character, 'group', $character->group_id, $rest);

                case 'msg': case 'mp': case 'w': case 'whisper':
                    [$target, $message] = $this->target($rest);

                    return $this->privateMessage($request, $character, $target, $message);

                case 'r': case 'repondre':
                    $id = Cache::get("chat:last_pm:{$character->id}") ?? throw new InvalidAction('Personne ne vous a écrit en privé.');

                    return $this->privateMessage($request, $character, Character::findOrFail($id), $rest);

                case 'inviter': case 'invite': case 'inv':
                    [$target] = $this->target($rest, needsMessage: false);
                    $this->groups->invite($character, $target);

                    return $this->reply(["Invitation envoyée à {$target->name}."]);

                case 'quitter': case 'leave':
                    $this->groups->leave($character);

                    return $this->reply([]);

                case 'exclure':
                    [$target] = $this->target($rest, needsMessage: false);
                    $this->groups->kick($character, $target);

                    return $this->reply([]);

                case 'chef': case 'lead':
                    [$target] = $this->target($rest, needsMessage: false);
                    $this->groups->promote($character, $target);

                    return $this->reply([]);

                case 'aide': case 'help': case '?':
                    $lines = array_map(fn ($usage, $what) => "{$usage} : {$what}", array_keys(self::HELP), self::HELP);
                    if ($user->is_admin) {
                        $lines = [...$lines, '— Administrateur —', ...$commands->run($user, $character, '/aide')];
                    }

                    return $this->reply($lines);
            }

            // Les commandes des administrateurs : jamais diffusées, réponse privée
            if (! $user->is_admin) {
                $adminCommands = array_map(fn ($usage) => ltrim(strtok($usage, ' '), '/'), array_keys(AdminCommandService::HELP));
                return $this->reply(in_array($command, $adminCommands, true)
                    ? ['Les commandes sont réservées aux administrateurs.']
                    : ["Commande inconnue : /{$command}. Tapez /aide."]);
            }

            return $this->reply($commands->run($user, $character, $text));
        } catch (InvalidAction $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ---------------------------------------------------------------------

    /** Envoie un message sur un canal (local, global, group) */
    private function post(Request $request, Character $character, string $channel, string|int|null $key, string $raw, array $extra = [], ?array $privateTo = null)
    {
        $user = $request->user();
        if ($user->isMuted()) {
            return response()->json(['message' => 'Un administrateur vous a retiré la parole jusqu\'à ' . $user->muted_until->format('H:i') . '.'], 403);
        }

        // Anti-spam : 5 messages toutes les 10 secondes au maximum (tous canaux confondus)
        $rate = config('chat.rate', ['messages' => 5, 'seconds' => 10]);
        $limiter = "chat:{$character->id}";
        if (RateLimiter::tooManyAttempts($limiter, (int) $rate['messages'])) {
            return response()->json(['message' => 'Doucement ! Attendez un peu avant de réécrire.'], 429);
        }

        $text = $this->clean($raw);
        if ($text === '') {
            return response()->json(['message' => 'Message vide.'], 422);
        }
        RateLimiter::hit($limiter, (int) $rate['seconds']);

        $message = [
            'id' => $character->id,
            'name' => $character->name,
            'text' => $text,
            'channel' => $channel,
            'at' => now()->format('H:i'),
            'ts' => microtime(true),
            'admin' => (bool) $user->is_admin,
            'professor' => (bool) $user->is_professor,   // grade Professeur : titre [Professeur]
        ] + $extra;

        // Historique (pour ceux qui arrivent) et diffusion
        $keys = match ($channel) {
            'local' => ["chat:{$key}"],
            'global' => ['chat:global'],
            'group' => ["chat:group:{$key}"],
            'private' => array_map(fn ($id) => "chat:pm:{$id}", $privateTo),
        };
        foreach ($keys as $cacheKey) {
            $history = Cache::get($cacheKey, []);
            $history[] = $message;
            Cache::put($cacheKey, array_slice($history, -(int) config('chat.history', 20)), now()->addHours(6));
        }

        try {
            broadcast(new ChatMessage($channel, $privateTo ?? $key, $message));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    private function privateMessage(Request $request, Character $from, Character $to, string $text)
    {
        if ($to->id === $from->id) {
            throw new InvalidAction('Vous ne pouvez pas vous écrire à vous-même !');
        }
        if (! $to->isOnline()) {
            throw new InvalidAction("{$to->name} n'est pas connecté.");
        }
        $response = $this->post($request, $from, 'private', null, $text, ['toId' => $to->id, 'to' => $to->name], [$from->id, $to->id]);
        if ($response->getStatusCode() === 200) {
            Cache::put("chat:last_pm:{$to->id}", $from->id, now()->addHours(6));   // pour /r
        }

        return $response;
    }

    /**
     * Le joueur visé au début du texte, et le reste (le message). Le nom peut contenir des espaces :
     * "Sacha Ketchum" entre guillemets, #12 (son numéro), ou simplement tapé (le nom existant le plus long gagne).
     *
     * @return array{0: Character, 1: string}
     */
    private function target(string $text, bool $needsMessage = true): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidAction('Pseudo du joueur manquant.');
        }

        $found = null;
        $rest = '';
        if (preg_match('/^"([^"]+)"\s*(.*)$/su', $text, $m) || preg_match('/^#(\d+)\s*(.*)$/su', $text, $m)) {
            $found = str_starts_with($text, '#') ? Character::find((int) $m[1]) : $this->byName($m[1]);
            $rest = $m[2];
        } else {
            $words = preg_split('/\s+/u', $text);
            for ($n = min(count($words), 4); $n >= 1 && ! $found; $n--) {
                $found = $this->byName(implode(' ', array_slice($words, 0, $n)));
                $rest = implode(' ', array_slice($words, $n));
            }
        }

        if (! $found) {
            throw new InvalidAction('Joueur introuvable : ' . Str::limit(strtok($text, ' '), 30) . '.');
        }
        if ($needsMessage && trim($rest) === '') {
            throw new InvalidAction("Message vide. Exemple : /msg {$found->name} Salut !");
        }

        return [$found, trim($rest)];
    }

    private function byName(string $name): ?Character
    {
        return Character::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }

    /** /groupe : les membres, connectés ou non, et où ils sont */
    private function groupLines(Character $character): array
    {
        $view = $this->groups->view($character);
        if (! $view) {
            return ['Vous n\'êtes dans aucun groupe. /inviter Pseudo pour en créer un.'];
        }

        return ["Groupe ({$this->count($view)}/{$view['max']}) :", ...array_map(
            fn ($m) => ($m['leader'] ? '★ ' : '') . $m['name'] . ' — ' . ($m['online'] ? $m['map'] : 'hors ligne'),
            $view['members'],
        )];
    }

    private function count(array $view): int
    {
        return count($view['members']);
    }

    /** Pas de caractères invisibles, pas d'espaces en trop, 120 caractères maximum */
    private function clean(string $text): string
    {
        $text = preg_replace('/[\p{C}]+/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, (int) config('chat.max_length', self::MAX_LENGTH));
    }

    private function reply(array $lines)
    {
        return response()->json(['reply' => $lines]);
    }

    /** Le nom de carte doit correspondre à un vrai fichier de carte (public/game/maps/xxx.tmj) */
    private function mapKey(string $map): ?string
    {
        return preg_match('/^[a-z0-9_]+$/', $map) && File::exists(public_path("game/maps/{$map}.tmj")) ? $map : null;
    }

    private function authorizeCharacter(Request $request, Character $character): void
    {
        abort_unless($character->user_id === $request->user()->id, 404);
    }
}
