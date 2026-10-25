<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Models\Character;
use Illuminate\Support\Facades\DB;

/**
 * La BANQUE : chaque personnage a un compte. On y dépose et on y retire de l'argent (PNJ ou distributeur,
 * propriété Tiled action = bank), et l'argent déposé rapporte des intérêts (config/bank.php).
 *
 * Les intérêts sont COMPOSÉS et comptés en continu : solde x (1 + taux) ^ (jours écoulés).
 * Ils sont calculés à chaque visite pour tout le temps écoulé depuis la précédente (characters.bank_updated_at) :
 * pas besoin de tâche planifiée, et le temps compte même hors ligne. Le compte est plafonné (max_balance).
 */
class BankService
{
    public function rate(): float
    {
        return max(0.0, (float) config('bank.daily_rate', 0.10));
    }

    public function maxBalance(): int
    {
        return max(0, (int) config('bank.max_balance', 9_999_999));
    }

    /** Ajoute les intérêts gagnés depuis le dernier calcul (sans enregistrer). Renvoie les intérêts gagnés. */
    public function accrue(Character $character): float
    {
        $balance = (float) $character->bank_balance;
        $since = $character->bank_updated_at ? \Illuminate\Support\Carbon::parse($character->bank_updated_at) : null;
        $character->bank_updated_at = now();
        if ($balance <= 0 || ! $since) {
            return 0.0;
        }
        $days = max(0, $since->diffInSeconds(now(), false)) / 86400;
        $new = min((float) $this->maxBalance(), $balance * (1 + $this->rate()) ** $days);
        $earned = max(0.0, $new - $balance);
        $character->bank_balance = max($balance, $new);

        return $earned;
    }

    /** Le compte, avec les intérêts à jour */
    public function status(Character $character): array
    {
        return DB::transaction(function () use ($character) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $earned = $this->accrue($character);
            $character->save();

            return $this->present($character, $earned);
        });
    }

    /** Déposer de l'argent du porte-monnaie sur le compte */
    public function deposit(Character $character, int $amount): array
    {
        return DB::transaction(function () use ($character, $amount) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $this->accrue($character);
            if ($amount < max(1, (int) config('bank.min_deposit', 1))) {
                throw new InvalidAction('Combien voulez-vous déposer ?');
            }
            if ($amount > $character->money) {
                throw new InvalidAction('Vous n\'avez pas assez d\'argent sur vous.');
            }
            $room = (int) floor($this->maxBalance() - (float) $character->bank_balance);
            if ($amount > $room) {
                throw new InvalidAction($room > 0
                    ? 'Votre compte est presque plein : vous pouvez encore déposer ' . number_format($room, 0, ',', ' ') . ' ₽.'
                    : 'Votre compte est plein !');
            }
            $character->money -= $amount;
            $character->bank_balance = (float) $character->bank_balance + $amount;
            $character->save();

            return $this->present($character);
        });
    }

    /** Retirer de l'argent du compte vers le porte-monnaie */
    public function withdraw(Character $character, int $amount): array
    {
        return DB::transaction(function () use ($character, $amount) {
            $character = Character::whereKey($character->id)->lockForUpdate()->first();
            $this->accrue($character);
            if ($amount < 1) {
                throw new InvalidAction('Combien voulez-vous retirer ?');
            }
            if ($amount > (int) floor((float) $character->bank_balance)) {
                throw new InvalidAction('Il n\'y a pas autant d\'argent sur votre compte.');
            }
            $room = Character::MAX_MONEY - $character->money;
            if ($amount > $room) {
                throw new InvalidAction('Votre porte-monnaie ne peut pas contenir plus de ' . number_format(Character::MAX_MONEY, 0, ',', ' ') . ' ₽.');
            }
            $character->bank_balance = (float) $character->bank_balance - $amount;
            $character->money += $amount;
            $character->save();

            return $this->present($character);
        });
    }

    /** Ce que le jeu affiche : solde (arrondi en dessous), argent sur soi, et les limites */
    public function present(Character $character, float $earned = 0.0): array
    {
        $balance = (int) floor((float) $character->bank_balance);

        return [
            'balance' => $balance,
            'money' => (int) $character->money,
            'earned' => (int) floor($earned),
            'rate' => round($this->rate() * 100, 2),
            'maxBalance' => $this->maxBalance(),
            'canDeposit' => max(0, min((int) $character->money, (int) floor($this->maxBalance() - (float) $character->bank_balance))),
            'canWithdraw' => max(0, min($balance, Character::MAX_MONEY - (int) $character->money)),
        ];
    }
}
