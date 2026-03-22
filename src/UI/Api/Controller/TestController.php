<?php

declare(strict_types=1);

namespace App\UI\Api\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TestController extends AbstractController
{
    #[Route('/', name: 'test_home')]
    public function index(): Response
    {
        return $this->render('test/index.html.twig');
    }
}
