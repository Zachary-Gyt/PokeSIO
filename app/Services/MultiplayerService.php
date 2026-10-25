<?php

namespace App\Services;

use App\Battle\InvalidAction;
use App\Events\PlayerNotice;
use App\Models\Battle;
use App\Models\BattleChallenge;
use App\Models\Character;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;

/**
 * Les INVITATIONS entre joueurs : "Sacha veut échanger avec vous" / "Sacha vous défie !".
 *
 *   1. A va voir B sur la carte, appuie sur Action, choisit "Échanger" ou "Combattre" -> invite()
 *   2. B reçoit une notification en direct (Reverb) et répond oui / non             -> answer()
 *   3. Oui : l'échange (TradeService) ou le combat (PvpService) commence pour les deux.
 *
 * Une invitation sans réponse expire au bout de 60 secondes.
 */
class MultiplayerService
{
    public const EXPIRES_AFTER_SECONDS = 60;

    public function __construct(private PvpService $pvp)
    {
    }

    /** $kind = 'trade' ou 'battle'. Renvoie l'id de l'invitation. */
    public function invite(Character $from, Character $to, string $kind): int
    {
        if ($from->id === $to->id) {
            throw new InvalidAction('Vous ne pouvez pas vous inviter vous-même !');
        }
        if ($this->isBusy($from)) {
            throw new InvalidAction('Terminez d\'abord votre échange ou votre combat en cours.');
        }
        if ($this->isBusy($to)) {
            throw new InvalidAction("{$to->name} est occupé pour le moment.");
        }
        if (! $from->hasUsablePokemon() || ! $to->party()->exists()) {
            throw new InvalidAction('Il faut au moins un Pokémon en état de se battre.');
        }

        if ($kind === 'trade') {
            // Une seule invitation en attente entre les deux : on remplace l'ancienne
            Trade::where('initiator_id', $from->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            $invitation = Trade::create(['initiator_id' => $from->id, 'recipient_id' => $to->id, 'status' => 'pending']);
        } else {
            BattleChallenge::where('challenger_id', $from->id)->where('status', 'pending')->update(['status' => 'expired']);
            $invitation = BattleChallenge::create(['challenger_id' => $from->id, 'challenged_id' => $to->id, 'status' => 'pending']);
        }

        PlayerNotice::send($to->id, 'invitation', [
            'id' => $invitation->id,
            'kind' => $kind,
            'from' => ['id' => $from->id, 'name' => $from->name],
        ]);

        return $invitation->id;
    }

    /**
     * B répond. Renvoie ['kind', 'accepted', 'tradeId' | 'battleId'].
     * Les deux joueurs reçoivent la suite par notification.
     */
    public function answer(Character $to, string $kind, int $id, bool $accept): array
    {
        return DB::transaction(function () use ($to, $kind, $id, $accept) {
            if ($kind === 'trade') {
                $trade = Trade::whereKey($id)->lockForUpdate()->first();
                if (! $trade || $trade->recipient_id !== $to->id || $trade->status !== 'pending' || $this->expired($trade->created_at)) {
                    throw new InvalidAction('Cette invitation n\'est plus valable.');
                }
                if ($accept && $this->isBusy($trade->initiator, except: $trade)) {
                    throw new InvalidAction("{$trade->initiator->name} est occupé pour le moment.");
                }
                $trade->update(['status' => $accept ? 'negotiating' : 'declined']);
                $result = ['kind' => 'trade', 'accepted' => $accept, 'tradeId' => $trade->id];
                PlayerNotice::send($trade->initiator_id, 'answer', $result + ['name' => $to->name]);

                return $result;
            }

            $challenge = BattleChallenge::whereKey($id)->lockForUpdate()->first();
            if (! $challenge || $challenge->challenged_id !== $to->id || $challenge->status !== 'pending' || $this->expired($challenge->created_at)) {
                throw new InvalidAction('Ce défi n\'est plus valable.');
            }
            if (! $accept) {
                $challenge->update(['status' => 'declined']);
                $result = ['kind' => 'battle', 'accepted' => false];
                PlayerNotice::send($challenge->challenger_id, 'answer', $result + ['name' => $to->name]);

                return $result;
            }
            if ($this->isBusy($challenge->challenger) || $this->isBusy($to)) {
                throw new InvalidAction('L\'un des deux dresseurs est occupé.');
            }
            if (! $to->hasUsablePokemon()) {
                throw new InvalidAction('Vous n\'avez aucun Pokémon en état de se battre !');
            }

            $battle = $this->pvp->start($challenge->challenger, $to);
            $challenge->update(['status' => 'accepted', 'battle_id' => $battle->id]);
            $result = ['kind' => 'battle', 'accepted' => true, 'battleId' => $battle->id];
            PlayerNotice::send($challenge->challenger_id, 'answer', $result + ['name' => $to->name]);

            return $result;
        });
    }

    /** En combat, ou dans un échange déjà commencé */
    public function isBusy(Character $character, ?Trade $except = null): bool
    {
        $inBattle = Battle::involving($character)->active()->exists();
        $inTrade = Trade::involving($character)->where('status', 'negotiating')
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->exists();

        return $inBattle || $inTrade;
    }

    private function expired($createdAt): bool
    {
        return $createdAt->lt(now()->subSeconds(self::EXPIRES_AFTER_SECONDS));
    }
}
