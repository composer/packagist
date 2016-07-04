<?php

namespace Packagist\WebBundle\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class MenuBuilder
{
    private $factory;
    private $username;

    /**
     * @param FactoryInterface      $factory
     * @param TokenStorageInterface $tokenStorage
     */
    public function __construct(FactoryInterface $factory, TokenStorageInterface $tokenStorage)
    {
        $this->factory = $factory;

        if ($tokenStorage->getToken() && $tokenStorage->getToken()->getUser()) {
            $this->username = $tokenStorage->getToken()->getUser()->getUsername();
        }
    }

    public function createUserMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $this->addProfileMenu($menu);
        $menu->addChild('hr', ['label' => '<hr>', 'labelAttributes' => ['class' => 'normal'], 'extras' => ['safe_label' => true]]);
        $menu->addChild('Logout', ['label' => '<span class="icon-off"></span>Logout', 'route' => 'logout', 'extras' => ['safe_label' => true]]);

        return $menu;
    }

    public function createProfileMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs nav-stacked');

        $this->addProfileMenu($menu);

        return $menu;
    }

    private function addProfileMenu($menu)
    {
        $menu->addChild('Profile', ['label' => '<span class="icon-vcard"></span>Profile', 'route' => 'fos_user_profile_show', 'extras' => ['safe_label' => true]]);
        $menu->addChild('Settings', ['label' => '<span class="icon-tools"></span>Settings', 'route' => 'fos_user_profile_edit', 'extras' => ['safe_label' => true]]);
        $menu->addChild('Change password', ['label' => '<span class="icon-key"></span>Change password', 'route' => 'fos_user_change_password', 'extras' => ['safe_label' => true]]);
        $menu->addChild('My packages', ['label' => '<span class="icon-box"></span>My packages', 'route' => 'user_packages', 'routeParameters' => ['name' => $this->username], 'extras' => ['safe_label' => true]]);
        $menu->addChild('My favorites', ['label' => '<span class="icon-leaf"></span>My favorites', 'route' => 'user_favorites', 'routeParameters' => ['name' => $this->username], 'extras' => ['safe_label' => true]]);
    }
}
