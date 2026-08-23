<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\BringCredential;
use App\EventListener\BringCredentialListener;
use App\Security\CredentialCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * The listener is what makes "leave the password field empty to keep the
 * stored one" true, so the case worth pinning down is the update that carries
 * no plaintext.
 */
#[CoversClass(BringCredentialListener::class)]
final class BringCredentialListenerTest extends TestCase
{
    private CredentialCipher $cipher;
    private BringCredentialListener $listener;

    protected function setUp(): void
    {
        $this->cipher = new CredentialCipher(str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->listener = new BringCredentialListener($this->cipher);
    }

    public function testItEncryptsOnInsertAndDropsThePlaintext(): void
    {
        $credential = new BringCredential()->setPlainPassword('hunter2');

        $this->listener->prePersist($credential, $this->createStub(LifecycleEventArgs::class));

        self::assertNull($credential->getPlainPassword());
        self::assertSame('hunter2', $this->cipher->decrypt($credential->getPasswordCipher()));
    }

    /**
     * A row with no ciphertext and no plaintext would leave the account unable
     * to reach Bring! with no sign that anything went wrong, so it is refused
     * rather than written.
     */
    public function testAnInsertWithoutAPlaintextIsRefused(): void
    {
        $this->expectException(LogicException::class);

        $this->listener->prePersist(new BringCredential(), $this->createStub(LifecycleEventArgs::class));
    }

    /**
     * The empty password field on the account page: nothing to encrypt, so the
     * stored ciphertext has to survive untouched.
     */
    public function testAnUpdateWithoutAPlaintextLeavesTheCiphertextAlone(): void
    {
        $credential = new BringCredential()->setPasswordCipher('stored-ciphertext');

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects(self::never())->method('recomputeSingleEntityChangeSet');

        $this->listener->preUpdate($credential, $this->preUpdateArgs($credential, $unitOfWork));

        self::assertSame('stored-ciphertext', $credential->getPasswordCipher());
    }

    /**
     * The changeset was computed before the listener ran, so a new ciphertext
     * only reaches the database if it is folded back in.
     */
    public function testAChangedPasswordIsReEncryptedAndFoldedIntoTheChangeset(): void
    {
        $credential = new BringCredential()
            ->setPasswordCipher('stored-ciphertext')
            ->setPlainPassword('new-password');

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->expects(self::once())
            ->method('recomputeSingleEntityChangeSet')
            ->with(self::anything(), $credential);

        $this->listener->preUpdate($credential, $this->preUpdateArgs($credential, $unitOfWork));

        self::assertNull($credential->getPlainPassword());
        self::assertSame('new-password', $this->cipher->decrypt($credential->getPasswordCipher()));
    }

    private function preUpdateArgs(BringCredential $credential, UnitOfWork $unitOfWork): PreUpdateEventArgs
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);
        $em->method('getClassMetadata')->willReturn($this->createStub(ClassMetadata::class));

        $changeSet = [];

        return new PreUpdateEventArgs($credential, $em, $changeSet);
    }
}
