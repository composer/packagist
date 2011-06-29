<?php

namespace Packagist\WebBundle\Entity;

use FOS\UserBundle\Entity\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="fos_user")
 */
class User extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\generatedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\ManyToMany(targetEntity="Package", mappedBy="maintainers")
     */
    private $packages;
    public function __construct()
    {
        $this->packages = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * Get id
     *
     * @return integer $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Add packages
     *
     * @param Packagist\WebBundle\Entity\Package $packages
     */
    public function addPackages(\Packagist\WebBundle\Entity\Package $packages)
    {
        $this->packages[] = $packages;
    }

    /**
     * Get packages
     *
     * @return Doctrine\Common\Collections\Collection $packages
     */
    public function getPackages()
    {
        return $this->packages;
    }
}