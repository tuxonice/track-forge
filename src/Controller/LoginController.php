<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class LoginController extends AbstractController
{
    private const CODE_LENGTH = 6;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        #[Autowire(service: 'limiter.login_code_by_ip')]
        private readonly RateLimiterFactory $loginCodeByIpLimiterFactory,
        #[Autowire(service: 'limiter.login_code_by_email')]
        private readonly RateLimiterFactory $loginCodeByEmailLimiterFactory,
        #[Autowire(service: 'limiter.login_code_verify_by_email')]
        private readonly RateLimiterFactory $loginCodeVerifyByEmailLimiterFactory,
        private readonly string $emailFrom,
        private readonly int $loginCodeTtl,
    ) {
    }

    #[Route(path: ['en' => '/en/login', 'pt' => '/pt/entrar'], name: 'app_login_request', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function requestLogin(): Response
    {
        return $this->render('Default/login-request.html.twig');
    }

    #[Route(path: ['en' => '/en/login', 'pt' => '/pt/entrar'], name: 'app_login_send', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function sendCode(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('login', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        if (!$this->loginCodeByIpLimiterFactory->create($request->getClientIp())->consume(1)->isAccepted()) {
            return new Response('<h1>429 Too many requests</h1>', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $email = (string) $request->request->get('email');

        if (!$this->loginCodeByEmailLimiterFactory->create(sha1($email))->consume(1)->isAccepted()) {
            return new Response('<h1>429 Too many requests</h1>', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('danger', $this->translator->trans('flash.invalid_email'));

            return $this->redirectToRoute('app_login_request');
        }

        $user = $this->userRepository->findOneByEmail($email);
        if (!$user) {
            $user = new User($email);
            $this->entityManager->persist($user);
        }
        $user->setLocale($request->getLocale());

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $user->setLoginCodeHash(password_hash($code, PASSWORD_DEFAULT));
        $user->setLoginCodeExpiresAt(new \DateTimeImmutable(sprintf('+%d seconds', $this->loginCodeTtl)));
        $user->setLoginCodeAttempts(0);
        $this->entityManager->flush();

        $request->getSession()->set('pending_login_user_id', $user->getId());

        $mail = (new Email())
            ->from($this->emailFrom)
            ->to($email)
            ->subject($this->translator->trans('mail.login_code_subject'))
            ->html($this->renderView('Default/Mail/login-code-html.html.twig', ['code' => $code]))
            ->text($this->renderView('Default/Mail/login-code-text.txt.twig', ['code' => $code]));

        $this->mailer->send($mail);

        $this->addFlash('success', $this->translator->trans('flash.code_sent'));

        return $this->redirectToRoute('app_login_verify');
    }

    #[Route(path: ['en' => '/en/login/verify', 'pt' => '/pt/entrar/verificar'], name: 'app_login_verify', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function showVerifyForm(Request $request): Response
    {
        $user = $this->pendingUser($request);
        if (!$user) {
            $this->addFlash('danger', $this->translator->trans('flash.no_pending_login'));

            return $this->redirectToRoute('app_login_request');
        }

        return $this->render('Default/login-verify.html.twig', ['email' => $user->getEmail()]);
    }

    #[Route(path: ['en' => '/en/login/verify', 'pt' => '/pt/entrar/verificar'], name: 'app_login_verify_submit', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function verifyCode(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('login-verify', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $user = $this->pendingUser($request);
        if (!$user) {
            $this->addFlash('danger', $this->translator->trans('flash.no_pending_login'));

            return $this->redirectToRoute('app_login_request');
        }

        $expiresAt = $user->getLoginCodeExpiresAt();
        if ($user->getLoginCodeHash() === null || $expiresAt === null || $expiresAt < new \DateTimeImmutable()) {
            $user->clearLoginCode();
            $this->entityManager->flush();
            $request->getSession()->remove('pending_login_user_id');
            $this->addFlash('danger', $this->translator->trans('flash.code_expired'));

            return $this->redirectToRoute('app_login_request');
        }

        if ($user->getLoginCodeAttempts() >= self::MAX_ATTEMPTS) {
            $user->clearLoginCode();
            $this->entityManager->flush();
            $request->getSession()->remove('pending_login_user_id');
            $this->addFlash('danger', $this->translator->trans('flash.too_many_attempts'));

            return $this->redirectToRoute('app_login_request');
        }

        if (!$this->loginCodeVerifyByEmailLimiterFactory->create(sha1($user->getEmail()))->consume(1)->isAccepted()) {
            return new Response('<h1>429 Too many requests</h1>', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $code = (string) $request->request->get('code');

        if (!password_verify($code, $user->getLoginCodeHash())) {
            $user->incrementLoginCodeAttempts();
            $this->entityManager->flush();
            $this->addFlash('danger', $this->translator->trans('flash.invalid_code'));

            return $this->redirectToRoute('app_login_verify');
        }

        $user->clearLoginCode();
        $this->entityManager->flush();
        $request->getSession()->remove('pending_login_user_id');

        $this->security->login($user);

        return $this->redirectToRoute('app_tracks', ['_locale' => $user->getLocale()]);
    }

    private function pendingUser(Request $request): ?User
    {
        $userId = $request->getSession()->get('pending_login_user_id');
        if (!is_int($userId)) {
            return null;
        }

        return $this->userRepository->find($userId);
    }
}
