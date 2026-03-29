<?php

declare(strict_types=1);

namespace App\UI\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DpaController extends AbstractController
{
    #[Route('/dpa', name: 'web_dpa', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dpa/index.html.twig');
    }
}
