<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

use function trim;

/**
 * An OAuth client, extended with the user it belongs to.
 *
 * The bundle maps AbstractClient as a mapped superclass and only maps its own
 * Model\Client when `league_oauth2_server.client.classname` is left alone.
 * Pointing that option here means this class owns the mapping instead, so the
 * identifier has to be declared locally — the superclass mapping omits it.
 *
 * Owning the mapping is also what allows the `app_` prefix: the bundle's token
 * entities resolve their `client` association to this class, so they follow the
 * table wherever it is named.
 *
 * `user` is nullable so clients created from the console — the shared connector,
 * an MCP Inspector client — keep working without an owner.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'app_oauth_client')]
class OAuthClient extends AbstractClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    protected string $identifier;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    public ?User $user = null;

    /**
     * Set once, in the constructor. `private(set)` says so in the language
     * rather than in a comment about the missing setter.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    public private(set) DateTimeImmutable $createdAt;

    /**
     * Which list this connector writes to when a tool call names none.
     * Null falls back to the first list of the account.
     */
    #[ORM\Column(length: 120, nullable: true)]
    public ?string $defaultListName = null {
        // A form submits an empty field rather than nothing at all, and an
        // empty default means the same as no default. Normalising in the hook
        // puts that rule where the value is, instead of in every caller — and
        // Doctrine writes the backing store directly when hydrating, so a row
        // read back from the database does not run it again.
        set (?string $value) {
            $value = null === $value ? null : trim($value);
            $this->defaultListName = '' === $value ? null : $value;
        }
    }

    /**
     * Signature is fixed by the bundle: CreateClientCommand and the client
     * manager both instantiate this with exactly these three arguments.
     */
    public function __construct(string $name, string $identifier, ?string $secret)
    {
        parent::__construct($name, $identifier, $secret);

        $this->createdAt = new DateTimeImmutable();
    }

    public function belongsTo(User $user): bool
    {
        return null !== $this->user && $this->user->getId() === $user->getId();
    }
}
