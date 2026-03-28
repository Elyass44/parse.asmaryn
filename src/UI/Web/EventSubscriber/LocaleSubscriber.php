<?php

declare(strict_types=1);

namespace App\UI\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reads the user's preferred locale from the session and applies it to every request.
 * Runs at priority 15 — after Symfony's LocaleListener (priority 16) — so route-level
 * _locale attributes still take precedence when present, but session wins otherwise.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->hasPreviousSession()) {
            $locale = $request->getSession()->get('_locale', 'fr');
            $request->setLocale($locale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 15]],
        ];
    }
}
