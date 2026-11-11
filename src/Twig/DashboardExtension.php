<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\DashboardNavigation;
use App\Service\NotificationProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Data for the dashboard shell (sidebar, notification bell, top-bar search), always for the LOGGED-IN user.
 */
final class DashboardExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly DashboardNavigation $navigation,
        private readonly NotificationProvider $notifications,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_menu', $this->menu(...)),
            new TwigFunction('dashboard_notifications', $this->notifications(...)),
            new TwigFunction('dashboard_search_route', fn () => ($u = $this->user()) ? $this->navigation->searchRoute($u) : null),
            new TwigFunction('dashboard_search_placeholder', fn () => ($u = $this->user()) ? $this->navigation->searchPlaceholder($u) : ''),
            new TwigFunction('dashboard_role', fn () => ($u = $this->user()) ? DashboardNavigation::roleOf($u) : null),
        ];
    }

    /** @return array<string, list<array{route: string, label: string, icon: string, active: bool}>> */
    public function menu(): array
    {
        $user = $this->user();

        return null === $user ? [] : $this->navigation->menu($user, $this->requests->getCurrentRequest()?->attributes->get('_route'));
    }

    /** @return list<array{icon: string, text: string, route: string, params: array<string, string>, tone: string}> */
    public function notifications(): array
    {
        $user = $this->user();

        return null === $user ? [] : $this->notifications->forUser($user);
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
