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
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use FOS\UserBundle\Model\User as BaseUser;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Serializable;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\UserRepository")
 * @ORM\Table(name="fos_user")
 * @ORM\AttributeOverrides({
 *     @ORM\AttributeOverride(name="username",
 *         column=@ORM\Column(
 *             name="username",
 *             type="string",
 *             length=191
 *         )
 *     ),
 *     @ORM\AttributeOverride(name="usernameCanonical",
 *         column=@ORM\Column(
 *             name="username_canonical",
 *             type="string",
 *             length=191,
 *             unique=true
 *         )
 *     ),
 *     @ORM\AttributeOverride(name="email",
 *         column=@ORM\Column(
 *             name="email",
 *             type="string",
 *             length=191
 *         )
 *     ),
 *     @ORM\AttributeOverride(name="emailCanonical",
 *         column=@ORM\Column(
 *             name="email_canonical",
 *             type="string",
 *             length=191,
 *             unique=true
 *         )
 *     )
 * })
 * @UniqueEntity(fields={"username"}, message="There is already an account with this username")
 * @UniqueEntity(fields={"email"}, message="There is already an account with this email")
 */
class User implements UserInterface, Serializable, TwoFactorInterface, BackupCodeInterface, EquatableInterface
{
    const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=180)
     * @Assert\Length(
     *     min=2,
     *     max=180,
     *     groups={"Profile", "Registration"}
     * )
     * @Assert\Regex(
     *     pattern="{^[^/""\r\n><#\[\]]{2,100}$}",
     *     message="Username invalid, /""\r\n><#[] are not allowed",
     *     groups={"Profile", "Registration"}
     * )
     * @Assert\NotBlank(
     *     message="fos_user.username.blank",
     *     groups={"Profile", "Registration"}
     * )
     * @var string
     */
    private $username;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
     * @var string
     */
    private $usernameCanonical;

    /**
     * @ORM\Column(type="string", length=180)
     * @Assert\Length(min=2, max=180, groups={"Profile", "Registration"})
     * @Assert\Email(message="fos_user.email.invalid", groups={"Profile", "Registration"})
     * @Assert\NotBlank(message="fos_user.email.blank", groups={"Profile", "Registration"})
     */
    private $email;

    /**
     * @ORM\Column(type="string", length=180, unique=true)
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
     * The salt to use for hashing. Newer hash algos do not typically require one anymore so it is usually null
     *
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $salt = null;

    /**
     * @ORM\Column(type="datetime", name="last_login", nullable=true)
     * @var \DateTime|null
     */
    private $lastLogin;

    /**
     * Random string sent to the user email address in order to verify it.
     *
     * @ORM\Column(type="string", name="confirmation_token", length=180, nullable=true, unique=true)
     */
    private ?string $confirmationToken = null;

    /**
     * @ORM\Column(type="datetime", name="password_requested_at", nullable=true)
     * @var \DateTime|null
     */
    private $passwordRequestedAt;

    /**
     * @ORM\ManyToMany(targetEntity="Package", mappedBy="maintainers")
     */
    private $packages;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $apiToken = null;

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
     * @var string|null
     */
    private $totpSecret;

    /**
     * @ORM\Column(type="string", length=8, nullable=true)
     * @var string|null
     */
    private $backupCode;

    public function __construct()
    {
        $this->enabled = false;
        $this->roles = array();
        $this->packages = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->salt = hash('sha256', random_bytes(40));
    }

    public function __toString()
    {
        return (string) $this->getUsername();
    }

    public function toArray()
    {
        return [
            'name' => $this->getUsername(),
            'avatar_url' => $this->getGravatarUrl(),
        ];
    }

    /**
     * Add packages
     *
     * @param Package $packages
     */
    public function addPackages(Package $packages)
    {
        $this->packages[] = $packages;
    }

    /**
     * Get packages
     *
     * @return Package[]
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set apiToken
     *
     * @param string $apiToken
     */
    public function setApiToken($apiToken)
    {
        $this->apiToken = $apiToken;
    }

    /**
     * Get apiToken
     *
     * @return string
     */
    public function getApiToken()
    {
        return $this->apiToken;
    }

    /**
     * Get githubId.
     *
     * @return string
     */
    public function getGithubId()
    {
        return $this->githubId;
    }

    /**
     * Get githubId.
     *
     * @return string
     */
    public function getGithubUsername()
    {
        if ($this->githubId) {
            if (!$this->githubToken) {
                return false;
            }

            $ctxt = ['http' => ['header' => ['User-Agent: packagist.org', 'Authorization: token '.$this->githubToken]]];
            $res = @file_get_contents('https://api.github.com/user', false, stream_context_create($ctxt));
            if (!$res || !($res = json_decode($res, true))) {
                return false;
            }

            return $res['login'];
        }

        return false;
    }

    /**
     * Set githubId.
     *
     * @param string $githubId
     */
    public function setGithubId($githubId)
    {
        $this->githubId = $githubId;
    }

    /**
     * Get githubToken.
     *
     * @return string
     */
    public function getGithubToken()
    {
        return $this->githubToken;
    }

    /**
     * Set githubToken.
     *
     * @param string $githubToken
     */
    public function setGithubToken($githubToken)
    {
        $this->githubToken = $githubToken;
    }

    /**
     * Get githubScope.
     *
     * @return string
     */
    public function getGithubScope()
    {
        return $this->githubScope;
    }

    /**
     * Set githubScope.
     *
     * @param string $githubScope
     */
    public function setGithubScope($githubScope)
    {
        $this->githubScope = $githubScope;
    }

    /**
     * Set failureNotifications
     *
     * @param Boolean $failureNotifications
     */
    public function setFailureNotifications($failureNotifications)
    {
        $this->failureNotifications = $failureNotifications;
    }

    /**
     * Get failureNotifications
     *
     * @return Boolean
     */
    public function getFailureNotifications()
    {
        return $this->failureNotifications;
    }

    /**
     * Get failureNotifications
     *
     * @return Boolean
     */
    public function isNotifiableForFailures()
    {
        return $this->failureNotifications;
    }

    /**
     * Get Gravatar Url
     *
     * @return string
     */
    public function getGravatarUrl()
    {
        return 'https://www.gravatar.com/avatar/'.md5(strtolower($this->getEmail())).'?d=identicon';
    }

    public function setTotpSecret(?string $secret)
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

    /**
     * {@inheritdoc}
     */
    public function addRole($role)
    {
        $role = strtoupper($role);
        if ($role === 'ROLE_USER') {
            return $this;
        }

        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
    public function unserialize($serialized)
    {
        $data = unserialize($serialized);

        if (13 === count($data)) {
            // Unserializing a User object from 1.3.x
            unset($data[4], $data[5], $data[6], $data[9], $data[10]);
            $data = array_values($data);
        } elseif (11 === count($data)) {
            // Unserializing a User from a dev version somewhere between 2.0-alpha3 and 2.0-beta1
            unset($data[4], $data[7], $data[8]);
            $data = array_values($data);
        }

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

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsernameCanonical()
    {
        return $this->usernameCanonical;
    }

    /**
     * {@inheritdoc}
     */
    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * {@inheritdoc}
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * {@inheritdoc}
     */
    public function getEmailCanonical()
    {
        return $this->emailCanonical;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Gets the last login time.
     *
     * @return \DateTime|null
     */
    public function getLastLogin()
    {
        return $this->lastLogin;
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles()
    {
        $roles = $this->roles;

        // we need to make sure to have at least one role
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * {@inheritdoc}
     */
    public function hasRole($role)
    {
        return in_array(strtoupper($role), $this->getRoles(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function isAccountNonExpired()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isAccountNonLocked()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isCredentialsNonExpired()
    {
        return true;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function isSuperAdmin()
    {
        return $this->hasRole(static::ROLE_SUPER_ADMIN);
    }

    /**
     * {@inheritdoc}
     */
    public function removeRole($role)
    {
        if (false !== $key = array_search(strtoupper($role), $this->roles, true)) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setUsername($username)
    {
        $this->username = $username;
        $this->setUsernameCanonical(mb_strtolower($username));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setUsernameCanonical($usernameCanonical)
    {
        $this->usernameCanonical = $usernameCanonical;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setSalt($salt)
    {
        $this->salt = $salt;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setEmail($email)
    {
        $this->email = $email;
        $this->setEmailCanonical(mb_strtolower($email));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setEmailCanonical($emailCanonical)
    {
        $this->emailCanonical = $emailCanonical;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled($boolean)
    {
        $this->enabled = (bool) $boolean;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setSuperAdmin($boolean)
    {
        if (true === $boolean) {
            $this->addRole(static::ROLE_SUPER_ADMIN);
        } else {
            $this->removeRole(static::ROLE_SUPER_ADMIN);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setLastLogin(\DateTime $time = null)
    {
        $this->lastLogin = $time;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfirmationToken($confirmationToken)
    {
        $this->confirmationToken = $confirmationToken;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setPasswordRequestedAt(\DateTime $date = null)
    {
        $this->passwordRequestedAt = $date;

        return $this;
    }

    /**
     * Gets the timestamp that the user requested a password reset.
     *
     * @return \DateTime|null
     */
    public function getPasswordRequestedAt()
    {
        return $this->passwordRequestedAt;
    }

    /**
     * {@inheritdoc}
     */
    public function isPasswordRequestNonExpired($ttl)
    {
        return $this->getPasswordRequestedAt() instanceof \DateTime &&
               $this->getPasswordRequestedAt()->getTimestamp() + $ttl > time();
    }

    /**
     * {@inheritdoc}
     */
    public function setRoles(array $roles)
    {
        $this->roles = array();

        foreach ($roles as $role) {
            $this->addRole($role);
        }

        return $this;
    }

    public function isEqualTo(UserInterface $user)
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
