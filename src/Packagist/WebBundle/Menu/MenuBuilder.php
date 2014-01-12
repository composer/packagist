<?php

namespace Packagist\WebBundle\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\HttpFoundation\Request;

class MenuBuilder
{
    private $factory;

    /**
     * @param FactoryInterface $factory
     */
    public function __construct(FactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    public function createUserMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $menu->addChild('Profile', array('label' => '<span class="icon-vcard"></span>Profile', 'route' => 'fos_user_profile_show', 'extras' => array('safe_label' => true)));
        $menu->addChild('Settings', array('label' => '<span class="icon-tools"></span>Settings', 'route' => 'fos_user_profile_edit', 'extras' => array('safe_label' => true)));
        $menu->addChild('Change password', array('label' => '<span class="icon-key"></span>Change password', 'route' => 'fos_user_change_password', 'extras' => array('safe_label' => true)));
        $menu->addChild('My packages', array('label' => '<span class="icon-box"></span>My packages', 'route' => 'user_packages', 'routeParameters' => array('name' => 'stloyd'), 'extras' => array('safe_label' => true)));
        $menu->addChild('My favorites', array('label' => '<span class="icon-leaf"></span>My favorites', 'route' => 'user_favorites', 'routeParameters' => array('name' => 'stloyd'), 'extras' => array('safe_label' => true)));
        $menu->addChild('hr', array('label' => '<hr>', 'labelAttributes' => array('class' => 'normal'), 'extras' => array('safe_label' => true)));
        $menu->addChild('Logout', array('label' => '<span class="icon-off"></span>Logout', 'route' => 'logout', 'extras' => array('safe_label' => true)));

        return $menu;
    }

    public function createProfileMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs nav-stacked');

        $menu->addChild('Profile', array('label' => '<span class="icon-vcard"></span>Profile', 'route' => 'fos_user_profile_show', 'extras' => array('safe_label' => true)));
        $menu->addChild('Edit your information', array('label' => '<span class="icon-tools"></span>Edit your information', 'route' => 'fos_user_profile_edit', 'extras' => array('safe_label' => true)));
        $menu->addChild('Change password', array('label' => '<span class="icon-key"></span>Change password', 'route' => 'fos_user_change_password', 'extras' => array('safe_label' => true)));
        $menu->addChild('View your packages', array('label' => '<span class="icon-box"></span>View your packages', 'route' => 'user_packages', 'routeParameters' => array('name' => 'stloyd'), 'extras' => array('safe_label' => true)));
        $menu->addChild('View your favorites', array('label' => '<span class="icon-leaf"></span>View your favorites', 'route' => 'user_favorites', 'routeParameters' => array('name' => 'stloyd'), 'extras' => array('safe_label' => true)));

        return $menu;
    }
}
