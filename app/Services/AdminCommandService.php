<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\AdminAction;
use App\Models\Character;
use App\Models\Item;
use App\Models\PokemonSpecies;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Les COMMANDES DES MAÎTRES DU JEU, tapées dans le chat (touche T) par un compte administrateur :
 *
 *   /aide                                   la liste des commandes
 *   /qui                                    les joueurs connectés (et où ils sont)
 *   /kick <joueur> [motif]                  renvoie le joueur à l'écran de sélection
 *   /ban <joueur> <durée> [motif]           durée : 30m, 2h, 7j, ou "def" (définitif)
 *   /unban <joueur>
 *   /mute <joueur> <durée>                  plus de chat pendant la durée ; /unmute <joueur>
 *   /give <joueur> <objet> [quantité]       objet : nom ("Super Bonbon" entre guillemets) ou slug (rare-candy)
 *   /money <joueur> <montant>               négatif pour retirer
 *   /givepoke <joueur> <pokémon> <niveau> [shiny]
 *   /spawn <pokémon> <niveau> [joueur] [shiny]   combat sauvage forcé (soi-même par défaut)
 *   /heal [joueur]                          soigne l'équipe
 *   /tp <joueur>                            va voir le joueur ;   /tpici <joueur> : le fait venir
 *   /carte <carte> [x y]                    va sur une carte (étape 11)
 *   /heure [matin|jour|nuit|reel]           étape 23 : force le moment de la journée pour tous (2 h au plus)
 *   /drapeau <joueur> [nom] [on|off]        étape 24 : voir ou changer les drapeaux du scénario
 *   /variable <joueur> <nom> <valeur>       étape 24 : changer une variable du scénario
 *   /oublier <joueur>                       étape 24 : efface tout le scénario du joueur (drapeaux et variables)
 *   /prof <joueur> [on|off]                 donne / retire le grade Professeur (titre dans le chat)
 *
 * <joueur> : le nom du personnage, ou #12 (son numéro). Chaque commande réussie est notée dans
 * le journal (admin_actions), visible sur la page /admin.
 *
 * Toutes les vérifications sont faites ICI, côté serveur : le jeu n'envoie qu'un texte.
 */
class AdminCommandService
{
    public const HELP = [
        '/qui' => 'joueurs connectés',
        '/kick <joueur> [motif]' => 'renvoyer à l\'écran de sélection',
        '/ban <joueur> <30m|2h|7j|def> [motif]' => 'bannir le compte',
        '/unban <joueur>' => 'lever le bannissement',
        '/mute <joueur> <durée>' => 'interdire le chat (/unmute pour lever)',
        '/give <joueur> <objet> [qté]' => 'donner un objet',
        '/money <joueur> <montant>' => 'donner (ou retirer) de l\'argent',
        '/givepoke <joueur> <pokémon> <niv> [shiny]' => 'donner un Pokémon',
        '/spawn <pokémon> <niv> [joueur] [shiny]' => 'combat sauvage forcé',
        '/heal [joueur]' => 'soigner l\'équipe',
        '/tp <joueur>' => 'aller voir un joueur (/tpici : le faire venir)',
        '/carte <carte> [x y]' => 'aller sur une carte (ex. /carte field_test)',
        '/heure [matin|jour|nuit|reel]' => 'forcer le moment de la journée (pour tous)',
        '/drapeau <joueur> [nom] [on|off]' => 'voir ou changer les drapeaux du scénario',
        '/variable <joueur> <nom> <valeur>' => 'changer une variable du scénario',
        '/oublier <joueur>' => 'effacer le scénario du joueur (drapeaux, variables)',
        '/prof <joueur> [on|off]' => 'grade Professeur (titre dans le chat)',
    ];

    /** Un joueur est "connecté" si le jeu a sauvegardé depuis moins de 2 minutes (sauvegarde toutes les 30 s) */
    private const ONLINE_SECONDS = 120;

    private User $admin;

    private Character $self;

    public function __construct(
        private PokemonFactory $pokemonFactory,
        private BattleService $battles,
    ) {
    }

    /**
     * Exécute une commande. Renvoie les lignes de réponse, affichées seulement à l'admin.
     * Une erreur de saisie lance une InvalidAction (son message est la réponse).
     *
     * @return string[]
     */
    public function run(User $admin, Character $self, string $text): array
    {
        $this->admin = $admin;
        $this->self = $self;

        $args = $this->split($text);
        $command = Str::lower(ltrim(array_shift($args) ?? '', '/'));

        try {
            return match ($command) {
                'aide', 'help', '?' => $this->help(),
                'qui', 'who' => $this->online(),
                'kick' => $this->kick($args),
                'ban' => $this->ban($args),
                'unban' => $this->unban($args),
                'mute' => $this->mute($args),
                'unmute' => $this->unmute($args),
                'give' => $this->give($args),
                'money' => $this->money($args),
                'givepoke' => $this->givePokemon($args),
                'spawn' => $this->spawn($args),
                'heal' => $this->heal($args),
                'tp' => $this->teleport($args, bring: false),
                'tpici', 'tphere' => $this->teleport($args, bring: true),
                'carte', 'map' => $this->goToMap($args),
                'heure', 'time' => $this->time($args),
                'drapeau', 'flag' => $this->storyFlag($args),
                'variable', 'var' => $this->storyVariable($args),
                'oublier' => $this->forgetStory($args),
                'prof', 'professeur' => $this->professor($args),
                default => ["Commande inconnue : /{$command}. Tape /aide."],
            };
        } catch (InvalidAction $e) {
            return [$e->getMessage()];
        }
    }

    // =====================================================================
    // Les commandes
    // =====================================================================

    private function help(): array
    {
        return array_map(fn ($usage, $what) => "{$usage} : {$what}", array_keys(self::HELP), self::HELP);
    }

    private function online(): array
    {
        $characters = Character::where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))
            ->orderBy('name')->get(['id', 'name', 'current_map']);

        if ($characters->isEmpty()) {
            return ['Aucun joueur connecté.'];
        }

        return ["{$characters->count()} joueur(s) connecté(s) :",
            $characters->map(fn ($c) => "{$c->name} #{$c->id} ({$this->mapName($c->current_map)})")->join(', ')];
    }

    private function kick(array $args): array
    {
        $target = $this->target(array_shift($args), protect: true);
        $reason = $this->rest($args);

        PlayerNotice::send($target->id, 'kicked', [
            'message' => 'Un administrateur vous a déconnecté.' . ($reason ? " Motif : {$reason}" : ''),
        ]);
        AdminAction::record($this->admin, 'kick', $target, $reason);

        return ["{$target->name} : retour forcé à l'écran de sélection."];
    }

    private function ban(array $args): array
    {
        $target = $this->target(array_shift($args), protect: true);
        $durationText = array_shift($args) ?? throw new InvalidAction('Durée manquante : /ban <joueur> <30m|2h|7j|def> [motif]');
        $until = $this->duration($durationText, allowPermanent: true);
        $reason = $this->rest($args);

        $user = $target->user;
        $user->ban($until, $reason);

        // Tous ses personnages sont renvoyés ; à la requête suivante, le compte est déconnecté
        $message = 'Votre compte est banni ' . $user->banEndText() . '.' . ($reason ? " Motif : {$reason}" : '');
        foreach ($user->characters as $character) {
            PlayerNotice::send($character->id, 'kicked', ['message' => $message]);
        }
        AdminAction::record($this->admin, 'ban', $target, trim(($until ? $durationText : 'définitif') . ' — ' . ($reason ?? ''), ' —'));

        return ["Le compte de {$target->name} ({$user->email}) est banni {$user->banEndText()}."];
    }

    private function unban(array $args): array
    {
        $target = $this->target(array_shift($args));
        $target->user->unban();
        AdminAction::record($this->admin, 'unban', $target);

        return ["Le compte de {$target->name} n'est plus banni."];
    }

    private function mute(array $args): array
    {
        $target = $this->target(array_shift($args), protect: true);
        $durationText = array_shift($args) ?? throw new InvalidAction('Durée manquante : /mute <joueur> <30m|2h|7j>');
        $until = $this->duration($durationText, allowPermanent: false);

        $target->user->forceFill(['muted_until' => $until])->save();
        PlayerNotice::send($target->id, 'admin_message', ['message' => 'Un administrateur vous a retiré la parole jusqu\'à ' . $until->format('H:i') . ($until->isToday() ? '' : ' le ' . $until->format('d/m')) . '.']);
        AdminAction::record($this->admin, 'mute', $target, $durationText);

        return ["{$target->name} ne peut plus écrire dans le chat jusqu'au {$until->format('d/m à H:i')}."];
    }

    /** Le grade Professeur (le titre [Professeur] dans le chat) : sans argument, il s'inverse */
    private function professor(array $args): array
    {
        $target = $this->target(array_shift($args));
        $user = $target->user;
        $switch = Str::lower((string) array_shift($args));
        $on = match ($switch) {
            'on', 'oui' => true,
            'off', 'non' => false,
            '' => ! $user->is_professor,
            default => throw new InvalidAction('Utilisation : /prof <joueur> [on|off]'),
        };
        $user->forceFill(['is_professor' => $on])->save();
        AdminAction::record($this->admin, $on ? 'professor' : 'unprofessor', $target);
        $this->notify($target, $on ? 'Vous avez reçu le grade de Professeur !' : 'Vous n\'avez plus le grade de Professeur.');

        return [$on ? "{$target->name} a maintenant le grade Professeur." : "{$target->name} n'est plus Professeur."];
    }

    private function unmute(array $args): array
    {
        $target = $this->target(array_shift($args));
        $target->user->forceFill(['muted_until' => null])->save();
        PlayerNotice::send($target->id, 'admin_message', ['message' => 'Vous pouvez de nouveau écrire dans le chat.']);
        AdminAction::record($this->admin, 'unmute', $target);

        return ["{$target->name} peut de nouveau écrire dans le chat."];
    }

    private function give(array $args): array
    {
        $target = $this->target(array_shift($args));
        $itemName = array_shift($args) ?? throw new InvalidAction('Objet manquant : /give <joueur> <objet> [quantité]');
        $item = $this->findItem($itemName);
        $quantity = $this->integer(array_shift($args) ?? '1', 1, 999, 'La quantité');

        $target->addItem($item, $quantity);
        $this->notify($target, "Un administrateur vous a donné {$item->name} x{$quantity} !");
        AdminAction::record($this->admin, 'give', $target, "{$item->name} x{$quantity}");

        return ["{$target->name} a reçu {$item->name} x{$quantity}."];
    }

    private function money(array $args): array
    {
        $target = $this->target(array_shift($args));
        $amount = $this->integer(array_shift($args) ?? '', -Character::MAX_MONEY, Character::MAX_MONEY, 'Le montant');

        // money n'est pas "fillable" : on passe par les méthodes du modèle (bornes 0 … 999 999)
        if ($amount >= 0) {
            $target->addMoney($amount);
        } else {
            $target->spendMoney(min($target->money, -$amount));
        }
        $this->notify($target, $amount >= 0
            ? "Un administrateur vous a donné {$amount} ₽ !"
            : 'Un administrateur vous a retiré ' . (-$amount) . ' ₽.');
        AdminAction::record($this->admin, 'money', $target, (string) $amount);

        return ["{$target->name} a maintenant {$target->money} ₽."];
    }

    /** Étape 24 : /drapeau Sacha (la liste), /drapeau Sacha potions_recues on|off */
    private function storyFlag(array $args): array
    {
        $target = $this->target(array_shift($args));
        if (! $args) {
            $flags = array_keys(\App\Scenario\StoryState::flags($target));
            $vars = collect(\App\Scenario\StoryState::vars($target))->map(fn ($v, $k) => "{$k} = {$v}")->values()->all();

            return [
                "Drapeaux de {$target->name} : " . ($flags ? implode(', ', $flags) : 'aucun'),
                'Variables : ' . ($vars ? implode(', ', $vars) : 'aucune'),
            ];
        }
        try {
            $name = \App\Scenario\ScriptParser::name(array_shift($args), 'Nom du drapeau');
        } catch (\App\Scenario\ScriptError $e) {
            throw new InvalidAction($e->getMessage());
        }
        $on = ! in_array(Str::lower($args[0] ?? 'on'), ['off', 'non', '0', 'false'], true);
        \App\Scenario\StoryState::setFlag($target, $name, $on);
        $target->save();
        AdminAction::record($this->admin, 'flag', $target, "{$name} " . ($on ? 'on' : 'off'));

        return ["Drapeau {$name} " . ($on ? 'levé' : 'baissé') . " pour {$target->name} (visible au prochain changement de carte)."];
    }

    /** Étape 24 : /variable Sacha visites 3 */
    private function storyVariable(array $args): array
    {
        $target = $this->target(array_shift($args));
        try {
            $name = \App\Scenario\ScriptParser::name(array_shift($args) ?? '', 'Nom de la variable');
        } catch (\App\Scenario\ScriptError $e) {
            throw new InvalidAction($e->getMessage());
        }
        $value = $this->integer(array_shift($args) ?? '', -1000000, 1000000, 'La valeur');
        \App\Scenario\StoryState::setVar($target, $name, $value);
        $target->save();
        AdminAction::record($this->admin, 'variable', $target, "{$name} = {$value}");

        return ["Variable {$name} = {$value} pour {$target->name}."];
    }

    /** Étape 24 : /oublier Sacha : comme si le joueur n'avait jamais vu aucun événement */
    private function forgetStory(array $args): array
    {
        $target = $this->target(array_shift($args));
        $flags = $target->story_flags ?? [];
        unset($flags['scenario']);
        $target->story_flags = $flags;
        $target->save();
        AdminAction::record($this->admin, 'forget', $target, 'scénario');

        return ["Le scénario de {$target->name} est remis à zéro (au prochain changement de carte)."];
    }

    private function givePokemon(array $args): array
    {
        $target = $this->target(array_shift($args));
        $species = $this->findSpecies(array_shift($args));
        $level = $this->integer(array_shift($args) ?? '5', 1, 100, 'Le niveau');
        $shiny = $this->isShiny($args);

        $pokemon = $this->pokemonFactory->make($species, $level, ['shiny' => $shiny]);
        $given = $this->pokemonFactory->giveTo($target, $pokemon, ['location' => 'Cadeau d\'un administrateur']);
        if (! $given) {
            throw new InvalidAction("L'équipe et le PC de {$target->name} sont pleins.");
        }

        $where = $given->location === 'party' ? 'dans son équipe' : 'au PC';
        $label = $species->name . ($shiny ? ' chromatique' : '') . " N.{$level}";
        $this->notify($target, "Un administrateur vous a offert {$label} ! (" . ($given->location === 'party' ? 'équipe' : 'PC') . ')');
        AdminAction::record($this->admin, 'givepoke', $target, $label);

        return ["{$target->name} a reçu {$label} ({$where})."];
    }

    private function spawn(array $args): array
    {
        $species = $this->findSpecies(array_shift($args));
        $level = $this->integer(array_shift($args) ?? '5', 1, 100, 'Le niveau');
        $shiny = $this->isShiny($args);
        $args = array_values(array_filter($args, fn ($a) => ! in_array(Str::lower($a), ['shiny', 'chromatique'], true)));
        $target = $args ? $this->target($args[0]) : $this->self;

        $this->battles->startSpawn($target, $species, $level, $shiny);
        // Le jeu du joueur lance le combat dès qu'il est libre (pas en plein dialogue ni dans un menu)
        PlayerNotice::send($target->id, 'spawn');
        AdminAction::record($this->admin, 'spawn', $target, $species->name . ($shiny ? ' chromatique' : '') . " N.{$level}");

        return ["Un {$species->name} sauvage N.{$level} apparaît devant {$target->name} !"];
    }

    /** Étape 23 : /heure nuit (forcé pour tous les joueurs, 2 h au plus), /heure reel pour revenir à l'heure réelle */
    private function time(array $args): array
    {
        $word = Str::lower(Str::ascii($args[0] ?? ''));
        if ($word === '') {
            $c = \App\Services\TimeOfDay::clock();

            return [sprintf('Il est %02d h %02d : %s%s.', $c['hour'], $c['minute'], \App\Services\TimeOfDay::PERIODS[$c['period']], $c['forced'] ? ' (forcé)' : '')];
        }
        $period = ['matin' => 'morning', 'jour' => 'day', 'nuit' => 'night', 'reel' => null, 'real' => null][$word] ?? false;
        if ($period === false) {
            throw new InvalidAction('Utilisation : /heure matin, /heure jour, /heure nuit ou /heure reel');
        }
        \App\Services\TimeOfDay::force($period);
        AdminAction::record($this->admin, 'time', $this->self, $word);

        return [$period ? 'C\'est maintenant le ' . \App\Services\TimeOfDay::PERIODS[$period] . ' pour tout le monde (2 h au plus). Les joueurs le verront à leur prochain changement de carte.' : 'Retour à l\'heure réelle.'];
    }

    private function heal(array $args): array
    {
        $target = $args ? $this->target($args[0]) : $this->self;
        if ($this->battles->activeBattle($target)) {
            throw new InvalidAction("{$target->name} est en plein combat : réessaie après.");
        }

        $target->healParty();
        if ($target->id !== $this->self->id) {
            $this->notify($target, 'Un administrateur a soigné vos Pokémon !');
        }
        AdminAction::record($this->admin, 'heal', $target);

        return ["L'équipe de {$target->name} est soignée."];
    }

    /**
     * /tp : l'admin va à la position du joueur ; /tpici : le joueur vient à celle de l'admin.
     * La position est celle de la dernière sauvegarde (moins de 30 secondes).
     */
    private function teleport(array $args, bool $bring): array
    {
        $target = $this->target(array_shift($args));
        if ($target->id === $this->self->id) {
            throw new InvalidAction('Vous êtes déjà là !');
        }

        [$moving, $destination] = $bring ? [$target, $this->self->fresh()] : [$this->self, $target->fresh()];
        if ($this->battles->activeBattle($moving)) {
            throw new InvalidAction("{$moving->name} est en plein combat : réessaie après.");
        }

        $place = [
            'map' => $destination->current_map,
            'x' => (int) $destination->position_x,
            'y' => (int) $destination->position_y,
            'facing' => $destination->facing ?? 'down',
        ];
        $moving->forceFill([
            'current_map' => $place['map'],
            'position_x' => $place['x'],
            'position_y' => $place['y'],
        ])->save();

        PlayerNotice::send($moving->id, 'teleport', $place + [
            'message' => $bring ? 'Un administrateur vous fait venir !' : null,
        ]);
        AdminAction::record($this->admin, $bring ? 'tpici' : 'tp', $target, "{$place['map']} ({$place['x']}, {$place['y']})");

        return [$bring
            ? "{$target->name} arrive ({$this->mapName($place['map'])})."
            : "En route vers {$target->name} ({$this->mapName($place['map'])})."];
    }

    /** /carte field_test [x y] : l'admin va sur une carte (au point d'apparition si x et y manquent) */
    private function goToMap(array $args): array
    {
        $key = Str::lower($args[0] ?? '');
        $map = MapInfo::load($key) ?? throw new InvalidAction("Carte « {$key} » introuvable (nom du fichier .tmj, sans l'extension).");
        if ($this->battles->activeBattle($this->self)) {
            throw new InvalidAction('Vous êtes en plein combat.');
        }
        $spot = isset($args[1], $args[2])
            ? ['x' => $this->integer($args[1], 0, $map->width() - 1, 'x'), 'y' => $this->integer($args[2], 0, $map->height() - 1, 'y')]
            : ($map->spawn() ?? ['x' => 0, 'y' => 0]);

        $this->self->forceFill(['current_map' => $key, 'position_x' => $spot['x'], 'position_y' => $spot['y']])->save();
        PlayerNotice::send($this->self->id, 'teleport', ['map' => $key, 'x' => $spot['x'], 'y' => $spot['y'], 'facing' => 'down']);
        AdminAction::record($this->admin, 'tp', $this->self, "{$key} ({$spot['x']}, {$spot['y']})");

        return ["En route vers {$this->mapName($key)}."];
    }

    // =====================================================================
    // Lecture des arguments
    // =====================================================================

    /** Découpe la commande en mots ; "plusieurs mots" entre guillemets comptent pour un seul */
    private function split(string $text): array
    {
        preg_match_all('/"([^"]*)"|(\S+)/u', trim($text), $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => ($m[2] ?? '') !== '' ? $m[2] : $m[1], $matches);
    }

    private function rest(array $args): ?string
    {
        $text = trim(implode(' ', $args));

        return $text === '' ? null : mb_substr($text, 0, 200);
    }

    /**
     * Le personnage visé : "#12" (son numéro) ou son nom (sans tenir compte des majuscules).
     * protect : on ne sanctionne ni soi-même ni un autre admin.
     */
    private function target(?string $name, bool $protect = false): Character
    {
        if ($name === null || $name === '') {
            throw new InvalidAction('Joueur manquant (son nom ou #numéro).');
        }

        if (preg_match('/^#(\d+)$/', $name, $m)) {
            $character = Character::find((int) $m[1]);
        } else {
            $matches = Character::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get();
            if ($matches->count() > 1) {
                throw new InvalidAction("Plusieurs personnages s'appellent {$name} : utilise leur numéro ("
                    . $matches->map(fn ($c) => "#{$c->id}")->join(', ') . ').');
            }
            $character = $matches->first();
        }

        if (! $character) {
            throw new InvalidAction("Aucun personnage « {$name} ».");
        }
        if ($protect && ($character->user_id === $this->admin->id || $character->user?->is_admin)) {
            throw new InvalidAction('Impossible de sanctionner un administrateur.');
        }

        return $character;
    }

    /** "30m", "2h", "7j" (ou "7d") ; "def" / "perm" = définitif (renvoie null) */
    private function duration(string $text, bool $allowPermanent): ?Carbon
    {
        $text = Str::lower($text);
        if ($allowPermanent && in_array($text, ['def', 'definitif', 'définitif', 'perm'], true)) {
            return null;
        }
        if (! preg_match('/^(\d{1,4})(m|min|h|j|d)$/', $text, $m) || (int) $m[1] < 1) {
            throw new InvalidAction("Durée « {$text} » invalide : 30m, 2h, 7j" . ($allowPermanent ? ' ou def.' : '.'));
        }

        return match ($m[2]) {
            'm', 'min' => now()->addMinutes((int) $m[1]),
            'h' => now()->addHours((int) $m[1]),
            default => now()->addDays((int) $m[1]),
        };
    }

    private function integer(string $text, int $min, int $max, string $label): int
    {
        if (! preg_match('/^-?\d+$/', $text) || (int) $text < $min || (int) $text > $max) {
            throw new InvalidAction("{$label} doit être un nombre entre {$min} et {$max}.");
        }

        return (int) $text;
    }

    private function isShiny(array $args): bool
    {
        return collect($args)->contains(fn ($a) => in_array(Str::lower($a), ['shiny', 'chromatique'], true));
    }

    /** Un Pokémon par son nom (Salamèche, salameche), son slug (charmander) ou son numéro (4) */
    private function findSpecies(?string $name): PokemonSpecies
    {
        if ($name === null) {
            throw new InvalidAction('Pokémon manquant.');
        }
        $key = $this->normalize($name);
        $species = PokemonSpecies::all()->first(fn ($s) => $s->slug === Str::lower($name)
            || (string) $s->pokedex_number === ltrim($name, '0#')
            || $this->normalize($s->name) === $key);

        return $species ?? throw new InvalidAction("Pokémon « {$name} » inconnu.");
    }

    /** Un objet par son nom français ("Super Bonbon", super_bonbon) ou son slug (rare-candy) */
    private function findItem(string $name): Item
    {
        $key = $this->normalize($name);
        $item = Item::all()->first(fn ($i) => $i->slug === Str::lower($name)
            || $this->normalize($i->name) === $key
            || $this->normalize($i->slug) === $key);

        return $item ?? throw new InvalidAction("Objet « {$name} » inconnu.");
    }

    /** "Pokéball", "poke_ball", "Poké Ball" -> "pokeball" : on compare sans accents, espaces ni tirets */
    private function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }

    private function mapName(?string $mapKey): string
    {
        return ($mapKey ? MapInfo::load($mapKey)?->property('displayName') : null) ?? $mapKey ?? '?';
    }

    /** Un petit message dans le jeu du joueur concerné (sauf si c'est l'admin lui-même) */
    private function notify(Character $target, string $message): void
    {
        if ($target->id !== $this->self->id) {
            PlayerNotice::send($target->id, 'admin_message', ['message' => $message]);
        }
    }
}
