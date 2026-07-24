<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Menu;

use App\Controller\AdminController;
use App\Entity\User;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\Translation\TranslatorInterface;

class MenuBuilder
{
    private string $username;

    public function __construct(private FactoryInterface $factory, TokenStorageInterface $tokenStorage, private TranslatorInterface $translator, private LogoutUrlGenerator $logoutUrlGenerator, private Security $security, private RequestStack $requestStack)
    {
        if ($tokenStorage->getToken() && $tokenStorage->getToken()->getUser() instanceof User) {
            $this->username = $tokenStorage->getToken()->getUser()->getUsername();
        }
    }

    public function createUserMenu(): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'list-unstyled');

        $this->addProfileMenu($menu);
        $menu->addChild('hr', [
            'label' => '<hr>',
            'labelAttributes' => ['class' => 'normal'],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.logout'), [
            'label' => '<span class="icon-off"></span>'.$this->translator->trans('menu.logout'),
            'uri' => $this->logoutUrlGenerator->getLogoutPath(),
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);

        return $menu;
    }

    public function createProfileMenu(): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs nav-stacked');

        $this->addProfileMenu($menu);

        return $menu;
    }

    public function createAdminMenu(): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs');

        if ($this->security->isGranted('ROLE_FILTER_LIST_ADMIN')) {
            $menu->addChild('Filter lists', [
                'label' => '<span class="icon-archive"></span>Filter lists',
                'route' => 'admin_filter_lists',
                'extras' => ['safe_label' => true, 'translation_domain' => false],
            ]);
        }
        if ($this->security->isGranted('ROLE_ANTISPAM')) {
            $menu->addChild('Suspect packages', [
                'label' => '<span class="icon-traffic-cone"></span>Suspect packages',
                'route' => 'view_spam',
                'extras' => ['safe_label' => true, 'translation_domain' => false],
            ]);
        }
        if ($this->security->isGranted('ROLE_ADMIN_ORGS')) {
            $menu->addChild('Organizations', [
                'label' => '<span class="icon-users"></span>Organizations',
                'route' => 'admin_organization_list',
                'extras' => [
                    'safe_label' => true,
                    'translation_domain' => false,
                    'routes' => [
                        ['route' => 'admin_organization_list'],
                        ['route' => 'admin_organization_create'],
                    ],
                ],
            ]);
        }
        $menu->addChild('Transparency log', [
            'label' => '<span class="icon-back-in-time"></span>Transparency log',
            'route' => 'view_transparency_log',
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);

        return $menu;
    }

    public function createOrganizationMenu(): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->setChildrenAttribute('class', 'nav nav-tabs nav-stacked');

        $slug = $this->requestStack->getCurrentRequest()?->attributes->get('organization');
        if (!\is_string($slug)) {
            return $menu;
        }

        $menu->addChild($this->translator->trans('menu.organization_overview'), [
            'label' => '<span class="icon-dashboard"></span>'.$this->translator->trans('menu.organization_overview'),
            'route' => 'organization_show',
            'routeParameters' => ['organization' => $slug],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.organization_teams'), [
            'label' => '<span class="icon-users"></span>'.$this->translator->trans('menu.organization_teams'),
            'route' => 'organization_teams',
            'routeParameters' => ['organization' => $slug],
            'extras' => [
                'safe_label' => true,
                'translation_domain' => false,
                'routes' => [
                    ['route' => 'organization_teams', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_team_create', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_team_rename', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_team_delete', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_team_member_add', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_team_member_remove', 'parameters' => ['organization' => $slug]],
                ],
            ],
        ]);
        $menu->addChild($this->translator->trans('menu.organization_members'), [
            'label' => '<span class="icon-user"></span>'.$this->translator->trans('menu.organization_members'),
            'route' => 'organization_members',
            'routeParameters' => ['organization' => $slug],
            'extras' => [
                'safe_label' => true,
                'translation_domain' => false,
                'routes' => [
                    ['route' => 'organization_members', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_member_remove', 'parameters' => ['organization' => $slug]],
                    ['route' => 'organization_member_leave', 'parameters' => ['organization' => $slug]],
                ],
            ],
        ]);
        $menu->addChild($this->translator->trans('menu.organization_settings'), [
            'label' => '<span class="icon-tools"></span>'.$this->translator->trans('menu.organization_settings'),
            'route' => 'organization_settings',
            'routeParameters' => ['organization' => $slug],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);

        return $menu;
    }

    public function hasAdminAccess(): bool
    {
        foreach (AdminController::ADMIN_ROLES as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private function addProfileMenu(ItemInterface $menu): void
    {
        $menu->addChild($this->translator->trans('menu.profile'), [
            'label' => '<span class="icon-vcard"></span>'.$this->translator->trans('menu.profile'),
            'route' => 'my_profile',
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.settings'), [
            'label' => '<span class="icon-tools"></span>'.$this->translator->trans('menu.settings'),
            'route' => 'edit_profile',
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.change_password'), [
            'label' => '<span class="icon-key"></span>'.$this->translator->trans('menu.change_password'),
            'route' => 'change_password',
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.configure_2fa'), [
            'label' => '<span class="icon-mobile"></span>'.$this->translator->trans('menu.configure_2fa'),
            'route' => 'user_2fa_configure',
            'routeParameters' => ['name' => $this->username],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        $menu->addChild($this->translator->trans('menu.my_packages'), [
            'label' => '<span class="icon-box"></span>'.$this->translator->trans('menu.my_packages'),
            'route' => 'user_packages',
            'routeParameters' => ['name' => $this->username],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        if ($this->security->isGranted('ROLE_ADMIN_ORGS')) {
            $menu->addChild($this->translator->trans('menu.my_organizations'), [
                'label' => '<span class="icon-users"></span>'.$this->translator->trans('menu.my_organizations'),
                'route' => 'organization_list',
                'extras' => ['safe_label' => true, 'translation_domain' => false],
            ]);
        }
        $menu->addChild($this->translator->trans('menu.my_favorites'), [
            'label' => '<span class="icon-leaf"></span>'.$this->translator->trans('menu.my_favorites'),
            'route' => 'user_favorites',
            'routeParameters' => ['name' => $this->username],
            'extras' => ['safe_label' => true, 'translation_domain' => false],
        ]);
        if ($this->hasAdminAccess()) {
            $menu->addChild('Admin', [
                'label' => '<span class="icon-cogs"></span>Admin',
                'route' => 'admin_index',
                'extras' => ['safe_label' => true, 'translation_domain' => false],
            ]);
        }
    }
}
