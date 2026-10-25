<?php

namespace App\Battle;

/**
 * Les textes du combat, en français, comme dans les jeux.
 *
 * Le français oblige à accorder les phrases : "Le Rattata sauvage", "L'Abo sauvage",
 * "l'Attaque du Rattata sauvage", "l'Attaque d'Abo"... Ces petites fonctions s'en chargent.
 */
class BattleText
{
    /** Nom des stats tel qu'il apparaît dans les messages ("L'Attaque de Pikachu monte !") */
    public const STAT_NAMES = [
        'attack' => "l'Attaque",
        'defense' => 'la Défense',
        'sp_attack' => "l'Attaque Spéciale",
        'sp_defense' => 'la Défense Spéciale',
        'speed' => 'la Vitesse',
        'accuracy' => 'la Précision',
        'evasion' => "l'Esquive",
    ];

    public function __construct(private string $battleType)
    {
    }

    /**
     * Le Pokémon comme SUJET d'une phrase : "Pikachu", "Le Rattata sauvage", "L'Abo ennemi".
     * $capital = false au milieu d'une phrase : "le Rattata sauvage".
     */
    public function subject(array $battler, string $side, bool $capital = true): string
    {
        // Combat entre joueurs : "Pikachu" pour l'un est "Le Pikachu ennemi" pour l'autre.
        // On écrit un JETON, remplacé pour chaque joueur par resolve().
        if ($this->battleType === 'pvp') {
            return '[[S|' . $side . '|' . ($capital ? 1 : 0) . '|' . self::clean($battler['name']) . ']]';
        }

        if ($side === 'player') {
            return $battler['name'];
        }

        $article = $this->startsWithVowel($battler['name']) ? "l'" : 'le ';
        if ($capital) {
            $article = ucfirst($article);
        }

        return $article . $battler['name'] . $this->foeSuffix();
    }

    /** "de Pikachu", "d'Abo", "du Rattata sauvage", "de l'Abo sauvage" */
    public function of(array $battler, string $side): string
    {
        if ($this->battleType === 'pvp') {
            return '[[O|' . $side . '|' . self::clean($battler['name']) . ']]';
        }

        if ($side === 'player') {
            return ($this->startsWithVowel($battler['name']) ? "d'" : 'de ') . $battler['name'];
        }

        return ($this->startsWithVowel($battler['name']) ? "de l'" : 'du ') . $battler['name'] . $this->foeSuffix();
    }

    /** "Un Rattata sauvage apparaît !" */
    public function wildAppears(array $battler): string
    {
        return "Un {$battler['name']} sauvage apparaît !";
    }

    /** Message de changement de stat : "L'Attaque du Rattata sauvage baisse !" */
    public function statChange(array $battler, string $side, string $stat, int $change, bool $blocked): string
    {
        $statName = ucfirst(self::STAT_NAMES[$stat] ?? $stat);
        $whose = $this->of($battler, $side);

        if ($blocked) {
            return $change > 0 ? "{$statName} {$whose} ne peut plus monter !" : "{$statName} {$whose} ne peut plus baisser !";
        }

        $amount = match (true) {
            abs($change) >= 3 => ' énormément',
            abs($change) === 2 => ' beaucoup',
            default => '',
        };

        return $change > 0 ? "{$statName} {$whose} monte{$amount} !" : "{$statName} {$whose} baisse{$amount} !";
    }

    /** Message quand un statut est infligé */
    public function statusInflicted(string $status, string $who): string
    {
        return match ($status) {
            'paralysis' => "{$who} est paralysé ! Il aura du mal à attaquer !",
            'burn' => "{$who} est brûlé !",
            'poison' => "{$who} est empoisonné !",
            'bad_poison' => "{$who} est gravement empoisonné !",
            'sleep' => "{$who} s'endort !",
            'freeze' => "{$who} est gelé !",
            default => "{$who} est affecté !",
        };
    }

    /** Message quand le Pokémon a DÉJÀ ce statut */
    public function alreadyStatus(string $status, string $who): string
    {
        return match ($status) {
            'paralysis' => "{$who} est déjà paralysé !",
            'burn' => "{$who} est déjà brûlé !",
            'poison', 'bad_poison' => "{$who} est déjà empoisonné !",
            'sleep' => "{$who} dort déjà !",
            'freeze' => "{$who} est déjà gelé !",
            default => 'Mais cela échoue !',
        };
    }

    /**
     * PvP : remplace les jetons [[S|côté|majuscule|nom]] et [[O|côté|nom]] d'un message
     * du point de vue du joueur qui le lit ($viewer = 'player' ou 'foe' dans l'état du combat).
     */
    public static function resolve(string $text, string $viewer): string
    {
        $words = new self('trainer');  // "ennemi" pour les Pokémon de l'autre joueur

        return preg_replace_callback(
            '/\[\[(S|O)\|(player|foe)\|(?:([01])\|)?([^\]]*)\]\]/u',
            function (array $m) use ($viewer, $words) {
                $side = $m[2] === $viewer ? 'player' : 'foe';
                $battler = ['name' => $m[4]];

                return $m[1] === 'S' ? $words->subject($battler, $side, $m[3] === '1') : $words->of($battler, $side);
            },
            $text,
        );
    }

    /** Un surnom ne doit pas casser un jeton */
    private static function clean(string $name): string
    {
        return str_replace(['[', ']', '|'], '', $name);
    }

    private function foeSuffix(): string
    {
        return in_array($this->battleType, ['wild', 'safari'], true) ? ' sauvage' : ' ennemi';   // étape 15 : Safari
    }

    private function startsWithVowel(string $name): bool
    {
        return (bool) preg_match('/^[AEIOUYÂÉÈÊËÎÏÔÛ]/iu', $name);
    }
}
