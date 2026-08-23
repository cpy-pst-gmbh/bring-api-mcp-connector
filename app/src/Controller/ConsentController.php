<?php

declare(strict_types=1);

namespace App\Controller;

use App\OAuth\ConsentSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_string;

#[IsGranted('ROLE_USER')]
final class ConsentController extends AbstractController
{
    #[Route('/consent', name: 'app_consent', methods: ['GET', 'POST'])]
    public function consent(Request $request): Response
    {
        $session = $request->getSession();
        $pending = $session->get(ConsentSubscriber::PENDING_REQUEST);
        $pendingUri = $session->get(ConsentSubscriber::PENDING_URI);

        if (!is_array($pending) || !is_string($pendingUri)) {
            $this->addFlash('error', 'consent.flash.nothing_pending');

            return $this->redirectToRoute('app_account');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consent', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $approved = 'approve' === $request->request->get('decision');

            $session->set(ConsentSubscriber::DECISION, [
                'client' => $pending['client'],
                'scopes' => $pending['scopes'],
                'approved' => $approved,
            ]);

            return $this->redirect($pendingUri);
        }

        return $this->render('consent/index.html.twig', [
            'client_name' => $pending['client_name'],
            'scopes' => $pending['scopes'],
        ]);
    }
}
