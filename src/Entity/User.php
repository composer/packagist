<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Serializable;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\UserRepository")
 * @ORM\Table(name="fos_user")
 * @UniqueEntity(fields={"usernameCanonical"}, message="There is already an account with this username", errorPath="username")
 * @UniqueEntity(fields={"emailCanonical"}, message="There is already an account with this email", errorPath="email")
 */
class User implements UserInterface, Serializable, TwoFactorInterface, BackupCodeInterface, EquatableInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=191)
     * @Assert\Length(min=2, max=191, groups={"Profile", "Registration"})
     * @Assert\Regex(
     *     pattern="{^[^/""\r\n><#\[\]]{2,100}$}",
     *     message="Username invalid, /""\r\n><#[] are not allowed",
     *     groups={"Profile", "Registration"}
     * )
     * @Assert\NotBlank(groups={"Profile", "Registration"})
     * @var string
     */
    private $username;

    /**
     * @ORM\Column(type="string", name="username_canonical", length=191, unique=true)
     * @var string
     */
    private $usernameCanonical;

    /**
     * @ORM\Column(type="string", length=191)
     * @Assert\Length(min=2, max=191, groups={"Profile", "Registration"})
     * @Assert\Email(groups={"Profile", "Registration"})
     * @Assert\NotBlank(groups={"Profile", "Registration"})
     */
    private $email;

    /**
     * @ORM\Column(type="string", name="email_canonical", length=191, unique=true)
     * @var string
     */
    private $emailCanonical;

    /**
     * @ORM\Column(type="boolean")
     * @var bool
     */
    private $enabled = false;

    /**
     * @ORM\Column(type="array")
     */
    private $roles = [];

    /**
     * @var string The hashed password
     * @ORM\Column(type="string")
     */
    private $password;

    /**
     * The salt to use for hashing.
     *
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $salt = null;

    /**
     * @ORM\Column(type="datetime", name="last_login", nullable=true)
     */
    private ?DateTimeInterface $lastLogin = null;

    /**
     * Random string sent to the user email address in order to verify it.
     *
     * @ORM\Column(type="string", name="confirmation_token", length=180, nullable=true, unique=true)
     */
    private ?string $confirmationToken = null;

    /**
     * @ORM\Column(type="datetime", name="password_requested_at", nullable=true)
     */
    private ?DateTimeInterface $passwordRequestedAt = null;

    /**
     * @ORM\ManyToMany(targetEntity="Package", mappedBy="maintainers")
     * @var Collection<Package>
     */
    private $packages;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private string $apiToken;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $githubId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $githubToken = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $githubScope = null;

    /**
     * @ORM\Column(type="boolean", options={"default"=true})
     */
    private bool $failureNotifications = true;

    /**
     * @ORM\Column(name="totpSecret", type="string", nullable=true)
     */
    private ?string $totpSecret = null;

    /**
     * @ORM\Column(type="string", length=8, nullable=true)
     */
    private ?string $backupCode = null;

    public function __construct()
    {
        $this->enabled = false;
        $this->roles = array();
        $this->packages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->getUsername();
    }

    public function toArray(): array
    {
        return [
            'name' => $this->getUsername(),
            'avatar_url' => $this->getGravatarUrl(),
        ];
    }

    public function addPackage(Package $packages)
    {
        $this->packages[] = $packages;
    }

    /**
     * @return Collection<Package>
     */
    public function getPackages(): Collection
    {
        return $this->packages;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setApiToken(string $apiToken): void
    {
        $this->apiToken = $apiToken;
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function getGithubId(): ?string
    {
        return $this->githubId;
    }

    public function getGithubUsername(): ?string
    {
        if ($this->githubId) {
            if (!$this->githubToken) {
                return null;
            }

            $ctxt = ['http' => ['header' => ['User-Agent: packagist.org', 'Authorization: token '.$this->githubToken]]];
            $res = @file_get_contents('https://api.github.com/user', false, stream_context_create($ctxt));
            if (!$res || !($res = json_decode($res, true))) {
                return null;
            }

            return $res['login'];
        }

        return null;
    }

    public function setGithubId(?string $githubId): void
    {
        $this->githubId = $githubId;
    }

    public function getGithubToken(): ?string
    {
        return $this->githubToken;
    }

    public function setGithubToken(?string $githubToken): void
    {
        $this->githubToken = $githubToken;
    }

    public function getGithubScope(): ?string
    {
        return $this->githubScope;
    }

    public function setGithubScope(?string $githubScope): void
    {
        $this->githubScope = $githubScope;
    }

    public function setFailureNotifications(bool $failureNotifications): void
    {
        $this->failureNotifications = $failureNotifications;
    }

    public function getFailureNotifications(): bool
    {
        return $this->failureNotifications;
    }

    public function isNotifiableForFailures(): bool
    {
        return $this->failureNotifications;
    }

    public function getGravatarUrl(): string
    {
        return 'https://www.gravatar.com/avatar/'.md5(strtolower($this->getEmail())).'?d=identicon';
    }

    public function setTotpSecret(?string $secret): void
    {
        $this->totpSecret = $secret;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret ? true : false;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isBackupCode(string $code): bool
    {
        return $this->backupCode !== null && hash_equals($this->backupCode, $code);
    }

    public function invalidateBackupCode(string $code): void
    {
        $this->backupCode = null;
    }

    public function invalidateAllBackupCodes(): void
    {
        $this->backupCode = null;
    }

    public function setBackupCode(string $code): void
    {
        $this->backupCode = $code;
    }

    public function addRole(string $role): void
    {
        $role = strtoupper($role);
        if ($role === 'ROLE_USER') {
            return;
        }

        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function serialize()
    {
        return serialize(array(
            $this->password,
            $this->salt,
            $this->usernameCanonical,
            $this->username,
            $this->enabled,
            $this->id,
            $this->email,
            $this->emailCanonical,
        ));
    }

    public function unserialize($serialized)
    {
        $data = unserialize($serialized);

        list(
            $this->password,
            $this->salt,
            $this->usernameCanonical,
            $this->username,
            $this->enabled,
            $this->id,
            $this->email,
            $this->emailCanonical
        ) = $data;
    }

    public function eraseCredentials()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->usernameCanonical;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getUsernameCanonical(): ?string
    {
        return $this->usernameCanonical;
    }

    public function getSalt(): ?string
    {
        return $this->salt;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getEmailCanonical(): ?string
    {
        return $this->emailCanonical;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getLastLogin(): ?DateTimeInterface
    {
        return $this->lastLogin;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // we need to make sure to have at least one role
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function hasRole(string $role): bool
    {
        return in_array(strtoupper($role), $this->getRoles(), true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function removeRole(string $role): void
    {
        if (false !== $key = array_search(strtoupper($role), $this->roles, true)) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }
    }

    public function setUsername(?string $username)
    {
        $this->username = $username;
        $this->setUsernameCanonical($username);
    }

    public function setUsernameCanonical(?string $usernameCanonical): void
    {
        $this->usernameCanonical = null === $usernameCanonical ? null : mb_strtolower($usernameCanonical);
    }

    public function setSalt($salt): void
    {
        $this->salt = $salt;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
        $this->setEmailCanonical($email);
    }

    public function setEmailCanonical(?string $emailCanonical): void
    {
        $this->emailCanonical = null === $emailCanonical ? null : mb_strtolower($emailCanonical);
    }

    public function setEnabled(bool $boolean): void
    {
        $this->enabled = $boolean;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function setLastLogin(?DateTimeInterface $time = null): void
    {
        $this->lastLogin = $time;
    }

    public function setPasswordRequestedAt(?DateTimeInterface $date = null): void
    {
        $this->passwordRequestedAt = $date;
    }

    /**
     * Gets the timestamp that the user requested a password reset.
     */
    public function getPasswordRequestedAt(): ?DateTimeInterface
    {
        return $this->passwordRequestedAt;
    }

    public function setRoles(array $roles): void
    {
        $this->roles = array();

        foreach ($roles as $role) {
            $this->addRole($role);
        }
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if ($this->getEmailCanonical() !== $user->getEmailCanonical()) {
            return false;
        }

        if ($this->getUsername() !== $user->getUsername()) {
            return false;
        }

        if ($this->getPassword() !== $user->getPassword()) {
            return false;
        }

        if ($this->isEnabled() !== $user->isEnabled()) {
            return false;
        }

        return (!$this->getPassword() && !$user->getPassword()) || ($this->getPassword() && $user->getPassword() && hash_equals($this->getPassword(), $user->getPassword()));
    }

    public function initializeApiToken(): void
    {
        $this->apiToken = substr(hash('sha256', random_bytes(20)), 0, 20);
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function initializeConfirmationToken(): void
    {
        $this->confirmationToken = substr(hash('sha256', random_bytes(40)), 0, 40);
    }

    public function clearConfirmationToken(): void
    {
        $this->confirmationToken = null;
    }

    public function isPasswordRequestExpired(int $ttl): bool
    {
        return !$this->getPasswordRequestedAt() instanceof \DateTime || $this->getPasswordRequestedAt()->getTimestamp() + $ttl <= time();
    }
}
