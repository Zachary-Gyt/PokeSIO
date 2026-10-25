<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Donne (ou retire) le grade Professeur à un compte : le titre [Professeur] devant son nom dans le chat.
 * Aucun privilège : un Professeur ne peut rien faire de plus qu'un autre joueur.
 * (Les administrateurs peuvent aussi le faire depuis la page /admin ou avec /prof dans le chat.)
 *
 *   php artisan game:professeur prof@exemple.fr
 *   php artisan game:professeur prof@exemple.fr --remove
 *   php artisan game:professeur --list
 */
class MakeProfessor extends Command
{
    protected $signature = 'game:professeur {email? : Adresse e-mail du compte} {--remove : Retirer le grade} {--list : Lister les Professeurs}';

    protected $description = 'Donne ou retire le grade Professeur (titre dans le chat) à un compte';

    public function handle(): int
    {
        if ($this->option('list')) {
            $list = User::where('is_professor', true)->get(['id', 'name', 'email']);
            $list->isEmpty()
                ? $this->info('Aucun Professeur.')
                : $this->table(['id', 'Nom', 'E-mail'], $list->toArray());

            return self::SUCCESS;
        }

        $email = $this->argument('email') ?? $this->ask('Adresse e-mail du compte');
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("Aucun compte avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $professor = ! $this->option('remove');
        $user->forceFill(['is_professor' => $professor])->save();
        $this->info($professor
            ? "{$user->name} ({$email}) a maintenant le grade Professeur."
            : "{$user->name} ({$email}) n'est plus Professeur.");

        return self::SUCCESS;
    }
}
