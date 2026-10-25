<?php

namespace App\Support;

use RuntimeException;

/**
 * Lit un fichier "Marshal" de Ruby (format 4.8), comme les .rxdata de RPG Maker XP / Pokémon Essentials.
 * Seulement ce qu'il faut pour Data/Tilesets.rxdata : nil, booléens, entiers, flottants, chaînes, symboles,
 * tableaux, tables de hachage, objets (=> tableau ['@class' => ..., '@ivar' => ...]) et objets "_dump"
 * (comme la classe Table de RPG Maker : => ['@class' => 'Table', '@data' => octets bruts]).
 *
 *   $data = RubyMarshal::load(file_get_contents('Tilesets.rxdata'));
 */
class RubyMarshal
{
    private int $pos = 0;

    private array $symbols = [];

    private array $objects = [];

    private function __construct(private string $bytes)
    {
    }

    public static function load(string $bytes): mixed
    {
        $reader = new self($bytes);
        if (strlen($bytes) < 2 || ord($bytes[0]) !== 4 || ord($bytes[1]) !== 8) {
            throw new RuntimeException('Ce fichier n\'est pas un fichier Marshal de Ruby (version 4.8).');
        }
        $reader->pos = 2;

        return $reader->value();
    }

    private function byte(): int
    {
        if ($this->pos >= strlen($this->bytes)) {
            throw new RuntimeException('Fichier Marshal tronqué.');
        }

        return ord($this->bytes[$this->pos++]);
    }

    private function raw(int $length): string
    {
        $s = substr($this->bytes, $this->pos, $length);
        $this->pos += $length;

        return $s;
    }

    /** Un entier Marshal (format compact) */
    private function int(): int
    {
        $c = $this->byte();
        if ($c >= 128) {
            $c -= 256;   // octet signé
        }
        if ($c === 0) {
            return 0;
        }
        if ($c >= 5) {
            return $c - 5;
        }
        if ($c <= -5) {
            return $c + 5;
        }
        $n = 0;
        if ($c > 0) {
            for ($i = 0; $i < $c; $i++) {
                $n |= $this->byte() << (8 * $i);
            }

            return $n;
        }
        $c = -$c;
        for ($i = 0; $i < $c; $i++) {
            $n |= $this->byte() << (8 * $i);
        }

        return $n - (1 << (8 * $c));
    }

    private function bytesString(): string
    {
        return $this->raw($this->int());
    }

    private function symbol(): string
    {
        $type = chr($this->byte());
        if ($type === ':') {
            return $this->symbols[] = $this->bytesString();
        }
        if ($type === ';') {
            return $this->symbols[$this->int()];
        }
        throw new RuntimeException("Symbole attendu, trouvé « {$type} ».");
    }

    private function value(): mixed
    {
        $type = chr($this->byte());

        switch ($type) {
            case '0': return null;
            case 'T': return true;
            case 'F': return false;
            case 'i': return $this->int();
            case ':': return $this->symbols[] = $this->bytesString();
            case ';': return $this->symbols[$this->int()];
            case '@': return $this->objects[$this->int()] ?? null;
            case '"':
                $s = $this->bytesString();
                $this->objects[] = $s;

                return $s;
            case 'f':
                $s = $this->bytesString();
                $this->objects[] = $s;

                return match ($s) { 'inf' => INF, '-inf' => -INF, 'nan' => NAN, default => (float) $s };
            case 'l':   // grand entier
                $sign = chr($this->byte());
                $len = $this->int() * 2;
                $n = 0;
                for ($i = 0; $i < $len; $i++) {
                    $n += $this->byte() * (256 ** $i);
                }
                $this->objects[] = $n;

                return $sign === '-' ? -$n : $n;
            case 'I':   // valeur + variables d'instance (l'encodage d'une chaîne)
                $v = $this->value();
                $count = $this->int();
                for ($i = 0; $i < $count; $i++) {
                    $this->symbol();
                    $this->value();
                }

                return $v;
            case '[':
                $index = count($this->objects);
                $this->objects[] = null;
                $list = [];
                $count = $this->int();
                for ($i = 0; $i < $count; $i++) {
                    $list[] = $this->value();
                }

                return $this->objects[$index] = $list;
            case '{':
            case '}':
                $index = count($this->objects);
                $this->objects[] = null;
                $hash = [];
                $count = $this->int();
                for ($i = 0; $i < $count; $i++) {
                    $k = $this->value();
                    $hash[is_scalar($k) ? (string) $k : $i] = $this->value();
                }
                if ($type === '}') {
                    $this->value();   // valeur par défaut
                }

                return $this->objects[$index] = $hash;
            case 'o':
                $index = count($this->objects);
                $this->objects[] = null;
                $object = ['@class' => $this->symbol()];
                $count = $this->int();
                for ($i = 0; $i < $count; $i++) {
                    $name = $this->symbol();
                    $object[$name] = $this->value();
                }

                return $this->objects[$index] = $object;
            case 'u':   // objet "_dump" (Table, Color, Tone de RPG Maker)
                $class = $this->symbol();
                $data = $this->bytesString();
                $object = ['@class' => $class, '@data' => $data];
                $this->objects[] = $object;

                return $object;
            case 'U':
                $index = count($this->objects);
                $this->objects[] = null;
                $class = $this->symbol();

                return $this->objects[$index] = ['@class' => $class, '@data' => $this->value()];
            case 'e':   // objet étendu par un module
                $this->symbol();

                return $this->value();
            case 'C':   // sous-classe d'une classe de base (String, Array...)
                $this->symbol();

                return $this->value();
            case 'c':
            case 'm':
                $name = $this->bytesString();
                $this->objects[] = $name;

                return $name;
            case '/':
                $source = $this->bytesString();
                $this->byte();
                $this->objects[] = $source;

                return $source;
            case 'S':
                $index = count($this->objects);
                $this->objects[] = null;
                $struct = ['@class' => $this->symbol()];
                $count = $this->int();
                for ($i = 0; $i < $count; $i++) {
                    $struct[$this->symbol()] = $this->value();
                }

                return $this->objects[$index] = $struct;
            default:
                throw new RuntimeException('Type Marshal inconnu « ' . $type . ' » (position ' . ($this->pos - 1) . ').');
        }
    }

    /**
     * Les valeurs d'une Table de RPG Maker (objet "_dump") : en-tête de 5 entiers 32 bits
     * (dimensions, xsize, ysize, zsize, nombre), puis des entiers 16 bits.
     *
     * @return int[]
     */
    public static function table(?array $table): array
    {
        $data = $table['@data'] ?? null;
        if (! is_string($data) || strlen($data) < 20) {
            return [];
        }
        $count = unpack('V5', substr($data, 0, 20))[5];
        $values = array_values(unpack('v' . $count, substr($data, 20, $count * 2)) ?: []);

        return array_map(fn ($v) => $v >= 0x8000 ? $v - 0x10000 : $v, $values);
    }
}
