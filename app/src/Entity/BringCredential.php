<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BringCredentialRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's Bring! password, kept so the MCP server can log in on their behalf.
 *
 * Written on every successful sign-in, since that is the moment the plaintext
 * is known and confirmed correct. The plaintext only ever lives in
 * `plainPassword`, which is not mapped: it carries the password from the
 * authenticator to BringCredentialListener and is cleared afterwards. Leaving
 * it null on update means "keep the stored password".
 *
 * The account name is not stored here — it is the user's email, see
 * getUsername().
 */
#[ORM\Entity(repositoryClass: BringCredentialRepository::class)]
#[ORM\Table(name: 'app_bring_credential')]
class BringCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'bringCredential')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'text')]
    private string $passwordCipher = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * The Bring! account is always the one the user signs in with.
     */
    public function getUsername(): string
    {
        return $this->user?->getEmail() ?? '';
    }

    public function getPasswordCipher(): string
    {
        return $this->passwordCipher;
    }

    public function setPasswordCipher(string $passwordCipher): self
    {
        $this->passwordCipher = $passwordCipher;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = '' === $plainPassword ? null : $plainPassword;

        return $this;
    }

    public function erasePlainPassword(): void
    {
        $this->plainPassword = null;
    }
}
