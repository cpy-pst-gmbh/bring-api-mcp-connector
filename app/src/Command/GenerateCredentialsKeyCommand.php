<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

#[AsCommand(
    name: 'app:credentials:generate-key',
    description: 'Generates a key for encrypting stored Bring! passwords',
)]
final class GenerateCredentialsKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $io->writeln($key);
        $io->newLine();
        $io->comment([
            'Set this as BRING_CREDENTIALS_KEY.',
            'In production use the secrets vault: bin/console secrets:set BRING_CREDENTIALS_KEY',
            'Replacing the key makes every stored password undecryptable — users have to enter theirs again.',
        ]);

        return Command::SUCCESS;
    }
}
