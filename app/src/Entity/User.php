<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account, identified by the same email address the user has at Bring!.
 *
 * There is no local password: signing in means proving the Bring! password to
 * Bring! itself, and the only other way in is a login link sent to this address.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'user.email.already_used')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Last sign-in or connector call. Dormant accounts are warned and then
     * removed, and this is the only thing that decides how dormant one is.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lastActiveAt;

    /**
     * When the warning about the pending deletion went out, and null while
     * none is outstanding. Cleared as soon as the account is used again, so a
     * returning user starts the whole clock over.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $inactivityNoticeSentAt = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: BringCredential::class, cascade: ['persist', 'remove'])]
    private ?BringCredential $bringCredential = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        // Creating an account is using it. Starting at zero would put a fresh
        // account eleven months from a warning it has done nothing to deserve.
        $this->lastActiveAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * The identifier the OAuth2 bundle stores on tokens as `sub`.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastActiveAt(): DateTimeImmutable
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(DateTimeImmutable $moment): self
    {
        $this->lastActiveAt = $moment;

        return $this;
    }

    public function getInactivityNoticeSentAt(): ?DateTimeImmutable
    {
        return $this->inactivityNoticeSentAt;
    }

    public function setInactivityNoticeSentAt(?DateTimeImmutable $moment): self
    {
        $this->inactivityNoticeSentAt = $moment;

        return $this;
    }

    public function getBringCredential(): ?BringCredential
    {
        return $this->bringCredential;
    }

    public function setBringCredential(?BringCredential $credential): self
    {
        $this->bringCredential = $credential;

        if (null !== $credential && $credential->getUser() !== $this) {
            $credential->setUser($this);
        }

        return $this;
    }

    public function hasBringCredential(): bool
    {
        return null !== $this->bringCredential;
    }
}
