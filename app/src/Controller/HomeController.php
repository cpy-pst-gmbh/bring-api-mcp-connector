<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * There is no landing page: the root is wherever the visitor is in the flow.
 *
 * Kept as a route rather than folding it into the login controller so that the
 * logo, the logout target and any external link still have one stable address.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute(
            $this->getUser() instanceof User ? 'app_account' : 'app_login',
        );
    }
}
