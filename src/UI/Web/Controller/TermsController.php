<?php

declare(strict_types=1);

namespace App\UI\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TermsController extends AbstractController
{
    #[Route('/terms', name: 'web_terms', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('terms/index.html.twig');
    }
}
