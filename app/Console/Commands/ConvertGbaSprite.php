<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Convertit des sprites de dresseur au format GBA (pokeemerald, comme ceux de « The Spriters Resource »)
 * au format du jeu (Pokémon Essentials).
 *
 *   php artisan game:sprite-gba skier_f --carte=skier_f_hns.png --combat=trainers_hns/skier_f_hns.png
 *
 * --carte  : la bande de marche GBA, 9 images côte à côte (16 x 32 ou 32 x 32 chacune) :
 *            bas, haut, gauche, marche bas x2, marche haut x2, marche gauche x2 (la droite = la gauche en miroir)
 *            -> public/game/sprites/trainers/overworld/<nom>.png (planche 4 x 4 : bas, gauche, droite, haut)
 * --combat : l'image de combat GBA de 64 x 64
 *            -> public/game/sprites/trainers/battle/<nom>.png (128 x 128)
 *
 * La couleur n° 0 de la palette devient transparente, et tout est agrandi x2 (pixels nets).
 */
class ConvertGbaSprite extends Command
{
    protected $signature = 'game:sprite-gba
        {nom : nom du sprite dans le jeu (ex. leader_falkner)}
        {--carte= : bande de marche GBA (9 images)}
        {--combat= : image de combat GBA (64 x 64)}';

    protected $description = 'Convertit un sprite de dresseur GBA (pokeemerald) au format du jeu';

    public function handle(): int
    {
        $name = $this->argument('nom');
        if (! $this->option('carte') && ! $this->option('combat')) {
            $this->error('Indique au moins --carte=... ou --combat=...');

            return self::FAILURE;
        }
        if ($file = $this->option('carte')) {
            if (! File::exists($file)) {
                $this->error("Introuvable : {$file}");

                return self::FAILURE;
            }
            $out = public_path("game/sprites/trainers/overworld/{$name}.png");
            [$w, $h] = $this->walk($file, $out);
            $this->info("Planche de carte : {$out} ({$w} x {$h})");
        }
        if ($file = $this->option('combat')) {
            if (! File::exists($file)) {
                $this->error("Introuvable : {$file}");

                return self::FAILURE;
            }
            $out = public_path("game/sprites/trainers/battle/{$name}.png");
            $src = $this->load($file);
            $big = $this->scale($src, imagesx($src), imagesy($src));
            imagepng($big, $out);
            $this->info("Image de combat : {$out} (" . imagesx($big) . ' x ' . imagesy($big) . ')');
        }
        $this->line('Relance le seeder du dresseur (ex. php artisan db:seed --class=OrtGymsSeeder), puis Ctrl+F5.');

        return self::SUCCESS;
    }

    /** Charge le PNG en couleurs vraies, avec la couleur n° 0 de la palette transparente */
    private function load(string $file)
    {
        $src = imagecreatefrompng($file);
        $w = imagesx($src);
        $h = imagesy($src);
        $paletted = ! imageistruecolor($src);
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $index = imagecolorat($src, $x, $y);
                if ($paletted && $index === 0) {
                    imagesetpixel($img, $x, $y, $clear);
                    continue;
                }
                $c = imagecolorsforindex($src, $index);
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $c['red'], $c['green'], $c['blue'], $c['alpha']));
            }
        }

        return $img;
    }

    /** Agrandit x2 (plus proche voisin) une zone de l'image */
    private function scale($img, int $w, int $h, int $sx = 0, int $sy = 0)
    {
        $out = imagecreatetruecolor($w * 2, $h * 2);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresized($out, $img, 0, 0, $sx, $sy, $w * 2, $h * 2, $w, $h);

        return $out;
    }

    private function walk(string $file, string $out): array
    {
        $img = $this->load($file);
        $h = imagesy($img);
        $fw = in_array(imagesx($img), [144, 160], true) ? 16 : 32;

        // Le haut vide des images GBA est retiré (comme les planches d'Essentials : 32 x 48 par image)
        $top = $h;
        $left = $fw;
        $right = 0;
        for ($i = 0; $i < 9; $i++) {
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $fw; $x++) {
                    if (((imagecolorat($img, $i * $fw + $x, $y) >> 24) & 0x7F) < 127) {
                        $top = min($top, $y);
                        $left = min($left, $x);
                        $right = max($right, $x);
                    }
                }
            }
        }
        $y0 = $top >= 8 ? 8 : 0;
        [$x0, $x1] = $fw === 32 && $left >= 8 && $right < 24 ? [8, 24] : [0, $fw];
        $cw = $x1 - $x0;
        $ch = $h - $y0;

        // Images GBA : 0 bas, 1 haut, 2 gauche, 3-4 marche bas, 5-6 marche haut, 7-8 marche gauche
        // Planche Essentials : lignes bas, gauche, droite, haut ; colonnes debout, pas 1, debout, pas 2
        $rows = [[0, 3, 0, 4, false], [2, 7, 2, 8, false], [2, 7, 2, 8, true], [1, 5, 1, 6, false]];
        $sheet = imagecreatetruecolor($cw * 4, $ch * 4);
        imagealphablending($sheet, false);
        imagesavealpha($sheet, true);
        imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));
        foreach ($rows as $r => $row) {
            $mirror = array_pop($row);
            foreach ($row as $c => $frame) {
                $piece = imagecreatetruecolor($cw, $ch);
                imagealphablending($piece, false);
                imagesavealpha($piece, true);
                imagecopy($piece, $img, 0, 0, $frame * $fw + $x0, $y0, $cw, $ch);
                if ($mirror) {
                    imageflip($piece, IMG_FLIP_HORIZONTAL);
                }
                imagecopy($sheet, $piece, $c * $cw, $r * $ch, 0, 0, $cw, $ch);
            }
        }
        $big = $this->scale($sheet, $cw * 4, $ch * 4);
        imagepng($big, $out);

        return [imagesx($big), imagesy($big)];
    }
}
