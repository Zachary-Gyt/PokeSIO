<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copie les images d'interface de Pokémon Essentials utilisées par l'écran de combat :
 *
 *   php artisan game:essentials-assets "C:\...\Pokemon Essentials v21.1"
 *
 * Ce qui est copié (et où) :
 *   Graphics\UI\Battle\*                -> public\game\battle\ui\
 *   Graphics\UI\types.png, shiny.png... -> public\game\battle\ui\
 *   Graphics\Battlebacks\* (sans unused) -> public\game\battle\backgrounds\
 *   Graphics\Battle animations\ball_*   -> public\game\battle\balls\
 *   Graphics\UI\Party\*                 -> public\game\ui\party\
 *   Graphics\Windowskins\choice 1.png   -> public\game\ui\choice.png   (cadre des menus)
 *   Graphics\UI\sel_arrow.png          -> public\game\ui\sel_arrow.png (curseur des menus)
 *   Graphics\UI\Summary, Bag, Storage  -> public\game\ui\summary, bag, storage (Résumé, Sac, PC)
 *   Graphics\UI\Load\ -> public\game\ui\load\ (étape 10 : écran de choix du personnage)
 *   Étape 17 : les personnages jouables (config/avatars.php) -> sprites\player\{red,leaf,brendan,may}_{walk,run,surf,bike,fish,back}.png
 *              et sprites\trainers\battle\player_{red,leaf,brendan,may}.png (jamais remplacés s'ils existent déjà)
 *   Étape 22 : Characters\berrytree*.png -> sprites\berries\ (buissons à Baies)
 *   Étape 20 : UI\Slot Machine\* -> ui\slot_machine\ ; Tilesets\Game Corner interior.png -> tilesets\game_corner.png ;
 *              --roulette="...\Essentials-RouletteGame-main" : Files\Graphics -> ui\roulette\, Files\SE -> audio\se\
 *   Étape 19 : UI\Naming\bg.png -> ui\naming\bg.png ; UI\Move Reminder\* -> ui\move_reminder\
 *   Étape 18 : Pokemon\Eggs\000, 000_icon, 000_cracks -> sprites\pokemon\eggs\ ; UI\hatch_bg.png -> ui\hatch_bg.png
 *   Étape 16 : Characters\Object ball, Healing balls 1 / 2 -> sprites\field\item_ball, heal_balls_1 / 2
 *   Étape 11 : Characters\Object tree 1 / rock / boulder -> sprites\field\ ; base_surf -> sprites\player\ ;
 *              UI\Field move\bg.png, UI\Town Map\ -> ui\ ; Autotiles\Still water.png -> tilesets\water.png
 *   Graphics\UI\evolution_bg.png, trade_bg.png, left_arrow.png, right_arrow.png -> public\game\ui\
 *   Graphics\Items\*                    -> public\game\items\ (icônes des objets)
 *   Graphics\Trainers\* (dresseurs)     -> public\game\sprites\trainers\battle\ (si --trainers)
 *   Graphics\Characters\trainer_*      -> public\game\sprites\trainers\overworld\ (si --trainers)
 *   Graphics\UI\Trainer Card, Mart     -> public\game\ui\trainer_card, mart (Carte Dresseur, Boutique)
 *
 * Les fichiers déjà présents sont remplacés, sauf les sprites de dos du joueur (tu as peut-être les tiens).
 */
class ImportEssentialsAssets extends Command
{
    protected $signature = 'game:essentials-assets
        {essentials : dossier de Pokémon Essentials (celui qui contient Graphics)}
        {--trainers : copier aussi tous les sprites des dresseurs, combat et carte (en minuscules : LASS.png -> lass.png)}
        {--roulette= : étape 20 : dossier du script Roulette de FL (Essentials-RouletteGame-main), pour ses images et ses sons}';

    protected $description = 'Copie les images de combat de Pokémon Essentials dans public/game';

    public function handle(): int
    {
        $root = rtrim($this->argument('essentials'), '\\/');
        $graphics = File::isDirectory("{$root}/Graphics") ? "{$root}/Graphics" : $root;

        if (! File::isDirectory("{$graphics}/UI/Battle")) {
            $this->error("Introuvable : {$graphics}\\UI\\Battle. Indique le dossier d'Essentials (celui qui contient Graphics).");

            return self::FAILURE;
        }

        $count = 0;
        $count += $this->copyFolder("{$graphics}/UI/Battle", public_path('game/battle/ui'));
        foreach (['types.png', 'shiny.png', 'category.png', 'statuses.png'] as $file) {
            $count += $this->copyFile("{$graphics}/UI/{$file}", public_path("game/battle/ui/{$file}"));
        }
        $count += $this->copyFolder("{$graphics}/Battlebacks", public_path('game/battle/backgrounds'));
        $count += $this->copyFolder("{$graphics}/Battle animations", public_path('game/battle/balls'), fn ($name) => str_starts_with($name, 'ball_'));
        $count += $this->copyFolder("{$graphics}/UI/Party", public_path('game/ui/party'));
        $count += $this->copyFile("{$graphics}/Windowskins/choice 1.png", public_path('game/ui/choice.png'));
        $count += $this->copyFile("{$graphics}/UI/sel_arrow.png", public_path('game/ui/sel_arrow.png'));

        // Étape 7 : écrans du menu (Résumé, Sac, PC, évolution) et icônes des objets
        $count += $this->copyFolder("{$graphics}/UI/Summary", public_path('game/ui/summary'));
        $count += $this->copyFolder("{$graphics}/UI/Bag", public_path('game/ui/bag'));
        $count += $this->copyFolder("{$graphics}/UI/Storage", public_path('game/ui/storage'));
        foreach (['evolution_bg.png', 'trade_bg.png', 'left_arrow.png', 'right_arrow.png'] as $file) {
            $count += $this->copyFile("{$graphics}/UI/{$file}", public_path("game/ui/{$file}"));
        }
        $count += $this->copyFolder("{$graphics}/Items", public_path('game/items'));

        // Étape 8 : Carte Dresseur, Boutique, sprites de face du joueur
        $count += $this->copyFolder("{$graphics}/UI/Trainer Card", public_path('game/ui/trainer_card'));
        $count += $this->copyFolder("{$graphics}/UI/Mart", public_path('game/ui/mart'));

        // Étape 10 : l'écran de chargement (fond et panneaux), pour la page de choix du personnage
        $count += $this->copyFolder("{$graphics}/UI/Load", public_path('game/ui/load'));

        // Étape 11 : capacités hors combat (obstacles, Surf, Vol) et l'eau de la carte de test
        $count += $this->copyFile("{$graphics}/Characters/Object tree 1.png", public_path('game/sprites/field/tree.png'));
        $count += $this->copyFile("{$graphics}/Characters/Object rock.png", public_path('game/sprites/field/rock.png'));
        $count += $this->copyFile("{$graphics}/Characters/Object boulder.png", public_path('game/sprites/field/boulder.png'));
        $count += $this->copyFile("{$graphics}/Characters/base_surf.png", public_path('game/sprites/player/surf_base.png'));
        // Étape 16 : Poké Ball posée par terre, et Balls de la machine de soin du Centre Pokémon
        $count += $this->copyFile("{$graphics}/Characters/Object ball.png", public_path('game/sprites/field/item_ball.png'));
        $count += $this->copyFile("{$graphics}/Characters/Healing balls 1.png", public_path('game/sprites/field/heal_balls_1.png'));
        $count += $this->copyFile("{$graphics}/Characters/Healing balls 2.png", public_path('game/sprites/field/heal_balls_2.png'));

        // Étape 22 : les buissons à Baies (une planche par Baie, et la terre plantée)
        $count += $this->copyFolder("{$graphics}/Characters", public_path('game/sprites/berries'), fn ($name) => str_starts_with($name, 'berrytree'));

        $count += $this->copyFile("{$graphics}/UI/Field move/bg.png", public_path('game/ui/field_move_bg.png'));
        foreach (['bg.png', 'mapRegion0.png', 'icon_fly.png', 'cursor.png'] as $file) {
            $count += $this->copyFile("{$graphics}/UI/Town Map/{$file}", public_path("game/ui/town_map/{$file}"));
        }
        $count += $this->copyFile("{$graphics}/Autotiles/Still water.png", public_path('game/tilesets/water.png'));

        // Étape 13 : le Pokédex
        $count += $this->copyFolder("{$graphics}/UI/Pokedex", public_path('game/ui/pokedex'));

        // Étape 18 : les Œufs (sprite, icône, fissures) et le fond de l'éclosion
        foreach (['000.png', '000_icon.png', '000_cracks.png'] as $file) {
            $count += $this->copyFile("{$graphics}/Pokemon/Eggs/{$file}", public_path("game/sprites/pokemon/eggs/{$file}"));
        }
        $count += $this->copyFile("{$graphics}/UI/hatch_bg.png", public_path('game/ui/hatch_bg.png'));

        // Étape 20 : le Casino : la machine à sous d'Essentials, et le tileset "Game Corner interior"
        $count += $this->copyFolder("{$graphics}/UI/Slot Machine", public_path('game/ui/slot_machine'));
        $count += $this->copyFile("{$graphics}/Tilesets/Game Corner interior.png", public_path('game/tilesets/game_corner.png'));
        if ($roulette = $this->option('roulette')) {
            $count += $this->copyRoulette(rtrim($roulette, '\\/'));
        }

        // Étape 19 : l'écran de surnom et le Maître des capacités
        $count += $this->copyFile("{$graphics}/UI/Naming/bg.png", public_path('game/ui/naming/bg.png'));
        $count += $this->copyFolder("{$graphics}/UI/Move Reminder", public_path('game/ui/move_reminder'));

        // Étape 17 : les personnages jouables (config/avatars.php)
        $count += $this->copyAvatars($graphics);

        if ($this->option('trainers')) {
            foreach (File::files("{$graphics}/Trainers") as $file) {
                $name = $file->getFilename();
                if (str_contains($name, '_back') || str_starts_with($name, 'POKEMONTRAINER_')) {
                    continue;
                }
                $count += $this->copyFile($file->getPathname(), public_path('game/sprites/trainers/battle/' . strtolower($name)));
            }

            // Planches de déplacement sur la carte : Characters\trainer_LEADER_Brock.png -> overworld\leader_brock.png
            foreach (File::files("{$graphics}/Characters") as $file) {
                $name = $file->getFilename();
                if (str_starts_with($name, 'trainer_') && strtolower($file->getExtension()) === 'png') {
                    $count += $this->copyFile($file->getPathname(), public_path('game/sprites/trainers/overworld/' . strtolower(substr($name, 8))));
                }
            }
        }

        $this->info("{$count} fichiers copiés.");

        return self::SUCCESS;
    }

    /**
     * Étape 17 : les images de chaque personnage jouable. Pour chaque fichier attendu :
     *   - déjà présent dans public/game : conservé (tu as peut-être dessiné le tien) ;
     *   - sinon, l'ancien fichier "male_..." / "female_..." (avant l'étape 17) est repris pour Red / Leaf ;
     *   - sinon, le fichier d'Essentials est copié (cherché dans Graphics\Characters, Graphics\Trainers, puis Graphics).
     */
    private function copyAvatars(string $graphics): int
    {
        $count = 0;
        foreach (config('avatars', []) as $key => $avatar) {
            foreach ($avatar['files'] ?? [] as $kind => $source) {
                $target = $kind === 'front'
                    ? public_path("game/sprites/trainers/battle/player_{$key}.png")
                    : public_path("game/sprites/player/{$key}_{$kind}.png");
                if (File::exists($target)) {
                    continue;
                }
                $legacy = empty($avatar['legacy']) ? null : ($kind === 'front'
                    ? public_path("game/sprites/trainers/battle/player_{$avatar['legacy']}.png")
                    : public_path("game/sprites/player/{$avatar['legacy']}_{$kind}.png"));
                if ($legacy && File::exists($legacy)) {
                    File::ensureDirectoryExists(dirname($target));
                    File::copy($legacy, $target);
                    $this->line('  ' . basename($legacy) . ' -> ' . basename($target));
                    $count++;
                    continue;
                }
                $found = collect(["{$graphics}/Characters", "{$graphics}/Trainers", $graphics])
                    ->map(fn ($dir) => "{$dir}/{$source}.png")
                    ->first(fn ($path) => File::exists($path));
                if (! $found) {
                    $this->warn("  {$avatar['name']} : {$source}.png introuvable dans Graphics (pour " . basename($target) . ')');
                    continue;
                }
                $count += $this->copyFile($found, $target);
            }
        }

        return $count;
    }

    private function copyFolder(string $from, string $to, ?callable $filter = null): int
    {
        if (! File::isDirectory($from)) {
            $this->warn("Dossier introuvable : {$from}");

            return 0;
        }

        $count = 0;
        foreach (File::files($from) as $file) {   // files() : pas les sous-dossiers ("unused")
            if (strtolower($file->getExtension()) !== 'png' || ($filter && ! $filter($file->getFilename()))) {
                continue;
            }
            $count += $this->copyFile($file->getPathname(), "{$to}/{$file->getFilename()}");
        }

        return $count;
    }

    /**
     * Étape 20 : la roulette (script de FL, licence MIT-0) : Files/Graphics/*.png -> public/game/ui/roulette,
     * Files/SE/*.ogg -> public/game/audio/se. On accepte le dossier du zip, ou celui qui contient "Files".
     */
    private function copyRoulette(string $root): int
    {
        $base = collect([$root, "{$root}/Essentials-RouletteGame-main"])->first(fn ($d) => File::isDirectory("{$d}/Files/Graphics"));
        if (! $base) {
            $this->warn("Roulette : dossier Files\\Graphics introuvable dans {$root}");

            return 0;
        }
        $count = $this->copyFolder("{$base}/Files/Graphics", public_path('game/ui/roulette'));
        foreach (File::files("{$base}/Files/SE") as $file) {
            $count += $this->copyFile($file->getPathname(), public_path('game/audio/se/' . $file->getFilename()));
        }

        return $count;
    }

    private function copyFile(string $from, string $to): int
    {
        if (! File::exists($from)) {
            $this->warn("Fichier introuvable : {$from}");

            return 0;
        }
        File::ensureDirectoryExists(dirname($to));
        File::copy($from, $to);

        return 1;
    }
}
