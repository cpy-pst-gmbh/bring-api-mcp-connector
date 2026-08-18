<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/**
 * An OAuth client, extended with the user it belongs to.
 *
 * The bundle maps AbstractClient as a mapped superclass and only maps its own
 * Model\Client when `league_oauth2_server.client.classname` is left alone.
 * Pointing that option here means this class owns the mapping instead, so the
 * identifier has to be declared locally — the superclass mapping omits it.
 *
 * Table name stays `oauth2_client`: the bundle's token entities join to it, and
 * this is still the bundle's table, only with two columns added.
 *
 * `user` is nullable so clients created from the console — the shared connector,
 * an MCP Inspector client — keep working without an owner.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth2_client')]
class OAuthClient extends AbstractClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    protected string $identifier;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Which list this connector writes to when a tool call names none.
     * Null falls back to the first list of the account.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $defaultListName = null;

    /**
     * Signature is fixed by the bundle: CreateClientCommand and the client
     * manager both instantiate this with exactly these three arguments.
     */
    public function __construct(string $name, string $identifier, ?string $secret)
    {
        parent::__construct($name, $identifier, $secret);

        $this->createdAt = new DateTimeImmutable();
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDefaultListName(): ?string
    {
        return $this->defaultListName;
    }

    public function setDefaultListName(?string $name): self
    {
        $name = null === $name ? null : trim($name);
        $this->defaultListName = '' === $name ? null : $name;

        return $this;
    }

    public function belongsTo(User $user): bool
    {
        return null !== $this->user && $this->user->getId() === $user->getId();
    }
}
