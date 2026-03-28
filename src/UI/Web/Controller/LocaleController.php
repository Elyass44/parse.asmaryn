<?php

declare(strict_types=1);

namespace App\UI\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    #[Route('/switch-locale/{locale}', name: 'switch_locale', requirements: ['locale' => 'fr|en'], methods: ['GET'])]
    public function __invoke(Request $request, string $locale): Response
    {
        $request->getSession()->set('_locale', $locale);

        return $this->redirectToRoute('home');
    }
}
