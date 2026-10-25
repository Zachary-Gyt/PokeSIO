<?php

namespace App\Scenario;

use Illuminate\Support\Str;

/**
 * Étape 24 : lit un fichier de SCÉNARIO (resources/scenario/*.txt) et le transforme en une liste
 * d'instructions simples, que EventRunner exécute une par une.
 *
 * Exemple de fichier :
 *
 *   # Une ligne qui commence par # est un commentaire
 *   evenement professeur
 *       si drapeau potions_recues
 *           dire Alors, ces Potions ?
 *       sinon
 *           dire Bonjour {joueur} ! Tiens, prends ça.
 *           donner objet potion 5
 *           drapeau potions_recues
 *       fin
 *   fin
 *
 * Les blocs (evenement, si, menu) se ferment par "fin". L'indentation n'est pas obligatoire
 * (mais elle aide beaucoup à se relire). Les mots-clés s'écrivent avec ou sans accents.
 *
 * Pourquoi "compiler" en instructions ? Un événement peut s'arrêter au milieu (une question au
 * joueur, un combat) et reprendre plus tard : avec une liste numérotée, il suffit de retenir le
 * numéro de la prochaine instruction. Les "si" deviennent des sauts :
 *
 *   0  jz   [drapeau potions_recues] -> 3     (saute à 3 si la condition est FAUSSE)
 *   1  say  "Alors, ces Potions ?"
 *   2  jmp  -> 6                              (fin du "si" : on saute le "sinon")
 *   3  say  "Bonjour {joueur} !..."
 *   4  give_item potion 5
 *   5  flag potions_recues
 *   6  (fin de l'événement)
 */
class ScriptParser
{
    public const DIRECTIONS = ['haut' => 'up', 'bas' => 'down', 'gauche' => 'left', 'droite' => 'right'];

    public const TIMES = ['matin' => 'morning', 'jour' => 'day', 'nuit' => 'night'];

    public const DAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    public const RESULTS = ['victoire' => 'win', 'defaite' => 'loss', 'fuite' => 'fled', 'capture' => 'caught'];

    /** Commandes jouées par le navigateur seulement (rien ne change sur le serveur) */
    private const CLIENT = ['deplacer', 'regarder', 'exclamation', 'cacher', 'montrer', 'attendre', 'musique', 'jingle', 'son', 'fondu', 'secouer'];

    private array $events = [];

    private array $errors = [];

    private ?string $current = null;   // nom de l'événement en cours de lecture

    private array $code = [];

    private array $stack = [];         // blocs ouverts : si, menu

    private int $line = 0;

    /**
     * @return array{events: array<string, array{line: int, code: array}>, errors: string[]}
     */
    public function parse(string $text): array
    {
        $this->events = [];
        $this->errors = [];
        $this->current = null;

        foreach (preg_split('/\R/u', $text) as $i => $raw) {
            $this->line = $i + 1;
            $line = trim(str_replace("\t", ' ', $raw));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$word, $rest] = self::cut($line);
            try {
                $this->statement(self::norm($word), $rest);
            } catch (ScriptError $e) {
                $this->errors[] = "ligne {$this->line} : {$e->getMessage()}";
            }
        }

        if ($this->current !== null) {
            $this->errors[] = "fin du fichier : l'événement \"{$this->current}\" n'est pas fermé (il manque \"fin\").";
            $this->closeEvent();
        }

        return ['events' => $this->events, 'errors' => $this->errors];
    }

    /**
     * Une condition seule (propriétés Tiled show_if / hide_if) : "potions_recues" (un simple nom = un drapeau)
     * ou "badge 2 et pas drapeau garde_parti". Renvoie les parties ; lance ScriptError si elle est mal écrite.
     * Les conditions qui n'ont pas de sens en dehors d'un événement (question, résultat, hasard) sont refusées.
     */
    public function parseCondition(string $text): array
    {
        $terms = [];
        foreach (self::splitAnd(trim($text)) as $part) {
            // Un simple nom (avec "pas" ou "!" devant) : un drapeau. "garde_parti et pas lina_battue"
            if (preg_match('/^(!|pas\s+|non\s+)?([a-z0-9_]+)$/iu', $part, $m) && ! in_array(self::norm($m[2]), ['garcon', 'fille'], true)) {
                $terms[] = [$m[1] !== '', 'flag', [self::name($m[2], 'nom du drapeau')]];
                continue;
            }
            $term = $this->term($part);
            if (in_array($term[1], ['question', 'result', 'chance'], true)) {
                throw new ScriptError("« {$part} » n'est pas permis ici (seulement dans un événement).");
            }
            $terms[] = $term;
        }
        if (! $terms) {
            throw new ScriptError('condition vide.');
        }

        return $terms;
    }

    // =====================================================================
    // Une ligne
    // =====================================================================

    private function statement(string $word, string $rest): void
    {
        if ($this->current === null) {
            if ($word !== 'evenement') {
                throw new ScriptError("\"{$word}\" en dehors d'un événement. Un fichier contient des blocs « evenement nom » ... « fin ».");
            }
            $name = self::name($rest, 'nom de l\'événement');
            if (isset($this->events[$name])) {
                throw new ScriptError("l'événement \"{$name}\" existe déjà dans ce fichier.");
            }
            $this->current = $name;
            $this->code = [];
            $this->stack = [];
            $this->events[$name] = ['line' => $this->line, 'code' => []];

            return;
        }

        // Dans un menu, avant le premier "choix", seul "choix" est permis
        $top = end($this->stack);
        if ($top && $top['type'] === 'menu' && $top['count'] === 0 && ! in_array($word, ['choix', 'fin'], true)) {
            throw new ScriptError('dans un menu, la ligne suivante doit être « choix Libellé ».');
        }

        match ($word) {
            'evenement' => throw new ScriptError('un événement ne peut pas en contenir un autre (il manque un « fin » plus haut ?).'),
            'fin' => $this->end(),
            'si' => $this->openIf($rest),
            'sinon' => $this->otherwise($rest),
            'menu' => $this->openMenu($rest),
            'choix' => $this->choice($rest),
            'dire', 'message' => $this->emit(['say', self::text($rest, $word), $word === 'message']),
            'nom' => $this->emit(['speaker', trim(self::unquote($rest)) ?: null]),
            'donner' => $this->give($rest),
            'prendre' => $this->take($rest),
            'drapeau' => $this->emit(['flag', self::name($rest, 'nom du drapeau'), true]),
            'enlever', 'retirer' => $this->removeFlag($rest),
            'variable' => $this->variable($rest),
            'soigner' => $this->emit(['heal']),
            'combat' => $this->battle($rest),
            'teleporter' => $this->teleport($rest),
            'cri' => $this->emit(['cry', self::slug($rest, 'Pokémon')]),
            'arreter', 'stop' => $this->emit(['stop']),
            default => in_array($word, self::CLIENT, true)
                ? $this->emit(['client', $this->clientStep($word, $rest)])
                : throw new ScriptError("commande inconnue : \"{$word}\"."),
        };
    }

    private function emit(array $instruction): int
    {
        $instruction[] = $this->line;   // dernier élément : la ligne du fichier (messages d'erreur)
        $this->code[] = $instruction;

        return count($this->code) - 1;
    }

    private function here(): int
    {
        return count($this->code);
    }

    /** Fixe la destination d'un saut déjà écrit (jmp : position 1 ; jz : position 2) */
    private function patch(array $indexes, int $to): void
    {
        foreach ($indexes as $i) {
            $this->code[$i][$this->code[$i][0] === 'jmp' ? 1 : 2] = $to;
        }
    }

    // =====================================================================
    // Blocs
    // =====================================================================

    private function end(): void
    {
        $block = array_pop($this->stack);
        if ($block === null) {
            $this->closeEvent();

            return;
        }
        if ($block['type'] === 'menu' && $block['count'] === 0) {
            throw new ScriptError('un menu doit avoir au moins un « choix ».');
        }
        $this->patch($block['next'], $this->here());
        $this->patch($block['end'], $this->here());
    }

    private function closeEvent(): void
    {
        foreach (array_reverse($this->stack) as $block) {
            $this->errors[] = "ligne {$block['line']} : ce « {$block['type']} » n'est pas fermé (il manque « fin »).";
            $this->patch($block['next'], $this->here());
            $this->patch($block['end'], $this->here());
        }
        $this->events[$this->current]['code'] = $this->code;
        $this->current = null;
        $this->stack = [];
    }

    private function openIf(string $rest): void
    {
        try {
            $next = $this->condition($rest);
        } catch (ScriptError $e) {
            // Le bloc est quand même ouvert : son "fin" ne doit pas fermer l'événement (une erreur, pas dix)
            $this->stack[] = ['type' => 'si', 'line' => $this->line, 'next' => [], 'end' => [], 'else' => false];
            throw $e;
        }
        $this->stack[] = ['type' => 'si', 'line' => $this->line, 'next' => $next, 'end' => [], 'else' => false];
    }

    private function otherwise(string $rest): void
    {
        $i = count($this->stack) - 1;
        if ($i < 0 || $this->stack[$i]['type'] !== 'si') {
            throw new ScriptError('« sinon » sans « si » au-dessus.');
        }
        if ($this->stack[$i]['else']) {
            throw new ScriptError('il y a déjà un « sinon » dans ce « si ».');
        }
        // La branche précédente est finie : elle saute à la fin du "si"
        $this->stack[$i]['end'][] = $this->emit(['jmp', null]);
        $this->patch($this->stack[$i]['next'], $this->here());

        [$word, $cond] = self::cut($rest);
        if (self::norm($word) === 'si') {
            $this->stack[$i]['next'] = [];
            $this->stack[$i]['next'] = $this->condition($cond);   // sinon si ...
        } elseif (trim($rest) === '') {
            $this->stack[$i]['next'] = [];
            $this->stack[$i]['else'] = true;
        } else {
            throw new ScriptError('après « sinon », rien ou « si ... ».');
        }
    }

    private function openMenu(string $rest): void
    {
        $at = $this->emit(['menu', self::text($rest, 'menu', allowEmpty: true), []]);
        $this->stack[] = ['type' => 'menu', 'line' => $this->line, 'at' => $at, 'count' => 0, 'next' => [], 'end' => []];
    }

    private function choice(string $rest): void
    {
        $i = count($this->stack) - 1;
        if ($i < 0 || $this->stack[$i]['type'] !== 'menu') {
            throw new ScriptError('« choix » en dehors d\'un « menu ».');
        }
        $label = self::text($rest, 'choix');
        if ($this->stack[$i]['count'] > 0) {
            $this->stack[$i]['end'][] = $this->emit(['jmp', null]);
        }
        $this->patch($this->stack[$i]['next'], $this->here());
        $this->code[$this->stack[$i]['at']][2][] = $label;
        $this->stack[$i]['next'] = [$this->emit(['jz', [[false, 'choice', [$this->stack[$i]['count']]]], null])];
        $this->stack[$i]['count']++;
    }

    /**
     * Une condition : "drapeau x et pas badge 2 et question Tu viens ?".
     * Écrit les sauts nécessaires ; renvoie les sauts à faire pointer vers la branche suivante.
     */
    private function condition(string $text): array
    {
        $terms = [];
        $question = null;
        foreach (self::splitAnd($text) as $part) {
            if ($question !== null) {
                throw new ScriptError('« question » doit être la dernière partie de la condition.');
            }
            $term = $this->term($part);
            if ($term[1] === 'question') {
                if ($term[0]) {
                    throw new ScriptError('« pas question » n\'existe pas : utilise « si question ... » puis « sinon ».');
                }
                $question = $term[2][0];
            } else {
                $terms[] = $term;
            }
        }
        if (! $terms && $question === null) {
            throw new ScriptError('condition vide après « si ».');
        }

        $jumps = [];
        if ($terms) {
            $jumps[] = $this->emit(['jz', $terms, null]);
        }
        if ($question !== null) {
            $this->emit(['ask', $question]);
            $jumps[] = $this->emit(['jz', [[false, 'choice', [0]]], null]);   // 0 = Oui
        }

        return $jumps;
    }

    /** Une partie de condition : [négation, type, arguments] */
    private function term(string $text): array
    {
        [$word, $rest] = self::cut($text);
        $word = self::norm($word);
        $not = false;
        if ($word === 'pas' || $word === 'non') {
            $not = true;
            [$word, $rest] = self::cut($rest);
            $word = self::norm($word);
        }
        $args = self::words($rest);

        $term = match ($word) {
            'drapeau' => ['flag', [self::name($rest, 'nom du drapeau')]],
            'variable' => $this->compare($args),
            'badge' => ['badge', [self::int($args[0] ?? null, 'numéro du badge', 1, 16)]],
            'badges' => ['badges', [self::int($args[0] ?? null, 'nombre de badges', 0, 16)]],
            'objet' => ['item', [self::slug($args[0] ?? '', 'objet'), self::int($args[1] ?? 1, 'quantité', 1)]],
            'argent' => ['money', [self::int($args[0] ?? null, 'montant', 0)]],
            'pokemon' => ['pokemon', [self::slug($args[0] ?? '', 'Pokémon')]],
            'equipe' => ['party', [self::int($args[0] ?? null, 'nombre de Pokémon', 0, 6)]],
            'dresseur' => ['trainer', [self::slug($args[0] ?? '', 'dresseur', '/^[a-z0-9_-]+$/')]],
            'heure' => ['time', [self::among($args, self::TIMES, 'heure (matin, jour ou nuit)')]],
            'jour' => ['day', [self::among($args, self::DAYS, 'jour (lundi ... dimanche)')]],
            'garcon' => ['gender', ['male']],
            'fille' => ['gender', ['female']],
            'hasard' => ['chance', [self::int($args[0] ?? null, 'pourcentage', 0, 100)]],
            'ligue' => ['league', [self::int($args[0] ?? null, 'nombre de membres battus', 0, 10)]],   // étape 27
            'pantheon' => ['hallOfFame', [self::int($args[0] ?? 1, 'nombre d\'entrées', 1)]],           // étape 27
            'resultat' => ['result', [self::among($args, self::RESULTS, 'résultat (victoire, defaite, fuite ou capture)')]],
            'question' => ['question', [self::text($rest, 'question')]],
            default => throw new ScriptError("condition inconnue : \"{$word}\"."),
        };

        return [$not, $term[0], $term[1]];
    }

    /** "variable visites >= 3" (sans opérateur : différente de 0) */
    private function compare(array $args): array
    {
        $name = self::name($args[0] ?? '', 'nom de la variable');
        if (count($args) === 1) {
            return ['var', [$name, '!=', 0]];
        }
        $op = $args[1] === '==' ? '=' : $args[1];
        if (! in_array($op, ['=', '!=', '<', '<=', '>', '>='], true) || ! isset($args[2])) {
            throw new ScriptError('écris « variable nom >= 3 » (opérateurs : = != < <= > >=).');
        }

        return ['var', [$name, $op, self::int($args[2], 'nombre', PHP_INT_MIN)]];
    }

    // =====================================================================
    // Commandes
    // =====================================================================

    private function give(string $rest): void
    {
        [$what, $rest] = self::cut($rest);
        $args = self::words($rest);
        match (self::norm($what)) {
            'objet' => $this->emit(['give_item', self::slug($args[0] ?? '', 'objet'), self::int($args[1] ?? 1, 'quantité', 1, 999)]),
            'argent' => $this->emit(['give_money', self::int($args[0] ?? null, 'montant', 1)]),
            'pokemon' => $this->givePokemon($args),
            // donner oeuf togepi [pas] ; « legendaire » : un légendaire (ou fabuleux) tiré au hasard
            'oeuf' => $this->emit(['give_egg', self::slug($args[0] ?? '', 'Pokémon'), self::int($args[1] ?? 1000, 'nombre de pas', 1, 100000)]),
            default => throw new ScriptError('écris « donner objet ... », « donner pokemon ... », « donner oeuf ... » ou « donner argent ... ».'),
        };
    }

    /** donner pokemon eevee 5 [chromatique] [objet soothe-bell] ; « legendaire » à la place de l'espèce : tiré au hasard */
    private function givePokemon(array $args): void
    {
        $slug = self::slug($args[0] ?? '', 'Pokémon');
        $level = self::int($args[1] ?? null, 'niveau', 1, 100);
        $shiny = false;
        $item = null;
        for ($i = 2; $i < count($args); $i++) {
            $word = self::norm($args[$i]);
            if ($word === 'chromatique' || $word === 'shiny') {
                $shiny = true;
            } elseif ($word === 'objet') {
                $item = self::slug($args[++$i] ?? '', 'objet tenu');
            } else {
                throw new ScriptError("option inconnue pour « donner pokemon » : \"{$args[$i]}\" (chromatique, objet slug).");
            }
        }
        $this->emit(['give_pokemon', $slug, $level, $shiny, $item]);
    }

    private function take(string $rest): void
    {
        [$what, $rest] = self::cut($rest);
        $args = self::words($rest);
        match (self::norm($what)) {
            'objet' => $this->emit(['take_item', self::slug($args[0] ?? '', 'objet'), self::int($args[1] ?? 1, 'quantité', 1, 999)]),
            'argent' => $this->emit(['take_money', self::int($args[0] ?? null, 'montant', 1)]),
            default => throw new ScriptError('écris « prendre objet ... » ou « prendre argent ... ».'),
        };
    }

    private function removeFlag(string $rest): void
    {
        [$what, $rest] = self::cut($rest);
        if (self::norm($what) !== 'drapeau') {
            throw new ScriptError('écris « enlever drapeau nom ».');
        }
        $this->emit(['flag', self::name($rest, 'nom du drapeau'), false]);
    }

    /** variable visites = 0 | variable visites + 1 | variable visites - 2 */
    private function variable(string $rest): void
    {
        $args = self::words($rest);
        $name = self::name($args[0] ?? '', 'nom de la variable');
        if (! in_array($args[1] ?? null, ['=', '+', '-'], true) || ! isset($args[2])) {
            throw new ScriptError('écris « variable nom = 3 », « variable nom + 1 » ou « variable nom - 1 ».');
        }
        $this->emit(['var', $name, $args[1], self::int($args[2], 'nombre', PHP_INT_MIN)]);
    }

    /** combat dresseur slug [perdable] | combat sauvage pokemon niveau [chromatique] */
    private function battle(string $rest): void
    {
        [$what, $rest] = self::cut($rest);
        $args = self::words($rest);
        $options = array_map(fn ($w) => self::norm($w), array_slice($args, self::norm($what) === 'dresseur' ? 1 : 2));
        foreach ($options as $option) {
            if (! in_array($option, ['perdable', 'chromatique', 'shiny'], true)) {
                throw new ScriptError("option de combat inconnue : \"{$option}\" (perdable, chromatique).");
            }
        }
        match (self::norm($what)) {
            'dresseur' => $this->emit(['battle_trainer', self::slug($args[0] ?? '', 'dresseur', '/^[a-z0-9_-]+$/'), in_array('perdable', $options, true)]),
            'sauvage' => $this->emit(['battle_wild', self::slug($args[0] ?? '', 'Pokémon'), self::int($args[1] ?? null, 'niveau', 1, 100),
                (bool) array_intersect(['chromatique', 'shiny'], $options), in_array('perdable', $options, true)]),
            default => throw new ScriptError('écris « combat dresseur slug » ou « combat sauvage pokemon niveau ».'),
        };
    }

    /** teleporter carte x y [direction] */
    private function teleport(string $rest): void
    {
        $args = self::words($rest);
        $map = self::slug($args[0] ?? '', 'carte', '/^[a-z0-9_]+$/');
        $x = self::int($args[1] ?? null, 'x', 0);
        $y = self::int($args[2] ?? null, 'y', 0);
        $facing = isset($args[3]) ? self::direction($args[3]) : 'down';
        $this->emit(['teleport', $map, $x, $y, $facing]);
    }

    /** Ce que le navigateur doit jouer (déplacements, sons, fondus...) */
    private function clientStep(string $word, string $rest): array
    {
        $args = self::words($rest);

        switch ($word) {
            case 'deplacer':
                // deplacer ce gauche 2 haut 1   |   deplacer ce vers joueur
                $target = self::target($args[0] ?? '');
                if (self::norm($args[1] ?? '') === 'vers') {
                    return ['type' => 'approach', 'target' => $target, 'to' => self::target($args[2] ?? '')];
                }
                $path = [];
                for ($i = 1; $i < count($args); $i++) {
                    $dir = self::direction($args[$i]);
                    $count = isset($args[$i + 1]) && ctype_digit($args[$i + 1]) ? (int) $args[++$i] : 1;
                    if ($count < 1 || $count > 50) {
                        throw new ScriptError('un déplacement fait de 1 à 50 cases.');
                    }
                    array_push($path, ...array_fill(0, $count, $dir));
                }
                if (! $path) {
                    throw new ScriptError('écris « deplacer cible direction [nombre] ... » (ex. deplacer ce gauche 2 haut 1).');
                }

                return ['type' => 'move', 'target' => $target, 'path' => $path];
            case 'regarder':
                // regarder joueur haut  |  regarder ce joueur
                $target = self::target($args[0] ?? '');
                $what = $args[1] ?? '';
                if (isset(self::DIRECTIONS[self::norm($what)])) {
                    return ['type' => 'face', 'target' => $target, 'dir' => self::direction($what)];
                }

                return ['type' => 'face', 'target' => $target, 'toward' => self::target($what)];
            case 'exclamation':
                return ['type' => 'exclaim', 'target' => self::target($args[0] ?? '')];
            case 'cacher':
            case 'montrer':
                return ['type' => $word === 'cacher' ? 'hide' : 'show', 'target' => self::target($args[0] ?? '')];
            case 'attendre':
                return ['type' => 'wait', 'ms' => self::int($args[0] ?? null, 'durée en millisecondes', 0, 10000)];
            case 'musique':
                $name = trim(self::unquote($rest));

                return ['type' => 'music', 'name' => in_array(self::norm($name), ['', 'carte'], true) ? null : $name];
            case 'jingle':
            case 'son':
                return ['type' => $word === 'son' ? 'se' : 'me', 'name' => self::text($rest, $word)];
            case 'fondu':
                $to = self::norm($args[0] ?? '');
                if (! in_array($to, ['noir', 'clair'], true)) {
                    throw new ScriptError('écris « fondu noir » ou « fondu clair ».');
                }

                return ['type' => 'fade', 'to' => $to === 'noir' ? 'black' : 'clear'];
            case 'secouer':
                return ['type' => 'shake'];
        }

        throw new ScriptError("commande inconnue : \"{$word}\".");
    }

    // =====================================================================
    // Petits outils
    // =====================================================================

    /** Coupe "mot reste de la ligne" */
    public static function cut(string $text): array
    {
        $text = trim($text);
        $parts = preg_split('/\s+/u', $text, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** Minuscules, sans accents : "Événement" -> "evenement" */
    public static function norm(string $word): string
    {
        return strtolower(Str::ascii(trim($word)));
    }

    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($text)), fn ($w) => $w !== ''));
    }

    /** Sépare "a et b et c" (en dehors de "question ...", qui prend toute la fin de la ligne) */
    private static function splitAnd(string $text): array
    {
        $parts = [];
        $rest = trim($text);
        while ($rest !== '') {
            if (self::norm(self::cut($rest)[0]) === 'question') {
                $parts[] = $rest;
                break;
            }
            $pieces = preg_split('/\s+et\s+/iu', $rest, 2);
            $parts[] = trim($pieces[0]);
            $rest = trim($pieces[1] ?? '');
        }

        return $parts;
    }

    private static function unquote(string $text): string
    {
        $text = trim($text);
        if (strlen($text) >= 2 && str_starts_with($text, '"') && str_ends_with($text, '"')) {
            return substr($text, 1, -1);
        }

        return $text;
    }

    private static function text(string $text, string $what, bool $allowEmpty = false): string
    {
        $text = self::unquote($text);
        if ($text === '' && ! $allowEmpty) {
            throw new ScriptError("« {$what} » doit être suivi d'un texte.");
        }

        return $text;
    }

    /** Nom de drapeau, de variable ou d'événement : lettres, chiffres et _ (accents enlevés, minuscules) */
    public static function name(string $text, string $what): string
    {
        $name = self::norm($text);
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new ScriptError("{$what} invalide : \"{$text}\" (lettres, chiffres et _ seulement, sans espace).");
        }

        return $name;
    }

    private static function slug(string $text, string $what, string $pattern = '/^[a-z0-9-]+$/'): string
    {
        $slug = strtolower(trim($text));
        if (! preg_match($pattern, $slug)) {
            throw new ScriptError($slug === '' ? "il manque : {$what}." : "{$what} invalide : \"{$text}\" (le slug, ex. potion, rare-candy).");
        }

        return $slug;
    }

    private static function int(mixed $value, string $what, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if ($value === null || ! preg_match('/^-?\d+$/', (string) $value)) {
            throw new ScriptError($value === null ? "il manque : {$what}." : "{$what} : \"{$value}\" n'est pas un nombre entier.");
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) {
            throw new ScriptError("{$what} : {$n} doit être entre {$min} et {$max}.");
        }

        return $n;
    }

    private static function direction(string $word): string
    {
        $dir = self::DIRECTIONS[self::norm($word)] ?? null;
        if (! $dir) {
            throw new ScriptError("direction inconnue : \"{$word}\" (haut, bas, gauche ou droite).");
        }

        return $dir;
    }

    /** Qui bouge : "joueur", "ce" (le PNJ de l'événement) ou le nom d'un objet Tiled */
    private static function target(string $word): string
    {
        $norm = self::norm($word);
        if ($norm === '') {
            throw new ScriptError('il manque la cible : joueur, ce, ou le nom d\'un PNJ dans Tiled.');
        }

        return in_array($norm, ['joueur', 'ce'], true) ? $norm : trim($word);
    }

    /** Un ou plusieurs mots d'une liste (heure jour nuit, jour samedi dimanche) */
    private static function among(array $words, array $allowed, string $what): array
    {
        $values = [];
        foreach (preg_split('/[\s,]+/u', implode(' ', $words)) as $word) {
            if ($word === '') {
                continue;
            }
            $value = $allowed[self::norm($word)] ?? null;
            if ($value === null) {
                throw new ScriptError("{$what} : \"{$word}\" inconnu.");
            }
            $values[] = $value;
        }
        if (! $values) {
            throw new ScriptError("il manque : {$what}.");
        }

        return array_values(array_unique($values));
    }
}
