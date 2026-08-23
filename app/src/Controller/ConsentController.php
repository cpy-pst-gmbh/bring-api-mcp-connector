<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\OAuth\ConsentSubscriber;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_string;

#[IsGranted('ROLE_USER')]
final class ConsentController extends AbstractController
{
    #[Route('/consent', name: 'app_consent', methods: ['GET', 'POST'])]
    #[Template('consent/index.html.twig')]
    public function consent(Request $request): array|RedirectResponse
    {
        $session = $request->getSession();
        $pending = $session->get(ConsentSubscriber::PENDING_REQUEST);
        $pendingUri = $session->get(ConsentSubscriber::PENDING_URI);

        if (false === is_array($pending) || false === is_string($pendingUri)) {
            $this->addFlash('error', 'consent.flash.nothing_pending');

            return $this->redirectToRoute('app_account');
        }

        if (true === $request->isMethod(Request::METHOD_POST)) {
            if (!$this->isCsrfTokenValid('consent', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $approved = 'approve' === $request->request->get('decision');

            $session->set(
                ConsentSubscriber::DECISION,
                [
                    'client' => $pending['client'],
                    'scopes' => $pending['scopes'],
                    'approved' => $approved,
                ],
            );

            return $this->redirect($pendingUri);
        }

        return [
            'client_name' => $pending['client_name'],
            'scopes' => $pending['scopes'],
        ];
    }
}
