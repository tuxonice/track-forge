<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FileUploaderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class AccountController extends AbstractController
{
    public function __construct(
        private readonly FileUploaderService $fileUploaderService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['en' => '/en/account', 'pt' => '/pt/conta'], name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('Default/account.html.twig');
    }

    #[Route('/account/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $locale = $user->getLocale();

        if (!hash_equals(mb_strtolower($user->getEmail()), mb_strtolower(trim((string) $request->request->get('email'))))) {
            $this->addFlash('danger', $this->translator->trans('flash.account_email_mismatch', [], null, $locale));

            return $this->redirectToRoute('app_account', ['_locale' => $locale]);
        }

        // The user data folder path depends on the id, which is gone once the user is removed.
        $userDataPath = $this->fileUploaderService->getUserDataPath($user);

        // Database first: a leftover folder is harmless, a user row pointing at missing files is not.
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->fileUploaderService->deleteDirectory($userDataPath);

        // Logging out invalidates the session, so the flash must be added afterwards.
        $this->security->logout(false);
        $this->addFlash('success', $this->translator->trans('flash.account_deleted', [], null, $locale));

        return $this->redirectToRoute('app_login_request', ['_locale' => $locale]);
    }
}
