<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Models\Item;
use App\Models\Move;
use App\Models\PokemonSpecies;
use App\Models\Trainer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Les 8 ARÈNES DE KANTO, au 1er étage de l'École ORT (salles 110 à 117), comme dans Rouge Feu / Vert Feuille :
 * les Champions (équipes et capacités de RFVF) et les dresseurs de chaque arène (équipes de RFVF).
 * Et les 8 ARÈNES DE JOHTO au 2e étage (salles 210 à 217), comme dans HeartGold / SoulSilver.
 *
 *   php artisan db:seed --class=OrtGymsSeeder
 *
 * Relançable : tout est retrouvé par son slug. Les PNJ sont déjà placés dans les cartes ort_110 à ort_117
 * (propriété Tiled "trainer" = le slug ci-dessous).
 * Argent gagné = reward_money x niveau du Pokémon le plus fort (formule des jeux).
 */
class OrtGymsSeeder extends Seeder
{
    /** Classe affichée => [sprite (overworld et combat), argent par niveau] */
    private const CLASSES = [
        'Campeur' => ['camper', 20], 'Nageur' => ['swimmer_m', 4], 'Pique-Nique' => ['picnicker', 20],
        'Marin' => ['sailor', 32], 'Mécano' => ['engineer', 48], 'Gentleman' => ['gentleman', 72],
        'Fillette' => ['lass', 16], 'Canon' => ['beauty', 72], 'Topdresseuse' => ['cooltrainer_f', 36],
        'Topdresseur' => ['cooltrainer_m', 36], 'Jongleur' => ['juggler', 40], 'Dompteur' => ['tamer', 40],
        'Kinésiste' => ['psychic_m', 20], 'Exorciste' => ['channeler', 30], 'Pillard' => ['burglar', 88],
        'Intello' => ['supernerd', 24], 'Karatéka' => ['blackbelt', 24],
    ];

    /** Les classes de Johto (HeartGold / SoulSilver) : [sprite voulu, sprite de remplacement, argent par niveau] */
    private const JOHTO_CLASSES = [
        'Ornithologue' => ['birdkeeper', 'birdkeeper', 32], 'Scout' => ['bugcatcher', 'bugcatcher', 16],
        'Jumelles' => ['twins', 'twins', 32], 'Fillette' => ['lass', 'lass', 16], 'Canon' => ['beauty', 'beauty', 56],
        'Médium' => ['channeler', 'channeler', 48], 'Karatéka' => ['blackbelt', 'blackbelt', 24],
        'Surfeur' => ['swimmer_m', 'swimmer_m', 32], 'Skieuse' => ['skier_f', 'cooltrainer_f', 32],
        'Topdresseur' => ['cooltrainer_m', 'cooltrainer_m', 60], 'Topdresseuse' => ['cooltrainer_f', 'cooltrainer_f', 60],
    ];

    private const WOMEN = ['Ondine', 'Érika', 'Morgane', 'Blanche', 'Jasmine', 'Sandra'];

    public function run(): void
    {
        $this->leaders();
        $this->gymTrainers();
        $this->johtoLeaders();
        $this->johtoTrainers();
    }

    /**
     * Les sprites [carte, combat] : celui voulu s'il existe, sinon le sprite de remplacement.
     * La planche de carte (overworld) et l'image de combat (battle) sont vérifiées séparément :
     * une Championne peut avoir son image de combat sans sa planche de carte (et inversement).
     */
    private function sprite(string $wanted, string $fallback): array
    {
        $result = [];
        foreach (['overworld' => 'planche de carte', 'battle' => 'image de combat'] as $dir => $label) {
            $ok = File::exists(public_path("game/sprites/trainers/{$dir}/{$wanted}.png"));
            if (! $ok) {
                $this->command?->warn("Sprite {$dir}/{$wanted}.png ({$label}) manquant : {$fallback} est utilisé en attendant.");
            }
            $result[] = $ok ? $wanted : $fallback;
        }

        return $result;
    }

    // =====================================================================
    // Les Champions de Kanto (Rouge Feu / Vert Feuille)
    // =====================================================================

    private function leaders(): void
    {
        // [badge, slug, nom, sprite, CT, objets, intro, défaite, après, équipe [[espèce, niveau, [capacités]]]]
        $leaders = [
            [1, 'champion_pierre', 'Pierre', 'leader_brock', 'tm39', ['super-potion'],
                "Je suis Pierre ! Le Champion de l'Arène Roche !\n\nMa volonté est dure comme la roche ! Mes Pokémon aussi !\n\nVoyons voir si tu peux entamer ma défense !",
                "Je t'ai sous-estimé...\nTu mérites le Badge Roche !",
                "Il y a encore bien des dresseurs à affronter. Continue comme ça !",
                [['geodude', 12, ['tackle', 'defense-curl']], ['onix', 14, ['tackle', 'bind', 'rock-tomb', 'harden']]]],
            [2, 'champion_ondine', 'Ondine', 'leader_misty', 'tm03', ['super-potion'],
                "Salut, toi ! Je suis Ondine, la Championne de l'Arène Eau !\n\nMa stratégie ? Les Pokémon Eau à fond !",
                "Waouh ! T'es trop fort !\nPrends ce Badge Cascade !",
                "Un jour, je serai la meilleure dresseuse de Pokémon Eau du monde !",
                [['staryu', 18, ['tackle', 'harden', 'water-pulse', 'recover']], ['starmie', 21, ['swift', 'water-pulse', 'recover', 'rapid-spin']]]],
            [3, 'champion_major_bob', 'Major Bob', 'leader_surge', 'tm34', ['super-potion'],
                "Hé, gamin ! Bienvenue dans l'Arène Électrik !\n\nPendant la guerre, mes Pokémon Électrik m'ont sauvé la peau !\n\nTu vas griller sur place, comme mes ennemis !",
                "Waouh ! T'es un vrai pro, gamin !\nVoici le Badge Foudre !",
                "Un soldat reconnaît toujours un adversaire de valeur !",
                [['voltorb', 21, ['tackle', 'screech', 'sonic-boom', 'shock-wave']], ['pikachu', 18, ['quick-attack', 'thunder-wave', 'double-team', 'shock-wave']],
                    ['raichu', 24, ['quick-attack', 'thunder-wave', 'double-team', 'shock-wave']]]],
            [4, 'champion_erika', 'Érika', 'leader_erika', 'tm19', ['hyper-potion', 'full-heal'],
                "Bonjour... Il fait beau aujourd'hui, n'est-ce pas ?\n\nJe suis Érika, la Championne de l'Arène Plante. J'adore les fleurs.\n\nOh ? Vous voulez m'affronter ? Très bien.",
                "Oh ! J'ai perdu...\nVoici le Badge Prisme.",
                "Les Pokémon Plante sont si apaisants... Revenez quand vous voulez.",
                [['victreebel', 29, ['acid', 'giga-drain', 'poison-powder', 'stun-spore']], ['tangela', 24, ['constrict', 'ingrain', 'poison-powder', 'giga-drain']],
                    ['vileplume', 29, ['acid', 'giga-drain', 'sleep-powder', 'stun-spore']]]],
            [5, 'champion_koga', 'Koga', 'leader_koga', 'tm06', ['hyper-potion', 'hyper-potion'],
                "Fwahahaha ! Un enfant ose me défier ?\n\nJe suis Koga, le Champion de l'Arène Poison. Le poison est l'art des ninjas.\n\nTu vas goûter à mes techniques empoisonnées !",
                "Humph ! Tu as prouvé ta valeur.\nVoici le Badge Âme !",
                "Un ninja accepte la défaite et en tire des leçons. Continue sur ta voie !",
                [['koffing', 37, ['toxic', 'self-destruct', 'sludge', 'smokescreen']], ['muk', 39, ['acid-armor', 'toxic', 'minimize', 'sludge']],
                    ['koffing', 37, ['toxic', 'self-destruct', 'sludge', 'smokescreen']], ['weezing', 43, ['tackle', 'toxic', 'sludge', 'smokescreen']]]],
            [6, 'champion_morgane', 'Morgane', 'leader_sabrina', 'tm04', ['hyper-potion', 'hyper-potion'],
                "Je savais que tu viendrais...\n\nJe suis Morgane, la Championne de l'Arène Psy. J'ai vu ta venue en rêve.\n\nMais je ne sais pas encore qui va gagner...",
                "Cette défaite... je ne l'avais pas prévue.\nLe Badge Marais est à toi.",
                "Tu as une force que mes pouvoirs ne peuvent pas voir.",
                [['kadabra', 38, ['psybeam', 'reflect', 'future-sight', 'calm-mind']], ['mr-mime', 37, ['barrier', 'psybeam', 'baton-pass', 'calm-mind']],
                    ['venomoth', 38, ['psybeam', 'gust', 'leech-life', 'supersonic']], ['alakazam', 43, ['psychic', 'recover', 'future-sight', 'calm-mind']]]],
            [7, 'champion_auguste', 'Auguste', 'leader_blaine', 'tm38', ['hyper-potion', 'hyper-potion'],
                "Hah ! Je suis Auguste, le Champion brûlant de l'Arène Feu !\n\nMes Pokémon Feu vont te réduire en cendres !\n\nT'as pensé à prendre de l'Anti-Brûle ?",
                "Je suis consumé !\nVoici le Badge Volcan !",
                "La flamme de la passion ne s'éteint jamais !",
                [['growlithe', 42, ['bite', 'roar', 'take-down', 'fire-blast']], ['ponyta', 40, ['stomp', 'bounce', 'fire-spin', 'fire-blast']],
                    ['rapidash', 42, ['stomp', 'bounce', 'fire-spin', 'fire-blast']], ['arcanine', 47, ['bite', 'roar', 'take-down', 'fire-blast']]]],
            [8, 'champion_giovanni', 'Giovanni', 'leader_giovanni', 'tm26', ['hyper-potion', 'hyper-potion', 'full-heal'],
                "Bienvenue dans ma salle. Je suis Giovanni, le Champion de l'Arène Sol.\n\nTu es arrivé jusqu'ici... Voyons si tu mérites le dernier badge.",
                "Ha ! Quel combat remarquable !\nTu as gagné le Badge Terre.",
                "Avec ce badge, plus aucun Pokémon ne te désobéira. La Ligue t'attend.",
                [['rhyhorn', 45, ['take-down', 'rock-blast', 'scary-face', 'earthquake']], ['dugtrio', 42, ['earthquake', 'sand-tomb', 'mud-slap', 'slash']],
                    ['nidoqueen', 44, ['body-slam', 'double-kick', 'earthquake', 'poison-sting']], ['nidoking', 45, ['thrash', 'double-kick', 'earthquake', 'poison-sting']],
                    ['rhydon', 50, ['take-down', 'rock-blast', 'scary-face', 'earthquake']]]],
        ];

        foreach ($leaders as [$order, $slug, $name, $sprite, $tm, $items, $intro, $defeat, $after, $team]) {
            [$overworld, $battle] = $this->sprite($sprite, 'cooltrainer_m');
            $badge = Badge::where('order', $order)->first();
            $badge?->update(['gym_leader' => $name]);   // (une ancienne version mettait Jeannine au badge n° 5)
            $this->trainer([
                'slug' => $slug, 'name' => $name, 'trainer_class' => in_array($name, self::WOMEN, true) ? 'Championne' : 'Champion',
                'map_key' => null, 'sprite_overworld' => $overworld, 'sprite_battle' => $battle,
                'reward_money' => 100, 'sight_range' => 0, 'ai_level' => 3, 'items' => $items,
                'intro_text' => $intro, 'defeat_text' => $defeat, 'after_text' => $after,
                'badge_id' => $badge?->id, 'reward_item_id' => Item::where('slug', $tm)->value('id'),
            ], $team, 15 + 2 * $order);
        }
        // Une ancienne version mettait Jeannine à la place de Koga : on la retire
        Trainer::where('slug', 'champion_jeannine')->delete();
    }

    // =====================================================================
    // Les dresseurs des arènes (Rouge Feu / Vert Feuille)
    // =====================================================================

    private function gymTrainers(): void
    {
        // [slug, classe, nom, [[espèce, niveau], ...], réplique avant, réplique après]
        $trainers = [
            // Arène Roche (salle 110)
            ['arene1_campeur_elvin', 'Campeur', 'Elvin', [['geodude', 10], ['sandshrew', 11]],
                "Halte ! Tu es bien trop jeune pour affronter Pierre !", "Bah ! T'es pas mal... Mais Pierre est bien plus fort !"],
            // Arène Eau (salle 111)
            ['arene2_nageur_louis', 'Nageur', 'Louis', [['horsea', 16], ['shellder', 16]],
                "Ondine adore les nageurs comme moi !", "J'ai bu la tasse..."],
            ['arene2_piquenique_diane', 'Pique-Nique', 'Diane', [['goldeen', 19]],
                "Que fais-tu ici ? Ondine est occupée !", "Tu es fort... Ondine va te donner du fil à retordre !"],
            // Arène Électrik (salle 112)
            ['arene3_marin_harold', 'Marin', 'Harold', [['pikachu', 21], ['pikachu', 21]],
                "Ce n'est pas un endroit pour les enfants !", "Ouah ! Tu as du courant !"],
            ['arene3_mecano_killian', 'Mécano', 'Killian', [['voltorb', 21], ['magnemite', 21]],
                "Je suis un spécialiste de l'électricité !", "Court-circuit !"],
            ['arene3_gentleman_anatole', 'Gentleman', 'Anatole', [['pikachu', 23]],
                "Quand j'étais militaire, Major Bob était mon supérieur !", "Je n'avais rien vu venir..."],
            // Arène Plante (salle 113)
            ['arene4_fillette_katy', 'Fillette', 'Katy', [['bellsprout', 23], ['weepinbell', 23]],
                "Ici, c'est l'arène d'Érika. Les garçons ne sont pas les bienvenus !", "Oh... T'es plutôt fort."],
            ['arene4_canon_elodie', 'Canon', 'Élodie', [['oddish', 21], ['oddish', 23], ['bellsprout', 21], ['bellsprout', 21]],
                "Mes Pokémon Plante sont aussi jolis que moi !", "Mes jolis Pokémon !"],
            ['arene4_topdresseuse_marie', 'Topdresseuse', 'Marie', [['bellsprout', 22], ['oddish', 22], ['gloom', 22], ['weepinbell', 22], ['ivysaur', 22]],
                "Érika nous apprend à prendre soin des plantes... et des Pokémon !", "Tu as gagné. Bravo."],
            ['arene4_fillette_lisa', 'Fillette', 'Lisa', [['oddish', 23], ['gloom', 23]],
                "Tu sens ce parfum ? C'est celui de nos Pokémon !", "Oh non !"],
            ['arene4_piquenique_tina', 'Pique-Nique', 'Tina', [['bulbasaur', 24], ['ivysaur', 24]],
                "Je viens toujours ici pour voir les fleurs !", "J'ai perdu..."],
            ['arene4_canon_laurence', 'Canon', 'Laurence', [['exeggcute', 24]],
                "Érika est si élégante... Je veux lui ressembler !", "Je ne suis pas encore assez élégante..."],
            ['arene4_canon_tamia', 'Canon', 'Tamia', [['bellsprout', 24], ['bellsprout', 24]],
                "Ne marche pas sur nos fleurs !", "Mes Chétiflor !"],
            // Arène Poison (salle 114) : les dresseurs de Koga (Parmanie)
            ['arene5_jongleur_vicente', 'Jongleur', 'Vicente', [['drowzee', 34], ['kadabra', 34]],
                "Les Pokémon Poison sont pleins de surprises... comme moi !", "Mes balles sont tombées..."],
            ['arene5_jongleur_melvin', 'Jongleur', 'Melvin', [['hypno', 38]],
                "Regarde bien mon pendule...", "Raté !"],
            ['arene5_jongleur_chris', 'Jongleur', 'Chris', [['drowzee', 31], ['drowzee', 31], ['drowzee', 31], ['kadabra', 31]],
                "Tu ne trouveras jamais le bon chemin !", "Tu l'as trouvé..."],
            ['arene5_dompteur_edgard', 'Dompteur', 'Edgard', [['arbok', 33], ['arbok', 33], ['sandslash', 33]],
                "Mes Pokémon obéissent au doigt et à l'œil !", "Ils ne m'ont pas obéi..."],
            ['arene5_dompteur_phil', 'Dompteur', 'Phil', [['sandslash', 34], ['arbok', 34]],
                "Le maître Koga est un ninja redoutable !", "Grr..."],
            ['arene5_jongleur_robert', 'Jongleur', 'Robert', [['drowzee', 34], ['hypno', 34]],
                "Je jongle entre la ruse et le poison !", "Je n'ai pas eu le temps de jongler !"],
            // Arène Psy (salle 115)
            ['arene6_kinesiste_prosper', 'Kinésiste', 'Prosper', [['slowpoke', 33], ['slowpoke', 33], ['slowbro', 33]],
                "Je savais que tu viendrais par ici...", "Je ne l'avais pas prévu, ça."],
            ['arene6_kinesiste_tyron', 'Kinésiste', 'Tyron', [['mr-mime', 34], ['kadabra', 34]],
                "Mes pouvoirs psychiques sont sans limite !", "Mes pouvoirs ont des limites..."],
            ['arene6_exorciste_aimee', 'Exorciste', 'Aimée', [['haunter', 38]],
                "Hiii... Les esprits m'ont parlé de toi...", "Les esprits se sont tus..."],
            ['arene6_exorciste_tatiana', 'Exorciste', 'Tatiana', [['gastly', 33], ['gastly', 33], ['haunter', 33]],
                "Morgane voit l'avenir, moi je vois les spectres !", "Brrr..."],
            ['arene6_exorciste_amanda', 'Exorciste', 'Amanda', [['gastly', 34], ['haunter', 34]],
                "Tu sens ce frisson ?", "Le frisson est passé..."],
            ['arene6_kinesiste_polo', 'Kinésiste', 'Polo', [['slowbro', 38]],
                "Ma Flagadoss lit dans tes pensées.", "Elle ne les a pas bien lues..."],
            ['arene6_kinesiste_noe', 'Kinésiste', 'Noé', [['kadabra', 31], ['kadabra', 31], ['slowpoke', 31], ['mr-mime', 31]],
                "Je peux plier une cuillère par la pensée !", "Pas la peine de plier la cuillère..."],
            // Arène Feu (salle 116)
            ['arene7_pillard_isidor', 'Pillard', 'Isidor', [['growlithe', 36], ['vulpix', 36], ['ninetales', 36]],
                "J'étais un voleur, mais Auguste m'a remis dans le droit chemin !", "Je retourne à mes vieux démons..."],
            ['arene7_intello_erik', 'Intello', 'Erik', [['vulpix', 36], ['vulpix', 36], ['ninetales', 36]],
                "Question : quel type résiste au Feu ? Réponse : pas le tien !", "Mauvaise réponse..."],
            ['arene7_intello_evan', 'Intello', 'Evan', [['ponyta', 34], ['charmander', 34], ['vulpix', 34], ['growlithe', 34]],
                "Le Feu ? C'est de la chimie, tout simplement !", "Mes calculs étaient faux !"],
            ['arene7_pillard_victor', 'Pillard', 'Victor', [['ponyta', 41]],
                "Ça chauffe, ici !", "Je suis cuit."],
            ['arene7_intello_didier', 'Intello', 'Didier', [['rapidash', 41]],
                "Mon Galopa court plus vite que le vent !", "Il a été trop lent..."],
            ['arene7_pillard_armand', 'Pillard', 'Armand', [['vulpix', 37], ['growlithe', 37]],
                "Le Feu purifie tout !", "Je suis réduit en cendres !"],
            ['arene7_intello_zac', 'Intello', 'Zac', [['growlithe', 37], ['vulpix', 37]],
                "Auguste pose de drôles de devinettes...", "Je n'avais pas la réponse !"],
            // Arène Sol (salle 117)
            ['arene8_dompteur_cyril', 'Dompteur', 'Cyril', [['arbok', 39], ['tauros', 39]],
                "Le Champion de cette arène est un homme redoutable !", "Je n'ai pas fait le poids..."],
            ['arene8_karateka_akira', 'Karatéka', 'Akira', [['machoke', 43]],
                "Hiyaaah ! Mes poings sont aussi durs que le sol !", "Je dois encore m'entraîner !"],
            ['arene8_dompteur_youri', 'Dompteur', 'Youri', [['rhyhorn', 43]],
                "Mon Rhinocorne va t'écraser !", "Écrasé... c'est moi."],
            ['arene8_topdresseur_samuel', 'Topdresseur', 'Samuel', [['sandslash', 37], ['sandslash', 37], ['rhyhorn', 37], ['nidorino', 39], ['nidoking', 39]],
                "Personne ne passe sans me battre !", "Tu es passé..."],
            ['arene8_karateka_tetsuo', 'Karatéka', 'Tetsuo', [['machop', 40], ['machoke', 40]],
                "Notre force vient de la terre !", "Ouf..."],
            ['arene8_karateka_takashi', 'Karatéka', 'Takashi', [['machoke', 38], ['machop', 38], ['machoke', 38]],
                "Montre-moi ce que tu vaux !", "Tu vaux beaucoup !"],
            ['arene8_topdresseur_yuji', 'Topdresseur', 'Yuji', [['sandslash', 38], ['graveler', 38], ['onix', 38], ['graveler', 38], ['marowak', 38]],
                "Les Pokémon Sol sont les plus solides !", "Le sol s'est dérobé sous mes pieds..."],
            ['arene8_topdresseur_wolfgang', 'Topdresseur', 'Wolfgang', [['marowak', 37], ['marowak', 37], ['rhyhorn', 38], ['nidorina', 39], ['nidoqueen', 39]],
                "Le Champion n'est pas un simple dresseur...", "Tu es prêt pour lui."],
        ];

        foreach ($trainers as [$slug, $class, $name, $team, $intro, $after]) {
            [$sprite, $money] = self::CLASSES[$class];
            $this->trainer([
                'slug' => $slug, 'name' => $name, 'trainer_class' => $class, 'map_key' => null,
                'sprite_overworld' => $sprite, 'sprite_battle' => $sprite, 'reward_money' => $money,
                'sight_range' => 3, 'ai_level' => 2, 'items' => [],
                'intro_text' => $intro, 'defeat_text' => $after, 'after_text' => $after,
                'badge_id' => null, 'reward_item_id' => null,
            ], array_map(fn ($p) => [$p[0], $p[1], []], $team), 12);
        }
    }

    // =====================================================================
    // Les Champions de Johto (HeartGold / SoulSilver), 2e étage, badges n° 9 à 16
    // =====================================================================

    private function johtoLeaders(): void
    {
        // [badge, nom du badge, slug, nom, sprite voulu, sprite de remplacement, CT, objets, intro, défaite, après, équipe]
        $leaders = [
            [9, 'Badge Zéphyr', 'champion_albert', 'Albert', 'leader_falkner', 'birdkeeper', 'tm51', ['potion'],
                "Je suis Albert, le Champion de l'Arène Vol !\n\nOn dit que les Pokémon Vol sont faibles face à l'électricité...\n\nJe vais te montrer la vraie puissance des Pokémon Vol de mon père !",
                "Mes chers Pokémon Vol...\nVoici le Badge Zéphyr, preuve de ta victoire.",
                "Il y a des arènes encore plus fortes que la mienne. Va de l'avant !",
                [['pidgey', 9, ['tackle', 'sand-attack', 'leer']], ['pidgeotto', 13, ['tackle', 'leer', 'gust', 'roost']]]],
            [10, 'Badge Essaim', 'champion_hector', 'Hector', 'leader_bugsy', 'bugcatcher', 'tm89', ['super-potion'],
                "Je suis Hector ! Je ne perds jamais quand il s'agit de Pokémon Insecte !\n\nMes recherches vont faire de moi une autorité en la matière !\n\nLaisse-moi te montrer ce que j'ai appris !",
                "Waouh, incroyable !\nTu mérites le Badge Essaim !",
                "Les Pokémon Insecte sont plus profonds qu'on ne le croit. Il y a encore tant à étudier !",
                [['metapod', 15, ['tackle', 'harden']], ['kakuna', 15, ['poison-sting', 'harden']],
                    ['scyther', 17, ['quick-attack', 'leer', 'fury-cutter', 'u-turn']]]],
            [11, 'Badge Plaine', 'champion_blanche', 'Blanche', 'leader_whitney', 'beauty', 'tm45', ['super-potion'],
                "Salut ! Je suis Blanche !\n\nTout le monde se mettait aux Pokémon, alors moi aussi !\n\nIls sont trop mignons ! Tu veux te battre ? Je te préviens, je suis forte !",
                "Ouiiin... Snif...\n... Bon d'accord. Voici le Badge Plaine.",
                "Tu sais quoi ? J'ai pleuré, mais c'était drôle quand même !",
                [['clefairy', 17, ['doubleslap', 'mimic', 'encore', 'metronome']], ['miltank', 19, ['rollout', 'attract', 'stomp', 'milk-drink']]]],
            [12, 'Badge Brume', 'champion_mortimer', 'Mortimer', 'leader_morty', 'psychic_m', 'tm30', ['super-potion'],
                "Je suis Mortimer, le Champion de l'Arène Spectre.\n\nOn dit qu'un Pokémon arc-en-ciel apparaîtra devant un dresseur vraiment puissant.\n\nJ'ai suivi cette légende. Voyons si tu es ce dresseur !",
                "Je ne suis pas assez bon...\nCe Badge Brume est à toi.",
                "J'ai vu des choses que tu ne peux imaginer. Toi aussi, un jour, tu les verras.",
                [['gastly', 21, ['lick', 'spite', 'mean-look', 'curse']], ['haunter', 21, ['hypnosis', 'curse', 'dream-eater', 'nightmare']],
                    ['haunter', 23, ['curse', 'mean-look', 'sucker-punch', 'night-shade']], ['gengar', 25, ['hypnosis', 'shadow-ball', 'mean-look', 'sucker-punch']]]],
            [13, 'Badge Choc', 'champion_chuck', 'Chuck', 'leader_chuck', 'blackbelt', 'tm01', ['hyper-potion'],
                "WAHAHAH !\n\nTu es arrivé jusqu'ici ? Tu dois être plutôt fort !\n\nJe vais te montrer la puissance de mes Pokémon Combat ! HWAAARGH !",
                "Quoi ? Gnnn...\nTu es fort ! Prends ce Badge Choc !",
                "Mon entraînement sous la cascade continue ! Reviens quand tu veux !",
                [['primeape', 29, ['leer', 'rock-slide', 'double-team', 'focus-punch']], ['poliwrath', 31, ['hypnosis', 'mind-reader', 'surf', 'dynamic-punch']]]],
            [14, 'Badge Minéral', 'champion_jasmine', 'Jasmine', 'leader_jasmine', 'lady', 'tm23', ['hyper-potion'],
                "... Je suis Jasmine, la Championne de l'Arène Acier.\n\nLes Pokémon Acier sont durs comme le fer...\n\nJe vous en prie... Battons-nous.",
                "... Bravo...\nVoici le Badge Minéral.",
                "Je dois encore apprendre à être une meilleure Championne...",
                [['magnemite', 30, ['thunderbolt', 'supersonic', 'sonic-boom', 'thunder-wave']], ['magnemite', 30, ['thunderbolt', 'supersonic', 'sonic-boom', 'thunder-wave']],
                    ['steelix', 35, ['screech', 'iron-tail', 'rock-throw', 'sandstorm']]]],
            [15, 'Badge Glacier', 'champion_fredo', 'Frédo', 'leader_pryce', 'gentleman', 'tm07', ['hyper-potion', 'hyper-potion'],
                "Les Pokémon traversent bien des épreuves et grandissent. Comme nous.\n\nJe suis Frédo, le Champion de l'Arène Glace.\n\nCela fait cinquante ans que je côtoie les Pokémon. Montre-moi ce que tu vaux !",
                "Ah, je suis impressionné par ta force.\nAccepte ce Badge Glacier.",
                "Quand la glace fond, le printemps arrive. Toi aussi, tu as encore de belles saisons devant toi.",
                [['seel', 30, ['hail', 'icy-wind', 'rest', 'snore']], ['dewgong', 32, ['ice-shard', 'aurora-beam', 'rest', 'sleep-talk']],
                    ['piloswine', 34, ['ice-fang', 'mud-bomb', 'hail', 'blizzard']]]],
            [16, 'Badge Lever', 'champion_sandra', 'Sandra', 'leader_clair', 'cooltrainer_f', 'tm59', ['hyper-potion', 'hyper-potion', 'full-heal'],
                "Je suis Sandra, la meilleure dresseuse de Pokémon Dragon au monde.\n\nJe suis même capable de tenir tête au Maître de la Ligue !\n\nTu veux toujours m'affronter ? ... Très bien. Allons-y !",
                "J'ai perdu ?\n... Bon. Je reconnais ta force. Voici le Badge Lever.",
                "Ne crois pas que ce soit fini. Un jour, je serai la plus forte !",
                [['gyarados', 38, ['dragon-pulse', 'dragon-rage', 'bite', 'twister']], ['dragonair', 38, ['dragon-pulse', 'thunder-wave', 'slam', 'fire-blast']],
                    ['dragonair', 38, ['dragon-pulse', 'thunder-wave', 'slam', 'aqua-tail']], ['kingdra', 41, ['dragon-pulse', 'hydro-pump', 'hyper-beam', 'smokescreen']]]],
        ];

        foreach ($leaders as [$order, $badgeName, $slug, $name, $wanted, $fallback, $tm, $items, $intro, $defeat, $after, $team]) {
            [$overworld, $battle] = $this->sprite($wanted, $fallback);
            // icon_badges.png : 2e ligne = les badges de Johto (icônes n° 8 à 15)
            $badge = Badge::updateOrCreate(['order' => $order], ['name' => $badgeName, 'gym_leader' => $name, 'sprite' => (string) ($order - 1)]);
            $this->trainer([
                'slug' => $slug, 'name' => $name, 'trainer_class' => in_array($name, self::WOMEN, true) ? 'Championne' : 'Champion',
                'map_key' => null, 'sprite_overworld' => $overworld, 'sprite_battle' => $battle,
                'reward_money' => 100, 'sight_range' => 0, 'ai_level' => 3, 'items' => $items,
                'intro_text' => $intro, 'defeat_text' => $defeat, 'after_text' => $after,
                'badge_id' => $badge->id, 'reward_item_id' => Item::where('slug', $tm)->value('id'),
            ], $team, 15 + 2 * ($order - 8));
            if (! Item::where('slug', $tm)->exists()) {
                $this->command?->warn("  {$tm} absente de ta base : {$name} ne donnera pas de CT.");
            }
        }
    }

    private function johtoTrainers(): void
    {
        // [slug, classe, nom, [[espèce, niveau], ...], réplique avant, réplique après]
        $trainers = [
            // Arène Vol (salle 210)
            ['arene9_ornithologue_ben', 'Ornithologue', 'Ben', [['spearow', 9]],
                "Albert est un dresseur magnifique ! Comme ses Pokémon Vol !", "Je me suis écrasé..."],
            ['arene9_ornithologue_elie', 'Ornithologue', 'Élie', [['pidgey', 7], ['pidgey', 7]],
                "Les Pokémon Vol ont la grâce des nuages !", "Plumé !"],
            // Arène Insecte (salle 211)
            ['arene10_jumelles_lia_liz', 'Jumelles', 'Lia & Liz', [['ledyba', 10], ['spinarak', 10]],
                "On est deux ! Et nos Insectes aussi !", "Oh non, on a perdu toutes les deux !"],
            ['arene10_scout_arnaud', 'Scout', 'Arnaud', [['paras', 13]],
                "Hector est le meilleur chercheur de Pokémon Insecte !", "Mon Paras..."],
            ['arene10_scout_jean_loic', 'Scout', 'Jean-Loïc', [['caterpie', 12], ['weedle', 12]],
                "Les Insectes, c'est trop cool !", "C'était pas cool, ça..."],
            ['arene10_scout_foulque', 'Scout', 'Foulque', [['weedle', 7], ['kakuna', 9], ['beedrill', 12]],
                "Mon Dardargnan a évolué grâce à Hector !", "Il faut encore qu'on s'entraîne."],
            // Arène Normal (salle 212)
            ['arene11_canon_victoria', 'Canon', 'Victoria', [['sentret', 9], ['sentret', 13], ['sentret', 17]],
                "Blanche est tellement adorable ! Et forte, aussi !", "Je me suis fait avoir..."],
            ['arene11_fillette_barbara', 'Fillette', 'Barbara', [['jigglypuff', 15], ['jigglypuff', 15], ['jigglypuff', 15]],
                "Mes Rondoudou vont t'endormir !", "Même mes Rondoudou se sont endormis..."],
            ['arene11_canon_carla', 'Canon', 'Carla', [['meowth', 16], ['meowth', 16]],
                "Ne sous-estime pas des Pokémon aussi mignons !", "Mes Miaouss !"],
            ['arene11_fillette_jennifer', 'Fillette', 'Jennifer', [['snubbull', 17]],
                "Mon Snubbull a l'air féroce, mais il est gentil !", "Pauvre Snubbull..."],
            // Arène Spectre (salle 213)
            ['arene12_medium_marilou', 'Médium', 'Marilou', [['gastly', 16], ['gastly', 16], ['gastly', 16], ['gastly', 16], ['gastly', 16]],
                "Les esprits t'attendaient...", "Les esprits sont repartis..."],
            ['arene12_medium_rika', 'Médium', 'Rika', [['haunter', 20], ['haunter', 20]],
                "Hihihi... Tu sens ce courant d'air froid ?", "Brr... C'est moi qui ai froid maintenant."],
            ['arene12_medium_serena', 'Médium', 'Serena', [['haunter', 22]],
                "Mortimer voit des choses que nous ne voyons pas...", "Je n'avais rien vu venir."],
            ['arene12_medium_marthe', 'Médium', 'Marthe', [['gastly', 18], ['haunter', 20], ['gastly', 20]],
                "Ne te retourne pas... Il y a quelque chose derrière toi !", "Ahhh... C'était moi, finalement."],
            // Arène Combat (salle 214)
            ['arene13_karateka_hiro', 'Karatéka', 'Hiro', [['hitmonlee', 27]],
                "Hiyaaah ! Mes jambes sont des armes !", "Je dois encore m'entraîner sous la cascade !"],
            ['arene13_karateka_lao', 'Karatéka', 'Lao', [['hitmonchan', 27]],
                "Mes poings sont plus rapides que l'éclair !", "Trop lent..."],
            ['arene13_karateka_ping', 'Karatéka', 'Ping', [['mankey', 23], ['mankey', 23], ['primeape', 25]],
                "Chuck nous entraîne jour et nuit !", "Hoou ! Tu es costaud !"],
            ['arene13_karateka_ryu', 'Karatéka', 'Ryu', [['machop', 25], ['machoke', 25]],
                "Montre-moi la force de ton esprit !", "Ton esprit est fort."],
            // Arène Glace (salle 216)
            ['arene15_surfeur_elliot', 'Surfeur', 'Elliot', [['swinub', 27], ['swinub', 27]],
                "La glace, c'est comme l'eau, mais en plus dur !", "J'ai glissé..."],
            ['arene15_skieuse_luna', 'Skieuse', 'Luna', [['jynx', 29]],
                "Tu sais skier ? Moi, je glisse sur la glace depuis toute petite !", "Je suis tombée..."],
            ['arene15_surfeur_esteban', 'Surfeur', 'Esteban', [['seel', 25], ['seel', 25], ['dewgong', 26]],
                "Mes Pokémon adorent l'eau glacée !", "Ça m'a refroidi."],
            ['arene15_surfeur_kylian', 'Surfeur', 'Kylian', [['shellder', 25], ['seel', 25], ['cloyster', 26]],
                "Frédo est le plus vieux Champion. Respecte-le !", "Tu le respectes à ta façon..."],
            ['arene15_skieuse_calipso', 'Skieuse', 'Calipso', [['dewgong', 29]],
                "Le froid ne me fait pas peur !", "Brrr... Finalement, si."],
            // Arène Dragon (salle 217)
            ['arene16_topdresseur_pablo', 'Topdresseur', 'Pablo', [['dratini', 35], ['dratini', 35], ['seadra', 35]],
                "Seuls les dresseurs de Dragons dignes de ce nom passent ici !", "Tu es digne..."],
            ['arene16_topdresseuse_linda', 'Topdresseuse', 'Linda', [['dratini', 35], ['dragonair', 37]],
                "Sandra est ma cousine... euh, mon modèle !", "Sandra ne va pas être contente..."],
            ['arene16_topdresseur_yves', 'Topdresseur', 'Yves', [['horsea', 35], ['seadra', 37]],
                "Les Dragons viennent de la mer, tu savais ?", "Coulé..."],
            ['arene16_topdresseur_arthur', 'Topdresseur', 'Arthur', [['seadra', 38]],
                "Mon Hypocéan est presque un Dragon !", "Presque..."],
            ['arene16_topdresseur_marin', 'Topdresseur', 'Marin', [['dragonair', 38]],
                "Sandra est la plus forte. Tu ne la battras pas !", "Peut-être que si, finalement..."],
        ];

        $sprites = [];
        foreach ($trainers as [$slug, $class, $name, $team, $intro, $after]) {
            [$wanted, $fallback, $money] = self::JOHTO_CLASSES[$class];
            [$overworld, $battle] = $sprites[$class] ??= $this->sprite($wanted, $fallback);
            $this->trainer([
                'slug' => $slug, 'name' => $name, 'trainer_class' => $class, 'map_key' => null,
                'sprite_overworld' => $overworld, 'sprite_battle' => $battle, 'reward_money' => $money,
                'sight_range' => 3, 'ai_level' => 2, 'items' => [],
                'intro_text' => $intro, 'defeat_text' => $after, 'after_text' => $after,
                'badge_id' => null, 'reward_item_id' => null,
            ], array_map(fn ($p) => [$p[0], $p[1], []], $team), 12);
        }
    }

    /** Crée ou met à jour un dresseur et remplace son équipe de premier combat (les revanches sont gardées) */
    private function trainer(array $data, array $team, int $iv): void
    {
        $trainer = Trainer::updateOrCreate(['slug' => $data['slug']], $data);
        $trainer->pokemon()->where('version', 0)->delete();
        $slot = 0;
        foreach ($team as [$speciesSlug, $level, $moves]) {
            $speciesId = PokemonSpecies::where('slug', $speciesSlug)->value('id');
            if (! $speciesId) {
                $this->command?->warn("Espèce inconnue : {$speciesSlug} ({$data['slug']})");
                continue;
            }
            $moveIds = $moves ? Move::whereIn('slug', $moves)->pluck('id', 'slug') : collect();
            $trainer->pokemon()->create([
                'version' => 0, 'slot' => ++$slot, 'pokemon_species_id' => $speciesId, 'level' => $level, 'iv' => $iv,
                // les capacités de Rouge Feu / Vert Feuille (celles absentes de la base sont ignorées)
                'move_ids' => $moveIds->isNotEmpty() ? collect($moves)->map(fn ($m) => $moveIds[$m] ?? null)->filter()->values()->all() : null,
            ]);
        }
        $this->command?->info("{$data['trainer_class']} {$data['name']} : {$slot} Pokémon");
    }
}
