<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\BringCredential;
use App\Security\CredentialCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use LogicException;

use function assert;

/**
 * Encrypts a newly entered Bring! password on its way into the database.
 *
 * Only runs when plainPassword is set, so an account update that leaves the
 * password field empty keeps the stored ciphertext untouched. Nothing is
 * decrypted on load — the plaintext is never needed in the web UI, only by
 * the internal endpoint the MCP server calls.
 */
#[AsEntityListener(event: Events::prePersist, entity: BringCredential::class)]
#[AsEntityListener(event: Events::preUpdate, entity: BringCredential::class)]
final class BringCredentialListener
{
    public function __construct(private readonly CredentialCipher $cipher)
    {
    }

    public function prePersist(BringCredential $credential, LifecycleEventArgs $args): void
    {
        $plain = $credential->getPlainPassword();

        if (null === $plain) {
            throw new LogicException('A new BringCredential needs a plain password to encrypt.');
        }

        $credential->setPasswordCipher($this->cipher->encrypt($plain));
        $credential->erasePlainPassword();
    }

    public function preUpdate(BringCredential $credential, PreUpdateEventArgs $args): void
    {
        $plain = $credential->getPlainPassword();

        if (null === $plain) {
            return;
        }

        $credential->setPasswordCipher($this->cipher->encrypt($plain));
        $credential->erasePlainPassword();

        // The changeset was computed before this listener ran, so the new
        // ciphertext has to be folded in explicitly. This relies on the entity
        // already being dirty — BringCredential::touch() in the controller
        // guarantees that even when only the password changed.
        $em = $args->getObjectManager();
        assert($em instanceof EntityManagerInterface);
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $em->getClassMetadata(BringCredential::class),
            $credential,
        );
    }
}
