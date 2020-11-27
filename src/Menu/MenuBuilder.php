<?php

namespace App\Menu;

use Knp\Menu\FactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\TranslatorInterface;

class MenuBuilder
{
    private $factory;
    private $username;

    /**
     * @param FactoryInterface      $factory
     * @param TokenStorageInterface $tokenStorage
     * @param TranslatorInterface   $translator
     */
    public function __construct(FactoryInterface $factory, TokenStorageInterface $tokenStorage, TranslatorInterface $translator)
    {
        $this->factory = $factory;
        $this->translator = $translator;

        if ($tokenStorage->getToken() && $tokenStorage->getToken()->getUser()) {
            $this->username = $tokenStorage->getToken()->getUser()->getUsername();
        }
    }

    public function createUserMenu()
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $this->addProfileMenu($menu);
        $menu->addChild('hr', array('label' => '<hr>', 'labelAttributes' => array('class' => 'normal'), 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.logout'), array('label' => '<span class="icon-off"></span>' . $this->translator->trans('menu.logout'), 'route' => 'logout', 'extras' => array('safe_label' => true)));

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
        $menu->addChild($this->translator->trans('menu.profile'), array('label' => '<span class="icon-vcard"></span>' . $this->translator->trans('menu.profile'), 'route' => 'fos_user_profile_show', 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.settings'), array('label' => '<span class="icon-tools"></span>' . $this->translator->trans('menu.settings'), 'route' => 'fos_user_profile_edit', 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.change_password'), array('label' => '<span class="icon-key"></span>' . $this->translator->trans('menu.change_password'), 'route' => 'fos_user_change_password', 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.configure_2fa'), array('label' => '<span class="icon-mobile"></span>' . $this->translator->trans('menu.configure_2fa'), 'route' => 'user_2fa_configure', 'routeParameters' => array('name' => $this->username), 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.my_packages'), array('label' => '<span class="icon-box"></span>' . $this->translator->trans('menu.my_packages'), 'route' => 'user_packages', 'routeParameters' => array('name' => $this->username), 'extras' => array('safe_label' => true)));
        $menu->addChild($this->translator->trans('menu.my_favorites'), array('label' => '<span class="icon-leaf"></span>' . $this->translator->trans('menu.my_favorites'), 'route' => 'user_favorites', 'routeParameters' => array('name' => $this->username), 'extras' => array('safe_label' => true)));
    }
}
