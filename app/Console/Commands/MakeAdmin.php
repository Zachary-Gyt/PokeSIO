<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Donne (ou retire) les droits d'administrateur à un compte. Seule la ligne de commande peut le faire :
 * personne ne peut se nommer admin depuis le site.
 *
 *   php artisan game:admin moi@exemple.fr
 *   php artisan game:admin moi@exemple.fr --remove
 *   php artisan game:admin --list
 */
class MakeAdmin extends Command
{
    protected $signature = 'game:admin {email? : Adresse e-mail du compte} {--remove : Retirer les droits} {--list : Lister les admins}';

    protected $description = 'Donne ou retire les droits d\'administrateur à un compte';

    public function handle(): int
    {
        if ($this->option('list')) {
            $admins = User::where('is_admin', true)->get(['id', 'name', 'email']);
            $admins->isEmpty()
                ? $this->info('Aucun administrateur.')
                : $this->table(['id', 'Nom', 'E-mail'], $admins->toArray());

            return self::SUCCESS;
        }

        $email = $this->argument('email') ?? $this->ask('Adresse e-mail du compte');
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("Aucun compte avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $admin = ! $this->option('remove');
        $user->forceFill(['is_admin' => $admin])->save();

        $this->info($admin
            ? "{$user->name} ({$email}) est maintenant administrateur. Tape /aide dans le chat du jeu."
            : "{$user->name} ({$email}) n'est plus administrateur.");

        return self::SUCCESS;
    }
}
