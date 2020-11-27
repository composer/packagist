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
use Symfony\Component\Validator\Constraints as Assert;
use FOS\UserBundle\Model\User as BaseUser;

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
 */
class User extends BaseUser implements TwoFactorInterface, BackupCodeInterface
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
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
     */
    protected $username;

    /**
     * @ORM\ManyToMany(targetEntity="Package", mappedBy="maintainers")
     */
    private $packages;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     * @var string
     */
    private $apiToken;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @var string
     */
    private $githubId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @var string
     */
    private $githubToken;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     * @var string
     */
    private $githubScope;

    /**
     * @ORM\Column(type="boolean", options={"default"=true})
     * @var string
     */
    private $failureNotifications = true;

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
        $this->packages = new ArrayCollection();
        $this->createdAt = new \DateTime();
        parent::__construct();
    }

    public function toArray()
    {
        return array(
            'name' => $this->getUsername(),
            'avatar_url' => $this->getGravatarUrl(),
        );
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
}
