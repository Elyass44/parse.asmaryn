<?php

declare(strict_types=1);

namespace App\UI\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyController extends AbstractController
{
    #[Route('/privacy', name: 'web_privacy', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('privacy/index.html.twig');
    }
}
